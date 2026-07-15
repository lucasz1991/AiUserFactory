<?php

namespace Tests\Feature;

use App\Services\Ai\LocalAssistantVoiceInstaller;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AssistantVoiceInstallCommandTest extends TestCase
{
    public function test_command_starts_detached_installation(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('startDetached')
                ->once()
                ->with(2)
                ->andReturn([
                    'started' => true,
                    'already_ready' => false,
                    'pid' => 4321,
                    'log_path' => '/app/storage/logs/local-assistant-voice-install.log',
                ]);
        });

        $this->artisan('assistant:voice:install')
            ->expectsOutputToContain('im Hintergrund gestartet')
            ->expectsOutputToContain('PID: 4321')
            ->expectsOutputToContain('assistant:voice:install --status')
            ->assertExitCode(0);
    }

    public function test_command_reports_an_already_ready_runtime_without_starting_again(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('startDetached')
                ->once()
                ->with(2)
                ->andReturn([
                    'started' => false,
                    'already_ready' => true,
                    'pid' => null,
                    'log_path' => '/app/storage/logs/local-assistant-voice-install.log',
                ]);
        });

        $this->artisan('assistant:voice:install')
            ->expectsOutputToContain('bereits vollstaendig installiert und bereit')
            ->assertExitCode(0);
    }

    public function test_status_option_renders_components_and_log_tail(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andReturn($this->installerStatus([
                'state_status' => 'running',
                'running' => true,
                'pid' => 4321,
                'log_tail' => '[local-voice] Lade Whisper-Modell herunter.',
            ]));
        });

        $this->artisan('assistant:voice:install --status')
            ->expectsOutputToContain('Installationsstatus: laeuft')
            ->expectsOutputToContain('PID: 4321')
            ->expectsOutputToContain('Whisper CLI')
            ->expectsOutputToContain('Lade Whisper-Modell herunter')
            ->assertExitCode(0);
    }

    public function test_failed_status_returns_failure(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andReturn($this->installerStatus([
                'state_status' => 'failed',
                'running' => false,
                'pid' => 4321,
                'log_tail' => '[local-voice] FEHLER: ffmpeg wurde nicht gefunden.',
            ]));
        });

        $this->artisan('assistant:voice:install --status')
            ->expectsOutputToContain('Installationsstatus: fehlgeschlagen')
            ->assertExitCode(1);
    }

    public function test_foreground_option_delegates_to_installer_worker(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runForeground')
                ->once()
                ->with(3, Mockery::on('is_callable'))
                ->andReturn(0);
        });

        $this->artisan('assistant:voice:install --foreground --build-jobs=3')
            ->assertExitCode(0);
    }

    public function test_invalid_build_jobs_are_rejected_before_start(): void
    {
        $this->mock(LocalAssistantVoiceInstaller::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('startDetached');
            $mock->shouldNotReceive('runForeground');
        });

        $this->artisan('assistant:voice:install --build-jobs=0')
            ->expectsOutputToContain('--build-jobs muss eine Ganzzahl zwischen 1 und 16 sein')
            ->assertExitCode(1);
    }

    /** @param array<string, mixed> $overrides */
    private function installerStatus(array $overrides = []): array
    {
        return [
            'state_status' => 'idle',
            'running' => false,
            'pid' => null,
            'can_start' => true,
            'preflight_errors' => [],
            'state' => [],
            'voice' => [
                'enabled' => false,
                'ready' => false,
                'transcription_ready' => false,
                'synthesis_ready' => false,
                'components' => [
                    'ffmpeg' => false,
                    'whisper_binary' => false,
                    'whisper_model' => false,
                    'piper_binary' => false,
                    'piper_model' => false,
                    'piper_config' => false,
                ],
                'missing' => [
                    'ffmpeg',
                    'whisper_binary',
                    'whisper_model',
                    'piper_binary',
                    'piper_model',
                    'piper_config',
                ],
                'whisper_language' => 'de',
                'piper_mode' => 'cli',
            ],
            'log_path' => '/app/storage/logs/local-assistant-voice-install.log',
            'log_tail' => '',
            ...$overrides,
        ];
    }
}
