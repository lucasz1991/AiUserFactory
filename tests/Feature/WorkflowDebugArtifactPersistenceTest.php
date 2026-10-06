<?php

namespace Tests\Feature;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowDebugArtifactService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowDebugArtifactPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('nestedArtifactLengths')]
    public function test_nested_artifact_paths_and_task_identities_are_not_shortened(int $pathLength, int $keyLength): void
    {
        $stepRun = $this->stepRun();
        $artifact = $this->artifact(str_repeat('p', $pathLength), str_repeat('k', $keyLength));
        $service = app(WorkflowDebugArtifactService::class);

        $service->ingestManifest($stepRun, ['artifacts' => [$artifact]]);
        $service->ingestManifest($stepRun, ['artifacts' => [$artifact]]);

        $this->assertDatabaseCount('workflow_run_artifacts', 1);
        $stored = WorkflowRunArtifact::query()->sole();
        $this->assertSame($artifact['storage_path'], $stored->storage_path);
        $this->assertSame($artifact['task_card_key'], $stored->task_card_key);
        $this->assertSame($stepRun->id, $stored->workflow_step_run_id);
    }

    public static function nestedArtifactLengths(): array
    {
        return ['two nested levels' => [288, 192], 'deep nested identities' => [4096, 1536]];
    }

    public function test_distinct_long_task_keys_with_the_same_prefix_do_not_overwrite_each_other(): void
    {
        $stepRun = $this->stepRun();
        $prefix = str_repeat('same-prefix-', 100);
        $first = $this->artifact('same-path.png', $prefix.'first');
        $second = $this->artifact('same-path.png', $prefix.'second');
        $service = app(WorkflowDebugArtifactService::class);

        $service->ingestManifest($stepRun, ['artifacts' => [$first, $second]]);
        $service->ingestManifest($stepRun, ['artifacts' => [$first, $second]]);

        $this->assertDatabaseCount('workflow_run_artifacts', 2);
        $this->assertSame([$first['task_card_key'], $second['task_card_key']], WorkflowRunArtifact::query()->orderBy('id')->pluck('task_card_key')->all());
    }

    public function test_small_columns_respect_their_actual_schema_limits(): void
    {
        $stepRun = $this->stepRun();
        $artifact = $this->artifact('path.png', 'task');
        $artifact = array_replace($artifact, [
            'phase' => str_repeat('a', 100), 'artifact_type' => str_repeat('b', 100),
            'browser_window' => str_repeat('w', 300), 'step_action_key' => str_repeat('s', 300),
            'title' => str_repeat('t', 300), 'storage_disk' => str_repeat('d', 100), 'status' => str_repeat('z', 100),
        ]);

        app(WorkflowDebugArtifactService::class)->ingestManifest($stepRun, ['artifacts' => [$artifact]]);

        $stored = WorkflowRunArtifact::query()->sole();
        foreach (['phase' => 20, 'artifact_type' => 40, 'browser_window' => 191, 'step_action_key' => 191, 'title' => 191, 'storage_disk' => 80, 'status' => 40] as $column => $length) {
            $this->assertSame($length, mb_strlen($stored->{$column}), $column);
        }
    }

    public function test_migration_widens_only_identity_columns_and_preserves_lookup_indexes(): void
    {
        $this->assertSame('text', Schema::getColumnType('workflow_run_artifacts', 'storage_path'));
        $this->assertSame('text', Schema::getColumnType('workflow_run_artifacts', 'task_card_key'));
        $this->assertFalse(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_task_card_key_index'));
        $this->assertTrue(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_run_step_idx'));
        $this->assertTrue(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_phase_type_idx'));
        $this->assertTrue(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_step_action_key_index'));
        $this->assertTrue(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_browser_window_index'));
    }

    public function test_schema_reapplication_and_rollback_never_shorten_existing_nested_data(): void
    {
        $stepRun = $this->stepRun();
        $artifact = $this->artifact(str_repeat('p', 288), str_repeat('k', 1536));
        app(WorkflowDebugArtifactService::class)->ingestManifest($stepRun, ['artifacts' => [$artifact]]);
        $migration = require database_path('migrations/2026_10_06_090000_widen_workflow_run_artifact_identity_columns.php');

        $migration->up();
        $migration->down();

        $stored = WorkflowRunArtifact::query()->sole();
        $this->assertSame($artifact['storage_path'], $stored->storage_path);
        $this->assertSame($artifact['task_card_key'], $stored->task_card_key);
        $this->assertSame('text', Schema::getColumnType('workflow_run_artifacts', 'storage_path'));
        $this->assertSame('text', Schema::getColumnType('workflow_run_artifacts', 'task_card_key'));
        $this->assertFalse(Schema::hasIndex('workflow_run_artifacts', 'workflow_run_artifacts_task_card_key_index'));
    }

    public function test_an_optional_artifact_query_failure_does_not_abort_later_artifacts_or_log_secrets(): void
    {
        $stepRun = $this->stepRun();
        Log::spy();
        $secret = 'synthetic-private-path-and-query-secret';
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($secret): void {
            if (str_starts_with(strtolower($sql), 'insert') && str_contains($sql, 'workflow_run_artifacts') && in_array($secret, $bindings, true)) {
                $previous = new PDOException('SQLSTATE[22001]: Data too long '.$secret, 1406);
                $previous->errorInfo = ['22001', 1406, $secret];
                throw new QueryException('sqlite', 'insert into secret_table '.$secret, [$secret], $previous);
            }
        });

        app(WorkflowDebugArtifactService::class)->ingestManifest($stepRun, ['artifacts' => [
            $this->artifact($secret, 'failed-task'), $this->artifact('after-failure.png', 'following-task'),
        ]]);

        $this->assertDatabaseCount('workflow_run_artifacts', 1);
        $this->assertSame('following-task', WorkflowRunArtifact::query()->sole()->task_card_key);
        Log::shouldHaveReceived('warning')->once()->with('Workflow debug artifact persistence failed.', Mockery::on(function (array $context) use ($stepRun, $secret): bool {
            $this->assertSame([
                'workflow_run_id' => $stepRun->workflow_run_id, 'workflow_step_run_id' => $stepRun->id,
                'sql_state' => '22001', 'driver_code' => 1406,
            ], $context);
            $this->assertStringNotContainsString($secret, json_encode($context));

            return true;
        }));
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('running', $stepRun->workflowRun->fresh()->status);
    }

    #[DataProvider('monitorPersistenceFailures')]
    public function test_public_monitor_ignores_optional_artifact_failures_but_not_critical_outcome_failures(bool $failCriticalOutcome): void
    {
        Queue::fake();
        Log::spy();
        $stepRun = $this->stepRun();
        $rootStep = $stepRun->workflowStep;
        $run = $stepRun->workflowRun;
        $middle = Workflow::query()->create(['name' => 'Middle', 'slug' => 'middle-'.str()->uuid(), 'is_active' => true, 'trigger_type' => 'manual']);
        $leaf = Workflow::query()->create(['name' => 'Leaf', 'slug' => 'leaf-'.str()->uuid(), 'is_active' => true, 'trigger_type' => 'manual']);
        $rootStep->forceFill(['config_json' => ['timeout_seconds' => 120, 'tasks' => [[
            'key' => 'root-include', 'task_key' => 'workflow.include.'.$middle->id, 'workflow_id' => $middle->id,
        ]]]])->save();
        $run->forceFill(['current_workflow_step_id' => $rootStep->id, 'started_at' => now()->subMinutes(3)])->save();
        $stepRun->forceFill([
            'started_at' => now()->subMinutes(3), 'external_run_type' => 'workflow-task',
            'external_run_id' => (string) str()->uuid(),
        ])->save();
        $task = [
            'key' => 'middle-leaf-done', 'task_key' => 'wait.seconds', 'runner' => 'node',
            'parent_task_key' => 'root-include', 'status' => 'success', 'browser_window' => 'middle-leaf-main',
            'startedAt' => now()->subSeconds(175)->toIso8601String(), 'finishedAt' => now()->subSeconds(150)->toIso8601String(),
            'source_workflow_id' => $leaf->id, 'embedded_workflow_path' => [
                ['frame_key' => 'middle-frame', 'workflow_id' => $middle->id, 'include_task_key' => 'root-include', 'source_step_id' => $rootStep->id],
                ['frame_key' => 'leaf-frame', 'parent_frame_key' => 'middle-frame', 'workflow_id' => $leaf->id, 'include_task_key' => 'leaf-include'],
            ],
        ];
        $failedPath = 'synthetic-private-manifest-failure';
        $faultInjected = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($failedPath, &$faultInjected, $failCriticalOutcome): void {
            if (! $faultInjected && str_starts_with(strtolower($sql), 'insert') && str_contains($sql, 'workflow_run_artifacts') && in_array($failedPath, $bindings, true)) {
                $faultInjected = true;
                $previous = new PDOException('SQLSTATE[22001]: Data too long '.$failedPath, 1406);
                $previous->errorInfo = ['22001', 1406, $failedPath];
                throw new QueryException('sqlite', 'insert into private_table '.$failedPath, [$failedPath], $previous);
            }
            if ($failCriticalOutcome && str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'workflow_step_runs')) {
                $previous = new PDOException('Synthetic critical outcome persistence failure', 2006);
                $previous->errorInfo = ['HY000', 2006, 'Synthetic critical outcome persistence failure'];
                throw new QueryException('sqlite', $sql, [], $previous);
            }
        });
        $status = [
            'runId' => $stepRun->external_run_id, 'workflowRunId' => $run->id, 'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $rootStep->id, 'workflowStepRunId' => $stepRun->id,
            'state' => 'completed', 'isRunning' => false, 'startedAt' => now()->subSeconds(179)->toIso8601String(),
            'debugArtifacts' => [
                $this->artifact($failedPath, $task['key']), $this->artifact('subsequent-good.png', $task['key']),
            ],
        ];
        $result = [
            'ok' => true, 'status' => 'success', 'finishedAt' => now()->subSeconds(150)->toIso8601String(),
            'browserIdentity' => ['runId' => $stepRun->external_run_id, 'workflowRunId' => $run->id, 'workflowRunUuid' => $run->run_uuid],
            'configuredTasks' => [array_replace($task, ['status' => 'configured'])], 'tasks' => [$task],
            'browserWindows' => [['name' => 'middle-leaf-main', 'targetId' => 'synthetic-nested-target']],
        ];
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')->andReturn($status);
        $runner->shouldReceive('readResult')->andReturn($result);
        $runner->shouldReceive('closeRun')->andReturn(['ok' => true]);
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        if ($failCriticalOutcome) {
            try {
                app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);
                $this->fail('Critical outcome persistence must not be treated as optional diagnostics.');
            } catch (QueryException $exception) {
                $this->assertSame(2006, $exception->getCode());
            }
        } else {
            app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);
        }

        $this->assertTrue($faultInjected);
        if ($failCriticalOutcome) {
            $this->assertSame('waiting', $stepRun->fresh()->status);
            $this->assertSame('running', $run->fresh()->status);
        } else {
            $this->assertSame('completed', $stepRun->fresh()->status);
            $this->assertSame('completed', $run->fresh()->status);
            $this->assertSame($task['key'], data_get($stepRun->fresh()->result_json, 'tasks.0.key'));
            $this->assertSame('success', data_get($stepRun->fresh()->result_json, 'tasks.0.status'));
            $this->assertSame($task['embedded_workflow_path'], data_get($stepRun->fresh()->result_json, 'tasks.0.embedded_workflow_path'));
            $this->assertSame($result['browserWindows'], data_get($stepRun->fresh()->result_json, 'browserWindows'));
            $this->assertNull(data_get($stepRun->fresh()->result_json, 'timedOutAt'));
        }
        $this->assertDatabaseCount('workflow_run_artifacts', 1);
        $this->assertSame('subsequent-good.png', WorkflowRunArtifact::query()->sole()->storage_path);
        Log::shouldHaveReceived('warning')->once()->with('Workflow debug artifact persistence failed.', Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), $failedPath)));
        Queue::assertNotPushed(MonitorWorkflowStepRunJob::class);
    }

    public static function monitorPersistenceFailures(): array
    {
        return ['optional diagnostic only' => [false], 'critical outcome must still fail' => [true]];
    }

    private function artifact(string $path, string $taskKey): array
    {
        return ['phase' => 'before', 'artifact_type' => 'screenshot', 'browser_window' => 'main', 'task_card_key' => $taskKey, 'storage_path' => $path];
    }

    private function stepRun(): WorkflowStepRun
    {
        $workflow = Workflow::query()->create(['name' => 'Artifact persistence QA', 'slug' => 'artifact-'.str()->uuid(), 'is_active' => true, 'trigger_type' => 'manual']);
        $step = $workflow->steps()->create(['name' => 'Tasks', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'main', 'position' => 10, 'is_enabled' => true, 'config_json' => ['tasks' => []]]);
        $run = WorkflowRun::query()->create(['workflow_id' => $workflow->id, 'run_uuid' => (string) str()->uuid(), 'status' => 'running', 'context_json' => [], 'result_json' => []]);

        return WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id, 'status' => 'waiting']);
    }
}
