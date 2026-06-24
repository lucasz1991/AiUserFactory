<section
    @if($autoRefresh) wire:poll.10s="syncProcesses(false)" @endif
    class="{{ $compact ? 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm' : 'space-y-6' }}"
>
    @if($showHeader)
        <div class="{{ $compact ? '' : 'rounded-lg border border-gray-200 bg-white p-6 shadow-sm' }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="{{ $compact ? 'text-lg' : 'text-2xl' }} font-semibold text-gray-900">Prozesse</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Erkannte Followflow-Prozesse, Node-Scripts und zugehoerige Browser-Kindprozesse.
                    </p>
                </div>
                <button type="button" wire:click="syncProcesses" wire:loading.attr="disabled" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                    Jetzt synchronisieren
                </button>
            </div>
        </div>
    @endif

    @if(! $tableReady)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Die Prozess-Tabelle ist noch nicht vorhanden. Bitte Migrationen ausfuehren.
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gesamt</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $stats['total'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Laufend</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-900">{{ $stats['running'] }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Leerlauf-Verdacht</p>
                <p class="mt-2 text-2xl font-semibold text-amber-900">{{ $stats['idle'] }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Beendet</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['exited'] }}</p>
            </div>
        </div>

        @if($notice)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-900">
                {{ $notice }}
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1 text-xs font-semibold">
                    @foreach(['running' => 'Laufend', 'idle' => 'Leerlauf', 'exited' => 'Beendet', 'all' => 'Alle'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')" class="rounded px-3 py-1.5 {{ $filter === $value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if($compact)
                    <a href="{{ route('processes.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">
                        Alle Prozesse
                    </a>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">PID</th>
                            <th class="px-4 py-3 text-left">Typ</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Ressourcen</th>
                            <th class="px-4 py-3 text-left">Kommando</th>
                            <th class="px-4 py-3 text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($processes as $process)
                            <tr wire:key="managed-process-{{ $process->id }}" class="{{ $process->is_idle_suspect ? 'bg-amber-50/60' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-semibold text-gray-900">{{ $process->pid }}</div>
                                    <div class="text-xs text-gray-500">PPID {{ $process->parent_pid ?: '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-semibold text-gray-900">{{ $process->process_type }}</div>
                                    <div class="text-xs text-gray-500">{{ $process->script_name ?: $process->executable ?: '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ in_array($process->status, ['running', 'terminate_requested', 'kill_requested'], true) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $process->status }}
                                    </span>
                                    @if($process->is_idle_suspect)
                                        <div class="mt-1 text-xs font-semibold text-amber-700">Leerlauf-Verdacht</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                                    <div>CPU: {{ $process->cpu_percent !== null ? $process->cpu_percent.'%' : '-' }}</div>
                                    <div>RAM: {{ $process->memory_mb !== null ? $process->memory_mb.' MB' : '-' }}</div>
                                    <div>Alter: {{ floor($process->elapsed_seconds / 60) }} Min.</div>
                                </td>
                                <td class="max-w-xl px-4 py-3">
                                    <div class="break-all text-xs text-gray-700">{{ $process->short_command ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-gray-400">zuletzt: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
                                    @if($process->action_message)
                                        <div class="mt-1 text-xs text-blue-700">{{ $process->action_message }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if($process->isRunning())
                                        <button type="button" wire:click="terminate({{ $process->id }}, false)" wire:confirm="Prozess {{ $process->pid }} beenden?" class="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50">
                                            Beenden
                                        </button>
                                        <button type="button" wire:click="terminate({{ $process->id }}, true)" wire:confirm="Prozess {{ $process->pid }} wirklich erzwingen beenden?" class="ml-2 rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                                            Kill
                                        </button>
                                    @else
                                        <span class="text-xs text-gray-400">Keine Aktion</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-500">
                                    Keine Prozesse fuer diesen Filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</section>
