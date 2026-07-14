<?php

namespace App\Services\Workflows;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotEvent;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class WorkflowCopilotQueueRecoveryService
{
    private const STALE_AFTER_SECONDS = 60;

    private const ACTIVE_RUN_STALE_AFTER_SECONDS = 150;

    private const REDISPATCH_COOLDOWN_SECONDS = 120;

    /** @var list<string> */
    private const RECONCILABLE_STATUSES = [
        WorkflowCopilotSession::STATUS_RUNNING,
        WorkflowCopilotSession::STATUS_REPAIRING,
        WorkflowCopilotSession::STATUS_VERIFYING,
    ];

    public function recordSupervisorFailure(int $sessionId, Throwable $exception): void
    {
        $locator = WorkflowCopilotSession::query()
            ->select(['id', 'workflow_id'])
            ->find($sessionId);

        if (! $locator) {
            return;
        }

        DB::transaction(function () use ($locator, $sessionId, $exception): void {
            Workflow::query()->lockForUpdate()->find($locator->workflow_id);
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $session
                || $session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
                || ! in_array($session->status, WorkflowCopilotSession::ACTIVE_STATUSES, true)) {
                return;
            }

            $now = now();
            $state = is_array($session->state_json) ? $session->state_json : [];

            if (! $this->hasLiveSupervisorLease($state)) {
                unset(
                    $state['supervisor_lease'],
                    $state['supervisor_recheck_requested'],
                    $state['supervisor_lease_recheck_token'],
                    $state['supervisor_lease_recheck_at'],
                );
            }
            $state['queue_recovery'] = array_replace(
                is_array($state['queue_recovery'] ?? null) ? $state['queue_recovery'] : [],
                [
                    'last_failure_at' => $now->toIso8601String(),
                    'last_failure_fingerprint' => $this->exceptionFingerprint($exception),
                    'requires_manual_resume' => true,
                ],
            );

            $session->forceFill([
                'status' => WorkflowCopilotSession::STATUS_PAUSED,
                'phase' => 'queue_failed',
                'state_json' => $state,
                'paused_at' => $now,
                'finished_at' => null,
                'last_activity_at' => $now,
            ])->save();

            $this->appendLockedEvent(
                $session,
                'queue.supervisor_failed',
                'Der Copilot-Supervisor ist nach allen Queue-Versuchen fehlgeschlagen. Die System-Sitzung wurde sicher pausiert und bleibt gesperrt.',
                [
                    'workflow_run_id' => $session->active_workflow_run_id ? (int) $session->active_workflow_run_id : null,
                    'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                    'exception_type' => class_basename($exception),
                    'error_code' => $this->safeExceptionCode($exception),
                    'error_summary' => $this->sanitizedExceptionSummary($exception),
                    'error_fingerprint' => $this->exceptionFingerprint($exception),
                    'manual_resume_required' => true,
                ],
                'queue_failed',
                'error',
                true,
            );
        }, 3);
    }

    /**
     * @return array{scanned:int,dispatched:int,fresh:int,leased:int,cooldown:int,skipped:int,failed:int}
     */
    public function reconcile(): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
            'fresh' => 0,
            'leased' => 0,
            'cooldown' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        WorkflowCopilotSession::query()
            ->where('execution_target', WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM)
            ->whereIn('status', self::RECONCILABLE_STATUSES)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($sessions) use (&$result): void {
                foreach ($sessions as $candidate) {
                    $result['scanned']++;
                    $claim = $this->claimRecoveryDispatch((int) $candidate->id);
                    $reason = (string) ($claim['reason'] ?? 'skipped');

                    if (! isset($claim['kind'])) {
                        $result[array_key_exists($reason, $result) ? $reason : 'skipped']++;

                        continue;
                    }

                    try {
                        $this->dispatchClaim($claim);
                        $result['dispatched']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        $this->recordRecoveryDispatchFailure($claim, $exception);
                    }
                }
            });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function claimRecoveryDispatch(int $sessionId): array
    {
        $locator = WorkflowCopilotSession::query()
            ->select(['id', 'workflow_id'])
            ->find($sessionId);

        if (! $locator) {
            return ['reason' => 'skipped'];
        }

        return DB::transaction(function () use ($locator, $sessionId): array {
            $workflow = Workflow::query()->lockForUpdate()->find($locator->workflow_id);
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $workflow
                || ! $session
                || $session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
                || ! in_array($session->status, self::RECONCILABLE_STATUSES, true)
                || (int) $workflow->active_workflow_copilot_session_id !== (int) $session->id) {
                return ['reason' => 'skipped'];
            }

            $state = is_array($session->state_json) ? $session->state_json : [];

            if ($this->hasLiveSupervisorLease($state)) {
                return ['reason' => 'leased'];
            }

            $run = $session->active_workflow_run_id
                ? WorkflowRun::query()->lockForUpdate()->find($session->active_workflow_run_id)
                : null;
            $stepRun = $run
                ? $run->stepRuns()
                    ->whereIn('status', ['running', 'waiting'])
                    ->latest('updated_at')
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($run && ! $this->hasSafeSystemIdentity($session, $run)) {
                $this->pauseUnsafeRunLocked($session, $run);

                return ['reason' => 'skipped'];
            }

            $lastActivity = $this->lastActivityAt($session, $run, $stepRun);
            $staleAfter = ($run && in_array($run->status, ['running', 'waiting'], true)) || $stepRun
                ? self::ACTIVE_RUN_STALE_AFTER_SECONDS
                : self::STALE_AFTER_SECONDS;

            if ($lastActivity?->isAfter(CarbonImmutable::now()->subSeconds($staleAfter))) {
                return ['reason' => 'fresh'];
            }

            $claim = $this->dispatchTarget($session, $run, $stepRun);
            $signature = $this->recoverySignature($session, $run, $stepRun, $claim);
            $recovery = is_array($state['queue_recovery'] ?? null) ? $state['queue_recovery'] : [];
            $cooldownUntil = $this->parseTimestamp($recovery['cooldown_until'] ?? null);

            if (($recovery['last_signature'] ?? null) === $signature && $cooldownUntil?->isFuture()) {
                return ['reason' => 'cooldown'];
            }

            unset(
                $state['supervisor_lease'],
                $state['supervisor_recheck_requested'],
                $state['supervisor_lease_recheck_token'],
                $state['supervisor_lease_recheck_at'],
            );
            $state['queue_recovery'] = array_replace($recovery, [
                'last_signature' => $signature,
                'last_dispatch_at' => now()->toIso8601String(),
                'cooldown_until' => now()->addSeconds(self::REDISPATCH_COOLDOWN_SECONDS)->toIso8601String(),
                'dispatch_kind' => $claim['kind'],
                'dispatch_target_id' => $claim['target_id'],
            ]);
            $session->forceFill(['state_json' => $state])->save();

            $this->appendLockedEvent(
                $session,
                'queue.recovery_dispatched',
                'Eine verwaiste System-Ausfuehrung wurde durch die Queue-Ueberwachung erneut eingeplant.',
                [
                    'dispatch_kind' => $claim['kind'],
                    'workflow_run_id' => $run?->id,
                    'workflow_step_run_id' => $stepRun?->id,
                    'run_status' => $run?->status,
                    'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                    'stale_since' => $lastActivity?->toIso8601String(),
                    'stale_after_seconds' => $staleAfter,
                ],
                (string) $session->phase,
                'warning',
                false,
            );

            return [...$claim, 'session_id' => (int) $session->id, 'signature' => $signature];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    protected function dispatchClaim(array $claim): void
    {
        match ($claim['kind']) {
            'run' => RunWorkflowJob::dispatch((int) $claim['target_id']),
            'monitor' => MonitorWorkflowStepRunJob::dispatch((int) $claim['target_id']),
            default => WorkflowCopilotSupervisorJob::dispatch((int) $claim['session_id']),
        };
    }

    /**
     * @return array{kind:string,target_id:int}
     */
    protected function dispatchTarget(
        WorkflowCopilotSession $session,
        ?WorkflowRun $run,
        ?WorkflowStepRun $stepRun,
    ): array {
        if (! $run) {
            return ['kind' => 'supervisor', 'target_id' => (int) $session->id];
        }

        $context = is_array($run->context_json) ? $run->context_json : [];

        if (is_array($context['copilot_checkpoint'] ?? null) && $context['copilot_checkpoint'] !== []) {
            return ['kind' => 'supervisor', 'target_id' => (int) $session->id];
        }

        if ($stepRun && in_array($stepRun->status, ['running', 'waiting'], true)) {
            return ['kind' => 'monitor', 'target_id' => (int) $stepRun->id];
        }

        if (in_array($run->status, ['queued', 'running', 'waiting'], true)) {
            return ['kind' => 'run', 'target_id' => (int) $run->id];
        }

        return ['kind' => 'supervisor', 'target_id' => (int) $session->id];
    }

    protected function hasSafeSystemIdentity(WorkflowCopilotSession $session, WorkflowRun $run): bool
    {
        $context = is_array($run->context_json) ? $run->context_json : [];

        return (int) $run->workflow_id === (int) $session->workflow_id
            && (int) $run->workflow_copilot_session_id === (int) $session->id
            && (int) ($context['workflow_copilot_session_id'] ?? 0) === (int) $session->id
            && ($context['execution_target'] ?? null) === WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
            && empty($context['network_node_id'])
            && empty($context['device_id']);
    }

    protected function pauseUnsafeRunLocked(WorkflowCopilotSession $session, WorkflowRun $run): void
    {
        $now = now();
        $state = is_array($session->state_json) ? $session->state_json : [];
        unset(
            $state['supervisor_lease'],
            $state['supervisor_recheck_requested'],
            $state['supervisor_lease_recheck_token'],
            $state['supervisor_lease_recheck_at'],
        );
        $state['queue_recovery'] = array_replace(
            is_array($state['queue_recovery'] ?? null) ? $state['queue_recovery'] : [],
            [
                'blocked_at' => $now->toIso8601String(),
                'blocked_reason' => 'invalid_system_identity',
                'requires_manual_resume' => true,
            ],
        );
        $session->forceFill([
            'status' => WorkflowCopilotSession::STATUS_PAUSED,
            'phase' => 'queue_recovery_blocked',
            'state_json' => $state,
            'paused_at' => $now,
            'last_activity_at' => $now,
        ])->save();

        $this->appendLockedEvent(
            $session,
            'queue.recovery_blocked',
            'Die Queue-Wiederaufnahme wurde pausiert, weil der Run nicht eindeutig an die System-Ausfuehrung gebunden ist.',
            [
                'workflow_run_id' => (int) $run->id,
                'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                'reason_code' => 'invalid_system_identity',
            ],
            'queue_recovery_blocked',
            'error',
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function hasLiveSupervisorLease(array $state): bool
    {
        $lease = is_array($state['supervisor_lease'] ?? null) ? $state['supervisor_lease'] : [];
        $expiresAt = $this->parseTimestamp($lease['expires_at'] ?? null);

        return filled($lease['token'] ?? null) && $expiresAt?->isFuture() === true;
    }

    protected function lastActivityAt(
        WorkflowCopilotSession $session,
        ?WorkflowRun $run,
        ?WorkflowStepRun $stepRun,
    ): ?CarbonImmutable {
        $values = [
            $session->last_activity_at,
            $session->started_at,
            $run?->updated_at,
            $run?->queued_at,
            $run?->started_at,
            $stepRun?->updated_at,
            $stepRun?->started_at,
        ];

        $timestamps = array_values(array_filter(array_map(
            fn (mixed $value): ?CarbonImmutable => $this->parseTimestamp($value),
            $values,
        )));

        if ($timestamps === []) {
            return null;
        }

        usort(
            $timestamps,
            fn (CarbonImmutable $left, CarbonImmutable $right): int => $right->getTimestamp() <=> $left->getTimestamp(),
        );

        return $timestamps[0];
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    protected function recoverySignature(
        WorkflowCopilotSession $session,
        ?WorkflowRun $run,
        ?WorkflowStepRun $stepRun,
        array $claim,
    ): string {
        return hash('sha256', implode('|', [
            (string) $session->id,
            (string) $session->status,
            (string) ($run?->id ?? 0),
            (string) ($run?->status ?? 'none'),
            (string) ($stepRun?->id ?? 0),
            (string) ($stepRun?->status ?? 'none'),
            (string) ($claim['kind'] ?? ''),
            (string) ($claim['target_id'] ?? 0),
        ]));
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    protected function recordRecoveryDispatchFailure(array $claim, Throwable $exception): void
    {
        $sessionId = (int) ($claim['session_id'] ?? 0);
        $locator = WorkflowCopilotSession::query()->select(['id', 'workflow_id'])->find($sessionId);

        if (! $locator) {
            return;
        }

        DB::transaction(function () use ($locator, $sessionId, $claim, $exception): void {
            Workflow::query()->lockForUpdate()->find($locator->workflow_id);
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $session || $session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
                return;
            }

            $state = is_array($session->state_json) ? $session->state_json : [];
            $recovery = is_array($state['queue_recovery'] ?? null) ? $state['queue_recovery'] : [];
            $state['queue_recovery'] = array_replace($recovery, [
                'dispatch_failed_at' => now()->toIso8601String(),
                'dispatch_failure_fingerprint' => $this->exceptionFingerprint($exception),
            ]);
            $session->forceFill(['state_json' => $state])->save();

            $this->appendLockedEvent(
                $session,
                'queue.recovery_dispatch_failed',
                'Die automatische Queue-Wiederaufnahme konnte nicht eingeplant werden und wird spaeter erneut versucht.',
                [
                    'dispatch_kind' => $claim['kind'] ?? null,
                    'dispatch_target_id' => $claim['target_id'] ?? null,
                    'error_summary' => $this->sanitizedExceptionSummary($exception),
                    'error_fingerprint' => $this->exceptionFingerprint($exception),
                ],
                (string) $session->phase,
                'error',
                true,
            );
        }, 3);
    }

    protected function appendLockedEvent(
        WorkflowCopilotSession $session,
        string $eventType,
        string $message,
        array $payload,
        string $phase,
        string $level,
        bool $milestone,
    ): void {
        $sequence = (int) $session->last_event_sequence + 1;
        $occurredAt = now();
        WorkflowCopilotEvent::query()->create([
            'workflow_copilot_session_id' => $session->id,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'phase' => $phase,
            'level' => $level,
            'message' => $message,
            'payload_json' => $payload,
            'is_milestone' => $milestone,
            'occurred_at' => $occurredAt,
        ]);
        $session->forceFill([
            'last_event_sequence' => $sequence,
            'last_activity_at' => $occurredAt,
        ])->save();
    }

    protected function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function sanitizedExceptionSummary(Throwable $exception): string
    {
        $summary = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $exception->getMessage()) ?? '';
        $summary = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $summary) ?? $summary;
        $summary = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[redacted-jwt]', $summary) ?? $summary;
        $summary = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[redacted-email]', $summary) ?? $summary;
        $summary = preg_replace('/(?<!\d)(?:\+?\d[\s().-]*){8,}(?!\d)/', '[redacted-phone]', $summary) ?? $summary;
        $summary = preg_replace_callback(
            '/\b(password|passwd|token|secret|cookie|authorization|api[_-]?key)\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            static fn (array $matches): string => Str::lower($matches[1]).'=[redacted]',
            $summary,
        ) ?? $summary;
        $summary = preg_replace_callback(
            '~https?://[^\s<>"\']+~i',
            static function (array $matches): string {
                $parts = parse_url($matches[0]);

                if (! is_array($parts) || empty($parts['host'])) {
                    return '[redacted-url]';
                }

                $url = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
                $url .= isset($parts['port']) ? ':'.$parts['port'] : '';
                $url .= $parts['path'] ?? '';

                return isset($parts['query']) ? $url.'?[redacted]' : $url;
            },
            $summary,
        ) ?? $summary;
        $summary = trim(preg_replace('/\s{2,}/', ' ', $summary) ?? $summary);

        return Str::limit($summary !== '' ? $summary : 'Kein sicher darstellbarer Fehlertext.', 500, '');
    }

    protected function exceptionFingerprint(Throwable $exception): string
    {
        return hash('sha256', $exception::class."\0".$exception->getCode()."\0".$exception->getMessage());
    }

    protected function safeExceptionCode(Throwable $exception): int|string|null
    {
        $code = $exception->getCode();

        return is_int($code) || is_string($code) ? Str::limit((string) $code, 30, '') : null;
    }
}
