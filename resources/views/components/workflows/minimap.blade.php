@props([
    'workflowRun',
    'activeStepId' => null,
    'activeTaskKey' => null,
    'compact' => false,
    'showHeader' => true,
])

@php
    $workflow = $workflowRun?->workflow;
    $steps = collect($workflow?->steps ?? [])->values();
    $stepRuns = collect($workflowRun?->stepRuns ?? [])->values();
    $runningStepRun = $stepRuns->first(fn ($stepRun) => in_array($stepRun->status, ['running', 'waiting'], true));
    $activeStepId = $activeStepId ?: ($workflowRun?->current_workflow_step_id ?: $runningStepRun?->workflow_step_id);
    $activeTaskKey = trim((string) ($activeTaskKey ?: data_get($workflowRun?->context_json, 'next_task_key', '')));
    $stepRunByStep = $stepRuns->groupBy('workflow_step_id')->map(fn ($runs) => $runs->last());
    $taskResultsByStep = $stepRuns
        ->groupBy('workflow_step_id')
        ->map(function ($runs) {
            $results = collect();

            foreach ($runs as $run) {
                foreach ((array) data_get($run?->result_json, 'tasks', []) as $taskResult) {
                    if (is_array($taskResult) && trim((string) data_get($taskResult, 'key', '')) !== '') {
                        $results->put((string) data_get($taskResult, 'key'), $taskResult);
                    }
                }
            }

            return $results;
        });
    $stepById = $steps->keyBy('id');
    $stepByAction = $steps->keyBy(fn ($step) => (string) $step->action_key);
    $actionKeyForTask = static function (string $taskKey) use ($steps): string {
        if ($taskKey === '') {
            return '';
        }

        foreach ($steps as $step) {
            foreach (collect($step->task_cards)->values() as $task) {
                if ((string) ($task['key'] ?? '') === $taskKey) {
                    return trim((string) $step->action_key);
                }
            }
        }

        return '';
    };
    $nodePositions = [];
    $firstTaskNodeByStep = [];
    $taskLabelByNode = [];

    foreach ($steps as $stepIndex => $step) {
        $actionKey = trim((string) $step->action_key);

        if ($actionKey === '') {
            continue;
        }

        $stepNode = $actionKey.'::*';
        $nodePositions[$stepNode] = ['step' => $stepIndex, 'task' => -1];
        $taskLabelByNode[$stepNode] = $step->name;

        foreach (collect($step->task_cards)->values() as $taskIndex => $task) {
            $taskKey = trim((string) ($task['key'] ?? ''));

            if ($taskKey === '') {
                continue;
            }

            $node = $actionKey.'::'.$taskKey;
            $nodePositions[$node] = ['step' => $stepIndex, 'task' => $taskIndex];
            $taskLabelByNode[$node] = $step->name.' / '.(string) ($task['title'] ?? $taskKey);
            $firstTaskNodeByStep[$actionKey] ??= $node;
        }
    }

    $routeDirection = static function (string $sourceNode, string $targetNode, string $type) use ($nodePositions): string {
        if (in_array($type, ['end', 'fail'], true)) {
            return $type;
        }

        if ($sourceNode === '' || $targetNode === '' || ! isset($nodePositions[$sourceNode], $nodePositions[$targetNode])) {
            return 'route';
        }

        $source = $nodePositions[$sourceNode];
        $target = $nodePositions[$targetNode];

        if ($sourceNode === $targetNode) {
            return 'loop';
        }

        if ($target['step'] < $source['step'] || ($target['step'] === $source['step'] && $target['task'] <= $source['task'])) {
            return 'back';
        }

        return 'forward';
    };
    $routeEvents = collect(data_get($workflowRun?->context_json, 'route_history', []))
        ->filter(fn ($event) => is_array($event) && is_array(data_get($event, 'route')))
        ->map(function (array $event, int $index) use ($stepById, $stepByAction, $firstTaskNodeByStep, $taskLabelByNode, $routeDirection) {
            $route = data_get($event, 'route', []);
            $outcome = (string) data_get($event, 'outcome', '-');
            $routeType = trim((string) data_get($route, 'type', 'step')) ?: 'step';
            $sourceStep = $stepById->get((int) data_get($event, 'workflow_step_id'));
            $sourceAction = trim((string) ($sourceStep?->action_key ?? ''));
            $sourceCard = trim((string) data_get($route, '_source_card_key', ''));
            $sourceNode = $sourceAction !== '' ? $sourceAction.'::'.($sourceCard !== '' ? $sourceCard : '*') : '';
            $targetAction = trim((string) data_get($route, 'action_key', data_get($route, 'step', '')));
            $targetCard = trim((string) data_get($route, 'card_key', data_get($route, 'card', '')));
            $targetNode = '';

            if (! in_array($routeType, ['end', 'fail'], true) && ! in_array($targetAction, ['', 'next', 'end', 'fail'], true)) {
                $targetNode = $targetAction.'::'.($targetCard !== '' ? $targetCard : '*');

                if ($targetCard === '' && isset($firstTaskNodeByStep[$targetAction])) {
                    $targetNode = $firstTaskNodeByStep[$targetAction];
                }
            }

            $direction = $routeDirection($sourceNode, $targetNode, $routeType);
            $targetStep = $stepByAction->get($targetAction);
            $directionLabel = match ($direction) {
                'back' => 'Zuruecksprung',
                'forward' => 'Weiterlauf',
                'loop' => 'Schleife',
                'end' => 'Ende',
                'fail' => 'Abbruch',
                default => 'Route',
            };
            $lineTone = match ($outcome) {
                'success' => 'success',
                'failed', 'timeout' => 'failed',
                'partial', 'waiting' => 'waiting',
                default => 'default',
            };

            return [
                'id' => 'route-'.$index,
                'at' => (string) data_get($event, 'at', ''),
                'outcome' => $outcome,
                'type' => $routeType,
                'direction' => $direction,
                'directionLabel' => $directionLabel,
                'lineTone' => $lineTone,
                'sourceNode' => $sourceNode,
                'targetNode' => $targetNode,
                'sourceLabel' => $taskLabelByNode[$sourceNode] ?? ($sourceStep?->name ?? '-'),
                'targetLabel' => $targetNode !== ''
                    ? ($taskLabelByNode[$targetNode] ?? ($targetStep?->name ?? '-'))
                    : (string) data_get($route, 'label', $routeType),
                'routeLabel' => (string) data_get($route, 'label', data_get($route, 'action_key', data_get($route, 'type', '-'))),
            ];
        })
        ->filter()
        ->values();
    $pendingRouteTargetCard = trim((string) data_get($workflowRun?->context_json, 'next_task_key', ''));
    $pendingRouteOutcome = trim((string) data_get($workflowRun?->context_json, 'next_task_route_outcome', ''));
    $pendingRouteSourceCard = trim((string) data_get($workflowRun?->context_json, 'next_task_route_source_key', ''));
    $pendingRouteTargetAction = trim((string) data_get($workflowRun?->context_json, 'next_step_action_key', ''));
    $pendingRouteSourceAction = $actionKeyForTask($pendingRouteSourceCard);

    if ($pendingRouteTargetAction === '') {
        $pendingRouteTargetAction = $actionKeyForTask($pendingRouteTargetCard);
    }

    if ($pendingRouteSourceAction === '') {
        $pendingRouteSourceAction = $pendingRouteTargetAction;
    }

    if ($pendingRouteTargetCard !== '' && $pendingRouteOutcome !== '' && $pendingRouteTargetAction !== '') {
        $sourceNode = $pendingRouteSourceAction !== '' && $pendingRouteSourceCard !== ''
            ? $pendingRouteSourceAction.'::'.$pendingRouteSourceCard
            : '';
        $targetNode = $pendingRouteTargetAction.'::'.$pendingRouteTargetCard;
        $direction = $routeDirection($sourceNode, $targetNode, 'card');
        $pendingRouteEvent = [
            'id' => 'route-pending',
            'at' => '',
            'outcome' => $pendingRouteOutcome,
            'type' => 'card',
            'direction' => $direction,
            'directionLabel' => match ($direction) {
                'back' => 'Aktiver Ruecksprung',
                'forward' => 'Aktiver Weiterlauf',
                'loop' => 'Aktive Schleife',
                default => 'Aktive Route',
            },
            'lineTone' => match ($pendingRouteOutcome) {
                'success' => 'success',
                'failed', 'timeout' => 'failed',
                'partial', 'waiting' => 'waiting',
                default => 'default',
            },
            'sourceNode' => $sourceNode,
            'targetNode' => $targetNode,
            'sourceLabel' => $taskLabelByNode[$sourceNode] ?? $pendingRouteSourceCard,
            'targetLabel' => $taskLabelByNode[$targetNode] ?? $pendingRouteTargetCard,
            'routeLabel' => 'naechster Task: '.$pendingRouteTargetCard,
            'pending' => true,
        ];
        $pendingRouteExists = $routeEvents->contains(fn (array $event): bool => ($event['sourceNode'] ?? '') === $sourceNode
            && ($event['targetNode'] ?? '') === $targetNode
            && ($event['outcome'] ?? '') === $pendingRouteOutcome);

        if ($pendingRouteExists) {
            $routeEvents = $routeEvents
                ->map(fn (array $event): array => (($event['sourceNode'] ?? '') === $sourceNode
                    && ($event['targetNode'] ?? '') === $targetNode
                    && ($event['outcome'] ?? '') === $pendingRouteOutcome)
                        ? array_merge($event, ['pending' => true, 'directionLabel' => $pendingRouteEvent['directionLabel'], 'routeLabel' => $pendingRouteEvent['routeLabel']])
                        : $event)
                ->values();
        } else {
            $routeEvents->push($pendingRouteEvent);
        }
    }
    $routeBadgesByNode = [];

    foreach ($routeEvents->slice(max(0, $routeEvents->count() - 8))->values() as $routeEvent) {
        $isPending = (bool) ($routeEvent['pending'] ?? false);
        $tone = (string) ($routeEvent['lineTone'] ?? 'default');

        if (($routeEvent['sourceNode'] ?? '') !== '') {
            $routeBadgesByNode[(string) $routeEvent['sourceNode']] = [
                'label' => $isPending ? 'Quelle aktiv' : 'Quelle',
                'tone' => $tone,
            ];
        }

        if (($routeEvent['targetNode'] ?? '') !== '') {
            $routeBadgesByNode[(string) $routeEvent['targetNode']] = [
                'label' => $isPending ? 'Ziel aktiv' : 'Ziel',
                'tone' => $tone,
            ];
        }
    }

    $routeEventsForJs = $routeEvents->take(-16)->values()->all();
    $mapId = 'workflow-minimap-'.($workflowRun?->id ?? 'preview');
    $activeStep = $stepById->get((int) $activeStepId);
    $activeStepAction = trim((string) ($activeStep?->action_key ?? ''));
    $activeTaskAction = $activeTaskKey !== '' ? $actionKeyForTask($activeTaskKey) : '';
    $activeRouteAction = $activeTaskAction !== '' ? $activeTaskAction : $activeStepAction;
    $activeRouteNode = $activeRouteAction !== ''
        ? $activeRouteAction.'::'.($activeTaskKey !== '' ? $activeTaskKey : '*')
        : '';
    $taskTone = static function (string $status, bool $active): string {
        return match (true) {
            $active || in_array($status, ['running', 'waiting'], true) => 'border-amber-300 bg-amber-50 text-amber-900 shadow-amber-100',
            $status === 'completed' || $status === 'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900 shadow-emerald-100',
            $status === 'skipped' || $status === 'not_executed' => 'border-slate-200 bg-slate-50 text-slate-500 shadow-slate-100',
            in_array($status, ['failed', 'timeout'], true) => 'border-red-300 bg-red-50 text-red-900 shadow-red-100',
            default => 'border-slate-200 bg-white text-slate-600 shadow-slate-100',
        };
    };
    $connectorTone = static function (string $status, bool $active): string {
        return match (true) {
            $active || in_array($status, ['running', 'waiting'], true) => 'bg-amber-300 text-amber-400',
            $status === 'completed' || $status === 'success' => 'bg-emerald-300 text-emerald-400',
            $status === 'skipped' || $status === 'not_executed' => 'bg-slate-200 text-slate-300',
            in_array($status, ['failed', 'timeout'], true) => 'bg-red-300 text-red-400',
            default => 'bg-slate-200 text-slate-300',
        };
    };
    $routeChipClass = static function (array $routeEvent): string {
        return match ($routeEvent['lineTone'] ?? 'default') {
            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'failed' => 'bg-red-50 text-red-700 ring-red-200',
            'waiting' => 'bg-amber-50 text-amber-700 ring-amber-200',
            default => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    };
    $routeBadgeClass = static function (array $badge): string {
        return match ($badge['tone'] ?? 'default') {
            'success' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
            'failed' => 'bg-red-100 text-red-700 ring-red-200',
            'waiting' => 'bg-amber-100 text-amber-700 ring-amber-200',
            default => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    };
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @if(! $workflowRun || ! $workflow)
        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Keine Workflow-Daten fuer diesen Prozess gefunden.
        </div>
    @else
        @if($showHeader)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ $workflow->name }}</div>
                    <div class="mt-1 truncate text-xs text-slate-500">Run #{{ $workflowRun->id }} - {{ $workflowRun->status }}</div>
                </div>
                <x-workflows.status-badge :status="$workflowRun->status" />
            </div>
        @endif

        <div
            x-data="{
                routeEvents: @js($routeEventsForJs),
                routeOverlay: { width: 0, height: 0 },
                routeSvgMarkup: '',
                routeLines: [],
                hoveredRouteNode: '',
                activeRouteNode: @js($activeRouteNode),
                markerIds: {
                    success: @js($mapId.'-arrow-success'),
                    failed: @js($mapId.'-arrow-failed'),
                    waiting: @js($mapId.'-arrow-waiting'),
                    default: @js($mapId.'-arrow-default'),
                },
                init() {
                    this.$nextTick(() => this.refreshRouteLines());
                    setTimeout(() => this.refreshRouteLines(), 150);
                    setTimeout(() => this.refreshRouteLines(), 600);
                    this._refreshMinimapRoutes = () => this.$nextTick(() => this.refreshRouteLines());
                    window.addEventListener('resize', this._refreshMinimapRoutes);
                    document.addEventListener('livewire:updated', this._refreshMinimapRoutes);
                    document.addEventListener('livewire:navigated', this._refreshMinimapRoutes);
                },
                destroy() {
                    window.removeEventListener('resize', this._refreshMinimapRoutes);
                    document.removeEventListener('livewire:updated', this._refreshMinimapRoutes);
                    document.removeEventListener('livewire:navigated', this._refreshMinimapRoutes);
                },
                routeFocusNode() {
                    return this.hoveredRouteNode || this.activeRouteNode || '';
                },
                routeFocusBelongsToStep(actionKey) {
                    const focusNode = this.routeFocusNode();

                    return focusNode !== '' && focusNode.startsWith(`${String(actionKey || '')}::`);
                },
                setHoveredRouteNode(node = '') {
                    this.hoveredRouteNode = String(node || '');
                    this.renderRouteLines();
                },
                renderRouteLines() {
                    const focusNode = this.routeFocusNode();
                    const hasRelatedLine = focusNode !== '' && this.routeLines.some((line) => line.sourceNode === focusNode || line.targetNode === focusNode);

                    this.routeSvgMarkup = this.routeLines.map((line) => {
                        const color = {
                            success: '#34d399',
                            failed: '#f87171',
                            waiting: '#f59e0b',
                            default: '#94a3b8',
                        }[line.tone] || '#94a3b8';
                        const marker = this.markerIds[line.tone] || this.markerIds.default;
                        const dash = line.tone === 'failed' || line.direction === 'back' ? ' stroke-dasharray=&quot;6 5&quot;' : '';
                        const related = !hasRelatedLine || line.sourceNode === focusNode || line.targetNode === focusNode;
                        const opacity = hasRelatedLine ? (related ? 1 : 0.5) : 0.92;
                        const strokeWidth = related && hasRelatedLine ? (line.pending ? 4.4 : 3.6) : (line.pending ? 3.5 : 2.5);
                        const filter = related && hasRelatedLine ? ' style=&quot;filter:drop-shadow(0 0 2px rgba(15,23,42,.24))&quot;' : '';
                        const path = String(line.path || '').replace(/&/g, '&amp;').replace(/&quot;/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                        return `<path d=&quot;${path}&quot; fill=&quot;none&quot; stroke-width=&quot;${strokeWidth}&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot; stroke=&quot;${color}&quot; opacity=&quot;${opacity}&quot;${dash}${filter} marker-end=&quot;url(#${marker})&quot;></path>`;
                    }).join('');
                },
                refreshRouteLines() {
                    const surface = this.$refs.minimapSurface;

                    if (!surface) {
                        this.routeOverlay = { width: 0, height: 0 };
                        this.routeSvgMarkup = '';
                        return;
                    }

                    const surfaceRect = surface.getBoundingClientRect();
                    const nodeElements = Array.from(surface.querySelectorAll('[data-minimap-node]'));
                    const nodes = new Map(nodeElements.map((node) => [node.dataset.minimapNode || '', node]));
                    const stepColumns = Array.from(surface.querySelectorAll('[data-minimap-step-column]'));
                    const stepIndexes = new Map(stepColumns.map((column, index) => [column, index]));
                    const relativeRect = (element) => {
                        const rect = element.getBoundingClientRect();

                        return {
                            left: rect.left - surfaceRect.left + surface.scrollLeft,
                            right: rect.right - surfaceRect.left + surface.scrollLeft,
                            top: rect.top - surfaceRect.top + surface.scrollTop,
                            bottom: rect.bottom - surfaceRect.top + surface.scrollTop,
                            centerX: rect.left + (rect.width / 2) - surfaceRect.left + surface.scrollLeft,
                            centerY: rect.top + (rect.height / 2) - surfaceRect.top + surface.scrollTop,
                        };
                    };
                    const roundedPath = (points, radius = 10) => {
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
                            const incoming = Math.max(1, Math.hypot(current.x - previous.x, current.y - previous.y));
                            const outgoing = Math.max(1, Math.hypot(next.x - current.x, next.y - current.y));
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
                    const lineFor = (routeEvent, index) => {
                        const source = nodes.get(routeEvent.sourceNode || '');
                        const target = nodes.get(routeEvent.targetNode || '');

                        if (!source || !target) {
                            return null;
                        }

                        const sourceRect = relativeRect(source);
                        const targetRect = relativeRect(target);
                        const sourceColumn = source.closest('[data-minimap-step-column]');
                        const targetColumn = target.closest('[data-minimap-step-column]');
                        const sourceColumnRect = sourceColumn ? relativeRect(sourceColumn) : sourceRect;
                        const targetColumnRect = targetColumn ? relativeRect(targetColumn) : targetRect;
                        const sourceStepIndex = stepIndexes.get(sourceColumn) ?? -1;
                        const targetStepIndex = stepIndexes.get(targetColumn) ?? -1;
                        const lane = 12 + ((index % 4) * 6);
                        const tone = routeEvent.lineTone || 'default';
                        const sourceNode = routeEvent.sourceNode || '';
                        const targetNode = routeEvent.targetNode || '';
                        const lineResult = (points, radius = 10) => ({
                            path: roundedPath(points, radius),
                            tone,
                            direction: routeEvent.direction || 'route',
                            pending: !!routeEvent.pending,
                            sourceNode,
                            targetNode,
                        });
                        let points = [];

                        if (source === target) {
                            const loopX = sourceRect.right + lane;
                            points = [
                                { x: sourceRect.right, y: sourceRect.centerY - 5 },
                                { x: loopX, y: sourceRect.centerY - 5 },
                                { x: loopX, y: sourceRect.centerY + 16 },
                                { x: sourceRect.right, y: sourceRect.centerY + 16 },
                            ];

                            return lineResult(points, 8);
                        }

                        if (sourceColumn && sourceColumn === targetColumn) {
                            const sideX = Math.max(sourceRect.right, targetRect.right) + lane;
                            points = [
                                { x: sourceRect.right, y: sourceRect.centerY },
                                { x: sideX, y: sourceRect.centerY },
                                { x: sideX, y: targetRect.centerY },
                                { x: targetRect.right, y: targetRect.centerY },
                            ];

                            return lineResult(points);
                        }

                        const goesBack = targetStepIndex < sourceStepIndex || targetRect.centerX < sourceRect.centerX;
                        const sourceX = goesBack ? sourceRect.left : sourceRect.right;
                        const targetX = goesBack ? targetRect.right : targetRect.left;
                        const adjacentSteps = sourceStepIndex >= 0
                            && targetStepIndex >= 0
                            && Math.abs(sourceStepIndex - targetStepIndex) === 1;

                        if (adjacentSteps) {
                            const gapLeft = goesBack ? targetColumnRect.right : sourceColumnRect.right;
                            const gapRight = goesBack ? sourceColumnRect.left : targetColumnRect.left;
                            const gapOffset = ((index % 5) - 2) * 2;
                            const gapX = Math.max(gapLeft + 5, Math.min(gapRight - 5, ((gapLeft + gapRight) / 2) + gapOffset));
                            points = [
                                { x: sourceX, y: sourceRect.centerY },
                                { x: gapX, y: sourceRect.centerY },
                                { x: gapX, y: targetRect.centerY },
                                { x: targetX, y: targetRect.centerY },
                            ];

                            return lineResult(points);
                        }

                        const firstStepIndex = Math.min(sourceStepIndex, targetStepIndex);
                        const lastStepIndex = Math.max(sourceStepIndex, targetStepIndex);
                        const involvedRects = nodeElements
                            .filter((node) => {
                                const columnIndex = stepIndexes.get(node.closest('[data-minimap-step-column]')) ?? -1;

                                return columnIndex >= firstStepIndex && columnIndex <= lastStepIndex;
                            })
                            .map(relativeRect);
                        const upperY = Math.max(6, Math.min(...involvedRects.map((rect) => rect.top), sourceRect.top, targetRect.top) - lane);
                        const lowerY = Math.min(
                            surface.scrollHeight - 6,
                            Math.max(...involvedRects.map((rect) => rect.bottom), sourceRect.bottom, targetRect.bottom) + lane,
                        );
                        const upperCost = Math.abs(sourceRect.centerY - upperY) + Math.abs(targetRect.centerY - upperY);
                        const lowerCost = Math.abs(sourceRect.centerY - lowerY) + Math.abs(targetRect.centerY - lowerY);
                        const corridorY = lowerCost < upperCost ? lowerY : upperY;
                        const sourceLaneX = sourceX + (goesBack ? -lane : lane);
                        const targetLaneX = targetX + (goesBack ? lane : -lane);
                        points = [
                            { x: sourceX, y: sourceRect.centerY },
                            { x: sourceLaneX, y: sourceRect.centerY },
                            { x: sourceLaneX, y: corridorY },
                            { x: targetLaneX, y: corridorY },
                            { x: targetLaneX, y: targetRect.centerY },
                            { x: targetX, y: targetRect.centerY },
                        ];

                        return lineResult(points);
                    };
                    const lines = this.routeEvents
                        .map((routeEvent, index) => lineFor(routeEvent, index))
                        .filter(Boolean);

                    this.routeOverlay = {
                        width: surface.scrollWidth,
                        height: surface.scrollHeight,
                    };
                    this.routeLines = lines;
                    this.renderRouteLines();
                },
            }"
            x-ref="minimapSurface"
            x-on:scroll.debounce.100ms="refreshRouteLines()"
            data-workflow-minimap-scroll-container
            data-workflow-preview-scrollbar
            class="relative overflow-x-auto pb-2"
        >
            <svg
                class="pointer-events-none absolute left-0 top-0 z-20"
                x-bind:width="routeOverlay.width"
                x-bind:height="routeOverlay.height"
                x-bind:viewBox="`0 0 ${routeOverlay.width} ${routeOverlay.height}`"
                aria-hidden="true"
            >
                <defs>
                    <marker id="{{ $mapId }}-arrow-success" markerWidth="6" markerHeight="6" refX="5.5" refY="3" orient="auto" markerUnits="userSpaceOnUse">
                        <path d="M0,0 L0,6 L6,3 z" fill="#34d399"></path>
                    </marker>
                    <marker id="{{ $mapId }}-arrow-failed" markerWidth="6" markerHeight="6" refX="5.5" refY="3" orient="auto" markerUnits="userSpaceOnUse">
                        <path d="M0,0 L0,6 L6,3 z" fill="#f87171"></path>
                    </marker>
                    <marker id="{{ $mapId }}-arrow-waiting" markerWidth="6" markerHeight="6" refX="5.5" refY="3" orient="auto" markerUnits="userSpaceOnUse">
                        <path d="M0,0 L0,6 L6,3 z" fill="#f59e0b"></path>
                    </marker>
                    <marker id="{{ $mapId }}-arrow-default" markerWidth="6" markerHeight="6" refX="5.5" refY="3" orient="auto" markerUnits="userSpaceOnUse">
                        <path d="M0,0 L0,6 L6,3 z" fill="#94a3b8"></path>
                    </marker>
                </defs>
                <g x-html="routeSvgMarkup"></g>
            </svg>

            <div class="relative z-10 flex min-w-max items-start gap-0 pt-7">
                @foreach($steps as $step)
                    @php
                        $stepRun = $stepRunByStep->get($step->id);
                        $isActiveStep = (int) $activeStepId === (int) $step->id;
                        $stepStatus = (string) ($stepRun?->status ?? 'configured');
                        $tasks = collect($step->task_cards)->values();
                        $resultTasks = $taskResultsByStep->get($step->id, collect());
                        $plannedOnlyStep = $step->type === \App\Models\WorkflowStep::TYPE_PLANNED_ACTION && trim((string) ($stepRun?->external_run_id ?? '')) === '';
                        $stepTone = $taskTone($stepStatus, $isActiveStep);
                        $stepNode = trim((string) $step->action_key).'::*';
                        $stepRouteBadge = $routeBadgesByNode[$stepNode] ?? null;
                    @endphp

                    <div class="flex items-start">
                        <div class="w-56 shrink-0" data-minimap-step-column="{{ $step->action_key }}">
                            <div
                                data-minimap-node="{{ $stepNode }}"
                                data-workflow-minimap-active-step="{{ $isActiveStep ? 'true' : 'false' }}"
                                x-on:mouseenter="setHoveredRouteNode(@js($stepNode))"
                                x-on:mouseleave="setHoveredRouteNode('')"
                                class="mb-2 flex items-center justify-between gap-2 rounded px-1 py-1"
                            >
                                <div class="truncate text-xs font-semibold text-slate-800">{{ $step->name }}</div>
                                @if($stepRun?->status)
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $stepTone }}">
                                        {{ $stepRun->status }}
                                    </span>
                                @elseif($stepRouteBadge)
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $routeBadgeClass($stepRouteBadge) }}">
                                        {{ $stepRouteBadge['label'] }}
                                    </span>
                                @endif
                            </div>

                            <div class="space-y-0">
                                @forelse($tasks as $task)
                                    @php
                                        $taskKey = (string) ($task['key'] ?? '');
                                        $taskResult = $resultTasks->get($taskKey);
                                        $taskStatus = $plannedOnlyStep
                                            ? 'not_executed'
                                            : (string) data_get($taskResult, 'status', data_get($task, 'status', 'configured'));
                                        $isTaskActive = $isActiveStep && ($activeTaskKey === '' ? ($loop->first && in_array($stepStatus, ['running', 'waiting'], true)) : $taskKey === $activeTaskKey);
                                        $tone = $taskTone($taskStatus, $isTaskActive);
                                        $lineTone = $connectorTone($taskStatus, $isTaskActive);
                                        $taskNode = trim((string) $step->action_key).'::'.$taskKey;
                                        $previousTask = $loop->first ? null : $tasks->get($loop->index - 1);
                                        $previousTaskNode = is_array($previousTask)
                                            ? trim((string) $step->action_key).'::'.trim((string) ($previousTask['key'] ?? ''))
                                            : '';
                                        $taskRouteBadge = $routeBadgesByNode[$taskNode] ?? null;
                                    @endphp

                                    @if(! $loop->first)
                                        <div
                                            class="ml-4 h-4 transition-all {{ $lineTone }}"
                                            x-bind:class="{
                                                'opacity-50': routeFocusNode() && ![@js($previousTaskNode), @js($taskNode)].includes(routeFocusNode()),
                                                'opacity-100 w-0.5': routeFocusNode() && [@js($previousTaskNode), @js($taskNode)].includes(routeFocusNode()),
                                                'w-px': !routeFocusNode() || ![@js($previousTaskNode), @js($taskNode)].includes(routeFocusNode()),
                                            }"
                                        ></div>
                                    @endif

                                    <div
                                        data-minimap-node="{{ $taskNode }}"
                                        data-workflow-minimap-active-target="{{ $isTaskActive ? 'true' : 'false' }}"
                                        x-on:mouseenter="setHoveredRouteNode(@js($taskNode))"
                                        x-on:mouseleave="setHoveredRouteNode('')"
                                        class="relative rounded-md border px-2 py-1.5 text-[11px] shadow-sm {{ $tone }}"
                                    >
                                        @if($taskRouteBadge)
                                            <span class="absolute right-1 top-1 rounded-full px-1.5 py-0.5 text-[9px] font-semibold ring-1 {{ $routeBadgeClass($taskRouteBadge) }}">
                                                {{ $taskRouteBadge['label'] }}
                                            </span>
                                        @endif
                                        <div class="truncate {{ $taskRouteBadge ? 'pr-16' : 'pr-2' }} font-semibold">{{ $task['title'] ?? 'Task' }}</div>
                                        <div class="mt-0.5 truncate opacity-70">{{ $taskStatus }}</div>
                                    </div>
                                @empty
                                    <div
                                        data-workflow-minimap-active-target="{{ $isActiveStep ? 'true' : 'false' }}"
                                        class="rounded-md border px-2 py-1.5 text-[11px] shadow-sm {{ $stepTone }}"
                                    >
                                        <div class="truncate font-semibold">{{ $step->type_label }}</div>
                                        <div class="mt-0.5 truncate opacity-70">{{ $stepStatus }}</div>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        @if(! $loop->last)
                            @php
                                $nextStepAction = trim((string) ($steps->get($loop->index + 1)?->action_key ?? ''));
                            @endphp
                            <div
                                class="flex h-20 w-12 shrink-0 items-center px-2 transition-opacity"
                                x-bind:class="routeFocusNode() && !routeFocusBelongsToStep(@js((string) $step->action_key)) && !routeFocusBelongsToStep(@js($nextStepAction)) ? 'opacity-50' : 'opacity-100'"
                            >
                                <div
                                    class="flex-1 transition-all {{ $connectorTone($stepStatus, $isActiveStep) }}"
                                    x-bind:class="routeFocusNode() && (routeFocusBelongsToStep(@js((string) $step->action_key)) || routeFocusBelongsToStep(@js($nextStepAction))) ? 'h-0.5' : 'h-px'"
                                ></div>
                                <div class="h-0 w-0 border-y-4 border-l-8 border-y-transparent {{ in_array($stepStatus, ['failed', 'timeout'], true) ? 'border-l-red-400' : ($isActiveStep || in_array($stepStatus, ['running', 'waiting'], true) ? 'border-l-amber-400' : ($stepStatus === 'completed' ? 'border-l-emerald-400' : 'border-l-slate-300')) }}"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        @if($routeEvents->isNotEmpty())
            <div class="flex flex-wrap gap-1 text-[11px]">
                @foreach($routeEvents->take(-6) as $routeEvent)
                    <span class="rounded-full px-2 py-1 font-semibold ring-1 {{ $routeChipClass($routeEvent) }}">
                        {{ $routeEvent['directionLabel'] }}: {{ $routeEvent['outcome'] }} -> {{ $routeEvent['routeLabel'] }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif
</div>
