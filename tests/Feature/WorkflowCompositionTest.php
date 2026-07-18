<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

class WorkflowCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_included_workflow_is_automatically_locked_and_unlocked_with_its_reference(): void
    {
        $parent = $this->workflow('parent');
        $child = $this->workflow('child');
        $childStep = $this->step($child, 'Child list', [
            $this->waitTask('child-wait'),
        ]);
        $parentStep = $this->step($parent, 'Parent list', [
            $this->workflowTask($child, 'child-workflow'),
        ]);

        $child->refresh();

        $this->assertTrue($child->is_included);
        $this->assertTrue($child->is_edit_locked);
        $this->assertTrue($child->includedByWorkflows()->whereKey($parent->id)->exists());

        $manager = app(WorkflowManager::class);
        $manager->mount($child);
        $manager->workflowDescription = 'Von einem Admin bearbeitet.';
        $manager->saveWorkflow();
        $this->assertSame('Von einem Admin bearbeitet.', $child->fresh()->description);

        Livewire::test(WorkflowManager::class, ['workflow' => $child])
            ->assertSee('Achtung: Dieser Workflow ist gesperrt.')
            ->assertSee('Als Admin kannst du ihn trotzdem bearbeiten.')
            ->assertSee('Testen')
            ->assertSee('Task-Bibliothek');

        $tasks = $this->runtimeTasks($parentStep);

        $this->assertCount(2, $tasks);
        $this->assertSame('node', $tasks[0]['runner']);
        $this->assertSame('child-workflow', $tasks[0]['parent_task_key']);
        $this->assertSame($child->id, $tasks[0]['embedded_workflow_id']);
        $this->assertStringContainsString((string) $childStep->id, $tasks[0]['key']);
        $this->assertSame('workflow-boundary', $tasks[1]['runner']);
        $this->assertSame('child-workflow', $tasks[1]['route_source_task_key']);
        $this->assertSame($tasks[0]['embedded_workflow_frame_key'], $tasks[1]['embedded_workflow_frame_key']);

        $manager->toggleStep($childStep->id);
        $this->assertFalse($childStep->fresh()->is_enabled);

        $parentStep->forceFill(['config_json' => ['tasks' => []]])->save();
        $child->refresh();

        $this->assertFalse($child->is_included);
        $this->assertFalse($child->is_edit_locked);
    }

    public function test_manual_lock_is_part_of_the_effective_edit_lock(): void
    {
        $workflow = $this->workflow('manual-lock');

        $this->assertFalse($workflow->is_edit_locked);

        $workflow->forceFill(['is_locked' => true])->save();
        $workflow->refresh();

        $this->assertTrue($workflow->is_edit_locked);
        $this->assertSame('Manuell gesperrt.', $workflow->lock_reason);
    }

    public function test_workflow_manager_imports_existing_workflow_lists_and_tasks(): void
    {
        $target = $this->workflow('import-target');
        $existingStep = $this->step($target, 'Existing collision', [$this->waitTask('existing')]);
        $existingStep->forceFill(['action_key' => 'imported-start'])->save();

        $source = $this->workflow('import-source');
        $startTask = $this->waitTask('start');
        $startTask['next'] = [
            'type' => 'card',
            'action_key' => 'imported-start',
            'step' => 'imported-start',
            'card_key' => 'check',
            'card' => 'check',
        ];
        $checkTask = $this->waitTask('check');
        $checkTask['next'] = [
            'type' => 'step',
            'action_key' => 'imported-finish',
            'step' => 'imported-finish',
        ];
        $checkTask['on_error'] = [
            'type' => 'card',
            'action_key' => 'imported-start',
            'step' => 'imported-start',
            'card_key' => 'start',
            'card' => 'start',
            'max_attempts' => 2,
        ];

        $source->steps()->create([
            'name' => 'Imported start',
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => 'imported-start',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [$startTask, $checkTask],
                'routes' => [
                    'success' => [
                        'type' => 'step',
                        'action_key' => 'imported-finish',
                        'step' => 'imported-finish',
                    ],
                ],
            ],
        ]);
        $source->steps()->create([
            'name' => 'Imported finish',
            'type' => WorkflowStep::TYPE_CLEANUP,
            'action_key' => 'imported-finish',
            'position' => 20,
            'is_enabled' => false,
            'config_json' => [
                'tasks' => [$this->waitTask('finish')],
                'routes' => [
                    'success' => ['type' => 'end', 'step' => 'end'],
                ],
            ],
            'wait_after_seconds' => 4,
        ]);

        Livewire::test(WorkflowManager::class, ['workflow' => $target])
            ->set('newStepCreationMode', 'import')
            ->set('importWorkflowId', (string) $source->id)
            ->call('importWorkflowSteps')
            ->assertHasNoErrors();

        $steps = $target->steps()->ordered()->get();
        $importedStart = $target->steps()->where('name', 'Imported start')->first();
        $importedFinish = $target->steps()->where('name', 'Imported finish')->first();

        $this->assertCount(3, $steps);
        $this->assertNotNull($importedStart);
        $this->assertNotNull($importedFinish);
        $this->assertSame('imported-start-2', $importedStart->action_key);
        $this->assertSame('imported-finish', $importedFinish->action_key);
        $this->assertFalse($importedFinish->is_enabled);
        $this->assertSame(4, $importedFinish->wait_after_seconds);
        $this->assertSame($importedFinish->action_key, data_get($importedStart->config_json, 'routes.success.action_key'));
        $this->assertSame($importedFinish->action_key, data_get($importedStart->config_json, 'routes.success.step'));

        $copiedStartTask = collect($importedStart->task_cards)->firstWhere('key', 'start');
        $copiedCheckTask = collect($importedStart->task_cards)->firstWhere('key', 'check');

        $this->assertSame($importedStart->action_key, data_get($copiedStartTask, 'next.action_key'));
        $this->assertSame($importedStart->action_key, data_get($copiedStartTask, 'next.step'));
        $this->assertSame('check', data_get($copiedStartTask, 'next.card_key'));
        $this->assertSame($importedFinish->action_key, data_get($copiedCheckTask, 'next.action_key'));
        $this->assertSame($importedStart->action_key, data_get($copiedCheckTask, 'on_error.action_key'));
        $this->assertSame('start', data_get($copiedCheckTask, 'on_error.card_key'));
        $this->assertSame(2, data_get($copiedCheckTask, 'on_error.max_attempts'));
    }

    public function test_workflow_list_duplicates_workflow_definitions_and_shows_run_stats(): void
    {
        $workflow = $this->workflow('duplicate-source');
        $this->step($workflow, 'Duplicate list', [$this->waitTask('duplicate-task')]);
        WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'context_json' => [],
            'result_json' => [],
        ]);
        WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'failed',
            'context_json' => [],
            'result_json' => [],
        ]);
        WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'cancelled',
            'context_json' => [],
            'result_json' => [],
        ]);

        Livewire::test(WorkflowsIndex::class)
            ->assertSee('B 3')
            ->assertSee('OK 1')
            ->assertSee('F 1')
            ->call('duplicateWorkflow', $workflow->id)
            ->call('duplicateWorkflow', $workflow->id)
            ->assertHasNoErrors();

        $firstCopy = Workflow::query()->where('name', 'Duplicate source 01')->first();
        $secondCopy = Workflow::query()->where('name', 'Duplicate source 02')->first();

        $this->assertNotNull($firstCopy);
        $this->assertNotNull($secondCopy);
        $this->assertSame('duplicate-source-01', $firstCopy->slug);
        $this->assertFalse($firstCopy->is_locked);
        $this->assertSame(0, $firstCopy->runs()->count());

        $copiedStep = $firstCopy->steps()->first();

        $this->assertNotNull($copiedStep);
        $this->assertSame('Duplicate list', $copiedStep->name);
        $this->assertSame('duplicate-task', data_get($copiedStep->task_cards, '0.key'));
    }

    public function test_task_error_routes_resume_at_the_target_card_and_detect_back_routes(): void
    {
        $workflow = $this->workflow('task-routes');
        $first = $this->waitTask('first');
        $second = $this->waitTask('second');
        $second['on_error'] = [
            'type' => 'card',
            'action_key' => 'route-list',
            'step' => 'route-list',
            'card_key' => 'first',
            'card' => 'first',
            'max_attempts' => 2,
        ];
        $second['next'] = ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'];
        $third = $this->waitTask('third');
        $step = $this->step($workflow, 'Route list', [$first, $second, $third]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => [],
            'result_json' => [],
        ])->load('workflow.steps');

        $executionReflection = new ReflectionClass(WorkflowExecutionService::class);
        $execution = $executionReflection->newInstanceWithoutConstructor();
        $routeMethod = $executionReflection->getMethod('routeForResult');
        $route = $routeMethod->invoke($execution, $step, 'failed', [
            'failedTaskKey' => 'second',
            'tasks' => [['key' => 'second', 'status' => 'failed']],
        ]);

        $this->assertSame('first', $route['card_key']);
        $this->assertSame('second', $route['_source_card_key']);
        $this->assertSame(2, $route['max_attempts']);

        $successRoute = $routeMethod->invoke($execution, $step, 'success', [
            'routeRequested' => true,
            'completedTaskKey' => 'second',
        ]);
        $this->assertSame('end', $successRoute['type']);

        $branchRoute = $routeMethod->invoke($execution, $step, 'failed', [
            'ok' => true,
            'routeRequested' => true,
            'routeOutcome' => 'failed',
            'completedTaskKey' => 'second',
        ]);
        $this->assertSame('first', $branchRoute['card_key']);
        $this->assertSame('second', $branchRoute['_source_card_key']);

        $outcomeMethod = $executionReflection->getMethod('resultOutcome');
        $this->assertSame('failed', $outcomeMethod->invoke($execution, [
            'ok' => true,
            'status' => 'success',
            'routeRequested' => true,
            'routeOutcome' => 'failed',
        ]));

        $backRouteMethod = $executionReflection->getMethod('isBackRoute');
        $this->assertTrue($backRouteMethod->invoke($execution, $run, $step, $route));

        $runtimeTasks = $this->runtimeTasks($step, 'second');
        $this->assertSame(['second', 'third'], collect($runtimeTasks)->pluck('key')->all());

        $manager = app(WorkflowManager::class);
        $manager->mount($workflow);
        $managerReflection = new ReflectionClass($manager);
        $nextRouteMethod = $managerReflection->getMethod('taskRouteTargetFromValue');
        $nextRoute = $nextRouteMethod->invoke($manager, 'next', $step, 'second', null);

        $this->assertSame('third', $nextRoute['card_key']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openEditTaskCard', $step->id, 'second')
            ->set('editingTaskInputValue', '0')
            ->set('editingTaskFailedTarget', 'card:'.$step->id.':first')
            ->set('editingTaskFailedRetryLimit', 2)
            ->call('saveEditTaskCard')
            ->assertHasNoErrors();

        $savedSecondTask = collect($step->fresh()->task_cards)->firstWhere('key', 'second');
        $this->assertSame('first', data_get($savedSecondTask, 'on_error.card_key'));
        $this->assertSame(2, data_get($savedSecondTask, 'on_error.max_attempts'));

        $decisionTask = app(WorkflowTaskCatalog::class)->task('decision.element_exists');
        $this->assertSame('node/workflows/tasks/decision/element_exists.cjs', $decisionTask['node_script']);
        $this->assertTrue((bool) data_get($decisionTask, 'form.timeout'));
        $this->assertSame('Suchdauer in Sekunden', data_get($decisionTask, 'form.timeout_label'));
    }

    public function test_only_executable_task_error_routes_continue_the_workflow(): void
    {
        $workflow = $this->workflow('terminal-task-routes');
        $task = $this->waitTask('source-task');
        $step = $this->step($workflow, 'Source list', [$task]);
        $config = $step->config_json;
        $config['routes']['failed'] = [
            'type' => 'step',
            'action_key' => 'legacy-step-fallback',
        ];
        $step->forceFill(['config_json' => $config])->save();

        $executionReflection = new ReflectionClass(WorkflowExecutionService::class);
        $execution = $executionReflection->newInstanceWithoutConstructor();
        $routeMethod = $executionReflection->getMethod('routeForResult');
        $continuableMethod = $executionReflection->getMethod('isContinuableFailureRoute');

        $this->assertNull($routeMethod->invoke($execution, $step->fresh(), 'failed', [
            'failedTaskKey' => 'source-task',
            'tasks' => [['key' => 'source-task', 'status' => 'failed']],
        ]));
        $this->assertTrue($continuableMethod->invoke($execution, [
            'type' => 'card',
            'action_key' => 'source-list',
            'card_key' => 'alternative-task',
        ]));
        $this->assertTrue($continuableMethod->invoke($execution, [
            'type' => 'step',
            'action_key' => 'alternative-list',
        ]));
        $this->assertFalse($continuableMethod->invoke($execution, null));
        $this->assertFalse($continuableMethod->invoke($execution, ['type' => 'end', 'step' => 'end']));
        $this->assertFalse($continuableMethod->invoke($execution, ['type' => 'fail', 'step' => 'fail']));
    }

    public function test_embedded_workflow_boundary_preserves_success_and_failure_routes(): void
    {
        $parent = $this->workflow('parent-routes');
        $child = $this->workflow('child-return');
        $returnTask = app(WorkflowTaskCatalog::class)->cardFromDefinition('data.workflow_return', [
            'key' => 'return-result',
            'value' => 'true',
        ]);
        $this->step($child, 'Child return list', [$returnTask]);

        $workflowTask = $this->workflowTask($child, 'child-workflow');
        $workflowTask['next'] = [
            'type' => 'card',
            'action_key' => 'parent-list',
            'card_key' => 'success-target',
        ];
        $workflowTask['on_error'] = [
            'type' => 'card',
            'action_key' => 'parent-list',
            'card_key' => 'failure-target',
            'max_attempts' => 2,
        ];
        $parentStep = $this->step($parent, 'Parent list', [
            $workflowTask,
            $this->waitTask('success-target'),
            $this->waitTask('failure-target'),
        ]);

        $tasks = $this->runtimeTasks($parentStep);
        $embeddedReturn = collect($tasks)->firstWhere('task_key', 'data.workflow_return');
        $boundary = collect($tasks)->firstWhere('runner', 'workflow-boundary');

        $this->assertNotNull($embeddedReturn);
        $this->assertNotNull($boundary);
        $this->assertSame($embeddedReturn['embedded_workflow_frame_key'], $boundary['embedded_workflow_frame_key']);
        $this->assertSame('child-workflow', $boundary['parent_task_key']);
        $this->assertSame('success-target', data_get($boundary, 'next.card_key'));
        $this->assertSame('failure-target', data_get($boundary, 'on_error.card_key'));
        $this->assertSame(2, data_get($boundary, 'on_error.max_attempts'));

        $executionReflection = new ReflectionClass(WorkflowExecutionService::class);
        $execution = $executionReflection->newInstanceWithoutConstructor();
        $routeMethod = $executionReflection->getMethod('routeForResult');
        $failedRoute = $routeMethod->invoke($execution, $parentStep, 'failed', [
            'failedTaskKey' => $boundary['key'],
            'tasks' => [[
                'key' => $boundary['key'],
                'parent_task_key' => $boundary['parent_task_key'],
                'status' => 'failed',
            ]],
        ]);

        $this->assertSame('failure-target', $failedRoute['card_key']);
        $this->assertSame('child-workflow', $failedRoute['_source_card_key']);
        $this->assertSame(2, $failedRoute['max_attempts']);
    }

    public function test_embedded_workflow_browser_windows_are_mapped_to_parent_identifier(): void
    {
        $parent = $this->workflow('parent-browser');
        $child = $this->workflow('child-browser');
        $this->step($child, 'Child browser list', [
            [
                'key' => 'open-main',
                'task_key' => 'browser.open',
                'title' => 'Open main',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open.cjs',
                'browser_window' => 'main',
                'browser_window_name' => 'main',
            ],
            [
                'key' => 'open-popup',
                'task_key' => 'browser.open_url',
                'title' => 'Open popup',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open_url.cjs',
                'browser_window' => 'popup',
                'browser_window_name' => 'popup',
                'url' => 'https://example.test',
            ],
        ]);

        $workflowTask = $this->workflowTask($child, 'child-workflow');
        $workflowTask['browser_window'] = 'child-session';
        $workflowTask['browser_window_name'] = 'child-session';
        $parentStep = $this->step($parent, 'Parent list', [$workflowTask]);

        $tasks = $this->runtimeTasks($parentStep);
        $openMain = collect($tasks)->firstWhere('task_key', 'browser.open');
        $openPopup = collect($tasks)->firstWhere('task_key', 'browser.open_url');
        $boundary = collect($tasks)->firstWhere('runner', 'workflow-boundary');

        $this->assertSame('child-session', $openMain['browser_window_name']);
        $this->assertSame('child-session-popup', $openPopup['browser_window_name']);
        $this->assertSame('child-session', $boundary['embedded_workflow_browser_window']);
    }

    public function test_embedded_workflow_receives_configured_variables_and_open_browser_window(): void
    {
        $parent = $this->workflow('parent-inputs');
        $child = $this->workflow('child-inputs');
        $this->step($child, 'Validate child inputs', [$this->waitTask('child-input')]);

        $workflowTask = $this->workflowTask($child, 'child-with-inputs');
        $workflowTask['browser_window'] = 'webmail';
        $workflowTask['browser_window_name'] = 'webmail';
        $workflowTask['workflow_input_variables'] = json_encode([
            'Mail-Inbox-Liste-Scan.subject_filter' => 'workflow_variables.parent_subject_filter',
            'fixed_value' => 'literal:test-value',
        ], JSON_THROW_ON_ERROR);
        $parentStep = $this->step($parent, 'Parent input list', [$workflowTask]);

        $tasks = $this->runtimeTasks($parentStep);
        $childTask = collect($tasks)->firstWhere('runner', 'node');
        $boundary = collect($tasks)->firstWhere('runner', 'workflow-boundary');

        $this->assertSame(
            'workflow_variables.parent_subject_filter',
            $childTask['embedded_workflow_inputs']['Mail-Inbox-Liste-Scan.subject_filter'],
        );
        $this->assertSame('literal:test-value', $childTask['embedded_workflow_inputs']['fixed_value']);
        $this->assertSame(['literal' => 'webmail'], $childTask['embedded_workflow_inputs']['browser_window']);
        $this->assertSame($childTask['embedded_workflow_inputs'], $boundary['embedded_workflow_inputs']);
    }

    public function test_embedded_workflow_internal_routes_are_remapped_to_runtime_tasks(): void
    {
        $parent = $this->workflow('parent-internal-routes');
        $child = $this->workflow('child-internal-routes');
        $first = $this->waitTask('child-first');
        $first['next'] = [
            'type' => 'card',
            'card_key' => 'child-second',
            'card' => 'child-second',
        ];
        $second = $this->waitTask('child-second');
        $second['next'] = [
            'type' => 'end',
            'step' => 'end',
        ];
        $this->step($child, 'Child internal list', [$first, $second]);

        $workflowTask = $this->workflowTask($child, 'child-workflow');
        $workflowTask['next'] = [
            'type' => 'card',
            'card_key' => 'parent-after-child',
            'card' => 'parent-after-child',
        ];
        $parentStep = $this->step($parent, 'Parent list', [
            $workflowTask,
            $this->waitTask('parent-after-child'),
        ]);

        $tasks = $this->runtimeTasks($parentStep);
        $runtimeFirst = collect($tasks)->first(fn (array $task): bool => str_ends_with((string) $task['key'], 'child-first'));
        $runtimeSecond = collect($tasks)->first(fn (array $task): bool => str_ends_with((string) $task['key'], 'child-second'));
        $boundary = collect($tasks)->firstWhere('runner', 'workflow-boundary');

        $this->assertNotNull($runtimeFirst);
        $this->assertNotNull($runtimeSecond);
        $this->assertNotNull($boundary);
        $this->assertSame($runtimeSecond['key'], data_get($runtimeFirst, 'next.card_key'));
        $this->assertSame($boundary['key'], data_get($runtimeSecond, 'next.card_key'));
        $this->assertSame($boundary['key'], $runtimeFirst['embedded_workflow_boundary_key']);
        $this->assertSame($boundary['key'], $runtimeSecond['embedded_workflow_boundary_key']);
        $this->assertSame('parent-after-child', data_get($boundary, 'next.card_key'));
    }

    public function test_unresolved_embedded_workflow_routes_are_not_silently_treated_as_success(): void
    {
        $parent = $this->workflow('parent-unresolved-child-route');
        $child = $this->workflow('child-unresolved-route');
        $first = $this->waitTask('child-first');
        $first['next'] = [
            'type' => 'card',
            'action_key' => 'child-unresolved-list',
            'step' => 'child-unresolved-list',
            'card_key' => 'missing-child-task',
            'card' => 'missing-child-task',
        ];
        $this->step($child, 'Child unresolved list', [$first]);

        $parentStep = $this->step($parent, 'Parent list', [
            $this->workflowTask($child, 'child-workflow'),
            $this->waitTask('parent-after-child'),
        ]);

        $tasks = $this->runtimeTasks($parentStep);
        $runtimeFirst = collect($tasks)->first(fn (array $task): bool => str_ends_with((string) $task['key'], 'child-first'));
        $boundary = collect($tasks)->firstWhere('runner', 'workflow-boundary');

        $this->assertNotNull($runtimeFirst);
        $this->assertNotNull($boundary);
        $this->assertSame('missing-child-task', data_get($runtimeFirst, 'next.card_key'));
        $this->assertNotSame($boundary['key'], data_get($runtimeFirst, 'next.card_key'));
        $this->assertSame($boundary['key'], $runtimeFirst['embedded_workflow_boundary_key']);
    }

    public function test_loop_start_creates_and_manages_paired_end_task(): void
    {
        $workflow = $this->workflow('loop-pair-editor');
        $step = $this->step($workflow, 'Loop list', []);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('prepareTaskFromCatalog', $step->id, 'loop.for_each_element', 0)
            ->set('newTaskElementSelector', '.result')
            ->set('newTaskBrowserWindow', 'main')
            ->call('addTaskCard')
            ->assertHasNoErrors();

        $tasks = $step->fresh()->task_cards;
        $this->assertCount(2, $tasks);
        $this->assertSame('loop.for_each_element', $tasks[0]['task_key']);
        $this->assertSame('loop.end', $tasks[1]['task_key']);
        $this->assertSame($tasks[0]['loop_pair_id'], $tasks[1]['loop_pair_id']);
        $this->assertSame($tasks[0]['key'], $tasks[1]['loop_start_key']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openEditTaskCard', $step->id, $tasks[1]['key'])
            ->set('editingTaskTitle', 'Ergebnis Loop')
            ->set('editingTaskElementSelector', '.product-card')
            ->call('saveEditTaskCard')
            ->assertHasNoErrors();

        $editedTasks = $step->fresh()->task_cards;
        $this->assertSame('Ergebnis Loop', $editedTasks[0]['title']);
        $this->assertSame('.product-card', $editedTasks[0]['selector']);
        $this->assertSame('Loop-Ende: Ergebnis Loop', $editedTasks[1]['title']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('removeTaskCard', $step->id, $editedTasks[1]['key']);

        $this->assertSame([], $step->fresh()->task_cards);
    }

    public function test_fill_field_editor_persists_workflow_variable_source_and_fallback(): void
    {
        $workflow = $this->workflow('fill-field-variable-source');
        $step = $this->step($workflow, 'Search input', []);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('prepareTaskFromCatalog', $step->id, 'input.fill_field', 0)
            ->assertSee('Wertquelle')
            ->assertSee('Workflow-Variable')
            ->assertSee('Fallback-Wert')
            ->set('newTaskElementSelector', '#search')
            ->set('newTaskBrowserWindow', 'main')
            ->set('newTaskValueSource', 'workflow_variable')
            ->set('newTaskWorkflowVariable', 'google_search_url')
            ->set('newTaskValueFallback', 'fallback search')
            ->call('addTaskCard')
            ->assertHasNoErrors();

        $task = $step->fresh()->task_cards[0];
        $this->assertSame('workflow_variable', $task['value_source']);
        $this->assertSame('google_search_url', $task['workflow_variable']);
        $this->assertSame('fallback search', $task['value_fallback']);
        $this->assertSame('', $task['value']);
        $this->assertSame('', $task['input']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openEditTaskCard', $step->id, $task['key'])
            ->assertSet('editingTaskValueSource', 'workflow_variable')
            ->assertSet('editingTaskWorkflowVariable', 'google_search_url')
            ->assertSet('editingTaskValueFallback', 'fallback search')
            ->set('editingTaskValueSource', 'fixed')
            ->set('editingTaskInputValue', 'literal search')
            ->call('saveEditTaskCard')
            ->assertHasNoErrors();

        $fixedTask = $step->fresh()->task_cards[0];
        $this->assertSame('fixed', $fixedTask['value_source']);
        $this->assertSame('literal search', $fixedTask['value']);
        $this->assertSame('literal search', $fixedTask['input']);
        $this->assertArrayNotHasKey('workflow_variable', $fixedTask);
        $this->assertArrayNotHasKey('value_fallback', $fixedTask);
    }

    public function test_validate_inputs_editor_persists_the_variable_menu_as_json_definitions(): void
    {
        $workflow = $this->workflow('validate-input-variable-editor');
        $step = $this->step($workflow, 'Workflow inputs', []);
        $definitions = [
            ['name' => 'browser_window', 'type' => 'browser_window', 'required' => false],
            ['name' => 'search_count', 'required' => false, 'default' => 3],
            ['name' => 'google_search_url', 'required' => true],
        ];

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('prepareTaskFromCatalog', $step->id, 'data.validate_inputs', 0)
            ->assertSee('Eingabevariablen')
            ->assertSee('Variablenname')
            ->assertSee('Nur fehlende Variablen mit aktivierter Pflichtangabe')
            ->set('newTaskExtra.input_definitions', json_encode($definitions))
            ->set('newTaskExtra.output_group', 'search_inputs')
            ->call('addTaskCard')
            ->assertHasNoErrors();

        $task = $step->fresh()->task_cards[0];
        $this->assertSame($definitions, json_decode($task['input_definitions'], true));
        $this->assertSame('search_inputs', $task['output_group']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openEditTaskCard', $step->id, $task['key'])
            ->assertSet('editingTaskExtra.input_definitions', json_encode($definitions, JSON_UNESCAPED_SLASHES))
            ->assertSee('Quelle und Task-', false);
    }

    public function test_fill_field_editor_requires_the_selected_value_source_configuration(): void
    {
        $workflow = $this->workflow('fill-field-value-source-validation');
        $step = $this->step($workflow, 'Search input', []);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('prepareTaskFromCatalog', $step->id, 'input.fill_field', 0)
            ->set('newTaskElementSelector', '#search')
            ->set('newTaskBrowserWindow', 'main')
            ->set('newTaskValueSource', 'workflow_variable')
            ->call('addTaskCard')
            ->assertHasErrors(['newTaskWorkflowVariable']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('prepareTaskFromCatalog', $step->id, 'input.fill_field', 0)
            ->set('newTaskElementSelector', '#search')
            ->set('newTaskBrowserWindow', 'main')
            ->set('newTaskValueSource', 'fixed')
            ->call('addTaskCard')
            ->assertHasErrors(['newTaskInputValue']);
    }

    protected function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }

    protected function step(Workflow $workflow, string $name, array $tasks): WorkflowStep
    {
        return $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => str($name)->slug(),
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);
    }

    protected function waitTask(string $key): array
    {
        return [
            'key' => $key,
            'task_key' => 'wait.seconds',
            'title' => 'Wait',
            'kind' => 'wait',
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/wait/seconds.cjs',
            'value' => 0,
        ];
    }

    protected function workflowTask(Workflow $workflow, string $key): array
    {
        return [
            'key' => $key,
            'task_key' => 'workflow.include.'.$workflow->id,
            'title' => $workflow->name,
            'kind' => 'workflow',
            'runner' => 'workflow',
            'workflow_id' => $workflow->id,
            'workflow_slug' => $workflow->slug,
        ];
    }

    protected function runtimeTasks(WorkflowStep $step, ?string $startTaskKey = null): array
    {
        $reflection = new ReflectionClass(WorkflowTaskRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('runtimeTasks');

        return $method->invoke($runner, $step, $startTaskKey);
    }
}
