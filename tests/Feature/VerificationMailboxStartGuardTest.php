<?php

namespace Tests\Feature;

use App\Exceptions\VerificationMailboxUnavailableException;
use App\Jobs\RunWorkflowJob;
use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Workflows\VerificationMailboxStartGuard;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VerificationMailboxStartGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        Queue::fake();
        $taskRunner = Mockery::mock(WorkflowTaskRunner::class);
        $taskRunner->shouldIgnoreMissing();
        $this->app->instance(WorkflowTaskRunner::class, $taskRunner);
    }

    public function test_a_registration_gets_a_free_mailbox_of_another_person_assigned(): void
    {
        $sophie = $this->person('Sophie', 'Neumann');
        $this->account($sophie, 'sophie@gmx.de');
        $this->account($this->person('Jonas', 'Keller'), 'muellerjosie@gmx.de', consumed: true);
        $free = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), ['person_id' => $sophie->id], 'workflow-studio');

        $this->assertSame($free->id, data_get($run->fresh()->context_json, 'verification_mailbox_guard.account_id'));
        $this->assertSame('anna@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.email'));
    }

    public function test_without_a_free_mailbox_the_registration_is_refused_before_any_run_exists(): void
    {
        $this->account($this->person('Jonas', 'Keller'), 'muellerjosie@gmx.de', consumed: true);

        try {
            app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [], 'workflow-studio');
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('kein freies GMX-Postfach', $exception->getMessage());
            $this->assertStringContainsString('Alle 1 GMX-Konten', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowRun::query()->count());
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_two_registrations_never_share_a_mailbox(): void
    {
        $first = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');
        $second = $this->account($this->person('Noah', 'Wolf'), 'noah@gmx.de');
        $workflow = $this->registrationWorkflow();

        $runA = app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');
        $runB = app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');

        $this->assertSame($first->id, data_get($runA->fresh()->context_json, 'verification_mailbox_guard.account_id'));
        $this->assertSame($second->id, data_get($runB->fresh()->context_json, 'verification_mailbox_guard.account_id'));

        $this->expectException(VerificationMailboxUnavailableException::class);
        app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');
    }

    public function test_the_runtime_context_hands_the_assigned_mailbox_to_the_workflow(): void
    {
        $account = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');
        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [], 'workflow-studio');
        $step = $run->workflow->steps()->first();
        $stepRun = $run->stepRuns()->create(['workflow_step_id' => $step->id, 'status' => 'queued', 'logs_json' => [], 'result_json' => []]);
        $context = (new \ReflectionMethod(WorkflowExecutionService::class, 'workflowRuntimeContext'))
            ->invoke(app(WorkflowExecutionService::class), $run->fresh(), $step, $stepRun);

        $this->assertSame($account->id, $context['verificationMailbox']['id']);
        $this->assertSame('anna@gmx.de', $context['veri-account']['email']);
        $this->assertSame('secret', $context['verificationMailbox']['password']);
        $this->assertSame('gmx', $context['verificationMailbox']['provider']);
    }

    public function test_workflows_that_only_read_a_mailbox_are_not_assigned_or_blocked(): void
    {
        $this->account($this->person('Jonas', 'Keller'), 'muellerjosie@gmx.de', consumed: true);

        $run = app(WorkflowExecutionService::class)->start($this->workflowWithTasks([[
            'key' => 'open-webmail', 'task_key' => 'wait.seconds', 'title' => 'Webmail oeffnen', 'value' => 0,
            'mailbox_source' => 'verification',
        ]]), [], 'workflow-studio');

        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_guard'));
    }

    public function test_a_single_studio_task_may_run_without_a_free_mailbox(): void
    {
        $this->account($this->person('Jonas', 'Keller'), 'muellerjosie@gmx.de', consumed: true);

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), ['studio_single_task' => true], 'workflow-studio');

        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_guard'));
    }

    public function test_start_drops_mailbox_markers_inherited_from_a_source_run(): void
    {
        $account = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [
            'verification_mailbox_guard' => ['account_id' => 999, 'email' => 'old@gmx.de'],
            'verification_mailbox_consumed' => ['account_id' => 999, 'email' => 'old@gmx.de', 'reason' => 'registered'],
        ], 'workflow-studio');

        $this->assertSame($account->id, data_get($run->fresh()->context_json, 'verification_mailbox_guard.account_id'));
        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_consumed'));
    }

    public function test_detection_covers_routes_embedded_workflows_settings_and_ignores_disabled_lists(): void
    {
        $guard = app(VerificationMailboxStartGuard::class);
        $flag = ['type' => 'fail', 'step' => 'fail', 'consume_verification_mailbox' => 'true'];

        $this->assertTrue($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'on_error' => $flag]])));
        $this->assertTrue($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'status_routes' => ['timeout' => $flag]]])));
        $this->assertFalse($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'next' => ['type' => 'fail', 'consume_verification_mailbox' => false]]])));

        $disabled = $this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'next' => $flag]]);
        $disabled->steps()->update(['is_enabled' => false]);
        $this->assertFalse($guard->requiresUnusedAddress($disabled->fresh()));

        $child = $this->workflowWithTasks([]);
        $child->forceFill(['settings_json' => ['requires_unused_verification_mailbox' => true]])->save();
        $parent = $this->workflowWithTasks([[
            'key' => 'include-child', 'task_key' => 'workflow.include.'.$child->id, 'runner' => 'workflow', 'workflow_id' => $child->id,
        ]]);
        $this->assertTrue($guard->requiresUnusedAddress($parent));

        $first = $this->workflowWithTasks([]);
        $second = $this->workflowWithTasks([[
            'key' => 'back', 'task_key' => 'workflow.include.'.$first->id, 'runner' => 'workflow', 'workflow_id' => $first->id,
        ]]);
        $first->steps()->first()->forceFill(['config_json' => ['tasks' => [[
            'key' => 'forth', 'task_key' => 'workflow.include.'.$second->id, 'runner' => 'workflow', 'workflow_id' => $second->id,
        ]]]])->save();
        $this->assertFalse($guard->requiresUnusedAddress($first->fresh()));
    }

    public function test_a_run_continues_only_while_its_mailbox_is_unburnt_or_burnt_by_itself(): void
    {
        $guard = app(VerificationMailboxStartGuard::class);
        $account = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');
        $run = $this->runOf($this->registrationWorkflow(), 'paused', ['verification_mailbox_guard' => ['account_id' => $account->id, 'email' => 'anna@gmx.de']]);

        $this->assertNull($guard->continuationBlockReason($run));

        $account->forceFill(['verification_consumed_at' => now(), 'verification_consumption' => [['reason' => 'registered', 'workflow_run_id' => 999]]])->save();
        $this->assertStringContainsString('von einem anderen Lauf verbraucht', (string) $guard->continuationBlockReason($run->fresh()));
        $this->assertStringStartsWith('Naechste Liste nicht gestartet:', (string) $guard->continuationBlockReason($run->fresh(), 'list'));

        $account->forceFill(['verification_consumption' => [['reason' => 'registered', 'workflow_run_id' => $run->id]]])->save();
        $this->assertNull($guard->continuationBlockReason($run->fresh()));

        $account->delete();
        $this->assertStringContainsString('existiert nicht mehr', (string) $guard->continuationBlockReason($run->fresh()));
    }

    public function test_a_run_does_not_start_its_next_list_after_another_run_burnt_its_mailbox(): void
    {
        $account = $this->account($this->person('Anna', 'Berg'), 'anna@gmx.de');
        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [], 'workflow-studio');
        $account->forceFill(['verification_consumed_at' => now(), 'verification_consumption' => [['reason' => 'registered', 'workflow_run_id' => 999]]])->save();

        app(WorkflowExecutionService::class)->advance($run->id);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringStartsWith('Naechste Liste nicht gestartet: Dieser Lauf wurde mit dem Verifizierungs-Postfach anna@gmx.de gestartet', (string) $run->error_message);
    }

    public function test_the_direct_registration_run_takes_its_recovery_address_from_the_pool(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['providers'][0]['registration_url'] = 'https://account.proton.me/mail/signup';
        $runner->saveSettings($settings);

        try {
            $runner->start(['displayName' => 'Test', 'desiredEmail' => 'test@proton.me', 'accountUsername' => 'test']);
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('Bei keiner anderen Person', $exception->getMessage());
        }
    }

    private function person(string $first, string $last): Person
    {
        return Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => strtolower($first).'-'.str()->random(4),
            'profile_label' => $first,
            'person_first_name' => $first,
            'person_last_name' => $last,
        ]);
    }

    private function account(Person $person, string $email, bool $consumed = false): PersonEmailAccount
    {
        return PersonEmailAccount::query()->create([
            'person_id' => $person->id,
            'email' => $email,
            'provider' => 'gmx',
            'username' => $email,
            'password_encrypted' => Crypt::encryptString('secret'),
            'is_primary' => true,
            'verification_consumed_at' => $consumed ? now() : null,
            'verification_consumption' => $consumed ? [['reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => now()->toIso8601String()]] : null,
        ]);
    }

    private function runOf(Workflow $workflow, string $status, array $context = []): WorkflowRun
    {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => $status,
            'started_at' => now(),
            'context_json' => ['execution_target' => 'system', ...$context],
            'result_json' => [],
        ]);
    }

    private function registrationWorkflow(): Workflow
    {
        return $this->workflowWithTasks([
            ['key' => 'enter-code', 'task_key' => 'wait.seconds', 'title' => 'Code eingeben', 'value' => 0],
            [
                'key' => 'proton-rejected', 'task_key' => 'wait.seconds', 'title' => 'IF Proton lehnt ab', 'value' => 0,
                'next' => ['type' => 'fail', 'step' => 'fail', 'message' => 'Proton hat abgelehnt.', 'consume_verification_mailbox' => true],
            ],
        ]);
    }

    private function workflowWithTasks(array $tasks): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Guard '.str()->random(6),
            'slug' => 'guard-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $workflow->steps()->create([
            'name' => 'Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);

        return $workflow;
    }
}
