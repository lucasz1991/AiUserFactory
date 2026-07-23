<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowRouteTargetAutoRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature R1: Routen mit geloeschtem Ziel sollen den Teststart nicht mehr
 * blockieren, sondern bestaetigungspflichtig auf die Standardroute fallen.
 *
 * Siehe README-Abschnitt „Feature R1".
 */
class WorkflowRouteTargetAutoRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_route_pointing_at_a_deleted_card(): void
    {
        $workflow = $this->workflowWithDanglingSuccessRoute();

        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        $this->assertCount(1, $findings);
        $this->assertSame('route_task_missing', $findings[0]['code']);
        $this->assertSame('liste-eins', $findings[0]['step']);
        $this->assertSame('start', $findings[0]['card']);
        $this->assertSame('next', $findings[0]['field']);
        $this->assertSame('Erfolgsroute', $findings[0]['field_label']);
        $this->assertSame('Karte buttonlink-klicken', $findings[0]['current_target']);
    }

    public function test_a_healthy_workflow_produces_no_findings(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Heil', 'slug' => 'heil', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'start', 'title' => 'Start', 'task_key' => 'browser.open', 'next' => ['type' => 'card', 'card_key' => 'zweite']],
                ['key' => 'zweite', 'title' => 'Zweite', 'task_key' => 'browser.close'],
            ]],
        ]);

        $this->assertSame([], app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow));
    }

    public function test_success_route_defaults_to_the_next_card_of_the_same_list(): void
    {
        $workflow = $this->workflowWithDanglingSuccessRoute();

        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        $this->assertSame(['type' => 'card', 'card_key' => 'ende', 'card' => 'ende'], $findings[0]['default_route']);
        $this->assertSame('Karte ende', $findings[0]['default_label']);
    }

    public function test_success_route_of_the_last_card_defaults_to_the_next_list(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Zwei Listen', 'slug' => 'zwei-listen', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'einzige', 'title' => 'Einzige', 'task_key' => 'browser.open', 'next' => ['type' => 'card', 'card_key' => 'geloescht']],
            ]],
        ]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste zwei',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-zwei',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'weiter', 'title' => 'Weiter', 'task_key' => 'browser.close']]],
        ]);

        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        $this->assertSame(['step' => 'next'], $findings[0]['default_route']);
        $this->assertSame('Naechste Liste', $findings[0]['default_label']);
    }

    public function test_success_route_of_the_last_card_in_the_last_list_defaults_to_end(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Ende', 'slug' => 'ende-workflow', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Einzige Liste',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'einzige-liste',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'einzige', 'title' => 'Einzige', 'task_key' => 'browser.open', 'next' => ['type' => 'card', 'card_key' => 'weg']],
            ]],
        ]);

        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        $this->assertSame(['type' => 'end'], $findings[0]['default_route']);
    }

    public function test_error_routes_default_to_an_explicit_fail(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Fehlerroute', 'slug' => 'fehlerroute', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                [
                    'key' => 'start',
                    'title' => 'Start',
                    'task_key' => 'browser.open',
                    'on_error' => ['type' => 'card', 'card_key' => 'geloescht'],
                    'status_routes' => ['timeout' => ['type' => 'card', 'card_key' => 'auch-weg']],
                ],
                ['key' => 'zweite', 'title' => 'Zweite', 'task_key' => 'browser.close'],
            ]],
        ]);

        $findings = collect(app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow))
            ->keyBy('field');

        $this->assertSame(['type' => 'fail'], $findings['on_error']['default_route']);
        $this->assertSame(['type' => 'fail'], $findings['status_routes.timeout']['default_route']);
        $this->assertSame('Workflow mit Fehler beenden', $findings['on_error']['default_label']);
    }

    public function test_it_also_finds_routes_pointing_at_a_deleted_list(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Liste weg', 'slug' => 'liste-weg', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [['key' => 'start', 'title' => 'Start', 'task_key' => 'browser.open']],
                'routes' => ['success' => ['action_key' => 'gibt-es-nicht']],
            ],
        ]);

        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        $this->assertCount(1, $findings);
        $this->assertSame('route_step_missing', $findings[0]['code']);
        $this->assertSame('routes.success', $findings[0]['field']);
        $this->assertNull($findings[0]['card']);
        $this->assertSame(['type' => 'end'], $findings[0]['default_route']);
    }

    public function test_terminal_and_next_routes_are_never_reported(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Terminal', 'slug' => 'terminal', 'is_active' => true]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'a', 'title' => 'A', 'task_key' => 'browser.open', 'next' => ['type' => 'end']],
                ['key' => 'b', 'title' => 'B', 'task_key' => 'browser.open', 'on_error' => ['type' => 'fail']],
                ['key' => 'c', 'title' => 'C', 'task_key' => 'browser.open', 'next' => ['step' => 'next']],
                ['key' => 'd', 'title' => 'D', 'task_key' => 'browser.open', 'next' => ['step' => 'end']],
            ]],
        ]);

        $this->assertSame([], app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow));
    }

    public function test_repair_writes_the_default_route_and_clears_the_validator_error(): void
    {
        $workflow = $this->workflowWithDanglingSuccessRoute();
        $validator = app(WorkflowDefinitionValidator::class);

        $before = collect($validator->validate($workflow)['diagnostics'])->pluck('code');
        $this->assertContains('route_task_missing', $before);

        $applied = app(WorkflowRouteTargetAutoRepairService::class)->repair($workflow);

        $this->assertCount(1, $applied);

        $step = $workflow->fresh()->steps->firstWhere('action_key', 'liste-eins');
        $this->assertSame(
            ['type' => 'card', 'card_key' => 'ende', 'card' => 'ende'],
            data_get($step->config_json, 'tasks.0.next'),
        );

        $after = collect($validator->validate($workflow->fresh())['diagnostics'])->pluck('code');
        $this->assertNotContains('route_task_missing', $after);
    }

    public function test_repair_is_idempotent_and_a_second_run_finds_nothing(): void
    {
        $workflow = $this->workflowWithDanglingSuccessRoute();
        $service = app(WorkflowRouteTargetAutoRepairService::class);

        $this->assertCount(1, $service->repair($workflow));
        $this->assertSame([], $service->analyze($workflow->fresh()));
        $this->assertSame([], $service->repair($workflow->fresh()));
    }

    public function test_repair_leaves_untouched_cards_alone(): void
    {
        $workflow = $this->workflowWithDanglingSuccessRoute();

        app(WorkflowRouteTargetAutoRepairService::class)->repair($workflow);

        $step = $workflow->fresh()->steps->firstWhere('action_key', 'liste-eins');
        $tasks = data_get($step->config_json, 'tasks');

        $this->assertCount(2, $tasks);
        $this->assertSame('ende', $tasks[1]['key']);
        $this->assertArrayNotHasKey('next', $tasks[1]);
    }

    private function workflowWithDanglingSuccessRoute(): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Geloeschte Karte',
            'slug' => 'geloeschte-karte',
            'is_active' => true,
        ]);

        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Liste eins',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'liste-eins',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [
                [
                    'key' => 'start',
                    'title' => 'Start',
                    'task_key' => 'browser.open',
                    // Zeigt auf eine Karte, die es nicht mehr gibt.
                    'next' => ['type' => 'card', 'card_key' => 'buttonlink-klicken'],
                ],
                ['key' => 'ende', 'title' => 'Ende', 'task_key' => 'browser.close'],
            ]],
        ]);

        return $workflow;
    }
}
