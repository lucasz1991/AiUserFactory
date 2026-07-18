<?php

namespace Tests\Unit;

use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowDebugArtifactService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class WorkflowCopilotObservationServiceTest extends TestCase
{
    protected array $temporaryFiles = [];

    public function test_it_combines_dom_and_screenshot_artifacts_without_leaking_secrets(): void
    {
        $domPath = $this->temporaryFile('snapshot.html', <<<'HTML'
<!-- workflow-debug-metadata: {"url":"https://example.test/login?token=top-secret","title":"Anmeldung","readyState":"complete","uiState":"login_page"} -->
<html><body>
    <input id="email" name="email" type="email" value="secret@example.test" placeholder="E-Mail-Adresse">
    <button data-testid="login-submit" aria-label="Jetzt anmelden">Anmelden</button>
    <script>window.secret = "must-not-leak";</script>
</body></html>
HTML);
        $screenshotPath = $this->temporaryFile(
            'screen.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        );
        $domArtifact = $this->artifact(11, 'dom', 'debug/snapshot.html', [
            'visibleTextExcerpt' => 'Anmeldung fuer secret@example.test',
            'uiState' => 'login_page',
            'selectorSuggestions' => [[
                'tag' => 'input',
                'type' => 'email',
                'text' => 'secret@example.test',
                'name' => 'email',
                'selector' => 'input[name="email"]',
                'boundingBox' => ['x' => 20, 'y' => 80, 'width' => 300, 'height' => 42],
                'visible' => true,
                'enabled' => true,
            ], [
                'tag' => 'button',
                'text' => 'Anmelden',
                'ariaLabel' => 'Jetzt anmelden',
                'selector' => 'button[data-testid="login-submit"]',
                'bounding_box' => ['x' => 20, 'y' => 140, 'width' => 160, 'height' => 42],
                'visible' => true,
                'enabled' => true,
            ]],
            'token' => 'metadata-token',
        ]);
        $screenshotArtifact = $this->artifact(12, 'screenshot', 'debug/screen.png');
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'run_uuid' => 'run-test',
            'result_json' => [
                'normalized_result' => ['state_signature' => 'state-123'],
                'browserWsEndpoint' => 'ws://127.0.0.1:9222/devtools/browser/private',
                'cookies' => [['name' => 'session', 'value' => 'cookie-secret']],
                'html' => '<html>full source must not leak</html>',
            ],
        ]);
        $stepRun = (new WorkflowStepRun)->forceFill([
            'id' => 9,
            'workflow_run_id' => 5,
            'result_json' => [
                'status' => 'failed',
                'input_value' => 'typed-secret',
            ],
        ]);
        $run->setRelation('stepRuns', new Collection([$stepRun]));
        $run->setRelation('artifacts', new Collection([$domArtifact, $screenshotArtifact]));
        $stepRun->setRelation('artifacts', new Collection([$domArtifact, $screenshotArtifact]));

        $artifacts = Mockery::mock(WorkflowDebugArtifactService::class);
        $artifacts->shouldReceive('absolutePath')->andReturnUsing(
            fn (WorkflowRunArtifact $artifact): ?string => match ((int) $artifact->getKey()) {
                11 => $domPath,
                12 => $screenshotPath,
                default => null,
            },
        );
        $artifacts->shouldReceive('artifactUrl')->twice()->with($screenshotArtifact)->andReturn('/workflow-runs/5/artifacts/12');
        $service = new WorkflowCopilotObservationService($artifacts);

        $observation = $service->observe($run, $stepRun);
        $repeat = $service->observe($run, $stepRun);
        $serialized = json_encode($observation, JSON_UNESCAPED_SLASHES);

        $this->assertSame('state-123', $observation['state_signature']);
        $this->assertSame('login_page', data_get($observation, 'dom.ui_state'));
        $this->assertSame('https://example.test/login?token=%5BREDACTED%5D', data_get($observation, 'page.url'));
        $this->assertSame('debug/screen.png', $observation['screenshot_relative_path']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $observation['screenshot_data_url']);
        $this->assertTrue(data_get($observation, 'screenshot.available_for_vision'));
        $this->assertSame(1, data_get($observation, 'screenshot.width'));
        $this->assertSame(1, data_get($observation, 'screenshot.height'));
        $this->assertTrue($observation['evidence_sufficient']);
        $this->assertGreaterThan(0, $observation['sensitive_fields_removed']);
        $this->assertNotEmpty($observation['interaction_map']);
        $this->assertSame(
            range(1, count($observation['interaction_map'])),
            array_column($observation['interaction_map'], 'element_number'),
        );
        $this->assertSame(
            array_column($observation['interaction_map'], 'element_ref'),
            array_column($repeat['interaction_map'], 'element_ref'),
        );

        $input = collect($observation['interaction_map'])->firstWhere('tag', 'input');
        $button = collect($observation['interaction_map'])->first(
            fn (array $element): bool => $element['tag'] === 'button' && $element['bounding_box'] !== null,
        );

        $this->assertSame('[REDACTED]', $input['text']);
        $this->assertSame(20.0, data_get($button, 'bounding_box.x'));
        $this->assertStringNotContainsString('secret@example.test', $serialized);
        $this->assertStringNotContainsString('metadata-token', $serialized);
        $this->assertStringNotContainsString('cookie-secret', $serialized);
        $this->assertStringNotContainsString('typed-secret', $serialized);
        $this->assertStringNotContainsString('ws://', $serialized);
        $this->assertStringNotContainsString('<html', $serialized);

        $withoutImage = $observation;
        unset($withoutImage['screenshot_data_url']);
        $this->assertLessThanOrEqual(
            WorkflowCopilotObservationService::MAX_OBSERVATION_BYTES,
            strlen(json_encode($withoutImage, JSON_UNESCAPED_SLASHES)),
        );
    }

    public function test_recursive_sanitizer_masks_credentials_input_values_websockets_and_full_html(): void
    {
        $service = new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class));

        $safe = $service->sanitizeForModel([
            'password' => 'password-secret',
            'nested' => [
                'token' => 'token-secret',
                'value' => 'input-secret',
                'browserWsEndpoint' => 'ws://localhost/private',
                'html' => '<html><body>private source</body></html>',
                'visible_text' => 'Kontakt: person@example.test',
                'blocker' => 'eyJhbGciOiJIUzI1NiJ9.secret.signature',
            ],
        ]);
        $serialized = json_encode($safe, JSON_UNESCAPED_SLASHES);

        $this->assertSame('[REDACTED]', $safe['password']);
        $this->assertSame('[REDACTED]', data_get($safe, 'nested.token'));
        $this->assertSame('[REDACTED]', data_get($safe, 'nested.value'));
        $this->assertSame('[REDACTED]', data_get($safe, 'nested.browserWsEndpoint'));
        $this->assertSame('[REDACTED]', data_get($safe, 'nested.html'));
        $this->assertStringContainsString('[EMAIL REDACTED]', data_get($safe, 'nested.visible_text'));
        $this->assertSame('[TOKEN REDACTED]', data_get($safe, 'nested.blocker'));
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString('ws://', $serialized);
        $this->assertStringNotContainsString('<html', $serialized);
    }

    public function test_current_step_observation_excludes_interactions_from_older_step_artifacts(): void
    {
        $oldArtifact = $this->artifact(31, 'json', 'debug/old.json', [
            'interaction_map' => [[
                'tag' => 'button',
                'text' => 'Alle ablehnen',
                'selector' => '#W0wltc',
                'visible' => true,
            ]],
        ]);
        $oldArtifact->workflow_step_run_id = 8;
        $currentArtifact = $this->artifact(32, 'json', 'debug/current.json', [
            'interaction_map' => [[
                'tag' => 'a',
                'text' => 'Aktuelles Suchergebnis',
                'selector' => '#search a:has(h3)',
                'visible' => true,
            ]],
        ]);
        $stepRun = (new WorkflowStepRun)->forceFill([
            'id' => 9,
            'workflow_run_id' => 5,
            'result_json' => [],
        ]);
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'workflow_revision' => 24,
            'result_json' => ['interaction_map' => [[
                'tag' => 'button',
                'text' => 'Alle ablehnen',
                'selector' => '#stale-run-result',
                'visible' => true,
            ]]],
        ]);
        $run->setRelation('stepRuns', collect([$stepRun]));
        $run->setRelation('artifacts', collect([$oldArtifact, $currentArtifact]));
        $stepRun->setRelation('artifacts', collect([$currentArtifact]));
        $artifacts = Mockery::mock(WorkflowDebugArtifactService::class);
        $artifacts->shouldReceive('absolutePath')->andReturnNull();

        $observation = (new WorkflowCopilotObservationService($artifacts))->observe($run, $stepRun);
        $serialized = json_encode($observation['interaction_map'], JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('Aktuelles Suchergebnis', $serialized);
        $this->assertStringNotContainsString('Alle ablehnen', $serialized);
        $this->assertSame(9, data_get($observation, 'evidence_provenance.workflow_step_run_id'));
        $this->assertSame(24, data_get($observation, 'evidence_provenance.workflow_revision'));
        $this->assertSame($observation['captured_at'], data_get($observation, 'evidence_provenance.captured_at'));
    }

    public function test_identical_consecutive_screenshots_are_not_reported_as_changed(): void
    {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $beforePath = $this->temporaryFile('before.png', $image);
        $afterPath = $this->temporaryFile('after.png', $image);
        $before = $this->artifact(20, 'screenshot', 'debug/before.png');
        $after = $this->artifact(21, 'screenshot', 'debug/after.png');
        $run = (new WorkflowRun)->forceFill(['id' => 5, 'result_json' => []]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect([$before, $after]));
        $artifacts = Mockery::mock(WorkflowDebugArtifactService::class);
        $artifacts->shouldReceive('absolutePath')->andReturnUsing(
            fn (WorkflowRunArtifact $artifact): ?string => (int) $artifact->getKey() === 20 ? $beforePath : $afterPath,
        );
        $artifacts->shouldReceive('artifactUrl')->once()->with($after)->andReturn('/workflow-runs/5/artifacts/21');

        $observation = (new WorkflowCopilotObservationService($artifacts))->observe($run);

        $this->assertFalse($observation['screenshot_changed']);
        $this->assertSame(21, $observation['screenshot_artifact_id']);
    }

    public function test_interaction_map_and_model_payload_are_bounded(): void
    {
        $elements = [];

        for ($index = 0; $index < 250; $index++) {
            $elements[] = [
                'tag' => 'button',
                'text' => str_repeat('Element '.$index.' ', 20),
                'selector' => 'button[data-index="'.$index.'"]',
                'visible' => true,
                'enabled' => true,
            ];
        }

        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => ['interaction_map' => $elements],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());
        $observation = (new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class)))->observe($run);
        $withoutImage = $observation;
        unset($withoutImage['screenshot_data_url']);

        $this->assertCount(WorkflowCopilotObservationService::MAX_ELEMENTS, $observation['interaction_map']);
        $this->assertTrue($observation['payload_truncated']);
        $this->assertLessThanOrEqual(
            WorkflowCopilotObservationService::MAX_OBSERVATION_BYTES,
            strlen(json_encode($withoutImage, JSON_UNESCAPED_SLASHES)),
        );
    }

    public function test_visible_consent_actions_are_prioritized_before_the_interaction_limit(): void
    {
        $elements = [];

        for ($index = 0; $index < 120; $index++) {
            $elements[] = [
                'tag' => 'a',
                'text' => 'Navigation '.$index,
                'selector' => 'a[data-index="'.$index.'"]',
                'visible' => true,
                'enabled' => true,
            ];
        }

        $elements[] = [
            'tag' => 'button',
            'text' => 'Alle akzeptieren',
            'selector' => 'button:has-text("Alle akzeptieren")',
            'visible' => true,
            'enabled' => true,
        ];
        $elements[] = [
            'tag' => 'button',
            'text' => 'Alle ablehnen',
            'selector' => 'button:has-text("Alle ablehnen")',
            'visible' => true,
            'enabled' => true,
        ];

        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => ['interaction_map' => $elements],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());

        $observation = (new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class)))->observe($run);

        $this->assertCount(WorkflowCopilotObservationService::MAX_ELEMENTS, $observation['interaction_map']);
        $this->assertSame('Alle ablehnen', data_get($observation, 'interaction_map.0.text'));
        $this->assertSame('button:has-text("Alle ablehnen")', data_get($observation, 'interaction_map.0.selector_candidates.0'));
        $this->assertTrue(collect($observation['interaction_map'])->contains(
            fn (array $element): bool => ($element['text'] ?? null) === 'Alle akzeptieren',
        ));
    }

    public function test_element_reference_stays_stable_when_text_changes_but_selector_does_not(): void
    {
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => [
                'interaction_map' => [[
                    'tag' => 'button',
                    'text' => 'Weiter',
                    'selector' => 'button[data-testid="continue"]',
                    'visible' => true,
                ]],
            ],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());
        $service = new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class));
        $first = $service->observe($run);

        $run->result_json = [
            'interaction_map' => [[
                'tag' => 'button',
                'text' => 'Bitte warten ...',
                'selector' => 'button[data-testid="continue"]',
                'visible' => true,
            ]],
        ];
        $second = $service->observe($run);

        $this->assertSame(
            data_get($first, 'interaction_map.0.element_ref'),
            data_get($second, 'interaction_map.0.element_ref'),
        );
    }

    public function test_google_search_field_uses_semantic_selectors_and_keeps_reference_when_generated_id_changes(): void
    {
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => [
                'interaction_map' => [[
                    'tag' => 'textarea',
                    'id' => 'APjFqb',
                    'role' => 'combobox',
                    'title' => 'Suche',
                    'ariaLabel' => 'Suche',
                    'name' => 'q',
                    'selector' => '#APjFqb',
                    'visible' => true,
                    'enabled' => true,
                ]],
            ],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());
        $service = new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class));
        $first = $service->observe($run);

        $run->result_json = [
            'interaction_map' => [[
                'tag' => 'textarea',
                'id' => 'gLFyf-new-generated-id',
                'role' => 'combobox',
                'title' => 'Suche',
                'ariaLabel' => 'Suche',
                'name' => 'q',
                'selector' => '#gLFyf-new-generated-id',
                'visible' => true,
                'enabled' => true,
            ]],
        ];
        $second = $service->observe($run);

        $this->assertSame(
            data_get($first, 'interaction_map.0.element_ref'),
            data_get($second, 'interaction_map.0.element_ref'),
        );
        $this->assertSame('Suche', data_get($second, 'interaction_map.0.semantic_label'));
        $this->assertSame('textarea[title="Suche"]', data_get($second, 'interaction_map.0.selector_candidates.0'));
        $this->assertSame('textarea[aria-label="Suche"]', data_get($second, 'interaction_map.0.selector_candidates.1'));
        $this->assertContains('textarea[name="q"]', data_get($second, 'interaction_map.0.selector_candidates'));
        $this->assertNotSame('#gLFyf-new-generated-id', data_get($second, 'interaction_map.0.selector_candidates.0'));
    }

    public function test_explicitly_hidden_elements_are_removed_from_the_model_interaction_map(): void
    {
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => [
                'interaction_map' => [[
                    'tag' => 'button',
                    'text' => 'Hidden admin action',
                    'selector' => '#hidden-admin-action',
                    'visible' => false,
                ], [
                    'tag' => 'button',
                    'text' => 'Visible action',
                    'selector' => '#visible-action',
                    'visible' => true,
                ]],
            ],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());

        $observation = (new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class)))->observe($run);
        $serialized = json_encode($observation['interaction_map'], JSON_UNESCAPED_SLASHES);

        $this->assertCount(1, $observation['interaction_map']);
        $this->assertSame('button:has-text("Visible action")', data_get($observation, 'interaction_map.0.selector_candidates.0'));
        $this->assertSame(1, data_get($observation, 'interaction_map.0.element_number'));
        $this->assertStringNotContainsString('hidden-admin-action', $serialized);
    }

    public function test_unstable_long_identifier_is_not_exposed_as_a_selector_candidate(): void
    {
        $run = (new WorkflowRun)->forceFill([
            'id' => 5,
            'result_json' => [
                'interaction_map' => [[
                    'tag' => 'button',
                    'text' => 'Continue',
                    'selector' => '[id="user_0123456789abcdefghijklmnop"]',
                    'visible' => true,
                ]],
            ],
        ]);
        $run->setRelation('stepRuns', collect());
        $run->setRelation('artifacts', collect());

        $observation = (new WorkflowCopilotObservationService(Mockery::mock(WorkflowDebugArtifactService::class)))->observe($run);

        $this->assertSame(
            ['button:has-text("Continue")'],
            data_get($observation, 'interaction_map.0.selector_candidates'),
        );
        $this->assertStringNotContainsString(
            '0123456789abcdefghijklmnop',
            json_encode($observation['interaction_map'], JSON_UNESCAPED_SLASHES),
        );
    }

    protected function artifact(int $id, string $type, string $path, array $metadata = []): WorkflowRunArtifact
    {
        return (new WorkflowRunArtifact)->forceFill([
            'id' => $id,
            'workflow_run_id' => 5,
            'workflow_step_run_id' => 9,
            'artifact_type' => $type,
            'phase' => 'after',
            'browser_window' => 'main',
            'current_url' => 'https://example.test/login?token=top-secret',
            'title' => 'Anmeldung',
            'storage_disk' => 'local',
            'storage_path' => $path,
            'status' => 'success',
            'metadata_json' => $metadata,
        ]);
    }

    protected function temporaryFile(string $name, string $content): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workflow-copilot-observation-'.getmypid();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
            @rmdir(dirname($file));
        }

        parent::tearDown();
    }
}
