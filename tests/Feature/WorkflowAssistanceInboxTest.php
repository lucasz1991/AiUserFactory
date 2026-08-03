<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowAssistanceInbox;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAssistanceRequest;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowAssistanceInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_open_the_assistance_inbox_route(): void
    {
        $admin = $this->user('admin', 'Aktive Admin');
        $request = $this->assistanceRequest();

        $this->actingAs($admin)
            ->get(route('network.workflow-assistance', ['requestUuid' => $request->request_uuid]))
            ->assertOk()
            ->assertSee('Workflow-Aufgaben')
            ->assertSee($request->title);
    }

    public function test_normal_user_is_forbidden_from_mounting_the_assistance_page(): void
    {
        $user = $this->user('user', 'Normaler Nutzer');

        Livewire::actingAs($user)
            ->test(WorkflowAssistanceInbox::class)
            ->assertForbidden();
    }

    public function test_admin_can_claim_a_pending_assistance_request(): void
    {
        $admin = $this->user('admin', 'Erste Admin');
        $request = $this->assistanceRequest();

        Livewire::actingAs($admin)
            ->test(WorkflowAssistanceInbox::class, ['requestUuid' => $request->request_uuid])
            ->call('claim')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Die Aufgabe ist jetzt dir zugewiesen.')
            ->assertSet('noticeType', 'success');

        $this->assertDatabaseHas('workflow_assistance_requests', [
            'id' => $request->id,
            'status' => WorkflowAssistanceRequest::STATUS_CLAIMED,
            'assigned_to_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('workflow_assistance_events', [
            'workflow_assistance_request_id' => $request->id,
            'event_type' => 'claimed',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_second_admin_cannot_claim_an_assistance_request_owned_by_another_admin(): void
    {
        $firstAdmin = $this->user('admin', 'Erste Admin');
        $secondAdmin = $this->user('admin', 'Zweite Admin');
        $request = $this->assistanceRequest();

        Livewire::actingAs($firstAdmin)
            ->test(WorkflowAssistanceInbox::class, ['requestUuid' => $request->request_uuid])
            ->call('claim')
            ->assertHasNoErrors();

        Livewire::actingAs($secondAdmin)
            ->test(WorkflowAssistanceInbox::class, ['requestUuid' => $request->request_uuid])
            ->call('claim')
            ->assertHasErrors(['assistance'])
            ->assertSee("Diese Aufgabe wird bereits von {$firstAdmin->name} bearbeitet.");

        $this->assertDatabaseHas('workflow_assistance_requests', [
            'id' => $request->id,
            'status' => WorkflowAssistanceRequest::STATUS_CLAIMED,
            'assigned_to_user_id' => $firstAdmin->id,
        ]);
        $this->assertDatabaseMissing('workflow_assistance_events', [
            'workflow_assistance_request_id' => $request->id,
            'event_type' => 'claimed',
            'actor_user_id' => $secondAdmin->id,
        ]);
    }

    public function test_inbox_explains_the_human_only_captcha_flow_and_uses_touch_sized_controls(): void
    {
        $admin = $this->user('admin', 'Mobile Admin');
        $request = $this->assistanceRequest();

        Livewire::actingAs($admin)
            ->test(WorkflowAssistanceInbox::class, ['requestUuid' => $request->request_uuid])
            ->assertSee('Human-in-the-loop')
            ->assertSee('CAPTCHA-Erkennung')
            ->assertSee('Administrator')
            ->assertSee('CAPTCHA-Bypass')
            ->assertSee('reCAPTCHA erneut')
            ->assertSeeHtml('wire:click="claim"')
            ->assertSeeHtml('min-h-11')
            ->assertSeeHtml('min-w-11')
            ->assertSeeHtml('min-h-12');
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => true,
        ]);
    }

    private function assistanceRequest(): WorkflowAssistanceRequest
    {
        $workflow = Workflow::query()->create([
            'name' => 'CAPTCHA Workflow',
            'slug' => 'captcha-workflow-'.Str::lower(Str::random(10)),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'status' => 'paused',
            'context_json' => [],
            'result_json' => [],
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(30),
        ]);

        return WorkflowAssistanceRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'source_key' => hash('sha256', (string) Str::uuid()),
            'workflow_id' => $workflow->id,
            'workflow_run_id' => $run->id,
            'open_workflow_run_id' => $run->id,
            'type' => WorkflowAssistanceRequest::TYPE_CAPTCHA,
            'status' => WorkflowAssistanceRequest::STATUS_PENDING,
            'priority' => 'high',
            'reason_code' => 'captcha_detected',
            'task_key' => 'captcha-handoff',
            'resume_task_key' => 'next-task',
            'browser_window' => 'main',
            'current_url' => 'https://example.test/challenge',
            'title' => 'reCAPTCHA wartet auf einen Menschen',
            'instructions' => 'Bitte ausschliesslich manuell loesen.',
            'cursor_json' => [],
            'browser_state_json' => [],
            'metadata_json' => ['provider' => 'recaptcha'],
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
