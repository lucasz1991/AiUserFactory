<div
    x-data
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
    x-on:keydown.escape.window="$wire.closeStudioPanel()"
    data-workflow-studio-shell
    class="{{ $embedded ? 'h-[100dvh]' : 'fixed inset-0 z-[70] h-[100dvh]' }} flex min-h-0 flex-col overflow-hidden bg-slate-100 text-slate-900"
    wire:poll.2s="refreshStudio"
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
        $statusTone = match ($runStatus) {
            'running', 'queued', 'waiting' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'paused' => 'border-amber-200 bg-amber-50 text-amber-700',
            'failed', 'timed_out', 'lost', 'unreachable' => 'border-rose-200 bg-rose-50 text-rose-700',
            'cancelled', 'stop_requested' => 'border-slate-200 bg-slate-100 text-slate-700',
            'completed' => 'border-cyan-200 bg-cyan-50 text-cyan-700',
            default => 'border-slate-200 bg-white text-slate-700',
        };
    @endphp

    <header class="relative z-30 shrink-0 border-b border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 lg:px-6">
            @if($embedded)
                <button type="button" wire:click="closeStudio" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500">
                    <span aria-hidden="true">←</span> Manager
                </button>
            @else
                <a href="{{ route('network.workflows.manage', $workflow) }}" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-cyan-500">
                    <span aria-hidden="true">←</span> Manager
                </a>
            @endif

            <div class="min-w-[220px] flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Workflow-Test</span>
                    <span class="text-slate-300">/</span>
                    <h1 class="max-w-xl truncate text-sm font-bold text-slate-950">{{ $workflow->name }}</h1>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-black {{ $statusTone }}"><span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-current align-middle"></span>{{ $statusLabel }}</span>
                </div>
                <p class="mt-1 text-[10px] text-slate-500">Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</p>
            </div>

            <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 p-1" aria-label="Testmodus">
                <button type="button" wire:click="chooseControlMode('interactive')" @disabled($modeLocked) class="h-8 rounded-lg px-3 text-[11px] font-bold transition {{ ! $autonomousMode ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800' }} disabled:cursor-not-allowed">
                    Eigenes Testen
                </button>
                <button type="button" wire:click="chooseControlMode('autonomous')" @disabled($modeLocked) class="h-8 rounded-lg px-3 text-[11px] font-bold transition {{ $autonomousMode ? 'bg-cyan-700 text-white shadow-sm' : 'text-slate-500 hover:text-cyan-800' }} disabled:cursor-not-allowed">
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
            <div class="flex min-w-0 flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-2.5 lg:px-6">
                <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 p-1">
                    <button type="button" wire:click="startRun" @disabled($isActive || $isPaused) class="inline-flex h-8 items-center gap-2 rounded-lg bg-slate-900 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-slate-800 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-35">
                        <span aria-hidden="true">▶</span> Bis Ende starten
                    </button>
                    <button type="button" wire:click="runSingleTask" @disabled($isActive || ! $selectedTask) class="inline-flex h-8 items-center gap-2 rounded-lg bg-cyan-700 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-cyan-600 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-35">
                        <span aria-hidden="true">▷|</span> Eine Task
                    </button>
                </div>

                <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1">
                    <button type="button" wire:click="selectPreviousTask" @disabled(! $hasPreviousTask) class="h-8 rounded-lg px-2.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-30">←</button>
                    <span class="min-w-12 text-center font-mono text-[10px] font-bold text-slate-400">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span>
                    <button type="button" wire:click="selectNextTask" @disabled(! $hasNextTask) class="h-8 rounded-lg px-2.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-30">→</button>
                </div>

                <button type="button" wire:click="pauseRun" @disabled(! $isActive) class="h-9 rounded-lg border border-amber-200 bg-amber-50 px-3 text-[11px] font-bold text-amber-800 transition hover:bg-amber-100 disabled:opacity-30">Pausieren</button>
                <button type="button" wire:click="resumeRun" @disabled(! $isPaused) class="h-9 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-[11px] font-bold text-emerald-800 transition hover:bg-emerald-100 disabled:opacity-30">Bis Ende fortsetzen</button>
                <button type="button" wire:click="restartRun" wire:confirm="Aktuellen Lauf beenden und neu starten?" class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-[11px] font-bold text-slate-600 transition hover:bg-slate-100">Neu versuchen</button>
                <button type="button" wire:click="stopRun" wire:confirm="Diesen Lauf wirklich stoppen?" @disabled(! $isActive && ! $isPaused) class="h-9 rounded-lg border border-rose-200 bg-white px-3 text-[11px] font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-30">Stoppen</button>

                @if($selectedTask)
                    <button type="button" wire:click="editSelectedTask" class="ml-auto flex min-w-0 max-w-sm items-center gap-2 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-left transition hover:border-cyan-400 hover:bg-cyan-100">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-cyan-500"></span>
                        <span class="min-w-0"><span class="block text-[8px] font-black uppercase tracking-[0.16em] text-cyan-700">Task bearbeiten</span><span class="block truncate text-[11px] font-bold text-cyan-950">{{ $selectedStep?->name }} / {{ $selectedTask['title'] ?? $selectedTaskKey }}</span></span>
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
        @if($lastActionResult)
            <div class="border-t px-4 py-2 text-xs lg:px-6 {{ ($lastActionResult['status'] ?? '') === 'failed' ? 'border-rose-200 bg-rose-50 font-semibold text-rose-700' : 'border-cyan-100 bg-cyan-50 text-cyan-800' }}">{{ $lastActionResult['message'] ?? '' }}</div>
        @endif
        @error('studio')<div class="border-t border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 lg:px-6">{{ $message }}</div>@enderror
    </header>

    @include('livewire.admin.network.workflow-studio.browser-windows')

    <nav class="relative z-20 shrink-0 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:px-6" aria-label="Testbereiche">
        <div class="flex min-w-max items-center gap-1 rounded-xl bg-slate-100 p-1">
            @foreach(($autonomousMode ? ['test' => 'Workflow-Vorschau', 'runtime' => 'Daten & Checkpoints'] : ['test' => 'Workflow-Vorschau', 'tools' => 'Browser & Selector', 'runtime' => 'Daten & Checkpoints']) as $tab => $label)
                <button type="button" wire:click="$set('activeWorkspaceTab', '{{ $tab }}')" class="h-8 rounded-lg px-3 text-[11px] font-bold transition {{ $activeWorkspaceTab === $tab ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">{{ $label }}</button>
            @endforeach
        </div>
    </nav>

    <main class="relative min-h-0 flex-1 overflow-hidden bg-slate-100 p-3">
        <div class="grid h-full min-h-0 gap-3 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="min-h-0 min-w-0" aria-label="Aktiver Testbereich">
                @if($activeWorkspaceTab === 'tools' && ! $autonomousMode)
                    @include('livewire.admin.network.workflow-studio.tools')
                @elseif($activeWorkspaceTab === 'runtime')
                    @include('livewire.admin.network.workflow-studio.runtime')
                @else
                    @include('livewire.admin.network.workflow-studio.browser')
                @endif
            </section>
            <aside class="min-h-0 overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Copilot">
                @include('livewire.admin.network.workflow-studio.copilot-rail')
            </aside>
        </div>

        <div wire:loading.delay.flex wire:target="startRun,pauseRun,resumeRun,runSingleTask,stopRun,restartRun,runProbe,commitProbeAsTask,saveSessionDefinition,startCopilot,setPermissionMode,chooseControlMode" class="pointer-events-none absolute inset-0 z-50 hidden items-center justify-center bg-white/55 backdrop-blur-[1px]">
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-xl"><span class="h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-cyan-600"></span>Test wird aktualisiert …</span>
        </div>
    </main>

    @if($activeStudioPanel === 'builder' && ! $autonomousMode)
        <div wire:key="workflow-studio-builder-modal" wire:click.self="closeStudioPanel" class="absolute inset-0 z-[65] flex items-stretch justify-center bg-slate-950/45 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-label="Workflow bearbeiten">
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
