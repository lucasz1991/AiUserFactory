@php
    $workflowLocked = (bool) ($selectedWorkflow?->is_edit_locked ?? false);
    $formatRunDuration = static function ($run): string {
        if (! $run) {
            return '-';
        }

        $stored = $run->duration_ms
            ?? data_get($run->result_json, 'durationMs')
            ?? data_get($run->result_json, 'duration_ms');

        if (is_numeric($stored) && (int) $stored >= 0) {
            $milliseconds = (int) $stored;
        } else {
            $startedAt = $run->started_at ?? $run->queued_at;

            if (! $startedAt) {
                return '-';
            }

            $milliseconds = max(0, $startedAt->diffInMilliseconds($run->finished_at ?? now()));
        }

        if ($milliseconds > 0 && $milliseconds < 1000) {
            return '< 1s';
        }

        $seconds = intdiv($milliseconds, 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return collect([
            $hours > 0 ? $hours.'h' : null,
            $minutes > 0 ? $minutes.'m' : null,
            ($hours === 0 && $remainingSeconds > 0) || ($hours === 0 && $minutes === 0) ? $remainingSeconds.'s' : null,
        ])->filter()->implode(' ');
    };
    $formatWorkflowValue = static function ($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value) || is_object($value)) {
            return \Illuminate\Support\Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', 120);
        }

        return \Illuminate\Support\Str::limit((string) $value, 120);
    };
    $workflowReturnLabel = static function ($run) use ($formatWorkflowValue): ?string {
        if (! $run) {
            return null;
        }

        foreach ([is_array($run->result_json) ? $run->result_json : [], is_array($run->context_json) ? $run->context_json : []] as $source) {
            if (\Illuminate\Support\Arr::has($source, 'workflow_return')) {
                $value = data_get($source, 'workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowReturn')) {
                $value = data_get($source, 'workflowReturn');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflow_variables.workflow_return')) {
                $value = data_get($source, 'workflow_variables.workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowVariables.workflow_return')) {
                $value = data_get($source, 'workflowVariables.workflow_return');
            } else {
                continue;
            }

            return 'Rueckgabe: '.$formatWorkflowValue($value);
        }

        return null;
    };
    $quickPreviewDurationLabel = $quickPreviewRun ? $formatRunDuration($quickPreviewRun) : null;
    $quickPreviewReturnLabel = $quickPreviewRun ? $workflowReturnLabel($quickPreviewRun) : null;
@endphp
<div
    class="space-y-5"
    wire:loading.class="opacity-60 pointer-events-none"
    x-data="{
        taskInsertTarget: null,
        armTaskInsert(stepId, stepName) {
            this.taskInsertTarget = { stepId: Number(stepId), stepName: String(stepName || '') };
            if (! $wire.showTaskPanel) {
                $wire.set('showTaskPanel', true);
            }
        },
        clearTaskInsert() {
            this.taskInsertTarget = null;
        },
        insertCatalogTask(taskKey) {
            if (! this.taskInsertTarget) {
                return;
            }
            const target = this.taskInsertTarget;
            this.taskInsertTarget = null;
            $wire.prepareTaskFromCatalog(target.stepId, taskKey, null);
        },
    }"
    x-init="$wire.$watch('showTaskPanel', open => { if (! open) clearTaskInsert() })"
    data-workflow-manager-root
    data-workflow-id="{{ $selectedWorkflow?->id ?? '' }}"
    x-on:assistant-open-workflow-improvement.window="
        const detail = Array.isArray($event.detail) ? ($event.detail[0] || {}) : ($event.detail || {});
        const workflowId = Number(detail.workflow_id || 0);
        const stepId = Number(detail.step_id || 0);
        if (workflowId === {{ (int) ($selectedWorkflow?->id ?? 0) }} && stepId > 0) {
            $wire.openAssistantImprovement(workflowId, stepId, detail.task_card_key || null);
        }
    "
    x-on:assistant-open-workflow-run-preview.window="
        const detail = Array.isArray($event.detail) ? ($event.detail[0] || {}) : ($event.detail || {});
        const workflowId = Number(detail.workflow_id || 0);
        if (!workflowId || workflowId === {{ (int) ($selectedWorkflow?->id ?? 0) }}) {
            $wire.openRunPreviewFromAssistant(Number(detail.run_id || 0), Number(detail.session_id || 0));
        }
    "
>
    <div class="p-box shadow-box bg-box border border-box rounded-box">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('network.workflows') }}" class="text-sm font-semibold text-slate-700 hover:text-slate-950">Workflows</a>
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
                <div class="ml-auto flex max-w-full flex-col items-end gap-2">
                    <div class="flex flex-wrap justify-end gap-2">
                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                Testen
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                                <button type="button" wire:click="openTestWorkbench('interactive')" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-100">
                                    Interaktiv testen
                                    <span class="mt-0.5 block text-xs font-medium text-slate-500">Schrittweise testen, pausieren und Tasks bearbeiten</span>
                                </button>
                                <button type="button" wire:click="openTestWorkbench('autonomous')" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                                    Autonom optimieren
                                    <span class="mt-0.5 block text-xs font-medium text-cyan-600">Copilot plant, testet und repariert exklusiv</span>
                                </button>
                                <button type="button" wire:click="$set('showCopilotRunsModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                                    Optimierungslaeufe anzeigen
                                    <span class="mt-0.5 block text-xs font-medium text-cyan-600">Kosten, Tests, Logs und Daten</span>
                                </button>
                                <button type="button" @if($quickPreviewRun) wire:click="openTestWorkbench('{{ $activeCopilotSession ? 'autonomous' : 'interactive' }}', {{ $quickPreviewRun->id }})" @endif x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-indigo-700 hover:bg-indigo-50 {{ $quickPreviewRun ? '' : 'pointer-events-none opacity-40' }}">
                                    {{ $quickPreviewRun && in_array($quickPreviewRun->status, ['queued', 'running', 'waiting'], true) ? 'Laufenden Test öffnen' : 'Letzten Test öffnen' }}
                                    @if($quickPreviewDurationLabel)
                                        <span class="mt-0.5 block text-xs font-medium text-indigo-500">Dauer: {{ $quickPreviewDurationLabel }}</span>
                                    @endif
                                    @if($quickPreviewReturnLabel)
                                        <span class="mt-0.5 block break-words text-xs font-medium text-indigo-500">{{ $quickPreviewReturnLabel }}</span>
                                    @endif
                                </button>
                                <button type="button" wire:click="downloadLatestRunDebugPackage" x-on:click="open = false" @disabled(! $quickPreviewRun) class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-40">
                                    Debug-Paket herunterladen
                                    <span class="mt-0.5 block text-xs font-medium text-emerald-500">CSV, letzter Run, DOM</span>
                                </button>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Bearbeiten
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                                <button type="button" wire:click="$set('showWorkflowModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Workflow-Einstellungen</button>
                                <button type="button" wire:click="$set('showAddStepModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Liste hinzufügen</button>
                                <button type="button" wire:click="$set('showActionLibraryModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-amber-700 hover:bg-amber-50">Aktionsbibliothek</button>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Weitere
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                                <button type="button" wire:click="openRevisionHistory" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-violet-700 hover:bg-violet-50">
                                    Revisionen
                                    <span class="mt-0.5 block text-xs font-medium text-violet-500">Einsehen, vergleichen, zurücksetzen</span>
                                </button>
                                <div class="my-1 border-t border-slate-100"></div>
                                <button type="button" wire:click="exportWorkflow" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50">Als ZIP exportieren</button>
                                <a href="{{ route('processes.index') }}" class="block rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Prozesse öffnen</a>
                                @if(! $workflowLocked)
                                    <div class="my-1 border-t border-slate-100"></div>
                                    <button type="button" wire:click="deleteWorkflow" wire:confirm="Workflow samt Aufgaben, Tasks und Ausfuehrungen wirklich loeschen?" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Workflow löschen</button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-1.5" aria-label="Workflow-Statistik">
                        @foreach([
                            ['Aufgaben', $summary['actions'], 'bg-slate-100 text-slate-700'],
                            ['Listen', $summary['lists'], 'bg-blue-50 text-blue-700'],
                            ['Tasks', $summary['task_cards'], 'bg-amber-50 text-amber-700'],
                            ['Benutzt', $summary['runs'], 'bg-slate-100 text-slate-700'],
                            ['Erfolgreich', $summary['successful_runs'], 'bg-emerald-50 text-emerald-700'],
                            ['Fehlerhaft', $summary['failed_runs'], 'bg-red-50 text-red-700'],
                        ] as [$label, $value, $classes])
                            <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] leading-none {{ $classes }}">
                                <span class="font-medium opacity-75">{{ $label }}</span>
                                <span class="font-bold tabular-nums">{{ $value }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-900">{{ session('error') }}</div>
    @endif

    @if(! $selectedWorkflow)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Dieser Workflow wurde nicht gefunden.</div>
        </x-admin.panel>
    @else
        @if($workflowLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900">
                <span class="font-semibold">Achtung: Dieser Workflow ist gesperrt.</span> {{ $selectedWorkflow->lock_reason }} Als Admin kannst du ihn trotzdem bearbeiten. Aenderungen koennen laufende oder eingebundene Workflows beeinflussen.
            </div>
        @endif
        <div>
            <div
                x-data="{
                    focusedTask: '',
                    hoveredRouteNode: '',
                    activeRouteNode: '',
                    showRoutes: true,
                    isFullscreen: false,
                    bodyOverflowBeforeFullscreen: null,
                    htmlOverflowBeforeFullscreen: null,
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
                    setFullscreen(active) {
                        const nextState = Boolean(active);

                        if (nextState === this.isFullscreen) {
                            return;
                        }

                        this.isFullscreen = nextState;

                        if (nextState) {
                            this.bodyOverflowBeforeFullscreen = document.body.style.overflow;
                            this.htmlOverflowBeforeFullscreen = document.documentElement.style.overflow;
                            document.body.style.overflow = 'hidden';
                            document.documentElement.style.overflow = 'hidden';
                        } else {
                            document.body.style.overflow = this.bodyOverflowBeforeFullscreen ?? '';
                            document.documentElement.style.overflow = this.htmlOverflowBeforeFullscreen ?? '';
                            this.bodyOverflowBeforeFullscreen = null;
                            this.htmlOverflowBeforeFullscreen = null;
                        }

                        this.$nextTick(() => {
                            this.refreshRouteLines();
                            setTimeout(() => this.refreshRouteLines(), 150);
                        });
                    },
                    destroy() {
                        window.removeEventListener('resize', this._refreshWorkflowRoutes);
                        document.removeEventListener('livewire:updated', this._refreshWorkflowRoutes);
                        document.removeEventListener('livewire:navigated', this._refreshWorkflowRoutes);

                        if (this.isFullscreen) {
                            document.body.style.overflow = this.bodyOverflowBeforeFullscreen ?? '';
                            document.documentElement.style.overflow = this.htmlOverflowBeforeFullscreen ?? '';
                        }
                    },
                    routeFocusNode() {
                        return this.hoveredRouteNode || this.activeRouteNode || '';
                    },
                    setHoveredRouteNode(node = '') {
                        this.hoveredRouteNode = String(node || '');
                        this.renderRouteLines();
                    },
                    setActiveRouteNode(node = '') {
                        this.activeRouteNode = String(node || '');
                        this.renderRouteLines();
                    },
                    renderRouteLines() {
                        const focusNode = this.routeFocusNode();
                        const hasRelatedLine = focusNode !== '' && this.routeLines.some((line) => line.sourceNode === focusNode || line.targetNode === focusNode);

                        this.routeSvgMarkup = this.routeLines.map((line) => {
                            const color = line.type === 'failed' ? '#fb7185' : '#10b981';
                            const marker = line.type === 'failed' ? 'url(#workflow-arrow-red)' : 'url(#workflow-arrow-green)';
                            const dash = line.type === 'failed' ? ' stroke-dasharray=&quot;6 5&quot;' : '';
                            const related = !hasRelatedLine || line.sourceNode === focusNode || line.targetNode === focusNode;
                            const opacity = hasRelatedLine ? (related ? 1 : 0.5) : 0.9;
                            const strokeWidth = related && hasRelatedLine ? 3.4 : 2.15;
                            const filter = related && hasRelatedLine ? ' style=&quot;filter:drop-shadow(0 0 2px rgba(15,23,42,.24))&quot;' : '';
                            const path = String(line.path || '').replace(/&/g, '&amp;').replace(/&quot;/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                            return `<path d=&quot;${path}&quot; fill=&quot;none&quot; stroke-width=&quot;${strokeWidth}&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot; stroke=&quot;${color}&quot; opacity=&quot;${opacity}&quot;${dash}${filter} marker-end=&quot;${marker}&quot;></path>`;
                        }).join('');
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
                        const relativeRect = (element) => {
                            const rect = element.getBoundingClientRect();

                            return {
                                width: rect.width,
                                height: rect.height,
                                left: rect.left - surfaceRect.left + surface.scrollLeft,
                                right: rect.right - surfaceRect.left + surface.scrollLeft,
                                top: rect.top - surfaceRect.top + surface.scrollTop,
                                bottom: rect.bottom - surfaceRect.top + surface.scrollTop,
                                centerX: rect.left + (rect.width / 2) - surfaceRect.left + surface.scrollLeft,
                                centerY: rect.top + (rect.height / 2) - surfaceRect.top + surface.scrollTop,
                            };
                        };
                        const roundedPath = (points, radius = 9) => {
                            const compact = points.filter((point, index) => {
                                const previous = points[index - 1];

                                return !previous || previous.x !== point.x || previous.y !== point.y;
                            });

                            if (compact.length < 2) {
                                return '';
                            }

                            let path = `M ${compact[0].x} ${compact[0].y}`;

                            for (let index = 1; index < compact.length - 1; index++) {
                                const previous = compact[index - 1];
                                const current = compact[index];
                                const next = compact[index + 1];
                                const incoming = Math.hypot(current.x - previous.x, current.y - previous.y);
                                const outgoing = Math.hypot(next.x - current.x, next.y - current.y);
                                const cornerRadius = Math.min(radius, incoming / 2, outgoing / 2);
                                const before = {
                                    x: current.x + ((previous.x - current.x) / incoming) * cornerRadius,
                                    y: current.y + ((previous.y - current.y) / incoming) * cornerRadius,
                                };
                                const after = {
                                    x: current.x + ((next.x - current.x) / outgoing) * cornerRadius,
                                    y: current.y + ((next.y - current.y) / outgoing) * cornerRadius,
                                };

                                path += ` L ${before.x} ${before.y} Q ${current.x} ${current.y} ${after.x} ${after.y}`;
                            }

                            const end = compact[compact.length - 1];

                            return `${path} L ${end.x} ${end.y}`;
                        };
                        const stepColumns = Array.from(surface.querySelectorAll('[data-workflow-step-column]'));
                        const stepIndexes = new Map(stepColumns.map((column, index) => [column, index]));
                        let routeLane = 0;
                        const makeLine = (source, target, type) => {
                            const targetElement = targetNode(target);

                            if (!source || !targetElement) {
                                return null;
                            }

                            const sourceStep = source.dataset.workflowStepAction || '';
                            const targetStep = targetElement.dataset.workflowStepAction || '';
                            const sourceRect = relativeRect(source);
                            const targetRect = relativeRect(targetElement);
                            const sourceStepElement = source.closest('[data-workflow-step-column]');
                            const targetStepElement = targetElement.closest('[data-workflow-step-column]');
                            const sourceStepRect = sourceStepElement ? relativeRect(sourceStepElement) : sourceRect;
                            const targetStepRect = targetStepElement ? relativeRect(targetStepElement) : targetRect;
                            const sourceStepIndex = stepIndexes.get(sourceStepElement) ?? -1;
                            const targetStepIndex = stepIndexes.get(targetStepElement) ?? -1;
                            const laneIndex = routeLane++;
                            const sourceY = type === 'failed'
                                ? sourceRect.top + (sourceRect.height * 0.68)
                                : sourceRect.top + (sourceRect.height * 0.4);
                            const targetY = targetRect.centerY;
                            const sourceNode = source.dataset.workflowTaskNode || '';
                            const targetNodeKey = targetElement.dataset.workflowTaskNode || '';
                            const lineResult = (points, radius = 9) => ({
                                path: roundedPath(points, radius),
                                type,
                                sourceNode,
                                targetNode: targetNodeKey,
                            });
                            let points = [];

                            if (source === targetElement) {
                                const loopX = sourceStepRect.right + 14 + ((laneIndex % 3) * 5);
                                points = [
                                    { x: sourceRect.right, y: sourceY },
                                    { x: loopX, y: sourceY },
                                    { x: loopX, y: targetY + 14 },
                                    { x: sourceRect.right, y: targetY + 14 },
                                    { x: sourceRect.right, y: targetY },
                                ];

                                return lineResult(points, 7);
                            }

                            if (sourceStep === targetStep) {
                                const stepNodes = nodes
                                    .filter((node) => (node.dataset.workflowStepAction || '') === sourceStep)
                                    .sort((left, right) => relativeRect(left).top - relativeRect(right).top);
                                const sourceIndex = stepNodes.indexOf(source);
                                const targetIndex = stepNodes.indexOf(targetElement);

                                if (type === 'success' && targetIndex === sourceIndex + 1) {
                                    return null;
                                }

                                const sideX = sourceStepRect.right + 14 + ((laneIndex % 3) * 5);
                                points = [
                                    { x: sourceRect.right, y: sourceY },
                                    { x: sideX, y: sourceY },
                                    { x: sideX, y: targetY },
                                    { x: targetRect.right, y: targetY },
                                ];

                                return lineResult(points);
                            }

                            const goesBack = targetStepIndex < sourceStepIndex || targetRect.centerX < sourceRect.centerX;
                            const sourceAnchorX = goesBack ? sourceRect.left : sourceRect.right;
                            const targetAnchorX = goesBack ? targetRect.right : targetRect.left;
                            const adjacentSteps = sourceStepIndex >= 0
                                && targetStepIndex >= 0
                                && Math.abs(sourceStepIndex - targetStepIndex) === 1;

                            if (adjacentSteps) {
                                const gapLeft = goesBack ? targetStepRect.right : sourceStepRect.right;
                                const gapRight = goesBack ? sourceStepRect.left : targetStepRect.left;
                                const gapOffset = ((laneIndex % 5) - 2) * 3;
                                const gapX = Math.max(gapLeft + 7, Math.min(gapRight - 7, ((gapLeft + gapRight) / 2) + gapOffset));
                                points = [
                                    { x: sourceAnchorX, y: sourceY },
                                    { x: gapX, y: sourceY },
                                    { x: gapX, y: targetY },
                                    { x: targetAnchorX, y: targetY },
                                ];

                                return lineResult(points);
                            }

                            const firstStepIndex = Math.min(sourceStepIndex, targetStepIndex);
                            const lastStepIndex = Math.max(sourceStepIndex, targetStepIndex);
                            const involvedRects = nodes
                                .filter((node) => {
                                    const columnIndex = stepIndexes.get(node.closest('[data-workflow-step-column]')) ?? -1;

                                    return columnIndex >= firstStepIndex && columnIndex <= lastStepIndex;
                                })
                                .map(relativeRect);
                            const clearance = 14 + ((laneIndex % 4) * 6);
                            const upperY = Math.max(12, Math.min(...involvedRects.map((rect) => rect.top), sourceRect.top, targetRect.top) - clearance);
                            const lowerY = Math.min(
                                surface.scrollHeight - 12,
                                Math.max(...involvedRects.map((rect) => rect.bottom), sourceRect.bottom, targetRect.bottom) + clearance,
                            );
                            const upperCost = Math.abs(sourceY - upperY) + Math.abs(targetY - upperY);
                            const lowerCost = Math.abs(sourceY - lowerY) + Math.abs(targetY - lowerY);
                            const corridorY = lowerCost < upperCost ? lowerY : upperY;
                            const sourceLaneX = sourceAnchorX + (goesBack ? -clearance : clearance);
                            const targetLaneX = targetAnchorX + (goesBack ? clearance : -clearance);
                            points = [
                                { x: sourceAnchorX, y: sourceY },
                                { x: sourceLaneX, y: sourceY },
                                { x: sourceLaneX, y: corridorY },
                                { x: targetLaneX, y: corridorY },
                                { x: targetLaneX, y: targetY },
                                { x: targetAnchorX, y: targetY },
                            ];

                            return lineResult(points);
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
                        this.renderRouteLines();
                    },
                }"
                x-init="refreshRouteLines()"
                x-on:keydown.escape.window="setFullscreen(false)"
                x-bind:class="isFullscreen ? 'fixed inset-0 z-[60] flex flex-col rounded-none border-0' : 'rounded-xl border border-slate-200'"
                class="overflow-hidden "
            >
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white p-box">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">{{ $selectedWorkflow->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            <span x-show="! isFullscreen">Listen horizontal anordnen, Tasks verschieben oder aus der Bibliothek hineinziehen.</span>
                            <span x-cloak x-show="isFullscreen">Vollbildansicht · Mit Esc beenden.</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div x-show="showRoutes" class="flex items-center gap-2  text-[11px] font-semibold text-slate-500">
                            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-5 rounded-full bg-emerald-500"></span>Erfolg</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-5 border-t-2 border-dashed border-rose-400"></span>Fehlschlag</span>
                        </div>
                        <button type="button" x-on:click="showRoutes = ! showRoutes" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-100 hover:text-slate-900">
                            <span x-text="showRoutes ? 'Routen ausblenden' : 'Routen einblenden'"></span>
                        </button>
                        <button
                            type="button"
                            x-on:click="setFullscreen(! isFullscreen)"
                            x-bind:aria-pressed="isFullscreen"
                            x-bind:aria-label="isFullscreen ? 'Vollbildansicht beenden' : 'Workflow im Vollbild anzeigen'"
                            x-bind:title="isFullscreen ? 'Vollbild beenden (Esc)' : 'Workflow im Vollbild anzeigen'"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2"
                        >
                            <svg x-show="! isFullscreen" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"></path>
                            </svg>
                            <svg x-cloak x-show="isFullscreen" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M8 8H3V3M16 8h5V3M8 16H3v5M16 16h5v5"></path>
                            </svg>
                            <span x-text="isFullscreen ? 'Vollbild beenden' : 'Vollbild'"></span>
                        </button>
                    </div>
                </div>

                <div
                    x-ref="routeSurface"
                    x-on:scroll.debounce.100ms="refreshRouteLines()"
                    x-bind:class="isFullscreen ? 'min-h-0 flex-1 max-h-none' : ' min-h-70vh'"
                    class="relative isolate overflow-x-auto overflow-y-hidden bg-white scroll-container"
                    style="background-image:linear-gradient(rgba(203,213,225,.18) 1px,transparent 1px),linear-gradient(90deg,rgba(203,213,225,.18) 1px,transparent 1px),linear-gradient(rgba(226,232,240,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(226,232,240,.12) 1px,transparent 1px);background-size:20px 20px,20px 20px,100px 100px,100px 100px;"
                >
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
                            <marker id="workflow-arrow-green" markerWidth="7" markerHeight="7" refX="6.5" refY="3.5" orient="auto" markerUnits="userSpaceOnUse">
                                <path d="M0,0 L0,7 L7,3.5 z" fill="#10b981"></path>
                            </marker>
                            <marker id="workflow-arrow-red" markerWidth="7" markerHeight="7" refX="6.5" refY="3.5" orient="auto" markerUnits="userSpaceOnUse">
                                <path d="M0,0 L0,7 L7,3.5 z" fill="#fb7185"></path>
                            </marker>
                        </defs>
                        <g x-html="routeSvgMarkup"></g>
                    </svg>

                    <div
                        x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })"
                        x-bind:class="isFullscreen ? 'gap-5 px-3' : 'gap-10 px-5'"
                        class="relative flex min-h-[570px] items-start pb-8 pt-[76px]"
                    >
                        @forelse($steps as $step)
                            <div class="flex w-[296px] min-w-[296px] max-w-[296px] shrink-0 items-start" x-sort:item="{{ $step->id }}" wire:key="workflow-step-wrap-{{ $step->id }}">
                                <x-workflows.step-card :step="$step" wire:key="workflow-step-{{ $step->id }}">
                                    <x-slot name="actions">
                                        <button type="button" wire:click="openEditStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Bearbeiten</button>
                                        <button type="button" wire:click="toggleStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}</button>
                                        <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste samt Tasks wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">Entfernen</button>
                                    </x-slot>
                                </x-workflows.step-card>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600 shadow-sm">
                                Keine Listen. Nutze oben den Button "Liste".
                            </div>
                        @endforelse

                        <button x-show="! isFullscreen" type="button" wire:click="$set('showAddStepModal', true)" class="flex min-h-[180px] w-[280px] shrink-0 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white/70 p-5 text-center text-sm font-semibold text-slate-600 transition hover:border-slate-400 hover:bg-white hover:text-slate-900 hover:shadow-sm">+ Neue Liste anlegen</button>
                    </div>
                </div>
            </div>
</div>

        @if(! $showTaskPanel)
            <button
                type="button"
                x-on:click="clearTaskInsert()"
                wire:click="$set('showTaskPanel', true)"
                class="fixed right-0 top-1/2 z-[65] flex -translate-y-1/2 items-center gap-2 rounded-l-xl border border-r-0 border-slate-700 bg-slate-900 px-3 py-3 text-sm font-semibold text-white shadow-xl transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2"
                aria-label="Task-Bibliothek oeffnen"
            >
                <svg class="h-5 w-5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                    <path d="M17.5 14v7M14 17.5h7"></path>
                </svg>
            </button>
        @endif

        @if($showTaskPanel)
            <div
                x-data="{}"
                x-on:keydown.escape.window="clearTaskInsert()"
                class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl"
            >
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 bg-slate-50/80 p-5">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-slate-900">Task-Bibliothek</h2>
                        <p class="mt-1 text-xs text-slate-500" x-show="! taskInsertTarget">Task auf eine Liste ziehen, danach oeffnet sich das Formular.</p>
                        <div x-cloak x-show="taskInsertTarget" role="status" class="mt-1 flex flex-wrap items-center gap-2">
                            <p class="text-xs text-emerald-700">
                                Klick fuegt den Task am Ende von
                                <span class="font-semibold" x-text="taskInsertTarget ? taskInsertTarget.stepName : ''"></span>
                                ein.
                            </p>
                            <button type="button" x-on:click="clearTaskInsert()" class="rounded border border-slate-300 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900">Abbrechen</button>
                        </div>
                    </div>
                    <button type="button" x-on:click="clearTaskInsert()" wire:click="$set('showTaskPanel', false)" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                        x
                    </button>
                </div>
                <div class="border-b border-slate-200 px-4">
                    <nav class="-mb-px flex gacontainer overflow-x-auto" aria-label="Task Gruppen">
                        @foreach($taskGroups as $taskGroup)
                            <button
                                type="button"
                                data-task-group-tab="{{ $taskGroup }}"
                                wire:click="$set('activeTaskGroup', @js($taskGroup))"
                                class="whitespace-nowrap border-b-2 py-3 text-sm font-semibold {{ $activeTaskGroup === $taskGroup ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
                            >
                                {{ $taskGroupLabels[$taskGroup] ?? $taskGroup }}
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ collect($taskDefinitions)->where('kind', $taskGroup)->count() }}</span>
                            </button>
                        @endforeach
                    </nav>
                </div>
                <div class="flex-1 space-y-3 overflow-y-auto container">
                    @foreach($visibleTaskDefinitions as $taskDefinition)
                        <div
                            data-workflow-task-catalog-key="{{ $taskDefinition['key'] }}"
                            data-assistant-highlight="workflow_task_catalog:{{ $taskDefinition['key'] }}"
                            data-assistant-highlight-key="{{ $taskDefinition['key'] }}"
                            draggable="true"
                            x-on:dragstart.stop="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                            x-on:click="insertCatalogTask(@js($taskDefinition['key']))"
                            x-on:keydown.enter.prevent="insertCatalogTask(@js($taskDefinition['key']))"
                            x-on:keydown.space.prevent="insertCatalogTask(@js($taskDefinition['key']))"
                            x-bind:tabindex="taskInsertTarget ? 0 : -1"
                            x-bind:role="taskInsertTarget ? 'button' : null"
                            x-bind:class="taskInsertTarget ? 'cursor-pointer ring-1 ring-emerald-300 hover:ring-emerald-500' : 'cursor-grab active:cursor-grabbing'"
                            class="rounded-xl border border-slate-200 bg-white container shadow-sm transition hover:border-slate-400 hover:shadow-md"
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

        <x-ui.dialog-modal wire:model="showWorkflowModal" maxWidth="2xl">
            <x-slot name="title">Workflow bearbeiten</x-slot>
            <x-slot name="content">
                <x-workflows.workflow-form
                    name-model="workflowName"
                    group-model="workflowGroup"
                    subcategory-model="workflowSubcategory"
                    description-model="workflowDescription"
                    active-model="workflowActive"
                    lock-model="workflowLocked"
                    development-model="workflowDevelopment"
                    lock-help="Gesperrte Workflows bleiben fuer Admins bearbeitbar. Der Sperrstatus wird weiterhin als Warnung angezeigt."
                />
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-ui.dialog-modal>

        @if($showTestWorkbenchModal && $selectedWorkflow)
            <div class="fixed inset-0 z-[70] overflow-hidden bg-slate-100" role="dialog" aria-modal="true" aria-label="Workflow testen">
                <livewire:admin.network.workflow-studio
                    :workflow="$selectedWorkflow"
                    :embedded="true"
                    :initial-mode="$testWorkbenchMode"
                    :run-id="$testWorkbenchRunId"
                    :key="'workflow-test-workbench-'.$selectedWorkflow->id.'-'.$testWorkbenchKey"
                />
            </div>
        @endif

        <x-ui.dialog-modal wire:model="showRunModal" maxWidth="xl" :interactive-aside="true">
            <x-slot name="title">Workflow testen</x-slot>
            <x-slot name="content">
                <div class="space-y-4">
                    <div>
                    <label for="workflow-run-person" class="block text-sm font-medium text-gray-700">Person / Kontext</label>
                    <select id="workflow-run-person" wire:model.defer="runPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">System (bisheriges Haupt-Verifikationskonto)</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                        @endforeach
                    </select>
                    @error('runPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="workflow-run-target" class="block text-sm font-medium text-gray-700">Ausfuehrung</label>
                        <select id="workflow-run-target" wire:model.live="runExecutionTarget" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="system">Server / System (bisheriger Ablauf)</option>
                            <option value="client_controller">ClientController-Netzwerk</option>
                        </select>
                        @error('runExecutionTarget') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if($runExecutionTarget === 'client_controller')
                        <div class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                            Browser-Workflows laufen direkt auf dem Node im lokalen CloakBrowser. Ein angeschlossenes Geraet ist nicht erforderlich.
                        </div>
                        <div>
                            <label for="workflow-run-node" class="block text-sm font-medium text-gray-700">ClientController-Node (optional)</label>
                            <select id="workflow-run-node" wire:model.live="runNetworkNodeId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <option value="">Automatisch freien Node waehlen / einreihen</option>
                                @foreach($runNetworkNodes as $node)
                                    <option value="{{ $node->id }}">{{ $node->name }} · {{ $node->is_online ? 'online' : 'offline' }}</option>
                                @endforeach
                            </select>
                            @error('runNetworkNodeId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-run-device" class="block text-sm font-medium text-gray-700">Geraet (optional)</label>
                            <select id="workflow-run-device" wire:model.defer="runDeviceId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <option value="">Kein festes Geraet</option>
                                @foreach($runNetworkNodeId ? $runDevices->where('network_node_id', (int) $runNetworkNodeId) : $runDevices as $device)
                                    <option value="{{ $device->id }}">{{ $device->name }} · {{ $device->status }}</option>
                                @endforeach
                            </select>
                            @error('runDeviceId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label for="workflow-run-inputs" class="block text-sm font-medium text-gray-700">Workflow-Eingaben (JSON)</label>
                        <textarea id="workflow-run-inputs" rows="7" wire:model.defer="runWorkflowInputs" placeholder='{"browser_window":"main","Mail-Inbox-Liste-Scan.subject_filter":["Rechnung"],"Mail-Inbox-Liste-Scan.max_age_minutes":30,"Mail-Inbox-Liste-Scan.mail_ids":[]}' class="mt-1 block w-full rounded-md border border-gray-300 p-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Diese Werte werden vom Task „Workflow-Eingaben pruefen“ gelesen und koennen folgende Tasks ueberschreiben.</p>
                        @error('runWorkflowInputs') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">Normalen Testdurchlauf starten</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showCopilotModal" maxWidth="3xl" :interactive-aside="true">
            <x-slot name="title">Workflow mit Copilot optimieren</x-slot>
            <x-slot name="content">
                <div class="space-y-5">
                    <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-950">
                        <p class="font-bold">Ausschliesslich System-Ausfuehrung</p>
                        <p class="mt-1 leading-5">Der Copilot verwendet dieselbe Workflow-Vorschau wie ein normaler System-Test, einschliesslich Workflow-Karte, Tasks, Browserfenstern und Logs. Ist der Workflow leer, plant er aus Ziel, Erfolgskriterien und Eingaben zuerst eine katalogkonforme Erstdefinition. Eine ClientController-Ausfuehrung ist fuer Reparaturen ausgeschlossen.</p>
                    </div>

                    <div class="rounded-xl border p-4 text-sm {{ $copilotAutoExecute ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-rose-200 bg-rose-50 text-rose-950' }}">
                        <p class="font-bold">{{ $copilotAutoExecute ? 'Autonome Aktionen sind freigegeben' : 'Autonome Aktionen sind deaktiviert' }}</p>
                        <p class="mt-1 leading-5">
                            {{ $copilotAutoExecute
                                ? 'Mit dem bewussten Start darf der Copilot vorhandene Workflow-Tasks im System-Test ausfuehren und Konfigurationen versioniert anpassen.'
                                : 'Aktiviere zuerst in den AI-Workflow-Chatbot-Einstellungen die Freigabe fuer autonome Workflow-Aktionen. Ohne diese Freigabe wird serverseitig keine Reparatursitzung gestartet.' }}
                        </p>
                        @error('copilotAutoExecute') <p class="mt-2 font-semibold text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="copilot-goal" class="block text-sm font-medium text-gray-700">Ziel der Optimierung</label>
                        <textarea id="copilot-goal" rows="4" wire:model.defer="copilotGoal" placeholder="Beispiel: Der komplette Registrierungsablauf soll bis zur sichtbaren Erfolgsseite durchlaufen." class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Dieses Ziel bleibt waehrend der Sitzung unveraendert.</p>
                        @error('copilotGoal') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="copilot-success-criteria" class="block text-sm font-medium text-gray-700">Feste Erfolgskriterien</label>
                        <textarea id="copilot-success-criteria" rows="5" wire:model.defer="copilotSuccessCriteria" placeholder="Finale URL enthaelt /success&#10;Text Registrierung abgeschlossen ist sichtbar&#10;workflow_return ist success" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Ein Kriterium pro Zeile oder ein strukturiertes JSON-Objekt. Der Copilot darf diese Kriterien nicht abschwaechen.</p>
                        @error('copilotSuccessCriteria') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="copilot-person" class="block text-sm font-medium text-gray-700">Person / Kontext</label>
                            <select id="copilot-person" wire:model.defer="copilotPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2.5 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="">System (Haupt-Verifikationskonto)</option>
                                @foreach($persons as $person)
                                    <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                                @endforeach
                            </select>
                            @error('copilotPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                            <p class="font-semibold text-slate-700">Ausfuehrungsziel</p>
                            <p class="mt-1 font-bold text-slate-950">Server / System</p>
                            <p class="mt-1 text-xs text-slate-500">Fest vorgegeben; keine Client- oder Node-Auswahl.</p>
                        </div>
                    </div>

                    <div>
                        <label for="copilot-workflow-inputs" class="block text-sm font-medium text-gray-700">Workflow-Eingaben (JSON)</label>
                        <textarea id="copilot-workflow-inputs" rows="5" wire:model.defer="copilotWorkflowInputs" placeholder='{"browser_window":"main"}' class="mt-1 block w-full rounded-md border border-gray-300 p-3 font-mono text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        @error('copilotWorkflowInputs') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">Sicherheits- und Arbeitsbudgets</legend>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <label for="copilot-max-minutes" class="block text-xs font-medium text-gray-600">Laufzeit (Min.)</label>
                                <input id="copilot-max-minutes" type="number" min="5" max="1440" wire:model.defer="copilotMaxMinutes" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxMinutes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-repairs" class="block text-xs font-medium text-gray-600">Reparaturrunden</label>
                                <input id="copilot-max-repairs" type="number" min="1" max="100" wire:model.defer="copilotMaxRepairIterations" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxRepairIterations') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-probes" class="block text-xs font-medium text-gray-600">Probeaktionen</label>
                                <input id="copilot-max-probes" type="number" min="1" max="500" wire:model.defer="copilotMaxProbeActions" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxProbeActions') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-same-state" class="block text-xs font-medium text-gray-600">Gleicher Zustand</label>
                                <input id="copilot-max-same-state" type="number" min="1" max="10" wire:model.defer="copilotMaxSameStateRepeats" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxSameStateRepeats') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-cost-usd" class="block text-xs font-medium text-gray-600">AI-Kosten (USD)</label>
                                <input id="copilot-max-cost-usd" type="number" min="0" max="10000" step="0.0001" wire:model.defer="copilotMaxCostUsd" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <p class="mt-1 text-[11px] text-slate-500">0 = unbegrenzt</p>
                                @error('copilotMaxCostUsd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </fieldset>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Webseitenaktionen koennen externe Wirkungen ausloesen. Ein Zurueckspulen setzt den Workflowcursor und internen Kontext zurueck, kann bereits versendete Formulare, Nachrichten oder Registrierungen aber nicht rueckgaengig machen.
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="startCopilotOptimization" wire:loading.attr="disabled" wire:target="startCopilotOptimization" @disabled(! $copilotAutoExecute) class="rounded-md bg-cyan-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-40">System-Optimierung starten</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showRunPreviewModal" maxWidth="7xl" :interactive-aside="true">
            <x-slot name="title">
                <div class="flex flex-wrap items-center gap-2">
                    <span>{{ $activeCopilotSession ? 'Testlauf & Copilot-Optimierung' : 'Workflow-Testlauf' }}</span>
                    @if($activeCopilotSession && $copilotStatus !== [])
                        @php
                            $managerCopilotStatus = (string) data_get($copilotStatus, 'status', 'unknown');
                            $managerCopilotStatusLabel = match ($managerCopilotStatus) {
                                'running' => 'Laeuft',
                                'paused' => 'Pausiert',
                                'repairing' => 'Repariert',
                                'verifying' => 'Verifiziert',
                                'succeeded' => 'Erfolgreich abgeschlossen',
                                'budget_exhausted' => 'Budget erreicht',
                                'failed' => 'Fehlgeschlagen',
                                'stopped' => 'Gestoppt',
                                default => $managerCopilotStatus,
                            };
                        @endphp
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $managerCopilotStatus === 'succeeded' ? 'bg-emerald-100 text-emerald-800' : (in_array($managerCopilotStatus, ['failed', 'budget_exhausted'], true) ? 'bg-rose-100 text-rose-800' : 'bg-cyan-100 text-cyan-800') }}">{{ $managerCopilotStatusLabel }}</span>
                    @endif
                </div>
            </x-slot>
            <x-slot name="content">
                <div @if($showRunPreviewModal) wire:poll.2s="refreshRunPreview" @endif class="space-y-5">
                    @if($activeCopilotSession && data_get($copilotStatus, 'status') === 'succeeded')
                        <section data-workflow-copilot-completed-state class="flex flex-col gap-3 rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 p-4 text-emerald-950 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[.14em] text-emerald-700">Optimierung abgeschlossen</p>
                                <h3 class="mt-1 text-base font-black">Ziel und Erfolgskriterien wurden im Kontrolllauf bestaetigt.</h3>
                                <p class="mt-1 text-sm text-emerald-800">Ergebnis, Screenshot, Ereignisse und Revisionen bleiben fuer die Nachvollziehbarkeit sichtbar.</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center justify-center rounded-full bg-emerald-600 px-4 py-2 text-xs font-black text-white">BESTANDEN</span>
                        </section>
                    @elseif($activeCopilotSession && in_array(data_get($copilotStatus, 'status'), ['failed', 'budget_exhausted'], true))
                        <section class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-950">
                            <p class="text-xs font-black uppercase tracking-[.14em] text-rose-700">Optimierung beendet</p>
                            <h3 class="mt-1 text-base font-black">Der Workflow hat das Ziel in diesem Lauf nicht erreicht.</h3>
                            <p class="mt-1 text-sm text-rose-800">Analysiere Ereignisse und letzten Bildschirm oder starte mit denselben Vorgaben und frischen Budgets neu.</p>
                        </section>
                    @endif

                    @if($previewWorkflowRun)
                        @if(! $activeCopilotSession && in_array($previewWorkflowRun->status, ['running', 'waiting', 'paused'], true))
                            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-xs font-black uppercase tracking-[.12em] text-amber-700">Interaktiver Debug-Lauf</p>
                                        @if($previewWorkflowRun->status === 'paused')
                                            <p class="mt-1 text-sm text-amber-950">Der Browser-, Variablen- und Routingzustand ist eingefroren. Du kannst den Workflow jetzt bearbeiten und danach ab einem Task fortsetzen.</p>
                                        @else
                                            <p class="mt-1 text-sm text-amber-950">Die Pause greift am naechsten sicheren Task- bzw. Step-Checkpoint.</p>
                                        @endif
                                    </div>
                                    @if($previewWorkflowRun->status === 'paused')
                                        <label class="block min-w-0 lg:w-[30rem]">
                                            <span class="mb-1 block text-xs font-bold text-amber-900">Optional ab diesem Task fortsetzen</span>
                                            <select wire:model="manualResumeCursor" class="w-full rounded-md border-amber-300 bg-white text-sm focus:border-amber-500 focus:ring-amber-500">
                                                <option value="">Gespeicherten Cursor verwenden</option>
                                                @foreach($manualResumeOptions as $resumeOption)
                                                    <option value="{{ $resumeOption['value'] }}">{{ $resumeOption['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                </div>

                                @if($previewWorkflowRun->status === 'paused')
                                    <div class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Naechster Step</span><code>{{ data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.next_step_action_key') ?: '-' }}</code></div>
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Naechster Task</span><code>{{ data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.next_task_key') ?: '-' }}</code></div>
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Variablen</span>{{ count((array) data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.workflow_variables', [])) }} gespeichert</div>
                                    </div>
                                @endif
                            </section>
                        @endif
                        <x-workflows.run-preview :workflow-run="$previewWorkflowRun" />
                    @elseif($activeCopilotSession)
                        <div class="rounded-md border border-dashed border-cyan-300 bg-cyan-50 p-4 text-sm text-cyan-900">
                            Der Copilot plant den Workflow und bereitet den ersten gemeinsamen Vorschau-Test vor.
                        </div>
                    @else
                        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            Dieser Workflow-Lauf wurde noch nicht geladen.
                        </div>
                    @endif

                    @if($activeCopilotSession && $copilotStatus !== [])
                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,.7fr)]">
                            <div class="space-y-4">
                                <div class="overflow-hidden rounded-xl border border-cyan-200 bg-white">
                                    <div class="flex flex-wrap items-start justify-between gap-3 bg-gradient-to-r from-slate-950 via-cyan-900 to-emerald-800 px-4 py-3 text-white">
                                        <div>
                                            <p class="font-bold">{{ data_get($copilotStatus, 'workflow_name') }}</p>
                                            <p class="mt-0.5 text-xs text-cyan-100">System-Ausfuehrung · {{ data_get($copilotStatus, 'phase') }}</p>
                                        </div>
                                        <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold">{{ data_get($copilotStatus, 'status') }}</span>
                                    </div>

                                    @if(data_get($copilotStatus, 'latest_screenshot_url'))
                                        <a href="{{ data_get($copilotStatus, 'latest_screenshot_url') }}" target="_blank" rel="noopener noreferrer" class="block bg-slate-100 p-2">
                                            <img src="{{ data_get($copilotStatus, 'latest_screenshot_url') }}" alt="Aktueller Workflow-Copilot-Bildschirm" class="mx-auto max-h-[440px] w-full object-contain" loading="lazy">
                                        </a>
                                    @else
                                        <div class="flex min-h-48 items-center justify-center bg-slate-100 px-4 text-sm text-slate-500">Der erste Bildschirm wird beim naechsten Checkpoint erfasst.</div>
                                    @endif

                                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block text-xs font-semibold text-slate-500">Step</span><strong>{{ data_get($copilotStatus, 'current_step_name') ?: 'Wird vorbereitet' }}</strong></div>
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block text-xs font-semibold text-slate-500">Task</span><strong>{{ data_get($copilotStatus, 'current_task_title') ?: data_get($copilotStatus, 'current_task_key', '-') }}</strong></div>
                                        @foreach(['page_state' => 'Erkannter Bildschirm', 'last_action' => 'Letzte Aktion', 'current_result' => 'Ergebnis', 'next_action' => 'Naechster Schritt'] as $key => $label)
                                            <div class="rounded-lg border border-slate-200 p-3 text-sm">
                                                <span class="block text-xs font-semibold text-slate-500">{{ $label }}</span>
                                                <span class="mt-1 block break-words text-slate-800">{{ data_get($copilotStatus, $key) ?: '-' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                @if(is_array(data_get($copilotStatus, 'vision_analysis')))
                                    @php
                                        $visionAnalysis = data_get($copilotStatus, 'vision_analysis');
                                        $visionVerdict = (string) data_get($visionAnalysis, 'verdict', 'pause');
                                    @endphp
                                    <section data-workflow-copilot-vision-analysis class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-black uppercase tracking-[.12em] text-slate-500">Letzte Bildanalyse</p>
                                                <h3 class="mt-1 font-bold text-slate-950">
                                                    {{ data_get($visionAnalysis, 'page_type') ?: 'Unbekannte Seite' }}
                                                    · {{ data_get($visionAnalysis, 'ui_state') ?: 'Unbekannter Zustand' }}
                                                </h3>
                                            </div>
                                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $visionVerdict === 'pass' ? 'bg-emerald-100 text-emerald-800' : ($visionVerdict === 'continue' ? 'bg-cyan-100 text-cyan-800' : 'bg-amber-100 text-amber-800') }}">
                                                {{ data_get($visionAnalysis, 'verdict_label') }}
                                                @if(data_get($visionAnalysis, 'confidence') !== null)
                                                    · {{ number_format((float) data_get($visionAnalysis, 'confidence') * 100, 0, ',', '.') }} %
                                                @endif
                                            </span>
                                        </div>

                                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">Seitentyp</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'page_type') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">UI-Zustand</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'ui_state') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">Zielfortschritt</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'goal_progress') ?: '-' }}</dd></div>
                                        </dl>

                                        @if(filled(data_get($visionAnalysis, 'browser_screen_description')))
                                            <div class="mt-3 rounded-lg border border-cyan-100 bg-cyan-50/60 p-3">
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-cyan-800">Beschreibung der Browseransicht</h4>
                                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ data_get($visionAnalysis, 'browser_screen_description') }}</p>
                                            </div>
                                        @endif

                                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                            <div>
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-slate-500">Erkannte Elemente</h4>
                                                <div class="mt-2 space-y-2">
                                                    @forelse(data_get($visionAnalysis, 'relevant_elements', []) as $element)
                                                        <div class="break-words border-l-2 border-cyan-300 pl-2 text-xs text-slate-700">
                                                            <code class="font-bold text-cyan-800">{{ $element['element_ref'] }}</code>
                                                            · {{ $element['reason'] ?: 'Als relevant erkannt' }}
                                                            @if($element['confidence'] !== null)
                                                                ({{ number_format((float) $element['confidence'] * 100, 0, ',', '.') }} %)
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-xs text-slate-500">Keine sicher zugeordneten Elemente.</p>
                                                    @endforelse
                                                </div>
                                            </div>

                                            <div>
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-slate-500">Vorgeschlagene Workflow-Aktionen</h4>
                                                <div class="mt-2 space-y-2">
                                                    @forelse(data_get($visionAnalysis, 'suggested_task_actions', []) as $action)
                                                        <div class="break-words border-l-2 border-emerald-300 pl-2 text-xs text-slate-700">
                                                            <code class="font-bold text-emerald-800">{{ $action['task_key'] }}</code>
                                                            @if(filled($action['workflow_task_key'] ?? null))
                                                                fuer <code>{{ $action['workflow_task_key'] }}</code>
                                                            @endif
                                                            @if(filled($action['element_ref']))
                                                                an <code>{{ $action['element_ref'] }}</code>
                                                            @endif
                                                            @if(filled($action['reason']))
                                                                · {{ $action['reason'] }}
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-xs text-slate-500">Keine direkte Task-Aktion vorgeschlagen.</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>

                                        @if(data_get($visionAnalysis, 'blockers', []) !== [])
                                            <div class="mt-4 border-t border-amber-100 pt-3">
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-amber-800">Hinweise und Hindernisse</h4>
                                                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-900">
                                                    @foreach(data_get($visionAnalysis, 'blockers', []) as $blocker)
                                                        <li class="break-words">{{ $blocker }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        @if(filled(data_get($visionAnalysis, 'model')) || data_get($visionAnalysis, 'duration_ms', 0) > 0)
                                            <p class="mt-3 text-xs text-slate-400">
                                                {{ data_get($visionAnalysis, 'model') ?: data_get($visionAnalysis, 'analysis_source') }}
                                                @if(data_get($visionAnalysis, 'duration_ms', 0) > 0)
                                                    · {{ number_format((int) data_get($visionAnalysis, 'duration_ms') / 1000, 1, ',', '.') }} s
                                                @endif
                                            </p>
                                        @endif
                                    </section>
                                @endif

                                @if(is_array(data_get($copilotStatus, 'verification_report')))
                                    @php
                                        $verificationReport = data_get($copilotStatus, 'verification_report');
                                    @endphp
                                    <section
                                        data-workflow-copilot-verification-report
                                        class="rounded-xl border p-4 text-sm {{ data_get($verificationReport, 'pass') ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950' }}"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h3 class="font-bold">{{ data_get($verificationReport, 'final') ? 'Finaler Verifikationsbericht' : 'Letzte Verifikationspruefung' }}</h3>
                                            <span class="rounded-full bg-white/70 px-3 py-1 text-xs font-bold">{{ data_get($verificationReport, 'pass') ? 'BESTANDEN' : 'NICHT BESTANDEN' }}</span>
                                        </div>
                                        <p class="mt-2 leading-6">{{ data_get($verificationReport, 'message') }}</p>
                                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Revision</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'revision') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Kontrolllauf</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'workflow_run_id') ? '#'.data_get($verificationReport, 'workflow_run_id') : '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Zielassertionen</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'criteria_total') > 0 ? data_get($verificationReport, 'criteria_passed').'/'.data_get($verificationReport, 'criteria_total') : '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2">
                                                <dt class="font-semibold opacity-60">Bildanalyse</dt>
                                                <dd class="mt-1 font-bold">
                                                    {{ data_get($verificationReport, 'vision_verdict') ?: '-' }}
                                                    @if(data_get($verificationReport, 'vision_confidence') !== null)
                                                        ({{ number_format((float) data_get($verificationReport, 'vision_confidence') * 100, 0, ',', '.') }} %)
                                                    @endif
                                                </dd>
                                            </div>
                                        </dl>
                                    </section>
                                @endif

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <h3 class="text-sm font-bold text-slate-900">Live-Ereignisse</h3>
                                        <span class="text-xs text-slate-500">{{ data_get($copilotStatus, 'active') ? 'Aktualisierung alle 2 Sekunden' : 'Gespeicherter Sitzungsverlauf' }}</span>
                                    </div>
                                    <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                        @forelse($copilotEvents as $event)
                                            <div wire:key="manager-copilot-event-{{ $event['id'] }}" class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm {{ in_array($event['level'], ['error', 'critical'], true) ? 'border-rose-200 bg-rose-50' : ($event['level'] === 'success' ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50') }}">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ in_array($event['level'], ['error', 'critical'], true) ? 'bg-rose-500' : ($event['level'] === 'success' ? 'bg-emerald-500' : 'bg-cyan-500') }}"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2"><span class="text-xs font-bold uppercase text-slate-500">{{ $event['phase'] ?: 'Status' }}</span><time class="text-xs text-slate-400">{{ $event['time'] }}</time></div>
                                                    <p class="mt-1 break-words text-slate-800">{{ $event['message'] }}</p>
                                                </div>
                                            </div>
                                        @empty
                                            <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">Noch keine sichtbaren Arbeitsschritte vorhanden.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            <aside class="space-y-4">
                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Fortschritt und Budget</h3>
                                    <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-4">
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'repair_iteration', 0) }}/{{ data_get($copilotStatus, 'max_repair_iterations', 15) }}</strong>Runden</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'probe_actions', 0) }}/{{ data_get($copilotStatus, 'max_probe_actions', 60) }}</strong>Proben</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'remaining_minutes', 0) }}m</strong>Restzeit</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">${{ number_format((float) data_get($copilotStatus, 'cost_usd', 0), 4) }}</strong>{{ (float) data_get($copilotStatus, 'max_cost_usd', 0) > 0 ? 'von $'.number_format((float) data_get($copilotStatus, 'max_cost_usd'), 2) : 'AI-Kosten' }}</div>
                                    </div>
                                    <p class="mt-3 text-xs leading-5 text-slate-500">Ziel: {{ data_get($copilotStatus, 'goal') }}</p>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Checkpoints</h3>
                                    <div class="mt-3 max-h-48 space-y-2 overflow-y-auto">
                                        @forelse(data_get($copilotStatus, 'checkpoints', []) as $checkpoint)
                                            <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-2 text-xs {{ $checkpoint['is_reproducible'] ? 'cursor-pointer hover:bg-cyan-50' : 'cursor-not-allowed opacity-50' }}">
                                                <input type="radio" wire:model.live="copilotRewindCheckpoint" value="{{ $checkpoint['id'] }}" @disabled(! $checkpoint['is_reproducible']) class="mt-0.5 border-slate-300 text-cyan-700 focus:ring-cyan-600">
                                                <span class="min-w-0"><strong>#{{ $checkpoint['sequence'] }} · {{ $checkpoint['step_name'] ?: $checkpoint['phase'] }}</strong><span class="mt-0.5 block break-words text-slate-500">{{ $checkpoint['task_key'] ?: 'vor dem Step' }}{{ $checkpoint['has_side_effects'] ? ' · externe Wirkung protokolliert' : '' }}</span></span>
                                            </label>
                                        @empty
                                            <p class="text-xs text-slate-500">Noch kein reproduzierbarer Checkpoint vorhanden.</p>
                                        @endforelse
                                    </div>
                                    @error('copilotRewindCheckpoint') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                                    <button type="button" wire:click="rewindCopilotOptimization" wire:confirm="Zum ausgewaehlten Checkpoint zurueckspringen? Externe Wirkungen werden nicht rueckgaengig gemacht." @disabled(blank($copilotRewindCheckpoint)) class="mt-3 w-full rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40">Zum Checkpoint zurueckspulen</button>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Workflow-Revisionen</h3>
                                    <div class="mt-3 max-h-48 space-y-2 overflow-y-auto">
                                        @forelse(data_get($copilotStatus, 'revisions', []) as $revision)
                                            <details class="rounded-lg border border-slate-200 p-2 text-xs">
                                                <summary class="cursor-pointer font-bold text-slate-800">Revision {{ $revision['revision_number'] }}{{ $revision['is_verified'] ? ' · verifiziert' : '' }}</summary>
                                                <p class="mt-2 text-slate-600">{{ $revision['reason'] ?: 'Automatische Workflow-Anpassung' }}</p>
                                                @if($revision['diff'] !== [])
                                                    <pre class="mt-2 max-h-32 overflow-auto rounded bg-slate-950 p-2 text-[10px] text-slate-100">{{ json_encode($revision['diff'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @endif
                                            </details>
                                        @empty
                                            <p class="text-xs text-slate-500">Noch keine Workflow-Aenderung gespeichert.</p>
                                        @endforelse
                                    </div>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Bereinigte DOM-Elementkarte</h3>
                                    <div class="mt-3 max-h-48 space-y-1.5 overflow-y-auto text-xs">
                                        @forelse(data_get($copilotStatus, 'dom_elements', []) as $element)
                                            <div class="rounded-lg bg-slate-50 p-2"><strong>[{{ $element['ref'] ?: '?' }}] {{ $element['role'] }}</strong><span class="mt-0.5 block break-words text-slate-600">{{ $element['text'] ?: $element['selector'] }}</span></div>
                                        @empty
                                            <p class="text-slate-500">Die DOM-Karte erscheint nach der ersten Beobachtung.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </aside>
                        </div>
                    @endif
                </div>
            </x-slot>
            <x-slot name="footer">
                @if($activeCopilotSession)
                    <button type="button" wire:click="downloadCopilotOptimizationLog" wire:loading.attr="disabled" wire:target="downloadCopilotOptimizationLog" class="rounded-md border border-cyan-300 bg-white px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50 disabled:opacity-50">Komplettes Optimierungslog exportieren</button>
                    <button type="button" wire:click="openCopilotChat" class="rounded-md bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Copilot-Chat oeffnen</button>
                    <button type="button" wire:click="restartCopilotOptimization" wire:confirm="Copilot-Optimierung vollstaendig neu starten? Der aktuelle Testlauf wird beendet und die Budgets werden zurueckgesetzt. Bereits ausgeloeste externe Wirkungen bleiben bestehen." wire:loading.attr="disabled" wire:target="restartCopilotOptimization" class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50">Optimierung neu starten</button>
                    @if(data_get($copilotStatus, 'active'))
                        @if(data_get($copilotStatus, 'paused'))
                            <button type="button" wire:click="resumeCopilotOptimization" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Fortsetzen</button>
                        @else
                            <button type="button" wire:click="pauseCopilotOptimization" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100">Pausieren</button>
                        @endif
                        <button type="button" wire:click="stopCopilotOptimization" wire:confirm="Autonome Workflow-Optimierung wirklich stoppen?" class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Stoppen</button>
                        <button type="button" wire:click="terminateCopilotOptimization" wire:confirm="Copilot wirklich beenden und alle Node-Prozesse seiner Testlaeufe erzwungen schliessen?" class="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">Copilot beenden</button>
                    @elseif(in_array(data_get($copilotStatus, 'status'), ['budget_exhausted', 'failed'], true))
                        <button type="button" wire:click="stopCopilotOptimization" wire:confirm="Sitzung beenden und Workflow-Lock freigeben? Die letzte Revision bleibt unverifiziert gespeichert." class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Sitzung beenden und Lock freigeben</button>
                        <button type="button" wire:click="terminateCopilotOptimization" wire:confirm="Sitzung, Testlaeufe und alle zugeordneten Node-Prozesse erzwungen beenden?" class="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">Alles beenden</button>
                    @endif
                @endif
                @if($previewWorkflowRun && $previewWorkflowRun->status === 'queued')
                    <button type="button" wire:click="deleteQueuedPreviewWorkflowRun" wire:confirm="Eingeplanten Workflow-Test wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Loeschen
                    </button>
                @elseif($previewWorkflowRun && in_array($previewWorkflowRun->status, ['running', 'waiting'], true))
                    @if(! $activeCopilotSession)
                        <button type="button" wire:click="pausePreviewWorkflowRun" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm hover:bg-amber-100">
                            Pausieren
                        </button>
                    @endif
                    <button type="button" wire:click="cancelPreviewWorkflowRun" wire:confirm="Workflow-Test wirklich stoppen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Stoppen
                    </button>
                @elseif($previewWorkflowRun && $previewWorkflowRun->status === 'paused' && ! $activeCopilotSession)
                    <button type="button" wire:click="resumePreviewWorkflowRun" wire:loading.attr="disabled" wire:target="resumePreviewWorkflowRun" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50">
                        {{ $manualResumeCursor !== '' ? 'Ab Task fortsetzen' : 'Fortsetzen' }}
                    </button>
                    <button type="button" wire:click="cancelPreviewWorkflowRun" wire:confirm="Pausierten Workflow-Test wirklich stoppen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Stoppen
                    </button>
                @endif
                @if($previewWorkflowRun)
                    <button type="button" wire:click="terminatePreviewWorkflowRun" wire:confirm="Diesen Testlauf wirklich beenden und seinen vollstaendigen Node-Prozessbaum erzwungen schliessen?" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-600">
                        Test + Node-Prozesse beenden
                    </button>
                @endif
                <button type="button" wire:click="closeRunPreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Schliessen
                </button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showCopilotRunsModal" maxWidth="7xl" :interactive-aside="true">
            <x-slot name="title">Copilot-Optimierungslaeufe dieses Workflows</x-slot>
            <x-slot name="content">
                @if($showCopilotRunsModal && $selectedWorkflow)
                    @livewire('admin.network.workflow-copilot-runs', ['workflowId' => $selectedWorkflow->id], key('workflow-copilot-runs-workflow-'.$selectedWorkflow->id))
                @endif
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Schliessen</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showAddStepModal" maxWidth="2xl">
            <x-slot name="title">Liste / Aufgabe hinzufuegen</x-slot>
            <x-slot name="content">
                <div class="space-y-4">
                    <div class="grid gap-2 rounded-lg bg-slate-100 p-1 sm:grid-cols-2">
                        <button
                            type="button"
                            wire:click="$set('newStepCreationMode', 'new')"
                            class="rounded-md px-3 py-2 text-sm font-semibold transition {{ $newStepCreationMode === 'new' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/70 hover:text-slate-900' }}"
                        >
                            Neue Liste
                        </button>
                        <button
                            type="button"
                            wire:click="$set('newStepCreationMode', 'import')"
                            class="rounded-md px-3 py-2 text-sm font-semibold transition {{ $newStepCreationMode === 'import' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:bg-white/70 hover:text-slate-900' }}"
                        >
                            Workflow importieren
                        </button>
                    </div>

                    @if($newStepCreationMode === 'import')
                        <div>
                            <label for="workflow-import-source" class="block text-sm font-medium text-gray-700">Workflow</label>
                            <select id="workflow-import-source" wire:model.defer="importWorkflowId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Workflow auswaehlen</option>
                                @foreach($importableWorkflows as $importableWorkflow)
                                    <option value="{{ $importableWorkflow['id'] }}">
                                        {{ $importableWorkflow['name'] }} ({{ $importableWorkflow['steps_count'] }} Listen, {{ $importableWorkflow['task_cards'] }} Tasks{{ $importableWorkflow['is_active'] ? '' : ', inaktiv' }})
                                    </option>
                                @endforeach
                            </select>
                            @error('importWorkflowId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            @if($importableWorkflows === [])
                                <div class="mt-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-3 text-sm text-slate-500">
                                    Keine importierbaren Workflows verfuegbar.
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="grid gacontainer md:grid-cols-2">
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
                    @endif
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                @if($newStepCreationMode === 'import')
                    <button type="button" wire:click="importWorkflowSteps" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Importieren</button>
                @else
                    <button type="button" wire:click="addStep" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Hinzufuegen</button>
                @endif
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showEditStepModal" maxWidth="2xl">
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
                    <div class="grid gacontainer md:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                            <input type="checkbox" wire:model.defer="editingStepEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                            Aktiv
                        </label>
                        <div>
                            <label for="workflow-edit-step-wait" class="block text-sm font-medium text-gray-700">Pause danach</label>
                            <input id="workflow-edit-step-wait" type="number" min="0" max="3600" wire:model.defer="editingStepWaitAfterSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="grid gacontainer md:grid-cols-2">
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
                    <div class="grid gacontainer md:grid-cols-2">
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
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showAddTaskModal" maxWidth="3xl">
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
                <button
                    type="button"
                    x-on:click.prevent="
                        const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;newTask&quot;]')?.value || 'person';
                        $wire.addTaskCard(source);
                    "
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                >Hinzufuegen</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
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
                <button
                    type="button"
                    x-on:click.prevent="
                        const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;editingTask&quot;]')?.value || 'person';
                        $wire.saveEditTaskCard(source);
                    "
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
                >Speichern</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showRevisionHistoryModal" maxWidth="6xl">
            <x-slot name="title">
                <div>
                    <span class="text-base font-semibold text-slate-950">Workflow-Revisionen</span>
                    <p class="mt-1 text-xs font-normal text-slate-500">Stände einsehen, vergleichen und als neue aktuelle Revision wiederherstellen.</p>
                </div>
            </x-slot>
            <x-slot name="content">
                @if($revisionStudioSessionId)
                    <livewire:admin.network.workflow-revision-history
                        :workflow-id="$selectedWorkflow->id"
                        :studio-session-id="$revisionStudioSessionId"
                        :key="'manager-workflow-revisions-'.$selectedWorkflow->id.'-'.$revisionStudioSessionId"
                    />
                @else
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">Der Revisionsverlauf wird vorbereitet.</div>
                @endif
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Schließen</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showActionLibraryModal" maxWidth="5xl">
            <x-slot name="title">Aktionsbibliothek</x-slot>
            <x-slot name="content">
                <div class="grid gacontainer md:grid-cols-2">
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
        </x-ui.dialog-modal>
    @endif
</div>
