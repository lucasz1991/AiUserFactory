<?php

namespace Tests\Feature;

use App\Models\AiApiDailyBudget;
use App\Models\AiApiRequestAudit;
use App\Models\User;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\AiProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class AiApiBudgetAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_reservation_larger_than_daily_limit_is_rejected_before_provider_call(): void
    {
        config([
            'ai_api.user_daily_budget_usd' => 0.20,
            'ai_api.reservation_cost_per_1000_tokens_usd' => 0,
            'ai_api.endpoint_minimum_reservation_usd.text' => 0.30,
        ]);

        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('textWithUsage');
        $this->app->instance(AiConnectionService::class, $ai);

        Sanctum::actingAs($this->adminOnTeam(null), ['ai:use']);

        $this->postJson('/api/ai/text', ['prompt' => 'zu teuer'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'daily_ai_budget_exceeded');

        $this->assertDatabaseHas('ai_api_request_audits', [
            'status' => 'budget_rejected',
            'charged_cost_microusd' => 0,
        ]);
    }

    public function test_daily_budget_is_enforced_for_both_user_and_current_team(): void
    {
        config([
            'ai_api.user_daily_budget_usd' => 0.30,
            'ai_api.team_daily_budget_usd' => 0.60,
            'ai_api.reservation_cost_per_1000_tokens_usd' => 0,
            'ai_api.endpoint_minimum_reservation_usd.text' => 0.30,
        ]);

        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('textWithUsage')
            ->twice()
            ->andReturn(new AiProviderResult('ok'));
        $this->app->instance(AiConnectionService::class, $ai);

        $first = $this->adminOnTeam(42);
        Sanctum::actingAs($first, ['ai:use']);

        $this->postJson('/api/ai/text', ['prompt' => 'erste Reservierung'])->assertOk();
        $this->postJson('/api/ai/text', ['prompt' => 'Userbudget ueberschritten'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'daily_ai_budget_exceeded');

        $second = $this->adminOnTeam(42);
        Sanctum::actingAs($second, ['ai:use']);
        $this->postJson('/api/ai/text', ['prompt' => 'zweite Teamreservierung'])->assertOk();

        $third = $this->adminOnTeam(42);
        Sanctum::actingAs($third, ['ai:use']);
        $this->postJson('/api/ai/text', ['prompt' => 'Teambudget ueberschritten'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'daily_ai_budget_exceeded');

        $this->assertSame(
            600_000,
            (int) AiApiDailyBudget::query()
                ->where('scope_type', 'team')
                ->where('scope_id', 42)
                ->value('committed_cost_microusd'),
        );
        $this->assertSame(
            ['succeeded', 'budget_rejected', 'succeeded', 'budget_rejected'],
            AiApiRequestAudit::query()->orderBy('id')->pluck('status')->all(),
        );
    }

    public function test_audit_stores_provider_usage_and_cost_but_never_request_or_response_content(): void
    {
        $promptSecret = 'PROMPT-SECRET-42';
        $systemSecret = 'SYSTEM-SECRET-84';
        $responseSecret = 'RESPONSE-SECRET-126';

        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('textWithUsage')->once()->andReturn(new AiProviderResult(
            data: $responseSecret,
            usage: [
                'prompt_tokens' => 12,
                'completion_tokens' => 4,
                'total_tokens' => 16,
                'cost' => 0.00125,
                'cost_details' => ['upstream_inference_cost' => 0.001],
            ],
            model: 'safe/model',
            provider: 'safe-provider',
        ));
        $this->app->instance(AiConnectionService::class, $ai);

        Sanctum::actingAs($this->adminOnTeam(null), ['ai:use']);

        $response = $this->postJson('/api/ai/text', [
            'prompt' => $promptSecret,
            'system' => $systemSecret,
        ])->assertOk()->assertJsonPath('content', $responseSecret);

        $audit = AiApiRequestAudit::query()->sole();
        $serializedAudit = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertSame($response->headers->get('X-Request-ID'), $audit->request_id);
        $this->assertSame(12, $audit->input_tokens);
        $this->assertSame(4, $audit->output_tokens);
        $this->assertSame(16, $audit->total_tokens);
        $this->assertSame(1_250, $audit->provider_cost_microusd);
        $this->assertSame(1_000, $audit->provider_upstream_cost_microusd);
        $this->assertSame(1_250, $audit->charged_cost_microusd);
        $this->assertStringNotContainsString($promptSecret, $serializedAudit);
        $this->assertStringNotContainsString($systemSecret, $serializedAudit);
        $this->assertStringNotContainsString($responseSecret, $serializedAudit);
    }

    public function test_response_request_id_is_generated_server_side_and_matches_the_audit(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('textWithUsage')->once()->andReturn(new AiProviderResult('ok'));
        $this->app->instance(AiConnectionService::class, $ai);

        Sanctum::actingAs($this->adminOnTeam(null), ['ai:use']);

        $response = $this->withHeader('X-Request-ID', 'attacker-controlled-id')
            ->postJson('/api/ai/text', ['prompt' => 'request id test'])
            ->assertOk();

        $requestId = (string) $response->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid($requestId));
        $this->assertNotSame('attacker-controlled-id', $requestId);
        $this->assertDatabaseHas('ai_api_request_audits', [
            'request_id' => $requestId,
            'status' => 'succeeded',
        ]);
    }

    public function test_provider_failure_never_leaks_provider_body_into_response_audit_or_logs(): void
    {
        $promptSecret = 'provider-echo-prompt-secret';
        $systemSecret = 'provider-echo-system-secret';
        $providerSecret = 'Bearer provider-body-secret';

        config([
            'services.openrouter.api_url' => 'https://provider.example.test/chat',
            'services.openrouter.api_key' => 'server-side-api-secret',
            'services.openrouter.text_model' => 'safe/model',
        ]);

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => $providerSecret],
                'echo' => [$promptSecret, $systemSecret],
            ], 502),
        ]);

        $handler = new TestHandler;
        Log::swap(new IlluminateLogger(
            new MonologLogger('ai-api-security', [$handler]),
            app('events'),
        ));

        Sanctum::actingAs($this->adminOnTeam(null), ['ai:use']);

        $response = $this->postJson('/api/ai/text', [
            'prompt' => $promptSecret,
            'system' => $systemSecret,
        ])->assertStatus(502)
            ->assertJsonPath('error', 'ai_service_unavailable');

        $requestId = (string) $response->headers->get('X-Request-ID');
        $audit = AiApiRequestAudit::query()->where('request_id', $requestId)->sole();
        $serializedAudit = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);
        $serializedLogs = collect($handler->getRecords())
            ->map(fn ($record): string => $record->message.' '.json_encode($record->context))
            ->implode("\n");
        $responseBody = $response->getContent();

        $this->assertSame('provider_error', $audit->status);
        $this->assertSame(502, $audit->provider_status_code);
        $this->assertSame('provider_failure', $audit->error_code);

        foreach ([$promptSecret, $systemSecret, $providerSecret, 'server-side-api-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $responseBody);
            $this->assertStringNotContainsString($secret, $serializedAudit);
            $this->assertStringNotContainsString($secret, $serializedLogs);
        }
    }

    private function adminOnTeam(?int $teamId): User
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => true,
        ]);
        $user->forceFill(['current_team_id' => $teamId])->save();

        return $user->fresh();
    }
}
