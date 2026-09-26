<?php

namespace Tests\Unit;

use App\Models\ManagedProcess;
use App\Services\Processes\ManagedProcessInventory;
use App\Services\Processes\ManagedProcessSupervisor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagedProcessProfileLockPolicyTest extends TestCase
{
    public function test_supervisor_does_not_restart_a_profile_lock_failure_into_a_new_identity(): void
    {
        $directory = storage_path('framework/testing/profile-lock-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $runtimePath = $directory.DIRECTORY_SEPARATOR.'runtime.json';
        $statusPath = $directory.DIRECTORY_SEPARATOR.'status.json';

        File::put($runtimePath, json_encode(['supervisor' => ['enabled' => true, 'maxRestarts' => 3]]));
        File::put($statusPath, json_encode([
            'state' => 'failed',
            'stage' => 'failed',
            'message' => 'BROWSER_PROFILE_IN_USE: Chromium user data directory is already in use.',
        ]));

        $supervisor = new class(app(ManagedProcessInventory::class)) extends ManagedProcessSupervisor
        {
            public function mayRestart(ManagedProcess $process, bool $force): bool
            {
                return $this->shouldRestart($process, $force);
            }
        };

        $process = new ManagedProcess([
            'run_type' => 'webmail-session',
            'status' => 'exited',
            'runtime_config_path' => $runtimePath,
            'status_path' => $statusPath,
            'run_id' => null,
            'restart_count' => 0,
        ]);

        try {
            $this->assertFalse($supervisor->mayRestart($process, true));
            $this->assertSame([], glob($directory.DIRECTORY_SEPARATOR.'browser-profile-restart-*') ?: []);
            $this->assertSame(
                ['supervisor' => ['enabled' => true, 'maxRestarts' => 3]],
                json_decode(File::get($runtimePath), true),
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
