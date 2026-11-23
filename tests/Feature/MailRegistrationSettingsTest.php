<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\MailRegistrationSettings;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MailRegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $taskRunner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        // Der echte Runner loeschte das Profilverzeichnis unter storage/.
        $this->taskRunner = Mockery::mock(WorkflowTaskRunner::class);
        $this->app->instance(WorkflowTaskRunner::class, $this->taskRunner);
    }

    public function test_changing_the_mailbox_without_a_password_is_rejected_and_nothing_is_saved(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxEmail', 'new@gmx.de')
            ->set('verificationMailboxUsername', 'new@gmx.de')
            ->call('saveSettings')
            ->assertHasErrors(['verificationMailboxPassword']);

        $mailbox = $this->storedMailbox();
        $this->assertSame('old@gmx.de', $mailbox['email']);
        $this->assertSame('old-secret', Crypt::decryptString($mailbox['password_encrypted']));
        $this->assertNotNull($mailbox['webmail_session']);
        $this->assertCount(1, $mailbox['browser_sessions']);
    }

    public function test_changing_the_mailbox_with_a_password_drops_the_old_password_sessions_and_profile(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxEmail', 'new@gmx.de')
            ->set('verificationMailboxUsername', 'new@gmx.de')
            ->set('verificationMailboxPassword', 'new-secret')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('verificationMailboxPassword', '')
            ->assertSet('hasStoredVerificationMailboxPassword', true);

        $mailbox = $this->storedMailbox();
        $this->assertSame('new@gmx.de', $mailbox['email']);
        $this->assertSame('new-secret', Crypt::decryptString($mailbox['password_encrypted']));
        $this->assertNull($mailbox['webmail_session']);
        $this->assertSame([], $mailbox['browser_sessions']);
    }

    public function test_saving_the_same_mailbox_keeps_password_and_sessions(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            // Gross-/Kleinschreibung ist kein Postfachwechsel.
            ->set('verificationMailboxEmail', 'Old@GMX.de')
            ->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $mailbox = $this->storedMailbox();
        $this->assertSame('old-secret', Crypt::decryptString($mailbox['password_encrypted']));
        $this->assertNotNull($mailbox['webmail_session']);
        $this->assertCount(1, $mailbox['browser_sessions']);
    }

    public function test_a_changed_login_name_counts_as_another_mailbox(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxUsername', 'other-login')
            ->call('saveSettings')
            ->assertHasErrors(['verificationMailboxPassword']);
    }

    public function test_a_changed_provider_keeps_the_password_but_drops_the_sessions(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxProvider', 'proton')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $mailbox = $this->storedMailbox();
        $this->assertSame('old-secret', Crypt::decryptString($mailbox['password_encrypted']));
        $this->assertNull($mailbox['webmail_session']);
        $this->assertSame([], $mailbox['browser_sessions']);
    }

    public function test_removing_the_mailbox_needs_no_password_and_drops_its_secrets(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxEmail', '')
            ->set('verificationMailboxUsername', '')
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('hasStoredVerificationMailboxPassword', false);

        $mailbox = $this->storedMailbox();
        $this->assertSame('', $mailbox['email']);
        $this->assertNull($mailbox['password_encrypted']);
        $this->assertSame([], $mailbox['browser_sessions']);
    }

    public function test_the_first_mailbox_setup_needs_no_previous_password(): void
    {
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxEmail', 'first@gmx.de')
            ->set('verificationMailboxProvider', 'gmx')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('first@gmx.de', $this->storedMailbox()['email']);
    }

    public function test_starting_a_session_build_for_a_new_mailbox_without_password_shows_the_field_error(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');
        $sessionRunner = Mockery::mock(WebmailSessionRunner::class);
        $sessionRunner->shouldNotReceive('start');
        $this->app->instance(WebmailSessionRunner::class, $sessionRunner);

        Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxEmail', 'new@gmx.de')
            ->set('verificationMailboxUsername', 'new@gmx.de')
            ->call('buildVerificationMailboxWebmailSession')
            ->assertHasErrors(['verificationMailboxPassword']);

        $this->assertSame('old@gmx.de', $this->storedMailbox()['email']);
    }

    public function test_reserve_addresses_can_be_added_and_removed_without_touching_the_active_mailbox(): void
    {
        $this->seedMailbox();
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('newReserveMailboxEmail', 'reserve1@gmx.de')
            ->set('newReserveMailboxPassword', 'reserve-secret')
            ->call('addReserveMailbox')
            ->assertHasNoErrors()
            ->assertSet('newReserveMailboxPassword', '')
            ->assertSet('reserveMailboxes.0.email', 'reserve1@gmx.de')
            ->assertSet('reserveMailboxes.0.hasPassword', true)
            // Doppelte und bereits aktive Adressen werden abgelehnt.
            ->set('newReserveMailboxEmail', 'RESERVE1@gmx.de')
            ->set('newReserveMailboxPassword', 'x')
            ->call('addReserveMailbox')
            ->assertHasErrors(['newReserveMailboxEmail'])
            ->set('newReserveMailboxEmail', 'old@gmx.de')
            ->call('addReserveMailbox')
            ->assertHasErrors(['newReserveMailboxEmail'])
            ->set('newReserveMailboxEmail', 'reserve2@gmx.de')
            ->set('newReserveMailboxPassword', '')
            ->call('addReserveMailbox')
            ->assertHasErrors(['newReserveMailboxPassword']);

        $settings = app(MailAccountRegistrationRunner::class)->settings();
        $this->assertCount(1, $settings['verification_mailbox_reserve']);
        $this->assertSame('reserve-secret', Crypt::decryptString($settings['verification_mailbox_reserve'][0]['password_encrypted']));
        $this->assertSame('old@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertCount(1, $settings['verification_mailbox']['browser_sessions']);

        Livewire::test(MailRegistrationSettings::class)
            ->call('removeReserveMailbox', 'reserve1@gmx.de')
            ->assertSet('reserveMailboxes', []);
        $this->assertSame([], app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox_reserve']);
    }

    public function test_saving_the_form_keeps_reserve_and_consumed_addresses(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [['email' => 'reserve1@gmx.de', 'password_encrypted' => Crypt::encryptString('r')]];
        $settings['verification_mailbox_consumed'] = [['email' => 'used@gmx.de', 'reason' => 'registered']];
        $runner->saveSettings($settings);

        Livewire::test(MailRegistrationSettings::class)
            ->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $settings = $runner->settings();
        $this->assertSame('reserve1@gmx.de', $settings['verification_mailbox_reserve'][0]['email']);
        $this->assertSame('used@gmx.de', $settings['verification_mailbox_consumed'][0]['email']);
    }

    public function test_a_consumed_mailbox_is_replaced_by_the_first_reserve_address(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [
            ['email' => 'reserve1@gmx.de', 'username' => '', 'password_encrypted' => Crypt::encryptString('r1')],
            ['email' => 'reserve2@gmx.de', 'password_encrypted' => Crypt::encryptString('r2')],
        ];
        $runner->saveSettings($settings);

        // Eine nicht (mehr) aktive Adresse wird vermerkt, das aktive Postfach bleibt.
        $other = $runner->consumeVerificationMailbox('someone-else@gmx.de', 'rejected', 5);
        $this->assertFalse($other['was_active']);
        $this->assertFalse($other['rotated']);
        $this->assertSame('old@gmx.de', $runner->settings()['verification_mailbox']['email']);
        $this->assertSame('someone-else@gmx.de', $runner->settings()['verification_mailbox_consumed'][0]['email']);
        $this->clearConsumed();

        $rotation = $runner->consumeVerificationMailbox('OLD@gmx.de', 'rejected', 7);

        $this->assertSame(['rotated' => true, 'consumed' => 'old@gmx.de', 'active' => 'reserve1@gmx.de', 'reserve_left' => 1, 'was_active' => true], $rotation);
        $settings = $runner->settings();
        $this->assertTrue($settings['verification_mailbox']['enabled']);
        $this->assertSame('reserve1@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame('gmx', $settings['verification_mailbox']['provider']);
        $this->assertSame('r1', Crypt::decryptString($settings['verification_mailbox']['password_encrypted']));
        $this->assertNull($settings['verification_mailbox']['webmail_session']);
        $this->assertSame([], $settings['verification_mailbox']['browser_sessions']);
        $this->assertSame(['reserve2@gmx.de'], array_column($settings['verification_mailbox_reserve'], 'email'));
        $this->assertSame('old@gmx.de', $settings['verification_mailbox_consumed'][0]['email']);
        $this->assertSame('rejected', $settings['verification_mailbox_consumed'][0]['reason']);
        $this->assertSame(7, $settings['verification_mailbox_consumed'][0]['workflow_run_id']);

        // Ohne Reserve bleibt das Postfach aktiv, wird aber als verbraucht vermerkt.
        $runner->consumeVerificationMailbox('reserve1@gmx.de', 'registered', 8);
        $last = $runner->consumeVerificationMailbox('reserve2@gmx.de', 'registered', 9);
        $this->assertFalse($last['rotated']);
        $this->assertSame('reserve2@gmx.de', $runner->settings()['verification_mailbox']['email']);
        $this->assertCount(3, $runner->settings()['verification_mailbox_consumed']);
    }

    public function test_a_consumed_active_mailbox_shows_a_warning_with_its_runs(): void
    {
        $this->seedMailbox();
        $this->seedConsumed([
            ['email' => 'OLD@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 639, 'consumed_at' => '2026-11-16T09:00:00+00:00'],
            ['email' => 'old@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T20:00:00+00:00'],
        ]);

        Livewire::test(MailRegistrationSettings::class)
            ->assertSeeHtml('data-verification-mailbox-consumed-warning')
            ->assertSeeHtml('data-verification-mailbox-consumed-no-reserve')
            ->assertSeeHtml('data-verification-mailbox-email-consumed')
            ->assertSeeHtml('data-verification-mailbox-reserve-empty="blocking"')
            ->assertSeeHtml('data-verification-mailbox-consumed-list="open"')
            ->assertSeeHtmlInOrder(['data-consumed-run="641"', 'data-consumed-run="639"'])
            ->assertSet('activeMailboxConsumption.events.0.workflowRunId', 641)
            ->assertCount('consumedMailboxes', 1)
            ->assertDontSeeHtml('data-activate-next-reserve');
    }

    public function test_an_unused_active_mailbox_shows_no_consumed_warning(): void
    {
        $this->seedMailbox();
        $this->seedConsumed([['email' => 'other@gmx.de', 'reason' => 'registered', 'workflow_run_id' => 3]]);

        Livewire::test(MailRegistrationSettings::class)
            ->assertDontSeeHtml('data-verification-mailbox-consumed-warning')
            ->assertSet('activeMailboxConsumption', [])
            ->assertSeeHtml('data-verification-mailbox-reserve-empty="hint"')
            ->assertSeeHtml('data-verification-mailbox-consumed-list="closed"');
    }

    public function test_adding_a_reserve_while_the_active_mailbox_is_consumed_activates_it_immediately(): void
    {
        $this->seedMailbox();
        $this->seedConsumed([['email' => 'old@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]]);
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();

        Livewire::test(MailRegistrationSettings::class)
            ->set('newReserveMailboxEmail', 'fresh@gmx.de')
            ->set('newReserveMailboxPassword', 'fresh-secret')
            ->call('addReserveMailbox')
            ->assertHasNoErrors()
            ->assertSet('verificationMailboxEmail', 'fresh@gmx.de')
            ->assertSet('activeMailboxConsumption', [])
            ->assertSet('reserveMailboxes', []);

        $mailbox = $this->storedMailbox();
        $this->assertSame('fresh@gmx.de', $mailbox['email']);
        $this->assertTrue($mailbox['enabled']);
        $this->assertSame('fresh-secret', Crypt::decryptString($mailbox['password_encrypted']));
        $this->assertSame([], $mailbox['browser_sessions']);
        $this->assertNull($mailbox['webmail_session']);
    }

    public function test_activating_the_next_reserve_skips_consumed_reserve_addresses(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [
            ['email' => 'burnt@gmx.de', 'password_encrypted' => Crypt::encryptString('b')],
            ['email' => 'good@gmx.de', 'password_encrypted' => Crypt::encryptString('g')],
        ];
        $settings['verification_mailbox_consumed'] = [
            ['email' => 'old@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641],
            ['email' => 'burnt@gmx.de', 'reason' => 'registered', 'workflow_run_id' => 600],
        ];
        $runner->saveSettings($settings);
        $this->taskRunner->shouldReceive('forgetVerificationMailboxBrowserProfile')->once();

        Livewire::test(MailRegistrationSettings::class)
            ->assertSet('nextReserveMailboxEmail', 'good@gmx.de')
            ->assertSet('reserveMailboxes.0.consumed', true)
            ->assertSeeHtml('data-activate-next-reserve')
            ->call('activateNextReserveMailbox')
            ->assertSet('verificationMailboxEmail', 'good@gmx.de');

        $settings = $runner->settings();
        $this->assertSame('good@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame(['burnt@gmx.de'], array_column($settings['verification_mailbox_reserve'], 'email'));
        // Aktivieren fuegt keinen weiteren Verbrauchs-Eintrag hinzu.
        $this->assertCount(2, $settings['verification_mailbox_consumed']);
    }

    public function test_releasing_a_consumed_address_removes_all_of_its_entries_only(): void
    {
        $this->seedMailbox();
        $this->seedConsumed([
            ['email' => 'old@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 639],
            ['email' => 'other@gmx.de', 'reason' => 'registered', 'workflow_run_id' => 640],
            ['email' => 'OLD@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641],
        ]);

        Livewire::test(MailRegistrationSettings::class)
            ->call('releaseConsumedMailbox', 'old@gmx.de')
            ->assertSet('activeMailboxConsumption', [])
            ->assertCount('consumedMailboxes', 1);

        $settings = app(MailAccountRegistrationRunner::class)->settings();
        $this->assertSame(['other@gmx.de'], array_column($settings['verification_mailbox_consumed'], 'email'));
        $this->assertSame('old@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame('old-secret', Crypt::decryptString($settings['verification_mailbox']['password_encrypted']));
    }

    public function test_a_stale_form_keeps_the_mailbox_that_was_switched_in_the_background(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [['email' => 'next@gmx.de', 'password_encrypted' => Crypt::encryptString('n')]];
        $runner->saveSettings($settings);
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        $page = Livewire::test(MailRegistrationSettings::class);
        // Waehrend die Seite offen ist, wechselt ein Laufende auf die Reserve-Adresse.
        $runner->consumeVerificationMailbox('old@gmx.de', 'rejected', 641);

        $page->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $settings = $runner->settings();
        $this->assertSame('next@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame('n', Crypt::decryptString($settings['verification_mailbox']['password_encrypted']));
        $this->assertSame(90, $settings['navigation_timeout_seconds']);

        // Wer im veralteten Formular das Postfach selbst aendert, muss neu laden.
        $stale = Livewire::test(MailRegistrationSettings::class);
        $runner->consumeVerificationMailbox('next@gmx.de', 'rejected', 642);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = ['enabled' => true, 'email' => 'third@gmx.de', 'provider' => 'gmx'];
        $runner->saveSettings($settings);

        $stale->set('verificationMailboxEmail', 'typed@gmx.de')
            ->set('verificationMailboxUsername', 'typed@gmx.de')
            ->set('verificationMailboxPassword', 'typed')
            ->call('saveSettings')
            ->assertHasErrors(['verificationMailboxEmail']);
        $this->assertSame('third@gmx.de', $runner->settings()['verification_mailbox']['email']);
    }

    public function test_resaving_an_already_consumed_active_mailbox_keeps_working(): void
    {
        $this->seedMailbox();
        $this->seedConsumed([['email' => 'old@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641]]);
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-verification-mailbox-consumed-warning');

        $this->assertSame(90, app(MailAccountRegistrationRunner::class)->settings()['navigation_timeout_seconds']);
        $this->assertCount(1, $this->storedMailbox()['browser_sessions']);
    }

    public function test_a_session_built_for_the_previous_mailbox_is_not_stored_in_the_new_one(): void
    {
        $this->seedMailbox();
        $sessions = Mockery::mock(WebmailSessionRunner::class);
        $sessions->shouldReceive('readRun')->with('session-1')->andReturn([
            'state' => 'completed',
            'result' => ['ok' => true, 'encryptedSessionPayload' => 'enc', 'sessionSummary' => []],
        ]);
        $this->app->instance(WebmailSessionRunner::class, $sessions);
        $page = Livewire::test(MailRegistrationSettings::class)
            ->set('verificationMailboxSessionRunId', 'session-1')
            ->set('verificationMailboxSessionEmail', 'old@gmx.de');

        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = ['enabled' => true, 'email' => 'new@gmx.de', 'provider' => 'gmx'];
        $runner->saveSettings($settings);

        $page->call('refreshVerificationMailboxSessionRun')
            ->assertSet('verificationMailboxSessionRunId', null)
            ->assertSet('verificationMailboxSessionResult.ok', false)
            ->assertSet('verificationMailboxEmail', 'new@gmx.de')
            ->assertSet('mailboxNoticeType', 'warning')
            ->assertSeeHtml('data-mailbox-notice="warning"');

        $this->assertNull($this->storedMailbox()['webmail_session']);
    }

    public function test_consumption_is_recorded_once_per_run_and_removed_from_the_reserve(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [['email' => 'Spare@gmx.de', 'password_encrypted' => Crypt::encryptString('s')]];
        $runner->saveSettings($settings);

        $this->assertTrue($runner->recordConsumedVerificationMailbox('spare@gmx.de', 'registered', 12));
        $this->assertFalse($runner->recordConsumedVerificationMailbox('SPARE@gmx.de', 'registered', 12));
        $runner->consumeVerificationMailbox('old@gmx.de', 'rejected', 13);
        $runner->consumeVerificationMailbox('old@gmx.de', 'rejected', 13);

        $settings = $runner->settings();
        $this->assertSame(['spare@gmx.de', 'old@gmx.de'], array_column($settings['verification_mailbox_consumed'], 'email'));
        $this->assertSame([], $settings['verification_mailbox_reserve']);
        // Ohne verwendbare Reserve bleibt das verbrauchte Postfach aktiv.
        $this->assertSame('old@gmx.de', $settings['verification_mailbox']['email']);
        $this->assertSame('ohne Laufnummer, Lauf #13', $runner->describeConsumedEntries([
            ['email' => 'old@gmx.de', 'workflow_run_id' => 13, 'consumed_at' => 'kein Datum'],
            ['email' => 'old@gmx.de', 'workflow_run_id' => null, 'consumed_at' => null],
        ]));
    }

    public function test_clearing_the_password_on_a_stale_form_never_touches_the_new_mailbox(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [['email' => 'next@gmx.de', 'password_encrypted' => Crypt::encryptString('n')]];
        $runner->saveSettings($settings);
        $page = Livewire::test(MailRegistrationSettings::class);
        $runner->consumeVerificationMailbox('old@gmx.de', 'rejected', 641);

        $page->call('clearVerificationMailboxPassword')
            ->assertHasErrors(['verificationMailboxPassword']);

        $this->assertSame('n', Crypt::decryptString($this->storedMailbox()['password_encrypted']));
    }

    public function test_a_password_typed_into_a_stale_form_requires_a_reload(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_reserve'] = [['email' => 'next@gmx.de', 'password_encrypted' => Crypt::encryptString('n')]];
        $runner->saveSettings($settings);
        $page = Livewire::test(MailRegistrationSettings::class);
        $runner->consumeVerificationMailbox('old@gmx.de', 'rejected', 641);

        $page->set('verificationMailboxPassword', 'meant-for-old')
            ->call('saveSettings')
            ->assertHasErrors(['verificationMailboxEmail']);

        $this->assertSame('n', Crypt::decryptString($this->storedMailbox()['password_encrypted']));
    }

    public function test_adding_a_reserve_while_a_run_still_uses_the_burnt_mailbox_waits_for_that_run(): void
    {
        $this->seedMailbox();
        $workflow = \App\Models\Workflow::query()->create([
            'name' => 'Consumer', 'slug' => 'consumer-'.str()->random(6), 'description' => '', 'category' => 'test',
            'is_active' => true, 'is_locked' => false, 'trigger_type' => 'manual', 'settings_json' => [],
        ]);
        $consumer = \App\Models\WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'status' => 'running',
            'context_json' => [], 'result_json' => [],
        ]);
        $this->seedConsumed([['email' => 'old@gmx.de', 'reason' => 'registered', 'workflow_run_id' => $consumer->id]]);
        $this->taskRunner->shouldNotReceive('forgetVerificationMailboxBrowserProfile');

        Livewire::test(MailRegistrationSettings::class)
            ->set('newReserveMailboxEmail', 'fresh@gmx.de')
            ->set('newReserveMailboxPassword', 'fresh-secret')
            ->call('addReserveMailbox')
            ->assertHasNoErrors()
            ->assertSet('verificationMailboxEmail', 'old@gmx.de')
            ->assertSet('reserveMailboxes.0.email', 'fresh@gmx.de')
            ->assertSeeHtml('data-mailbox-notice="success"')
            ->assertSee('Lauf #'.$consumer->id)
            ->call('activateNextReserveMailbox')
            ->assertSet('mailboxNoticeType', 'warning')
            ->assertSet('verificationMailboxEmail', 'old@gmx.de');

        $this->assertSame('old@gmx.de', $this->storedMailbox()['email']);
    }

    public function test_saving_the_form_keeps_the_time_of_the_last_mailbox_switch(): void
    {
        $this->seedMailbox();
        $runner = app(MailAccountRegistrationRunner::class);
        $stamp = $this->storedMailbox()['identity_changed_at'];
        $this->assertNotNull($stamp);

        Livewire::test(MailRegistrationSettings::class)
            ->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame($stamp, $runner->settings()['verification_mailbox']['identity_changed_at']);
    }

    private function seedConsumed(array $entries): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox_consumed'] = $entries;
        $runner->saveSettings($settings);
    }

    private function clearConsumed(): void
    {
        $this->seedConsumed([]);
    }

    private function seedMailbox(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = [
            'enabled' => true,
            'email' => 'old@gmx.de',
            'provider' => 'gmx',
            'username' => 'old@gmx.de',
            'password_encrypted' => Crypt::encryptString('old-secret'),
            'webmail_url' => 'https://www.gmx.net',
            'webmail_session' => ['payload_encrypted' => Crypt::encryptString('{"cookies":[]}'), 'label' => 'alt'],
            'browser_sessions' => [
                'verification-mailbox--gmx.net' => ['payload_encrypted' => Crypt::encryptString('{"cookies":[]}'), 'domain' => 'gmx.net'],
            ],
        ];
        $runner->saveSettings($settings);
    }

    private function storedMailbox(): array
    {
        return app(MailAccountRegistrationRunner::class)->settings()['verification_mailbox'];
    }
}
