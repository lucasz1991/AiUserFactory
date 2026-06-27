<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'is_active',
        'is_locked',
        'trigger_type',
        'settings_json',
        'last_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'settings_json' => 'array',
        'last_run_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position')->orderBy('id');
    }

    public function enabledSteps(): HasMany
    {
        return $this->steps()->where('is_enabled', true);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->latest('id');
    }

    public function includedWorkflows(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workflow_dependencies',
            'parent_workflow_id',
            'child_workflow_id',
        )->withTimestamps();
    }

    public function includedByWorkflows(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workflow_dependencies',
            'child_workflow_id',
            'parent_workflow_id',
        )->withTimestamps();
    }

    public function getIsIncludedAttribute(): bool
    {
        return $this->relationLoaded('includedByWorkflows')
            ? $this->includedByWorkflows->isNotEmpty()
            : $this->includedByWorkflows()->exists();
    }

    public function getIsEditLockedAttribute(): bool
    {
        return (bool) $this->is_locked || $this->is_included;
    }

    public function getLockReasonAttribute(): string
    {
        if ($this->is_included) {
            $parents = $this->relationLoaded('includedByWorkflows')
                ? $this->includedByWorkflows->pluck('name')->filter()->join(', ')
                : $this->includedByWorkflows()->pluck('name')->filter()->join(', ');

            return $parents !== ''
                ? 'In anderen Workflows enthalten: '.$parents
                : 'In einem anderen Workflow enthalten.';
        }

        return $this->is_locked ? 'Manuell gesperrt.' : '';
    }

    public function syncIncludedWorkflowReferences(): void
    {
        $workflowIds = $this->steps()
            ->get()
            ->flatMap(function (WorkflowStep $step): array {
                $config = is_array($step->config_json) ? $step->config_json : [];

                return is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
            })
            ->filter(fn (mixed $task): bool => is_array($task) && (string) ($task['runner'] ?? '') === 'workflow')
            ->map(fn (array $task): int => (int) ($task['workflow_id'] ?? 0))
            ->filter(fn (int $workflowId): bool => $workflowId > 0 && $workflowId !== (int) $this->id)
            ->unique()
            ->values();

        $existingIds = self::query()->whereKey($workflowIds->all())->pluck('id');

        $this->includedWorkflows()->sync($existingIds);
    }

    public function includesWorkflow(int $workflowId): bool
    {
        $pending = $this->includedWorkflows()->pluck('workflows.id')->map(fn ($id): int => (int) $id)->all();
        $visited = [];

        while ($pending !== []) {
            $candidateId = (int) array_shift($pending);

            if ($candidateId === $workflowId) {
                return true;
            }

            if (isset($visited[$candidateId])) {
                continue;
            }

            $visited[$candidateId] = true;
            $candidate = self::query()->find($candidateId);
            $includedIds = $candidate
                ? $candidate->includedWorkflows()->pluck('workflows.id')->all()
                : [];
            $pending = [...$pending, ...$includedIds];
        }

        return false;
    }
}
