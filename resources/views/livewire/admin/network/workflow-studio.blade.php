<div
    x-data="{
        activeTab: 'browser',
        openTab(tab) {
            this.activeTab = tab;
        },
    }"
    x-on:workflow-preview-task-selected.window="
        if (Number($event.detail.workflowId || 0) === {{ (int) $workflow->id }}) {
            $wire.editTask(Number($event.detail.stepId || 0), String($event.detail.taskKey || ''));
        }
    "
    class="fixed inset-0 z-[70] flex h-screen flex-col overflow-hidden bg-slate-100 text-slate-900"
    wire:poll.2s="refreshStudio"
>
    @php
        $runStatus = $run?->status ?? 'draft';
        $isPaused = $runStatus === 'paused';
        $isActive = in_array($runStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true);
        $cursorStepId = $run?->current_workflow_step_id;
        $cursorTaskKey = data_get($run?->context_json, 'next_task_key');
        $probeResult = data_get($run?->context_json, 'studio_probe_result');
        $variables = data_get($run?->context_json, 'workflow_variables', []);
        $loopState = data_get($run?->context_json, 'loop_state', []);
        $selectedStep = $steps->firstWhere('id', (int) $selectedStepId);
        $selectedTask = collect($selectedStep?->task_cards ?? [])->firstWhere('key', $selectedTaskKey);
        $permissionLabel = collect($permissionModes)->first(fn ($permission) => $permission->value === $permissionMode)?->label() ?? 'Kritisch nachfragen';
        $statusLabel = match ($runStatus) {
            'queued' => 'Wartet',
            'running' => 'Läuft',
            'waiting' => 'Wartet auf Ereignis',
            'paused' => 'Pausiert',
            'stop_requested' => 'Wird gestoppt',
            'completed' => 'Abgeschlossen',
            'failed' => 'Fehlgeschlagen',
            'cancelled' => 'Gestoppt',
            'timed_out' => 'Zeitüberschreitung',
            'lost', 'unreachable' => 'Nicht erreichbar',
            default => 'Entwurf',
        };
        $statusTone = match ($runStatus) {
            'running', 'queued', 'waiting' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'paused' => 'border-amber-200 bg-amber-50 text-amber-700',
            'failed', 'timed_out', 'lost', 'unreachable' => 'border-rose-200 bg-rose-50 text-rose-700',
            'cancelled', 'stop_requested' => 'border-slate-300 bg-slate-100 text-slate-700',
            'completed' => 'border-sky-200 bg-sky-50 text-sky-700',
            default => 'border-violet-200 bg-violet-50 text-violet-700',
        };
        $tabs = [
            ['id' => 'browser', 'label' => 'Testlauf', 'description' => 'Vorschau & Inspector', 'badge' => $isPaused ? 'pausiert' : null],
            ['id' => 'runtime', 'label' => 'Laufdaten', 'description' => 'Variablen & Log', 'badge' => $run ? '#'.$run->id : null],
        ];
    @endphp

    <header class="relative z-30 shrink-0 border-b border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 lg:px-6">
            <a
                href="{{ route('network.workflows.manage', $workflow) }}"
                class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
                Manager
            </a>

            <div class="min-w-[220px] flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="max-w-xl truncate text-base font-bold text-slate-950">{{ $workflow->name }}</h1>
                    <span class="rounded-full border px-2.5 py-1 text-[11px] font-bold {{ $statusTone }}">
                        <span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-current align-middle"></span>{{ $statusLabel }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">
                    Workflow Studio #{{ $session->id }}
                    <span class="mx-1 text-slate-300">•</span>
                    Revision {{ $workflow->copilot_revision }}
                    <span class="mx-1 text-slate-300">•</span>
                    {{ $permissionLabel }}
                </p>
            </div>

            <div class="flex min-w-0 items-center gap-2">
                <label for="studio-permission" class="sr-only">Copilot-Berechtigung</label>
                <select id="studio-permission" wire:model="permissionMode" class="h-10 max-w-[210px] rounded-lg border-slate-300 bg-white py-1 pl-3 pr-8 text-xs font-semibold text-slate-700 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                    @foreach($permissionModes as $permission)
                        @if($permission->value !== 'unrestricted' || auth()->user()?->isAdmin())
                            <option value="{{ $permission->value }}">{{ $permission->label() }}</option>
                        @endif
                    @endforeach
                </select>
                <button type="button" wire:click="setPermissionMode" class="h-10 rounded-lg border border-sky-200 bg-sky-50 px-3 text-xs font-bold text-sky-700 transition hover:border-sky-300 hover:bg-sky-100">Anwenden</button>
            </div>
        </div>

        <div class="flex items-center gap-2 overflow-x-auto border-t border-slate-100 bg-slate-50/80 px-4 py-2.5 lg:px-6">
            <button type="button" wire:click="startRun" @disabled($isActive || $isPaused) class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"></path></svg>
                Starten & durchlaufen
            </button>
            <button type="button" wire:click="pauseRun" @disabled(! $isActive) class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-amber-200 bg-white px-3 text-xs font-bold text-amber-700 shadow-sm transition hover:bg-amber-50 disabled:opacity-35">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h3v14H7zm7 0h3v14h-3z"></path></svg>
                Sicher pausieren
            </button>
            <button type="button" wire:click="runSingleTask" @disabled(! $isPaused || $selectedTaskKey === '') class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-sky-200 bg-white px-3 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50 disabled:opacity-35">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 5v14l11-7z"></path></svg>
                1 Task ausführen
            </button>
            <button type="button" wire:click="resumeRun" @disabled(! $isPaused) class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 text-xs font-bold text-emerald-700 shadow-sm transition hover:bg-emerald-50 disabled:opacity-35">
                Fortsetzen bis Ende
            </button>
            <span class="mx-1 h-6 w-px shrink-0 bg-slate-200"></span>
            <button type="button" wire:click="stopRun" wire:confirm="Diesen Lauf wirklich stoppen?" @disabled(! $isActive && ! $isPaused) class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-rose-200 bg-white px-3 text-xs font-bold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:opacity-35">Stoppen</button>
            <button type="button" wire:click="restartRun" wire:confirm="Aktuellen Lauf beenden und neu starten?" class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 shadow-sm transition hover:bg-slate-100">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.34 5.66M20 4v7h-7"></path></svg>
                Neustart
            </button>
            <span class="mx-1 h-6 w-px shrink-0 bg-slate-200"></span>
            <button type="button" wire:click="editSelectedTask" @disabled($selectedTaskKey === '') class="inline-flex h-9 shrink-0 items-center gap-2 rounded-lg border border-sky-200 bg-white px-3 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-35">Task bearbeiten</button>
            <button type="button" wire:click="$set('showCopilotSettingsModal', true)" class="inline-flex h-9 shrink-0 items-center rounded-lg border border-sky-200 bg-white px-3 text-xs font-bold text-sky-700 shadow-sm transition hover:bg-sky-50">Copilot & Ziele</button>
            <button type="button" wire:click="$set('showCheckpointsModal', true)" class="inline-flex h-9 shrink-0 items-center rounded-lg border border-violet-200 bg-white px-3 text-xs font-bold text-violet-700 shadow-sm transition hover:bg-violet-50">Checkpoints <span class="ml-1 rounded-full bg-violet-100 px-1.5 py-0.5 text-[9px]">{{ $checkpoints->count() }}</span></button>
        </div>

        @if($permissionMode === 'unrestricted')
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900 lg:px-6">
                <span><strong>Uneingeschränkter Zugriff:</strong> Der Copilot darf in dieser Sitzung auch Außenaktionen ohne Einzelrückfrage ausführen.</span>
                <label class="inline-flex items-center gap-2 font-semibold">
                    <input type="checkbox" wire:model="unrestrictedWarningAcknowledged" class="rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                    Warnung verstanden
                </label>
            </div>
        @endif

        @if($pendingConfirmation)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-950 lg:px-6">
                <span><strong>Copilot fragt nach:</strong> {{ $pendingConfirmation['message'] ?? 'Aktion bestätigen?' }}</span>
                <div class="flex gap-2">
                    <button type="button" wire:click="discardPendingAction" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800">Verwerfen</button>
                    <button type="button" wire:click="confirmPendingAction" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-amber-400">Einmalig ausführen</button>
                </div>
            </div>
        @endif

        @if($lastActionResult)
            <div class="border-t px-4 py-2 text-xs lg:px-6 {{ ($lastActionResult['status'] ?? '') === 'failed' ? 'border-rose-200 bg-rose-50 font-semibold text-rose-700' : 'border-sky-100 bg-sky-50 text-sky-800' }}">
                {{ $lastActionResult['message'] ?? '' }}
            </div>
        @endif
        @error('studio')
            <div class="border-t border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 lg:px-6">{{ $message }}</div>
        @enderror
    </header>

    <nav class="relative z-20 shrink-0 overflow-x-auto border-b border-slate-200 bg-white px-3 lg:px-6" role="tablist" aria-label="Workflow-Studio Bereiche">
        <div class="flex min-w-max items-stretch gap-1">
            @foreach($tabs as $tab)
                <button
                    type="button"
                    role="tab"
                    x-on:click="openTab(@js($tab['id']))"
                    x-bind:aria-selected="activeTab === @js($tab['id'])"
                    x-bind:class="activeTab === @js($tab['id']) ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'"
                    class="group flex min-w-[138px] items-center justify-between gap-3 border-b-2 px-3 py-3 text-left transition"
                >
                    <span>
                        <span class="block text-xs font-bold">{{ $tab['label'] }}</span>
                        <span class="mt-0.5 block text-[10px] font-medium text-slate-400">{{ $tab['description'] }}</span>
                    </span>
                    @if($tab['badge'] !== null)
                        <span x-bind:class="activeTab === @js($tab['id']) ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-500'" class="rounded-full px-2 py-0.5 text-[10px] font-bold">{{ $tab['badge'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    </nav>

    <main class="relative min-h-0 flex-1 overflow-hidden bg-slate-100 p-3 lg:p-4">
        <section x-show="activeTab === 'browser'" class="h-full" role="tabpanel">
            @include('livewire.admin.network.workflow-studio.browser')
        </section>
        <section x-cloak x-show="activeTab === 'runtime'" class="h-full" role="tabpanel">
            @include('livewire.admin.network.workflow-studio.runtime')
        </section>

        <div
            wire:loading.delay.flex
            wire:target="startRun,pauseRun,resumeRun,runSingleTask,stopRun,restartRun,runProbe,commitProbeAsTask,createCheckpoint,restoreCheckpoint,branchFromCheckpoint,saveSelectedTask,saveSessionDefinition,startCopilot,setPermissionMode"
            class="pointer-events-none absolute inset-0 z-50 hidden items-center justify-center bg-white/55 backdrop-blur-[1px]"
        >
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-xl">
                <span class="h-3 w-3 animate-spin rounded-full border-2 border-slate-300 border-t-sky-600"></span>
                Studio aktualisiert …
            </span>
        </div>
    </main>

    <x-dialog-modal wire:model="showCopilotSettingsModal" maxWidth="5xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Copilot, Ziel & Ausführung</span><p class="mt-1 text-xs font-normal text-slate-500">Planungsdaten und Berechtigungen dieser Studio-Sitzung.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.workflow-studio.copilot')</x-slot>
        <x-slot name="footer"><button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Schließen</button></x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showCheckpointsModal" maxWidth="5xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Checkpoints verwalten</span><p class="mt-1 text-xs font-normal text-slate-500">Gespeicherte Laufzustände laden, verzweigen oder neu anlegen.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.workflow-studio.checkpoints')</x-slot>
        <x-slot name="footer"><button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Schließen</button></x-slot>
    </x-dialog-modal>

    <livewire:admin.network.workflow-studio-task-editor
        :workflow="$workflow"
        :studio-session-id="$session->id"
        :key="'workflow-studio-task-editor-'.$workflow->id.'-'.$session->id"
    />
</div>
