<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunCheckpoint extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workflow_copilot_session_id',
        'workflow_run_id',
        'workflow_step_id',
        'workflow_task_attempt_id',
        'workflow_revision_id',
        'screenshot_artifact_id',
        'sequence',
        'phase',
        'task_key',
        'cursor_json',
        'context_json',
        'browser_state_json',
        'dom_snapshot_json',
        'state_signature',
        'side_effect_ledger_json',
        'is_reproducible',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'cursor_json' => 'array',
        'context_json' => 'array',
        'browser_state_json' => 'array',
        'dom_snapshot_json' => 'array',
        'side_effect_ledger_json' => 'array',
        'is_reproducible' => 'boolean',
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

    public function taskAttempt(): BelongsTo
    {
        return $this->belongsTo(WorkflowTaskAttempt::class, 'workflow_task_attempt_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(WorkflowRevision::class, 'workflow_revision_id');
    }

    public function screenshotArtifact(): BelongsTo
    {
        return $this->belongsTo(WorkflowRunArtifact::class, 'screenshot_artifact_id');
    }
}
