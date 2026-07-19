<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowCopilotPromptContextService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowCopilotPromptContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_press_key_catalog_context_exposes_only_enter_and_tab(): void
    {
        $snapshot = app(WorkflowCopilotPromptContextService::class)->taskCatalogSnapshot(['browser.press_key']);
        $definition = $snapshot['browser.press_key'];
        $valueParameter = collect($definition['parameters'])->firstWhere('name', 'value');

        $this->assertSame('select', data_get($definition, 'configuration.value_type'));
        $this->assertSame(['Enter', 'Tab'], data_get($definition, 'configuration.value_options'));
        $this->assertSame('select', $valueParameter['type']);
        $this->assertSame([
            'Enter' => 'Enter - bestaetigen oder absenden',
            'Tab' => 'Tab - zum naechsten Feld wechseln',
        ], $valueParameter['options']);
    }

    public function test_context_contains_complete_catalog_routes_and_redacted_task_values(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Prompt Context Test',
            'slug' => 'prompt-context-test',
            'description' => 'Testet den vollstaendigen Copilot-Kontext.',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Suche',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'suche',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'routes' => [
                    'failed' => ['type' => 'fail', 'step' => 'fail', 'label' => 'Terminal'],
                ],
                'tasks' => [[
                    'key' => 'suchfeld-fuellen',
                    'task_key' => 'input.fill_field',
                    'title' => 'Suchfeld fuellen',
                    'selector' => 'textarea[title="Suche"]',
                    'value_source' => 'fixed',
                    'value' => 'never-leak-this-fixed-value',
                    'on_error' => [
                        'type' => 'card',
                        'step' => 'suche',
                        'card' => 'suchfeld-fuellen',
                    ],
                ]],
            ],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Eine Suche ausfuehren.',
            'success_criteria' => ['assertions' => ['Rückgabewert = array']],
            'workflow_inputs' => ['query' => 'private query'],
        ]);

        $context = app(WorkflowCopilotPromptContextService::class)->forWorkflow(
            $workflow->fresh(),
            $session,
            $step->fresh(),
            ['task_key' => 'suchfeld-fuellen', 'outcome' => 'failed', 'successful' => false],
        );
        $serialized = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertSame(2, data_get($context, 'context_version'));
        $this->assertLessThan(
            count(app(WorkflowTaskCatalog::class)->all()),
            count(data_get($context, 'workflow_task_catalog')),
        );
        $this->assertSame(
            count(app(WorkflowTaskCatalog::class)->all()),
            collect(data_get($context, 'workflow_task_catalog_index'))->sum('count'),
        );
        $this->assertArrayHasKey('input.fill_field', data_get($context, 'workflow_task_catalog'));
        $this->assertStringContainsString('leerer Workflow', data_get($context, 'workflow_authoring_capabilities.empty_workflow'));
        $taskCatalog = data_get($context, 'workflow_task_catalog')['input.fill_field'];
        $this->assertNotEmpty($taskCatalog['parameters']);
        $this->assertArrayHasKey('configuration', $taskCatalog);
        $this->assertArrayHasKey('defaults', $taskCatalog);
        $this->assertNotEmpty($taskCatalog['documentation']['purpose']);
        $this->assertArrayHasKey('failure_modes', $taskCatalog['documentation']);
        $this->assertStringContainsString('Workflow-Step', data_get($context, 'workflow_structure.lists'));
        $this->assertCount(5, data_get($context, 'workflow_structure.loop_recipe'));
        $this->assertSame(
            'Beendet den gesamten Workflow sofort als fehlgeschlagen. fail niemals fuer einen behebbaren oder optionalen Fehler verwenden.',
            data_get($context, 'execution_contract.route_types.fail'),
        );
        $this->assertSame(
            'textarea[title="Suche"]',
            data_get($context, 'workflow.steps.0.tasks.0.parameters.selector'),
        );
        $this->assertTrue(
            data_get($context, 'workflow.steps.0.tasks.0.parameters.value_configuration.configured'),
        );
        $this->assertSame(
            'suchfeld-fuellen',
            data_get($context, 'workflow.steps.0.tasks.0.on_error.card'),
        );
        $this->assertContains(
            'terminal_step_route',
            collect(data_get($context, 'workflow_diagnostics'))->pluck('code')->all(),
        );
        $this->assertContains(
            'self_route',
            collect(data_get($context, 'workflow_diagnostics'))->pluck('code')->all(),
        );
        $this->assertSame(
            [['name' => 'query', 'type' => 'string', 'provided' => true]],
            data_get($context, 'copilot_session.workflow_inputs'),
        );
        $inputProvenance = collect(data_get($context, 'variable_provenance'))->firstWhere('name', 'query');
        $this->assertSame('workflow_input', $inputProvenance['origin']);
        $this->assertTrue($inputProvenance['set']);
        $this->assertArrayNotHasKey('value', $inputProvenance);
        $this->assertSame('suchfeld-fuellen', data_get($context, 'runtime_state.checkpoint.task_key'));
        $this->assertStringNotContainsString('never-leak-this-fixed-value', $serialized);
        $this->assertStringNotContainsString('private query', $serialized);
    }

    public function test_context_diagnoses_conflicting_loop_collection_and_missing_targets(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Loop Diagnostics',
            'slug' => 'loop-diagnostics',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $workflow->steps()->create([
            'name' => 'Ergebnisse',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'ergebnisse',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [
                    [
                        'key' => 'result-loop',
                        'task_key' => 'loop.for_each_element',
                        'loop_pair_id' => 'pair-1',
                        'loop_end_key' => 'result-loop-end',
                        'collect_to_array' => 'results',
                        'collect_from_variable' => 'current_result',
                        'completion_target' => 'missing-target',
                    ],
                    [
                        'key' => 'read-result',
                        'task_key' => 'browser.read_element_fields',
                        'output_variable' => 'current_result',
                    ],
                    [
                        'key' => 'append-result',
                        'task_key' => 'data.append_to_array',
                        'array_name' => 'results',
                        'value_from_variable' => 'current_result',
                    ],
                    [
                        'key' => 'result-loop-end',
                        'task_key' => 'loop.end',
                        'loop_pair_id' => 'pair-1',
                        'loop_start_key' => 'result-loop',
                    ],
                ],
            ],
        ]);

        $context = app(WorkflowCopilotPromptContextService::class)->forWorkflow($workflow->fresh());
        $codes = collect($context['workflow_diagnostics'])->pluck('code');

        $this->assertContains('loop_collection_duplicate_append', $codes);
        $this->assertContains('loop_route_target_missing', $codes);
        $this->assertNotContains('loop_collection_producer_missing', $codes);
    }
}
