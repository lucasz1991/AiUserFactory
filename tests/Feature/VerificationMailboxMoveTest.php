<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\PersonEmailAccountSettings;
use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Models\User;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class VerificationMailboxMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_the_settings_mailbox_moves_to_the_person_with_password_sessions_and_consumption(): void
    {
        $jonas = $this->person('Jonas', 'Keller', 'muellerjosie2026');
        $this->person('Sophie', 'Neumann', 'sophie');
        $this->seedLegacyMailbox();

        $this->artisan('mail:move-verification-mailbox', ['person' => 'Jonas Keller'])
            ->expectsOutputToContain('muellerjosie@gmx.de')
            ->expectsOutputToContain('als Verifizierungs-Postfach verbraucht')
            ->assertSuccessful();

        $account = $jonas->emailAccounts()->sole();
        $this->assertSame('muellerjosie@gmx.de', $account->email);
        $this->assertSame('gmx', $account->provider);
        $this->assertSame('muellerjosie@gmx.de', $account->username);
        $this->assertSame('josie-secret', Crypt::decryptString($account->password_encrypted));
        $this->assertTrue($account->is_primary);
        $this->assertSame('cookie-jar', data_get($account->webmail_session, 'label'));
        $this->assertArrayHasKey('verification-mailbox--gmx.net', $account->verification_browser_sessions);
        $this->assertTrue($account->isVerificationConsumed());
        $this->assertSame([639, 641], array_column($account->verificationConsumptionEntries(), 'workflow_run_id'));
        $this->assertSame('2026-11-16 05:58:10', $account->verification_consumed_at->utc()->format('Y-m-d H:i:s'));
        // Primaerer Account wird wie im Personen-Formular gespiegelt.
        $this->assertSame('muellerjosie@gmx.de', $jonas->fresh()->person_email);
        $this->assertSame('muellerjosie@gmx.de', data_get($jonas->fresh()->metadata, 'email_account.email'));

        $legacy = app(MailAccountRegistrationRunner::class)->legacyVerificationMailbox();
        $this->assertSame('', $legacy['mailbox']['email']);
        $this->assertSame([], $legacy['consumed']);
    }

    public function test_a_dry_run_changes_nothing_and_unknown_people_are_rejected(): void
    {
        $jonas = $this->person('Jonas', 'Keller', 'muellerjosie2026');
        $this->seedLegacyMailbox();

        $this->artisan('mail:move-verification-mailbox', ['person' => (string) $jonas->id, '--dry-run' => true])
            ->expectsOutputToContain('Probelauf')
            ->assertSuccessful();
        $this->artisan('mail:move-verification-mailbox', ['person' => 'Niemand Unbekannt'])
            ->assertFailed();

        $this->assertSame(0, PersonEmailAccount::query()->count());
        $this->assertSame('muellerjosie@gmx.de', app(MailAccountRegistrationRunner::class)->legacyVerificationMailbox()['mailbox']['email']);
    }

    public function test_reserve_addresses_block_the_move_unless_they_are_dropped_explicitly(): void
    {
        $this->person('Jonas', 'Keller', 'muellerjosie2026');
        $this->seedLegacyMailbox(reserve: [['email' => 'spare@gmx.de', 'password_encrypted' => Crypt::encryptString('s')]]);

        $this->artisan('mail:move-verification-mailbox', ['person' => 'muellerjosie2026'])
            ->expectsOutputToContain('spare@gmx.de')
            ->assertFailed();
        $this->assertSame(0, PersonEmailAccount::query()->count());

        $this->artisan('mail:move-verification-mailbox', ['person' => 'muellerjosie2026', '--drop-reserve' => true])
            ->assertSuccessful();
        $this->assertSame(1, PersonEmailAccount::query()->count());
    }

    public function test_the_person_account_list_shows_the_verification_state_and_can_release_it(): void
    {
        $jonas = $this->person('Jonas', 'Keller', 'muellerjosie2026');
        $account = PersonEmailAccount::query()->create([
            'person_id' => $jonas->id, 'email' => 'muellerjosie@gmx.de', 'provider' => 'gmx', 'is_primary' => true,
            'password_encrypted' => Crypt::encryptString('josie-secret'),
            'verification_consumed_at' => now(),
            'verification_consumption' => [['reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00']],
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(PersonEmailAccountSettings::class, ['personId' => $jonas->id])
            ->assertSeeHtml('data-verification-consumed')
            ->assertSee('Lauf #641')
            ->call('releaseVerificationAccount', $account->id)
            ->assertSeeHtml('data-verification-free');

        $this->assertFalse($account->fresh()->isVerificationConsumed());
    }

    private function person(string $first, string $last, string $key): Person
    {
        return Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => $key,
            'profile_label' => $key,
            'person_first_name' => $first,
            'person_last_name' => $last,
        ]);
    }

    private function seedLegacyMailbox(array $reserve = []): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = [
            'enabled' => true,
            'email' => 'muellerjosie@gmx.de',
            'provider' => 'gmx',
            'username' => 'muellerjosie@gmx.de',
            'password_encrypted' => Crypt::encryptString('josie-secret'),
            'webmail_url' => 'https://www.gmx.net',
            'webmail_session' => ['payload_encrypted' => Crypt::encryptString('{"cookies":[]}'), 'label' => 'cookie-jar'],
            'browser_sessions' => ['verification-mailbox--gmx.net' => ['payload_encrypted' => 'x', 'domain' => 'gmx.net']],
        ];
        $settings['verification_mailbox_consumed'] = [
            ['email' => 'muellerjosie@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
            ['email' => 'muellerjosie@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 639, 'consumed_at' => '2026-11-16T05:58:10+00:00'],
            ['email' => 'muellerjosie@gmx.de', 'reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
            ['email' => 'other@gmx.de', 'reason' => 'registered', 'workflow_run_id' => 5, 'consumed_at' => '2026-10-01T00:00:00+00:00'],
        ];
        $settings['verification_mailbox_reserve'] = $reserve;
        $runner->saveSettings($settings);
    }
}
