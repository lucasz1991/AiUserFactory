<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowOptimizationPlan extends Model
{
    protected $fillable = [
        'workflow_id', 'workflow_copilot_session_id', 'workflow_studio_session_id', 'status',
        'goal_hash', 'plan_json', 'total_items', 'verified_items', 'finalized_revision', 'finalized_at',
    ];

    protected $casts = [
        'plan_json' => 'array', 'total_items' => 'integer', 'verified_items' => 'integer',
        'finalized_revision' => 'integer', 'finalized_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function copilotSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }

    public function studioSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkflowOptimizationPlanItem::class)->orderBy('sequence');
    }
}
