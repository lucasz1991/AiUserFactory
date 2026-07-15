<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AssistantVoiceStatus extends Command
{
    protected $signature = 'assistant:voice:status
        {--activate : Aktiviert Whisper und Piper nach erfolgreicher Bereitschaftspruefung}
        {--smoke : Fuehrt einen echten Piper-zu-Whisper-Rundtest aus}';

    protected $description = 'Prueft die serverlokale Whisper-/Piper-Laufzeit und aktiviert sie optional.';

    public function handle(LocalAssistantVoiceService $voice): int
    {
        $status = $voice->status();
        $labels = [
            'ffmpeg' => 'ffmpeg',
            'whisper_binary' => 'Whisper CLI',
            'whisper_model' => 'Whisper-Modell',
            'piper_binary' => 'Piper CLI',
            'piper_model' => 'Piper-Modell',
            'piper_config' => 'Piper-Konfiguration',
        ];

        $this->line('Serverlokale Sprachverarbeitung: '.($status['enabled'] ? 'aktiviert' : 'deaktiviert'));
        $this->table(
            ['Komponente', 'Status'],
            collect($status['components'])
                ->map(fn (bool $ready, string $component): array => [
                    $labels[$component] ?? $component,
                    $ready ? 'bereit' : 'fehlt',
                ])
                ->values()
                ->all(),
        );
        $this->line('Whisper/STT: '.($status['transcription_ready'] ? 'bereit' : 'nicht bereit'));
        $this->line('Piper/TTS: '.($status['synthesis_ready'] ? 'bereit' : 'nicht bereit'));

        if (! $status['ready']) {
            $this->error('Die lokale Sprachlaufzeit ist noch nicht vollstaendig einsatzbereit.');

            return self::FAILURE;
        }

        if ((bool) $this->option('smoke') && ! $this->runSmokeTest($voice)) {
            return self::FAILURE;
        }

        if (! (bool) $this->option('activate')) {
            return self::SUCCESS;
        }

        $settingsTable = (new Setting)->getTable();

        if (! Schema::hasTable($settingsTable)) {
            $this->error('Die Settings-Tabelle fehlt. Migrationen muessen vor der Aktivierung ausgefuehrt werden.');

            return self::FAILURE;
        }

        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $settings = is_array($settings) ? $settings : [];
        $settings['speech_input_provider'] = 'whisper_local';
        $settings['speech_output_provider'] = 'piper_local';
        Setting::setValue('ai_assistant', 'workflow_copilot', $settings);

        $this->info('Whisper (Spracheingabe) und Piper (Sprachausgabe) wurden aktiviert.');

        return self::SUCCESS;
    }

    private function runSmokeTest(LocalAssistantVoiceService $voice): bool
    {
        $path = tempnam(sys_get_temp_dir(), 'assistant-voice-smoke-');

        if ($path === false) {
            $this->error('Die temporaere WAV-Datei fuer den Voice-Smoke-Test konnte nicht angelegt werden.');

            return false;
        }

        try {
            $audio = $voice->synthesize(
                'Hallo. Dies ist ein produktiver Test der lokalen Sprachverarbeitung.',
                1.0,
                'assistant-voice-smoke-tts',
            );

            if (! str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
                throw new RuntimeException('Piper hat keine gueltige WAV-Datei erzeugt.');
            }

            if (file_put_contents($path, $audio, LOCK_EX) === false) {
                throw new RuntimeException('Die Piper-WAV-Datei konnte nicht fuer Whisper bereitgestellt werden.');
            }

            $transcript = $voice->transcribe(
                new UploadedFile($path, 'assistant-voice-smoke.wav', 'audio/wav', UPLOAD_ERR_OK, true),
                'assistant-voice-smoke-stt',
            );

            $this->info('Piper/TTS-Smoke: gueltige WAV-Datei ('.strlen($audio).' Bytes).');
            $this->info('Whisper/STT-Smoke: '.$transcript);

            return true;
        } catch (Throwable $exception) {
            $this->error('Voice-Smoke-Test fehlgeschlagen: '.$exception->getMessage());

            return false;
        } finally {
            @unlink($path);
        }
    }
}
