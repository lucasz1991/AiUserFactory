<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowExecutionRouteDecisionCharacterizationTest extends TestCase
{
    public function test_result_outcome_precedence_and_legacy_fallbacks_remain_stable(): void
    {
        $outcome = $this->method('resultOutcome');
        $service = $this->executionService();

        $cases = [
            'requested route' => [
                'result' => [
                    'routeRequested' => true,
                    'routeOutcome' => ' TIMEOUT ',
                    'normalized_result' => [
                        'technical_status' => 'success',
                        'business_status' => 'success',
                    ],
                    'ok' => true,
                ],
                'expected' => 'timeout',
            ],
            'normalized timeout' => [
                'result' => ['normalized_result' => ['technical_status' => 'timeout']],
                'expected' => 'timeout',
            ],
            'normalized cancellation' => [
                'result' => ['normalized_result' => ['technical_status' => 'cancelled']],
                'expected' => 'failed',
            ],
            'normalized business failure' => [
                'result' => ['normalized_result' => ['business_status' => 'failed']],
                'expected' => 'failed',
            ],
            'normalized unknown business result' => [
                'result' => ['normalized_result' => ['business_status' => 'unknown']],
                'expected' => 'partial',
            ],
            'normalized success takes precedence over legacy ok' => [
                'result' => [
                    'normalized_result' => [
                        'technical_status' => 'success',
                        'business_status' => 'success',
                    ],
                    'ok' => false,
                ],
                'expected' => 'success',
            ],
            'legacy timeout' => [
                'result' => ['statusLevel' => 'timeout', 'ok' => true],
                'expected' => 'timeout',
            ],
            'legacy failure' => [
                'result' => ['statusLevel' => 'success', 'ok' => false],
                'expected' => 'failed',
            ],
            'legacy warning' => [
                'result' => ['statusLevel' => 'warning', 'ok' => true],
                'expected' => 'partial',
            ],
            'legacy success' => [
                'result' => ['ok' => true],
                'expected' => 'success',
            ],
        ];

        foreach ($cases as $label => $case) {
            $this->assertSame(
                $case['expected'],
                $outcome->invoke($service, $case['result']),
                $label,
            );
        }
    }

    public function test_routes_are_normalized_without_discarding_route_metadata(): void
    {
        $step = $this->step('source-list', [
            'routes' => [
                'success' => [
                    'step' => 'next',
                    'card' => 'next-card',
                    'max_attempts' => 4,
                ],
                'default' => [
                    'type' => 'step',
                    'action_key' => 'fallback-list',
                    'label' => 'Fallback',
                ],
            ],
        ]);
        $service = $this->executionService();
        $routeForOutcome = $this->method('routeForOutcome');

        $success = $routeForOutcome->invoke($service, $step, 'success');

        $this->assertSame([
            'step' => 'source-list',
            'card' => 'next-card',
            'max_attempts' => 4,
            'action_key' => 'source-list',
            'card_key' => 'next-card',
            'type' => 'card',
        ], $success);
        $this->assertSame([
            'type' => 'step',
            'action_key' => 'fallback-list',
            'label' => 'Fallback',
            'step' => 'fallback-list',
        ], $routeForOutcome->invoke($service, $step, 'partial'));
        $this->assertTrue($this->method('hasRouteForOutcome')->invoke($service, $step, 'timeout'));

        $stepWithoutRoutes = $this->step('empty-list');
        $this->assertNull($routeForOutcome->invoke($service, $stepWithoutRoutes, 'success'));
        $this->assertFalse($this->method('hasRouteForOutcome')->invoke($service, $stepWithoutRoutes, 'success'));
    }

    public function test_task_and_embedded_workflow_results_keep_their_existing_routing_rules(): void
    {
        $step = $this->step('source-list', [
            'tasks' => [
                [
                    'key' => 'source-task',
                    'next' => ['type' => 'card', 'card_key' => 'success-target'],
                    'on_error' => [
                        'type' => 'card',
                        'card_key' => 'failure-target',
                        'max_attempts' => 2,
                    ],
                ],
                ['key' => 'terminal-task'],
                [
                    'key' => 'embedded-workflow',
                    'on_error' => ['type' => 'card', 'card' => 'embedded-failure'],
                ],
            ],
            'routes' => [
                'default' => ['type' => 'step', 'action_key' => 'fallback-list'],
            ],
        ]);
        $routeForResult = $this->method('routeForResult');
        $service = $this->executionService();

        $dynamic = $routeForResult->invoke($service, $step, 'success', [
            'route_requested' => true,
            'route_target_key' => 'dynamic-target',
            'completed_task_key' => 'source-task',
        ]);
        $this->assertSame('dynamic-target', $dynamic['card_key']);
        $this->assertSame('source-task', $dynamic['_source_card_key']);
        $this->assertSame('source-list', $dynamic['action_key']);

        $success = $routeForResult->invoke($service, $step, 'success', [
            'routeRequested' => true,
            'completedTaskKey' => 'source-task',
        ]);
        $this->assertSame('success-target', $success['card_key']);
        $this->assertSame('source-task', $success['_source_card_key']);

        $failure = $routeForResult->invoke($service, $step, 'failed', [
            'routeRequested' => true,
            'completedTaskKey' => 'source-task',
        ]);
        $this->assertSame('failure-target', $failure['card_key']);
        $this->assertSame(2, $failure['max_attempts']);

        $this->assertNull($routeForResult->invoke($service, $step, 'failed', [
            'routeRequested' => true,
            'failedTaskKey' => 'terminal-task',
        ]));

        $embeddedFailure = $routeForResult->invoke($service, $step, 'failed', [
            'failedTaskKey' => 'workflow-boundary-child',
            'tasks' => [[
                'key' => 'workflow-boundary-child',
                'parent_task_key' => 'embedded-workflow',
                'status' => 'failed',
            ]],
        ]);
        $this->assertSame('embedded-failure', $embeddedFailure['card_key']);
        $this->assertSame('embedded-workflow', $embeddedFailure['_source_card_key']);

        $fallback = $routeForResult->invoke($service, $step, 'partial', ['ok' => true]);
        $this->assertSame('fallback-list', $fallback['action_key']);
    }

    public function test_only_executable_routes_continue_and_linear_fallback_skips_disabled_steps(): void
    {
        $service = $this->executionService();
        $continuable = $this->method('isContinuableFailureRoute');

        $this->assertTrue($continuable->invoke($service, [
            'type' => 'card',
            'card_key' => 'retry-target',
        ]));
        $this->assertTrue($continuable->invoke($service, [
            'type' => 'step',
            'action_key' => 'next-list',
        ]));
        $this->assertFalse($continuable->invoke($service, null));
        $this->assertFalse($continuable->invoke($service, ['type' => 'end', 'step' => 'end']));
        $this->assertFalse($continuable->invoke($service, ['type' => 'fail', 'step' => 'fail']));
        $this->assertFalse($continuable->invoke($service, ['type' => 'card', 'card_key' => '']));

        $first = $this->step('first-list', id: 11, name: 'First');
        $disabled = $this->step('disabled-list', id: 12, name: 'Disabled', enabled: false);
        $last = $this->step('last-list', id: 13, name: 'Last');
        $run = $this->runWithSteps([$first, $disabled, $last]);
        $linear = $this->method('linearRouteAfterStep');

        $this->assertSame([
            'type' => 'step',
            'action_key' => 'last-list',
            'label' => 'Last',
        ], $linear->invoke($service, $run, $first, 'success'));
        $this->assertSame([
            'type' => 'end',
            'label' => 'Workflow abschliessen',
        ], $linear->invoke($service, $run, $last, 'partial'));
        $this->assertSame([
            'type' => 'end',
            'label' => 'Kein naechster Schritt',
        ], $linear->invoke($service, $run, $disabled, 'success'));
        $this->assertSame([
            'type' => 'fail',
            'label' => 'Fehler ohne explizite Route',
        ], $linear->invoke($service, $run, $first, 'failed'));
    }

    private function executionService(): WorkflowExecutionService
    {
        return (new ReflectionClass(WorkflowExecutionService::class))->newInstanceWithoutConstructor();
    }

    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(WorkflowExecutionService::class, $name);
    }

    private function step(
        string $actionKey,
        array $config = [],
        int $id = 1,
        string $name = 'Source',
        bool $enabled = true,
    ): WorkflowStep {
        return (new WorkflowStep)->forceFill([
            'id' => $id,
            'name' => $name,
            'action_key' => $actionKey,
            'is_enabled' => $enabled,
            'config_json' => $config,
        ]);
    }

    /** @param  array<int, WorkflowStep>  $steps */
    private function runWithSteps(array $steps): WorkflowRun
    {
        $workflow = (new Workflow)->forceFill(['id' => 1]);
        $workflow->setRelation('steps', new Collection($steps));

        $run = (new WorkflowRun)->forceFill(['id' => 1, 'workflow_id' => 1]);
        $run->setRelation('workflow', $workflow);

        return $run;
    }
}
