<?php

namespace Tests\Feature;

use App\Models\NetworkJob;
use App\Models\NetworkJobProgressEvent;
use App\Models\NetworkNode;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientControllerReliableWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_protocol_two_uses_a_lease_and_deduplicates_sequences(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Reliable node',
            'node_uuid' => 'reliable-node',
            'api_key' => 'reliable-key',
            'status' => 'active',
        ]);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_task',
            'payload_version' => 1,
            'payload_json' => ['runtime' => []],
            'status' => 'pending',
            'queued_at' => now(),
        ]);

        $pull = $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk()
            ->assertJsonPath('jobs.0.payload_version', 1);
        $leaseToken = (string) $pull->json('jobs.0.lease_token');
        $this->assertNotSame('', $leaseToken);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk()
            ->assertJsonCount(0, 'jobs');

        $resume = $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/pull-jobs', [
                'protocol_version' => 2,
                'resume_job_uuids' => [$job->job_uuid],
            ])
            ->assertOk()
            ->assertJsonPath('jobs.0.job_uuid', $job->job_uuid);
        $leaseToken = (string) $resume->json('jobs.0.lease_token');

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode(['state' => 'running', 'message' => 'first']),
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged_sequence', 1);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode(['state' => 'running', 'message' => 'duplicate']),
            ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame('first', $job->fresh()->result_json['message']);
        $this->assertSame(1, NetworkJobProgressEvent::query()->where('network_job_id', $job->id)->count());

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => 'wrong-token',
                'sequence' => 2,
                'progress' => json_encode(['state' => 'running']),
            ])
            ->assertStatus(409);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/job-result', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 2,
                'status' => 'success',
                'result' => ['ok' => true, 'statusMessage' => 'done'],
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged_sequence', 2);

        $this->assertSame(2, NetworkJobProgressEvent::query()->where('network_job_id', $job->id)->count());
    }

    public function test_capable_node_receives_one_portable_full_workflow_job(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Bundle node',
            'node_uuid' => 'bundle-node',
            'api_key' => 'bundle-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
            'capabilities_json' => ['workflow_bundle_v1' => true],
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Portable workflow',
            'slug' => 'portable-workflow',
            'is_active' => true,
        ]);
        $first = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Open browser',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'open-browser',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'open',
                'title' => 'Open',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open.cjs',
                'task_key' => 'browser.open',
            ]]],
        ]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Wait',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'wait',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['seconds' => 1],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'queued',
            'queued_at' => now(),
            'context_json' => [
                'execution_target' => 'client_controller',
                'network_node_id' => $node->id,
            ],
            'result_json' => [],
        ]);

        app(WorkflowExecutionService::class)->advance($run);

        $job = NetworkJob::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $this->assertSame('workflow_run', $job->type);
        $this->assertSame(2, $job->payload_version);
        $this->assertCount(2, data_get($job->payload_json, 'workflow_bundle.steps'));
        $this->assertSame('wait', data_get($job->payload_json, 'workflow_bundle.steps.0.defaultNext'));
        $this->assertSame(2, $run->stepRuns()->count());
        $this->assertSame($first->id, $run->fresh()->current_workflow_step_id);

        $pull = $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk();
        $leaseToken = (string) $pull->json('jobs.0.lease_token');
        $stepRuns = $run->stepRuns()->orderBy('id')->get();
        $stepResults = [
            [
                'workflowStepId' => $stepRuns[0]->workflow_step_id,
                'workflowStepRunId' => $stepRuns[0]->id,
                'ok' => true,
                'state' => 'completed',
                'status' => 'success',
                'statusMessage' => 'Browser opened',
            ],
            [
                'workflowStepId' => $stepRuns[1]->workflow_step_id,
                'workflowStepRunId' => $stepRuns[1]->id,
                'ok' => true,
                'state' => 'completed',
                'status' => 'success',
                'statusMessage' => 'Wait completed',
            ],
        ];

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode([
                    'state' => 'running',
                    'currentStepId' => $stepRuns[1]->workflow_step_id,
                    'steps' => [$stepResults[0]],
                ]),
            ])
            ->assertOk();

        $this->assertSame('completed', $stepRuns[0]->fresh()->status);
        $this->assertSame('waiting', $stepRuns[1]->fresh()->status);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/job-result', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 2,
                'status' => 'success',
                'result' => [
                    'ok' => true,
                    'status' => 'success',
                    'statusMessage' => 'Full workflow completed',
                    'steps' => $stepResults,
                ],
            ])
            ->assertOk();

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->stepRuns()->where('status', 'completed')->count());
        $this->assertNull($node->fresh()->workflow_reservation_run_id);
    }

    public function test_factory_requests_stop_and_waits_for_authoritative_client_timeout_result(): void
    {
        $node = NetworkNode::query()->create([
            'name' => 'Timeout node',
            'node_uuid' => 'timeout-node',
            'api_key' => 'timeout-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Timeout workflow',
            'slug' => 'timeout-workflow',
            'is_active' => true,
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Remote step',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'remote-step',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'current_workflow_step_id' => $step->id,
            'status' => 'running',
            'queued_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
            'context_json' => ['execution_target' => 'client_controller'],
            'result_json' => [],
        ]);
        $leaseToken = Str::random(64);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'workflow_run_id' => $run->id,
            'type' => 'workflow_run',
            'payload_version' => 2,
            'payload_json' => ['workflow_bundle' => []],
            'status' => 'dispatched',
            'queued_at' => now()->subMinutes(10),
            'dispatched_at' => now()->subMinutes(10),
            'expires_at' => now()->subSecond(),
            'lease_expires_at' => now()->addMinute(),
            'lease_token_hash' => hash('sha256', $leaseToken),
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'external_run_type' => 'client-controller-workflow-run',
            'external_run_id' => $job->job_uuid,
            'started_at' => now()->subMinutes(10),
            'result_json' => [],
        ]);

        app(WorkflowExecutionService::class)->expireTimedOutRuns();

        $this->assertSame('stop_requested', $job->fresh()->status);
        $this->assertSame('stop_requested', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode(['state' => 'running', 'message' => 'still running']),
            ])
            ->assertOk()
            ->assertJsonPath('control.command', 'stop')
            ->assertJsonPath('control.payload.result_status', 'timed_out');

        $this->withHeader('X-NODE-API-KEY', $node->api_key)
            ->postJson('/api/client-controller/job-result', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 2,
                'status' => 'timed_out',
                'result' => [
                    'ok' => false,
                    'status' => 'timed_out',
                    'state' => 'timed_out',
                    'statusMessage' => 'Client stopped after timeout',
                    'finishedAt' => now()->toIso8601String(),
                ],
            ])
            ->assertOk();

        $this->assertSame('timed_out', $run->fresh()->status);
        $this->assertSame('client-controller', $run->fresh()->result_json['source']);
    }

    public function test_unassigned_client_run_uses_a_free_node_instead_of_a_busy_node(): void
    {
        $busyNode = NetworkNode::query()->create([
            'name' => 'Busy node',
            'node_uuid' => 'busy-node',
            'api_key' => 'busy-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
            'capabilities_json' => ['workflow_bundle_v1' => true],
        ]);
        $freeNode = NetworkNode::query()->create([
            'name' => 'Free node',
            'node_uuid' => 'free-node',
            'api_key' => 'free-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now()->subSecond(),
            'capabilities_json' => ['workflow_bundle_v1' => true],
        ]);
        NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $busyNode->id,
            'type' => 'workflow_run',
            'payload_version' => 2,
            'payload_json' => ['workflow_bundle' => []],
            'status' => 'dispatched',
            'queued_at' => now(),
            'dispatched_at' => now(),
            'lease_expires_at' => now()->addMinute(),
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Auto assigned workflow',
            'slug' => 'auto-assigned-workflow',
            'is_active' => true,
        ]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Portable task',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'portable-task',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'portable',
                'title' => 'Portable',
                'runner' => 'node',
                'node_script' => 'node/workflows/tasks/browser/open.cjs',
            ]]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'queued',
            'queued_at' => now(),
            'context_json' => ['execution_target' => 'client_controller'],
            'result_json' => [],
        ]);

        app(WorkflowExecutionService::class)->advance($run);

        $job = NetworkJob::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $this->assertSame($freeNode->id, $job->network_node_id);
        $this->assertSame($run->id, $freeNode->fresh()->workflow_reservation_run_id);
        $this->assertNull($busyNode->fresh()->workflow_reservation_run_id);
    }
}
