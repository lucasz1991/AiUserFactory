<?php

namespace Tests\Unit;

use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowResultNormalizer;
use PHPUnit\Framework\TestCase;

class WorkflowResultNormalizerTest extends TestCase
{
    public function test_empty_mail_scan_is_valid_business_empty_not_technical_failure(): void
    {
        $normalized = $this->normalize([
            'ok' => false,
            'status' => 'failed',
            'task_key' => 'webmail.scan_mail_list',
            'mail_list_scan_debug' => [
                'totalCandidates' => 0,
                'acceptedCandidates' => 0,
                'pollCount' => 3,
            ],
            'candidateCount' => 0,
            'matchCount' => 0,
        ], ['state' => 'failed']);

        $this->assertSame('success', $normalized['technical_status']);
        $this->assertSame('valid_empty', $normalized['business_status']);
        $this->assertSame('valid_empty_result', $normalized['result_class']);
        $this->assertSame('valid_empty_result', $normalized['diagnostic_reason_code']);
        $this->assertFalse($normalized['retryable']);
    }

    public function test_missing_mail_match_is_no_match_not_hard_failure(): void
    {
        $normalized = $this->normalize([
            'ok' => false,
            'status' => 'failed',
            'task_key' => 'webmail.scan_mail_list',
            'statusMessage' => 'Keine passende Mail gefunden.',
            'mailListScanDebug' => [
                'totalCandidates' => 4,
                'acceptedCandidates' => 4,
                'pollCount' => 2,
            ],
            'candidateCount' => 4,
            'matchCount' => 0,
        ], ['state' => 'failed']);

        $this->assertSame('success', $normalized['technical_status']);
        $this->assertSame('no_match', $normalized['business_status']);
        $this->assertSame('mail_match_none', $normalized['result_class']);
        $this->assertSame('mail_match_none', $normalized['diagnostic_reason_code']);
        $this->assertFalse($normalized['retryable']);
    }

    public function test_embedded_workflow_tasks_are_classified(): void
    {
        $result = $this->normalizer()->normalizeStepResult($this->step(), [], [
            'ok' => true,
            'status' => 'success',
            'tasks' => [[
                'ok' => true,
                'status' => 'success',
                'task_key' => 'data.collect',
                'parent_task_key' => 'child-workflow',
                'embedded_workflow_id' => 25,
                'embedded_workflow_name' => 'Child Workflow',
                'results' => [],
            ]],
        ], 'workflow-task');

        $embedded = $result['normalized_result']['embedded_workflows'][0] ?? [];

        $this->assertSame('embedded_valid_empty', $embedded['embedded_class'] ?? null);
        $this->assertSame('Child Workflow', $embedded['embedded_workflow_name'] ?? null);
        $this->assertSame(1, $embedded['task_count'] ?? null);
    }

    public function test_failed_task_downgrade_replaces_success_diagnostic_reason(): void
    {
        $normalized = $this->normalize([
            'ok' => true,
            'status' => 'success',
            'tasks' => [[
                'ok' => false,
                'status' => 'failed',
                'task_key' => 'browser.click',
                'statusMessage' => 'Kein klickbares Ziel uebergeben oder gefunden.',
                'selector' => 'button:has-text("Login")',
            ]],
        ], ['state' => 'completed']);

        $this->assertSame('failed', $normalized['technical_status']);
        $this->assertNotSame('success', $normalized['diagnostic_reason_code']);
        $this->assertNotSame('Ausfuehrung erfolgreich.', $normalized['diagnostic_reason']);
    }

    protected function normalize(array $result, array $status = []): array
    {
        $result = $this->normalizer()->normalizeStepResult($this->step(), $status, $result, 'workflow-task');

        return $result['normalized_result'];
    }

    protected function normalizer(): WorkflowResultNormalizer
    {
        return new WorkflowResultNormalizer;
    }

    protected function step(): WorkflowStep
    {
        return (new WorkflowStep)->forceFill([
            'id' => 123,
            'name' => 'Mail scan',
            'type' => 'task_list',
            'action_key' => 'mail-scan',
            'position' => 1,
        ]);
    }
}
