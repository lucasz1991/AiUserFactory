<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTaskAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_copilot_session_id',
        'workflow_run_id',
        'workflow_step_id',
        'workflow_revision_id',
        'attempt_number',
        'kind',
        'status',
        'task_key',
        'task_title',
        'task_definition_json',
        'input_json',
        'result_json',
        'error_message',
        'side_effects_json',
        'artifacts_json',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'task_definition_json' => 'array',
        'input_json' => 'array',
        'result_json' => 'array',
        'side_effects_json' => 'array',
        'artifacts_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(WorkflowRevision::class, 'workflow_revision_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowRunCheckpoint::class);
    }
}
