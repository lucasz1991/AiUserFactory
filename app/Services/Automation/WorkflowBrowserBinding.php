<?php

namespace App\Services\Automation;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskCatalog;

/**
 * Erkennt, ob ein Workflow den Browser der Bezugsperson beansprucht.
 *
 * Hintergrund: Die Nebenlaeufigkeit je Person ist frei konfigurierbar
 * (`persons.max_concurrent_workflow_runs`). Browserprofil, Cookie-Datei und
 * gespeicherte Session gehoeren aber jeder Person nur einmal. Zwei gleichzeitige
 * browsergebundene Laeufe wuerden sich die Session gegenseitig ueberschreiben.
 * Deshalb gilt fuer browsergebundene Workflows immer Exklusivitaet, unabhaengig
 * vom eingestellten Limit.
 *
 * Als browsergebunden gilt eine Task-Karte, wenn
 *  - ihr Katalogeintrag `kind` `browser` oder `input` traegt (der Katalog fuehrt
 *    24 `browser`-, 8 `input`-, 18 `data`- und 5 `wait`-Aufgaben), oder
 *  - sie ein Browserfenster deklariert.
 *
 * Eingebettete Workflows (`runner = workflow`, Schluessel `workflow.include.<id>`)
 * zaehlen rekursiv mit; Zyklen werden ueber die Besuchsliste abgefangen.
 */
class WorkflowBrowserBinding
{
    protected const BROWSER_KINDS = ['browser', 'input'];

    /** @var array<int, bool> Ergebnis je Workflow-Id fuer die Dauer der Anfrage. */
    protected array $cache = [];

    public function __construct(protected WorkflowTaskCatalog $catalog) {}

    public function requiresBrowser(Workflow $workflow): bool
    {
        return $this->resolve((int) $workflow->getKey(), []);
    }

    public function requiresBrowserById(int $workflowId): bool
    {
        return $this->resolve($workflowId, []);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @param  array<int, bool>  $visited
     */
    protected function resolve(int $workflowId, array $visited): bool
    {
        if ($workflowId <= 0 || isset($visited[$workflowId])) {
            return false;
        }

        if (array_key_exists($workflowId, $this->cache)) {
            return $this->cache[$workflowId];
        }

        $visited[$workflowId] = true;

        $workflow = Workflow::query()->with('enabledSteps')->find($workflowId);

        if (! $workflow) {
            return $this->cache[$workflowId] = false;
        }

        $definitions = $this->catalog->all();
        $requiresBrowser = false;
        $embeddedIds = [];

        foreach ($workflow->enabledSteps as $step) {
            foreach ($this->tasksOf($step) as $task) {
                if (! is_array($task)) {
                    continue;
                }

                if (($task['is_enabled'] ?? true) === false) {
                    continue;
                }

                $embeddedId = $this->embeddedWorkflowId($task);

                if ($embeddedId > 0) {
                    $embeddedIds[] = $embeddedId;

                    continue;
                }

                if ($this->taskRequiresBrowser($task, $definitions)) {
                    $requiresBrowser = true;
                    break 2;
                }
            }
        }

        if (! $requiresBrowser) {
            foreach (array_unique($embeddedIds) as $embeddedId) {
                if ($this->resolve($embeddedId, $visited)) {
                    $requiresBrowser = true;
                    break;
                }
            }
        }

        return $this->cache[$workflowId] = $requiresBrowser;
    }

    /**
     * @return array<int, mixed>
     */
    protected function tasksOf(WorkflowStep $step): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];

        return is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, array<string, mixed>>  $definitions
     */
    protected function taskRequiresBrowser(array $task, array $definitions): bool
    {
        if (trim((string) ($task['browser_window'] ?? $task['browserWindow'] ?? '')) !== '') {
            return true;
        }

        $taskKey = trim((string) ($task['task_key'] ?? $task['taskKey'] ?? ''));
        $kind = trim((string) ($definitions[$taskKey]['kind'] ?? $task['kind'] ?? ''));

        if (in_array($kind, self::BROWSER_KINDS, true)) {
            return true;
        }

        // Karten ohne Katalogeintrag (Altbestand) werden ueber ihren Schluessel
        // eingeordnet, damit ein unbekannter Browser-Task nicht als harmlos gilt.
        return $taskKey !== ''
            && (str_starts_with($taskKey, 'browser.') || str_starts_with($taskKey, 'input.'));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    protected function embeddedWorkflowId(array $task): int
    {
        if ((string) ($task['runner'] ?? '') === 'workflow') {
            return (int) ($task['workflow_id'] ?? $task['workflowId'] ?? 0);
        }

        $taskKey = trim((string) ($task['task_key'] ?? $task['taskKey'] ?? ''));

        if (str_starts_with($taskKey, 'workflow.include.')) {
            return (int) substr($taskKey, strlen('workflow.include.'));
        }

        return 0;
    }
}
