<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Tools\Chatbot;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowAssistantToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowAssistantImprovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_run_analysis_exposes_stable_task_targets_and_result_details(): void
    {
        [$workflow, $step, $run] = $this->failedRun();

        $result = app(WorkflowAssistantToolService::class)->execute('analyze_last_workflow_run', [
            'run_id' => $run->id,
            'include_debug_excerpt' => true,
        ], new \stdClass);

        $this->assertTrue($result['ok']);
        $this->assertSame($workflow->id, data_get($result, 'run.workflow_id'));
        $this->assertSame($step->id, data_get($result, 'run.step_runs.0.step_id'));
        $this->assertSame('login', data_get($result, 'run.step_runs.0.step_action_key'));
        $this->assertSame('submit-login', data_get($result, 'run.step_runs.0.task_results.1.task_card_key'));
        $this->assertSame('failed', data_get($result, 'run.step_runs.0.task_results.1.status'));
        $this->assertSame('Selector wurde nicht gefunden.', data_get($result, 'run.step_runs.0.task_results.1.error_message'));
    }

    public function test_improvements_are_validated_deduplicated_and_prioritized(): void
    {
        [$workflow, $step, $run] = $this->failedRun();

        $result = app(WorkflowAssistantToolService::class)->execute('present_workflow_improvements', [
            'workflow_id' => $workflow->id,
            'run_id' => $run->id,
            'improvements' => [
                [
                    'severity' => 'warning',
                    'title' => 'Selector pruefen',
                    'explanation' => 'Der vorhandene Selector ist instabil.',
                    'recommendation' => 'Einen stabileren Datenattribut-Selector verwenden.',
                    'step_id' => $step->id,
                    'step_action_key' => 'login',
                    'task_card_key' => 'submit-login',
                ],
                [
                    'severity' => 'error',
                    'title' => 'Selector reparieren',
                    'explanation' => 'Der Task ist im Testlauf fehlgeschlagen.',
                    'recommendation' => 'Den Selector am aktuellen DOM ausrichten.',
                    'step_id' => $step->id,
                    'task_card_key' => 'submit-login',
                ],
                [
                    'severity' => 'info',
                    'title' => 'Nicht zuordenbarer Hinweis',
                    'explanation' => 'Dieses Ziel existiert nicht im Workflow.',
                    'recommendation' => 'Den Workflow manuell pruefen.',
                    'step_id' => $step->id,
                    'task_card_key' => 'missing-task',
                ],
            ],
        ], new \stdClass);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['improvements']);
        $this->assertSame('highlight_workflow_improvements', data_get($result, 'ui_action.type'));
        $this->assertSame('error', data_get($result, 'improvements.0.severity'));
        $this->assertTrue(data_get($result, 'improvements.0.highlightable'));
        $this->assertSame('workflow_task', data_get($result, 'improvements.0.target_type'));
        $this->assertStringContainsString('stabileren Datenattribut-Selector', data_get($result, 'improvements.0.recommendation'));
        $this->assertStringContainsString('aktuellen DOM', data_get($result, 'improvements.0.recommendation'));
        $this->assertFalse(data_get($result, 'improvements.1.highlightable'));
        $this->assertNull(data_get($result, 'improvements.1.step_id'));
        $this->assertNull(data_get($result, 'improvements.1.task_card_key'));
    }

    public function test_improvement_click_target_opens_the_existing_task_editor(): void
    {
        [$workflow, $step] = $this->failedRun();

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->set('showRunPreviewModal', true)
            ->call('openAssistantImprovement', $workflow->id, $step->id, 'submit-login')
            ->assertSet('showRunPreviewModal', false)
            ->assertSet('showEditTaskModal', true)
            ->assertSet('editingTaskStepId', $step->id)
            ->assertSet('editingTaskKey', 'submit-login')
            ->call('openAssistantImprovement', $workflow->id, $step->id, null)
            ->assertSet('showEditTaskModal', false)
            ->assertSet('showEditStepModal', true)
            ->assertSet('editingStepId', $step->id);
    }

    public function test_chatbot_forces_structured_improvements_after_a_run_analysis(): void
    {
        [$workflow, $step, $run] = $this->failedRun();
        $this->actingAs(User::factory()->create());
        $requests = [];
        $responses = [
            $this->toolResponse('analysis-call', 'analyze_last_workflow_run', [
                'run_id' => $run->id,
                'include_debug_excerpt' => true,
            ]),
            ['choices' => [['message' => ['content' => 'Der Selector ist fehlgeschlagen.', 'tool_calls' => []]]]],
            $this->toolResponse('improvement-call', 'present_workflow_improvements', [
                'workflow_id' => $workflow->id,
                'run_id' => $run->id,
                'improvements' => [[
                    'severity' => 'error',
                    'title' => 'Selector reparieren',
                    'explanation' => 'Der Login-Task ist fehlgeschlagen.',
                    'recommendation' => 'Den Selector am aktuellen DOM ausrichten.',
                    'step_id' => $step->id,
                    'step_action_key' => 'login',
                    'task_card_key' => 'submit-login',
                ]],
            ]),
            ['choices' => [['message' => ['content' => 'Ich habe die betroffene Task-Karte rot markiert.', 'tool_calls' => []]]]],
        ];
        $ai = \Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('requestStreamed')
            ->times(4)
            ->andReturnUsing(function (array $payload) use (&$requests, &$responses): array {
                $requests[] = $payload;

                return array_shift($responses);
            });
        $this->app->instance(AiConnectionService::class, $ai);

        Livewire::test(Chatbot::class)
            ->set('message', 'Analysiere den letzten Testlauf.')
            ->call('sendMessage')
            ->assertSet('chatHistory.1.improvements.0.severity', 'error')
            ->assertSet('chatHistory.1.improvements.0.task_card_key', 'submit-login')
            ->assertDispatched('assistant-ui-action', function (string $name, array $parameters): bool {
                return data_get($parameters, 'action.type') === 'highlight_workflow_improvements';
            });

        $this->assertSame('auto', data_get($requests, '1.tool_choice'));
        $this->assertSame('function', data_get($requests, '2.tool_choice.type'));
        $this->assertSame('present_workflow_improvements', data_get($requests, '2.tool_choice.function.name'));
    }

    private function failedRun(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Login workflow',
            'slug' => 'login-workflow',
            'description' => 'Test',
            'category' => 'automation',
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Login',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'login',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [
                    [
                        'key' => 'open-login',
                        'task_key' => 'browser.open_url',
                        'title' => 'Login oeffnen',
                        'kind' => 'browser',
                        'url' => 'https://example.test/login',
                    ],
                    [
                        'key' => 'submit-login',
                        'task_key' => 'browser.click',
                        'title' => 'Login absenden',
                        'kind' => 'browser',
                        'selector' => '#login',
                    ],
                ],
            ],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'failed',
            'context_json' => [],
            'result_json' => [],
            'error_message' => 'Login fehlgeschlagen.',
        ]);
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id,
            'status' => 'failed',
            'duration_ms' => 920,
            'logs_json' => [],
            'result_json' => [
                'tasks' => [
                    ['key' => 'open-login', 'status' => 'success', 'durationMs' => 120],
                    [
                        'key' => 'submit-login',
                        'status' => 'failed',
                        'errorMessage' => 'Selector wurde nicht gefunden.',
                        'durationMs' => 800,
                    ],
                ],
            ],
            'error_message' => 'Selector wurde nicht gefunden.',
        ]);

        return [$workflow, $step, $run];
    }

    private function toolResponse(string $id, string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ],
            ]],
        ];
    }
}
