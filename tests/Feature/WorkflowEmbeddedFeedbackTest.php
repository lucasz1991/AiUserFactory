<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowRunTaskFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEmbeddedFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_feedback_reads_unloaded_database_results_and_fresh_loaded_poll_results(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Feedback', 'slug' => 'embedded-feedback', 'is_active' => true, 'trigger_type' => 'manual']);
        $step = $workflow->steps()->create(['name' => 'Tasks', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'tasks', 'position' => 10, 'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'first', 'task_key' => 'wait.seconds', 'title' => 'First', 'value' => 0]]]]);
        $run = WorkflowRun::query()->create(['run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'status' => 'running']);
        $stepRun = WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => ['tasks' => [['key' => 'first', 'status' => 'running']]]]);
        $service = app(WorkflowRunTaskFeedback::class);
        $this->assertSame('running', $service->tasks($workflow, $run)[$step->id]['first']['status']);
        $loadedRun = $run->fresh()->load('stepRuns');
        $this->assertSame('running', $service->tasks($workflow, $loadedRun)[$step->id]['first']['status']);
        $stepRun->update(['result_json' => ['tasks' => [['key' => 'first', 'status' => 'success']]]]);
        // Studio's render/poll hydrates a fresh run, so the relation-aware fast
        // path does not cache a previous poll's task state.
        $freshPollRun = $run->fresh()->load('stepRuns');
        $this->assertSame('success', $service->tasks($workflow, $freshPollRun)[$step->id]['first']['status']);
        $this->assertSame('success', $service->tasks($workflow, $run->fresh())[$step->id]['first']['status']);
    }

    public function test_empty_runner_include_turns_green_only_from_its_outer_closing_boundary(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $this->snapshot($run, $step, [$boundary, ['key' => 'include', 'status' => 'template']]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('success', $feedback[$step->id]['include']['status']);
        $this->assertFalse($feedback[$step->id]['include']['failed']);
    }

    public function test_inner_boundary_does_not_finish_the_root_include(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $inner = $boundary;
        $inner['key'] = 'inner-boundary';
        $inner['embedded_workflow_frame_key'] = 'inner';
        $inner['embedded_workflow_path'][] = ['frame_key' => 'inner', 'parent_frame_key' => $boundary['embedded_workflow_frame_key']];
        $this->snapshot($run, $step, [$inner, ['key' => 'include', 'status' => 'template']]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('template', $feedback[$step->id]['include']['status']);
    }

    public function test_completed_run_and_completed_leaves_do_not_invent_a_successful_boundary(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $leaf = array_replace($boundary, ['key' => 'leaf', 'runner' => 'node']);
        unset($leaf['embeddedWorkflowCompleted']);
        $configured = array_replace($boundary, ['status' => 'configured']);
        unset($configured['embeddedWorkflowCompleted']);
        $this->snapshot($run, $step, [$leaf], [$leaf, $configured]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('interrupted', $feedback[$step->id]['include']['status']);
    }

    public function test_forged_configured_completion_is_not_execution_evidence(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $leaf = array_replace($boundary, ['key' => 'leaf', 'runner' => 'node']);
        $this->snapshot($run, $step, [$leaf], [$leaf, $boundary]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertNotContains($feedback[$step->id]['include']['status'], ['success', 'completed']);
    }

    public function test_newer_paused_attempt_does_not_keep_an_older_include_green(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $this->snapshot($run, $step, [$boundary]);
        $run->context_json = ['task_history' => [['workflow_step_id' => $step->id, 'task_key' => 'include', 'status' => 'success']]];
        $leaf = array_replace($boundary, ['key' => 'leaf', 'runner' => 'node', 'status' => 'running']);
        unset($leaf['embeddedWorkflowCompleted']);
        $configured = array_replace($boundary, ['status' => 'configured']);
        unset($configured['embeddedWorkflowCompleted']);
        $this->snapshot($run, $step, [$leaf], [$leaf, $configured]);
        $run->status = 'paused';
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('paused', $feedback[$step->id]['include']['status']);
    }

    public function test_identical_include_keys_in_other_root_lists_do_not_share_boundary_status(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $otherStep = $workflow->steps()->create(['name' => 'Other', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'other', 'position' => 20, 'is_enabled' => true,
            'config_json' => ['tasks' => $step->task_cards]]);
        $this->snapshot($run, $step, [$boundary]);
        $this->snapshot($run, $otherStep, [$boundary, ['key' => 'include', 'status' => 'template']]);
        $workflow->unsetRelation('steps');
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('success', $feedback[$step->id]['include']['status']);
        $this->assertSame('template', $feedback[$otherStep->id]['include']['status']);
    }

    public function test_mismatching_frame_source_child_or_parent_metadata_is_not_positive_evidence(): void
    {
        foreach (['frame', 'source', 'child', 'parent', 'snapshot'] as $case) {
            [$workflow, $step, $run, $boundary] = $this->embeddedFixture($case);
            if ($case === 'frame') {
                $boundary['embedded_workflow_frame_key'] = 'foreign';
            } elseif ($case === 'source') {
                $boundary['composition_root_step_id'] = 99999;
            } elseif ($case === 'child') {
                $boundary['embedded_workflow_path'][0]['workflow_id'] = 99999;
            } elseif ($case === 'parent') {
                $boundary['parent_task_key'] = 'other-include';
            }
            $snapshot = $this->snapshot($run, $step, [$boundary, ['key' => 'include', 'status' => 'template']]);
            if ($case === 'snapshot') {
                $snapshot->update(['result_json' => $snapshot->result_json + ['workflowRunId' => 99999]]);
            }
            $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
            $this->assertNotContains($feedback[$step->id]['include']['status'] ?? '', ['success', 'completed']);
        }
    }

    public function test_actual_failed_boundary_is_red_but_success_with_false_result_is_not_green(): void
    {
        [$workflow, $step, $run, $boundary] = $this->embeddedFixture();
        $boundary['status'] = 'failed';
        $boundary['ok'] = false;
        $this->snapshot($run, $step, [$boundary]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('failed', $feedback[$step->id]['include']['status']);
        $this->assertTrue($feedback[$step->id]['include']['failed']);
        $boundary['status'] = 'success';
        $this->snapshot($run, $step, [$boundary]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertSame('interrupted', $feedback[$step->id]['include']['status']);
    }

    private function snapshot(WorkflowRun $run, WorkflowStep $step, array $tasks, ?array $definitions = null): WorkflowStepRun
    {
        return WorkflowStepRun::query()->updateOrCreate(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id], ['status' => 'success',
            'result_json' => ['tasks' => $tasks] + ($definitions !== null ? ['configuredTasks' => $definitions] : [])]);
    }

    private function embeddedFixture(string $suffix = ''): array
    {
        $child = Workflow::query()->create(['name' => 'Child', 'slug' => 'feedback-child-'.$suffix, 'is_active' => true, 'trigger_type' => 'manual']);
        $workflow = Workflow::query()->create(['name' => 'Root', 'slug' => 'feedback-root-'.$suffix, 'is_active' => true, 'trigger_type' => 'manual']);
        $step = $workflow->steps()->create(['name' => 'Tasks', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'tasks', 'position' => 10, 'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'include', 'task_key' => 'workflow.include.'.$child->id, 'workflow_id' => $child->id, 'title' => 'Include', 'runner' => '']]]]);
        $run = WorkflowRun::query()->create(['run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'status' => 'completed']);
        $frameKey = 'root-step-'.$step->id.'-workflow-'.$child->id.'-include';
        $boundary = ['key' => $frameKey.'-boundary', 'runner' => 'workflow-boundary', 'status' => 'success', 'ok' => true, 'embeddedWorkflowCompleted' => true,
            'embedded_workflow_frame_key' => $frameKey, 'composition_root_step_id' => $step->id, 'composition_root_task_key' => 'include', 'parent_task_key' => 'include',
            'embedded_workflow_path' => [['frame_key' => $frameKey, 'parent_frame_key' => null, 'source_step_id' => $step->id,
                'source_step_action_key' => $step->action_key, 'include_task_key' => 'include', 'workflow_id' => $child->id]]];

        return [$workflow, $step, $run, $boundary];
    }
}
