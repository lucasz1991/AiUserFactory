<?php

namespace Tests\Feature;

use App\Jobs\DeleteTempFile;
use App\Jobs\ExpireWorkflowRunsJob;
use App\Jobs\GeneratePersonImages;
use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\PlanPersonaNetworkActivities;
use App\Jobs\ReconcileWorkflowCopilotSessionsJob;
use App\Jobs\RecordOperationsWorkerHeartbeat;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Services\Operations\OperationalHeartbeatService;
use App\Services\Operations\OperationalMetricsService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Support\WorkflowQueues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_routes_control_ai_and_general_jobs_to_independent_lanes(): void
    {
        config(['queue.default' => 'database']);

        foreach ([new RunWorkflowJob(1), new MonitorWorkflowStepRunJob(2), new ExpireWorkflowRunsJob, new ReconcileWorkflowCopilotSessionsJob] as $job) {
            Bus::dispatch($job);
        }

        foreach ([new WorkflowCopilotSupervisorJob(3), new PlanPersonaNetworkActivities, new GeneratePersonImages(4, 'synthetic', 'portrait', '1:1')] as $job) {
            Bus::dispatch($job);
        }

        Bus::dispatch(new DeleteTempFile('local', 'synthetic-does-not-exist'));

        $this->assertSame(4, Queue::connection(WorkflowQueues::CONTROL_CONNECTION)->size(WorkflowQueues::CONTROL));
        $this->assertSame(3, Queue::connection(WorkflowQueues::AI_CONNECTION)->size(WorkflowQueues::AI));
        $this->assertSame(1, Queue::connection('database')->size('default'));
    }

    public function test_control_worker_can_finish_monitoring_while_an_ai_job_is_reserved(): void
    {
        Bus::dispatch(new WorkflowCopilotSupervisorJob(3));
        Bus::dispatch(new MonitorWorkflowStepRunJob(12));
        $ai = Queue::connection(WorkflowQueues::AI_CONNECTION)->pop(WorkflowQueues::AI);
        $control = Queue::connection(WorkflowQueues::CONTROL_CONNECTION)->pop(WorkflowQueues::CONTROL);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('monitorStepRun')->once()->with(12);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        $this->assertNotNull($ai);
        $this->assertNotNull($control);
        $control->fire();
        $control->delete();

        $this->assertSame(0, Queue::connection(WorkflowQueues::CONTROL_CONNECTION)->size(WorkflowQueues::CONTROL));
        $this->assertSame(1, Queue::connection(WorkflowQueues::AI_CONNECTION)->size(WorkflowQueues::AI));
        $this->assertNotNull(DB::table('jobs')->where('id', $ai->getJobId())->value('reserved_at'));
    }

    public function test_queue_dispatch_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        DB::beginTransaction();
        Bus::dispatch(new RunWorkflowJob(1));
        Bus::dispatch(new WorkflowCopilotSupervisorJob(2));
        $this->assertSame(0, DB::table('jobs')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(function (): void {
            Bus::dispatch(new RunWorkflowJob(1));
            Bus::dispatch(new WorkflowCopilotSupervisorJob(2));
            $this->assertSame(0, DB::table('jobs')->count());
        });

        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_long_ai_job_is_not_available_for_duplicate_processing_before_its_timeout(): void
    {
        $this->freezeTime();
        Bus::dispatch(new GeneratePersonImages(4, 'synthetic', 'portrait', '1:1'));
        $queue = Queue::connection(WorkflowQueues::AI_CONNECTION);
        $first = $queue->pop(WorkflowQueues::AI);
        $this->assertNotNull($first);
        $this->travel(1801)->seconds();
        $this->assertNull($queue->pop(WorkflowQueues::AI));
        $this->travel(60)->seconds();
        $this->assertSame($first->getJobId(), $queue->pop(WorkflowQueues::AI)->getJobId());
    }

    public function test_health_records_each_pool_and_distinguishes_delays_from_waiting_jobs(): void
    {
        Cache::flush();
        $this->freezeTime();
        $heartbeats = app(OperationalHeartbeatService::class);
        $heartbeats->recordScheduler();

        foreach (array_keys(WorkflowQueues::lanes()) as $lane) {
            (new RecordOperationsWorkerHeartbeat($lane))->handle($heartbeats);
        }

        Queue::connection(WorkflowQueues::CONTROL_CONNECTION)->later(300, new RunWorkflowJob(1), '', WorkflowQueues::CONTROL);
        Queue::connection(WorkflowQueues::CONTROL_CONNECTION)->push(new MonitorWorkflowStepRunJob(2), '', WorkflowQueues::CONTROL);
        Queue::connection(WorkflowQueues::AI_CONNECTION)->push(new WorkflowCopilotSupervisorJob(3), '', WorkflowQueues::AI);
        Queue::connection(WorkflowQueues::AI_CONNECTION)->pop(WorkflowQueues::AI);
        $this->travel(31)->seconds();
        $snapshot = app(OperationalMetricsService::class)->snapshot();

        $this->assertSame(1, data_get($snapshot, 'queues.workflow-control.ready'));
        $this->assertSame(1, data_get($snapshot, 'queues.workflow-control.delayed'));
        $this->assertSame(31, data_get($snapshot, 'queues.workflow-control.oldest_ready_seconds'));
        $this->assertSame(1, data_get($snapshot, 'queues.workflow-ai.reserved'));
        $this->assertSame('ok', data_get($snapshot, 'queues.workflow-ai.heartbeat.status'));
        $this->assertContains('queue_workflow-control_backlog', array_column($snapshot['alerts'], 'code'));
        $this->assertNotContains('queue_workflow-ai_backlog', array_column($snapshot['alerts'], 'code'));
        $this->assertNotSame((new RecordOperationsWorkerHeartbeat(WorkflowQueues::AI))->uniqueId(), (new RecordOperationsWorkerHeartbeat(WorkflowQueues::CONTROL))->uniqueId());

        $this->travel(240)->seconds();
        $heartbeats->recordWorker('default');
        $heartbeats->recordWorker(WorkflowQueues::AI);
        $snapshot = app(OperationalMetricsService::class)->snapshot();
        $this->assertSame('stale', data_get($snapshot, 'queues.workflow-control.heartbeat.status'));
        $this->assertSame('ok', data_get($snapshot, 'queues.workflow-ai.heartbeat.status'));
        $this->assertContains('queue_workflow-control_heartbeat', array_column($snapshot['alerts'], 'code'));
    }
}
