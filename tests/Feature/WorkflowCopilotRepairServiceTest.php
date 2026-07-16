<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotRepairService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkflowCopilotRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_browser_click_repairs_keep_selector_and_shared_fields_but_drop_form_disabled_fields(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([
            [
                'key' => 'login-click',
                'task_key' => 'browser.click',
                'title' => 'Login klicken',
                'selector' => '.missing-login',
            ],
            [
                'key' => 'after-login',
                'task_key' => 'browser.find_element',
                'title' => 'Erfolg pruefen',
                'selector' => '.success',
            ],
        ]);

        $updated = app(WorkflowCopilotRepairService::class)->applyChangesToStep(
            $step,
            'login-click',
            [
                'selector' => 'button[type="submit"]',
                'element_selector' => 'button[type="submit"]',
                'value' => 'darf-nicht-gespeichert-werden',
                'input' => 'darf-nicht-gespeichert-werden',
                'url' => 'https://wrong.example',
                'browser_window' => 'secondary',
                'browser_window_name' => 'secondary',
                'session_key' => 'not-a-click-field',
                'timeout_seconds' => 9999,
                'next' => [
                    'type' => 'card',
                    'action_key' => $step->action_key,
                    'step' => $step->action_key,
                    'card_key' => 'after-login',
                    'card' => 'after-login',
                ],
            ],
        );

        $this->assertSame('button[type="submit"]', $updated['selector']);
        $this->assertSame('button[type="submit"]', $updated['element_selector']);
        $this->assertSame(3600, $updated['timeout_seconds']);
        $this->assertSame('after-login', data_get($updated, 'next.card_key'));
        $this->assertArrayNotHasKey('value', $updated);
        $this->assertArrayNotHasKey('input', $updated);
        $this->assertArrayNotHasKey('url', $updated);
        $this->assertArrayNotHasKey('browser_window', $updated);
        $this->assertArrayNotHasKey('browser_window_name', $updated);
        $this->assertArrayNotHasKey('session_key', $updated);
        $this->assertSame($workflow->id, $step->fresh()->workflow_id);
    }

    public function test_catalog_form_allows_only_its_url_browser_window_and_extra_fields(): void
    {
        [, $step] = $this->workflowWithTasks([
            [
                'key' => 'open-session',
                'task_key' => 'browser.open_browser_session',
                'title' => 'Session oeffnen',
            ],
            [
                'key' => 'fill-email',
                'task_key' => 'input.fill_field',
                'title' => 'E-Mail fuellen',
            ],
        ]);

        $updated = app(WorkflowCopilotRepairService::class)->applyChangesToStep(
            $step,
            'open-session',
            [
                'url' => 'https://example.test/dashboard',
                'browser_window' => 'login',
                'browser_window_name' => 'login',
                'session_key' => 'session-42',
                'target_domain' => 'example.test',
                'selector' => '#forbidden',
                'value' => 'forbidden',
                'input' => 'forbidden',
            ],
        );

        $this->assertSame('https://example.test/dashboard', $updated['url']);
        $this->assertSame('login', $updated['browser_window']);
        $this->assertSame('login', $updated['browser_window_name']);
        $this->assertSame('session-42', $updated['session_key']);
        $this->assertSame('example.test', $updated['target_domain']);
        $this->assertArrayNotHasKey('selector', $updated);
        $this->assertArrayNotHasKey('value', $updated);
        $this->assertArrayNotHasKey('input', $updated);

        try {
            app(WorkflowCopilotRepairService::class)->applyChangesToStep(
                $step->fresh(),
                'open-session',
                ['url' => 'javascript:alert(document.cookie)'],
            );
            $this->fail('Eine aktive JavaScript-URL wurde als Copilot-Reparatur gespeichert.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('keine erlaubten Taskparameter', $exception->getMessage());
        }

        $filled = app(WorkflowCopilotRepairService::class)->applyChangesToStep(
            $step->fresh(),
            'fill-email',
            [
                'selector' => 'input[type="email"]',
                'value' => 'person.email',
                'input' => 'person.email',
                'url' => 'https://forbidden.example',
                'browser_window' => 'forbidden',
            ],
        );

        $this->assertSame('input[type="email"]', $filled['selector']);
        $this->assertSame('person.email', $filled['value']);
        $this->assertSame('person.email', $filled['input']);
        $this->assertArrayNotHasKey('url', $filled);
        $this->assertArrayNotHasKey('browser_window', $filled);
    }

    public function test_route_mutations_require_legal_types_and_existing_step_and_card_targets(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'login-click',
            'task_key' => 'browser.click',
            'title' => 'Login klicken',
            'selector' => '.login',
        ]]);
        $targetStep = $workflow->steps()->create([
            'name' => 'Ergebnis',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'result',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [[
                    'key' => 'result-card',
                    'task_key' => 'browser.find_element',
                    'title' => 'Ergebnis lesen',
                    'selector' => '.result',
                ]],
            ],
        ]);
        $service = app(WorkflowCopilotRepairService::class);

        $updated = $service->applyChangesToStep($step, 'login-click', [
            'status_routes' => [
                'failed' => [
                    'type' => 'card',
                    'action_key' => $targetStep->action_key,
                    'step' => $targetStep->action_key,
                    'card_key' => 'result-card',
                    'card' => 'result-card',
                ],
                'timeout' => ['type' => 'fail', 'step' => 'fail'],
            ],
        ]);

        $this->assertSame('result-card', data_get($updated, 'status_routes.failed.card_key'));
        $this->assertSame('fail', data_get($updated, 'status_routes.timeout.type'));

        foreach ([
            ['next' => ['type' => 'javascript', 'step' => 'result']],
            ['next' => ['type' => 'step', 'step' => 'missing-step']],
            ['next' => ['type' => 'card', 'step' => 'result', 'card' => 'missing-card']],
            ['next' => ['type' => 'card', 'action_key' => 'result', 'step' => 'other-step', 'card' => 'result-card']],
        ] as $invalidChanges) {
            try {
                $service->applyChangesToStep($step->fresh(), 'login-click', $invalidChanges);
                $this->fail('Eine ungueltige Copilot-Route wurde gespeichert.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('ungueltige oder nicht aufloesbare Workflow-Route', $exception->getMessage());
            }
        }
    }

    public function test_vision_suggestions_match_catalog_key_and_optional_card_key_separately(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'login-click',
            'task_key' => 'browser.click',
            'title' => 'Login klicken',
            'selector' => '.before',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Login abschliessen.',
        ]);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            ['task_key' => 'login-click'],
            ['interaction_map' => [[
                'element_ref' => 'el_login',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button[type="submit"]'],
            ]]],
            [
                'confidence' => 0.92,
                'relevant_elements' => [[
                    'element_ref' => 'el_login',
                    'confidence' => 0.94,
                ]],
                'suggested_task_actions' => [
                    [
                        'task_key' => 'browser.click',
                        'card_key' => 'other-click',
                        'element_ref' => 'el_login',
                        'confidence' => 0.9,
                        'parameters' => ['selector' => '.wrong-card'],
                    ],
                    [
                        'task_key' => 'input.fill_field',
                        'card_key' => 'login-click',
                        'element_ref' => 'el_login',
                        'confidence' => 0.9,
                        'parameters' => ['selector' => '.wrong-catalog'],
                    ],
                    [
                        'task_key' => 'browser.click',
                        'card_key' => 'login-click',
                        'element_ref' => 'el_login',
                        'confidence' => 0.9,
                        'parameters' => ['selector' => 'button[type="submit"]'],
                    ],
                ],
            ],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame('login-click', $plan['task_key']);
        $this->assertSame('browser.click', $plan['task_catalog_key']);
        $this->assertSame('button[type="submit"]', data_get($plan, 'changes.selector'));
    }

    public function test_user_instruction_can_prioritize_the_second_visible_selector_candidate(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'login-click',
            'task_key' => 'browser.click',
            'title' => 'Login klicken',
            'selector' => '.before',
        ]]);
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Login abschliessen.']);
        $session = $sessions->updateState($session, [
            'active_instructions' => ['Versuche den zweiten sichtbaren Login-Button.'],
        ]);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            ['task_key' => 'login-click'],
            [
                'interaction_map' => [[
                    'element_ref' => 'el_first',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['button.login-primary'],
                ], [
                    'element_ref' => 'el_second',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['button.login-secondary'],
                ]],
            ],
            [
                'confidence' => 0.9,
                'relevant_elements' => [
                    ['element_ref' => 'el_first', 'confidence' => 0.9],
                    ['element_ref' => 'el_second', 'confidence' => 0.9],
                ],
            ],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame('button.login-secondary', data_get($plan, 'changes.selector'));
    }

    public function test_verification_status_blocks_planning_and_persisting_repairs(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'login-click',
            'task_key' => 'browser.click',
            'title' => 'Login klicken',
            'selector' => '.before',
        ]]);
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Login abschliessen.']);
        $session = $sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_VERIFYING,
            'verifying',
        );
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step,
            ['task_key' => 'login-click'],
            ['interaction_map' => []],
            [],
        );

        $this->assertSame('pause', $plan['action']);
        $this->assertStringContainsString('Kontrolllaufs', $plan['reason']);

        try {
            $service->applyChangesToStep($step, 'login-click', ['selector' => '.after']);
            $this->fail('Eine Reparatur wurde waehrend des Kontrolllaufs gespeichert.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Kontrolllaufs', $exception->getMessage());
        }

        $this->assertSame('.before', data_get($step->fresh()->config_json, 'tasks.0.selector'));
    }

    public function test_only_the_owning_session_can_mutate_a_copilot_locked_workflow(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'login-click',
            'task_key' => 'browser.click',
            'title' => 'Login klicken',
            'selector' => '.before',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $service = app(WorkflowCopilotRepairService::class);

        try {
            $service->applyChangesToStep($step, 'login-click', ['selector' => '.bypass']);
            $this->fail('A caller without the owning Copilot session bypassed the workflow lock.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('aktive Copilot-Sitzung', $exception->getMessage());
        }

        $updated = $service->applyChangesToStep(
            $step->fresh(),
            'login-click',
            ['selector' => '.owned-repair'],
            $session,
        );

        $this->assertSame('.owned-repair', $updated['selector']);
        $this->assertSame('.owned-repair', data_get($step->fresh()->config_json, 'tasks.0.selector'));
    }

    public function test_mutating_probe_pauses_without_a_confident_explicit_vision_element_reference(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'danger-click',
            'task_key' => 'browser.click',
            'title' => 'Danger klicken',
            'selector' => '.missing',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->twice()->andReturn([
            'action' => 'update_task',
            'element_ref' => 'el_danger',
            'changes' => ['selector' => '#danger'],
            'reason' => 'DOM sieht passend aus.',
        ]);
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);
        $observation = ['interaction_map' => [[
            'element_ref' => 'el_danger',
            'visible' => true,
            'enabled' => true,
            'selector_candidates' => ['#danger'],
        ]]];

        $withoutVision = $service->plan(
            $session,
            $step,
            ['task_key' => 'danger-click'],
            $observation,
            [],
        );
        $lowConfidence = $service->plan(
            $session,
            $step,
            ['task_key' => 'danger-click'],
            $observation,
            [
                'confidence' => 0.2,
                'relevant_elements' => [['element_ref' => 'el_danger', 'confidence' => 0.2]],
            ],
        );

        foreach ([$withoutVision, $lowConfidence] as $plan) {
            $this->assertSame('pause', $plan['action']);
            $this->assertArrayNotHasKey('probe_task', $plan);
            $this->assertStringContainsString('Vision-Elementreferenz', $plan['reason']);
        }
    }

    public function test_unique_dom_only_candidate_can_still_repair_a_non_mutating_find_task(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'find-login',
            'task_key' => 'browser.find_element',
            'title' => 'Login finden',
            'selector' => '.missing',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            ['task_key' => 'find-login'],
            ['interaction_map' => [[
                'element_ref' => 'el_login',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button.login'],
            ]]],
            [],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame('button.login', data_get($plan, 'changes.selector'));
    }

    public function test_planner_payload_and_returned_reason_are_recursively_sanitized(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'find-login',
            'task_key' => 'browser.find_element',
            'title' => 'Kontakt secret.person@example.test',
            'selector' => '.missing',
        ]]);
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Login fuer secret.person@example.test mit token=goal-secret',
            'success_criteria' => ['cookie' => 'session-secret'],
        ]);
        $session = $sessions->updateState($session, [
            'active_instructions' => ['Rufe +49 171 12345678 an; ws://127.0.0.1/private'],
        ]);
        $plannerPrompt = null;
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->withArgs(function (string $prompt) use (&$plannerPrompt): bool {
            $plannerPrompt = $prompt;

            return true;
        })->andReturn([
            'action' => 'pause',
            'reason' => 'Kontakt secret.person@example.test, token=answer-secret, +49 171 12345678, ws://127.0.0.1/private',
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            [
                'task_key' => 'find-login',
                'result' => ['password' => 'checkpoint-secret'],
            ],
            [
                'interaction_map' => [],
                'nested' => ['authorization' => 'Bearer planner-secret'],
            ],
            [
                'blockers' => ['eyJhbGciOiJIUzI1NiJ9.secret.signature'],
            ],
        );
        $serialized = json_encode($plan, JSON_UNESCAPED_SLASHES);

        $this->assertIsString($plannerPrompt);
        $this->assertStringNotContainsString('secret.person@example.test', $plannerPrompt);
        $this->assertStringNotContainsString('goal-secret', $plannerPrompt);
        $this->assertStringNotContainsString('session-secret', $plannerPrompt);
        $this->assertStringNotContainsString('171 12345678', $plannerPrompt);
        $this->assertStringNotContainsString('ws://', $plannerPrompt);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9.secret.signature', $plannerPrompt);
        $this->assertSame('pause', $plan['action']);
        $this->assertStringNotContainsString('secret.person@example.test', $serialized);
        $this->assertStringNotContainsString('answer-secret', $serialized);
        $this->assertStringNotContainsString('171 12345678', $serialized);
        $this->assertStringNotContainsString('ws://', $serialized);
    }

    public function test_planner_exception_is_replaced_with_a_stable_non_sensitive_pause_reason(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'find-login',
            'task_key' => 'browser.find_element',
            'title' => 'Login finden',
            'selector' => '.missing',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andThrow(new RuntimeException(
            'Provider failed for secret.person@example.test with token=exception-secret',
        ));
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            ['task_key' => 'find-login'],
            ['interaction_map' => []],
            [],
        );

        $this->assertSame('pause', $plan['action']);
        $this->assertStringNotContainsString('secret.person@example.test', $plan['reason']);
        $this->assertStringNotContainsString('exception-secret', $plan['reason']);
        $this->assertStringContainsString('nicht verfuegbar', $plan['reason']);
    }

    public function test_blank_page_repairs_the_route_to_existing_navigation_and_then_continues_it(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'wait-results',
            'task_key' => 'wait.selector',
            'title' => 'Auf Ergebnisse warten',
            'selector' => '#search',
        ]]);
        $navigationStep = $workflow->steps()->create([
            'name' => 'Startseite',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'open-homepage',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'open-google',
                'task_key' => 'browser.open_url',
                'title' => 'Google oeffnen',
                'url' => 'https://www.google.com',
            ]]],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Suche erfolgreich abschliessen.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->twice()->andReturn([
            'action' => 'pause',
            'reason' => 'Auf der leeren Seite ist kein Element sichtbar.',
        ]);
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);
        $checkpoint = [
            'task_key' => 'wait-results',
            'outcome' => 'timeout',
            'result' => ['statusMessage' => 'Timeout'],
        ];
        $observation = [
            'page' => ['url' => 'about:blank', 'state' => 'unknown_browser_state'],
            'interaction_map' => [],
        ];
        $vision = [
            'confidence' => 0,
            'verdict' => 'pause',
            'safe_pause' => true,
            'suggested_task_actions' => [],
        ];

        $plan = $service->plan($session, $step, $checkpoint, $observation, $vision);

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertSame('update_step_routes', data_get($plan, 'operations.0.type'));
        $this->assertSame('open-homepage', data_get($plan, 'operations.0.routes.failed.step'));
        $this->assertSame('open-homepage', data_get($plan, 'operations.0.routes.timeout.step'));

        $service->applyStructuralOperations($workflow->fresh(), $plan['operations'], $session->fresh(), $observation);
        $this->assertSame('open-homepage', data_get($step->fresh()->config_json, 'routes.timeout.step'));

        $continued = $service->plan($session->fresh(), $step->fresh(), $checkpoint, $observation, $vision);

        $this->assertSame('continue_route', $continued['action']);
        $this->assertStringContainsString('Navigationsliste', $continued['reason']);
        $this->assertSame($navigationStep->id, $workflow->steps()->where('action_key', 'open-homepage')->value('id'));
    }

    public function test_structural_planner_can_insert_only_a_catalog_bound_non_visual_task(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'find-results',
            'task_key' => 'browser.find_element',
            'title' => 'Ergebnisse finden',
            'selector' => '#search',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Suche erfolgreich abschliessen.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')
            ->once()
            ->withArgs(fn (string $prompt, ?string $system, array $options): bool => str_contains($prompt, 'workflow_structure')
                && str_contains($prompt, 'workflow_task_catalog')
                && str_contains((string) $system, 'Datenanalyse- und Planungsmodell')
                && str_contains((string) $system, 'Bildverstehen-Modell')
                && data_get($options, 'max_completion_tokens') === 2200)
            ->andReturn([
                'action' => 'structural_update',
                'reason' => 'Vor der Ergebnispruefung fehlt die Navigation.',
                'operations' => [[
                    'type' => 'insert_task',
                    'step_action_key' => 'start',
                    'task_catalog_key' => 'browser.open_url',
                    'title' => 'Google oeffnen',
                    'description' => 'Oeffnet die bereits vertrauenswuerdige Zielseite.',
                    'parameters' => [
                        'url' => 'https://www.google.com',
                        'browser_window' => 'main',
                    ],
                    'insert_position' => 0,
                ], [
                    'type' => 'insert_task',
                    'step_action_key' => 'start',
                    'task_catalog_key' => 'browser.click',
                    'title' => 'Unsicher klicken',
                    'parameters' => ['selector' => '#danger'],
                    'insert_position' => 1,
                ]],
            ]);
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step,
            ['task_key' => 'find-results', 'outcome' => 'failed'],
            [
                'page' => ['url' => 'https://www.google.com', 'state' => 'content'],
                'interaction_map' => [],
            ],
            [
                'confidence' => 0,
                'verdict' => 'pause',
                'safe_pause' => true,
                'model' => 'vision/model-test',
                'analysis_source' => 'vision',
            ],
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertCount(1, $plan['operations']);
        $this->assertSame('browser.open_url', data_get($plan, 'operations.0.task_catalog_key'));
        $this->assertSame('https://www.google.com', data_get($plan, 'operations.0.parameters.url'));
        $this->assertArrayNotHasKey('browser_window', data_get($plan, 'operations.0.parameters'));
        $this->assertSame('image_understanding', data_get($plan, 'planning_handoff.vision_profile'));
        $this->assertSame('vision/model-test', data_get($plan, 'planning_handoff.vision_model'));
        $this->assertSame('data_analysis', data_get($plan, 'planning_handoff.planner_profile'));

        $service->applyStructuralOperations($workflow->fresh(), $plan['operations'], $session->fresh(), [
            'page' => ['url' => 'https://www.google.com'],
        ]);

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('browser.open_url', data_get($tasks, '0.task_key'));
        $this->assertSame('https://www.google.com', data_get($tasks, '0.url'));
        $this->assertSame('browser.find_element', data_get($tasks, '1.task_key'));
        $this->assertNotContains('browser.click', collect($tasks)->pluck('task_key')->all());
    }

    public function test_url_repairs_block_private_metadata_and_cross_workflow_hosts(): void
    {
        [, $step] = $this->workflowWithTasks([[
            'key' => 'open-session',
            'task_key' => 'browser.open_browser_session',
            'title' => 'Session oeffnen',
            'url' => 'https://example.test/start',
            'target_domain' => 'example.test',
        ]]);
        $service = app(WorkflowCopilotRepairService::class);

        foreach ([
            'http://127.0.0.1/admin',
            'http://10.0.0.1/private',
            'http://169.254.169.254/latest/meta-data',
            'http://[::1]/private',
            'http://metadata.google.internal/latest',
            'https://attacker.example.net/phishing',
        ] as $unsafeUrl) {
            try {
                $service->applyChangesToStep($step->fresh(), 'open-session', ['url' => $unsafeUrl]);
                $this->fail('Unsicheres Copilot-URL-Ziel wurde gespeichert: '.$unsafeUrl);
            } catch (DomainException $exception) {
                $this->assertStringContainsString('keine erlaubten Taskparameter', $exception->getMessage());
            }
        }

        $updated = $service->applyChangesToStep(
            $step->fresh(),
            'open-session',
            ['url' => 'https://accounts.example.test/login'],
        );

        $this->assertSame('https://accounts.example.test/login', $updated['url']);
    }

    private function workflowWithTasks(array $tasks): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Repair Test '.str()->random(6),
            'slug' => 'repair-test-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Start',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'start',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);

        return [$workflow, $step];
    }
}
