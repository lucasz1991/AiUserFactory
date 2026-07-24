<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;

/**
 * Einzige Quelle der Wahrheit fuer die Observability-Stufe eines Laufs.
 *
 * Grundprinzip (Fachseite, 2026-07-24): Die schwere Datensammlung — Screenshots,
 * DOM-Baum, Cursor-Overlay, Debug-Artefakte — dient ausschliesslich dem Erstellen,
 * Testen und Optimieren eines Workflows. Im **echten Ablauf** darf nichts davon
 * anfallen; dort zaehlt nur das fachliche Ergebnis (`workflow_return`).
 *
 * Vor diesem Service war das Gating an das Workflow-Setting `dev_mode` und eine
 * globale Live-Vorschau-Einstellung gebunden — also am Workflow, nicht am
 * einzelnen Lauf. Ein Workflow mit `dev_mode=true` sammelte darum auch bei einem
 * echten Trigger-Lauf schwere Daten. Diese Policy leitet die Stufe stattdessen
 * aus dem konkreten Lauf ab, sodass ein Produktionslauf strukturell keine
 * Beobachtungsdaten mehr erzeugen kann.
 *
 * Siehe README-Abschnitt „Feature R6".
 */
class WorkflowObservabilityPolicy
{
    /** Voll: Copilot beobachtet, plant, repariert. */
    public const LEVEL_COPILOT = 'copilot';

    /** Voll: Studio-Testlauf mit eingeschaltetem Development. */
    public const LEVEL_DEBUG = 'debug';

    /** Beobachtbar: Studio-Testlauf ohne Development (Screenshots/Cursor, kein DOM/Artefakt). */
    public const LEVEL_PREVIEW = 'preview';

    /** Echt: keine Beobachtungsdaten, nur das Ergebnis. */
    public const LEVEL_OFF = 'off';

    /** Rangfolge fuer Vergleiche wie „mindestens preview". */
    private const RANK = [
        self::LEVEL_OFF => 0,
        self::LEVEL_PREVIEW => 1,
        self::LEVEL_DEBUG => 2,
        self::LEVEL_COPILOT => 3,
    ];

    public function level(WorkflowRun $run): string
    {
        // Ein ausdruecklich als echt markierter Lauf (Studio-Aktion „Echter
        // Ablauf") ist immer off, unabhaengig von allem anderen.
        if ($this->isRealPlayback($run)) {
            return self::LEVEL_OFF;
        }

        if ($this->hasCopilotSession($run)) {
            return self::LEVEL_COPILOT;
        }

        if (! $this->isTestRun($run)) {
            return self::LEVEL_OFF;
        }

        return $this->developmentEnabled($run) ? self::LEVEL_DEBUG : self::LEVEL_PREVIEW;
    }

    public function capturesScreenshots(WorkflowRun $run): bool
    {
        return $this->atLeast($run, self::LEVEL_PREVIEW);
    }

    public function showsCursor(WorkflowRun $run): bool
    {
        return $this->atLeast($run, self::LEVEL_PREVIEW);
    }

    public function capturesDom(WorkflowRun $run): bool
    {
        return $this->atLeast($run, self::LEVEL_DEBUG);
    }

    public function keepsArtifacts(WorkflowRun $run): bool
    {
        return $this->atLeast($run, self::LEVEL_DEBUG);
    }

    /**
     * Im echten Ablauf zeigt die Oberflaeche nur die Ausgabe (`workflow_return`)
     * statt Screenshots, Inspektor und Cursor.
     */
    public function resultOnly(WorkflowRun $run): bool
    {
        return $this->level($run) === self::LEVEL_OFF;
    }

    /**
     * Stufe >= der angegebenen. `atLeast($run, 'preview')` ist die Bedingung, an
     * der R4/R5 ihre Daten emittieren duerfen.
     */
    public function atLeast(WorkflowRun $run, string $level): bool
    {
        $target = self::RANK[$level] ?? 0;

        return (self::RANK[$this->level($run)] ?? 0) >= $target;
    }

    private function isRealPlayback(WorkflowRun $run): bool
    {
        return (bool) data_get($run->context_json, 'real_playback', false);
    }

    private function isTestRun(WorkflowRun $run): bool
    {
        $context = is_array($run->context_json) ? $run->context_json : [];

        return $this->hasCopilotSession($run)
            || (bool) data_get($context, 'interactive_debug', false)
            || (int) data_get($context, 'workflow_studio_session_id', 0) > 0
            || (int) data_get($context, 'workflow_copilot_session_id', 0) > 0;
    }

    private function hasCopilotSession(WorkflowRun $run): bool
    {
        return (int) $run->workflow_copilot_session_id > 0
            || (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) > 0;
    }

    private function developmentEnabled(WorkflowRun $run): bool
    {
        $settings = is_array($run->workflow?->settings_json) ? $run->workflow->settings_json : [];

        return filter_var($settings['dev_mode'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($settings['development'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
