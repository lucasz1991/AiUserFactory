<?php

namespace Tests\Unit;

use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowCopilotVisionService;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowDebugArtifactService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkflowCopilotVisionServiceTest extends TestCase
{
    public function test_it_uses_the_visual_fallback_model_and_filters_unknown_refs_and_tasks(): void
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => ['content' => 'not valid json'],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'page_type' => 'login',
                        'ui_state' => 'login_form_visible',
                        'goal_progress' => 0.35,
                        'blockers' => ['Der konfigurierte Selector passt nicht.'],
                        'relevant_elements' => [
                            ['element_ref' => 'el_login', 'reason' => 'Sichtbarer Login-Button', 'confidence' => 0.95],
                            ['element_ref' => 'invented_element', 'reason' => 'Erfunden', 'confidence' => 1],
                        ],
                        'confidence' => 0.91,
                        'suggested_task_actions' => [
                            [
                                'task_key' => 'browser.click',
                                'element_ref' => 'el_login',
                                'parameters' => ['selector' => 'button[data-testid="login"]'],
                                'reason' => 'Den sichtbaren Button pruefen.',
                                'confidence' => 0.9,
                            ],
                            [
                                'task_key' => 'shell.exec',
                                'element_ref' => 'el_login',
                                'parameters' => ['command' => 'unsafe'],
                                'reason' => 'Nicht erlaubt.',
                                'confidence' => 1,
                            ],
                        ],
                        'needs_screenshot' => false,
                        'verdict' => 'continue',
                    ], JSON_UNESCAPED_SLASHES)],
                ]],
            ]);

        $ai = new class extends AiConnectionService
        {
            protected function setting(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'api_key' => 'test-api-key',
                    'api_url' => 'https://openrouter.test/chat/completions',
                    'temperature' => 0,
                    'max_completion_tokens' => 1800,
                    default => $default,
                };
            }
        };
        $service = $this->visionService($ai, ['vision-primary', 'vision-fallback']);

        $result = $service->analyze(
            $this->visualObservation(),
            'Erfolgreich anmelden.',
            [
                'execution_contract' => [
                    'route_types' => [
                        'fail' => 'Beendet den gesamten Workflow.',
                    ],
                    'verification' => [
                        'Unveraenderlicher Kontrolllauf ohne Mutation.',
                    ],
                ],
                'workflow' => [
                    'steps' => [[
                        'action_key' => 'login',
                        'tasks' => [[
                            'key' => 'login-click',
                            'task_key' => 'browser.click',
                            'on_error' => ['type' => 'step', 'step' => 'login-fehler'],
                        ]],
                    ]],
                ],
            ],
        );

        $this->assertSame('vision', $result['analysis_source']);
        $this->assertSame('vision-fallback', $result['model']);
        $this->assertTrue($result['fallback_used']);
        $this->assertSame('continue', $result['verdict']);
        $this->assertFalse($result['safe_pause']);
        $this->assertSame(['el_login'], array_column($result['relevant_elements'], 'element_ref'));
        $this->assertSame(['browser.click'], array_column($result['suggested_task_actions'], 'task_key'));
        $this->assertSame(['error', 'success'], array_column($result['attempts'], 'status'));
        $this->assertSame(['vision-primary', 'vision-fallback'], array_column($result['attempts'], 'model'));

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('vision-primary', data_get($requests[0][0]->data(), 'model'));
        $this->assertSame('vision-fallback', data_get($requests[1][0]->data(), 'model'));
        $this->assertStringContainsString(
            'Vollstaendiger Task-, Routing- und Workflow-Kontext',
            (string) data_get($requests[1][0]->data(), 'messages.0.content.0.text'),
        );
        $this->assertStringContainsString(
            'Beendet den gesamten Workflow',
            (string) data_get($requests[1][0]->data(), 'messages.0.content.0.text'),
        );
        $this->assertStringContainsString(
            '"on_error":{"type":"step","step":"login-fehler"}',
            (string) data_get($requests[1][0]->data(), 'messages.0.content.0.text'),
        );
        $this->assertStringStartsWith(
            'data:image/png;base64,',
            (string) data_get($requests[1][0]->data(), 'messages.0.content.1.image_url.url'),
        );
        $this->assertSame('json_object', data_get($requests[1][0]->data(), 'response_format.type'));
    }

    public function test_dom_only_low_confidence_result_pauses_without_blind_actions(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('imageUnderstanding');
        $ai->shouldReceive('json')->once()->andReturn([
            'page_type' => 'unknown',
            'ui_state' => 'partially_loaded',
            'goal_progress' => 'unklar',
            'blockers' => ['Screenshot fehlt.'],
            'relevant_elements' => [['element_ref' => 'el_login']],
            'confidence' => 0.2,
            'suggested_task_actions' => [[
                'task_key' => 'browser.click',
                'element_ref' => 'el_login',
                'parameters' => ['selector' => 'button'],
                'reason' => 'Unsicherer Klick',
                'confidence' => 0.2,
            ]],
            'needs_screenshot' => true,
            'verdict' => 'continue',
        ]);
        $service = $this->visionService($ai, ['vision-primary']);
        $observation = $this->visualObservation();
        $observation['screenshot_data_url'] = null;
        $observation['screenshot']['available_for_vision'] = false;

        $result = $service->analyze($observation, 'Erfolgreich anmelden.');

        $this->assertSame('dom', $result['analysis_source']);
        $this->assertSame('pause', $result['verdict']);
        $this->assertTrue($result['safe_pause']);
        $this->assertTrue($result['needs_screenshot']);
        $this->assertSame([], $result['suggested_task_actions']);
        $this->assertStringContainsString('nicht verlaesslich genug', implode(' ', $result['blockers']));
        $this->assertSame('success', data_get($result, 'attempts.0.status'));
    }

    public function test_failed_visual_models_with_insufficient_dom_evidence_return_a_safe_pause(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('imageUnderstanding')->twice()->andThrow(new RuntimeException('provider token=private'));
        $ai->shouldNotReceive('json');
        $service = $this->visionService($ai, ['vision-primary', 'vision-fallback']);
        $observation = $this->visualObservation();
        $observation['interaction_map'] = [];
        $observation['dom']['visible_text_excerpt'] = null;
        $observation['page']['url'] = null;
        $observation['evidence_sufficient'] = false;

        $result = $service->analyze($observation, 'Erfolgreich anmelden.');
        $serialized = json_encode($result, JSON_UNESCAPED_SLASHES);

        $this->assertSame('safe_pause', $result['analysis_source']);
        $this->assertSame('pause', $result['verdict']);
        $this->assertTrue($result['safe_pause']);
        $this->assertSame([], $result['suggested_task_actions']);
        $this->assertCount(2, $result['attempts']);
        $this->assertStringNotContainsString('private', $serialized);
    }

    public function test_configured_visual_models_keep_primary_first_and_deduplicate_fallbacks(): void
    {
        $observations = new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class));
        $service = new class(Mockery::mock(AiConnectionService::class), new WorkflowTaskCatalog, $observations) extends WorkflowCopilotVisionService
        {
            public function models(): array
            {
                return $this->configuredModels();
            }

            protected function setting(string $type, string $key): array
            {
                return match ($type.'.'.$key) {
                    'services.openrouter' => [
                        'image_understanding_model' => 'vision-primary',
                        'vision_fallback_models' => ['legacy-fallback'],
                    ],
                    'ai_assistant.workflow_copilot' => [
                        'vision_fallback_models' => "vision-fallback\nvision-primary\nvision-last",
                    ],
                    default => [],
                };
            }
        };

        $this->assertSame([
            'vision-primary',
            'vision-fallback',
            'vision-last',
        ], $service->models());
    }

    protected function visionService(AiConnectionService $ai, array $models): WorkflowCopilotVisionService
    {
        $observations = new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class));

        return new class($ai, new WorkflowTaskCatalog, $observations, $models) extends WorkflowCopilotVisionService
        {
            public function __construct(
                AiConnectionService $ai,
                WorkflowTaskCatalog $taskCatalog,
                WorkflowCopilotObservationService $observations,
                protected array $testModels,
            ) {
                parent::__construct($ai, $taskCatalog, $observations);
            }

            protected function configuredModels(): array
            {
                return $this->testModels;
            }
        };
    }

    protected function visualObservation(): array
    {
        return [
            'state_signature' => 'state-login',
            'page' => [
                'url' => 'https://example.test/login',
                'title' => 'Login',
                'window' => 'main',
                'viewport' => ['width' => 1280, 'height' => 720],
            ],
            'dom' => [
                'ui_state' => 'login_page',
                'ready_state' => 'complete',
                'visible_text_excerpt' => 'Login Anmelden',
            ],
            'interaction_map' => [[
                'element_ref' => 'el_login',
                'tag' => 'button',
                'role' => 'button',
                'type' => null,
                'text' => 'Anmelden',
                'aria' => 'Anmelden',
                'name' => null,
                'placeholder' => null,
                'visible' => true,
                'enabled' => true,
                'focused' => false,
                'selected' => false,
                'bounding_box' => ['x' => 20, 'y' => 30, 'width' => 160, 'height' => 42],
                'selector_candidates' => ['button[data-testid="login"]'],
                'frame' => null,
                'window' => 'main',
            ]],
            'screenshot_data_url' => 'data:image/png;base64,'.base64_encode('small-png-test-payload'),
            'screenshot' => [
                'available_for_vision' => true,
                'mime_type' => 'image/png',
                'size_bytes' => 22,
            ],
            'evidence_sufficient' => true,
        ];
    }
}
