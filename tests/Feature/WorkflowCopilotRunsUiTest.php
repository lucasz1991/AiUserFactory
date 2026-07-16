<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowCopilotRuns;
use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Models\Workflow;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowCopilotRunsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_run_module_lists_global_and_workflow_scoped_sessions_with_redacted_details(): void
    {
        $firstWorkflow = $this->workflow('copilot-runs-first');
        $secondWorkflow = $this->workflow('copilot-runs-second');
        $service = app(WorkflowCopilotSessionService::class);
        $secret = 'session-secret-value';
        $first = $service->start($firstWorkflow, [
            'goal' => 'Ersten Workflow optimieren.',
            'workflow_inputs' => ['password' => $secret, 'query' => 'sichtbar'],
            'budget' => ['max_cost_usd' => 2.5],
        ]);
        $first->forceFill([
            'usage_json' => array_replace($first->usage_json, [
                'ai_requests' => 3,
                'total_tokens' => 1250,
                'cost_usd' => 0.123456,
            ]),
        ])->save();
        $service->appendEvent(
            $first->fresh(),
            'tool.result',
            'Verarbeiteter Wert: '.$secret,
            ['password' => $secret, 'result' => 'ok'],
        );
        $second = $service->start($secondWorkflow, [
            'goal' => 'Zweiten Workflow optimieren.',
        ]);

        Livewire::test(WorkflowCopilotRuns::class)
            ->assertSee($firstWorkflow->name)
            ->assertSee($secondWorkflow->name)
            ->call('selectSession', $first->id)
            ->assertSee('$0.123456')
            ->assertSee('1.250')
            ->call('setActiveTab', 'logs')
            ->assertSee('Verarbeiteter Wert: [redacted]')
            ->assertDontSee($secret)
            ->call('setActiveTab', 'data')
            ->assertSee('workflow_input_keys')
            ->assertDontSee($secret);

        Livewire::test(WorkflowCopilotRuns::class, ['workflowId' => $firstWorkflow->id])
            ->assertSee($firstWorkflow->name)
            ->assertDontSee($secondWorkflow->name)
            ->call('selectSession', $second->id)
            ->assertSet('selectedSessionId', $first->id);

        Livewire::test(WorkflowsIndex::class)
            ->set('showCopilotRunsModal', true)
            ->assertSee('Copilot-Optimierungslaeufe aller Workflows');

        Livewire::test(WorkflowManager::class, ['workflow' => $firstWorkflow])
            ->set('showCopilotRunsModal', true)
            ->assertSee('Copilot-Optimierungslaeufe dieses Workflows');
    }

    private function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'subcategory' => '',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }
}
