<?php

namespace Tests\Feature;

use App\Livewire\Admin\Config\PersonAccounts;
use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Models\User;
use App\Services\Persons\PersonAccountRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
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
            ->assertDontSee('mail-secret')
            ->call('selectType', 'facebook')
            ->assertSet('selectedType', 'facebook')
            ->assertSee('person.accounts.facebook.password')
            ->assertDontSee('facebook-secret');
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
