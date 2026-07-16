<?php

namespace App\Services\Ai;

use RuntimeException;

class WorkflowCopilotAiUsageTracker
{
    /** @var list<list<array<string, mixed>>> */
    protected array $captureStack = [];

    public function beginCapture(): void
    {
        $this->captureStack[] = [];
    }

    /** @return list<array<string, mixed>> */
    public function finishCapture(): array
    {
        if ($this->captureStack === []) {
            throw new RuntimeException('Es ist keine AI-Nutzungserfassung aktiv.');
        }

        $records = array_pop($this->captureStack);

        if ($this->captureStack !== [] && $records !== []) {
            $parentIndex = count($this->captureStack) - 1;
            $this->captureStack[$parentIndex] = [
                ...$this->captureStack[$parentIndex],
                ...$records,
            ];
        }

        return $records;
    }

    public function recordResponse(array $request, array $response, ?string $profile = null): void
    {
        if ($this->captureStack === []) {
            return;
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $inputTokens = $this->integerValue($usage, ['prompt_tokens', 'input_tokens', 'promptTokens', 'inputTokens']);
        $outputTokens = $this->integerValue($usage, ['completion_tokens', 'output_tokens', 'completionTokens', 'outputTokens']);
        $totalTokens = $this->integerValue($usage, ['total_tokens', 'totalTokens']);
        $reasoningTokens = $this->integerValue($usage, [
            'reasoning_tokens',
            'reasoningTokens',
            'completion_tokens_details.reasoning_tokens',
            'completionTokensDetails.reasoningTokens',
        ]);
        $cost = $this->numericValue($usage, ['cost', 'total_cost', 'totalCost']);
        $providerCost = $this->numericValue($usage, [
            'cost_details.upstream_inference_cost',
            'costDetails.upstreamInferenceCost',
        ]);
        $record = [
            'request_id' => trim((string) ($response['id'] ?? '')) ?: null,
            'model' => trim((string) ($response['model'] ?? $request['model'] ?? '')) ?: null,
            'provider' => trim((string) ($response['provider'] ?? '')) ?: null,
            'profile' => trim((string) $profile) ?: null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens > 0 ? $totalTokens : $inputTokens + $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'cost_usd' => round(max(0, $cost), 10),
            'provider_cost_usd' => round(max(0, $providerCost), 10),
        ];
        $captureIndex = count($this->captureStack) - 1;
        $this->captureStack[$captureIndex][] = $record;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function summarize(array $records): array
    {
        $models = [];
        $summary = [
            'ai_requests' => count($records),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => 0.0,
            'provider_cost_usd' => 0.0,
            'models' => [],
        ];

        foreach ($records as $record) {
            foreach (['input_tokens', 'output_tokens', 'total_tokens', 'reasoning_tokens'] as $field) {
                $summary[$field] += max(0, (int) ($record[$field] ?? 0));
            }

            foreach (['cost_usd', 'provider_cost_usd'] as $field) {
                $summary[$field] += max(0, (float) ($record[$field] ?? 0));
            }

            $model = trim((string) ($record['model'] ?? ''));

            if ($model !== '') {
                $models[$model] = ($models[$model] ?? 0) + 1;
            }
        }

        $summary['cost_usd'] = round($summary['cost_usd'], 10);
        $summary['provider_cost_usd'] = round($summary['provider_cost_usd'], 10);
        $summary['models'] = $models;

        return $summary;
    }

    protected function integerValue(array $usage, array $keys): int
    {
        foreach ($keys as $key) {
            $value = data_get($usage, $key);

            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return 0;
    }

    protected function numericValue(array $usage, array $keys): float
    {
        foreach ($keys as $key) {
            $value = data_get($usage, $key);

            if (is_numeric($value)) {
                return max(0, (float) $value);
            }
        }

        return 0.0;
    }
}
