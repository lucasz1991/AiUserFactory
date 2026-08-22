<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\AiProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AiApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_api_requires_an_active_administrator(): void
    {
        $this->postJson('/api/ai/text', ['prompt' => 'Hallo'])
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'staff', 'status' => true]), ['ai:use']);
        $this->postJson('/api/ai/text', ['prompt' => 'Hallo'])
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => false]), ['ai:use']);
        $this->postJson('/api/ai/text', ['prompt' => 'Hallo'])
            ->assertForbidden();
    }

    public function test_personal_access_token_requires_the_ai_ability(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $token = $admin->createToken('without-ai', ['read'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/ai/text', ['prompt' => 'Hallo'])
            ->assertForbidden();
    }

    public function test_typed_endpoint_forwards_only_bounded_allowlisted_options(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        Sanctum::actingAs($admin, ['ai:use']);

        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('textWithUsage')
            ->once()
            ->with('Kurze Antwort', 'Nur Fakten', [
                'temperature' => 0.2,
                'max_completion_tokens' => 300,
            ])
            ->andReturn(new AiProviderResult('Erledigt'));
        $this->app->instance(AiConnectionService::class, $ai);

        $this->postJson('/api/ai/text', [
            'prompt' => 'Kurze Antwort',
            'system' => 'Nur Fakten',
            'options' => [
                'temperature' => 0.2,
                'max_completion_tokens' => 300,
            ],
        ])->assertOk()->assertJsonPath('content', 'Erledigt');
    }

    public function test_model_timeout_and_arbitrary_payload_overrides_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => true]), ['ai:use']);

        $this->postJson('/api/ai/text', [
            'prompt' => 'Hallo',
            'options' => [
                'model' => 'attacker/expensive-model',
                '_timeout' => 0,
                'max_completion_tokens' => 2001,
            ],
            'payload' => ['messages' => []],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['options', 'options.max_completion_tokens', 'payload']);
    }

    public function test_generic_send_and_unbounded_stream_proxy_routes_do_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => true]), ['ai:use']);

        $this->postJson('/api/ai/send', ['payload' => ['model' => 'arbitrary']])->assertNotFound();
        $this->postJson('/api/ai/stream', ['payload' => ['model' => 'arbitrary']])->assertNotFound();
    }

    public function test_ai_proxy_has_a_per_actor_rate_limit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => true]), ['ai:use']);

        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('textWithUsage')->times(10)->andReturn(new AiProviderResult('ok'));
        $this->app->instance(AiConnectionService::class, $ai);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/ai/text', ['prompt' => 'Versuch '.$attempt])->assertOk();
        }

        $this->postJson('/api/ai/text', ['prompt' => 'Zu viel'])->assertTooManyRequests();
    }
}
