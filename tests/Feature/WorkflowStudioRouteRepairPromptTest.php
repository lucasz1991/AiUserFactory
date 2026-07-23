<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowStudio;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature R1: Der Teststart bricht bei geloeschten Routenzielen nicht mehr
 * wortlos ab, sondern oeffnet einen Bestaetigungsdialog.
 *
 * Siehe README-Abschnitt „Feature R1".
 */
class WorkflowStudioRouteRepairPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_starting_a_run_with_a_deleted_route_target_opens_the_dialog_instead_of_only_failing(): void
    {
        $this->actingAs($this->admin());

        $component = Livewire::test(WorkflowStudio::class, ['workflow' => $this->workflowWithDanglingRoute()])
            ->call('startRun');

        $component->assertSet('showRouteRepairModal', true);
        $component->assertSet('routeRepairIntent', 'start_run');

        $findings = $component->get('routeRepairFindings');
        $this->assertCount(1, $findings);
        $this->assertSame('route_task_missing', $findings[0]['code']);
        $this->assertSame('buttonlink-klicken', trim(str_replace('Karte', '', $findings[0]['current_target'])));
        $this->assertSame([], $component->get('routeRepairBlockingMessages'));
    }

    public function test_the_dialog_lists_the_old_and_the_new_target(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(WorkflowStudio::class, ['workflow' => $this->workflowWithDanglingRoute()])
            ->call('startRun')
            ->assertSee('buttonlink-klicken')
            ->assertSee('Auf Standardroute setzen und Test starten')
            ->assertSee('Erfolgsroute');
    }

    public function test_closing_the_dialog_changes_nothing(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflowWithDanglingRoute();

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->call('startRun')
            ->call('closeRouteRepairModal')
            ->assertSet('showRouteRepairModal', false)
            ->assertSet('routeRepairFindings', []);

        $this->assertSame(
            ['type' => 'card', 'card_key' => 'buttonlink-klicken'],
            data_get($workflow->fresh()->steps->first()->config_json, 'tasks.0.next'),
        );
    }

    public function test_confirming_the_dialog_repairs_the_route_and_leaves_no_missing_target(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflowWithDanglingRoute();

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->call('startRun')
            ->assertSet('showRouteRepairModal', true)
            ->call('applyRouteRepairAndStart')
            ->assertSet('showRouteRepairModal', false);

        $this->assertSame(
            ['type' => 'card', 'card_key' => 'ende', 'card' => 'ende'],
            data_get($workflow->fresh()->steps->first()->config_json, 'tasks.0.next'),
        );
    }

    public function test_a_workflow_without_dangling_routes_never_opens_the_dialog(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(WorkflowStudio::class, ['workflow' => $this->healthyWorkflow()])
            ->call('startRun')
            ->assertSet('showRouteRepairModal', false);
    }

    public function test_remaining_errors_are_shown_and_block_the_start_button(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflowWithDanglingRoute();
        $step = $workflow->steps->first();

        // Zusaetzlicher, von der Reparatur unabhaengiger Fehler: unbekannter
        // Katalog-Key. Die Reparatur darf keinen Start versprechen, der danach
        // doch scheitert.
        $config = $step->config_json;
        $config['tasks'][] = ['key' => 'kaputt', 'title' => 'Kaputt', 'task_key' => 'gibt.es.nicht'];
        $step->forceFill(['config_json' => $config])->save();

        $component = Livewire::test(WorkflowStudio::class, ['workflow' => $workflow->fresh()])
            ->call('startRun');

        $component->assertSet('showRouteRepairModal', true);
        $this->assertNotSame([], $component->get('routeRepairBlockingMessages'));
        $component->assertSee('Diese Fehler bleiben auch nach der Reparatur bestehen');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    private function workflowWithDanglingRoute(): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Studio Routen '.str()->random(6),
            'slug' => 'studio-routen-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);

        $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                [
                    'key' => 'start',
                    'task_key' => 'wait.seconds',
                    'title' => 'Erster Task',
                    'value' => 0,
                    // Zielkarte wurde geloescht.
                    'next' => ['type' => 'card', 'card_key' => 'buttonlink-klicken'],
                ],
                ['key' => 'ende', 'task_key' => 'wait.seconds', 'title' => 'Letzter Task', 'value' => 0],
            ]],
        ]);

        return $workflow->load('steps');
    }

    private function healthyWorkflow(): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Studio Heil '.str()->random(6),
            'slug' => 'studio-heil-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);

        $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'start', 'task_key' => 'wait.seconds', 'title' => 'Erster Task', 'value' => 0],
            ]],
        ]);

        return $workflow->load('steps');
    }
}
