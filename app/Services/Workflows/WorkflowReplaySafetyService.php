<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;

/**
 * Fail-closed replay assessment for Copilot restarts. A missing effect ledger
 * is not proof that browser tasks had no external effect.
 */
class WorkflowReplaySafetyService
{
    /** @var array<string, true> */
    private const REPLAY_SAFE_TASKS = [
        'browser.open' => true,
        'browser.open_url' => true,
        'browser.navigate_back' => true,
        'browser.navigate_forward' => true,
        'browser.reload' => true,
        'browser.wait_for_selector' => true,
        'browser.wait_for_navigation' => true,
        'browser.read_text' => true,
        'browser.read_searchengine_result' => true,
        'browser.extract_links' => true,
        'browser.extract_attribute' => true,
        'browser.page_state' => true,
        'browser.screenshot' => true,
        'browser.highlight' => true,
        'browser.open_browser_session' => true,
        'browser.open_webmail_session' => true,
        'browser.find_element' => true,
        'browser.find_inputs' => true,
        'browser.read_element_fields' => true,
        'browser.hover' => true,
        'browser.scroll' => true,
        'browser.close' => true,
        'webmail.check_session' => true,
        'webmail.read_verification_code' => true,
        'mail.inbox_list_scan' => true,
        'mail.check_address_availability' => true,
        'mail.extract_value' => true,
        'mail.fill_address' => true,
        'mail.generate_address' => true,
        'mail.generate_password' => true,
        'mail.list_search_loop' => true,
        'data.read_account_data' => true,
        'data.read_login_data' => true,
        'data.resolve_person' => true,
        'data.validate_inputs' => true,
        'data.append_to_array' => true,
        'data.workflow_return' => true,
        'wait.seconds' => true,
        'wait.selector' => true,
        'wait.status' => true,
        'loop.for_each_element' => true,
        'loop.end' => true,
        'decision.array_length' => true,
        'decision.element_exists' => true,
        'decision.variable' => true,
    ];

    /**
     * @return array{safe: bool, assessed: array<int, array<string, mixed>>, blocked: array<int, array<string, mixed>>}
     */
    public function analyze(WorkflowRun $run): array
    {
        $stepRuns = $run->relationLoaded('stepRuns')
            ? $run->stepRuns
            : $run->stepRuns()->with('workflowStep')->get();
        $assessed = [];

        foreach ($stepRuns as $stepRun) {
            $step = $stepRun->workflowStep;
            $cards = is_array($step?->task_cards) ? $step->task_cards : [];
            $result = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $taskResults = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];

            if ($taskResults !== []) {
                foreach ($taskResults as $taskResult) {
                    if (! is_array($taskResult) || $this->wasNotExecuted($taskResult)) {
                        continue;
                    }

                    $card = $this->matchingCard($cards, $taskResult);
                    $taskKey = trim((string) (
                        $taskResult['task_key']
                        ?? $taskResult['taskKey']
                        ?? $card['task_key']
                        ?? ''
                    ));
                    $assessed[] = $this->assessment(
                        $taskKey,
                        (string) ($taskResult['key'] ?? $taskResult['title'] ?? $taskKey),
                        (int) $stepRun->workflow_step_id,
                    );
                }

                continue;
            }

            if ($cards === []) {
                continue;
            }

            if (in_array((string) $stepRun->status, ['completed', 'failed'], true)) {
                foreach ($cards as $card) {
                    if (is_array($card)) {
                        $assessed[] = $this->assessment(
                            trim((string) ($card['task_key'] ?? '')),
                            trim((string) ($card['key'] ?? $card['title'] ?? '')),
                            (int) $stepRun->workflow_step_id,
                        );
                    }
                }
            } elseif (in_array((string) $stepRun->status, ['running', 'waiting'], true)
                && (filled($stepRun->external_run_id) || $result !== [])) {
                if ($this->checkpointProvesSingleCurrentTaskDidNotExecute($run, $stepRun, $cards)) {
                    continue;
                }

                // When an interrupted step has no task-level receipt, the last
                // action may have reached the provider even if its response did not.
                $assessed[] = $this->assessment('unattributed-step-execution', 'unattributed-step-execution', (int) $stepRun->workflow_step_id);
            }
        }

        $blocked = array_values(array_filter($assessed, fn (array $item): bool => $item['classification'] !== 'replay_safe'));

        return [
            'safe' => $blocked === [],
            'assessed' => $assessed,
            'blocked' => $blocked,
        ];
    }

    protected function checkpointProvesSingleCurrentTaskDidNotExecute(WorkflowRun $run, WorkflowStepRun $stepRun, array $cards): bool
    {
        if (count($cards) !== 1 || ! is_array($cards[0] ?? null)) {
            return false;
        }

        $checkpoint = is_array(data_get($run->context_json, 'copilot_checkpoint'))
            ? data_get($run->context_json, 'copilot_checkpoint')
            : [];
        $checkpointTask = trim((string) ($checkpoint['failure_task_key'] ?? $checkpoint['task_key'] ?? ''));
        $cardKey = trim((string) ($cards[0]['key'] ?? ''));

        if ($checkpointTask === '' || $cardKey === '' || $checkpointTask !== $cardKey
            || (int) ($checkpoint['workflow_step_id'] ?? 0) !== (int) $stepRun->workflow_step_id) {
            return false;
        }

        $reasonCode = strtolower(trim((string) ($checkpoint['failure_reason_code'] ?? '')));
        $message = strtolower(trim((string) data_get($checkpoint, 'result.statusMessage', '')));

        return in_array($reasonCode, ['element_not_found', 'selector_timeout', 'precondition_missing'], true)
            || str_contains($message, 'vorausgehende navigation fehlt')
            || str_contains($message, 'element nicht gefunden');
    }

    /** @return array<string, mixed> */
    public function classifyTaskKey(string $taskKey): array
    {
        return $this->assessment($taskKey, $taskKey, 0);
    }

    protected function assessment(string $taskKey, string $label, int $stepId): array
    {
        $taskKey = strtolower(trim($taskKey));
        $safe = isset(self::REPLAY_SAFE_TASKS[$taskKey]);

        return [
            'workflow_step_id' => $stepId ?: null,
            'task_key' => $taskKey !== '' ? $taskKey : null,
            'label' => $label,
            'classification' => $safe ? 'replay_safe' : 'unknown_or_external_effect',
        ];
    }

    protected function wasNotExecuted(array $taskResult): bool
    {
        return in_array(strtolower(trim((string) ($taskResult['status'] ?? ''))), [
            'queued', 'pending', 'not_started', 'not_run', 'skipped', 'cancelled_before_start',
        ], true);
    }

    protected function matchingCard(array $cards, array $taskResult): ?array
    {
        $key = trim((string) ($taskResult['key'] ?? ''));
        $taskKey = trim((string) ($taskResult['task_key'] ?? $taskResult['taskKey'] ?? ''));

        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            if (($key !== '' && (string) ($card['key'] ?? '') === $key)
                || ($taskKey !== '' && (string) ($card['task_key'] ?? '') === $taskKey)) {
                return $card;
            }
        }

        return null;
    }
}
