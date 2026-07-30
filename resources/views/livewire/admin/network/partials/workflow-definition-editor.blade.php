@php
    $modalOnly = (bool) ($modalOnly ?? false);
    $revisionMode = (bool) ($revisionMode ?? false);
    $editorInstance = (string) ($editorInstance ?? ('workflow-'.$workflow->id));
    $initialOverviewStep = $steps->firstWhere('id', $overviewSelectedStepId) ?: $steps->first();
    $initialRouteNode = $initialOverviewStep
        ? $initialOverviewStep->action_key.'::'.($overviewSelectedTaskKey !== '' ? $overviewSelectedTaskKey : '*')
        : '';
    $initialFocusedTask = $initialOverviewStep && $overviewSelectedTaskKey !== ''
        ? $initialOverviewStep->id.'::'.$overviewSelectedTaskKey
        : '';
    $routeMarkerId = 'workflow-route-'.(\Illuminate\Support\Str::slug($editorInstance) ?: 'editor');
    $taskEditReadOnlyState = (bool) ($taskEditReadOnly ?? false);
    $taskEditCanRequestPauseState = (bool) ($taskEditCanRequestPause ?? false);
    $taskEditPauseRequestedState = (bool) ($taskEditPauseRequested ?? false);
    $taskEditRunStatusState = (string) ($taskEditRunStatus ?? 'idle');
    $taskEditLockMessageState = (string) ($taskEditLockMessage ?? '');
@endphp

<div
    class="{{ $modalOnly ? '' : 'h-full min-h-0 overflow-y-auto overscroll-contain xl:overflow-hidden' }}"
    data-studio-task-editor
    data-workflow-definition-editor
    data-workflow-editor-instance="{{ $editorInstance }}"
    x-data="{
        ...workflowRouteSurface({
            instance: @js($routeMarkerId),
            initialNode: @js($initialRouteNode),
        }),
        mobilePanel: 'canvas',
        focusedTask: @js($initialFocusedTask),
        scrollBehavior() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
        },
        armTaskInsert(stepId) {
            this.mobilePanel = 'catalog';
            $wire.selectCatalogTarget(stepId);
            this.$nextTick(() => {
                this.$root.querySelector('[data-studio-task-catalog]')?.scrollIntoView({ behavior: this.scrollBehavior(), block: 'nearest' });
                this.queueRouteRefresh();
            });
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
                this.queueRouteRefresh();
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
    x-on:workflow-standard-editor-focused.window="focusOverviewTask($event.detail)"
    x-on:workflow-task-move-requested.stop="
        const detail = $event.detail || {};
        detail.direction === 'another-list'
            ? $wire.prepareTaskMove(Number(detail.stepId || 0), String(detail.taskKey || ''))
            : $wire.moveTaskRelative(Number(detail.stepId || 0), String(detail.taskKey || ''), String(detail.direction || ''));
    "
    x-on:workflow-step-move-requested.stop="
        const detail = $event.detail || {};
        $wire.moveStepRelative(Number(detail.stepId || 0), String(detail.direction || ''));
    "
    x-on:workflow-minimap-zoom-changed.window="queueRouteRefresh()"
>
    @unless($modalOnly)
    <nav data-studio-mobile-switch class="sticky top-0 z-40 grid grid-cols-2 gap-1.5 border-b border-slate-200 bg-white/95 p-2 backdrop-blur xl:hidden" aria-label="Mobiler Editorbereich">
        <button type="button" x-on:click="mobilePanel = 'canvas'; $nextTick(() => queueRouteRefresh())" x-bind:aria-pressed="mobilePanel === 'canvas'" aria-controls="studio-task-canvas-panel" x-bind:class="mobilePanel === 'canvas' ? 'bg-slate-950 text-white shadow-sm' : 'bg-slate-100 text-slate-600'" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 text-xs font-bold transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="7" height="6" rx="1"></rect><rect x="14" y="14" width="7" height="6" rx="1"></rect><path d="M10 7h3a4 4 0 0 1 4 4v3"></path></svg>
            Workflow <span class="font-mono opacity-70">{{ $steps->sum(fn ($step) => count($step->task_cards)) }}</span>
        </button>
        <button type="button" x-on:click="mobilePanel = 'catalog'; $nextTick(() => queueRouteRefresh())" x-bind:aria-pressed="mobilePanel === 'catalog'" aria-controls="studio-task-catalog-panel" x-bind:class="mobilePanel === 'catalog' ? 'bg-slate-950 text-white shadow-sm' : 'bg-slate-100 text-slate-600'" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 text-xs font-bold transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"></rect><rect x="14" y="3" width="7" height="7" rx="2"></rect><rect x="3" y="14" width="7" height="7" rx="2"></rect><path d="M17.5 14v7M14 17.5h7"></path></svg>
            Bibliothek <span class="font-mono opacity-70">{{ $taskDefinitions->count() }}</span>
        </button>
    </nav>

    <div data-studio-task-layout class="ff-canvas-shell grid min-h-full overflow-visible xl:h-full xl:min-h-0 xl:grid-cols-[350px_minmax(0,1fr)] xl:overflow-hidden">
        <aside
            id="studio-task-catalog-panel"
            x-cloak
            x-bind:class="mobilePanel === 'catalog' ? 'flex' : 'hidden xl:flex'"
            data-studio-task-catalog
            class="ff-task-drawer h-[calc(100dvh-10rem)] min-h-[480px] shrink-0 flex-col border-b bg-white text-slate-900 xl:h-auto xl:min-h-0 xl:border-b-0 xl:border-r"
            aria-labelledby="studio-task-catalog-title"
        >
            <div class="ff-task-library-header relative overflow-hidden border-b border-slate-800 bg-slate-950 px-4 py-5 text-white">
                <div class="pointer-events-none absolute -right-12 -top-16 h-40 w-40 rounded-full bg-blue-500/20 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/10 text-blue-200 shadow-inner">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"></rect><rect x="14" y="3" width="7" height="7" rx="2"></rect><rect x="3" y="14" width="7" height="7" rx="2"></rect><path d="M17.5 14v7M14 17.5h7"></path></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-200">Workflow-Bausteine</p>
                            <h2 id="studio-task-catalog-title" class="mt-1 text-lg font-bold tracking-tight text-white">Task-Bibliothek</h2>
                        </div>
                    </div>
                    <span class="rounded-full border border-white/10 bg-white/10 px-2.5 py-1 font-mono text-[11px] font-bold text-white">{{ $taskDefinitions->count() }}</span>
                </div>
                <p class="relative mt-3 max-w-[30rem] text-xs leading-5 text-slate-300">Task auswählen oder am Desktop direkt in eine Liste ziehen. Die Einstellungen öffnen sich vor dem Einsetzen.</p>
            </div>

            <div class="space-y-3 border-b border-slate-200 bg-white p-4">
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

            <nav class="ff-task-group-rail shrink-0 overflow-x-auto overscroll-x-contain border-b border-slate-200 bg-slate-50 px-3 py-3 [scrollbar-width:none]" aria-label="Task-Gruppen">
                <div class="flex min-w-max items-center gap-1.5" role="group" aria-label="Bibliothek filtern">
                    @if($searchActive)
                        <span class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 text-[11px] font-bold text-blue-800">
                            Alle Treffer
                            <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-[10px]">{{ $visibleTaskDefinitions->count() }}</span>
                        </span>
                    @endif
                    @foreach($taskGroups as $taskGroup)
                        <button
                            type="button"
                            wire:click="$set('activeTaskGroup', @js($taskGroup))"
                            x-on:click="$el.scrollIntoView({ block: 'nearest', inline: 'center', behavior: scrollBehavior() })"
                            aria-pressed="{{ ! $searchActive && $activeTaskGroup === $taskGroup ? 'true' : 'false' }}"
                            class="inline-flex min-h-10 items-center gap-2 whitespace-nowrap rounded-xl border px-3 text-[11px] font-bold transition {{ ! $searchActive && $activeTaskGroup === $taskGroup ? 'border-blue-200 bg-blue-50 text-blue-800 shadow-sm' : 'border-transparent bg-white text-slate-600 hover:border-slate-200 hover:text-slate-950' }}"
                        >
                            {{ data_get($taskGroupMeta, $taskGroup.'.short_label', $taskGroupLabels[$taskGroup] ?? $taskGroup) }}
                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500">{{ $taskGroupCounts->get($taskGroup, 0) }}</span>
                        </button>
                    @endforeach
                </div>
            </nav>

            <div class="border-b border-slate-200 bg-white px-4 py-3" role="status" aria-live="polite">
                @if($searchActive)
                    <p class="text-xs font-bold text-slate-900">{{ $visibleTaskDefinitions->count() }} Treffer in allen Gruppen</p>
                    <p class="mt-0.5 text-[11px] leading-4 text-slate-500">Die Kategorie steht direkt am jeweiligen Task.</p>
                @else
                    <p class="text-xs font-bold text-slate-900">{{ $taskGroupLabels[$activeTaskGroup] ?? $activeTaskGroup }}</p>
                    <p class="mt-0.5 text-[11px] leading-4 text-slate-500">{{ data_get($taskGroupMeta, $activeTaskGroup.'.description', '') }}</p>
                @endif
            </div>

            <div class="ff-task-library-list min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50/70 p-3">
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
                        class="ff-catalog-card group block min-h-[76px] w-full border bg-white p-3 text-left disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <span class="flex items-center gap-3">
                            <span class="ff-catalog-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition group-hover:border-blue-200 group-hover:bg-blue-100 group-hover:text-blue-700">
                                @switch($taskDefinition['kind'])
                                    @case('browser')
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path></svg>
                                        @break
                                    @case('input')
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"></rect><path d="M7 10h.01M11 10h.01M15 10h.01M7 14h7"></path></svg>
                                        @break
                                    @case('wait')
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                                        @break
                                    @case('workflow')
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="6" height="5" rx="1"></rect><rect x="15" y="15" width="6" height="5" rx="1"></rect><path d="M9 6.5h3a5 5 0 0 1 5 5V15"></path></svg>
                                        @break
                                    @default
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"></path><circle cx="18" cy="17" r="2"></circle></svg>
                                @endswitch
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate text-[13px] font-bold text-slate-950">{{ $taskDefinition['label'] }}</span>
                                    @if($searchActive)
                                        <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-[9px] font-bold text-slate-500">{{ data_get($taskGroupMeta, $taskDefinition['library_group'].'.short_label', $taskDefinition['library_group']) }}</span>
                                    @endif
                                </span>
                                <span class="mt-1 block line-clamp-2 text-xs leading-4 text-slate-500">{{ $taskDefinition['description'] }}</span>
                            </span>
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border border-blue-200 bg-blue-50 text-lg font-medium leading-none text-blue-700 transition group-hover:border-blue-600 group-hover:bg-blue-600 group-hover:text-white" aria-hidden="true">+</span>
                        </span>
                    </button>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
                        <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                        </span>
                        <p class="mt-3 text-xs font-bold text-slate-700">Keine passenden Tasks</p>
                        <p class="mt-1 text-[11px] leading-4 text-slate-500">Suchbegriff anpassen oder eine andere Gruppe wählen.</p>
                    </div>
                @endforelse
            </div>
        </aside>

        <section
            id="studio-task-canvas-panel"
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
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <div class="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-600 sm:flex" aria-label="Legende der Verbindungslinien">
                        <span class="inline-flex items-center gap-1"><i class="h-0.5 w-3 rounded bg-emerald-500"></i> Erfolg</span>
                        <span class="inline-flex items-center gap-1"><i class="h-0.5 w-3 rounded bg-rose-400"></i> Fehler</span>
                        <span class="inline-flex items-center gap-1"><i class="h-0.5 w-3 rounded bg-amber-500"></i> Partial</span>
                        <span class="inline-flex items-center gap-1"><i class="h-0.5 w-3 rounded bg-violet-500"></i> Timeout</span>
                        @if($activeRun)
                            <span class="inline-flex items-center gap-1"><i class="h-0.5 w-3 rounded bg-sky-500"></i> Laufweg</span>
                        @endif
                    </div>
                    <button
                        type="button"
                        x-show="compactRouteMode"
                        x-on:click="toggleAllRoutes()"
                        x-bind:aria-pressed="showAllRoutes"
                        class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-blue-300 hover:text-blue-700"
                        x-text="showAllRoutes ? 'Nur Auswahl' : 'Alle Verbindungen'"
                    ></button>
                    <button
                        type="button"
                        x-on:click="showRoutes = !showRoutes; $nextTick(() => queueRouteRefresh())"
                        x-bind:aria-pressed="showRoutes"
                        class="inline-flex min-h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-blue-300 hover:text-blue-700"
                        x-text="showRoutes ? 'Linien ausblenden' : 'Linien einblenden'"
                    ></button>
                    <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="ff-action-trigger ff-action-trigger--primary inline-flex h-10 items-center gap-2 px-3 text-xs font-bold disabled:cursor-not-allowed disabled:opacity-40">
                        <span class="text-base leading-none">+</span> Neue Liste
                    </button>
                </div>
            </div>

            <section
                x-data="{ overviewOpen: true }"
                data-studio-editor-overview
                class="shrink-0 border-b border-slate-200 bg-white/90"
                aria-label="Workflow-Übersicht"
            >
                <button
                    type="button"
                    x-on:click="overviewOpen = ! overviewOpen; $nextTick(() => queueRouteRefresh())"
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
                        :route-map="$routeMap"
                        :active-step-id="$activeRun?->current_workflow_step_id"
                        :active-task-key="data_get($activeRun?->context_json, 'next_task_key')"
                        :selected-step-id="$overviewSelectedStepId"
                        :selected-task-key="$overviewSelectedTaskKey"
                        :show-header="false"
                        :selectable-tasks="true"
                        :zoomable="true"
                        initial-zoom="overview"
                        :instance="$editorInstance"
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

            <div
                x-ref="routeSurface"
                data-studio-workflow-canvas
                data-workflow-route-surface
                class="ff-canvas-grid relative min-h-0 flex-1 overflow-auto overscroll-contain"
                x-on:scroll.passive.debounce.80ms="queueRouteRefresh()"
            >
                <script type="application/json" x-ref="routeMap">@json($routeMap ?? ['nodes' => [], 'edges' => []])</script>
                <svg
                    x-cloak
                    x-show="showRoutes"
                    class="pointer-events-none absolute left-0 top-0 z-10 overflow-visible"
                    x-bind:width="routeOverlay.width"
                    x-bind:height="routeOverlay.height"
                    x-bind:viewBox="`0 0 ${routeOverlay.width} ${routeOverlay.height}`"
                    aria-hidden="true"
                >
                    <defs>
                        @foreach([
                            'success' => '#10b981',
                            'failed' => '#fb7185',
                            'partial' => '#f59e0b',
                            'timeout' => '#8b5cf6',
                            'runtime' => '#0ea5e9',
                            'default' => '#94a3b8',
                        ] as $markerName => $markerColor)
                            <marker id="{{ $routeMarkerId }}-arrow-{{ $markerName }}" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                <path d="M 0 0 L 10 5 L 0 10 z" fill="{{ $markerColor }}"></path>
                            </marker>
                        @endforeach
                    </defs>
                    <g x-html="routeSvgMarkup"></g>
                </svg>
                <div
                    x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })"
                    class="relative z-20 flex min-h-full min-w-max items-start gap-8 px-4 pb-10 pt-6 sm:px-6 sm:pt-8"
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

                    <div class="flex w-44 shrink-0 flex-col gap-3 pt-2" aria-label="Workflow-Terminalziele">
                        <div
                            data-workflow-route-node="terminal::end"
                            class="rounded-xl border border-emerald-200 bg-emerald-50/95 px-4 py-3 text-emerald-900 shadow-sm"
                        >
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-emerald-700">Terminal</p>
                            <p class="mt-1 text-sm font-bold">Workflow-Ende</p>
                        </div>
                        <div
                            data-workflow-route-node="terminal::fail"
                            class="rounded-xl border border-rose-200 bg-rose-50/95 px-4 py-3 text-rose-900 shadow-sm"
                        >
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-rose-700">Terminal</p>
                            <p class="mt-1 text-sm font-bold">Workflow-Abbruch</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    @endunless

    @unless($modalOnly)
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
            <button type="button" wire:click="saveEditStep" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">{{ $revisionMode ? 'Speichern & Revision erstellen' : 'Speichern' }}</button>
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
    @endunless

    <x-ui.dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task bearbeiten</span><p class="mt-1 text-xs font-normal text-slate-500">{{ $revisionMode ? 'Im pausierten Testlauf werden Änderungen als neue Workflow-Revision gespeichert.' : 'Task-Einstellungen, Datenquellen und Routen an einer Stelle bearbeiten.' }}</p></div>
        </x-slot>
        <x-slot name="content">
            @if($taskEditReadOnlyState)
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-bold">Task ist während des aktiven Testlaufs schreibgeschützt</p>
                            <p class="mt-1 text-xs leading-5 text-amber-800">{{ $taskEditLockMessageState ?: 'Pausiere den Lauf am sicheren Haltepunkt, bevor du Änderungen speicherst.' }}</p>
                        </div>
                        <span class="rounded-full border border-amber-200 bg-white px-2.5 py-1 font-mono text-[10px] font-bold uppercase tracking-wide text-amber-800">{{ $taskEditRunStatusState }}</span>
                    </div>
                </div>
            @endif
            <div
                @if($taskEditReadOnlyState)
                    x-data
                    x-init="$nextTick(() => $el.querySelectorAll('input, textarea, select').forEach((control) => control.disabled = true))"
                    class="opacity-65"
                    data-workflow-task-form-readonly
                @endif
            >
                @include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'edit', 'steps' => $steps, 'taskDefinitions' => $taskDefinitions])
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeEditTaskModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            @if($taskEditReadOnlyState)
                @if($taskEditCanRequestPauseState)
                    <button
                        type="button"
                        wire:click="requestPauseForTaskEdit"
                        wire:loading.attr="disabled"
                        wire:target="requestPauseForTaskEdit"
                        @disabled($taskEditPauseRequestedState)
                        class="ml-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 disabled:cursor-wait disabled:opacity-60"
                    >
                        {{ $taskEditPauseRequestedState ? 'Pause angefordert …' : 'Pausieren & bearbeiten' }}
                    </button>
                @endif
            @else
                <button type="button" x-on:click.prevent="const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;editingTask&quot;]')?.value || 'person'; $wire.saveEditTaskCard(source);" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">{{ $revisionMode ? 'Speichern & Revision erstellen' : 'Speichern' }}</button>
            @endif
        </x-slot>
    </x-ui.dialog-modal>

    @unless($modalOnly)
    <x-ui.dialog-modal wire:model="showMoveTaskModal" maxWidth="lg">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task in andere Liste verschieben</span><p class="mt-1 text-xs font-normal text-slate-500">Die Task wird ans Ende der gewählten Liste verschoben. Gekoppelte Loop-Blöcke bleiben zusammen.</p></div>
        </x-slot>
        <x-slot name="content">
            <label for="workflow-task-move-target-{{ $routeMarkerId }}" class="block text-sm font-semibold text-slate-700">Zielliste</label>
            <select id="workflow-task-move-target-{{ $routeMarkerId }}" wire:model="movingTaskTargetStepId" class="mt-2 block min-h-11 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                @foreach($steps as $targetStep)
                    @if((int) $targetStep->id !== (int) $movingTaskStepId)
                        <option value="{{ $targetStep->id }}">{{ $targetStep->name }}</option>
                    @endif
                @endforeach
            </select>
            @error('movingTaskTargetStepId') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
        </x-slot>
        <x-slot name="footer">
            <button type="button" wire:click="closeMoveTaskModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" wire:click="confirmTaskMove" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Task verschieben</button>
        </x-slot>
    </x-ui.dialog-modal>
    @endunless
</div>
