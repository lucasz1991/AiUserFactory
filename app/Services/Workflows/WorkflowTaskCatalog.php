<?php

namespace App\Services\Workflows;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkflowTaskCatalog
{
    public function all(): array
    {
        $tasks = [
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
            'browser.open_browser_session' => [
                'label' => 'Browser-Session laden und letzte URL oeffnen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open_browser_session.cjs',
                'browser_window' => 'main',
                'timeout_seconds' => 120,
                'description' => 'Laedt gespeicherte Cookies und Browser-Storage der Bezugs-Person und oeffnet standardmaessig die zuletzt gespeicherte URL der Session.',
                'form' => [
                    'browser_window' => true,
                    'browser_window_create' => true,
                    'browser_window_label' => 'Browserfenster',
                    'browser_window_placeholder' => 'main, session, login',
                    'selector' => false,
                    'value' => false,
                    'value_required' => false,
                    'url' => true,
                    'url_label' => 'URL ueberschreiben (optional)',
                    'url_placeholder' => 'leer = letzte URL der gespeicherten Session',
                    'mailbox_source' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'session_key',
                            'label' => 'Session-Key',
                            'placeholder' => 'leer = neueste gespeicherte Session',
                            'help' => 'Optional. Waehlt gezielt einen Eintrag aus metadata.browser_sessions.',
                            'tab' => 'Session',
                        ],
                        [
                            'name' => 'target_domain',
                            'label' => 'Domain/URL',
                            'placeholder' => 'example.com oder https://example.com',
                            'help' => 'Optional. Wenn kein Session-Key gesetzt ist, wird die passende gespeicherte Session zu dieser Domain geladen.',
                            'tab' => 'Session',
                        ],
                    ],
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
                'description' => 'Oeffnet aktuelle Webmail-Nachrichten, sucht in den letzten Mails nach einem Verifizierungscode und merkt ihn als Workflow-Variable.',
                'form' => [
                    'selector' => false,
                    'value' => true,
                    'value_required' => false,
                    'value_label' => 'Optionaler Suchtext',
                    'value_placeholder' => 'Instagram, Sicherheitscode, Domain oder leer',
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'mail.inbox_list_scan' => [
                'label' => 'Mail-Inbox-Liste scannen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/inbox_list_scan.cjs',
                'timeout_seconds' => 60,
                'description' => 'Liest eine Webmail-Inbox-Liste ueber einstellbare Listen- und Listitem-Selectoren aus und speichert die Treffer als Workflow-Array.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Listen-Selector',
                    'selector_placeholder' => '[role=list], [class*=inbox] oder leer fuer Fallbacks',
                    'value' => true,
                    'value_required' => false,
                    'value_label' => 'Listitem-Selector',
                    'value_placeholder' => '[role=row], li, tr oder leer fuer Fallbacks',
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        [
                            'name' => 'output_array_name',
                            'label' => 'Ausgabe-Array',
                            'placeholder' => 'inbox_mails',
                            'default' => 'inbox_mails',
                            'tab' => 'Ausgabe',
                        ],
                        [
                            'name' => 'subject_selector',
                            'label' => 'Betreff-Selector',
                            'placeholder' => '[class*=subject]',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'title_selector',
                            'label' => 'Titel-Selector',
                            'placeholder' => '[title], [class*=title]',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'subject_filter',
                            'label' => 'Betreff muss enthalten',
                            'placeholder' => "['queued', 'running', 'waiting'] oder queued",
                            'help' => 'Mehrere Werte als Array-Schreibweise, durch Komma oder je Zeile. Mindestens ein Wert muss im Betreff vorkommen.',
                            'span' => 'full',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'title_filter',
                            'label' => 'Titel muss enthalten',
                            'placeholder' => "['queued', 'running', 'waiting'] oder queued",
                            'help' => 'Optional. Wenn Betreff- oder Titelfilter passt, wird die Mail uebernommen.',
                            'span' => 'full',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'mail_ids',
                            'label' => 'Mail-IDs',
                            'placeholder' => "['message-123', 'message-456']",
                            'help' => 'Optional. Uebernimmt nur Listeneintraege, deren data-message-id, data-mail-id, data-id, id oder href passt.',
                            'span' => 'full',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'search_input_selector',
                            'label' => 'Webmail-Suchfeld Selector',
                            'placeholder' => 'input[type=search], input[aria-label*=Suche]',
                            'help' => 'Optional. Wenn Suchfeld und Suchwert gesetzt sind, wird zuerst die Webmail-Suche ausgefuehrt. Betreff- und Titelfilter werden danach ignoriert.',
                            'span' => 'full',
                            'tab' => 'Webmail-Suche',
                        ],
                        [
                            'name' => 'search_value',
                            'label' => 'Webmail-Suchwert',
                            'placeholder' => 'Proton, Instagram oder security@mail.instagram.com',
                            'help' => 'Fester Suchtext oder Name/Pfad einer Workflow-Variable.',
                            'span' => 'full',
                            'tab' => 'Webmail-Suche',
                        ],
                        [
                            'name' => 'search_button_selector',
                            'label' => 'Suchen-Button Selector',
                            'placeholder' => 'button[type=submit], button:has-text("Suchen")',
                            'help' => 'Optional. Wenn leer, wird nach dem Fuellen des Suchfelds Enter gedrueckt.',
                            'span' => 'full',
                            'tab' => 'Webmail-Suche',
                        ],
                        [
                            'name' => 'search_wait_ms',
                            'label' => 'Warten nach Suche (ms)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 30000,
                            'step' => 100,
                            'placeholder' => '1200',
                            'default' => '1200',
                            'tab' => 'Webmail-Suche',
                        ],
                        [
                            'name' => 'date_selector',
                            'label' => 'Datum-Selector',
                            'placeholder' => 'time, [title], [class*=date]',
                            'tab' => 'Zeit',
                        ],
                        [
                            'name' => 'date_attribute',
                            'label' => 'Datum-Attribut',
                            'placeholder' => "title oder ['datetime', 'title']",
                            'help' => 'Attribut am Datumselement, z.B. title, datetime, data-date. Leer nutzt Text und Standardattribute.',
                            'tab' => 'Zeit',
                        ],
                        [
                            'name' => 'max_age_minutes',
                            'label' => 'Mail maximal alt (Minuten)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 10080,
                            'step' => 1,
                            'placeholder' => '15',
                            'tab' => 'Zeit',
                        ],
                        [
                            'name' => 'mail_time_gmt_offset_hours',
                            'label' => 'GMT-Offset der Mailzeit',
                            'type' => 'number',
                            'min' => -14,
                            'max' => 14,
                            'step' => 0.5,
                            'placeholder' => '0',
                            'default' => '0',
                            'help' => 'Zeitzone der im Mail-Listeneintrag erkannten Uhrzeit. Beispiel: 0 fuer GMT/UTC. Die Ausgabe wird automatisch in die Zeitzone des Browserfensters umgerechnet.',
                            'tab' => 'Zeit',
                        ],
                        [
                            'name' => 'wait_for_new_mail_seconds',
                            'label' => 'Auf neue Mail warten (Sekunden)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 3600,
                            'step' => 5,
                            'placeholder' => '60',
                            'help' => 'Der Scan wiederholt sich im 5-Sekunden-Takt, bis eine neue passende Mail gefunden wurde oder die Zeit ablaeuft.',
                            'tab' => 'Zeit',
                        ],
                        [
                            'name' => 'max_items',
                            'label' => 'Maximale Treffer',
                            'type' => 'number',
                            'min' => 1,
                            'max' => 200,
                            'step' => 1,
                            'placeholder' => '30',
                            'default' => '50',
                            'tab' => 'Ausgabe',
                        ],
                    ],
                ],
            ],
            'mail.list_search_loop' => [
                'label' => 'Mail-Liste durchsuchen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/list_search_loop.cjs',
                'timeout_seconds' => 120,
                'description' => 'Durchlaeuft ein zuvor erzeugtes Mail-Array, oeffnet passende Listeneintraege und speichert die gefundene Mail oder einen extrahierten Wert unter frei waehlbarem Namen.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Mail-Body-Selector',
                    'selector_placeholder' => '[role=document], article, [class*=mail-body] oder leer fuer Fallbacks',
                    'value' => true,
                    'value_required' => false,
                    'value_label' => 'Suchtext',
                    'value_placeholder' => 'Instagram, Sicherheitscode oder Domain',
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        [
                            'name' => 'input_array_name',
                            'label' => 'Eingabe-Array',
                            'placeholder' => 'inbox_mails',
                            'default' => 'inbox_mails',
                            'tab' => 'Quelle',
                        ],
                        [
                            'name' => 'search_fields',
                            'label' => 'Suchfelder',
                            'placeholder' => 'subject,title,sender,preview,body',
                            'default' => 'subject,title,sender,preview,body',
                            'help' => "Mehrere Werte als Komma-Liste oder Array-Schreibweise: ['subject', 'body']",
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'subject_selector',
                            'label' => 'Betreff-Selector',
                            'placeholder' => '[class*=subject]',
                            'help' => 'Optional, falls Betreff nach dem Oeffnen der Mail aus dem DOM gelesen werden soll.',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'subject_filter',
                            'label' => 'Betreff muss enthalten',
                            'placeholder' => "['queued', 'running', 'waiting'] oder queued",
                            'help' => 'Optional. Wenn Betreff- oder Titelfilter passt, landet die Mail im Trefferarray.',
                            'span' => 'full',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'title_selector',
                            'label' => 'Titel-Selector',
                            'placeholder' => '[title], [class*=title]',
                            'help' => 'Optional, falls der Titel nach dem Oeffnen der Mail aus dem DOM gelesen werden soll.',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'title_filter',
                            'label' => 'Titel muss enthalten',
                            'placeholder' => "['queued', 'running', 'waiting'] oder queued",
                            'help' => 'Optional. Wenn Betreff- oder Titelfilter passt, landet die Mail im Trefferarray.',
                            'span' => 'full',
                            'tab' => 'Filter',
                        ],
                        [
                            'name' => 'output_mail_name',
                            'label' => 'Ausgabe-Mail',
                            'placeholder' => 'matched_mail',
                            'default' => 'matched_mail',
                            'tab' => 'Ausgabe',
                        ],
                        [
                            'name' => 'output_value_name',
                            'label' => 'Ausgabe-Wert',
                            'placeholder' => 'verification_code oder mail_body',
                            'help' => 'Optional. Wenn gesetzt, wird ein extrahierter Wert oder der konfigurierte Mail-Inhalt unter diesem Namen gespeichert.',
                            'tab' => 'Ausgabe',
                        ],
                        [
                            'name' => 'output_value_source',
                            'label' => 'Quelle fuer Ausgabe-Wert',
                            'placeholder' => 'body',
                            'default' => 'body',
                            'help' => 'Wird genutzt, wenn keine Regex/Extraktion aktiv ist. Beispiele: body, subject, sender, preview.',
                            'tab' => 'Ausgabe',
                        ],
                        [
                            'name' => 'max_age_minutes',
                            'label' => 'Mail maximal alt (Minuten)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 10080,
                            'step' => 1,
                            'placeholder' => '15',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'max_open_count',
                            'label' => 'Maximal oeffnen',
                            'type' => 'number',
                            'min' => 1,
                            'max' => 50,
                            'step' => 1,
                            'placeholder' => '8',
                            'default' => '8',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'open_wait_ms',
                            'label' => 'Warten nach Oeffnen (ms)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 10000,
                            'step' => 100,
                            'placeholder' => '900',
                            'default' => '900',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'stop_on_first_match',
                            'label' => 'Beim ersten Treffer stoppen',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'help' => 'true/false',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'regex',
                            'label' => 'Regex fuer Wert',
                            'placeholder' => '\\b\\d{6}\\b',
                            'help' => 'Optional. Wenn gesetzt, wird aus dem Mail-Body ein Wert extrahiert.',
                            'tab' => 'Extraktion',
                        ],
                    ],
                ],
            ],
            'mail.list_action_loop' => [
                'label' => 'Mail-Liste: Aktion je Mail ausfuehren',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/list_action_loop.cjs',
                'timeout_seconds' => 600,
                'description' => 'Durchlaeuft ein zuvor gescanntes Mail-Array, oeffnet jede Mail und fuehrt eine oder mehrere konfigurierbare Klickaktionen aus, z. B. Loeschen und Bestaetigen.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Aktions-Selector',
                    'selector_placeholder' => 'button[aria-label*=delete i], text=Loeschen',
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        [
                            'name' => 'input_array_name',
                            'label' => 'Eingabe-Mail-Array',
                            'placeholder' => 'inbox_mails',
                            'default' => 'inbox_mails',
                            'tab' => 'Quelle & Oeffnen',
                        ],
                        [
                            'name' => 'open_selector',
                            'label' => 'Mail-Oeffnen-Selector',
                            'placeholder' => '[role=row], [data-message-id]',
                            'help' => 'Optional. Leer verwendet den beim Inbox-Scan gespeicherten Listitem-Selector.',
                            'tab' => 'Quelle & Oeffnen',
                        ],
                        [
                            'name' => 'open_wait_ms',
                            'label' => 'Warten nach Oeffnen (ms)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 30000,
                            'step' => 100,
                            'default' => '700',
                            'tab' => 'Quelle & Oeffnen',
                        ],
                        [
                            'name' => 'confirm_selector',
                            'label' => 'Bestaetigungs-Selector',
                            'placeholder' => 'button:has-text("Endgueltig loeschen")',
                            'help' => 'Optionaler zweiter Klick nach dem Aktions-Selector.',
                            'tab' => 'Aktionen',
                        ],
                        [
                            'name' => 'action_steps',
                            'label' => 'Weitere Aktionsschritte (JSON)',
                            'type' => 'textarea',
                            'rows' => 7,
                            'span' => 'full',
                            'placeholder' => "[\n  {\"selector\":\"button[aria-label*=delete i]\",\"wait_ms\":500},\n  {\"selector\":\"button:has-text('Bestaetigen')\",\"wait_ms\":700}\n]",
                            'help' => 'Wenn gesetzt, ersetzt diese Reihenfolge Aktions- und Bestaetigungs-Selector. required:false erlaubt optionale Schritte.',
                            'tab' => 'Aktionen',
                        ],
                        [
                            'name' => 'return_selector',
                            'label' => 'Zurueck-zur-Liste-Selector',
                            'placeholder' => 'button[aria-label*=back i], text=Posteingang',
                            'help' => 'Optional. Nur erforderlich, wenn die Aktion nicht automatisch zur Mail-Liste zurueckkehrt.',
                            'tab' => 'Aktionen',
                        ],
                        [
                            'name' => 'action_wait_ms',
                            'label' => 'Warten je Aktion (ms)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 30000,
                            'step' => 100,
                            'default' => '500',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'action_timeout_ms',
                            'label' => 'Selector-Timeout (ms)',
                            'type' => 'number',
                            'min' => 250,
                            'max' => 120000,
                            'step' => 250,
                            'default' => '10000',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'max_items',
                            'label' => 'Maximal verarbeiten',
                            'type' => 'number',
                            'min' => 1,
                            'max' => 200,
                            'default' => '50',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'continue_on_error',
                            'label' => 'Nach Einzelfehler fortsetzen',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'tab' => 'Laufzeit',
                        ],
                        [
                            'name' => 'output_array_name',
                            'label' => 'Ergebnis-Array',
                            'placeholder' => 'mail_action_results',
                            'default' => 'mail_action_results',
                            'tab' => 'Ergebnis',
                        ],
                    ],
                ],
            ],
            'mail.extract_value' => [
                'label' => 'Wert aus Mail ermitteln',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/mail/extract_value.cjs',
                'timeout_seconds' => 45,
                'description' => 'Extrahiert per Regex, Code-Heuristik oder Contains-Regel einen Wert aus einer geoeffneten Mail oder einer Workflow-Variable.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Mail-Body-Selector',
                    'selector_placeholder' => '[role=document], article, [class*=mail-body] oder leer fuer sichtbaren Text',
                    'value' => true,
                    'value_required' => false,
                    'value_label' => 'Regex oder Optionen JSON',
                    'value_placeholder' => '\\b\\d{6}\\b oder {"source":"workflow_variables.matched_mail.body","output_value_name":"verification_code"}',
                    'url' => false,
                    'success_payload' => true,
                    'success_payload_label' => 'Ausgabe/Extraktion JSON',
                    'success_payload_placeholder' => '{"output_value_name":"verification_code","extract_mode":"verification_code","required":true}',
                    'failure_payload' => false,
                ],
            ],
            'browser.scroll' => [
                'label' => 'Seite oder Container scrollen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/scroll.cjs',
                'timeout_seconds' => 60,
                'description' => 'Scrollt eine Seite oder einen Container kontrolliert, laedt Lazy Content nach und stoppt optional bei einem Ziel-Selector oder unveraendertem Inhalt.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Scroll-Container (optional)',
                    'selector_placeholder' => 'leer = Browserfenster, sonst z. B. .results',
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'direction', 'label' => 'Richtung', 'default' => 'down', 'placeholder' => 'down oder up', 'tab' => 'Scroll'],
                        ['name' => 'pixels', 'label' => 'Pixel pro Runde', 'type' => 'number', 'min' => 1, 'max' => 100000, 'default' => '600', 'tab' => 'Scroll'],
                        ['name' => 'steps', 'label' => 'Runden', 'type' => 'number', 'min' => 1, 'max' => 1000, 'default' => '1', 'tab' => 'Scroll'],
                        ['name' => 'delay_ms_between_steps', 'label' => 'Pause zwischen Runden (ms)', 'type' => 'number', 'min' => 0, 'max' => 60000, 'default' => '250', 'tab' => 'Scroll'],
                        ['name' => 'until_selector', 'label' => 'Stop-Selector (optional)', 'placeholder' => '.last-result, [data-loaded=true]', 'tab' => 'Stop'],
                        ['name' => 'max_rounds', 'label' => 'Maximale Runden', 'type' => 'number', 'min' => 1, 'max' => 1000, 'default' => '20', 'tab' => 'Stop'],
                        ['name' => 'stop_if_no_change', 'label' => 'Ohne Aenderung stoppen', 'default' => 'true', 'placeholder' => 'true oder false', 'tab' => 'Stop'],
                    ],
                ],
            ],
            'loop.for_each_element' => [
                'label' => 'Fuer jedes DOM-Element',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/loop/for_each_element.cjs',
                'timeout_seconds' => 30,
                'description' => 'Iteriert Treffer eines DOM-Selectors, loest Elemente pro Durchlauf frisch auf und kann Reader-Ausgaben direkt in einem Workflow-Array sammeln.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Treffer-Selector',
                    'selector_placeholder' => '#search .g, .product-card, [data-result]',
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'limit', 'label' => 'Maximale Treffer', 'type' => 'number', 'min' => 0, 'max' => 10000, 'default' => '0', 'help' => '0 verarbeitet alle Treffer.', 'tab' => 'Schleife'],
                        ['name' => 'offset', 'label' => 'Treffer ueberspringen', 'type' => 'number', 'min' => 0, 'max' => 10000, 'default' => '0', 'tab' => 'Schleife'],
                        ['name' => 'only_visible', 'label' => 'Nur sichtbare Elemente', 'default' => 'true', 'placeholder' => 'true oder false', 'tab' => 'Schleife'],
                        ['name' => 'store_current_element_as', 'label' => 'Aktuelles Element als', 'default' => 'current_result', 'placeholder' => 'current_result', 'tab' => 'Variablen'],
                        ['name' => 'store_index_as', 'label' => 'Index als', 'default' => 'result_index', 'placeholder' => 'result_index', 'tab' => 'Variablen'],
                        ['name' => 'collect_to_array', 'label' => 'Direkt in Array sammeln', 'placeholder' => 'top_results', 'help' => 'Optional. Speichert nach jedem erfolgreichen Durchlauf den Wert der Sammelvariable direkt in diesem Workflow-Array.', 'tab' => 'Sammlung'],
                        ['name' => 'collect_from_variable', 'label' => 'Sammelvariable', 'default' => 'current_result', 'placeholder' => 'current_result', 'help' => 'Variable, die ein Reader im Loop-Body erzeugt. Wird nur verwendet, wenn ein Ziel-Array eingetragen ist.', 'tab' => 'Sammlung'],
                        ['name' => 'collect_dedupe_by', 'label' => 'Deduplizieren nach', 'placeholder' => 'url oder product.id', 'help' => 'Optionaler Objektpfad. Bereits vorhandene Werte mit demselben Feldwert werden nicht erneut gespeichert.', 'tab' => 'Sammlung'],
                        ['name' => 'collect_max_items', 'label' => 'Maximale Array-Eintraege', 'type' => 'number', 'min' => 0, 'max' => 100000, 'default' => '0', 'help' => '0 bedeutet unbegrenzt. Das Loop-Limit bleibt davon getrennt.', 'tab' => 'Sammlung'],
                        ['name' => 'success_target', 'label' => 'Zielkarte je Treffer', 'placeholder' => 'Karten-Key von read_element_fields', 'help' => 'Optional. Ohne Eingabe wird die normale Erfolgsroute verwendet.', 'tab' => 'Routen'],
                        ['name' => 'completion_target', 'label' => 'Zielkarte nach Abschluss', 'placeholder' => 'Karten-Key nach dem Loop', 'help' => 'Optional. Ohne Ziel wird automatisch ueber das gekoppelte Loop-Ende hinter die Schleife gesprungen.', 'tab' => 'Routen'],
                        ['name' => 'empty_target', 'label' => 'Zielkarte bei leerer Liste', 'placeholder' => 'Karten-Key fuer den Leerfall', 'help' => 'Optionaler Sonderweg, wenn bereits die erste Suche keine Elemente liefert.', 'tab' => 'Routen'],
                        ['name' => 'error_target', 'label' => 'Zielkarte bei Fehler', 'placeholder' => 'Karten-Key der Fehlerbehandlung', 'tab' => 'Routen'],
                    ],
                ],
            ],
            'loop.end' => [
                'label' => 'Loop-Ende',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/loop/end.cjs',
                'browser_window' => 'main',
                'timeout_seconds' => 15,
                'description' => 'Automatisches Endsegment einer Schleife. Springt zur Startkarte zurueck, solange die Schleife aktiv ist.',
                'hidden_from_library' => true,
                'form' => [
                    'browser_window' => true,
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
            'browser.read_element_fields' => [
                'label' => 'Felder aus aktuellem Element lesen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/read_element_fields.cjs',
                'timeout_seconds' => 30,
                'description' => 'Liest mehrere konfigurierbare Text-, Link-, HTML-, Attribut- oder Exists-Felder aus dem aktuellen Loop-Element und speichert ein strukturiertes Objekt.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'scope_variable', 'label' => 'Loop-Scope', 'default' => 'current_result', 'placeholder' => 'current_result', 'tab' => 'Quelle'],
                        ['name' => 'output_variable', 'label' => 'Ausgabevariable', 'default' => 'current_result', 'placeholder' => 'current_result', 'help' => 'Darf dem Scope-Namen entsprechen; der DOM-Handle bleibt separat erhalten.', 'tab' => 'Quelle'],
                        [
                            'name' => 'fields',
                            'label' => 'Felddefinitionen (JSON)',
                            'type' => 'textarea',
                            'rows' => 9,
                            'span' => 'full',
                            'placeholder' => '[{"name":"titel","selector":"h3","type":"text","required":true},{"name":"url","selector":"a","type":"href","required":true},{"name":"description","selector":".description","fallback_selectors":[".snippet"],"type":"text"}]',
                            'help' => 'Typen: text, inner_text, href, html, attribute, exists. Optional: attribute_name, multiple, join_with, fallback_selectors, default_value, trim, normalize_whitespace.',
                            'tab' => 'Felder',
                        ],
                    ],
                ],
            ],
            'data.append_to_array' => [
                'label' => 'Objekt an Array anhaengen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/append_to_array.cjs',
                'timeout_seconds' => 15,
                'description' => 'Haengt eine Workflow-Variable dauerhaft an ein benanntes Workflow-Array an, optional mit Deduplizierung und maximaler Laenge.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'array_name', 'label' => 'Array-Name', 'default' => 'top_results', 'placeholder' => 'top_results', 'tab' => 'Array'],
                        ['name' => 'value_from_variable', 'label' => 'Wert aus Variable', 'default' => 'current_result', 'placeholder' => 'current_result', 'tab' => 'Array'],
                        ['name' => 'dedupe_by', 'label' => 'Deduplizieren nach', 'placeholder' => 'url', 'help' => 'Optionaler Objektpfad, z. B. url oder product.id.', 'tab' => 'Array'],
                        ['name' => 'max_items', 'label' => 'Maximale Eintraege', 'type' => 'number', 'min' => 0, 'max' => 100000, 'default' => '0', 'help' => '0 bedeutet unbegrenzt.', 'tab' => 'Array'],
                    ],
                ],
            ],
            'decision.array_length' => [
                'label' => 'IF Array-Laenge pruefen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/decision/array_length.cjs',
                'timeout_seconds' => 15,
                'description' => 'Prueft die Anzahl gesammelter Array-Eintraege mit >=, >, =, !=, < oder <= und verzweigt optional dynamisch.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'array_name', 'label' => 'Array-Name', 'default' => 'top_results', 'placeholder' => 'top_results', 'tab' => 'Bedingung'],
                        ['name' => 'operator', 'label' => 'Operator', 'default' => '>=', 'placeholder' => '>=', 'tab' => 'Bedingung'],
                        ['name' => 'compare_value', 'label' => 'Vergleichswert', 'type' => 'number', 'min' => 0, 'max' => 100000, 'default' => '3', 'tab' => 'Bedingung'],
                        ['name' => 'success_target', 'label' => 'Zielkarte wenn erfuellt', 'placeholder' => 'Karten-Key', 'tab' => 'Routen'],
                        ['name' => 'error_target', 'label' => 'Zielkarte wenn nicht erfuellt', 'placeholder' => 'Karten-Key', 'tab' => 'Routen'],
                    ],
                ],
            ],
            'browser.read_searchengine_result' => [
                'label' => 'Suchmaschinentreffer lesen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/read_searchengine_result.cjs',
                'timeout_seconds' => 30,
                'description' => 'Komfortabler, aber engine-unabhaengiger Wrapper fuer Titel, URL, Beschreibung, Site-Name und Breadcrumb eines aktuellen Loop-Treffers.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        ['name' => 'scope_variable', 'label' => 'Loop-Scope', 'default' => 'current_result', 'placeholder' => 'current_result', 'tab' => 'Quelle'],
                        ['name' => 'output_variable', 'label' => 'Ausgabevariable', 'default' => 'current_result', 'placeholder' => 'current_result', 'tab' => 'Quelle'],
                        ['name' => 'title_selector', 'label' => 'Titel-Selector', 'default' => 'h3', 'placeholder' => 'h3, .result-title', 'tab' => 'Selektoren'],
                        ['name' => 'link_selector', 'label' => 'Link-Selector', 'default' => 'a', 'placeholder' => 'a[href]', 'tab' => 'Selektoren'],
                        ['name' => 'description_selector', 'label' => 'Description-Selector', 'placeholder' => '.VwiC3b, .snippet, .description', 'tab' => 'Selektoren'],
                        ['name' => 'site_name_selector', 'label' => 'Site-Name-Selector', 'placeholder' => '.site-name', 'tab' => 'Selektoren'],
                        ['name' => 'breadcrumb_selector', 'label' => 'Breadcrumb-Selector', 'placeholder' => '.breadcrumb', 'tab' => 'Selektoren'],
                        ['name' => 'fallbacks', 'label' => 'Fallbacks (JSON)', 'type' => 'textarea', 'rows' => 5, 'span' => 'full', 'placeholder' => '{"title":["[role=heading]"],"link":["a[href]"],"description":[".VwiC3b",".snippet"]}', 'tab' => 'Fallbacks'],
                        ['name' => 'visible_only', 'label' => 'Nur sichtbare Felder', 'default' => 'true', 'placeholder' => 'true oder false', 'tab' => 'Optionen'],
                        ['name' => 'normalize_url', 'label' => 'Relative URLs normalisieren', 'default' => 'true', 'placeholder' => 'true oder false', 'tab' => 'Optionen'],
                        ['name' => 'trim_text', 'label' => 'Text trimmen', 'default' => 'true', 'placeholder' => 'true oder false', 'tab' => 'Optionen'],
                    ],
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
            'browser.hover' => [
                'label' => 'Element hovern',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/hover.cjs',
                'timeout_seconds' => 30,
                'description' => 'Bewegt den Cursor auf ein Element und haelt den Hover-Zustand fuer folgende Such-, Klick- und Eingabe-Tasks aktiv.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Hover-Selector oder Text',
                    'selector_placeholder' => '[aria-haspopup=true], button:has-text("Mehr") oder text=Menue',
                    'timeout' => true,
                    'timeout_label' => 'Suchdauer in Sekunden',
                    'timeout_help' => 'Wie lange nach dem Hover-Element gesucht wird.',
                    'timeout_min' => 1,
                    'timeout_max' => 3600,
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'settle_ms',
                            'label' => 'Warten nach Hover (ms)',
                            'type' => 'number',
                            'min' => 0,
                            'max' => 10000,
                            'step' => 50,
                            'placeholder' => '250',
                            'default' => '250',
                            'help' => 'Kurze Wartezeit, damit durch Hover sichtbare Elemente gerendert werden.',
                            'tab' => 'Hover',
                        ],
                        [
                            'name' => 'release_after_click',
                            'label' => 'Cursor nach Klick entfernen',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'help' => 'true bewegt den Cursor nach einem erfolgreichen Folge-Klick weg. false haelt den Hover fuer weitere Tasks.',
                            'tab' => 'Hover',
                        ],
                    ],
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
                    'timeout' => true,
                    'timeout_label' => 'Suchdauer in Sekunden',
                    'timeout_help' => 'Wie lange nach dem Element gesucht wird. Fuer schnelle Abfragen z. B. 1-3 Sekunden verwenden.',
                    'timeout_min' => 1,
                    'timeout_max' => 3600,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                ],
            ],
            'decision.variable' => [
                'label' => 'IF Eingabevariable pruefen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/decision/variable.cjs',
                'timeout_seconds' => 15,
                'description' => 'Prueft Werte aus Workflow-Eingaben oder Workflow-Variablen. Erfuellt folgt der Erfolgsroute, nicht erfuellt der Fehlerroute.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
                    'extra_fields' => [
                        [
                            'name' => 'variable_path',
                            'label' => 'Variablenpfad',
                            'placeholder' => 'browser_window oder Mail-Inbox-Liste-Scan.subject_filter',
                            'help' => 'Wird direkt aus den durch Workflow-Eingaben pruefen erzeugten Workflow-Variablen gelesen.',
                            'span' => 'full',
                            'required' => true,
                            'tab' => 'Bedingung',
                        ],
                        [
                            'name' => 'operator',
                            'label' => 'Operator',
                            'placeholder' => 'exists',
                            'default' => 'exists',
                            'help' => 'exists, missing, equals, not_equals, contains, truthy, falsy oder browser_window_open.',
                            'tab' => 'Bedingung',
                        ],
                        [
                            'name' => 'compare_value',
                            'label' => 'Vergleichswert',
                            'placeholder' => 'main, true, 15 oder JSON',
                            'help' => 'Nur fuer equals, not_equals oder contains erforderlich.',
                            'tab' => 'Bedingung',
                        ],
                    ],
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
            'browser.highlight' => [
                'label' => 'Element hervorheben',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/highlight.cjs',
                'timeout_seconds' => 30,
                'description' => 'Sucht ein sichtbares Element und markiert es im Browser fuer die visuelle Pruefung.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector oder Text',
                    'selector_placeholder' => '#search, button[type=submit] oder text=Weiter',
                    'value' => false,
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                ],
            ],
            'browser.press_key' => [
                'label' => 'Taste senden',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/press_key.cjs',
                'timeout_seconds' => 30,
                'description' => 'Sendet eine Playwright-kompatible Taste an das aktive Browserfenster.',
                'form' => [
                    'selector' => false,
                    'value' => true,
                    'value_label' => 'Taste',
                    'value_placeholder' => 'Enter, Escape, Tab oder Control+A',
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
                'description' => 'Fuellt ein konkretes oder heuristisch gefundenes Eingabefeld mit einem festen Wert oder einer Workflow-Variable.',
                'form' => [
                    'selector' => true,
                    'selector_label' => 'Selector',
                    'selector_placeholder' => 'input[name=email], #password',
                    'value' => true,
                    'value_required' => false,
                    'value_source_control' => true,
                    'value_label' => 'Fester Wert',
                    'value_placeholder' => 'Suchtext, E-Mail-Adresse oder anderer fester Wert',
                    'value_help' => 'Wird nur verwendet, wenn als Wertquelle "Fester Wert" gewaehlt ist.',
                    'url' => false,
                    'success_payload' => true,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'value_source',
                            'label' => 'Wertquelle',
                            'type' => 'select',
                            'options' => [
                                'fixed' => 'Fester Wert',
                                'workflow_variable' => 'Workflow-Variable',
                            ],
                            'tab' => 'Eingabe',
                            'span' => 'full',
                        ],
                        [
                            'name' => 'workflow_variable',
                            'label' => 'Name der Workflow-Variable',
                            'placeholder' => 'google_search_url oder workflow_inputs.search_query',
                            'help' => 'Der Variablenwert wird zur Laufzeit eingesetzt. Der eingegebene Name selbst wird nie in das Browserfeld geschrieben.',
                            'required_when' => [
                                'field' => 'value_source',
                                'equals' => 'workflow_variable',
                            ],
                            'visible_when' => [
                                'field' => 'value_source',
                                'equals' => 'workflow_variable',
                            ],
                            'format' => 'variable_path',
                            'tab' => 'Eingabe',
                        ],
                        [
                            'name' => 'value_fallback',
                            'label' => 'Fallback-Wert',
                            'placeholder' => 'Optionaler Wert, falls die Variable fehlt oder leer ist',
                            'help' => 'Bleibt dieses Feld leer, schlaegt die Task mit einer eindeutigen Diagnose fehl, statt den Variablennamen einzugeben.',
                            'visible_when' => [
                                'field' => 'value_source',
                                'equals' => 'workflow_variable',
                            ],
                            'tab' => 'Eingabe',
                        ],
                    ],
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
            'data.validate_inputs' => [
                'label' => 'Workflow-Eingaben pruefen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/validate_inputs.cjs',
                'timeout_seconds' => 15,
                'description' => 'Prueft Workflow-Eingaben. Nur fehlende Pflichtwerte verwenden die Fehlerroute; optionale Werte werden mit Setzstatus und Defaults in der Ausgabegruppe dokumentiert.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'input_definitions',
                            'label' => 'Eingabewerte',
                            'type' => 'workflow_input_definitions',
                            'span' => 'full',
                            'help' => 'Das Formular speichert intern weiterhin ein JSON-Array. Ohne Quelle wird unter dem Variablennamen gesucht. Nur gesetzte Werte ueberschreiben folgende Tasks.',
                            'tab' => 'Eingaben',
                        ],
                        [
                            'name' => 'output_group',
                            'label' => 'Ausgabe-Gruppe',
                            'placeholder' => 'workflow_inputs',
                            'default' => 'workflow_inputs',
                            'help' => 'Speichert Direktwerte sowie _inputs mit set/present/required und _summary mit dem Pruefergebnis unter diesem Workflow-Variablennamen.',
                            'tab' => 'Ausgabe',
                        ],
                    ],
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
                'label' => 'Workflow-/Mail-Accountdaten speichern',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/persist_mail_account.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistMailAccountTask@handle',
                'timeout_seconds' => 30,
                'description' => 'Speichert E-Mail, Passwort, Provider und weitere Werte als Workflow-Variablen und optional am Mail-Account der Persona.',
                'form' => [
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'persist_account',
                            'label' => 'Mail-Account persistieren',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'help' => 'true speichert die Accountdaten verschluesselt an der Persona. false speichert nur Workflow-Variablen.',
                            'tab' => 'Speichern',
                        ],
                        [
                            'name' => 'group_variable',
                            'label' => 'Gruppenvariable',
                            'placeholder' => 'email_account',
                            'default' => 'email_account',
                            'help' => 'Alle erkannten Werte werden zusaetzlich als Objekt unter diesem Workflow-Variablennamen gespeichert.',
                            'tab' => 'Speichern',
                        ],
                        [
                            'name' => 'field_map',
                            'label' => 'Feldzuordnung',
                            'type' => 'textarea',
                            'rows' => 5,
                            'span' => 'full',
                            'placeholder' => '{"email":"account.email","password":"generated_password","provider":"literal:proton"}',
                            'help' => 'Optional als JSON oder zeilenweise key=quelle. Quellen koennen Context-Pfade oder literal:Wert sein.',
                            'tab' => 'Speichern',
                        ],
                        [
                            'name' => 'email_source',
                            'label' => 'E-Mail Quelle',
                            'placeholder' => 'account.email',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'email_variable',
                            'label' => 'E-Mail Variable',
                            'placeholder' => 'email',
                            'default' => 'email',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'username_source',
                            'label' => 'Benutzername Quelle',
                            'placeholder' => 'account.username',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'username_variable',
                            'label' => 'Benutzername Variable',
                            'placeholder' => 'username',
                            'default' => 'username',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'password_source',
                            'label' => 'Passwort Quelle',
                            'placeholder' => 'generated_password',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'password_variable',
                            'label' => 'Passwort Variable',
                            'placeholder' => 'password',
                            'default' => 'password',
                            'help' => 'Wird intern gespeichert, aber in oeffentlichen Vorschau-/Run-Ausgaben ausgeblendet.',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'provider_source',
                            'label' => 'Provider Quelle',
                            'placeholder' => 'literal:proton',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'provider_variable',
                            'label' => 'Provider Variable',
                            'placeholder' => 'provider',
                            'default' => 'provider',
                            'tab' => 'Einzelwerte',
                        ],
                        [
                            'name' => 'webmail_url_source',
                            'label' => 'Webmail-URL Quelle',
                            'placeholder' => 'account.webmailUrl',
                            'tab' => 'Weitere Werte',
                        ],
                        [
                            'name' => 'webmail_url_variable',
                            'label' => 'Webmail-URL Variable',
                            'placeholder' => 'webmail_url',
                            'default' => 'webmail_url',
                            'tab' => 'Weitere Werte',
                        ],
                        [
                            'name' => 'recovery_email_source',
                            'label' => 'Recovery-E-Mail Quelle',
                            'placeholder' => 'account.recoveryEmail',
                            'tab' => 'Weitere Werte',
                        ],
                        [
                            'name' => 'recovery_email_variable',
                            'label' => 'Recovery-E-Mail Variable',
                            'placeholder' => 'recovery_email',
                            'default' => 'recovery_email',
                            'tab' => 'Weitere Werte',
                        ],
                    ],
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
            'data.persist_browser_session' => [
                'label' => 'Browser-Session speichern',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/persist_browser_session.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistBrowserSessionTask@handle',
                'browser_window' => 'main',
                'timeout_seconds' => 30,
                'description' => 'Speichert Cookies und Browser-Storage der aktuellen Website verschluesselt an der Person. Domain und Cookie-Domains werden mitgespeichert.',
                'form' => [
                    'browser_window' => true,
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'target_domain',
                            'label' => 'Domain/URL',
                            'placeholder' => 'leer = aktuelle Browserfenster-URL',
                            'help' => 'Optional. Beispiel: example.com oder https://example.com. Leer nutzt die URL des ausgewaehlten Browserfensters.',
                            'tab' => 'Session',
                        ],
                        [
                            'name' => 'session_key',
                            'label' => 'Session-Key',
                            'placeholder' => 'leer = Domain',
                            'help' => 'Optionaler Speichername unter metadata.browser_sessions.',
                            'tab' => 'Session',
                        ],
                        [
                            'name' => 'session_label',
                            'label' => 'Anzeigename',
                            'placeholder' => 'Shop Login, Dashboard, Webmail',
                            'tab' => 'Session',
                        ],
                    ],
                ],
            ],
            'data.delete_browser_session' => [
                'label' => 'Browser-Session/Cookies loeschen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/delete_browser_session.cjs',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistBrowserSessionTask@delete',
                'browser_window' => 'main',
                'timeout_seconds' => 30,
                'description' => 'Loescht Cookies und Browser-Storage fuer eine Website. Ohne Domain-Eingabe wird die aktuelle URL des Browserfensters verwendet.',
                'form' => [
                    'browser_window' => true,
                    'selector' => false,
                    'value' => false,
                    'url' => false,
                    'mailbox_source' => false,
                    'success_payload' => false,
                    'failure_payload' => true,
                    'extra_fields' => [
                        [
                            'name' => 'target_domain',
                            'label' => 'Domain/URL',
                            'placeholder' => 'leer = aktuelle Browserfenster-URL',
                            'help' => 'Optional. Beispiel: example.com oder https://example.com.',
                            'tab' => 'Loeschen',
                        ],
                        [
                            'name' => 'session_key',
                            'label' => 'Session-Key',
                            'placeholder' => 'leer = Domain',
                            'help' => 'Optional. Wird genutzt, um gespeicherte Sessions gezielt aus metadata.browser_sessions zu entfernen.',
                            'tab' => 'Loeschen',
                        ],
                        [
                            'name' => 'clear_cookies',
                            'label' => 'Cookies loeschen',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'help' => 'true/false',
                            'tab' => 'Loeschen',
                        ],
                        [
                            'name' => 'clear_storage',
                            'label' => 'Local/Session Storage loeschen',
                            'placeholder' => 'true',
                            'default' => 'true',
                            'help' => 'true/false',
                            'tab' => 'Loeschen',
                        ],
                    ],
                ],
            ],
            'data.workflow_return' => [
                'label' => 'Workflow-Rueckgabewert setzen',
                'kind' => 'data',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/data/workflow_return.cjs',
                'timeout_seconds' => 15,
                'description' => 'Setzt einen Rueckgabewert fuer eingebettete Workflows, damit der uebergeordnete Workflow auf true, false oder eine Variable reagieren kann.',
                'form' => [
                    'selector' => true,
                    'selector_required' => false,
                    'selector_label' => 'Variablenname',
                    'selector_placeholder' => 'workflow_return, mailbox_ready, code_found',
                    'value' => true,
                    'value_required' => false,
                    'value_label' => 'Rueckgabewert',
                    'value_placeholder' => 'true, false, Text oder JSON',
                    'url' => false,
                    'mailbox_source' => false,
                    'success_payload' => false,
                    'failure_payload' => false,
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

        return collect($tasks)
            ->map(fn (array $definition, string $taskKey): array => $this->withDocumentation($taskKey, $definition))
            ->all();
    }

    protected function withDocumentation(string $taskKey, array $definition): array
    {
        $description = trim((string) ($definition['description'] ?? ''));
        $runner = (string) ($definition['runner'] ?? 'node');
        $script = trim((string) ($definition['node_script'] ?? $definition['php_handler'] ?? ''));

        $definition['documentation'] = [
            'purpose' => $description,
            'use_when' => 'Nutze diese Task, wenn der Workflow genau diesen fachlichen Teilschritt benoetigt: '.$description,
            'workflow_role' => $this->workflowRoleDocumentation($taskKey),
            'execution' => $runner === 'node'
                ? 'Die Task wird im Node-Workflow-Runtime innerhalb der aktiven Liste ausgefuehrt'.($script !== '' ? ' ('.$script.')' : '').'. Ihre Ausgaben werden vor der naechsten Task in den gemeinsamen Workflow-Kontext uebernommen.'
                : 'Die Task wird serverseitig ausgefuehrt. Das Ergebnis wird normalisiert und fuer nachfolgende Listen und Tasks im Workflow-Kontext bereitgestellt.',
            'inputs' => $this->documentationFields($definition),
            'outputs' => $this->outputDocumentation($taskKey),
            'routing' => $this->routingDocumentation($taskKey),
            'example_configuration' => $this->exampleConfiguration($definition),
            'important_notes' => $this->documentationNotes($taskKey),
            'scope_behavior' => $this->scopeBehaviorDocumentation($taskKey),
            'compatibility' => $this->compatibilityDocumentation($taskKey),
            'failure_modes' => $this->failureModeDocumentation($taskKey),
            'recipes' => $this->recipeDocumentation($taskKey),
        ];

        return $definition;
    }

    protected function workflowRoleDocumentation(string $taskKey): string
    {
        return match (true) {
            str_starts_with($taskKey, 'browser.open') => 'Initialisiert ein benanntes Browserfenster. Nachfolgende Browser-, Input- und Wait-Tasks muessen dasselbe browser_window verwenden.',
            str_starts_with($taskKey, 'browser.') => 'Arbeitet im aktuell gewaehlten Browserfenster und liefert Beobachtungs- oder Aktionsdaten an die folgenden Tasks derselben Liste.',
            str_starts_with($taskKey, 'input.') => 'Veraendert Formulardaten im Browser. Die Task sollte nach einer passenden Browser-/Find-Task und vor einer Submit- oder Pruef-Task stehen.',
            str_starts_with($taskKey, 'wait.') => 'Synchronisiert den Workflow mit Zeit oder sichtbarem Seitenzustand, ohne eigenstaendige Fachdaten zu erzeugen.',
            str_starts_with($taskKey, 'decision.') => 'Erzeugt eine fachliche Verzweigung. Erfolg und nicht erfuellte Bedingung muessen auf unterschiedliche, nachvollziehbare Ziele zeigen.',
            str_starts_with($taskKey, 'loop.') => 'Steuert einen wiederholten Task-Block. Automatische Laeufe behandeln das Paar als wiederholbares Segment; im Studio koennen Start, Body und Ende einzeln getestet werden.',
            str_starts_with($taskKey, 'mail.') || str_starts_with($taskKey, 'webmail.') => 'Verarbeitet Mailbox-/Webmail-Daten und stellt Treffer oder Status als Workflow-Daten fuer spaetere Tasks bereit.',
            str_starts_with($taskKey, 'data.persist_') || $taskKey === 'data.delete_browser_session' => 'Schreibt oder entfernt dauerhafte Anwendungsdaten. Vorher muessen alle benoetigten Werte validiert und auf die richtige Person bzw. Session bezogen sein.',
            str_starts_with($taskKey, 'data.') => 'Liest, transformiert oder speichert Workflow-Daten. Benannte Ausgaben bleiben fuer nachfolgende Tasks und Listen verfuegbar.',
            default => 'Ist ein einzelner ausfuehrbarer Baustein innerhalb einer Workflow-Liste.',
        };
    }

    protected function outputDocumentation(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => [
                'Aktiviert pro Durchlauf ein DOM-Element als privaten Scope und schreibt Index/Metadaten in Workflow-Variablen.',
                'Kann den vom Reader erzeugten Wert automatisch in collect_to_array sammeln.',
                'Liefert matched_count, selected_count, visited_count, processed_count, skipped_count und collected_count.',
            ],
            'loop.end' => ['Springt bei einer aktiven Schleife zur gekoppelten Startkarte zurueck; nach Abschluss laeuft der Workflow hinter dem Loop weiter.'],
            'browser.read_element_fields', 'browser.read_searchengine_result' => ['Schreibt das gelesene Objekt unter output_variable in workflow_variables und liefert dasselbe Objekt als result.'],
            'data.append_to_array' => ['Schreibt das vollstaendige Array unter array_name in workflow_variables und meldet new_length, appended, deduped und limit_reached.'],
            'data.validate_inputs' => [
                'Speichert alle deklarierten Direktwerte unter dem Namen der Ausgabegruppe.',
                'Ergaenzt _inputs mit set, present, required, used_default und dem effektiven Wert je Variable.',
                'Ergaenzt _summary mit valid, has_required_inputs, required_count und missing_required_count.',
            ],
            'decision.array_length' => ['Liefert Array-Laenge, Vergleichsergebnis und die passende dynamische Route. Das Array selbst bleibt unveraendert.'],
            'decision.element_exists', 'decision.variable' => ['Liefert ein boolesches Bedingungsergebnis. Eine nicht erfuellte Bedingung ist ein fachlicher failed-Zweig, kein Runtime-Crash.'],
            'browser.find_inputs' => ['Liefert sichtbare Eingabefelder inklusive Labels, Namen und Selector-Kandidaten fuer nachfolgende Formular-Tasks.'],
            'browser.find_element' => ['Liefert den Fundstatus und Selector-/Elementinformationen fuer nachfolgende Aktionen oder Entscheidungen.'],
            'mail.inbox_list_scan' => ['Speichert die gefundenen Mails unter output_array_name als Workflow-Array und liefert Zaehler sowie Diagnoseinformationen.'],
            'mail.list_search_loop', 'mail.list_action_loop' => ['Liefert Treffer-, Aktions- und Wiederholungsstatus und aktualisiert die konfigurierten Workflow-Variablen.'],
            'mail.extract_value' => ['Schreibt den extrahierten Wert unter output_value_name in workflow_variables.'],
            'mail.generate_address', 'mail.generate_password' => ['Erzeugt den Wert und stellt ihn als Ergebnis sowie als Workflow-Variable fuer die folgenden Tasks bereit.'],
            'data.workflow_return' => ['Setzt workflow_return und workflow_return_ok. Dieser Wert ist die ausdrueckliche Schnittstelle zu Eltern-Workflows und Erfolgskriterien.'],
            'data.read_account_data', 'data.resolve_person', 'data.read_login_data' => ['Liefert normalisierte Personen-, Account- oder Login-Daten fuer nachfolgende Tasks.'],
            'data.persist_mail_account', 'data.persist_webmail_session', 'data.persist_browser_session', 'data.delete_browser_session' => ['Liefert Speicher-/Loeschstatus und die betroffenen, nicht geheimen Identifikatoren.'],
            'wait.selector', 'wait.seconds', 'wait.status' => ['Liefert Warte- und Statusinformationen; vorhandene Workflow-Variablen bleiben unveraendert erhalten.'],
            'browser.open', 'browser.open_url', 'browser.open_webmail_session', 'browser.open_browser_session' => ['Stellt ein benanntes Browserfenster bzw. eine Seite fuer nachfolgende Tasks bereit und meldet den Oeffnungs-/Sessionstatus.'],
            'browser.close' => ['Meldet das geschlossene Browserfenster; andere benannte Fenster bleiben erhalten.'],
            default => ['Liefert einen normalisierten Status mit ok, status und statusMessage. Task-spezifische Ergebniswerte werden in den gemeinsamen Runtime-Kontext uebernommen.'],
        };
    }

    protected function routingDocumentation(string $taskKey): array
    {
        $base = [
            'Ohne ausdrueckliche Erfolgsroute folgt die naechste Task derselben Liste; am Listenende folgt die Erfolgsroute der Liste.',
            'on_error bzw. status_routes.failed/timeout hat Vorrang vor der Fehlerroute der Liste.',
        ];

        return match ($taskKey) {
            'loop.for_each_element' => [
                'success_target startet den Loop-Body fuer jeden Treffer.',
                'completion_target wird nach mindestens einem verarbeiteten Treffer verwendet.',
                'empty_target gilt nur, wenn die Trefferliste von Anfang an leer ist.',
                'Ohne Abschlussziel springt die Task automatisch zum gekoppelten loop.end und danach hinter die Schleife.',
                'error_target behandelt Selector-, DOM- und Array-Speicherfehler.',
            ],
            'loop.end' => ['Solange der Loop aktiv ist, erfolgt automatisch ein Ruecksprung zur gekoppelten Startkarte. Das Loop-Ende darf nicht separat umgeroutet oder aus dem Paar geloest werden.'],
            'decision.array_length' => ['success_target gilt bei erfuelltem Vergleich, error_target bei nicht erfuelltem Vergleich. Beide Ziele sollten fachlich verschieden sein.'],
            'decision.element_exists', 'decision.variable' => [
                'next ist der Wahr-/Erfolgsweg.',
                'on_error ist der Falsch-/Nicht-erfuellt-Weg und darf fuer optionale Bedingungen normal weiterfuehren.',
            ],
            default => $base,
        };
    }

    protected function documentationNotes(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => [
                'Ein Reader muss zwischen Loop-Start und Loop-Ende stehen, bevor dessen Ausgabe gesammelt werden kann.',
                'collect_from_variable und output_variable des Readers muessen denselben Namen verwenden.',
                'Loop-Limit begrenzt Durchlaeufe; collect_max_items begrenzt nur die gespeicherte Array-Laenge.',
                'Das DOM-Element wird vor jeder Iteration anhand einer stabilen Identitaet frisch aufgeloest.',
                'Im Studio wird der aktuelle Scope als Selector, Offset und Index serialisiert, damit die folgende Reader-Task in einem separaten Einzelschritt fortsetzen kann.',
            ],
            'browser.read_searchengine_result' => [
                'Alle Selektoren werden relativ zum aktuellen Loop-Scope ausgewertet.',
                'Ist der Scope selbst ein Link und link_selector findet keinen untergeordneten Link, wird die href des Scope-Elements verwendet.',
                'Fuer Google-Ergebnisse ist beispielsweise #search a:has(h3) als Loop-Selector mit h3 als title_selector kompatibel.',
            ],
            'loop.end' => ['Diese interne Task wird vom Studio automatisch mit loop_pair_id und loop_start_key erzeugt und gemeinsam mit dem Loop-Start verwaltet.'],
            'data.append_to_array' => [
                'value_from_variable muss auf die Ausgabe einer vorherigen Task zeigen; bei leerem Wert schlaegt die Task mit einem eindeutigen reason_code fehl.',
                'dedupe_by bezieht sich auf einen Pfad innerhalb des anzuhangenden Objekts, beispielsweise url oder product.id.',
                'Das Array bleibt als workflow_variables[array_name] ueber Listen- und Step-Grenzen hinweg erhalten.',
            ],
            'data.validate_inputs' => [
                'Die Fehlerroute wird ausschliesslich verwendet, wenn mindestens eine required=true-Variable fehlt oder ein erforderliches Browserfenster nicht offen ist.',
                'set=true bedeutet, dass der Workflow die Variable geliefert hat. present=true kann auch durch einen Default entstehen.',
                'Ohne required=true-Definition ist das Ergebnis immer success, auch wenn alle optionalen Werte fehlen.',
                'Das Variablenmenue speichert weiterhin kompatibles JSON unter input_definitions.',
            ],
            'data.workflow_return' => ['Das Sammeln in ein Array beendet den Workflow nicht. Fuer eingebettete Workflows muss das fertige Array anschliessend mit data.workflow_return zurueckgegeben werden.'],
            'input.fill_field' => ['Bei value_source=workflow_variable wird der Variablenname aufgeloest; er darf nicht als sichtbarer Literaltext in das Formular geschrieben werden.'],
            'browser.click', 'input.submit' => ['Nach Navigation oder deutlicher DOM-Aenderung sollte eine Wait- oder Find-Task den neuen Seitenzustand bestaetigen.'],
            default => ['Verwende stabile Kartenschluessel und benannte Workflow-Variablen, damit Copilot, Routing und Laufdiagnose dieselben Referenzen benutzen.'],
        };
    }

    protected function scopeBehaviorDocumentation(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => [
                'produces_scope' => true,
                'scope_variable_field' => 'store_current_element_as',
                'scope_is_persistable' => true,
            ],
            'browser.read_element_fields', 'browser.read_searchengine_result' => [
                'consumes_scope' => true,
                'scope_variable_field' => 'scope_variable',
                'selectors_are_relative' => true,
                'scope_self_supported' => true,
            ],
            default => [],
        };
    }

    protected function compatibilityDocumentation(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => [
                'body_producers' => ['browser.read_element_fields', 'browser.read_searchengine_result'],
                'body_consumers' => ['data.append_to_array'],
                'paired_with' => ['loop.end'],
                'collection_modes' => ['collect_to_array', 'data.append_to_array'],
            ],
            'browser.read_element_fields', 'browser.read_searchengine_result' => [
                'requires_preceding' => ['loop.for_each_element'],
                'compatible_consumers' => ['data.append_to_array', 'data.workflow_return'],
            ],
            'data.append_to_array' => [
                'requires_variable_producer' => true,
                'compatible_producers' => ['browser.read_element_fields', 'browser.read_searchengine_result'],
            ],
            'data.workflow_return' => ['accepts' => ['scalar', 'object', 'array', 'boolean']],
            default => [],
        };
    }

    protected function failureModeDocumentation(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => ['selector_missing', 'loop_element_missing_after_refresh', 'array_not_array', 'value_missing'],
            'browser.read_searchengine_result' => ['scope_missing', 'required_title_missing', 'required_url_missing'],
            'data.append_to_array' => ['array_not_array', 'value_missing'],
            default => [],
        };
    }

    protected function recipeDocumentation(string $taskKey): array
    {
        return match ($taskKey) {
            'loop.for_each_element' => [[
                'name' => 'Suchergebnisse als Array sammeln',
                'sequence' => ['loop.for_each_element', 'browser.read_searchengine_result', 'data.append_to_array', 'loop.end', 'data.workflow_return'],
                'invariant' => 'Reader output_variable entspricht append value_from_variable; Loop limit entspricht der gewuenschten Maximalzahl.',
            ]],
            'browser.read_searchengine_result' => [[
                'name' => 'Google Ergebnislink als Scope',
                'loop_selector' => '#search a:has(h3)',
                'title_selector' => 'h3',
                'link_selector' => 'a',
                'link_fallback' => ':scope',
            ]],
            default => [],
        };
    }

    protected function documentationFields(array $definition): array
    {
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];
        $fields = [];

        foreach ([
            'selector' => ['label' => 'Selector', 'required' => 'selector_required'],
            'value' => ['label' => 'Wert', 'required' => 'value_required'],
            'url' => ['label' => 'URL', 'required' => 'url_required'],
            'browser_window' => ['label' => 'Browserfenster', 'required' => 'browser_window_required'],
            'mailbox_source' => ['label' => 'Mailbox-Quelle', 'required' => 'mailbox_source_required'],
        ] as $name => $metadata) {
            if (! (bool) ($form[$name] ?? false)) {
                continue;
            }

            $fields[] = array_filter([
                'name' => $name,
                'label' => (string) ($form[$name.'_label'] ?? $metadata['label']),
                'required' => (bool) ($form[$metadata['required']] ?? false),
                'explanation' => (string) ($form[$name.'_help'] ?? $form[$name.'_placeholder'] ?? ''),
            ], static fn (mixed $value): bool => $value !== '');
        }

        foreach (is_array($form['extra_fields'] ?? null) ? $form['extra_fields'] : [] as $field) {
            if (! is_array($field) || blank($field['name'] ?? null)) {
                continue;
            }

            $fields[] = array_filter([
                'name' => (string) $field['name'],
                'label' => (string) ($field['label'] ?? $field['name']),
                'required' => (bool) ($field['required'] ?? false),
                'default' => $field['default'] ?? null,
                'explanation' => (string) ($field['help'] ?? $field['placeholder'] ?? ''),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $fields;
    }

    protected function exampleConfiguration(array $definition): array
    {
        return collect($this->documentationFields($definition))
            ->mapWithKeys(function (array $field): array {
                $value = $field['default'] ?? null;

                if ($value === null && (bool) ($field['required'] ?? false)) {
                    $value = '<'.($field['name'] ?? 'wert').'>';
                }

                return $value === null ? [] : [(string) $field['name'] => $value];
            })
            ->all();
    }

    public function options(): array
    {
        return collect($this->all())
            ->filter(fn (array $task): bool => ! (bool) ($task['hidden_from_library'] ?? false))
            ->map(fn (array $task, string $key): array => [
                'key' => $key,
                'label' => $task['label'],
                'kind' => $task['kind'],
                'runner' => $task['runner'],
                'description' => $task['description'] ?? '',
                'documentation' => $task['documentation'] ?? [],
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

        foreach (['node_script', 'php_handler', 'workflow_id', 'workflow_slug', 'browser_window', 'browser_window_name', 'selector', 'element_selector', 'input_selector', 'input', 'value', 'url', 'mailbox_source', 'script_person_source', 'success_payload', 'failure_payload', 'next', 'on_partial', 'on_error', 'status_routes', 'loop_pair_id', 'loop_pair_segment', 'loop_start_key', 'loop_end_key'] as $key) {
            $value = Arr::get($overrides, $key, Arr::get($definition, $key));

            if ($value !== null && $value !== '') {
                $card[$key] = $value;
            }
        }

        foreach (is_array(data_get($definition, 'form.extra_fields')) ? data_get($definition, 'form.extra_fields') : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) ($field['name'] ?? '')) ?: '';

            if ($key === '') {
                continue;
            }

            $value = Arr::get($overrides, $key, Arr::get($definition, $key, $field['default'] ?? null));

            if ($value !== null && $value !== '') {
                $card[$key] = $value;
            }
        }

        return $card;
    }
}
