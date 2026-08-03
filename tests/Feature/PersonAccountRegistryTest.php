<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Services\Persons\PersonAccountRegistry;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PersonAccountRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_account_is_exposed_under_the_dotted_account_structure(): void
    {
        $person = $this->makePerson();

        PersonEmailAccount::create([
            'person_id' => $person->id,
            'email' => 'nora.brandt@proton.me',
            'provider' => 'proton',
            'username' => 'nora.brandt',
            'password_encrypted' => Crypt::encryptString('mail-secret'),
            'webmail_url' => 'https://mail.proton.me',
            'is_primary' => true,
        ]);

        $payload = app(PersonAccountRegistry::class)->workflowPayload($person->fresh(), true);

        $this->assertSame('nora.brandt', data_get($payload, 'email.username'));
        $this->assertSame('nora.brandt@proton.me', data_get($payload, 'email.address'));
        $this->assertSame('mail-secret', data_get($payload, 'email.password'));
        $this->assertSame('https://mail.proton.me', data_get($payload, 'email.url'));
        $this->assertTrue(data_get($payload, 'email.hasPassword'));
        $this->assertTrue(data_get($payload, 'email.isConfigured'));
    }

    public function test_every_account_type_provides_username_address_and_password(): void
    {
        $payload = app(PersonAccountRegistry::class)->workflowPayload($this->makePerson(), true);

        $this->assertSame(array_keys(PersonAccountRegistry::TYPES), array_keys($payload));

        foreach ($payload as $type => $account) {
            $this->assertArrayHasKey('username', $account, $type);
            $this->assertArrayHasKey('address', $account, $type);
            $this->assertArrayHasKey('password', $account, $type);
        }
    }

    public function test_payload_without_secrets_never_carries_a_plaintext_password(): void
    {
        $person = $this->makePerson();

        app(PersonAccountRegistry::class)->saveSocialAccount($person, 'facebook', [
            'username' => 'nora.brandt',
            'password' => 'portal-secret',
        ]);

        $payload = app(PersonAccountRegistry::class)->workflowPayload($person->fresh(), false);

        $this->assertSame('', data_get($payload, 'facebook.password'));
        $this->assertTrue(data_get($payload, 'facebook.hasPassword'));
    }

    public function test_saving_a_portal_account_encrypts_the_password_and_derives_the_profile_address(): void
    {
        $person = $this->makePerson();
        $registry = app(PersonAccountRegistry::class);

        $registry->saveSocialAccount($person, 'x', [
            'username' => '@nora_brandt',
            'password' => 'x-secret',
            'status' => 'active',
        ]);

        $person = $person->fresh();
        $stored = $person->social_accounts['x'] ?? [];

        $this->assertSame('nora_brandt', $stored['username']);
        $this->assertNotSame('x-secret', $stored['password_encrypted']);
        $this->assertSame('x-secret', Crypt::decryptString($stored['password_encrypted']));

        $account = $registry->account($person, 'x', true);
        $this->assertSame('https://x.com/nora_brandt', $account['address']);
        $this->assertSame('x-secret', $account['password']);
    }

    public function test_instagram_keeps_using_the_person_login_columns(): void
    {
        $person = $this->makePerson([
            'login_username' => 'nora.brandt',
            'login_password_encrypted' => Crypt::encryptString('instagram-secret'),
            'session_cookie_present' => true,
            'cookie_count' => 12,
        ]);

        $account = app(PersonAccountRegistry::class)->account($person, 'instagram', true);

        $this->assertSame('nora.brandt', $account['username']);
        $this->assertSame('instagram-secret', $account['password']);
        $this->assertTrue($account['hasSession']);
    }

    public function test_saving_instagram_mirrors_the_username_but_never_the_password(): void
    {
        $person = $this->makePerson([
            'login_password_encrypted' => Crypt::encryptString('instagram-secret'),
        ]);

        app(PersonAccountRegistry::class)->saveSocialAccount($person, 'instagram', [
            'username' => 'neuer_name',
            'password' => 'sollte-ignoriert-werden',
        ]);

        $person = $person->fresh();

        $this->assertSame('neuer_name', $person->login_username);
        $this->assertSame('instagram-secret', Crypt::decryptString($person->login_password_encrypted));
    }

    public function test_twitter_is_normalized_to_x_and_unknown_types_are_rejected(): void
    {
        $registry = app(PersonAccountRegistry::class);

        $this->assertSame('x', $registry->normalizeType('twitter'));
        $this->assertSame('email', $registry->normalizeType('E-Mail'));
        $this->assertNull($registry->normalizeType('myspace'));
    }

    public function test_deleting_a_portal_account_removes_the_stored_password(): void
    {
        $person = $this->makePerson();
        $registry = app(PersonAccountRegistry::class);

        $registry->saveSocialAccount($person, 'tiktok', [
            'username' => 'nora',
            'password' => 'tiktok-secret',
        ]);

        $registry->deleteSocialAccount($person->fresh(), 'tiktok');

        $person = $person->fresh();

        $this->assertArrayNotHasKey('tiktok', $person->social_accounts ?? []);
        $this->assertFalse($registry->account($person, 'tiktok')['isConfigured']);
    }

    public function test_the_public_runtime_context_clears_every_account_password(): void
    {
        $runner = app(WorkflowTaskRunner::class);
        $method = new \ReflectionMethod($runner, 'publicRuntimeContext');
        $method->setAccessible(true);

        $public = $method->invoke($runner, [
            'person' => [
                'loginUsername' => 'nora.brandt',
                'loginPassword' => 'instagram-secret',
                'accounts' => [
                    'email' => ['username' => 'nora.brandt', 'password' => 'mail-secret', 'hasPassword' => true],
                    'instagram' => ['username' => 'nora_brandt', 'password' => 'instagram-secret', 'hasPassword' => true],
                ],
            ],
        ]);

        $this->assertSame('nora.brandt', $public['person']['accounts']['email']['username']);
        $this->assertTrue($public['person']['accounts']['email']['hasPassword']);
        $this->assertSame('', $public['person']['accounts']['email']['password']);
        $this->assertSame('', $public['person']['accounts']['instagram']['password']);
        $this->assertArrayNotHasKey('loginPassword', $public['person']);
        $this->assertStringNotContainsString('mail-secret', json_encode($public));
        $this->assertStringNotContainsString('instagram-secret', json_encode($public));
    }

    public function test_the_task_catalog_offers_the_account_paths_as_input_values(): void
    {
        $options = app(WorkflowTaskCatalog::class)->inputFillDataValueOptions();

        foreach (array_keys(PersonAccountRegistry::TYPES) as $type) {
            $this->assertArrayHasKey('person.accounts.'.$type.'.username', $options);
            $this->assertArrayHasKey('person.accounts.'.$type.'.address', $options);
            $this->assertArrayHasKey('person.accounts.'.$type.'.password', $options);
        }

        // Die Bestandspfade bleiben unveraendert waehlbar.
        $this->assertArrayHasKey('person.loginUsername', $options);
        $this->assertArrayHasKey('account.email', $options);
    }

    protected function makePerson(array $attributes = []): Person
    {
        return Person::create(array_replace([
            'platform' => 'instagram',
            'profile_key' => 'nora-brandt',
            'profile_label' => 'Nora Brandt',
            'person_first_name' => 'Nora',
            'person_last_name' => 'Brandt',
            'browser_profile_path' => 'browser-profiles/instagram/nora',
            'cookie_file_path' => 'cookies/nora-cookies.json',
            'is_active' => true,
        ], $attributes));
    }
}
