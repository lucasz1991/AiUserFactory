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

        $this->assertCount(
            count(app(WorkflowTaskCatalog::class)->all()),
            data_get($context, 'workflow_task_catalog'),
        );
        $this->assertArrayHasKey('loop.end', data_get($context, 'workflow_task_catalog'));
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
        $this->assertStringNotContainsString('never-leak-this-fixed-value', $serialized);
        $this->assertStringNotContainsString('private query', $serialized);
    }
}
