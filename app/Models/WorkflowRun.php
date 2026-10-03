<?php

namespace App\Models;

use App\Exceptions\WorkflowRunConflictException;
use App\Services\Workflows\WorkflowRunContextStore;
use Illuminate\Database\Eloquent\Builder;
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
        'person_id',
        'workflow_copilot_session_id',
        'workflow_studio_session_id',
        'workflow_revision',
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
        'workflow_revision' => 'integer',
        'context_json' => 'array',
        'result_json' => 'array',
    ];

    protected function performUpdate(Builder $query)
    {
        // The lock covers only the merge/write, never browser or AI I/O. Keeping
        // this at the persistence boundary also protects existing context writers.
        return $this->getConnection()->transaction(function () use ($query): bool {
            $current = $this->newQueryWithoutScopes()->useWritePdo()->lockForUpdate()->find($this->getKey());
            if (! $current) {
                return false;
            }

            $store = app(WorkflowRunContextStore::class);
            if (! $store->equivalent($this->getOriginal('status'), $current->status)
                && in_array($current->status, ['paused', 'stop_requested', 'completed', 'failed', 'cancelled', 'timed_out'], true)
                && $this->isDirty(['context_json', 'current_workflow_step_id', 'workflow_revision', 'result_json'])
                && ! ($this->isDirty('status') && $this->status === $current->status)) {
                throw new WorkflowRunConflictException('status');
            }
            foreach (['status', 'current_workflow_step_id', 'workflow_revision', 'result_json', 'error_message', 'finished_at'] as $attribute) {
                if ($this->isDirty($attribute)
                    && ! $store->equivalent($this->getOriginal($attribute), $current->getAttribute($attribute))
                    && ! $store->equivalent($this->getAttribute($attribute), $current->getAttribute($attribute))) {
                    throw new WorkflowRunConflictException($attribute);
                }
            }

            if ($this->isDirty('context_json')) {
                $this->setAttribute('context_json', $store->merge(
                    $this->getOriginal('context_json') ?? [],
                    $this->context_json ?? [],
                    $current->context_json ?? [],
                ));
            }

            return parent::performUpdate($query);
        }, 3);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Indizierter Spiegel von `context_json['person_id']`, gesetzt beim Start in
     * `WorkflowExecutionService::start()`.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_workflow_step_id');
    }

    public function copilotSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowCopilotSession::class, 'workflow_copilot_session_id');
    }

    public function studioSession(): BelongsTo
    {
        return $this->belongsTo(WorkflowStudioSession::class, 'workflow_studio_session_id');
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(WorkflowStepRun::class)->orderBy('id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(WorkflowRunArtifact::class)->orderBy('id');
    }

    public function taskAttempts(): HasMany
    {
        return $this->hasMany(WorkflowTaskAttempt::class)->orderBy('attempt_number');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(WorkflowRunCheckpoint::class)->orderBy('sequence');
    }

    public function assistanceRequests(): HasMany
    {
        return $this->hasMany(WorkflowAssistanceRequest::class)->orderByDesc('requested_at');
    }
}
