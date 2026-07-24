@php
    $inspectorTree = is_array($panel['domTree'] ?? null) ? $panel['domTree'] : [];
    $inspectorNodes = [];

    foreach ((array) ($inspectorTree['frames'] ?? []) as $inspectorFrame) {
        if (! is_array($inspectorFrame)) {
            continue;
        }

        foreach ((array) ($inspectorFrame['nodes'] ?? []) as $inspectorNode) {
            if (! is_array($inspectorNode)) {
                continue;
            }

            $inspectorNodes[] = [
                ...$inspectorNode,
                'classes' => is_array($inspectorNode['classes'] ?? null)
                    ? $inspectorNode['classes']
                    : preg_split('/\s+/', trim((string) ($inspectorNode['className'] ?? '')), -1, PREG_SPLIT_NO_EMPTY),
                'rect' => is_array($inspectorNode['rect'] ?? null)
                    ? $inspectorNode['rect']
                    : [
                        'x' => (float) ($inspectorNode['x'] ?? 0),
                        'y' => (float) ($inspectorNode['y'] ?? 0),
                        'width' => (float) ($inspectorNode['width'] ?? 0),
                        'height' => (float) ($inspectorNode['height'] ?? 0),
                    ],
                'frameRef' => (string) ($inspectorFrame['frameRef'] ?? ''),
                'frameName' => (string) ($inspectorFrame['name'] ?? ''),
            ];
        }
    }

    $inspectorPayload = [
        'windowKey' => (string) ($panel['windowKey'] ?? $panel['name'] ?? $panel['title'] ?? 'main'),
        'targetId' => (string) ($panel['targetId'] ?? $panel['target_id'] ?? ''),
        'viewport' => is_array($inspectorTree['viewport'] ?? null) ? $inspectorTree['viewport'] : null,
        'nodes' => $inspectorNodes,
        'cursor' => is_array($panel['cursor'] ?? null) ? $panel['cursor'] : null,
    ];
    $inspectorRunId = isset($workflowRun)
        ? ($workflowRun?->id ?? 'run')
        : (isset($run) ? ($run?->id ?? 'run') : 'run');
    $inspectorStorageKey = 'workflow-dom-inspector:'.$inspectorRunId.':'.$inspectorPayload['windowKey'];
    $inspectorJson = json_encode(
        $inspectorPayload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
    ) ?: '{}';
    $inspectorInteractive = $interactive ?? true;
@endphp

@once
    <style>
        @keyframes workflow-cursor-click {
            0% { opacity: .8; transform: translate(-50%, -50%) scale(.35); }
            70% { opacity: .18; transform: translate(-50%, -50%) scale(1.35); }
            100% { opacity: 0; transform: translate(-50%, -50%) scale(1.7); }
        }

        [data-workflow-cursor-click] {
            animation: workflow-cursor-click .55s ease-out both;
        }
    </style>
@endonce

<div
    wire:key="workflow-dom-inspector-{{ md5($inspectorStorageKey.':'.($inspectorTree['capturedAt'] ?? '').':'.data_get($inspectorPayload, 'cursor.sequence', '')) }}"
    data-workflow-dom-inspector
    x-data="{
        nodes: [],
        nodeIndex: {},
        selectedRef: null,
        collapsed: {},
        viewport: null,
        cursor: null,
        cursorPoint: null,
        cursorClicked: false,
        windowKey: 'main',
        interactive: @js((bool) $inspectorInteractive),
        storageKey: @js($inspectorStorageKey),
        init() {
            let payload = {};

            try {
                payload = JSON.parse(this.$refs.payload.textContent || '{}');
            } catch {
                payload = {};
            }

            this.nodes = Array.isArray(payload.nodes) ? payload.nodes : [];
            this.nodeIndex = Object.fromEntries(this.nodes.map((node) => [node.nodeRef, node]));
            this.viewport = payload.viewport || null;
            this.cursor = payload.cursor || null;
            this.windowKey = String(payload.windowKey || 'main');

            try {
                const remembered = window.sessionStorage.getItem(this.storageKey);
                this.selectedRef = remembered && this.nodeIndex[remembered] ? remembered : null;
            } catch {
                this.selectedRef = null;
            }

            if (this.cursor) {
                this.cursorPoint = {
                    x: Number(this.cursor.fromX || 0),
                    y: Number(this.cursor.fromY || 0),
                };
                this.$nextTick(() => {
                    window.requestAnimationFrame(() => {
                        this.cursorPoint = {
                            x: Number(this.cursor.toX || 0),
                            y: Number(this.cursor.toY || 0),
                        };
                        this.cursorClicked = this.cursor.clicked === true;
                    });
                });
            }
        },
        visibleNodes() {
            return this.nodes.filter((node) => {
                let parentRef = node.parentRef;
                let guard = 0;

                while (parentRef && guard < 40) {
                    if (this.collapsed[parentRef]) {
                        return false;
                    }

                    parentRef = this.nodeIndex[parentRef]?.parentRef || null;
                    guard += 1;
                }

                return true;
            });
        },
        hasChildren(node) {
            return this.nodes.some((candidate) => candidate.parentRef === node.nodeRef);
        },
        toggle(node) {
            this.collapsed[node.nodeRef] = !this.collapsed[node.nodeRef];
        },
        select(node) {
            this.selectedRef = node.nodeRef;

            try {
                window.sessionStorage.setItem(this.storageKey, node.nodeRef);
            } catch {
                // Session storage can be unavailable in hardened browser modes.
            }
        },
        selectedNode() {
            return this.selectedRef ? (this.nodeIndex[this.selectedRef] || null) : null;
        },
        selectedNodeActionable() {
            const node = this.selectedNode();

            return Boolean(
                this.interactive
                && node?.selector
                && node?.frameRef === 'main'
                && node?.inShadowDom !== true
            );
        },
        nodeLabel(node) {
            const id = node.id ? `#${node.id}` : '';
            const classes = Array.isArray(node.classes) && node.classes.length
                ? `.${node.classes.slice(0, 3).join('.')}`
                : '';

            return `<${node.tag || 'element'}${id}${classes}>`;
        },
        overlayStyle(rect, viewport = null) {
            const sourceViewport = viewport || this.viewport || {};
            const width = Number(sourceViewport.width || 0);
            const height = Number(sourceViewport.height || 0);

            if (!rect || width <= 0 || height <= 0) {
                return 'display:none';
            }

            const left = Math.max(0, Math.min(width, Number(rect.x || 0)));
            const top = Math.max(0, Math.min(height, Number(rect.y || 0)));
            const right = Math.max(left, Math.min(width, left + Number(rect.width || 0)));
            const bottom = Math.max(top, Math.min(height, top + Number(rect.height || 0)));

            return `left:${(left / width) * 100}%;top:${(top / height) * 100}%;width:${((right - left) / width) * 100}%;height:${((bottom - top) / height) * 100}%`;
        },
        cursorStyle() {
            const viewport = this.cursor?.viewport || this.viewport || {};
            const width = Number(viewport.width || 0);
            const height = Number(viewport.height || 0);

            if (!this.cursorPoint || width <= 0 || height <= 0) {
                return 'display:none';
            }

            const x = Math.max(0, Math.min(width, Number(this.cursorPoint.x || 0)));
            const y = Math.max(0, Math.min(height, Number(this.cursorPoint.y || 0)));

            return `left:${(x / width) * 100}%;top:${(y / height) * 100}%`;
        },
    }"
    class="bg-white"
>
    <script type="application/json" x-ref="payload">{!! $inspectorJson !!}</script>

    @if(filled($panel['image'] ?? null))
        <div class="relative overflow-hidden bg-slate-100" x-ref="stage">
            <img
                src="{{ $panel['image'] }}"
                alt="{{ $panel['title'] ?? $panel['name'] ?? 'Browserfenster' }} Screenshot"
                class="block h-auto w-full"
                x-ref="image"
            >

            <div
                x-show="selectedNode()?.rect"
                x-bind:style="overlayStyle(selectedNode()?.rect)"
                class="pointer-events-none absolute z-20 border-2 border-cyan-400 bg-cyan-300/20 shadow-[0_0_0_1px_rgba(8,145,178,.35),0_0_20px_rgba(6,182,212,.35)]"
            >
                <span class="absolute -top-6 left-0 max-w-64 truncate rounded bg-cyan-950 px-2 py-1 font-mono text-[9px] font-bold text-white shadow" x-text="selectedNode()?.selector || nodeLabel(selectedNode() || {})"></span>
            </div>

            <div
                x-show="cursorPoint"
                x-bind:style="cursorStyle()"
                class="pointer-events-none absolute z-30 transition-all duration-300 ease-out"
                aria-hidden="true"
            >
                <svg class="h-6 w-6 -translate-x-[3px] -translate-y-[2px] drop-shadow-[0_2px_2px_rgba(15,23,42,.65)]" viewBox="0 0 24 24" fill="none">
                    <path d="M3 2.5 19 13l-7.1 1.2L8 21.5 3 2.5Z" fill="white" stroke="#0f172a" stroke-width="1.7" stroke-linejoin="round"/>
                </svg>
                <span x-show="cursorClicked" data-workflow-cursor-click class="absolute left-0 top-0 h-8 w-8 rounded-full border-2 border-cyan-400 bg-cyan-300/25"></span>
            </div>

            <a href="{{ $panel['image'] }}" target="_blank" rel="noopener" class="absolute right-2 top-2 z-30 rounded border border-slate-200 bg-white/90 px-2 py-1 text-[9px] font-bold text-slate-700 shadow hover:bg-white">
                Bild öffnen
            </a>
        </div>
    @else
        <div class="flex aspect-video items-center justify-center bg-slate-50 px-4 text-center text-sm font-semibold text-slate-500">
            Noch kein Screenshot verfügbar.
        </div>
    @endif

    <section class="border-t border-slate-200" data-workflow-dom-tree>
        <header class="flex items-center justify-between gap-3 bg-slate-50 px-3 py-2">
            <div>
                <p class="text-[9px] font-black uppercase tracking-[0.16em] text-cyan-700">DOM-Inspektor</p>
                <p class="mt-0.5 text-[10px] text-slate-500" x-text="nodes.length ? `${nodes.length} strukturelle Knoten` : 'DOM wird erst im Debug- oder Copilot-Lauf erfasst'"></p>
            </div>
            <span x-show="selectedNode()?.inShadowDom" class="rounded bg-violet-100 px-2 py-1 text-[8px] font-black uppercase text-violet-700">Shadow DOM</span>
        </header>

        <div x-show="nodes.length" class="grid min-h-0 md:grid-cols-[minmax(0,1fr)_15rem]">
            <div class="max-h-72 overflow-auto border-b border-slate-200 py-1 md:border-b-0 md:border-r" data-workflow-preview-scrollbar>
                <template x-for="node in visibleNodes()" :key="node.nodeRef">
                    <div
                        class="group flex min-w-0 items-center pr-2 text-[10px]"
                        x-bind:class="selectedRef === node.nodeRef ? 'bg-cyan-50 text-cyan-950' : 'text-slate-600 hover:bg-slate-50'"
                        x-bind:style="`padding-left:${Math.min(28, Number(node.depth || 0)) * 12 + 4}px`"
                    >
                        <button
                            type="button"
                            x-on:click.stop="toggle(node)"
                            class="flex h-6 w-5 shrink-0 items-center justify-center text-slate-400"
                            x-bind:class="hasChildren(node) ? '' : 'invisible'"
                            x-bind:aria-label="collapsed[node.nodeRef] ? 'Knoten aufklappen' : 'Knoten zuklappen'"
                        >
                            <span x-text="collapsed[node.nodeRef] ? '›' : '⌄'"></span>
                        </button>
                        <button type="button" x-on:click="select(node)" class="flex min-w-0 flex-1 items-center gap-2 py-1 text-left">
                            <span class="shrink-0 font-mono font-bold" x-text="nodeLabel(node)"></span>
                            <span class="truncate text-slate-400" x-text="node.text || node.ariaLabel || node.role || ''"></span>
                        </button>
                    </div>
                </template>
            </div>

            <aside class="min-h-32 bg-white p-3">
                <template x-if="selectedNode()">
                    <div>
                        <p class="truncate font-mono text-[10px] font-bold text-slate-900" x-text="selectedNode().selector || nodeLabel(selectedNode())"></p>
                        <p class="mt-1 text-[9px] text-slate-400" x-text="selectedNode().frameName ? `Frame: ${selectedNode().frameName}` : selectedNode().frameRef"></p>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-[9px]">
                            <div><dt class="font-bold uppercase text-slate-400">Sichtbar</dt><dd class="mt-0.5 text-slate-700" x-text="selectedNode().visible ? 'ja' : 'nein'"></dd></div>
                            <div><dt class="font-bold uppercase text-slate-400">Aktiv</dt><dd class="mt-0.5 text-slate-700" x-text="selectedNode().enabled ? 'ja' : 'nein'"></dd></div>
                        </dl>
                        <div class="mt-3 grid gap-2">
                            <button
                                type="button"
                                x-on:click="$dispatch('workflow-dom-node-selected', { browserWindow: windowKey, selector: selectedNode().selector })"
                                x-bind:disabled="!selectedNodeActionable()"
                                class="rounded-md bg-cyan-700 px-2 py-1.5 text-[9px] font-bold text-white hover:bg-cyan-600 disabled:cursor-not-allowed disabled:opacity-35"
                            >
                                Als Selektor verwenden
                            </button>
                            <button
                                type="button"
                                x-on:click="$dispatch('workflow-dom-node-highlight', { browserWindow: windowKey, selector: selectedNode().selector })"
                                x-bind:disabled="!selectedNodeActionable()"
                                class="rounded-md border border-cyan-200 bg-cyan-50 px-2 py-1.5 text-[9px] font-bold text-cyan-800 hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-35"
                            >
                                Im Browser markieren
                            </button>
                        </div>
                        <p x-show="selectedNode() && !selectedNodeActionable()" class="mt-2 text-[9px] leading-4 text-amber-700">
                            Für iframe- und Shadow-DOM-Knoten bleibt die sichere Screenshot-Markierung aktiv; Browseraktionen sind bis zu einer eindeutig adressierbaren Frame-/Root-ID gesperrt.
                        </p>
                    </div>
                </template>
                <p x-show="!selectedNode()" class="text-[10px] leading-5 text-slate-400">Knoten auswählen, um ihn sofort im Screenshot zu markieren.</p>
            </aside>
        </div>

        <div x-show="!nodes.length" class="border-t border-dashed border-slate-200 px-3 py-4 text-center text-[10px] text-slate-400">
            In der Stufe „preview“ bleibt der DOM-Baum bewusst aus. Starte einen Debug- oder Copilot-Test.
        </div>
    </section>
</div>
