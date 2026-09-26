<?php

namespace Tests\Unit;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowReplaySafetyService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WorkflowReplaySafetyTest extends TestCase
{
    public function test_read_only_navigation_and_observation_are_safe_to_replay(): void
    {
        $run = $this->runWithStep('completed', [
            ['key' => 'open', 'task_key' => 'browser.open_url'],
            ['key' => 'read', 'task_key' => 'browser.read_searchengine_result'],
        ], [
            ['key' => 'open', 'status' => 'success'],
            ['key' => 'read', 'status' => 'success'],
        ]);

        $analysis = app(WorkflowReplaySafetyService::class)->analyze($run);

        $this->assertTrue($analysis['safe']);
        $this->assertCount(2, $analysis['assessed']);
        $this->assertSame([], $analysis['blocked']);
    }

    public function test_click_and_submit_without_an_effect_ledger_block_automatic_replay(): void
    {
        $run = $this->runWithStep('completed', [
            ['key' => 'submit', 'task_key' => 'input.submit'],
            ['key' => 'click', 'task_key' => 'browser.click'],
        ], [
            ['key' => 'submit', 'status' => 'failed'],
            ['key' => 'click', 'status' => 'success'],
        ]);

        $analysis = app(WorkflowReplaySafetyService::class)->analyze($run);

        $this->assertFalse($analysis['safe']);
        $this->assertSame(['input.submit', 'browser.click'], array_column($analysis['blocked'], 'task_key'));
    }

    public function test_missing_task_receipt_for_an_interrupted_step_is_unknown_and_fails_closed(): void
    {
        $run = $this->runWithStep('waiting', [
            ['key' => 'send', 'task_key' => 'input.submit'],
        ], []);

        $analysis = app(WorkflowReplaySafetyService::class)->analyze($run);

        $this->assertFalse($analysis['safe']);
        $this->assertSame('unknown_or_external_effect', $analysis['blocked'][0]['classification']);
        $this->assertSame('unattributed-step-execution', $analysis['blocked'][0]['task_key']);
    }

    protected function runWithStep(string $status, array $cards, array $taskResults): WorkflowRun
    {
        $step = new WorkflowStep(['config_json' => ['tasks' => $cards]]);
        $step->id = 31;
        $stepRun = new WorkflowStepRun([
            'workflow_step_id' => 31,
            'status' => $status,
            'external_run_type' => 'workflow-task',
            'external_run_id' => $status === 'waiting' ? 'synthetic-running-task' : null,
            'result_json' => $taskResults === [] ? [] : ['tasks' => $taskResults],
        ]);
        $stepRun->setRelation('workflowStep', $step);
        $run = new WorkflowRun;
        $run->setRelation('stepRuns', new Collection([$stepRun]));

        return $run;
    }
}
