@php
    $inspectorTask = $runFocus['task'] ?? [];
    // Nur unkritische Konfigurationsfelder zeigen; Literalwerte (z. B. Passwoerter) bleiben im Editor.
    $inspectorFields = collect([
        'Typ' => $inspectorTask['task_key'] ?? null,
        'Runner' => $inspectorTask['runner'] ?? null,
        'Fenster' => $runFocus['browser_window'] ?: null,
        'Timeout' => isset($inspectorTask['timeout_seconds']) ? $inspectorTask['timeout_seconds'].' s' : null,
        'URL' => $inspectorTask['url'] ?? null,
        'Selektor' => $inspectorTask['selector'] ?? $inspectorTask['element_selector'] ?? $inspectorTask['input_selector'] ?? null,
        'Workflow' => $inspectorTask['workflow_slug'] ?? null,
    ])->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');
    $inspectorStatus = $runFocus['failure'] ? 'failed' : ($runFocus['live'] ? $runFocus['status'] : null);
    $inspectorStatusLabel = match ($inspectorStatus) {
        'failed' => 'Fehler',
        'running' => 'Läuft',
        'waiting' => 'Wartet',
        'pending' => 'Als Nächstes',
        default => null,
    };
@endphp

<aside
    wire:key="studio-run-inspector-{{ $session->id }}"
    x-data="{ tab: 'task' }"
    class="ff-studio-inspector flex min-h-0 min-w-0 flex-col overflow-hidden"
    data-studio-run-inspector
    aria-label="Aktiver Task und Laufdaten"
>
    <div class="ff-studio-inspector-tabs shrink-0" role="tablist" aria-label="Inspektor">
        @foreach(['task' => $runFocus['live'] ? 'Aktiver Task' : 'Task', 'data' => 'Laufdaten', 'log' => 'Log'] as $tabKey => $tabLabel)
            <button type="button" role="tab" x-on:click="tab = @js($tabKey)" x-bind:aria-selected="(tab === @js($tabKey)).toString()">{{ $tabLabel }}</button>
        @endforeach
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-3">
        <div x-show="tab === 'task'" class="space-y-3" data-studio-inspector-task>
            @if($runFocus['task_key'] !== '')
                <section class="ff-studio-inspector-box">
                    <p class="truncate text-[10px] text-slate-500">
                        @if($runFocus['step_number'])Liste {{ $runFocus['step_number'] }} › @endif{{ $runFocus['step_name'] }}
                        @if($runFocus['task_number']) › Task {{ $runFocus['task_number'] }}/{{ $runFocus['task_count'] }}@endif
                    </p>
                    <h3 class="mt-1 break-words text-sm font-bold text-slate-950">{{ $runFocus['task_title'] }}</h3>
                    @if($inspectorStatusLabel)
                        <span class="ff-studio-focus-chip mt-2" data-tone="{{ $inspectorStatus === 'failed' ? 'failed' : 'active' }}" data-failed="{{ $inspectorStatus === 'failed' ? 'true' : 'false' }}">
                            <span class="ff-studio-focus-dot" aria-hidden="true"></span>{{ $inspectorStatusLabel }}
                        </span>
                    @endif
                    @if($inspectorFields->isNotEmpty())
                        <dl class="mt-3 grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-[11px]">
                            @foreach($inspectorFields as $fieldLabel => $fieldValue)
                                <dt class="text-slate-500">{{ $fieldLabel }}</dt>
                                <dd class="truncate font-semibold text-slate-800" title="{{ $fieldValue }}">{{ $fieldValue }}</dd>
                            @endforeach
                        </dl>
                    @endif
                    @if(! $autonomousMode && ! $historicalRunView && $runFocus['step_id'] > 0)
                        <button type="button" wire:click="editTask({{ $runFocus['step_id'] }}, @js($runFocus['task_key']))" class="ff-studio-control mt-3 w-full justify-center">Task bearbeiten</button>
                    @endif
                </section>
            @else
                <p class="rounded-xl border border-dashed border-slate-300 p-4 text-center text-xs text-slate-500">Sobald der Test läuft, erscheint hier der aktive Task. Ohne Lauf zeigt der Inspektor die ausgewählte Task.</p>
            @endif

            @if($runFocus['failure'])
                <section class="ff-studio-inspector-box border-rose-200 bg-rose-50/60">
                    <h4 class="ff-studio-inspector-heading text-rose-700">Fehler</h4>
                    <p class="break-words text-xs leading-5 text-rose-900">{{ \Illuminate\Support\Str::limit($runFocus['failure']['message'], 600) }}</p>
                </section>
            @endif

            <section class="ff-studio-inspector-box">
                <h4 class="ff-studio-inspector-heading">Letzte Ereignisse</h4>
                <ol class="divide-y divide-slate-100 text-[11px]">
                    @forelse($events->reverse()->take(6) as $event)
                        <li class="grid grid-cols-[3.5rem_minmax(0,1fr)] gap-2 py-1.5">
                            <time class="font-mono text-slate-400">{{ $event->occurred_at?->format('H:i:s') }}</time>
                            <span class="break-words text-slate-700">{{ \Illuminate\Support\Str::limit($event->message ?: $event->event_type, 140) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">Noch keine Ereignisse.</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <div x-cloak x-show="tab === 'data'" class="space-y-3" data-studio-inspector-data>
            <dl class="grid grid-cols-2 gap-2 text-[11px]">
                <div class="ff-studio-inspector-box"><dt class="text-slate-500">Lauf</dt><dd class="mt-0.5 font-bold text-slate-900">{{ $run ? '#'.$run->id : '–' }}</dd></div>
                <div class="ff-studio-inspector-box"><dt class="text-slate-500">Status</dt><dd class="mt-0.5 font-bold text-slate-900">{{ $statusLabel }}</dd></div>
                <div class="ff-studio-inspector-box col-span-2"><dt class="text-slate-500">Aktive Liste</dt><dd class="mt-0.5 truncate font-bold text-slate-900">{{ $steps->firstWhere('id', (int) $cursorStepId)?->name ?? '–' }}</dd></div>
            </dl>
            <section class="ff-studio-inspector-box">
                <h4 class="ff-studio-inspector-heading">Workflow-Variablen</h4>
                <pre class="max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-900 p-3 font-mono text-[10.5px] leading-5 text-slate-100">{{ json_encode($variables ?: new stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </section>
            <button type="button" wire:click="openToolModal('data')" class="ff-studio-control w-full justify-center">Alle Laufdaten öffnen</button>
        </div>

        <div x-cloak x-show="tab === 'log'" data-studio-inspector-log>
            <ol class="space-y-1.5 text-[11px]">
                @forelse($events->reverse()->take(25) as $event)
                    <li class="ff-studio-inspector-box !p-2">
                        <div class="flex items-center justify-between gap-2"><strong class="truncate text-slate-800">{{ $event->event_type }}</strong><time class="shrink-0 font-mono text-[10px] text-slate-400">{{ $event->occurred_at?->format('H:i:s') }}</time></div>
                        <p class="mt-0.5 break-words text-slate-600">{{ $event->message }}</p>
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Noch keine Ereignisse.</li>
                @endforelse
            </ol>
            <button type="button" wire:click="openToolModal('logs')" class="ff-studio-control mt-3 w-full justify-center">Vollständiges Log</button>
        </div>
    </div>
</aside>
