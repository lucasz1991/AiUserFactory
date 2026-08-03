<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowAssistanceRequest extends Model
{
    use HasFactory;

    public const TYPE_CAPTCHA = 'captcha';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_CLAIMED];

    protected $fillable = [
        'request_uuid', 'source_key', 'workflow_id', 'workflow_run_id', 'open_workflow_run_id',
        'workflow_step_id', 'workflow_step_run_id', 'workflow_studio_session_id',
        'requested_by_user_id', 'assigned_to_user_id', 'resolved_by_user_id',
        'type', 'status', 'priority', 'reason_code', 'task_key', 'resume_task_key',
        'browser_window', 'current_url', 'title', 'instructions', 'cursor_json',
        'browser_state_json', 'metadata_json', 'resolution_note', 'requested_at',
        'claimed_at', 'resolved_at', 'cancelled_at', 'expires_at', 'resume_dispatched_at',
    ];

    protected $casts = [
        'cursor_json' => 'array',
        'browser_state_json' => 'array',
        'metadata_json' => 'array',
        'requested_at' => 'datetime',
        'claimed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
        'resume_dispatched_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'request_uuid';
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array((string) $this->status, self::OPEN_STATUSES, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function workflowStepRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowStepRun::class);
    }

    public function studioSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowAssistanceEvent::class)->orderBy('sequence');
    }
}
