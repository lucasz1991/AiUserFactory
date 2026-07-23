<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskOrderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature R2: Wer eine Karte loescht, erfaehrt sofort, dass Verzweigungen jetzt
 * ins Leere zeigen — statt es erst beim naechsten Teststart zu merken.
 *
 * Siehe README-Abschnitt „Feature R2".
 */
class WorkflowTaskRemovalRouteWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_remove_task_returns_the_removed_key(): void
    {
        $workflow = $this->workflow();
        $step = $workflow->steps->first();

        $removed = app(WorkflowTaskOrderingService::class)->removeTask($step, 'ziel');

        $this->assertSame(['ziel'], $removed);
    }

    public function test_remove_task_also_returns_the_coupled_loop_partner(): void
    {
        $workflow = Workflow::query()->create($this->workflowAttributes('loop'));
        $step = $workflow->steps()->create([
            'name' => 'Schleife',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'schleife',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'loop-start', 'task_key' => 'loop.for_each_element', 'title' => 'Start', 'loop_pair_id' => 'paar-1'],
                ['key' => 'loop-ende', 'task_key' => 'loop.end', 'title' => 'Ende', 'loop_pair_id' => 'paar-1'],
                ['key' => 'danach', 'task_key' => 'wait.seconds', 'title' => 'Danach', 'value' => 0],
            ]],
        ]);

        $removed = app(WorkflowTaskOrderingService::class)->removeTask($step, 'loop-start');

        sort($removed);
        $this->assertSame(['loop-ende', 'loop-start'], $removed);
        $this->assertCount(1, data_get($step->fresh()->config_json, 'tasks'));
    }

    public function test_removing_a_referenced_card_warns_about_the_dangling_route(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflow();

        $component = Livewire::test(WorkflowManager::class)
            ->set('selectedWorkflowId', $workflow->id)
            ->call('removeTaskCard', $workflow->steps->first()->id, 'ziel');

        $warning = (string) $component->get('lastRemovalRouteWarning');

        $this->assertStringContainsString('ins Leere', $warning);
        $this->assertStringContainsString('1 Verzweigung ', $warning, 'Singular erwartet.');
        // Die ausloesende Karte wird benannt, damit man sie sofort findet.
        $this->assertStringContainsString('Start', $warning);
    }

    public function test_two_dangling_routes_are_reported_in_plural(): void
    {
        $this->actingAs($this->admin());
        $workflow = Workflow::query()->create($this->workflowAttributes('plural'));
        $step = $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'eins', 'task_key' => 'wait.seconds', 'title' => 'Eins', 'value' => 0, 'next' => ['type' => 'card', 'card_key' => 'ziel']],
                ['key' => 'zwei', 'task_key' => 'wait.seconds', 'title' => 'Zwei', 'value' => 0, 'next' => ['type' => 'card', 'card_key' => 'ziel']],
                ['key' => 'ziel', 'task_key' => 'wait.seconds', 'title' => 'Ziel', 'value' => 0],
            ]],
        ]);

        $component = Livewire::test(WorkflowManager::class)
            ->set('selectedWorkflowId', $workflow->id)
            ->call('removeTaskCard', $step->id, 'ziel');

        $this->assertStringContainsString('2 Verzweigungen', (string) $component->get('lastRemovalRouteWarning'));
    }

    public function test_the_manager_view_renders_a_warning_channel(): void
    {
        // Der Hinweis wird per session()->flash('warning') gemeldet; Livewire-
        // Komponententests koennen Flash-Meldungen nicht zuverlaessig lesen,
        // deshalb wird der Kanal hier strukturell geprueft.
        $markup = file_get_contents(resource_path('views/livewire/admin/network/workflow-manager.blade.php'));

        $this->assertStringContainsString("session()->has('warning')", $markup);
        $this->assertStringContainsString("session('warning')", $markup);
    }

    public function test_removing_an_unreferenced_card_stays_a_plain_success(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflow();

        Livewire::test(WorkflowManager::class)
            ->set('selectedWorkflowId', $workflow->id)
            ->call('removeTaskCard', $workflow->steps->first()->id, 'unbeteiligt')
            ->assertSet('lastRemovalRouteWarning', '');

        // Die uebrigen Karten bleiben unveraendert erhalten.
        $tasks = data_get($workflow->fresh()->steps->first()->config_json, 'tasks');
        $this->assertSame(['start', 'ziel'], collect($tasks)->pluck('key')->all());
    }

    public function test_the_warning_does_not_rewrite_any_route(): void
    {
        $this->actingAs($this->admin());
        $workflow = $this->workflow();

        Livewire::test(WorkflowManager::class)
            ->set('selectedWorkflowId', $workflow->id)
            ->call('removeTaskCard', $workflow->steps->first()->id, 'ziel');

        // R2 warnt nur. Das Umschreiben bleibt der bestaetigten Reparatur aus R1
        // vorbehalten — sonst waere es genau der stille Strukturverlust, den R1
        // vermeiden soll.
        $this->assertSame(
            ['type' => 'card', 'card_key' => 'ziel'],
            data_get($workflow->fresh()->steps->first()->config_json, 'tasks.0.next'),
        );
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    /** @return array<string,mixed> */
    private function workflowAttributes(string $suffix): array
    {
        return [
            'name' => 'Manager '.$suffix.' '.str()->random(6),
            'slug' => 'manager-'.$suffix.'-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ];
    }

    private function workflow(): Workflow
    {
        $workflow = Workflow::query()->create($this->workflowAttributes('routen'));

        $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'start', 'task_key' => 'wait.seconds', 'title' => 'Start', 'value' => 0, 'next' => ['type' => 'card', 'card_key' => 'ziel']],
                ['key' => 'ziel', 'task_key' => 'wait.seconds', 'title' => 'Ziel', 'value' => 0],
                ['key' => 'unbeteiligt', 'task_key' => 'wait.seconds', 'title' => 'Unbeteiligt', 'value' => 0],
            ]],
        ]);

        return $workflow->load('steps');
    }
}
