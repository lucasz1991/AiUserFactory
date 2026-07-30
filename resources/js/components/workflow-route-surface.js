const ROUTE_TONES = {
    success: { color: '#10b981', marker: 'success', dash: '' },
    failed: { color: '#fb7185', marker: 'failed', dash: '6 5' },
    error: { color: '#fb7185', marker: 'failed', dash: '6 5' },
    partial: { color: '#f59e0b', marker: 'partial', dash: '4 4' },
    timeout: { color: '#8b5cf6', marker: 'timeout', dash: '3 4' },
    runtime: { color: '#0ea5e9', marker: 'runtime', dash: '' },
    implicit: { color: '#94a3b8', marker: 'default', dash: '' },
    default: { color: '#94a3b8', marker: 'default', dash: '' },
};

const normalizeOutcome = (value) => {
    const normalized = String(value || 'default').trim().toLowerCase();

    if (normalized.includes('timeout')) return 'timeout';
    if (normalized.includes('partial')) return 'partial';
    if (normalized.includes('fail') || normalized.includes('error')) return 'failed';
    if (normalized.includes('success') || normalized === 'next') return 'success';
    if (normalized.includes('runtime') || normalized.includes('active')) return 'runtime';
    if (normalized.includes('implicit')) return 'implicit';

    return 'default';
};

const escapeAttribute = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

export function workflowRouteSurface(config = {}) {
    return {
        routeInstance: String(config.instance || 'workflow'),
        focusedTask: '',
        hoveredRouteNode: '',
        activeRouteNode: '',
        initialRouteNode: String(config.initialNode || ''),
        showRoutes: true,
        showAllRoutes: true,
        compactRouteMode: false,
        routeLines: [],
        routeOverlay: { width: 0, height: 0 },
        routeSvgMarkup: '',
        _routeResizeObserver: null,
        _routeMedia: null,
        _routeMediaListener: null,
        _routeLivewireCleanup: null,
        _routeFrame: null,
        _routeTimers: [],

        init() {
            this._routeMedia = window.matchMedia('(max-width: 767px)');
            this.compactRouteMode = this._routeMedia.matches;
            this.showAllRoutes = !this.compactRouteMode;
            this._routeMediaListener = (event) => {
                this.compactRouteMode = event.matches;
                this.showAllRoutes = !event.matches;
                this.queueRouteRefresh();
            };
            this._routeMedia.addEventListener?.('change', this._routeMediaListener);

            this.$nextTick(() => {
                const surface = this.$refs.routeSurface;

                if (surface && window.ResizeObserver) {
                    this._routeResizeObserver = new ResizeObserver(() => this.queueRouteRefresh());
                    this._routeResizeObserver.observe(surface);
                }

                this.queueRouteRefresh();
                this._routeTimers.push(window.setTimeout(() => this.queueRouteRefresh(), 120));
                this._routeTimers.push(window.setTimeout(() => this.queueRouteRefresh(), 520));
            });

            this._routeWindowRefresh = () => this.queueRouteRefresh();
            window.addEventListener('resize', this._routeWindowRefresh, { passive: true });
            window.addEventListener('orientationchange', this._routeWindowRefresh, { passive: true });

            if (window.Livewire?.hook) {
                this._routeLivewireCleanup = window.Livewire.hook('morph.updated', ({ el }) => {
                    if (this.$root?.contains(el) || el?.contains?.(this.$root)) {
                        this.queueRouteRefresh();
                    }
                });
            }
        },

        destroy() {
            this._routeResizeObserver?.disconnect();
            this._routeMedia?.removeEventListener?.('change', this._routeMediaListener);
            this._routeLivewireCleanup?.();
            window.removeEventListener('resize', this._routeWindowRefresh);
            window.removeEventListener('orientationchange', this._routeWindowRefresh);
            window.cancelAnimationFrame(this._routeFrame);
            this._routeTimers.forEach((timer) => window.clearTimeout(timer));
        },

        routeFocusNode() {
            return this.hoveredRouteNode || this.activeRouteNode || this.initialRouteNode || '';
        },

        setHoveredRouteNode(node = '') {
            this.hoveredRouteNode = String(node || '');
            this.renderRouteLines();
        },

        setActiveRouteNode(node = '') {
            const normalized = String(node || '');
            this.activeRouteNode = this.activeRouteNode === normalized ? '' : normalized;
            this.initialRouteNode = normalized || this.initialRouteNode;
            this.renderRouteLines();
        },

        toggleAllRoutes() {
            this.showAllRoutes = !this.showAllRoutes;
            this.renderRouteLines();
        },

        queueRouteRefresh() {
            window.cancelAnimationFrame(this._routeFrame);
            this._routeFrame = window.requestAnimationFrame(() => this.refreshRouteLines());
        },

        readRouteMap() {
            const source = this.$refs.routeMap;

            if (!source?.textContent?.trim()) {
                return null;
            }

            try {
                return JSON.parse(source.textContent);
            } catch (error) {
                console.warn('Workflow-Routenkarte konnte nicht gelesen werden.', error);

                return null;
            }
        },

        fallbackEdges(nodes) {
            const edges = [];

            nodes.forEach((node, index) => {
                const stepElement = node.closest('[data-step-route-success]');
                const nextNode = nodes[index + 1] || null;
                const nextNodeSameStep = nextNode
                    && nextNode.dataset.workflowStepAction === node.dataset.workflowStepAction;
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

                if (successTarget) {
                    edges.push({
                        id: `fallback-success-${index}`,
                        source: node.dataset.workflowTaskNode || '',
                        target: successTarget,
                        outcome: node.dataset.routeSuccess ? 'success' : 'implicit',
                    });
                }

                let failedTarget = String(node.dataset.routeFailed || '').trim();

                if (!failedTarget && stepElement && lastInStep) {
                    failedTarget = String(stepElement.dataset.stepRouteFailed || '').trim();
                }

                if (failedTarget) {
                    edges.push({
                        id: `fallback-failed-${index}`,
                        source: node.dataset.workflowTaskNode || '',
                        target: failedTarget,
                        outcome: 'failed',
                    });
                }
            });

            return edges;
        },

        renderRouteLines() {
            const focusNode = this.routeFocusNode();
            const focusOnly = this.compactRouteMode && !this.showAllRoutes;
            const hasRelatedLine = focusNode !== '' && this.routeLines.some(
                (line) => line.sourceNode === focusNode || line.targetNode === focusNode,
            );

            this.routeSvgMarkup = this.routeLines.map((line) => {
                const related = !focusNode
                    || line.sourceNode === focusNode
                    || line.targetNode === focusNode;

                if (focusOnly && (!focusNode || !related)) {
                    return '';
                }

                const outcome = normalizeOutcome(line.outcome);
                const tone = ROUTE_TONES[outcome] || ROUTE_TONES.default;
                const runtimeActive = Boolean(line.runtimeActive);
                const color = runtimeActive ? ROUTE_TONES.runtime.color : tone.color;
                const markerName = runtimeActive ? ROUTE_TONES.runtime.marker : tone.marker;
                const dash = runtimeActive ? '' : tone.dash;
                const opacity = hasRelatedLine ? (related ? 1 : 0.16) : (outcome === 'implicit' ? 0.55 : 0.88);
                const strokeWidth = runtimeActive ? 3.6 : (related && hasRelatedLine ? 3.1 : (outcome === 'implicit' ? 1.6 : 2.2));
                const filter = related && hasRelatedLine
                    ? ' style="filter:drop-shadow(0 0 2px rgba(15,23,42,.2))"'
                    : '';
                const dashMarkup = dash ? ` stroke-dasharray="${dash}"` : '';

                return `<path data-route-edge="${escapeAttribute(line.id)}" d="${escapeAttribute(line.path)}" fill="none" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" stroke="${color}" opacity="${opacity}"${dashMarkup}${filter} marker-end="url(#${escapeAttribute(this.routeInstance)}-arrow-${markerName})"></path>`;
            }).join('');
        },

        refreshRouteLines() {
            const surface = this.$refs.routeSurface;

            if (!surface || surface.offsetWidth === 0 || surface.offsetHeight === 0) {
                this.routeLines = [];
                this.routeSvgMarkup = '';

                return;
            }

            const taskNodes = Array.from(surface.querySelectorAll('[data-workflow-task-node]'));
            const routeNodes = Array.from(surface.querySelectorAll('[data-workflow-route-node]'));
            const allNodes = [...new Set([...taskNodes, ...routeNodes])];
            const surfaceRect = surface.getBoundingClientRect();
            const byKey = new Map();
            const firstByStep = new Map();

            allNodes.forEach((node) => {
                const key = node.dataset.workflowRouteNode || node.dataset.workflowTaskNode || '';
                const step = node.dataset.workflowStepAction || '';

                if (key) {
                    byKey.set(key, node);
                }

                if (step && node.dataset.workflowTaskNode && !firstByStep.has(step)) {
                    firstByStep.set(step, node);
                }
            });

            const targetElement = (target) => {
                const normalized = String(target || '').trim();

                if (!normalized) {
                    return null;
                }

                if (byKey.has(normalized)) {
                    return byKey.get(normalized);
                }

                if (normalized.endsWith('::*')) {
                    return firstByStep.get(normalized.slice(0, -3)) || null;
                }

                return null;
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

                for (let index = 1; index < compact.length - 1; index += 1) {
                    const previous = compact[index - 1];
                    const current = compact[index];
                    const next = compact[index + 1];
                    const incoming = Math.hypot(current.x - previous.x, current.y - previous.y);
                    const outgoing = Math.hypot(next.x - current.x, next.y - current.y);
                    const cornerRadius = Math.min(radius, incoming / 2, outgoing / 2);

                    if (!cornerRadius) {
                        path += ` L ${current.x} ${current.y}`;
                        continue;
                    }

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
            const routeMap = this.readRouteMap();
            const edges = Array.isArray(routeMap?.edges) && routeMap.edges.length
                ? routeMap.edges.filter((edge) => edge?.reachable !== false)
                : this.fallbackEdges(taskNodes);
            let routeLane = 0;
            const lines = [];

            edges.forEach((edge, edgeIndex) => {
                const sourceNode = String(edge.source || edge.from || '');
                const targetNode = String(edge.target || edge.to || '');
                const source = targetElement(sourceNode);
                const target = targetElement(targetNode);

                if (!source || !target) {
                    return;
                }

                const sourceRect = relativeRect(source);
                const targetRect = relativeRect(target);
                const sourceStepElement = source.closest('[data-workflow-step-column]');
                const targetStepElement = target.closest('[data-workflow-step-column]');
                const sourceStepRect = sourceStepElement ? relativeRect(sourceStepElement) : sourceRect;
                const targetStepRect = targetStepElement ? relativeRect(targetStepElement) : targetRect;
                const sourceStepIndex = stepIndexes.get(sourceStepElement) ?? -1;
                const targetStepIndex = stepIndexes.get(targetStepElement) ?? -1;
                const laneIndex = routeLane++;
                const outcome = normalizeOutcome(edge.outcome || edge.line_tone || edge.type);
                const sourceY = outcome === 'failed'
                    ? sourceRect.top + (sourceRect.height * 0.72)
                    : sourceRect.top + (sourceRect.height * 0.38);
                const targetY = targetRect.centerY;
                let points;

                if (source === target) {
                    const loopX = sourceStepRect.right + 16 + ((laneIndex % 4) * 6);
                    points = [
                        { x: sourceRect.right, y: sourceY },
                        { x: loopX, y: sourceY },
                        { x: loopX, y: targetY + 16 },
                        { x: sourceRect.right, y: targetY + 16 },
                        { x: sourceRect.right, y: targetY },
                    ];
                } else if (sourceStepElement && sourceStepElement === targetStepElement) {
                    const sideX = sourceStepRect.right + 16 + ((laneIndex % 4) * 6);
                    points = [
                        { x: sourceRect.right, y: sourceY },
                        { x: sideX, y: sourceY },
                        { x: sideX, y: targetY },
                        { x: targetRect.right, y: targetY },
                    ];
                } else {
                    const goesBack = targetStepIndex < sourceStepIndex || targetRect.centerX < sourceRect.centerX;
                    const sourceAnchorX = goesBack ? sourceRect.left : sourceRect.right;
                    const targetAnchorX = goesBack ? targetRect.right : targetRect.left;
                    const adjacent = sourceStepIndex >= 0
                        && targetStepIndex >= 0
                        && Math.abs(sourceStepIndex - targetStepIndex) === 1;

                    if (adjacent) {
                        const gapLeft = goesBack ? targetStepRect.right : sourceStepRect.right;
                        const gapRight = goesBack ? sourceStepRect.left : targetStepRect.left;
                        const gapOffset = ((laneIndex % 5) - 2) * 3;
                        const gapX = Math.max(
                            gapLeft + 8,
                            Math.min(gapRight - 8, ((gapLeft + gapRight) / 2) + gapOffset),
                        );
                        points = [
                            { x: sourceAnchorX, y: sourceY },
                            { x: gapX, y: sourceY },
                            { x: gapX, y: targetY },
                            { x: targetAnchorX, y: targetY },
                        ];
                    } else {
                        const clearance = 18 + ((laneIndex % 5) * 6);
                        const corridorY = Math.max(12, Math.min(sourceStepRect.top, targetStepRect.top) - clearance);
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
                    }
                }

                lines.push({
                    id: String(edge.id || `route-${edgeIndex}`),
                    path: roundedPath(points),
                    outcome,
                    sourceNode,
                    targetNode,
                    runtimeActive: Boolean(
                        edge.runtime_active
                        || edge.runtimeActive
                        || (edge.runtime && (edge.executed || edge.pending)),
                    ),
                });
            });

            this.routeOverlay = {
                width: Math.max(surface.scrollWidth, surface.clientWidth),
                height: Math.max(surface.scrollHeight, surface.clientHeight),
            };
            this.routeLines = lines.filter((line) => line.path !== '');
            this.renderRouteLines();
        },
    };
}
