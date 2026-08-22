<?php

namespace Tests\Feature;

use App\Models\NetworkNode;
use App\Models\NodeCredentialEvent;
use App\Models\NodeEnrollmentToken;
use App\Models\Setting;
use App\Services\ClientController\NetworkNodeCredentialService;
use App\Services\ClientController\NodeEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesNetworkNodes;
use Tests\TestCase;

class ClientControllerCredentialSecurityTest extends TestCase
{
    use CreatesNetworkNodes;
    use RefreshDatabase;

    public function test_legacy_default_and_body_only_credentials_fail_closed(): void
    {
        Setting::setValue('client_controller', 'security', [
            'bootstrap_api_key' => 'followflow-default-node-key-change-me',
        ]);

        $this->postJson('/api/client-controller/register-node', [
            'name' => 'Unsafe node',
            'node_uuid' => 'unsafe-node',
            'bootstrap_api_key' => 'followflow-default-node-key-change-me',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bootstrap_api_key');

        $token = app(NodeEnrollmentService::class)->issue(null, null, 'body-only-test');

        $this->postJson('/api/client-controller/register-node', [
            'name' => 'Body-only node',
            'node_uuid' => 'body-only-node',
            'bootstrap_api_key' => $token,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('bootstrap_api_key');

        $this->assertNull(NodeEnrollmentToken::query()->firstOrFail()->consumed_at);
        $this->assertSame(2, NodeCredentialEvent::query()
            ->where('event_type', 'enrollment.rejected')
            ->where('outcome', 'rejected')
            ->count());
        $serializedEvents = NodeCredentialEvent::query()
            ->orderBy('id')
            ->get()
            ->toJson();
        $this->assertStringNotContainsString('followflow-default-node-key-change-me', $serializedEvents);
        $this->assertStringNotContainsString($token, $serializedEvents);
    }

    public function test_one_time_enrollment_returns_a_new_key_once_and_stores_only_hash_and_ciphertext(): void
    {
        $token = app(NodeEnrollmentService::class)->issue(null, null, 'new-node-test');

        $response = $this->withHeader('X-BOOTSTRAP-API-KEY', $token)
            ->postJson('/api/client-controller/register-node', [
                'name' => 'Secure node',
                'node_uuid' => 'secure-node-1',
                'version' => '1.0.0',
                'os' => 'windows',
                'current_server_domain' => 'https://factory.example.test',
            ])
            ->assertOk()
            ->assertJsonPath('node.name', 'Secure node');

        $plainTextApiKey = (string) $response->json('node.api_key');
        $node = NetworkNode::query()->where('node_uuid', 'secure-node-1')->firstOrFail();

        $this->assertStringStartsWith('ffn_', $plainTextApiKey);
        $this->assertSame(hash('sha256', $plainTextApiKey), $node->getRawOriginal('api_key'));
        $this->assertSame(hash('sha256', $plainTextApiKey), $node->api_key_hash);
        $this->assertNotSame($plainTextApiKey, $node->getRawOriginal('signing_secret_encrypted'));
        $this->assertSame($plainTextApiKey, $node->signing_secret_encrypted);
        $this->assertNotNull(NodeEnrollmentToken::query()->firstOrFail()->consumed_at);
        $this->assertDatabaseHas('node_credential_events', [
            'network_node_id' => $node->id,
            'event_type' => 'enrollment.consumed',
            'outcome' => 'success',
        ]);

        $this->withHeader('X-BOOTSTRAP-API-KEY', $token)
            ->postJson('/api/client-controller/register-node', [
                'name' => 'Secure node',
                'node_uuid' => 'secure-node-1',
            ])
            ->assertUnauthorized()
            ->assertJsonMissing(['api_key' => $plainTextApiKey]);
    }

    public function test_unbound_token_cannot_take_over_an_existing_node_but_bound_token_rotates_it(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Existing node',
            'node_uuid' => 'existing-secure-node',
            'status' => 'active',
        ]);
        $oldKey = $this->networkNodeApiKey($node);

        $unboundToken = app(NodeEnrollmentService::class)->issue(null, null, 'unbound-takeover-test');
        $this->withHeader('X-BOOTSTRAP-API-KEY', $unboundToken)
            ->postJson('/api/client-controller/register-node', [
                'name' => 'Attacker name',
                'node_uuid' => $node->node_uuid,
            ])
            ->assertStatus(409);

        $boundToken = app(NodeEnrollmentService::class)->issue($node, null, 'bound-rotation-test');
        $response = $this->withHeader('X-BOOTSTRAP-API-KEY', $boundToken)
            ->postJson('/api/client-controller/register-node', [
                'name' => 'Client supplied name',
                'node_uuid' => $node->node_uuid,
            ])
            ->assertOk()
            ->assertJsonPath('node.name', 'Existing node');

        $this->assertNotSame($oldKey, (string) $response->json('node.api_key'));
    }

    public function test_node_api_key_is_accepted_only_from_header_and_revocation_is_immediate(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Header node',
            'node_uuid' => 'header-node',
            'status' => 'active',
        ]);
        $key = $this->networkNodeApiKey($node);

        $this->postJson('/api/client-controller/heartbeat', [
            'api_key' => $key,
            'status' => 'online',
        ])->assertUnauthorized();

        $this->withHeader('X-NODE-API-KEY', $key)
            ->postJson('/api/client-controller/heartbeat', ['status' => 'online'])
            ->assertOk();

        app(NetworkNodeCredentialService::class)->revoke($node, null, 'test-revocation');

        $this->withHeader('X-NODE-API-KEY', $key)
            ->postJson('/api/client-controller/heartbeat', ['status' => 'online'])
            ->assertUnauthorized();
    }

    public function test_expired_enrollment_token_is_rejected(): void
    {
        $token = app(NodeEnrollmentService::class)->issue(null, null, 'expired-test');
        NodeEnrollmentToken::query()->update(['expires_at' => now()->subSecond()]);

        $this->withHeader('X-NODE-ENROLLMENT-TOKEN', $token)
            ->postJson('/api/client-controller/register-node', [
                'name' => 'Expired node',
                'node_uuid' => 'expired-node',
            ])
            ->assertUnauthorized();
    }

    public function test_rebind_requires_server_and_node_approval_plus_a_valid_short_lived_signature(): void
    {
        Setting::setValue('client_controller', 'server', ['allow_server_rebind' => true]);
        $node = $this->createNetworkNode([
            'name' => 'Rebind node',
            'node_uuid' => 'rebind-node',
            'status' => 'active',
            'allow_server_rebind' => true,
            'current_server_domain' => 'https://old.example.test',
        ]);
        $key = $this->networkNodeApiKey($node);
        $expiresAt = Carbon::now()->addMinutes(2)->startOfSecond();
        $newDomain = 'https://new.example.test';
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$node->node_uuid, $newDomain, $expiresAt->toIso8601String()]),
            $key,
        );

        $this->withHeader('X-NODE-API-KEY', $key)
            ->postJson('/api/client-controller/rebind', [
                'new_server_domain' => $newDomain,
                'expires_at' => $expiresAt->toIso8601String(),
                'signature' => str_repeat('0', 64),
            ])
            ->assertUnauthorized();

        $this->withHeader('X-NODE-API-KEY', $key)
            ->postJson('/api/client-controller/rebind', [
                'new_server_domain' => $newDomain,
                'expires_at' => $expiresAt->toIso8601String(),
                'signature' => $signature,
            ])
            ->assertOk()
            ->assertJsonPath('current_server_domain', $newDomain);

        $node->forceFill(['allow_server_rebind' => false])->save();
        $this->withHeader('X-NODE-API-KEY', $key)
            ->postJson('/api/client-controller/rebind', [
                'new_server_domain' => 'https://forced.example.test',
                'expires_at' => $expiresAt->toIso8601String(),
                'signature' => $signature,
                'force' => true,
            ])
            ->assertUnprocessable();
    }
}
