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
    $inspectorInteractive = (bool) ($interactive ?? true);
    $inspectorCanProbe = (bool) ($canProbe ?? false);
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
    x-data="workflowDomInspector({
        interactive: @js($inspectorInteractive),
        canProbe: @js($inspectorCanProbe),
        storageKey: @js($inspectorStorageKey),
    })"
    class="bg-white"
>
    <script type="application/json" x-ref="payload">{!! $inspectorJson !!}</script>

    <section class="border-b border-slate-200 bg-slate-950" aria-label="Browserfenster-Screenshot">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3 text-white">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">Browserfenster</p>
                <p class="mt-1 text-xs text-slate-300">Element anklicken, um es im Body-DOM zu finden.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-[9px] font-bold">
                <span class="rounded-full border border-white/10 bg-white/10 px-2.5 py-1 text-slate-200">Snapshot-Analyse aktiv</span>
                <span
                    class="rounded-full px-2.5 py-1"
                    x-bind:class="canProbe ? 'bg-emerald-400/20 text-emerald-200' : 'bg-amber-300/20 text-amber-100'"
                    x-text="canProbe ? 'Live-Probe verfügbar' : 'Live-Probe nur pausiert'"
                ></span>
            </div>
        </header>

        @if(filled($panel['image'] ?? null))
            <div
                class="relative cursor-crosshair overflow-hidden bg-slate-900"
                x-ref="stage"
                x-on:click="selectFromScreenshot($event)"
                data-workflow-screenshot-picker
            >
                <img
                    src="{{ $panel['image'] }}"
                    alt="{{ $panel['title'] ?? $panel['name'] ?? 'Browserfenster' }} Screenshot"
                    class="block h-auto w-full select-none"
                    draggable="false"
                    x-ref="image"
                >

                <template x-for="(node, overlayIndex) in overlayNodes()" :key="`match-${node.nodeRef}`">
                    <div
                        data-workflow-dom-match-overlay
                        x-bind:style="overlayStyle(node.rect)"
                        class="pointer-events-none absolute z-10 border-2 border-amber-300 bg-amber-300/20 shadow-[0_0_0_1px_rgba(120,53,15,.35)]"
                    >
                        <span class="absolute -left-px -top-px rounded-br bg-amber-300 px-1.5 py-0.5 font-mono text-[8px] font-black text-amber-950" x-text="matchedRefs.indexOf(node.nodeRef) + 1"></span>
                    </div>
                </template>

                <div
                    x-cloak
                    x-show="selectedNode()?.rect"
                    x-bind:style="overlayStyle(selectedNode()?.rect)"
                    class="pointer-events-none absolute z-20 border-2 border-cyan-300 bg-cyan-300/20 shadow-[0_0_0_1px_rgba(8,145,178,.45),0_0_24px_rgba(34,211,238,.4)]"
                >
                    <span class="absolute -top-7 left-0 max-w-80 truncate rounded-md bg-cyan-950 px-2 py-1 font-mono text-[9px] font-bold text-white shadow-xl" x-text="selectedSuggestions[0]?.selector || nodeLabel(selectedNode())"></span>
                </div>

                <div
                    x-cloak
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

                <a
                    href="{{ $panel['image'] }}"
                    target="_blank"
                    rel="noopener"
                    x-on:click.stop
                    class="absolute right-3 top-3 z-30 rounded-lg border border-white/20 bg-slate-950/80 px-3 py-1.5 text-[10px] font-bold text-white shadow-lg backdrop-blur hover:bg-slate-900"
                >
                    Bild öffnen
                </a>

                <p
                    x-cloak
                    x-show="selectionNotice"
                    x-text="selectionNotice"
                    class="absolute bottom-3 left-1/2 z-30 -translate-x-1/2 rounded-lg bg-slate-950/90 px-3 py-2 text-[10px] font-semibold text-white shadow-xl"
                ></p>
            </div>
        @else
            <div class="flex aspect-video items-center justify-center bg-slate-900 px-4 text-center text-sm font-semibold text-slate-400">
                Noch kein Screenshot verfügbar. Der zuletzt erfasste Body-DOM kann trotzdem durchsucht werden.
            </div>
        @endif
    </section>

    <section class="bg-slate-50" data-workflow-dom-tree aria-label="Durchsuchbarer Body-DOM">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-700">Body-DOM</p>
                        <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-[9px] font-bold text-slate-500">&lt;body&gt;</span>
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500" x-text="nodes.length ? `${nodes.length} Body-Knoten im Snapshot` : 'Kein Body-DOM in diesem Lauf erfasst'"></p>
                </div>
                <span x-show="selectedNode()?.inShadowDom" class="rounded-full bg-violet-100 px-2.5 py-1 text-[9px] font-black uppercase text-violet-700">Shadow DOM</span>
            </div>

            <div x-show="nodes.length" class="mt-3 grid gap-2 lg:grid-cols-[minmax(16rem,1fr)_auto]">
                <div class="relative">
                    <label class="sr-only" for="workflow-dom-search-{{ md5($inspectorStorageKey) }}">DOM nach CSS-Selektor oder Text durchsuchen</label>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.5-3.5"></path>
                    </svg>
                    <input
                        id="workflow-dom-search-{{ md5($inspectorStorageKey) }}"
                        type="search"
                        x-model="query"
                        x-on:input.debounce.220ms="search()"
                        x-on:keydown.enter.prevent="search()"
                        x-on:keydown.escape.prevent="clearSearch()"
                        placeholder='CSS-Selektor oder Text, z. B. button[type="submit"]'
                        autocomplete="off"
                        data-workflow-dom-search
                        class="h-11 w-full rounded-xl border-slate-300 bg-white pl-10 pr-10 font-mono text-xs text-slate-900 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    >
                    <button
                        x-cloak
                        x-show="query"
                        type="button"
                        x-on:click="clearSearch()"
                        class="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        aria-label="DOM-Suche leeren"
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex h-11 min-w-24 items-center justify-center rounded-xl border px-3 text-[10px] font-black"
                        x-bind:class="searchError ? 'border-rose-200 bg-rose-50 text-rose-700' : (query ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-500')"
                        x-text="searchError || (query ? `${matchedRefs.length} Treffer` : 'Bereit')"
                        aria-live="polite"
                    ></span>
                    <button type="button" x-on:click="selectNextMatch(-1)" x-bind:disabled="matchedRefs.length === 0" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-35" aria-label="Vorheriger Treffer">
                        <span aria-hidden="true">↑</span>
                    </button>
                    <button type="button" x-on:click="selectNextMatch(1)" x-bind:disabled="matchedRefs.length === 0" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-35" aria-label="Nächster Treffer">
                        <span aria-hidden="true">↓</span>
                    </button>
                </div>
            </div>

            <div x-show="nodes.length" class="mt-2 flex flex-wrap items-center gap-2">
                <span class="text-[9px] font-black uppercase tracking-wider text-slate-400">Schnellfilter</span>
                <button type="button" x-on:click="runQuickQuery('button, input:not([type=hidden]), textarea, select, a[href], [role=button]')" class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[9px] font-bold text-slate-600 hover:border-cyan-300 hover:text-cyan-800">Eingaben & Buttons</button>
                <button type="button" x-on:click="runQuickQuery('input:not([type=hidden]), textarea, select, [contenteditable=true]')" class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[9px] font-bold text-slate-600 hover:border-cyan-300 hover:text-cyan-800">Formularfelder</button>
                <button type="button" x-on:click="runQuickQuery('[aria-label], [role], [data-testid], [data-test]')" class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[9px] font-bold text-slate-600 hover:border-cyan-300 hover:text-cyan-800">Semantische Ziele</button>
                <span class="ml-auto hidden text-[9px] text-slate-400 lg:inline">Suche und Auswahl bleiben bei Livewire-Polling erhalten.</span>
            </div>
        </header>

        <div x-show="nodes.length" class="grid min-h-0 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,.65fr)]">
            <div class="min-h-[22rem] max-h-[38rem] overflow-auto bg-slate-950 py-2 xl:border-r xl:border-slate-800" data-workflow-preview-scrollbar>
                <template x-for="node in visibleNodes()" :key="node.nodeRef">
                    <div
                        data-workflow-dom-row
                        x-bind:data-node-ref="node.nodeRef"
                        class="group flex min-w-0 items-stretch border-l-2 pr-3 font-mono text-[11px] transition"
                        x-bind:class="selectedRef === node.nodeRef
                            ? 'border-cyan-300 bg-cyan-300/10 text-white'
                            : (isMatched(node) ? 'border-amber-300 bg-amber-300/10 text-slate-100' : 'border-transparent text-slate-300 hover:bg-white/5')"
                        x-bind:style="`padding-left:${Math.min(32, Number(node.depth || 0)) * 13 + 5}px`"
                    >
                        <button
                            type="button"
                            x-on:click.stop="toggle(node)"
                            class="flex h-8 w-6 shrink-0 items-center justify-center text-slate-500 hover:text-slate-200"
                            x-bind:class="hasChildren(node) && !query ? '' : 'invisible'"
                            x-bind:aria-label="collapsed[node.nodeRef] ? 'Knoten aufklappen' : 'Knoten zuklappen'"
                        >
                            <svg class="h-3 w-3 transition" x-bind:class="collapsed[node.nodeRef] ? '-rotate-90' : ''" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path d="m2.5 4 3.5 3.5L9.5 4"></path>
                            </svg>
                        </button>
                        <button type="button" x-on:click="select(node)" class="flex min-w-0 flex-1 items-center gap-1.5 py-1.5 text-left">
                            <span class="text-slate-500">&lt;</span>
                            <span class="font-bold text-fuchsia-300" x-text="node.tag"></span>
                            <span x-show="node.id" class="text-cyan-300" x-text="`#${node.id}`"></span>
                            <span x-show="node.classes?.length" class="truncate text-amber-200" x-text="`.${node.classes.slice(0, 3).join('.')}`"></span>
                            <span class="text-slate-500">&gt;</span>
                            <span class="min-w-0 truncate pl-1 font-sans text-[10px] text-slate-500" x-text="nodeSummary(node)"></span>
                        </button>
                        <span x-show="isMatched(node)" class="my-auto ml-2 rounded bg-amber-300 px-1.5 py-0.5 font-sans text-[8px] font-black text-amber-950" x-text="matchedRefs.indexOf(node.nodeRef) + 1"></span>
                    </div>
                </template>

                <div x-show="query && !searchError && matchedRefs.length === 0" class="flex min-h-56 items-center justify-center px-6 text-center">
                    <div>
                        <p class="text-sm font-bold text-slate-300">Kein Treffer im Body-DOM</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Prüfe den CSS-Selektor oder suche mit <span class="font-mono text-slate-400">text=Beschriftung</span>.</p>
                    </div>
                </div>
            </div>

            <aside class="min-h-[22rem] max-h-[38rem] overflow-auto border-t border-slate-200 bg-white p-4 xl:border-t-0" data-workflow-selector-suggestions>
                <template x-if="selectedNode()">
                    <div class="space-y-5">
                        <div>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[9px] font-black uppercase tracking-[0.16em] text-cyan-700">Elementdaten</p>
                                    <p class="mt-1 truncate font-mono text-xs font-bold text-slate-950" x-text="nodeLabel(selectedNode())"></p>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <span x-show="selectedNode().focused" class="rounded-full bg-cyan-100 px-2 py-1 text-[8px] font-black uppercase text-cyan-800">Fokus</span>
                                    <span x-show="selectedNode().actionable || selectedNode().editable" class="rounded-full bg-emerald-100 px-2 py-1 text-[8px] font-black uppercase text-emerald-800">Bedienbar</span>
                                </div>
                            </div>
                            <p x-show="nodeSummary(selectedNode())" class="mt-3 max-h-20 overflow-auto rounded-lg bg-slate-50 p-2.5 text-[10px] leading-4 text-slate-600" x-text="nodeSummary(selectedNode())"></p>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-[10px]">
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Frame</dt><dd class="mt-1 truncate text-slate-700" x-text="selectedNode().frameName || selectedNode().frameRef || 'main'"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Node-Ref</dt><dd class="mt-1 truncate font-mono text-slate-700" x-text="selectedNode().nodeRef"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Rolle / Typ</dt><dd class="mt-1 text-slate-700" x-text="[selectedNode().role, selectedNode().type].filter(Boolean).join(' · ') || '–'"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Name</dt><dd class="mt-1 truncate text-slate-700" x-text="selectedNode().name || '–'"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Label</dt><dd class="mt-1 truncate text-slate-700" x-text="selectedNode().label || selectedNode().ariaLabel || '–'"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Placeholder</dt><dd class="mt-1 truncate text-slate-700" x-text="selectedNode().placeholder || '–'"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Status</dt><dd class="mt-1 text-slate-700" x-text="`${selectedNode().visible ? 'sichtbar' : 'verborgen'} · ${selectedNode().enabled ? 'aktiv' : 'deaktiviert'}`"></dd></div>
                            <div><dt class="font-black uppercase tracking-wide text-slate-400">Rechteck</dt><dd class="mt-1 font-mono text-slate-700" x-text="`${Math.round(selectedNode().rect.x)}, ${Math.round(selectedNode().rect.y)} · ${Math.round(selectedNode().rect.width)}×${Math.round(selectedNode().rect.height)}`"></dd></div>
                        </dl>

                        <div x-show="Object.keys(selectedNode().attributes || {}).length">
                            <p class="text-[9px] font-black uppercase tracking-[0.14em] text-slate-400">Sichere Attribute</p>
                            <dl class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200 bg-slate-50 px-3">
                                <template x-for="[attribute, value] in Object.entries(selectedNode().attributes || {})" :key="attribute">
                                    <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-2 py-2 text-[9px]">
                                        <dt class="truncate font-mono font-bold text-fuchsia-700" x-text="attribute"></dt>
                                        <dd class="truncate font-mono text-slate-600" x-text="value"></dd>
                                    </div>
                                </template>
                            </dl>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[9px] font-black uppercase tracking-[0.14em] text-slate-400">Selektor-Vorschläge</p>
                                <span class="text-[9px] text-slate-400" x-text="`${selectedSuggestions.length} Varianten`"></span>
                            </div>
                            <div class="mt-2 space-y-2">
                                <template x-for="candidate in selectedSuggestions" :key="candidate.selector">
                                    <div class="rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm">
                                        <div class="flex items-start gap-2">
                                            <code class="min-w-0 flex-1 break-all text-[10px] leading-4 text-slate-800" x-text="candidate.selector"></code>
                                            <button
                                                type="button"
                                                x-on:click="copySelector(candidate.selector)"
                                                class="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[9px] font-bold text-slate-600 hover:bg-slate-100"
                                                x-text="copiedSelector === candidate.selector ? 'Kopiert' : 'Kopieren'"
                                            ></button>
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[8px] font-bold uppercase text-slate-500" x-text="candidate.kind"></span>
                                            <span x-show="candidate.unique" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[8px] font-black uppercase text-emerald-700">eindeutig</span>
                                            <span x-show="candidate.count !== null && !candidate.unique" class="rounded bg-amber-100 px-1.5 py-0.5 text-[8px] font-bold text-amber-800" x-text="`${candidate.count} Treffer`"></span>
                                            <button
                                                x-show="interactive"
                                                type="button"
                                                x-on:click="useSelector(candidate.selector)"
                                                x-bind:disabled="!selectedNodeActionable()"
                                                class="ml-auto rounded-md bg-cyan-700 px-2 py-1 text-[8px] font-black uppercase text-white hover:bg-cyan-600 disabled:cursor-not-allowed disabled:opacity-35"
                                            >
                                                Für Probe übernehmen
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="rounded-xl border p-3" x-bind:class="canProbe ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
                            <p class="text-[10px] font-bold" x-bind:class="canProbe ? 'text-emerald-800' : 'text-amber-800'" x-text="canProbe ? 'Der Lauf ist pausiert: Live-Proben sind verfügbar.' : 'Snapshot-Suche bleibt verfügbar. Für echte Browseraktionen den Lauf manuell pausieren.'"></p>
                        </div>

                        <p x-show="selectedNode() && !selectedNodeActionable() && interactive" class="text-[9px] leading-4 text-amber-700">
                            Iframe- und Shadow-DOM-Elemente lassen sich im Snapshot markieren und kopieren. Eine Live-Probe bleibt gesperrt, bis der Frame beziehungsweise Root eindeutig adressierbar ist.
                        </p>
                    </div>
                </template>

                <div x-show="!selectedNode()" class="flex min-h-52 items-center justify-center text-center">
                    <div>
                        <p class="text-sm font-bold text-slate-700">Element auswählen</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Klicke in den Screenshot oder auf eine Zeile im Body-DOM.</p>
                    </div>
                </div>
            </aside>
        </div>

        <div x-show="!nodes.length" class="px-5 py-10 text-center">
            <p class="text-sm font-bold text-slate-700">Noch kein Body-DOM verfügbar</p>
            <p class="mx-auto mt-2 max-w-xl text-xs leading-5 text-slate-500">DOM-Snapshots werden in Debug- und Copilot-Testläufen erfasst. Im Real-Playback bleiben Screenshot und DOM bewusst deaktiviert.</p>
        </div>
    </section>
</div>
