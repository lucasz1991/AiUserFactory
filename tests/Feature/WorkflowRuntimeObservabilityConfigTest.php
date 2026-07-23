<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkflowRuntimeObservabilityConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_nullable_workflow_live_preview_override_is_used_by_local_and_remote_runtime(): void
    {
        [$workflow, $step, $run, $stepRun] = $this->runtimeModels();
        $cases = [
            'missing override inherits enabled global setting' => [true, [], true],
            'null override inherits disabled global setting' => [false, ['live_preview' => null], false],
            'false override disables enabled global setting' => [true, ['live_preview' => false], false],
            'true override enables disabled global setting' => [false, ['live_preview' => true], true],
        ];

        foreach ($cases as $label => [$globalEnabled, $workflowSettings, $expected]) {
            $workflow->forceFill(['settings_json' => $workflowSettings])->save();
            $run->setRelation('workflow', $workflow->fresh());
            $mailSettings = Mockery::mock(MailAccountRegistrationRunner::class);
            $mailSettings->shouldReceive('settings')->twice()->andReturn([
                'live_preview_enabled' => $globalEnabled,
                'live_preview_interval_seconds' => 3,
            ]);
            $runner = new WorkflowTaskRunnerObservabilityStub($mailSettings);

            $remote = $runner->remoteRuntime($run, $step, $stepRun);
            $local = $runner->start($run, $step, $stepRun);

            try {
                $this->assertSame($expected, $remote['livePreviewEnabled'], $label.' (remote)');
                $this->assertSame($expected, $local['livePreviewEnabled'], $label.' (local)');
            } finally {
                $this->deleteTaskRunArtifacts((string) $local['runId']);
            }
        }
    }

    public function test_debug_capture_flags_are_off_without_development_and_copilot_remains_fully_observable(): void
    {
        [$workflow, $step, $run, $stepRun] = $this->runtimeModels();
        $mailSettings = Mockery::mock(MailAccountRegistrationRunner::class);
        $runner = new WorkflowTaskRunnerObservabilityStub($mailSettings);

        $workflow->forceFill(['settings_json' => [
            'dev_mode' => false,
            'dev_capture_dom_before_step' => true,
            'dev_capture_dom_after_step' => true,
            'dev_capture_screenshot_before_step' => true,
            'dev_capture_screenshot_after_step' => true,
            'dev_keep_artifacts' => true,
        ]])->save();
        $run->setRelation('workflow', $workflow->fresh());
        $normal = $runner->debugConfig($run, $step, $stepRun);

        $this->assertFalse($normal['enabled']);
        $this->assertCaptureFlags($normal, false);

        $workflow->forceFill(['settings_json' => ['dev_mode' => true]])->save();
        $run->setRelation('workflow', $workflow->fresh());
        $developmentWithoutExplicitCaptures = $runner->debugConfig($run, $step, $stepRun);

        $this->assertTrue($developmentWithoutExplicitCaptures['enabled']);
        $this->assertCaptureFlags($developmentWithoutExplicitCaptures, false);

        $workflow->forceFill(['settings_json' => [
            'dev_mode' => true,
            'dev_capture_dom_before_step' => true,
            'dev_capture_dom_after_step' => true,
            'dev_capture_screenshot_before_step' => true,
            'dev_capture_screenshot_after_step' => true,
            'dev_keep_artifacts' => true,
        ]])->save();
        $run->setRelation('workflow', $workflow->fresh());
        $development = $runner->debugConfig($run, $step, $stepRun);

        $this->assertTrue($development['enabled']);
        $this->assertCaptureFlags($development, true);

        $workflow->forceFill(['settings_json' => [
            'dev_mode' => false,
            'dev_capture_dom_before_step' => false,
            'dev_capture_dom_after_step' => false,
            'dev_capture_screenshot_before_step' => false,
            'dev_capture_screenshot_after_step' => false,
            'dev_keep_artifacts' => false,
        ]])->save();
        $run->forceFill(['context_json' => ['workflow_copilot_session_id' => 99]])->save();
        $run->setRelation('workflow', $workflow->fresh());
        $copilot = $runner->debugConfig($run, $step, $stepRun);

        $this->assertTrue($copilot['enabled']);
        $this->assertTrue($copilot['copilotObservation']);
        $this->assertCaptureFlags($copilot, true);
    }

    public function test_workflows_index_persists_development_capture_flags_and_preserves_preview_override(): void
    {
        foreach ([false, true] as $developmentEnabled) {
            $name = 'Observability '.($developmentEnabled ? 'an' : 'aus');

            Livewire::test(WorkflowsIndex::class)
                ->set('newWorkflowName', $name)
                ->set('newWorkflowPlanWithCopilot', false)
                ->set('newWorkflowDevelopment', $developmentEnabled)
                ->call('createWorkflow')
                ->assertHasNoErrors();

            $createdSettings = Workflow::query()->where('name', $name)->firstOrFail()->settings_json;
            $this->assertArrayHasKey('live_preview', $createdSettings);
            $this->assertNull($createdSettings['live_preview']);
            $this->assertDevelopmentSettings($createdSettings, $developmentEnabled);
        }

        $workflow = Workflow::query()->where('name', 'Observability an')->firstOrFail();
        $workflow->forceFill(['settings_json' => [
            ...$workflow->settings_json,
            'live_preview' => false,
            'unrelated_setting' => 'preserved',
        ]])->save();

        Livewire::test(WorkflowsIndex::class)
            ->call('openEditWorkflow', $workflow->id)
            ->set('editingWorkflowDevelopment', false)
            ->call('saveEditWorkflow')
            ->assertHasNoErrors();

        $editedSettings = $workflow->fresh()->settings_json;
        $this->assertFalse($editedSettings['live_preview']);
        $this->assertSame('preserved', $editedSettings['unrelated_setting']);
        $this->assertDevelopmentSettings($editedSettings, false);
    }

    private function runtimeModels(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Runtime Observability '.str()->random(6),
            'slug' => 'runtime-observability-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Runtime',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'runtime',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'wait',
                'task_key' => 'wait.seconds',
                'title' => 'Warten',
                'value' => 0,
            ]]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => [],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'result_json' => [],
        ]);
        $run->setRelation('workflow', $workflow);

        return [$workflow, $step, $run, $stepRun];
    }

    private function assertCaptureFlags(array $config, bool $expected): void
    {
        foreach ([
            'captureDomBeforeStep',
            'captureDomAfterStep',
            'captureScreenshotBeforeStep',
            'captureScreenshotAfterStep',
            'keepArtifacts',
        ] as $key) {
            $this->assertSame($expected, $config[$key], $key);
        }
    }

    private function assertDevelopmentSettings(array $settings, bool $expected): void
    {
        foreach ([
            'dev_mode',
            'dev_capture_dom_before_step',
            'dev_capture_dom_after_step',
            'dev_capture_screenshot_before_step',
            'dev_capture_screenshot_after_step',
            'dev_keep_artifacts',
        ] as $key) {
            $this->assertSame($expected, $settings[$key], $key);
        }
    }

    private function deleteTaskRunArtifacts(string $runId): void
    {
        File::deleteDirectory(storage_path('app/workflow-task-runs/'.$runId));
        File::deleteDirectory(storage_path('app/public/workflow-task-runs/'.$runId));
    }
}

class WorkflowTaskRunnerObservabilityStub extends WorkflowTaskRunner
{
    public function debugConfig(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): array
    {
        return $this->devDebugRuntimeConfig($run, $step, $stepRun);
    }

    protected function resolveNodeBinary(): string
    {
        return PHP_BINARY;
    }

    protected function resolveNodeScriptPath(): string
    {
        return base_path('node/workflows/run_step.cjs');
    }

    protected function spawnDetachedProcess(array $command, string $workingDirectory, string $stdoutPath, string $stderrPath, array $environment = []): ?int
    {
        return 4242;
    }
}
