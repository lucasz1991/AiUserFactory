<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowCopilotSession extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_REPAIRING = 'repairing';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STOPPED = 'stopped';

    public const EXECUTION_TARGET_SYSTEM = 'system';

    public const ACTIVE_STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_REPAIRING,
        self::STATUS_VERIFYING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_BUDGET_EXHAUSTED,
        self::STATUS_FAILED,
        self::STATUS_STOPPED,
    ];

    public const LOCK_RETAINING_STATUSES = [
        ...self::ACTIVE_STATUSES,
        self::STATUS_BUDGET_EXHAUSTED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'session_uuid',
        'workflow_id',
        'person_id',
        'active_workflow_run_id',
        'status',
        'phase',
        'execution_target',
        'goal',
        'success_criteria_json',
        'workflow_inputs_json',
        'budget_json',
        'usage_json',
        'state_json',
        'current_revision',
        'repair_round',
        'last_event_sequence',
        'started_at',
        'paused_at',
        'finished_at',
        'last_activity_at',
    ];

    protected $casts = [
        'success_criteria_json' => 'array',
        'workflow_inputs_json' => 'array',
        'budget_json' => 'array',
        'usage_json' => 'array',
        'state_json' => 'array',
        'current_revision' => 'integer',
        'repair_round' => 'integer',
        'last_event_sequence' => 'integer',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->execution_target = $session->execution_target ?: self::EXECUTION_TARGET_SYSTEM;
            $session->assertSystemExecutionTarget();
        });

        static::updating(function (self $session): void {
            if ($session->isDirty('execution_target')) {
                $session->assertSystemExecutionTarget();
            }
        });
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function activeRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'active_workflow_run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowCopilotEvent::class)->orderBy('sequence');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkflowRevision::class)->orderBy('revision_number');
    }

    public function taskAttempts(): HasMany
    {
        return $this->hasMany(WorkflowTaskAttempt::class)->orderBy('attempt_number');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowRunCheckpoint::class)->orderBy('sequence');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function retainsWorkflowLock(): bool
    {
        return in_array($this->status, self::LOCK_RETAINING_STATUSES, true);
    }

    protected function assertSystemExecutionTarget(): void
    {
        if ($this->execution_target !== self::EXECUTION_TARGET_SYSTEM) {
            throw new DomainException('Workflow-Copilot-Sitzungen duerfen ausschliesslich auf execution_target=system laufen.');
        }
    }
}
