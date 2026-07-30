<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowRunPreview;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowRunPreviewDomInspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
    }

    public function test_debug_preview_renders_the_bounded_dom_tree_marker_and_cursor_for_its_window(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'DOM Preview',
            'slug' => 'dom-preview-'.Str::random(8),
            'is_active' => true,
            'settings_json' => ['dev_mode' => true],
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Browser',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'paused',
            'context_json' => ['interactive_debug' => true],
            'result_json' => [],
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'paused',
            'result_json' => [
                'browserWindows' => [[
                    'key' => 'main',
                    'label' => 'Main',
                    'targetId' => 'target-main',
                    'screenshotUrl' => 'https://example.test/live.png',
                    'debugDomPath' => 'C:\\private\\must-not-leak.json',
                    'domTree' => [
                        'version' => 2,
                        'rootTag' => 'body',
                        'capturedAt' => '2026-07-24T12:00:00.000Z',
                        'viewport' => ['width' => 800, 'height' => 600, 'deviceScaleFactor' => 1],
                        'frames' => [[
                            'frameRef' => 'main',
                            'name' => 'main',
                            'rootTag' => 'body',
                            'nodes' => [
                                [
                                    'nodeRef' => 'main:main:body',
                                    'parentRef' => null,
                                    'depth' => 0,
                                    'tag' => 'body',
                                    'selector' => 'body',
                                    'x' => 0,
                                    'y' => 0,
                                    'width' => 800,
                                    'height' => 600,
                                    'visible' => true,
                                    'enabled' => true,
                                    'inShadowDom' => false,
                                ],
                                [
                                    'nodeRef' => 'main:main:submit',
                                    'parentRef' => 'main:main:body',
                                    'depth' => 1,
                                    'tag' => 'button',
                                    'id' => 'submit',
                                    'className' => 'primary action',
                                    'text' => 'Absenden',
                                    'selector' => '#submit',
                                    'selectorCandidates' => [
                                        ['selector' => 'button[data-testid="save"]', 'kind' => 'attribute', 'unique' => true, 'matchCount' => 1, 'score' => 100],
                                        ['selector' => 'button.primary', 'kind' => 'class', 'unique' => false, 'matchCount' => 2, 'score' => 55],
                                    ],
                                    'attributes' => [
                                        'data-testid' => 'save',
                                        'aria-label' => 'Formular absenden',
                                    ],
                                    'role' => 'button',
                                    'ariaLabel' => 'Formular absenden',
                                    'label' => 'Absenden',
                                    'x' => 120,
                                    'y' => 80,
                                    'width' => 160,
                                    'height' => 40,
                                    'visible' => true,
                                    'enabled' => true,
                                    'actionable' => true,
                                    'inShadowDom' => false,
                                ],
                                [
                                    'nodeRef' => 'main:main:continue',
                                    'parentRef' => 'main:main:body',
                                    'depth' => 1,
                                    'tag' => 'button',
                                    'className' => 'primary action',
                                    'text' => 'Weiter',
                                    'selector' => 'button.primary',
                                    'x' => 310,
                                    'y' => 80,
                                    'width' => 160,
                                    'height' => 40,
                                    'visible' => true,
                                    'enabled' => true,
                                    'actionable' => true,
                                    'inShadowDom' => false,
                                ],
                                [
                                    'nodeRef' => 'main:main:email',
                                    'parentRef' => 'main:main:body',
                                    'depth' => 1,
                                    'tag' => 'input',
                                    'type' => 'email',
                                    'name' => 'email',
                                    'placeholder' => 'E-Mail',
                                    'selector' => 'input[name="email"]',
                                    'attributes' => [
                                        'name' => 'email',
                                        'placeholder' => 'E-Mail',
                                        'type' => 'email',
                                    ],
                                    'x' => 120,
                                    'y' => 150,
                                    'width' => 350,
                                    'height' => 42,
                                    'visible' => true,
                                    'enabled' => true,
                                    'focused' => true,
                                    'editable' => true,
                                    'actionable' => true,
                                    'inShadowDom' => false,
                                ],
                            ],
                        ]],
                    ],
                    'cursor' => [
                        'window' => 'main',
                        'fromX' => 1,
                        'fromY' => 1,
                        'toX' => 200,
                        'toY' => 100,
                        'steps' => 7,
                        'sequence' => 3,
                        'clicked' => true,
                        'viewport' => ['width' => 800, 'height' => 600],
                    ],
                ]],
            ],
        ]);

        Livewire::test(WorkflowRunPreview::class, [
            'workflowRunId' => $run->id,
            'selectableTasks' => true,
        ])
            ->assertViewHas('resultOnly', false)
            ->assertViewHas('screenshotPanels', function (Collection $panels): bool {
                $panel = $panels->first();

                return $panels->count() === 1
                    && data_get($panel, 'windowKey') === 'main'
                    && data_get($panel, 'domTree.frames.0.nodes.1.selector') === '#submit'
                    && data_get($panel, 'cursor.toX') === 200;
            })
            ->assertSeeHtml('data-workflow-dom-inspector')
            ->assertSeeHtml('data-workflow-dom-tree')
            ->assertSeeHtml('data-workflow-dom-search')
            ->assertSeeHtml('data-workflow-screenshot-picker')
            ->assertSeeHtml('data-workflow-dom-match-overlay')
            ->assertSeeHtml('data-workflow-selector-suggestions')
            ->assertSee('Body-DOM')
            ->assertSee('Elementdaten')
            ->assertSee('Selektor-Vorschläge')
            ->assertSee('Eingaben & Buttons', false)
            ->assertSee('Für Probe übernehmen')
            ->assertSee('Kopieren')
            ->assertSee('#submit', false)
            ->assertSee('button[data-testid=\u0022save\u0022]', false)
            ->assertSee('canProbe: true', false)
            ->assertDontSee('workflow-dom-node-highlight', false)
            ->assertDontSee('must-not-leak.json');
    }

    public function test_snapshot_inspection_stays_available_while_live_probe_is_disabled_for_a_waiting_run(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Waiting DOM Preview',
            'slug' => 'waiting-dom-preview-'.Str::random(8),
            'is_active' => true,
            'settings_json' => ['dev_mode' => true],
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Browser',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'waiting',
            'context_json' => ['interactive_debug' => true],
            'result_json' => [],
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'result_json' => [
                'browserWindows' => [[
                    'key' => 'main',
                    'label' => 'Main',
                    'screenshotUrl' => 'https://example.test/live.png',
                    'domTree' => [
                        'version' => 2,
                        'rootTag' => 'body',
                        'viewport' => ['width' => 800, 'height' => 600],
                        'frames' => [[
                            'frameRef' => 'main',
                            'rootTag' => 'body',
                            'nodes' => [[
                                'nodeRef' => 'main:body',
                                'parentRef' => null,
                                'depth' => 0,
                                'tag' => 'body',
                                'selector' => 'body',
                                'x' => 0,
                                'y' => 0,
                                'width' => 800,
                                'height' => 600,
                                'visible' => true,
                            ]],
                        ]],
                    ],
                ]],
            ],
        ]);

        Livewire::test(WorkflowRunPreview::class, [
            'workflowRunId' => $run->id,
            'selectableTasks' => true,
        ])
            ->assertSeeHtml('data-workflow-dom-search')
            ->assertSeeHtml('data-workflow-screenshot-picker')
            ->assertSee('Snapshot-Analyse aktiv')
            ->assertSee('canProbe: false', false);
    }
}
