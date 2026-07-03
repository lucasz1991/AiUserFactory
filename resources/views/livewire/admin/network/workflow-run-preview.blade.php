<div
    class="space-y-4"
    data-assistant-highlight="run_preview:{{ $workflowRun?->id ?? 'empty' }}"
    data-assistant-highlight-key="{{ $workflowRun?->id ?? 'empty' }}"
    @if($polling) wire:poll.3s="refresh" @endif
>
    @if(! $workflowRun)
        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Dieser Workflow-Lauf wurde noch nicht geladen.
        </div>
    @else
        <div x-data="{ overviewOpen: false, logsOpen: 'timeline' }" class="space-y-4">
            @if($processSummary)
                <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <span class="font-semibold text-slate-900">Prozess:</span>
                    PID {{ $processSummary['pid'] ?? '-' }} · {{ $processSummary['process_type'] ?? '-' }} · {{ $processSummary['status'] ?? '-' }}
                </div>
            @endif

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="bg-slate-950 text-white" style="aspect-ratio: 21 / 5; min-height: 8rem; max-height: 12rem;">
                    <div class="flex h-full flex-col justify-between gap-4 p-4 sm:p-5">
                        <div class="flex min-w-0 items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Workflow-Vorschau</p>
                                <h3 class="mt-1 truncate text-xl font-semibold text-white sm:text-2xl">
                                    {{ $workflowRun->workflow?->name ?? 'Workflow' }}
                                </h3>
                                <p class="mt-1 max-w-4xl truncate text-xs text-slate-300">
                                    {{ data_get($latestStatusResult, 'statusMessage', data_get($latestStatusResult, 'message', $workflowRun->status)) ?: $workflowRun->status }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-workflows.status-badge :status="$workflowRun->status" />
                                <button
                                    type="button"
                                    x-on:click="overviewOpen = !overviewOpen"
                                    class="rounded-md border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/15"
                                >
                                    <span x-show="!overviewOpen">Maximieren</span>
                                    <span x-cloak x-show="overviewOpen">Minimieren</span>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-4 gap-2 text-xs text-slate-300">
                            <div class="min-w-0 rounded-md border border-white/10 bg-white/5 px-3 py-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Run</div>
                                <div class="mt-1 truncate font-semibold text-white">#{{ $workflowRun->id }}</div>
                            </div>
                            <div class="min-w-0 rounded-md border border-white/10 bg-white/5 px-3 py-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Dauer</div>
                                <div class="mt-1 truncate font-semibold text-white">{{ $workflowDurationLabel }}</div>
                            </div>
                            <div class="min-w-0 rounded-md border border-white/10 bg-white/5 px-3 py-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Browser</div>
                                <div class="mt-1 truncate font-semibold text-white">{{ $screenshotPanels->count() }}</div>
                            </div>
                            <div class="min-w-0 rounded-md border border-white/10 bg-white/5 px-3 py-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Rueckgabe</div>
                                <div class="mt-1 truncate font-semibold {{ $workflowReturn['has'] ? 'text-emerald-200' : 'text-white' }}">
                                    {{ $workflowReturn['has'] ? $workflowReturn['key'].' = '.$workflowReturn['valueLabel'] : '-' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-cloak x-show="overviewOpen" x-collapse.duration.180ms class="border-t border-slate-100 bg-white px-4 py-3">
                    <x-workflows.minimap
                        :workflow-run="$workflowRun"
                        :active-step-id="$activeStepId"
                        :active-task-key="$activeTaskKey"
                    />
                </div>
            </section>

            @if($embeddedCards->isNotEmpty())
                <section class="space-y-2">
                    @foreach($embeddedCards as $card)
                        <article x-data="{ expanded: false }" class="overflow-hidden rounded-lg border border-sky-200 bg-sky-50 shadow-sm">
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
                                <span class="shrink-0 rounded-md border border-sky-200 bg-white px-3 py-1.5 text-xs font-semibold text-sky-800">
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

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Browserfenster</div>
                        <div class="mt-1 text-sm text-slate-600">Live-Screenshots je offenem Fenster</div>
                    </div>
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                        {{ $screenshotPanels->count() }}
                    </span>
                </div>

                <div class="border-t border-slate-100 bg-slate-100 p-3">
                    @if($screenshotPanels->isNotEmpty())
                        <div class="flex flex-nowrap gap-3 overflow-x-auto pb-1">
                            @foreach($screenshotPanels as $panel)
                                <article
                                    class="min-w-0 shrink-0 overflow-hidden rounded-lg border border-slate-800 bg-slate-950 shadow-sm"
                                    style="flex: 0 0 {{ $browserPanelBasis }}%; max-width: {{ $browserPanelBasis }}%; min-width: {{ $browserPanelMinWidth }};"
                                >
                                    <div class="flex items-start justify-between gap-3 border-b border-slate-800 px-3 py-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-xs font-semibold uppercase tracking-wide text-slate-200">
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
                                                class="shrink-0 rounded border border-slate-700 px-2 py-1 text-[10px] font-semibold text-slate-200 hover:bg-slate-800"
                                            >
                                                DOM
                                            </a>
                                        @endif
                                    </div>

                                    @if($panel['image'])
                                        <a href="{{ $panel['image'] }}" target="_blank" rel="noopener" class="relative block bg-slate-950">
                                            <img src="{{ $panel['image'] }}" alt="{{ $panel['title'] }} Screenshot" class="aspect-video w-full object-contain">
                                            <span class="absolute right-2 top-2 max-w-[70%] truncate rounded border border-white/20 bg-slate-950/85 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">
                                                {{ $panel['windowKey'] ?? $panel['title'] }}
                                            </span>
                                        </a>
                                    @else
                                        <div class="flex aspect-video items-center justify-center px-4 text-center text-sm font-semibold text-slate-300">
                                            Noch kein Screenshot verfuegbar.
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <div class="rounded-md border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">
                            Fuer diesen Workflow-Lauf wurden noch keine Browser-Screenshots gespeichert.
                        </div>
                    @endif
                </div>
            </section>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
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
                            <div class="max-h-80 space-y-2 overflow-auto pr-1">
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
                                                                        <div class="mt-2 overflow-x-auto">
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
                                                        <a href="{{ $artifact['url'] }}" target="_blank" rel="noopener" class="block bg-slate-950">
                                                            <img src="{{ $artifact['url'] }}" alt="Debug Screenshot" class="aspect-video w-full object-contain">
                                                        </a>
                                                    @elseif($artifact['status'] === 'success' && $artifact['type'] === 'dom')
                                                        <div class="flex aspect-video items-center justify-center bg-slate-900 px-3 text-center text-xs font-semibold text-slate-200">
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
