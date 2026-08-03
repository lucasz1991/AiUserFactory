<?php

namespace App\Services\Persons;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use Illuminate\Support\Facades\Crypt;

/**
 * Kanonische Kontenquelle einer Person.
 *
 * Vorher lagen die Zugangsdaten an drei Stellen: das Mailkonto in
 * `person_email_accounts` (gespiegelt nach `persons.metadata['email_account']`),
 * der Instagram-Zugang direkt in `persons.login_username` /
 * `persons.login_password_encrypted` und alles Uebrige unstrukturiert in
 * `persons.social_accounts`. Diese Klasse liest und schreibt alle drei Quellen
 * hinter einer Form und erzeugt daraus die Workflow-Punktstruktur
 * `person.accounts.<typ>.username|address|password`.
 *
 * Sicherheitsvertrag: `payload()` liefert Klartext-Passwoerter nur mit
 * `withSecrets: true`. Jeder Weg, der Kontext nach aussen schreibt (Status-JSON,
 * Debugpaket, Copilot-Log), muss `person.accounts.*.password` entfernen —
 * siehe Teamprotokoll-Regel 6.
 */
class PersonAccountRegistry
{
    /**
     * Unterstuetzte Kontotypen in Anzeigereihenfolge.
     *
     * `kind` unterscheidet das Mailkonto (eigene Tabelle, IMAP/SMTP, Webmail-
     * Session) von den Portalkonten. `profile_url` baut aus dem Benutzernamen
     * eine Profiladresse, wenn keine eigene hinterlegt ist.
     */
    public const TYPES = [
        'email' => [
            'label' => 'E-Mail-Account',
            'kind' => 'email',
            'portal_url' => '',
            'profile_url' => '',
            'address_label' => 'E-Mail-Adresse',
            'accent' => 'sky',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'kind' => 'social',
            'portal_url' => 'https://www.instagram.com/',
            'profile_url' => 'https://www.instagram.com/%s/',
            'address_label' => 'Profiladresse',
            'accent' => 'pink',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'kind' => 'social',
            'portal_url' => 'https://www.facebook.com/',
            'profile_url' => 'https://www.facebook.com/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'blue',
        ],
        'x' => [
            'label' => 'X',
            'kind' => 'social',
            'portal_url' => 'https://x.com/',
            'profile_url' => 'https://x.com/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'slate',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'kind' => 'social',
            'portal_url' => 'https://www.tiktok.com/',
            'profile_url' => 'https://www.tiktok.com/@%s',
            'address_label' => 'Profiladresse',
            'accent' => 'slate',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'kind' => 'social',
            'portal_url' => 'https://www.linkedin.com/',
            'profile_url' => 'https://www.linkedin.com/in/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'blue',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'kind' => 'social',
            'portal_url' => 'https://www.youtube.com/',
            'profile_url' => 'https://www.youtube.com/@%s',
            'address_label' => 'Kanaladresse',
            'accent' => 'red',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'kind' => 'social',
            'portal_url' => 'https://www.pinterest.com/',
            'profile_url' => 'https://www.pinterest.com/%s/',
            'address_label' => 'Profiladresse',
            'accent' => 'red',
        ],
        'threads' => [
            'label' => 'Threads',
            'kind' => 'social',
            'portal_url' => 'https://www.threads.net/',
            'profile_url' => 'https://www.threads.net/@%s',
            'address_label' => 'Profiladresse',
            'accent' => 'slate',
        ],
        'reddit' => [
            'label' => 'Reddit',
            'kind' => 'social',
            'portal_url' => 'https://www.reddit.com/',
            'profile_url' => 'https://www.reddit.com/user/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'amber',
        ],
        'snapchat' => [
            'label' => 'Snapchat',
            'kind' => 'social',
            'portal_url' => 'https://www.snapchat.com/',
            'profile_url' => 'https://www.snapchat.com/add/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'amber',
        ],
        'telegram' => [
            'label' => 'Telegram',
            'kind' => 'social',
            'portal_url' => 'https://web.telegram.org/',
            'profile_url' => 'https://t.me/%s',
            'address_label' => 'Profiladresse',
            'accent' => 'sky',
        ],
    ];

    public const STATUSES = [
        'active' => 'Aktiv',
        'inactive' => 'Inaktiv',
        'pending' => 'In Vorbereitung',
        'blocked' => 'Gesperrt',
    ];

    /**
     * Kontotyp normalisieren. Unbekannte Werte fallen bewusst auf `null`, damit
     * kein Tippfehler eine neue Schluesselstruktur anlegt.
     */
    public function normalizeType(mixed $type): ?string
    {
        $type = strtolower(trim((string) $type));

        if ($type === 'twitter') {
            $type = 'x';
        }

        if ($type === 'mail' || $type === 'e-mail') {
            $type = 'email';
        }

        return array_key_exists($type, self::TYPES) ? $type : null;
    }

    public function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? ucfirst($type);
    }

    /**
     * Alle Konten einer Person in Anzeigereihenfolge.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(Person $person, bool $withSecrets = false): array
    {
        $accounts = [];

        foreach (array_keys(self::TYPES) as $type) {
            $accounts[$type] = $this->account($person, $type, $withSecrets);
        }

        return $accounts;
    }

    /**
     * Nur die Konten, die tatsaechlich Daten tragen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function configured(Person $person, bool $withSecrets = false): array
    {
        return array_filter(
            $this->all($person, $withSecrets),
            static fn (array $account): bool => (bool) $account['isConfigured'],
        );
    }

    /**
     * Ein einzelnes Konto in der kanonischen Form.
     *
     * @return array<string, mixed>
     */
    public function account(Person $person, string $type, bool $withSecrets = false): array
    {
        $type = $this->normalizeType($type) ?? 'email';

        return $type === 'email'
            ? $this->emailAccount($person, $withSecrets)
            : $this->socialAccount($person, $type, $withSecrets);
    }

    /**
     * Workflow-Punktstruktur `person.accounts.<typ>.…`.
     *
     * Bewusst flach und stabil benannt: `username`, `address`, `password`,
     * `email`, `url`, `provider`, `status`, `hasPassword`. `password` ist nur
     * mit `withSecrets: true` gefuellt.
     *
     * @return array<string, array<string, mixed>>
     */
    public function workflowPayload(Person $person, bool $withSecrets = false): array
    {
        $payload = [];

        foreach ($this->all($person, $withSecrets) as $type => $account) {
            $payload[$type] = [
                'type' => $type,
                'label' => $account['label'],
                'username' => (string) $account['username'],
                'address' => (string) $account['address'],
                'password' => $withSecrets ? (string) $account['password'] : '',
                'email' => (string) $account['email'],
                'url' => (string) $account['url'],
                'provider' => (string) $account['provider'],
                'status' => (string) $account['status'],
                'hasPassword' => (bool) $account['hasPassword'],
                'isConfigured' => (bool) $account['isConfigured'],
            ];
        }

        return $payload;
    }

    /**
     * Alle waehlbaren Datenpfade als flache Liste (Pfad => Beschriftung).
     * Wird sowohl vom Task-Katalog als auch von der Accounts-Oberflaeche
     * genutzt, damit beide Seiten dieselben Pfade zeigen.
     *
     * @return array<string, string>
     */
    public static function dataPathsFor(string $type): array
    {
        $label = self::TYPES[$type]['label'] ?? ucfirst($type);
        $addressLabel = self::TYPES[$type]['address_label'] ?? 'Adresse';

        return [
            'person.accounts.'.$type.'.username' => $label.': Benutzername',
            'person.accounts.'.$type.'.address' => $label.': '.$addressLabel,
            'person.accounts.'.$type.'.password' => $label.': Passwort',
            'person.accounts.'.$type.'.email' => $label.': Hinterlegte E-Mail',
            'person.accounts.'.$type.'.url' => $label.': Portal-URL',
        ];
    }

    // ==================================================================
    // Schreiben
    // ==================================================================

    /**
     * Portalkonto speichern. Fuer `email` ist bewusst kein Schreibweg
     * vorgesehen — Mailkonten laufen weiter ueber `PersonEmailAccountSettings`
     * mit IMAP/SMTP, Registrierung und Webmail-Session.
     *
     * Instagram bleibt zusaetzlich auf `persons.login_username` gespiegelt,
     * weil Auto-Login, Session-Aufbau und Base-Sync diese Spalten lesen. Das
     * Instagram-Passwort wird hier NICHT geschrieben: es braucht zusaetzlich
     * die Base-Verschluesselung aus `PersonList::saveProfile()`.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveSocialAccount(Person $person, string $type, array $data): void
    {
        $type = $this->normalizeType($type);

        if ($type === null || $type === 'email') {
            return;
        }

        $accounts = is_array($person->social_accounts) ? $person->social_accounts : [];
        $existing = is_array($accounts[$type] ?? null) ? $accounts[$type] : [];

        $username = ltrim(trim((string) ($data['username'] ?? '')), '@');
        $password = (string) ($data['password'] ?? '');

        $encrypted = $existing['password_encrypted'] ?? null;

        if (array_key_exists('clearPassword', $data) && $data['clearPassword']) {
            $encrypted = null;
        } elseif (trim($password) !== '') {
            $encrypted = Crypt::encryptString($password);
        }

        $accounts[$type] = [
            ...$existing,
            'platform' => $type,
            'username' => $username,
            'handle' => $username !== '' ? '@'.$username : '',
            'address' => trim((string) ($data['address'] ?? ($existing['address'] ?? ''))),
            'email' => trim((string) ($data['email'] ?? ($existing['email'] ?? ''))),
            'password_encrypted' => $encrypted,
            'status' => array_key_exists((string) ($data['status'] ?? ''), self::STATUSES)
                ? (string) $data['status']
                : ($existing['status'] ?? 'active'),
            'notes' => trim((string) ($data['notes'] ?? ($existing['notes'] ?? ''))),
            'updated_at' => now()->toIso8601String(),
        ];

        if ($type === 'instagram') {
            $accounts[$type]['login_enabled'] = (bool) $person->auto_login_enabled;
            $accounts[$type]['managed'] = true;
        }

        $attributes = ['social_accounts' => $accounts];

        // Instagram-Benutzername bleibt die fuehrende Spalte fuer Auto-Login
        // und Session-Aufbau; sonst liefe der Workflow gegen einen anderen
        // Namen als die Oberflaeche anzeigt.
        if ($type === 'instagram') {
            $attributes['login_username'] = $username;
        }

        $person->forceFill($attributes)->save();
    }

    /**
     * Portalkonto vollstaendig entfernen (inklusive gespeichertem Passwort).
     */
    public function deleteSocialAccount(Person $person, string $type): void
    {
        $type = $this->normalizeType($type);

        if ($type === null || $type === 'email') {
            return;
        }

        $accounts = is_array($person->social_accounts) ? $person->social_accounts : [];
        unset($accounts[$type]);

        $attributes = ['social_accounts' => $accounts];

        if ($type === 'instagram') {
            $attributes['login_username'] = '';
        }

        $person->forceFill($attributes)->save();
    }

    // ==================================================================
    // Lesen
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    protected function emailAccount(Person $person, bool $withSecrets): array
    {
        /** @var PersonEmailAccount|null $primary */
        $primary = $person->relationLoaded('emailAccounts')
            ? $person->emailAccounts->firstWhere('is_primary', true) ?? $person->emailAccounts->first()
            : ($person->emailAccounts()->where('is_primary', true)->first() ?? $person->emailAccounts()->first());

        $mirror = is_array(data_get($person->metadata, 'email_account'))
            ? data_get($person->metadata, 'email_account')
            : [];

        $address = trim((string) ($primary?->email ?? ($mirror['email'] ?? $person->person_email ?? '')));
        $username = trim((string) ($primary?->username ?? ($mirror['username'] ?? ''))) ?: $address;
        $provider = trim((string) ($primary?->provider ?? ($mirror['provider'] ?? '')));
        $encrypted = $primary?->password_encrypted ?? ($mirror['password_encrypted'] ?? null);
        $url = trim((string) ($primary?->webmail_url ?? ($mirror['webmail_url'] ?? '')));

        $hasSession = $primary
            ? $primary->hasWebmailSession()
            : trim((string) data_get($mirror, 'webmail_session.payload_encrypted', '')) !== '';

        return $this->normalizeAccount([
            'type' => 'email',
            'username' => $username,
            'address' => $address,
            'password' => $withSecrets ? $this->decrypt($encrypted) : '',
            'email' => $address,
            'url' => $url,
            'provider' => $provider,
            'status' => $address !== '' ? 'active' : 'pending',
            'notes' => trim((string) ($primary?->notes ?? '')),
            'hasPassword' => is_string($encrypted) && trim($encrypted) !== '',
            'hasSession' => $hasSession,
            'accountCount' => $person->relationLoaded('emailAccounts')
                ? $person->emailAccounts->count()
                : $person->emailAccounts()->count(),
            'updatedAt' => optional($primary?->updated_at)->toIso8601String() ?? (string) ($mirror['updated_at'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function socialAccount(Person $person, string $type, bool $withSecrets): array
    {
        $accounts = is_array($person->social_accounts) ? $person->social_accounts : [];
        $stored = is_array($accounts[$type] ?? null) ? $accounts[$type] : [];

        $username = ltrim(trim((string) ($stored['username'] ?? '')), '@');
        $encrypted = $stored['password_encrypted'] ?? null;
        $hasSession = false;

        // Instagram fuehrt Benutzername, Passwort und Session weiterhin in den
        // eigenen Spalten der Person — die Oberflaeche und der Workflow muessen
        // denselben Wert sehen wie der Session-Aufbau.
        if ($type === 'instagram') {
            $username = ltrim(trim((string) ($person->login_username ?: $username)), '@');
            $encrypted = $person->login_password_encrypted ?: $encrypted;
            $hasSession = (bool) $person->session_cookie_present || (int) $person->cookie_count > 0;
        }

        $address = trim((string) ($stored['address'] ?? ''));

        if ($address === '' && $username !== '') {
            $template = self::TYPES[$type]['profile_url'] ?? '';
            $address = $template !== '' ? sprintf($template, $username) : '';
        }

        $hasPassword = is_string($encrypted) && trim($encrypted) !== '';

        if ($type === 'instagram' && ! $hasPassword) {
            $hasPassword = filled($person->login_password_base_encrypted);
        }

        return $this->normalizeAccount([
            'type' => $type,
            'username' => $username,
            'address' => $address,
            'password' => $withSecrets ? $this->decrypt($encrypted) : '',
            'email' => trim((string) ($stored['email'] ?? '')),
            'url' => self::TYPES[$type]['portal_url'] ?? '',
            'provider' => '',
            'status' => (string) ($stored['status'] ?? ($username !== '' ? 'active' : 'pending')),
            'notes' => trim((string) ($stored['notes'] ?? '')),
            'hasPassword' => $hasPassword,
            'hasSession' => $hasSession,
            'accountCount' => $username !== '' ? 1 : 0,
            'updatedAt' => (string) ($stored['updated_at'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    protected function normalizeAccount(array $account): array
    {
        $type = (string) $account['type'];
        $definition = self::TYPES[$type] ?? [];
        $username = (string) ($account['username'] ?? '');
        $address = (string) ($account['address'] ?? '');
        $status = array_key_exists((string) ($account['status'] ?? ''), self::STATUSES)
            ? (string) $account['status']
            : 'pending';

        $isConfigured = $username !== '' || $address !== '';

        return [
            'type' => $type,
            'label' => $definition['label'] ?? ucfirst($type),
            'kind' => $definition['kind'] ?? 'social',
            'accent' => $definition['accent'] ?? 'slate',
            'addressLabel' => $definition['address_label'] ?? 'Adresse',
            'username' => $username,
            'handle' => $username !== '' ? '@'.$username : '',
            'address' => $address,
            'password' => (string) ($account['password'] ?? ''),
            'email' => (string) ($account['email'] ?? ''),
            'url' => (string) ($account['url'] ?? ''),
            'provider' => (string) ($account['provider'] ?? ''),
            'status' => $isConfigured ? $status : 'pending',
            'statusLabel' => self::STATUSES[$isConfigured ? $status : 'pending'] ?? 'In Vorbereitung',
            'notes' => (string) ($account['notes'] ?? ''),
            'hasPassword' => (bool) ($account['hasPassword'] ?? false),
            'hasSession' => (bool) ($account['hasSession'] ?? false),
            'accountCount' => (int) ($account['accountCount'] ?? 0),
            'updatedAt' => (string) ($account['updatedAt'] ?? ''),
            'isConfigured' => $isConfigured,
            'dataPaths' => self::dataPathsFor($type),
        ];
    }

    protected function decrypt(mixed $encrypted): string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }
}
