<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowCopilotEvent;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotRepairService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowSelectorProbeService;
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

    public function test_vision_value_reference_updates_existing_fill_task_to_explicit_workflow_variable(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'fill-search',
            'task_key' => 'input.fill_field',
            'title' => 'Suche fuellen',
            'selector' => '#search',
            'value' => 'google_search_url',
            'input' => 'google_search_url',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Suche aus Workflow-Variable ausfuehren.',
        ]);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            ['task_key' => 'fill-search', 'outcome' => 'failed'],
            ['interaction_map' => [[
                'element_ref' => 'el_search',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['#search'],
            ]]],
            [
                'confidence' => 0.92,
                'verdict' => 'continue',
                'safe_pause' => false,
                'relevant_elements' => [[
                    'element_ref' => 'el_search',
                    'confidence' => 0.94,
                ]],
                'suggested_task_actions' => [[
                    'task_key' => 'input.fill_field',
                    'card_key' => 'fill-search',
                    'element_ref' => 'el_search',
                    'confidence' => 0.94,
                    'parameters' => [
                        'value_reference' => 'google_search_url',
                        'fallback_value' => 'fallback search',
                    ],
                ]],
            ],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame('workflow_variable', data_get($plan, 'changes.value_source'));
        $this->assertSame('google_search_url', data_get($plan, 'changes.workflow_variable'));
        $this->assertSame('fallback search', data_get($plan, 'changes.value_fallback'));
        $this->assertArrayNotHasKey('value_reference', $plan['changes']);
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

    public function test_valid_configured_failure_route_is_preferred_over_an_unrelated_visible_selector(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'wait-results',
            'task_key' => 'wait.selector',
            'title' => 'Auf Suchergebnisbereich warten',
            'description' => 'Wartet auf sichtbare Suchergebnisse.',
            'selector' => 'div#search article.result',
            'on_error' => [
                'type' => 'step',
                'action_key' => 'fill-search',
                'step' => 'fill-search',
                'label' => 'Sucheingabe',
            ],
        ]]);
        $workflow->steps()->create([
            'name' => 'Sucheingabe',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'fill-search',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'fill-query',
                'task_key' => 'input.fill_field',
                'title' => 'Suchbegriff eingeben',
                'selector' => 'textarea[name="q"]',
            ]]],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow->fresh());

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'wait-results',
                'successful' => false,
                'outcome' => 'timeout',
                'result' => [
                    'statusMessage' => 'Ergebnisbereich wurde nicht gefunden.',
                    'sideEffects' => [],
                ],
            ],
            [
                'interaction_map' => [[
                    'element_ref' => 'el_search_button',
                    'visible' => true,
                    'enabled' => true,
                    'text' => 'Google Suche',
                    'selector_candidates' => ['input[aria-label="Google Suche"]'],
                ]],
            ],
            [
                'confidence' => 0.9,
                'verdict' => 'continue',
                'safe_pause' => false,
                'relevant_elements' => [[
                    'element_ref' => 'el_search_button',
                    'confidence' => 0.9,
                ]],
            ],
        );

        $this->assertSame('continue_route', $plan['action']);
        $this->assertFalse($plan['resume_checkpoint']);
        $this->assertSame('fill-search', data_get($plan, 'configured_route.action_key'));
        $this->assertStringContainsString('Fehlerroute', $plan['reason']);
        $this->assertArrayNotHasKey('changes', $plan);
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
                'dom' => ['ui_state' => 'login', 'visible_text_excerpt' => 'Login'],
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
        $ai->shouldNotReceive('json');
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
        $this->assertTrue(collect($plan['operations'])->contains(
            fn (array $operation): bool => ($operation['type'] ?? null) === 'update_task_routes'
                && data_get($operation, 'changes.on_error.step') === 'open-homepage'
                && data_get($operation, 'changes.on_error.card_key') === 'open-google',
        ));
        $this->assertTrue(collect($plan['operations'])->contains(
            fn (array $operation): bool => ($operation['type'] ?? null) === 'update_step_routes'
                && data_get($operation, 'routes.failed.step') === 'open-homepage'
                && data_get($operation, 'routes.timeout.card_key') === 'open-google',
        ));

        $service->applyStructuralOperations($workflow->fresh(), $plan['operations'], $session->fresh(), $observation);
        $this->assertSame('open-homepage', data_get($step->fresh()->config_json, 'routes.timeout.step'));
        $this->assertSame('open-google', data_get($step->fresh()->config_json, 'routes.timeout.card_key'));

        $continued = $service->plan($session->fresh(), $step->fresh(), $checkpoint, $observation, $vision);

        $this->assertSame('continue_route', $continued['action']);
        $this->assertStringContainsString('Navigationskarte', $continued['reason']);
        $this->assertSame($navigationStep->id, $workflow->steps()->where('action_key', 'open-homepage')->value('id'));
    }

    public function test_blank_page_ignores_route_churn_and_makes_existing_url_navigation_reachable_from_entry(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Session 3 Recovery',
            'slug' => 'session-3-recovery-'.str()->random(8),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $entry = $workflow->steps()->create([
            'name' => 'Vorbereitung',
            'type' => WorkflowStep::TYPE_PREPARATION,
            'action_key' => 'prepare',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'routes' => ['success' => ['type' => 'step', 'step' => 'cookie-check', 'action_key' => 'cookie-check']],
                'tasks' => [[
                    'key' => 'validate-inputs',
                    'task_key' => 'data.validate_inputs',
                    'title' => 'Eingaben pruefen',
                ]],
            ],
        ]);
        $navigation = $workflow->steps()->create([
            'name' => 'Google oeffnen',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'open-google',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'navigate-google',
                'task_key' => 'browser.open_url',
                'title' => 'Google URL oeffnen',
                'url' => 'https://www.google.com',
            ]]],
        ]);
        $failedStep = $workflow->steps()->create([
            'name' => 'Ergebnisse pruefen',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'check-results',
            'position' => 30,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'wait-results',
                'task_key' => 'wait.selector',
                'title' => 'Auf Ergebnisse warten',
                'selector' => '#search',
                'on_error' => ['type' => 'step', 'step' => 'cookie-check', 'action_key' => 'cookie-check'],
            ]]],
        ]);
        $workflow->steps()->create([
            'name' => 'Cookies pruefen',
            'type' => WorkflowStep::TYPE_DECISION,
            'action_key' => 'cookie-check',
            'position' => 40,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $failedStep,
            ['task_key' => 'wait-results', 'outcome' => 'timeout'],
            ['page' => ['url' => 'about:blank'], 'interaction_map' => []],
            ['verdict' => 'pause', 'confidence' => 0],
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertTrue(collect($plan['operations'])->contains(
            fn (array $operation): bool => ($operation['step_action_key'] ?? null) === 'prepare'
                && data_get($operation, 'routes.success.card_key') === 'navigate-google',
        ));
        $this->assertTrue(collect($plan['operations'])->contains(
            fn (array $operation): bool => ($operation['task_key'] ?? null) === 'wait-results'
                && data_get($operation, 'changes.on_error.card_key') === 'navigate-google',
        ));

        app(WorkflowCopilotRepairService::class)->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
            ['page' => ['url' => 'about:blank']],
        );

        $this->assertSame('navigate-google', data_get($entry->fresh()->config_json, 'routes.success.card_key'));
        $this->assertSame('navigate-google', data_get($failedStep->fresh()->task_cards, '0.on_error.card_key'));
        $this->assertSame($navigation->id, $workflow->steps()->where('action_key', 'open-google')->value('id'));
    }

    public function test_consent_obstacle_creates_one_reject_step_and_preserves_the_original_route(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'check-consent',
            'task_key' => 'decision.element_exists',
            'title' => 'Consent pruefen',
            'selector' => 'button:has-text("Alle ablehnen")',
        ]]);
        $targetStep = $workflow->steps()->create([
            'name' => 'Sucheingabe',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'search-input',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'fill-query',
                'task_key' => 'input.fill_field',
                'title' => 'Suchbegriff fuellen',
                'selector' => 'textarea[name="q"]',
            ]]],
        ]);
        $stepConfig = $step->config_json;
        $stepConfig['routes']['success'] = [
            'type' => 'step',
            'action_key' => $targetStep->action_key,
            'step' => $targetStep->action_key,
            'label' => $targetStep->name,
        ];
        $step->forceFill(['config_json' => $stepConfig])->save();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Suche ohne blockierenden Consent-Dialog ausfuehren.',
        ]);
        $checkpoint = [
            'task_key' => 'check-consent',
            'successful' => false,
            'outcome' => 'blocked',
            'result' => [
                'ok' => true,
                'matchedCandidate' => 'button:has-text("Alle ablehnen")',
                'element' => ['text' => 'Alle ablehnen'],
            ],
        ];
        $observation = [
            'page' => ['url' => 'https://www.google.com', 'state' => 'consent_blocked', 'window' => 'main'],
            'page_state' => 'consent_blocked',
            'dom' => ['ui_state' => 'consent_blocked', 'visible_text_excerpt' => 'Alle ablehnen Alle akzeptieren'],
            'interaction_map' => [[
                'element_ref' => 'el_accept',
                'tag' => 'button',
                'text' => 'Alle akzeptieren',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button:has-text("Alle akzeptieren")'],
                'window' => 'main',
            ], [
                'element_ref' => 'el_reject',
                'tag' => 'button',
                'text' => 'Alle ablehnen',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button:has-text("Alle ablehnen")'],
                'window' => 'main',
            ]],
        ];
        $vision = [
            'ui_state' => 'consent_blocked',
            'verdict' => 'blocked',
            'confidence' => 0.99,
            'model' => 'test/vision',
        ];
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan($session, $step->fresh(), $checkpoint, $observation, $vision);

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertSame('insert_step', data_get($plan, 'operations.0.type'));
        $this->assertSame('reject', data_get($plan, 'operations.0.decision'));
        $this->assertSame('button:has-text("Alle ablehnen")', data_get($plan, 'operations.0.selector'));

        $service->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
            [
                'page' => ['url' => 'https://www.google.com', 'state' => 'consent_blocked', 'window' => 'main'],
                'page_state' => 'consent_blocked',
                'dom' => ['ui_state' => 'consent_blocked', 'visible_text_excerpt' => 'Alle ablehnen'],
                'interaction_map' => [[
                    'element_ref' => 'el_reject',
                    'tag' => 'button',
                    'text' => 'Alle ablehnen',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['button:has-text("Alle ablehnen")'],
                    'window' => 'main',
                ]],
                'copilot_checkpoint' => [
                    'task_key' => 'check-consent',
                    'result' => [],
                ],
                'copilot_vision' => [
                    ...$vision,
                    'relevant_elements' => [[
                        'element_ref' => 'el_reject',
                        'semantic_label' => 'Alle ablehnen',
                        'selector' => 'button:has-text("Alle ablehnen")',
                    ]],
                    'suggested_task_actions' => [[
                        'task_key' => 'browser.click',
                        'element_ref' => 'el_reject',
                        'target_label' => 'Alle ablehnen',
                        'target_selector' => 'button:has-text("Alle ablehnen")',
                    ]],
                ],
            ],
        );

        $ordered = $workflow->steps()->ordered()->get();
        $this->assertCount(3, $ordered);
        $this->assertSame($step->id, $ordered->get(0)->id);
        $this->assertSame($targetStep->id, $ordered->get(2)->id);
        $consentStep = $ordered->get(1);
        $this->assertSame($consentStep->action_key, data_get($step->fresh()->config_json, 'routes.success.action_key'));
        $this->assertSame('browser.click', data_get($consentStep->task_cards, '0.task_key'));
        $this->assertSame('button:has-text("Alle ablehnen")', data_get($consentStep->task_cards, '0.selector'));
        $this->assertSame($targetStep->action_key, data_get($consentStep->task_cards, '0.next.action_key'));

        $followUp = $service->plan($session->fresh(), $step->fresh(), $checkpoint, $observation, $vision);
        $this->assertSame('continue_route', $followUp['action']);
        $this->assertTrue($followUp['resume_checkpoint']);
        $this->assertCount(3, $workflow->steps()->get());
    }

    public function test_failed_optional_consent_click_repairs_static_if_routes_when_obstacle_is_gone(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'if-cookies-banner',
            'task_key' => 'decision.element_exists',
            'title' => 'IF Cookies Banner',
            'selector' => 'text="Alle ablehnen"',
            'on_error' => [
                'type' => 'card',
                'step' => 'start',
                'card' => 'consent-ablehnen',
            ],
        ], [
            'key' => 'consent-ablehnen',
            'task_key' => 'browser.click',
            'title' => 'Consent: Alle ablehnen',
            'selector' => '#W0wltc',
            'on_error' => [
                'type' => 'card',
                'step' => 'start',
                'card' => 'if-cookies-banner',
                'max_attempts' => 1,
            ],
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($step->workflow, [
            'goal' => 'Google-Suche ohne Consent-Blockade ausfuehren.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'consent-ablehnen',
                'successful' => false,
                'outcome' => 'failed',
                'result' => ['ok' => false, 'statusMessage' => 'Element nicht gefunden.'],
            ],
            [
                'page' => ['url' => 'https://www.google.com', 'title' => 'Google', 'state' => 'search_input'],
                'dom' => ['ui_state' => 'search_input', 'visible_text_excerpt' => 'Google Suche'],
                'interaction_map' => [[
                    'element_ref' => 'el_search_submit',
                    'tag' => 'input',
                    'text' => 'Google Suche',
                    'aria' => 'Google Suche',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['input[aria-label="Google Suche"]'],
                ]],
                'evidence_sufficient' => true,
            ],
            [
                'page_type' => 'search_page',
                'ui_state' => 'search_input',
                'confidence' => 0.9,
                'verdict' => 'continue',
                'safe_pause' => false,
                'relevant_elements' => [[
                    'element_ref' => 'el_search_submit',
                    'confidence' => 0.9,
                ]],
                'suggested_task_actions' => [],
            ],
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertSame('consent-ablehnen', $plan['task_key']);
        $this->assertSame('deterministic_optional_obstacle_routing', data_get($plan, 'planning_handoff.planner_profile'));
        $this->assertCount(2, $plan['operations']);

        app(WorkflowCopilotRepairService::class)->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
        );

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('consent-ablehnen', data_get($tasks, '0.next.card'));
        $this->assertSame('next', data_get($tasks, '0.on_error.step'));
        $this->assertSame('next', data_get($tasks, '1.next.step'));
        $this->assertSame('next', data_get($tasks, '1.on_error.step'));
        $this->assertSame('#W0wltc', data_get($tasks, '1.selector'));
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
                && str_contains($prompt, 'execution_contract')
                && str_contains($prompt, 'workflow_diagnostics')
                && str_contains($prompt, 'type=fail')
                && str_contains($prompt, 'unveraenderlichen Kontrolllauf')
                && str_contains((string) $system, 'Datenanalyse- und Planungsmodell')
                && str_contains((string) $system, 'Bildverstehen-Modell')
                && str_contains((string) $system, 'type=fail beendet den gesamten Workflow')
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
        $this->assertSame(1, data_get($plan, 'decision_trace.accepted_operation_count'));
        $this->assertSame(1, data_get($plan, 'decision_trace.rejected_operation_count'));
        $this->assertSame('visual_target_not_trusted', data_get($plan, 'decision_trace.rejected_operations.0.reason_code'));

        $service->applyStructuralOperations($workflow->fresh(), $plan['operations'], $session->fresh(), [
            'page' => ['url' => 'https://www.google.com'],
        ]);

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('browser.open_url', data_get($tasks, '0.task_key'));
        $this->assertSame('https://www.google.com', data_get($tasks, '0.url'));
        $this->assertSame('browser.find_element', data_get($tasks, '1.task_key'));
        $this->assertNotContains('browser.click', collect($tasks)->pluck('task_key')->all());
    }

    public function test_structural_planner_inserts_visual_tasks_only_from_matching_vision_and_dom_refs(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Ergebnis anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Suchfeld fuellen und Suche absenden.',
        ]);
        $observation = [
            'page' => ['url' => 'https://www.google.com', 'title' => 'Google', 'state' => 'search_input', 'window' => 'main'],
            'dom' => ['ui_state' => 'search_input', 'visible_text_excerpt' => 'Google Suche'],
            'interaction_map' => [[
                'element_ref' => 'el_search_input',
                'tag' => 'textarea',
                'aria' => 'Suche',
                'name' => 'q',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['#APjFqb', 'textarea[aria-label="Suche"]'],
                'window' => 'main',
            ], [
                'element_ref' => 'el_search_submit',
                'tag' => 'input',
                'aria' => 'Google Suche',
                'name' => 'btnK',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['input[aria-label="Google Suche"]', 'input[name="btnK"]'],
                'window' => 'main',
            ]],
            'evidence_sufficient' => true,
        ];
        $vision = [
            'page_type' => 'search_page',
            'ui_state' => 'search_input',
            'confidence' => 0.9,
            'verdict' => 'continue',
            'safe_pause' => false,
            'model' => 'vision/model-test',
            'relevant_elements' => [[
                'element_ref' => 'el_search_input',
                'confidence' => 0.94,
            ], [
                'element_ref' => 'el_search_submit',
                'confidence' => 0.93,
            ]],
            'suggested_task_actions' => [[
                'task_key' => 'input.fill_field',
                'element_ref' => 'el_search_input',
                'parameters' => ['value_reference' => 'google_search_url'],
                'confidence' => 0.94,
            ], [
                'task_key' => 'input.submit',
                'element_ref' => 'el_search_submit',
                'parameters' => [],
                'confidence' => 0.93,
            ]],
        ];
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')
            ->once()
            ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'configured_but_not_executed')
                && str_contains($prompt, 'trusted_vision_element_refs'))
            ->andReturn([
                'action' => 'structural_update',
                'reason' => 'Die beobachtete Suchinteraktion fehlt in dieser Liste.',
                'operations' => [[
                    'type' => 'insert_task',
                    'step_action_key' => 'start',
                    'task_catalog_key' => 'input.fill_field',
                    'title' => 'Suchfeld fuellen',
                    'parameters' => ['value_reference' => 'google_search_url'],
                    'element_ref' => 'el_search_input',
                    'insert_position' => 0,
                ], [
                    'type' => 'insert_task',
                    'step_action_key' => 'start',
                    'task_catalog_key' => 'input.submit',
                    'title' => 'Google-Suche absenden',
                    'parameters' => ['selector' => '#vom-modell-nicht-uebernehmen'],
                    'element_ref' => 'el_search_submit',
                    'insert_position' => 1,
                ]],
            ]);
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'append-result',
                'successful' => false,
                'outcome' => 'failed',
                'result' => ['ok' => false, 'statusMessage' => 'Kein Wert gefunden.'],
            ],
            $observation,
            $vision,
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertCount(2, $plan['operations']);
        $this->assertSame('textarea[aria-label="Suche"]', data_get($plan, 'operations.0.parameters.selector'));
        $this->assertSame('workflow_variable', data_get($plan, 'operations.0.parameters.value_source'));
        $this->assertSame('google_search_url', data_get($plan, 'operations.0.parameters.workflow_variable'));
        $this->assertArrayNotHasKey('value', data_get($plan, 'operations.0.parameters'));
        $this->assertArrayNotHasKey('input', data_get($plan, 'operations.0.parameters'));
        $this->assertSame('input[aria-label="Google Suche"]', data_get($plan, 'operations.1.parameters.selector'));
        $this->assertSame(2, data_get($plan, 'decision_trace.accepted_operation_count'));
        $this->assertSame(0, data_get($plan, 'decision_trace.rejected_operation_count'));

        $service->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
            $observation,
        );

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('input.fill_field', data_get($tasks, '0.task_key'));
        $this->assertSame('textarea[aria-label="Suche"]', data_get($tasks, '0.selector'));
        $this->assertSame('workflow_variable', data_get($tasks, '0.value_source'));
        $this->assertSame('google_search_url', data_get($tasks, '0.workflow_variable'));
        $this->assertSame('', data_get($tasks, '0.value'));
        $this->assertSame('', data_get($tasks, '0.input'));
        $this->assertSame('input.submit', data_get($tasks, '1.task_key'));
        $this->assertSame('input[aria-label="Google Suche"]', data_get($tasks, '1.selector'));
        $this->assertSame('data.append_to_array', data_get($tasks, '2.task_key'));
    }

    public function test_structural_planner_can_wrap_existing_collection_tasks_in_an_atomic_visual_loop(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Ergebnis anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
        ], [
            'key' => 'read-result',
            'task_key' => 'browser.read_searchengine_result',
            'title' => 'Suchtreffer lesen',
            'scope_variable' => 'current_result',
            'output_variable' => 'current_result',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Die ersten drei Suchtreffer strukturiert sammeln.',
        ]);
        $observation = [
            'page' => ['url' => 'https://www.google.com/search?q=test', 'title' => 'test - Google Suche', 'state' => 'search_results'],
            'dom' => ['ui_state' => 'search_results', 'visible_text_excerpt' => 'Suchergebnisse'],
            'interaction_map' => [[
                'element_ref' => 'el_result_link',
                'tag' => 'a',
                'text' => 'Erster Suchtreffer',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['a[data-result-link]'],
                'window' => 'main',
            ]],
            'evidence_sufficient' => true,
        ];
        $vision = [
            'page_type' => 'search_results',
            'ui_state' => 'search_results',
            'confidence' => 0.91,
            'verdict' => 'continue',
            'safe_pause' => false,
            'model' => 'vision/model-test',
            'relevant_elements' => [[
                'element_ref' => 'el_result_link',
                'confidence' => 0.91,
            ]],
            'suggested_task_actions' => [[
                'task_key' => 'loop.for_each_element',
                'element_ref' => 'el_result_link',
                'parameters' => ['limit' => 3],
                'confidence' => 0.91,
            ]],
        ];
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'action' => 'structural_update',
            'reason' => 'Der Consumer steht vor dem Producer und der Suchtreffer-Reader besitzt keinen Loop-Scope.',
            'operations' => [[
                'type' => 'insert_task',
                'step_action_key' => 'start',
                'task_catalog_key' => 'loop.for_each_element',
                'title' => 'Top-Treffer durchlaufen',
                'parameters' => [
                    'limit' => 3,
                    'store_current_element_as' => 'current_result',
                    'store_index_as' => 'result_index',
                ],
                'element_ref' => 'el_result_link',
                'insert_position' => 0,
            ], [
                'type' => 'move_task',
                'step_action_key' => 'start',
                'task_key' => 'read-result',
                'insert_position' => 1,
            ], [
                'type' => 'move_task',
                'step_action_key' => 'start',
                'task_key' => 'append-result',
                'insert_position' => 2,
            ]],
        ]);
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'append-result',
                'successful' => false,
                'outcome' => 'failed',
                'result' => ['ok' => false, 'statusMessage' => 'Kein Wert zum Anhaengen gefunden.'],
            ],
            $observation,
            $vision,
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertCount(3, $plan['operations']);
        $this->assertSame('loop.for_each_element', data_get($plan, 'operations.0.task_catalog_key'));
        $this->assertSame('move_task', data_get($plan, 'operations.1.type'));
        $this->assertSame('move_task', data_get($plan, 'operations.2.type'));

        $service->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
            $observation,
        );

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('loop.for_each_element', data_get($tasks, '0.task_key'));
        $this->assertSame('browser.read_searchengine_result', data_get($tasks, '1.task_key'));
        $this->assertSame('data.append_to_array', data_get($tasks, '2.task_key'));
        $this->assertSame('loop.end', data_get($tasks, '3.task_key'));
        $this->assertSame(data_get($tasks, '0.loop_pair_id'), data_get($tasks, '3.loop_pair_id'));
        $this->assertSame(data_get($tasks, '3.key'), data_get($tasks, '0.empty_target'));
        $this->assertSame('current_result', data_get($tasks, '0.store_current_element_as'));
        $this->assertSame(3, data_get($tasks, '0.limit'));
    }

    public function test_collection_dependency_is_repaired_from_existing_result_selector_without_model_pause(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Ergebnis anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
            'max_items' => '0',
        ], [
            'key' => 'read-result',
            'task_key' => 'browser.read_searchengine_result',
            'title' => 'Suchtreffer lesen',
            'scope_variable' => 'current_result',
            'output_variable' => 'current_result',
            'browser_window' => 'main',
            'browser_window_name' => 'main',
        ]]);
        $workflow->steps()->create([
            'name' => 'Ergebnisbereich pruefen',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'ergebnisbereich-pruefen',
            'position' => 5,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'auf-suchergebnisbereich-warten',
                'task_key' => 'wait.selector',
                'title' => 'Auf Suchergebnisse warten',
                'selector' => 'div#search a:has(div[data-rpos])',
                'browser_window' => 'main',
                'browser_window_name' => 'main',
            ]]],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Die Top 3 Suchtreffer strukturiert sammeln.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'append-result',
                'successful' => false,
                'outcome' => 'failed',
                'result' => ['ok' => false, 'statusMessage' => 'Kein Wert zum Anhaengen gefunden.'],
            ],
            [
                'page' => ['url' => 'https://www.google.com/search?q=test', 'window' => 'main'],
                'evidence_sufficient' => true,
            ],
            [],
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertSame('deterministic_collection_dependency', data_get($plan, 'decision_trace.source'));
        $this->assertCount(3, $plan['operations']);
        $this->assertSame('collection_dependency', data_get($plan, 'operations.0.purpose'));
        $this->assertSame('div#search a:has(div[data-rpos])', data_get($plan, 'operations.0.parameters.selector'));
        $this->assertSame(3, data_get($plan, 'operations.0.parameters.limit'));
        $this->assertSame('read-result', data_get($plan, 'operations.1.task_key'));
        $this->assertSame('append-result', data_get($plan, 'operations.2.task_key'));

        $service->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
        );

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('loop.for_each_element', data_get($tasks, '0.task_key'));
        $this->assertSame('browser.read_searchengine_result', data_get($tasks, '1.task_key'));
        $this->assertSame('data.append_to_array', data_get($tasks, '2.task_key'));
        $this->assertSame('loop.end', data_get($tasks, '3.task_key'));
        $this->assertSame('div#search a:has(div[data-rpos])', data_get($tasks, '0.selector'));
        $this->assertSame(3, data_get($tasks, '0.limit'));
        $this->assertSame(data_get($tasks, '0.loop_pair_id'), data_get($tasks, '3.loop_pair_id'));
    }

    public function test_empty_required_search_collection_uses_observed_heading_link_selector_without_model_pause(): void
    {
        [, $step] = $this->workflowWithTasks([[
            'key' => 'result-loop',
            'task_key' => 'loop.for_each_element',
            'title' => 'Ergebnisse durchlaufen',
            'selector' => 'div#search a:has(div[data-rpos])',
            'store_current_element_as' => 'current_result',
        ], [
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Ergebnis anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($step->workflow, [
            'goal' => 'Google-Ergebnisse als Array zurueckgeben.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'result-loop',
                'successful' => false,
                'outcome' => 'failed',
                'result' => [
                    'businessGap' => [
                        'reason_code' => 'required_collection_empty',
                    ],
                ],
            ],
            [
                'page' => [
                    'url' => 'https://www.google.com/search?q=test',
                    'state' => 'search_results',
                    'window' => 'main',
                ],
                'dom' => ['ui_state' => 'search_results'],
                'interaction_map' => [[
                    'element_ref' => 'el_search_results',
                    'tag' => 'a',
                    'text' => 'Erster Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => [
                        '#volatile-result-id',
                        '#search a:has(h3), #search a:has(h2)',
                    ],
                    'window' => 'main',
                ]],
                'evidence_sufficient' => true,
            ],
            [],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame(
            'deterministic_empty_collection_selector',
            data_get($plan, 'decision_trace.source'),
        );
        $this->assertSame(
            '#search a:has(h3), #search a:has(h2)',
            data_get($plan, 'changes.selector'),
        );
    }

    public function test_missing_required_workflow_return_is_inserted_from_existing_array_without_model_pause(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Ergebnis anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Google-Ergebnisse als Array zurueckgeben.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step->fresh(),
            [
                'task_key' => 'append-result',
                'successful' => false,
                'outcome' => 'failed',
                'result' => [
                    'businessGap' => [
                        'reason_code' => 'required_workflow_return_missing',
                        'source_array' => 'top_results',
                    ],
                ],
            ],
            ['page' => ['url' => 'https://www.google.com/search?q=test']],
            [],
        );

        $this->assertSame('restart_with_workflow_changes', $plan['action']);
        $this->assertSame('data.workflow_return', data_get($plan, 'operations.0.task_catalog_key'));
        $this->assertSame('top_results', data_get($plan, 'operations.0.parameters.selector'));

        $service->applyStructuralOperations(
            $workflow->fresh(),
            $plan['operations'],
            $session->fresh(),
        );

        $tasks = $step->fresh()->task_cards;
        $this->assertSame('data.workflow_return', data_get($tasks, '1.task_key'));
        $this->assertSame('top_results', data_get($tasks, '1.selector'));
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

    public function test_selector_timeout_with_role_matching_dom_element_plans_deterministic_selector_probe(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'wait-results',
            'task_key' => 'wait.selector',
            'title' => 'Auf Suchergebnisse warten',
            'selector' => 'div#search a:has(div[data-rpos])',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Suchergebnisse einsammeln.',
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);
        $service = app(WorkflowCopilotRepairService::class);

        $plan = $service->plan(
            $session,
            $step,
            [
                'task_key' => 'wait-results',
                'successful' => false,
                'outcome' => 'timeout',
                'result' => [
                    'ok' => false,
                    'statusMessage' => 'Kein Ziel wurde innerhalb des Timeouts gefunden: div#search a:has(div[data-rpos])',
                    'sideEffects' => [],
                ],
            ],
            [
                'page' => ['url' => 'https://www.example.com/search?q=test', 'state' => 'search_results', 'window' => 'main'],
                'dom' => ['ui_state' => 'search_results'],
                'interaction_map' => [[
                    'element_ref' => 'el_result_1',
                    'tag' => 'a',
                    'text' => 'Erster Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['#search a:has(h3)', '#volatile-id-123'],
                    'window' => 'main',
                ], [
                    'element_ref' => 'el_result_2',
                    'tag' => 'a',
                    'text' => 'Zweiter Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['#search a:has(h3)'],
                    'window' => 'main',
                ], [
                    'element_ref' => 'el_filter_button',
                    'tag' => 'button',
                    'text' => 'Filter',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['button[aria-label="Filter"]'],
                    'window' => 'main',
                ]],
                'evidence_sufficient' => true,
            ],
            [],
        );

        $this->assertSame('probe_update', $plan['action']);
        $this->assertSame('wait-results', $plan['task_key']);
        $this->assertSame('wait.selector', $plan['task_catalog_key']);
        $this->assertSame('#search a:has(h3)', data_get($plan, 'changes.selector'));
        $this->assertSame('#search a:has(h3)', data_get($plan, 'changes.element_selector'));
        $this->assertSame('wait-results--copilot-probe', data_get($plan, 'probe_task.key'));
        $this->assertSame('deterministic_selector_probe', data_get($plan, 'decision_trace.source'));
        $this->assertSame('selector_timeout', data_get($plan, 'decision_trace.failure_class'));
        $this->assertSame('selector_probe', data_get($plan, 'evidence.class'));
        $this->assertSame('dom_observation', data_get($plan, 'evidence.candidate_source'));
        $this->assertSame('div#search a:has(div[data-rpos])', data_get($plan, 'evidence.previous_selector'));
        $this->assertSame(['el_result_1', 'el_result_2'], data_get($plan, 'evidence.matches.0.element_refs'));
        $this->assertSame('deterministic_selector_probe', data_get($plan, 'planning_handoff.planner_profile'));

        $event = WorkflowCopilotEvent::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->where('event_type', 'repair.selector_probe_applied')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('selector_timeout', data_get($event->payload_json, 'failure_class'));
        $this->assertSame('div#search a:has(div[data-rpos])', data_get($event->payload_json, 'previous_selector'));
        $this->assertSame('#search a:has(h3)', data_get($event->payload_json, 'new_selector'));
        $this->assertSame('dom_observation', data_get($event->payload_json, 'candidate_source'));

        // Die geplanten update_task-Aenderungen sind revisionsfaehig: dieselben
        // changes uebernimmt der Supervisor nach erfolgreicher Probe via
        // applyChangesToStep in die naechste Workflow-Revision.
        $updated = $service->applyChangesToStep($step->fresh(), 'wait-results', $plan['changes'], $session->fresh());
        $this->assertSame('#search a:has(h3)', $updated['selector']);
        $this->assertSame('#search a:has(h3)', data_get($step->fresh()->config_json, 'tasks.0.selector'));
    }

    public function test_selector_timeout_without_usable_observation_keeps_existing_planner_behavior(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'wait-results',
            'task_key' => 'wait.selector',
            'title' => 'Auf Suchergebnisse warten',
            'selector' => 'div#search a:has(div[data-rpos])',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'action' => 'pause',
            'reason' => 'Keine sichere Reparatur moeglich.',
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            [
                'task_key' => 'wait-results',
                'successful' => false,
                'outcome' => 'timeout',
                'result' => [
                    'ok' => false,
                    'statusMessage' => 'Kein Ziel wurde innerhalb des Timeouts gefunden: div#search a:has(div[data-rpos])',
                ],
            ],
            [
                'page' => ['url' => 'https://www.example.com/search?q=test', 'state' => 'search_results'],
                'interaction_map' => [[
                    // Rollen-gleiches Element, aber nur ein volatiler ID-Kandidat.
                    'element_ref' => 'el_result_1',
                    'tag' => 'a',
                    'text' => 'Erster Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['#volatile-id-123'],
                ], [
                    // Stabiler Kandidat, aber falsche Elementrolle.
                    'element_ref' => 'el_filter_button',
                    'tag' => 'button',
                    'text' => 'Filter',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['button[aria-label="Filter"]'],
                ]],
            ],
            [],
        );

        $this->assertSame('pause', $plan['action']);
        $this->assertSame(0, WorkflowCopilotEvent::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->where('event_type', 'repair.selector_probe_applied')
            ->count());
        $this->assertSame('div#search a:has(div[data-rpos])', data_get($step->fresh()->config_json, 'tasks.0.selector'));
    }

    public function test_selector_probe_declines_on_ambiguous_equally_stable_candidates(): void
    {
        [$workflow, $step] = $this->workflowWithTasks([[
            'key' => 'wait-results',
            'task_key' => 'wait.selector',
            'title' => 'Auf Suchergebnisse warten',
            'selector' => 'div#search a:has(div[data-rpos])',
        ]]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'action' => 'pause',
            'reason' => 'Keine sichere Reparatur moeglich.',
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        $plan = app(WorkflowCopilotRepairService::class)->plan(
            $session,
            $step,
            [
                'task_key' => 'wait-results',
                'successful' => false,
                'outcome' => 'timeout',
                'result' => [
                    'ok' => false,
                    'statusMessage' => 'Kein Ziel wurde innerhalb des Timeouts gefunden: div#search a:has(div[data-rpos])',
                ],
            ],
            [
                'page' => ['url' => 'https://www.example.com/search?q=test', 'state' => 'search_results'],
                'interaction_map' => [[
                    'element_ref' => 'el_result_1',
                    'tag' => 'a',
                    'text' => 'Erster Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['a[title="Erster Treffer"]'],
                ], [
                    'element_ref' => 'el_result_2',
                    'tag' => 'a',
                    'text' => 'Zweiter Treffer',
                    'visible' => true,
                    'enabled' => true,
                    'selector_candidates' => ['a[title="Zweiter Treffer"]'],
                ]],
            ],
            [],
        );

        $this->assertSame('pause', $plan['action']);
        $this->assertSame(0, WorkflowCopilotEvent::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->where('event_type', 'repair.selector_probe_applied')
            ->count());
    }

    public function test_failure_classification_is_deterministic_for_known_runtime_messages(): void
    {
        $probes = app(WorkflowSelectorProbeService::class);

        foreach ([
            'selector_timeout' => [
                ['outcome' => 'timeout', 'result' => ['statusMessage' => 'Kein Ziel wurde innerhalb des Timeouts gefunden: div#search a']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Timed out waiting for selector #login']],
            ],
            'selector_not_found' => [
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Kein klickbares Ziel uebergeben oder gefunden.']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Keines der gefundenen Ziele konnte geklickt werden.']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Kein Element gefunden. Weiterleitung kann ueber Teilstatus oder Fehler erfolgen.']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Kein passendes Input-Feld konnte gefuellt werden.']],
            ],
            'navigation' => [
                ['outcome' => 'failed', 'result' => ['error' => 'Timeout beim Navigieren']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Die Seite konnte nicht geladen werden.']],
            ],
            'network' => [
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'net::ERR_NAME_NOT_RESOLVED bei www.example.com']],
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Connection refused durch die Zielseite']],
            ],
            'consent' => [
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Der Consent-Dialog blockiert die Seite.']],
            ],
            'unknown' => [
                ['outcome' => 'failed', 'result' => ['statusMessage' => 'Unerwarteter interner Fehler.']],
                ['outcome' => 'timeout', 'result' => []],
                [],
            ],
        ] as $expected => $checkpoints) {
            foreach ($checkpoints as $checkpoint) {
                $this->assertSame(
                    $expected,
                    $probes->classifyFailure($checkpoint),
                    'Fehlklassifikation fuer: '.json_encode($checkpoint),
                );
            }
        }
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
