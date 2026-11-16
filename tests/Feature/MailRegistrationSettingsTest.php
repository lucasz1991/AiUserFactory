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

        // Ein anderes Postfach (z. B. schon gewechselt) aendert nichts.
        $this->assertNull($runner->consumeVerificationMailbox('someone-else@gmx.de', 'rejected', 5));

        $rotation = $runner->consumeVerificationMailbox('OLD@gmx.de', 'rejected', 7);

        $this->assertSame(['rotated' => true, 'consumed' => 'old@gmx.de', 'active' => 'reserve1@gmx.de', 'reserve_left' => 1], $rotation);
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
