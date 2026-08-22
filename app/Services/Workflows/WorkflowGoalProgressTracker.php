<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Support\Str;

/**
 * Verwaltet Zielfortschritt pro fachlichem Seiten-/Task-Kontext.
 *
 * Der fruehere sitzungsweite Skalar verglich z. B. Login, Consent und Ergebnis-
 * seite miteinander. Ein niedrigerer Wert auf einer neuen Seite wurde dadurch
 * als Reparatur-Rueckschritt gewertet. Scopes halten nur vergleichbare
 * Messpunkte zusammen und bleiben ueber Reparatur-Runs derselben Seite stabil.
 */
class WorkflowGoalProgressTracker
{
    private const REGRESSION_TOLERANCE = 0.05;

    private const MAX_SCOPES = 50;

    /** @return array{state:array,usage:array,scope:string,current:?float,previous:?float,regressions:int,regressed:bool} */
    public function evaluate(
        array $state,
        array $usage,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        $scope = $this->scopeKey($step, $checkpoint, $observation);
        $current = $this->value($vision['goal_progress'] ?? null);
        $scopes = is_array($state['goal_progress_scopes'] ?? null) ? $state['goal_progress_scopes'] : [];
        $entry = is_array($scopes[$scope] ?? null) ? $scopes[$scope] : [];

        // Einmalige, kompatible Uebernahme fuer bereits laufende Sitzungen. Ab
        // Scope-Version 1 werden die alten globalen Felder nie mehr gelesen.
        $legacy = ! isset($state['goal_progress_scope_version']) && $scopes === [];
        $previous = $this->value($entry['value'] ?? ($legacy ? ($state['last_goal_progress'] ?? null) : null));
        $regressions = max(0, (int) ($entry['regressions'] ?? ($legacy ? ($state['progress_regressions'] ?? 0) : 0)));

        if ($current === null) {
            return compact('state', 'usage', 'scope', 'current', 'previous', 'regressions') + ['regressed' => false];
        }

        $regressed = $previous !== null && $current < ($previous - self::REGRESSION_TOLERANCE);

        if ($regressed) {
            $regressions++;
        }

        $scopes[$scope] = [
            'value' => $current,
            'regressions' => $regressions,
            'workflow_run_id' => (int) $run->id,
            'workflow_step_id' => (int) $step->id,
            'task_key' => trim((string) ($checkpoint['failure_task_key'] ?? $checkpoint['task_key'] ?? '')),
            'page' => $this->pageIdentity($observation),
            'updated_at' => now()->toIso8601String(),
        ];

        if (count($scopes) > self::MAX_SCOPES) {
            uasort($scopes, static fn (array $left, array $right): int => strcmp(
                (string) ($right['updated_at'] ?? ''),
                (string) ($left['updated_at'] ?? ''),
            ));
            $scopes = array_slice($scopes, 0, self::MAX_SCOPES, true);
        }

        unset($state['last_goal_progress'], $state['progress_regressions']);
        $state['goal_progress_scope_version'] = 1;
        $state['goal_progress_scopes'] = $scopes;
        $usage['progress_regressions_total'] = collect($scopes)->sum(
            fn (mixed $candidate): int => is_array($candidate) ? max(0, (int) ($candidate['regressions'] ?? 0)) : 0,
        );

        return compact('state', 'usage', 'scope', 'current', 'previous', 'regressions', 'regressed');
    }

    public function scopeKey(WorkflowStep $step, array $checkpoint, array $observation): string
    {
        $taskKey = trim((string) ($checkpoint['failure_task_key'] ?? $checkpoint['task_key'] ?? 'unknown')) ?: 'unknown';
        $page = $this->pageIdentity($observation);

        return 'step:'.(int) $step->id
            .'|task:'.Str::limit($taskKey, 80, '')
            .'|page:'.substr(hash('sha256', $page), 0, 16);
    }

    public function label(?float $progress): string
    {
        return $progress === null ? 'unbekannt' : ((int) round($progress * 100)).' %';
    }

    private function value(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private function pageIdentity(array $observation): string
    {
        $url = trim((string) data_get($observation, 'page.url', ''));
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('/\b(?:\d+|[0-9a-f]{8}-[0-9a-f-]{27,})\b/i', '{id}', $path) ?: $path;
        $path = '/'.trim($path, '/');
        $state = Str::lower(trim((string) (data_get($observation, 'page.state') ?? data_get($observation, 'dom.ui_state', ''))));

        return $host !== '' || $path !== '/'
            ? $host.$path
            : ($state !== '' ? 'state:'.$state : 'unknown-page');
    }
}
