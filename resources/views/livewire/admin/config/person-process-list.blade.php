<section
    @if($autoRefresh) wire:poll.10s="syncProcesses(false)" @endif
    class="space-y-4"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-slate-900">Prozesse dieser Person</h3>
            <p class="mt-1 text-sm text-slate-500">Node-Laeufe, Browser-Kindprozesse, Heartbeats und letzte Statusmeldungen.</p>
        </div>
        <button type="button" wire:click="syncProcesses" wire:loading.attr="disabled" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
            Aktualisieren
        </button>
    </div>

    @if($notice)
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-900">
            {{ $notice }}
        </div>
    @endif

    @if(! $tableReady)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Die Prozess-Tabelle ist noch nicht vorhanden. Bitte Migrationen ausfuehren.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gesamt</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['total'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Laufend</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-900">{{ $stats['running'] }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Ohne Lebenszeichen</p>
                <p class="mt-2 text-2xl font-semibold text-amber-900">{{ $stats['stale'] }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Beendet</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['exited'] }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1 text-xs font-semibold">
                    @foreach(['running' => 'Laufend', 'stale' => 'Stale', 'exited' => 'Beendet', 'all' => 'Alle'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')" class="rounded px-3 py-1.5 {{ $filter === $value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-[1120px] divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Prozess</th>
                            <th class="px-4 py-3 text-left">Run</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Heartbeat</th>
                            <th class="px-4 py-3 text-left">Letzter Schritt</th>
                            <th class="px-4 py-3 text-left">Kommando</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($processes as $process)
                            @php
                                $heartbeatAge = $process->heartbeat_at ? (int) $process->heartbeat_at->diffInSeconds(now()) : null;
                                $isStale = $process->isRunning() && ($heartbeatAge === null || $heartbeatAge > 30);
                            @endphp
                            <tr class="{{ $isStale ? 'bg-amber-50/70' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-semibold text-slate-900">PID {{ $process->pid }}</div>
                                    <div class="text-xs text-slate-500">PPID {{ $process->parent_pid ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $process->process_type }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="break-all font-semibold text-slate-900">{{ $process->run_id ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $process->process_key ?: data_get($process->metadata, 'process_identity.processKey', '-') }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $process->isRunning() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $process->status }}
                                    </span>
                                    @if($process->restart_count > 0)
                                        <div class="mt-1 text-xs font-semibold text-blue-700">Restarts: {{ $process->restart_count }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-600">
                                    @if($process->heartbeat_at)
                                        <div class="font-semibold {{ $isStale ? 'text-amber-700' : 'text-emerald-700' }}">
                                            {{ $isStale ? 'Stale' : 'Aktiv' }}
                                        </div>
                                        <div>{{ $heartbeatAge }}s alt</div>
                                        <div>{{ $process->heartbeat_at->format('d.m.Y H:i:s') }}</div>
                                    @else
                                        <div class="font-semibold text-amber-700">Kein Heartbeat</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-900">{{ $process->last_stage ?: data_get($process->metadata, 'status_stage', '-') }}</div>
                                    <div class="mt-1 max-w-sm break-words text-xs text-slate-600">{{ $process->last_message ?: $process->action_message ?: '-' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="max-w-md break-all text-xs text-slate-700">{{ $process->short_command ?: $process->command ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">zuletzt gesehen: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">
                                    Keine Prozesse fuer diese Person gefunden.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
