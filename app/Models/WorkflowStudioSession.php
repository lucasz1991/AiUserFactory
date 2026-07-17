<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStudioSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_uuid', 'workflow_id', 'user_id', 'person_id', 'active_workflow_run_id',
        'workflow_copilot_session_id', 'mode', 'permission_mode', 'status', 'goal',
        'success_criteria_json', 'workflow_inputs_json', 'budget_json', 'usage_json',
        'state_json', 'current_revision', 'started_at', 'paused_at', 'finished_at', 'last_activity_at',
    ];

    protected $casts = [
        'success_criteria_json' => 'array', 'workflow_inputs_json' => 'array', 'budget_json' => 'array',
        'usage_json' => 'array', 'state_json' => 'array', 'current_revision' => 'integer',
        'started_at' => 'datetime', 'paused_at' => 'datetime', 'finished_at' => 'datetime', 'last_activity_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function activeRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'active_workflow_run_id');
    }

    public function copilotSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowStudioCheckpoint::class)->orderBy('sequence');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkflowStudioRevision::class)->orderBy('revision_number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowStudioEvent::class)->orderBy('sequence');
    }
}
