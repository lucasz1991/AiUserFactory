<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowLiveRecording;
use App\Models\User;
use App\Models\WorkflowRecording;
use App\Services\Workflows\WorkflowRecordingBrowser;
use App\Services\Workflows\WorkflowRecordingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkflowLiveRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_admin_module_renders_without_exposing_browser_credentials(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->forceFill(['runtime_json' => ['secret' => 'PRIVATE-BEARER-DO-NOT-RENDER', 'port' => 34567]])->save();
        $this->actingAs($user)->get(route('network.live-recording', ['recording' => $recording->id]))
            ->assertOk()->assertSee('Live Aufnahme')->assertSee('data-workflow-live-recording', false)
            ->assertDontSee('PRIVATE-BEARER-DO-NOT-RENDER');
    }

    public function test_owner_gate_blocks_all_foreign_browser_endpoints_and_livewire_mount(): void
    {
        $recording = $this->draft($this->admin());
        $other = $this->admin();
        $this->actingAs($other)->get(route('network.live-recording.state', $recording))->assertNotFound();
        $this->get(route('network.live-recording.frame', $recording))->assertNotFound();
        $this->postJson(route('network.live-recording.command', $recording), ['type' => 'key', 'key' => 'Enter'])->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($other)->withQueryParams(['recording' => $recording->id])
            ->test(WorkflowLiveRecording::class);
    }

    public function test_inactive_admin_and_non_admin_cannot_control_module(): void
    {
        $inactive = User::factory()->create(['role' => 'admin', 'status' => false]);
        Livewire::actingAs($inactive)->test(WorkflowLiveRecording::class)->assertForbidden();
        $regular = User::factory()->create(['role' => 'user', 'status' => true]);
        Livewire::actingAs($regular)->test(WorkflowLiveRecording::class)->assertForbidden();
    }

    public function test_frame_is_owner_private_and_only_available_while_browser_is_open(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $this->actingAs($user)->get(route('network.live-recording.frame', $recording))->assertStatus(409);
        $recording->update(['status' => 'paused']);
        $browser = Mockery::mock(WorkflowRecordingBrowser::class);
        $browser->shouldReceive('frame')->once()->andReturn("\xFF\xD8test\xFF\xD9");
        $this->app->instance(WorkflowRecordingBrowser::class, $browser);
        $response = $this->get(route('network.live-recording.frame', $recording));
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_command_rejects_arbitrary_keys_javascript_and_private_runtime_keys_before_bridge(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->update(['status' => 'recording']);
        $browser = Mockery::mock(WorkflowRecordingBrowser::class);
        $browser->shouldNotReceive('command');
        $this->app->instance(WorkflowRecordingBrowser::class, $browser);
        $this->actingAs($user)->postJson(route('network.live-recording.command', $recording), ['type' => 'key', 'key' => 'Password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->postJson(route('network.live-recording.command', $recording), ['type' => 'evaluate', 'script' => 'alert(1)'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson(route('network.live-recording.command', $recording), ['type' => 'fill', 'source' => 'remote_script'])
            ->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_command_ignores_unvalidated_fields_and_never_forwards_private_credentials(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->update(['status' => 'recording']);
        $browser = Mockery::mock(WorkflowRecordingBrowser::class);
        $browser->shouldReceive('command')->once()->withArgs(fn ($owned, $command) => $owned->id === $recording->id
            && $command === ['type' => 'key', 'key' => 'Enter'])
            ->andReturn(['state' => 'recording', 'events' => [], 'url' => 'https://example.org', 'viewport' => ['width' => 1280, 'height' => 800]]);
        $this->app->instance(WorkflowRecordingBrowser::class, $browser);
        $response = $this->actingAs($user)->postJson(route('network.live-recording.command', $recording), [
            'type' => 'key', 'key' => 'Enter', 'secret' => 'must-not-forward', 'runtimeDir' => 'C:/private',
        ]);
        $response->assertOk()->assertDontSee('must-not-forward')->assertJsonPath('recordingId', $recording->id);
    }

    public function test_failed_browser_errors_do_not_leak_input_or_page_values(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->update(['status' => 'recording']);
        $browser = Mockery::mock(WorkflowRecordingBrowser::class);
        $browser->shouldReceive('command')->once()->andThrow(new \RuntimeException('PrivateToken sample-password sensitive-page-text'));
        $this->app->instance(WorkflowRecordingBrowser::class, $browser);
        $this->actingAs($user)->postJson(route('network.live-recording.command', $recording), ['type' => 'key', 'key' => 'Enter'])
            ->assertStatus(409)->assertDontSee('sample-password')->assertDontSee('PrivateToken');
    }

    public function test_livewire_keeps_unsaved_name_when_an_event_is_selected(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->update(['status' => 'stopped', 'events_json' => [
            ['id' => 'event-1', 'sequence' => 1, 'type' => 'navigate', 'url' => 'https://example.org', 'label' => 'Öffnen'],
        ]]);
        Livewire::actingAs($user)->withQueryParams(['recording' => $recording->id])->test(WorkflowLiveRecording::class)
            ->set('name', 'Eigener neuer Name')->call('selectEvent', 'event-1')
            ->assertSet('name', 'Eigener neuer Name')->assertSet('selectedEventId', 'event-1');
    }

    public function test_save_redirects_to_the_existing_workflow_editor(): void
    {
        $user = $this->admin();
        $recording = $this->draft($user);
        $recording->update(['status' => 'stopped', 'events_json' => [
            ['id' => 'event-1', 'sequence' => 1, 'type' => 'navigate', 'url' => 'https://example.org', 'label' => 'Öffnen'],
        ]]);
        $component = Livewire::actingAs($user)->withQueryParams(['recording' => $recording->id])
            ->test(WorkflowLiveRecording::class)->call('saveWorkflow')->assertHasNoErrors()->assertSet('error', '');
        $recording->refresh();
        $this->assertSame('saved', $recording->status);
        $this->assertNotNull($recording->workflow_id);
        $component->assertRedirect(route('network.workflows.manage', ['workflow' => $recording->workflow_id]));
    }

    public function test_invalid_start_address_has_a_visible_validation_message(): void
    {
        Livewire::actingAs($this->admin())->test(WorkflowLiveRecording::class)
            ->set('name', 'Ungültige Adresse')->set('url', 'file:///private')
            ->call('startRecording')->assertHasErrors('startUrl')
            ->assertSee('Bitte eine vollstaendige HTTP- oder HTTPS-Adresse ohne Zugangsdaten eingeben.');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    private function draft(User $user): WorkflowRecording
    {
        return app(WorkflowRecordingService::class)->create($user, 'UI-Aufnahme', 'https://example.org');
    }
}
