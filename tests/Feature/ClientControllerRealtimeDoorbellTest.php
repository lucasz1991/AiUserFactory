<?php

namespace Tests\Feature;

use App\Events\NetworkJobDoorbell;
use App\Models\NetworkJob;
use App\Services\ClientController\NetworkJobDoorbellService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesNetworkNodes;
use Tests\TestCase;

class ClientControllerRealtimeDoorbellTest extends TestCase
{
    use CreatesNetworkNodes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'client_controller.realtime.enabled' => true,
            'client_controller.realtime.app_key' => 'public-reverb-key',
            'client_controller.realtime.app_secret' => 'private-reverb-secret',
            'client_controller.realtime.host' => 'factory.example.test',
            'client_controller.realtime.port' => 443,
            'client_controller.realtime.scheme' => 'https',
            'client_controller.realtime.path' => '',
        ]);
    }

    public function test_node_can_fetch_only_its_private_realtime_contract_and_authorize_that_channel(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Realtime node',
            'node_uuid' => 'realtime-node-1',
            'status' => 'active',
        ]);
        $apiKey = $this->networkNodeApiKey($node);

        $this->getJson('/api/client-controller/realtime/config')->assertUnauthorized();
        $this->withHeader('X-NODE-API-KEY', '')
            ->getJson('/api/client-controller/realtime/config')
            ->assertUnauthorized();

        $config = $this->withHeader('X-NODE-API-KEY', $apiKey)
            ->getJson('/api/client-controller/realtime/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('event', 'client-controller.job-poll')
            ->assertJsonPath('watchdog_seconds', 30);
        $channel = (string) $config->json('channel');
        $websocketUrl = (string) $config->json('websocket_url');

        $this->assertStringStartsWith('private-client-controller.node.', $channel);
        $this->assertSame(40, strlen(str($channel)->afterLast('.')));
        $this->assertStringStartsWith('wss://factory.example.test/app/public-reverb-key?', $websocketUrl);
        $this->assertStringNotContainsString('private-reverb-secret', $config->getContent());
        $this->assertStringNotContainsString($apiKey, $config->getContent());

        $this->withHeader('X-NODE-API-KEY', $apiKey)
            ->postJson('/api/client-controller/realtime/auth', [
                'socket_id' => '123.456',
                'channel' => 'private-client-controller.node.wrong',
            ])
            ->assertForbidden();

        $expected = 'public-reverb-key:'.hash_hmac('sha256', '123.456:'.$channel, 'private-reverb-secret');
        $this->withHeader('X-NODE-API-KEY', $apiKey)
            ->postJson('/api/client-controller/realtime/auth', [
                'socket_id' => '123.456',
                'channel' => $channel,
            ])
            ->assertOk()
            ->assertExactJson(['auth' => $expected]);
    }

    public function test_doorbell_contains_no_job_payload_and_records_the_signal_timestamp(): void
    {
        Event::fake([NetworkJobDoorbell::class]);
        $node = $this->createNetworkNode([
            'name' => 'Doorbell node',
            'node_uuid' => 'doorbell-node-1',
            'status' => 'active',
        ]);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) str()->uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_run',
            'payload_json' => ['secret' => 'must-not-be-broadcast'],
            'signature' => 'private-signature',
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        $sent = app(NetworkJobDoorbellService::class)->signal($job);

        $this->assertTrue($sent);
        $this->assertNotNull($job->fresh()->signaled_at);
        Event::assertDispatched(NetworkJobDoorbell::class, function (NetworkJobDoorbell $event) use ($node): bool {
            $serialized = json_encode($event->broadcastWith(), JSON_UNESCAPED_SLASHES);

            return $event->nodeUuid === $node->node_uuid
                && $event->reason === 'job_available'
                && ! str_contains($serialized, 'must-not-be-broadcast')
                && ! str_contains($serialized, 'private-signature');
        });
    }

    public function test_disabled_realtime_keeps_polling_fallback_without_claiming_a_signal(): void
    {
        Event::fake([NetworkJobDoorbell::class]);
        config(['client_controller.realtime.enabled' => false]);
        $node = $this->createNetworkNode([
            'name' => 'Polling node',
            'node_uuid' => 'polling-node-1',
            'status' => 'active',
        ]);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) str()->uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_run',
            'payload_json' => [],
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        $this->assertFalse(app(NetworkJobDoorbellService::class)->signal($job));
        $this->assertNull($job->fresh()->signaled_at);
        Event::assertNotDispatched(NetworkJobDoorbell::class);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->getJson('/api/client-controller/realtime/config')
            ->assertOk()
            ->assertExactJson(['enabled' => false, 'watchdog_seconds' => 30]);
    }
}
