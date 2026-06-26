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
                'label' => 'Browser starten',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open.cjs',
                'timeout_seconds' => 60,
                'description' => 'Startet oder uebernimmt einen Browser-Kontext fuer weitere Workflow-Karten.',
            ],
            'browser.open_url' => [
                'label' => 'URL aufrufen',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open_url.cjs',
                'timeout_seconds' => 120,
                'description' => 'Navigiert zu einer variablen URL und wartet optional auf ein Ziel-Element.',
            ],
            'browser.find_inputs' => [
                'label' => 'Input-Felder suchen',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/find_inputs.cjs',
                'timeout_seconds' => 45,
                'description' => 'Sammelt sichtbare Eingabefelder mit Name, Label, Placeholder und Selector-Kandidaten.',
            ],
            'input.fill_field' => [
                'label' => 'Input-Feld fuellen',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/input/fill_field.cjs',
                'timeout_seconds' => 60,
                'description' => 'Fuellt ein konkretes oder heuristisch gefundenes Eingabefeld mit variablem Wert.',
            ],
            'input.submit' => [
                'label' => 'Formular absenden',
                'kind' => 'input',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/input/submit.cjs',
                'timeout_seconds' => 60,
                'description' => 'Klickt auf einen passenden Submit-Button oder sendet das naechste Formular ab.',
            ],
            'wait.selector' => [
                'label' => 'Auf Element warten',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/wait/selector.cjs',
                'timeout_seconds' => 90,
                'description' => 'Wartet auf ein sichtbares Element und liefert je nach Treffer einen Status.',
            ],
            'wait.status' => [
                'label' => 'Status auswerten',
                'kind' => 'wait',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/wait/status.cjs',
                'timeout_seconds' => 30,
                'description' => 'Prueft DOM/Text/URL gegen Statusregeln und gibt success, partial, failed oder timeout zurueck.',
            ],
            'data.read_account_data' => [
                'label' => 'Accountdaten lesen',
                'kind' => 'data',
                'runner' => 'php',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\ReadAccountDataTask@handle',
                'timeout_seconds' => 15,
                'description' => 'Extrahiert Accountdaten aus Workflow-, Persona- oder Node-Ergebnissen.',
            ],
            'data.read_login_data' => [
                'label' => 'Login-Daten lesen',
                'kind' => 'data',
                'runner' => 'php',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\ReadLoginDataTask@handle',
                'timeout_seconds' => 15,
                'description' => 'Bereitet Provider, E-Mail, Benutzername, Passwort und Webmail-URL fuer Login-Tasks vor.',
            ],
            'data.persist_mail_account' => [
                'label' => 'Mail-Account speichern',
                'kind' => 'data',
                'runner' => 'php',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistMailAccountTask@handle',
                'timeout_seconds' => 30,
                'description' => 'Speichert Provider, E-Mail, Benutzername, Passwort und Webmail-URL an der Persona.',
            ],
            'data.persist_webmail_session' => [
                'label' => 'Webmail-Session speichern',
                'kind' => 'data',
                'runner' => 'php',
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistWebmailSessionTask@handle',
                'timeout_seconds' => 30,
                'description' => 'Speichert verschluesselte Cookies/Storage aus einem Webmail-Session-Ergebnis.',
            ],
            'browser.close' => [
                'label' => 'Browser beenden',
                'kind' => 'browser',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/close.cjs',
                'timeout_seconds' => 30,
                'description' => 'Schliesst Seite, Kontext oder Browser, wenn der Runner einen Handle uebergibt.',
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

        foreach (['node_script', 'php_handler', 'selector', 'input', 'next', 'on_partial', 'on_error', 'status_routes'] as $key) {
            $value = Arr::get($overrides, $key, Arr::get($definition, $key));

            if ($value !== null && $value !== '') {
                $card[$key] = $value;
            }
        }

        return $card;
    }
}
