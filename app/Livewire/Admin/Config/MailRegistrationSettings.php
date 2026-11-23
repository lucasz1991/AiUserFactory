<?php

namespace App\Livewire\Admin\Config;

use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\Workflows\VerificationMailboxStartGuard;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class MailRegistrationSettings extends Component
{
    public string $browserEngine = 'cloak-with-chrome-fallback';
    public bool $cloakHumanizeEnabled = false;
    public string $cloakHumanPreset = '';
    public bool $headlessEnabled = false;
    public bool $previewModalEnabled = true;
    public bool $livePreviewEnabled = true;
    public int $livePreviewIntervalSeconds = 3;
    public bool $browserActivityCheckEnabled = true;
    public bool $domDebugEnabled = true;
    public int $navigationTimeoutSeconds = 120;
    public int $observationTimeoutSeconds = 300;

    public bool $verificationMailboxEnabled = false;
    public string $verificationMailboxEmail = '';
    public string $verificationMailboxProvider = '';
    public string $verificationMailboxUsername = '';
    public string $verificationMailboxPassword = '';
    public bool $hasStoredVerificationMailboxPassword = false;
    public string $verificationMailboxWebmailUrl = '';

    /** @var array<int, array{email: string, username: string, hasPassword: bool, addedAt: ?string, consumed: bool}> */
    public array $reserveMailboxes = [];

    /**
     * Eine Zeile pro Adresse, zuletzt verbrauchte zuerst; events neueste zuerst.
     *
     * @var array<int, array{email: string, isActive: bool, events: array<int, array{reason: string, reasonLabel: string, workflowRunId: ?int, consumedAt: ?string, consumedAtLabel: ?string}>}>
     */
    public array $consumedMailboxes = [];

    /** Gespeichertes aktives Postfach, falls verbraucht (Zeile aus $consumedMailboxes), sonst []. */
    public array $activeMailboxConsumption = [];

    public ?string $nextReserveMailboxEmail = null;

    /**
     * Postfach, wie es beim letzten Laden im Formular stand. Weicht das gespeicherte
     * Postfach davon ab (Wechsel am Laufende, zweiter Tab), ist das Formular veraltet.
     *
     * @var array{email?: string, username?: string, provider?: string}
     */
    public array $loadedVerificationMailbox = [];

    /** Postfach, fuer das der laufende Sitzungsaufbau gestartet wurde. */
    public ?string $verificationMailboxSessionEmail = null;

    /** Ergebnis der letzten Postfach-Aktion, sichtbar im Formular. */
    public string $mailboxNotice = '';

    public string $mailboxNoticeType = 'success';

    /** Ein Postfachwechsel beim Speichern raeumt das alte Profil erst nach dem Speichern ab. */
    protected bool $profileCleanupPending = false;

    public string $newReserveMailboxEmail = '';
    public string $newReserveMailboxUsername = '';
    public string $newReserveMailboxPassword = '';

    public bool $providerOneEnabled = true;
    public string $providerOneMode = 'proton_username_check';
    public string $providerOneLabel = '';
    public string $providerOneRegistrationUrl = '';
    public string $providerOneCompletionUrlContains = '';
    public string $providerOneCompletionSelector = '';
    public string $providerOneWebmailUrl = '';

    public bool $providerTwoEnabled = false;
    public string $providerTwoLabel = '';
    public string $providerTwoRegistrationUrl = '';

    public bool $providerThreeEnabled = false;
    public string $providerThreeLabel = '';
    public string $providerThreeRegistrationUrl = '';

    public bool $showRegistrationRunModal = false;
    public ?string $registrationRunId = null;
    public array $registrationRunStatus = [];
    public array $verificationMailboxSessionResult = [];
    public bool $showVerificationMailboxSessionModal = false;
    public ?string $verificationMailboxSessionRunId = null;

    public function mount(MailAccountRegistrationRunner $runner): void
    {
        $this->fillFromSettings($runner->settings());
    }

    public function saveSettings(bool $flash = true): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $this->profileCleanupPending = false;

        // Unter der Postfach-Sperre: Ein gleichzeitiger Wechsel am Laufende wird so
        // nicht vom Formular ueberschrieben.
        $settings = $this->withMailboxLock(
            fn (): array => $runner->saveSettings($this->settingsPayload()),
            'verificationMailboxEmail',
        );

        if ($this->profileCleanupPending) {
            $this->profileCleanupPending = false;

            try {
                app(WorkflowTaskRunner::class)->forgetVerificationMailboxBrowserProfile();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $this->fillFromSettings($settings);

        if ($flash) {
            session()->flash('success', 'Mail-Registrierungseinstellungen wurden gespeichert.');
            $this->dispatch('showAlert', 'Mail-Registrierung gespeichert.', 'success');
        }
    }

    public function startTestRun(): void
    {
        try {
            $this->saveSettings(false);
            $testUsername = 'testlauf-'.now()->format('YmdHis');

            $run = app(MailAccountRegistrationRunner::class)->start([
                'displayName' => 'Testlauf Mail-Registrierung',
                'desiredEmail' => $testUsername.'@proton.me',
                'accountUsername' => $testUsername,
            ]);

            $this->registrationRunId = $run['runId'] ?? null;
            $this->registrationRunStatus = $run;
            $this->showRegistrationRunModal = (bool) ($run['previewModalEnabled'] ?? $this->previewModalEnabled);
            $this->dispatch('showAlert', 'Mail-Registrierung wurde gestartet.', 'success');
        } catch (ValidationException $exception) {
            // Feldfehler (z. B. fehlendes Passwort nach Postfachwechsel) gehoeren ans Formular.
            throw $exception;
        } catch (\Throwable $exception) {
            $this->registrationRunStatus = [
                'state' => 'failed',
                'stage' => 'start-failed',
                'message' => $exception->getMessage(),
                'events' => [],
            ];
            $this->showRegistrationRunModal = $this->previewModalEnabled;
            $this->dispatch('showAlert', 'Mail-Registrierung konnte nicht gestartet werden.', 'error');
        }
    }

    public function refreshRegistrationRun(): void
    {
        if (! $this->registrationRunId) {
            return;
        }

        $run = app(MailAccountRegistrationRunner::class)->readRun($this->registrationRunId);

        if (is_array($run)) {
            $this->registrationRunStatus = $run;
        }
    }

    public function closeRegistrationRunModal(): void
    {
        $this->showRegistrationRunModal = false;
    }

    public function clearVerificationMailboxPassword(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $this->withMailboxLock(function () use ($runner): array {
            $settings = $runner->settings();
            $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];

            // Zeigt das Formular noch ein frueheres Postfach, gehoert das Passwort dazu
            // und nicht zur inzwischen nachgerueckten Adresse.
            if ($this->loadedVerificationMailbox !== []
                && $runner->verificationMailboxIdentity($mailbox) !== $runner->verificationMailboxIdentity($this->loadedVerificationMailbox)) {
                throw ValidationException::withMessages([
                    'verificationMailboxPassword' => 'Das Verifizierungs-Postfach wurde inzwischen gewechselt (aktiv: '
                        .(trim((string) ($mailbox['email'] ?? '')) ?: 'keines').'). Bitte die Seite neu laden.',
                ]);
            }

            $mailbox['password_encrypted'] = null;
            $settings['verification_mailbox'] = $mailbox;

            return $runner->saveSettings($settings);
        }, 'verificationMailboxPassword');
        $this->fillFromSettings($settings);

        session()->flash('success', 'Passwort des Verifikations-Postfachs wurde geloescht.');
        $this->dispatch('showAlert', 'Verifikations-Passwort geloescht.', 'success');
    }

    public function addReserveMailbox(): void
    {
        $validated = $this->validate([
            'newReserveMailboxEmail' => ['required', 'email', 'max:255'],
            'newReserveMailboxUsername' => ['nullable', 'string', 'max:255'],
            'newReserveMailboxPassword' => ['required', 'string', 'max:512'],
        ], [], [
            'newReserveMailboxEmail' => 'E-Mail-Adresse',
            'newReserveMailboxPassword' => 'Passwort',
        ]);
        $runner = app(MailAccountRegistrationRunner::class);
        $guard = app(VerificationMailboxStartGuard::class);
        $email = trim($validated['newReserveMailboxEmail']);
        $entry = [
            'email' => $email,
            'provider' => 'gmx',
            'username' => trim((string) ($validated['newReserveMailboxUsername'] ?? '')),
            'password_encrypted' => Crypt::encryptString($validated['newReserveMailboxPassword']),
            'webmail_url' => $this->defaultWebmailUrl('gmx'),
            'added_at' => now()->toIso8601String(),
        ];

        // Eine Sperre fuer Pruefen, Hinzufuegen und ggf. Aktivieren: Ein Wechsel am
        // Laufende dazwischen kann so nichts ueberschreiben.
        [$settings, $promoted, $consumers] = $this->withMailboxLock(function () use ($runner, $guard, $email, $entry): array {
            $settings = $runner->settings();
            $key = strtolower($email);

            if (strtolower(trim((string) ($settings['verification_mailbox']['email'] ?? ''))) === $key) {
                throw ValidationException::withMessages([
                    'newReserveMailboxEmail' => 'Diese Adresse ist bereits das aktive Verifizierungs-Postfach.',
                ]);
            }

            if (collect($settings['verification_mailbox_reserve'])->contains(fn (array $mailbox): bool => strtolower(trim((string) $mailbox['email'])) === $key)) {
                throw ValidationException::withMessages([
                    'newReserveMailboxEmail' => 'Diese Adresse steht schon in der Reserve-Liste.',
                ]);
            }

            if ($runner->isVerificationMailboxConsumed($email, $settings)) {
                throw ValidationException::withMessages([
                    'newReserveMailboxEmail' => 'Diese Adresse wurde bereits verbraucht ('
                        .$runner->describeConsumedEntries($runner->consumedEntriesFor($email, $settings))
                        .'). Unter "Verbrauchte Adressen" freigeben, falls Proton sie nachweislich wieder annimmt.',
                ]);
            }

            $settings['verification_mailbox_reserve'][] = $entry;
            $promoted = null;
            $consumers = [];
            $activeEmail = trim((string) ($settings['verification_mailbox']['email'] ?? ''));

            // Ist das aktive Postfach leer oder verbraucht, wird die neue Adresse sofort
            // aktiv - ausser ein noch laufender Lauf nutzt das verbrauchte Postfach noch.
            if ($activeEmail === '' || $runner->isVerificationMailboxConsumed($activeEmail, $settings)) {
                $consumers = $activeEmail !== '' ? $guard->pendingConsumerRunIds($activeEmail) : [];

                if ($consumers === []) {
                    [$settings, $promoted] = $runner->promoteNextReserve($settings);
                }
            }

            return [$runner->saveSettings($settings), $promoted, $consumers];
        }, 'newReserveMailboxEmail');

        if ($promoted !== null) {
            $this->afterVerificationMailboxPromotion($settings);
        } else {
            $this->refreshMailboxState($settings);
        }

        $this->reset('newReserveMailboxEmail', 'newReserveMailboxUsername', 'newReserveMailboxPassword');
        $this->notifyMailbox(match (true) {
            $promoted !== null => $promoted['email'].' ist jetzt das aktive Verifizierungs-Postfach (das bisherige war verbraucht).',
            $consumers !== [] => 'Reserve-Adresse wurde hinzugefuegt. Sie wird aktiv, sobald Lauf #'.implode(', #', $consumers).' beendet ist.',
            default => 'Reserve-Adresse wurde hinzugefuegt.',
        });
    }

    public function removeReserveMailbox(string $email): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $this->withMailboxLock(function () use ($runner, $email): array {
            $settings = $runner->settings();
            $settings['verification_mailbox_reserve'] = array_values(array_filter(
                $settings['verification_mailbox_reserve'],
                fn (array $mailbox): bool => strtolower($mailbox['email']) !== strtolower(trim($email)),
            ));

            return $runner->saveSettings($settings);
        }, 'newReserveMailboxEmail');
        $this->refreshMailboxState($settings);
        $this->notifyMailbox('Reserve-Adresse wurde entfernt.');
    }

    /**
     * Holt die naechste unverbrauchte Reserve-Adresse sofort ins aktive Postfach,
     * wenn das aktive verbraucht oder leer ist (sonst passiert das beim naechsten
     * Registrierungsstart automatisch). Nutzt ein noch laufender Lauf das
     * verbrauchte Postfach, wartet der Wechsel auf dessen Ende.
     */
    public function activateNextReserveMailbox(): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $activeEmail = trim((string) ($runner->settings()['verification_mailbox']['email'] ?? ''));
        $consumers = $activeEmail !== '' ? app(VerificationMailboxStartGuard::class)->pendingConsumerRunIds($activeEmail) : [];

        if ($consumers !== []) {
            $this->refreshMailboxState($runner->settings());
            $this->notifyMailbox('Lauf #'.implode(', #', $consumers).' nutzt das Postfach noch. Die Reserve-Adresse wird an seinem Ende automatisch aktiv.', 'warning');

            return;
        }

        try {
            $state = $runner->ensureUsableVerificationMailbox();
        } catch (LockTimeoutException) {
            $this->notifyMailbox('Das Verifizierungs-Postfach wird gerade aktualisiert. Bitte erneut versuchen.', 'warning');

            return;
        }

        $settings = $runner->settings();

        if ($state['status'] !== 'promoted') {
            $this->refreshMailboxState($settings);
            $this->notifyMailbox(
                $state['status'] === 'ok' ? 'Das aktive Verifizierungs-Postfach ist nicht verbraucht.' : 'Keine unverbrauchte Reserve-Adresse vorhanden.',
                $state['status'] === 'ok' ? 'warning' : 'error',
            );

            return;
        }

        $this->afterVerificationMailboxPromotion($settings);
        $this->notifyMailbox($state['active'].' ist jetzt das aktive Verifizierungs-Postfach.');
    }

    /**
     * Entfernt eine Adresse aus der Verbraucht-Liste, wenn Proton sie nachweislich
     * wieder annimmt. Das aktive Postfach bleibt unveraendert.
     */
    public function releaseConsumedMailbox(string $email): void
    {
        $runner = app(MailAccountRegistrationRunner::class);

        try {
            $removed = $runner->releaseConsumedVerificationMailbox($email);
        } catch (LockTimeoutException) {
            $this->notifyMailbox('Das Verifizierungs-Postfach wird gerade aktualisiert. Bitte erneut versuchen.', 'warning');

            return;
        }

        $this->refreshMailboxState($runner->settings());
        $this->notifyMailbox(
            $removed === [] ? 'Die Adresse war nicht als verbraucht vermerkt.' : trim($email).' wurde freigegeben.',
            $removed === [] ? 'warning' : 'success',
        );
    }

    public function updatedVerificationMailboxProvider(mixed $value = null): void
    {
        $this->verificationMailboxProvider = $this->normalizeWebmailProvider($this->verificationMailboxProvider);
        $this->verificationMailboxWebmailUrl = $this->defaultWebmailUrl($this->verificationMailboxProvider);
    }

    public function buildVerificationMailboxWebmailSession(): void
    {
        try {
            $this->saveSettings(false);
            $settings = app(MailAccountRegistrationRunner::class)->settings();
            $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
            $password = $this->decryptStoredPassword($mailbox['password_encrypted'] ?? null);

            $run = app(WebmailSessionRunner::class)->start([
                'provider' => $mailbox['provider'] ?? '',
                'email' => $mailbox['email'] ?? '',
                'username' => ($mailbox['username'] ?? '') ?: ($mailbox['email'] ?? ''),
                'password' => $password,
                'webmailUrl' => $mailbox['webmail_url'] ?? '',
                'browserEngine' => $settings['browser_engine'] ?? 'cloak-with-chrome-fallback',
                'cloakHumanizeEnabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
                'cloakHumanPreset' => $settings['cloak_human_preset'] ?? '',
                'headlessEnabled' => (bool) ($settings['headless_enabled'] ?? false),
                'livePreviewEnabled' => (bool) ($settings['live_preview_enabled'] ?? true),
                'livePreviewIntervalMs' => max(1000, (int) ($settings['live_preview_interval_seconds'] ?? 3) * 1000),
                'livePreviewIntervalSeconds' => max(1, (int) ($settings['live_preview_interval_seconds'] ?? 3)),
                'navigationTimeoutMs' => ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000,
                'observationTimeoutMs' => min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000)),
            ], 'master-mailbox-webmail');

            $this->verificationMailboxSessionRunId = $run['runId'] ?? null;
            $this->verificationMailboxSessionEmail = (string) ($mailbox['email'] ?? '');
            $this->verificationMailboxSessionResult = $run;
            $this->showVerificationMailboxSessionModal = (bool) ($settings['preview_modal_enabled'] ?? true);

            $this->dispatch('showAlert', 'Master-Webmail-Sessionlauf wurde gestartet.', 'success');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->verificationMailboxSessionResult = [
                'ok' => false,
                'state' => 'failed',
                'statusMessage' => 'Master-Webmail-Session konnte nicht gespeichert werden.',
                'message' => 'Master-Webmail-Session konnte nicht gespeichert werden.',
                'warnings' => [$exception->getMessage()],
                'notes' => [],
            ];
            $this->showVerificationMailboxSessionModal = $this->previewModalEnabled;

            $this->dispatch('showAlert', 'Master-Webmail-Session konnte nicht gespeichert werden.', 'error');
        }
    }

    public function refreshVerificationMailboxSessionRun(): void
    {
        if (! $this->verificationMailboxSessionRunId) {
            return;
        }

        $run = app(WebmailSessionRunner::class)->readRun($this->verificationMailboxSessionRunId);

        if (! is_array($run)) {
            return;
        }

        $result = is_array($run['result'] ?? null) ? $run['result'] : [];

        if (! empty($result['encryptedSessionPayload'])) {
            $runner = app(MailAccountRegistrationRunner::class);
            $sessionEmail = strtolower(trim((string) $this->verificationMailboxSessionEmail));
            $payload = $this->webmailSessionPayload($result);

            try {
                // Wurde das Postfach inzwischen gewechselt, gehoert die Sitzung zum alten
                // und darf nicht in das neue geschrieben werden.
                $stored = $runner->withVerificationMailboxLock(function () use ($runner, $sessionEmail, $payload): ?array {
                    $settings = $runner->settings();
                    $activeEmail = strtolower(trim((string) ($settings['verification_mailbox']['email'] ?? '')));

                    if ($sessionEmail !== '' && $sessionEmail !== $activeEmail) {
                        return null;
                    }

                    $settings['verification_mailbox']['webmail_session'] = $payload;

                    return $runner->saveSettings($settings);
                });
            } catch (LockTimeoutException) {
                // Der naechste Abruf versucht es erneut.
                return;
            }

            unset($result['encryptedSessionPayload']);
            $run['result'] = $result;
            $this->verificationMailboxSessionRunId = null;
            $this->verificationMailboxSessionEmail = null;

            if ($stored === null) {
                $message = 'Die Webmail-Session gehoert zum vorherigen Postfach und wurde verworfen.';
                $this->refreshMailboxState($runner->settings());
                $this->verificationMailboxSessionResult = array_replace($run, $result, [
                    'ok' => false,
                    'state' => 'discarded',
                    'statusMessage' => $message,
                    'message' => $message,
                ]);
                $this->notifyMailbox($message, 'warning');

                return;
            }

            $this->fillFromSettings($stored);

            $this->dispatch(
                'showAlert',
                ($result['ok'] ?? false) ? 'Master-Webmail-Session wurde gespeichert.' : 'Master-Webmail-Session wurde mit Hinweisen beendet.',
                ($result['ok'] ?? false) ? 'success' : 'warning'
            );
        }

        $this->verificationMailboxSessionResult = array_replace($run, $result);
    }

    public function closeVerificationMailboxSessionModal(): void
    {
        $this->showVerificationMailboxSessionModal = false;
    }

    public function render()
    {
        return view('livewire.admin.config.mail-registration-settings');
    }

    /**
     * Welche gespeicherten Daten zum bisherigen Postfach gehoeren und beim Speichern
     * verfallen: "account" (anderes Konto - auch das Passwort), "sessions" (auch bei
     * geaendertem Anbieter - Cookies und Login-Sitzungen).
     *
     * @return array{account: bool, sessions: bool}
     */
    protected function verificationMailboxChange(array $existingMailbox, array $validated): array
    {
        $hadPassword = trim((string) ($existingMailbox['password_encrypted'] ?? '')) !== '';
        $hadMailbox = trim((string) ($existingMailbox['email'] ?? '')) !== ''
            || trim((string) ($existingMailbox['username'] ?? '')) !== '';
        $identity = static function (mixed $email, mixed $username): string {
            $email = strtolower(trim((string) $email));

            return $email.'|'.(strtolower(trim((string) $username)) ?: $email);
        };
        $previousAccount = $identity($existingMailbox['email'] ?? '', $existingMailbox['username'] ?? '');
        $newAccount = $identity($validated['verificationMailboxEmail'] ?? '', $validated['verificationMailboxUsername'] ?? '');
        // Ohne bisheriges Postfach ist es die Ersteinrichtung, kein Wechsel.
        $accountChanged = $hadMailbox && $previousAccount !== $newAccount;
        $providerChanged = $hadMailbox
            && $this->normalizeWebmailProvider($existingMailbox['provider'] ?? 'proton')
                !== $this->normalizeWebmailProvider($validated['verificationMailboxProvider'] ?? 'proton');

        return [
            // Ein neues Passwort ist nur noetig, wenn das alte Konto eines hatte.
            'account' => $accountChanged && $hadPassword,
            'sessions' => $accountChanged || $providerChanged,
        ];
    }

    protected function settingsPayload(): array
    {
        $validated = $this->validate([
            'browserEngine' => ['required', 'string', 'in:chrome,cloak,cloak-with-chrome-fallback'],
            'cloakHumanizeEnabled' => ['boolean'],
            'cloakHumanPreset' => ['nullable', 'string', 'max:120'],
            'headlessEnabled' => ['boolean'],
            'previewModalEnabled' => ['boolean'],
            'livePreviewEnabled' => ['boolean'],
            'livePreviewIntervalSeconds' => ['required', 'integer', 'min:1', 'max:60'],
            'browserActivityCheckEnabled' => ['boolean'],
            'domDebugEnabled' => ['boolean'],
            'navigationTimeoutSeconds' => ['required', 'integer', 'min:30', 'max:300'],
            'observationTimeoutSeconds' => ['required', 'integer', 'min:30', 'max:1800'],

            'verificationMailboxEnabled' => ['boolean'],
            'verificationMailboxEmail' => ['nullable', 'email', 'max:255'],
            'verificationMailboxProvider' => ['required', 'string', 'in:proton,gmx'],
            'verificationMailboxUsername' => ['nullable', 'string', 'max:255'],
            'verificationMailboxPassword' => ['nullable', 'string', 'max:512'],
            'verificationMailboxWebmailUrl' => ['nullable', 'url', 'max:2048'],

            'providerOneEnabled' => ['boolean'],
            'providerOneMode' => ['required', 'string', 'in:observed_manual,proton_username_check'],
            'providerOneLabel' => ['required', 'string', 'max:120'],
            'providerOneRegistrationUrl' => ['nullable', 'url', 'max:2048'],
            'providerOneCompletionUrlContains' => ['nullable', 'string', 'max:512'],
            'providerOneCompletionSelector' => ['nullable', 'string', 'max:512'],
            'providerOneWebmailUrl' => ['nullable', 'url', 'max:2048'],

            'providerTwoEnabled' => ['boolean'],
            'providerTwoLabel' => ['nullable', 'string', 'max:120'],
            'providerTwoRegistrationUrl' => ['nullable', 'url', 'max:2048'],
            'providerThreeEnabled' => ['boolean'],
            'providerThreeLabel' => ['nullable', 'string', 'max:120'],
            'providerThreeRegistrationUrl' => ['nullable', 'url', 'max:2048'],
        ]);
        $existingSettings = app(MailAccountRegistrationRunner::class)->settings();
        $existingMailbox = is_array($existingSettings['verification_mailbox'] ?? null) ? $existingSettings['verification_mailbox'] : [];
        $validated = $this->withCurrentMailboxIfFormIsStale($validated, $existingMailbox);
        $encryptedVerificationPassword = $existingMailbox['password_encrypted'] ?? null;
        $newMailboxPassword = trim((string) ($validated['verificationMailboxPassword'] ?? ''));
        $mailboxChange = $this->verificationMailboxChange($existingMailbox, $validated);

        // Passwort und Sitzungen gehoeren zum bisherigen Postfach. Blieben sie
        // stehen, oeffnete der Webmail-Workflow per Cookie weiter das alte Postfach.
        if ($mailboxChange['sessions']) {
            $existingMailbox['webmail_session'] = null;
            $existingMailbox['browser_sessions'] = [];
        }

        if ($mailboxChange['account']) {
            if ($newMailboxPassword === '' && trim((string) ($validated['verificationMailboxEmail'] ?? '')) !== '') {
                throw ValidationException::withMessages([
                    'verificationMailboxPassword' => 'Fuer das neue Verifizierungs-Postfach bitte auch das Passwort eintragen.',
                ]);
            }

            $encryptedVerificationPassword = null;
        }

        if ($mailboxChange['sessions']) {
            // Ein noch laufender Sitzungsaufbau gehoert zum alten Postfach. Das alte
            // Profil wird erst nach dem Speichern entfernt: Die Bereinigung behaelt
            // nur das Profil der dann gespeicherten Adresse.
            $this->verificationMailboxSessionRunId = null;
            $this->profileCleanupPending = true;
        }

        if (trim((string) ($validated['verificationMailboxPassword'] ?? '')) !== '') {
            $encryptedVerificationPassword = Crypt::encryptString((string) $validated['verificationMailboxPassword']);
        }

        $verificationMailboxProvider = $this->normalizeWebmailProvider($validated['verificationMailboxProvider'] ?? 'proton');
        $verificationMailboxWebmailUrl = trim((string) ($validated['verificationMailboxWebmailUrl'] ?? ''))
            ?: $this->defaultWebmailUrl($verificationMailboxProvider);

        return [
            'browser_engine' => $validated['browserEngine'],
            'cloak_humanize_enabled' => (bool) $validated['cloakHumanizeEnabled'],
            'cloak_human_preset' => trim((string) ($validated['cloakHumanPreset'] ?? '')),
            'headless_enabled' => (bool) $validated['headlessEnabled'],
            'preview_modal_enabled' => (bool) $validated['previewModalEnabled'],
            'live_preview_enabled' => (bool) $validated['livePreviewEnabled'],
            'live_preview_interval_seconds' => (int) $validated['livePreviewIntervalSeconds'],
            'browser_activity_check_enabled' => (bool) $validated['browserActivityCheckEnabled'],
            'dom_debug_enabled' => (bool) $validated['domDebugEnabled'],
            'navigation_timeout_seconds' => (int) $validated['navigationTimeoutSeconds'],
            'observation_timeout_seconds' => (int) $validated['observationTimeoutSeconds'],
            'verification_mailbox' => [
                'enabled' => (bool) $validated['verificationMailboxEnabled'],
                'email' => trim((string) ($validated['verificationMailboxEmail'] ?? '')),
                'provider' => $verificationMailboxProvider,
                'username' => trim((string) ($validated['verificationMailboxUsername'] ?? '')),
                'password_encrypted' => $this->nullableString($encryptedVerificationPassword),
                'webmail_url' => $verificationMailboxWebmailUrl,
                'webmail_session' => is_array($existingMailbox['webmail_session'] ?? null) ? $existingMailbox['webmail_session'] : null,
                'browser_sessions' => is_array($existingMailbox['browser_sessions'] ?? null) ? $existingMailbox['browser_sessions'] : [],
            ],
            // Werden ueber eigene Aktionen gepflegt; das Formular gibt sie unveraendert weiter.
            'verification_mailbox_reserve' => $existingSettings['verification_mailbox_reserve'] ?? [],
            'verification_mailbox_consumed' => $existingSettings['verification_mailbox_consumed'] ?? [],
            'providers' => [
                [
                    'key' => $validated['providerOneMode'] === 'proton_username_check' ? 'proton' : 'observed_manual',
                    'label' => trim($validated['providerOneLabel']),
                    'mode' => $validated['providerOneMode'],
                    'enabled' => (bool) $validated['providerOneEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerOneRegistrationUrl'] ?? '')),
                    'completion_url_contains' => trim((string) ($validated['providerOneCompletionUrlContains'] ?? '')),
                    'completion_selector' => trim((string) ($validated['providerOneCompletionSelector'] ?? '')),
                    'webmail_url' => trim((string) ($validated['providerOneWebmailUrl'] ?? '')),
                ],
                [
                    'key' => 'provider_2',
                    'label' => trim((string) ($validated['providerTwoLabel'] ?? '')) ?: 'Provider 2',
                    'mode' => 'planned',
                    'enabled' => (bool) $validated['providerTwoEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerTwoRegistrationUrl'] ?? '')),
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
                [
                    'key' => 'provider_3',
                    'label' => trim((string) ($validated['providerThreeLabel'] ?? '')) ?: 'Provider 3',
                    'mode' => 'planned',
                    'enabled' => (bool) $validated['providerThreeEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerThreeRegistrationUrl'] ?? '')),
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
            ],
        ];
    }

    /**
     * Ein Formular, das vor einem Postfachwechsel geladen wurde, schickt noch das
     * alte Postfach. Hat der Admin die Postfachfelder nicht angefasst, bleibt das
     * gespeicherte Postfach; hat er sie geaendert (auch nur ein Passwort
     * eingetragen), muss er neu laden. Sonst holte ein Speichern die verbrauchte
     * Adresse zurueck und die nachgerueckte Reserve-Adresse ginge samt Passwort
     * verloren.
     */
    protected function withCurrentMailboxIfFormIsStale(array $validated, array $storedMailbox): array
    {
        if ($this->loadedVerificationMailbox === []) {
            return $validated;
        }

        $runner = app(MailAccountRegistrationRunner::class);
        $loadedIdentity = $runner->verificationMailboxIdentity($this->loadedVerificationMailbox);

        if ($runner->verificationMailboxIdentity($storedMailbox) === $loadedIdentity) {
            return $validated;
        }

        $submittedIdentity = $runner->verificationMailboxIdentity([
            'email' => $validated['verificationMailboxEmail'] ?? '',
            'username' => $validated['verificationMailboxUsername'] ?? '',
            'provider' => $this->normalizeWebmailProvider($validated['verificationMailboxProvider'] ?? 'proton'),
        ]);

        if ($submittedIdentity !== $loadedIdentity || trim((string) ($validated['verificationMailboxPassword'] ?? '')) !== '') {
            throw ValidationException::withMessages([
                'verificationMailboxEmail' => 'Das Verifizierungs-Postfach wurde inzwischen geaendert (aktiv: '
                    .(trim((string) ($storedMailbox['email'] ?? '')) ?: 'keines')
                    .'). Bitte die Seite neu laden und die Aenderung erneut eintragen.',
            ]);
        }

        return array_replace($validated, [
            'verificationMailboxEnabled' => (bool) ($storedMailbox['enabled'] ?? false),
            'verificationMailboxEmail' => (string) ($storedMailbox['email'] ?? ''),
            'verificationMailboxUsername' => (string) ($storedMailbox['username'] ?? ''),
            'verificationMailboxProvider' => $this->normalizeWebmailProvider($storedMailbox['provider'] ?? 'proton'),
            'verificationMailboxWebmailUrl' => (string) ($storedMailbox['webmail_url'] ?? ''),
            'verificationMailboxPassword' => '',
        ]);
    }

    protected function afterVerificationMailboxPromotion(array $settings): void
    {
        try {
            app(WorkflowTaskRunner::class)->forgetVerificationMailboxBrowserProfile();
        } catch (\Throwable $exception) {
            report($exception);
        }

        // Ein noch laufender Sitzungsaufbau gehoert zum vorherigen Postfach.
        $this->verificationMailboxSessionRunId = null;
        $this->verificationMailboxSessionEmail = null;
        $this->fillVerificationMailbox($settings);
        $this->fillMailboxLists($settings);
    }

    /**
     * Fuehrt eine Lese-Aendern-Schreiben-Aktion unter der Postfach-Sperre aus und
     * meldet eine belegte Sperre als Feldfehler statt als Ausnahme.
     */
    protected function withMailboxLock(callable $callback, string $errorField): mixed
    {
        try {
            return app(MailAccountRegistrationRunner::class)->withVerificationMailboxLock($callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                $errorField => 'Das Verifizierungs-Postfach wird gerade aktualisiert. Bitte in einigen Sekunden erneut versuchen.',
            ]);
        }
    }

    /**
     * Aktualisiert die Listen und - wenn der Admin die Postfachfelder nicht
     * angefasst hat - auch das Formular, damit es kein frueheres Postfach zeigt.
     */
    protected function refreshMailboxState(array $settings): void
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $formIdentity = $runner->verificationMailboxIdentity([
            'email' => $this->verificationMailboxEmail,
            'username' => $this->verificationMailboxUsername,
            'provider' => $this->normalizeWebmailProvider($this->verificationMailboxProvider),
        ]);

        if ($this->loadedVerificationMailbox === [] || $formIdentity === $runner->verificationMailboxIdentity($this->loadedVerificationMailbox)) {
            $this->fillVerificationMailbox($settings);
        }

        $this->fillMailboxLists($settings);
    }

    protected function notifyMailbox(string $message, string $type = 'success'): void
    {
        $this->mailboxNotice = $message;
        $this->mailboxNoticeType = $type;
        $this->dispatch('showAlert', $message, $type);
    }

    protected function fillMailboxLists(array $settings): void
    {
        $activeKey = strtolower(trim((string) ($settings['verification_mailbox']['email'] ?? '')));
        $consumedEntries = is_array($settings['verification_mailbox_consumed'] ?? null) ? $settings['verification_mailbox_consumed'] : [];
        $consumedKeys = collect($consumedEntries)
            ->map(fn (array $entry): string => strtolower(trim((string) ($entry['email'] ?? ''))))
            ->unique()
            ->values()
            ->all();

        $this->reserveMailboxes = array_map(fn (array $mailbox): array => [
            'email' => (string) $mailbox['email'],
            'username' => (string) ($mailbox['username'] ?? ''),
            'hasPassword' => trim((string) ($mailbox['password_encrypted'] ?? '')) !== '',
            'addedAt' => $mailbox['added_at'] ?? null,
            'consumed' => in_array(strtolower(trim((string) $mailbox['email'])), $consumedKeys, true),
        ], $settings['verification_mailbox_reserve'] ?? []);
        $this->nextReserveMailboxEmail = collect($this->reserveMailboxes)
            ->first(fn (array $mailbox): bool => ! $mailbox['consumed'] && strtolower($mailbox['email']) !== $activeKey)['email'] ?? null;

        $this->consumedMailboxes = collect(array_reverse($consumedEntries))
            ->groupBy(fn (array $entry): string => strtolower(trim((string) ($entry['email'] ?? ''))))
            ->map(fn ($entries, string $key): array => [
                'email' => (string) $entries->first()['email'],
                'isActive' => $key === $activeKey,
                'events' => $entries->map(fn (array $entry): array => $this->consumedEventView($entry))->values()->all(),
            ])
            ->values()
            ->all();
        $this->activeMailboxConsumption = collect($this->consumedMailboxes)->firstWhere('isActive', true) ?? [];
    }

    protected function consumedEventView(array $entry): array
    {
        $reason = trim((string) ($entry['reason'] ?? ''));
        $consumedAt = $entry['consumed_at'] ?? null;
        $consumedAtLabel = null;

        try {
            if (trim((string) $consumedAt) !== '') {
                $consumedAtLabel = Carbon::parse((string) $consumedAt)->timezone(config('app.timezone'))->format('d.m.Y H:i');
            }
        } catch (\Throwable) {
            // Ein unlesbares Datum wird einfach nicht angezeigt.
        }

        return [
            'reason' => $reason,
            'reasonLabel' => match ($reason) {
                'registered' => 'Konto registriert',
                'rejected' => 'von Proton abgelehnt',
                '' => 'verbraucht',
                default => $reason,
            },
            'workflowRunId' => is_numeric($entry['workflow_run_id'] ?? null) ? (int) $entry['workflow_run_id'] : null,
            'consumedAt' => $consumedAt,
            'consumedAtLabel' => $consumedAtLabel,
        ];
    }

    protected function fillVerificationMailbox(array $settings): void
    {
        $verificationMailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $this->verificationMailboxEnabled = (bool) ($verificationMailbox['enabled'] ?? false);
        $this->verificationMailboxEmail = (string) ($verificationMailbox['email'] ?? '');
        $this->verificationMailboxProvider = $this->normalizeWebmailProvider($verificationMailbox['provider'] ?? 'proton');
        $this->verificationMailboxUsername = (string) ($verificationMailbox['username'] ?? '');
        $this->verificationMailboxPassword = '';
        $this->hasStoredVerificationMailboxPassword = trim((string) ($verificationMailbox['password_encrypted'] ?? '')) !== '';
        $this->verificationMailboxWebmailUrl = (string) ($verificationMailbox['webmail_url'] ?? '') ?: $this->defaultWebmailUrl($this->verificationMailboxProvider);
        $this->loadedVerificationMailbox = [
            'email' => $this->verificationMailboxEmail,
            'username' => $this->verificationMailboxUsername,
            'provider' => $this->verificationMailboxProvider,
        ];
    }

    protected function fillFromSettings(array $settings): void
    {
        $this->fillMailboxLists($settings);

        $providers = array_values($settings['providers'] ?? []);
        $providerOne = $providers[0] ?? [];
        $providerTwo = $providers[1] ?? [];
        $providerThree = $providers[2] ?? [];

        $this->browserEngine = (string) ($settings['browser_engine'] ?? 'cloak-with-chrome-fallback');
        $this->cloakHumanizeEnabled = (bool) ($settings['cloak_humanize_enabled'] ?? false);
        $this->cloakHumanPreset = (string) ($settings['cloak_human_preset'] ?? '');
        $this->headlessEnabled = (bool) ($settings['headless_enabled'] ?? false);
        $this->previewModalEnabled = (bool) ($settings['preview_modal_enabled'] ?? true);
        $this->livePreviewEnabled = (bool) ($settings['live_preview_enabled'] ?? true);
        $this->livePreviewIntervalSeconds = max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? 3)));
        $this->browserActivityCheckEnabled = (bool) ($settings['browser_activity_check_enabled'] ?? true);
        $this->domDebugEnabled = (bool) ($settings['dom_debug_enabled'] ?? true);
        $this->navigationTimeoutSeconds = (int) ($settings['navigation_timeout_seconds'] ?? 120);
        $this->observationTimeoutSeconds = (int) ($settings['observation_timeout_seconds'] ?? 300);
        $this->fillVerificationMailbox($settings);

        $this->providerOneEnabled = (bool) ($providerOne['enabled'] ?? true);
        $this->providerOneMode = in_array(($providerOne['mode'] ?? ''), ['observed_manual', 'proton_username_check'], true)
            ? (string) $providerOne['mode']
            : 'proton_username_check';
        $this->providerOneLabel = (string) ($providerOne['label'] ?? 'Eigener Provider / beobachteter Browserflow');
        $this->providerOneRegistrationUrl = (string) ($providerOne['registration_url'] ?? '');
        $this->providerOneCompletionUrlContains = (string) ($providerOne['completion_url_contains'] ?? '');
        $this->providerOneCompletionSelector = (string) ($providerOne['completion_selector'] ?? '');
        $this->providerOneWebmailUrl = (string) ($providerOne['webmail_url'] ?? '');

        $this->providerTwoEnabled = (bool) ($providerTwo['enabled'] ?? false);
        $this->providerTwoLabel = (string) ($providerTwo['label'] ?? 'Provider 2');
        $this->providerTwoRegistrationUrl = (string) ($providerTwo['registration_url'] ?? '');

        $this->providerThreeEnabled = (bool) ($providerThree['enabled'] ?? false);
        $this->providerThreeLabel = (string) ($providerThree['label'] ?? 'Provider 3');
        $this->providerThreeRegistrationUrl = (string) ($providerThree['registration_url'] ?? '');
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function decryptStoredPassword(mixed $encrypted): string
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

    protected function normalizeWebmailProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'proton';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function webmailSessionPayload(array $result): array
    {
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        return [
            'payload_encrypted' => (string) $result['encryptedSessionPayload'],
            'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
