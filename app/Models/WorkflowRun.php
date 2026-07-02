<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_uuid',
        'workflow_id',
        'current_workflow_step_id',
        'status',
        'requested_by',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'context_json',
        'result_json',
        'error_message',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'context_json' => 'array',
        'result_json' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_workflow_step_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class)->orderBy('id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(WorkflowRunArtifact::class)->orderBy('id');
    }
}
