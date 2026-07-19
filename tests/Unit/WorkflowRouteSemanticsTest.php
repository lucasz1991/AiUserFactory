<?php

namespace Tests\Unit;

use App\Enums\WorkflowLogicalOutcome;
use App\Enums\WorkflowRouteDisposition;
use PHPUnit\Framework\TestCase;

class WorkflowRouteSemanticsTest extends TestCase
{
    public function test_false_if_result_is_a_logical_branch_and_not_a_technical_error(): void
    {
        $outcome = WorkflowLogicalOutcome::fromResult([
            'ok' => true,
            'status' => 'condition_not_met',
            'branchOutcome' => 'failed',
            'conditionMatched' => false,
        ], 'failed');

        $this->assertSame(WorkflowLogicalOutcome::CONDITION_FALSE, $outcome);
    }

    public function test_only_explicit_fail_route_is_a_business_failure(): void
    {
        $this->assertSame(WorkflowRouteDisposition::CONTINUE, WorkflowRouteDisposition::fromRoute([
            'type' => 'card',
            'card_key' => 'alternative-task',
        ]));
        $this->assertSame(WorkflowRouteDisposition::CONTINUE, WorkflowRouteDisposition::fromRoute([
            'type' => 'step',
            'action_key' => 'alternative-list',
        ]));
        $this->assertSame(WorkflowRouteDisposition::COMPLETE, WorkflowRouteDisposition::fromRoute([
            'type' => 'end',
            'step' => 'end',
        ]));
        $this->assertSame(WorkflowRouteDisposition::FAIL, WorkflowRouteDisposition::fromRoute([
            'type' => 'fail',
            'step' => 'fail',
        ]));
        $this->assertSame(WorkflowRouteDisposition::INVALID, WorkflowRouteDisposition::fromRoute(null));
    }
}
