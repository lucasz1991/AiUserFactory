<div
    x-data="{
        fallbackNotice: null,
        fallbackNoticeTimer: null,
        previewInstance: @js('studio-definition-'.$session->id),
        init() {
            if (! @js($hosted)) this.$dispatch('workflow-studio-pin-copilot');
        },
        destroy() {
            window.clearTimeout(this.fallbackNoticeTimer);
            if (! @js($hosted)) this.$dispatch('workflow-studio-unpin-copilot');
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
        acceptsPreviewEvent(event) {
            const detail = event.detail || {};
            const instance = String(detail.instance || detail.source || '');
            const originatesHere = typeof Node !== 'undefined'
                && event.target instanceof Node
                && this.$root.contains(event.target);

            return Number(detail.workflowId || 0) === {{ (int) $workflow->id }}
                && (originatesHere || instance === this.previewInstance);
        },
    }"
    x-on:workflow-studio-notice.window="showStudioNotice($event)"
    x-on:workflow-preview-task-selected.window="
        if (acceptsPreviewEvent($event)) {
            $wire.selectTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    x-on:workflow-preview-task-edit-requested.window="
        if (acceptsPreviewEvent($event)) {
            $wire.editTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    data-workflow-studio-shell
    data-workflow-studio-mode="{{ $hosted ? 'hosted' : ($embedded ? 'embedded' : 'standalone') }}"
    data-workflow-studio-session="{{ $session->id }}"
    data-workflow-studio-host="{{ $hostInstance }}"
    class="workflow-experience {{ $hosted ? 'relative h-full' : ($embedded ? 'relative h-[100dvh]' : 'fixed inset-0 top-0 z-[70] h-[100dvh]') }} flex min-h-0 w-full flex-col overflow-hidden bg-slate-100 text-slate-900"
    @if($hosted)
        wire:poll.visible.{{ $studioPollSeconds ?? 2 }}s="refreshStudio"
    @else
        wire:poll.{{ $studioPollSeconds ?? 2 }}s="refreshStudio"
    @endif
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
        $historicalRunView = (bool) ($historicalRunView ?? false);
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

    <header class="ff-studio-header ff-studio-header--compact relative z-30 shrink-0 border-b">
        @if(! $hosted || $autonomousMode)
        <div data-studio-primary-bar class="ff-studio-primarybar flex flex-wrap items-center justify-end gap-2 px-3 py-1 sm:px-4">
            @if($hosted)
                <div data-workflow-studio-hosted-status class="sr-only">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="ff-kicker">Arbeitsfläche</span>
                        <span class="ff-status-island" data-active="{{ $isActive ? 'true' : 'false' }}" role="status" aria-live="polite">
                            <span class="ff-status-dot" aria-hidden="true"></span>
                            <span class="text-[10px] font-bold tracking-wide">{{ $statusLabel }}</span>
                        </span>
                    </div>
                    <p class="mt-1 text-[10px] text-slate-500">Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</p>
                </div>
            @else
                @if($embedded)
                    <button type="button" x-on:click="$dispatch('workflow-studio-unpin-copilot')" wire:click="closeStudio" class="ff-action-trigger inline-flex h-9 items-center gap-2 px-3 text-xs font-semibold">
                        <span aria-hidden="true">←</span> Manager
                    </button>
                @else
                    <a href="{{ route('network.workflows.manage', $workflow) }}" class="ff-action-trigger inline-flex h-9 items-center gap-2 px-3 text-xs font-semibold">
                        <span aria-hidden="true">←</span> Manager
                    </a>
                @endif

                <div class="ff-studio-heading min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="sr-only">Workflow-Test</span>
                        <h1 class="max-w-xl truncate text-base font-bold tracking-tight text-slate-950">{{ $workflow->name }}</h1>
                        <span class="ff-status-island" data-active="{{ $isActive ? 'true' : 'false' }}" role="status" aria-live="polite">
                            <span class="ff-status-dot" aria-hidden="true"></span>
                            <span class="text-[10px] font-bold tracking-wide">{{ $statusLabel }}</span>
                        </span>
                    </div>
                    <p class="mt-1 text-[10px] text-slate-500">Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</p>
                </div>
            @endif

            <div class="ff-segmented-control ff-studio-mode-control" role="group" aria-label="Testmodus">
                <button type="button" wire:click="chooseControlMode('interactive')" aria-pressed="{{ ! $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked || $historicalRunView) class="h-8 px-3 text-[11px] font-bold {{ ! $autonomousMode ? 'text-slate-950' : 'text-slate-500 hover:text-slate-800' }} disabled:cursor-not-allowed disabled:opacity-40">
                    Eigenes Testen
                </button>
                <button type="button" wire:click="chooseControlMode('autonomous')" aria-pressed="{{ $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked || $historicalRunView) class="h-8 px-3 text-[11px] font-bold {{ $autonomousMode ? 'text-slate-950' : 'text-slate-500 hover:text-blue-800' }} disabled:cursor-not-allowed disabled:opacity-40">
                    Autonomer Copilot
                </button>
            </div>
            @if($modeLocked)
                <button type="button" wire:click="unlockControlMode"
                        wire:confirm="Testmodus fuer diese Sitzung entsperren? Der Modus kann danach neu gewaehlt werden."
                        @disabled($historicalRunView)
                        class="ff-studio-control"
                        aria-label="Modus gesperrt · entsperren"
                        title="Modus ist festgeschrieben – hier entsperren, um ihn neu zu waehlen">
                    Entsperren
                </button>
            @endif
        </div>
        @else
            <span data-workflow-studio-hosted-status class="sr-only" role="status" aria-live="polite">{{ $statusLabel }} · Sitzung #{{ $session->id }} · Revision {{ $workflow->copilot_revision }} · {{ $permissionLabel }}</span>
        @endif

        @if($historicalRunView)
            <div data-workflow-historical-run-readonly class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-violet-200 bg-violet-50 px-4 py-2.5 text-xs text-violet-950 lg:px-6" role="status" aria-live="polite">
                <div class="min-w-0">
                    <p class="font-bold">Historischer Testlauf · nur ansehen</p>
                    <p class="mt-0.5 leading-5">Lauf-, Sitzungs- und Copilot-Steuerungen sind gesperrt. Bearbeiten öffnet immer die aktuelle Workflow-Definition.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="openDefinitionBuilder" data-workflow-studio-builder-trigger class="ff-action-trigger inline-flex min-h-11 items-center px-3 text-xs font-bold">Aktuelle Definition öffnen</button>
                    @if($selectedTask)
                        <button type="button" wire:click="editSelectedTask" class="ff-action-trigger inline-flex min-h-11 items-center px-3 text-xs font-bold">Ausgewählte Task bearbeiten</button>
                    @endif
                </div>
            </div>
        @endif

        @if(! $autonomousMode)
            <div data-studio-run-controls class="ff-studio-run-controls flex min-w-0 flex-wrap items-center gap-1.5 border-t px-3 py-1.5 sm:px-4">
                <div class="ff-studio-run-actions" role="group" aria-label="Test steuern">
                    <button type="button" wire:click="startRun" data-studio-run-start-trigger @disabled($historicalRunView || $isActive || $isPaused) class="ff-studio-control {{ ! $isPaused ? 'ff-studio-control--primary' : '' }}">
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4.5a.75.75 0 0 1 1.14-.64l8 5.5a.75.75 0 0 1 0 1.28l-8 5.5A.75.75 0 0 1 6 15.5v-11Z" /></svg>
                        Bis Ende
                    </button>
                    @if($isPaused)
                        <button type="button" wire:click="resumeRun" @disabled($historicalRunView || ! $isPaused) class="ff-studio-control ff-studio-control--primary" aria-label="Bis Ende fortsetzen" title="Bis Ende fortsetzen">Fortsetzen</button>
                    @else
                        <button type="button" wire:click="pauseRun" @disabled($historicalRunView || ! $isActive) class="ff-studio-control">Pausieren</button>
                    @endif
                    <button type="button" wire:click="stopRun" data-studio-run-stop-trigger wire:confirm="Diesen Lauf wirklich stoppen?" @disabled($historicalRunView || (! $isActive && ! $isPaused)) class="ff-studio-control ff-studio-control--stop">Stoppen</button>
                </div>

                <div class="ff-studio-task-navigation" role="group" aria-label="Task-Navigation">
                    <button type="button" wire:click="selectPreviousTask" aria-label="Vorherige Task auswählen" @disabled(! $hasPreviousTask) class="ff-studio-control ff-studio-control--icon">←</button>
                    <span class="ff-studio-task-counter" role="status" aria-live="polite" aria-atomic="true">
                        @if($selectedTaskNumber)
                            <span class="sr-only">Ausgewählte Task {{ $selectedTaskNumber }} von {{ $taskCount }}</span>
                        @else
                            <span class="sr-only">Keine Task ausgewählt. {{ $taskCount }} Tasks verfügbar.</span>
                        @endif
                        <span aria-hidden="true">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span>
                    </span>
                    <button type="button" wire:click="selectNextTask" aria-label="Nächste Task auswählen" @disabled(! $hasNextTask) class="ff-studio-control ff-studio-control--icon">→</button>
                </div>

                @if($selectedTask)
                    <button type="button" wire:click="editSelectedTask" class="ff-studio-control ff-studio-selected-task" title="{{ $selectedStep?->name }} / {{ $selectedTask['title'] ?? $selectedTaskKey }} bearbeiten">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12.5 4 3.5 3.5M3.5 16.5l4-.7L16 7.3a2.5 2.5 0 0 0-3.5-3.5L4.2 12.5l-.7 4Z" /></svg>
                        <span class="sr-only">Ausgewählte Task bearbeiten:</span>
                        <span class="truncate">{{ $selectedTask['title'] ?? $selectedTaskKey }}</span>
                    </button>
                @endif

                <details data-studio-test-options wire:key="studio-test-options-{{ $session->id }}" class="ff-studio-disclosure ff-studio-test-options" x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $el.open" x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="open = false; $refs.summary.focus()">
                    <summary x-ref="summary" class="ff-studio-control">
                        Testoptionen
                        <svg class="ff-studio-disclosure-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m6 8 4 4 4-4" /></svg>
                    </summary>
                    <div class="ff-studio-disclosure-menu">
                        @if($hosted)
                            <div class="ff-studio-test-mode-options">
                                <span class="ff-studio-option-label">Testmodus</span>
                                <div class="ff-segmented-control ff-studio-mode-control" role="group" aria-label="Testmodus">
                                    <button type="button" wire:click="chooseControlMode('interactive')" x-on:click="open = false" aria-pressed="{{ ! $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked || $historicalRunView) class="h-8 px-3 text-[11px] disabled:cursor-not-allowed disabled:opacity-40">Eigenes Testen</button>
                                    <button type="button" wire:click="chooseControlMode('autonomous')" x-on:click="open = false" aria-pressed="{{ $autonomousMode ? 'true' : 'false' }}" @disabled($modeLocked || $historicalRunView) class="h-8 px-3 text-[11px] disabled:cursor-not-allowed disabled:opacity-40">Autonomer Copilot</button>
                                </div>
                                @if($modeLocked)
                                    <button type="button" wire:click="unlockControlMode" x-on:click="open = false" wire:confirm="Testmodus fuer diese Sitzung entsperren? Der Modus kann danach neu gewaehlt werden." @disabled($historicalRunView) class="ff-studio-control ff-studio-menu-action" title="Modus ist festgeschrieben – hier entsperren, um ihn neu zu waehlen">Modus entsperren</button>
                                @endif
                            </div>
                        @endif
                        {{-- Der Testkontext bleibt zwischen Läufen wählbar und wird erst beim Start eingefroren. --}}
                        <label class="ff-studio-person-context">
                            <span>Person / Testkontext</span>
                            <select wire:model="personId" @disabled($historicalRunView || $isActive || $isPaused)>
                                <option value="">Keine Person</option>
                                @foreach($persons as $person)
                                    <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="button" wire:click="runSingleTask" x-on:click="open = false" @disabled($historicalRunView || $isActive || ! $selectedTask) class="ff-studio-control ff-studio-menu-action">Eine Task</button>
                        <button type="button" wire:click="runRealPlayback" x-on:click="open = false" @disabled($historicalRunView || $isActive || $isPaused) title="Wie im echten Ablauf: ohne Screenshots, DOM oder Cursor – am Ende nur das Ergebnis." class="ff-studio-control ff-studio-menu-action">Echter Ablauf</button>
                        <button type="button" wire:click="restartRun" x-on:click="open = false" wire:confirm="Aktuellen Lauf beenden und neu starten?" @disabled($historicalRunView) class="ff-studio-control ff-studio-menu-action">Neu versuchen</button>
                        <button type="button" wire:click="openDefinitionBuilder" x-on:click="open = false" data-workflow-studio-builder-trigger @disabled($isActive) title="{{ $isActive ? 'Den Test pausieren, um Listen und Tasks zu bearbeiten' : 'Task-Bibliothek und Listenverwaltung öffnen' }}" class="ff-studio-control ff-studio-menu-action">Listen &amp; Tasks</button>
                    </div>
                </details>
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
                <div class="flex gap-2"><button type="button" wire:click="discardPendingAction" @disabled($historicalRunView) class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800 disabled:opacity-40">Verwerfen</button><button type="button" wire:click="confirmPendingAction" @disabled($historicalRunView) class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white disabled:opacity-40">Einmalig ausführen</button></div>
            </div>
        @endif
        @error('studio')<span class="sr-only" role="alert">{{ $message }}</span>@enderror
    </header>

    @unless($resultOnly)
        @include('livewire.admin.network.workflow-studio.browser-windows')
        @include('livewire.admin.network.workflow-studio.tool-bar')
    @endunless

    {{-- isolate kapselt die Diagramm-/Overlay-z-Werte (Minimap z-10/z-20, Lade-Overlay z-50) in einen eigenen Stacking-Kontext, damit sie nie mit den Shell-Modalen konkurrieren --}}
    <main wire:key="studio-canvas-{{ $session->id }}" class="ff-canvas-grid relative isolate min-h-0 flex-1 overflow-hidden p-3">
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
        <x-ui.modal id="studio-route-repair-{{ $session->id }}" wire:key="workflow-studio-route-repair" :open="true" close-action="closeRouteRepairModal" return-focus="[data-studio-run-start-trigger]" max-width="2xl">
            <x-slot name="title">
                <h2 class="text-base font-bold">{{ count($routeRepairFindings) }} Verzweigung(en) ohne gültiges Ziel</h2>
                <p class="mt-1 text-xs font-normal text-slate-500">Die Zielkarte oder Zielliste wurde gelöscht. Auf die Standardroute setzen und den Test erneut starten.</p>
            </x-slot>
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

                <x-slot name="actions">
                    <button type="button" x-on:click="$dispatch('close')" class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-100">Schließen</button>
                    <button type="button" wire:click="applyRouteRepairAndStart" @disabled($historicalRunView || $routeRepairBlockingMessages !== []) class="h-9 rounded-lg bg-slate-900 px-3.5 text-xs font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">
                        Auf Standardroute setzen und Test starten
                    </button>
                </x-slot>
        </x-ui.modal>
    @endif

    @if($showCopilotSettingsModal && ! $historicalRunView)
        <x-ui.modal id="studio-copilot-settings-{{ $session->id }}" wire:key="workflow-studio-copilot-settings" wire:model="showCopilotSettingsModal" return-focus="[data-studio-copilot-settings-trigger]" max-width="xl">
            <x-slot name="title">
                <h2 class="text-base font-bold">Einstellungen und Start</h2>
                <p class="mt-1 text-xs font-normal text-slate-500">Ziel, Testkontext und Berechtigungen dieser Studio-Sitzung.</p>
            </x-slot>
            @include('livewire.admin.network.workflow-studio.copilot-rail')
        </x-ui.modal>
    @endif

    @if($historicalRunView)
        <livewire:admin.network.workflow-studio-task-editor
            :workflow="$workflow"
            :studio-session-id="$session->id"
            :modal-only="true"
            :key="'workflow-studio-task-editor-'.$workflow->id.'-'.$session->id"
        />
    @endif

    @if(! $autonomousMode && ! $historicalRunView)
        @include('livewire.admin.network.workflow-studio.selector-modal')
    @endif
</div>
