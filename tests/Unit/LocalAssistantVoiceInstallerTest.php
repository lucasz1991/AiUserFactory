<?php

namespace Tests\Unit;

use App\Services\Ai\LocalAssistantVoiceInstaller;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class LocalAssistantVoiceInstallerTest extends TestCase
{
    private string $runtimeDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'assistant-voice-installer-'.getmypid().'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->runtimeDirectory);
        config()->set('services.local_assistant_voice.install.state_path', $this->runtimeDirectory.DIRECTORY_SEPARATOR.'state.json');
        config()->set('services.local_assistant_voice.install.lock_path', $this->runtimeDirectory.DIRECTORY_SEPARATOR.'install.lock');
        config()->set('services.local_assistant_voice.install.log_path', $this->runtimeDirectory.DIRECTORY_SEPARATOR.'install.log');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runtimeDirectory);

        parent::tearDown();
    }

    public function test_detached_start_persists_worker_pid_and_launcher_log(): void
    {
        $installer = $this->installer($this->notReadyVoiceStatus(), 2468);

        $result = $installer->startDetached(2);
        $state = json_decode((string) file_get_contents(
            $this->runtimeDirectory.DIRECTORY_SEPARATOR.'state.json',
        ), true);

        $this->assertTrue($result['started']);
        $this->assertFalse($result['already_ready']);
        $this->assertSame(2468, $result['pid']);
        $this->assertSame('launching', $state['status']);
        $this->assertSame(2468, $state['pid']);
        $this->assertSame(2, $state['build_jobs']);
        $this->assertStringContainsString(
            'Installationslauf wird gestartet',
            (string) file_get_contents($this->runtimeDirectory.DIRECTORY_SEPARATOR.'install.log'),
        );
    }

    public function test_stale_running_state_is_reported_as_interrupted(): void
    {
        file_put_contents(
            $this->runtimeDirectory.DIRECTORY_SEPARATOR.'state.json',
            json_encode(['status' => 'running', 'pid' => 9999], JSON_THROW_ON_ERROR),
        );
        $installer = $this->installer($this->notReadyVoiceStatus());

        $status = $installer->status();

        $this->assertSame('interrupted', $status['state_status']);
        $this->assertFalse($status['running']);
        $this->assertSame(9999, $status['pid']);
    }

    public function test_ready_voice_runtime_is_reported_as_complete(): void
    {
        $voiceStatus = $this->notReadyVoiceStatus();
        $voiceStatus['enabled'] = true;
        $voiceStatus['ready'] = true;
        $voiceStatus['transcription_ready'] = true;
        $voiceStatus['synthesis_ready'] = true;
        $voiceStatus['components'] = array_fill_keys(array_keys($voiceStatus['components']), true);
        $voiceStatus['missing'] = [];
        $installer = $this->installer($voiceStatus);

        $status = $installer->status();

        $this->assertSame('complete', $status['state_status']);
        $this->assertFalse($status['can_start']);
    }

    public function test_installer_preflight_requires_ensurepip(): void
    {
        $source = File::get(app_path('Services/Ai/LocalAssistantVoiceInstaller.php'));

        $this->assertStringContainsString('import ensurepip, sys, venv', $source);
    }

    public function test_bootstrap_recreates_an_incomplete_piper_environment(): void
    {
        $source = File::get(base_path('scripts/bootstrap-local-assistant-voice.sh'));

        $this->assertStringContainsString('! "$PIPER_PYTHON" -m pip --version', $source);
        $this->assertStringContainsString('rm -rf -- "$PIPER_VENV"', $source);
        $this->assertStringContainsString('import ensurepip, venv', $source);
    }

    /** @param array<string, mixed> $voiceStatus */
    private function installer(array $voiceStatus, int $spawnedPid = 2468): LocalAssistantVoiceInstaller
    {
        $voice = $this->mock(LocalAssistantVoiceService::class, function (MockInterface $mock) use ($voiceStatus): void {
            $mock->shouldReceive('status')->andReturn($voiceStatus);
        });

        return new class($voice, $spawnedPid) extends LocalAssistantVoiceInstaller
        {
            public function __construct(LocalAssistantVoiceService $voice, private readonly int $spawnedPid)
            {
                parent::__construct($voice);
            }

            protected function isLinux(): bool
            {
                return true;
            }

            protected function preflightErrors(): array
            {
                return [];
            }

            protected function processIsRunning(int $pid): bool
            {
                return false;
            }

            protected function spawnDetachedWorker(int $buildJobs): int
            {
                return $this->spawnedPid;
            }
        };
    }

    /** @return array<string, mixed> */
    private function notReadyVoiceStatus(): array
    {
        return [
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
        ];
    }
}
