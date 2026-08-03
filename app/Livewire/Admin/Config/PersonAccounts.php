<?php

namespace App\Livewire\Admin\Config;

use App\Models\Person;
use App\Services\Persons\PersonAccountRegistry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Zusammengefuehrter Accounts-Tab der Person.
 *
 * Ersetzt die frueher getrennten Tabs "E-Mail" und "Social Media". Das
 * Mailkonto bleibt fachlich bei `PersonEmailAccountSettings` (IMAP/SMTP,
 * Registrierung, Webmail-Session) und wird hier nur eingebettet; die
 * Portalkonten laufen ueber `PersonAccountRegistry`.
 *
 * Instagram-Zugangsdaten werden bewusst NICHT hier geschrieben: Passwort und
 * Base-Verschluesselung haengen an `PersonList::saveProfile()`. Die Karte
 * verlinkt daher auf den bestehenden Dialog des Elternkomponenten.
 */
class PersonAccounts extends Component
{
    public int $personId;

    public string $selectedType = 'email';

    public bool $showForm = false;

    public string $formUsername = '';

    public string $formAddress = '';

    public string $formEmail = '';

    public string $formPassword = '';

    public string $formStatus = 'active';

    public string $formNotes = '';

    public bool $formHasStoredPassword = false;

    public function mount(int $personId): void
    {
        $this->personId = $personId;
    }

    public function render()
    {
        $person = $this->person();
        $registry = $this->registry();

        $accounts = $person ? $registry->all($person) : [];
        $selected = $accounts[$this->selectedType] ?? null;

        return view('livewire.admin.config.person-accounts', [
            'person' => $person,
            'accounts' => $accounts,
            'selected' => $selected,
            'statuses' => PersonAccountRegistry::STATUSES,
            'connectedCount' => collect($accounts)->where('isConfigured', true)->count(),
            'credentialCount' => collect($accounts)->where('hasPassword', true)->count(),
        ]);
    }

    public function selectType(string $type): void
    {
        $type = $this->registry()->normalizeType($type);

        if ($type === null) {
            return;
        }

        $this->selectedType = $type;
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function editAccount(?string $type = null): void
    {
        $person = $this->person();
        $type = $this->registry()->normalizeType($type ?? $this->selectedType);

        if (! $person || $type === null || $type === 'email') {
            return;
        }

        $account = $this->registry()->account($person, $type);

        $this->selectedType = $type;
        $this->formUsername = (string) $account['username'];
        $this->formAddress = (string) $account['address'];
        $this->formEmail = (string) $account['email'];
        $this->formPassword = '';
        $this->formStatus = (string) $account['status'];
        $this->formNotes = (string) $account['notes'];
        $this->formHasStoredPassword = (bool) $account['hasPassword'];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->formPassword = '';
        $this->resetErrorBag();
    }

    public function saveAccount(): void
    {
        $person = $this->person();

        if (! $person || $this->selectedType === 'email') {
            return;
        }

        $validated = $this->validate([
            'formUsername' => ['nullable', 'string', 'max:255'],
            'formAddress' => ['nullable', 'string', 'max:2048'],
            'formEmail' => ['nullable', 'email', 'max:255'],
            'formPassword' => ['nullable', 'string', 'max:512'],
            'formStatus' => ['required', 'string', Rule::in(array_keys(PersonAccountRegistry::STATUSES))],
            'formNotes' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->registry()->saveSocialAccount($person, $this->selectedType, [
            'username' => $validated['formUsername'] ?? '',
            'address' => $validated['formAddress'] ?? '',
            'email' => $validated['formEmail'] ?? '',
            // Instagram-Passwoerter brauchen zusaetzlich die Base-Verschluesselung
            // und laufen deshalb ausschliesslich ueber den Zugangsdaten-Dialog.
            'password' => $this->selectedType === 'instagram' ? '' : ($validated['formPassword'] ?? ''),
            'status' => $validated['formStatus'],
            'notes' => $validated['formNotes'] ?? '',
        ]);

        $this->formPassword = '';
        $this->showForm = false;
        $this->dispatch('refreshPersonDetail');
        $this->dispatch('showAlert', $this->registry()->label($this->selectedType).'-Account gespeichert.', 'success');
    }

    public function clearStoredPassword(): void
    {
        $person = $this->person();

        if (! $person || $this->selectedType === 'email' || $this->selectedType === 'instagram') {
            return;
        }

        $this->registry()->saveSocialAccount($person, $this->selectedType, [
            'clearPassword' => true,
        ]);

        $this->formHasStoredPassword = false;
        $this->formPassword = '';
        $this->dispatch('refreshPersonDetail');
        $this->dispatch('showAlert', 'Gespeichertes Passwort wurde geloescht.', 'success');
    }

    public function deleteAccount(string $type): void
    {
        $person = $this->person();
        $type = $this->registry()->normalizeType($type);

        if (! $person || $type === null || $type === 'email') {
            return;
        }

        $this->registry()->deleteSocialAccount($person, $type);

        $this->showForm = false;
        $this->dispatch('refreshPersonDetail');
        $this->dispatch('showAlert', $this->registry()->label($type).'-Account entfernt.', 'success');
    }

    #[On('refreshPersonAccounts')]
    public function refreshAccounts(): void
    {
        // Das Rendern liest die Person ohnehin frisch; der Listener existiert,
        // damit Geschwisterkomponenten (Mailkonto, Session-Aufbau) die Liste
        // aktualisieren koennen.
        $this->formHasStoredPassword = $this->formHasStoredPassword && $this->showForm;
    }

    protected function person(): ?Person
    {
        return Person::query()->with('emailAccounts')->find($this->personId);
    }

    protected function registry(): PersonAccountRegistry
    {
        return app(PersonAccountRegistry::class);
    }
}
