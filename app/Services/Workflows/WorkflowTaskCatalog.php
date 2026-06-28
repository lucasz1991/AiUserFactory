<?php

namespace App\Services\Workflows;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkflowTaskCatalog
{
    public function all(): array
    {
        return [
            'browser.open' => [
                'label' => 'Browserfenster oeffnen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open.cjs',
                'timeout_seconds' => 60,
                'description' => 'Startet oder uebernimmt einen Browser-Kontext fuer weitere Workflow-Karten.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
            'browser.open_url' => [
                'label' => 'URL aufrufen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open_url.cjs',
                'timeout_seconds' => 120,
                'description' => 'Navigiert zu einer variablen URL und wartet optional auf ein Ziel-Element.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => true,
                    'url_label' => 'URL',
                    'url_placeholder' => 'https://example.test oder person.webmailUrl',
                    'success_payload' => false,
                    'failure_payload' => true,
                ],
            ],
            'browser.open_webmail_session' => [
                'label' => 'Browser-Session laden und Webmailportal oeffnen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open_webmail_session.cjs',
                'timeout_seconds' => 120,
                'description' => 'Laedt gespeicherte Webmail-Cookies und Browser-Storage der Bezugs-Person oder des Haupt-Verifikationskontos und oeffnet das Webmailportal.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => true,
                    'mailbox_source_label' => 'Script-Bezugsperson',
                    'mailbox_source_options' => [
                        'person' => 'Bezugs-Person',
                        'verification' => 'Haupt-Verifikationskonto',
                    ],
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'webmail.check_session' => [
                'label' => 'Webmailportal-Session pruefen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/webmail/check_session.cjs',
                'timeout_seconds' => 120,
                'description' => 'Prueft, ob eine gespeicherte oder aktuell offene Webmail-Session verwendbar ist.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => true,
                    'mailbox_source_label' => 'Script-Bezugsperson',
                    'mailbox_source_options' => [
                        'person' => 'Bezugs-Person',
                        'verification' => 'Haupt-Verifikationskonto',
                    ],
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'webmail.read_verification_code' => [
                'label' => 'Verifizierungscode aus Webmail lesen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/webmail/read_verification_code.cjs',
                'timeout_seconds' => 90,
                'description' => 'Liest sichtbare Mail-Inhalte im Webmailportal und extrahiert den aktuellen Verifizierungscode.',
                'form' => [
                    'selector' => false,
                    'value' => true,
                    'value_label' => 'Suchtext oder Domain',
                    'value_placeholder' => 'Instagram, Sicherheitscode oder leer',
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'browser.find_inputs' => [
                'label' => 'Input-Felder suchen',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/find_inputs.cjs',
                'timeout_seconds' => 45,
                'description' => 'Sammelt sichtbare Eingabefelder mit Name, Label, Placeholder und Selector-Kandidaten.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'browser.find_element' => [
                'label' => 'Element ermitteln',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/find_element.cjs',
                'timeout_seconds' => 45,
                'description' => 'Sucht ein Element per Selector, Text oder Rolle und liefert Treffer-Metadaten.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector oder Text',
                    'selector_placeholder' => 'button[type=submit], #login oder text=Weiter',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'decision.element_exists' => [
                'label' => 'IF Element vorhanden',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/decision/element_exists.cjs',
                'timeout_seconds' => 15,
                'description' => 'Prueft, ob ein sichtbares Element existiert. Treffer folgen der Erfolgsroute, fehlende Elemente der Fehlerroute.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'IF-Selector',
                    'selector_placeholder' => 'button:has(span:has-text("Login")), #mailbox',
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
            'browser.click' => [
                'label' => 'Button/Link klicken',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/click.cjs',
                'timeout_seconds' => 60,
                'description' => 'Klickt ein Element per Selector oder Text und gibt den Folgezustand weiter.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector oder Klicktext',
                    'selector_placeholder' => 'button[type=submit], a[href*=next] oder text=Weiter',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'input.fill_field' => [
                'label' => 'Input-Feld fuellen',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/input/fill_field.cjs',
                'timeout_seconds' => 60,
                'description' => 'Fuellt ein konkretes oder heuristisch gefundenes Eingabefeld mit variablem Wert.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector',
                    'selector_placeholder' => 'input[name=email], #password',
                    'value' => true,
                    'value_label' => 'Datenquelle oder Wert',
                    'value_placeholder' => 'person.email, person.password oder fester Wert',
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'input.submit' => [
                'label' => 'Formular absenden',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/input/submit.cjs',
                'timeout_seconds' => 60,
                'description' => 'Klickt auf einen passenden Submit-Button oder sendet das naechste Formular ab.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Submit-Selector',
                    'selector_placeholder' => 'button[type=submit] oder text=Absenden',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'wait.selector' => [
                'label' => 'Auf Element warten',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/wait/selector.cjs',
                'timeout_seconds' => 90,
                'description' => 'Wartet auf ein sichtbares Element und liefert je nach Treffer einen Status.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector',
                    'selector_placeholder' => '#mailbox, [data-ready=true]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'wait.seconds' => [
                'label' => 'Warten',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/wait/seconds.cjs',
                'timeout_seconds' => 120,
                'description' => 'Wartet eine definierte Zeit und leitet danach weiter.',
                'form' => [
                    'selector' => false,
                    'value' => true,
                    'value_label' => 'Sekunden',
                    'value_placeholder' => '5',
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
            'wait.status' => [
                'label' => 'Status auswerten',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/wait/status.cjs',
                'timeout_seconds' => 30,
                'description' => 'Prueft DOM/Text/URL gegen Statusregeln und gibt success, partial, failed oder timeout zurueck.',
                'form' => [
                    'selector' => false,
                    'value' => true,
                    'value_label' => 'Statusregeln',
                    'value_placeholder' => '[{"source":"text","contains":"Willkommen","status":"success"}]',
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'mail.generate_address' => [
                'label' => 'Wunsch-Mailadresse generieren',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/generate_address.cjs',
                'timeout_seconds' => 45,
                'description' => 'Erstellt aus Vorname, Nachname und Zufallskombinationen einen Username und traegt ihn in das Registrierungsfeld ein.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Username/E-Mail-Selector',
                    'selector_placeholder' => 'input[name*=username], input[type=email]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'mail.fill_address' => [
                'label' => 'Mailadresse eintragen',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/fill_address.cjs',
                'timeout_seconds' => 45,
                'description' => 'Traegt den aktuellen Wunsch-Username oder die Wunsch-Mailadresse in das Registrierungsfeld ein.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'E-Mail/Username-Selector',
                    'selector_placeholder' => 'input[name*=username], input[type=email]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'mail.check_address_availability' => [
                'label' => 'Mailadresse pruefen',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/check_address_availability.cjs',
                'timeout_seconds' => 90,
                'description' => 'Prueft Provider-Feedback und probiert bei belegter Adresse automatisch weitere Kandidaten.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'E-Mail/Username-Selector',
                    'selector_placeholder' => 'input[name*=username], input[type=email]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'mail.generate_password' => [
                'label' => 'Wunschpasswort generieren',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/generate_password.cjs',
                'timeout_seconds' => 45,
                'description' => 'Generiert ein neues Passwort, traegt es ein und stellt generated-password sowie new_password bereit.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Passwort-Selector',
                    'selector_placeholder' => 'input[type=password]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'data.read_account_data' => [
                'label' => 'Accountdaten lesen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/read_account_data.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\ReadAccountDataTask@handle',
                'timeout_seconds' => 15,
                'description' => 'Extrahiert Accountdaten aus Workflow-, Persona- oder Node-Ergebnissen.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'data.resolve_person' => [
                'label' => 'Person-Daten ermitteln',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/resolve_person.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\ResolvePersonDataTask@handle',
                'timeout_seconds' => 15,
                'description' => 'Liest Persona-Stammdaten und stellt sie als Payload fuer weitere Tasks bereit.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => false,
                ],
            ],
            'data.read_login_data' => [
                'label' => 'Login-Daten lesen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/read_login_data.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\ReadLoginDataTask@handle',
                'timeout_seconds' => 15,
                'description' => 'Bereitet Provider, E-Mail, Benutzername, Passwort und Webmail-URL fuer Login-Tasks vor.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'data.persist_mail_account' => [
                'label' => 'Mail-Account speichern',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/persist_mail_account.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistMailAccountTask@handle',
                'timeout_seconds' => 30,
                'description' => 'Speichert Provider, E-Mail, Benutzername, Passwort und Webmail-URL an der Persona.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                ],
            ],
            'data.persist_webmail_session' => [
                'label' => 'Webmail-Session speichern',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/persist_webmail_session.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistWebmailSessionTask@handle',
                'timeout_seconds' => 30,
                'description' => 'Speichert Cookies/Storage aus dem aktuell offenen Webmailportal verschluesselt an Person oder Haupt-Verifikationskonto.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => true,
                    'mailbox_source_label' => 'Script-Bezugsperson',
                    'mailbox_source_options' => [
                        'person' => 'Bezugs-Person',
                        'verification' => 'Haupt-Verifikationskonto',
                    ],
                    'success_payload' => false,
                    'failure_payload' => true,
                ],
            ],
            'browser.close' => [
                'label' => 'Browserfenster schliessen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/close.cjs',
                'timeout_seconds' => 30,
                'description' => 'Schliesst Seite, Kontext oder Browser, wenn der Runner einen Handle uebergibt.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
        ];
    }

    public function options(): array
    {
        return collect($this->all())
            ->map(fn (array $task, string $key): array => [
                'key' => $key,
                'label' => $task['label'],
                'kind' => $task['kind'],
                'runner' => $task['runner'],
                'description' => $task['description'] ?? '',
                'form' => $task['form'] ?? [],
            ])
            ->values()
            ->toArray();
    }

    public function task(string $taskKey): ?array
    {
        $definition = $this->all()[$taskKey] ?? null;

        if (! $definition) {
            return null;
        }

        return ['task_key' => $taskKey, ...$definition];
    }

    public function cardFromDefinition(string $taskKey, array $overrides = []): array
    {
        $definition = $this->task($taskKey) ?? [
            'task_key' => $taskKey,
            'label' => Str::of($taskKey)->replace(['.', '_'], ' ')->title()->toString(),
            'kind' => 'data',
            'runner' => 'php',
            'timeout_seconds' => 60,
            'description' => '',
        ];

        $card = [
            'key' => (string) ($overrides['key'] ?? Str::slug($definition['label']) ?: Str::slug($taskKey)),
            'task_key' => $definition['task_key'],
            'title' => (string) ($overrides['title'] ?? $definition['label']),
            'description' => (string) ($overrides['description'] ?? $definition['description'] ?? ''),
            'kind' => (string) ($overrides['kind'] ?? $definition['kind'] ?? 'data'),
            'runner' => (string) ($overrides['runner'] ?? $definition['runner'] ?? 'php'),
            'status' => (string) ($overrides['status'] ?? 'configured'),
            'timeout_seconds' => max(0, (int) ($overrides['timeout_seconds'] ?? $definition['timeout_seconds'] ?? 60)),
        ];

        $usesBrowserWindow = in_array((string) ($definition['kind'] ?? ''), ['browser', 'input', 'wait'], true)
            && (string) ($definition['task_key'] ?? $taskKey) !== 'wait.seconds';

        if ($usesBrowserWindow && ! array_key_exists('browser_window', $definition)) {
            $definition['browser_window'] = 'main';
        }

        foreach (['node_script', 'php_handler', 'workflow_id', 'workflow_slug', 'browser_window', 'browser_window_name', 'selector', 'element_selector', 'input_selector', 'input', 'value', 'url', 'mailbox_source', 'script_person_source', 'success_payload', 'failure_payload', 'next', 'on_partial', 'on_error', 'status_routes'] as $key) {
            $value = Arr::get($overrides, $key, Arr::get($definition, $key));

            if ($value !== null && $value !== '') {
                $card[$key] = $value;
            }
        }

        return $card;
    }
}
