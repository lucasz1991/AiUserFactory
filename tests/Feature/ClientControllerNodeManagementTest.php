<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClientController\NodeDetail;
use App\Livewire\Admin\ClientController\NodeIndex;
use App\Models\NetworkNode;
use App\Models\Setting;
use App\Services\ClientController\ClientControllerReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_an_approved_signed_update_is_dispatched_and_confirmed_by_the_next_version_heartbeat(): void
    {
        Http::fake([
            'https://api.github.com/repos/lucasz1991/ClientController/releases/latest' => Http::response([
                'tag_name' => 'v0.2.0',
                'name' => 'ClientController 0.2.0',
                'html_url' => 'https://github.com/lucasz1991/ClientController/releases/tag/v0.2.0',
                'published_at' => now()->toIso8601String(),
                'body' => 'Signed release',
                'assets' => [[
                    'name' => 'latest.json',
                    'browser_download_url' => 'https://github.com/lucasz1991/ClientController/releases/download/v0.2.0/latest.json',
                ]],
            ]),
        ]);

        Setting::setValue('client_controller', 'updates', [
            'github_repository' => 'lucasz1991/ClientController',
            'manifest_url' => 'https://github.com/lucasz1991/ClientController/releases/latest/download/latest.json',
            'updater_public_key' => 'PUBLIC-TAURI-KEY',
            'check_interval_minutes' => 15,
        ]);

        $node = NetworkNode::query()->create([
            'name' => 'Update node',
            'node_uuid' => 'node-update-1',
            'api_key' => 'update-node-key',
            'version' => '0.1.1',
            'status' => 'active',
        ]);

        $job = app(ClientControllerReleaseService::class)->queueUpdate($node, 'admin@example.test');

        $this->assertSame('node_update', $job->type);
        $this->assertSame('0.2.0', $job->payload_json['target_version']);
        $this->assertSame('pending', $node->fresh()->update_status);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/pull-jobs')
            ->assertOk()
            ->assertJsonPath('jobs.0.type', 'node_update')
            ->assertJsonPath('jobs.0.payload.target_version', '0.2.0');

        $this->assertSame('installing', $node->fresh()->update_status);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/heartbeat', [
                'status' => 'online',
                'version' => '0.2.0',
                'os' => 'windows',
            ])
            ->assertOk();

        $this->assertSame('installed', $node->fresh()->update_status);
        $this->assertNotNull($node->fresh()->update_installed_at);
        $this->assertDatabaseHas('network_jobs', [
            'id' => $job->id,
            'status' => 'success',
        ]);
    }

    public function test_node_inventory_is_a_livewire_module_and_displays_installed_and_latest_versions(): void
    {
        Http::fake([
            'https://api.github.com/repos/lucasz1991/ClientController/releases/latest' => Http::response([
                'tag_name' => 'v0.2.0',
                'name' => 'ClientController 0.2.0',
                'html_url' => 'https://github.com/lucasz1991/ClientController/releases/tag/v0.2.0',
                'published_at' => now()->toIso8601String(),
                'assets' => [],
            ]),
        ]);

        NetworkNode::query()->create([
            'name' => 'Visible version node',
            'node_uuid' => 'node-visible-version',
            'api_key' => 'visible-version-key',
            'version' => '0.1.1',
            'status' => 'active',
        ]);

        Livewire::test(NodeIndex::class)
            ->assertSee('Installationen und Updates')
            ->assertSee('Visible version node')
            ->assertSee('v0.1.1')
            ->assertSee('v0.2.0');
    }
}
