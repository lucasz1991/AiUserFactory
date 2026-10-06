@if(($embeddedWorkflowMaps ?? []) !== [])
    <section
        wire:key="studio-child-overlay-{{ $session->id }}-{{ $run->id }}"
        x-data="{ expanded: true }"
        class="ff-workflow-embedded-overlay mt-3"
        data-workflow-embedded-minimaps
        aria-label="Unter-Workflows im Testlauf"
    >
        <button
            type="button"
            data-workflow-embedded-toggle
            x-on:click="expanded = ! expanded"
            x-bind:aria-expanded="expanded.toString()"
            aria-expanded="true"
            aria-controls="studio-child-rail-{{ $session->id }}-{{ $run->id }}"
            class="ff-workflow-embedded-toggle"
        >
            <span class="font-semibold">Unter-Workflows</span>
            <span class="ff-workflow-embedded-count">{{ count($embeddedWorkflowMaps) }}</span>
            <span class="sr-only" x-text="expanded ? 'einklappen' : 'ausklappen'"></span>
            <svg class="ml-auto h-4 w-4 shrink-0" x-bind:class="expanded ? '' : 'rotate-180'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m6 12 4-4 4 4" /></svg>
        </button>
        <div
            id="studio-child-rail-{{ $session->id }}-{{ $run->id }}"
            x-cloak
            x-show="expanded"
            data-workflow-embedded-rail
            class="ff-workflow-embedded-rail space-y-3"
            tabindex="0"
            role="region"
            aria-label="Eingebettete Workflows, bei Bedarf horizontal scrollen"
        >
        @foreach($embeddedWorkflowMaps as $childMap)
            <article
                wire:key="studio-child-map-{{ $session->id }}-{{ $run->id }}-{{ $childMap['frame_key'] }}"
                data-workflow-embedded-frame="{{ $childMap['frame_key'] }}"
                data-workflow-embedded-parent="{{ $childMap['parent_frame_key'] }}"
                data-workflow-embedded-depth="{{ $childMap['depth'] }}"
                data-workflow-embedded-status="{{ $childMap['status'] }}"
                class="min-w-0 rounded-xl border border-slate-200 bg-white"
                style="margin-left: {{ min(5, $childMap['depth'] - 1) * 12 }}px"
                aria-label="Unter-Workflow {{ $childMap['name'] }}, Ebene {{ $childMap['depth'] }}"
            >
                <header class="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-slate-100 px-3 py-2">
                    <div class="flex min-w-0 items-center gap-2 text-xs">
                        <span class="shrink-0 text-slate-400" aria-hidden="true">↳</span>
                        <strong class="truncate font-semibold text-slate-800" title="{{ $childMap['include_title'] }}">{{ $childMap['name'] }}</strong>
                        <span class="shrink-0 text-[10px] text-slate-500">Ebene {{ $childMap['depth'] }}</span>
                    </div>
                    <div class="flex min-w-0 items-center gap-2">
                        @if($childMap['browser_windows'] !== [])
                            <span class="max-w-64 truncate text-[10px] text-slate-500" title="Browserfenster: {{ implode(', ', $childMap['browser_windows']) }}">{{ implode(', ', $childMap['browser_windows']) }}</span>
                        @endif
                        @if(in_array($childMap['status'], ['configured', 'interrupted'], true))
                            <span class="inline-flex shrink-0 rounded-full bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-600">{{ $childMap['status'] === 'configured' ? 'Noch nicht gelaufen' : 'Angehalten' }}</span>
                        @else
                            <x-workflows.status-badge :status="$childMap['status']" />
                        @endif
                    </div>
                </header>
                <div
                    data-workflow-embedded-map-body
                    class="ff-workflow-embedded-map-body {{ $childMap['workflow']->steps->count() === 1 ? 'ff-workflow-embedded-map-body--single-list' : '' }} max-h-80 lg:max-h-48 min-h-0 overflow-auto overscroll-contain p-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                    tabindex="0"
                    role="region"
                    aria-label="Workflow-Karte {{ $childMap['name'] }}, bei Bedarf scrollen"
                >
                    <x-workflows.minimap
                        :workflow="$childMap['workflow']"
                        :workflow-run="$childMap['run']"
                        :route-map="$childMap['route_map']"
                        :show-header="false"
                        :compact="true"
                        :zoomable="true"
                        :selectable-tasks="false"
                        :live-flow="true"
                        :runtime-task="$childMap['runtime_task']"
                        initial-zoom="overview"
                        :instance="'studio-child-'.$session->id.'-'.$run->id.'-'.$childMap['frame_key']"
                    />
                </div>
            </article>
        @endforeach
        </div>
    </section>
@endif
