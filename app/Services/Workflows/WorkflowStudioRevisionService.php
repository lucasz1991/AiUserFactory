<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowRevisionConflictException;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioRevision;
use App\Models\WorkflowStudioSession;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkflowStudioRevisionService
{
    private const WORKFLOW_FIELDS = [
        'name', 'slug', 'description', 'category', 'subcategory', 'is_active', 'is_locked',
        'trigger_type', 'settings_json',
    ];

    private const STEP_FIELDS = [
        'name', 'type', 'action_key', 'position', 'is_enabled', 'config_json',
        'retry_attempts', 'wait_after_seconds',
    ];

    public function snapshot(Workflow $workflow): array
    {
        $workflow->loadMissing('steps');

        return [
            'workflow' => [
                'id' => (int) $workflow->getKey(),
                ...collect(self::WORKFLOW_FIELDS)
                    ->mapWithKeys(fn (string $field): array => [$field => $workflow->getAttribute($field)])
                    ->all(),
            ],
            'steps' => $workflow->steps
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (WorkflowStep $step): array => [
                    'id' => (int) $step->getKey(),
                    ...collect(self::STEP_FIELDS)
                        ->mapWithKeys(fn (string $field): array => [$field => $step->getAttribute($field)])
                        ->all(),
                ])->all(),
        ];
    }

    public function ensureBaseline(WorkflowStudioSession $session, string $reason = 'Ausgangsstand des Workflow Studios'): WorkflowStudioRevision
    {
        $workflow = $session->workflow()->with('steps')->firstOrFail();
        $revisionNumber = (int) ($workflow->copilot_revision ?? 0);
        $existing = WorkflowStudioRevision::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('revision_number', $revisionNumber)
            ->first();

        if ($existing) {
            return $existing;
        }

        $snapshot = $this->snapshot($workflow);

        return WorkflowStudioRevision::query()->create([
            'workflow_studio_session_id' => $session->getKey(),
            'workflow_id' => $workflow->getKey(),
            'revision_number' => $revisionNumber,
            'parent_revision_number' => null,
            'actor' => 'system',
            'reason' => $reason,
            'before_snapshot_json' => $snapshot,
            'after_snapshot_json' => $snapshot,
            'diff_json' => [],
            'is_verified' => (string) $workflow->copilot_verification_status === 'verified',
            'verified_at' => $workflow->copilot_verified_at,
        ]);
    }

    public function apply(
        WorkflowStudioSession $session,
        int $expectedRevision,
        string $reason,
        Closure $mutation,
        string $actor = 'user',
    ): WorkflowStudioRevision {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Eine Workflow-Aenderung benoetigt eine Begruendung.');
        }

        return DB::transaction(function () use ($session, $expectedRevision, $reason, $mutation, $actor): WorkflowStudioRevision {
            $lockedSession = WorkflowStudioSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($lockedSession->workflow_id);
            $actualRevision = (int) ($workflow->copilot_revision ?? 0);

            if ($actualRevision !== $expectedRevision) {
                throw new WorkflowRevisionConflictException($expectedRevision, $actualRevision);
            }

            $before = $this->snapshot($workflow->fresh(['steps']) ?? $workflow);
            $mutation($workflow);
            $afterWorkflow = Workflow::query()->with('steps')->findOrFail($workflow->getKey());
            $after = $this->snapshot($afterWorkflow);
            $diff = $this->diffSnapshots($before, $after);

            if ($diff === []) {
                throw new DomainException('Die angeforderte Workflow-Aenderung hat keine Definition veraendert.');
            }

            $revisionNumber = $actualRevision + 1;
            $revision = WorkflowStudioRevision::query()->create([
                'workflow_studio_session_id' => $lockedSession->getKey(),
                'workflow_id' => $workflow->getKey(),
                'revision_number' => $revisionNumber,
                'parent_revision_number' => $actualRevision,
                'actor' => trim($actor) ?: 'user',
                'reason' => $reason,
                'before_snapshot_json' => $before,
                'after_snapshot_json' => $after,
                'diff_json' => $diff,
                'is_verified' => false,
            ]);

            $afterWorkflow->forceFill([
                'copilot_revision' => $revisionNumber,
                'copilot_verification_status' => 'unverified',
                'copilot_verified_at' => null,
            ])->save();
            $lockedSession->forceFill([
                'current_revision' => $revisionNumber,
                'status' => 'draft',
                'last_activity_at' => now(),
            ])->save();

            app(WorkflowStudioSessionService::class)->appendEvent(
                $lockedSession,
                'revision.saved',
                'Workflow-Aenderung wurde als Revision '.$revisionNumber.' gespeichert.',
                ['revision_number' => $revisionNumber, 'reason' => $reason, 'actor' => $revision->actor, 'diff' => $diff],
                'success',
            );

            return $revision->fresh() ?? $revision;
        });
    }

    public function recordCurrentDefinition(
        WorkflowStudioSession $session,
        array $before,
        int $expectedRevision,
        string $reason,
        string $actor = 'copilot',
    ): WorkflowStudioRevision {
        return DB::transaction(function () use ($session, $before, $expectedRevision, $reason, $actor): WorkflowStudioRevision {
            $lockedSession = WorkflowStudioSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $workflow = Workflow::query()->with('steps')->lockForUpdate()->findOrFail($lockedSession->workflow_id);
            $actualRevision = (int) ($workflow->copilot_revision ?? 0);
            if ($actualRevision !== $expectedRevision) {
                throw new WorkflowRevisionConflictException($expectedRevision, $actualRevision);
            }
            $after = $this->snapshot($workflow);
            $diff = $this->diffSnapshots($before, $after);
            if ($diff === []) {
                throw new DomainException('Die externe Planung hat keine Workflow-Definition veraendert.');
            }
            $revisionNumber = $actualRevision + 1;
            $revision = WorkflowStudioRevision::query()->create([
                'workflow_studio_session_id' => $lockedSession->getKey(),
                'workflow_id' => $workflow->getKey(),
                'revision_number' => $revisionNumber,
                'parent_revision_number' => $actualRevision,
                'actor' => trim($actor) ?: 'copilot',
                'reason' => trim($reason),
                'before_snapshot_json' => $before,
                'after_snapshot_json' => $after,
                'diff_json' => $diff,
                'is_verified' => false,
            ]);
            $workflow->forceFill(['copilot_revision' => $revisionNumber, 'copilot_verification_status' => 'unverified', 'copilot_verified_at' => null])->save();
            $lockedSession->forceFill(['current_revision' => $revisionNumber, 'status' => 'draft_ready', 'last_activity_at' => now()])->save();
            app(WorkflowStudioSessionService::class)->appendEvent($lockedSession, 'revision.saved', 'Copilot-Entwurf wurde als Revision '.$revisionNumber.' gespeichert.', [
                'revision_number' => $revisionNumber, 'reason' => $reason, 'actor' => $actor, 'diff' => $diff,
            ], 'success');

            return $revision;
        });
    }

    public function restore(
        WorkflowStudioSession $session,
        WorkflowStudioRevision|int $targetRevision,
        int $expectedRevision,
        string $reason,
        string $actor = 'user',
    ): WorkflowStudioRevision {
        $target = $targetRevision instanceof WorkflowStudioRevision
            ? $targetRevision
            : WorkflowStudioRevision::query()
                ->where('workflow_id', $session->workflow_id)
                ->where('revision_number', $targetRevision)
                ->firstOrFail();

        if ((int) $target->workflow_id !== (int) $session->workflow_id) {
            throw new DomainException('Die Zielrevision gehoert nicht zu diesem Workflow.');
        }

        $snapshot = is_array($target->after_snapshot_json) ? $target->after_snapshot_json : [];

        return $this->apply(
            $session,
            $expectedRevision,
            $reason,
            fn (Workflow $workflow) => $this->restoreSnapshot($workflow, $snapshot),
            $actor,
        );
    }

    public function diffSnapshots(array $before, array $after): array
    {
        return $this->diffValue($before, $after, '');
    }

    private function restoreSnapshot(Workflow $workflow, array $snapshot): void
    {
        $workflowData = is_array($snapshot['workflow'] ?? null) ? $snapshot['workflow'] : [];
        $workflow->forceFill(collect(self::WORKFLOW_FIELDS)
            ->filter(fn (string $field): bool => array_key_exists($field, $workflowData))
            ->mapWithKeys(fn (string $field): array => [$field => $workflowData[$field]])
            ->all())->save();

        $steps = collect(is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [])
            ->filter(fn (mixed $step): bool => is_array($step));
        $stepIds = $steps->pluck('id')->map(fn (mixed $id): int => (int) $id)->filter()->all();
        $obsolete = $workflow->steps();
        if ($stepIds !== []) {
            $obsolete->whereNotIn('id', $stepIds);
        }
        $obsolete->get()->each->delete();

        foreach ($steps as $stepData) {
            $attributes = collect(self::STEP_FIELDS)
                ->filter(fn (string $field): bool => array_key_exists($field, $stepData))
                ->mapWithKeys(fn (string $field): array => [$field => $stepData[$field]])
                ->all();
            $stepId = (int) ($stepData['id'] ?? 0);
            $step = $stepId > 0
                ? WorkflowStep::query()->where('workflow_id', $workflow->getKey())->find($stepId)
                : null;

            $step ??= new WorkflowStep;
            if (! $step->exists) {
                $step->workflow_id = $workflow->getKey();
            }
            $step->forceFill($attributes)->save();
        }

        $workflow->syncIncludedWorkflowReferences();
    }

    private function diffValue(mixed $before, mixed $after, string $path): array
    {
        if ($before === $after) {
            return [];
        }

        if (is_array($before) && is_array($after)) {
            $operations = [];
            foreach (array_values(array_unique([...array_keys($before), ...array_keys($after)])) as $key) {
                $childPath = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if (! array_key_exists($key, $before)) {
                    $operations[] = ['op' => 'add', 'path' => $childPath, 'value' => $after[$key]];
                } elseif (! array_key_exists($key, $after)) {
                    $operations[] = ['op' => 'remove', 'path' => $childPath, 'old_value' => $before[$key]];
                } else {
                    array_push($operations, ...$this->diffValue($before[$key], $after[$key], $childPath));
                }
            }

            return $operations;
        }

        return [['op' => 'replace', 'path' => $path ?: '/', 'old_value' => $before, 'value' => $after]];
    }
}
