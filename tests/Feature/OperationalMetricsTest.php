<?php

namespace Tests\Feature;

use App\Jobs\RecordOperationsWorkerHeartbeat;
use App\Livewire\Admin\OperationsDashboard;
use App\Models\AiApiDailyBudget;
use App\Models\AiApiRequestAudit;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NodeCredentialEvent;
use App\Models\WorkflowPortalProfile;
use App\Services\Operations\OperationalAlertService;
use App\Services\Operations\OperationalHeartbeatService;
use App\Services\Operations\OperationalMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class OperationalMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
        Carbon::setTestNow('2026-08-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_reports_heartbeats_queue_lag_and_stale_pending_jobs(): void
    {
        config([
            'operations.thresholds.oldest_pending_job_seconds' => 120,
            'operations.thresholds.scheduler_heartbeat_seconds' => 180,
            'operations.thresholds.worker_heartbeat_seconds' => 180,
        ]);
        $heartbeats = app(OperationalHeartbeatService::class);
        $heartbeats->recordScheduler();
        (new RecordOperationsWorkerHeartbeat)->handle($heartbeats);
        Cache::forever(OperationalHeartbeatService::ARTIFACT_PRUNE_KEY, [
            'recorded_at' => now()->toIso8601String(),
            'removed' => ['run_directories' => 2],
            'storage_bytes' => 4096,
        ]);
        $node = NetworkNode::query()->forceCreate([
            'name' => 'Metrics Node',
            'node_uuid' => (string) str()->uuid(),
            'api_key' => 'legacy-test-value',
            'is_online' => true,
            'last_seen_at' => now(),
            'status' => 'active',
        ]);
        NetworkJob::query()->create([
            'job_uuid' => (string) str()->uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_run',
            'payload_json' => [],
            'status' => 'completed',
            'queued_at' => now()->subSeconds(10),
            'signaled_at' => now()->subSeconds(8),
            'pulled_at' => now()->subSeconds(6),
            'started_at' => now()->subSeconds(4),
            'dispatched_at' => now()->subSeconds(4),
            'completed_at' => now()->subSecond(),
        ]);
        $pending = NetworkJob::query()->create([
            'job_uuid' => (string) str()->uuid(),
            'network_node_id' => $node->id,
            'type' => 'workflow_run',
            'payload_json' => [],
            'status' => 'pending',
            'queued_at' => now()->subMinutes(5),
        ]);
        AiApiRequestAudit::query()->create([
            'request_id' => (string) str()->uuid(),
            'team_id' => 42,
            'usage_date' => now('UTC')->toDateString(),
            'endpoint' => 'generate',
            'profile' => 'workflow-copilot',
            'status' => 'succeeded',
            'charged_cost_microusd' => 1250,
            'completed_at' => now(),
        ]);
        AiApiDailyBudget::query()->create([
            'usage_date' => now('UTC')->toDateString(),
            'scope_type' => 'team',
            'scope_id' => 42,
            'committed_cost_microusd' => 45_000_000,
            'reserved_tokens' => 250,
            'request_count' => 1,
        ]);

        $snapshot = app(OperationalMetricsService::class)->snapshot();

        $this->assertSame('ok', data_get($snapshot, 'liveness.scheduler.status'));
        $this->assertSame('ok', data_get($snapshot, 'liveness.worker.status'));
        $this->assertSame(1, data_get($snapshot, 'network_jobs.pending_count'));
        $this->assertSame(300, data_get($snapshot, 'network_jobs.oldest_pending_seconds'));
        $this->assertSame($pending->job_uuid, data_get($snapshot, 'network_jobs.oldest_pending_uuid'));
        $this->assertSame(6.0, data_get($snapshot, 'network_jobs.queue_lag_p95_seconds'));
        $this->assertSame(2.0, data_get($snapshot, 'network_jobs.signal_to_pull_p95_seconds'));
        $this->assertSame(2.0, data_get($snapshot, 'network_jobs.pull_to_start_p95_seconds'));
        $this->assertSame(4.0, data_get($snapshot, 'network_jobs.signal_to_start_p95_seconds'));
        $this->assertSame(1, data_get($snapshot, 'network_jobs.realtime_sample_count'));
        $this->assertSame(1, data_get($snapshot, 'nodes.available'));
        $this->assertSame(4096, data_get($snapshot, 'artifacts.storage_bytes'));
        $this->assertSame(1, data_get($snapshot, 'ai_api.requests'));
        $this->assertSame(0.00125, data_get($snapshot, 'ai_api.charged_cost_usd'));
        $this->assertSame(42, data_get($snapshot, 'ai_api.cost_by_team.0.team_id'));
        $this->assertSame(1, data_get($snapshot, 'ai_api.budget_alerts'));
        $this->assertContains('oldest_pending_job', collect($snapshot['alerts'])->pluck('code')->all());
        $this->assertContains('ai_api_budget_near_limit', collect($snapshot['alerts'])->pluck('code')->all());
    }

    public function test_health_command_can_fail_a_monitor_when_a_critical_slo_is_breached(): void
    {
        Cache::forever(OperationalHeartbeatService::SCHEDULER_KEY, [
            'recorded_at' => now()->subMinutes(10)->toIso8601String(),
        ]);
        Cache::forever(OperationalHeartbeatService::WORKER_KEY, [
            'recorded_at' => now()->subMinutes(10)->toIso8601String(),
        ]);

        $exit = Artisan::call('operations:health', ['--json' => true, '--fail-on-alert' => true]);

        $this->assertSame(1, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('scheduler_heartbeat', collect($payload['alerts'])->pluck('code')->all());
        $this->assertContains('worker_heartbeat', collect($payload['alerts'])->pluck('code')->all());
    }

    public function test_rejected_enrollments_raise_a_deduplicated_payload_free_alert(): void
    {
        config([
            'operations.thresholds.enrollment_alert_window_minutes' => 15,
            'operations.thresholds.enrollment_rejection_warning' => 2,
            'operations.thresholds.enrollment_rejection_critical' => 3,
        ]);
        $secret = 'bootstrap-secret-must-never-reach-operations-log';

        foreach (range(1, 3) as $attempt) {
            NodeCredentialEvent::query()->create([
                'node_uuid' => 'rejected-node-'.$attempt,
                'event_type' => 'enrollment.rejected',
                'outcome' => 'rejected',
                'ip_hash' => hash('sha256', '192.0.2.'.$attempt),
                'metadata_json' => ['unsafe_upstream_value' => $secret],
                'occurred_at' => now()->subMinute(),
            ]);
        }

        $handler = new TestHandler;
        Log::swap(new IlluminateLogger(
            new MonologLogger('operations-security-test', [$handler]),
            $this->app['events'],
        ));

        $snapshot = app(OperationalMetricsService::class)->snapshot();
        $first = app(OperationalAlertService::class)->dispatch();
        $second = app(OperationalAlertService::class)->dispatch();

        $this->assertSame(3, data_get($snapshot, 'security.enrollment.rejected'));
        $this->assertSame(3, data_get($snapshot, 'security.enrollment.distinct_ip_fingerprints'));
        $this->assertContains('node_enrollment_rejections', collect($snapshot['alerts'])->pluck('code')->all());
        $this->assertContains('node_enrollment_rejections', $first['emitted']);
        $this->assertContains('node_enrollment_rejections', $second['suppressed']);
        $this->assertStringNotContainsString(
            $secret,
            json_encode($handler->getRecords(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_snapshot_reports_task_type_and_portal_success_metrics_with_alerts(): void
    {
        config([
            'operations.thresholds.task_type_success_minimum_runs' => 2,
            'operations.thresholds.task_type_success_rate_percent' => 75,
            'operations.thresholds.portal_success_minimum_attempts' => 4,
            'operations.thresholds.portal_success_rate_percent' => 75,
        ]);
        $workflowId = DB::table('workflows')->insertGetId([
            'name' => 'Operations metrics workflow',
            'slug' => 'operations-metrics-workflow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stepId = DB::table('workflow_steps')->insertGetId([
            'workflow_id' => $workflowId,
            'name' => 'Click task',
            'type' => 'browser_task',
            'action_key' => 'browser.click',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (range(1, 2) as $attempt) {
            $runId = DB::table('workflow_runs')->insertGetId([
                'run_uuid' => (string) str()->uuid(),
                'workflow_id' => $workflowId,
                'status' => 'failed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('workflow_step_runs')->insert([
                'workflow_run_id' => $runId,
                'workflow_step_id' => $stepId,
                'status' => 'failed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        WorkflowPortalProfile::query()->create([
            'domain' => 'portal.example.test',
            'role' => 'submit',
            'selector' => '#submit',
            'selector_hash' => hash('sha256', '#submit'),
            'hit_count' => 1,
            'miss_count' => 3,
        ]);

        $snapshot = app(OperationalMetricsService::class)->snapshot();

        $this->assertSame('browser.click', data_get($snapshot, 'workflows.task_types.0.task_type'));
        $this->assertSame(0.0, data_get($snapshot, 'workflows.task_types.0.success_rate_percent'));
        $this->assertSame('portal.example.test', data_get($snapshot, 'portal_profiles.by_domain.0.domain'));
        $this->assertSame(25.0, data_get($snapshot, 'portal_profiles.by_domain.0.success_rate_percent'));
        $this->assertContains('task_type_success_rate', collect($snapshot['alerts'])->pluck('code')->all());
        $this->assertContains('portal_success_rate', collect($snapshot['alerts'])->pluck('code')->all());
    }

    public function test_admin_dashboard_renders_the_operational_contract(): void
    {
        Livewire::test(OperationsDashboard::class)
            ->assertSee('Betrieb &amp; SLOs', false)
            ->assertSee('Offene Client-Jobs')
            ->assertSee('Workflow-Erfolg')
            ->assertSee('Copilot-Kosten')
            ->assertSee('AI-API-Kosten')
            ->assertSee('operations:health --json --fail-on-alert');
    }
}
