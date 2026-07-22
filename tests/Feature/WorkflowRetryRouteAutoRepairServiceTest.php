<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowRetryRouteAutoRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowRetryRouteAutoRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbounded_backward_error_routes_receive_a_default_retry_limit(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Erste Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'dfdf-10',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten-a',
                'task_key' => 'wait.seconds',
                'value' => 1,
            ]]],
        ]);
        $second = $workflow->steps()->create([
            'name' => 'Zweite Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'register-form-ausfullen-20',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten-b',
                'task_key' => 'wait.seconds',
                'value' => 1,
                // Fachliche Vorwaertsverzweigung darf nicht angefasst werden.
                'next' => ['type' => 'step', 'action_key' => 'dfdf-10', 'card_key' => 'warten-a'],
                'on_error' => ['type' => 'step', 'action_key' => 'dfdf-10'],
            ]]],
        ]);

        $before = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));
        $this->assertFalse($before['valid']);
        $this->assertContains('unbounded_backward_retry_route', collect($before['diagnostics'])->pluck('code'));

        $repaired = app(WorkflowRetryRouteAutoRepairService::class)->repair($workflow->fresh('steps'));

        $this->assertCount(1, $repaired);
        $this->assertSame('register-form-ausfullen-20', $repaired[0]['step']);
        $this->assertSame('warten-b', $repaired[0]['card']);
        $this->assertSame('on_error', $repaired[0]['field']);
        $this->assertSame('dfdf-10', $repaired[0]['target']);

        $persisted = $second->fresh()->config_json;
        $this->assertSame(
            WorkflowRetryRouteAutoRepairService::DEFAULT_MAX_ATTEMPTS,
            (int) data_get($persisted, 'tasks.0.on_error.max_attempts'),
        );
        // Die fachliche next-Route bleibt ohne Versuchslimit.
        $this->assertNull(data_get($persisted, 'tasks.0.next.max_attempts'));

        $after = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));
        $this->assertTrue($after['valid'], json_encode($after['diagnostics'], JSON_PRETTY_PRINT));
    }

    public function test_bounded_forward_and_terminal_routes_stay_untouched(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Erste Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'liste-10',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten-a',
                'task_key' => 'wait.seconds',
                'value' => 1,
                'on_error' => ['type' => 'step', 'action_key' => 'liste-20'],
            ]]],
        ]);
        $second = $workflow->steps()->create([
            'name' => 'Zweite Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'liste-20',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten-b',
                'task_key' => 'wait.seconds',
                'value' => 1,
                'on_error' => ['type' => 'step', 'action_key' => 'liste-10', 'max_attempts' => 3],
            ], [
                'key' => 'warten-c',
                'task_key' => 'wait.seconds',
                'value' => 1,
                'on_error' => ['step' => 'fail'],
            ]]],
        ]);

        $repaired = app(WorkflowRetryRouteAutoRepairService::class)->repair($workflow->fresh('steps'));

        $this->assertSame([], $repaired);
        $persisted = $second->fresh()->config_json;
        $this->assertSame(3, (int) data_get($persisted, 'tasks.0.on_error.max_attempts'));
        $this->assertNull(data_get($persisted, 'tasks.1.on_error.max_attempts'));
    }

    public function test_backward_step_level_failure_routes_are_repaired(): void
    {
        $workflow = $this->workflow();
        $workflow->steps()->create([
            'name' => 'Erste Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'liste-10',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten-a',
                'task_key' => 'wait.seconds',
                'value' => 1,
            ]]],
        ]);
        $second = $workflow->steps()->create([
            'name' => 'Zweite Liste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'liste-20',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [[
                    'key' => 'warten-b',
                    'task_key' => 'wait.seconds',
                    'value' => 1,
                ]],
                'routes' => [
                    'failed' => ['type' => 'step', 'action_key' => 'liste-10'],
                ],
            ],
        ]);

        $repaired = app(WorkflowRetryRouteAutoRepairService::class)->repair($workflow->fresh('steps'));

        $this->assertCount(1, $repaired);
        $this->assertSame('routes.failed', $repaired[0]['field']);
        $this->assertSame(
            WorkflowRetryRouteAutoRepairService::DEFAULT_MAX_ATTEMPTS,
            (int) data_get($second->fresh()->config_json, 'routes.failed.max_attempts'),
        );

        $after = app(WorkflowDefinitionValidator::class)->validate($workflow->fresh('steps'));
        $this->assertTrue($after['valid'], json_encode($after['diagnostics'], JSON_PRETTY_PRINT));
    }

    private function workflow(): Workflow
    {
        return Workflow::query()->create([
            'name' => 'AutoRepair '.str()->random(6),
            'slug' => 'auto-repair-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }
}
