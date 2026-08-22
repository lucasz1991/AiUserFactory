<div class="space-y-6" wire:poll.30s aria-live="polite" data-operations-dashboard>
    <section class="rounded-2xl bg-slate-950 px-5 py-6 text-white shadow-xl sm:px-7" aria-labelledby="operations-title">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-300">Production Health</p>
                <h1 id="operations-title" class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Betrieb &amp; SLOs</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-300">Scheduler, Queue, Client-Jobs, Workflows, Copilot- und API-Kosten, Nodes und Artefakte in einem überprüfbaren Betriebsbild.</p>
            </div>
            <div class="text-right text-xs text-slate-400">
                <span class="block">Fenster: {{ $metrics['window_days'] }} Tage</span>
                <span class="mt-1 block">Stand {{ \Illuminate\Support\Carbon::parse($metrics['generated_at'])->format('d.m.Y H:i:s') }}</span>
            </div>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            @foreach(['scheduler' => 'Scheduler', 'worker' => 'Queue-Worker', 'artifact_prune' => 'Artifact-Prune'] as $key => $label)
                @php($health = data_get($metrics, 'liveness.'.$key, []))
                <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold">{{ $label }}</span>
                        <span class="h-2.5 w-2.5 rounded-full {{ ($health['status'] ?? null) === 'ok' ? 'bg-emerald-400' : (($health['status'] ?? null) === 'stale' ? 'bg-rose-400' : 'bg-amber-400') }}" aria-hidden="true"></span>
                    </div>
                    <strong class="mt-2 block text-lg">{{ match($health['status'] ?? 'unknown') { 'ok' => 'Aktuell', 'stale' => 'Veraltet', default => 'Noch unbelegt' } }}</strong>
                    <span class="mt-1 block text-xs text-slate-400">{{ isset($health['age_seconds']) ? $health['age_seconds'].' Sekunden alt' : 'wird beim nächsten Lauf erfasst' }}</span>
                </div>
            @endforeach
        </div>
    </section>

    @if($metrics['alerts'] !== [])
        <section class="space-y-2" aria-label="Betriebswarnungen">
            @foreach($metrics['alerts'] as $alert)
                <div class="rounded-xl border p-4 text-sm {{ $alert['severity'] === 'critical' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                    <strong>{{ $alert['severity'] === 'critical' ? 'Kritisch' : 'Warnung' }}:</strong> {{ $alert['message'] }}
                    <code class="ml-2 text-[11px] opacity-70">{{ $alert['code'] }}</code>
                </div>
            @endforeach
        </section>
    @else
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900">Alle überwachten Grenzwerte sind aktuell im grünen Bereich.</div>
    @endif

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" aria-label="Kernmetriken">
        @foreach([
            ['Offene Client-Jobs', $metrics['network_jobs']['pending_count'], 'Ältester: '.$metrics['network_jobs']['oldest_pending_seconds'].' s'],
            ['Queue-Lag P95', $metrics['network_jobs']['queue_lag_p95_seconds'] !== null ? $metrics['network_jobs']['queue_lag_p95_seconds'].' s' : 'n/a', $metrics['network_jobs']['sample_count'].' Stichproben'],
            ['Workflow-Erfolg', $metrics['workflows']['success_rate_percent'] !== null ? number_format($metrics['workflows']['success_rate_percent'], 1, ',', '.').' %' : 'n/a', $metrics['workflows']['terminal_count'].' abgeschlossene Läufe'],
            ['Copilot-Kosten', '$ '.number_format($metrics['copilot']['cost_usd'], 4, '.', ','), $metrics['copilot']['sessions'].' Sitzungen'],
            ['AI-API-Kosten', '$ '.number_format($metrics['ai_api']['charged_cost_usd'], 4, '.', ','), $metrics['ai_api']['requests'].' Anfragen'],
            ['Realtime Signal → Start P95', $metrics['network_jobs']['signal_to_start_p95_seconds'] !== null ? $metrics['network_jobs']['signal_to_start_p95_seconds'].' s' : 'noch ohne Daten', $metrics['network_jobs']['realtime_sample_count'].' Stichproben'],
            ['Nodes online', $metrics['nodes']['online'].' / '.$metrics['nodes']['total'], $metrics['nodes']['available'].' verfügbar'],
            ['Manuelle Eingriffe', $metrics['copilot']['manual_interventions'], $metrics['copilot']['repairs'].' Reparaturereignisse'],
            ['Artifact-Speicher', $this->humanBytes($metrics['artifacts']['storage_bytes']), $metrics['artifacts']['database_records'].' DB-Einträge'],
        ] as [$label, $value, $detail])
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <strong class="mt-2 block text-2xl text-slate-950">{{ $value }}</strong>
                <span class="mt-1 block text-xs text-slate-500">{{ $detail }}</span>
            </article>
        @endforeach
    </section>

    <section class="grid gap-6 xl:grid-cols-3">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div><h2 class="font-semibold text-slate-950">Erfolg je Tasktyp</h2><p class="text-xs text-slate-500">Top 20 im Messfenster</p></div>
                <a href="{{ route('processes.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Prozesse</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Tasktyp</th><th class="px-4 py-3 text-right">Läufe</th><th class="px-4 py-3 text-right">Erfolg</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($metrics['workflows']['task_types'] as $task)
                            <tr><td class="px-4 py-3 font-medium text-slate-800">{{ $task['task_type'] }}</td><td class="px-4 py-3 text-right text-slate-600">{{ $task['total'] }}</td><td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format($task['success_rate_percent'], 1, ',', '.') }} %</td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-10 text-center text-slate-500">Noch keine Task-Laufdaten.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <div><h2 class="font-semibold text-slate-950">KI-Kosten je Workflow</h2><p class="text-xs text-slate-500">Serverseitig gemessene Copilot-Nutzung</p></div>
                <a href="{{ route('network.workflows') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Workflows</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Workflow</th><th class="px-4 py-3 text-right">Sitzungen</th><th class="px-4 py-3 text-right">USD</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($metrics['copilot']['cost_by_workflow'] as $workflow)
                            <tr><td class="px-4 py-3 font-medium text-slate-800">{{ $workflow['workflow_name'] }}</td><td class="px-4 py-3 text-right text-slate-600">{{ $workflow['sessions'] }}</td><td class="px-4 py-3 text-right font-semibold text-slate-900">$ {{ number_format($workflow['cost_usd'], 6, '.', ',') }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-10 text-center text-slate-500">Noch keine Copilot-Kostendaten.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="font-semibold text-slate-950">AI-API-Kosten je Team</h2>
                <p class="text-xs text-slate-500">Typisierte Endpunkte, ohne Prompt- oder Antwortinhalte</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Team</th><th class="px-4 py-3 text-right">Anfragen</th><th class="px-4 py-3 text-right">USD</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($metrics['ai_api']['cost_by_team'] as $team)
                            <tr><td class="px-4 py-3 font-medium text-slate-800">{{ $team['team_id'] === null ? 'Ohne Team' : 'Team #'.$team['team_id'] }}</td><td class="px-4 py-3 text-right text-slate-600">{{ $team['requests'] }}</td><td class="px-4 py-3 text-right font-semibold text-slate-900">$ {{ number_format($team['cost_usd'], 6, '.', ',') }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-10 text-center text-slate-500">Noch keine typisierten AI-API-Kostendaten.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-labelledby="runner-baseline-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="runner-baseline-title" class="font-semibold text-slate-950">Runner-Baseline vor Architekturwechsel</h2>
                <p class="mt-1 text-xs text-slate-500">Messwerte für die Entscheidung „langlebiger Runner“ statt spekulativer Prozess-Umschreibung.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ $metrics['window_days'] }} Tage</span>
        </div>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
            @foreach([
                ['Prozessstarts', $metrics['runner_baseline']['process_starts']],
                ['Root-Starts', $metrics['runner_baseline']['root_process_starts']],
                ['Prozesse/Run P95', $metrics['runner_baseline']['processes_per_run_p95'] ?? 'n/a'],
                ['Erkennung P95', $metrics['runner_baseline']['detection_lag_p95_seconds'] !== null ? $metrics['runner_baseline']['detection_lag_p95_seconds'].' s' : 'n/a'],
                ['RAM P95', $metrics['runner_baseline']['memory_p95_mb'] !== null ? $metrics['runner_baseline']['memory_p95_mb'].' MB' : 'n/a'],
                ['Abbruch P95', $metrics['runner_baseline']['termination_latency_p95_seconds'] !== null ? $metrics['runner_baseline']['termination_latency_p95_seconds'].' s' : 'n/a'],
                ['Restarts', $metrics['runner_baseline']['restart_count']],
                ['Idle-Verdacht', $metrics['runner_baseline']['idle_suspects']],
            ] as [$label, $value])
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt><dd class="mt-1 text-lg font-bold text-slate-900">{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <a href="{{ route('client-controller.jobs.index') }}" class="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Client-Jobs öffnen</a>
        <a href="{{ route('network.portal-profiles') }}" class="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Portal-Profile prüfen</a>
        <span class="self-center text-xs text-slate-500">CLI/Monitoring: <code>php artisan operations:health --json --fail-on-alert</code></span>
    </section>
</div>
