<section
    @if($autoRefresh) wire:poll.5s="syncProcesses(false)" @endif
    class="space-y-4"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-slate-900">Prozesse dieser Person</h3>
            <p class="mt-1 text-sm text-slate-500">Standardmaessig werden nur die eigentlichen Node-Root-Scripte angezeigt. Browser-Kindprozesse sind optional.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="toggleChildProcesses" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                {{ $showChildProcesses ? 'Browser-Kinder ausblenden' : 'Browser-Kinder anzeigen' }}
            </button>
            <button type="button" wire:click="syncProcesses" wire:loading.attr="disabled" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                Aktualisieren
            </button>
        </div>
    </div>

    @if($notice)
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-900">
            {{ $notice }}
        </div>
    @endif

    @if(! $tableReady)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Die Prozess-Tabelle ist noch nicht vorhanden. Bitte Migrationen ausfuehren.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Prozesse</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['total'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Laufend</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-900">{{ $stats['running'] }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Ohne Lebenszeichen</p>
                <p class="mt-2 text-2xl font-semibold text-amber-900">{{ $stats['stale'] }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Browser-Kinder</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['children'] }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1 text-xs font-semibold">
                    @foreach(['running' => 'Laufend', 'stale' => 'Stale', 'exited' => 'Beendet', 'all' => 'Alle'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')" class="rounded px-3 py-1.5 {{ $filter === $value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-[1240px] divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Prozess</th>
                            <th class="px-4 py-3 text-left">Run</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Heartbeat</th>
                            <th class="px-4 py-3 text-left">Letzter Schritt</th>
                            <th class="px-4 py-3 text-left">Kommando</th>
                            <th class="px-4 py-3 text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($processes as $process)
                            @php
                                $isWorkflowProcess = $process->process_type === 'workflow-run';
                                $workflowRunPreview = $process->relationLoaded('workflowRunPreview') ? $process->getRelation('workflowRunPreview') : null;
                                $heartbeatAge = $process->heartbeat_at ? (int) $process->heartbeat_at->diffInSeconds(now()) : null;
                                $isStale = ! $isWorkflowProcess && $process->isRunning() && ($heartbeatAge === null || $heartbeatAge > 30);
                            @endphp
                            <tr class="{{ $isStale ? 'bg-amber-50/70' : '' }}">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="font-semibold text-slate-900">{{ $isWorkflowProcess ? 'Workflow #'.abs((int) $process->pid) : 'PID '.$process->pid }}</div>
                                    <div class="text-xs text-slate-500">{{ $isWorkflowProcess ? 'Root-Prozess' : 'PPID '.($process->parent_pid ?: '-') }}</div>
                                    <div class="mt-1 text-xs {{ $process->is_root ? 'font-semibold text-blue-700' : 'text-slate-500' }}">
                                        {{ $isWorkflowProcess ? 'Workflow' : ($process->is_root ? 'Node-Root' : 'Browser-Kind') }} · {{ $process->process_type }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="break-all font-semibold text-slate-900">{{ $process->run_id ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $process->process_key ?: data_get($process->metadata, 'process_identity.processKey', '-') }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $process->isRunning() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                                        {{ $process->status }}
                                    </span>
                                    @if($process->restart_count > 0)
                                        <div class="mt-1 text-xs font-semibold text-blue-700">Restarts: {{ $process->restart_count }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-600">
                                    @if($isWorkflowProcess)
                                        <div class="font-semibold text-blue-700">Workflow-Lauf</div>
                                        <div>{{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
                                    @elseif($process->heartbeat_at)
                                        <div class="font-semibold {{ $isStale ? 'text-amber-700' : 'text-emerald-700' }}">
                                            {{ $isStale ? 'Stale' : 'Aktiv' }}
                                        </div>
                                        <div>{{ $heartbeatAge }}s alt</div>
                                        <div>{{ $process->heartbeat_at->format('d.m.Y H:i:s') }}</div>
                                    @else
                                        <div class="font-semibold text-amber-700">Kein Heartbeat</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-900">{{ $process->last_stage ?: data_get($process->metadata, 'status_stage', '-') }}</div>
                                    <div class="mt-1 max-w-sm break-words text-xs text-slate-600">{{ $process->last_message ?: $process->action_message ?: '-' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="max-w-md break-all text-xs text-slate-700">{{ $process->short_command ?: $process->command ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">zuletzt gesehen: {{ optional($process->last_seen_at)->format('d.m.Y H:i:s') ?: '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if($isWorkflowProcess && (int) data_get($process->metadata, 'workflow_run_db_id') > 0)
                                        <button type="button" wire:click="openWorkflowPreview({{ (int) data_get($process->metadata, 'workflow_run_db_id') }})" class="rounded border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                            Vorschau
                                        </button>
                                    @elseif($workflowRunPreview)
                                        <button type="button" wire:click="openWorkflowPreview({{ $workflowRunPreview->id }})" class="rounded border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                            Workflow
                                        </button>
                                    @elseif($process->is_root && $process->run_id && in_array($process->run_type, ['mail-registration', 'webmail-session'], true))
                                        <button type="button" wire:click="openPreview('{{ $process->run_id }}', '{{ $process->run_type }}')" class="rounded border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                            Vorschau
                                        </button>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">
                                    Keine Prozesse fuer diese Person gefunden.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <x-dialog-modal wire:model="showPreviewModal" maxWidth="6xl">
        <x-slot name="title">
            Prozess-Vorschau
        </x-slot>

        <x-slot name="content">
            @php
                $previewPollSeconds = max(1, min(60, (int) data_get($previewStatus, 'livePreviewPollIntervalSeconds', data_get($previewStatus, 'livePreviewIntervalSeconds', 3))));
            @endphp
            <div
                @if($showPreviewModal && data_get($previewStatus, 'isRunning')) wire:poll.{{ $previewPollSeconds }}s="refreshPreview" @endif
                class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,460px)]"
            >
                <div class="grid gap-3">
                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                            <div class="min-w-0">
                                <div>{{ $previewRunType === 'webmail-session' ? 'Webmail' : 'Registrierung' }}</div>
                                @include('livewire.admin.config.partials.browser-window-status', [
                                    'windowStatus' => $previewRunType === 'webmail-session'
                                        ? data_get($previewStatus, 'windowStatus')
                                        : data_get($previewStatus, 'registrationWindowStatus'),
                                ])
                            </div>
                            @if(data_get($previewStatus, 'registrationDebugDomUrl') || data_get($previewStatus, 'debugDomUrl'))
                                <a href="{{ data_get($previewStatus, 'registrationDebugDomUrl', data_get($previewStatus, 'debugDomUrl')) }}" download="process-preview-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                    DOM
                                </a>
                            @endif
                        </div>
                        @if(data_get($previewStatus, 'screenshotUrl'))
                            <img src="{{ data_get($previewStatus, 'screenshotUrl') }}" alt="Live Screenshot" class="aspect-video w-full object-contain">
                        @elseif(data_get($previewStatus, 'livePreviewEnabled') === false)
                            <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                Live-Screenshots sind deaktiviert.
                            </div>
                        @else
                            <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                Noch kein Screenshot verfuegbar.
                            </div>
                        @endif
                    </div>

                    @if($previewRunType === 'mail-registration')
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                                <div class="min-w-0">
                                    <div>Webmail</div>
                                    @include('livewire.admin.config.partials.browser-window-status', [
                                        'windowStatus' => data_get($previewStatus, 'webmailWindowStatus'),
                                    ])
                                </div>
                                @if(data_get($previewStatus, 'webmailDebugDomUrl'))
                                    <a href="{{ data_get($previewStatus, 'webmailDebugDomUrl') }}" download="process-preview-webmail-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                        DOM
                                    </a>
                                @endif
                            </div>
                            @if(data_get($previewStatus, 'webmailScreenshotUrl'))
                                <img src="{{ data_get($previewStatus, 'webmailScreenshotUrl') }}" alt="Webmail Live Screenshot" class="aspect-video w-full object-contain">
                            @else
                                <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                                    Webmail-Fenster noch nicht geoeffnet.
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="space-y-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900">
                            {{ data_get($previewStatus, 'statusMessage', data_get($previewStatus, 'message', 'Noch kein Status verfuegbar.')) }}
                        </div>
                        <div class="mt-2 break-all text-xs text-slate-500">
                            Run: {{ $previewRunId ?: '-' }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Script: {{ data_get($previewStatus, 'scriptVersionLabel', data_get($previewStatus, 'scriptName', '-')) }}
                        </div>
                        @if(data_get($previewStatus, 'processHeartbeatStatus.statusText'))
                            <div class="mt-2 rounded-md {{ data_get($previewStatus, 'processHeartbeatStatus.stale') ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800' }} px-3 py-2 text-xs font-semibold">
                                {{ data_get($previewStatus, 'processHeartbeatStatus.statusText') }}
                            </div>
                        @endif
                    </div>

                    <div class="max-h-96 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                        <div class="mt-3 space-y-2">
                            @forelse(array_reverse(data_get($previewStatus, 'events', [])) as $event)
                                <div class="rounded-md bg-white p-3 text-xs shadow-sm">
                                    <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', '-') }}</div>
                                    <div class="mt-1 text-slate-600">{{ data_get($event, 'message', '-') }}</div>
                                    <div class="mt-1 text-slate-400">{{ data_get($event, 'at', '') }}</div>
                                </div>
                            @empty
                                <div class="text-sm text-slate-500">Noch keine Ablaufdaten.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <button type="button" wire:click="closePreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Schliessen
            </button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showWorkflowPreviewModal" maxWidth="6xl">
        <x-slot name="title">
            Workflow-Vorschau
        </x-slot>

        <x-slot name="content">
            @if($previewWorkflowRun)
                <x-workflows.run-preview :workflow-run="$previewWorkflowRun" />
            @else
                <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                    Dieser Workflow-Lauf wurde nicht gefunden.
                </div>
            @endif
        </x-slot>

        <x-slot name="footer">
            <button type="button" wire:click="closeWorkflowPreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Schliessen
            </button>
        </x-slot>
    </x-dialog-modal>
</section>
