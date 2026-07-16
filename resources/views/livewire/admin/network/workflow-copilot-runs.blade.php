<div class="min-h-[620px] text-slate-800" wire:poll.5s>
    @php
        $statusLabels = [
            'running' => 'Laeuft',
            'paused' => 'Pausiert',
            'repairing' => 'Repariert',
            'verifying' => 'Prueft',
            'succeeded' => 'Erfolgreich',
            'budget_exhausted' => 'Budget erschoepft',
            'failed' => 'Fehlgeschlagen',
            'stopped' => 'Gestoppt',
        ];
        $statusClasses = [
            'running' => 'bg-cyan-100 text-cyan-800',
            'paused' => 'bg-amber-100 text-amber-800',
            'repairing' => 'bg-indigo-100 text-indigo-800',
            'verifying' => 'bg-blue-100 text-blue-800',
            'succeeded' => 'bg-emerald-100 text-emerald-800',
            'budget_exhausted' => 'bg-orange-100 text-orange-800',
            'failed' => 'bg-rose-100 text-rose-800',
            'stopped' => 'bg-slate-200 text-slate-700',
        ];
    @endphp

    <div class="grid min-h-[620px] overflow-hidden border border-slate-200 bg-white lg:grid-cols-[330px_minmax(0,1fr)]">
        <aside class="flex min-h-0 flex-col border-b border-slate-200 bg-slate-50 lg:border-b-0 lg:border-r">
            <div class="space-y-3 border-b border-slate-200 p-3">
                <div>
                    <h3 class="font-semibold text-slate-950">Optimierungslaeufe</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $workflowId !== null ? 'Nur fuer diesen Workflow' : 'Alle Workflows' }}
                    </p>
                </div>
                <div class="grid grid-cols-[minmax(0,1fr)_130px] gap-2">
                    <label class="sr-only" for="copilot-runs-search">Suchen</label>
                    <input id="copilot-runs-search" type="search" wire:model.live.debounce.300ms="search" placeholder="ID, Workflow, Ziel" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                    <label class="sr-only" for="copilot-runs-status">Status</label>
                    <select id="copilot-runs-status" wire:model.live="status" class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                        <option value="all">Alle Status</option>
                        @foreach($statusLabels as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}">{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="max-h-[500px] flex-1 overflow-y-auto p-2">
                <div class="space-y-1.5">
                    @forelse($sessions as $session)
                        @php
                            $sessionUsage = is_array($session->usage_json) ? $session->usage_json : [];
                            $sessionCostCaptured = array_key_exists('ai_requests', $sessionUsage) || array_key_exists('cost_usd', $sessionUsage);
                            $sessionCost = (float) data_get($sessionUsage, 'cost_usd', 0);
                        @endphp
                        <button
                            type="button"
                            wire:key="copilot-session-row-{{ $session->id }}"
                            wire:click="selectSession({{ $session->id }})"
                            class="w-full border px-3 py-2.5 text-left transition {{ (int) $selectedSessionId === (int) $session->id ? 'border-cyan-500 bg-cyan-50' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="min-w-0 truncate text-sm font-semibold text-slate-950">{{ $session->workflow?->name ?: 'Workflow #'.$session->workflow_id }}</span>
                                <span class="shrink-0 text-[11px] font-bold text-slate-500">#{{ $session->id }}</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold {{ $statusClasses[$session->status] ?? 'bg-slate-100 text-slate-700' }}">
                                    {{ $statusLabels[$session->status] ?? $session->status }}
                                </span>
                                <span class="text-[11px] text-slate-500">{{ $session->started_at?->format('d.m.Y H:i') ?: $session->created_at?->format('d.m.Y H:i') }}</span>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-[11px] text-slate-500">
                                <span><strong class="text-slate-800">{{ $session->runs_count }}</strong> Tests</span>
                                <span><strong class="text-slate-800">{{ $session->revisions_count }}</strong> Rev.</span>
                                <span class="text-right"><strong class="text-slate-800">{{ $sessionCostCaptured ? '$'.number_format($sessionCost, 4, '.', '') : '-' }}</strong></span>
                            </div>
                        </button>
                    @empty
                        <div class="border border-dashed border-slate-300 bg-white px-3 py-8 text-center text-sm text-slate-500">
                            Keine Copilot-Optimierungslaeufe gefunden.
                        </div>
                    @endforelse
                </div>
            </div>

            @if($sessions->hasPages())
                <div class="border-t border-slate-200 bg-white p-3">
                    {{ $sessions->links() }}
                </div>
            @endif
        </aside>

        <section class="min-w-0 bg-white">
            @if($selectedSession)
                @php
                    $usage = is_array($selectedSession->usage_json) ? $selectedSession->usage_json : [];
                    $budget = is_array($selectedSession->budget_json) ? $selectedSession->budget_json : [];
                    $costCaptured = array_key_exists('ai_requests', $usage) || array_key_exists('cost_usd', $usage);
                    $cost = (float) data_get($usage, 'cost_usd', 0);
                    $costBudget = (float) data_get($budget, 'max_cost_usd', 0);
                @endphp

                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate text-lg font-semibold text-slate-950">{{ $selectedSession->workflow?->name ?: 'Workflow #'.$selectedSession->workflow_id }}</h3>
                            <span class="rounded px-2 py-1 text-xs font-bold {{ $statusClasses[$selectedSession->status] ?? 'bg-slate-100 text-slate-700' }}">
                                {{ $statusLabels[$selectedSession->status] ?? $selectedSession->status }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Sitzung #{{ $selectedSession->id }} · Revision {{ $selectedSession->current_revision }} · Phase {{ $selectedSession->phase ?: '-' }}</p>
                    </div>
                    <button type="button" wire:click="downloadSelectedSessionLog" wire:loading.attr="disabled" wire:target="downloadSelectedSessionLog" class="rounded-md border border-cyan-300 bg-white px-3 py-2 text-xs font-semibold text-cyan-800 hover:bg-cyan-50 disabled:opacity-50">
                        Komplettes Log exportieren
                    </button>
                </div>

                @error('session')
                    <div class="border-b border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
                @enderror

                <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-3" role="tablist" aria-label="Copilot-Laufdetails">
                    @foreach(['overview' => 'Uebersicht', 'logs' => 'Logs', 'runs' => 'Tests & Revisionen', 'data' => 'Daten'] as $tabValue => $tabLabel)
                        <button type="button" wire:click="setActiveTab('{{ $tabValue }}')" class="border-b-2 px-3 py-2 text-sm font-semibold {{ $activeTab === $tabValue ? 'border-cyan-600 text-cyan-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>

                <div class="max-h-[500px] overflow-y-auto p-4">
                    @if($activeTab === 'overview')
                        <div class="space-y-5">
                            <div class="grid gap-px overflow-hidden border border-slate-200 bg-slate-200 sm:grid-cols-2 xl:grid-cols-4">
                                <div class="bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase text-slate-500">Kosten</p>
                                    <p class="mt-1 text-lg font-bold text-slate-950">{{ $costCaptured ? '$'.number_format($cost, 6, '.', '') : 'Nicht erfasst' }}</p>
                                    <p class="text-xs text-slate-500">Budget: {{ $costBudget > 0 ? '$'.number_format($costBudget, 4, '.', '') : 'Unbegrenzt' }}</p>
                                </div>
                                <div class="bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase text-slate-500">AI-Nutzung</p>
                                    <p class="mt-1 text-lg font-bold text-slate-950">{{ number_format((int) data_get($usage, 'total_tokens', 0), 0, ',', '.') }}</p>
                                    <p class="text-xs text-slate-500">{{ (int) data_get($usage, 'ai_requests', 0) }} Anfragen</p>
                                </div>
                                <div class="bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase text-slate-500">Testlaeufe</p>
                                    <p class="mt-1 text-lg font-bold text-slate-950">{{ $selectedSession->runs_count }}</p>
                                    <p class="text-xs text-slate-500">{{ $selectedSession->checkpoints_count }} Checkpoints</p>
                                </div>
                                <div class="bg-white p-3">
                                    <p class="text-[11px] font-semibold uppercase text-slate-500">Anpassungen</p>
                                    <p class="mt-1 text-lg font-bold text-slate-950">{{ $selectedSession->revisions_count }}</p>
                                    <p class="text-xs text-slate-500">{{ $selectedSession->task_attempts_count }} Taskversuche</p>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-slate-950">Optimierungsziel</h4>
                                <p class="mt-1 whitespace-pre-wrap border-l-2 border-cyan-500 pl-3 text-sm leading-6 text-slate-700">{{ data_get($selectedData, 'session.goal') ?: 'Kein Ziel gespeichert.' }}</p>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-950">Budget und Nutzung</h4>
                                    <dl class="mt-2 divide-y divide-slate-100 border-y border-slate-200 text-sm">
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Reparaturiterationen</dt><dd class="font-semibold">{{ (int) data_get($usage, 'repair_iterations', 0) }} / {{ (int) data_get($budget, 'max_repair_iterations', 0) }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Probeaktionen</dt><dd class="font-semibold">{{ (int) data_get($usage, 'probe_actions', 0) }} / {{ (int) data_get($budget, 'max_probe_actions', 0) }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Gleicher Zustand</dt><dd class="font-semibold">{{ (int) data_get($usage, 'same_state_repeats', 0) }} / {{ (int) data_get($budget, 'max_same_state_repeats', 0) }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Dauer</dt><dd class="font-semibold">{{ (int) data_get($budget, 'max_duration_minutes', 0) }} Min.</dd></div>
                                    </dl>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-950">Zeitverlauf</h4>
                                    <dl class="mt-2 divide-y divide-slate-100 border-y border-slate-200 text-sm">
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Gestartet</dt><dd class="font-semibold">{{ $selectedSession->started_at?->format('d.m.Y H:i:s') ?: '-' }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Letzte Aktivitaet</dt><dd class="font-semibold">{{ $selectedSession->last_activity_at?->format('d.m.Y H:i:s') ?: '-' }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Beendet</dt><dd class="font-semibold">{{ $selectedSession->finished_at?->format('d.m.Y H:i:s') ?: '-' }}</dd></div>
                                        <div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Ausfuehrungsziel</dt><dd class="font-semibold">{{ $selectedSession->execution_target }}</dd></div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    @elseif($activeTab === 'logs')
                        <div class="space-y-2">
                            @forelse($selectedEvents as $event)
                                <article class="border border-slate-200 bg-white px-3 py-2">
                                    <div class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                        <span class="font-bold text-slate-800">#{{ $event['sequence'] }}</span>
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">{{ $event['event_type'] }}</span>
                                        <span>{{ $event['phase'] ?: '-' }}</span>
                                        <span class="ml-auto">{{ $event['occurred_at']?->format('d.m.Y H:i:s') }}</span>
                                    </div>
                                    <p class="mt-1 whitespace-pre-wrap text-sm leading-5 text-slate-800">{{ $event['message'] ?: '-' }}</p>
                                    @if($event['payload'] !== [])
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-cyan-800">Payload anzeigen</summary>
                                            <pre class="mt-2 max-h-72 overflow-auto bg-slate-950 p-3 text-[11px] leading-5 text-slate-100">{{ json_encode($event['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </details>
                                    @endif
                                </article>
                            @empty
                                <p class="py-10 text-center text-sm text-slate-500">Keine Ereignisse gespeichert.</p>
                            @endforelse
                        </div>
                    @elseif($activeTab === 'runs')
                        <div class="space-y-6">
                            <div>
                                <h4 class="mb-2 text-sm font-semibold text-slate-950">Workflow-Vorschau-Tests</h4>
                                <div class="overflow-x-auto border border-slate-200">
                                    <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                                        <thead class="bg-slate-50 text-slate-600"><tr><th class="px-3 py-2">Run</th><th class="px-3 py-2">Revision</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Dauer</th><th class="px-3 py-2">Start</th><th class="px-3 py-2">Fehler</th></tr></thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            @forelse($selectedRuns as $run)
                                                <tr><td class="px-3 py-2 font-semibold">#{{ $run['id'] }}</td><td class="px-3 py-2">{{ $run['revision'] ?? '-' }}</td><td class="px-3 py-2">{{ $run['status'] }}</td><td class="px-3 py-2">{{ $run['duration_ms'] !== null ? number_format($run['duration_ms'] / 1000, 2, ',', '.').' s' : '-' }}</td><td class="whitespace-nowrap px-3 py-2">{{ $run['started_at']?->format('d.m.Y H:i:s') ?: '-' }}</td><td class="max-w-sm px-3 py-2 text-rose-700">{{ $run['error_message'] ?: '-' }}</td></tr>
                                            @empty
                                                <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">Keine Testlaeufe gespeichert.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div>
                                <h4 class="mb-2 text-sm font-semibold text-slate-950">Workflow-Revisionen</h4>
                                <div class="space-y-2">
                                    @forelse($selectedRevisions as $revision)
                                        <details class="border border-slate-200 bg-white px-3 py-2">
                                            <summary class="cursor-pointer text-sm font-semibold text-slate-900">Revision {{ $revision['revision_number'] }} · {{ $revision['actor'] }} · {{ $revision['is_verified'] ? 'verifiziert' : 'offen' }}</summary>
                                            <p class="mt-2 text-sm text-slate-700">{{ $revision['reason'] ?: 'Kein Grund gespeichert.' }}</p>
                                            @if($revision['diff'] !== [])
                                                <pre class="mt-2 max-h-72 overflow-auto bg-slate-950 p-3 text-[11px] leading-5 text-slate-100">{{ json_encode($revision['diff'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            @endif
                                        </details>
                                    @empty
                                        <p class="py-8 text-center text-sm text-slate-500">Keine Revisionen gespeichert.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    @else
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-semibold text-slate-950">Bereinigter Sitzungsdatensatz</h4>
                                <span class="text-xs text-slate-500">Inputwerte und Geheimnisse werden nicht angezeigt.</span>
                            </div>
                            <pre class="max-h-[450px] overflow-auto bg-slate-950 p-4 text-[11px] leading-5 text-slate-100">{{ json_encode($selectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </div>
            @else
                <div class="flex min-h-[620px] items-center justify-center p-8 text-center text-sm text-slate-500">
                    Waehle links einen Copilot-Optimierungslauf aus.
                </div>
            @endif
        </section>
    </div>
</div>
