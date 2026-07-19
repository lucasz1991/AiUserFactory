<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowLogicalOutcome;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkflowResultNormalizer
{
    public function normalizeStepResult(
        WorkflowStep $step,
        array $status,
        array $result,
        ?string $externalRunType = null,
    ): array {
        $tasks = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values();

        if ($tasks->isNotEmpty()) {
            $normalizedTasks = $tasks
                ->map(fn (array $task): array => $this->withNormalizedPayload($task, $status, $externalRunType, $task))
                ->values()
                ->all();
            $result['tasks'] = $normalizedTasks;
            $tasks = collect($normalizedTasks);
        }

        $normalized = $this->evaluatePayload($result, $status, $externalRunType);
        $normalized = $this->mergeTaskEvaluations($normalized, $tasks);
        $normalized['step_id'] = (int) $step->id;
        $normalized['step_name'] = (string) $step->name;
        $normalized['step_type'] = (string) $step->type;
        $normalized['external_run_type'] = $externalRunType;

        $result['normalized_result'] = $normalized;
        $result['technical_status'] = $normalized['technical_status'];
        $result['business_status'] = $normalized['business_status'];
        $result['result_class'] = $normalized['result_class'];
        $result['empty_result'] = $normalized['empty_result'];
        $result['retryable'] = $normalized['retryable'];
        $result['state_mismatch'] = $normalized['state_mismatch'];
        $result['diagnostic_reason_code'] = $normalized['diagnostic_reason_code'];
        $result['diagnostic_reason'] = $normalized['diagnostic_reason'];
        $result['state_signature'] = $normalized['state_signature'];
        $result['ui_state'] = $normalized['ui_state'];
        $routeOutcome = strtolower(trim((string) ($result['routeOutcome'] ?? $result['route_outcome'] ?? 'success')));
        $logicalOutcome = WorkflowLogicalOutcome::fromResult($result, $routeOutcome);
        $result['logical_outcome'] = $logicalOutcome->value;
        $result['logicalOutcome'] = $logicalOutcome->value;
        $result['normalized_result']['logical_outcome'] = $logicalOutcome->value;

        if (($normalized['mail_scan'] ?? []) !== []) {
            $result['mail_scan_diagnostics'] = $normalized['mail_scan'];
        }

        if (($normalized['embedded_workflows'] ?? []) !== []) {
            $result['embedded_workflow_diagnostics'] = $normalized['embedded_workflows'];
        }

        return $result;
    }

    public function summarizeRun(WorkflowRun $run): array
    {
        $run->loadMissing(['stepRuns.workflowStep']);
        $stepEvaluations = $run->stepRuns
            ->map(fn (WorkflowStepRun $stepRun): array => $this->stepRunEvaluation($stepRun))
            ->filter()
            ->values();
        $technicalStatuses = $stepEvaluations
            ->pluck('technical_status')
            ->filter()
            ->values();
        $technicalStatus = match (true) {
            $technicalStatuses->contains('failed') => 'failed',
            $technicalStatuses->contains('timeout') => 'timeout',
            $technicalStatuses->contains('cancelled') => 'cancelled',
            $technicalStatuses->contains('running') => 'running',
            default => 'success',
        };
        $businessStatuses = $stepEvaluations
            ->pluck('business_status')
            ->filter()
            ->values();
        $workflowReturnOk = data_get($run->context_json, 'workflow_return_ok');
        $businessStatus = 'success';
        $reasonCode = 'workflow_success';
        $resultClass = 'workflow_success';

        if (in_array($technicalStatus, ['failed', 'timeout', 'cancelled'], true)) {
            $businessStatus = 'failed';
            $reasonCode = 'workflow_technical_failure';
            $resultClass = 'workflow_hard_failure';
        } elseif ($workflowReturnOk === false) {
            $businessStatus = 'failed';
            $reasonCode = 'workflow_return_false';
            $resultClass = 'workflow_business_failure';
        } elseif ($businessStatuses->contains('failed')) {
            $businessStatus = 'failed';
            $reasonCode = 'business_failure';
            $resultClass = 'workflow_business_failure';
        } elseif ($businessStatuses->contains('no_match')) {
            $businessStatus = 'no_match';
            $reasonCode = 'mail_match_none';
            $resultClass = 'workflow_completed_no_match';
        } elseif ($businessStatuses->contains('valid_empty')) {
            $businessStatus = 'valid_empty';
            $reasonCode = 'valid_empty_result';
            $resultClass = 'workflow_completed_valid_empty';
        } elseif ($businessStatuses->contains('partial') || $businessStatuses->contains('unknown')) {
            $businessStatus = 'partial';
            $reasonCode = 'business_partial';
            $resultClass = 'workflow_completed_with_diagnostics';
        }

        return [
            'technical_status' => $technicalStatus,
            'business_status' => $businessStatus,
            'result_class' => $resultClass,
            'ok' => $technicalStatus === 'success' && $businessStatus !== 'failed',
            'business_ok' => $businessStatus === 'success',
            'empty_result' => $stepEvaluations->contains(fn (array $item): bool => (bool) ($item['empty_result'] ?? false)),
            'retryable' => $stepEvaluations->contains(fn (array $item): bool => (bool) ($item['retryable'] ?? false)),
            'state_mismatch' => $stepEvaluations->contains(fn (array $item): bool => (bool) ($item['state_mismatch'] ?? false)),
            'diagnostic_reason_code' => $reasonCode,
            'diagnostic_reason' => $this->reasonText($reasonCode),
            'step_evaluations' => $stepEvaluations->all(),
            'diagnostic_counts' => $this->diagnosticCounts($stepEvaluations),
        ];
    }

    protected function withNormalizedPayload(array $payload, array $status, ?string $externalRunType, array $task): array
    {
        $payload['normalized_result'] = $this->evaluatePayload($payload, $status, $externalRunType, $task);
        $routeOutcome = strtolower(trim((string) ($payload['routeOutcome'] ?? $payload['route_outcome'] ?? $payload['branchOutcome'] ?? $payload['branch_outcome'] ?? 'success')));
        $logicalOutcome = WorkflowLogicalOutcome::fromResult($payload, $routeOutcome);
        $payload['logical_outcome'] = $logicalOutcome->value;
        $payload['logicalOutcome'] = $logicalOutcome->value;
        $payload['normalized_result']['logical_outcome'] = $logicalOutcome->value;

        return $payload;
    }

    protected function evaluatePayload(
        array $payload,
        array $status,
        ?string $externalRunType,
        ?array $task = null,
    ): array {
        $message = $this->message($payload, $status);
        $reasonCode = $this->reasonCode($payload, $status, $task, $message);
        $technicalStatus = $this->technicalStatus($payload, $status, $reasonCode);
        $mailScan = $this->mailScanDiagnostics($payload, $task);
        $emptyResult = $this->emptyResult($payload, $mailScan);
        $businessStatus = $this->businessStatus($payload, $reasonCode, $technicalStatus, $emptyResult, $mailScan);
        $resultClass = $this->resultClass($technicalStatus, $businessStatus, $reasonCode);
        $retryable = $this->retryable($technicalStatus, $reasonCode, $businessStatus);
        $uiState = $this->uiState($payload, $status, $message);
        $stateSignature = $this->stateSignature($payload, $status, $reasonCode, $uiState);

        return [
            'technical_status' => $technicalStatus,
            'business_status' => $businessStatus,
            'result_class' => $resultClass,
            'empty_result' => $emptyResult,
            'retryable' => $retryable,
            'state_mismatch' => in_array($reasonCode, [
                'state_not_reached',
                'same_state_retry_blocked',
                'selector_not_found',
                'mail_list_not_visible',
                'session_invalid',
                'session_missing',
                'session_expired',
                'logged_out_redirect',
                'ui_blocked_by_consent',
                'captcha_detected',
            ], true),
            'diagnostic_reason_code' => $reasonCode,
            'diagnostic_reason' => $this->reasonText($reasonCode),
            'state_signature' => $stateSignature,
            'ui_state' => $uiState,
            'mail_scan' => $mailScan,
            'counts' => $this->counts($payload, $mailScan),
            'observed_state' => $this->observedState($payload, $status, $uiState),
            'expected_state' => $this->expectedState($payload, $task),
            'selector_status' => $this->selectorStatus($payload, $reasonCode),
            'external_run_type' => $externalRunType,
        ];
    }

    protected function mergeTaskEvaluations(array $normalized, Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return $normalized;
        }

        $evaluations = $tasks
            ->map(fn (array $task): array => is_array($task['normalized_result'] ?? null) ? $task['normalized_result'] : [])
            ->filter()
            ->values();

        if ($evaluations->isEmpty()) {
            return $normalized;
        }

        $embedded = $this->embeddedWorkflowDiagnostics($tasks);
        $technicalFailed = $evaluations->contains(fn (array $item): bool => ($item['technical_status'] ?? '') === 'failed');
        $retryable = $evaluations->contains(fn (array $item): bool => (bool) ($item['retryable'] ?? false));
        $stateMismatch = $evaluations->contains(fn (array $item): bool => (bool) ($item['state_mismatch'] ?? false));
        $empty = $evaluations->contains(fn (array $item): bool => (bool) ($item['empty_result'] ?? false));
        $businessStatuses = $evaluations->pluck('business_status')->filter()->values();

        if ($technicalFailed && $normalized['technical_status'] !== 'failed') {
            $normalized['technical_status'] = 'failed';
        }

        if ($businessStatuses->contains('failed')) {
            $normalized['business_status'] = 'failed';
        } elseif ($businessStatuses->contains('no_match')) {
            $normalized['business_status'] = 'no_match';
        } elseif ($businessStatuses->contains('valid_empty')) {
            $normalized['business_status'] = 'valid_empty';
        } elseif ($businessStatuses->contains('partial')) {
            $normalized['business_status'] = 'partial';
        }

        $normalized['empty_result'] = (bool) $normalized['empty_result'] || $empty;
        $normalized['retryable'] = (bool) $normalized['retryable'] || $retryable;
        $normalized['state_mismatch'] = (bool) $normalized['state_mismatch'] || $stateMismatch;

        // Wenn Task-Auswertungen den Status auf failed herabstufen, darf der
        // Diagnose-Code nicht auf "success" ("Ausfuehrung erfolgreich.") stehen
        // bleiben — sonst widersprechen sich result_class und diagnostic_reason.
        $merged = ($normalized['technical_status'] ?? '') === 'failed'
            || ($normalized['business_status'] ?? '') === 'failed';

        if ($merged && in_array($normalized['diagnostic_reason_code'] ?? '', ['', 'success'], true)) {
            $failureReason = $evaluations
                ->first(fn (array $item): bool => (
                    (($item['technical_status'] ?? '') === 'failed' || ($item['business_status'] ?? '') === 'failed')
                    && ! in_array($item['diagnostic_reason_code'] ?? '', ['', 'success'], true)
                ));
            $normalized['diagnostic_reason_code'] = $failureReason['diagnostic_reason_code'] ?? 'hard_failure';
            $normalized['diagnostic_reason'] = $this->reasonText($normalized['diagnostic_reason_code']);
        }

        $normalized['result_class'] = $this->resultClass(
            $normalized['technical_status'],
            $normalized['business_status'],
            $normalized['diagnostic_reason_code'],
        );
        $normalized['task_diagnostic_counts'] = $this->diagnosticCounts($evaluations);
        $normalized['embedded_workflows'] = $embedded;

        return $normalized;
    }

    protected function technicalStatus(array $payload, array $status, string $reasonCode): string
    {
        $state = Str::lower(trim((string) ($payload['state'] ?? $payload['status'] ?? $status['state'] ?? $status['status'] ?? '')));
        $ok = $payload['ok'] ?? null;

        if (in_array($state, ['queued', 'starting', 'running', 'waiting'], true)) {
            return 'running';
        }

        if (in_array($state, ['cancelled', 'canceled'], true)) {
            return 'cancelled';
        }

        if (in_array($state, ['timeout', 'timed_out'], true) || $reasonCode === 'timeout') {
            return 'timeout';
        }

        if (in_array($reasonCode, ['valid_empty_result', 'mail_match_none', 'mail_filter_excluded'], true)) {
            return 'success';
        }

        if ($ok === true || in_array($state, ['success', 'completed', 'partial', 'warning'], true)) {
            return 'success';
        }

        return 'failed';
    }

    protected function businessStatus(
        array $payload,
        string $reasonCode,
        string $technicalStatus,
        bool $emptyResult,
        array $mailScan,
    ): string {
        if (in_array($technicalStatus, ['failed', 'timeout', 'cancelled'], true)) {
            return 'failed';
        }

        if ($reasonCode === 'mail_match_none') {
            return 'no_match';
        }

        if (in_array($reasonCode, ['valid_empty_result', 'mail_filter_excluded'], true)) {
            return 'valid_empty';
        }

        if ($emptyResult && $this->looksLikeMailTask($payload)) {
            return ((int) ($mailScan['matched_count'] ?? 0)) > 0 ? 'success' : 'valid_empty';
        }

        $statusLevel = Str::lower(trim((string) ($payload['statusLevel'] ?? $payload['status_level'] ?? '')));

        if (in_array($statusLevel, ['partial', 'warning'], true)) {
            return 'partial';
        }

        return $technicalStatus === 'success' ? 'success' : 'unknown';
    }

    protected function resultClass(string $technicalStatus, string $businessStatus, string $reasonCode): string
    {
        if ($technicalStatus === 'running') {
            return 'running';
        }

        if ($technicalStatus === 'timeout') {
            return 'retryable_failure';
        }

        if ($reasonCode === 'same_state_retry_blocked') {
            return 'stuck_state';
        }

        if ($technicalStatus === 'failed') {
            return in_array($reasonCode, [
                'selector_not_found',
                'state_not_reached',
                'mail_list_not_visible',
                'session_invalid',
                'session_expired',
                'dom_still_changing',
            ], true) ? 'retryable_failure' : 'hard_failure';
        }

        return match ($businessStatus) {
            'valid_empty' => 'valid_empty_result',
            'no_match' => 'mail_match_none',
            'partial' => 'partial_success',
            'failed' => 'business_failure',
            default => 'business_success',
        };
    }

    protected function reasonCode(array $payload, array $status, ?array $task, string $message): string
    {
        $providedReason = Str::lower(trim((string) (
            $payload['diagnostic_reason_code']
            ?? $payload['reason_code']
            ?? $payload['failureReasonCode']
            ?? $payload['failure_reason_code']
            ?? ''
        )));

        if ($providedReason !== '') {
            return $providedReason;
        }

        $messageLower = Str::lower($message);
        $taskKey = Str::lower(trim((string) ($payload['task_key'] ?? $payload['taskKey'] ?? $task['task_key'] ?? $task['taskKey'] ?? '')));
        $isMailTask = str_starts_with($taskKey, 'mail.') || str_contains($taskKey, 'webmail.');

        if (str_contains($messageLower, 'captcha')) {
            return 'captcha_detected';
        }

        if (str_contains($messageLower, 'consent') || str_contains($messageLower, 'cookie') || str_contains($messageLower, 'einwilligung')) {
            return 'ui_blocked_by_consent';
        }

        if (str_contains($messageLower, 'timeout') || str_contains($messageLower, 'zeitlimit')) {
            return 'timeout';
        }

        if (str_contains($messageLower, 'session-datei wurde nicht gefunden') || str_contains($messageLower, 'keine verschluesselte') || str_contains($messageLower, 'session fehlt')) {
            return 'session_missing';
        }

        if (str_contains($messageLower, 'session') && (str_contains($messageLower, 'ungueltig') || str_contains($messageLower, 'invalid') || str_contains($messageLower, 'expired') || str_contains($messageLower, 'abgelaufen'))) {
            return 'session_invalid';
        }

        if (str_contains($messageLower, 'logged out') || str_contains($messageLower, 'login') && str_contains($messageLower, 'redirect')) {
            return 'logged_out_redirect';
        }

        if ($isMailTask && str_contains($messageLower, 'keine mail-liste unter')) {
            return 'mail_list_not_visible';
        }

        if ($isMailTask && (str_contains($messageLower, 'keine passende mail') || str_contains($messageLower, 'kein verifizierungscode'))) {
            return 'mail_match_none';
        }

        if ($this->mailScanFilteredOut($payload)) {
            return 'mail_filter_excluded';
        }

        if ($this->validEmptyPayload($payload)) {
            return 'valid_empty_result';
        }

        if (str_contains($messageLower, 'selector') && (str_contains($messageLower, 'nicht gefunden') || str_contains($messageLower, 'not found'))) {
            return 'selector_not_found';
        }

        if (str_contains($messageLower, 'page-handle') || str_contains($messageLower, 'browser') && str_contains($messageLower, 'nicht')) {
            return 'state_not_reached';
        }

        if ($this->looksLikeMailTask($payload) && $this->emptyResult($payload, $this->mailScanDiagnostics($payload, $task))) {
            return 'valid_empty_result';
        }

        if (($payload['ok'] ?? null) === false || ($status['state'] ?? null) === 'failed') {
            return 'hard_failure';
        }

        return 'success';
    }

    protected function retryable(string $technicalStatus, string $reasonCode, string $businessStatus): bool
    {
        if ($businessStatus === 'valid_empty' || $businessStatus === 'no_match') {
            return false;
        }

        if (in_array($reasonCode, ['valid_empty_result', 'mail_match_none', 'mail_filter_excluded', 'same_state_retry_blocked'], true)) {
            return false;
        }

        return in_array($technicalStatus, ['failed', 'timeout'], true)
            && in_array($reasonCode, [
                'timeout',
                'selector_not_found',
                'state_not_reached',
                'dom_still_changing',
                'session_invalid',
                'session_expired',
                'logged_out_redirect',
                'mail_list_not_visible',
            ], true);
    }

    protected function mailScanDiagnostics(array $payload, ?array $task = null): array
    {
        if (! $this->looksLikeMailTask($payload) && ! $this->looksLikeMailTask($task ?? [])) {
            return [];
        }

        $debug = $this->firstArray([
            $payload['mailListScanDebug'] ?? null,
            $payload['mail_list_scan_debug'] ?? null,
            $payload['mail_scan_debug'] ?? null,
        ]);
        $candidates = collect(is_array($debug['candidates'] ?? null) ? $debug['candidates'] : []);
        $openedMails = collect(is_array($payload['openedMails'] ?? null) ? $payload['openedMails'] : ($payload['opened_mails'] ?? []));
        $visibleMailCount = $this->intValue(
            $debug['totalCandidates'] ?? $debug['total_candidates'] ?? $payload['sourceCount'] ?? $payload['source_count'] ?? null,
        );
        $candidateCount = $this->intValue(
            $payload['candidateCount'] ?? $payload['candidate_count'] ?? $debug['acceptedCandidates'] ?? $debug['accepted_candidates'] ?? null,
        );
        $matchedCount = $this->intValue($payload['matchCount'] ?? $payload['match_count'] ?? null);
        $filteredByAge = $candidates->filter(fn (mixed $item): bool => is_array($item) && ($item['ageAccepted'] ?? $item['age_accepted'] ?? true) === false)->count();
        $filteredBySubject = $candidates->filter(fn (mixed $item): bool => is_array($item) && ($item['textAccepted'] ?? $item['text_accepted'] ?? true) === false)->count();
        $listVisible = ! Str::contains(Str::lower($this->message($payload, [])), 'keine mail-liste unter');

        return [
            'visible_mail_count' => $visibleMailCount,
            'candidate_count' => $candidateCount,
            'matched_count' => $matchedCount,
            'filtered_by_age_count' => $filteredByAge,
            'filtered_by_subject_count' => $filteredBySubject,
            'poll_count' => $this->intValue($payload['pollCount'] ?? $payload['poll_count'] ?? $debug['pollCount'] ?? $debug['poll_count'] ?? null),
            'scan_duration_ms' => $this->intValue($payload['scanDurationMs'] ?? $payload['scan_duration_ms'] ?? null),
            'opened_mail_count' => $openedMails->count(),
            'inbox_visible' => $listVisible,
            'list_visible' => $listVisible,
            'output_array_name' => $payload['outputArrayName'] ?? $payload['output_array_name'] ?? null,
            'input_array_name' => $payload['inputArrayName'] ?? $payload['input_array_name'] ?? null,
        ];
    }

    protected function embeddedWorkflowDiagnostics(Collection $tasks): array
    {
        return $tasks
            ->filter(fn (array $task): bool => trim((string) ($task['parent_task_key'] ?? '')) !== '' || trim((string) ($task['embedded_workflow_id'] ?? '')) !== '')
            ->groupBy(fn (array $task): string => trim((string) ($task['parent_task_key'] ?? $task['embedded_workflow_id'] ?? 'embedded')))
            ->map(function (Collection $items, string $key): array {
                $evaluations = $items
                    ->map(fn (array $task): array => is_array($task['normalized_result'] ?? null) ? $task['normalized_result'] : [])
                    ->filter()
                    ->values();
                $class = 'embedded_success';

                if ($evaluations->contains(fn (array $item): bool => ($item['result_class'] ?? '') === 'hard_failure')) {
                    $class = 'embedded_hard_failure';
                } elseif ($evaluations->contains(fn (array $item): bool => ($item['result_class'] ?? '') === 'retryable_failure')) {
                    $class = 'embedded_retryable_failure';
                } elseif ($evaluations->contains(fn (array $item): bool => ($item['result_class'] ?? '') === 'valid_empty_result')) {
                    $class = 'embedded_valid_empty';
                } elseif ($evaluations->contains(fn (array $item): bool => in_array(($item['business_status'] ?? ''), ['partial', 'no_match'], true))) {
                    $class = 'embedded_partial_success';
                }

                return [
                    'parent_task_key' => $key,
                    'embedded_workflow_id' => $items->pluck('embedded_workflow_id')->filter()->first(),
                    'embedded_workflow_name' => $items->pluck('embedded_workflow_name')->filter()->first(),
                    'embedded_class' => $class,
                    'task_count' => $items->count(),
                    'diagnostic_counts' => $this->diagnosticCounts($evaluations),
                ];
            })
            ->values()
            ->all();
    }

    protected function stepRunEvaluation(WorkflowStepRun $stepRun): array
    {
        $result = is_array($stepRun->result_json) ? $stepRun->result_json : [];
        $normalized = is_array($result['normalized_result'] ?? null)
            ? $result['normalized_result']
            : $this->evaluatePayload($result, [], $stepRun->external_run_type);

        return [
            'workflow_step_id' => (int) $stepRun->workflow_step_id,
            'workflow_step_run_id' => (int) $stepRun->id,
            'step_name' => $stepRun->workflowStep?->name,
            'status' => $stepRun->status,
            ...Arr::only($normalized, [
                'technical_status',
                'business_status',
                'result_class',
                'empty_result',
                'retryable',
                'state_mismatch',
                'diagnostic_reason_code',
                'diagnostic_reason',
                'state_signature',
                'ui_state',
            ]),
        ];
    }

    protected function diagnosticCounts(Collection $evaluations): array
    {
        return $evaluations
            ->pluck('diagnostic_reason_code')
            ->filter()
            ->countBy()
            ->sortKeys()
            ->all();
    }

    protected function counts(array $payload, array $mailScan): array
    {
        return array_filter([
            'candidate_count' => $this->firstInt([
                $payload['candidateCount'] ?? null,
                $payload['candidate_count'] ?? null,
                $mailScan['candidate_count'] ?? null,
            ]),
            'matched_count' => $this->firstInt([
                $payload['matchCount'] ?? null,
                $payload['match_count'] ?? null,
                $mailScan['matched_count'] ?? null,
            ]),
            'source_count' => $this->firstInt([$payload['sourceCount'] ?? null, $payload['source_count'] ?? null]),
            'successful_count' => $this->firstInt([$payload['successfulCount'] ?? null, $payload['successful_count'] ?? null]),
            'failed_count' => $this->firstInt([$payload['failedCount'] ?? null, $payload['failed_count'] ?? null]),
            'poll_count' => $mailScan['poll_count'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function uiState(array $payload, array $status, string $message): string
    {
        $haystack = Str::lower(implode(' ', array_filter([
            $message,
            $payload['url'] ?? $payload['currentUrl'] ?? $payload['current_url'] ?? $payload['finalUrl'] ?? $payload['frameUrl'] ?? '',
            $payload['title'] ?? $payload['currentTitle'] ?? $payload['frameTitle'] ?? '',
            $status['message'] ?? '',
        ])));

        return match (true) {
            str_contains($haystack, 'captcha') => 'captcha_blocked',
            str_contains($haystack, 'consent') || str_contains($haystack, 'cookie') => 'consent_blocked',
            str_contains($haystack, 'login') || str_contains($haystack, 'signin') || str_contains($haystack, 'anmelden') => 'login_page',
            str_contains($haystack, 'register') || str_contains($haystack, 'signup') || str_contains($haystack, 'registr') => 'registration_form',
            str_contains($haystack, 'verification') || str_contains($haystack, 'verifizierung') || str_contains($haystack, 'code') => 'verification_pending',
            str_contains($haystack, 'inbox') || str_contains($haystack, 'posteingang') => 'inbox_visible',
            str_contains($haystack, 'empty inbox') || str_contains($haystack, '0 mail') || str_contains($haystack, 'keine mail') => 'empty_inbox',
            str_contains($haystack, 'expired') || str_contains($haystack, 'abgelaufen') || str_contains($haystack, 'logged out') => 'session_expired',
            default => 'unknown_browser_state',
        };
    }

    protected function stateSignature(array $payload, array $status, string $reasonCode, string $uiState): string
    {
        $browserWindow = trim((string) (
            $payload['browser_window']
            ?? $payload['browserWindow']
            ?? $payload['browser_window_name']
            ?? $payload['browserWindowName']
            ?? data_get($status, 'browserWindow')
            ?? ''
        ));
        $url = trim((string) (
            $payload['url']
            ?? $payload['currentUrl']
            ?? $payload['current_url']
            ?? $payload['finalUrl']
            ?? $payload['frameUrl']
            ?? data_get($status, 'url')
            ?? ''
        ));
        $title = trim((string) (
            $payload['title']
            ?? $payload['currentTitle']
            ?? $payload['current_title']
            ?? $payload['frameTitle']
            ?? data_get($status, 'title')
            ?? ''
        ));
        $taskKey = trim((string) (
            $payload['key']
            ?? $payload['task_key']
            ?? $payload['taskKey']
            ?? $payload['failedTaskKey']
            ?? $payload['failed_task_key']
            ?? ''
        ));
        $selector = trim((string) (
            $payload['selector']
            ?? $payload['element_selector']
            ?? $payload['input_selector']
            ?? $payload['listSelector']
            ?? $payload['list_selector']
            ?? ''
        ));

        return hash('sha1', implode('|', [
            $reasonCode,
            $uiState,
            Str::limit($taskKey, 120, ''),
            Str::limit($browserWindow, 120, ''),
            Str::limit($this->normalizedUrl($url), 240, ''),
            Str::limit($title, 160, ''),
            Str::limit($selector, 180, ''),
        ]));
    }

    protected function observedState(array $payload, array $status, string $uiState): array
    {
        return array_filter([
            'ui_state' => $uiState,
            'url' => $payload['url'] ?? $payload['currentUrl'] ?? $payload['current_url'] ?? $payload['finalUrl'] ?? $payload['frameUrl'] ?? data_get($status, 'url'),
            'title' => $payload['title'] ?? $payload['currentTitle'] ?? $payload['current_title'] ?? $payload['frameTitle'] ?? data_get($status, 'title'),
            'browser_window' => $payload['browser_window'] ?? $payload['browserWindow'] ?? $payload['browser_window_name'] ?? $payload['browserWindowName'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function expectedState(array $payload, ?array $task): array
    {
        return array_filter([
            'task_key' => $payload['task_key'] ?? $payload['taskKey'] ?? $task['task_key'] ?? $task['taskKey'] ?? null,
            'selector' => $payload['selector'] ?? $payload['element_selector'] ?? $payload['input_selector'] ?? $task['selector'] ?? $task['element_selector'] ?? $task['input_selector'] ?? null,
            'browser_window' => $payload['browser_window'] ?? $payload['browserWindow'] ?? $task['browser_window'] ?? $task['browserWindow'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function selectorStatus(array $payload, string $reasonCode): array
    {
        $selector = $payload['selector'] ?? $payload['element_selector'] ?? $payload['input_selector'] ?? $payload['listSelector'] ?? $payload['list_selector'] ?? null;

        return [
            'selector' => $selector,
            'found' => $reasonCode !== 'selector_not_found' && $reasonCode !== 'mail_list_not_visible',
            'reason_code' => in_array($reasonCode, ['selector_not_found', 'mail_list_not_visible'], true) ? $reasonCode : null,
        ];
    }

    protected function message(array $payload, array $status): string
    {
        return trim((string) (
            $payload['statusMessage']
            ?? $payload['status_message']
            ?? $payload['message']
            ?? $status['message']
            ?? $status['statusMessage']
            ?? ''
        ));
    }

    protected function emptyResult(array $payload, array $mailScan): bool
    {
        if ($this->validEmptyPayload($payload)) {
            return true;
        }

        if ($mailScan !== []) {
            return ((int) ($mailScan['candidate_count'] ?? 0)) === 0
                && ((int) ($mailScan['matched_count'] ?? 0)) === 0;
        }

        foreach (['results', 'mails', 'items', 'matches'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key]) && count($payload[$key]) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function validEmptyPayload(array $payload): bool
    {
        if (($payload['ok'] ?? null) !== true) {
            return false;
        }

        foreach (['candidateCount', 'candidate_count', 'sourceCount', 'source_count', 'matchCount', 'match_count'] as $key) {
            if (array_key_exists($key, $payload) && (int) $payload[$key] === 0) {
                return true;
            }
        }

        foreach (['results', 'mails', 'items', 'matches'] as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key]) && count($payload[$key]) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function mailScanFilteredOut(array $payload): bool
    {
        $debug = $this->firstArray([
            $payload['mailListScanDebug'] ?? null,
            $payload['mail_list_scan_debug'] ?? null,
        ]);

        if ($debug === []) {
            return false;
        }

        $total = (int) ($debug['totalCandidates'] ?? $debug['total_candidates'] ?? 0);
        $accepted = (int) ($debug['acceptedCandidates'] ?? $debug['accepted_candidates'] ?? $payload['candidate_count'] ?? $payload['candidateCount'] ?? 0);

        return $total > 0 && $accepted === 0;
    }

    protected function looksLikeMailTask(array $payload): bool
    {
        $taskKey = Str::lower(trim((string) ($payload['task_key'] ?? $payload['taskKey'] ?? '')));
        $nodeScript = Str::lower(trim((string) ($payload['node_script'] ?? $payload['nodeScript'] ?? '')));

        return str_starts_with($taskKey, 'mail.')
            || str_starts_with($taskKey, 'webmail.')
            || str_contains($nodeScript, '/mail/')
            || array_key_exists('mailListScanDebug', $payload)
            || array_key_exists('mail_list_scan_debug', $payload)
            || array_key_exists('openedMails', $payload)
            || array_key_exists('opened_mails', $payload)
            || array_key_exists('matchedMail', $payload)
            || array_key_exists('matched_mail', $payload)
            || array_key_exists('candidateCount', $payload)
            || array_key_exists('candidate_count', $payload);
    }

    protected function reasonText(string $code): string
    {
        return match ($code) {
            'selector_not_found' => 'Ziel-Selector wurde im beobachteten Zustand nicht gefunden.',
            'state_not_reached' => 'Der erwartete Browser-/UI-Zustand wurde nicht erreicht.',
            'same_state_retry_blocked' => 'Retry wurde gestoppt, weil derselbe Zustand mehrfach unveraendert fehlschlug.',
            'valid_empty_result' => 'Leeres Ergebnis ist fachlich gueltig und kein technischer Fehler.',
            'mail_match_none' => 'Mail-Quelle wurde ausgewertet, aber es gab keinen passenden Treffer.',
            'mail_filter_excluded' => 'Mail-Kandidaten wurden durch Filter ausgeschlossen.',
            'mail_list_not_visible' => 'Mail-Liste oder Quelle war nicht sichtbar.',
            'session_invalid' => 'Session ist vorhanden, aber ungueltig.',
            'session_missing' => 'Session fehlt.',
            'session_expired' => 'Session ist abgelaufen.',
            'logged_out_redirect' => 'Browser wurde auf Login/Logout-Zustand umgeleitet.',
            'ui_blocked_by_consent' => 'UI wird durch Consent-/Cookie-Blocker blockiert.',
            'captcha_detected' => 'Captcha-Blockade wurde erkannt.',
            'timeout' => 'Ausfuehrung lief in ein Timeout.',
            'hard_failure' => 'Harter technischer Fehler.',
            default => 'Ausfuehrung erfolgreich.',
        };
    }

    protected function firstArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    protected function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        return null;
    }

    protected function intValue(mixed $value): int
    {
        return $value === null || $value === '' ? 0 : (int) $value;
    }

    protected function normalizedUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        return strtolower(($parts['host'] ?? '').($parts['path'] ?? ''));
    }
}
