<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStudioCheckpoint;
use App\Models\WorkflowStudioRevision;
use App\Models\WorkflowStudioSession;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class WorkflowStudioCheckpointService
{
    public function create(
        WorkflowStudioSession $session,
        ?WorkflowRun $run = null,
        ?string $name = null,
        string $phase = 'manual',
    ): WorkflowStudioCheckpoint {
        $run ??= $session->activeRun;
        if (! $run || (int) $run->workflow_id !== (int) $session->workflow_id) {
            throw new DomainException('Fuer diesen Checkpoint ist kein gueltiger Studio-Lauf vorhanden.');
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $stepRun = $run->stepRuns()->latest('id')->first();
        $artifact = $run->artifacts()
            ->where('artifact_type', 'screenshot')
            ->where('status', 'success')
            ->latest('id')
            ->first();
        $revision = WorkflowStudioRevision::query()
            ->where('workflow_id', $session->workflow_id)
            ->where('revision_number', (int) ($run->workflow_revision ?? $session->current_revision))
            ->first();
        $browserState = array_filter([
            'browser_session' => $context['browser_session'] ?? $context['browserSession'] ?? null,
            'browser_sessions' => $context['browser_sessions'] ?? $context['browserSessions'] ?? null,
            'browser_windows' => $context['browser_windows'] ?? $context['browserWindows'] ?? null,
            'browser_profile_key' => $context['browser_profile_key'] ?? $context['browserProfileKey'] ?? null,
            'current_url' => $artifact?->current_url,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        $cursor = [
            'workflow_step_id' => $run->current_workflow_step_id,
            'task_key' => $context['next_task_key'] ?? data_get($stepRun?->result_json, 'taskKey'),
            'step_run_id' => $stepRun?->getKey(),
            'run_status' => $run->status,
            'workflow_revision' => (int) ($run->workflow_revision ?? $session->current_revision ?? $session->workflow->copilot_revision),
        ];
        $safeContext = $this->safeContext($context);
        $domSnapshot = data_get($artifact?->metadata_json, 'dom_snapshot');
        $domSnapshot = is_array($domSnapshot) ? $this->safeContext($domSnapshot) : null;

        return DB::transaction(function () use ($session, $run, $name, $phase, $context, $artifact, $revision, $browserState, $cursor, $safeContext, $domSnapshot): WorkflowStudioCheckpoint {
            $locked = WorkflowStudioSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $sequence = ((int) $locked->checkpoints()->max('sequence')) + 1;
            $signature = hash('sha256', json_encode([
                'workflow_id' => $run->workflow_id,
                'revision' => $run->workflow_revision,
                'cursor' => $cursor,
                'context' => $safeContext,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $checkpoint = $locked->checkpoints()->create([
                'workflow_run_id' => $run->getKey(),
                'workflow_step_id' => $run->current_workflow_step_id,
                'workflow_studio_revision_id' => $revision?->getKey(),
                'screenshot_artifact_id' => $artifact?->getKey(),
                'sequence' => $sequence,
                'name' => trim((string) $name) ?: 'Checkpoint '.$sequence,
                'phase' => $phase,
                'task_key' => $cursor['task_key'],
                'cursor_json' => $cursor,
                'context_json' => $safeContext,
                'browser_state_json' => $browserState,
                'dom_snapshot_json' => $domSnapshot,
                'encrypted_runtime_context' => Crypt::encryptString(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'state_signature' => $signature,
                'side_effect_ledger_json' => $context['side_effect_ledger'] ?? [],
                'is_reproducible' => $this->browserStateIsReproducible($browserState),
            ]);

            app(WorkflowStudioSessionService::class)->appendEvent(
                $locked,
                'checkpoint.created',
                'Checkpoint „'.$checkpoint->name.'“ wurde gespeichert.',
                ['checkpoint_id' => (int) $checkpoint->getKey(), 'sequence' => $sequence, 'task_key' => $checkpoint->task_key],
                'success',
            );

            return $checkpoint;
        });
    }

    public function restore(
        WorkflowStudioSession $session,
        WorkflowStudioCheckpoint|int $checkpoint,
        ?WorkflowRun $run = null,
    ): WorkflowRun {
        $checkpoint = $this->resolve($session, $checkpoint);
        $run ??= $session->activeRun;

        if (! $run || (int) $run->workflow_id !== (int) $session->workflow_id) {
            throw new DomainException('Der Ziel-Lauf ist nicht mehr verfuegbar.');
        }

        if ($run->stepRuns()->where(function ($query): void {
            $query->where('status', 'running')
                ->orWhere(fn ($waiting) => $waiting->where('status', 'waiting')->whereNotNull('external_run_id'));
        })->exists()) {
            throw new DomainException('Ein laufender Task muss zuerst sicher pausiert werden.');
        }

        $this->assertCompatible($session, $checkpoint);
        $context = $this->decryptContext($checkpoint);
        $context['manual_pause_requested'] = true;
        $context['manual_pause_checkpoint'] = [
            'workflow_studio_checkpoint_id' => (int) $checkpoint->getKey(),
            'restored_at' => now()->toIso8601String(),
        ];
        $cursor = is_array($checkpoint->cursor_json) ? $checkpoint->cursor_json : [];
        if (filled($cursor['task_key'] ?? null)) {
            $context['next_task_key'] = $cursor['task_key'];
        }

        $run->stepRuns()->whereIn('status', ['running', 'waiting'])->update([
            'status' => 'queued',
            'external_run_type' => null,
            'external_run_id' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
        ]);
        $run->forceFill([
            'status' => 'paused',
            'current_workflow_step_id' => $cursor['workflow_step_id'] ?? $checkpoint->workflow_step_id,
            'context_json' => $context,
            'result_json' => [],
            'finished_at' => null,
            'error_message' => null,
        ])->save();
        $session->forceFill(['active_workflow_run_id' => $run->getKey(), 'status' => 'paused', 'paused_at' => now()])->save();
        app(WorkflowStudioSessionService::class)->appendEvent($session, 'checkpoint.restored', 'Lauf wurde auf „'.$checkpoint->name.'“ zurueckgesetzt.', [
            'checkpoint_id' => (int) $checkpoint->getKey(), 'workflow_run_id' => (int) $run->getKey(),
        ], 'warning');

        return $run->fresh() ?? $run;
    }

    public function branch(WorkflowStudioSession $session, WorkflowStudioCheckpoint|int $checkpoint): WorkflowRun
    {
        $checkpoint = $this->resolve($session, $checkpoint);
        $this->assertCompatible($session, $checkpoint);
        $context = $this->decryptContext($checkpoint);
        $cursor = is_array($checkpoint->cursor_json) ? $checkpoint->cursor_json : [];
        $context['workflow_studio_session_id'] = (int) $session->getKey();
        $context['manual_pause_requested'] = true;
        $context['branched_from_checkpoint_id'] = (int) $checkpoint->getKey();
        if (filled($cursor['task_key'] ?? null)) {
            $context['next_task_key'] = $cursor['task_key'];
        }

        $run = app(WorkflowExecutionService::class)->start($session->workflow, $context, 'workflow-studio-checkpoint');
        $run->forceFill([
            'current_workflow_step_id' => $cursor['workflow_step_id'] ?? $checkpoint->workflow_step_id,
            'workflow_studio_session_id' => $session->getKey(),
        ])->save();
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        app(WorkflowStudioSessionService::class)->appendEvent($session, 'checkpoint.branched', 'Neuer Lauf wurde von „'.$checkpoint->name.'“ abgezweigt.', [
            'checkpoint_id' => (int) $checkpoint->getKey(), 'workflow_run_id' => (int) $run->getKey(),
        ], 'success');

        return $run->fresh() ?? $run;
    }

    public function markUnreachableBrowserState(WorkflowStudioCheckpoint $checkpoint): void
    {
        $checkpoint->forceFill(['is_reproducible' => false])->save();
    }

    public function compatibility(
        WorkflowStudioSession $session,
        WorkflowStudioCheckpoint|int $checkpoint,
    ): array {
        try {
            $checkpoint = $this->resolve($session, $checkpoint);
            $this->assertCompatible($session, $checkpoint);

            return [
                'compatible' => true,
                'reason' => null,
                'revision' => $this->checkpointRevision($session, $checkpoint),
            ];
        } catch (DomainException $exception) {
            return [
                'compatible' => false,
                'reason' => $exception->getMessage(),
                'revision' => $checkpoint instanceof WorkflowStudioCheckpoint
                    ? $this->checkpointRevision($session, $checkpoint)
                    : null,
            ];
        }
    }

    private function resolve(WorkflowStudioSession $session, WorkflowStudioCheckpoint|int $checkpoint): WorkflowStudioCheckpoint
    {
        $checkpoint = $checkpoint instanceof WorkflowStudioCheckpoint
            ? $checkpoint
            : WorkflowStudioCheckpoint::query()->findOrFail($checkpoint);

        if ((int) $checkpoint->workflow_studio_session_id !== (int) $session->getKey()) {
            throw new DomainException('Der Checkpoint gehoert nicht zu dieser Studio-Sitzung.');
        }

        return $checkpoint;
    }

    private function assertCompatible(WorkflowStudioSession $session, WorkflowStudioCheckpoint $checkpoint): void
    {
        if (! $checkpoint->is_reproducible) {
            throw new DomainException('Dieser Checkpoint ist nicht reproduzierbar, weil die Browser-Sitzung nicht mehr erreichbar ist.');
        }

        $checkpointRevision = $this->checkpointRevision($session, $checkpoint);
        if ($checkpointRevision !== (int) $session->workflow->copilot_revision) {
            throw new DomainException('Dieser Checkpoint ist nach strukturellen Workflow-Aenderungen inkompatibel.');
        }
    }

    private function checkpointRevision(WorkflowStudioSession $session, WorkflowStudioCheckpoint $checkpoint): int
    {
        $cursor = is_array($checkpoint->cursor_json) ? $checkpoint->cursor_json : [];

        return (int) (
            $cursor['workflow_revision']
            ?? $checkpoint->revision?->revision_number
            ?? $checkpoint->run?->workflow_revision
            ?? $session->current_revision
        );
    }

    private function decryptContext(WorkflowStudioCheckpoint $checkpoint): array
    {
        $json = Crypt::decryptString((string) $checkpoint->encrypted_runtime_context);
        $context = json_decode($json, true);

        if (! is_array($context)) {
            throw new DomainException('Der gespeicherte Runtime-Kontext ist ungueltig.');
        }

        return $context;
    }

    private function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (preg_match('/password|secret|token|api.?key|authorization|cookie|storage.?state|credential/', $normalizedKey)) {
                $safe[$key] = '[geschuetzt]';

                continue;
            }
            $safe[$key] = is_array($value) ? $this->safeContext($value) : $value;
        }

        return $safe;
    }

    private function browserStateIsReproducible(array $browserState): bool
    {
        return ! isset($browserState['browser_session'])
            || ! in_array(data_get($browserState, 'browser_session.status'), ['closed', 'lost', 'expired'], true);
    }
}
