<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowOptimizationPlanItem extends Model
{
    protected $fillable = [
        'workflow_optimization_plan_id', 'sequence', 'step_index', 'task_index', 'step_action_key',
        'task_key', 'catalog_task_key', 'status', 'blueprint_json', 'candidate_revision', 'attempts',
        'materialized_at', 'verified_at',
    ];

    protected $casts = [
        'sequence' => 'integer', 'step_index' => 'integer', 'task_index' => 'integer',
        'blueprint_json' => 'array', 'candidate_revision' => 'integer', 'attempts' => 'integer',
        'materialized_at' => 'datetime', 'verified_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkflowOptimizationPlan::class, 'workflow_optimization_plan_id');
    }
}
