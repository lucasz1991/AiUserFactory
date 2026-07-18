<div
    x-data="{
        activePanel: null,
        panelTitles: {
            builder: 'Workflow bearbeiten',
            copilot: 'Copilot & Ziele',
            tools: 'Selector & Browser',
            runtime: 'Daten & Logs',
            revisions: 'Revisionen',
        },
        init() {
            this.closePanel();
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('workflow-studio-pin-copilot')));
        },
        isPanelOpen() {
            return typeof this.activePanel === 'string'
                && Object.prototype.hasOwnProperty.call(this.panelTitles, this.activePanel);
        },
        openPanel(panel) {
            const normalized = String(panel || '').trim();
            this.activePanel = Object.prototype.hasOwnProperty.call(this.panelTitles, normalized)
                ? normalized
                : null;
        },
        closePanel() {
            this.activePanel = null;
        },
    }"
    x-on:workflow-preview-task-selected.window="
        if (Number($event.detail.workflowId || 0) === {{ (int) $workflow->id }}) {
            $wire.selectTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    x-on:workflow-preview-task-edit-requested.window="
        if (Number($event.detail.workflowId || 0) === {{ (int) $workflow->id }}) {
            openPanel('builder');
            $wire.editTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    x-on:workflow-studio-open-builder.window="openPanel('builder')"
    x-on:workflow-studio-open-panel.window="openPanel($event.detail.panel)"
    x-on:keydown.escape.window="closePanel()"
    data-workflow-studio-shell
    class="fixed inset-0 z-[70] flex h-screen flex-col overflow-hidden bg-slate-100 text-slate-900"
    wire:poll.2s="refreshStudio"
>
    @php
        $runStatus = $run?->status ?? 'draft';
        $isPaused = $runStatus === 'paused';
        $isActive = in_array($runStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true);
        $isFinal = in_array($runStatus, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true);
        $cursorStepId = $run?->current_workflow_step_id;
        $cursorTaskKey = trim((string) data_get($run?->context_json, 'next_task_key', ''));
        $probeResult = data_get($run?->context_json, 'studio_probe_result');
        $variables = data_get($run?->context_json, 'workflow_variables', []);
        $loopState = data_get($run?->context_json, 'loop_state', []);
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
            'running', 'queued', 'waiting' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            'paused' => 'border-amber-300 bg-amber-50 text-amber-700',
            'failed', 'timed_out', 'lost', 'unreachable' => 'border-rose-300 bg-rose-50 text-rose-700',
            'cancelled', 'stop_requested' => 'border-slate-300 bg-slate-100 text-slate-700',
            'completed' => 'border-cyan-300 bg-cyan-50 text-cyan-700',
            default => 'border-slate-300 bg-white text-slate-700',
        };
    @endphp

    <header class="relative z-30 shrink-0 shadow-lg shadow-slate-950/10">
        <div class="flex flex-wrap items-center gap-3 bg-slate-950 px-4 py-3 text-white lg:px-6">
            <a href="{{ route('network.workflows.manage', $workflow) }}" class="inline-flex h-9 items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 text-xs font-bold text-slate-200 transition hover:border-white/30 hover:bg-white/10 hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
                Manager
            </a>

            <div class="min-w-[220px] flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[9px] font-black uppercase tracking-[0.2em] text-cyan-300">Workflow Studio</span>
                    <span class="text-slate-600">/</span>
                    <h1 class="max-w-xl truncate text-sm font-bold text-white">{{ $workflow->name }}</h1>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-black {{ $statusTone }}"><span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-current align-middle"></span>{{ $statusLabel }}</span>
                </div>
                <p class="mt-1 text-[10px] text-slate-400">Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</p>
            </div>

            <button type="button" x-on:click="openPanel('copilot')" class="inline-flex h-9 items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 text-xs font-bold text-slate-200 transition hover:border-cyan-400/50 hover:bg-cyan-400/10 hover:text-cyan-200">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 3.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2V9.6h.1A1.7 1.7 0 0 0 3.6 8a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8 3.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2h4v.1A1.7 1.7 0 0 0 15 3.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8c.16.37.37.7.6 1 .3.35.7.55 1.1.6h.1v4h-.1a1.7 1.7 0 0 0-1.7 1.4Z"></path></svg>
                Copilot & Ziele
            </button>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-2.5 lg:px-6">
            <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 p-1">
                <button type="button" wire:click="startRun" @disabled($isActive || $isPaused) class="inline-flex h-8 items-center gap-2 rounded-lg bg-slate-900 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-35">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"></path></svg>
                    Durchlaufen
                </button>
                <button type="button" wire:click="runSingleTask" @disabled($isActive || ! $selectedTask) class="inline-flex h-8 items-center gap-2 rounded-lg bg-cyan-600 px-3 text-[11px] font-bold text-white shadow-sm transition hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-35">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 5v14l11-7z"></path><path d="M20 5v14"></path></svg>
                    1 Task ausführen
                </button>
            </div>

            <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1">
                <button type="button" wire:click="selectPreviousTask" @disabled(! $hasPreviousTask) class="h-8 rounded-lg px-2.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-30">← Task zurück</button>
                <span class="min-w-12 text-center font-mono text-[10px] font-bold text-slate-400">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span>
                <button type="button" wire:click="selectNextTask" @disabled(! $hasNextTask) class="h-8 rounded-lg px-2.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-30">Task weiter →</button>
            </div>

            <div class="flex items-center gap-1">
                <button type="button" wire:click="pauseRun" @disabled(! $isActive) class="h-9 rounded-lg border border-amber-200 bg-amber-50 px-3 text-[11px] font-bold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-30">Pausieren</button>
                <button type="button" wire:click="resumeRun" @disabled(! $isPaused) class="h-9 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-[11px] font-bold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-30">Bis Ende fortsetzen</button>
                <button type="button" wire:click="stopRun" wire:confirm="Diesen Lauf wirklich stoppen?" @disabled(! $isActive && ! $isPaused) class="h-9 rounded-lg border border-rose-200 bg-white px-3 text-[11px] font-bold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-30">Stoppen</button>
                <button type="button" wire:click="restartRun" wire:confirm="Aktuellen Lauf beenden und neu starten?" class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-[11px] font-bold text-slate-600 transition hover:bg-slate-100">Neustart</button>
            </div>

            @if($selectedTask)
                <button type="button" wire:click="editSelectedTask" class="ml-auto flex min-w-0 max-w-sm items-center gap-2 rounded-xl border-2 border-cyan-300 bg-cyan-50 px-3 py-1.5 text-left transition hover:border-cyan-500 hover:bg-cyan-100" data-studio-toolbar-selected-task>
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-cyan-500 ring-4 ring-cyan-100"></span>
                    <span class="min-w-0"><span class="block text-[8px] font-black uppercase tracking-[0.16em] text-cyan-700">Ausgewählt</span><span class="block truncate text-[11px] font-bold text-cyan-950">{{ $selectedStep?->name }} / {{ $selectedTask['title'] ?? $selectedTaskKey }}</span></span>
                    <span class="text-cyan-700">›</span>
                </button>
            @endif
        </div>

        @if($permissionMode === 'unrestricted')
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900 lg:px-6">
                <span><strong>Uneingeschränkter Zugriff:</strong> Außenaktionen sind in dieser Sitzung ohne Einzelrückfrage erlaubt.</span>
                <label class="inline-flex items-center gap-2 font-semibold"><input type="checkbox" wire:model="unrestrictedWarningAcknowledged" class="rounded border-amber-400 text-amber-600 focus:ring-amber-500"> Warnung verstanden</label>
            </div>
        @endif

        @if($pendingConfirmation)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 lg:px-6">
                <span><strong>Freigabe erforderlich:</strong> {{ $pendingConfirmation['message'] ?? 'Aktion bestätigen?' }}</span>
                <div class="flex gap-2"><button type="button" wire:click="discardPendingAction" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800">Verwerfen</button><button type="button" wire:click="confirmPendingAction" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-amber-400">Einmalig ausführen</button></div>
            </div>
        @endif

        @if($lastActionResult)
            <div class="border-b px-4 py-2 text-xs lg:px-6 {{ ($lastActionResult['status'] ?? '') === 'failed' ? 'border-rose-200 bg-rose-50 font-semibold text-rose-700' : 'border-cyan-100 bg-cyan-50 text-cyan-800' }}">{{ $lastActionResult['message'] ?? '' }}</div>
        @endif
        @error('studio')
            <div class="border-b border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 lg:px-6">{{ $message }}</div>
        @enderror
    </header>

    @include('livewire.admin.network.workflow-studio.browser-windows')

    <nav class="relative z-20 shrink-0 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:px-6" aria-label="Workflow-Studio Werkzeuge">
        <div class="flex min-w-max items-center gap-2">
            <span class="mr-1 text-[9px] font-black uppercase tracking-[0.16em] text-slate-400">Werkzeuge</span>
            <button type="button" x-on:click="openPanel('builder')" class="inline-flex h-8 items-center gap-2 rounded-lg bg-slate-900 px-3 text-[11px] font-bold text-white transition hover:bg-slate-800">
                Workflow bearbeiten <span class="rounded bg-white/10 px-1.5 py-0.5 text-[9px]">{{ $taskCount }}</span>
            </button>
            <button type="button" x-on:click="openPanel('copilot')" class="inline-flex h-8 items-center gap-2 rounded-lg border border-cyan-200 bg-cyan-50 px-3 text-[11px] font-bold text-cyan-800 transition hover:border-cyan-300 hover:bg-cyan-100">
                Copilot <span class="h-1.5 w-1.5 rounded-full {{ $copilotSession && in_array($copilotSession->status, \App\Models\WorkflowCopilotSession::ACTIVE_STATUSES, true) ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
            </button>
            <button type="button" x-on:click="openPanel('tools')" class="h-8 rounded-lg border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700">Selector & Browser</button>
            <button type="button" x-on:click="openPanel('runtime')" class="inline-flex h-8 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700">
                Daten & Logs @if($run)<span class="font-mono text-[9px] text-slate-400">#{{ $run->id }}</span>@endif
            </button>
            <button type="button" x-on:click="openPanel('revisions')" class="inline-flex h-8 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-[11px] font-bold text-slate-700 transition hover:border-cyan-300 hover:text-cyan-700">
                Revisionen <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] text-slate-500">{{ $workflow->studioRevisions->count() }}</span>
            </button>
        </div>
    </nav>

    <main class="relative min-h-0 flex-1 overflow-hidden bg-slate-100 p-3">
        <section class="h-full" aria-label="Dauerhaft sichtbarer Workflow-Test">@include('livewire.admin.network.workflow-studio.browser')</section>

        <div wire:loading.delay.flex wire:target="startRun,pauseRun,resumeRun,runSingleTask,stopRun,restartRun,runProbe,commitProbeAsTask,saveSelectedTask,saveSessionDefinition,startCopilot,pauseCopilot,resumeCopilot,restartCopilot,stopCopilot,setPermissionMode" class="pointer-events-none absolute inset-0 z-50 hidden items-center justify-center bg-white/55 backdrop-blur-[1px]">
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-xl"><span class="h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-cyan-600"></span>Studio aktualisiert …</span>
        </div>
    </main>

    <div
        x-cloak
        x-show="isPanelOpen()"
        x-effect="if (activePanel !== null && !isPanelOpen()) closePanel()"
        x-transition.opacity
        x-on:click.self="closePanel()"
        class="absolute inset-0 z-[65] flex items-stretch justify-center bg-slate-950/55 p-2 backdrop-blur-sm sm:p-5"
        role="dialog"
        aria-modal="true"
        x-bind:aria-label="panelTitles[activePanel] || 'Workflow Studio Werkzeug'"
    >
        <section class="flex min-h-0 w-full max-w-[1720px] flex-col overflow-hidden rounded-2xl border border-white/20 bg-slate-100 shadow-2xl">
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Workflow Studio Werkzeug</p>
                    <h2 class="mt-1 text-base font-bold text-slate-950" x-text="panelTitles[activePanel] || 'Werkzeug'"></h2>
                </div>
                <button type="button" x-on:click="closePanel()" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-100">
                    Schliessen <span aria-hidden="true">&times;</span>
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-hidden p-3 sm:p-4">
                <section x-show="activePanel === 'builder'" class="h-full">
                    <livewire:admin.network.workflow-studio-task-editor :workflow="$workflow" :studio-session-id="$session->id" :key="'workflow-studio-builder-'.$workflow->id.'-'.$session->id" />
                </section>
                <section x-show="activePanel === 'copilot'" class="h-full overflow-y-auto">
                    <div class="mx-auto max-w-[1500px] rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">@include('livewire.admin.network.workflow-studio.copilot')</div>
                </section>
                <section x-show="activePanel === 'tools'" class="h-full">@include('livewire.admin.network.workflow-studio.tools')</section>
                <section x-show="activePanel === 'runtime'" class="h-full">@include('livewire.admin.network.workflow-studio.runtime')</section>
                <section x-show="activePanel === 'revisions'" class="h-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <livewire:admin.network.workflow-revision-history :workflow-id="$workflow->id" :studio-session-id="$session->id" :key="'workflow-studio-revisions-'.$workflow->id.'-'.$session->id" />
                </section>
            </div>
        </section>
    </div>

    @include('livewire.admin.network.workflow-studio.selector-modal')
</div>
