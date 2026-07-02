<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunArtifact extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'workflow_run_id',
        'workflow_step_id',
        'workflow_step_run_id',
        'step_position',
        'step_action_key',
        'task_card_key',
        'phase',
        'artifact_type',
        'browser_window',
        'current_url',
        'title',
        'storage_disk',
        'storage_path',
        'status',
        'error_message',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'step_position' => 'integer',
    ];

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
}
