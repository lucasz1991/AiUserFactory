<?php

namespace Tests\Unit;

use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use PHPUnit\Framework\TestCase;

class WorkflowCopilotAiUsageTrackerTest extends TestCase
{
    public function test_openrouter_usage_is_normalized_and_summarized_per_capture(): void
    {
        $tracker = new WorkflowCopilotAiUsageTracker;
        $tracker->beginCapture();
        $tracker->recordResponse(
            ['model' => 'openai/gpt-test'],
            [
                'id' => 'generation-1',
                'model' => 'openai/gpt-test-2026',
                'provider' => 'test-provider',
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 30,
                    'total_tokens' => 150,
                    'completion_tokens_details' => ['reasoning_tokens' => 7],
                    'cost' => 0.0042,
                    'cost_details' => ['upstream_inference_cost' => 0.0031],
                ],
            ],
            'data_analysis',
        );

        $records = $tracker->finishCapture();
        $summary = $tracker->summarize($records);

        $this->assertCount(1, $records);
        $this->assertSame('generation-1', $records[0]['request_id']);
        $this->assertSame('openai/gpt-test-2026', $records[0]['model']);
        $this->assertSame(120, $summary['input_tokens']);
        $this->assertSame(30, $summary['output_tokens']);
        $this->assertSame(150, $summary['total_tokens']);
        $this->assertSame(7, $summary['reasoning_tokens']);
        $this->assertSame(0.0042, $summary['cost_usd']);
        $this->assertSame(0.0031, $summary['provider_cost_usd']);
        $this->assertSame(['openai/gpt-test-2026' => 1], $summary['models']);
    }
}
