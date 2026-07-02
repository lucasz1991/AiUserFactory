<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NetworkNode;
use App\Services\ClientController\NetworkJobDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientControllerNetworkJobDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_node_job_with_a_canonical_hmac_signature(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Test node',
            'node_uuid' => 'node-test-1',
            'api_key' => 'node-secret-api-key',
            'status' => 'active',
        ]);
        $payload = ['z' => 1, 'nested' => ['b' => 2, 'a' => 1]];

        $job = app(NetworkJobDispatcher::class)->dispatch($node, 'workflow_task', $payload);

        $canonical = '{"nested":{"a":1,"b":2},"z":1}';
        $this->assertSame(hash_hmac('sha256', $canonical, $node->api_key), $job->signature);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->device_id);
    }

    public function test_it_rejects_a_device_from_another_node(): void
    {
        $first = NetworkNode::query()->create([
            'name' => 'First',
            'node_uuid' => 'node-test-2',
            'api_key' => 'first-key',
            'status' => 'active',
        ]);
        $second = NetworkNode::query()->create([
            'name' => 'Second',
            'node_uuid' => 'node-test-3',
            'api_key' => 'second-key',
            'status' => 'active',
        ]);
        $device = Device::query()->create([
            'network_node_id' => $second->id,
            'name' => 'Other device',
            'platform' => 'android',
            'device_uuid' => 'device-test-1',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(NetworkJobDispatcher::class)->dispatch($first, 'workflow_task', [], $device);
    }
}
