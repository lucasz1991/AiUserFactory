<div
    class="h-full min-h-0 overflow-y-auto overscroll-contain xl:overflow-hidden"
    data-studio-task-editor
    x-data="{
        mobilePanel: 'canvas',
        focusedTask: '',
        hoveredRouteNode: '',
        activeRouteNode: '',
        routeFocusNode() {
            return this.hoveredRouteNode || this.activeRouteNode;
        },
        setHoveredRouteNode(node) {
            this.hoveredRouteNode = node || '';
        },
        setActiveRouteNode(node) {
            this.activeRouteNode = this.activeRouteNode === node ? '' : (node || '');
        },
        scrollBehavior() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
        },
        armTaskInsert(stepId) {
            this.mobilePanel = 'catalog';
            $wire.selectCatalogTarget(stepId);
            this.$nextTick(() => document.querySelector('[data-studio-task-catalog]')?.scrollIntoView({ behavior: this.scrollBehavior(), block: 'nearest' }));
        },
        async focusOverviewTask(detail) {
            const stepId = Number(detail?.stepId || 0);
            const taskKey = String(detail?.taskKey || '');

            if (!stepId || !taskKey) return;

            await $wire.selectOverviewTask(stepId, taskKey);
            this.mobilePanel = 'canvas';
            this.focusedTask = `${stepId}::${taskKey}`;
            this.$nextTick(() => {
                const target = Array.from(this.$root.querySelectorAll('[data-workflow-task-key]'))
                    .find((node) => node.dataset.workflowTaskKey === taskKey && Number(node.closest('[data-workflow-step-id]')?.dataset.workflowStepId || 0) === stepId);
                this.activeRouteNode = target?.dataset.workflowTaskNode || '';
                target?.scrollIntoView({ behavior: this.scrollBehavior(), block: 'nearest', inline: 'center' });
            });
        },
        editOverviewTask(detail) {
            const stepId = Number(detail?.stepId || 0);
            const taskKey = String(detail?.taskKey || '');

            if (stepId && taskKey) {
                $wire.openFromStudio(stepId, taskKey);
            }
        },
    }"
    x-on:workflow-preview-task-selected.stop="focusOverviewTask($event.detail)"
    x-on:workflow-preview-task-edit-requested.stop="editOverviewTask($event.detail)"
>
    <nav data-studio-mobile-switch class="sticky top-0 z-40 grid grid-cols-2 gap-1 border-b border-slate-200 bg-white/95 p-2 backdrop-blur xl:hidden" aria-label="Mobiler Editorbereich">
        <button type="button" x-on:click="mobilePanel = 'canvas'" x-bind:aria-pressed="mobilePanel === 'canvas'" x-bind:class="mobilePanel === 'canvas' ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600'" class="min-h-11 rounded-xl px-3 text-xs font-bold transition">
            Workflow <span class="ml-1 font-mono opacity-70">{{ $steps->sum(fn ($step) => count($step->task_cards)) }}</span>
        </button>
        <button type="button" x-on:click="mobilePanel = 'catalog'" x-bind:aria-pressed="mobilePanel === 'catalog'" x-bind:class="mobilePanel === 'catalog' ? 'bg-slate-900 text-white shadow-sm' : 'bg-slate-100 text-slate-600'" class="min-h-11 rounded-xl px-3 text-xs font-bold transition">
            Task-Bibliothek <span class="ml-1 font-mono opacity-70">{{ $taskDefinitions->count() }}</span>
        </button>
    </nav>

    <div data-studio-task-layout class="ff-canvas-shell grid min-h-full overflow-visible xl:h-full xl:min-h-0 xl:grid-cols-[320px_minmax(0,1fr)] xl:overflow-hidden">
        <aside
            x-cloak
            x-bind:class="mobilePanel === 'catalog' ? 'flex' : 'hidden xl:flex'"
            data-studio-task-catalog
            class="ff-task-drawer h-[calc(100dvh-10rem)] min-h-[480px] shrink-0 flex-col border-b bg-white text-slate-900 xl:h-auto xl:min-h-0 xl:border-b-0 xl:border-r"
        >
            <div class="border-b border-slate-200 px-4 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="ff-kicker">Bausteine</p>
                        <h2 class="mt-1 text-base font-bold tracking-tight text-slate-950">Task-Katalog</h2>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $taskDefinitions->count() }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">Task anklicken oder direkt in eine Liste ziehen. Danach öffnet sich das vollständige Formular.</p>
            </div>

            <div class="space-y-3 border-b border-slate-200 p-4">
                <div>
                    <label for="studio-catalog-target" class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Zielliste</label>
                    <select id="studio-catalog-target" wire:model.live="catalogTargetStepId" class="ff-search-field mt-1.5 w-full px-3 text-xs font-semibold" @disabled(! $canEdit)>
                        @forelse($steps as $step)
                            <option value="{{ $step->id }}">{{ $step->name }}</option>
                        @empty
                            <option value="">Zuerst eine Liste anlegen</option>
                        @endforelse
                    </select>
                </div>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input type="search" wire:model.live.debounce.250ms="taskSearch" class="ff-search-field w-full py-2 pl-9 pr-3 text-xs placeholder:text-slate-400" placeholder="Task suchen …" aria-label="Tasks im Katalog suchen">
                </div>
            </div>

            <div class="border-b border-slate-200 p-3 sm:hidden">
                <label for="studio-task-group-mobile" class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Aufgabengruppe</label>
                <select id="studio-task-group-mobile" wire:model.live="activeTaskGroup" class="ff-search-field mt-1.5 w-full px-3 text-xs font-semibold">
                    @foreach($taskGroups as $taskGroup)
                        <option value="{{ $taskGroup }}">{{ $taskGroupLabels[$taskGroup] ?? $taskGroup }} ({{ $taskGroupCounts->get($taskGroup, 0) }})</option>
                    @endforeach
                </select>
            </div>

            <nav class="hidden shrink-0 gap-1 overflow-x-auto border-b border-slate-200 px-3 sm:flex" aria-label="Task-Gruppen">
                @foreach($taskGroups as $taskGroup)
                    <button
                        type="button"
                        wire:click="$set('activeTaskGroup', @js($taskGroup))"
                        aria-pressed="{{ $activeTaskGroup === $taskGroup ? 'true' : 'false' }}"
                        class="whitespace-nowrap border-b-2 px-2 py-3 text-[11px] font-bold transition {{ $activeTaskGroup === $taskGroup ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-950' }}"
                    >{{ $taskGroupLabels[$taskGroup] ?? $taskGroup }} <span class="font-mono opacity-60">{{ $taskGroupCounts->get($taskGroup, 0) }}</span></button>
                @endforeach
            </nav>

            <div class="border-b border-slate-200 bg-slate-50/80 px-4 py-2.5 text-[10px] leading-4 text-slate-500" role="status">
                @if($searchActive)
                    Suchergebnisse aus allen Aufgabengruppen
                @else
                    {{ data_get($taskGroupMeta, $activeTaskGroup.'.description', '') }}
                @endif
            </div>

            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                @forelse($visibleTaskDefinitions as $taskDefinition)
                    <button
                        type="button"
                        wire:click="prepareCatalogTask(@js($taskDefinition['key']))"
                        x-on:click="mobilePanel = 'canvas'"
                        x-bind:draggable="window.matchMedia('(pointer: fine)').matches && @js($canEdit)"
                        x-on:dragstart.stop="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                        @disabled(! $canEdit || $steps->isEmpty())
                        data-task-library-key="{{ $taskDefinition['key'] }}"
                        data-task-library-group="{{ $taskDefinition['library_group'] }}"
                        class="ff-catalog-card group block w-full border bg-white p-3 text-left disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <span class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate text-xs font-bold text-slate-950">{{ $taskDefinition['label'] }}</span>
                                    @if($searchActive)
                                        <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500">{{ data_get($taskGroupMeta, $taskDefinition['library_group'].'.short_label', $taskDefinition['library_group']) }}</span>
                                    @endif
                                </span>
                                <span class="mt-1 block line-clamp-2 text-[10px] leading-4 text-slate-500">{{ $taskDefinition['description'] }}</span>
                            </span>
                            <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-blue-200 bg-blue-50 font-mono text-[11px] font-bold text-blue-700">+</span>
                        </span>
                    </button>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-xs text-slate-500">Keine passenden Tasks gefunden.</div>
                @endforelse
            </div>
        </aside>

        <section
            x-cloak
            x-bind:class="mobilePanel === 'canvas' ? 'flex' : 'hidden xl:flex'"
            data-studio-editor-canvas-panel
            class="min-h-[560px] min-w-0 shrink-0 flex-col bg-slate-50 xl:min-h-0"
        >
            <div class="ff-canvas-toolbar flex shrink-0 flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                <div>
                    <div class="flex items-center gap-2">
                        <div>
                            <p class="ff-kicker">Editor</p>
                            <h2 class="mt-0.5 text-sm font-bold tracking-tight text-slate-950">Workflow aufbauen</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ $steps->count() }} Listen</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ $steps->sum(fn ($step) => count($step->task_cards)) }} Tasks</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Listen und Tasks verschieben, bearbeiten oder direkt aus dem Katalog einsetzen.</p>
                </div>
                <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="ff-action-trigger ff-action-trigger--primary inline-flex h-10 items-center gap-2 px-3 text-xs font-bold disabled:cursor-not-allowed disabled:opacity-40">
                    <span class="text-base leading-none">+</span> Neue Liste
                </button>
            </div>

            <section
                x-data="{ overviewOpen: true }"
                data-studio-editor-overview
                class="shrink-0 border-b border-slate-200 bg-white/90"
                aria-label="Workflow-Übersicht"
            >
                <button
                    type="button"
                    x-on:click="overviewOpen = ! overviewOpen"
                    x-bind:aria-expanded="overviewOpen"
                    class="flex min-h-11 w-full items-center justify-between gap-3 px-4 py-2 text-left transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                >
                    <span>
                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-blue-700">Workflow-Karte</span>
                        <span class="mt-0.5 block text-[11px] text-slate-500">Task antippen, um die zugehörige Karte im Editor zu fokussieren.</span>
                    </span>
                    <span class="shrink-0 text-[11px] font-bold text-slate-600" x-text="overviewOpen ? 'Einklappen' : 'Aufklappen'"></span>
                </button>
                <div x-cloak x-show="overviewOpen" x-collapse.duration.180ms class="ff-builder-overview-map border-t border-slate-100 px-3 py-2 sm:px-4">
                    <x-workflows.minimap
                        :workflow="$workflow"
                        :workflow-run="$activeRun"
                        :active-step-id="$activeRun?->current_workflow_step_id"
                        :active-task-key="data_get($activeRun?->context_json, 'next_task_key')"
                        :selected-step-id="$overviewSelectedStepId"
                        :selected-task-key="$overviewSelectedTaskKey"
                        :show-header="false"
                        :selectable-tasks="true"
                        :zoomable="true"
                        initial-zoom="overview"
                        :instance="'builder-'.$studioSessionId"
                    />
                </div>
            </section>

            <details class="group shrink-0 border-b border-slate-200 bg-white/80 px-4 py-2.5 text-xs text-slate-600">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-700 marker:hidden">
                    <span>Kurzhilfe zu Workflow, Listen, Tasks und Weiterleitungen</span>
                    <span class="text-[10px] font-semibold text-slate-600 group-open:hidden">Öffnen</span>
                    <span class="hidden text-[10px] font-semibold text-slate-600 group-open:inline">Schließen</span>
                </summary>
                <div class="mt-3 grid gap-3 leading-5 md:grid-cols-2 xl:grid-cols-4">
                    <p><strong>Workflow:</strong> Der gesamte Prozess mit Ziel, Eingaben und Erfolgskriterien. Aktivierte Listen laufen grundsätzlich von links nach rechts.</p>
                    <p><strong>Liste:</strong> Eine fachliche Phase mit eigenen Erfolgs-, Fehler-, Partial- und Timeout-Wegen. Ihre Route greift erst, wenn keine Task-Route Vorrang hat.</p>
                    <p><strong>Task:</strong> Eine konkrete Aktion. Ohne eigene Route folgt die nächste Karte; <code>next</code> und <code>on_error</code> können zu Karten, Listen, Ende oder Fehler führen.</p>
                    <p><strong>Loop:</strong> Start, Reader, optionales Array-Sammeln und Loop-Ende bilden einen Block. Normaler Abschluss, leere Liste und Fehler besitzen getrennte Ziele.</p>
                </div>
            </details>

            @if(! $canEdit)
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
                    <span><strong>Bearbeitung gesperrt:</strong> Der Lauf ist {{ $runStatus ?: 'aktiv' }}. Pausiere ihn, damit Browserzustand und Task-Reihenfolge konsistent bleiben.</span>
                </div>
            @endif
            @error('studioBuilder')
                <div class="shrink-0 border-b border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-700">{{ $message }}</div>
            @enderror

            <div data-studio-workflow-canvas class="ff-canvas-grid relative min-h-0 flex-1 overflow-auto overscroll-contain">
                <div
                    x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })"
                    class="flex min-h-full min-w-max items-start gap-8 px-4 pb-10 pt-6 sm:px-6 sm:pt-8"
                >
                    @forelse($steps as $step)
                        <div
                            x-sort:item="{{ $step->id }}"
                            wire:key="studio-builder-step-{{ $step->id }}"
                            data-studio-editor-step
                            class="rounded-2xl transition {{ (string) $step->id === $catalogTargetStepId ? 'ring-2 ring-blue-500 ring-offset-4 ring-offset-slate-50' : '' }}"
                        >
                            <x-workflows.step-card :step="$step" :locked="! $canEdit">
                                <x-slot name="actions">
                                    <button type="button" wire:click="openEditStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Liste bearbeiten</button>
                                    <button type="button" wire:click="toggleStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}</button>
                                    <button type="button" wire:click="selectCatalogTarget({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-cyan-700 hover:bg-cyan-50">Katalog hier einsetzen</button>
                                    <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste samt Tasks wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-rose-700 hover:bg-rose-50">Liste entfernen</button>
                                </x-slot>
                            </x-workflows.step-card>
                        </div>
                    @empty
                        <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="flex min-h-64 w-[320px] items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-white/80 p-8 text-center text-sm font-bold text-slate-600 transition hover:border-cyan-400 hover:text-cyan-700 disabled:opacity-40">Erste Liste anlegen</button>
                    @endforelse

                    @if($steps->isNotEmpty())
                        <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="flex min-h-48 w-[240px] shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-white/70 p-6 text-center text-sm font-bold text-slate-500 transition hover:border-cyan-400 hover:bg-white hover:text-cyan-700 disabled:opacity-40">+ Weitere Liste</button>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <x-ui.dialog-modal wire:model="showAddStepModal" maxWidth="2xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Neue Workflow-Liste</span><p class="mt-1 text-xs font-normal text-slate-500">Eine Liste gruppiert zusammengehörige Tasks und besitzt eigene Erfolgs- und Fehlerwege.</p></div>
        </x-slot>
        <x-slot name="content">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="studio-new-step-type" class="block text-sm font-medium text-slate-700">Aufgabentyp</label>
                    <select id="studio-new-step-type" wire:model.live="newStepType" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                        <option value="preparation">Vorbereitung</option>
                        <option value="data_processing">Daten verarbeiten</option>
                        <option value="browser_control">Browsersteuerung</option>
                        <option value="interaction">Interaktion</option>
                        <option value="decision">Status prüfen</option>
                        <option value="cleanup">Abschluss</option>
                    </select>
                </div>
                <div>
                    <label for="studio-new-step-name" class="block text-sm font-medium text-slate-700">Listenname</label>
                    <input id="studio-new-step-name" type="text" wire:model.defer="newStepName" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500" placeholder="z. B. Login vorbereiten">
                    @error('newStepName') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeAddStepModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" wire:click="addStep" class="ml-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Liste anlegen</button>
        </x-slot>
    </x-ui.dialog-modal>

    <x-ui.dialog-modal wire:model="showEditStepModal" maxWidth="2xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Liste bearbeiten</span><p class="mt-1 text-xs font-normal text-slate-500">Name, Status, Pause und Routing dieser Liste ändern.</p></div>
        </x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <label for="studio-edit-step-name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="studio-edit-step-name" type="text" wire:model.defer="editingStepName" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                    @error('editingStepName') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="studio-edit-step-description" class="block text-sm font-medium text-slate-700">Beschreibung</label>
                    <textarea id="studio-edit-step-description" rows="3" wire:model.defer="editingStepDescription" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="editingStepEnabled" class="rounded border-slate-300 text-cyan-600 shadow-sm focus:ring-cyan-500"> Aktiv
                    </label>
                    <div>
                        <label for="studio-edit-step-wait" class="block text-sm font-medium text-slate-700">Pause danach (Sekunden)</label>
                        <input id="studio-edit-step-wait" type="number" min="0" max="3600" wire:model.defer="editingStepWaitAfterSeconds" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach(['editingStepSuccessTarget' => 'Bei Erfolg', 'editingStepFailedTarget' => 'Bei Fehler'] as $model => $label)
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ $label }}</label>
                            <select wire:model.defer="{{ $model }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="">Keine Route</option>
                                <option value="end">Workflow beenden</option>
                                <option value="fail">Fehlerroute</option>
                                @foreach($steps as $targetStep)
                                    <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                                    @foreach($targetStep->task_cards as $targetTask)
                                        <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Task: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label class="block text-sm font-medium text-slate-700">Grund bei Erfolg</label><input type="text" wire:model.defer="editingStepSuccessReason" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></div>
                    <div><label class="block text-sm font-medium text-slate-700">Grund bei Fehler</label><input type="text" wire:model.defer="editingStepFailedReason" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></div>
                </div>
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-slate-700">Fehler-Rückleitung: maximale Versuche</label>
                    <input type="number" min="0" max="20" wire:model.defer="editingStepFailedRetryLimit" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeEditStepModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" wire:click="saveEditStep" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Speichern & Revision erstellen</button>
        </x-slot>
    </x-ui.dialog-modal>

    <x-ui.dialog-modal wire:model="showAddTaskModal" maxWidth="3xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task einsetzen</span><p class="mt-1 text-xs font-normal text-slate-500">Parameter, Browserfenster sowie Erfolgs- und Fehlerwege vor dem Einfügen festlegen.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'create', 'steps' => $steps, 'taskDefinitions' => $taskDefinitions])</x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeAddTaskModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" x-on:click.prevent="const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;newTask&quot;]')?.value || 'person'; $wire.addTaskCard(source);" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Task einsetzen</button>
        </x-slot>
    </x-ui.dialog-modal>

    <x-ui.dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task bearbeiten</span><p class="mt-1 text-xs font-normal text-slate-500">Alle Task-Einstellungen aus dem Workflow-Manager stehen auch im pausierten Testlauf zur Verfügung.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'edit', 'steps' => $steps, 'taskDefinitions' => $taskDefinitions])</x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeEditTaskModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" x-on:click.prevent="const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;editingTask&quot;]')?.value || 'person'; $wire.saveEditTaskCard(source);" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Speichern & Revision erstellen</button>
        </x-slot>
    </x-ui.dialog-modal>
</div>
