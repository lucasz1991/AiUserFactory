<?php

namespace Tests\Feature;

use App\Models\NetworkJob;
use App\Models\NetworkJobProgressEvent;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRuntimeFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesNetworkNodes;
use Tests\TestCase;

class ClientControllerReliableWorkflowTest extends TestCase
{
    use CreatesNetworkNodes;
    use RefreshDatabase;

    public function test_protocol_two_uses_a_lease_and_deduplicates_sequences(): void
    {
        $node = $this->createNetworkNode([
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

        $pull = $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk()
            ->assertJsonPath('jobs.0.payload_version', 1);
        $leaseToken = (string) $pull->json('jobs.0.lease_token');
        $this->assertNotSame('', $leaseToken);
        $job->refresh();
        $this->assertNotNull($job->pulled_at);
        $this->assertNull($job->started_at);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk()
            ->assertJsonCount(0, 'jobs');

        $resume = $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/pull-jobs', [
                'protocol_version' => 2,
                'resume_job_uuids' => [$job->job_uuid],
            ])
            ->assertOk()
            ->assertJsonPath('jobs.0.job_uuid', $job->job_uuid);
        $leaseToken = (string) $resume->json('jobs.0.lease_token');

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode(['state' => 'running', 'message' => 'first']),
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged_sequence', 1);

        $this->assertNotNull($job->fresh()->started_at);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => 'wrong-token',
                'sequence' => 2,
                'progress' => json_encode(['state' => 'running']),
            ])
            ->assertStatus(409);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

    public function test_nested_client_session_artifacts_are_redacted_from_progress_and_results(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Session redaction node',
            'node_uuid' => 'session-redaction-node',
            'api_key' => 'session-redaction-key',
            'status' => 'active',
        ]);
        $leaseToken = Str::random(64);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_task',
            'payload_version' => 2,
            'payload_json' => ['runtime' => []],
            'status' => 'dispatched',
            'queued_at' => now(),
            'dispatched_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'lease_token_hash' => hash('sha256', $leaseToken),
        ]);
        $headers = ['X-NODE-API-KEY' => $this->networkNodeApiKey($node)];
        $sessionPayload = '{"cookies":[{"name":"sid","value":"synthetic-session-secret"}]}';

        $this->withHeaders($headers)->post('/api/client-controller/job-progress', [
            'job_uuid' => $job->job_uuid,
            'lease_token' => $leaseToken,
            'sequence' => 1,
            'progress' => json_encode([
                'state' => 'running',
                'workflow' => ['steps' => [['tasks' => [[
                    'remoteBrowserSessionPayload' => $sessionPayload,
                    'encryptedSessionPayload' => 'encrypted-synthetic-session-secret',
                ]]]]],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk();

        $this->withHeaders($headers)->postJson('/api/client-controller/job-result', [
            'job_uuid' => $job->job_uuid,
            'lease_token' => $leaseToken,
            'sequence' => 2,
            'status' => 'success',
            'result' => [
                'ok' => true,
                'remoteBrowserSessionPayload' => $sessionPayload,
                'workflow' => ['steps' => [['tasks' => [[
                    'browserSessionPayload' => $sessionPayload,
                    'webmailSession' => ['payload_encrypted' => 'synthetic-session-secret'],
                ]]]]],
                'browserCleanup' => ['browserSessions' => ['session' => $sessionPayload]],
            ],
        ])->assertOk();

        $job->refresh();
        $storedValues = json_encode([
            'result' => $job->result_json,
            'events' => $job->progressEvents()->pluck('payload_json')->all(),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('synthetic-session-secret', $storedValues);
        $this->assertStringNotContainsString('remoteBrowserSessionPayload', $storedValues);
        $this->assertStringNotContainsString('encryptedSessionPayload', $storedValues);
        $this->assertStringNotContainsString('browserSessions', $storedValues);
    }

    public function test_nested_client_browser_session_is_encrypted_and_persisted_before_redaction(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Nested session node',
            'node_uuid' => 'nested-session-node',
            'api_key' => 'nested-session-key',
            'status' => 'active',
        ]);
        $person = Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'nested-session-'.str()->random(8),
            'profile_label' => 'Nested session test',
            'metadata' => ['browser_sessions' => []],
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Nested session workflow',
            'slug' => 'nested-session-'.str()->random(8),
            'is_active' => true,
        ]);
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Persist session',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'persist-session',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'started_at' => now(),
            'context_json' => ['person_id' => $person->id, 'execution_target' => 'client_controller'],
            'result_json' => [],
        ]);
        $jobUuid = (string) Str::uuid();
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'external_run_type' => 'client-controller-workflow-task',
            'external_run_id' => $jobUuid,
            'started_at' => now(),
            'result_json' => [],
        ]);
        $leaseToken = Str::random(64);
        $job = NetworkJob::query()->create([
            'job_uuid' => $jobUuid,
            'network_node_id' => $node->id,
            'workflow_run_id' => $run->id,
            'type' => 'workflow_task',
            'payload_version' => 2,
            'payload_json' => ['runtime' => []],
            'status' => 'dispatched',
            'queued_at' => now(),
            'dispatched_at' => now(),
            'lease_expires_at' => now()->addMinute(),
            'lease_token_hash' => hash('sha256', $leaseToken),
        ]);
        $sessionPayload = json_encode([
            'cookies' => [['name' => 'sid', 'value' => 'nested-synthetic-secret', 'domain' => '.example.test']],
        ], JSON_THROW_ON_ERROR);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/job-result', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'status' => 'success',
                'result' => [
                    'ok' => true,
                    'status' => 'success',
                    'workflow' => ['steps' => [['tasks' => [[
                        'remoteBrowserSessionPayload' => $sessionPayload,
                        'sessionKey' => 'person-'.$person->id.'-account-primary--example.test',
                        'ownerSessionKey' => 'person-'.$person->id.'-account-primary',
                        'browserSessionSummary' => [
                            'domain' => 'example.test',
                            'finalUrl' => 'https://example.test/account',
                            'domains' => ['example.test'],
                        ],
                    ]]]]],
                ],
            ])->assertOk();

        $storedSession = $person->fresh()->metadata['browser_sessions']['person-'.$person->id.'-account-primary--example.test'];
        $this->assertSame($sessionPayload, Crypt::decryptString($storedSession['payload_encrypted']));
        $storedValues = json_encode([
            'job' => $job->fresh()->result_json,
            'events' => $job->fresh()->progressEvents()->pluck('payload_json')->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('nested-synthetic-secret', $storedValues);
        $this->assertStringNotContainsString('encryptedBrowserSessionPayload', $storedValues);
        $this->assertStringNotContainsString('remoteBrowserSessionPayload', $storedValues);
    }

    public function test_capable_node_receives_one_portable_full_workflow_job(): void
    {
        $node = $this->createNetworkNode([
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
                'php_handler' => 'App\\Services\\Workflows\\Tasks\\PersistBrowserSessionTask@handle',
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
        $this->assertNull($job->expires_at);
        $this->assertCount(2, data_get($job->payload_json, 'workflow_bundle.steps'));
        $this->assertSame('wait', data_get($job->payload_json, 'workflow_bundle.steps.0.defaultNext'));
        // Regel 7: das Bundle traegt den Fingerabdruck der Node-Runtime, mit der
        // es erzeugt wurde, damit der Client seinen eigenen Stand abgleichen kann.
        $this->assertSame(
            app(WorkflowRuntimeFingerprint::class)->hash(),
            data_get($job->payload_json, 'workflow_bundle.runtimeHash'),
        );
        $this->assertSame('sha256', data_get($job->payload_json, 'workflow_bundle.runtimeHashAlgorithm'));
        $this->assertSame(2, $run->stepRuns()->count());
        $this->assertSame($first->id, $run->fresh()->current_workflow_step_id);

        $pull = $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

    public function test_pending_workflow_jobs_are_pullable_even_with_legacy_expiry(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Legacy expiry node',
            'node_uuid' => 'legacy-expiry-node',
            'api_key' => 'legacy-expiry-key',
            'status' => 'active',
        ]);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_run',
            'payload_version' => 2,
            'payload_json' => ['workflow_bundle' => ['steps' => []]],
            'status' => 'pending',
            'queued_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/pull-jobs', ['protocol_version' => 2])
            ->assertOk()
            ->assertJsonPath('jobs.0.job_uuid', $job->job_uuid);
    }

    public function test_factory_does_not_stop_workflow_jobs_on_expires_and_waits_for_authoritative_client_result(): void
    {
        $node = $this->createNetworkNode([
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

        $this->assertSame('dispatched', $job->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->post('/api/client-controller/job-progress', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'progress' => json_encode(['state' => 'running', 'message' => 'still running']),
            ])
            ->assertOk()
            ->assertJsonMissingPath('control.command');

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
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

    public function test_single_step_result_for_full_client_workflow_is_treated_as_progress(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Partial bundle node',
            'node_uuid' => 'partial-bundle-node',
            'api_key' => 'partial-bundle-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
            'capabilities_json' => ['workflow_bundle_v1' => true],
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Partial bundle workflow',
            'slug' => 'partial-bundle-workflow',
            'is_active' => true,
        ]);
        $first = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'First list',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'first-list',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'open', 'runner' => 'node', 'node_script' => 'node/workflows/tasks/browser/open.cjs']]],
        ]);
        $second = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Second list',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'second-list',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'check', 'runner' => 'node', 'node_script' => 'node/workflows/tasks/decision/element_exists.cjs']]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'current_workflow_step_id' => $first->id,
            'status' => 'running',
            'queued_at' => now(),
            'started_at' => now(),
            'context_json' => [
                'execution_target' => 'client_controller',
                'network_node_id' => $node->id,
            ],
            'result_json' => [],
        ]);
        $firstRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $first->id,
            'status' => 'waiting',
            'external_run_type' => 'client-controller-workflow-run',
            'external_run_id' => 'partial-job',
            'started_at' => now(),
            'result_json' => [],
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $second->id,
            'status' => 'queued',
            'result_json' => [],
        ]);
        $leaseToken = Str::random(64);
        $job = NetworkJob::query()->create([
            'job_uuid' => 'partial-job',
            'network_node_id' => $node->id,
            'workflow_run_id' => $run->id,
            'type' => 'workflow_run',
            'payload_version' => 2,
            'payload_json' => ['workflow_bundle' => ['steps' => []]],
            'status' => 'dispatched',
            'queued_at' => now(),
            'dispatched_at' => now(),
            'lease_token_hash' => hash('sha256', $leaseToken),
            'lease_expires_at' => now()->addMinute(),
        ]);

        $this->withHeader('X-NODE-API-KEY', $this->networkNodeApiKey($node))
            ->postJson('/api/client-controller/job-result', [
                'job_uuid' => $job->job_uuid,
                'lease_token' => $leaseToken,
                'sequence' => 1,
                'status' => 'success',
                'result' => [
                    'ok' => true,
                    'status' => 'success',
                    'state' => 'completed',
                    'scriptName' => 'run_step.cjs',
                    'workflowStepId' => $first->id,
                    'workflowStepRunId' => $firstRun->id,
                    'statusMessage' => 'First list completed',
                    'tasks' => [['key' => 'open', 'status' => 'success']],
                    'browserWsEndpoint' => 'ws://127.0.0.1/devtools/browser/test',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('partial', true);

        $this->assertSame('dispatched', $job->fresh()->status);
        $this->assertNull($job->fresh()->completed_at);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('completed', $firstRun->fresh()->status);

        app(WorkflowExecutionService::class)->advance($run->fresh());

        $this->assertSame(1, NetworkJob::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, NetworkJob::query()->where('workflow_run_id', $run->id)->where('type', 'workflow_task')->count());
    }

    public function test_unassigned_client_run_uses_a_free_node_instead_of_a_busy_node(): void
    {
        $busyNode = $this->createNetworkNode([
            'name' => 'Busy node',
            'node_uuid' => 'busy-node',
            'api_key' => 'busy-key',
            'status' => 'active',
            'is_online' => true,
            'last_seen_at' => now(),
            'capabilities_json' => ['workflow_bundle_v1' => true],
        ]);
        $freeNode = $this->createNetworkNode([
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

        $run->forceFill(['status' => 'stop_requested'])->save();
        app(WorkflowExecutionService::class)->advance($run);
        $this->assertSame(1, NetworkJob::query()->where('workflow_run_id', $run->id)->count());
    }

    public function test_force_termination_requests_a_complete_client_process_tree_stop(): void
    {
        $node = $this->createNetworkNode([
            'name' => 'Force stop node',
            'node_uuid' => 'force-stop-node',
            'api_key' => 'force-stop-key',
            'status' => 'active',
        ]);
        $workflow = Workflow::query()->create([
            'name' => 'Force stop workflow',
            'slug' => 'force-stop-workflow',
            'is_active' => true,
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'context_json' => ['execution_target' => 'client_controller'],
            'result_json' => [],
        ]);
        $job = NetworkJob::query()->create([
            'job_uuid' => (string) Str::uuid(),
            'network_node_id' => $node->id,
            'workflow_run_id' => $run->id,
            'type' => 'workflow_run',
            'payload_version' => 2,
            'payload_json' => ['workflow_bundle' => []],
            'status' => 'dispatched',
            'queued_at' => now()->subMinute(),
            'dispatched_at' => now(),
            'lease_expires_at' => now()->addMinute(),
        ]);

        $result = app(WorkflowExecutionService::class)->terminate($run, 'Client-Prozessbaum beenden.');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['pendingClientConfirmation']);
        $this->assertSame('stop_requested', $run->fresh()->status);
        $this->assertSame('stop_requested', $job->fresh()->status);
        $this->assertSame('stop', $job->fresh()->control_command);
        $this->assertTrue((bool) data_get($job->fresh()->control_payload_json, 'force'));
        $this->assertTrue((bool) data_get($job->fresh()->control_payload_json, 'terminate_process_tree'));
    }
}
