<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class LocalAssistantVoiceService
{
    /**
     * @return array{
     *     enabled: bool,
     *     ready: bool,
     *     transcription_ready: bool,
     *     synthesis_ready: bool,
     *     components: array<string, bool>,
     *     missing: list<string>,
     *     whisper_language: string,
     *     piper_mode: string
     * }
     */
    public function status(): array
    {
        $enabled = (bool) config('services.local_assistant_voice.enabled', false);
        $components = [
            'ffmpeg' => $this->commandIsAvailable('ffmpeg'),
            'whisper_binary' => $this->commandIsAvailable('whisper'),
            'whisper_model' => $this->fileIsAvailable($this->whisperModel()),
            'piper_binary' => $this->commandIsAvailable('piper'),
            'piper_model' => $this->fileIsAvailable($this->piperModel()),
            'piper_config' => $this->fileIsAvailable($this->piperConfig()),
        ];
        $transcriptionReady = $enabled
            && $components['ffmpeg']
            && $components['whisper_binary']
            && $components['whisper_model'];
        $synthesisReady = $enabled
            && $components['piper_binary']
            && $components['piper_model']
            && $components['piper_config'];

        return [
            'enabled' => $enabled,
            'ready' => $transcriptionReady && $synthesisReady,
            'transcription_ready' => $transcriptionReady,
            'synthesis_ready' => $synthesisReady,
            'components' => $components,
            'missing' => array_values(array_keys(array_filter(
                $components,
                static fn (bool $ready): bool => ! $ready,
            ))),
            'whisper_language' => trim((string) config('services.local_assistant_voice.whisper.language', 'de')) ?: 'de',
            'piper_mode' => $this->piperMode(),
        ];
    }

    public function isTranscriptionReady(): bool
    {
        return $this->status()['transcription_ready'];
    }

    public function isSynthesisReady(): bool
    {
        return $this->status()['synthesis_ready'];
    }

    public function transcribe(UploadedFile $audio, string $connectionId = ''): string
    {
        $this->assertReady('transcription');
        $directory = $this->createRequestDirectory();

        try {
            $inputPath = $directory.DIRECTORY_SEPARATOR.'input.'.$this->audioExtension($audio);
            $wavPath = $directory.DIRECTORY_SEPARATOR.'input-16khz.wav';

            if (! @copy((string) $audio->getRealPath(), $inputPath) || ! $this->fileIsAvailable($inputPath)) {
                throw new LocalAssistantVoiceException(
                    'Die hochgeladene Audiodatei konnte nicht fuer Whisper vorbereitet werden.',
                    'audio_copy_failed',
                );
            }

            $this->runProcess([
                ...$this->baseCommand('ffmpeg'),
                '-hide_banner',
                '-loglevel',
                'error',
                '-nostdin',
                '-y',
                '-i',
                $inputPath,
                '-ac',
                '1',
                '-ar',
                '16000',
                '-c:a',
                'pcm_s16le',
                $wavPath,
            ], null, $this->timeout('ffmpeg', 60), 'ffmpeg', $connectionId);

            if (! $this->fileIsAvailable($wavPath) || (int) filesize($wavPath) <= 44) {
                throw new LocalAssistantVoiceException(
                    'ffmpeg hat keine verwertbare Audiodatei fuer Whisper erzeugt.',
                    'audio_conversion_failed',
                );
            }

            $output = $this->withEngineLock('whisper', function () use ($wavPath, $connectionId): string {
                $command = [
                    ...$this->baseCommand('whisper'),
                    '-m',
                    $this->whisperModel(),
                    '-f',
                    $wavPath,
                    '-nt',
                    '-l',
                    trim((string) config('services.local_assistant_voice.whisper.language', 'de')) ?: 'de',
                ];
                $threads = max(0, (int) config('services.local_assistant_voice.whisper.threads', 0));

                if ($threads > 0) {
                    array_push($command, '-t', (string) $threads);
                }

                return $this->runProcess(
                    $command,
                    null,
                    $this->timeout('whisper', 240),
                    'whisper',
                    $connectionId,
                );
            });
            $transcript = $this->normalizeTranscript($output);

            if ($transcript === '') {
                throw new LocalAssistantVoiceException(
                    'Whisper hat keinen gesprochenen Text erkannt.',
                    'empty_transcript',
                );
            }

            return $transcript;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function synthesize(string $text, float $speed = 1.0, string $connectionId = ''): string
    {
        $this->assertReady('synthesis');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            throw new LocalAssistantVoiceException(
                'Fuer Piper wurde kein auszugebender Text uebergeben.',
                'empty_text',
            );
        }

        $directory = $this->createRequestDirectory();

        try {
            $textPath = $directory.DIRECTORY_SEPARATOR.'speech.txt';
            $outputPath = $directory.DIRECTORY_SEPARATOR.'speech.wav';

            if (@file_put_contents($textPath, $text.PHP_EOL, LOCK_EX) === false) {
                throw new LocalAssistantVoiceException(
                    'Der Text konnte nicht fuer Piper vorbereitet werden.',
                    'text_write_failed',
                );
            }

            $speed = max(0.5, min(2.0, $speed));
            $lengthScale = number_format(1 / $speed, 4, '.', '');
            $mode = $this->piperMode();
            $command = [
                ...$this->baseCommand('piper'),
                '--model',
                $this->piperModel(),
                '--config',
                $this->piperConfig(),
                '--length-scale',
                $lengthScale,
            ];
            $input = null;

            if ($mode === 'legacy') {
                array_push($command, '--output_file', $outputPath);
                $input = $text.PHP_EOL;
            } else {
                array_push($command, '--input-file', $textPath, '--output-file', $outputPath);
            }

            $this->withEngineLock('piper', fn (): string => $this->runProcess(
                $command,
                $input,
                $this->timeout('piper', 120),
                'piper',
                $connectionId,
            ));

            if (! $this->fileIsAvailable($outputPath)) {
                throw new LocalAssistantVoiceException(
                    'Piper hat keine Audiodatei erzeugt.',
                    'piper_output_missing',
                );
            }

            $audio = @file_get_contents($outputPath);

            if (! is_string($audio) || strlen($audio) <= 44 || ! str_starts_with($audio, 'RIFF')) {
                throw new LocalAssistantVoiceException(
                    'Piper hat keine gueltige WAV-Audiodatei erzeugt.',
                    'piper_output_invalid',
                );
            }

            return $audio;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function assertReady(string $operation): void
    {
        $status = $this->status();
        $readyKey = $operation === 'transcription' ? 'transcription_ready' : 'synthesis_ready';

        if ($status[$readyKey]) {
            return;
        }

        if (! $status['enabled']) {
            throw new LocalAssistantVoiceException(
                'Die serverlokale Sprachverarbeitung ist deaktiviert.',
                'local_voice_disabled',
            );
        }

        $required = $operation === 'transcription'
            ? ['ffmpeg', 'whisper_binary', 'whisper_model']
            : ['piper_binary', 'piper_model', 'piper_config'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $component): bool => ! ($status['components'][$component] ?? false),
        ));

        throw new LocalAssistantVoiceException(
            'Die serverlokale Sprachverarbeitung ist nicht vollstaendig installiert (fehlt: '.implode(', ', $missing).').',
            'local_voice_not_ready',
        );
    }

    private function withEngineLock(string $engine, callable $callback): mixed
    {
        $timeout = $this->timeout($engine, $engine === 'whisper' ? 240 : 120);
        $waitSeconds = max(1, (int) config('services.local_assistant_voice.lock_wait_seconds', 30));

        try {
            return Cache::lock('assistant-local-voice:'.$engine, $timeout + 30)
                ->block($waitSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            throw new LocalAssistantVoiceException(
                'Die serverlokale Sprachverarbeitung ist ausgelastet. Bitte die Anfrage gleich erneut senden.',
                $engine.'_busy',
                $exception,
            );
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(
        array $command,
        ?string $input,
        int $timeout,
        string $engine,
        string $connectionId,
    ): string {
        $process = new Process($command, base_path(), null, $input, $timeout);
        $process->setIdleTimeout($timeout);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            Log::warning('Local assistant voice process timed out.', [
                'connection_id' => $connectionId,
                'engine' => $engine,
                'timeout_seconds' => $timeout,
            ]);

            throw new LocalAssistantVoiceException(
                'Die serverlokale Sprachverarbeitung hat das Zeitlimit ueberschritten.',
                $engine.'_timeout',
                $exception,
            );
        } catch (ProcessFailedException $exception) {
            Log::warning('Local assistant voice process failed.', [
                'connection_id' => $connectionId,
                'engine' => $engine,
                'exit_code' => $process->getExitCode(),
                'error' => Str::limit(trim($process->getErrorOutput() ?: $process->getOutput()), 1000, ''),
            ]);

            throw new LocalAssistantVoiceException(
                'Die serverlokale Sprachverarbeitung konnte den '.$engine.'-Prozess nicht erfolgreich ausfuehren.',
                $engine.'_process_failed',
                $exception,
            );
        } catch (Throwable $exception) {
            Log::warning('Local assistant voice process could not be started.', [
                'connection_id' => $connectionId,
                'engine' => $engine,
                'error' => Str::limit($exception->getMessage(), 1000, ''),
            ]);

            throw new LocalAssistantVoiceException(
                'Die serverlokale Sprachverarbeitung konnte den '.$engine.'-Prozess nicht starten.',
                $engine.'_start_failed',
                $exception,
            );
        }

        return $process->getOutput();
    }

    private function normalizeTranscript(string $output): string
    {
        $output = (string) preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $output);
        $lines = preg_split('/\R/u', $output) ?: [];
        $transcript = collect($lines)
            ->map(static function (string $line): string {
                $line = trim($line);
                $line = (string) preg_replace('/^\[[\d:.]+\s+-->\s+[\d:.]+\]\s*/', '', $line);

                return trim($line);
            })
            ->reject(static fn (string $line): bool => $line === '')
            ->reject(static fn (string $line): bool => preg_match('/^\[[^\]]+\]$/u', $line) === 1)
            ->implode(' ');

        return trim((string) preg_replace('/\s+/u', ' ', $transcript));
    }

    /**
     * @return list<string>
     */
    private function baseCommand(string $component): array
    {
        $configured = config("services.local_assistant_voice.{$component}.command");

        if (is_array($configured)) {
            $command = array_values(array_filter(
                array_map(static fn (mixed $part): string => trim((string) $part), $configured),
                static fn (string $part): bool => $part !== '',
            ));

            if ($command !== []) {
                return $command;
            }
        }

        $binary = trim((string) config("services.local_assistant_voice.{$component}.binary", ''));

        return $binary === '' ? [] : [$binary];
    }

    private function commandIsAvailable(string $component): bool
    {
        $command = $this->baseCommand($component);

        if ($command === []) {
            return false;
        }

        $binary = $command[0];

        if ($this->fileIsAvailable($binary)) {
            return true;
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    private function whisperModel(): string
    {
        return trim((string) config('services.local_assistant_voice.whisper.model', ''));
    }

    private function piperModel(): string
    {
        return trim((string) config('services.local_assistant_voice.piper.model', ''));
    }

    private function piperConfig(): string
    {
        $configured = trim((string) config('services.local_assistant_voice.piper.config', ''));

        return $configured !== '' ? $configured : $this->piperModel().'.json';
    }

    private function piperMode(): string
    {
        return trim((string) config('services.local_assistant_voice.piper.mode', 'cli')) === 'legacy'
            ? 'legacy'
            : 'cli';
    }

    private function timeout(string $component, int $default): int
    {
        return max(5, min(900, (int) config("services.local_assistant_voice.{$component}.timeout", $default)));
    }

    private function fileIsAvailable(string $path): bool
    {
        return $path !== '' && is_file($path) && (int) @filesize($path) > 0;
    }

    private function createRequestDirectory(): string
    {
        $base = trim((string) config(
            'services.local_assistant_voice.temp_path',
            storage_path('app/private/assistant-voice'),
        ));
        $base = rtrim($base, '/\\');

        if ($base === '') {
            throw new LocalAssistantVoiceException(
                'Das temporaere Verzeichnis fuer die Sprachverarbeitung ist nicht konfiguriert.',
                'temp_path_missing',
            );
        }

        if (! is_dir($base) && ! @mkdir($base, 0700, true) && ! is_dir($base)) {
            throw new LocalAssistantVoiceException(
                'Das temporaere Verzeichnis fuer die Sprachverarbeitung konnte nicht erstellt werden.',
                'temp_path_create_failed',
            );
        }

        $directory = $base.DIRECTORY_SEPARATOR.(string) Str::uuid();

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new LocalAssistantVoiceException(
                'Das Anfrageverzeichnis fuer die Sprachverarbeitung konnte nicht erstellt werden.',
                'request_path_create_failed',
            );
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function audioExtension(UploadedFile $audio): string
    {
        $extension = Str::lower($audio->getClientOriginalExtension());

        if (in_array($extension, ['webm', 'ogg', 'wav', 'mp3', 'mp4', 'm4a', 'aac', 'flac'], true)) {
            return $extension;
        }

        return match (Str::lower((string) $audio->getMimeType())) {
            'audio/ogg' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            default => 'webm',
        };
    }
}
