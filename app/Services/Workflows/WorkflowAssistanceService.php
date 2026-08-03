<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Models\WorkflowAssistanceEvent;
use App\Models\WorkflowAssistanceRequest;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Support\Push\PushCategory;
use App\Support\Push\PushDelivery;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowAssistanceService
{
    public const CAPTCHA_TASK_KEY = 'human.recaptcha_handoff';

    public function __construct(protected PushDelivery $push) {}

    public function requestCaptcha(WorkflowStepRun $stepRun, array $result): WorkflowAssistanceRequest
    {
        $stepRun->loadMissing(['workflowRun.workflow', 'workflowRun.studioSession', 'workflowStep']);
        $run = $stepRun->workflowRun;
        $marker = is_array($result['humanIntervention'] ?? null)
            ? $result['humanIntervention']
            : (is_array($result['human_intervention'] ?? null) ? $result['human_intervention'] : []);

        if (! $this->requiresCaptchaAssistance($result)) {
            throw new DomainException('Das Runner-Ergebnis enthaelt keinen gueltigen reCAPTCHA-Handoff.');
        }

        $taskKey = trim((string) (
            $marker['taskKey']
            ?? $marker['task_key']
            ?? $result['completedTaskKey']
            ?? $result['completed_task_key']
            ?? ''
        ));
        $interventionId = trim((string) ($marker['id'] ?? ''));
        $sourceKey = hash('sha256', implode('|', [
            'captcha',
            (string) $run->getKey(),
            (string) $stepRun->getKey(),
            $taskKey,
            $interventionId !== '' ? $interventionId : trim((string) $stepRun->external_run_id),
        ]));
        $browserWindow = trim((string) ($marker['browserWindow'] ?? $marker['browser_window'] ?? 'main')) ?: 'main';
        $browserState = $this->sanitizedBrowserState($result, $browserWindow);
        $expiresAfterMinutes = max(5, min(60, (int) ($marker['expiresAfterMinutes'] ?? $marker['expires_after_minutes'] ?? 15)));
        $instructions = Str::limit(strip_tags(trim((string) ($marker['instructions'] ?? ''))), 2000, '');
        $requestedByUserId = (int) ($run->studioSession?->user_id ?? 0) ?: null;
        $created = false;

        $request = DB::transaction(function () use (
            $run,
            $stepRun,
            $result,
            $marker,
            $taskKey,
            $sourceKey,
            $browserWindow,
            $browserState,
            $expiresAfterMinutes,
            $instructions,
            $requestedByUserId,
            &$created,
        ): WorkflowAssistanceRequest {
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $request = WorkflowAssistanceRequest::query()
                ->where('source_key', $sourceKey)
                ->orWhere('open_workflow_run_id', $lockedRun->getKey())
                ->lockForUpdate()
                ->first();

            if (! $request) {
                $context = is_array($lockedRun->context_json) ? $lockedRun->context_json : [];
                $request = WorkflowAssistanceRequest::query()->create([
                    'request_uuid' => (string) Str::uuid(),
                    'source_key' => $sourceKey,
                    'workflow_id' => $lockedRun->workflow_id,
                    'workflow_run_id' => $lockedRun->getKey(),
                    'open_workflow_run_id' => $lockedRun->getKey(),
                    'workflow_step_id' => $stepRun->workflow_step_id,
                    'workflow_step_run_id' => $stepRun->getKey(),
                    'workflow_studio_session_id' => $lockedRun->workflow_studio_session_id,
                    'requested_by_user_id' => $requestedByUserId,
                    'type' => WorkflowAssistanceRequest::TYPE_CAPTCHA,
                    'status' => WorkflowAssistanceRequest::STATUS_PENDING,
                    'priority' => 'high',
                    'reason_code' => 'captcha_detected',
                    'task_key' => $taskKey,
                    'resume_task_key' => trim((string) ($context['next_task_key'] ?? '')) ?: null,
                    'browser_window' => $browserWindow,
                    'current_url' => $browserState['current_url'] ?? null,
                    'title' => 'reCAPTCHA in „'.Str::limit((string) $lockedRun->workflow?->name, 120, '').'“',
                    'instructions' => $instructions !== ''
                        ? $instructions
                        : 'Bitte das reCAPTCHA im Browserbild manuell loesen und anschliessend erneut pruefen.',
                    'cursor_json' => [
                        'workflow_step_id' => $lockedRun->current_workflow_step_id,
                        'next_step_action_key' => $context['next_step_action_key'] ?? null,
                        'next_task_key' => $context['next_task_key'] ?? null,
                    ],
                    'browser_state_json' => $browserState,
                    'metadata_json' => [
                        'intervention_id' => $marker['id'] ?? null,
                        'runtime_task_key' => $marker['runtimeTaskKey'] ?? $marker['runtime_task_key'] ?? null,
                        'provider' => 'recaptcha',
                        'evidence' => $this->safeEvidence($marker['evidence'] ?? data_get($result, 'captcha')),
                    ],
                    'requested_at' => now(),
                    'expires_at' => now()->addMinutes($expiresAfterMinutes),
                ]);
                $this->appendEventLocked(
                    $request,
                    'requested',
                    null,
                    'reCAPTCHA erkannt. Die Admin-Aufgabe wurde erstellt.',
                    ['browser_window' => $browserWindow],
                );
                $created = true;
            }

            $context = is_array($lockedRun->context_json) ? $lockedRun->context_json : [];
            $context['workflow_assistance'] = [
                'active_request_id' => $request->getKey(),
                'active_request_uuid' => $request->request_uuid,
                'type' => $request->type,
                'task_key' => $request->task_key,
                'requested_at' => $request->requested_at?->toIso8601String(),
                'expires_at' => $request->expires_at?->toIso8601String(),
            ];
            $lockedRun->forceFill(['context_json' => $context])->save();

            return $request->fresh();
        });

        if ($created) {
            $this->notifyAdministrators($request);
        }

        return $request;
    }

    public function requiresCaptchaAssistance(array $result): bool
    {
        if (($result['manualInterventionRequired'] ?? false) !== true
            && ($result['manual_intervention_required'] ?? false) !== true) {
            return false;
        }

        $marker = $result['humanIntervention'] ?? $result['human_intervention'] ?? null;

        return is_array($marker)
            && strtolower(trim((string) ($marker['type'] ?? ''))) === WorkflowAssistanceRequest::TYPE_CAPTCHA
            && strtolower(trim((string) ($marker['provider'] ?? 'recaptcha'))) === 'recaptcha';
    }

    public function claim(WorkflowAssistanceRequest $request, User $operator): WorkflowAssistanceRequest
    {
        $this->assertOperator($operator);
        $expired = false;

        $claimed = DB::transaction(function () use ($request, $operator, &$expired): WorkflowAssistanceRequest {
            WorkflowRun::query()->lockForUpdate()->findOrFail($request->workflow_run_id);
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->isExpired()) {
                $this->expireLocked($locked);
                $expired = true;

                return $locked;
            }

            if (! $locked->isOpen()) {
                throw new DomainException('Diese Admin-Aufgabe ist bereits abgeschlossen.');
            }

            if ($locked->assigned_to_user_id && (int) $locked->assigned_to_user_id !== (int) $operator->getKey()) {
                throw new DomainException('Diese Aufgabe wird bereits von '.$locked->assignedTo?->name.' bearbeitet.');
            }

            if (! $locked->assigned_to_user_id) {
                $locked->forceFill([
                    'status' => WorkflowAssistanceRequest::STATUS_CLAIMED,
                    'assigned_to_user_id' => $operator->getKey(),
                    'claimed_at' => now(),
                ])->save();
                $this->appendEventLocked($locked, 'claimed', $operator, 'Aufgabe wurde uebernommen.');
            }

            return $locked->fresh(['assignedTo']);
        });

        if ($expired) {
            throw new DomainException('Die Bearbeitungszeit ist abgelaufen. Der Browser darf nicht blind fortgesetzt werden.');
        }

        return $claimed;
    }

    public function release(WorkflowAssistanceRequest $request, User $operator): WorkflowAssistanceRequest
    {
        $this->assertOperator($operator);

        return DB::transaction(function () use ($request, $operator): WorkflowAssistanceRequest {
            WorkflowRun::query()->lockForUpdate()->findOrFail($request->workflow_run_id);
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertAssignedOperator($locked, $operator);
            $locked->forceFill([
                'status' => WorkflowAssistanceRequest::STATUS_PENDING,
                'assigned_to_user_id' => null,
                'claimed_at' => null,
            ])->save();
            $this->appendEventLocked($locked, 'released', $operator, 'Aufgabe wurde fuer andere Administratoren freigegeben.');

            return $locked->fresh();
        });
    }

    public function runBrowserProbe(
        WorkflowAssistanceRequest $request,
        User $operator,
        string $action,
        array $payload = [],
    ): array {
        $this->assertOperator($operator);
        $request = $request->fresh(['workflowRun.workflow']);
        $this->assertAssignedOperator($request, $operator);

        if ($request->isExpired()) {
            throw new DomainException('Die Bearbeitungszeit ist abgelaufen. Bitte den Workflow kontrolliert neu starten.');
        }

        $run = $request->workflowRun;
        if (! $run || $run->status !== 'paused') {
            throw new DomainException('Eine Browseraktion ist nur am sicher pausierten Workflow moeglich.');
        }

        if ((int) data_get($run->context_json, 'workflow_assistance.active_request_id', 0) !== (int) $request->getKey()) {
            throw new DomainException('Der pausierte Lauf gehoert nicht mehr zu dieser Admin-Aufgabe.');
        }

        $task = $this->probeTask($request, $action, $payload);
        $response = app(WorkflowExecutionService::class)->runManualProbe(
            $run,
            $task,
            $request->workflow_step_id,
        );

        if (! ($response['ok'] ?? false)) {
            throw new DomainException((string) ($response['message'] ?? 'Die Browseraktion konnte nicht gestartet werden.'));
        }

        $this->appendEvent(
            $request,
            $action === 'verify' ? 'verification_started' : 'browser_interaction_started',
            $operator,
            match ($action) {
                'verify' => 'Die erneute reCAPTCHA-Pruefung wurde gestartet.',
                'click' => 'Ein manueller Klick im Browserbild wurde gestartet.',
                'type' => 'Eine manuelle Texteingabe wurde gestartet.',
                'key' => 'Eine manuelle Tasteneingabe wurde gestartet.',
                default => 'Das Browserbild wird aktualisiert.',
            },
            ['action' => $action],
        );

        return $response;
    }

    public function verificationState(WorkflowAssistanceRequest $request): array
    {
        $run = $request->workflowRun()->first();
        $probe = is_array(data_get($run?->context_json, 'studio_probe_result'))
            ? data_get($run->context_json, 'studio_probe_result')
            : [];
        $task = is_array($probe['task'] ?? null) ? $probe['task'] : [];

        if ((string) ($task['assistance_request_uuid'] ?? '') !== (string) $request->request_uuid
            || (string) ($task['task_key'] ?? '') !== self::CAPTCHA_TASK_KEY
            || ! filter_var($task['verification_only'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['status' => 'none', 'message' => 'Noch keine abschliessende reCAPTCHA-Pruefung vorhanden.'];
        }

        $result = is_array($probe['result'] ?? null) ? $probe['result'] : [];
        $taskResult = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && (string) ($candidate['task_key'] ?? '') === self::CAPTCHA_TASK_KEY);

        if (! is_array($taskResult)) {
            return ['status' => 'failed', 'message' => 'Die reCAPTCHA-Pruefung lieferte kein auswertbares Ergebnis.'];
        }

        if (($taskResult['captchaDetected'] ?? $taskResult['captcha_detected'] ?? null) === false) {
            return [
                'status' => 'passed',
                'message' => 'Kein ungelöstes reCAPTCHA mehr erkannt. Der Workflow darf fortgesetzt werden.',
                'completed_at' => $probe['completed_at'] ?? null,
            ];
        }

        return [
            'status' => 'blocked',
            'message' => 'Das reCAPTCHA ist weiterhin sichtbar. Bitte weiter manuell bearbeiten und erneut pruefen.',
            'completed_at' => $probe['completed_at'] ?? null,
        ];
    }

    public function resolveAndResume(
        WorkflowAssistanceRequest $request,
        User $operator,
        ?string $resolutionNote = null,
    ): array {
        $this->assertOperator($operator);
        $alreadyResumed = false;

        $runId = DB::transaction(function () use ($request, $operator, $resolutionNote, &$alreadyResumed): int {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($request->workflow_run_id);
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->status === WorkflowAssistanceRequest::STATUS_RESOLVED && $locked->resume_dispatched_at) {
                $alreadyResumed = true;

                return (int) $run->getKey();
            }

            $this->assertAssignedOperator($locked, $operator);
            if ($run->status !== 'paused') {
                throw new DomainException('Der Workflow ist nicht mehr sicher pausiert.');
            }
            if ($locked->isExpired()) {
                throw new DomainException('Die Admin-Aufgabe ist abgelaufen. Ein blindes Fortsetzen ist gesperrt.');
            }
            if ((int) data_get($run->context_json, 'workflow_assistance.active_request_id', 0) !== (int) $locked->getKey()) {
                throw new DomainException('Der Workflow-Cursor gehoert nicht mehr zu dieser Admin-Aufgabe.');
            }

            $verification = $this->verificationStateForRun($locked, $run);
            if (($verification['status'] ?? '') !== 'passed') {
                throw new DomainException((string) ($verification['message'] ?? 'Das reCAPTCHA muss vor dem Fortsetzen erneut geprueft werden.'));
            }

            $context = is_array($run->context_json) ? $run->context_json : [];
            $history = is_array($context['workflow_assistance_history'] ?? null)
                ? $context['workflow_assistance_history']
                : [];
            $history[] = [
                'request_uuid' => $locked->request_uuid,
                'type' => $locked->type,
                'resolved_by_user_id' => $operator->getKey(),
                'resolved_at' => now()->toIso8601String(),
            ];
            $context['workflow_assistance_history'] = array_slice($history, -50);
            unset($context['workflow_assistance']);
            $run->forceFill(['context_json' => $context])->save();

            $locked->forceFill([
                'status' => WorkflowAssistanceRequest::STATUS_RESOLVED,
                'open_workflow_run_id' => null,
                'resolved_by_user_id' => $operator->getKey(),
                'resolved_at' => now(),
                'resolution_note' => Str::limit(strip_tags(trim((string) $resolutionNote)), 2000, '') ?: null,
            ])->save();
            $this->appendEventLocked($locked, 'resolved', $operator, 'reCAPTCHA wurde geprueft und als geloest bestaetigt.');

            return (int) $run->getKey();
        });

        if ($alreadyResumed) {
            return ['ok' => true, 'message' => 'Der Workflow wurde bereits fortgesetzt.'];
        }

        $response = app(WorkflowExecutionService::class)->resumeManualPause($runId);
        if (! ($response['ok'] ?? false)) {
            throw new DomainException((string) ($response['message'] ?? 'Der Workflow konnte nicht fortgesetzt werden.'));
        }

        DB::transaction(function () use ($request, $operator): void {
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if (! $locked->resume_dispatched_at) {
                $locked->forceFill(['resume_dispatched_at' => now()])->save();
                $this->appendEventLocked($locked, 'resumed', $operator, 'Der Workflow wurde ab dem gespeicherten Cursor fortgesetzt.');
            }
        });

        return $response;
    }

    public function latestBrowserSnapshot(WorkflowAssistanceRequest $request): array
    {
        $request->loadMissing(['workflowRun.stepRuns', 'workflowStepRun']);
        $run = $request->workflowRun;
        $payloads = [
            $request->browser_state_json,
            data_get($run?->context_json, 'manual_pause_checkpoint'),
            data_get($run?->context_json, 'studio_probe_result.result'),
            $request->workflowStepRun?->result_json,
        ];
        $windows = collect($payloads)
            ->filter(fn (mixed $payload): bool => is_array($payload))
            ->flatMap(function (array $payload): array {
                $candidate = $payload['windows'] ?? $payload['browserWindows'] ?? $payload['browser_windows'] ?? [];

                return is_array($candidate) ? $candidate : [];
            })
            ->filter(fn (mixed $window): bool => is_array($window))
            ->map(fn (array $window): array => $this->sanitizeBrowserWindow($window))
            ->filter(fn (array $window): bool => $window !== [])
            ->filter(function (array $window) use ($request): bool {
                $key = trim((string) ($window['key'] ?? ''));

                return $key === '' || $key === (string) $request->browser_window;
            })
            ->sortBy(fn (array $window): string => (string) ($window['captured_at'] ?? ''));

        return $windows->last() ?? [
            'key' => $request->browser_window,
            'url' => $request->current_url,
            'preview_path' => null,
            'captured_at' => null,
        ];
    }

    public function previewAbsolutePath(WorkflowAssistanceRequest $request): ?string
    {
        $relative = trim((string) ($this->latestBrowserSnapshot($request)['preview_path'] ?? ''));

        if ($relative === ''
            || str_contains($relative, '..')
            || ! str_starts_with(str_replace('\\', '/', $relative), 'workflow-task-runs/')
            || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'png') {
            return null;
        }

        $root = realpath(storage_path('app/public/workflow-task-runs'));
        $path = realpath(storage_path('app/public/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative)));

        if ($root === false || $path === false) {
            return null;
        }

        $rootPrefix = rtrim(strtolower($root), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with(strtolower($path), $rootPrefix) ? $path : null;
    }

    public function appendEvent(
        WorkflowAssistanceRequest $request,
        string $type,
        ?User $actor,
        string $message,
        array $payload = [],
    ): WorkflowAssistanceEvent {
        return DB::transaction(function () use ($request, $type, $actor, $message, $payload): WorkflowAssistanceEvent {
            $locked = WorkflowAssistanceRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            return $this->appendEventLocked($locked, $type, $actor, $message, $payload);
        });
    }

    protected function probeTask(WorkflowAssistanceRequest $request, string $action, array $payload): array
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $common = [
            'key' => 'assistance-'.Str::lower(Str::random(10)),
            'title' => 'Admin-Browseraktion',
            'browser_window' => $request->browser_window,
        ];

        $task = match ($action) {
            'refresh' => $catalog->cardFromDefinition('wait.seconds', [...$common, 'value' => 0]),
            'click' => $catalog->cardFromDefinition('browser.assistance_click_coordinates', $common),
            'type' => $catalog->cardFromDefinition('browser.assistance_type_text', [
                ...$common,
                'value' => Str::limit((string) ($payload['text'] ?? ''), 500, ''),
                'value_source' => 'literal',
            ]),
            'key' => $catalog->cardFromDefinition('browser.press_key', [
                ...$common,
                'value' => in_array((string) ($payload['key'] ?? ''), ['Enter', 'Tab'], true)
                    ? (string) $payload['key']
                    : 'Tab',
            ]),
            'verify' => $catalog->cardFromDefinition(self::CAPTCHA_TASK_KEY, $common),
            default => throw new DomainException('Diese Admin-Browseraktion ist nicht erlaubt.'),
        };

        if ($action === 'click') {
            $x = filter_var($payload['x_ratio'] ?? null, FILTER_VALIDATE_FLOAT);
            $y = filter_var($payload['y_ratio'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($x === false || $y === false || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                throw new DomainException('Die Klickposition liegt ausserhalb des Browserbildes.');
            }

            $snapshot = $this->latestBrowserSnapshot($request);
            $expectedCapture = trim((string) ($payload['captured_at'] ?? ''));
            if ($expectedCapture === '' || $expectedCapture !== trim((string) ($snapshot['captured_at'] ?? ''))) {
                throw new DomainException('Das Browserbild wurde inzwischen aktualisiert. Bitte auf das neue Bild klicken.');
            }
            $task['x_ratio'] = $x;
            $task['y_ratio'] = $y;
        }

        if ($action === 'verify') {
            $task['verification_only'] = true;
        }

        $task['assistance_request_uuid'] = $request->request_uuid;

        return $task;
    }

    protected function verificationStateForRun(WorkflowAssistanceRequest $request, WorkflowRun $run): array
    {
        $run->setRelation('assistanceRequestForVerification', $request);
        $probe = is_array(data_get($run->context_json, 'studio_probe_result'))
            ? data_get($run->context_json, 'studio_probe_result')
            : [];
        $task = is_array($probe['task'] ?? null) ? $probe['task'] : [];

        if ((string) ($task['assistance_request_uuid'] ?? '') !== (string) $request->request_uuid
            || (string) ($task['task_key'] ?? '') !== self::CAPTCHA_TASK_KEY
            || ! filter_var($task['verification_only'] ?? false, FILTER_VALIDATE_BOOL)) {
            return ['status' => 'none', 'message' => 'Bitte das reCAPTCHA zuerst erneut pruefen.'];
        }

        $result = is_array($probe['result'] ?? null) ? $probe['result'] : [];
        $taskResult = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && (string) ($candidate['task_key'] ?? '') === self::CAPTCHA_TASK_KEY);

        return is_array($taskResult)
            && ($taskResult['captchaDetected'] ?? $taskResult['captcha_detected'] ?? null) === false
                ? ['status' => 'passed', 'message' => 'reCAPTCHA-Pruefung erfolgreich.']
                : ['status' => 'blocked', 'message' => 'Das reCAPTCHA ist weiterhin sichtbar oder die Pruefung ist fehlgeschlagen.'];
    }

    protected function appendEventLocked(
        WorkflowAssistanceRequest $request,
        string $type,
        ?User $actor,
        string $message,
        array $payload = [],
    ): WorkflowAssistanceEvent {
        $sequence = (int) $request->events()->max('sequence') + 1;

        return $request->events()->create([
            'sequence' => $sequence,
            'event_type' => Str::limit(trim($type), 80, ''),
            'actor_user_id' => $actor?->getKey(),
            'message' => Str::limit(strip_tags(trim($message)), 4000, ''),
            'payload_json' => Arr::only($payload, ['action', 'browser_window']),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    protected function assertOperator(User $operator): void
    {
        if (! $operator->isAdmin() || ! $operator->isActive() || $operator->trashed()) {
            throw new DomainException('Nur aktive Administratoren duerfen Workflow-Hilfen bearbeiten.');
        }
    }

    protected function assertAssignedOperator(WorkflowAssistanceRequest $request, User $operator): void
    {
        if (! $request->isOpen()) {
            throw new DomainException('Diese Admin-Aufgabe ist bereits abgeschlossen.');
        }

        if ((int) $request->assigned_to_user_id !== (int) $operator->getKey()) {
            throw new DomainException('Bitte die Aufgabe zuerst selbst uebernehmen.');
        }
    }

    protected function expireLocked(WorkflowAssistanceRequest $request): void
    {
        $request->forceFill([
            'status' => WorkflowAssistanceRequest::STATUS_EXPIRED,
            'open_workflow_run_id' => null,
            'cancelled_at' => now(),
        ])->save();
        $this->appendEventLocked($request, 'expired', null, 'Die Bearbeitungszeit ist abgelaufen.');
    }

    protected function notifyAdministrators(WorkflowAssistanceRequest $request): void
    {
        $administrators = User::query()
            ->where('role', 'admin')
            ->where('status', true)
            ->get();
        $badgeCount = WorkflowAssistanceRequest::query()->open()->count();

        foreach ($administrators as $administrator) {
            $this->push->send(
                $administrator,
                PushCategory::Workflows,
                'workflow-assistance:'.$request->request_uuid,
                'reCAPTCHA wartet auf Hilfe',
                $request->workflow?->name ?: 'Ein Workflow wurde sicher pausiert.',
                'netzwerk/workflow-aufgaben/'.$request->request_uuid,
                $request->expires_at ? max(60, now()->diffInSeconds($request->expires_at, false)) : 900,
                $badgeCount,
            );
        }
    }

    protected function sanitizedBrowserState(array $result, string $browserWindow): array
    {
        $windows = collect(is_array($result['browserWindows'] ?? null) ? $result['browserWindows'] : [])
            ->filter(fn (mixed $window): bool => is_array($window))
            ->map(fn (array $window): array => $this->sanitizeBrowserWindow($window))
            ->filter(fn (array $window): bool => $window !== [])
            ->values()
            ->all();
        $selected = collect($windows)->firstWhere('key', $browserWindow) ?? collect($windows)->first();

        return [
            'windows' => $windows,
            'current_url' => is_array($selected) ? ($selected['url'] ?? null) : null,
        ];
    }

    protected function sanitizeBrowserWindow(array $window): array
    {
        $path = trim((string) ($window['livePreviewRelativePath'] ?? $window['live_preview_relative_path'] ?? ''));
        if ($path !== '' && (str_contains($path, '..') || ! str_starts_with(str_replace('\\', '/', $path), 'workflow-task-runs/'))) {
            $path = '';
        }

        $url = $this->safeUrl((string) ($window['url'] ?? ''));
        $key = trim((string) ($window['key'] ?? $window['name'] ?? 'main')) ?: 'main';

        return [
            'key' => Str::limit($key, 120, ''),
            'label' => Str::limit(strip_tags((string) ($window['label'] ?? $key)), 160, ''),
            'url' => $url,
            'title' => Str::limit(strip_tags((string) ($window['title'] ?? '')), 240, ''),
            'preview_path' => $path !== '' ? $path : null,
            'captured_at' => $window['capturedAt'] ?? $window['captured_at'] ?? null,
            'stale' => (bool) ($window['stale'] ?? false),
        ];
    }

    protected function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || (! str_starts_with($url, 'about:') && filter_var($url, FILTER_VALIDATE_URL) === false)) {
            return null;
        }

        if (str_starts_with($url, 'about:')) {
            return Str::limit($url, 2000, '');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $authority = (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $authority .= ':'.(int) $parts['port'];
        }

        return Str::limit(
            strtolower((string) $parts['scheme']).'://'.$authority
                .($parts['path'] ?? '')
                .(isset($parts['query']) ? '?'.$parts['query'] : '')
                .(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''),
            2000,
            '',
        );
    }

    protected function safeEvidence(mixed $evidence): array
    {
        return is_array($evidence)
            ? Arr::only($evidence, [
                'provider', 'frameCount', 'frameUrlSignals', 'selectorSignals',
                'textSignals', 'checked', 'responsePresent',
            ])
            : [];
    }
}
