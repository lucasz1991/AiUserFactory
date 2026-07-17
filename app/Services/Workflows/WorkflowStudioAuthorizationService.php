<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Models\User;
use App\Models\WorkflowStudioSession;
use DomainException;

class WorkflowStudioAuthorizationService
{
    public const SAFE_ACTIONS = [
        'read_state', 'analyze', 'selector.search', 'selector.highlight', 'selector.read',
        'probe.wait', 'probe.screenshot', 'probe.dom_refresh',
    ];

    public const CRITICAL_ACTIONS = [
        'task.delete', 'task.disable', 'workflow.replace', 'goal.change', 'success_criteria.change',
        'revision.restore', 'checkpoint.restore', 'checkpoint.branch',
    ];

    public const EXTERNAL_ACTIONS = [
        'probe.click', 'probe.fill', 'probe.keypress', 'probe.navigate', 'probe.submit',
        'external.send', 'external.register', 'external.delete',
    ];

    public function setPermissionMode(
        WorkflowStudioSession $session,
        WorkflowCopilotPermissionMode|string $mode,
        ?User $actor,
        bool $warningAcknowledged = false,
    ): WorkflowStudioSession {
        $mode = $mode instanceof WorkflowCopilotPermissionMode ? $mode : WorkflowCopilotPermissionMode::normalize($mode);

        if ($mode === WorkflowCopilotPermissionMode::UNRESTRICTED) {
            if (! $actor?->isAdmin()) {
                throw new DomainException('Uneingeschraenkter Zugriff ist ausschliesslich fuer Administratoren verfuegbar.');
            }

            if (! $warningAcknowledged) {
                throw new DomainException('Bestaetigen Sie einmalig, dass der Copilot in dieser Sitzung auch externe Aktionen ohne Rueckfrage ausfuehren darf.');
            }
        }

        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['unrestricted_warning_acknowledged'] = $mode === WorkflowCopilotPermissionMode::UNRESTRICTED
            ? true
            : (bool) ($state['unrestricted_warning_acknowledged'] ?? false);
        $session->forceFill(['permission_mode' => $mode->value, 'state_json' => $state, 'last_activity_at' => now()])->save();
        $copilotSession = $session->copilotSession;
        if ($copilotSession) {
            $budget = is_array($copilotSession->budget_json) ? $copilotSession->budget_json : [];
            $budget['permission_mode'] = $mode->value;
            $budget['auto_execute_workflow_actions'] = $mode !== WorkflowCopilotPermissionMode::ASK_ALL;
            $copilotSession->forceFill(['budget_json' => $budget, 'last_activity_at' => now()])->save();
        }

        app(WorkflowStudioSessionService::class)->appendEvent(
            $session,
            'permission.changed',
            'Copilot-Berechtigung wurde auf „'.$mode->label().'“ gesetzt.',
            ['permission_mode' => $mode->value, 'actor_user_id' => $actor?->getKey()],
            $mode === WorkflowCopilotPermissionMode::UNRESTRICTED ? 'warning' : 'info',
        );

        return $session->fresh() ?? $session;
    }

    public function decide(
        WorkflowStudioSession $session,
        string $action,
        array $parameters = [],
        ?string $confirmationId = null,
    ): array {
        $mode = WorkflowCopilotPermissionMode::normalize($session->permission_mode);
        $actionId = $this->actionId($session, $action, $parameters);
        $state = is_array($session->state_json) ? $session->state_json : [];
        if ($mode === WorkflowCopilotPermissionMode::UNRESTRICTED
            && ! (bool) ($state['unrestricted_warning_acknowledged'] ?? false)) {
            return [
                'allowed' => false,
                'requires_confirmation' => true,
                'confirmation_id' => null,
                'action' => $action,
                'message' => 'Uneingeschraenkter Zugriff muss zuerst ueber die deutliche Sitzungswarnung aktiviert werden.',
            ];
        }
        $confirmedIds = array_values(array_filter((array) ($state['confirmed_action_ids'] ?? []), 'is_string'));
        $isConfirmed = $confirmationId !== null
            && hash_equals($actionId, $confirmationId)
            && in_array($confirmationId, $confirmedIds, true);

        $requiresConfirmation = match ($mode) {
            WorkflowCopilotPermissionMode::ASK_ALL => ! in_array($action, ['read_state', 'analyze'], true),
            WorkflowCopilotPermissionMode::ASK_CRITICAL => in_array($action, [...self::CRITICAL_ACTIONS, ...self::EXTERNAL_ACTIONS], true),
            WorkflowCopilotPermissionMode::UNRESTRICTED => false,
        };

        return [
            'allowed' => ! $requiresConfirmation || $isConfirmed,
            'requires_confirmation' => $requiresConfirmation && ! $isConfirmed,
            'confirmation_id' => $requiresConfirmation ? $actionId : null,
            'action' => $action,
            'message' => $requiresConfirmation && ! $isConfirmed
                ? 'Diese Copilot-Aktion benoetigt eine Bestaetigung.'
                : 'Aktion ist fuer diese Studio-Sitzung freigegeben.',
        ];
    }

    public function confirm(WorkflowStudioSession $session, string $actionId): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $actionId)) {
            throw new DomainException('Die Bestaetigungs-ID ist ungueltig.');
        }

        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['confirmed_action_ids'] = array_values(array_unique([
            ...(array) ($state['confirmed_action_ids'] ?? []),
            $actionId,
        ]));
        $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
    }

    public function consume(WorkflowStudioSession $session, string $actionId): void
    {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['confirmed_action_ids'] = array_values(array_filter(
            (array) ($state['confirmed_action_ids'] ?? []),
            fn (mixed $candidate): bool => is_string($candidate) && ! hash_equals($candidate, $actionId),
        ));
        $session->forceFill(['state_json' => $state])->save();
    }

    public function assertAllowed(
        WorkflowStudioSession $session,
        string $action,
        array $parameters = [],
        ?string $confirmationId = null,
    ): array {
        $decision = $this->decide($session, $action, $parameters, $confirmationId);

        if (! $decision['allowed']) {
            throw new DomainException($decision['message'].' Aktions-ID: '.$decision['confirmation_id']);
        }

        if ($confirmationId) {
            $this->consume($session, $confirmationId);
        }

        return $decision;
    }

    private function actionId(WorkflowStudioSession $session, string $action, array $parameters): string
    {
        $normalized = $this->sortRecursive($parameters);

        return hash_hmac(
            'sha256',
            $session->session_uuid.'|'.$action.'|'.json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }

        return $value;
    }
}
