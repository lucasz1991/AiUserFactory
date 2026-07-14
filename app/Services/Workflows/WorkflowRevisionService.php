<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowRevisionConflictException;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowStep;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkflowRevisionService
{
    private const WORKFLOW_DEFINITION_FIELDS = [
        'name',
        'slug',
        'description',
        'category',
        'subcategory',
        'is_active',
        'is_locked',
        'trigger_type',
        'settings_json',
    ];

    private const STEP_DEFINITION_FIELDS = [
        'name',
        'type',
        'action_key',
        'position',
        'is_enabled',
        'config_json',
        'retry_attempts',
        'wait_after_seconds',
    ];

    public function __construct(private readonly WorkflowCopilotSessionService $sessions) {}

    public function snapshot(Workflow $workflow): array
    {
        $workflow->loadMissing('steps');

        return [
            'workflow' => [
                'id' => (int) $workflow->getKey(),
                ...collect(self::WORKFLOW_DEFINITION_FIELDS)
                    ->mapWithKeys(fn (string $field): array => [$field => $workflow->getAttribute($field)])
                    ->all(),
            ],
            'steps' => $workflow->steps
                ->sortBy([['position', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (WorkflowStep $step): array => [
                    'id' => (int) $step->getKey(),
                    ...collect(self::STEP_DEFINITION_FIELDS)
                        ->mapWithKeys(fn (string $field): array => [$field => $step->getAttribute($field)])
                        ->all(),
                ])
                ->all(),
        ];
    }

    public function apply(
        WorkflowCopilotSession $session,
        int $expectedRevision,
        string $reason,
        Closure $mutation,
        string $actor = 'copilot',
    ): WorkflowRevision {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Eine Workflow-Aenderung benoetigt eine Begruendung.');
        }

        return DB::transaction(function () use ($session, $expectedRevision, $reason, $mutation, $actor): WorkflowRevision {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertOwnedSystemSession($workflow, $lockedSession);

            if ($lockedSession->status === WorkflowCopilotSession::STATUS_VERIFYING) {
                throw new DomainException('Waehrend des eingefrorenen Kontrolllaufs duerfen keine Workflow-Revisionen gespeichert werden.');
            }

            $actualRevision = (int) ($workflow->copilot_revision ?? 0);

            if ($actualRevision !== $expectedRevision) {
                throw new WorkflowRevisionConflictException($expectedRevision, $actualRevision);
            }

            $before = $this->snapshot($workflow->fresh(['steps']) ?? $workflow);
            $mutation($workflow);
            $afterWorkflow = Workflow::query()->with('steps')->findOrFail($workflow->getKey());

            if ((int) $afterWorkflow->active_workflow_copilot_session_id !== (int) $lockedSession->getKey()) {
                throw new DomainException('Die Aenderung hat den exklusiven Copilot-Lock des Workflows verletzt.');
            }

            $after = $this->snapshot($afterWorkflow);
            $diff = $this->diffSnapshots($before, $after);

            if ($diff === []) {
                throw new DomainException('Die angeforderte Workflow-Aenderung hat keine Definition veraendert.');
            }

            $revisionNumber = $actualRevision + 1;
            $revision = WorkflowRevision::query()->create([
                'workflow_copilot_session_id' => $lockedSession->getKey(),
                'workflow_id' => $afterWorkflow->getKey(),
                'revision_number' => $revisionNumber,
                'parent_revision_number' => $actualRevision > 0 ? $actualRevision : null,
                'actor' => trim($actor) ?: 'copilot',
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
                'status' => WorkflowCopilotSession::STATUS_REPAIRING,
                'phase' => 'repairing',
                'current_revision' => $revisionNumber,
                'last_activity_at' => now(),
            ])->save();
            $this->sessions->appendEvent(
                $lockedSession,
                'revision.saved',
                'Workflow-Aenderung wurde als Revision '.$revisionNumber.' gespeichert.',
                [
                    'workflow_revision_id' => (int) $revision->getKey(),
                    'revision_number' => $revisionNumber,
                    'parent_revision_number' => $actualRevision > 0 ? $actualRevision : null,
                    'reason' => $reason,
                    'actor' => $revision->actor,
                    'diff' => $diff,
                ],
                'repairing',
                'success',
                true,
            );

            return $revision->fresh() ?? $revision;
        });
    }

    public function restore(
        WorkflowCopilotSession $session,
        WorkflowRevision|int $targetRevision,
        int $expectedRevision,
        string $reason,
        string $actor = 'copilot',
    ): WorkflowRevision {
        $target = $targetRevision instanceof WorkflowRevision
            ? $targetRevision
            : WorkflowRevision::query()
                ->where('workflow_id', $session->workflow_id)
                ->where('revision_number', $targetRevision)
                ->firstOrFail();

        if ((int) $target->workflow_id !== (int) $session->workflow_id) {
            throw new DomainException('Die Zielrevision gehoert nicht zum Workflow der Copilot-Sitzung.');
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

    public function markVerified(
        WorkflowCopilotSession $session,
        int $expectedRevision,
        string $message = 'Workflow wurde durch einen vollstaendigen Kontrolllauf verifiziert.',
    ): WorkflowRevision {
        return DB::transaction(function () use ($session, $expectedRevision, $message): WorkflowRevision {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertOwnedSystemSession($workflow, $lockedSession);
            $actualRevision = (int) ($workflow->copilot_revision ?? 0);

            if ($actualRevision !== $expectedRevision) {
                throw new WorkflowRevisionConflictException($expectedRevision, $actualRevision);
            }

            $revision = WorkflowRevision::query()
                ->where('workflow_id', $workflow->getKey())
                ->where('revision_number', $actualRevision)
                ->lockForUpdate()
                ->first();

            if (! $revision) {
                $snapshot = $this->snapshot($workflow->fresh(['steps']) ?? $workflow);
                $revision = WorkflowRevision::query()->create([
                    'workflow_copilot_session_id' => $lockedSession->getKey(),
                    'workflow_id' => $workflow->getKey(),
                    'revision_number' => $actualRevision,
                    'parent_revision_number' => null,
                    'actor' => 'system',
                    'reason' => 'Unveraenderter Ausgangsstand wurde durch den Kontrolllauf verifiziert.',
                    'before_snapshot_json' => $snapshot,
                    'after_snapshot_json' => $snapshot,
                    'diff_json' => [],
                ]);
            }

            $now = now();
            $revision->forceFill(['is_verified' => true, 'verified_at' => $now])->save();
            $workflow->forceFill([
                'active_workflow_copilot_session_id' => null,
                'copilot_locked_at' => null,
                'copilot_verification_status' => 'verified',
                'copilot_verified_at' => $now,
            ])->save();
            $lockedSession->forceFill([
                'status' => WorkflowCopilotSession::STATUS_SUCCEEDED,
                'phase' => 'completed',
                'finished_at' => $now,
                'last_activity_at' => $now,
            ])->save();
            $this->sessions->appendEvent(
                $lockedSession,
                'revision.verified',
                $message,
                ['workflow_revision_id' => (int) $revision->getKey(), 'revision_number' => $actualRevision],
                'completed',
                'success',
                true,
            );

            return $revision->fresh() ?? $revision;
        });
    }

    public function diffSnapshots(array $before, array $after): array
    {
        return $this->diffValue($before, $after, '');
    }

    private function restoreSnapshot(Workflow $workflow, array $snapshot): void
    {
        $workflowData = is_array($snapshot['workflow'] ?? null) ? $snapshot['workflow'] : [];
        $workflow->forceFill(collect(self::WORKFLOW_DEFINITION_FIELDS)
            ->filter(fn (string $field): bool => array_key_exists($field, $workflowData))
            ->mapWithKeys(fn (string $field): array => [$field => $workflowData[$field]])
            ->all())->save();

        $steps = collect(is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [])
            ->filter(fn (mixed $step): bool => is_array($step));
        $stepIds = $steps->pluck('id')->map(fn (mixed $id): int => (int) $id)->filter()->all();
        $obsolete = $workflow->steps()->when(
            $stepIds !== [],
            fn ($query) => $query->whereNotIn('id', $stepIds),
        );

        if ($stepIds === []) {
            $obsolete = $workflow->steps();
        }

        $obsolete->get()->each->delete();

        foreach ($steps as $stepData) {
            $attributes = collect(self::STEP_DEFINITION_FIELDS)
                ->filter(fn (string $field): bool => array_key_exists($field, $stepData))
                ->mapWithKeys(fn (string $field): array => [$field => $stepData[$field]])
                ->all();
            $stepId = (int) ($stepData['id'] ?? 0);
            $step = $stepId > 0
                ? WorkflowStep::query()->where('workflow_id', $workflow->getKey())->find($stepId)
                : null;

            if (! $step) {
                $step = new WorkflowStep;

                if ($stepId > 0) {
                    $step->setAttribute('id', $stepId);
                }

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
            $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));

            foreach ($keys as $key) {
                $childPath = $path.'/'.$this->escapeJsonPointer((string) $key);
                $hasBefore = array_key_exists($key, $before);
                $hasAfter = array_key_exists($key, $after);

                if (! $hasBefore) {
                    $operations[] = ['op' => 'add', 'path' => $childPath, 'value' => $after[$key]];
                } elseif (! $hasAfter) {
                    $operations[] = ['op' => 'remove', 'path' => $childPath, 'old_value' => $before[$key]];
                } else {
                    array_push($operations, ...$this->diffValue($before[$key], $after[$key], $childPath));
                }
            }

            return $operations;
        }

        return [[
            'op' => 'replace',
            'path' => $path !== '' ? $path : '/',
            'old_value' => $before,
            'value' => $after,
        ]];
    }

    private function escapeJsonPointer(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }

    private function assertOwnedSystemSession(Workflow $workflow, WorkflowCopilotSession $session): void
    {
        if ($session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
            throw new DomainException('Workflow-Copilot-Revisionen duerfen ausschliesslich im System-Ziel gespeichert werden.');
        }

        if (! $session->isActive()) {
            throw new DomainException('Nur eine aktive Copilot-Sitzung darf Workflow-Revisionen speichern.');
        }

        if ((int) $workflow->active_workflow_copilot_session_id !== (int) $session->getKey()) {
            throw new DomainException('Die Copilot-Sitzung besitzt nicht den exklusiven Workflow-Lock.');
        }
    }
}
