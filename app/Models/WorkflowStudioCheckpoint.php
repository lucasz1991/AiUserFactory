<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStudioCheckpoint extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workflow_studio_session_id', 'workflow_run_id', 'workflow_step_id', 'workflow_studio_revision_id', 'screenshot_artifact_id', 'sequence', 'name', 'phase', 'task_key', 'cursor_json', 'context_json', 'browser_state_json', 'dom_snapshot_json', 'encrypted_runtime_context', 'state_signature', 'side_effect_ledger_json', 'is_reproducible'];

    protected $casts = ['sequence' => 'integer', 'cursor_json' => 'array', 'context_json' => 'array', 'browser_state_json' => 'array', 'dom_snapshot_json' => 'array', 'side_effect_ledger_json' => 'array', 'is_reproducible' => 'boolean'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioRevision::class, 'workflow_studio_revision_id');
    }
}
