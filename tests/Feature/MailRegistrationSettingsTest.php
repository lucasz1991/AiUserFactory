<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\MailRegistrationSettings;
use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\VerificationAccountPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class MailRegistrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_the_page_lists_person_mailboxes_with_their_verification_state(): void
    {
        $jonas = $this->person('jonas', 'Jonas', 'Keller');
        $burnt = $this->account($jonas, 'muellerjosie@gmx.de', consumption: [
            ['reason' => 'rejected', 'workflow_run_id' => 639, 'consumed_at' => '2026-11-16T05:58:10+00:00'],
            ['reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
        ]);
        $this->account($this->person('anna', 'Anna', 'Berg'), 'anna@gmx.de');
        $this->account($this->person('noah', 'Noah', 'Wolf'), 'noah@gmx.de', password: false);
        $this->account($this->person('lena', 'Lena', 'Kurz'), 'lena@proton.me', provider: 'proton');

        Livewire::test(MailRegistrationSettings::class)
            ->assertSeeHtml('data-verification-account-pool')
            ->assertSeeHtml('data-verification-account="'.$burnt->id.'"')
            ->assertSeeHtml('data-verification-account-state="consumed"')
            ->assertSeeHtml('data-verification-account-state="free"')
            ->assertSeeHtml('data-verification-account-state="no-password"')
            ->assertSee('Jonas Keller')
            ->assertSee('Lauf #641')
            ->assertSee('Lauf #639')
            ->assertDontSee('lena@proton.me')
            ->assertDontSeeHtml('data-verification-account-pool-empty');
    }

    public function test_without_a_free_mailbox_the_page_warns_that_registrations_are_blocked(): void
    {
        $this->account($this->person('jonas', 'Jonas', 'Keller'), 'muellerjosie@gmx.de', consumption: [
            ['reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
        ]);

        Livewire::test(MailRegistrationSettings::class)
            ->assertSeeHtml('data-verification-account-pool-empty');
    }

    public function test_a_mailbox_used_by_an_unfinished_run_is_shown_as_in_use(): void
    {
        $account = $this->account($this->person('anna', 'Anna', 'Berg'), 'anna@gmx.de');
        $workflow = Workflow::query()->create([
            'name' => 'Proton', 'slug' => 'proton-'.str()->random(6), 'description' => '', 'category' => 'test',
            'is_active' => true, 'is_locked' => false, 'trigger_type' => 'manual', 'settings_json' => [],
        ]);
        WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'status' => 'running',
            'context_json' => ['verification_mailbox_guard' => ['account_id' => $account->id, 'email' => 'anna@gmx.de']],
            'result_json' => [],
        ]);

        Livewire::test(MailRegistrationSettings::class)
            ->assertSeeHtml('data-verification-account-state="in-use"');
    }

    public function test_a_consumed_mailbox_can_be_released_and_keeps_its_history(): void
    {
        $account = $this->account($this->person('jonas', 'Jonas', 'Keller'), 'muellerjosie@gmx.de', consumption: [
            ['reason' => 'rejected', 'workflow_run_id' => 641, 'consumed_at' => '2026-11-23T00:28:51+00:00'],
        ]);

        Livewire::test(MailRegistrationSettings::class)
            ->call('releaseVerificationAccount', $account->id)
            ->assertSet('mailboxNoticeType', 'success')
            ->assertSeeHtml('data-mailbox-notice="success"')
            ->assertSeeHtml('data-verification-account-state="free"');

        $account->refresh();
        $this->assertFalse($account->isVerificationConsumed());
        $this->assertCount(1, $account->verificationConsumptionEntries());
    }

    public function test_saving_the_form_keeps_unrelated_settings_and_stores_the_browser_options(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['verification_mailbox'] = ['enabled' => true, 'email' => 'legacy@gmx.de', 'provider' => 'gmx'];
        $runner->saveSettings($settings);

        Livewire::test(MailRegistrationSettings::class)
            ->set('navigationTimeoutSeconds', 90)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $settings = $runner->settings();
        $this->assertSame(90, $settings['navigation_timeout_seconds']);
        // Das alte Postfach bleibt bis zum Umzug unangetastet.
        $this->assertSame('legacy@gmx.de', $settings['verification_mailbox']['email']);
    }

    public function test_the_test_run_explains_that_no_free_mailbox_exists(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['providers'][0]['registration_url'] = 'https://account.proton.me/mail/signup';
        $runner->saveSettings($settings);

        Livewire::test(MailRegistrationSettings::class)
            ->call('startTestRun')
            ->assertSet('registrationRunStatus.stage', 'start-failed')
            ->assertSet('registrationRunStatus.message', fn (string $message): bool => str_contains($message, 'kein freies GMX-Postfach'));
    }

    public function test_the_pool_skips_the_registering_person_and_records_consumption_once_per_run(): void
    {
        $sophie = $this->person('sophie', 'Sophie', 'Neumann');
        $own = $this->account($sophie, 'sophie@gmx.de');
        $other = $this->account($this->person('anna', 'Anna', 'Berg'), 'anna@gmx.de');
        $pool = app(VerificationAccountPool::class);

        $this->assertSame($other->id, $pool->available($sophie->id)?->id);
        $this->assertSame($own->id, $pool->available()?->id);

        $this->assertTrue($pool->recordConsumption($other->id, 'rejected', 7));
        $this->assertFalse($pool->recordConsumption($other->id, 'rejected', 7));
        $this->assertTrue($other->fresh()->isVerificationConsumed());
        $this->assertCount(1, $other->fresh()->verificationConsumptionEntries());
        $this->assertNull($pool->available($sophie->id));
        $this->assertStringStartsWith('Lauf #7 am ', $pool->describeConsumption($other->fresh()->verificationConsumptionEntries()));
    }

    private function person(string $key, string $first, string $last): Person
    {
        return Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => $key.'-'.str()->random(4),
            'profile_label' => $first,
            'person_first_name' => $first,
            'person_last_name' => $last,
        ]);
    }

    private function account(Person $person, string $email, string $provider = 'gmx', bool $password = true, array $consumption = []): PersonEmailAccount
    {
        return PersonEmailAccount::query()->create([
            'person_id' => $person->id,
            'email' => $email,
            'provider' => $provider,
            'username' => $email,
            'password_encrypted' => $password ? Crypt::encryptString('secret') : null,
            'is_primary' => true,
            'verification_consumption' => $consumption !== [] ? $consumption : null,
            'verification_consumed_at' => $consumption !== [] ? now() : null,
        ]);
    }
}
