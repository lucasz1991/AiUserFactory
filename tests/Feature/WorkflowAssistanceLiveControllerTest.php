<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAssistanceRequest;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowAssistanceLiveBrowser;
use App\Services\Workflows\WorkflowAssistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowAssistanceLiveControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistanceLiveBrowser $live;

    protected function setUp(): void
    {
        parent::setUp();

        $this->live = new FakeAssistanceLiveBrowser;
        $this->app->instance(WorkflowAssistanceLiveBrowser::class, $this->live);
    }

    public function test_only_the_assigned_admin_receives_live_frames(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'status' => true]);
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $request = $this->assistanceRequest($owner);

        $this->actingAs($other)
            ->get(route('network.workflow-assistance.live.frame', $request))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('network.workflow-assistance.live.frame', ['assistance' => $request, 'after' => 4, 'wait' => 9999]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Frame-Sequence', '5');

        $this->assertSame([4, 2000], $this->live->frameCalls[0]);
    }

    public function test_drag_inputs_are_forwarded_in_order_and_logged_on_release(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'status' => true]);
        $request = $this->assistanceRequest($owner);
        $url = route('network.workflow-assistance.live.input', $request);

        $this->actingAs($owner)->postJson($url, ['type' => 'down', 'x' => 0.1, 'y' => 0.2])->assertOk();
        $this->actingAs($owner)->postJson($url, ['type' => 'move', 'x' => 0.4, 'y' => 0.2])->assertOk();
        $this->actingAs($owner)->postJson($url, ['type' => 'up', 'x' => 0.4, 'y' => 0.2])->assertOk();

        $this->assertSame(['down', 'move', 'up'], array_column($this->live->inputs, 'type'));
        $this->assertSame(1, $request->events()->where('event_type', 'browser_interaction_started')->count());
    }

    public function test_invalid_inputs_and_busy_runs_are_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'status' => true]);
        $request = $this->assistanceRequest($owner);
        $url = route('network.workflow-assistance.live.input', $request);

        $this->actingAs($owner)->postJson($url, ['type' => 'click', 'x' => 1.5, 'y' => 0.2])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'key', 'key' => 'F12'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['type' => 'eval'])->assertUnprocessable();

        $request->workflowRun()->update(['status' => 'running']);
        $this->actingAs($owner)->postJson($url, ['type' => 'click', 'x' => 0.5, 'y' => 0.5])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Der Workflow ist gerade beschäftigt. Bitte kurz warten.');

        $this->assertSame([], $this->live->inputs);
    }

    public function test_expired_or_released_tasks_lose_live_access_and_stop_the_live_view(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'status' => true]);
        $request = $this->assistanceRequest($owner);

        app(WorkflowAssistanceService::class)->release($request, $owner);

        $this->assertSame(1, $this->live->stops);
        $this->actingAs($owner)
            ->get(route('network.workflow-assistance.live.frame', $request))
            ->assertForbidden();

        $expired = $this->assistanceRequest($owner, now()->subMinute());
        $this->actingAs($owner)
            ->get(route('network.workflow-assistance.live.frame', $expired))
            ->assertStatus(409);
    }

    private function assistanceRequest(User $owner, $expiresAt = null): WorkflowAssistanceRequest
    {
        $workflow = Workflow::query()->create([
            'name' => 'Live '.Str::random(6),
            'slug' => 'live-'.Str::random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Verifizierung',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'verify',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->getKey(),
            'current_workflow_step_id' => $step->getKey(),
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'started_at' => now(),
            'context_json' => [],
            'result_json' => [],
        ]);

        return WorkflowAssistanceRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'source_key' => hash('sha256', Str::random(20)),
            'workflow_id' => $workflow->getKey(),
            'workflow_run_id' => $run->getKey(),
            'open_workflow_run_id' => $run->getKey(),
            'workflow_step_id' => $step->getKey(),
            'type' => WorkflowAssistanceRequest::TYPE_CAPTCHA,
            'status' => WorkflowAssistanceRequest::STATUS_CLAIMED,
            'priority' => 'high',
            'reason_code' => 'captcha_detected',
            'task_key' => 'verifizierung-an-admin',
            'browser_window' => 'main',
            'title' => 'Proton-Verifizierung',
            'instructions' => 'Puzzle loesen.',
            'assigned_to_user_id' => $owner->getKey(),
            'claimed_at' => now(),
            'requested_at' => now(),
            'expires_at' => $expiresAt ?? now()->addMinutes(15),
            'metadata_json' => ['provider' => 'proton'],
        ]);
    }
}

class FakeAssistanceLiveBrowser extends WorkflowAssistanceLiveBrowser
{
    public array $frameCalls = [];

    public array $inputs = [];

    public int $stops = 0;

    public function frame(WorkflowAssistanceRequest $request, int $afterSequence = 0, int $waitMs = 0): array
    {
        $this->frameCalls[] = [$afterSequence, $waitMs];

        return ['bytes' => "\xFF\xD8\xFF\xE0fake", 'sequence' => $afterSequence + 1];
    }

    public function input(WorkflowAssistanceRequest $request, array $command): array
    {
        $this->inputs[] = $command;

        return ['ok' => true, 'sequence' => count($this->inputs)];
    }

    public function stop(WorkflowAssistanceRequest $request): void
    {
        $this->stops++;
    }
}
