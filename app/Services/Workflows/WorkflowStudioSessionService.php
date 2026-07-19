<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStudioEvent;
use App\Models\WorkflowStudioSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowStudioSessionService
{
    public function open(
        Workflow $workflow,
        ?User $user = null,
        string $mode = 'manual',
        ?string $permissionMode = null,
        array $attributes = [],
    ): WorkflowStudioSession {
        $mode = in_array($mode, ['manual', 'interactive', 'assisted', 'autonomous'], true) ? $mode : 'manual';
        $mode = $mode === 'autonomous' ? 'autonomous' : 'manual';
        $permission = WorkflowCopilotPermissionMode::normalize(
            $permissionMode
            ?? data_get($workflow->settings_json, 'studio.permission_mode')
            ?? $this->globalPermissionMode()->value,
        );

        if ($permission === WorkflowCopilotPermissionMode::UNRESTRICTED && ! $user?->isAdmin()) {
            $permission = WorkflowCopilotPermissionMode::ASK_CRITICAL;
        }

        $requestedPermission = $permission;
        if ($permission === WorkflowCopilotPermissionMode::UNRESTRICTED
            && ! (bool) ($attributes['unrestricted_warning_acknowledged'] ?? false)) {
            $permission = WorkflowCopilotPermissionMode::ASK_CRITICAL;
        }

        $session = WorkflowStudioSession::query()->create([
            'session_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->getKey(),
            'user_id' => $user?->getKey(),
            'person_id' => $attributes['person_id'] ?? null,
            'mode' => $mode,
            'control_owner' => $mode === 'autonomous' ? 'copilot' : 'user',
            'permission_mode' => $permission->value,
            'status' => $attributes['status'] ?? 'draft',
            'goal' => $attributes['goal'] ?? null,
            'success_criteria_json' => $attributes['success_criteria'] ?? [],
            'workflow_inputs_json' => $attributes['workflow_inputs'] ?? [],
            'budget_json' => $attributes['budget'] ?? [],
            'usage_json' => [],
            'state_json' => [
                'execution_target' => $attributes['execution_target'] ?? 'system',
                'failed_selectors' => [],
                'confirmed_action_ids' => [],
                'unrestricted_warning_acknowledged' => (bool) ($attributes['unrestricted_warning_acknowledged'] ?? false),
                'requested_permission_mode' => $requestedPermission->value,
            ],
            'current_revision' => (int) ($workflow->copilot_revision ?? 0),
            'last_activity_at' => now(),
        ]);

        $this->appendEvent($session, 'session.created', 'Workflow-Studio-Sitzung wurde angelegt.', [
            'mode' => $mode,
            'permission_mode' => $permission->value,
            'requested_permission_mode' => $requestedPermission->value,
        ]);

        return $session->fresh() ?? $session;
    }

    public function latestOrOpen(
        Workflow $workflow,
        ?User $user = null,
        string $mode = 'manual',
        ?string $permissionMode = null,
    ): WorkflowStudioSession {
        // Eine neue Testsitzung darf keine beendete/tote Sitzung wiederverwenden,
        // sonst wird deren (nie zurueckgesetztes) mode_locked_at geerbt und der
        // Modus wirkt dauerhaft gesperrt. Nur echte, noch offene Sitzungen
        // fortsetzen; alles Terminale/Fehlgeschlagene fuehrt zu einer frischen,
        // entsperrten Sitzung.
        $session = WorkflowStudioSession::query()
            ->where('workflow_id', $workflow->getKey())
            ->when($user, fn ($query) => $query->where('user_id', $user->getKey()))
            ->whereNull('finished_at')
            ->whereNotIn('status', [
                'stopped', 'completed', 'failed', 'cancelled',
                'timed_out', 'lost', 'budget_exhausted',
            ])
            ->latest('id')
            ->first();

        return $session ?: $this->open($workflow, $user, $mode, $permissionMode);
    }

    public function attachRun(WorkflowStudioSession $session, WorkflowRun $run): void
    {
        $run->forceFill(['workflow_studio_session_id' => $session->getKey()])->save();
        $session->forceFill([
            'active_workflow_run_id' => $run->getKey(),
            'status' => (string) $run->status,
            'started_at' => $session->started_at ?: now(),
            'last_activity_at' => now(),
        ])->save();
        $this->appendEvent($session, 'run.attached', 'Workflow-Lauf wurde mit der Studio-Sitzung verbunden.', [
            'workflow_run_id' => (int) $run->getKey(),
            'run_uuid' => $run->run_uuid,
        ]);
    }

    public function appendEvent(
        WorkflowStudioSession $session,
        string $type,
        string $message,
        array $payload = [],
        string $level = 'info',
    ): WorkflowStudioEvent {
        return DB::transaction(function () use ($session, $type, $message, $payload, $level): WorkflowStudioEvent {
            $locked = WorkflowStudioSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $sequence = ((int) $locked->events()->max('sequence')) + 1;
            $locked->forceFill(['last_activity_at' => now()])->save();

            return $locked->events()->create([
                'sequence' => $sequence,
                'event_type' => trim($type),
                'level' => trim($level) ?: 'info',
                'message' => trim($message),
                'payload_json' => $payload,
                'occurred_at' => now(),
            ]);
        });
    }

    public function globalPermissionMode(): WorkflowCopilotPermissionMode
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $defaults = is_array(data_get($settings, 'optimization_defaults'))
            ? data_get($settings, 'optimization_defaults')
            : [];

        if (filled($defaults['permission_mode'] ?? null)) {
            return WorkflowCopilotPermissionMode::normalize($defaults['permission_mode']);
        }

        return filter_var($defaults['auto_execute_workflow_actions'] ?? true, FILTER_VALIDATE_BOOL)
            ? WorkflowCopilotPermissionMode::ASK_CRITICAL
            : WorkflowCopilotPermissionMode::ASK_ALL;
    }
}
