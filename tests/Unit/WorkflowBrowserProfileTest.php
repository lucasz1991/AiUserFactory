<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskRunner;
use ReflectionClass;
use Tests\TestCase;

class WorkflowBrowserProfileTest extends TestCase
{
    public function test_same_mailbox_uses_same_profile_across_workflow_runs(): void
    {
        $firstRun = $this->workflowRun('11111111-1111-1111-1111-111111111111');
        $secondRun = $this->workflowRun('22222222-2222-2222-2222-222222222222');
        $step = $this->webmailStep('person');
        $context = ['account' => ['email' => 'Person@Example.test']];

        $this->assertSame(
            $this->profileKey($firstRun, $step, $context),
            $this->profileKey($secondRun, $step, $context),
        );
    }

    public function test_different_mailboxes_use_separate_profiles(): void
    {
        $run = $this->workflowRun('11111111-1111-1111-1111-111111111111');
        $step = $this->webmailStep('person');

        $this->assertNotSame(
            $this->profileKey($run, $step, ['account' => ['email' => 'first@example.test']]),
            $this->profileKey($run, $step, ['account' => ['email' => 'second@example.test']]),
        );
    }

    public function test_workflow_can_opt_out_of_persistent_browser_profile(): void
    {
        $firstRun = $this->workflowRun('11111111-1111-1111-1111-111111111111', false);
        $secondRun = $this->workflowRun('22222222-2222-2222-2222-222222222222', false);
        $step = $this->webmailStep('person');
        $context = ['account' => ['email' => 'person@example.test']];

        $this->assertNotSame(
            $this->profileKey($firstRun, $step, $context),
            $this->profileKey($secondRun, $step, $context),
        );
    }

    protected function workflowRun(string $uuid, bool $persistent = true): WorkflowRun
    {
        $workflow = new Workflow([
            'settings_json' => ['persistent_browser_profile' => $persistent],
        ]);
        $workflow->id = 4;
        $run = new WorkflowRun([
            'run_uuid' => $uuid,
            'workflow_id' => 4,
            'context_json' => ['person_id' => 12],
        ]);
        $run->setRelation('workflow', $workflow);

        return $run;
    }

    protected function webmailStep(string $mailboxSource): WorkflowStep
    {
        return new WorkflowStep([
            'config_json' => [
                'tasks' => [[
                    'task_key' => 'browser.open_webmail_session',
                    'mailbox_source' => $mailboxSource,
                ]],
            ],
        ]);
    }

    protected function profileKey(WorkflowRun $run, WorkflowStep $step, array $context): string
    {
        $reflection = new ReflectionClass(WorkflowTaskRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod('workflowBrowserProfileKey')->invoke($runner, $run, $step, $context);
    }
}
