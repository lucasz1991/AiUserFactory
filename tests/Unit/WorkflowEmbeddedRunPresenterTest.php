<?php

namespace Tests\Unit;

use App\Livewire\Admin\Network\WorkflowStudio;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowEmbeddedRunPresenter;
use App\Services\Workflows\WorkflowRunTaskFeedback;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowEmbeddedRunPresenterTest extends TestCase
{
    public function test_three_levels_are_frozen_and_each_ancestor_inclusion_is_active(): void
    {
        [$workflow, $run] = $this->fixture();
        DB::enableQueryLog();
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertCount(3, $maps);
        $this->assertSame(['outer', 'inner', 'deep'], array_column($maps, 'frame_key'));
        $this->assertSame([1, 2, 3], array_column($maps, 'depth'));
        $this->assertSame(['', 'outer', 'inner'], array_column($maps, 'parent_frame_key'));
        $this->assertSame(['inner-boundary', 'deep-boundary', 'deep-task'], array_column(array_column($maps, 'runtime_task'), 'task_key'));
        $this->assertSame(['Frozen outer', 'Frozen inner', 'Frozen deep'], array_column($maps, 'name'));
        $this->assertSame(['outer-a', 'inner-boundary', 'outer-z'], array_column($maps[0]['workflow']->steps[0]->task_cards, 'key'));
        $this->assertSame(['inner-a', 'deep-boundary', 'inner-z'], array_column($maps[1]['workflow']->steps[0]->task_cards, 'key'));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_pending_tasks_and_future_edges_never_gain_execution_colour(): void
    {
        [$workflow, $run] = $this->fixture();
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $outerEdges = collect($maps[0]['route_map']['edges']);
        $this->assertTrue($outerEdges->contains(fn ($edge) => $edge['source'] === 'outer-step::outer-a' && $edge['target'] === 'outer-step::inner-boundary' && ($edge['executed'] ?? false)));
        $this->assertFalse($outerEdges->contains(fn ($edge) => $edge['source'] === 'outer-step::inner-boundary' && ($edge['executed'] ?? false)));
        $this->assertSame('configured', $maps[0]['workflow']->steps[0]->task_cards[2]['status']);
        $this->assertSame(0, $maps[2]['route_map']['meta']['runtime_edge_count']);
    }

    public function test_public_live_snapshot_requires_exact_next_route_not_just_timestamps(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        unset($snapshot['events']);
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertGreaterThan(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        $snapshot['tasks'][0]['next'] = ['type' => 'card', 'card_key' => 'not-executed'];
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
    }

    public function test_implicit_successor_is_coloured_only_when_frozen_adjacent_and_actually_started(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        unset($snapshot['events'], $snapshot['tasks'][0]['next'], $snapshot['tasks'][1]['next']);
        $snapshot['configuredTasks'] = $snapshot['tasks'];
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertGreaterThan(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        $this->assertGreaterThan(0, $maps[1]['route_map']['meta']['runtime_edge_count']);
        $this->assertSame('2026-10-05T10:00:00.000Z', $maps[0]['workflow']->steps[0]->task_cards[0]['startedAt']);

        $skipped = $snapshot['tasks'][0];
        $skipped['key'] = 'configured-but-never-started';
        $skipped['status'] = 'configured';
        unset($skipped['startedAt'], $skipped['finishedAt']);
        array_splice($snapshot['tasks'], 1, 0, [$skipped]);
        $snapshot['configuredTasks'] = $snapshot['tasks'];
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        $this->assertGreaterThan(0, $maps[1]['route_map']['meta']['runtime_edge_count']);
    }

    public function test_dynamic_return_or_conditional_result_cannot_claim_implicit_successor(): void
    {
        foreach ([['route_target_key' => 'inner-a'], ['workflow_return' => true], ['task_key' => 'decision.contains_text'], ['status_routes' => ['success' => ['type' => 'card', 'card_key' => 'not-executed']]]] as $override) {
            [$workflow, $run] = $this->fixture();
            $snapshot = $run->stepRuns[0]->result_json;
            unset($snapshot['events'], $snapshot['tasks'][0]['next']);
            $snapshot['tasks'][0] = array_replace($snapshot['tasks'][0], $override);
            $snapshot['configuredTasks'] = $snapshot['tasks'];
            $run->stepRuns[0]->result_json = $snapshot;
            $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
            $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        }
    }

    public function test_final_executed_results_merge_frozen_definitions_without_losing_unvisited_cards(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['configuredTasks'] = $snapshot['tasks'];
        $snapshot['tasks'] = array_values(array_filter($snapshot['tasks'], fn ($task) => isset($task['startedAt']) || ($task['runner'] ?? '') === 'workflow-boundary'));
        foreach ($snapshot['tasks'] as &$task) {
            $task['status'] = 'success';
        }
        unset($task);
        $run->status = 'completed';
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertCount(3, $maps);
        $this->assertSame(['outer-a', 'inner-boundary', 'outer-z'], array_column($maps[0]['workflow']->steps[0]->task_cards, 'key'));
        $this->assertSame('configured', $maps[0]['workflow']->steps[0]->task_cards[2]['status']);
        $this->assertSame('configured', $maps[1]['workflow']->steps[0]->task_cards[2]['status']);
        $this->assertSame(['completed', 'completed', 'completed'], array_column($maps, 'status'));
    }

    public function test_executed_only_legacy_array_cannot_prove_an_implicit_route(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        unset($snapshot['events'], $snapshot['tasks'][0]['next']);
        $snapshot['tasks'] = array_values(array_filter($snapshot['tasks'], fn ($task) => isset($task['startedAt'])));
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
    }

    public function test_paused_and_terminal_runs_do_not_reanimate_a_stale_running_leaf(): void
    {
        foreach (['paused', 'failed', 'cancelled', 'completed'] as $status) {
            [$workflow, $run] = $this->fixture();
            $run->status = $status;
            $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
            foreach ($maps as $map) {
                $this->assertFalse($map['active']);
                $this->assertSame([], $map['runtime_task']);
                $this->assertNotSame('running', $map['status']);
            }
            $leaf = $maps[2]['workflow']->steps[0]->task_cards[0];
            $this->assertSame($status === 'paused' ? 'paused' : 'interrupted', $leaf['status']);
        }
    }

    public function test_foreign_and_ambiguous_snapshot_evidence_is_refused(): void
    {
        foreach (['workflowRunId', 'workflowStepRunId', 'workflowStepId'] as $field) {
            [$workflow, $run] = $this->fixture();
            $snapshot = $run->stepRuns[0]->result_json;
            $snapshot[$field] = 999999;
            $run->stepRuns[0]->result_json = $snapshot;
            $this->assertSame([], app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run));
        }
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['tasks'][] = $snapshot['tasks'][0];
        $run->stepRuns[0]->result_json = $snapshot;
        $this->assertSame([], app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run));
    }

    public function test_two_invocations_of_the_same_child_workflow_remain_isolated(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        $original = $snapshot['tasks'];
        foreach ($original as $task) {
            $task['key'] = 'second-'.$task['key'];
            $task['status'] = 'configured';
            unset($task['startedAt'], $task['finishedAt']);
            foreach ($task['embedded_workflow_path'] as &$frame) {
                $frame['frame_key'] = 'second-'.$frame['frame_key'];
                if ($frame['parent_frame_key'] !== '') {
                    $frame['parent_frame_key'] = 'second-'.$frame['parent_frame_key'];
                }
                $frame['include_task_key'] = 'second-'.$frame['include_task_key'];
            }
            unset($frame);
            if (isset($task['embedded_workflow_frame_key'])) {
                $task['embedded_workflow_frame_key'] = 'second-'.$task['embedded_workflow_frame_key'];
            }
            $snapshot['tasks'][] = $task;
        }
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertCount(6, $maps);
        $this->assertSame(['outer', 'inner', 'deep', 'second-outer', 'second-inner', 'second-deep'], array_column($maps, 'frame_key'));
        $this->assertSame([true, true, true, false, false, false], array_column($maps, 'active'));
        $this->assertSame(['configured', 'configured', 'configured'], array_column(array_slice($maps, 3), 'status'));
        foreach (array_slice($maps, 3) as $map) {
            $this->assertSame(0, $map['route_map']['meta']['runtime_edge_count']);
        }
    }

    public function test_completed_boundary_finishes_the_child_without_colouring_unexecuted_cards(): void
    {
        [$workflow, $run] = $this->fixture();
        $run->status = 'completed';
        $snapshot = $run->stepRuns[0]->result_json;
        foreach ($snapshot['tasks'] as &$task) {
            if (($task['runner'] ?? '') === 'workflow-boundary') {
                $task['status'] = 'success';
            }
            if ($task['key'] === 'deep-task') {
                $task['status'] = 'success';
            }
        }
        unset($task);
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(['completed', 'completed', 'completed'], array_column($maps, 'status'));
        $this->assertSame([false, false, false], array_column($maps, 'active'));
        $this->assertSame('configured', $maps[0]['workflow']->steps[0]->task_cards[2]['status']);
    }

    public function test_failed_leaf_marks_its_frame_failed_but_not_an_unstarted_sibling(): void
    {
        [$workflow, $run] = $this->fixture();
        $run->status = 'failed';
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['tasks'][2]['status'] = 'failed';
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(['failed', 'failed', 'failed'], array_column($maps, 'status'));
        $this->assertSame('failed', $maps[2]['workflow']->steps[0]->task_cards[0]['status']);
        $this->assertSame('configured', $maps[1]['workflow']->steps[0]->task_cards[2]['status']);
    }

    public function test_foreign_root_source_and_legacy_identity_do_not_generate_guessed_child_maps(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        foreach ($snapshot['tasks'] as &$task) {
            $task['embedded_workflow_path'][0]['source_step_id'] = 777;
        }
        unset($task);
        $run->stepRuns[0]->result_json = $snapshot;
        $this->assertSame([], app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run));
        foreach ($snapshot['tasks'] as &$task) {
            unset($task['embedded_workflow_path']);
            $task['embedded_workflow_id'] = 2;
            $task['embedded_workflow_name'] = 'Legacy without invocation proof';
        }
        unset($task);
        $run->stepRuns[0]->result_json = $snapshot;
        $this->assertSame([], app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run));
    }

    public function test_out_of_order_events_and_overlapping_timed_tasks_never_prove_edges(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['events'][1]['at'] = '2026-10-05T09:59:00.000Z';
        $snapshot['events'][3]['at'] = '2026-10-05T09:59:00.000Z';
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        $this->assertSame(0, $maps[1]['route_map']['meta']['runtime_edge_count']);
        unset($snapshot['events']);
        $snapshot['tasks'][0]['finishedAt'] = '2026-10-05T10:00:00.250Z';
        $snapshot['tasks'][1]['finishedAt'] = '2026-10-05T10:00:00.450Z';
        $run->stepRuns[0]->result_json = $snapshot;
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $this->assertSame(0, $maps[0]['route_map']['meta']['runtime_edge_count']);
        $this->assertSame(0, $maps[1]['route_map']['meta']['runtime_edge_count']);
    }

    public function test_browser_mini_and_modal_keys_are_distinct_and_share_live_connection_guard(): void
    {
        $mini = file_get_contents(resource_path('views/livewire/admin/network/workflow-studio/browser-windows.blade.php'));
        $modal = file_get_contents(resource_path('views/livewire/admin/network/workflow-studio/tool-modal.blade.php'));
        $this->assertStringContainsString('studio-browser-window-', $mini);
        $this->assertStringContainsString('studio-browser-modal-window-', $modal);
        $this->assertStringNotContainsString('wire:key="studio-browser-window-', $modal);
        $this->assertStringContainsString('$isActive && ! $historicalRunView', $mini);
        $this->assertStringContainsString('$isActive && ! $historicalRunView', $modal);
    }

    public function test_child_preview_is_desktop_compact_but_keeps_scroll_and_multilist_route_corridors(): void
    {
        $browser = file_get_contents(resource_path('views/livewire/admin/network/workflow-studio/browser.blade.php'));
        $children = file_get_contents(resource_path('views/livewire/admin/network/workflow-studio/embedded-minimaps.blade.php'));
        $css = file_get_contents(resource_path('css/workflow-experience.css'));
        $this->assertStringContainsString('lg:h-64', $browser);
        $this->assertStringContainsString('lg:max-h-48', $children);
        $this->assertStringContainsString('overflow-auto overscroll-contain', $children);
        $this->assertStringContainsString('tabindex="0"', $children);
        $this->assertStringContainsString("steps->count() === 1 ? 'ff-workflow-embedded-map-body--single-list'", $children);
        $this->assertStringContainsString('.ff-workflow-embedded-map-body--single-list .ff-route-stage', $css);
        $this->assertStringContainsString('.ff-route-stage { padding-top: 80px !important; padding-bottom: 80px !important;', $css);
        $this->assertStringContainsString('[data-workflow-embedded-frame] [data-workflow-minimap-zoom] > button', $css);
    }

    public function test_newer_finished_attempt_does_not_revive_an_old_active_frame(): void
    {
        [$workflow, $run] = $this->fixture();
        $newer = clone $run->stepRuns[0];
        $newer->id++;
        $newer->status = 'success';
        $newer->result_json = ['tasks' => []];
        $run->setRelation('stepRuns', collect([$run->stepRuns[0], $newer]));
        $this->assertSame([], app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run));
    }

    public function test_in_memory_minimap_reuses_native_renderer_without_database_queries_or_selection_writes(): void
    {
        [$workflow, $run] = $this->fixture();
        $maps = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run);
        $session = new WorkflowStudioSession;
        $session->id = 88;
        DB::enableQueryLog();
        $html = view('livewire.admin.network.workflow-studio.embedded-minimaps', ['embeddedWorkflowMaps' => $maps, 'session' => $session, 'run' => $run])->render();
        $this->assertStringContainsString('data-workflow-embedded-depth="3"', $html);
        $this->assertStringContainsString('data-workflow-route-evidence-mode="observed"', $html);
        $this->assertStringContainsString('data-workflow-minimap-active-target="true"', $html);
        $this->assertStringContainsString('border-emerald-300', $html);
        $this->assertStringContainsString('border-amber-300', $html);
        $this->assertStringNotContainsString('wire:poll', $html);
        $this->assertStringNotContainsString('workflow-preview-task-selected', $html);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_window_snapshots_use_exact_active_name_and_preserve_disconnected_state(): void
    {
        [$workflow, $run] = $this->fixture();
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['activeBrowserWindow'] = 'mail-leaf-popup';
        $snapshot['screenshotUrl'] = '/storage/child.jpg';
        $snapshot['windowStatus'] = ['targetId' => 'legacy-target'];
        $snapshot['browserWindows'] = [['key' => 'mail', 'targetId' => 'closed-target', 'closed' => true, 'connected' => false], ['key' => 'mail-leaf-popup', 'targetId' => 'legacy-target', 'screenshotUrl' => '/storage/child.jpg']];
        $run->stepRuns[0]->result_json = $snapshot;
        $run->context_json = ['browser_windows' => [['key' => 'mail', 'targetId' => 'old-target']]];
        $run->setRelation('artifacts', collect());
        $cards = (new ReflectionMethod(WorkflowStudio::class, 'browserWindowCards'))->invoke(new WorkflowStudio, $workflow, $run);
        $cards = collect($cards)->keyBy('name');
        $this->assertFalse($cards['mail']['connected']);
        $this->assertTrue($cards['mail-leaf-popup']['active']);
        $this->assertSame('/storage/child.jpg', $cards['mail-leaf-popup']['screenshot_url']);
        $this->assertFalse($cards->has('main'));
    }

    public function test_closed_window_keeps_last_preview_but_is_not_reconnected_from_old_step_snapshot(): void
    {
        [$workflow, $run] = $this->fixture();
        $old = clone $run->stepRuns[0];
        $old->id = 89;
        $old->result_json = ['browserWindows' => [['key' => 'mail', 'targetId' => 'old-target', 'screenshotUrl' => '/storage/old.jpg']]];
        $snapshot = $run->stepRuns[0]->result_json;
        $snapshot['browserWindows'] = [];
        $run->stepRuns[0]->result_json = $snapshot;
        $run->context_json = ['browser_windows' => [['key' => 'mail', 'targetId' => 'old-target']]];
        $run->setRelation('stepRuns', collect([$old, $run->stepRuns[0]]))->setRelation('artifacts', collect());
        $cards = (new ReflectionMethod(WorkflowStudio::class, 'browserWindowCards'))->invoke(new WorkflowStudio, $workflow, $run);
        $mail = collect($cards)->keyBy('name')['mail'];
        $this->assertFalse($mail['connected']);
        $this->assertFalse($mail['active']);
        $this->assertSame('/storage/old.jpg', $mail['screenshot_url']);
    }

    public function test_native_feedback_uses_loaded_owned_results_and_excludes_foreign_runs(): void
    {
        [$workflow, $run] = $this->fixture();
        $map = app(WorkflowEmbeddedRunPresenter::class)->present($workflow, $run)[2];
        $foreign = clone $map['run']->stepRuns[0];
        $foreign->workflow_run_id = 999;
        $foreign->result_json = ['tasks' => [['key' => 'deep-task', 'status' => 'failed']]];
        $map['run']->setRelation('stepRuns', $map['run']->stepRuns->push($foreign));
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($map['workflow'], $map['run']);
        $this->assertSame('running', $feedback[40]['deep-task']['status']);
    }

    private function fixture(): array
    {
        $workflow = new Workflow(['name' => 'Root']);
        $workflow->id = 1;
        $step = new WorkflowStep(['workflow_id' => 1, 'name' => 'Root list', 'action_key' => 'root', 'is_enabled' => true, 'config_json' => ['tasks' => [['key' => 'outer-include', 'task_key' => 'workflow.2', 'title' => 'Outer include']]]]);
        $step->id = 10;
        $workflow->setRelation('steps', collect([$step]));
        $outer = $this->frame('outer', '', 2, 10, 'root', 'outer-include', 10);
        $inner = $this->frame('inner', 'outer', 3, 20, 'outer-step', 'inner-include', 20);
        $deep = $this->frame('deep', 'inner', 4, 30, 'inner-step', 'deep-include', 20);
        $tasks = [
            $this->task('outer-a', [$outer], 20, 'outer-step', 10, 'success', '00.000', '00.100', 'inner-a'),
            $this->task('inner-a', [$outer, $inner], 30, 'inner-step', 10, 'success', '00.200', '00.300', 'deep-task'),
            $this->task('deep-task', [$outer, $inner, $deep], 40, 'deep-step', 10, 'running', '00.400'),
            $this->boundary('deep-boundary', [$outer, $inner, $deep], 'deep'),
            $this->task('inner-z', [$outer, $inner], 30, 'inner-step', 30),
            $this->boundary('inner-boundary', [$outer, $inner], 'inner'),
            $this->task('outer-z', [$outer], 20, 'outer-step', 30),
            $this->boundary('outer-boundary', [$outer], 'outer'),
        ];
        $events = [
            ['stage' => 'task-completed', 'taskKey' => 'outer-a', 'at' => '2026-10-05T10:00:00.100Z'],
            ['stage' => 'task-started', 'taskKey' => 'inner-a', 'at' => '2026-10-05T10:00:00.200Z'],
            ['stage' => 'task-completed', 'taskKey' => 'inner-a', 'at' => '2026-10-05T10:00:00.300Z'],
            ['stage' => 'task-started', 'taskKey' => 'deep-task', 'at' => '2026-10-05T10:00:00.400Z'],
        ];
        $run = new WorkflowRun(['workflow_id' => 1, 'status' => 'running', 'current_workflow_step_id' => 10, 'context_json' => []]);
        $run->id = 70;
        $stepRun = new WorkflowStepRun(['workflow_run_id' => 70, 'workflow_step_id' => 10, 'status' => 'running', 'result_json' => ['tasks' => $tasks, 'events' => $events]]);
        $stepRun->id = 90;
        $run->setRelation('stepRuns', collect([$stepRun]));

        return [$workflow, $run];
    }

    private function frame(string $key, string $parent, int $id, int $stepId, string $action, string $include, int $order): array
    {
        return ['frame_key' => $key, 'parent_frame_key' => $parent, 'workflow_id' => $id, 'workflow_name' => 'Frozen '.$key,
            'source_step_id' => $stepId, 'source_step_action_key' => $action, 'source_step_name' => $action, 'source_step_position' => 1,
            'include_task_key' => $include, 'include_task_title' => ucfirst($key).' include', 'include_task_order' => $order, 'browser_window' => 'mail'];
    }

    private function task(string $key, array $path, int $stepId, string $action, int $order, string $status = 'configured', ?string $start = null, ?string $finish = null, ?string $next = null): array
    {
        return array_filter(['key' => $key, 'title' => $key, 'task_key' => 'wait.seconds', 'status' => $status, 'runner' => 'node',
            'embedded_workflow_path' => $path, 'embedded_source_step_id' => $stepId, 'embedded_source_action_key' => $action,
            'embedded_source_step_name' => $action, 'embedded_source_step_position' => 1, 'embedded_source_task_order' => $order,
            'browser_window' => $key === 'deep-task' ? 'mail-leaf-popup' : 'mail',
            'startedAt' => $start ? '2026-10-05T10:00:'.$start.'Z' : null, 'finishedAt' => $finish ? '2026-10-05T10:00:'.$finish.'Z' : null,
            'next' => $next ? ['type' => 'card', 'card_key' => $next] : null], fn ($value) => $value !== null);
    }

    private function boundary(string $key, array $path, string $frame): array
    {
        return ['key' => $key, 'runner' => 'workflow-boundary', 'status' => 'configured', 'embedded_workflow_frame_key' => $frame, 'embedded_workflow_path' => $path];
    }
}
