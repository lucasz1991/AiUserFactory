<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AssistantVoiceStatus extends Command
{
    protected $signature = 'assistant:voice:status
        {--activate : Aktiviert Whisper und Piper nach erfolgreicher Bereitschaftspruefung}';

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
}
