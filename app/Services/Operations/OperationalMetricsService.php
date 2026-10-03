<?php

namespace App\Services\Operations;

use App\Models\AiApiDailyBudget;
use App\Models\AiApiRequestAudit;
use App\Models\ManagedProcess;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\NodeCredentialEvent;
use App\Models\NodeEnrollmentToken;
use App\Models\Person;
use App\Models\WorkflowCopilotEvent;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowPortalProfile;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Support\WorkflowQueues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalMetricsService
{
    public function __construct(private readonly OperationalHeartbeatService $heartbeats) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $windowDays = max(1, (int) config('operations.window_days', 30));
        $since = now()->subDays($windowDays);
        $liveness = $this->liveness();
        $networkJobs = $this->networkJobs($since);
        $queues = $this->queues();
        $workflows = $this->workflows($since);
        $copilot = $this->copilot($since);
        $aiApi = $this->aiApi($since);
        $nodes = $this->nodes();
        $portalProfiles = $this->portalProfiles();
        $security = $this->security();
        $artifacts = $this->artifacts();
        $runnerBaseline = $this->runnerBaseline($since);

        return [
            'generated_at' => now()->toIso8601String(),
            'window_days' => $windowDays,
            'liveness' => $liveness,
            'network_jobs' => $networkJobs,
            'queues' => $queues,
            'workflows' => $workflows,
            'copilot' => $copilot,
            'ai_api' => $aiApi,
            'nodes' => $nodes,
            'portal_profiles' => $portalProfiles,
            'security' => $security,
            'artifacts' => $artifacts,
            'runner_baseline' => $runnerBaseline,
            'alerts' => $this->alerts($liveness, $networkJobs, $workflows, $copilot, $aiApi, $portalProfiles, $security, $queues),
        ];
    }

    /** @return array<string, mixed> */
    private function liveness(): array
    {
        return [
            'scheduler' => $this->heartbeat(
                OperationalHeartbeatService::SCHEDULER_KEY,
                (int) config('operations.thresholds.scheduler_heartbeat_seconds', 180),
            ),
            'worker' => $this->heartbeat(
                OperationalHeartbeatService::WORKER_KEY,
                $this->workerHeartbeatSeconds('default'),
            ),
            'artifact_prune' => $this->heartbeat(
                OperationalHeartbeatService::ARTIFACT_PRUNE_KEY,
                (int) config('operations.thresholds.artifact_prune_hours', 48) * 3600,
            ),
            'cookie_prune' => $this->heartbeat(
                OperationalHeartbeatService::COOKIE_PRUNE_KEY,
                (int) config('operations.thresholds.cookie_prune_hours', 48) * 3600,
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function queues(): array
    {
        $database = DB::connection(config('queue.connections.database.connection'));
        $table = (string) config('queue.connections.database.table', 'jobs');
        $available = Schema::connection($database->getName())->hasTable($table);
        $now = now()->timestamp;
        $lanes = [];

        foreach (WorkflowQueues::lanes() as $queue => $connection) {
            $reservationSeconds = WorkflowQueues::reservationSeconds($queue);
            $metrics = null;

            if ($available) {
                $metrics = $database->table($table)->where('queue', $queue)
                    ->selectRaw('COUNT(*) AS pending')
                    ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready', [$now])
                    ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed', [$now])
                    ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
                    ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at <= ? THEN 1 ELSE 0 END) AS expired_reserved', [$now - $reservationSeconds])
                    ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at ELSE NULL END) AS oldest_ready_at', [$now])
                    ->first();
            }

            $lanes[$queue] = [
                'connection' => $connection,
                'queue' => $queue,
                'available' => $available,
                'retry_after_seconds' => $reservationSeconds,
                'heartbeat' => $this->heartbeat(OperationalHeartbeatService::workerKey($queue), $this->workerHeartbeatSeconds($queue)),
                'pending' => $metrics ? (int) $metrics->pending : null,
                'ready' => $metrics ? (int) $metrics->ready : null,
                'delayed' => $metrics ? (int) $metrics->delayed : null,
                'reserved' => $metrics ? (int) $metrics->reserved : null,
                'expired_reserved' => $metrics ? (int) $metrics->expired_reserved : null,
                'oldest_ready_seconds' => $metrics && $metrics->oldest_ready_at !== null ? max(0, $now - (int) $metrics->oldest_ready_at) : null,
            ];
        }

        return $lanes;
    }

    private function workerHeartbeatSeconds(string $queue): int
    {
        // A probe on a healthy AI pool can wait behind the longest allowed job.
        return max((int) config('operations.thresholds.worker_heartbeat_seconds', 180), WorkflowQueues::reservationSeconds($queue) + 120);
    }

    /** @return array<string, mixed> */
    private function networkJobs(Carbon $since): array
    {
        if (! Schema::hasTable('network_jobs')) {
            return $this->emptyNetworkJobs();
        }

        $recent = NetworkJob::query()->where('created_at', '>=', $since);
        $statusCounts = (clone $recent)
            ->select(['status'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
        $pending = NetworkJob::query()
            ->whereIn('status', ['pending', 'dispatched', 'stop_requested', 'unreachable'])
            ->orderByRaw('COALESCE(queued_at, created_at) asc')
            ->first();
        $lagSamples = (clone $recent)
            ->whereNotNull('dispatched_at')
            ->where(function ($query): void {
                $query->whereNotNull('queued_at')->orWhereNotNull('created_at');
            })
            ->latest('id')
            ->limit(5000)
            ->get(['queued_at', 'created_at', 'dispatched_at'])
            ->map(fn (NetworkJob $job): float => max(0, ($job->queued_at ?? $job->created_at)->floatDiffInSeconds($job->dispatched_at)))
            ->all();
        $signalToPull = $this->durationSamples($recent, 'signaled_at', 'pulled_at');
        $pullToStart = $this->durationSamples($recent, 'pulled_at', 'started_at');
        $signalToStart = $this->durationSamples($recent, 'signaled_at', 'started_at');

        return [
            'total' => array_sum($statusCounts),
            'status_counts' => $statusCounts,
            'pending_count' => array_sum(array_intersect_key($statusCounts, array_flip(['pending', 'dispatched', 'stop_requested', 'unreachable']))),
            'oldest_pending_seconds' => $pending
                ? max(0, (int) ($pending->queued_at ?? $pending->created_at)->diffInSeconds(now()))
                : 0,
            'oldest_pending_uuid' => $pending?->job_uuid,
            'queue_lag_p50_seconds' => $this->percentile($lagSamples, 50),
            'queue_lag_p95_seconds' => $this->percentile($lagSamples, 95),
            'signal_to_pull_p95_seconds' => $this->percentile($signalToPull, 95),
            'pull_to_start_p95_seconds' => $this->percentile($pullToStart, 95),
            'signal_to_start_p95_seconds' => $this->percentile($signalToStart, 95),
            'realtime_sample_count' => count($signalToStart),
            'sample_count' => count($lagSamples),
            'attempts_total' => (int) (clone $recent)->sum('attempt_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function workflows(Carbon $since): array
    {
        if (! Schema::hasTable('workflow_runs')) {
            return ['total' => 0, 'completed' => 0, 'failed' => 0, 'success_rate_percent' => null, 'task_types' => []];
        }

        $runs = WorkflowRun::query()->where('created_at', '>=', $since);
        $counts = (clone $runs)
            ->select(['status'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
        $completed = (int) ($counts['completed'] ?? 0);
        $terminal = collect(['completed', 'failed', 'cancelled', 'timed_out', 'lost'])
            ->sum(fn (string $status): int => (int) ($counts[$status] ?? 0));

        return [
            'total' => array_sum($counts),
            'completed' => $completed,
            'failed' => (int) ($counts['failed'] ?? 0),
            'active' => collect(['queued', 'running', 'waiting', 'paused', 'stop_requested', 'unreachable'])
                ->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)),
            'status_counts' => $counts,
            'terminal_count' => $terminal,
            'success_rate_percent' => $terminal > 0 ? round(($completed / $terminal) * 100, 1) : null,
            'task_types' => $this->workflowTaskTypes($since),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function workflowTaskTypes(Carbon $since): array
    {
        if (! Schema::hasTable('workflow_step_runs') || ! Schema::hasTable('workflow_steps')) {
            return [];
        }

        return DB::table('workflow_step_runs')
            ->join('workflow_steps', 'workflow_steps.id', '=', 'workflow_step_runs.workflow_step_id')
            ->where('workflow_step_runs.created_at', '>=', $since)
            ->select(['workflow_steps.action_key', 'workflow_step_runs.status'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('workflow_steps.action_key', 'workflow_step_runs.status')
            ->get()
            ->groupBy(fn (object $row): string => trim((string) $row->action_key) ?: 'unknown')
            ->map(function (Collection $rows, string $type): array {
                $total = (int) $rows->sum(fn (object $row): int => (int) $row->aggregate);
                $statusCounts = $rows->mapWithKeys(
                    fn (object $row): array => [(string) $row->status => (int) $row->aggregate],
                )->all();
                $completed = (int) ($statusCounts['completed'] ?? 0);
                $terminal = collect(['completed', 'failed', 'cancelled', 'timed_out', 'lost'])
                    ->sum(fn (string $status): int => (int) ($statusCounts[$status] ?? 0));

                return [
                    'task_type' => $type,
                    'total' => $total,
                    'completed' => $completed,
                    'failed' => (int) ($statusCounts['failed'] ?? 0),
                    'terminal_count' => $terminal,
                    'status_counts' => $statusCounts,
                    'success_rate_percent' => $terminal > 0 ? round(($completed / $terminal) * 100, 1) : null,
                ];
            })
            ->sortByDesc('total')
            ->take(20)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function copilot(Carbon $since): array
    {
        if (! Schema::hasTable('workflow_copilot_sessions')) {
            return ['sessions' => 0, 'repairs' => 0, 'manual_interventions' => 0, 'cost_usd' => 0.0, 'budget_alerts' => 0, 'cost_by_workflow' => []];
        }

        $sessions = WorkflowCopilotSession::query()
            ->with('workflow:id,name')
            ->where('created_at', '>=', $since)
            ->get();
        $costByWorkflow = $sessions
            ->groupBy('workflow_id')
            ->map(function (Collection $workflowSessions): array {
                $first = $workflowSessions->first();

                return [
                    'workflow_id' => (int) ($first?->workflow_id ?? 0),
                    'workflow_name' => (string) ($first?->workflow?->name ?? 'Unbekannt'),
                    'sessions' => $workflowSessions->count(),
                    'cost_usd' => round($workflowSessions->sum(fn (WorkflowCopilotSession $session): float => (float) data_get($session->usage_json, 'cost_usd', 0)), 6),
                ];
            })
            ->sortByDesc('cost_usd')
            ->take(20)
            ->values()
            ->all();
        $budgetAlerts = $sessions->filter(function (WorkflowCopilotSession $session): bool {
            $limit = (float) data_get($session->budget_json, 'max_cost_usd', 0);

            return $limit > 0 && (float) data_get($session->usage_json, 'cost_usd', 0) >= $limit;
        })->count();
        $events = Schema::hasTable('workflow_copilot_events')
            ? WorkflowCopilotEvent::query()->where('created_at', '>=', $since)
            : null;

        return [
            'sessions' => $sessions->count(),
            'succeeded' => $sessions->where('status', WorkflowCopilotSession::STATUS_SUCCEEDED)->count(),
            'repairs' => $events ? (clone $events)->where('event_type', 'like', 'repair.%')->count() : 0,
            'manual_interventions' => $events ? (clone $events)->where('event_type', 'assistance.requested')->count() : 0,
            'cost_usd' => round($sessions->sum(fn (WorkflowCopilotSession $session): float => (float) data_get($session->usage_json, 'cost_usd', 0)), 6),
            'provider_cost_usd' => round($sessions->sum(fn (WorkflowCopilotSession $session): float => (float) data_get($session->usage_json, 'provider_cost_usd', 0)), 6),
            'budget_alerts' => $budgetAlerts,
            'cost_by_workflow' => $costByWorkflow,
        ];
    }

    /** @return array<string, mixed> */
    private function aiApi(Carbon $since): array
    {
        if (! Schema::hasTable('ai_api_request_audits')) {
            return [
                'requests' => 0,
                'succeeded' => 0,
                'budget_rejections' => 0,
                'recent_budget_rejections' => 0,
                'budget_alerts' => 0,
                'charged_cost_usd' => 0.0,
                'cost_by_team' => [],
            ];
        }

        $audits = AiApiRequestAudit::query()
            ->where('created_at', '>=', $since)
            ->get(['team_id', 'status', 'charged_cost_microusd']);
        $costByTeam = $audits
            ->where('status', '!=', 'budget_rejected')
            ->groupBy(fn (AiApiRequestAudit $audit): string => $audit->team_id === null ? 'none' : (string) $audit->team_id)
            ->map(function (Collection $teamAudits, string $teamKey): array {
                return [
                    'team_id' => $teamKey === 'none' ? null : (int) $teamKey,
                    'requests' => $teamAudits->count(),
                    'cost_usd' => round($teamAudits->sum('charged_cost_microusd') / 1_000_000, 6),
                ];
            })
            ->sortByDesc('cost_usd')
            ->take(20)
            ->values()
            ->all();
        $budgetAlerts = 0;
        $recentBudgetRejections = AiApiRequestAudit::query()
            ->where('status', 'budget_rejected')
            ->where('created_at', '>=', now()->subMinutes(
                (int) config('operations.thresholds.ai_api_alert_window_minutes', 60),
            ))
            ->count();

        if (Schema::hasTable('ai_api_daily_budgets')) {
            $budgetAlerts = AiApiDailyBudget::query()
                ->whereDate('usage_date', now('UTC')->toDateString())
                ->get(['scope_type', 'committed_cost_microusd'])
                ->filter(function (AiApiDailyBudget $budget): bool {
                    $limitUsd = $budget->scope_type === 'team'
                        ? config('ai_api.team_daily_budget_usd', 50.0)
                        : config('ai_api.user_daily_budget_usd', 10.0);
                    $limit = max(0, (int) floor((float) $limitUsd * 1_000_000));

                    $warningRatio = (int) config('operations.thresholds.ai_api_budget_warning_percent', 90) / 100;

                    return $limit > 0
                        && (int) $budget->committed_cost_microusd >= (int) floor($limit * $warningRatio);
                })
                ->count();
        }

        return [
            'requests' => $audits->count(),
            'succeeded' => $audits->where('status', 'succeeded')->count(),
            'budget_rejections' => $audits->where('status', 'budget_rejected')->count(),
            'recent_budget_rejections' => $recentBudgetRejections,
            'budget_alerts' => $budgetAlerts,
            'charged_cost_usd' => round($audits->where('status', '!=', 'budget_rejected')->sum('charged_cost_microusd') / 1_000_000, 6),
            'cost_by_team' => $costByTeam,
        ];
    }

    /** @return array<string, int> */
    private function nodes(): array
    {
        if (! Schema::hasTable('network_nodes')) {
            return ['total' => 0, 'online' => 0, 'available' => 0, 'offline' => 0];
        }

        $total = NetworkNode::query()->count();
        $online = NetworkNode::query()->where('is_online', true)->count();

        return [
            'total' => $total,
            'online' => $online,
            'available' => NetworkNode::query()->available()->count(),
            'offline' => max(0, $total - $online),
        ];
    }

    /** @return array<string, mixed> */
    private function portalProfiles(): array
    {
        if (! Schema::hasTable('workflow_portal_profiles')) {
            return ['total' => 0, 'active' => 0, 'expired' => 0, 'conflicts' => 0, 'by_domain' => []];
        }

        $profiles = WorkflowPortalProfile::query()->get();
        $usable = $profiles->filter->isUsable();
        $conflicts = $usable
            ->groupBy(fn (WorkflowPortalProfile $profile): string => $profile->domain.'|'.$profile->role)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->count();
        $byDomain = $profiles
            ->groupBy(fn (WorkflowPortalProfile $profile): string => trim((string) $profile->domain) ?: 'unknown')
            ->map(function (Collection $domainProfiles, string $domain): array {
                $hits = (int) $domainProfiles->sum('hit_count');
                $misses = (int) $domainProfiles->sum('miss_count');
                $attempts = $hits + $misses;

                return [
                    'domain' => $domain,
                    'selectors' => $domainProfiles->count(),
                    'hits' => $hits,
                    'misses' => $misses,
                    'attempts' => $attempts,
                    'success_rate_percent' => $attempts > 0 ? round(($hits / $attempts) * 100, 1) : null,
                ];
            })
            ->sortByDesc('attempts')
            ->take(20)
            ->values()
            ->all();

        return [
            'total' => $profiles->count(),
            'active' => $usable->count(),
            'expired' => $profiles->filter->isExpired()->count(),
            'conflicts' => $conflicts,
            'by_domain' => $byDomain,
        ];
    }

    /** @return array<string, mixed> */
    private function security(): array
    {
        $windowMinutes = (int) config('operations.thresholds.enrollment_alert_window_minutes', 15);
        $enrollment = [
            'window_minutes' => $windowMinutes,
            'rejected' => 0,
            'distinct_ip_fingerprints' => 0,
            'distinct_node_uuids' => 0,
            'expired_unused_tokens' => 0,
        ];

        if (Schema::hasTable('node_credential_events')) {
            $rejections = NodeCredentialEvent::query()
                ->where('event_type', 'enrollment.rejected')
                ->where('outcome', 'rejected')
                ->where('occurred_at', '>=', now()->subMinutes($windowMinutes));

            $enrollment['rejected'] = (clone $rejections)->count();
            $enrollment['distinct_ip_fingerprints'] = (clone $rejections)
                ->whereNotNull('ip_hash')
                ->distinct('ip_hash')
                ->count('ip_hash');
            $enrollment['distinct_node_uuids'] = (clone $rejections)
                ->whereNotNull('node_uuid')
                ->distinct('node_uuid')
                ->count('node_uuid');
        }

        if (Schema::hasTable('node_enrollment_tokens')) {
            $enrollment['expired_unused_tokens'] = NodeEnrollmentToken::query()
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '<=', now())
                ->count();
        }

        $retentionDays = max(1, (int) config('security.cookie_files.retention_days', 30));
        $stalePayloads = 0;

        if (Schema::hasTable('persons')) {
            $cutoff = now('UTC')->subDays($retentionDays);
            $stalePayloads = Person::withTrashed()
                ->whereNotNull('cookie_payload')
                ->where(function ($query) use ($cutoff): void {
                    $query->where('cookies_synced_at', '<', $cutoff)
                        ->orWhere(function ($inner) use ($cutoff): void {
                            $inner->whereNull('cookies_synced_at')->where('updated_at', '<', $cutoff);
                        });
                })
                ->count();
        }

        return [
            'enrollment' => $enrollment,
            'cookies' => [
                'retention_days' => $retentionDays,
                'stale_payloads' => $stalePayloads,
                'last_pruned' => $this->heartbeats->latest(OperationalHeartbeatService::COOKIE_PRUNE_KEY),
                'last_key_rotation' => $this->heartbeats->latest(OperationalHeartbeatService::COOKIE_KEY_ROTATION_KEY),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function artifacts(): array
    {
        $latest = $this->heartbeats->latest(OperationalHeartbeatService::ARTIFACT_PRUNE_KEY);

        return [
            'database_records' => Schema::hasTable('workflow_run_artifacts') ? WorkflowRunArtifact::query()->count() : 0,
            'last_pruned_at' => $latest['recorded_at'] ?? null,
            'last_removed' => is_array($latest['removed'] ?? null) ? $latest['removed'] : [],
            'storage_bytes' => max(0, (int) ($latest['storage_bytes'] ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function runnerBaseline(Carbon $since): array
    {
        if (! Schema::hasTable('managed_processes')) {
            return [
                'process_starts' => 0,
                'root_process_starts' => 0,
                'processes_per_run_p95' => null,
                'detection_lag_p95_seconds' => null,
                'memory_p95_mb' => null,
                'termination_latency_p95_seconds' => null,
                'restart_count' => 0,
                'idle_suspects' => 0,
            ];
        }

        $processes = ManagedProcess::query()
            ->where('created_at', '>=', $since)
            ->get([
                'run_id',
                'is_root',
                'is_idle_suspect',
                'memory_mb',
                'started_at',
                'detected_at',
                'last_action_at',
                'exited_at',
                'restart_count',
            ]);
        $perRun = $processes
            ->filter(fn (ManagedProcess $process): bool => filled($process->run_id))
            ->countBy(fn (ManagedProcess $process): string => (string) $process->run_id)
            ->values()
            ->all();
        $detectionLag = $processes
            ->filter(fn (ManagedProcess $process): bool => $process->started_at !== null && $process->detected_at !== null)
            ->map(fn (ManagedProcess $process): float => max(0, $process->started_at->floatDiffInSeconds($process->detected_at)))
            ->all();
        $terminationLatency = $processes
            ->filter(fn (ManagedProcess $process): bool => $process->last_action_at !== null && $process->exited_at !== null)
            ->map(fn (ManagedProcess $process): float => max(0, $process->last_action_at->floatDiffInSeconds($process->exited_at)))
            ->all();

        return [
            'process_starts' => $processes->count(),
            'root_process_starts' => $processes->where('is_root', true)->count(),
            'processes_per_run_p95' => $this->percentile($perRun, 95),
            'detection_lag_p95_seconds' => $this->percentile($detectionLag, 95),
            'memory_p95_mb' => $this->percentile($processes->pluck('memory_mb')->filter(fn (mixed $value): bool => is_numeric($value))->map(fn (mixed $value): float => (float) $value)->all(), 95),
            'termination_latency_p95_seconds' => $this->percentile($terminationLatency, 95),
            'restart_count' => (int) $processes->sum('restart_count'),
            'idle_suspects' => $processes->where('is_idle_suspect', true)->count(),
        ];
    }

    /** @return list<array{severity:string, code:string, message:string}> */
    private function alerts(
        array $liveness,
        array $jobs,
        array $workflows,
        array $copilot,
        array $aiApi,
        array $portalProfiles,
        array $security,
        array $queues,
    ): array {
        $alerts = [];

        foreach ($queues as $queue => $lane) {
            if ($queue !== 'default' && data_get($lane, 'heartbeat.status') !== 'ok') {
                $alerts[] = [
                    'severity' => data_get($lane, 'heartbeat.status') === 'stale' ? 'critical' : 'warning',
                    'code' => 'queue_'.$queue.'_heartbeat',
                    'message' => 'Queue '.$queue.': Worker-Heartbeat ist '.(data_get($lane, 'heartbeat.status') === 'stale' ? 'veraltet.' : 'noch nicht belegt.'),
                ];
            }

            $maxWait = $queue === WorkflowQueues::CONTROL ? 30 : WorkflowQueues::reservationSeconds($queue);

            if (($lane['oldest_ready_seconds'] ?? 0) > $maxWait || ($lane['expired_reserved'] ?? 0) > 0) {
                $alerts[] = [
                    'severity' => 'critical',
                    'code' => 'queue_'.$queue.'_backlog',
                    'message' => 'Queue '.$queue.': faellige Jobs warten zu lange oder eine Worker-Reservierung ist abgelaufen.',
                ];
            }
        }

        foreach ([
            'scheduler' => 'Scheduler',
            'worker' => 'Queue-Worker',
            'artifact_prune' => 'Artifact-Prune',
            'cookie_prune' => 'Cookie-Prune',
        ] as $key => $label) {
            if (($liveness[$key]['status'] ?? 'unknown') !== 'ok') {
                $alerts[] = [
                    'severity' => ($liveness[$key]['status'] ?? null) === 'stale' ? 'critical' : 'warning',
                    'code' => $key.'_heartbeat',
                    'message' => $label.'-Heartbeat ist '.(($liveness[$key]['status'] ?? null) === 'stale' ? 'veraltet.' : 'noch nicht belegt.'),
                ];
            }
        }

        if (($jobs['oldest_pending_seconds'] ?? 0) > (int) config('operations.thresholds.oldest_pending_job_seconds', 120)) {
            $alerts[] = ['severity' => 'critical', 'code' => 'oldest_pending_job', 'message' => 'Der älteste Client-Job wartet länger als der Grenzwert.'];
        }

        if (($jobs['queue_lag_p95_seconds'] ?? 0) > (float) config('operations.thresholds.queue_lag_p95_seconds', 30)) {
            $alerts[] = ['severity' => 'warning', 'code' => 'queue_lag_p95', 'message' => 'Der P95 Queue-Lag liegt über dem Grenzwert.'];
        }

        if (($jobs['realtime_sample_count'] ?? 0) > 0
            && ($jobs['signal_to_start_p95_seconds'] ?? 0) > (float) config('operations.thresholds.signal_to_start_p95_seconds', 3)) {
            $alerts[] = ['severity' => 'warning', 'code' => 'signal_to_start_p95', 'message' => 'Der P95 von Realtime-Signal bis Jobstart liegt über dem SLO.'];
        }

        if (($workflows['terminal_count'] ?? 0) >= (int) config('operations.thresholds.workflow_success_minimum_runs', 10)
            && ($workflows['success_rate_percent'] ?? 100) < (float) config('operations.thresholds.workflow_success_rate_percent', 80)) {
            $alerts[] = ['severity' => 'warning', 'code' => 'workflow_success_rate', 'message' => 'Die Workflow-Erfolgsquote liegt unter dem Zielwert.'];
        }

        $unhealthyTaskTypes = collect($workflows['task_types'] ?? [])->filter(
            fn (mixed $metric): bool => is_array($metric)
                && ($metric['terminal_count'] ?? 0) >= (int) config('operations.thresholds.task_type_success_minimum_runs', 10)
                && ($metric['success_rate_percent'] ?? 100) < (float) config('operations.thresholds.task_type_success_rate_percent', 75),
        )->count();

        if ($unhealthyTaskTypes > 0) {
            $alerts[] = ['severity' => 'warning', 'code' => 'task_type_success_rate', 'message' => 'Mindestens ein Workflow-Tasktyp liegt unter der Erfolgsquote.'];
        }

        $unhealthyPortals = collect($portalProfiles['by_domain'] ?? [])->filter(
            fn (mixed $metric): bool => is_array($metric)
                && ($metric['attempts'] ?? 0) >= (int) config('operations.thresholds.portal_success_minimum_attempts', 10)
                && ($metric['success_rate_percent'] ?? 100) < (float) config('operations.thresholds.portal_success_rate_percent', 75),
        )->count();

        if ($unhealthyPortals > 0) {
            $alerts[] = ['severity' => 'warning', 'code' => 'portal_success_rate', 'message' => 'Mindestens ein Portal liegt unter der Selector-Erfolgsquote.'];
        }

        if (($copilot['budget_alerts'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'critical', 'code' => 'copilot_budget', 'message' => 'Mindestens eine Copilot-Sitzung hat ihr Kostenbudget erreicht.'];
        }

        if (($aiApi['recent_budget_rejections'] ?? 0) > 0) {
            $alerts[] = ['severity' => 'critical', 'code' => 'ai_api_budget_rejected', 'message' => 'Mindestens eine typisierte AI-API-Anfrage wurde am Tagesbudget gestoppt.'];
        } elseif (($aiApi['budget_alerts'] ?? 0) > 0) {
            $warningPercent = (int) config('operations.thresholds.ai_api_budget_warning_percent', 90);
            $alerts[] = [
                'severity' => 'warning',
                'code' => 'ai_api_budget_near_limit',
                'message' => 'Mindestens ein AI-API-Tagesbudget ist zu '.$warningPercent.' Prozent ausgeschöpft.',
            ];
        }

        $enrollmentRejections = (int) data_get($security, 'enrollment.rejected', 0);
        $criticalEnrollmentThreshold = max(
            (int) config('operations.thresholds.enrollment_rejection_warning', 5),
            (int) config('operations.thresholds.enrollment_rejection_critical', 20),
        );

        if ($enrollmentRejections >= $criticalEnrollmentThreshold) {
            $alerts[] = ['severity' => 'critical', 'code' => 'node_enrollment_rejections', 'message' => 'Die Zahl fehlgeschlagener Node-Enrollments liegt über dem kritischen Grenzwert.'];
        } elseif ($enrollmentRejections >= (int) config('operations.thresholds.enrollment_rejection_warning', 5)) {
            $alerts[] = ['severity' => 'warning', 'code' => 'node_enrollment_rejections', 'message' => 'Mehrere Node-Enrollments wurden im Alarmfenster abgewiesen.'];
        }

        if ((int) data_get($security, 'cookies.stale_payloads', 0) > 0) {
            $alerts[] = ['severity' => 'warning', 'code' => 'stale_cookie_payloads', 'message' => 'Cookie-Sitzungen haben die konfigurierte Löschfrist überschritten.'];
        }

        return $alerts;
    }

    /** @return array{status:string, recorded_at:?string, age_seconds:?int, threshold_seconds:int, payload:array} */
    private function heartbeat(string $key, int $thresholdSeconds): array
    {
        $payload = $this->heartbeats->latest($key);
        $recordedAt = is_string($payload['recorded_at'] ?? null) ? $payload['recorded_at'] : null;
        $age = $recordedAt ? max(0, Carbon::parse($recordedAt)->diffInSeconds(now())) : null;

        return [
            'status' => $age === null ? 'unknown' : ($age <= $thresholdSeconds ? 'ok' : 'stale'),
            'recorded_at' => $recordedAt,
            'age_seconds' => $age,
            'threshold_seconds' => $thresholdSeconds,
            'payload' => $payload ?? [],
        ];
    }

    /** @return list<float> */
    private function durationSamples($query, string $from, string $to): array
    {
        if (! Schema::hasColumn('network_jobs', $from) || ! Schema::hasColumn('network_jobs', $to)) {
            return [];
        }

        return (clone $query)
            ->whereNotNull($from)
            ->whereNotNull($to)
            ->latest('id')
            ->limit(5000)
            ->get([$from, $to])
            ->map(fn (NetworkJob $job): float => max(0, Carbon::parse($job->{$from})->floatDiffInSeconds(Carbon::parse($job->{$to}))))
            ->all();
    }

    /** @param list<float|int> $values */
    private function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $index = max(0, min(count($values) - 1, (int) ceil(($percentile / 100) * count($values)) - 1));

        return round((float) $values[$index], 3);
    }

    /** @return array<string, mixed> */
    private function emptyNetworkJobs(): array
    {
        return [
            'total' => 0,
            'status_counts' => [],
            'pending_count' => 0,
            'oldest_pending_seconds' => 0,
            'oldest_pending_uuid' => null,
            'queue_lag_p50_seconds' => null,
            'queue_lag_p95_seconds' => null,
            'signal_to_pull_p95_seconds' => null,
            'pull_to_start_p95_seconds' => null,
            'signal_to_start_p95_seconds' => null,
            'realtime_sample_count' => 0,
            'sample_count' => 0,
            'attempts_total' => 0,
        ];
    }
}
