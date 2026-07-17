<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkflowCopilotPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_plan_persists_validated_task_and_step_routes(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Autonome Google-Suche',
            'slug' => 'autonome-google-suche',
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')
            ->once()
            ->withArgs(fn (string $prompt, string $system): bool => str_contains($prompt, 'execution_contract')
                && str_contains($prompt, 'workflow_task_catalog')
                && str_contains($prompt, 'type=fail')
                && str_contains($system, 'unveraenderlichen Kontrolllauf'))
            ->andReturn([
                'summary' => 'Optionales Cookie-Banner behandeln und danach suchen.',
                'assumptions' => [],
                'steps' => [[
                    'name' => 'Cookie pruefen',
                    'action_key' => 'cookie-pruefen',
                    'type' => 'decision',
                    'description' => 'Optionales Banner verzweigen.',
                    'routes' => [
                        'success' => ['type' => 'step', 'step' => 'suche'],
                        'failed' => ['type' => 'step', 'step' => 'suche'],
                    ],
                    'tasks' => [[
                        'key' => 'if-cookie',
                        'task_key' => 'decision.element_exists',
                        'title' => 'IF Cookie',
                        'parameters' => ['selector' => 'button:has-text("Alle ablehnen")'],
                        'next' => ['type' => 'card', 'step' => 'cookie-pruefen', 'card' => 'cookie-ablehnen'],
                        'on_error' => ['type' => 'step', 'step' => 'suche'],
                    ], [
                        'key' => 'cookie-ablehnen',
                        'task_key' => 'browser.click',
                        'title' => 'Cookie ablehnen',
                        'parameters' => ['selector' => 'button:has-text("Alle ablehnen")'],
                        'next' => ['type' => 'step', 'step' => 'suche'],
                        'on_error' => ['type' => 'step', 'step' => 'suche'],
                    ]],
                ], [
                    'name' => 'Suche',
                    'action_key' => 'suche',
                    'type' => 'browser_task',
                    'description' => 'Suchfeld abwarten.',
                    'routes' => [
                        'success' => ['type' => 'end', 'step' => 'end'],
                    ],
                    'tasks' => [[
                        'key' => 'suchfeld-warten',
                        'task_key' => 'wait.selector',
                        'title' => 'Suchfeld abwarten',
                        'parameters' => ['selector' => 'textarea[title="Suche"]'],
                    ]],
                ]],
            ]);
        $this->app->instance(AiConnectionService::class, $ai);

        $result = app(WorkflowCopilotPlanningService::class)->planAndApply(
            $workflow,
            'Eine Google-Suche ohne Cookie-Schleife ausfuehren.',
            ['assertions' => ['Rückgabewert = array']],
            ['query' => 'OpenAI'],
        );

        $this->assertSame(3, $result['task_count']);
        $steps = $workflow->fresh()->steps()->ordered()->get();
        $this->assertSame(['cookie-pruefen', 'suche'], $steps->pluck('action_key')->all());
        $cookieTasks = $steps->first()->task_cards;
        $this->assertSame('cookie-ablehnen', data_get($cookieTasks, '0.next.card'));
        $this->assertSame('suche', data_get($cookieTasks, '0.on_error.step'));
        $this->assertSame('suche', data_get($cookieTasks, '1.next.step'));
        $this->assertSame('suche', data_get($cookieTasks, '1.on_error.step'));
        $this->assertSame('suche', data_get($steps->first()->config_json, 'routes.failed.step'));
        $this->assertSame('end', data_get($steps->last()->config_json, 'routes.success.type'));
    }
}
