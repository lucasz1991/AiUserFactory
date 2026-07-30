<?php

namespace App\Support\Push;

/**
 * Fachliche Kategorien, fuer die sich ein Benutzer einzeln an- und abmelden
 * kann. RailTime fuehrt hier Nachrichten/Chat/Anrufe; FollowFlow hat diese
 * Domaenen nicht und ersetzt sie durch die eigenen Ereignisarten.
 */
enum PushCategory: string
{
    case Workflows = 'workflows';
    case Copilot = 'copilot';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Workflows => 'Workflow-Laeufe',
            self::Copilot => 'Copilot-Pruefpausen',
            self::System => 'Systemmeldungen',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Workflows => 'Ein Workflow-Lauf ist fertig, gescheitert oder wartet auf eine Eingabe.',
            self::Copilot => 'Der Workflow-Copilot haelt an einem Checkpoint an und braucht eine Entscheidung.',
            self::System => 'Wartung, Fehler und andere Hinweise aus dem Betrieb.',
        };
    }

    /**
     * Zeitkritische Kategorien werden mit `urgency: high` zugestellt.
     */
    public function isTimeCritical(): bool
    {
        return $this === self::Copilot;
    }
}
