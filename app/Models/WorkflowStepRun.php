<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStepRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_run_id',
        'workflow_step_id',
        'status',
        'external_run_type',
        'external_run_id',
        'started_at',
        'finished_at',
        'duration_ms',
        'logs_json',
        'result_json',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'logs_json' => 'array',
        'result_json' => 'array',
    ];

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }
}
