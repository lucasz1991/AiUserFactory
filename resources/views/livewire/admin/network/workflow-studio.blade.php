<div class="fixed inset-0 z-[70] flex h-screen flex-col overflow-hidden bg-slate-950 text-slate-100" wire:poll.2s="refreshStudio">
    @php
        $runStatus = $run?->status ?? 'draft';
        $isPaused = $runStatus === 'paused';
        $isActive = in_array($runStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true);
        $isFinal = in_array($runStatus, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true);
        $cursorStepId = $run?->current_workflow_step_id;
        $cursorTaskKey = data_get($run?->context_json, 'next_task_key');
        $probeResult = data_get($run?->context_json, 'studio_probe_result');
        $variables = data_get($run?->context_json, 'workflow_variables', []);
        $loopState = data_get($run?->context_json, 'loop_state', []);
    @endphp

    <header class="shrink-0 border-b border-slate-700 bg-slate-900/95 shadow-2xl backdrop-blur">
        <div class="flex min-w-[1180px] items-center gap-3 px-4 py-3">
            <a href="{{ route('network.workflows.manage', $workflow) }}" class="inline-flex h-9 items-center rounded-lg border border-slate-600 px-3 text-sm font-semibold text-slate-200 hover:bg-slate-800">← Manager</a>
            <div class="min-w-0 max-w-[300px]">
                <div class="truncate text-sm font-bold text-white">{{ $workflow->name }}</div>
                <div class="text-[11px] text-slate-400">Studio #{{ $session->id }} · Revision {{ $workflow->copilot_revision }}</div>
            </div>

            <div class="ml-2 flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-950/70 p-1.5">
                <button type="button" wire:click="startRun" @disabled($isActive) class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40">▶ Starten</button>
                <button type="button" wire:click="pauseRun" @disabled(! $isActive) class="rounded-lg px-3 py-2 text-xs font-semibold text-amber-200 hover:bg-slate-800 disabled:opacity-35">Ⅱ Pausieren</button>
                <button type="button" wire:click="runSingleTask" @disabled(! $isPaused || $selectedTaskKey === '') class="rounded-lg px-3 py-2 text-xs font-semibold text-cyan-200 hover:bg-slate-800 disabled:opacity-35">▷ Einzel-Task</button>
                <button type="button" wire:click="resumeRun" @disabled(! $isPaused) class="rounded-lg px-3 py-2 text-xs font-semibold text-emerald-200 hover:bg-slate-800 disabled:opacity-35">Fortsetzen</button>
                <button type="button" wire:click="stopRun" wire:confirm="Diesen Lauf wirklich stoppen?" @disabled(! $isActive && ! $isPaused) class="rounded-lg px-3 py-2 text-xs font-semibold text-rose-200 hover:bg-slate-800 disabled:opacity-35">■ Stoppen</button>
                <button type="button" wire:click="restartRun" wire:confirm="Aktuellen Lauf beenden und neu starten?" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-800">↻ Neustart</button>
                <button type="button" wire:click="createCheckpoint" @disabled(! $run) class="rounded-lg px-3 py-2 text-xs font-semibold text-amber-200 hover:bg-slate-800 disabled:opacity-35">◆ Checkpoint</button>
                <button type="button" wire:click="restoreCheckpoint" wire:confirm="Ausgewählten Checkpoint in den aktuellen Lauf laden?" @disabled(! $isPaused || $selectedCheckpointId === '') class="rounded-lg px-3 py-2 text-xs font-semibold text-violet-200 hover:bg-slate-800 disabled:opacity-35">↶ Laden</button>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold {{ $isPaused ? 'border-amber-500/50 bg-amber-500/10 text-amber-200' : ($runStatus === 'failed' ? 'border-rose-500/50 bg-rose-500/10 text-rose-200' : 'border-cyan-500/40 bg-cyan-500/10 text-cyan-200') }}">
                    <span class="h-2 w-2 rounded-full bg-current"></span>{{ ucfirst($runStatus) }}
                </span>
                <select wire:model="permissionMode" class="h-9 rounded-lg border-slate-600 bg-slate-800 py-1 pl-3 pr-8 text-xs text-white focus:border-cyan-500 focus:ring-cyan-500">
                    @foreach($permissionModes as $permission)
                        @if($permission->value !== 'unrestricted' || auth()->user()?->isAdmin())
                            <option value="{{ $permission->value }}">{{ $permission->label() }}</option>
                        @endif
                    @endforeach
                </select>
                @if($permissionMode === 'unrestricted')
                    <label class="flex max-w-[210px] items-center gap-2 text-[10px] leading-tight text-amber-200">
                        <input type="checkbox" wire:model="unrestrictedWarningAcknowledged" class="rounded border-amber-500 bg-slate-900 text-amber-500"> Außenaktionen ohne Rückfrage erlauben
                    </label>
                @endif
                <button type="button" wire:click="setPermissionMode" class="h-9 rounded-lg border border-cyan-500/50 px-3 text-xs font-bold text-cyan-100 hover:bg-cyan-500/10">Anwenden</button>
            </div>
        </div>

        @if($lastActionResult)
            <div class="border-t border-slate-800 px-4 py-1.5 text-xs {{ ($lastActionResult['status'] ?? '') === 'failed' ? 'bg-rose-950/70 text-rose-200' : 'bg-slate-950/70 text-slate-300' }}">
                {{ $lastActionResult['message'] ?? '' }}
            </div>
        @endif
        @error('studio')
            <div class="border-t border-rose-800 bg-rose-950 px-4 py-2 text-xs font-semibold text-rose-200">{{ $message }}</div>
        @enderror
    </header>

    @if($pendingConfirmation)
        <div class="shrink-0 border-b border-amber-600 bg-amber-950 px-4 py-3 text-sm text-amber-100">
            <div class="flex items-center justify-between gap-4">
                <span><strong>Copilot fragt nach:</strong> {{ $pendingConfirmation['message'] ?? 'Aktion bestätigen?' }}</span>
                <div class="flex gap-2">
                    <button type="button" wire:click="discardPendingAction" class="rounded-lg border border-amber-600 px-3 py-1.5 text-xs font-bold">Verwerfen</button>
                    <button type="button" wire:click="confirmPendingAction" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-slate-950">Einmalig ausführen</button>
                </div>
            </div>
        </div>
    @endif

    <main class="min-h-0 flex-1 overflow-x-auto overflow-y-hidden">
        <div class="grid h-full min-w-[1480px] grid-cols-[minmax(520px,38%)_minmax(470px,34%)_minmax(420px,28%)]">
            <section class="flex min-h-0 flex-col border-r border-slate-700 bg-slate-900">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-700 px-4 py-3">
                    <div>
                        <h2 class="text-sm font-bold text-white">Workflow-Diagramm</h2>
                        <p class="text-[11px] text-slate-400">Aktiver Cursor, Tasks und Checkpoints</p>
                    </div>
                    <div class="flex items-center gap-2 text-[10px] text-slate-400"><span class="h-2 w-2 rounded-full bg-cyan-400"></span> Cursor <span class="ml-2 h-2 w-2 rotate-45 bg-amber-400"></span> Checkpoint</div>
                </div>
                <div
                    class="workflow-studio-grid min-h-0 flex-1 overflow-auto p-8"
                    style="background-color:#0f172a;background-image:linear-gradient(rgba(71,85,105,.32) 1px,transparent 1px),linear-gradient(90deg,rgba(71,85,105,.32) 1px,transparent 1px),linear-gradient(rgba(100,116,139,.35) 1px,transparent 1px),linear-gradient(90deg,rgba(100,116,139,.35) 1px,transparent 1px);background-size:20px 20px,20px 20px,100px 100px,100px 100px;"
                >
                    <div class="mx-auto min-w-[430px] max-w-[580px] space-y-6">
                        @forelse($steps as $step)
                            @php
                                $stepActive = (int) $cursorStepId === (int) $step->id;
                                $stepCheckpoints = $checkpoints->where('workflow_step_id', $step->id);
                            @endphp
                            <article class="relative rounded-2xl border {{ $stepActive ? 'border-cyan-400 bg-cyan-950/90 shadow-[0_0_30px_rgba(34,211,238,.2)]' : 'border-slate-600 bg-slate-900/95' }} p-4 shadow-xl">
                                @if(! $loop->first)<div class="absolute -top-7 left-1/2 h-7 w-px bg-slate-500"></div>@endif
                                <div class="flex items-start gap-3">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $stepActive ? 'bg-cyan-400 text-slate-950' : 'bg-slate-700 text-slate-200' }} text-xs font-black">{{ $loop->iteration }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-3">
                                            <h3 class="truncate text-sm font-bold text-white">{{ $step->name }}</h3>
                                            <span class="text-[10px] text-slate-400">{{ $step->action_key }}</span>
                                        </div>
                                        <div class="mt-3 space-y-2">
                                            @forelse($step->task_cards as $task)
                                                @php
                                                    $taskKey = (string) ($task['key'] ?? '');
                                                    $taskActive = $stepActive && $cursorTaskKey === $taskKey;
                                                    $selected = (int) $selectedStepId === (int) $step->id && $selectedTaskKey === $taskKey;
                                                @endphp
                                                <button type="button" wire:click="selectTask({{ $step->id }}, @js($taskKey))" class="flex w-full items-start gap-2 rounded-xl border px-3 py-2.5 text-left transition {{ $taskActive ? 'border-cyan-300 bg-cyan-400/15' : ($selected ? 'border-violet-400 bg-violet-500/10' : 'border-slate-700 bg-slate-950/70 hover:border-slate-500') }}">
                                                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ $taskActive ? 'animate-pulse bg-cyan-300' : 'bg-slate-600' }}"></span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block truncate text-xs font-bold text-slate-100">{{ $task['title'] ?? $taskKey }}</span>
                                                        <span class="block truncate text-[10px] text-slate-400">{{ $task['task_key'] ?? '' }}</span>
                                                    </span>
                                                </button>
                                            @empty
                                                <div class="rounded-lg border border-dashed border-slate-600 px-3 py-4 text-center text-xs text-slate-500">Noch keine Tasks</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                                @if($stepCheckpoints->isNotEmpty())
                                    <div class="absolute -right-2 -top-2 flex gap-1" title="{{ $stepCheckpoints->count() }} Checkpoints">
                                        @foreach($stepCheckpoints->take(4) as $checkpoint)<span class="h-3 w-3 rotate-45 border border-amber-200 bg-amber-400"></span>@endforeach
                                    </div>
                                @endif
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-slate-600 bg-slate-900/80 p-10 text-center text-sm text-slate-400">Der Workflow enthält noch keine Schritte. Plane ihn rechts mit dem Copilot oder öffne den Manager.</div>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="flex min-h-0 flex-col border-r border-slate-700 bg-slate-950">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-700 px-4 py-3">
                    <div><h2 class="text-sm font-bold">Live-Browser & Inspector</h2><p class="text-[11px] text-slate-400">Probeaktionen speichern den Workflow nicht</p></div>
                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $isPaused ? 'bg-amber-500/15 text-amber-200' : 'bg-slate-800 text-slate-400' }}">{{ $isPaused ? 'Proben freigegeben' : 'Zum Prüfen pausieren' }}</span>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <div class="overflow-hidden rounded-xl border border-slate-700 bg-white text-slate-900 shadow-2xl">
                        @if($run)
                            <x-workflows.run-preview :workflow-run="$run" />
                        @else
                            <div class="flex min-h-[280px] items-center justify-center bg-slate-900 text-sm text-slate-400">Browser-Vorschau erscheint nach dem ersten Start.</div>
                        @endif
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-700 bg-slate-900 p-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Probeaktion</label>
                                <select wire:model.live="probeAction" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-950 text-xs text-white">
                                    <option value="selector.search">Selector suchen</option>
                                    <option value="selector.highlight">Element hervorheben</option>
                                    <option value="selector.read">Text / Attribute lesen</option>
                                    <option value="probe.click">Klicken</option>
                                    <option value="probe.fill">Eingabe setzen</option>
                                    <option value="probe.keypress">Taste senden</option>
                                    <option value="probe.submit">Formular absenden</option>
                                    <option value="probe.wait">Warten</option>
                                    <option value="probe.navigate">Navigieren</option>
                                    <option value="probe.screenshot">Screenshot neu erfassen</option>
                                    <option value="probe.dom_refresh">DOM neu erfassen</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Browserfenster</label>
                                <input type="text" wire:model="probeBrowserWindow" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-950 text-xs text-white" placeholder="main">
                            </div>
                            <div class="col-span-2">
                                <label class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Selector, Text oder Rolle</label>
                                <input type="text" wire:model="probeSelector" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-950 text-xs text-white" placeholder="button[type=submit], text=Weiter, #search">
                            </div>
                            <div class="col-span-2">
                                <label class="text-[11px] font-bold uppercase tracking-wide text-slate-400">Wert, URL oder Wartezeit</label>
                                <input type="text" wire:model="probeValue" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-950 text-xs text-white" placeholder="Suchbegriff, https://… oder Sekunden">
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="runProbe" @disabled(! $isPaused) class="flex-1 rounded-lg bg-cyan-600 px-3 py-2 text-xs font-bold text-white hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-40">Probe ausführen</button>
                            <button type="button" wire:click="commitProbeAsTask" @disabled(! is_array($probeResult)) class="rounded-lg border border-violet-500 px-3 py-2 text-xs font-bold text-violet-200 hover:bg-violet-500/10 disabled:opacity-35">Als Task übernehmen</button>
                        </div>
                        @if(is_array($probeResult))
                            <div class="mt-3 rounded-lg border {{ data_get($probeResult, 'successful') ? 'border-emerald-700 bg-emerald-950/40' : 'border-rose-700 bg-rose-950/40' }} p-3 text-xs">
                                <div class="font-bold">{{ data_get($probeResult, 'successful') ? 'Probe erfolgreich' : 'Probe fehlgeschlagen' }}</div>
                                <pre class="mt-2 max-h-36 overflow-auto whitespace-pre-wrap text-[10px] text-slate-300">{{ json_encode(data_get($probeResult, 'result'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <details class="rounded-xl border border-slate-700 bg-slate-900 p-3" open>
                            <summary class="cursor-pointer text-xs font-bold">Variablen</summary>
                            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-[10px] text-slate-400">{{ json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                        <details class="rounded-xl border border-slate-700 bg-slate-900 p-3">
                            <summary class="cursor-pointer text-xs font-bold">Loop-Stack</summary>
                            <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-[10px] text-slate-400">{{ json_encode($loopState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </div>
                </div>
            </section>

            <aside class="flex min-h-0 flex-col bg-slate-900">
                <div class="shrink-0 border-b border-slate-700 px-4 py-3"><h2 class="text-sm font-bold">Konfiguration & Copilot</h2><p class="text-[11px] text-slate-400">Task bearbeiten, Ziel prüfen, Zustand sichern</p></div>
                <div class="min-h-0 flex-1 overflow-y-auto p-4">
                    <details class="rounded-xl border border-slate-700 bg-slate-950/60 p-4" open>
                        <summary class="cursor-pointer text-xs font-bold text-cyan-100">Aktive Task bearbeiten</summary>
                        @if($selectedTaskKey)
                            <div class="mt-3 text-[10px] text-slate-400">{{ $selectedTaskKey }} · Änderungen erzeugen eine Revision.</div>
                            <textarea wire:model="editingTaskJson" rows="12" spellcheck="false" class="mt-2 w-full rounded-lg border-slate-600 bg-slate-950 font-mono text-[11px] text-slate-200"></textarea>
                            <button type="button" wire:click="saveSelectedTask" class="mt-2 w-full rounded-lg bg-violet-600 px-3 py-2 text-xs font-bold text-white hover:bg-violet-500">Task als Revision speichern</button>
                        @else
                            <p class="mt-3 text-xs text-slate-500">Wähle links eine Task aus.</p>
                        @endif
                    </details>

                    <details class="mt-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <summary class="cursor-pointer text-xs font-bold text-cyan-100">Ziel, Eingaben & Copilot</summary>
                        <div class="mt-3 space-y-3">
                            <div><label class="text-[10px] font-bold uppercase text-slate-400">Modus</label><select wire:model="mode" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-900 text-xs text-white"><option value="manual">Manuell</option><option value="assisted">Mit Copilot</option><option value="autonomous">Autonom</option></select></div>
                            <div><label class="text-[10px] font-bold uppercase text-slate-400">Fachliches Ziel</label><textarea wire:model="goal" rows="3" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-900 text-xs text-white"></textarea></div>
                            <div><label class="text-[10px] font-bold uppercase text-slate-400">Erfolgskriterien · eine Zeile je Kriterium</label><textarea wire:model="successCriteria" rows="4" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-900 text-xs text-white"></textarea></div>
                            <div><label class="text-[10px] font-bold uppercase text-slate-400">Workflow-Eingaben · JSON</label><textarea wire:model="workflowInputs" rows="5" class="mt-1 w-full rounded-lg border-slate-600 bg-slate-900 font-mono text-[11px] text-white"></textarea></div>
                            <div class="grid grid-cols-2 gap-2">
                                <select wire:model="personId" class="rounded-lg border-slate-600 bg-slate-900 text-xs text-white"><option value="">Keine Person</option>@foreach($persons as $person)<option value="{{ $person->id }}">{{ $person->display_name }}</option>@endforeach</select>
                                <select wire:model.live="executionTarget" class="rounded-lg border-slate-600 bg-slate-900 text-xs text-white"><option value="system">System</option><option value="client_controller">ClientController</option></select>
                            </div>
                            @if($executionTarget === 'client_controller')
                                <select wire:model="networkNodeId" class="w-full rounded-lg border-slate-600 bg-slate-900 text-xs text-white"><option value="">Node wählen</option>@foreach($networkNodes as $node)<option value="{{ $node->id }}">{{ $node->name }}</option>@endforeach</select>
                            @endif
                            <div class="grid grid-cols-2 gap-2"><button type="button" wire:click="saveSessionDefinition" class="rounded-lg border border-slate-600 px-3 py-2 text-xs font-bold hover:bg-slate-800">Definition speichern</button><button type="button" wire:click="startCopilot" wire:confirm="Autonome Copilot-Optimierung starten?" class="rounded-lg bg-cyan-600 px-3 py-2 text-xs font-bold text-white hover:bg-cyan-500">Copilot starten</button></div>
                        </div>
                    </details>

                    <details class="mt-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4" open>
                        <summary class="cursor-pointer text-xs font-bold text-cyan-100">Checkpoints</summary>
                        <div class="mt-3 flex gap-2"><input type="text" wire:model="checkpointName" class="min-w-0 flex-1 rounded-lg border-slate-600 bg-slate-900 text-xs text-white" placeholder="Checkpoint-Name"><button type="button" wire:click="createCheckpoint" @disabled(! $run) class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-bold text-slate-950 disabled:opacity-40">Erstellen</button></div>
                        <select wire:model="selectedCheckpointId" class="mt-2 w-full rounded-lg border-slate-600 bg-slate-900 text-xs text-white"><option value="">Checkpoint wählen</option>@foreach($checkpoints as $checkpoint)<option value="{{ $checkpoint->id }}">#{{ $checkpoint->sequence }} {{ $checkpoint->name }}{{ ! $checkpoint->is_reproducible ? ' · nicht reproduzierbar' : '' }}{{ $checkpoint->revision && $checkpoint->revision->revision_number !== $workflow->copilot_revision ? ' · inkompatibel' : '' }}</option>@endforeach</select>
                        <div class="mt-2 grid grid-cols-2 gap-2"><button type="button" wire:click="restoreCheckpoint" wire:confirm="Aktuellen Lauf auf diesen Checkpoint zurücksetzen?" @disabled(! $isPaused || $selectedCheckpointId === '') class="rounded-lg border border-amber-600 px-3 py-2 text-xs font-bold text-amber-200 disabled:opacity-35">Lauf zurücksetzen</button><button type="button" wire:click="branchFromCheckpoint" @disabled($selectedCheckpointId === '') class="rounded-lg border border-cyan-600 px-3 py-2 text-xs font-bold text-cyan-200 disabled:opacity-35">Neuen Lauf abzweigen</button></div>
                    </details>

                    <div class="mt-3"><livewire:admin.network.workflow-revision-history :workflow-id="$workflow->id" :studio-session-id="$session->id" :key="'workflow-revisions-'.$workflow->id.'-'.$workflow->copilot_revision" /></div>

                    <details class="mt-3 rounded-xl border border-slate-700 bg-slate-950/60 p-4">
                        <summary class="cursor-pointer text-xs font-bold text-cyan-100">Studio-Protokoll</summary>
                        <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">@foreach($events as $event)<div class="rounded-lg border border-slate-800 bg-slate-900 p-2 text-[10px]"><div class="flex justify-between gap-2"><strong class="text-slate-200">{{ $event->event_type }}</strong><span class="text-slate-500">{{ $event->occurred_at?->format('H:i:s') }}</span></div><p class="mt-1 text-slate-400">{{ $event->message }}</p></div>@endforeach</div>
                    </details>
                </div>
            </aside>
        </div>
    </main>

    <div wire:loading.flex class="pointer-events-none absolute inset-0 z-50 hidden items-center justify-center bg-slate-950/35 backdrop-blur-[1px]"><span class="rounded-full border border-cyan-400 bg-slate-900 px-4 py-2 text-xs font-bold text-cyan-100 shadow-xl">Studio aktualisiert …</span></div>
</div>
