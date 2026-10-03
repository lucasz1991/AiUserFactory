<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\PersonAccounts;
use App\Livewire\Admin\Config\PersonEmailAccountSettings;
use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Models\User;
use App\Services\Persons\PersonAccountRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rendert das Profil und den Accounts-Tab mit echten Daten. Ergaenzt die reinen
 * Markup-Pruefungen aus `PersonProfileMarkupTest` um den gerenderten Zustand.
 */
class PersonProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_page_renders_the_hero_metrics_and_the_accounts_tab(): void
    {
        $person = $this->makePerson();
        $this->actingAs($this->admin());

        $response = $this->get(route('persons.show', ['profileId' => $person->profile_key]));

        $response->assertOk();
        $response->assertSee('data-person-profile', false);
        $response->assertSee('data-profile-hero', false);
        $response->assertSee('Accounts verbunden', false);
        $response->assertSee('Nora Brandt', false);

        // Die entfernten Kopfzeilen-Knoepfe duerfen nicht wieder auftauchen.
        $response->assertDontSee('Session aufbauen', false);
        $response->assertDontSee('>Zurueck<', false);
    }

    public function test_the_accounts_tab_lists_mail_and_portal_accounts_and_switches_between_them(): void
    {
        $person = $this->makePerson();

        PersonEmailAccount::create([
            'person_id' => $person->id,
            'email' => 'nora.brandt@proton.me',
            'provider' => 'proton',
            'username' => 'nora.brandt',
            'password_encrypted' => Crypt::encryptString('mail-secret'),
            'is_primary' => true,
        ]);

        app(PersonAccountRegistry::class)->saveSocialAccount($person, 'facebook', [
            'username' => 'nora.brandt',
            'password' => 'facebook-secret',
        ]);

        $this->actingAs($this->admin());

        Livewire::test(PersonAccounts::class, ['personId' => $person->id])
            ->assertSee('E-Mail-Account')
            ->assertSee('Instagram')
            ->assertSee('Facebook')
            ->assertSee('TikTok')
            ->assertSee('person.accounts.email.username')
            ->assertSee('mail-secret')
            ->call('selectType', 'facebook')
            ->assertSet('selectedType', 'facebook')
            ->assertSee('person.accounts.facebook.password')
            ->assertSee('facebook-secret')
            ->assertSet('formPassword', '');
    }

    public function test_saving_a_portal_account_through_the_accounts_tab_persists_it(): void
    {
        $person = $this->makePerson();
        $this->actingAs($this->admin());

        Livewire::test(PersonAccounts::class, ['personId' => $person->id])
            ->call('selectType', 'x')
            ->call('editAccount', 'x')
            ->assertSet('showForm', true)
            ->set('formUsername', 'nora_brandt')
            ->set('formPassword', 'x-secret')
            ->set('formStatus', 'active')
            ->call('saveAccount')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $account = app(PersonAccountRegistry::class)->account($person->fresh(), 'x', true);

        $this->assertSame('nora_brandt', $account['username']);
        $this->assertSame('x-secret', $account['password']);
    }

    public function test_only_selected_social_credentials_are_rendered_and_not_serialized_as_public_state(): void
    {
        $person = $this->makePerson();
        $person->update(['login_password_encrypted' => Crypt::encryptString('instagram-fixture-secret')]);
        app(PersonAccountRegistry::class)->saveSocialAccount($person, 'facebook', [
            'username' => 'test', 'password' => 'fixture-"<>&-password',
        ]);
        $this->actingAs($this->admin());
        $component = Livewire::test(PersonAccounts::class, ['personId' => $person->id])
            ->assertDontSee('instagram-fixture-secret')
            ->call('selectType', 'facebook')
            ->assertSee('fixture-"<>&-password')
            ->assertSee('data-readable-password', false)
            ->assertDontSee('instagram-fixture-secret');

        $this->assertStringNotContainsString('fixture-', json_encode(get_object_vars($component->instance())));
        $component->call('selectType', 'instagram')
            ->assertSee('instagram-fixture-secret')
            ->assertDontSee('fixture-&quot;');
    }

    public function test_each_mailbox_shows_its_own_password_without_leaking_other_persons_accounts(): void
    {
        $person = $this->makePerson();
        foreach (['first-secret', 'second-secret', null] as $index => $password) {
            PersonEmailAccount::create([
                'person_id' => $person->id, 'email' => "mail{$index}@example.test",
                'provider' => 'custom', 'is_primary' => $index === 0,
                'password_encrypted' => $password ? Crypt::encryptString($password) : 'invalid-ciphertext',
            ]);
        }
        $other = $person->replicate();
        $other->profile_key = 'other-person';
        $other->save();
        PersonEmailAccount::create([
            'person_id' => $other->id, 'email' => 'other@example.test',
            'provider' => 'custom', 'password_encrypted' => Crypt::encryptString('other-secret'),
        ]);
        $this->actingAs($this->admin());
        $component = Livewire::test(PersonEmailAccountSettings::class, ['personId' => $person->id])
            ->assertSee('first-secret')->assertSee('second-secret')
            ->assertSee('nicht entschlüsselbar')->assertDontSee('other-secret')
            ->assertDontSee('invalid-ciphertext')->assertSet('accountPassword', '');
        $this->assertStringNotContainsString('first-secret', json_encode($component->get('accounts')));
        $this->assertStringNotContainsString('second-secret', json_encode($component->get('accounts')));
    }

    public function test_credential_components_require_admin_even_when_accessed_directly(): void
    {
        $person = $this->makePerson();
        foreach ([PersonAccounts::class, PersonEmailAccountSettings::class] as $component) {
            Livewire::test($component, ['personId' => $person->id])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'user']));
        foreach ([PersonAccounts::class, PersonEmailAccountSettings::class] as $component) {
            Livewire::test($component, ['personId' => $person->id])->assertForbidden();
        }
    }

    public function test_revoked_admin_access_is_rechecked_on_livewire_updates(): void
    {
        $person = $this->makePerson();
        $admin = $this->admin();
        $this->actingAs($admin);
        $component = Livewire::test(PersonAccounts::class, ['personId' => $person->id]);
        $admin->update(['role' => 'user']);
        $component->call('selectType', 'instagram')->assertForbidden();
    }

    public function test_person_identity_cannot_be_changed_through_livewire(): void
    {
        $this->actingAs($this->admin());
        $person = $this->makePerson();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PersonAccounts::class, ['personId' => $person->id])
            ->set('personId', $person->id + 1);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function makePerson(): Person
    {
        return Person::create([
            'platform' => 'instagram',
            'profile_key' => 'nora-brandt',
            'profile_label' => 'Nora Brandt',
            'person_first_name' => 'Nora',
            'person_last_name' => 'Brandt',
            'person_city' => 'Hamburg',
            'browser_profile_path' => 'browser-profiles/instagram/nora',
            'cookie_file_path' => 'cookies/nora-cookies.json',
            'is_active' => true,
        ]);
    }
}
