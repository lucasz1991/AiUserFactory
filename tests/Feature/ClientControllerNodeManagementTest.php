<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClientController\NodeDetail;
use App\Models\NetworkNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientControllerNodeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reregistration_returns_the_current_key_and_preserves_the_admin_name(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Büro-Node Berlin',
            'node_uuid' => 'node-reregister-1',
            'api_key' => 'current-server-api-key',
            'status' => 'active',
        ]);

        $response = $this->withHeaders([
            'X-BOOTSTRAP-API-KEY' => 'followflow-default-node-key-change-me',
        ])->postJson('/api/client-controller/register-node', [
            'name' => 'ClientNode-node-reregister-1',
            'node_uuid' => $node->node_uuid,
            'version' => '0.1.0',
            'os' => 'windows',
            'current_server_domain' => 'https://factory.example.test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('node.api_key', 'current-server-api-key')
            ->assertJsonPath('node.name', 'Büro-Node Berlin');

        $this->assertSame('127.0.0.1', $node->fresh()->public_ip);
        $this->assertTrue($node->fresh()->is_online);
    }

    public function test_stale_heartbeat_marks_a_node_offline(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Stale node',
            'node_uuid' => 'node-stale-1',
            'api_key' => 'stale-node-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->assertSame(1, NetworkNode::expireStale());
        $this->assertFalse($node->fresh()->is_online);
        $this->assertFalse($node->fresh()->isAvailable());
    }

    public function test_detail_module_queues_only_one_active_remote_command(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Remote node',
            'node_uuid' => 'node-remote-1',
            'api_key' => 'remote-node-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
        ]);

        Livewire::test(NodeDetail::class, ['node' => $node])
            ->call('queueCommand', 'node_diagnostics')
            ->assertHasNoErrors()
            ->call('queueCommand', 'node_diagnostics')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('network_jobs', 1);
        $this->assertDatabaseHas('network_jobs', [
            'network_node_id' => $node->id,
            'type' => 'node_diagnostics',
            'status' => 'pending',
        ]);
    }
}
