<?php

namespace Tests\Feature;

use App\Exceptions\VerificationMailboxUnavailableException;
use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Workflows\VerificationMailboxStartGuard;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VerificationMailboxStartGuardTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $taskRunner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        Queue::fake();
        // Der echte Runner loeschte Profilverzeichnisse unter storage/.
        $this->taskRunner = Mockery::mock(WorkflowTaskRunner::class);
        $this->taskRunner->shouldIgnoreMissing();
        $this->app->instance(WorkflowTaskRunner::class, $this->taskRunner);
    }

    public function test_a_registration_with_a_burnt_address_and_no_reserve_is_refused_before_any_run_exists(): void
    {
        $this->seedMailbox('muellerjosie@gmx.de', consumed: [
            ['email' => 'muellerjosie@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 639, 'consumed_at' => '2026-11-16T05:58:10+00:00'],
            ['email' => 'muellerjosie@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
        ]);
        $workflow = $this->registrationWorkflow();

        try {
            app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('muellerjosie@gmx.de', $exception->getMessage());
            $this->assertStringContainsString('Lauf #641', $exception->getMessage());
            $this->assertStringContainsString('Lauf #639', $exception->getMessage());
            $this->assertStringContainsString('Einstellungen > Mail-Registrierung', $exception->getMessage());
        }

        $this->assertSame(0, WorkflowRun::query()->count());
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_a_reserve_address_replaces_a_burnt_one_before_the_run_starts(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [
            ['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641],
        ], reserve: [
            ['email' => 'next@gmx.de', 'provider' => 'gmx', 'password_encrypted' => Crypt::encryptString('n')],
        ]);
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();
        $workflow = $this->registrationWorkflow();

        $run = app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');

        $settings = app(MailAccountRegistrationRunner::class)->settings();
        $this->assertSame('next@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame([], $settings['verification_mailbox_reserve']);
        $this->assertCount(1, $settings['verification_mailbox_consumed']);
        $this->assertSame('next@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.email'));
        $this->assertSame('used@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.promoted_from'));
    }

    public function test_an_unused_address_starts_normally_and_is_stamped_on_the_run(): void
    {
        $this->seedMailbox('fresh@gmx.de');
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [], 'workflow-studio');

        $this->assertSame('fresh@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.email'));
        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_guard.promoted_from'));
    }

    public function test_workflows_that_only_read_the_mailbox_are_not_blocked(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]]);
        $before = app(MailAccountRegistrationRunner::class)->settings();

        $run = app(WorkflowExecutionService::class)->start($this->workflowWithTasks([[
            'key' => 'open-webmail', 'task_key' => 'wait.seconds', 'title' => 'Webmail oeffnen', 'value' => 0,
            'mailbox_source' => 'verification',
            'next' => ['type' => 'fail', 'step' => 'fail', 'message' => 'Postfach nicht erreichbar.'],
        ]]), [], 'workflow-studio');

        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_guard'));
        $this->assertSame($before, app(MailAccountRegistrationRunner::class)->settings());
    }

    public function test_an_embedded_registration_guards_the_parent_and_cycles_are_safe(): void
    {
        $guard = app(VerificationMailboxStartGuard::class);
        $child = $this->registrationWorkflow();
        $parent = $this->workflowWithTasks([[
            'key' => 'include-registration', 'task_key' => 'workflow.include.'.$child->id, 'title' => 'Registrierung',
            'runner' => 'workflow', 'workflow_id' => $child->id,
        ]]);

        $this->assertTrue($guard->requiresUnusedAddress($parent));

        $first = $this->workflowWithTasks([]);
        $second = $this->workflowWithTasks([[
            'key' => 'back', 'task_key' => 'workflow.include.'.$first->id, 'title' => 'Zurueck', 'runner' => 'workflow', 'workflow_id' => $first->id,
        ]]);
        $first->steps()->first()->forceFill(['config_json' => ['tasks' => [[
            'key' => 'forth', 'task_key' => 'workflow.include.'.$second->id, 'title' => 'Hin', 'runner' => 'workflow', 'workflow_id' => $second->id,
        ]]]])->save();

        $this->assertFalse($guard->requiresUnusedAddress($first->fresh()));
    }

    public function test_detection_covers_error_and_status_routes_settings_and_ignores_disabled_lists(): void
    {
        $guard = app(VerificationMailboxStartGuard::class);
        $flag = ['type' => 'fail', 'step' => 'fail', 'consume_verification_mailbox' => 'true'];

        $this->assertTrue($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'on_error' => $flag]])));
        $this->assertTrue($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'status_routes' => ['timeout' => $flag]]])));
        $this->assertFalse($guard->requiresUnusedAddress($this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'next' => ['type' => 'fail', 'consume_verification_mailbox' => false]]])));

        $disabled = $this->workflowWithTasks([['key' => 'a', 'task_key' => 'wait.seconds', 'next' => $flag]]);
        $disabled->steps()->update(['is_enabled' => false]);
        $this->assertFalse($guard->requiresUnusedAddress($disabled->fresh()));

        $explicit = $this->workflowWithTasks([]);
        $explicit->forceFill(['settings_json' => ['requires_unused_verification_mailbox' => true]])->save();
        $this->assertTrue($guard->requiresUnusedAddress($explicit->fresh()));
    }

    public function test_inside_a_transaction_the_guard_only_checks_and_never_switches(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]], reserve: [
            ['email' => 'next@gmx.de', 'provider' => 'gmx', 'password_encrypted' => Crypt::encryptString('n')],
        ]);
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');
        $workflow = $this->registrationWorkflow();

        try {
            DB::transaction(fn () => app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio'));
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('next@gmx.de', $exception->getMessage());
        }

        $this->assertSame('used@gmx.de', app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox']['email']);
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function test_the_direct_registration_run_refuses_a_burnt_recovery_mailbox(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]]);
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['providers'][0]['registration_url'] = 'https://account.proton.me/mail/signup';
        $runner->saveSettings($settings);

        $this->expectException(VerificationMailboxUnavailableException::class);
        $this->expectExceptionMessage('used@gmx.de');

        $runner->start(['displayName' => 'Test', 'desiredEmail' => 'test@proton.me', 'accountUsername' => 'test']);
    }

    public function test_promotion_waits_while_an_unfinished_run_still_uses_the_burnt_address(): void
    {
        $consumer = $this->runOf($this->registrationWorkflow(), 'running');
        $this->seedMailbox('used@gmx.de', consumed: [
            ['email' => 'used@gmx.de', 'reason' => 'registered', 'workflow_run_id' => $consumer->id],
        ], reserve: [
            ['email' => 'next@gmx.de', 'provider' => 'gmx', 'password_encrypted' => Crypt::encryptString('n')],
        ]);
        $workflow = $this->registrationWorkflow();

        try {
            app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('#'.$consumer->id, $exception->getMessage());
        }

        $this->assertSame('used@gmx.de', app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox']['email']);

        // Ist der verbrauchende Lauf beendet, rueckt die Reserve beim naechsten Start nach.
        $consumer->forceFill(['status' => 'completed'])->save();
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();
        $run = app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');

        $this->assertSame('next@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.email'));
    }

    public function test_a_single_studio_task_runs_without_stamp_or_promotion(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]], reserve: [
            ['email' => 'next@gmx.de', 'provider' => 'gmx', 'password_encrypted' => Crypt::encryptString('n')],
        ]);
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), ['studio_single_task' => true], 'workflow-studio');

        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_guard'));
        $this->assertSame('used@gmx.de', app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox']['email']);
    }

    public function test_start_drops_mailbox_markers_inherited_from_a_source_run(): void
    {
        $this->seedMailbox('fresh@gmx.de');

        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [
            'verification_mailbox_guard' => ['email' => 'old@gmx.de', 'promoted_from' => null],
            'verification_mailbox_consumed' => ['email' => 'old@gmx.de', 'reason' => 'registered'],
        ], 'workflow-studio');

        $this->assertSame('fresh@gmx.de', data_get($run->fresh()->context_json, 'verification_mailbox_guard.email'));
        $this->assertNull(data_get($run->fresh()->context_json, 'verification_mailbox_consumed'));
    }

    public function test_the_settings_flag_of_an_embedded_child_guards_the_parent(): void
    {
        $child = $this->workflowWithTasks([]);
        $child->forceFill(['settings_json' => ['requires_unused_verification_mailbox' => true]])->save();
        $parent = $this->workflowWithTasks([[
            'key' => 'include-child', 'task_key' => 'workflow.include.'.$child->id, 'runner' => 'workflow', 'workflow_id' => $child->id,
        ]]);

        $this->assertTrue(app(VerificationMailboxStartGuard::class)->requiresUnusedAddress($parent));
    }

    public function test_a_stamped_run_may_continue_only_with_its_own_unburnt_or_self_consumed_address(): void
    {
        $guard = app(VerificationMailboxStartGuard::class);
        $workflow = $this->registrationWorkflow();
        $run = $this->runOf($workflow, 'paused', ['verification_mailbox_guard' => ['email' => 'a@gmx.de']]);

        // Adresse gewechselt.
        $this->seedMailbox('b@gmx.de');
        $this->assertStringContainsString('inzwischen ist b@gmx.de aktiv', (string) $guard->continuationBlockReason($run));
        $this->assertStringStartsWith('Naechste Liste nicht gestartet:', (string) $guard->continuationBlockReason($run, 'list'));

        // Gleiche Adresse, aber von einem anderen Lauf verbraucht.
        $this->seedMailbox('a@gmx.de', consumed: [['email' => 'a@gmx.de', 'reason' => 'registered', 'workflow_run_id' => 999]]);
        $this->assertStringContainsString('von einem anderen Lauf verbraucht', (string) $guard->continuationBlockReason($run->fresh()));

        // Selbst verbraucht (Eintrag mit eigener Laufnummer): die restlichen Listen laufen weiter.
        $this->seedMailbox('a@gmx.de', consumed: [['email' => 'a@gmx.de', 'reason' => 'registered', 'workflow_run_id' => $run->id]]);
        $this->assertNull($guard->continuationBlockReason($run->fresh()));

        // Unverbraucht und unveraendert.
        $this->seedMailbox('a@gmx.de');
        $this->assertNull($guard->continuationBlockReason($run->fresh()));
    }

    public function test_a_run_does_not_start_its_next_list_after_the_mailbox_was_switched(): void
    {
        $this->seedMailbox('a@gmx.de');
        $run = app(WorkflowExecutionService::class)->start($this->registrationWorkflow(), [], 'workflow-studio');
        // Der Admin traegt waehrend des Laufs ein anderes Postfach ein.
        $this->seedMailbox('b@gmx.de');

        app(WorkflowExecutionService::class)->advance($run->id);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringStartsWith('Naechste Liste nicht gestartet: Dieser Lauf wurde mit der Verifizierungs-Adresse a@gmx.de gestartet', (string) $run->error_message);
        // Nichts verbraucht: b@gmx.de bleibt frei.
        $this->assertSame([], app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox_consumed']);
    }

    public function test_the_direct_registration_run_never_promotes_a_reserve_on_its_own(): void
    {
        $this->seedMailbox('used@gmx.de', consumed: [['email' => 'used@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]], reserve: [
            ['email' => 'next@gmx.de', 'provider' => 'gmx', 'password_encrypted' => Crypt::encryptString('n')],
        ]);
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['providers'][0]['registration_url'] = 'https://account.proton.me/mail/signup';
        $runner->saveSettings($settings);

        try {
            $runner->start(['displayName' => 'Test', 'desiredEmail' => 'test@proton.me', 'accountUsername' => 'test']);
            $this->fail('Der Start haette abgelehnt werden muessen.');
        } catch (VerificationMailboxUnavailableException $exception) {
            $this->assertStringContainsString('next@gmx.de', $exception->getMessage());
        }

        $this->assertSame('used@gmx.de', $runner->settings()['verification_mailbox']['email']);
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

    private function seedMailbox(string $email, array $consumed = [], array $reserve = []): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = [
            'enabled' => true,
            'email' => $email,
            'provider' => 'gmx',
            'username' => $email,
            'password_encrypted' => Crypt::encryptString('secret'),
            'webmail_url' => 'https://www.gmx.net',
        ];
        $settings['verification_mailbox_consumed'] = $consumed;
        $settings['verification_mailbox_reserve'] = $reserve;
        $runner->saveSettings($settings);
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
