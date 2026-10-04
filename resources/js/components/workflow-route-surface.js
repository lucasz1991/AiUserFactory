const ROUTE_TONES = {
    success: { color: '#10b981', marker: 'success', dash: '' },
    failed: { color: '#fb7185', marker: 'failed', dash: '6 5' },
    error: { color: '#fb7185', marker: 'failed', dash: '6 5' },
    partial: { color: '#3b82f6', marker: 'partial', dash: '4 4' },
    timeout: { color: '#8b5cf6', marker: 'timeout', dash: '3 4' },
    runtime: { color: '#0ea5e9', marker: 'runtime', dash: '' },
    implicit: { color: '#3b82f6', marker: 'default', dash: '' },
    default: { color: '#3b82f6', marker: 'default', dash: '' },
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

const roundedRoutePath = (points, radius = 8) => {
    const compact = points.filter((point, index) => !index
        || point.x !== points[index - 1].x || point.y !== points[index - 1].y);
    if (compact.length < 2) return '';
    let path = `M ${compact[0].x} ${compact[0].y}`;
    for (let index = 1; index < compact.length - 1; index += 1) {
        const previous = compact[index - 1];
        const current = compact[index];
        const next = compact[index + 1];
        const incoming = Math.hypot(current.x - previous.x, current.y - previous.y);
        const outgoing = Math.hypot(next.x - current.x, next.y - current.y);
        const corner = Math.min(radius, incoming / 2, outgoing / 2);
        const before = { x: current.x + (previous.x - current.x) / incoming * corner, y: current.y + (previous.y - current.y) / incoming * corner };
        const after = { x: current.x + (next.x - current.x) / outgoing * corner, y: current.y + (next.y - current.y) / outgoing * corner };
        path += ` L ${before.x} ${before.y} Q ${current.x} ${current.y} ${after.x} ${after.y}`;
    }
    const end = compact[compact.length - 1];
    return `${path} L ${end.x} ${end.y}`;
};

// Shared geometry for overview, editable canvas and live test. Lane allocation
// depends on the complete map, never the hovered/selected subset.
export function buildWorkflowRouteLines(edges, nodes) {
    const unique = new Map();
    const rank = { success: 0, implicit: 1, default: 2, partial: 3, failed: 4, timeout: 5, runtime: 6 };
    for (const [index, edge] of edges.entries()) {
        if (edge?.reachable === false) continue;
        const sourceNode = String(edge.source || edge.sourceNode || edge.from || '');
        const targetNode = String(edge.target || edge.targetNode || edge.to || '');
        const source = nodes.get(sourceNode);
        const target = nodes.get(targetNode);
        if (!source || !target) continue;
        const outcome = normalizeOutcome(edge.outcome || edge.line_tone || edge.lineTone || edge.type);
        const key = `${sourceNode}|${targetNode}|${outcome}`;
        const previous = unique.get(key);
        unique.set(key, {
            id: String(previous?.id || edge.id || `route-${index}`), sourceNode, targetNode, source, target, outcome,
            runtimeActive: Boolean(previous?.runtimeActive || edge.runtime_active || edge.runtimeActive || edge.pending || (edge.runtime && edge.executed)),
            runtimeObserved: Boolean(previous?.runtimeObserved || (edge.runtime && (edge.runtimeCount || edge.runtime_count || edge.executed))),
            ageOpacity: Math.max(previous?.ageOpacity || 0, Number(edge.ageOpacity ?? 0.88)),
        });
    }
    const routes = [...unique.values()].sort((a, b) => a.source.index - b.source.index
        || a.source.rect.top - b.source.rect.top || rank[a.outcome] - rank[b.outcome]
        || a.target.index - b.target.index || a.target.rect.top - b.target.rect.top
        || a.targetNode.localeCompare(b.targetNode));
    const lanes = new Map();
    const allocate = (key, start, end) => {
        const pool = lanes.get(key) || [];
        const low = Math.min(start, end) - 6;
        const high = Math.max(start, end) + 6;
        let slot = pool.findIndex((ranges) => ranges.every(([a, b]) => high < a || low > b));
        if (slot < 0) { slot = pool.length; pool.push([]); }
        pool[slot].push([low, high]);
        lanes.set(key, pool);
        return slot;
    };
    const incoming = new Map();
    const all = [...nodes.values()];
    const top = Math.min(...all.map((node) => node.columnRect.top));
    const bottom = Math.max(...all.map((node) => node.columnRect.bottom));
    routes.forEach((line) => {
        const { source, target } = line;
        const sameColumn = source.column === target.column && source.column !== null;
        const back = target.index < source.index || (sameColumn && target.rect.top <= source.rect.top);
        const adjacent = !sameColumn && Math.abs(source.index - target.index) === 1;
        line.kind = sameColumn ? 'side' : (adjacent && !back ? 'gap' : (back ? 'bottom' : 'top'));
        line.pool = line.kind === 'side' ? `side:${source.index}` : (line.kind === 'gap' ? `gap:${source.index}` : line.kind);
        line.slot = allocate(line.pool, line.kind === 'side' || line.kind === 'gap' ? source.rect.centerY : source.rect.centerX,
            line.kind === 'side' || line.kind === 'gap' ? target.rect.centerY : target.rect.centerX);
        const list = incoming.get(line.targetNode) || [];
        list.push(line);
        incoming.set(line.targetNode, list);
    });
    return routes.map((line) => {
        const { source, target } = line;
        const s = source.rect;
        const t = target.rect;
        const slots = lanes.get(line.pool).length;
        const sourceY = s.top + s.height * ({ failed: 0.68, timeout: 0.82, partial: 0.24 }[line.outcome] ?? 0.40);
        const inbound = incoming.get(line.targetNode);
        const targetY = t.centerY + (inbound.length > 1 ? (inbound.indexOf(line) / (inbound.length - 1) - 0.5) * Math.min(20, t.height * 0.5) : 0);
        const channel = 12 + line.slot * Math.min(6, 24 / Math.max(1, slots - 1));
        let points;
        if (line.kind === 'side') {
            const sideX = source.columnRect.right + channel;
            // A self-loop returns to a separate port, never a zero-length edge.
            const endY = source === target ? s.top + s.height * 0.90 : targetY;
            points = [{ x: s.right, y: sourceY }, { x: sideX, y: sourceY }, { x: sideX, y: endY }, { x: t.right, y: endY }];
        } else if (line.kind === 'gap') {
            const left = source.columnRect.right;
            const right = target.columnRect.left;
            const gapX = left + (right - left) * (line.slot + 1) / (slots + 1);
            points = [{ x: s.right, y: sourceY }, { x: gapX, y: sourceY }, { x: gapX, y: targetY }, { x: t.left, y: targetY }];
        } else {
            const back = line.kind === 'bottom';
            const corridorY = back ? bottom + 12 + line.slot * Math.min(8, 56 / Math.max(1, slots - 1))
                : top - 12 - line.slot * Math.min(8, 56 / Math.max(1, slots - 1));
            const sourceX = source.columnRect.right + channel;
            const targetAnchor = back ? t.right : t.left;
            const targetX = back ? target.columnRect.right + channel : target.columnRect.left - channel;
            points = [{ x: s.right, y: sourceY }, { x: sourceX, y: sourceY }, { x: sourceX, y: corridorY },
                { x: targetX, y: corridorY }, { x: targetX, y: targetY }, { x: targetAnchor, y: targetY }];
        }
        return { ...line, path: roundedRoutePath(points), points };
    });
}

export function workflowRouteSurface(config = {}) {
    return {
        routeInstance: String(config.instance || 'workflow'),
        focusedTask: '',
        hoveredRouteNode: '',
        activeRouteNode: '',
        initialRouteNode: String(config.initialNode || ''),
        showRoutes: true,
        showAllRoutes: false,
        compactRouteMode: false,
        routeLines: [],
        routeOverlay: { width: 0, height: 0 },
        routeSvgMarkup: '',
        _routeResizeObserver: null,
        _routeMutationObserver: null,
        _routeMedia: null,
        _routeMediaListener: null,
        _routeLivewireCleanup: null,
        _routeFrame: null,
        _routeTimers: [],

        init() {
            this._routeMedia = window.matchMedia('(max-width: 767px)');
            this.compactRouteMode = this._routeMedia.matches;
            this.showAllRoutes = false;
            this._routeMediaListener = (event) => {
                this.compactRouteMode = event.matches;
                this.queueRouteRefresh();
            };
            this._routeMedia.addEventListener?.('change', this._routeMediaListener);

            this.$nextTick(() => {
                const surface = this.$refs.routeSurface;

                if (surface && window.ResizeObserver) {
                    this._routeResizeObserver = new ResizeObserver(() => this.queueRouteRefresh());
                    this._routeResizeObserver.observe(surface);
                }
                if (surface && window.MutationObserver) {
                    this._routeMutationObserver = new MutationObserver((records) => {
                        // Columns can change without resizing the viewport.
                        // SVG writes are not layout signals.
                        if (records.some((record) => !record.target.closest?.('svg'))) this.queueRouteRefresh();
                    });
                    this._routeMutationObserver.observe(surface, { childList: true, subtree: true });
                }

                this.queueRouteRefresh();
                this._routeTimers.push(window.setTimeout(() => this.queueRouteRefresh(), 120));
                this._routeTimers.push(window.setTimeout(() => this.queueRouteRefresh(), 520));
            });

            this._routeWindowRefresh = () => this.queueRouteRefresh();
            window.addEventListener('resize', this._routeWindowRefresh, { passive: true });
            window.addEventListener('orientationchange', this._routeWindowRefresh, { passive: true });

            if (window.Livewire?.hook) {
                this._routeLivewireCleanup = window.Livewire.hook('morphed', ({ el }) => {
                    if (this.$root?.contains(el) || el?.contains?.(this.$root)) {
                        this.queueRouteRefresh();
                    }
                });
            }
        },

        destroy() {
            this._routeResizeObserver?.disconnect();
            this._routeMutationObserver?.disconnect();
            this._routeMedia?.removeEventListener?.('change', this._routeMediaListener);
            this._routeLivewireCleanup?.();
            window.removeEventListener('resize', this._routeWindowRefresh);
            window.removeEventListener('orientationchange', this._routeWindowRefresh);
            window.cancelAnimationFrame(this._routeFrame);
            this._routeTimers.forEach((timer) => window.clearTimeout(timer));
        },

        routeFocusNode() {
            return this.activeRouteNode || this.hoveredRouteNode || this.initialRouteNode || '';
        },

        setHoveredRouteNode(node = '') {
            this.hoveredRouteNode = String(node || '');
            this.renderRouteLines();
        },

        setActiveRouteNode(node = '') {
            const normalized = String(node || '');
            this.activeRouteNode = normalized;
            this.initialRouteNode = normalized;
            if (normalized) this.showAllRoutes = false;
            this.renderRouteLines();
        },

        toggleAllRoutes() {
            this.showAllRoutes = !this.showAllRoutes;
            if (this.showAllRoutes) {
                this.activeRouteNode = '';
                this.initialRouteNode = '';
                this.hoveredRouteNode = '';
            }
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
                (line) => line.sourceNode === focusNode,
            );

            this.routeSvgMarkup = this.routeLines.map((line) => {
                const related = !focusNode || line.sourceNode === focusNode;

                if (focusNode && !related) return '';

                if (focusOnly && (!focusNode || !related)) {
                    return '';
                }

                const outcome = normalizeOutcome(line.outcome);
                // Quiet default: show the actual task flow. Branches are
                // inspectable on hover/selection or through the explicit All control.
                if (!focusNode && !this.showAllRoutes && !line.runtimeActive && !line.runtimeObserved
                    && (line.sourceNode.endsWith('::*') || !['success', 'implicit', 'default'].includes(outcome))) return '';
                const tone = ROUTE_TONES[outcome] || ROUTE_TONES.default;
                const runtimeActive = Boolean(line.runtimeActive);
                const color = tone.color;
                const markerName = tone.marker;
                const dash = tone.dash;
                const ageOpacity = Math.max(0.35, Math.min(1, Number(line.ageOpacity ?? 0.88)));
                const opacity = hasRelatedLine || runtimeActive ? 1 : (outcome === 'implicit' ? 0.55 : ageOpacity);
                const strokeWidth = runtimeActive || hasRelatedLine ? 2.6 : 1.8;
                const dashMarkup = dash ? ` stroke-dasharray="${dash}"` : '';

                const halo = `<path class="ff-route-halo" d="${escapeAttribute(line.path)}" fill="none" stroke-width="${strokeWidth + 3}" stroke-linecap="round" stroke-linejoin="round"></path>`;
                return halo + `<path data-route-edge="${escapeAttribute(line.id)}" data-route-source="${escapeAttribute(line.sourceNode)}" data-route-target="${escapeAttribute(line.targetNode)}" data-route-outcome="${outcome}" d="${escapeAttribute(line.path)}" fill="none" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" stroke="${color}" opacity="${opacity}"${dashMarkup} marker-end="url(#${escapeAttribute(this.routeInstance)}-arrow-${markerName})"></path>`;
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
                this._routeResizeObserver?.observe(node);
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
                // Feedback belongs to the task but must not move its ports.
                const anchor = element.matches?.('[data-workflow-task-node]')
                    ? (element.querySelector('.ff-task-card') || element)
                    : element;
                const rect = anchor.getBoundingClientRect();

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
            const stepColumns = Array.from(surface.querySelectorAll('[data-workflow-step-column]'));
            const stepIndexes = new Map(stepColumns.map((column, index) => [column, index]));
            const routeMap = this.readRouteMap();
            const rawEdges = Array.isArray(routeMap?.edges)
                ? routeMap.edges
                : this.fallbackEdges(taskNodes);
            const edges = rawEdges.map((edge) => {
                const key = String(edge.target || edge.targetNode || edge.to || '');
                const firstTask = key.endsWith('::*') ? firstByStep.get(key.slice(0, -3)) : null;
                return firstTask ? { ...edge, target: firstTask.dataset.workflowTaskNode } : edge;
            });
            const descriptors = new Map();
            const descriptorFor = (element) => {
                const column = element.closest('[data-workflow-step-column]');
                const rect = relativeRect(element);
                return { rect, column, columnRect: column ? relativeRect(column) : rect,
                    index: stepIndexes.get(column) ?? stepColumns.length };
            };
            allNodes.forEach((node) => {
                const key = node.dataset.workflowRouteNode || node.dataset.workflowTaskNode || '';
                if (key) descriptors.set(key, descriptorFor(node));
            });
            for (const edge of edges) {
                for (const key of [edge.source || edge.sourceNode || edge.from, edge.target || edge.targetNode || edge.to]) {
                    if (key && !descriptors.has(key)) {
                        const element = targetElement(key);
                        if (element) descriptors.set(key, descriptorFor(element));
                    }
                }
            }
            const lines = buildWorkflowRouteLines(edges, descriptors);

            this.routeOverlay = {
                width: Math.max(surface.scrollWidth, surface.clientWidth),
                height: Math.max(surface.scrollHeight, surface.clientHeight),
            };
            this.routeLines = lines.filter((line) => line.path !== '');
            this.renderRouteLines();
        },
    };
}
