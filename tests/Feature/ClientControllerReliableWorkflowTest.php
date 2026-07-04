<?php

namespace Tests\Feature;

use App\Models\NetworkJob;
use App\Models\NetworkJobProgressEvent;
use App\Models\NetworkNode;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
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
    }
}
