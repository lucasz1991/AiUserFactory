<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Zeitplan verbindet genau eine Person mit genau einem Workflow und einer
 * Zeitregel. Mehrere Zeitplaene je Paar sind erlaubt und werden ueber `label`
 * unterschieden.
 */
class PersonWorkflowSchedule extends Model
{
    public const CADENCE_INTERVAL = 'interval';

    public const CADENCE_DAILY_TIMES = 'daily_times';

    public const CADENCE_ACTIVITY_PLAN = 'activity_plan';

    public const CADENCES = [
        self::CADENCE_INTERVAL => 'Intervall',
        self::CADENCE_DAILY_TIMES => 'Feste Uhrzeiten',
        self::CADENCE_ACTIVITY_PLAN => 'Aktivitaetsplan der Person',
    ];

    protected $fillable = [
        'person_id',
        'workflow_id',
        'label',
        'is_active',
        'cadence_type',
        'interval_minutes',
        'daily_times',
        'activity_plan_session_types',
        'weekdays',
        'window_start',
        'window_end',
        'jitter_seconds',
        'max_runs_per_day',
        'min_gap_minutes',
        'priority',
        'context_json',
        'next_run_at',
        'last_run_at',
        'last_workflow_run_id',
        'runs_today',
        'runs_today_date',
        'consecutive_failures',
        'paused_until',
        'last_skip_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'interval_minutes' => 'integer',
        'daily_times' => 'array',
        'activity_plan_session_types' => 'array',
        'weekdays' => 'array',
        'jitter_seconds' => 'integer',
        'max_runs_per_day' => 'integer',
        'min_gap_minutes' => 'integer',
        'priority' => 'integer',
        'context_json' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'runs_today' => 'integer',
        'runs_today_date' => 'date',
        'consecutive_failures' => 'integer',
        'paused_until' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function lastWorkflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'last_workflow_run_id');
    }

    public function scopeDue(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('paused_until')->orWhere('paused_until', '<=', $now);
            });
    }

    public function getCadenceLabelAttribute(): string
    {
        return self::CADENCES[$this->cadence_type] ?? $this->cadence_type;
    }

    /**
     * Tageszaehler zuruecksetzen, sobald ein neuer Tag begonnen hat.
     * Der Vergleich laeuft in der Ortszeit der Person, damit der Deckel dort
     * kippt, wo die Persona lebt.
     */
    public function resetDailyCounterIfNeeded(string $timezone): void
    {
        $today = now($timezone)->toDateString();

        if ($this->runs_today_date?->toDateString() === $today) {
            return;
        }

        $this->runs_today = 0;
        $this->runs_today_date = $today;
    }
}
