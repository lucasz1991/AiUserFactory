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
<div class="space-y-5" wire:loading.class="opacity-60 pointer-events-none">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
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
                                Tests
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition x-on:click.outside="open = false" class="absolute right-0 z-50 mt-2 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-xl">
                                <button type="button" wire:click="$set('showRunModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Neuen Test starten</button>
                                <button type="button" wire:click="openLatestRunPreview" x-on:click="open = false" @disabled(! $quickPreviewRun) class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-40">
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
                <span class="font-semibold">Achtung: Dieser Workflow ist gesperrt.</span> {{ $selectedWorkflow->lock_reason }} Als Admin kannst du ihn trotzdem bearbeiten. Aenderungen koennen laufende oder eingebundene Workflows beeinflussen.
            </div>
        @endif
        <div>
            <div
                x-data="{
                    focusedTask: '',
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
                            const laneIndex = routeLane++;
                            const sourceY = type === 'failed'
                                ? sourceRect.top + (sourceRect.height * 0.68)
                                : sourceRect.top + (sourceRect.height * 0.4);
                            const targetY = targetRect.centerY;
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

                                return { path: roundedPath(points, 7), type };
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

                                return { path: roundedPath(points), type };
                            }

                            const sourceAnchorX = sourceRect.right;
                            const targetAnchorX = targetRect.left;
                            const laneInset = 14 + ((laneIndex % 3) * 5);
                            const sourceLaneX = sourceStepRect.right + laneInset;
                            const targetLaneX = targetStepRect.left - laneInset;
                            const topLaneY = 18 + ((laneIndex % 7) * 7);
                            points = [
                                { x: sourceAnchorX, y: sourceY },
                                { x: sourceLaneX, y: sourceY },
                                { x: sourceLaneX, y: topLaneY },
                                { x: targetLaneX, y: topLaneY },
                                { x: targetLaneX, y: targetY },
                                { x: targetAnchorX, y: targetY },
                            ];

                            return { path: roundedPath(points), type };
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
                            const color = line.type === 'failed' ? '#fb7185' : '#10b981';
                            const marker = line.type === 'failed' ? 'url(#workflow-arrow-red)' : 'url(#workflow-arrow-green)';
                            const dash = line.type === 'failed' ? ' stroke-dasharray=&quot;6 5&quot;' : '';
                            const path = String(line.path || '').replace(/&/g, '&amp;').replace(/&quot;/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                            return `<path d=&quot;${path}&quot; fill=&quot;none&quot; stroke-width=&quot;2.15&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot; stroke=&quot;${color}&quot; stroke-opacity=&quot;0.9&quot;${dash} marker-end=&quot;${marker}&quot;></path>`;
                        }).join('');
                    },
                }"
                x-init="refreshRouteLines()"
                x-on:keydown.escape.window="setFullscreen(false)"
                x-bind:class="isFullscreen ? 'fixed inset-0 z-[60] flex flex-col rounded-none border-0' : 'rounded-xl border border-slate-200'"
                class="overflow-hidden "
            >
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900">{{ $selectedWorkflow->name }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            <span x-show="! isFullscreen">Listen horizontal anordnen, Tasks verschieben oder aus der Bibliothek hineinziehen.</span>
                            <span x-cloak x-show="isFullscreen">Vollbildansicht · Mit Esc beenden.</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <div x-show="showRoutes" class="flex items-center gap-4 text-[11px] font-semibold text-slate-500">
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
                    class="relative isolate overflow-x-auto overflow-y-hidden bg-slate-100/80 scroll-container"
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
            <div x-data="{}" class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 bg-slate-50/80 p-5">
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
                <div class="flex-1 space-y-3 overflow-y-auto p-4">
                    @foreach($visibleTaskDefinitions as $taskDefinition)
                        <div
                            data-workflow-task-catalog-key="{{ $taskDefinition['key'] }}"
                            data-assistant-highlight="workflow_task_catalog:{{ $taskDefinition['key'] }}"
                            data-assistant-highlight-key="{{ $taskDefinition['key'] }}"
                            draggable="true"
                            x-on:dragstart.stop="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                            class="cursor-grab rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-400 hover:shadow-md active:cursor-grabbing"
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
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunModal" maxWidth="xl">
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
                <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">Testen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showRunPreviewModal" maxWidth="7xl">
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
                <button
                    type="button"
                    x-on:click.prevent="
                        const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;newTask&quot;]')?.value || 'person';
                        $wire.addTaskCard(source);
                    "
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                >Hinzufuegen</button>
            </x-slot>
        </x-dialog-modal>

        <x-dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
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
