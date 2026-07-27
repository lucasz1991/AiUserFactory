<div
    x-data="{
        fallbackNotice: null,
        fallbackNoticeTimer: null,
        init() {
            this.$dispatch('workflow-studio-pin-copilot');
        },
        destroy() {
            window.clearTimeout(this.fallbackNoticeTimer);
            this.$dispatch('workflow-studio-unpin-copilot');
        },
        showStudioNotice(event) {
            const detail = Array.isArray(event.detail) ? (event.detail[0] || {}) : (event.detail || {});
            const type = ['success', 'error', 'warning', 'info'].includes(detail.type) ? detail.type : 'info';
            const message = String(detail.message || 'Workflow Studio wurde aktualisiert.');

            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    toast: true,
                    position: 'top',
                    icon: type,
                    title: message,
                    showConfirmButton: false,
                    timer: type === 'error' ? 5200 : 3000,
                    timerProgressBar: true,
                });
                return;
            }

            this.fallbackNotice = { type, message };
            window.clearTimeout(this.fallbackNoticeTimer);
            this.fallbackNoticeTimer = window.setTimeout(() => this.fallbackNotice = null, type === 'error' ? 5200 : 3000);
        },
    }"
    x-on:workflow-studio-notice.window="showStudioNotice($event)"
    x-on:workflow-preview-task-selected.window="
        if (Number($event.detail.workflowId || 0) === {{ (int) $workflow->id }}) {
            $wire.selectTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    x-on:workflow-preview-task-edit-requested.window="
        if (Number($event.detail.workflowId || 0) === {{ (int) $workflow->id }}) {
            $wire.editTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    x-on:workflow-studio-open-builder.window="$wire.openStudioPanel('builder')"
    data-workflow-studio-shell
    data-workflow-studio-mode="{{ $embedded ? 'embedded' : 'standalone' }}"
    class="workflow-experience {{ $embedded ? 'relative h-[100dvh]' : 'fixed inset-0 top-0 z-[70] h-[100dvh]' }} flex min-h-0 flex-col overflow-hidden bg-slate-100 text-slate-900"
    wire:poll.{{ $studioPollSeconds ?? 2 }}s="refreshStudio"
>
    @php
        $runStatus = $run?->status ?? 'draft';
        $isPaused = $runStatus === 'paused';
        $isActive = in_array($runStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true);
        $cursorStepId = $run?->current_workflow_step_id;
        $cursorTaskKey = trim((string) data_get($run?->context_json, 'next_task_key', ''));
        $variables = data_get($run?->context_json, 'workflow_variables', []);
        $loopState = data_get($run?->context_json, 'loop_state', []);
        $probeResult = data_get($run?->context_json, 'studio_probe_result');
        $selectedStep = $steps->firstWhere('id', (int) $selectedStepId);
        $selectedTask = collect($selectedStep?->task_cards ?? [])->firstWhere('key', $selectedTaskKey);
        $permissionLabel = collect($permissionModes)->first(fn ($permission) => $permission->value === $permissionMode)?->label() ?? 'Kritisch nachfragen';
        $statusLabel = match ($runStatus) {
            'queued' => 'Startet',
            'running' => 'Läuft',
            'waiting' => 'Wartet',
            'paused' => 'Pausiert',
            'stop_requested' => 'Wird gestoppt',
            'completed' => 'Abgeschlossen',
            'failed' => 'Fehlgeschlagen',
            'cancelled' => 'Gestoppt',
            'timed_out' => 'Zeitüberschreitung',
            'lost', 'unreachable' => 'Nicht erreichbar',
            default => 'Bereit',
        };
    @endphp

    <header class="ff-studio-header relative z-30 shrink-0 border-b">
        <div class="flex flex-wrap items-center gap-3 px-3 py-3 sm:px-4 lg:px-6">
            @if($embedded)
                <button type="button" x-on:click="$dispatch('workflow-studio-unpin-copilot')" wire:click="closeStudio" class="ff-action-trigger inline-flex h-9 items-center gap-2 px-3 text-xs font-semibold">
                    <span aria-hidden="true">←</span> Manager
                </button>
            @else
                <a href="{{ route('network.workflows.manage', $workflow) }}" class="ff-action-trigger inline-flex h-9 items-center gap-2 px-3 text-xs font-semibold">
                    <span aria-hidden="true">←</span> Manager
                </a>
            @endif

            <div class="min-w-[220px] flex-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="ff-kicker">Workflow-Test</span>
                    <h1 class="max-w-xl truncate text-base font-bold tracking-tight text-slate-950">{{ $workflow->name }}</h1>
                    <span class="ff-status-island" data-active="{{ $isActive ? 'true' : 'false' }}" role="status" aria-live="polite">
                        <span class="ff-status-dot" aria-hidden="true"></span>
                        <span class="text-[10px] font-bold tracking-wide">{{ $statusLabel }}</span>
                    </span>
                </div>
                <p class="mt-1 text-[10px] text-slate-500">Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</p>
            </div>

            <div class="ff-segmented-control" role="group" aria-label="Testmodus">
                <button type="button" wire:click="chooseControlMode('interactive')" aria-pressed="{{ ! $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked) class="h-8 px-3 text-[11px] font-bold {{ ! $autonomousMode ? 'text-slate-950' : 'text-slate-500 hover:text-slate-800' }} disabled:cursor-not-allowed">
                    Eigenes Testen
                </button>
                <button type="button" wire:click="chooseControlMode('autonomous')" aria-pressed="{{ $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked) class="h-8 px-3 text-[11px] font-bold {{ $autonomousMode ? 'text-slate-950' : 'text-slate-500 hover:text-blue-800' }} disabled:cursor-not-allowed">
                    Autonomer Copilot
                </button>
            </div>
            @if($modeLocked)
                <button type="button" wire:click="unlockControlMode"
                        wire:confirm="Testmodus fuer diese Sitzung entsperren? Der Modus kann danach neu gewaehlt werden."
                        class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-[10px] font-bold text-slate-600 transition hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-800"
                        title="Modus ist festgeschrieben – hier entsperren, um ihn neu zu waehlen">
                    Modus gesperrt · entsperren
                </button>
            @endif
        </div>

        @if(! $autonomousMode)
            <div class="ff-studio-commandbar flex min-w-0 items-center gap-2 overflow-x-auto border-t border-slate-100 px-3 py-2.5 sm:px-4 lg:px-6">
                {{-- Anders als das Select in den Copilot-Einstellungen bleibt die Personenwahl hier auch nach dem Modus-Lock zwischen Laeufen aenderbar; der Kontext wird erst beim Start in den Run-Context eingefroren --}}
                <label class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-200 bg-white py-1 pl-3 pr-1" title="Person / Testkontext für diesen Lauf">
                    <span class="shrink-0 text-[9px] font-black uppercase tracking-[0.16em] text-slate-400">Person</span>
                    <select wire:model="personId" @disabled($isActive || $isPaused) class="h-8 max-w-[200px] rounded-lg border-slate-200 bg-white text-[11px] font-bold text-slate-700 shadow-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:cursor-not-allowed disabled:opacity-40">
                        <option value="">Keine Person</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="ff-run-island shrink-0" role="group" aria-label="Test starten">
                    <button type="button" wire:click="runSingleTask" @disabled($isActive || ! $selectedTask) class="ff-run-primary inline-flex h-8 items-center gap-2 px-3 text-[11px] font-bold disabled:cursor-not-allowed disabled:opacity-35">
                        <span aria-hidden="true">▷|</span> Eine Task
                    </button>
                    <button type="button" wire:click="startRun" @disabled($isActive || $isPaused) class="inline-flex h-8 items-center gap-2 px-3 text-[11px] font-bold disabled:cursor-not-allowed disabled:opacity-35">
                        <span aria-hidden="true">▶</span> Bis Ende
                    </button>
                    <button type="button" wire:click="runRealPlayback" @disabled($isActive || $isPaused) title="Wie im echten Ablauf: ohne Screenshots, DOM oder Cursor – am Ende nur das Ergebnis." class="inline-flex h-8 items-center gap-2 px-3 text-[11px] font-bold disabled:cursor-not-allowed disabled:opacity-35">
                        <span aria-hidden="true">⏵</span> Echter Ablauf
                    </button>
                </div>

                <div class="ff-segmented-control shrink-0" role="group" aria-label="Task-Navigation">
                    <button type="button" wire:click="selectPreviousTask" aria-label="Vorherige Task auswählen" @disabled(! $hasPreviousTask) class="h-8 px-2.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-30">←</button>
                    <span class="min-w-12 text-center font-mono text-[10px] font-bold text-slate-400" role="status" aria-live="polite" aria-atomic="true">
                        @if($selectedTaskNumber)
                            <span class="sr-only">Ausgewählte Task {{ $selectedTaskNumber }} von {{ $taskCount }}</span>
                        @else
                            <span class="sr-only">Keine Task ausgewählt. {{ $taskCount }} Tasks verfügbar.</span>
                        @endif
                        <span aria-hidden="true">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span>
                    </span>
                    <button type="button" wire:click="selectNextTask" aria-label="Nächste Task auswählen" @disabled(! $hasNextTask) class="h-8 px-2.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-30">→</button>
                </div>

                <button type="button" wire:click="pauseRun" @disabled(! $isActive) class="h-9 rounded-lg border border-amber-200 bg-amber-50 px-3 text-[11px] font-bold text-amber-800 transition hover:bg-amber-100 disabled:opacity-30">Pausieren</button>
                <button type="button" wire:click="resumeRun" @disabled(! $isPaused) class="h-9 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-[11px] font-bold text-emerald-800 transition hover:bg-emerald-100 disabled:opacity-30">Bis Ende fortsetzen</button>
                <button type="button" wire:click="restartRun" wire:confirm="Aktuellen Lauf beenden und neu starten?" class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-[11px] font-bold text-slate-600 transition hover:bg-slate-100">Neu versuchen</button>
                <button type="button" wire:click="stopRun" wire:confirm="Diesen Lauf wirklich stoppen?" @disabled(! $isActive && ! $isPaused) class="h-9 rounded-lg border border-rose-200 bg-white px-3 text-[11px] font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-30">Stoppen</button>

                @if($selectedTask)
                    <button type="button" wire:click="editSelectedTask" class="ff-selected-task ml-auto flex min-w-[13rem] max-w-sm shrink-0 items-center gap-2 rounded-xl border px-3 py-1.5 text-left transition hover:border-blue-400">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-600"></span>
                        <span class="min-w-0"><span class="block text-[8px] font-black uppercase tracking-[0.16em] text-blue-700">Ausgewählte Task bearbeiten</span><span class="block truncate text-[11px] font-bold text-blue-950">{{ $selectedStep?->name }} / {{ $selectedTask['title'] ?? $selectedTaskKey }}</span></span>
                    </button>
                @endif
            </div>
        @else
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-cyan-100 bg-cyan-50 px-4 py-2.5 text-xs text-cyan-950 lg:px-6">
                <span><strong>Autonome Steuerung:</strong> Nach dem Start plant, testet und repariert der Copilot exklusiv. Die Benutzer-Laufsteuerung bleibt für diese Sitzung gesperrt.</span>
                @if($copilotSession)<span class="rounded-lg bg-white px-2.5 py-1 font-mono text-[10px] font-bold text-cyan-800">Copilot #{{ $copilotSession->id }} · {{ $copilotSession->status }}</span>@endif
            </div>
        @endif

        @if($pendingConfirmation)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 lg:px-6">
                <span><strong>Freigabe erforderlich:</strong> {{ $pendingConfirmation['message'] ?? 'Aktion bestätigen?' }}</span>
                <div class="flex gap-2"><button type="button" wire:click="discardPendingAction" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800">Verwerfen</button><button type="button" wire:click="confirmPendingAction" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white">Einmalig ausführen</button></div>
            </div>
        @endif
        @error('studio')<span class="sr-only" role="alert">{{ $message }}</span>@enderror
    </header>

    @unless($resultOnly)
        @include('livewire.admin.network.workflow-studio.browser-windows')
        @include('livewire.admin.network.workflow-studio.tool-bar')
    @endunless

    {{-- isolate kapselt die Diagramm-/Overlay-z-Werte (Minimap z-10/z-20, Lade-Overlay z-50) in einen eigenen Stacking-Kontext, damit sie nie mit den Shell-Modalen konkurrieren --}}
    <main class="ff-canvas-grid relative isolate min-h-0 flex-1 overflow-hidden p-3">
        <section class="h-full min-h-0 min-w-0" aria-label="Workflow-Vorschau und Live-Ausführung">
            @include('livewire.admin.network.workflow-studio.browser')
        </section>

        <div wire:loading.delay.flex wire:target="startRun,runRealPlayback,pauseRun,resumeRun,runSingleTask,stopRun,restartRun,runProbe,commitProbeAsTask,saveSessionDefinition,startCopilot,setPermissionMode,chooseControlMode" class="pointer-events-none absolute inset-0 z-50 hidden items-center justify-center bg-white/55 backdrop-blur-[1px]">
            <span class="ff-loading-island inline-flex items-center gap-2 border px-4 py-2 text-xs font-bold"><span class="h-3 w-3 animate-spin rounded-full border-2 border-slate-500 border-t-white"></span>Test wird aktualisiert …</span>
        </div>
    </main>

    <div x-cloak x-show="fallbackNotice" x-transition class="pointer-events-none absolute left-1/2 top-3 z-[80] w-[calc(100%_-_2rem)] max-w-[34rem] -translate-x-1/2 rounded-xl border bg-white px-4 py-3 text-sm font-semibold shadow-2xl" x-bind:class="fallbackNotice?.type === 'error' ? 'border-rose-200 text-rose-800' : (fallbackNotice?.type === 'success' ? 'border-emerald-200 text-emerald-800' : 'border-cyan-200 text-cyan-800')" role="status"><span x-text="fallbackNotice?.message"></span></div>

    @if($activeToolModal !== '')
        @include('livewire.admin.network.workflow-studio.tool-modal')
    @endif

    @if($showRouteRepairModal)
        <div wire:key="workflow-studio-route-repair" wire:click.self="closeRouteRepairModal" class="absolute inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-label="Fehlende Verzweigungen">
            <section class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
                <header class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-[0.18em] text-amber-700">Workflow-Struktur</p>
                        <h2 class="mt-1 text-base font-bold text-slate-950">{{ count($routeRepairFindings) }} Verzweigung(en) ohne gültiges Ziel</h2>
                        <p class="mt-1 text-xs text-slate-500">Die Zielkarte oder Zielliste wurde gelöscht. Sie können die betroffenen Verzweigungen auf die Standardroute setzen und den Test direkt starten.</p>
                    </div>
                    <button type="button" wire:click="closeRouteRepairModal" class="h-9 shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-100">Schließen ×</button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 sm:px-5">
                    <ul class="space-y-2">
                        @foreach($routeRepairFindings as $finding)
                            <li class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                                <p class="text-xs font-bold text-slate-900">
                                    {{ $finding['step_name'] ?: $finding['step'] }}
                                    @if($finding['card'])
                                        <span class="font-normal text-slate-500">·</span> {{ $finding['card_title'] ?: $finding['card'] }}
                                    @endif
                                </p>
                                <p class="mt-1 text-[11px] text-slate-600">
                                    <span class="font-semibold">{{ $finding['field_label'] }}</span>
                                    <span class="mx-1 text-slate-400">zeigt auf</span>
                                    <span class="rounded bg-rose-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-rose-800 line-through">{{ $finding['current_target'] }}</span>
                                    <span class="mx-1 text-slate-400">→ wird</span>
                                    <span class="rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-emerald-800">{{ $finding['default_label'] }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>

                    @if($routeRepairBlockingMessages !== [])
                        <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5">
                            <p class="text-xs font-bold text-rose-900">Diese Fehler bleiben auch nach der Reparatur bestehen:</p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-4 text-[11px] text-rose-800">
                                @foreach($routeRepairBlockingMessages as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-1.5 text-[11px] font-semibold text-rose-900">Der Test kann erst nach deren Behebung starten.</p>
                        </div>
                    @endif
                </div>

                <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                    <button type="button" wire:click="closeRouteRepairModal" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-100">Schließen</button>
                    <button type="button" wire:click="applyRouteRepairAndStart" @disabled($routeRepairBlockingMessages !== []) class="h-9 rounded-lg bg-slate-900 px-3.5 text-xs font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">
                        Auf Standardroute setzen und Test starten
                    </button>
                </footer>
            </section>
        </div>
    @endif

    @if($showCopilotSettingsModal)
        <div wire:key="workflow-studio-copilot-settings" wire:click.self="$set('showCopilotSettingsModal', false)" class="absolute inset-0 z-40 flex items-center justify-center bg-slate-950/35 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-label="Copilot-Einstellungen">
            <section class="flex max-h-full w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5"><div><p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Workflow-Copilot</p><h2 class="mt-1 text-base font-bold text-slate-950">Einstellungen und Start</h2><p class="mt-1 text-xs text-slate-500">Ziel, Testkontext und Berechtigungen dieser Studio-Sitzung.</p></div><button type="button" wire:click="$set('showCopilotSettingsModal', false)" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-100">Schließen ×</button></header>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    @include('livewire.admin.network.workflow-studio.copilot-rail')
                </div>
            </section>
        </div>
    @endif

    @if($activeStudioPanel === 'builder' && ! $autonomousMode)
        <div wire:key="workflow-studio-builder-modal" wire:click.self="closeStudioPanel" class="absolute inset-0 z-50 flex items-stretch justify-center bg-slate-950/45 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-label="Workflow bearbeiten">
            <section class="flex min-h-0 w-full max-w-[1720px] flex-col overflow-hidden rounded-2xl border border-white/30 bg-slate-100 shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div><p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Interaktiver Test</p><h2 class="mt-1 text-base font-bold text-slate-950">Workflow und Task bearbeiten</h2></div>
                    <button type="button" wire:click="closeStudioPanel" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-100">Schließen ×</button>
                </header>
                <div class="min-h-0 flex-1 overflow-hidden p-3 sm:p-4">
                    <livewire:admin.network.workflow-studio-task-editor :workflow="$workflow" :studio-session-id="$session->id" :key="'workflow-studio-builder-'.$workflow->id.'-'.$session->id" />
                </div>
            </section>
        </div>
    @endif

    @if(! $autonomousMode)
        @include('livewire.admin.network.workflow-studio.selector-modal')
    @endif
</div>
