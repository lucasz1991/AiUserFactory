<?php

namespace App\Services\Workflows;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowExecutionService
{
    public function __construct(
        protected MailAccountRegistrationRunner $mailRegistration,
        protected WebmailSessionRunner $webmailSession,
    ) {
    }

    public function start(Workflow $workflow, array $context = [], string $requestedBy = 'admin-ui'): WorkflowRun
    {
        if (! $workflow->is_active) {
            throw new \RuntimeException('Dieser Workflow ist deaktiviert.');
        }

        if (! $workflow->enabledSteps()->exists()) {
            throw new \RuntimeException('Dieser Workflow hat keine aktiven Schritte.');
        }

        $run = DB::transaction(function () use ($workflow, $context, $requestedBy): WorkflowRun {
            $run = WorkflowRun::query()->create([
                'run_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflow->id,
                'status' => 'queued',
                'requested_by' => $requestedBy,
                'queued_at' => now(),
                'context_json' => $this->normalizeContext($context),
                'result_json' => [],
            ]);

            $workflow->forceFill(['last_run_at' => now()])->save();

            return $run;
        });

        RunWorkflowJob::dispatch($run->id);

        return $run;
    }

    public function advance(int|WorkflowRun $workflowRun): void
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
            return;
        }

        if (! $run->started_at) {
            $run->forceFill([
                'status' => 'running',
                'started_at' => now(),
            ])->save();
        } elseif ($run->status === 'waiting') {
            $run->forceFill(['status' => 'running'])->save();
        }

        $activeStepRun = $run->stepRuns()
            ->whereIn('status', ['running', 'waiting'])
            ->first();

        if ($activeStepRun) {
            $this->scheduleMonitor($activeStepRun);

            return;
        }

        $step = $this->nextStepForRun($run);

        if (! $step) {
            $this->completeRun($run);

            return;
        }

        $stepRun = WorkflowStepRun::query()
            ->where('workflow_run_id', $run->id)
            ->where('workflow_step_id', $step->id)
            ->first();

        if ($stepRun && $stepRun->status === 'failed') {
            $this->failRun($run, $stepRun->error_message ?: 'Workflow-Schritt ist fehlgeschlagen.');

            return;
        }

        $stepRun = $stepRun ?: $this->createStepRun($run, $step);

        try {
            $this->executeStep($run, $step, $stepRun);
        } catch (\Throwable $exception) {
            $this->failStepRun($stepRun, $exception->getMessage());
            $this->failRun($run, $exception->getMessage());
        }
    }

    public function monitorStepRun(int $workflowStepRunId): void
    {
        $stepRun = WorkflowStepRun::query()
            ->with(['workflowRun.workflow.steps', 'workflowStep'])
            ->find($workflowStepRunId);

        if (! $stepRun || ! in_array($stepRun->status, ['running', 'waiting'], true)) {
            return;
        }

        $status = $this->readExternalStatus($stepRun);

        if (! is_array($status)) {
            $message = 'Der externe Node-Lauf konnte nicht gelesen werden.';
            $this->failStepRun($stepRun, $message);
            $this->continueAfterStep($stepRun->workflowRun, $stepRun, ['ok' => false, 'statusMessage' => $message], 'failed');

            return;
        }

        if ($this->externalStillRunning($status)) {
            $this->scheduleMonitor($stepRun);

            return;
        }

        $result = $this->readExternalResult($stepRun, $status);

        if (! $this->externalSucceeded($stepRun->workflowStep, $status, $result)) {
            $message = (string) (
                data_get($result, 'statusMessage')
                ?: data_get($result, 'message')
                ?: data_get($status, 'message')
                ?: 'Node-Schritt wurde nicht erfolgreich abgeschlossen.'
            );

            if ($this->hasRouteForOutcome($stepRun->workflowStep, 'failed')) {
                $result['routedOutcome'] = 'failed';
                $result['statusMessage'] = $message;
                $this->completeStepRun($stepRun, $result, 'failed');
                $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, 'failed');

                return;
            }

            $this->failStepRun($stepRun, $message, $result);
            $this->failRun($stepRun->workflowRun, $message);

            return;
        }

        $this->applyExternalResult($stepRun, $result);
        $outcome = $this->resultOutcome($result);
        $this->completeStepRun($stepRun, $result, 'completed');
        $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, $outcome, max(0, (int) $stepRun->workflowStep->wait_after_seconds));
    }

    protected function executeStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $run->forceFill([
            'status' => 'running',
            'current_workflow_step_id' => $step->id,
        ])->save();

        return match ($step->type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => $this->startMailRegistrationStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WEBMAIL_LOGIN => $this->startWebmailLoginStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WAIT => $this->completeWaitStep($run, $step, $stepRun),
            default => $this->completePlannedActionStep($run, $step, $stepRun),
        };
    }

    protected function startMailRegistrationStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $subject = $this->mailRegistrationSubject($run, $step);
        $providerKey = trim((string) data_get($step->config_json, 'provider_key')) ?: null;
        $externalRun = $this->mailRegistration->start(
            $subject,
            $providerKey,
            $this->workflowRuntimeContext($run, $step, $stepRun),
        );

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => 'mail-registration',
            'external_run_id' => $externalRun['runId'] ?? null,
            'result_json' => $this->publicRunSnapshot($externalRun),
        ])->save();

        $this->scheduleMonitor($stepRun);

        return 'waiting';
    }

    protected function startWebmailLoginStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $account = $this->webmailAccount($run, $step);
        $externalRun = $this->webmailSession->start(
            $account,
            'workflow-'.$run->id.'-step-'.$step->id,
            $this->workflowRuntimeContext($run, $step, $stepRun),
        );

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => 'webmail-session',
            'external_run_id' => $externalRun['runId'] ?? null,
            'result_json' => $this->publicRunSnapshot($externalRun),
        ])->save();

        $this->scheduleMonitor($stepRun);

        return 'waiting';
    }

    protected function completePlannedActionStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $result = [
            'ok' => true,
            'statusMessage' => 'Geplante Persona-Aktion wurde im Workflow verarbeitet.',
            'action' => $step->config_json,
            'completedAt' => now()->toIso8601String(),
        ];

        $this->completeStepRun($stepRun, $result);
        $this->continueAfterStep($run, $stepRun, $result, 'success');

        return 'waiting';
    }

    protected function completeWaitStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $seconds = max(0, (int) (data_get($step->config_json, 'seconds') ?: $step->wait_after_seconds));

        $result = [
            'ok' => true,
            'statusMessage' => $seconds > 0 ? 'Workflow wartet bis zum naechsten Schritt.' : 'Warteschritt abgeschlossen.',
            'waitSeconds' => $seconds,
        ];

        $this->completeStepRun($stepRun, $result);
        $this->continueAfterStep($run, $stepRun, $result, 'success', $seconds);

        return 'waiting';
    }

    protected function createStepRun(WorkflowRun $run, WorkflowStep $step): WorkflowStepRun
    {
        return WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'started_at' => now(),
            'result_json' => [],
        ]);
    }

    protected function completeStepRun(WorkflowStepRun $stepRun, array $result, string $taskStatus = 'completed'): void
    {
        $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : now();
        $finishedAt = now();
        $result = $this->withTaskStatuses($stepRun->workflowStep, $result, $taskStatus);

        $stepRun->forceFill([
            'status' => 'completed',
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'result_json' => $this->publicRunSnapshot($result),
            'error_message' => null,
        ])->save();
    }

    protected function failStepRun(WorkflowStepRun $stepRun, string $message, ?array $result = null): void
    {
        $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : now();
        $finishedAt = now();
        $result = $result ? $this->withTaskStatuses($stepRun->workflowStep, $result, 'failed', $message) : null;

        $stepRun->forceFill([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'result_json' => $result ? $this->publicRunSnapshot($result) : $stepRun->result_json,
            'error_message' => $message,
        ])->save();
    }

    protected function completeRun(WorkflowRun $run): void
    {
        $run = $this->loadRun($run->id);

        $run->forceFill([
            'status' => 'completed',
            'current_workflow_step_id' => null,
            'finished_at' => now(),
            'result_json' => [
                'ok' => true,
                'completed_steps' => $run->stepRuns()->where('status', 'completed')->count(),
                'finishedAt' => now()->toIso8601String(),
            ],
            'error_message' => null,
        ])->save();
    }

    protected function failRun(WorkflowRun $run, string $message): void
    {
        $run->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                'ok' => false,
                'failedAt' => now()->toIso8601String(),
            ]),
            'error_message' => $message,
        ])->save();
    }

    protected function nextStepForRun(WorkflowRun $run): ?WorkflowStep
    {
        $steps = $run->workflow
            ->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $targetActionKey = trim((string) data_get($run->context_json, 'next_step_action_key', ''));

        if ($targetActionKey !== '') {
            $target = $steps->first(fn (WorkflowStep $step): bool => $step->action_key === $targetActionKey);

            if (! $target) {
                throw new \RuntimeException('Routing-Ziel wurde nicht gefunden: '.$targetActionKey);
            }

            $this->clearRouteCursor($run);

            return $target;
        }

        foreach ($steps as $step) {
            $stepRun = WorkflowStepRun::query()
                ->where('workflow_run_id', $run->id)
                ->where('workflow_step_id', $step->id)
                ->first();

            if (! $stepRun || ! in_array($stepRun->status, ['completed', 'skipped'], true)) {
                return $step;
            }
        }

        return null;
    }

    protected function continueAfterStep(WorkflowRun $run, WorkflowStepRun $stepRun, array $result, string $outcome, int $delaySeconds = 0): void
    {
        $route = $this->routeForOutcome($stepRun->workflowStep, $outcome)
            ?: $this->linearRouteAfterStep($run, $stepRun->workflowStep, $outcome);
        $routeType = (string) ($route['type'] ?? 'step');

        $this->recordRoute($run, $stepRun, $outcome, $route);
        $run->refresh();

        if ($routeType === 'end') {
            $this->completeRun($run);

            return;
        }

        if ($routeType === 'fail') {
            $this->failRun($run, (string) (
                $route['message']
                ?? data_get($result, 'statusMessage')
                ?? data_get($result, 'message')
                ?? 'Workflow wurde ueber Fehlerroute beendet.'
            ));

            return;
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $targetActionKey = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetCardKey = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($targetActionKey !== '') {
            $context['next_step_action_key'] = $targetActionKey;
        } else {
            unset($context['next_step_action_key']);
        }

        if ($routeType === 'card' && $targetCardKey !== '') {
            $context['next_task_key'] = $targetCardKey;
        } elseif ($targetCardKey !== '') {
            $context['next_task_key'] = $targetCardKey;
        } else {
            unset($context['next_task_key']);
        }

        $run->forceFill([
            'status' => $delaySeconds > 0 ? 'waiting' : 'running',
            'current_workflow_step_id' => null,
            'context_json' => $context,
        ])->save();

        $pendingDispatch = RunWorkflowJob::dispatch($run->id);

        if ($delaySeconds > 0) {
            $pendingDispatch->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function routeForOutcome(WorkflowStep $step, string $outcome): ?array
    {
        $routes = $step->routes;
        $route = $routes[$outcome] ?? $routes['default'] ?? null;

        return is_array($route) ? $route : null;
    }

    protected function hasRouteForOutcome(WorkflowStep $step, string $outcome): bool
    {
        return $this->routeForOutcome($step, $outcome) !== null;
    }

    protected function linearRouteAfterStep(WorkflowRun $run, WorkflowStep $currentStep, string $outcome): array
    {
        if ($outcome === 'failed') {
            return [
                'type' => 'fail',
                'label' => 'Fehler ohne explizite Route',
            ];
        }

        $steps = $run->workflow
            ->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $currentIndex = $steps->search(fn (WorkflowStep $step): bool => $step->id === $currentStep->id);

        if ($currentIndex === false) {
            return ['type' => 'end', 'label' => 'Kein naechster Schritt'];
        }

        $nextStep = $steps->get($currentIndex + 1);

        if (! $nextStep) {
            return ['type' => 'end', 'label' => 'Workflow abschliessen'];
        }

        return [
            'type' => 'step',
            'action_key' => $nextStep->action_key,
            'label' => $nextStep->name,
        ];
    }

    protected function resultOutcome(array $result): string
    {
        if (! (bool) ($result['ok'] ?? false)) {
            return 'failed';
        }

        $statusLevel = strtolower(trim((string) ($result['statusLevel'] ?? '')));

        if (in_array($statusLevel, ['partial', 'waiting', 'warning'], true)) {
            return 'partial';
        }

        return 'success';
    }

    protected function recordRoute(WorkflowRun $run, WorkflowStepRun $stepRun, string $outcome, array $route): void
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $history = is_array($context['route_history'] ?? null) ? $context['route_history'] : [];
        $history[] = [
            'at' => now()->toIso8601String(),
            'workflow_step_id' => $stepRun->workflow_step_id,
            'workflow_step_run_id' => $stepRun->id,
            'outcome' => $outcome,
            'route' => $route,
        ];

        $context['route_history'] = array_slice($history, -50);

        $run->forceFill(['context_json' => $context])->save();
    }

    protected function clearRouteCursor(WorkflowRun $run): void
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        unset($context['next_step_action_key'], $context['next_task_key']);

        $run->forceFill(['context_json' => $context])->save();
    }

    protected function readExternalStatus(WorkflowStepRun $stepRun): ?array
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        if ($externalRunId === '') {
            return null;
        }

        return match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->readRun($externalRunId),
            'webmail-session' => $this->webmailSession->readRun($externalRunId),
            default => null,
        };
    }

    protected function readExternalResult(WorkflowStepRun $stepRun, array $status): array
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        $result = match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->readResult($externalRunId),
            'webmail-session' => is_array($status['result'] ?? null)
                ? $status['result']
                : $this->webmailSession->readResult($externalRunId),
            default => null,
        };

        return is_array($result) ? $result : $status;
    }

    protected function externalStillRunning(array $status): bool
    {
        $state = (string) data_get($status, 'state', '');

        if ((bool) data_get($status, 'isRunning', false)) {
            return true;
        }

        if (in_array($state, ['queued', 'starting', 'running'], true)) {
            return true;
        }

        return $state === 'waiting' && (bool) data_get($status, 'result.webmailCheckPending', false);
    }

    protected function externalSucceeded(WorkflowStep $step, array $status, array $result): bool
    {
        if ((bool) data_get($result, 'ok', false)) {
            return true;
        }

        if ((bool) data_get($step->config_json, 'allow_partial', false)) {
            return ! in_array((string) data_get($status, 'state'), ['failed'], true);
        }

        return false;
    }

    protected function applyExternalResult(WorkflowStepRun $stepRun, array $result): void
    {
        if ($stepRun->external_run_type === 'mail-registration') {
            $this->applyMailRegistrationResult($stepRun->workflowRun, $result);

            return;
        }

        if ($stepRun->external_run_type === 'webmail-session') {
            $this->applyWebmailSessionResult($stepRun->workflowRun, $result);
        }
    }

    protected function applyMailRegistrationResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);
        $account = is_array($result['account'] ?? null) ? $result['account'] : null;

        if (! $person || ! $account) {
            return;
        }

        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $email = $this->nullableString($account['email'] ?? $emailAccount['email'] ?? $person->person_email);
        $password = trim((string) ($account['password'] ?? ''));

        $emailAccount = array_replace($emailAccount, [
            'email' => $email,
            'provider' => $this->normalizeProvider($account['provider'] ?? $emailAccount['provider'] ?? 'proton'),
            'username' => $this->nullableString($account['username'] ?? $email ?? null),
            'recovery_email' => $this->nullableString($account['recoveryEmail'] ?? $emailAccount['recovery_email'] ?? null),
            'webmail_url' => $this->nullableString($account['webmailUrl'] ?? $emailAccount['webmail_url'] ?? null)
                ?: $this->defaultWebmailUrl($this->normalizeProvider($account['provider'] ?? 'proton')),
            'updated_at' => now()->toIso8601String(),
        ]);

        if ($password !== '') {
            $emailAccount['password_encrypted'] = Crypt::encryptString($password);
        }

        $metadata['email_account'] = $emailAccount;

        $person->forceFill([
            'person_email' => $email,
            'metadata' => $metadata,
        ])->save();
    }

    protected function applyWebmailSessionResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);
        $encryptedPayload = trim((string) ($result['encryptedSessionPayload'] ?? ''));

        if (! $person || $encryptedPayload === '') {
            return;
        }

        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $emailAccount['webmail_session'] = [
            'payload_encrypted' => $encryptedPayload,
            'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ];
        $metadata['email_account'] = $emailAccount;

        $person->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    protected function mailRegistrationSubject(WorkflowRun $run, WorkflowStep $step): array
    {
        $person = $this->personForRun($run, $step);
        $configuredSubject = is_array(data_get($step->config_json, 'subject'))
            ? data_get($step->config_json, 'subject')
            : [];

        if (! $person) {
            return array_replace([
                'displayName' => '',
                'desiredEmail' => '',
                'accountUsername' => '',
            ], $configuredSubject);
        }

        $emailAccount = is_array(data_get($person->metadata, 'email_account'))
            ? data_get($person->metadata, 'email_account')
            : [];
        $desiredEmail = trim((string) ($emailAccount['email'] ?? $person->person_email ?? ''));
        $username = trim((string) ($emailAccount['username'] ?? '')) ?: ($desiredEmail ?: $this->suggestedUsername($person));

        return array_replace([
            'personId' => $person->id,
            'displayName' => $person->display_name,
            'firstName' => $person->person_first_name,
            'lastName' => $person->person_last_name,
            'desiredEmail' => $desiredEmail,
            'accountUsername' => $username,
            'recoveryEmail' => (string) ($emailAccount['recovery_email'] ?? ''),
            'city' => $person->person_city,
            'country' => $person->person_country,
            'timezone' => $person->person_timezone,
        ], $configuredSubject);
    }

    protected function webmailAccount(WorkflowRun $run, WorkflowStep $step): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $account = is_array($config['account'] ?? null) ? $config['account'] : [];
        $person = ((bool) ($config['use_person_email_account'] ?? true)) ? $this->personForRun($run, $step) : null;
        $settings = $this->mailRegistration->settings();

        if ($person) {
            $emailAccount = is_array(data_get($person->metadata, 'email_account'))
                ? data_get($person->metadata, 'email_account')
                : [];

            $account = array_replace([
                'provider' => $emailAccount['provider'] ?? ($config['provider'] ?? 'proton'),
                'email' => $emailAccount['email'] ?? $person->person_email,
                'username' => $emailAccount['username'] ?? ($emailAccount['email'] ?? $person->person_email),
                'password' => $this->decryptString($emailAccount['password_encrypted'] ?? null),
                'webmailUrl' => $emailAccount['webmail_url'] ?? null,
                'personId' => $person->id,
            ], $account);
        }

        $account['provider'] = $this->normalizeProvider($account['provider'] ?? $config['provider'] ?? 'proton');
        $account['email'] = trim((string) ($account['email'] ?? ''));
        $account['username'] = trim((string) ($account['username'] ?? $account['email']));
        $password = trim((string) ($account['password'] ?? ''));

        if ($password === '') {
            $password = (string) ($this->decryptString($account['password_encrypted'] ?? null) ?? '');
        }

        $account['password'] = $password;
        $account['webmailUrl'] = trim((string) ($account['webmailUrl'] ?? $account['webmail_url'] ?? ''))
            ?: $this->defaultWebmailUrl($account['provider']);
        $account['browserEngine'] = $settings['browser_engine'] ?? 'cloak-with-chrome-fallback';
        $account['cloakHumanizeEnabled'] = (bool) ($settings['cloak_humanize_enabled'] ?? false);
        $account['cloakHumanPreset'] = $settings['cloak_human_preset'] ?? '';
        $account['headlessEnabled'] = (bool) ($settings['headless_enabled'] ?? false);
        $account['livePreviewEnabled'] = (bool) ($settings['live_preview_enabled'] ?? true);
        $account['livePreviewIntervalSeconds'] = max(1, (int) ($settings['live_preview_interval_seconds'] ?? 3));
        $account['navigationTimeoutMs'] = ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000;
        $account['observationTimeoutMs'] = min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000));

        if (trim($account['email']) === '' || trim($account['username']) === '' || trim($account['password']) === '') {
            throw new \RuntimeException('Fuer den Webmail-Login fehlen E-Mail, Benutzername oder Passwort.');
        }

        return $account;
    }

    protected function personForRun(WorkflowRun $run, ?WorkflowStep $step = null): ?Person
    {
        $personId = (int) (
            data_get($step?->config_json, 'person_id')
            ?: data_get($run->context_json, 'person_id')
            ?: 0
        );

        return $personId > 0 ? Person::query()->find($personId) : null;
    }

    protected function workflowRuntimeContext(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): array
    {
        return [
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowName' => $run->workflow?->name,
            'workflowSlug' => $run->workflow?->slug,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
        ];
    }

    protected function scheduleMonitor(WorkflowStepRun $stepRun): void
    {
        if (! in_array($stepRun->external_run_type, ['mail-registration', 'webmail-session'], true)) {
            return;
        }

        MonitorWorkflowStepRunJob::dispatch($stepRun->id)->delay(now()->addSeconds(10));
    }

    protected function normalizeContext(array $context): array
    {
        return [
            ...$context,
            'person_id' => (int) ($context['person_id'] ?? $context['personId'] ?? 0) ?: null,
            'started_from' => (string) ($context['started_from'] ?? 'workflow-manager'),
        ];
    }

    protected function loadRun(int|WorkflowRun $workflowRun): WorkflowRun
    {
        $runId = $workflowRun instanceof WorkflowRun ? $workflowRun->id : $workflowRun;

        return WorkflowRun::query()
            ->with([
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->findOrFail($runId);
    }

    protected function publicRunSnapshot(array $payload): array
    {
        unset($payload['encryptedSessionPayload'], $payload['password'], $payload['passwordEncrypted']);

        if (isset($payload['account']) && is_array($payload['account'])) {
            unset($payload['account']['password'], $payload['account']['passwordEncrypted']);
        }

        return $payload;
    }

    protected function withTaskStatuses(WorkflowStep $step, array $result, string $status, ?string $errorMessage = null): array
    {
        $tasks = $step->task_cards;

        if ($tasks === []) {
            return $result;
        }

        $result['tasks'] = collect($tasks)
            ->map(function (array $task) use ($status, $errorMessage): array {
                $task['status'] = $status;
                $task['finishedAt'] = now()->toIso8601String();

                if ($errorMessage) {
                    $task['errorMessage'] = $errorMessage;
                }

                return $task;
            })
            ->values()
            ->toArray();

        return $result;
    }

    protected function isFinalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled'], true);
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'proton';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function decryptString(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function suggestedUsername(Person $person): string
    {
        $source = trim((string) (
            $person->profile_key
            ?: $person->person_alias
            ?: $person->display_name
            ?: ''
        ));

        return str($source)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9._-]+/', '-')
            ->replaceMatches('/^[._-]+|[._-]+$/', '')
            ->replaceMatches('/[._-]{2,}/', '-')
            ->limit(64, '')
            ->toString();
    }
}
