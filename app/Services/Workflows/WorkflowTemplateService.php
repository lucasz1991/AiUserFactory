<?php

namespace App\Services\Workflows;

use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Str;

class WorkflowTemplateService
{
    public function ensureDefaults(): void
    {
        $registrationWorkflow = $this->workflow(
            slug: 'email-mailbox-registration',
            name: 'E-Mail-Postfach registrieren',
            description: 'Registriert ein Persona-Postfach und speichert danach die Webmail-Session.'
        );

        $this->step($registrationWorkflow, [
            'name' => 'Registrierung automatisieren',
            'type' => WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION,
            'position' => 10,
            'action_key' => 'mail-registration-automation',
            'config_json' => [
                'provider_key' => 'proton',
                'allow_partial' => false,
                'automation_summary' => 'Browserflow: oeffnen, Felder fuellen, Verifikation abwarten, Accountdaten lesen.',
                'tasks' => $this->mailRegistrationTasks(),
                'routes' => [
                    'success' => [
                        'type' => 'step',
                        'action_key' => 'registration-webmail-session',
                        'label' => 'Webmail-Session sichern',
                    ],
                    'partial' => [
                        'type' => 'end',
                        'label' => 'Manuelle Pruefung',
                    ],
                    'failed' => [
                        'type' => 'fail',
                        'label' => 'Registrierung fehlgeschlagen',
                    ],
                ],
            ],
        ]);

        $this->step($registrationWorkflow, [
            'name' => 'Webmail-Session sichern',
            'type' => WorkflowStep::TYPE_WEBMAIL_LOGIN,
            'position' => 20,
            'action_key' => 'registration-webmail-session',
            'config_json' => [
                'provider' => 'proton',
                'use_person_email_account' => true,
                'allow_partial' => false,
                'automation_summary' => 'Login aus gespeicherten Accountdaten, Session-Cookies lesen und speichern.',
                'tasks' => $this->webmailLoginTasks(),
                'routes' => [
                    'success' => [
                        'type' => 'end',
                        'label' => 'Workflow abschliessen',
                    ],
                    'failed' => [
                        'type' => 'fail',
                        'label' => 'Webmail-Session fehlgeschlagen',
                    ],
                ],
            ],
        ]);

        $this->pruneLegacyWorkflowSteps($registrationWorkflow);

        $webmailWorkflow = $this->workflow(
            slug: 'webmail-portal-login',
            name: 'Webmailportal Login',
            description: 'Oeffnet das konfigurierte Webmail-Portal und speichert eine wiederverwendbare Session.'
        );

        $this->step($webmailWorkflow, [
            'name' => 'Webmailportal Login automatisieren',
            'type' => WorkflowStep::TYPE_WEBMAIL_LOGIN,
            'position' => 10,
            'action_key' => 'webmail-login-automation',
            'config_json' => [
                'provider' => 'proton',
                'use_person_email_account' => true,
                'allow_partial' => false,
                'automation_summary' => 'Browser oeffnen, Loginfelder fuellen, Postfach erkennen, Session speichern, beenden.',
                'tasks' => $this->webmailLoginTasks(),
                'routes' => [
                    'success' => [
                        'type' => 'end',
                        'label' => 'Session gespeichert',
                    ],
                    'failed' => [
                        'type' => 'fail',
                        'label' => 'Login fehlgeschlagen',
                    ],
                ],
            ],
        ]);

        $this->pruneLegacyWorkflowSteps($webmailWorkflow);
    }

    public function ensureMailSettings(): array
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $existing = Setting::getValue(MailAccountRegistrationRunner::SETTINGS_TYPE, MailAccountRegistrationRunner::SETTINGS_KEY);
        $settings = is_array($existing)
            ? array_replace_recursive($runner->defaultSettings(), $existing)
            : $runner->defaultSettings();

        $settings['providers'][0] = array_replace($settings['providers'][0] ?? [], [
            'key' => 'proton',
            'label' => 'Proton',
            'mode' => MailAccountRegistrationRunner::PROVIDER_MODE_PROTON_USERNAME_CHECK,
            'enabled' => true,
            'phone_required' => false,
            'registration_url' => 'https://account.proton.me/mail/signup',
            'webmail_url' => 'https://mail.proton.me',
        ]);

        $settings = $runner->saveSettings($settings);

        Setting::setValue('webmail', 'portal_login', [
            'default_provider' => 'proton',
            'providers' => [
                'proton' => [
                    'label' => 'Proton',
                    'webmail_url' => 'https://mail.proton.me',
                ],
                'gmx' => [
                    'label' => 'GMX',
                    'webmail_url' => 'https://www.gmx.net',
                ],
            ],
            'workflow_slug' => 'webmail-portal-login',
        ]);

        return $settings;
    }

    protected function workflow(string $slug, string $name, string $description): Workflow
    {
        return Workflow::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'category' => 'mail',
                'is_active' => true,
                'trigger_type' => 'manual',
                'settings_json' => [
                    'seeded' => true,
                    'template_version' => 1,
                ],
            ]
        );
    }

    protected function step(Workflow $workflow, array $payload): WorkflowStep
    {
        $name = trim((string) ($payload['name'] ?? 'Workflow-Schritt'));
        $type = trim((string) ($payload['type'] ?? WorkflowStep::TYPE_PLANNED_ACTION));
        $actionKey = trim((string) ($payload['action_key'] ?? Str::slug($name.'-'.$type)));

        return WorkflowStep::query()->updateOrCreate(
            [
                'workflow_id' => $workflow->id,
                'action_key' => $actionKey,
            ],
            [
                'name' => $name,
                'type' => $type,
                'position' => (int) ($payload['position'] ?? 10),
                'is_enabled' => (bool) ($payload['is_enabled'] ?? true),
                'config_json' => is_array($payload['config_json'] ?? null) ? $payload['config_json'] : [],
                'retry_attempts' => (int) ($payload['retry_attempts'] ?? 0),
                'wait_after_seconds' => (int) ($payload['wait_after_seconds'] ?? 0),
            ]
        );
    }

    protected function pruneLegacyWorkflowSteps(Workflow $workflow): void
    {
        $legacyActionKeys = [
            'e-mail-postfach-registrieren-mail-account-registration',
            'webmailportal-login-speichern-webmail-login',
        ];

        $workflow->steps()
            ->whereIn('action_key', $legacyActionKeys)
            ->delete();
    }

    protected function mailRegistrationTasks(): array
    {
        return [
            [
                'key' => 'open-browser',
                'title' => 'Browser starten',
                'description' => 'Cloak/Chrome mit isoliertem Profil und Live-Preview starten.',
                'kind' => 'browser',
                'status' => 'automated',
                'next' => ['card' => 'open-registration-url', 'label' => 'Registrierungsseite aufrufen'],
                'on_error' => ['step' => 'fail', 'label' => 'Browserstart fehlgeschlagen'],
            ],
            [
                'key' => 'open-registration-url',
                'title' => 'Registrierungsseite aufrufen',
                'description' => 'Provider-URL oeffnen und auf sichtbare Formularfelder warten.',
                'kind' => 'browser',
                'status' => 'automated',
                'selector' => 'registration_url',
                'next' => ['card' => 'fill-username', 'label' => 'Username/E-Mail fuellen'],
                'on_error' => ['step' => 'fail', 'label' => 'Seite nicht erreichbar'],
            ],
            [
                'key' => 'fill-username',
                'title' => 'Username/E-Mail fuellen',
                'description' => 'Persona-Daten lesen, Username ableiten und in das erste passende Feld eintragen.',
                'kind' => 'input',
                'status' => 'automated',
                'input' => 'subject.accountUsername',
                'next' => ['card' => 'check-availability', 'label' => 'Verfuegbarkeit pruefen'],
                'on_error' => ['card' => 'fill-username', 'label' => 'Alternative versuchen'],
            ],
            [
                'key' => 'check-availability',
                'title' => 'Verfuegbarkeit pruefen',
                'description' => 'Provider-Feedback abwarten und belegte Username-Varianten ueberspringen.',
                'kind' => 'wait',
                'status' => 'automated',
                'next' => ['card' => 'fill-password', 'label' => 'Passwort setzen'],
                'on_error' => ['card' => 'fill-username', 'label' => 'Naechsten Username versuchen'],
            ],
            [
                'key' => 'fill-password',
                'title' => 'Passwort generieren und fuellen',
                'description' => 'Passwort erzeugen, Passwort- und Bestaetigungsfeld fuellen und absenden.',
                'kind' => 'input',
                'status' => 'automated',
                'selector' => 'input[type=password]',
                'next' => ['card' => 'wait-verification', 'label' => 'Verifikation abwarten'],
                'on_error' => ['step' => 'fail', 'label' => 'Passwortformular fehlgeschlagen'],
            ],
            [
                'key' => 'wait-verification',
                'title' => 'Verifikation abwarten',
                'description' => 'E-Mail-Verifikation erkennen, Webmail-Check einplanen oder manuellen Eingriff markieren.',
                'kind' => 'wait',
                'status' => 'automated',
                'next' => ['card' => 'read-account-data', 'label' => 'Accountdaten lesen'],
                'on_error' => ['step' => 'end', 'label' => 'Manuelle Verifikation erforderlich'],
            ],
            [
                'key' => 'read-account-data',
                'title' => 'Accountdaten lesen',
                'description' => 'E-Mail, Username, Provider, Webmail-URL und Passwort aus dem Node-Ergebnis extrahieren.',
                'kind' => 'data',
                'status' => 'automated',
                'next' => ['card' => 'persist-account-data', 'label' => 'Daten speichern'],
                'on_error' => ['step' => 'fail', 'label' => 'Keine Accountdaten'],
            ],
            [
                'key' => 'persist-account-data',
                'title' => 'Daten speichern',
                'description' => 'Accountdaten verschluesselt in der Persona speichern.',
                'kind' => 'data',
                'status' => 'automated',
                'next' => ['card' => 'close-browser', 'label' => 'Browser beenden'],
                'on_error' => ['step' => 'fail', 'label' => 'Speichern fehlgeschlagen'],
            ],
            [
                'key' => 'close-browser',
                'title' => 'Browser beenden',
                'description' => 'Browserprozess und Profil-Heartbeat sauber abschliessen.',
                'kind' => 'browser',
                'status' => 'automated',
                'next' => ['step' => 'registration-webmail-session', 'label' => 'Naechste Liste'],
            ],
        ];
    }

    protected function webmailLoginTasks(): array
    {
        return [
            [
                'key' => 'read-login-data',
                'title' => 'Login-Daten lesen',
                'description' => 'Provider, E-Mail, Benutzername, Passwort und Webmail-URL aus Persona/Settings laden.',
                'kind' => 'data',
                'status' => 'automated',
                'next' => ['card' => 'open-browser', 'label' => 'Browser starten'],
                'on_error' => ['step' => 'fail', 'label' => 'Login-Daten unvollstaendig'],
            ],
            [
                'key' => 'open-browser',
                'title' => 'Browser starten',
                'description' => 'Isoliertes Browserprofil fuer die Webmail-Session starten.',
                'kind' => 'browser',
                'status' => 'automated',
                'next' => ['card' => 'open-webmail-url', 'label' => 'Webmailportal aufrufen'],
                'on_error' => ['step' => 'fail', 'label' => 'Browserstart fehlgeschlagen'],
            ],
            [
                'key' => 'open-webmail-url',
                'title' => 'Webmailportal aufrufen',
                'description' => 'Webmail-URL oeffnen und Login-Formular erkennen.',
                'kind' => 'browser',
                'status' => 'automated',
                'selector' => 'webmail_url',
                'next' => ['card' => 'fill-username', 'label' => 'Benutzername fuellen'],
                'on_error' => ['step' => 'fail', 'label' => 'Portal nicht erreichbar'],
            ],
            [
                'key' => 'fill-username',
                'title' => 'Benutzername fuellen',
                'description' => 'Erstes passendes Login-/E-Mail-Feld fuellen.',
                'kind' => 'input',
                'status' => 'automated',
                'input' => 'email_account.username',
                'next' => ['card' => 'fill-password', 'label' => 'Passwort fuellen'],
                'on_error' => ['step' => 'fail', 'label' => 'Username-Feld nicht gefunden'],
            ],
            [
                'key' => 'fill-password',
                'title' => 'Passwort fuellen',
                'description' => 'Passwortfeld fuellen und Login absenden.',
                'kind' => 'input',
                'status' => 'automated',
                'selector' => 'input[type=password]',
                'next' => ['card' => 'wait-mailbox', 'label' => 'Postfach erkennen'],
                'on_error' => ['step' => 'fail', 'label' => 'Passwortfeld nicht gefunden'],
            ],
            [
                'key' => 'wait-mailbox',
                'title' => 'Postfach erkennen',
                'description' => 'Auf Portal-/Mailbox-Zustand warten und Screenshots/DOM-Debug aktualisieren.',
                'kind' => 'wait',
                'status' => 'automated',
                'next' => ['card' => 'save-session', 'label' => 'Session speichern'],
                'on_error' => ['step' => 'fail', 'label' => 'Postfach nicht erkannt'],
            ],
            [
                'key' => 'save-session',
                'title' => 'Session speichern',
                'description' => 'Cookies und Storage lesen, verschluesseln und der Persona zuordnen.',
                'kind' => 'data',
                'status' => 'automated',
                'next' => ['card' => 'close-browser', 'label' => 'Browser beenden'],
                'on_error' => ['step' => 'fail', 'label' => 'Sessiondaten fehlen'],
            ],
            [
                'key' => 'close-browser',
                'title' => 'Browser beenden',
                'description' => 'Browserprozess sauber schliessen und Ergebnis schreiben.',
                'kind' => 'browser',
                'status' => 'automated',
                'next' => ['step' => 'end', 'label' => 'Workflow abschliessen'],
            ],
        ];
    }
}
