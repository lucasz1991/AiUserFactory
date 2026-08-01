<?php

namespace App\Console\Commands;

use App\Services\Ai\SpeechServiceClient;
use App\Services\Ai\SpeechServiceException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class AssistantSpeechServiceStatus extends Command
{
    protected $signature = 'assistant:speech-service:status
        {--smoke : Fuehrt einen echten TTS-zu-STT-Rundtest ueber den gemeinsamen Dienst aus}';

    protected $description = 'Prueft den gemeinsamen, serverlokalen Speech-Dienst fuer Followflow.';

    public function handle(SpeechServiceClient $speechService): int
    {
        if (! $speechService->enabled()) {
            $this->warn('Der gemeinsame Speech-Dienst ist in Followflow deaktiviert.');

            return self::FAILURE;
        }

        try {
            $status = $speechService->status();
        } catch (SpeechServiceException $exception) {
            $this->error('Statuspruefung fehlgeschlagen ('.$exception->reasonCode.').');

            return self::FAILURE;
        }

        $engines = data_get($status, 'engines');
        $engines = is_array($engines) ? $engines : [];
        $ready = data_get($status, 'status') === 'ready'
            || data_get($status, 'ready') === true;
        $transcriptionReady = ($engines['ffmpeg'] ?? null) === 'ready'
            && ($engines['whisper'] ?? null) === 'ready';
        $synthesisReady = ($engines['piper'] ?? null) === 'ready';

        if ($engines === []) {
            $transcriptionReady = (bool) (data_get($status, 'transcription_ready')
                ?? data_get($status, 'stt_ready')
                ?? false);
            $synthesisReady = (bool) (data_get($status, 'synthesis_ready')
                ?? data_get($status, 'tts_ready')
                ?? false);
        }

        $this->line('Gemeinsamer Speech-Dienst: '.($ready ? 'bereit' : 'nicht bereit'));
        $this->table(['Funktion', 'Status'], [
            ['Whisper/STT', $transcriptionReady ? 'bereit' : 'nicht bereit'],
            ['Piper/TTS', $synthesisReady ? 'bereit' : 'nicht bereit'],
        ]);

        if (! $ready || ! $transcriptionReady || ! $synthesisReady) {
            return self::FAILURE;
        }

        if ((bool) $this->option('smoke') && ! $this->runSmokeTest($speechService)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runSmokeTest(SpeechServiceClient $speechService): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'followflow-speech-smoke-');

        if ($path === false) {
            $this->error('Die temporaere WAV-Datei fuer den Speech-Smoke-Test konnte nicht angelegt werden.');

            return false;
        }

        try {
            $audio = $speechService->synthesize(
                'Hallo. Dies ist ein Test des gemeinsamen Sprachdienstes.',
                1.0,
            );

            if (file_put_contents($path, $audio, LOCK_EX) === false) {
                throw new RuntimeException('Die WAV-Datei konnte nicht fuer den Rundtest bereitgestellt werden.');
            }

            $transcript = $speechService->transcribe(
                new UploadedFile($path, 'followflow-speech-smoke.wav', 'audio/wav', UPLOAD_ERR_OK, true),
            );

            $this->info('TTS-Smoke: gueltige WAV-Datei ('.strlen($audio).' Bytes).');
            $this->info('STT-Smoke: Text erkannt ('.mb_strlen($transcript).' Zeichen).');

            return true;
        } catch (SpeechServiceException $exception) {
            $this->error('Speech-Smoke-Test fehlgeschlagen ('.$exception->reasonCode.').');

            return false;
        } catch (Throwable) {
            $this->error('Speech-Smoke-Test ist unerwartet fehlgeschlagen.');

            return false;
        } finally {
            @unlink($path);
        }
    }
}
