<div
    class="space-y-4"
    data-assistant-highlight="run_preview:{{ $workflowRun?->id ?? 'empty' }}"
    data-assistant-highlight-key="{{ $workflowRun?->id ?? 'empty' }}"
    @if($polling) wire:poll.3s="refresh" @endif
>
    @once
        <style>
            [data-workflow-preview-scrollbar] {
                scrollbar-color: #94a3b8 #f8fafc;
                scrollbar-width: thin;
                scroll-behavior: smooth;
            }

            [data-workflow-preview-scrollbar]::-webkit-scrollbar {
                height: 8px;
                width: 8px;
            }

            [data-workflow-preview-scrollbar]::-webkit-scrollbar-track {
                background: #f8fafc;
                border-radius: 999px;
            }

            [data-workflow-preview-scrollbar]::-webkit-scrollbar-thumb {
                background: linear-gradient(180deg, #cbd5e1, #94a3b8);
                border: 2px solid #f8fafc;
                border-radius: 999px;
            }

            [data-workflow-preview-scrollbar]::-webkit-scrollbar-thumb:hover {
                background: linear-gradient(180deg, #94a3b8, #64748b);
            }
        </style>
    @endonce

    @if(! $workflowRun)
        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Dieser Workflow-Lauf wurde noch nicht geladen.
        </div>
    @else
        <div
            x-data="{
                overviewOpen: false,
                browserOpen: false,
                logsOpen: 'timeline',
                workflowScrollObserver: null,
                workflowScrollTimer: null,
                init() {
                    this.$watch('overviewOpen', (open) => {
                        if (open) {
                            this.queueActiveWorkflowScroll(true);
                        }
                    });

                    this.$nextTick(() => this.startWorkflowScrollObserver());
                },
                startWorkflowScrollObserver() {
                    const wrapper = this.$refs.maximizedWorkflowMap;

                    if (!wrapper || !window.MutationObserver) {
                        return;
                    }

                    if (this.workflowScrollObserver) {
                        this.workflowScrollObserver.disconnect();
                    }

                    this.workflowScrollObserver = new MutationObserver((mutations) => {
                        const changedActiveTarget = mutations.some((mutation) => {
                            if (mutation.type === 'attributes') {
                                return true;
                            }

                            return Array.from(mutation.addedNodes || []).some((node) => {
                                return node.nodeType === 1
                                    && (node.matches?.('[data-workflow-minimap-active-target], [data-workflow-minimap-active-step]')
                                        || node.querySelector?.('[data-workflow-minimap-active-target], [data-workflow-minimap-active-step]'));
                            });
                        });

                        if (this.overviewOpen && changedActiveTarget) {
                            this.queueActiveWorkflowScroll();
                        }
                    });
                    this.workflowScrollObserver.observe(wrapper, {
                        attributes: true,
                        attributeFilter: ['data-workflow-minimap-active-step', 'data-workflow-minimap-active-target'],
                        childList: true,
                        subtree: true,
                    });
                },
                queueActiveWorkflowScroll(opening = false) {
                    clearTimeout(this.workflowScrollTimer);
                    this.workflowScrollTimer = setTimeout(() => this.scrollActiveWorkflowTarget(), opening ? 260 : 90);
                },
                scrollActiveWorkflowTarget() {
                    if (!this.overviewOpen) {
                        return;
                    }

                    const wrapper = this.$refs.maximizedWorkflowMap;
                    const target = wrapper?.querySelector('[data-workflow-minimap-active-target=&quot;true&quot;]')
                        || wrapper?.querySelector('[data-workflow-minimap-active-step=&quot;true&quot;]');

                    if (!target) {
                        return;
                    }

                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                        inline: 'center',
                    });
                },
            }"
            class="space-y-4"
        >
            @if($processSummary)
                <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <span class="font-semibold text-slate-900">Prozess:</span>
                    PID {{ $processSummary['pid'] ?? '-' }} · {{ $processSummary['process_type'] ?? '-' }} · {{ $processSummary['status'] ?? '-' }}
                </div>
            @endif

            <section class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex min-w-0 items-center justify-between gap-4 border-b border-slate-100 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-sky-600">Workflow-Vorschau</p>
                        <h3 class="mt-1 truncate text-base font-semibold text-slate-950">
                            {{ $workflowRun->workflow?->name ?? 'Workflow' }}
                        </h3>
                        <p class="mt-0.5 max-w-4xl truncate text-xs text-slate-500">
                            Run #{{ $workflowRun->id }} · {{ $workflowDurationLabel }} · {{ data_get($latestStatusResult, 'statusMessage', data_get($latestStatusResult, 'message', $workflowRun->status)) ?: $workflowRun->status }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-workflows.status-badge :status="$workflowRun->status" />
                        <button
                            type="button"
                            x-on:click="overviewOpen = !overviewOpen"
                            x-bind:title="overviewOpen ? 'Minimieren' : 'Maximieren'"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-sky-200 bg-sky-50 text-sky-800 transition hover:bg-sky-100"
                        >
                            <svg x-show="!overviewOpen" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M15 3h6v6"></path>
                                <path d="m21 3-7 7"></path>
                                <path d="M9 21H3v-6"></path>
                                <path d="m3 21 7-7"></path>
                            </svg>
                            <svg x-cloak x-show="overviewOpen" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M8 3v5H3"></path>
                                <path d="m3 8 5-5"></path>
                                <path d="M16 21v-5h5"></path>
                                <path d="m21 16-5 5"></path>
                            </svg>
                            <span class="sr-only" x-text="overviewOpen ? 'Minimieren' : 'Maximieren'"></span>
                        </button>
                    </div>
                </div>

                <div
                    x-show="!overviewOpen"
                    x-collapse.duration.180ms
                    class="w-full border-l-4 border-sky-400 bg-white text-slate-900"
                    style="aspect-ratio: 21 / 5; min-height: 8rem; max-height: 12rem;"
                >
                    <div class="flex h-full w-full flex-col justify-center p-4 sm:p-5">
                        <div class="min-h-0 w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2">
                            @if($compactWorkflowMap->isNotEmpty())
                                <div class="flex w-full min-w-0 items-center gap-1 overflow-x-auto pb-0.5" data-workflow-preview-scrollbar>
                                    @foreach($compactWorkflowMap as $miniStep)
                                        @if(! $loop->first)
                                            <span class="shrink-0 px-0.5 text-sm font-semibold leading-none text-slate-400">&rarr;</span>
                                        @endif
                                        <div
                                            title="{{ $miniStep['position'] }}. {{ $miniStep['title'] }}"
                                            @class([
                                                'flex h-14 min-w-[5.75rem] flex-1 flex-col justify-between rounded-md border px-1.5 py-1 shadow-sm',
                                                'border-amber-300 bg-amber-50/95 ring-2 ring-amber-300/70' => $miniStep['active'] || in_array($miniStep['status'], ['running', 'waiting'], true),
                                                'border-emerald-300 bg-emerald-50/95' => ! $miniStep['active'] && in_array($miniStep['status'], ['completed', 'success'], true),
                                                'border-red-300 bg-red-50/95' => ! $miniStep['active'] && in_array($miniStep['status'], ['failed', 'timeout'], true),
                                                'border-slate-300 bg-slate-100/95' => ! $miniStep['active'] && in_array($miniStep['status'], ['skipped', 'not_executed'], true),
                                                'border-slate-200 bg-white' => ! $miniStep['active'] && ! in_array($miniStep['status'], ['running', 'waiting', 'completed', 'success', 'failed', 'timeout', 'skipped', 'not_executed'], true),
                                            ])
                                        >
                                            <div class="w-full truncate text-center text-[9px] font-semibold leading-3 text-slate-600">
                                                {{ $miniStep['title'] }}
                                            </div>
                                            <div class="flex w-full flex-wrap justify-center gap-0.5">
                                                @foreach($miniStep['tasks'] as $miniTask)
                                                    <span
                                                        title="{{ $miniTask['title'] }}"
                                                        @class([
                                                            'block h-2.5 w-3 rounded-sm border',
                                                            'border-amber-500 bg-amber-400 shadow-sm shadow-amber-300/50' => $miniTask['active'] || in_array($miniTask['status'], ['running', 'waiting'], true),
                                                            'border-emerald-500 bg-emerald-400' => ! $miniTask['active'] && in_array($miniTask['status'], ['completed', 'success'], true),
                                                            'border-red-500 bg-red-400' => ! $miniTask['active'] && in_array($miniTask['status'], ['failed', 'timeout'], true),
                                                            'border-slate-300 bg-slate-300' => ! $miniTask['active'] && in_array($miniTask['status'], ['skipped', 'not_executed'], true),
                                                            'border-slate-300 bg-white' => ! $miniTask['active'] && ! in_array($miniTask['status'], ['running', 'waiting', 'completed', 'success', 'failed', 'timeout', 'skipped', 'not_executed'], true),
                                                        ])
                                                    ></span>
                                                @endforeach
                                                @if($miniStep['overflow'] > 0)
                                                    <span class="flex h-2.5 min-w-3 items-center justify-center rounded-sm border border-slate-200 bg-white px-0.5 text-[8px] font-semibold leading-none text-slate-600">
                                                        +{{ $miniStep['overflow'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="h-3 w-full truncate text-center text-[9px] font-semibold leading-3 {{ $miniStep['activeTaskTitle'] !== '' ? 'text-slate-800' : 'text-transparent' }}">
                                                {{ $miniStep['activeTaskTitle'] !== '' ? $miniStep['activeTaskTitle'] : '-' }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="flex h-11 items-center justify-center text-xs font-semibold text-slate-500">
                                    Workflow-Karte wird geladen.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div x-cloak x-show="overviewOpen" x-collapse.duration.180ms class="bg-white">
                    <div class="px-4 py-3" x-ref="maximizedWorkflowMap">
                        <x-workflows.minimap
                            :workflow-run="$workflowRun"
                            :active-step-id="$activeStepId"
                            :active-task-key="$activeTaskKey"
                            :show-header="false"
                        />
                    </div>
                </div>
            </section>

            @if($embeddedCards->isNotEmpty())
                <section class="space-y-2">
                    @foreach($embeddedCards as $card)
                        <article x-data="{ expanded: false }" class="overflow-hidden rounded-xl border border-sky-200 bg-white shadow-sm">
                            <button type="button" x-on:click="expanded = !expanded" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-sky-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">Embedded</span>
                                        <span class="truncate text-sm font-semibold text-slate-950">{{ $card['title'] }}</span>
                                        <span class="rounded-full border border-sky-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800">{{ $card['statusLabel'] }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                        <span>{{ $card['taskCount'] }} Tasks</span>
                                        @if($card['stepTitle'])
                                            <span>{{ $card['stepTitle'] }}</span>
                                        @endif
                                        @if($card['browserWindow'])
                                            <span>Fenster: {{ $card['browserWindow'] }}</span>
                                        @endif
                                        @if($card['frameKey'])
                                            <span class="font-mono">{{ $card['frameKey'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-md border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-800">
                                    <span x-show="!expanded">Maximieren</span>
                                    <span x-cloak x-show="expanded">Minimieren</span>
                                </span>
                            </button>

                            <div x-cloak x-show="expanded" x-collapse.duration.180ms class="border-t border-sky-200 bg-white p-4">
                                <div class="grid gap-3 lg:grid-cols-[minmax(220px,320px)_minmax(0,1fr)]">
                                    <div class="rounded-md border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600">
                                        <div class="font-semibold uppercase tracking-wide text-slate-500">Uebersicht</div>
                                        <div class="mt-2 space-y-1">
                                            <div>Status: <span class="font-semibold text-slate-900">{{ $card['statusLabel'] }}</span></div>
                                            <div>Parent: <span class="font-mono">{{ $card['parentTaskKey'] ?: '-' }}</span></div>
                                            <div>Frame: <span class="font-mono">{{ $card['frameKey'] ?: '-' }}</span></div>
                                            @if($card['return']['has'])
                                                <div class="rounded border border-indigo-100 bg-indigo-50 px-2 py-1 text-indigo-800">
                                                    Rueckgabe: {{ $card['return']['key'] }} = {{ $card['return']['valueLabel'] }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        @foreach($card['tasks'] as $embeddedTask)
                                            <div class="flex items-center justify-between gap-3 rounded-md border border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                                                <div class="min-w-0">
                                                    <div class="truncate font-semibold text-slate-900">{{ $embeddedTask['title'] }}</div>
                                                    <div class="mt-0.5 truncate text-slate-500">
                                                        {{ $embeddedTask['status'] }}
                                                        @if($embeddedTask['runner'])
                                                            · {{ $embeddedTask['runner'] }}
                                                        @endif
                                                        @if($embeddedTask['node_script'])
                                                            · {{ $embeddedTask['node_script'] }}
                                                        @elseif($embeddedTask['php_handler'])
                                                            · {{ $embeddedTask['php_handler'] }}
                                                        @endif
                                                    </div>
                                                    @if($embeddedTask['return']['has'])
                                                        <div class="mt-1 break-words text-indigo-700">
                                                            Rueckgabe: {{ $embeddedTask['return']['key'] }} = {{ $embeddedTask['return']['valueLabel'] }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <a
                                                    href="{{ $jsonDownload($embeddedTask['raw']) }}"
                                                    download="{{ $downloadName($card['title'].' '.$embeddedTask['title']) }}.json"
                                                    class="shrink-0 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-100"
                                                >
                                                    JSON
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>
            @endif

            @if($screenshotPanels->isNotEmpty())
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex min-h-20 w-full items-center justify-between gap-4 bg-white px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Browserfenster</div>
                            <div class="mt-1 truncate text-sm font-semibold text-slate-900">
                                {{ $screenshotPanels->count() }} offene {{ $screenshotPanels->count() === 1 ? 'Vorschau' : 'Vorschauen' }}
                            </div>
                            <div class="mt-0.5 truncate text-xs text-slate-500">
                                {{ $screenshotPanels->pluck('title')->filter()->take(3)->implode(' · ') }}
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center justify-end gap-2">
                            <div class="hidden max-w-sm items-center justify-end gap-1 sm:flex" x-show="!browserOpen">
                                @foreach($screenshotPanels->take(4) as $panel)
                                    <div class="relative h-12 w-20 overflow-hidden rounded-md border border-slate-200 bg-slate-100 shadow-sm">
                                        @if($panel['image'])
                                            <img src="{{ $panel['image'] }}" alt="{{ $panel['title'] }} Screenshot" class="h-full w-full object-cover">
                                        @else
                                            <div class="flex h-full w-full items-center justify-center text-[9px] font-semibold text-slate-400">
                                                Leer
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                @if($screenshotPanels->count() > 4)
                                    <span class="flex h-12 min-w-12 items-center justify-center rounded-md border border-slate-200 bg-slate-50 px-2 text-xs font-semibold text-slate-600">
                                        +{{ $screenshotPanels->count() - 4 }}
                                    </span>
                                @endif
                            </div>
                            <button
                                type="button"
                                x-on:click="browserOpen = !browserOpen"
                                x-bind:title="browserOpen ? 'Minimieren' : 'Maximieren'"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-sky-200 bg-sky-50 text-sky-800 transition hover:bg-sky-100"
                            >
                                <svg x-show="!browserOpen" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M15 3h6v6"></path>
                                    <path d="m21 3-7 7"></path>
                                    <path d="M9 21H3v-6"></path>
                                    <path d="m3 21 7-7"></path>
                                </svg>
                                <svg x-cloak x-show="browserOpen" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M8 3v5H3"></path>
                                    <path d="m3 8 5-5"></path>
                                    <path d="M16 21v-5h5"></path>
                                    <path d="m21 16-5 5"></path>
                                </svg>
                                <span class="sr-only" x-text="browserOpen ? 'Minimieren' : 'Maximieren'"></span>
                            </button>
                        </div>
                    </div>

                    <div x-cloak x-show="browserOpen" x-collapse.duration.180ms>
                        <div class="border-t border-slate-100 bg-slate-50 p-3">
                            <div class="flex flex-nowrap gap-3 overflow-x-auto pb-1" data-workflow-preview-scrollbar>
                                @foreach($screenshotPanels as $panel)
                                    <article
                                        class="min-w-0 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                                        style="flex: 0 0 {{ $browserPanelBasis }}%; max-width: {{ $browserPanelBasis }}%; min-width: {{ $browserPanelMinWidth }};"
                                    >
                                        <div class="flex items-start justify-between gap-3 border-b border-slate-100 bg-white px-3 py-2">
                                            <div class="min-w-0">
                                                <div class="truncate text-xs font-semibold uppercase tracking-wide text-slate-700">
                                                    {{ $panel['title'] }} · {{ $panel['step'] }}
                                                </div>
                                                @include('livewire.admin.config.partials.browser-window-status', [
                                                    'windowStatus' => is_array($panel['window'] ?? null) ? $panel['window'] : [],
                                                ])
                                            </div>
                                            @if($panel['dom'])
                                                <a
                                                    href="{{ $panel['dom'] }}"
                                                    download="{{ $downloadName(($panel['step'] ?? 'workflow').' '.($panel['title'] ?? 'browser')).'-dom.json' }}"
                                                    class="shrink-0 rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-100"
                                                >
                                                    DOM
                                                </a>
                                            @endif
                                        </div>

                                        @if($panel['image'])
                                            <a href="{{ $panel['image'] }}" target="_blank" rel="noopener" class="relative block bg-slate-100">
                                                <img src="{{ $panel['image'] }}" alt="{{ $panel['title'] }} Screenshot" class="aspect-video w-full object-contain">
                                                <span class="absolute right-2 top-2 max-w-[70%] truncate rounded border border-slate-200 bg-white/90 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-700 shadow-sm">
                                                    {{ $panel['windowKey'] ?? $panel['title'] }}
                                                </span>
                                            </a>
                                        @else
                                            <div class="flex aspect-video items-center justify-center bg-slate-50 px-4 text-center text-sm font-semibold text-slate-500">
                                                Noch kein Screenshot verfuegbar.
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Logs & Debug</div>
                        <div class="mt-1 text-sm text-slate-600">Ablauf, Variablen und technische Diagnose</div>
                    </div>
                    <a
                        href="{{ $jsonDownload($runJsonPayload) }}"
                        download="workflow-run-{{ $workflowRun->id }}.json"
                        class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        Run JSON
                    </a>
                </div>

                <div class="divide-y divide-slate-100 border-t border-slate-100">
                    <div>
                        <button type="button" x-on:click="logsOpen = logsOpen === 'timeline' ? '' : 'timeline'" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">Ablauf</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $timelineEvents->count() }} Ereignisse</span>
                            </span>
                            <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500" x-text="logsOpen === 'timeline' ? 'Offen' : 'Oeffnen'"></span>
                        </button>
                        <div x-cloak x-show="logsOpen === 'timeline'" x-collapse.duration.180ms class="border-t border-slate-100 bg-slate-50 p-4">
                            <div class="max-h-80 space-y-2 overflow-auto pr-1" data-workflow-preview-scrollbar>
                                @forelse($timelineEvents->reverse()->values() as $event)
                                    <div class="rounded-md border border-slate-100 bg-white p-3 text-xs shadow-sm">
                                        <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', data_get($event, 'type', '-')) }}</div>
                                        <div class="mt-1 text-slate-600">{{ data_get($event, 'message', data_get($event, 'text', '-')) }}</div>
                                        @if(data_get($event, 'at'))
                                            <div class="mt-1 text-slate-400">{{ $formatWorkflowTimestamp(data_get($event, 'at')) }}</div>
                                        @endif
                                    </div>
                                @empty
                                    <div class="text-sm text-slate-500">Noch keine Ablaufdaten.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div>
                        <button type="button" x-on:click="logsOpen = logsOpen === 'steps' ? '' : 'steps'" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">Schritte & Tasks</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $stepDebugPanels->count() }} Debug-Panels</span>
                            </span>
                            <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500" x-text="logsOpen === 'steps' ? 'Offen' : 'Oeffnen'"></span>
                        </button>
                        <div x-cloak x-show="logsOpen === 'steps'" x-collapse.duration.180ms class="border-t border-slate-100 bg-slate-50 p-4">
                            <div class="space-y-3">
                                @forelse($stepDebugPanels as $panel)
                                    <details class="group rounded-md border border-slate-200 bg-white shadow-sm" @if($loop->first) open @endif>
                                        <summary class="flex cursor-pointer list-none items-start justify-between gap-3 px-3 py-3 marker:hidden">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-slate-900">{{ $panel['title'] }}</div>
                                                <div class="mt-1 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                                                    <span>{{ $panel['status'] }}</span>
                                                    @if($panel['external'])
                                                        <span>·</span>
                                                        <span class="truncate">{{ $panel['external'] }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-2">
                                                <a
                                                    href="{{ $jsonDownload($panel['debug']) }}"
                                                    download="{{ $downloadName($panel['title']) }}-step-{{ data_get($panel, 'debug.workflowStepRunId') }}.json"
                                                    class="rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-100"
                                                    x-on:click.stop
                                                >
                                                    Debug
                                                </a>
                                                <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 group-open:hidden">Oeffnen</span>
                                                <span class="hidden rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 group-open:inline">Schliessen</span>
                                            </div>
                                        </summary>

                                        <div class="space-y-3 border-t border-slate-100 p-3">
                                            @if($panel['message'])
                                                <div class="rounded bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ $panel['message'] }}</div>
                                            @endif

                                            @if($panel['normalized']['has'])
                                                <div class="rounded-md border border-sky-100 bg-sky-50 p-3 text-xs text-sky-900">
                                                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                                                        <span>Technisch: <span class="font-semibold">{{ data_get($panel, 'normalized.data.technical_status', '-') }}</span></span>
                                                        <span>Fachlich: <span class="font-semibold">{{ data_get($panel, 'normalized.data.business_status', '-') }}</span></span>
                                                        <span>Klasse: <span class="font-semibold">{{ data_get($panel, 'normalized.data.result_class', '-') }}</span></span>
                                                        <span>Reason: <span class="font-semibold">{{ data_get($panel, 'normalized.data.diagnostic_reason_code', '-') }}</span></span>
                                                        <span>UI: {{ data_get($panel, 'normalized.data.ui_state', '-') }}</span>
                                                        <span>Retry: {{ data_get($panel, 'normalized.data.retryable') ? 'ja' : 'nein' }}</span>
                                                        <span>State-Mismatch: {{ data_get($panel, 'normalized.data.state_mismatch') ? 'ja' : 'nein' }}</span>
                                                    </div>
                                                    @if(data_get($panel, 'normalized.data.state_signature'))
                                                        <div class="mt-1 truncate font-mono text-[10px] text-sky-700">State: {{ data_get($panel, 'normalized.data.state_signature') }}</div>
                                                    @endif
                                                    @if($panel['normalized']['mail'] !== [])
                                                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sky-800">
                                                            <span>Sichtbar: {{ data_get($panel, 'normalized.mail.visible_mail_count', 0) }}</span>
                                                            <span>Kandidaten: {{ data_get($panel, 'normalized.mail.candidate_count', 0) }}</span>
                                                            <span>Matches: {{ data_get($panel, 'normalized.mail.matched_count', 0) }}</span>
                                                            <span>Zeitfilter: {{ data_get($panel, 'normalized.mail.filtered_by_age_count', 0) }}</span>
                                                            <span>Betrefffilter: {{ data_get($panel, 'normalized.mail.filtered_by_subject_count', 0) }}</span>
                                                            <span>Polls: {{ data_get($panel, 'normalized.mail.poll_count', 0) }}</span>
                                                            <span>Liste: {{ data_get($panel, 'normalized.mail.list_visible') ? 'sichtbar' : 'nicht sichtbar' }}</span>
                                                        </div>
                                                    @elseif($panel['normalized']['counts'] !== [])
                                                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sky-800">
                                                            @foreach($panel['normalized']['counts'] as $countKey => $countValue)
                                                                <span>{{ $countKey }}: {{ $countValue }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                    @if($panel['normalized']['embedded']->isNotEmpty())
                                                        <div class="mt-2 space-y-1">
                                                            @foreach($panel['normalized']['embedded'] as $embedded)
                                                                <div class="rounded border border-sky-200 bg-white/70 px-2 py-1 text-sky-800">
                                                                    <span class="font-semibold">{{ data_get($embedded, 'embedded_class', 'embedded') }}</span>
                                                                    <span>· {{ data_get($embedded, 'embedded_workflow_name') ?: data_get($embedded, 'parent_task_key') ?: 'Embedded Workflow' }}</span>
                                                                    <span>· Tasks: {{ data_get($embedded, 'task_count', 0) }}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif

                                            @if($panel['return']['has'])
                                                <div class="rounded border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-800">
                                                    <span class="font-semibold">Rueckgabe:</span>
                                                    {{ $panel['return']['key'] }} = {{ $panel['return']['valueLabel'] }}
                                                    <span class="text-indigo-600">· OK: {{ $panel['return']['okLabel'] }}</span>
                                                </div>
                                            @endif

                                            @if($panel['tasks']->isNotEmpty())
                                                <div class="space-y-2">
                                                    @foreach($panel['tasks'] as $task)
                                                        <div class="rounded-md border border-slate-100 bg-slate-50 p-3 text-xs">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <div class="min-w-0">
                                                                    <div class="truncate font-semibold text-slate-900">{{ $task['title'] }}</div>
                                                                    <div class="mt-1 truncate text-slate-500">
                                                                        {{ $task['status'] }}
                                                                        @if($task['runner'])
                                                                            · {{ $task['runner'] }}
                                                                        @endif
                                                                        @if($task['node_script'])
                                                                            · {{ $task['node_script'] }}
                                                                        @elseif($task['php_handler'])
                                                                            · {{ $task['php_handler'] }}
                                                                        @endif
                                                                    </div>
                                                                    @if($task['return']['has'])
                                                                        <div class="mt-1 break-words text-indigo-700">
                                                                            Rueckgabe: {{ $task['return']['key'] }} = {{ $task['return']['valueLabel'] }}
                                                                            · OK: {{ $task['return']['okLabel'] }}
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                                <a
                                                                    href="{{ $jsonDownload($task['debug']) }}"
                                                                    download="{{ $downloadName($panel['title'].' '.$task['title']) }}.json"
                                                                    class="shrink-0 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-100"
                                                                >
                                                                    JSON
                                                                </a>
                                                            </div>

                                                            @if($task['mailScan']['has'])
                                                                <div class="mt-3 rounded border border-amber-100 bg-amber-50 p-3 text-[11px] text-amber-900">
                                                                    <div class="font-semibold uppercase tracking-wide text-amber-700">Mail-Scan Zeitfilter</div>
                                                                    @if(data_get($task, 'mailScan.search.enabled') || data_get($task, 'mailScan.search.searchInputSelector') || data_get($task, 'mailScan.search.search_input_selector'))
                                                                        <div class="mt-2 grid gap-1 rounded border border-amber-200 bg-white/60 p-2 text-amber-900 sm:grid-cols-2">
                                                                            <span>Webmail-Suche: {{ data_get($task, 'mailScan.search.enabled') ? 'aktiv' : 'inaktiv' }}</span>
                                                                            <span>Status: {{ data_get($task, 'mailScan.search.statusMessage') ?: data_get($task, 'mailScan.search.status_message') ?: data_get($task, 'mailScan.search.status') ?: '-' }}</span>
                                                                            <span class="break-words">Suchwert: {{ data_get($task, 'mailScan.search.searchValue') ?: data_get($task, 'mailScan.search.search_value') ?: data_get($task, 'mailScan.search.configuredSearchValue') ?: data_get($task, 'mailScan.search.configured_search_value') ?: '-' }}</span>
                                                                            <span class="break-words">Suchfeld: {{ data_get($task, 'mailScan.search.searchInputSelector') ?: data_get($task, 'mailScan.search.search_input_selector') ?: '-' }}</span>
                                                                            <span>Absenden: {{ data_get($task, 'mailScan.search.searchButtonSelector') || data_get($task, 'mailScan.search.search_button_selector') ? 'Button' : 'Enter' }}</span>
                                                                            <span>Warten: {{ data_get($task, 'mailScan.search.searchWaitMs', data_get($task, 'mailScan.search.search_wait_ms', 0)) }}ms</span>
                                                                        </div>
                                                                    @endif
                                                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                                                                        <span>Gesucht: {{ data_get($task, 'mailScan.debug.maxAgeSeconds') ? data_get($task, 'mailScan.debug.maxAgeSeconds').'s' : 'kein Zeitfilter' }}</span>
                                                                        @if(data_get($task, 'mailScan.debug.acceptedSince'))
                                                                            <span>Seit: {{ data_get($task, 'mailScan.debug.acceptedSince') }}</span>
                                                                        @endif
                                                                        <span>Unbekannte Zeit: {{ data_get($task, 'mailScan.debug.includeUnknownAge') ? 'erlaubt' : 'blockiert' }}</span>
                                                                        <span>Gefunden: {{ data_get($task, 'mailScan.debug.totalCandidates', 0) }}</span>
                                                                        <span>Akzeptiert: {{ data_get($task, 'mailScan.debug.acceptedCandidates', 0) }}</span>
                                                                        <span>Poll: {{ data_get($task, 'mailScan.debug.pollCount', 0) }}</span>
                                                                        <span>GMT Offset: {{ data_get($task, 'mailScan.debug.mailTimeGmtOffsetHours', 0) }}</span>
                                                                        @if(data_get($task, 'mailScan.debug.subjectTitleFiltersIgnored') || data_get($task, 'mailScan.debug.subject_title_filters_ignored'))
                                                                            <span>Betreff/Titel: ignoriert durch Webmail-Suche</span>
                                                                        @endif
                                                                    </div>
                                                                    @if(data_get($task, 'mailScan.debug.configuredSubjectFilters') || data_get($task, 'mailScan.debug.configuredTitleFilters'))
                                                                        <div class="mt-1 break-words text-amber-800">
                                                                            Konfigurierte Textfilter:
                                                                            Betreff {{ collect(data_get($task, 'mailScan.debug.configuredSubjectFilters', []))->implode(', ') ?: '-' }},
                                                                            Titel {{ collect(data_get($task, 'mailScan.debug.configuredTitleFilters', []))->implode(', ') ?: '-' }}
                                                                        </div>
                                                                    @endif
                                                                    @if(data_get($task, 'mailScan.debug.foundDateTexts'))
                                                                        <div class="mt-1 break-words text-amber-800">
                                                                            Zeittexte: {{ collect(data_get($task, 'mailScan.debug.foundDateTexts'))->take(10)->implode(' | ') }}
                                                                        </div>
                                                                    @endif
                                                                    @if($task['mailScan']['candidates'] !== [])
                                                                        <div class="mt-2 overflow-x-auto" data-workflow-preview-scrollbar>
                                                                            <table class="min-w-full text-left">
                                                                                <thead class="text-[10px] uppercase tracking-wide text-amber-700">
                                                                                    <tr>
                                                                                        <th class="py-1 pr-3">OK</th>
                                                                                        <th class="py-1 pr-3">Zeittext</th>
                                                                                        <th class="py-1 pr-3">Empfangen</th>
                                                                                        <th class="py-1 pr-3">Alter</th>
                                                                                        <th class="py-1 pr-3">Art</th>
                                                                                        <th class="py-1 pr-3">Grund</th>
                                                                                        <th class="py-1 pr-3">Text</th>
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody class="align-top text-amber-950">
                                                                                    @foreach($task['mailScan']['candidates'] as $candidate)
                                                                                        <tr class="border-t border-amber-100">
                                                                                            <td class="py-1 pr-3 font-semibold">{{ data_get($candidate, 'accepted') ? 'ja' : 'nein' }}</td>
                                                                                            <td class="py-1 pr-3">{{ data_get($candidate, 'dateText') ?: '-' }}</td>
                                                                                            <td class="py-1 pr-3">{{ data_get($candidate, 'receivedAtBrowser') ?: data_get($candidate, 'receivedAt') ?: '-' }}</td>
                                                                                            <td class="py-1 pr-3">{{ data_get($candidate, 'ageLabel') ?: '-' }}</td>
                                                                                            <td class="py-1 pr-3">{{ data_get($candidate, 'dateParseKind') ?: '-' }}</td>
                                                                                            <td class="py-1 pr-3">{{ data_get($candidate, 'reason') ?: '-' }}</td>
                                                                                            <td class="max-w-[18rem] truncate py-1 pr-3">{{ data_get($candidate, 'subject') ?: data_get($candidate, 'title') ?: data_get($candidate, 'text') }}</td>
                                                                                        </tr>
                                                                                    @endforeach
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if($panel['events'] !== [])
                                                <div class="space-y-1">
                                                    @foreach(array_reverse($panel['events']) as $event)
                                                        <div class="rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-500">
                                                            <span class="font-semibold text-slate-700">{{ data_get($event, 'stage', data_get($event, 'type', '-')) }}</span>
                                                            <span>{{ data_get($event, 'message', data_get($event, 'text', '')) }}</span>
                                                            @if(data_get($event, 'at'))
                                                                <span class="text-slate-400">· {{ $formatWorkflowTimestamp(data_get($event, 'at')) }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </details>
                                @empty
                                    <div class="text-sm text-slate-500">Noch keine Debugdaten.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div>
                        <button type="button" x-on:click="logsOpen = logsOpen === 'variables' ? '' : 'variables'" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                            <span>
                                <span class="block text-sm font-semibold text-slate-900">Variablen</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ count($workflowVariables) }} Werte</span>
                            </span>
                            <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500" x-text="logsOpen === 'variables' ? 'Offen' : 'Oeffnen'"></span>
                        </button>
                        <div x-cloak x-show="logsOpen === 'variables'" x-collapse.duration.180ms class="border-t border-slate-100">
                            @if($workflowVariables !== [])
                                <div class="max-h-80 overflow-auto">
                                    <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                                        <thead class="sticky top-0 bg-slate-50 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th class="w-56 px-4 py-2">Variable</th>
                                                <th class="px-4 py-2">Aktueller Wert</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            @foreach($workflowVariables as $variableKey => $variableValue)
                                                <tr>
                                                    <td class="px-4 py-2 align-top font-mono text-[11px] font-semibold text-slate-700">{{ $variableKey }}</td>
                                                    <td class="px-4 py-2 align-top font-mono text-[11px] text-slate-600">
                                                        <div class="max-w-[54rem] whitespace-pre-wrap break-words">{{ $formatWorkflowValue($variableValue) }}</div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="px-4 py-5 text-sm text-slate-500">Noch keine Workflow-Variablen gespeichert.</div>
                            @endif
                        </div>
                    </div>

                    @if($debugArtifactGroups->isNotEmpty())
                        <div>
                            <button type="button" x-on:click="logsOpen = logsOpen === 'artifacts' ? '' : 'artifacts'" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-900">Debug-Artefakte</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">DOM-Snapshots und Screenshots aus dem Dev-Debug-Modus</span>
                                </span>
                                <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500" x-text="logsOpen === 'artifacts' ? 'Offen' : 'Oeffnen'"></span>
                            </button>
                            <div x-cloak x-show="logsOpen === 'artifacts'" x-collapse.duration.180ms class="space-y-4 border-t border-slate-100 bg-slate-50 p-4">
                                @foreach($debugArtifactGroups as $artifactGroup)
                                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-slate-900">
                                                    @if($artifactGroup['position'])
                                                        Schritt {{ $artifactGroup['position'] }} ·
                                                    @endif
                                                    {{ $artifactGroup['step'] }}
                                                </div>
                                                <div class="mt-1 text-xs text-slate-500">{{ $artifactGroup['artifacts']->count() }} Artefakte</div>
                                            </div>
                                        </div>

                                        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                            @foreach($artifactGroup['artifacts'] as $artifact)
                                                <div class="overflow-hidden rounded-md border {{ $artifact['status'] === 'success' ? 'border-slate-200 bg-white' : 'border-amber-200 bg-amber-50' }}">
                                                    <div class="flex items-start justify-between gap-2 border-b border-slate-100 px-3 py-2 text-xs">
                                                        <div class="min-w-0">
                                                            <div class="font-semibold uppercase tracking-wide {{ $artifact['status'] === 'success' ? 'text-slate-700' : 'text-amber-800' }}">
                                                                {{ $artifact['phase'] }} · {{ $artifact['type'] }}
                                                            </div>
                                                            <div class="mt-1 truncate text-[11px] text-slate-500">{{ $artifact['browser_window'] }}</div>
                                                        </div>
                                                        <span class="shrink-0 rounded border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $artifact['status'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-300 bg-amber-100 text-amber-800' }}">
                                                            {{ $artifact['status'] }}
                                                        </span>
                                                    </div>

                                                    @if($artifact['status'] === 'success' && $artifact['type'] === 'screenshot' && $artifact['url'])
                                                        <a href="{{ $artifact['url'] }}" target="_blank" rel="noopener" class="block bg-slate-100">
                                                            <img src="{{ $artifact['url'] }}" alt="Debug Screenshot" class="aspect-video w-full object-contain">
                                                        </a>
                                                    @elseif($artifact['status'] === 'success' && $artifact['type'] === 'dom')
                                                        <div class="flex aspect-video items-center justify-center bg-slate-100 px-3 text-center text-xs font-semibold text-slate-600">
                                                            DOM Snapshot
                                                        </div>
                                                    @else
                                                        <div class="flex aspect-video items-center justify-center px-3 text-center text-xs text-amber-800">
                                                            {{ $artifact['error'] ?: 'Capture fehlgeschlagen.' }}
                                                        </div>
                                                    @endif

                                                    <div class="space-y-2 px-3 py-2 text-[11px] text-slate-500">
                                                        @if($artifact['title'])
                                                            <div class="truncate font-semibold text-slate-700">{{ $artifact['title'] }}</div>
                                                        @endif
                                                        @if($artifact['current_url'])
                                                            <div class="truncate">{{ $artifact['current_url'] }}</div>
                                                        @endif
                                                        <div>{{ $formatWorkflowTimestamp($artifact['created_at']) }}</div>
                                                        @if($artifact['status'] === 'success')
                                                            <div class="flex flex-wrap gap-2">
                                                                <a href="{{ $artifact['url'] }}" target="_blank" rel="noopener" class="rounded border border-slate-200 px-2 py-1 font-semibold text-slate-600 hover:bg-slate-50">Vorschau</a>
                                                                <a href="{{ $artifact['download_url'] }}" class="rounded border border-slate-200 px-2 py-1 font-semibold text-slate-600 hover:bg-slate-50">Download</a>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
