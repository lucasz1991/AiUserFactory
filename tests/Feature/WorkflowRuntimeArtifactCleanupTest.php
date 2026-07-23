<?php

namespace Tests\Feature;

use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowRuntimeArtifactCleanupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanupPaths = [];

    private ?string $originalStoragePath = null;

    protected function tearDown(): void
    {
        usort($this->cleanupPaths, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach (array_unique($this->cleanupPaths) as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            } elseif (File::isFile($path)) {
                File::delete($path);
            }
        }

        if ($this->originalStoragePath !== null) {
            $this->app->useStoragePath($this->originalStoragePath);
        }

        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prune_command_covers_private_public_debug_and_profile_directories(): void
    {
        $this->useIsolatedStorage();
        Carbon::setTestNow('2026-07-23 12:00:00');
        $token = 'p1-prune-'.Str::lower(Str::random(8));
        $freshToken = $token.'-fresh';
        $oldTimestamp = now()->subDays(10)->getTimestamp();
        $oldPaths = [
            storage_path('app/workflow-task-runs/'.$token),
            storage_path('app/public/workflow-task-runs/client-controller/'.$token),
            storage_path('app/workflow-runs/'.$token.'/debug-artifacts'),
            storage_path('app/browser-profiles/workflows/'.$token),
        ];
        $freshPaths = [
            storage_path('app/workflow-task-runs/'.$freshToken),
            storage_path('app/public/workflow-task-runs/client-controller/'.$freshToken),
            storage_path('app/workflow-runs/'.$freshToken.'/debug-artifacts'),
            storage_path('app/browser-profiles/workflows/'.$freshToken),
        ];
        $this->cleanupPaths[] = storage_path('app/workflow-runs/'.$token);
        $this->cleanupPaths[] = storage_path('app/workflow-runs/'.$freshToken);

        foreach ([...$oldPaths, ...$freshPaths] as $path) {
            $this->makeArtifactDirectory($path);
        }
        foreach ($oldPaths as $path) {
            touch($path.DIRECTORY_SEPARATOR.'artifact.txt', $oldTimestamp);
            touch($path, $oldTimestamp);
        }
        clearstatcache();

        $this->artisan('workflow:prune-artifacts', [
            '--run-days' => 3,
            '--public-days' => 3,
            '--profile-days' => 3,
            '--dry-run' => true,
        ])->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        foreach ($oldPaths as $path) {
            $this->assertDirectoryExists($path);
        }

        $this->artisan('workflow:prune-artifacts', [
            '--run-days' => 3,
            '--public-days' => 3,
            '--profile-days' => 3,
        ])->assertSuccessful();

        foreach ($oldPaths as $path) {
            $this->assertDirectoryDoesNotExist($path);
        }
        foreach ($freshPaths as $path) {
            $this->assertDirectoryExists($path);
        }
    }

    public function test_successful_non_debug_runner_deletes_logs_but_debug_and_failed_runs_keep_them(): void
    {
        $service = app(WorkflowExecutionService::class);
        $method = new ReflectionMethod($service, 'cleanupSuccessfulWorkflowTaskLogs');
        $method->setAccessible(true);

        $offRunId = $this->makeRunnerArtifacts(['enabled' => false], 'off');
        $debugRunId = $this->makeRunnerArtifacts(['enabled' => true], 'debug');
        $failedRunId = $this->makeRunnerArtifacts(['enabled' => false], 'off');

        $method->invoke($service, $this->stepRun($offRunId), ['ok' => true]);
        $method->invoke($service, $this->stepRun($debugRunId), ['ok' => true]);
        $method->invoke($service, $this->stepRun($failedRunId), ['ok' => false]);

        $this->assertFileDoesNotExist($this->runnerPath($offRunId, 'stdout.log'));
        $this->assertFileDoesNotExist($this->runnerPath($offRunId, 'stderr.log'));
        $this->assertFileExists($this->runnerPath($debugRunId, 'stdout.log'));
        $this->assertFileExists($this->runnerPath($debugRunId, 'stderr.log'));
        $this->assertFileExists($this->runnerPath($failedRunId, 'stdout.log'));
        $this->assertFileExists($this->runnerPath($failedRunId, 'stderr.log'));
    }

    private function makeArtifactDirectory(string $path): void
    {
        File::ensureDirectoryExists($path);
        File::put($path.DIRECTORY_SEPARATOR.'artifact.txt', 'test');
        $this->cleanupPaths[] = $path;
    }

    private function useIsolatedStorage(): void
    {
        $this->originalStoragePath = $this->app->storagePath();
        $isolatedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aiuserfactory-p1-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($isolatedPath);
        $this->cleanupPaths[] = $isolatedPath;
        $this->app->useStoragePath($isolatedPath);
    }

    /**
     * @param  array<string, mixed>  $devDebug
     */
    private function makeRunnerArtifacts(array $devDebug, string $observabilityLevel): string
    {
        $runId = 'p1-logs-'.Str::lower(Str::random(12));
        $directory = storage_path('app/workflow-task-runs/'.$runId);
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.'runtime.json', json_encode([
            'devDebug' => $devDebug,
            'observability' => ['level' => $observabilityLevel],
        ], JSON_THROW_ON_ERROR));
        File::put($directory.DIRECTORY_SEPARATOR.'stdout.log', 'stdout');
        File::put($directory.DIRECTORY_SEPARATOR.'stderr.log', 'stderr');
        $this->cleanupPaths[] = $directory;

        return $runId;
    }

    private function stepRun(string $runId): WorkflowStepRun
    {
        return (new WorkflowStepRun)->forceFill([
            'external_run_type' => 'workflow-task',
            'external_run_id' => $runId,
        ]);
    }

    private function runnerPath(string $runId, string $filename): string
    {
        return storage_path('app/workflow-task-runs/'.$runId.DIRECTORY_SEPARATOR.$filename);
    }
}
