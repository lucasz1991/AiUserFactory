<?php

namespace App\Services\Ai;

use App\Models\AiApiDailyBudget;
use App\Models\AiApiRequestAudit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AiApiRequestService
{
    public const REQUEST_ID_HEADER = 'X-Request-ID';

    public function __construct(
        private readonly AiConnectionService $ai,
    ) {}

    public function text(
        User $user,
        string $prompt,
        ?string $system,
        array $options,
    ): AiApiExecutionResult {
        return $this->execute(
            user: $user,
            endpoint: 'text',
            profile: 'text',
            reservedTokens: $this->reservedTokens($prompt, $system, $options),
            providerCall: function () use ($prompt, $system, $options): AiProviderResult {
                $provider = $this->ai->textWithUsage($prompt, $system, $options);

                return new AiProviderResult(
                    data: ['content' => (string) $provider->data],
                    usage: $provider->usage,
                    model: $provider->model,
                    provider: $provider->provider,
                );
            },
        );
    }

    public function json(
        User $user,
        string $prompt,
        ?string $system,
        array $options,
    ): AiApiExecutionResult {
        return $this->execute(
            user: $user,
            endpoint: 'json',
            profile: 'data',
            reservedTokens: $this->reservedTokens($prompt, $system, $options),
            providerCall: fn (): AiProviderResult => $this->ai->jsonWithUsage($prompt, $system, $options),
        );
    }

    public function imageGeneration(
        User $user,
        string $prompt,
        array $options,
    ): AiApiExecutionResult {
        return $this->execute(
            user: $user,
            endpoint: 'image_generation',
            profile: 'image_generation',
            reservedTokens: $this->reservedTokens($prompt, null, $options),
            providerCall: function () use ($prompt, $options): AiProviderResult {
                $provider = $this->ai->imageGenerationWithUsage($prompt, $options);
                $response = is_array($provider->data) ? $provider->data : [];

                return new AiProviderResult(
                    data: [
                        'response' => $response,
                        'images' => $this->ai->generatedImageUrls($response),
                    ],
                    usage: $provider->usage,
                    model: $provider->model,
                    provider: $provider->provider,
                );
            },
        );
    }

    private function execute(
        User $user,
        string $endpoint,
        string $profile,
        int $reservedTokens,
        Closure $providerCall,
    ): AiApiExecutionResult {
        // Eingehende IDs werden nie uebernommen: Korrelation und Audit-ID
        // entstehen ausschliesslich serverseitig.
        $requestId = (string) Str::uuid();
        $startedAt = microtime(true);
        $reservationCost = $this->reservationCostMicrousd($endpoint, $reservedTokens);

        try {
            $audit = $this->reserveBudget(
                requestId: $requestId,
                user: $user,
                endpoint: $endpoint,
                profile: $profile,
                reservedTokens: $reservedTokens,
                reservationCost: $reservationCost,
            );
        } catch (Throwable $exception) {
            $this->logFailure($requestId, $endpoint, $user, $exception, 'budget_reservation_failed');

            throw new AiApiRequestFailedException($requestId, 503);
        }

        if ($audit->status === 'budget_rejected') {
            throw new AiApiBudgetExceededException($requestId);
        }

        try {
            $provider = $providerCall();

            if (! $provider instanceof AiProviderResult) {
                throw new AiProviderException;
            }
        } catch (Throwable $exception) {
            $providerStatus = $exception instanceof AiProviderException
                ? $exception->providerStatusCode
                : null;

            $this->finalizeFailureSafely($audit, $startedAt, $providerStatus);
            $this->logFailure($requestId, $endpoint, $user, $exception, 'provider_failure', $providerStatus);

            throw new AiApiRequestFailedException($requestId);
        }

        try {
            $this->finalizeSuccess($audit, $provider, $startedAt);
        } catch (Throwable $exception) {
            // Providerkosten sind bereits entstanden. Die konservative
            // Reservierung bleibt bestehen und die Antwort wird fail-closed.
            $this->logFailure($requestId, $endpoint, $user, $exception, 'audit_finalize_failed');

            throw new AiApiRequestFailedException($requestId, 503);
        }

        return new AiApiExecutionResult($requestId, $provider->data);
    }

    private function reserveBudget(
        string $requestId,
        User $user,
        string $endpoint,
        string $profile,
        int $reservedTokens,
        int $reservationCost,
    ): AiApiRequestAudit {
        $usageDate = now('UTC')->toDateString();
        $teamId = $this->teamId($user);
        $scopes = $this->budgetScopes($user, $teamId);

        return DB::transaction(function () use (
            $requestId,
            $user,
            $teamId,
            $usageDate,
            $endpoint,
            $profile,
            $reservedTokens,
            $reservationCost,
            $scopes,
        ): AiApiRequestAudit {
            $budgets = [];
            $timestamp = now();

            foreach ($scopes as $scope) {
                AiApiDailyBudget::query()->insertOrIgnore([
                    'usage_date' => $usageDate,
                    'scope_type' => $scope['type'],
                    'scope_id' => $scope['id'],
                    'committed_cost_microusd' => 0,
                    'reserved_tokens' => 0,
                    'request_count' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $budgets[] = [
                    'scope' => $scope,
                    'budget' => AiApiDailyBudget::query()
                        ->where('usage_date', $usageDate)
                        ->where('scope_type', $scope['type'])
                        ->where('scope_id', $scope['id'])
                        ->lockForUpdate()
                        ->firstOrFail(),
                ];
            }

            $rejected = false;

            foreach ($budgets as $entry) {
                $committed = (int) $entry['budget']->committed_cost_microusd;
                $limit = (int) $entry['scope']['limit'];

                if ($reservationCost > $limit || $committed > $limit - $reservationCost) {
                    $rejected = true;
                    break;
                }
            }

            if (! $rejected) {
                foreach ($budgets as $entry) {
                    /** @var AiApiDailyBudget $budget */
                    $budget = $entry['budget'];
                    $budget->forceFill([
                        'committed_cost_microusd' => (int) $budget->committed_cost_microusd + $reservationCost,
                        'reserved_tokens' => (int) $budget->reserved_tokens + $reservedTokens,
                        'request_count' => (int) $budget->request_count + 1,
                    ])->save();
                }
            }

            return AiApiRequestAudit::query()->create([
                'request_id' => $requestId,
                'user_id' => $user->getKey(),
                'team_id' => $teamId,
                'usage_date' => $usageDate,
                'endpoint' => $endpoint,
                'profile' => $profile,
                'status' => $rejected ? 'budget_rejected' : 'reserved',
                'error_code' => $rejected ? 'daily_budget_exceeded' : null,
                'reserved_tokens' => $reservedTokens,
                'reservation_cost_microusd' => $reservationCost,
                'charged_cost_microusd' => $rejected ? 0 : $reservationCost,
                'completed_at' => $rejected ? now() : null,
            ]);
        }, 3);
    }

    private function finalizeSuccess(
        AiApiRequestAudit $audit,
        AiProviderResult $provider,
        float $startedAt,
    ): void {
        $usage = $this->normalizeUsage($provider->usage);
        $providerCost = $this->costMicrousd($provider->usage, ['cost', 'total_cost', 'totalCost']);
        $upstreamCost = $this->costMicrousd($provider->usage, [
            'cost_details.upstream_inference_cost',
            'costDetails.upstreamInferenceCost',
        ]);
        $chargedCost = $providerCost ?? $upstreamCost ?? (int) $audit->reservation_cost_microusd;
        $chargedTokens = ($usage['total_tokens'] ?? 0) > 0
            ? (int) $usage['total_tokens']
            : (int) $audit->reserved_tokens;

        DB::transaction(function () use (
            $audit,
            $provider,
            $usage,
            $providerCost,
            $upstreamCost,
            $chargedCost,
            $chargedTokens,
            $startedAt,
        ): void {
            $lockedAudit = AiApiRequestAudit::query()->lockForUpdate()->findOrFail($audit->id);

            foreach ($this->budgetScopesFromAudit($lockedAudit) as $scope) {
                $budget = AiApiDailyBudget::query()
                    ->where('usage_date', $lockedAudit->usage_date->toDateString())
                    ->where('scope_type', $scope['type'])
                    ->where('scope_id', $scope['id'])
                    ->lockForUpdate()
                    ->first();

                if (! $budget) {
                    continue;
                }

                $budget->forceFill([
                    'committed_cost_microusd' => max(
                        0,
                        (int) $budget->committed_cost_microusd
                            - (int) $lockedAudit->reservation_cost_microusd
                            + $chargedCost,
                    ),
                    'reserved_tokens' => max(
                        0,
                        (int) $budget->reserved_tokens
                            - (int) $lockedAudit->reserved_tokens
                            + $chargedTokens,
                    ),
                ])->save();
            }

            $lockedAudit->forceFill([
                'status' => 'succeeded',
                'model' => $this->shortMetadata($provider->model, 191),
                'provider' => $this->shortMetadata($provider->provider, 120),
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'charged_cost_microusd' => $chargedCost,
                'provider_cost_microusd' => $providerCost,
                'provider_upstream_cost_microusd' => $upstreamCost,
                'duration_ms' => $this->durationMs($startedAt),
                'completed_at' => now(),
            ])->save();
        }, 3);
    }

    private function finalizeFailureSafely(
        AiApiRequestAudit $audit,
        float $startedAt,
        ?int $providerStatus,
    ): void {
        try {
            $audit->forceFill([
                'status' => 'provider_error',
                'provider_status_code' => $this->safeStatusCode($providerStatus),
                'error_code' => 'provider_failure',
                'duration_ms' => $this->durationMs($startedAt),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('Typed AI API audit failure could not be finalized.', [
                'request_id' => $audit->request_id,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /** @return list<array{type: string, id: int, limit: int}> */
    private function budgetScopes(User $user, ?int $teamId): array
    {
        if (! (bool) config('ai_api.budget_enabled', true)) {
            return [];
        }

        $scopes = [[
            'type' => 'user',
            'id' => (int) $user->getKey(),
            'limit' => $this->usdToMicrousd(config('ai_api.user_daily_budget_usd', 10.0), false),
        ]];

        if ($teamId !== null) {
            $scopes[] = [
                'type' => 'team',
                'id' => $teamId,
                'limit' => $this->usdToMicrousd(config('ai_api.team_daily_budget_usd', 50.0), false),
            ];
        }

        return $scopes;
    }

    /** @return list<array{type: string, id: int}> */
    private function budgetScopesFromAudit(AiApiRequestAudit $audit): array
    {
        if (! (bool) config('ai_api.budget_enabled', true)) {
            return [];
        }

        $scopes = [];

        if ($audit->user_id !== null) {
            $scopes[] = ['type' => 'user', 'id' => (int) $audit->user_id];
        }

        if ($audit->team_id !== null) {
            $scopes[] = ['type' => 'team', 'id' => (int) $audit->team_id];
        }

        return $scopes;
    }

    private function reservedTokens(string $prompt, ?string $system, array $options): int
    {
        $maxCompletionTokens = max(1, min(2000, (int) ($options['max_completion_tokens'] ?? 2000)));

        // Bytes statt Wortanzahl sind absichtlich konservativ und speichern
        // weder Prompt noch Systemtext.
        $estimatedInputTokens = max(1, strlen($prompt) + strlen((string) $system));

        return $estimatedInputTokens + $maxCompletionTokens;
    }

    private function reservationCostMicrousd(string $endpoint, int $reservedTokens): int
    {
        $minimums = config('ai_api.endpoint_minimum_reservation_usd', []);
        $minimumUsd = is_array($minimums) ? ($minimums[$endpoint] ?? 0.25) : 0.25;
        $minimum = $this->usdToMicrousd($minimumUsd);
        $perThousand = $this->usdToMicrousd(
            config('ai_api.reservation_cost_per_1000_tokens_usd', 0.05)
        );
        $tokenReservation = (int) ceil(($reservedTokens / 1000) * $perThousand);

        return max(1, $minimum, $tokenReservation);
    }

    /** @return array{input_tokens: ?int, output_tokens: ?int, total_tokens: ?int} */
    private function normalizeUsage(array $usage): array
    {
        $input = $this->usageInteger($usage, ['prompt_tokens', 'input_tokens', 'promptTokens', 'inputTokens']);
        $output = $this->usageInteger($usage, ['completion_tokens', 'output_tokens', 'completionTokens', 'outputTokens']);
        $total = $this->usageInteger($usage, ['total_tokens', 'totalTokens']);

        if (($total ?? 0) <= 0 && (($input ?? 0) > 0 || ($output ?? 0) > 0)) {
            $total = (int) ($input ?? 0) + (int) ($output ?? 0);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ];
    }

    private function usageInteger(array $usage, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($usage, $key);

            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    private function costMicrousd(array $usage, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($usage, $key);

            if (! is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            return $this->usdToMicrousd($value);
        }

        return null;
    }

    private function usdToMicrousd(mixed $usd, bool $roundUp = true): int
    {
        $value = is_numeric($usd) ? max(0, (float) $usd) * 1_000_000 : 0.0;

        return (int) ($roundUp ? ceil($value) : floor($value));
    }

    private function teamId(User $user): ?int
    {
        $teamId = $user->getAttribute('current_team_id');

        return is_numeric($teamId) && (int) $teamId > 0 ? (int) $teamId : null;
    }

    private function shortMetadata(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function safeStatusCode(?int $status): ?int
    {
        return $status !== null && $status >= 100 && $status <= 599 ? $status : null;
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, min(4_294_967_295, (int) round((microtime(true) - $startedAt) * 1000)));
    }

    private function logFailure(
        string $requestId,
        string $endpoint,
        User $user,
        Throwable $exception,
        string $errorCode,
        ?int $providerStatus = null,
    ): void {
        Log::warning('Typed AI API request failed.', array_filter([
            'request_id' => $requestId,
            'user_id' => $user->getKey(),
            'team_id' => $this->teamId($user),
            'endpoint' => $endpoint,
            'error_code' => $errorCode,
            'provider_status_code' => $this->safeStatusCode($providerStatus),
            // Keine Exception-Message: Provider-Bodies koennen Prompts,
            // Antworten oder Secrets spiegeln.
            'exception_class' => $exception::class,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
