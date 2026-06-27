@php($workflowLocked = (bool) ($selectedWorkflow?->is_edit_locked ?? false))
<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('network.workflows') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">Workflows</a>
                    <span class="text-sm text-slate-400">/</span>
                    <span class="text-sm text-slate-500">Management</span>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <h1 class="text-2xl font-semibold text-gray-900">{{ $selectedWorkflow?->name ?? 'Workflow Management' }}</h1>
                    @if($workflowLocked)
                        <span title="{{ $selectedWorkflow->lock_reason }}" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-amber-100 text-amber-700" aria-label="Workflow gesperrt">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 0h10.5A2.25 2.25 0 0 1 19.5 12.75v6A2.25 2.25 0 0 1 17.25 21H6.75a2.25 2.25 0 0 1-2.25-2.25v-6A2.25 2.25 0 0 1 6.75 10.5Z" />
                            </svg>
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    Workflow als Prozessablauf: Aufgaben als Listen, Tasks als Karten, Verzweigungen nach Ergebnisstatus.
                </p>
            </div>

            @if($selectedWorkflow)
                <div class="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        wire:click="openLatestRunPreview"
                        @disabled(! $quickPreviewRun)
                        class="rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ $quickPreviewRun && in_array($quickPreviewRun->status, ['queued', 'running', 'waiting'], true) ? 'Laufenden Test öffnen' : 'Letzten Test öffnen' }}
                    </button>
                    <button type="button" wire:click="$set('showRunModal', true)" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Testen
                    </button>
                    @if(! $workflowLocked)
                        <button type="button" wire:click="$set('showWorkflowModal', true)" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Workflow</button>
                        <button type="button" wire:click="$set('showAddStepModal', true)" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 shadow-sm hover:bg-blue-50">Liste</button>
                        <button type="button" wire:click="$set('showTaskPanel', true)" class="rounded-md border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50">Tasks</button>
                        <button type="button" wire:click="$set('showActionLibraryModal', true)" class="rounded-md border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-700 shadow-sm hover:bg-amber-50">Aktionen</button>
                        <a href="{{ route('processes.index') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Prozesse</a>
                        <button type="button" wire:click="deleteWorkflow" wire:confirm="Workflow samt Aufgaben, Tasks und Ausfuehrungen wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">Loeschen</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ session('error') }}</div>
    @endif

    @if(! $selectedWorkflow)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Dieser Workflow wurde nicht gefunden.</div>
        </x-admin.panel>
    @else
        @if($workflowLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <span class="font-semibold">Nur-Lese-Modus.</span> {{ $selectedWorkflow->lock_reason }} Im Manager koennen fuer diesen Workflow nur Tests gestartet und geoeffnet werden.
            </div>
        @endif
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-admin.stat label="Aufgaben" :value="$summary['actions']" tone="slate" />
            <x-admin.stat label="Listen" :value="$summary['lists']" tone="blue" />
            <x-admin.stat label="Tasks" :value="$summary['task_cards']" tone="amber" />
            <x-admin.stat label="Laeufe" :value="$summary['runs']" tone="emerald" />
        </div>

        <x-admin.panel title="Board">
            <div
                x-data="{
                    focusedTask: '',
                    routeLines: [],
                    routeOverlay: { width: 0, height: 0 },
                    routeSvgMarkup: '',
                    init() {
                        this.$nextTick(() => this.refreshRouteLines());
                        setTimeout(() => this.refreshRouteLines(), 150);
                        setTimeout(() => this.refreshRouteLines(), 600);
                        this._refreshWorkflowRoutes = () => this.$nextTick(() => this.refreshRouteLines());
                        window.addEventListener('resize', this._refreshWorkflowRoutes);
                        document.addEventListener('livewire:updated', this._refreshWorkflowRoutes);
                        document.addEventListener('livewire:navigated', this._refreshWorkflowRoutes);
                    },
                    refreshRouteLines() {
                        const surface = this.$refs.routeSurface;

                        if (!surface) {
                            this.routeLines = [];
                            return;
                        }

                        const nodes = Array.from(surface.querySelectorAll('[data-workflow-task-node]'));
                        const surfaceRect = surface.getBoundingClientRect();
                        const byKey = new Map();
                        const firstByStep = new Map();

                        nodes.forEach((node) => {
                            const key = node.dataset.workflowTaskNode || '';
                            const step = node.dataset.workflowStepAction || '';

                            if (key) {
                                byKey.set(key, node);
                            }

                            if (step && !firstByStep.has(step)) {
                                firstByStep.set(step, node);
                            }
                        });

                        const targetNode = (target) => {
                            const normalized = String(target || '').trim();

                            if (!normalized) {
                                return null;
                            }

                            if (normalized.endsWith('::*')) {
                                return firstByStep.get(normalized.slice(0, -3)) || null;
                            }

                            return byKey.get(normalized) || null;
                        };
                        const makeLine = (source, target, type) => {
                            const targetElement = targetNode(target);

                            if (!source || !targetElement) {
                                return null;
                            }

                            const sourceRect = source.getBoundingClientRect();
                            const targetRect = targetElement.getBoundingClientRect();
                            const sourceStep = source.dataset.workflowStepAction || '';
                            const targetStep = targetElement.dataset.workflowStepAction || '';
                            const sourceCenterX = sourceRect.left + (sourceRect.width / 2) - surfaceRect.left + surface.scrollLeft;
                            const sourceCenterY = sourceRect.top + (sourceRect.height / 2) - surfaceRect.top + surface.scrollTop;
                            const targetCenterX = targetRect.left + (targetRect.width / 2) - surfaceRect.left + surface.scrollLeft;
                            const targetCenterY = targetRect.top + (targetRect.height / 2) - surfaceRect.top + surface.scrollTop;
                            const sourceRight = sourceRect.right - surfaceRect.left + surface.scrollLeft;
                            const sourceLeft = sourceRect.left - surfaceRect.left + surface.scrollLeft;
                            const sourceTop = sourceRect.top - surfaceRect.top + surface.scrollTop;
                            const sourceBottom = sourceRect.bottom - surfaceRect.top + surface.scrollTop;
                            const targetLeft = targetRect.left - surfaceRect.left + surface.scrollLeft;
                            const targetRight = targetRect.right - surfaceRect.left + surface.scrollLeft;
                            const targetTop = targetRect.top - surfaceRect.top + surface.scrollTop;
                            const targetBottom = targetRect.bottom - surfaceRect.top + surface.scrollTop;

                            if (source === targetElement) {
                                const loopX = sourceRight + 34;
                                const loopEndY = sourceTop + (sourceRect.height * 0.78);
                                const loopPath = `M ${sourceRight} ${sourceCenterY} L ${loopX} ${sourceCenterY} L ${loopX} ${loopEndY} L ${sourceRight} ${loopEndY}`;

                                return { path: loopPath, type };
                            }

                            if (sourceStep === targetStep) {
                                if (targetTop >= sourceBottom) {
                                    const gapY = sourceBottom + Math.max(8, (targetTop - sourceBottom) / 2);
                                    const path = `M ${sourceCenterX} ${sourceBottom} L ${sourceCenterX} ${gapY} L ${targetCenterX} ${gapY} L ${targetCenterX} ${targetTop}`;

                                    return { path, type };
                                }

                                const sideX = Math.max(sourceRight, targetRight) + 28;
                                const path = `M ${sourceRight} ${sourceCenterY} L ${sideX} ${sourceCenterY} L ${sideX} ${targetCenterY} L ${targetRight} ${targetCenterY}`;

                                return { path, type };
                            }

                            if (targetLeft >= sourceRight) {
                                const gapX = sourceRight + Math.max(16, (targetLeft - sourceRight) / 2);
                                const path = `M ${sourceRight} ${sourceCenterY} L ${gapX} ${sourceCenterY} L ${gapX} ${targetCenterY} L ${targetLeft} ${targetCenterY}`;

                                return { path, type };
                            }

                            const sideX = Math.max(sourceRight, targetRight) + 42;
                            const entryX = targetLeft < sourceLeft ? targetRight : targetLeft;
                            const path = `M ${sourceRight} ${sourceCenterY} L ${sideX} ${sourceCenterY} L ${sideX} ${targetCenterY} L ${entryX} ${targetCenterY}`;

                            return { path, type };
                        };
                        const lines = [];

                        nodes.forEach((node, index) => {
                            const stepElement = node.closest('[data-step-route-success]');
                            const nextNode = nodes[index + 1] || null;
                            const nextNodeSameStep = nextNode && nextNode.dataset.workflowStepAction === node.dataset.workflowStepAction;
                            const lastInStep = !nextNodeSameStep;
                            let successTarget = String(node.dataset.routeSuccess || '').trim();

                            if (!successTarget && nextNodeSameStep) {
                                successTarget = nextNode.dataset.workflowTaskNode || '';
                            }

                            if (!successTarget && lastInStep && stepElement) {
                                successTarget = String(stepElement.dataset.stepRouteSuccess || '').trim();
                            }

                            if (!successTarget && nextNode) {
                                successTarget = nextNode.dataset.workflowTaskNode || '';
                            }

                            const successLine = makeLine(node, successTarget, 'success');

                            if (successLine) {
                                lines.push(successLine);
                            }

                            let failedTarget = String(node.dataset.routeFailed || '').trim();

                            if (!failedTarget && stepElement && lastInStep) {
                                failedTarget = String(stepElement.dataset.stepRouteFailed || '').trim();
                            }

                            const failedLine = makeLine(node, failedTarget, 'failed');

                            if (failedLine) {
                                lines.push(failedLine);
                            }
                        });

                        this.routeOverlay = {
                            width: surface.scrollWidth,
                            height: surface.scrollHeight,
                        };
                        this.routeLines = lines;
                        this.routeSvgMarkup = lines.map((line, index) => {
                            const color = line.type === 'failed' ? '#fca5a5' : '#bbf7d0';
                            const marker = line.type === 'failed' ? 'url(#workflow-arrow-red)' : 'url(#workflow-arrow-green)';
                            const dash = line.type === 'failed' ? ' stroke-dasharray=&quot;6 5&quot;' : '';
                            const path = String(line.path || '').replace(/&/g, '&amp;').replace(/&quot;/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                            return `<path d=&quot;${path}&quot; fill=&quot;none&quot; stroke-width=&quot;3&quot; stroke-linecap=&quot;round&quot; stroke=&quot;${color}&quot;${dash} marker-end=&quot;${marker}&quot;></path>`;
                        }).join('');
                    },
                }"
                x-init="refreshRouteLines()"
                x-on:scroll.debounce.100ms="refreshRouteLines()"
                x-ref="routeSurface"
                class="relative max-h-[calc(100vh-220px)] overflow-auto rounded-md border border-blue-600 bg-blue-400 p-4 shadow-sm"
            >
                <svg
                    class="pointer-events-none absolute left-0 top-0 z-30"
                    x-bind:width="routeOverlay.width"
                    x-bind:height="routeOverlay.height"
                    x-bind:viewBox="`0 0 ${routeOverlay.width} ${routeOverlay.height}`"
                    aria-hidden="true"
                >
                    <defs>
                        <marker id="workflow-arrow-green" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth">
                            <path d="M0,0 L0,6 L8,3 z" fill="#bbf7d0"></path>
                        </marker>
                        <marker id="workflow-arrow-red" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth">
                            <path d="M0,0 L0,6 L8,3 z" fill="#fca5a5"></path>
                        </marker>
                    </defs>
                    <g x-html="routeSvgMarkup"></g>
                </svg>

                <div class="relative z-10 mb-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-white">{{ $selectedWorkflow->name }}</p>
                        <p class="text-xs text-blue-100">Tasks aus dem rechten Panel auf eine Liste ziehen.</p>
                    </div>
                    @if(! $workflowLocked)
                        <button type="button" wire:click="$set('showTaskPanel', true)" class="rounded-md bg-white/95 px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-white">Task-Bibliothek</button>
                    @endif
                </div>

                <div @if(! $workflowLocked) x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })" @endif class="relative z-10 flex min-h-[560px] items-start gap-0 pb-2">
                    @forelse($steps as $step)
                        <div class="flex items-start" @if(! $workflowLocked) x-sort:item="{{ $step->id }}" @endif wire:key="workflow-step-wrap-{{ $step->id }}">
                            <x-workflows.step-card :step="$step" :locked="$workflowLocked" wire:key="workflow-step-{{ $step->id }}">
                                @if(! $workflowLocked)
                                    <x-slot name="actions">
                                        <button type="button" wire:click="openEditStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Bearbeiten</button>
                                        <button type="button" wire:click="toggleStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}</button>
                                        <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste samt Tasks wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">Entfernen</button>
                                    </x-slot>
                                @endif
                            </x-workflows.step-card>
                            @if(! $loop->last)
                                <div class="w-4 shrink-0"></div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-md border border-dashed border-white/40 bg-white/90 p-6 text-center text-sm text-slate-600">
                            Keine Listen. Nutze oben den Button "Liste".
                        </div>
                    @endforelse

                    @if(! $workflowLocked)
                        <button type="button" wire:click="$set('showAddStepModal', true)" class="flex min-h-[220px] w-[260px] shrink-0 items-start rounded-md border border-dashed border-white/45 bg-transparent p-3 text-left text-sm font-semibold text-blue-50 transition hover:border-white hover:bg-white/10 hover:text-white">+ Neue Liste rechts anlegen</button>
                    @endif
                </div>

            </div>
        </x-admin.panel>

        @if($showTaskPanel && ! $workflowLocked)
            <div x-data="{}" class="fixed inset-y-0 right-0 z-40 flex w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Task-Bibliothek</h2>
                        <p class="mt-1 text-xs text-slate-500">Task auf eine Liste ziehen, danach oeffnet sich das Formular.</p>
                    </div>
                    <button type="button" wire:click="$set('showTaskPanel', false)" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                        x
                    </button>
                </div>
                <div class="border-b border-slate-200 px-4">
                    <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Task Gruppen">
                        @foreach($taskGroups as $taskGroup)
                            <button type="button" wire:click="$set('activeTaskGroup', @js($taskGroup))" class="whitespace-nowrap border-b-2 py-3 text-sm font-semibold {{ $activeTaskGroup === $taskGroup ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                                {{ $taskGroupLabels[$taskGroup] ?? $taskGroup }}
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ collect($taskDefinitions)->where('kind', $taskGroup)->count() }}</span>
                            </button>
                        @endforeach
                    </nav>
                </div>
                <div class="flex-1 space-y-3 overflow-y-auto p-4">
                    @foreach($visibleTaskDefinitions as $taskDefinition)
                        <div
                            draggable="true"
                            x-on:dragstart.stop="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                            class="cursor-grab rounded-md border border-slate-200 bg-white p-3 shadow-sm transition hover:border-blue-300 hover:shadow-md active:cursor-grabbing"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-slate-900">{{ $taskDefinition['label'] }}</p>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{{ $taskDefinition['kind'] }}</span>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ $taskDefinition['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <x-dialog-modal wire:model="showWorkflowModal" maxWidth="2xl">
            <x-slot name="title">Workflow bearbeiten</x-slot>
            <x-slot name="content">
                <x-workflows.workflow-form
                    name-model="workflowName"
                    group-model="workflowGroup"
                    description-model="workflowDescription"
                    active-model="workflowActive"
                    lock-model="workflowLocked"
                    lock-help="Nach dem Speichern ist der Workflow im Manager nur noch testbar. Entsperren ist in der Workflow-Liste moeglich."
                />
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunModal" maxWidth="xl">
            <x-slot name="title">Workflow testen</x-slot>
            <x-slot name="content">
                <div>
                    <label for="workflow-run-person" class="block text-sm font-medium text-gray-700">Person</label>
                    <select id="workflow-run-person" wire:model.defer="runPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">Keine Person binden</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                        @endforeach
                    </select>
                    @error('runPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">Testen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunPreviewModal" maxWidth="6xl">
            <x-slot name="title">Workflow-Vorschau</x-slot>
            <x-slot name="content">
                <div @if($showRunPreviewModal) wire:poll.3s="refreshRunPreview" @endif>
                    @if($previewWorkflowRun)
                        <x-workflows.run-preview :workflow-run="$previewWorkflowRun" />
                    @else
                        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            Dieser Workflow-Lauf wurde noch nicht geladen.
                        </div>
                    @endif
                </div>
            </x-slot>
            <x-slot name="footer">
                @if($previewWorkflowRun && $previewWorkflowRun->status === 'queued')
                    <button type="button" wire:click="deleteQueuedPreviewWorkflowRun" wire:confirm="Eingeplanten Workflow-Test wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Loeschen
                    </button>
                @elseif($previewWorkflowRun && in_array($previewWorkflowRun->status, ['running', 'waiting'], true))
                    <button type="button" wire:click="cancelPreviewWorkflowRun" wire:confirm="Workflow-Test wirklich stoppen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Stoppen
                    </button>
                @endif
                <button type="button" wire:click="closeRunPreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Schliessen
                </button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showAddStepModal" maxWidth="2xl">
            <x-slot name="title">Liste / Aufgabe hinzufuegen</x-slot>
            <x-slot name="content">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="workflow-new-step-type" class="block text-sm font-medium text-gray-700">Aufgabentyp</label>
                        <select id="workflow-new-step-type" wire:model.live="newStepType" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="preparation">Vorbereitung</option>
                            <option value="data_processing">Daten verarbeiten</option>
                            <option value="browser_control">Browsersteuerung</option>
                            <option value="interaction">Interaktion</option>
                            <option value="decision">Status pruefen</option>
                            <option value="cleanup">Abschluss</option>
                        </select>
                    </div>
                    <div>
                        <label for="workflow-new-step-name" class="block text-sm font-medium text-gray-700">Listenname</label>
                        <input id="workflow-new-step-name" type="text" wire:model.defer="newStepName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('newStepName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="addStep" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Hinzufuegen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showEditStepModal" maxWidth="2xl">
            <x-slot name="title">Liste / Aufgabe bearbeiten</x-slot>
            <x-slot name="content">
                <div class="space-y-4">
                    <div>
                        <label for="workflow-edit-step-name" class="block text-sm font-medium text-gray-700">Name</label>
                        <input id="workflow-edit-step-name" type="text" wire:model.defer="editingStepName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('editingStepName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="workflow-edit-step-description" class="block text-sm font-medium text-gray-700">Beschreibung</label>
                        <textarea id="workflow-edit-step-description" rows="3" wire:model.defer="editingStepDescription" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                            <input type="checkbox" wire:model.defer="editingStepEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                            Aktiv
                        </label>
                        <div>
                            <label for="workflow-edit-step-wait" class="block text-sm font-medium text-gray-700">Pause danach</label>
                            <input id="workflow-edit-step-wait" type="number" min="0" max="3600" wire:model.defer="editingStepWaitAfterSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach([
                            'editingStepSuccessTarget' => 'Bei Erfolg',
                            'editingStepFailedTarget' => 'Bei Fehler',
                        ] as $model => $label)
                            <div>
                                <label class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                                <select wire:model.defer="{{ $model }}" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">Keine Route</option>
                                    <option value="end">Workflow beenden</option>
                                    <option value="fail">Fehlerroute</option>
                                    @foreach($steps as $targetStep)
                                        <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                                        @foreach($targetStep->task_cards as $targetTask)
                                            <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Karte: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="workflow-edit-step-success-reason" class="block text-sm font-medium text-gray-700">Grund bei Erfolg</label>
                            <input id="workflow-edit-step-success-reason" type="text" wire:model.defer="editingStepSuccessReason" placeholder="z.B. Element gefunden / Login erfolgreich" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('editingStepSuccessReason') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-edit-step-failed-reason" class="block text-sm font-medium text-gray-700">Grund bei Fehler</label>
                            <input id="workflow-edit-step-failed-reason" type="text" wire:model.defer="editingStepFailedReason" placeholder="z.B. Selector nicht gefunden / Timeout" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('editingStepFailedReason') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="max-w-xs">
                        <label for="workflow-edit-step-failed-retry-limit" class="block text-sm font-medium text-gray-700">Fehler-Rueckleitung wiederholen bis Abbruch</label>
                        <input id="workflow-edit-step-failed-retry-limit" type="number" min="0" max="20" wire:model.defer="editingStepFailedRetryLimit" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">0 bedeutet kein Limit. Das Limit gilt nur fuer Fehler-Routen zur selben oder einer frueheren Liste.</p>
                        @error('editingStepFailedRetryLimit') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveEditStep" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showAddTaskModal" maxWidth="3xl">
            <x-slot name="title">Step-Karte hinzufuegen</x-slot>
            <x-slot name="content">
                @include('livewire.admin.network.partials.workflow-task-form', [
                    'mode' => 'create',
                    'steps' => $steps,
                    'taskDefinitions' => $taskDefinitions,
                ])
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="addTaskCard" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Hinzufuegen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showEditTaskModal" maxWidth="3xl">
            <x-slot name="title">Step-Karte bearbeiten</x-slot>
            <x-slot name="content">
                @include('livewire.admin.network.partials.workflow-task-form', [
                    'mode' => 'edit',
                    'steps' => $steps,
                    'taskDefinitions' => $taskDefinitions,
                ])
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveEditTaskCard" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showActionLibraryModal" maxWidth="5xl">
            <x-slot name="title">Aktionsbibliothek</x-slot>
            <x-slot name="content">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="workflow-action-person" class="block text-sm font-medium text-gray-700">Person</label>
                        <select id="workflow-action-person" wire:model.live="actionPersonFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Alle Personen</option>
                            @foreach($personOptions as $person)
                                <option value="{{ $person['id'] }}">{{ $person['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="workflow-action-type" class="block text-sm font-medium text-gray-700">Typ</label>
                        <select id="workflow-action-type" wire:model.live="actionTypeFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="all">Alle Aktionen</option>
                            <option value="step">Session-Schritte</option>
                            <option value="content">Content</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    @forelse($actions as $action)
                        <x-workflows.action-template-card :action="$action" wire:key="workflow-action-template-{{ $action['id'] }}">
                            <x-slot name="actions">
                                <button type="button" wire:click="addActionStep(@js($action['id']))" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                                    Hinzufuegen
                                </button>
                            </x-slot>
                        </x-workflows.action-template-card>
                    @empty
                        <div class="md:col-span-2 rounded-md border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            Keine geplanten Aktionen gefunden.
                        </div>
                    @endforelse
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Schliessen</button>
            </x-slot>
        </x-dialog-modal>
    @endif
</div>
