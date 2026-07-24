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
                        'capturedAt' => '2026-07-24T12:00:00.000Z',
                        'viewport' => ['width' => 800, 'height' => 600, 'deviceScaleFactor' => 1],
                        'frames' => [[
                            'frameRef' => 'main',
                            'name' => 'main',
                            'nodes' => [[
                                'nodeRef' => 'main:main:submit',
                                'parentRef' => null,
                                'depth' => 0,
                                'tag' => 'button',
                                'id' => 'submit',
                                'className' => 'primary action',
                                'text' => 'Absenden',
                                'selector' => '#submit',
                                'x' => 120,
                                'y' => 80,
                                'width' => 160,
                                'height' => 40,
                                'visible' => true,
                                'enabled' => true,
                                'inShadowDom' => false,
                            ]],
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
                    && data_get($panel, 'domTree.frames.0.nodes.0.selector') === '#submit'
                    && data_get($panel, 'cursor.toX') === 200;
            })
            ->assertSeeHtml('data-workflow-dom-inspector')
            ->assertSeeHtml('data-workflow-dom-tree')
            ->assertSee('DOM-Inspektor')
            ->assertSee('Als Selektor verwenden')
            ->assertSee('Im Browser markieren')
            ->assertSee('#submit', false)
            ->assertDontSee('must-not-leak.json');
    }
}
