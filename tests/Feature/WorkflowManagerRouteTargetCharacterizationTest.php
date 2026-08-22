<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowManagerRouteTargetCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_values_resolve_to_the_existing_persisted_route_shape(): void
    {
        [$manager, $firstStep, $secondStep] = $this->managerWithRouteFixture();

        $this->assertSame(
            ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'],
            $this->invoke($manager, 'routeTargetFromValue', 'end'),
        );
        $this->assertSame(
            ['type' => 'fail', 'step' => 'fail', 'label' => 'Fehlerroute'],
            $this->invoke($manager, 'routeTargetFromValue', 'fail'),
        );
        $this->assertSame([
            'type' => 'step',
            'action_key' => 'first-list',
            'step' => 'first-list',
            'label' => 'First list',
        ], $this->invoke($manager, 'routeTargetFromValue', 'step:first-list'));
        $this->assertSame([
            'type' => 'card',
            'action_key' => 'second-list',
            'step' => 'second-list',
            'card_key' => 'gamma',
            'card' => 'gamma',
            'label' => 'Second list / Gamma card',
        ], $this->invoke($manager, 'routeTargetFromValue', 'card:'.$secondStep->id.':gamma'));

        $this->assertNull($this->invoke($manager, 'routeTargetFromValue', 'step:missing'));
        $this->assertNull($this->invoke($manager, 'routeTargetFromValue', 'card:'.$firstStep->id.':missing'));
        $this->assertNull($this->invoke($manager, 'routeTargetFromValue', ''));
    }

    public function test_next_routes_follow_cards_then_lists_and_finish_at_the_workflow_end(): void
    {
        [$manager, $firstStep, $secondStep, $emptyStep] = $this->managerWithRouteFixture();

        $this->assertSame('beta', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $firstStep, 'alpha', null),
            'card_key',
        ));
        $this->assertSame('gamma', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $firstStep, 'beta', null),
            'card_key',
        ));
        $this->assertSame('empty-list', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $secondStep, 'gamma', null),
            'action_key',
        ));
        $this->assertSame('step', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $secondStep, 'gamma', null),
            'type',
        ));
        $this->assertSame('end', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $emptyStep, null, null),
            'type',
        ));
        $this->assertSame('beta', data_get(
            $this->invoke($manager, 'taskRouteTargetFromValue', 'next', $firstStep, null, 1),
            'card_key',
        ));
    }

    public function test_route_mutation_and_form_value_round_trip_preserve_existing_contracts(): void
    {
        [$manager, $firstStep, $secondStep] = $this->managerWithRouteFixture();
        $routes = ['success' => ['type' => 'end', 'step' => 'end']];

        $routes = $this->invoke(
            $manager,
            'setRoute',
            $routes,
            'failed',
            'card:'.$secondStep->id.':gamma',
            'Noch einmal versuchen',
            3,
        );

        $this->assertSame('gamma', data_get($routes, 'failed.card_key'));
        $this->assertSame('Noch einmal versuchen', data_get($routes, 'failed.reason'));
        $this->assertSame(3, data_get($routes, 'failed.max_attempts'));
        $this->assertSame('card:'.$secondStep->id.':gamma', $this->invoke($manager, 'routeValueFromTarget', $routes['failed']));
        $this->assertSame('step:first-list', $this->invoke($manager, 'routeValueFromTarget', [
            'type' => 'step',
            'action_key' => $firstStep->action_key,
        ]));
        $this->assertSame('end', $this->invoke($manager, 'routeValueFromTarget', ['step' => 'end']));
        $this->assertSame('fail', $this->invoke($manager, 'routeValueFromTarget', ['type' => 'fail']));
        $this->assertSame('', $this->invoke($manager, 'routeValueFromTarget', ['type' => 'card', 'step' => 'missing', 'card' => 'x']));

        $routes = $this->invoke($manager, 'setRoute', $routes, 'failed', '', '', 0);
        $this->assertArrayNotHasKey('failed', $routes);
        $this->assertArrayHasKey('success', $routes);
    }

    /** @return array{WorkflowManager, WorkflowStep, WorkflowStep, WorkflowStep} */
    private function managerWithRouteFixture(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Route characterization',
            'slug' => 'route-characterization',
            'category' => 'test',
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $firstStep = $this->step($workflow, 'First list', 'first-list', 10, [
            $this->task('alpha', 'Alpha card'),
            $this->task('beta', 'Beta card'),
        ]);
        $secondStep = $this->step($workflow, 'Second list', 'second-list', 20, [
            $this->task('gamma', 'Gamma card'),
        ]);
        $emptyStep = $this->step($workflow, 'Empty list', 'empty-list', 30, []);
        $manager = app(WorkflowManager::class);
        $manager->selectedWorkflowId = $workflow->id;

        return [$manager, $firstStep, $secondStep, $emptyStep];
    }

    private function step(Workflow $workflow, string $name, string $actionKey, int $position, array $tasks): WorkflowStep
    {
        return $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => $actionKey,
            'position' => $position,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);
    }

    /** @return array<string, mixed> */
    private function task(string $key, string $title): array
    {
        return [
            'key' => $key,
            'task_key' => 'wait.seconds',
            'title' => $title,
            'kind' => 'wait',
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/wait/seconds.cjs',
        ];
    }

    private function invoke(WorkflowManager $manager, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($manager, $method))->invoke($manager, ...$arguments);
    }
}
