<?php

namespace App\Console\Commands;

use App\Services\Ai\LocalAssistantVoiceInstaller;
use Illuminate\Console\Command;
use Throwable;

class AssistantVoiceInstall extends Command
{
    protected $signature = 'assistant:voice:install
        {--status : Zeigt Installationsstatus und die letzten Logzeilen, ohne etwas zu starten}
        {--foreground : Fuehrt den Bootstrap im aktuellen Prozess aus (intern fuer den Worker)}
        {--build-jobs=2 : Anzahl paralleler CMake-Build-Jobs (1 bis 16)}';

    protected $description = 'Installiert Whisper und Piper kontrolliert als serverlokale Assistant-Sprachlaufzeit.';

    public function handle(LocalAssistantVoiceInstaller $installer): int
    {
        $buildJobs = filter_var($this->option('build-jobs'), FILTER_VALIDATE_INT);

        if (! is_int($buildJobs) || $buildJobs < 1 || $buildJobs > 16) {
            $this->error('--build-jobs muss eine Ganzzahl zwischen 1 und 16 sein.');

            return self::FAILURE;
        }

        if ((bool) $this->option('status') && (bool) $this->option('foreground')) {
            $this->error('--status und --foreground koennen nicht gemeinsam verwendet werden.');

            return self::FAILURE;
        }

        if ((bool) $this->option('status')) {
            return $this->renderStatus($installer->status());
        }

        if ((bool) $this->option('foreground')) {
            return $installer->runForeground(
                $buildJobs,
                fn (string $buffer): mixed => $this->output->write($buffer),
            );
        }

        try {
            $result = $installer->startDetached($buildJobs);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['already_ready']) {
            $this->info('Whisper und Piper sind bereits vollstaendig installiert und bereit.');

            return self::SUCCESS;
        }

        $this->info('Die lokale Voice-Installation wurde im Hintergrund gestartet.');
        $this->line('PID: '.($result['pid'] ?? 'unbekannt'));
        $this->line('Log: '.$result['log_path']);
        $this->line('Fortschritt: php artisan assistant:voice:install --status');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $status */
    private function renderStatus(array $status): int
    {
        $voice = is_array($status['voice'] ?? null) ? $status['voice'] : [];
        $components = is_array($voice['components'] ?? null) ? $voice['components'] : [];
        $labels = [
            'ffmpeg' => 'ffmpeg',
            'whisper_binary' => 'Whisper CLI',
            'whisper_model' => 'Whisper-Modell',
            'piper_binary' => 'Piper CLI',
            'piper_model' => 'Piper-Modell',
            'piper_config' => 'Piper-Konfiguration',
        ];
        $stateLabels = [
            'idle' => 'noch nicht gestartet',
            'launching' => 'wird gestartet',
            'running' => 'laeuft',
            'complete' => 'abgeschlossen',
            'failed' => 'fehlgeschlagen',
            'interrupted' => 'unerwartet beendet',
        ];
        $stateStatus = (string) ($status['state_status'] ?? 'idle');

        $this->line('Installationsstatus: '.($stateLabels[$stateStatus] ?? $stateStatus));
        $this->line('PID: '.($status['pid'] ?? '-'));
        $this->line('Serverlokale Sprache: '.(($voice['ready'] ?? false) ? 'bereit' : 'nicht bereit'));
        $this->table(
            ['Komponente', 'Status'],
            collect($components)->map(fn (mixed $ready, string $component): array => [
                $labels[$component] ?? $component,
                $ready ? 'bereit' : 'fehlt',
            ])->values()->all(),
        );

        foreach ((array) ($status['preflight_errors'] ?? []) as $error) {
            $this->warn((string) $error);
        }

        $this->line('Log: '.($status['log_path'] ?? '-'));
        $logTail = trim((string) ($status['log_tail'] ?? ''));

        if ($logTail !== '') {
            $this->newLine();
            $this->line('Letzte Logzeilen:');
            $this->output->writeln($logTail);
        }

        return in_array($stateStatus, ['failed', 'interrupted'], true)
            && ! (bool) ($voice['ready'] ?? false)
                ? self::FAILURE
                : self::SUCCESS;
    }
}
