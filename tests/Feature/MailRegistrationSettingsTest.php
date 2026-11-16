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
