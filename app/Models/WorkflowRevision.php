<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRevision extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workflow_copilot_session_id',
        'workflow_id',
        'revision_number',
        'parent_revision_number',
        'actor',
        'reason',
        'before_snapshot_json',
        'after_snapshot_json',
        'diff_json',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'parent_revision_number' => 'integer',
        'before_snapshot_json' => 'array',
        'after_snapshot_json' => 'array',
        'diff_json' => 'array',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function taskAttempts(): HasMany
    {
        return $this->hasMany(WorkflowTaskAttempt::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowRunCheckpoint::class);
    }
}
