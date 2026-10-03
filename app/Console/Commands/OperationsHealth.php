<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalMetricsService;
use Illuminate\Console\Command;

class OperationsHealth extends Command
{
    protected $signature = 'operations:health {--json : Maschinenlesbares JSON ausgeben} {--fail-on-alert : Bei kritischen Meldungen mit Fehlercode enden}';

    protected $description = 'Zeigt Scheduler-, Worker-, Queue-, Workflow-, Node-, Copilot- und Artifact-Gesundheit.';

    public function handle(OperationalMetricsService $metrics): int
    {
        $snapshot = $metrics->snapshot();

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Metrik', 'Wert'], [
                ['Scheduler', data_get($snapshot, 'liveness.scheduler.status')],
                ['Queue-Worker', data_get($snapshot, 'liveness.worker.status')],
                ['Offene Client-Jobs', data_get($snapshot, 'network_jobs.pending_count')],
                ['Ältester Client-Job (s)', data_get($snapshot, 'network_jobs.oldest_pending_seconds')],
                ['Queue-Lag P95 (s)', data_get($snapshot, 'network_jobs.queue_lag_p95_seconds', 'n/a')],
                ['Workflow-Erfolg (%)', data_get($snapshot, 'workflows.success_rate_percent', 'n/a')],
                ['Online Nodes', data_get($snapshot, 'nodes.online').'/'.data_get($snapshot, 'nodes.total')],
                ['Copilot-Kosten (USD)', data_get($snapshot, 'copilot.cost_usd')],
                ['AI-API-Kosten (USD)', data_get($snapshot, 'ai_api.charged_cost_usd')],
                ['Enrollment-Ablehnungen', data_get($snapshot, 'security.enrollment.rejected')],
                ['Abgelaufene Cookie-Payloads', data_get($snapshot, 'security.cookies.stale_payloads')],
                ['Warnungen', count(data_get($snapshot, 'alerts', []))],
            ]);

            $this->table(['Queue', 'Worker', 'Faellig', 'Verzoegert', 'Reserviert', 'Aeltester faelliger Job (s)'],
                collect($snapshot['queues'])->map(fn (array $lane): array => [
                    $lane['queue'],
                    data_get($lane, 'heartbeat.status'),
                    $lane['ready'] ?? 'n/a',
                    $lane['delayed'] ?? 'n/a',
                    $lane['reserved'] ?? 'n/a',
                    $lane['oldest_ready_seconds'] ?? 'n/a',
                ])->values()->all(),
            );

            foreach (data_get($snapshot, 'alerts', []) as $alert) {
                $this->warn('['.strtoupper((string) ($alert['severity'] ?? 'warning')).'] '.($alert['message'] ?? 'Unbekannte Meldung'));
            }
        }

        $critical = collect(data_get($snapshot, 'alerts', []))->contains(
            fn (mixed $alert): bool => is_array($alert) && ($alert['severity'] ?? null) === 'critical',
        );

        return $this->option('fail-on-alert') && $critical ? self::FAILURE : self::SUCCESS;
    }
}
