<?php

namespace App\Livewire\Admin\Config;

use Illuminate\Encryption\Encrypter;
use App\Models\File as StoredFile;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Base\ScraperProfileSyncClient;
use App\Services\Scraper\ScraperProfileDatabaseStore;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Component;

class PersonList extends Component
{
    public string $activeProfileId = '';

    public string $selectedProfileId = '';

    public array $activeProfileIds = [];

    public array $profileOptions = [];

    public bool $showCreateProfileModal = false;

    public bool $showProfileModal = false;

    public bool $showRuntimeSettingsModal = false;

    public string $editingProfileId = '';

    public string $newProfileLabel = '';

    public bool $newAutoLoginEnabled = false;

    public string $newLoginUsername = '';

    public string $newLoginPassword = '';

    public string $profileLabel = 'instagram-default';

    public string $personFirstName = '';

    public string $personLastName = '';

    public string $personAlias = '';

    public string $personDateOfBirth = '';

    public string $personGender = '';

    public string $personEmail = '';

    public string $personPhone = '';

    public string $personAddressLine1 = '';

    public string $personAddressLine2 = '';

    public string $personPostalCode = '';

    public string $personState = '';

    public string $personCountry = '';

    public string $personCity = '';

    public string $personTimezone = '';

    public string $personNotes = '';

    public string $botStatus = 'manual';

    public bool $persistentProfileEnabled = true;

    public string $browserProfilePath = 'browser-profiles/instagram/default';

    public string $cookieFilePath = 'cookies/instagram-cookies.json';

    public bool $headlessEnabled = true;

    public bool $autoLoginEnabled = false;

    public string $loginUsername = '';

    public string $loginPassword = '';

    public bool $hasStoredPassword = false;

    public int $navigationTimeoutSeconds = 120;

    public int $postLoginWaitMs = 2500;

    public int $typingDelayMs = 35;

    public int $relationshipListProcessTimeoutSeconds = 14400;

    public int $relationshipListMaxScrollRounds = 100000;

    public int $followerListMaxItems = 0;

    public int $followingListMaxItems = 0;

    public ?array $sessionBuildResult = null;

    public ?array $baseSyncResult = null;

    public function mount(): void
    {
        $collection = $this->loadProfileCollection();

        $this->activeProfileId = $collection['active_profile_id'];
        $this->selectedProfileId = $collection['active_profile_id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->fillFormFromProfile($this->findProfile($collection, $this->activeProfileId));
    }

    public function saveSettings(): void
    {
        $this->saveProfile();
    }

    public function saveProfile(): void
    {
        if ($this->editingProfileId === '') {
            return;
        }

        $collection = $this->loadProfileCollection();

        try {
            $collection = $this->persistProfileFormInCollection($collection, $this->editingProfileId);
        } catch (\RuntimeException) {
            return;
        }

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $collection['active_profile_id'];
        $this->selectedProfileId = $this->editingProfileId;
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->loginPassword = '';
        $this->showProfileModal = false;
        $this->editingProfileId = '';

        session()->flash('success', 'Person wurde gespeichert.');
        $this->dispatch('showAlert', 'Person wurde gespeichert.', 'success');
    }

    public function addProfile(): void
    {
        $this->openCreateProfileModal();
    }

    public function openCreateProfileModal(): void
    {
        $collection = $this->loadProfileCollection();
        $nextNumber = count($collection['profiles']) + 1;

        $this->newProfileLabel = 'instagram-profil-'.$nextNumber;
        $this->newAutoLoginEnabled = false;
        $this->newLoginUsername = '';
        $this->newLoginPassword = '';
        $this->showCreateProfileModal = true;

        $this->resetErrorBag();
    }

    public function closeCreateProfileModal(): void
    {
        $this->showCreateProfileModal = false;
        $this->newProfileLabel = '';
        $this->newAutoLoginEnabled = false;
        $this->newLoginUsername = '';
        $this->newLoginPassword = '';

        $this->resetErrorBag();
    }

    public function createProfile(): void
    {
        try {
            $validated = $this->validate([
                'newProfileLabel' => ['required', 'string', 'max:120'],
                'newAutoLoginEnabled' => ['boolean'],
                'newLoginUsername' => ['nullable', 'string', 'max:255'],
                'newLoginPassword' => ['nullable', 'string', 'max:255'],
            ]);

            if ($validated['newAutoLoginEnabled'] && (blank($validated['newLoginUsername']) || blank($validated['newLoginPassword']))) {
                $this->addError('newLoginUsername', 'Bitte hinterlege fuer den Auto-Login einen Instagram-Benutzernamen und ein Passwort.');

                return;
            }

            $collection = $this->loadProfileCollection();
        } catch (\RuntimeException) {
            $this->showCreateProfileModal = false;

            return;
        }

        try {
            $profile = $this->makeNewProfile($collection, $validated);
        } catch (\RuntimeException) {
            return;
        }

        $collection['profiles'][] = $profile;
        $collection['active_profile_id'] = $profile['id'];
        $collection['active_profile_ids'] = $this->appendActiveProfileId($collection['active_profile_ids'] ?? [], $profile['id']);
        $collection['updated_at'] = now()->toIso8601String();

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $profile['id'];
        $this->selectedProfileId = $profile['id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->fillFormFromProfile($profile);
        $this->sessionBuildResult = null;
        $this->showCreateProfileModal = false;
        $this->newLoginPassword = '';

        session()->flash('success', 'Neue Person wurde angelegt.');
        $this->dispatch('showAlert', 'Neue Person wurde angelegt.', 'success');
    }

    public function switchProfile(string $profileId): void
    {
        $this->makePrimaryProfile($profileId);
    }

    public function makePrimaryProfile(string $profileId): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Die ausgewaehlte Person wurde nicht gefunden.', 'error');

            return;
        }

        $collection['active_profile_id'] = $profile['id'];
        $collection['active_profile_ids'] = $this->appendActiveProfileId($collection['active_profile_ids'] ?? [], $profile['id']);
        $collection['updated_at'] = now()->toIso8601String();

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $profile['id'];
        $this->selectedProfileId = $profile['id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->sessionBuildResult = null;

        $this->dispatch('showAlert', 'Standard-Account wurde gewechselt.', 'success');
    }

    public function toggleProfileActive(string $profileId): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Die Person wurde nicht gefunden.', 'error');

            return;
        }

        $activeProfileIds = $collection['active_profile_ids'];

        if (in_array($profileId, $activeProfileIds, true)) {
            if (count($activeProfileIds) <= 1) {
                $this->dispatch('showAlert', 'Mindestens ein Account muss fuer Analysen aktiv bleiben.', 'warning');

                return;
            }

            $activeProfileIds = array_values(array_filter(
                $activeProfileIds,
                static fn (string $activeProfileId): bool => $activeProfileId !== $profileId,
            ));
        } else {
            $activeProfileIds = $this->appendActiveProfileId($activeProfileIds, $profileId);
        }

        if ($collection['active_profile_id'] === $profileId && ! in_array($profileId, $activeProfileIds, true)) {
            $collection['active_profile_id'] = $activeProfileIds[0];
        }

        $collection['active_profile_ids'] = $activeProfileIds;
        $collection['updated_at'] = now()->toIso8601String();

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $collection['active_profile_id'];
        $this->selectedProfileId = $this->selectedProfileId === $profileId ? $collection['active_profile_id'] : $this->selectedProfileId;
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->sessionBuildResult = null;

        $this->dispatch('showAlert', 'Account-Aktivierung wurde aktualisiert.', 'success');
    }

    public function editProfile(string $profileId): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Die Person wurde nicht gefunden.', 'error');

            return;
        }

        $this->editingProfileId = $profile['id'];
        $this->selectedProfileId = $profile['id'];
        $this->fillFormFromProfile($profile);
        $this->showProfileModal = true;
    }

    public function selectProfile(string $profileId): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Die Person wurde nicht gefunden.', 'error');

            return;
        }

        $this->selectedProfileId = $profile['id'];
    }

    public function selectAndEditProfile(string $profileId): void
    {
        $this->selectProfile($profileId);
        $this->editProfile($profileId);
    }

    public function closeProfileModal(): void
    {
        $this->showProfileModal = false;
        $this->editingProfileId = '';
        $this->loginPassword = '';

        $this->resetErrorBag();
    }

    public function openRuntimeSettingsModal(): void
    {
        $collection = $this->loadProfileCollection();
        $profile = $this->findProfile($collection, $this->activeProfileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Aktive Person wurde nicht gefunden.', 'error');

            return;
        }

        $this->fillFormFromProfile($profile);
        $this->showRuntimeSettingsModal = true;
    }

    public function closeRuntimeSettingsModal(): void
    {
        $this->showRuntimeSettingsModal = false;

        $this->resetErrorBag();
    }

    public function saveRuntimeSettings(): void
    {
        $collection = $this->loadProfileCollection();

        try {
            $collection = $this->persistRuntimeSettingsInCollection($collection, $this->activeProfileId);
        } catch (\RuntimeException) {
            return;
        }

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $collection['active_profile_id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->showRuntimeSettingsModal = false;

        session()->flash('success', 'Scraper-Einstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'Scraper-Einstellungen wurden gespeichert.', 'success');
    }

    public function deleteProfile(string $profileId): void
    {
        $collection = $this->loadProfileCollection();

        $remainingProfiles = array_values(array_filter(
            $collection['profiles'],
            static fn (array $profile): bool => ($profile['id'] ?? null) !== $profileId,
        ));

        if (count($remainingProfiles) === count($collection['profiles'])) {
            $this->dispatch('showAlert', 'Die Person wurde nicht gefunden.', 'error');

            return;
        }

        if ($remainingProfiles === []) {
            $remainingProfiles[] = $this->defaultProfile('default');
        }

        $collection['profiles'] = $remainingProfiles;
        $remainingProfileIds = array_column($remainingProfiles, 'id');
        $collection['active_profile_ids'] = array_values(array_intersect($collection['active_profile_ids'], $remainingProfileIds));

        if ($collection['active_profile_ids'] === []) {
            $collection['active_profile_ids'] = [$remainingProfiles[0]['id']];
        }

        if ($collection['active_profile_id'] === $profileId) {
            $collection['active_profile_id'] = $collection['active_profile_ids'][0];
        }

        $collection['updated_at'] = now()->toIso8601String();

        $this->persistProfileCollection($collection);
        $this->activeProfileId = $collection['active_profile_id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);
        $this->sessionBuildResult = null;
        $this->showProfileModal = $this->showProfileModal && $this->editingProfileId !== $profileId;
        $this->editingProfileId = $this->editingProfileId === $profileId ? '' : $this->editingProfileId;

        session()->flash('success', 'Person wurde geloescht.');
        $this->dispatch('showAlert', 'Person wurde geloescht.', 'success');
    }

    public function clearProfileScrapeBlock(string $profileId): void
    {
        $databaseStore = app(ScraperProfileDatabaseStore::class);

        if (! $databaseStore->isAvailable()) {
            $this->dispatch('showAlert', 'Die Personen-Datenbank ist nicht verfuegbar.', 'warning');

            return;
        }

        $databaseStore->clearScrapeBlock($profileId);

        $collection = $this->loadProfileCollection();
        $this->activeProfileId = $collection['active_profile_id'];
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);

        session()->flash('success', 'Person wurde entsperrt.');
        $this->dispatch('showAlert', 'Person wurde entsperrt.', 'success');
    }

    public function buildInstagramSession(): void
    {
        try {
            $storedSettings = $this->activeProfileSettings();
        } catch (\RuntimeException) {
            return;
        }

        try {
            $runtimeConfig = $this->buildRuntimeConfig($storedSettings);
            $runtimeConfigPath = $this->writeRuntimeConfigFile($runtimeConfig);
            $nodeScript = $this->resolveNodeScriptPath();

            $result = Process::path(base_path())
                ->timeout(max(60, min(90, ((int) ($storedSettings['navigation_timeout_seconds'] ?? 120)) + 30)))
                ->run([
                    $this->resolveNodeBinary(),
                    $nodeScript,
                    '',
                    $runtimeConfigPath,
                    'login-session',
                ]);
            app(ScraperProfileDatabaseStore::class)->syncCookiePayloadsFromRuntimeConfig($runtimeConfig);
        } catch (\Throwable $exception) {
            $this->sessionBuildResult = [
                'ok' => false,
                'statusMessage' => 'Der Session-Aufbau konnte nicht gestartet werden.',
                'warnings' => [$exception->getMessage()],
                'notes' => [],
            ];

            $this->dispatch('showAlert', 'Der Session-Aufbau konnte nicht gestartet werden.', 'error');

            return;
        } finally {
            if (isset($runtimeConfigPath) && File::exists($runtimeConfigPath)) {
                File::delete($runtimeConfigPath);
            }
        }

        $payload = json_decode(trim($result->output()), true);

        if (is_array($payload)) {
            $this->sessionBuildResult = $payload;
            $this->hasStoredPassword = true;
            $this->loginPassword = '';

            $this->dispatch(
                'showAlert',
                $payload['ok'] ? 'Instagram-Session wurde aufgebaut.' : 'Instagram-Session konnte nicht aufgebaut werden.',
                $payload['ok'] ? 'success' : 'warning'
            );

            return;
        }

        if (! $result->successful()) {
            $warnings = array_values(array_filter([
                trim($result->errorOutput()),
                trim($result->output()),
            ]));

            $this->sessionBuildResult = [
                'ok' => false,
                'statusMessage' => 'Der Session-Aufbau ist beim Start des Node-Skripts fehlgeschlagen.',
                'warnings' => $warnings !== [] ? $warnings : ['Der Node-Prozess wurde mit einem Fehler beendet.'],
                'notes' => [],
            ];

            $this->dispatch('showAlert', 'Der Session-Aufbau ist fehlgeschlagen.', 'error');

            return;
        }

        $this->sessionBuildResult = [
            'ok' => false,
            'statusMessage' => 'Der Session-Aufbau hat kein gueltiges JSON-Ergebnis geliefert.',
            'warnings' => [trim($result->errorOutput())],
            'notes' => [],
        ];

        $this->dispatch('showAlert', 'Der Session-Aufbau ist fehlgeschlagen.', 'error');
    }

    public function clearStoredPassword(): void
    {
        $collection = $this->loadProfileCollection();
        $profileId = $this->editingProfileId !== '' ? $this->editingProfileId : $this->activeProfileId;
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            return;
        }

        $profile['login_password_encrypted'] = null;
        $profile['login_password_base_encrypted'] = null;
        $profile['updated_at'] = now()->toIso8601String();

        $collection = $this->replaceProfile($collection, $profile);
        $collection['updated_at'] = now()->toIso8601String();

        $this->persistProfileCollection($collection);

        $this->loginPassword = '';
        $this->hasStoredPassword = false;
        $this->activeProfileIds = $collection['active_profile_ids'];
        $this->profileOptions = $this->buildProfileOptions($collection);

        session()->flash('success', 'Das gespeicherte Instagram-Passwort wurde entfernt.');
        $this->dispatch('showAlert', 'Das gespeicherte Instagram-Passwort wurde entfernt.', 'success');
    }

    public function syncProfilesToBase(): void
    {
        $collection = $this->loadProfileCollection();

        try {
            $payload = app(ScraperProfileSyncClient::class)->syncCollection($collection);
        } catch (\Throwable $exception) {
            $this->baseSyncResult = [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];

            Person::query()
                ->whereIn('profile_key', array_column($collection['profiles'] ?? [], 'id'))
                ->update([
                    'base_sync_status' => 'failed',
                    'base_sync_error' => $exception->getMessage(),
                ]);

            $this->profileOptions = $this->buildProfileOptions($this->loadProfileCollection());
            $this->dispatch('showAlert', 'Scraper-Profile konnten nicht an die Base gesendet werden.', 'error');

            return;
        }

        $syncedKeys = array_values(array_filter($payload['profile_keys'] ?? []));

        Person::query()
            ->whereIn('profile_key', $syncedKeys)
            ->update([
                'base_sync_status' => 'synced',
                'base_synced_at' => now('UTC'),
                'base_sync_error' => null,
            ]);

        $this->baseSyncResult = [
            'ok' => true,
            'message' => sprintf('%d Scraper-Profile wurden an die Base gesendet.', (int) ($payload['synced'] ?? count($syncedKeys))),
        ];
        $this->profileOptions = $this->buildProfileOptions($this->loadProfileCollection());

        session()->flash('success', $this->baseSyncResult['message']);
        $this->dispatch('showAlert', $this->baseSyncResult['message'], 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.person-list');
    }

    protected function persistSettings(): array
    {
        return $this->activeProfileSettings();
    }

    protected function activeProfileSettings(): array
    {
        $collection = $this->loadProfileCollection();
        $profileId = $this->activeProfileId !== ''
            ? $this->activeProfileId
            : $collection['active_profile_id'];
        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            throw new \RuntimeException('Aktive Person wurde nicht gefunden.');
        }

        return $profile;
    }

    protected function persistProfileFormInCollection(array $collection, string $profileId): array
    {
        $validated = $this->validate([
            'profileLabel' => ['required', 'string', 'max:120'],
            'personFirstName' => ['nullable', 'string', 'max:255'],
            'personLastName' => ['nullable', 'string', 'max:255'],
            'personAlias' => ['nullable', 'string', 'max:255'],
            'personDateOfBirth' => ['nullable', 'date'],
            'personGender' => ['nullable', 'string', 'max:80'],
            'personEmail' => ['nullable', 'email', 'max:255'],
            'personPhone' => ['nullable', 'string', 'max:80'],
            'personAddressLine1' => ['nullable', 'string', 'max:255'],
            'personAddressLine2' => ['nullable', 'string', 'max:255'],
            'personPostalCode' => ['nullable', 'string', 'max:40'],
            'personState' => ['nullable', 'string', 'max:120'],
            'personCountry' => ['nullable', 'string', 'max:120'],
            'personCity' => ['nullable', 'string', 'max:120'],
            'personTimezone' => ['nullable', 'string', 'max:120'],
            'personNotes' => ['nullable', 'string', 'max:5000'],
            'botStatus' => ['required', 'string', 'in:manual,ready,training,disabled'],
            'persistentProfileEnabled' => ['boolean'],
            'browserProfilePath' => ['required', 'string', 'max:255'],
            'cookieFilePath' => ['required', 'string', 'max:255'],
            'autoLoginEnabled' => ['boolean'],
            'loginUsername' => ['nullable', 'string', 'max:255'],
            'loginPassword' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Die Person wurde nicht gefunden.', 'error');

            throw new \RuntimeException('Person wurde nicht gefunden.');
        }

        $existingPassword = $profile['login_password_encrypted'] ?? null;
        $existingBasePassword = $profile['login_password_base_encrypted'] ?? null;

        $encryptedPassword = $existingPassword;
        $runtimePassword = $this->decryptRuntimePassword($existingPassword);

        if (filled($validated['loginPassword'] ?? null)) {
            $runtimePassword = $validated['loginPassword'];
            $encryptedPassword = Crypt::encryptString($runtimePassword);
        }

        $baseEncryptedPassword = $existingBasePassword;

        if (filled($runtimePassword) && (filled($validated['loginPassword'] ?? null) || blank($baseEncryptedPassword))) {
            try {
                $baseEncryptedPassword = $this->encryptPasswordForBaseAppKey($runtimePassword);
            } catch (\RuntimeException $exception) {
                $this->addError('loginPassword', $exception->getMessage());

                throw $exception;
            }
        }

        $passwordConfigured = filled($runtimePassword) || filled($baseEncryptedPassword);

        if ($validated['autoLoginEnabled'] && (blank($validated['loginUsername']) || ! $passwordConfigured)) {
            $this->addError('loginUsername', 'Bitte hinterlege fuer den Auto-Login einen Instagram-Benutzernamen und ein Passwort.');

            throw new \RuntimeException('Auto-Login-Konfiguration unvollstaendig.');
        }

        if ($validated['autoLoginEnabled'] && blank($baseEncryptedPassword)) {
            $this->addError('loginPassword', 'Das gespeicherte Passwort konnte nicht fuer die Base-Installation aufbereitet werden.');

            throw new \RuntimeException('Base-Passwortverschluesselung fehlgeschlagen.');
        }

        $loginUsername = trim((string) ($validated['loginUsername'] ?? ''));

        $profile = [
            ...$profile,
            'id' => $this->normalizeProfileId($profile['id'] ?? $profileId),
            'profile_label' => trim($validated['profileLabel']),
            'person_first_name' => $this->nullableString($validated['personFirstName'] ?? null),
            'person_last_name' => $this->nullableString($validated['personLastName'] ?? null),
            'person_alias' => $this->nullableString($validated['personAlias'] ?? null),
            'person_date_of_birth' => $this->nullableString($validated['personDateOfBirth'] ?? null),
            'person_gender' => $this->nullableString($validated['personGender'] ?? null),
            'person_email' => $this->nullableString($validated['personEmail'] ?? null),
            'person_phone' => $this->nullableString($validated['personPhone'] ?? null),
            'person_address_line1' => $this->nullableString($validated['personAddressLine1'] ?? null),
            'person_address_line2' => $this->nullableString($validated['personAddressLine2'] ?? null),
            'person_postal_code' => $this->nullableString($validated['personPostalCode'] ?? null),
            'person_state' => $this->nullableString($validated['personState'] ?? null),
            'person_country' => $this->nullableString($validated['personCountry'] ?? null),
            'person_city' => $this->nullableString($validated['personCity'] ?? null),
            'person_timezone' => $this->nullableString($validated['personTimezone'] ?? null),
            'person_notes' => $this->nullableString($validated['personNotes'] ?? null),
            'bot_status' => $validated['botStatus'],
            'identity_profile' => array_filter([
                'name' => trim(collect([$validated['personFirstName'] ?? '', $validated['personLastName'] ?? ''])->implode(' ')),
                'alias' => $this->nullableString($validated['personAlias'] ?? null),
                'address_line1' => $this->nullableString($validated['personAddressLine1'] ?? null),
                'address_line2' => $this->nullableString($validated['personAddressLine2'] ?? null),
                'postal_code' => $this->nullableString($validated['personPostalCode'] ?? null),
                'state' => $this->nullableString($validated['personState'] ?? null),
                'city' => $this->nullableString($validated['personCity'] ?? null),
                'country' => $this->nullableString($validated['personCountry'] ?? null),
                'timezone' => $this->nullableString($validated['personTimezone'] ?? null),
            ], static fn ($value): bool => $value !== null && $value !== ''),
            'bot_profile' => array_filter([
                'status' => $validated['botStatus'],
                'prepared_for_automation' => in_array($validated['botStatus'], ['ready', 'training'], true),
            ], static fn ($value): bool => $value !== null),
            'persistent_profile_enabled' => (bool) $validated['persistentProfileEnabled'],
            'browser_profile_path' => trim($validated['browserProfilePath']),
            'cookie_file_path' => trim($validated['cookieFilePath']),
            'headless_enabled' => true,
            'auto_login_enabled' => (bool) $validated['autoLoginEnabled'],
            'login_username' => $loginUsername,
            'social_accounts' => $this->socialAccountsForInstagram(
                $loginUsername,
                $profile['social_accounts'] ?? [],
                (bool) $validated['autoLoginEnabled'],
            ),
            'login_password_encrypted' => $encryptedPassword,
            'login_password_base_encrypted' => $baseEncryptedPassword,
            'updated_at' => now()->toIso8601String(),
        ];

        $collection = $this->replaceProfile($collection, $profile);
        $collection['updated_at'] = now()->toIso8601String();

        return $collection;
    }

    protected function persistRuntimeSettingsInCollection(array $collection, string $profileId): array
    {
        $validated = $this->validate([
            'navigationTimeoutSeconds' => ['required', 'integer', 'min:30', 'max:300'],
            'postLoginWaitMs' => ['required', 'integer', 'min:500', 'max:15000'],
            'typingDelayMs' => ['required', 'integer', 'min:0', 'max:500'],
            'relationshipListProcessTimeoutSeconds' => ['required', 'integer', 'min:14400', 'max:21600'],
            'relationshipListMaxScrollRounds' => ['required', 'integer', 'min:20', 'max:1000000'],
            'followerListMaxItems' => ['required', 'integer', 'min:0', 'max:1000000'],
            'followingListMaxItems' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $profile = $this->findProfile($collection, $profileId);

        if (! $profile) {
            $this->dispatch('showAlert', 'Aktive Person wurde nicht gefunden.', 'error');

            throw new \RuntimeException('Aktive Person wurde nicht gefunden.');
        }

        $profile = [
            ...$profile,
            'navigation_timeout_seconds' => (int) $validated['navigationTimeoutSeconds'],
            'post_login_wait_ms' => (int) $validated['postLoginWaitMs'],
            'typing_delay_ms' => (int) $validated['typingDelayMs'],
            'relationship_list_process_timeout_seconds' => (int) $validated['relationshipListProcessTimeoutSeconds'],
            'relationship_list_max_scroll_rounds' => (int) $validated['relationshipListMaxScrollRounds'],
            'follower_list_max_items' => (int) $validated['followerListMaxItems'],
            'following_list_max_items' => (int) $validated['followingListMaxItems'],
            'updated_at' => now()->toIso8601String(),
        ];

        $collection = $this->replaceProfile($collection, $profile);
        $collection['updated_at'] = now()->toIso8601String();

        return $collection;
    }

    protected function loadProfileCollection(): array
    {
        $settings = Schema::hasTable('settings')
            ? Setting::getValue('scraper', 'instagram_profile')
            : null;
        $legacyCollection = is_array($settings)
            ? $this->normalizeProfileCollection($settings)
            : $this->normalizeProfileCollection([]);
        $databaseStore = app(ScraperProfileDatabaseStore::class);

        if ($databaseStore->isAvailable()) {
            $databaseStore->importLegacyCollectionIfMissing($legacyCollection, $this->resolveBaseStorageRootOrNull());
            $databaseCollection = $databaseStore->loadProfileCollection($legacyCollection);

            if (is_array($databaseCollection)) {
                $normalizedCollection = $this->normalizeProfileCollection($databaseCollection);
                $databaseStore->hydrateCookieFilesFromCollection($normalizedCollection, $this->resolveBaseStorageRootOrNull());

                return $normalizedCollection;
            }
        }

        return $legacyCollection;
    }

    protected function persistProfileCollection(array $collection): void
    {
        $collection = $this->normalizeProfileCollection($collection);
        $databaseStore = app(ScraperProfileDatabaseStore::class);

        if ($databaseStore->isAvailable()) {
            $databaseStore->persistProfileCollection($collection, $this->resolveBaseStorageRootOrNull());
        }

        if (Schema::hasTable('settings')) {
            Setting::setValue('scraper', 'instagram_profile', $collection);
        }
    }

    protected function normalizeProfileCollection(array $settings): array
    {
        $profiles = [];

        if (isset($settings['profiles']) && is_array($settings['profiles'])) {
            foreach ($settings['profiles'] as $key => $profile) {
                if (! is_array($profile)) {
                    continue;
                }

                $profiles[] = $this->normalizeProfile($profile, is_string($key) ? $key : null);
            }
        } elseif ($settings !== []) {
            $profiles[] = $this->normalizeProfile($settings, 'default');
        }

        if ($profiles === []) {
            $profiles[] = $this->defaultProfile('default');
        }

        $activeProfileId = $this->normalizeProfileId($settings['active_profile_id'] ?? ($profiles[0]['id'] ?? 'default'));

        if (! $this->findProfile(['profiles' => $profiles], $activeProfileId)) {
            $activeProfileId = $profiles[0]['id'];
        }

        $profileIds = array_column($profiles, 'id');
        $activeProfileIds = $this->normalizeActiveProfileIds($settings['active_profile_ids'] ?? null, $profileIds);

        if ($activeProfileIds === []) {
            $activeProfileIds = [$activeProfileId];
        }

        if (! in_array($activeProfileId, $activeProfileIds, true)) {
            $activeProfileIds = $this->appendActiveProfileId($activeProfileIds, $activeProfileId);
        }

        return [
            'active_profile_id' => $activeProfileId,
            'active_profile_ids' => $activeProfileIds,
            'profiles' => array_values($profiles),
            'updated_at' => (string) ($settings['updated_at'] ?? now()->toIso8601String()),
        ];
    }

    protected function normalizeProfile(array $profile, ?string $fallbackId = null): array
    {
        $id = $this->normalizeProfileId($profile['id'] ?? $fallbackId);
        $loginUsername = trim((string) ($profile['login_username'] ?? ''));
        $autoLoginEnabled = (bool) ($profile['auto_login_enabled'] ?? false);

        return [
            'id' => $id,
            'profile_label' => $this->normalizeText($profile['profile_label'] ?? 'instagram-default', 'instagram-default'),
            'person_first_name' => trim((string) ($profile['person_first_name'] ?? '')),
            'person_last_name' => trim((string) ($profile['person_last_name'] ?? '')),
            'person_alias' => trim((string) ($profile['person_alias'] ?? '')),
            'person_date_of_birth' => trim((string) ($profile['person_date_of_birth'] ?? '')),
            'person_gender' => trim((string) ($profile['person_gender'] ?? '')),
            'person_email' => trim((string) ($profile['person_email'] ?? '')),
            'person_phone' => trim((string) ($profile['person_phone'] ?? '')),
            'person_address_line1' => trim((string) ($profile['person_address_line1'] ?? '')),
            'person_address_line2' => trim((string) ($profile['person_address_line2'] ?? '')),
            'person_postal_code' => trim((string) ($profile['person_postal_code'] ?? '')),
            'person_state' => trim((string) ($profile['person_state'] ?? '')),
            'person_country' => trim((string) ($profile['person_country'] ?? '')),
            'person_city' => trim((string) ($profile['person_city'] ?? '')),
            'person_timezone' => trim((string) ($profile['person_timezone'] ?? '')),
            'person_notes' => trim((string) ($profile['person_notes'] ?? '')),
            'avatar_path' => trim((string) ($profile['avatar_path'] ?? '')),
            'identity_profile' => is_array($profile['identity_profile'] ?? null) ? $profile['identity_profile'] : [],
            'bot_profile' => is_array($profile['bot_profile'] ?? null) ? $profile['bot_profile'] : [],
            'bot_status' => in_array(($profile['bot_status'] ?? 'manual'), ['manual', 'ready', 'training', 'disabled'], true)
                ? $profile['bot_status']
                : 'manual',
            'persistent_profile_enabled' => (bool) ($profile['persistent_profile_enabled'] ?? true),
            'browser_profile_path' => $this->normalizeText($profile['browser_profile_path'] ?? 'browser-profiles/instagram/default', 'browser-profiles/instagram/default'),
            'cookie_file_path' => $this->normalizeText($profile['cookie_file_path'] ?? 'cookies/instagram-cookies.json', 'cookies/instagram-cookies.json'),
            'headless_enabled' => true,
            'auto_login_enabled' => $autoLoginEnabled,
            'login_username' => $loginUsername,
            'social_accounts' => $this->normalizeSocialAccounts($profile['social_accounts'] ?? [], $loginUsername, $autoLoginEnabled),
            'login_password_encrypted' => $this->nullableString($profile['login_password_encrypted'] ?? null),
            'login_password_base_encrypted' => $this->nullableString($profile['login_password_base_encrypted'] ?? null),
            'navigation_timeout_seconds' => max(30, min(300, (int) ($profile['navigation_timeout_seconds'] ?? 120))),
            'post_login_wait_ms' => max(500, min(15000, (int) ($profile['post_login_wait_ms'] ?? 2500))),
            'typing_delay_ms' => max(0, min(500, (int) ($profile['typing_delay_ms'] ?? 35))),
            'relationship_list_process_timeout_seconds' => max(14400, min(21600, (int) ($profile['relationship_list_process_timeout_seconds'] ?? 14400))),
            'relationship_list_max_scroll_rounds' => max(20, min(1000000, (int) ($profile['relationship_list_max_scroll_rounds'] ?? 100000))),
            'follower_list_max_items' => max(0, min(1000000, (int) ($profile['follower_list_max_items'] ?? 0))),
            'following_list_max_items' => max(0, min(1000000, (int) ($profile['following_list_max_items'] ?? 0))),
            'cookie_payload' => (string) ($profile['cookie_payload'] ?? ''),
            'cookie_payload_hash' => (string) ($profile['cookie_payload_hash'] ?? ''),
            'cookie_count' => max(0, (int) ($profile['cookie_count'] ?? 0)),
            'session_cookie_present' => (bool) ($profile['session_cookie_present'] ?? false),
            'cookies_synced_at' => $this->nullableString($profile['cookies_synced_at'] ?? null),
            'scrape_blocked_at' => $this->nullableString($profile['scrape_blocked_at'] ?? null),
            'scrape_blocked_until' => $this->nullableString($profile['scrape_blocked_until'] ?? null),
            'scrape_blocked_reason' => $this->nullableString($profile['scrape_blocked_reason'] ?? null),
            'is_scrape_blocked' => $this->isFutureTimestamp($profile['scrape_blocked_until'] ?? null),
            'scrape_block_remaining_seconds' => $this->remainingSecondsUntil($profile['scrape_blocked_until'] ?? null),
            'base_sync_status' => in_array(($profile['base_sync_status'] ?? 'pending'), ['pending', 'synced', 'failed'], true)
                ? ($profile['base_sync_status'] ?? 'pending')
                : 'pending',
            'base_synced_at' => $this->nullableString($profile['base_synced_at'] ?? null),
            'base_sync_error' => trim((string) ($profile['base_sync_error'] ?? '')),
            'updated_at' => (string) ($profile['updated_at'] ?? now()->toIso8601String()),
        ];
    }

    protected function defaultProfile(?string $id = null, string $label = 'instagram-default'): array
    {
        return [
            'id' => $this->normalizeProfileId($id),
            'profile_label' => $label,
            'person_first_name' => '',
            'person_last_name' => '',
            'person_alias' => '',
            'person_date_of_birth' => '',
            'person_gender' => '',
            'person_email' => '',
            'person_phone' => '',
            'person_address_line1' => '',
            'person_address_line2' => '',
            'person_postal_code' => '',
            'person_state' => '',
            'person_country' => '',
            'person_city' => '',
            'person_timezone' => '',
            'person_notes' => '',
            'avatar_path' => '',
            'identity_profile' => [],
            'bot_profile' => ['status' => 'manual', 'prepared_for_automation' => false],
            'bot_status' => 'manual',
            'social_accounts' => [],
            'persistent_profile_enabled' => true,
            'browser_profile_path' => 'browser-profiles/instagram/default',
            'cookie_file_path' => 'cookies/instagram-cookies.json',
            'headless_enabled' => true,
            'auto_login_enabled' => false,
            'login_username' => '',
            'login_password_encrypted' => null,
            'login_password_base_encrypted' => null,
            'navigation_timeout_seconds' => 120,
            'post_login_wait_ms' => 2500,
            'typing_delay_ms' => 35,
            'relationship_list_process_timeout_seconds' => 14400,
            'relationship_list_max_scroll_rounds' => 100000,
            'follower_list_max_items' => 0,
            'following_list_max_items' => 0,
            'cookie_payload' => '',
            'cookie_payload_hash' => '',
            'cookie_count' => 0,
            'session_cookie_present' => false,
            'cookies_synced_at' => null,
            'scrape_blocked_at' => null,
            'scrape_blocked_until' => null,
            'scrape_blocked_reason' => null,
            'is_scrape_blocked' => false,
            'scrape_block_remaining_seconds' => 0,
            'base_sync_status' => 'pending',
            'base_synced_at' => null,
            'base_sync_error' => '',
            'updated_at' => now()->toIso8601String(),
        ];
    }

    protected function makeNewProfile(array $collection, array $validated = []): array
    {
        $profileNumber = count($collection['profiles']) + 1;
        $label = $this->normalizeText($validated['newProfileLabel'] ?? 'instagram-profil-'.$profileNumber, 'instagram-profil-'.$profileNumber);
        $username = trim((string) ($validated['newLoginUsername'] ?? ''));
        $password = (string) ($validated['newLoginPassword'] ?? '');
        $autoLoginEnabled = (bool) ($validated['newAutoLoginEnabled'] ?? false);
        $slug = Str::slug($username !== '' ? $username : $label) ?: 'instagram-profil-'.$profileNumber;

        while ($this->profilePathExists($collection, 'browser-profiles/instagram/'.$slug)) {
            $profileNumber++;
            $slug = (Str::slug($username !== '' ? $username : $label) ?: 'instagram-profil').'-'.$profileNumber;
        }

        $encryptedPassword = null;
        $baseEncryptedPassword = null;

        if (filled($password)) {
            $encryptedPassword = Crypt::encryptString($password);

            try {
                $baseEncryptedPassword = $this->encryptPasswordForBaseAppKey($password);
            } catch (\RuntimeException $exception) {
                $this->addError('newLoginPassword', $exception->getMessage());

                throw $exception;
            }
        }

        if ($autoLoginEnabled && blank($baseEncryptedPassword)) {
            $this->addError('newLoginPassword', 'Das gespeicherte Passwort konnte nicht fuer die Base-Installation aufbereitet werden.');

            throw new \RuntimeException('Base-Passwortverschluesselung fehlgeschlagen.');
        }

        return [
            ...$this->defaultProfile(Str::uuid()->toString(), $label),
            'browser_profile_path' => 'browser-profiles/instagram/'.$slug,
            'cookie_file_path' => 'cookies/'.$slug.'-cookies.json',
            'auto_login_enabled' => $autoLoginEnabled,
            'login_username' => $username,
            'social_accounts' => $this->socialAccountsForInstagram($username, [], $autoLoginEnabled),
            'login_password_encrypted' => $encryptedPassword,
            'login_password_base_encrypted' => $baseEncryptedPassword,
        ];
    }

    protected function buildProfileOptions(array $collection): array
    {
        return array_map(function (array $profile) use ($collection): array {
            return [
                'id' => $profile['id'],
                'label' => $profile['profile_label'],
                'avatar_url' => $this->profileAvatarUrl($profile),
                'display_name' => trim(collect([$profile['person_first_name'] ?? '', $profile['person_last_name'] ?? ''])->filter()->implode(' '))
                    ?: ($profile['person_alias'] ?: $profile['profile_label']),
                'person_alias' => $profile['person_alias'] ?? '',
                'person_city' => $profile['person_city'] ?? '',
                'person_country' => $profile['person_country'] ?? '',
                'bot_status' => $profile['bot_status'] ?? 'manual',
                'social_accounts' => $profile['social_accounts'] ?? [],
                'login_username' => $profile['login_username'],
                'browser_profile_path' => $profile['browser_profile_path'],
                'cookie_file_path' => $profile['cookie_file_path'],
                'has_stored_password' => filled($profile['login_password_encrypted'] ?? null)
                    || filled($profile['login_password_base_encrypted'] ?? null),
                'is_active' => in_array($profile['id'], $collection['active_profile_ids'], true),
                'is_primary' => $profile['id'] === $collection['active_profile_id'],
                'is_scrape_blocked' => (bool) ($profile['is_scrape_blocked'] ?? false),
                'scrape_blocked_until' => $profile['scrape_blocked_until'] ?? null,
                'scrape_blocked_until_label' => $this->formatTimestampLabel($profile['scrape_blocked_until'] ?? null),
                'scrape_blocked_reason' => $profile['scrape_blocked_reason'] ?? null,
                'scrape_block_remaining_seconds' => (int) ($profile['scrape_block_remaining_seconds'] ?? 0),
                'base_sync_status' => $profile['base_sync_status'] ?? 'pending',
                'base_synced_at_label' => $this->formatTimestampLabel($profile['base_synced_at'] ?? null),
                'base_sync_error' => $profile['base_sync_error'] ?? '',
            ];
        }, $collection['profiles']);
    }

    protected function profileAvatarUrl(array $profile): string
    {
        $profileId = trim((string) ($profile['id'] ?? ''));

        if ($profileId === '') {
            return '';
        }

        $person = Person::query()
            ->where('platform', 'instagram')
            ->where('profile_key', $profileId)
            ->first();

        if (! $person) {
            return $this->temporaryPrivateFileUrl($profile['avatar_path'] ?? '', $profileId);
        }

        $avatarFile = $person->files()
            ->where('type', 'avatar')
            ->latest('id')
            ->first();

        if (! $avatarFile && $person->avatar_path) {
            $avatarFile = $this->createListPersonAvatarFileFromPath($person, $person->avatar_path);
        }

        if ($avatarFile) {
            return $avatarFile->getEphemeralPublicUrl(10);
        }

        return $this->temporaryPrivateFileUrl($person->avatar_path ?: ($profile['avatar_path'] ?? ''), (string) $person->getKey());
    }

    protected function temporaryPrivateFileUrl(mixed $path, string $cacheKey): string
    {
        $path = trim((string) $path);

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            return '';
        }

        $file = new StoredFile([
            'path' => $path,
            'disk' => 'private',
            'mime_type' => Storage::disk('private')->mimeType($path),
        ]);
        $file->id = 'person-list-avatar-'.$cacheKey.'-'.md5($path);

        return $file->getEphemeralPublicUrl(10);
    }

    protected function createListPersonAvatarFileFromPath(Person $person, string $path): ?StoredFile
    {
        $path = trim($path);

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            return null;
        }

        $person->loadMissing('filePool');

        $sourceFile = StoredFile::query()
            ->where('disk', 'private')
            ->where('path', $path)
            ->latest('id')
            ->first();

        return $person->files()->create([
            'filepool_id' => $person->filePool?->id,
            'user_id' => auth()->id() ?: $sourceFile?->user_id,
            'name' => $sourceFile?->name ?: 'Profilbild',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $sourceFile?->mime_type ?: Storage::disk('private')->mimeType($path),
            'type' => 'avatar',
            'size' => $sourceFile?->size ?: Storage::disk('private')->size($path),
        ]);
    }

    protected function normalizeActiveProfileIds(mixed $activeProfileIds, array $profileIds): array
    {
        if (! is_array($activeProfileIds)) {
            return [];
        }

        $profileIds = array_map(static fn (mixed $profileId): string => (string) $profileId, $profileIds);
        $normalizedIds = [];

        foreach ($activeProfileIds as $activeProfileId) {
            $activeProfileId = trim((string) $activeProfileId);

            if ($activeProfileId === '' || ! in_array($activeProfileId, $profileIds, true)) {
                continue;
            }

            $normalizedIds[] = $activeProfileId;
        }

        return array_values(array_unique($normalizedIds));
    }

    protected function appendActiveProfileId(array $activeProfileIds, string $profileId): array
    {
        $activeProfileIds[] = $profileId;

        return array_values(array_unique(array_filter(
            $activeProfileIds,
            static fn (mixed $activeProfileId): bool => is_string($activeProfileId) && trim($activeProfileId) !== '',
        )));
    }

    protected function findProfile(array $collection, ?string $profileId): ?array
    {
        $profileId = $this->normalizeProfileId($profileId);

        foreach (($collection['profiles'] ?? []) as $profile) {
            if (($profile['id'] ?? null) === $profileId) {
                return $profile;
            }
        }

        return null;
    }

    protected function replaceProfile(array $collection, array $profile): array
    {
        $profile = $this->normalizeProfile($profile);
        $replaced = false;

        foreach ($collection['profiles'] as $index => $existingProfile) {
            if (($existingProfile['id'] ?? null) !== $profile['id']) {
                continue;
            }

            $collection['profiles'][$index] = $profile;
            $replaced = true;

            break;
        }

        if (! $replaced) {
            $collection['profiles'][] = $profile;
        }

        return $this->normalizeProfileCollection($collection);
    }

    protected function fillFormFromProfile(?array $profile): void
    {
        $profile = $profile ? $this->normalizeProfile($profile) : $this->defaultProfile('default');

        $this->profileLabel = $profile['profile_label'];
        $this->personFirstName = $profile['person_first_name'];
        $this->personLastName = $profile['person_last_name'];
        $this->personAlias = $profile['person_alias'];
        $this->personDateOfBirth = $profile['person_date_of_birth'];
        $this->personGender = $profile['person_gender'];
        $this->personEmail = $profile['person_email'];
        $this->personPhone = $profile['person_phone'];
        $this->personAddressLine1 = $profile['person_address_line1'];
        $this->personAddressLine2 = $profile['person_address_line2'];
        $this->personPostalCode = $profile['person_postal_code'];
        $this->personState = $profile['person_state'];
        $this->personCountry = $profile['person_country'];
        $this->personCity = $profile['person_city'];
        $this->personTimezone = $profile['person_timezone'];
        $this->personNotes = $profile['person_notes'];
        $this->botStatus = $profile['bot_status'];
        $this->persistentProfileEnabled = $profile['persistent_profile_enabled'];
        $this->browserProfilePath = $profile['browser_profile_path'];
        $this->cookieFilePath = $profile['cookie_file_path'];
        $this->headlessEnabled = true;
        $this->autoLoginEnabled = $profile['auto_login_enabled'];
        $this->loginUsername = $profile['login_username'];
        $this->loginPassword = '';
        $this->navigationTimeoutSeconds = $profile['navigation_timeout_seconds'];
        $this->postLoginWaitMs = $profile['post_login_wait_ms'];
        $this->typingDelayMs = $profile['typing_delay_ms'];
        $this->relationshipListProcessTimeoutSeconds = $profile['relationship_list_process_timeout_seconds'];
        $this->relationshipListMaxScrollRounds = $profile['relationship_list_max_scroll_rounds'];
        $this->followerListMaxItems = $profile['follower_list_max_items'];
        $this->followingListMaxItems = $profile['following_list_max_items'];
        $this->hasStoredPassword = filled($profile['login_password_encrypted'] ?? null)
            || filled($profile['login_password_base_encrypted'] ?? null);

        $this->resetErrorBag();
    }

    protected function profilePathExists(array $collection, string $browserProfilePath): bool
    {
        foreach ($collection['profiles'] as $profile) {
            if (($profile['browser_profile_path'] ?? null) === $browserProfilePath) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeProfileId(mixed $profileId): string
    {
        $profileId = trim((string) $profileId);

        return $profileId !== '' ? $profileId : Str::uuid()->toString();
    }

    protected function normalizeText(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    protected function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    protected function normalizeSocialAccounts(mixed $accounts, string $instagramUsername = '', bool $loginEnabled = false): array
    {
        $accounts = is_array($accounts) ? $accounts : [];

        return $this->socialAccountsForInstagram($instagramUsername, $accounts, $loginEnabled);
    }

    protected function socialAccountsForInstagram(string $username, array $existingAccounts = [], bool $loginEnabled = false): array
    {
        $username = trim(ltrim($username, '@'));

        if ($username === '') {
            unset($existingAccounts['instagram']);

            return $existingAccounts;
        }

        $instagramAccount = is_array($existingAccounts['instagram'] ?? null)
            ? $existingAccounts['instagram']
            : [];

        $existingAccounts['instagram'] = [
            ...$instagramAccount,
            'platform' => 'instagram',
            'username' => $username,
            'handle' => '@'.$username,
            'managed' => true,
            'login_enabled' => $loginEnabled,
        ];

        return $existingAccounts;
    }

    protected function isFutureTimestamp(mixed $value): bool
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return false;
        }

        try {
            return Carbon::parse((string) $value)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function remainingSecondsUntil(mixed $value): int
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return 0;
        }

        try {
            $until = Carbon::parse((string) $value);

            return $until->isFuture() ? max(0, now()->diffInSeconds($until, false)) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function formatTimestampLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)
                ->timezone(config('app.timezone', 'Europe/Berlin'))
                ->format('d.m.Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function buildRuntimeConfig(array $storedSettings): array
    {
        $decryptedPassword = $this->decryptRuntimePassword($storedSettings['login_password_encrypted'] ?? null);
        $passwordConfigured = filled($storedSettings['login_password_encrypted'] ?? null)
            || filled($storedSettings['login_password_base_encrypted'] ?? null);

        return [
            'profileId' => trim((string) ($storedSettings['id'] ?? '')),
            'profileLabel' => (string) ($storedSettings['profile_label'] ?? 'instagram-default'),
            // Session-Aufbau schreibt Cookies in die Cookie-Datei; ein persistentes Chrome-Profil darf hier nicht geteilt werden,
            // weil Chrome sonst mit "browser is already running for ... userDataDir" sperrt.
            'persistentProfileEnabled' => false,
            'browserProfilePath' => $this->resolveStorageAwarePath($storedSettings['browser_profile_path'] ?? 'browser-profiles/instagram/default'),
            'cookieFilePath' => $this->resolveStorageAwarePath($storedSettings['cookie_file_path'] ?? 'cookies/instagram-cookies.json'),
            'headlessEnabled' => false,
            'autoLoginEnabled' => (bool) ($storedSettings['auto_login_enabled'] ?? false),
            'loginUsername' => trim((string) ($storedSettings['login_username'] ?? '')),
            'loginPassword' => $decryptedPassword,
            'loginPasswordConfigured' => $passwordConfigured,
            'loginPasswordDecryptable' => $decryptedPassword !== null || ! $passwordConfigured,
            'loginPasswordSource' => $decryptedPassword !== null ? 'admin_encrypted' : null,
            'navigationTimeoutMs' => max(30000, ((int) ($storedSettings['navigation_timeout_seconds'] ?? 120)) * 1000),
            'postLoginWaitMs' => max(500, (int) ($storedSettings['post_login_wait_ms'] ?? 2500)),
            'typingDelayMs' => max(0, (int) ($storedSettings['typing_delay_ms'] ?? 35)),
            'followerListMaxItems' => max(0, (int) ($storedSettings['follower_list_max_items'] ?? 0)),
            'followingListMaxItems' => max(0, (int) ($storedSettings['following_list_max_items'] ?? 0)),
            'relationshipListMaxScrollRounds' => max(20, (int) ($storedSettings['relationship_list_max_scroll_rounds'] ?? 100000)),
        ];
    }

    protected function decryptRuntimePassword(mixed $encryptedPassword): ?string
    {
        if (! is_string($encryptedPassword) || trim($encryptedPassword) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedPassword);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function writeRuntimeConfigFile(array $runtimeConfig): string
    {
        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'instagram-scraper-session-'.Str::uuid().'.json';
        File::put($path, json_encode($runtimeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    protected function resolveStorageAwarePath(mixed $configuredPath): string
    {
        $configuredPath = trim((string) $configuredPath);

        if ($configuredPath === '') {
            return storage_path('app');
        }

        if ($this->isAbsolutePath($configuredPath)) {
            return $configuredPath;
        }

        return storage_path('app'.DIRECTORY_SEPARATOR.ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredPath),
            DIRECTORY_SEPARATOR
        ));
    }

    protected function resolveBaseStorageRootOrNull(): ?string
    {
        return storage_path('app');
    }

    protected function resolveNodeScriptPath(): string
    {
        $nodeScript = base_path('resources/node/scraper/scrape-instagram.cjs');

        if (! File::exists($nodeScript)) {
            throw new \RuntimeException(sprintf(
                'Das lokale Node-Skript fuer den Session-Aufbau wurde nicht gefunden: %s',
                $nodeScript
            ));
        }

        return $nodeScript;
    }

    protected function encryptPasswordForBaseAppKey(?string $plainPassword): ?string
    {
        $plainPassword = trim((string) $plainPassword);

        if ($plainPassword === '') {
            return null;
        }

        $baseAppKey = $this->resolveBaseAppKey();

        if (! $baseAppKey) {
            return null;
        }

        return $this->makeBaseProjectEncrypter($baseAppKey)->encryptString($plainPassword);
    }

    protected function resolveBaseAppKey(): ?string
    {
        $configuredKey = trim(
            (string) config('services.webaidetective_base.app_key', ''),
            " \t\n\r\0\x0B\"'"
        );

        if ($configuredKey !== '') {
            return $configuredKey;
        }

        $fallbackSettings = Setting::getValue('services', 'webaidetective_base');

        if (is_array($fallbackSettings) && isset($fallbackSettings['app_key'])) {
            return trim((string) ($fallbackSettings['app_key'] ?? ''), " \t\n\r\0\x0B\"'");
        }

        return null;
    }

    protected function makeBaseProjectEncrypter(string $appKey): Encrypter
    {
        $key = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new \RuntimeException('Der APP_KEY der Base-Installation ist ungueltig.');
        }

        return new Encrypter($key, config('app.cipher', 'AES-256-CBC'));
    }

    protected function resolveNodeBinary(): string
    {
        $environmentCandidates = array_filter([
            env('SCRAPER_NODE_BINARY'),
            env('NODE_BINARY'),
            getenv('SCRAPER_NODE_BINARY') ?: null,
            getenv('NODE_BINARY') ?: null,
        ], static fn (mixed $candidate): bool => is_string($candidate) && trim($candidate) !== '');

        $candidates = array_merge($environmentCandidates, [
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            '/usr/bin/node',
            '/usr/local/bin/node',
            '/bin/node',
            '/snap/bin/node',
            '/usr/bin/nodejs',
            '/usr/local/bin/nodejs',
        ]);

        foreach (glob('/opt/plesk/node/*/bin/node') ?: [] as $pleskCandidate) {
            $candidates[] = $pleskCandidate;
        }

        $homeDirectory = getenv('HOME') ?: null;

        if (is_string($homeDirectory) && trim($homeDirectory) !== '') {
            foreach (glob($homeDirectory.'/.nvm/versions/node/*/bin/node') ?: [] as $nvmCandidate) {
                $candidates[] = $nvmCandidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        foreach (['node', 'nodejs'] as $binaryName) {
            $resolvedBinary = Process::run(['sh', '-lc', sprintf('command -v %s 2>/dev/null', $binaryName)]);

            if (! $resolvedBinary->successful()) {
                continue;
            }

            $candidate = trim($resolvedBinary->output());

            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Node.js wurde fuer den Session-Aufbau nicht gefunden. Geprueft wurden feste Pfade, Plesk-/NVM-Installationen sowie `command -v node` und `command -v nodejs`.');
    }

    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }
}



