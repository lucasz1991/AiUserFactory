@php
    $toolMeta = [
        'browser' => ['Browserfenster', 'Live-Vorschauen, URLs und DOM-Zugriffe'],
        'data' => ['Laufdaten', 'Cursor, Loop-Zustand und Laufkontext'],
        'checkpoints' => ['Checkpoints', 'Gespeicherte Wiederaufnahme- und Fehlerpunkte'],
        'logs' => ['Logs', 'Zeitlicher Verlauf der Studio-Sitzung'],
        'debug' => ['Debug', 'Probe-Ergebnis und technische Task-Versuche'],
        'steps' => ['Schritte', 'Listen und aktueller Ausführungsstatus'],
        'tasks' => ['Tasks', 'Alle konfigurierten Tasks auswählen und bearbeiten'],
        'variables' => ['Variablen', 'Aktueller persistierter Workflow-Zustand'],
        'artifacts' => ['Debug-Artefakte', 'Screenshots und DOM-Snapshots des Laufs'],
    ];
    [$toolTitle, $toolDescription] = $toolMeta[$activeToolModal] ?? ['Werkzeug', 'Workflow-Diagnose'];
@endphp

<div
    wire:key="workflow-studio-tool-modal-{{ $activeToolModal }}"
    wire:click.self="closeToolModal"
    {{-- Standard-z-Skala statt Arbitrary-Klasse: z-40 existiert in jedem (auch alten/gecachten) CSS-Build --}}
    class="absolute inset-0 z-40 flex items-center justify-center bg-slate-950/35 p-3 backdrop-blur-sm sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $toolTitle }}"
>
    <section class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
        <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <p class="text-[9px] font-black uppercase tracking-[0.18em] text-cyan-700">Testwerkzeug</p>
                <h2 class="mt-1 truncate text-base font-bold text-slate-950">{{ $toolTitle }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $toolDescription }}</p>
            </div>
            <button type="button" wire:click="closeToolModal" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-cyan-500">Schließen <span aria-hidden="true">×</span></button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-5">
            @if($activeToolModal === 'browser')
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach($browserWindows as $window)
                        <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $window['connected'] ? 'bg-emerald-500' : 'bg-slate-300' }}"></span><h3 class="truncate text-sm font-bold text-slate-900">{{ $window['name'] }}</h3>@if($window['active'])<span class="rounded bg-cyan-100 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-cyan-800">aktiv</span>@endif</div>
                                    <p class="mt-1 truncate text-[10px] text-slate-500">{{ $window['url'] ?: 'Noch keine URL erfasst' }}</p>
                                </div>
                                @if(! $autonomousMode)<button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="shrink-0 rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-[10px] font-bold text-cyan-800 hover:bg-cyan-100">Selector prüfen</button>@endif
                            </div>
                            @include('livewire.admin.network.workflow-studio.dom-inspector', [
                                'panel' => [
                                    'title' => $window['name'],
                                    'windowKey' => $window['name'],
                                    'image' => $window['screenshot_url'] ?? null,
                                    'domTree' => $window['dom_tree'] ?? null,
                                    'cursor' => $window['cursor'] ?? null,
                                    'targetId' => $window['target_id'] ?? null,
                                ],
                                'interactive' => ! $autonomousMode,
                            ])
                            @if(filled($window['dom_url'] ?? null))
                                <div class="border-t border-slate-100 px-4 py-2"><a href="{{ $window['dom_url'] }}" target="_blank" rel="noopener" class="text-[10px] font-bold text-cyan-700 hover:text-cyan-900">Bereinigten DOM-Snapshot öffnen</a></div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @elseif($activeToolModal === 'data')
                <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Run</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ $run ? '#'.$run->id : 'Noch keiner' }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Status</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ $statusLabel }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Aktive Liste</dt><dd class="mt-1 truncate text-sm font-bold text-slate-900">{{ $steps->firstWhere('id', (int) $cursorStepId)?->name ?? '–' }}</dd></div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Nächste Task</dt><dd class="mt-1 truncate font-mono text-xs font-bold text-slate-900">{{ $cursorTaskKey ?: '–' }}</dd></div>
                </dl>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><header class="border-b border-slate-200 px-4 py-3"><h3 class="text-sm font-bold text-slate-900">Workflow-Daten</h3><p class="mt-1 text-xs text-slate-500">Persistierte Werte des aktuellen Laufs.</p></header><pre class="max-h-[28rem] overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
                    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><header class="border-b border-slate-200 px-4 py-3"><h3 class="text-sm font-bold text-slate-900">Loop-Zustand</h3><p class="mt-1 text-xs text-slate-500">Aktuelle Iterationen und gespeicherte Schleifenergebnisse.</p></header><pre class="max-h-[28rem] overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($loopState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
                </div>
            @elseif($activeToolModal === 'checkpoints')
                <div class="space-y-3">
                    @forelse($checkpoints as $checkpoint)
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="text-sm font-bold text-slate-900">#{{ $checkpoint->sequence }} · {{ $checkpoint->phase ?: 'Checkpoint' }}</h3><span class="rounded-full px-2 py-1 text-[9px] font-black {{ $checkpoint->is_reproducible ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $checkpoint->is_reproducible ? 'reproduzierbar' : 'nicht reproduzierbar' }}</span></div>
                            <dl class="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-3"><div><dt class="font-bold text-slate-400">Resume</dt><dd class="mt-1 break-all">{{ $checkpoint->resume_task_key ?: $checkpoint->task_key ?: '–' }}</dd></div><div><dt class="font-bold text-slate-400">Fehler-Task</dt><dd class="mt-1 break-all">{{ $checkpoint->failure_task_key ?: '–' }}</dd></div><div><dt class="font-bold text-slate-400">Revision</dt><dd class="mt-1">{{ data_get($checkpoint->cursor_json, 'workflow_revision', $workflow->copilot_revision) }}</dd></div></dl>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">Noch keine technischen Checkpoints vorhanden.</div>
                    @endforelse
                </div>
            @elseif($activeToolModal === 'logs')
                <div class="space-y-2">
                    @forelse($events as $event)
                        <article class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm"><span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-cyan-500"></span><div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-3"><strong class="truncate text-xs text-slate-900">{{ $event->event_type }}</strong><time class="shrink-0 font-mono text-[10px] text-slate-400">{{ $event->occurred_at?->format('H:i:s') }}</time></div><p class="mt-1 text-xs leading-5 text-slate-600">{{ $event->message }}</p></div></article>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">Noch keine Studio-Ereignisse protokolliert.</div>
                    @endforelse
                </div>
            @elseif($activeToolModal === 'debug')
                @if(is_array($probeResult))
                    <section class="mb-4 overflow-hidden rounded-xl border border-cyan-200 bg-white"><header class="border-b border-cyan-100 bg-cyan-50 px-4 py-3"><h3 class="text-sm font-bold text-cyan-950">Letztes Probe-Ergebnis</h3></header><pre class="max-h-80 overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($probeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
                @endif
                <div class="space-y-3">
                    @forelse($taskAttempts as $attempt)
                        <details class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" @if($loop->first) open @endif><summary class="cursor-pointer text-sm font-bold text-slate-900">{{ $attempt->failure_task_key ?: $attempt->task_key ?: 'Task' }} <span class="ml-2 font-normal text-slate-400">{{ $attempt->status }}</span></summary><dl class="mt-3 grid gap-3 text-xs text-slate-600 sm:grid-cols-2"><div><dt class="font-bold text-slate-400">Resume</dt><dd class="mt-1 break-all">{{ $attempt->resume_task_key ?: $attempt->task_key ?: '–' }}</dd></div><div><dt class="font-bold text-slate-400">Fehler-Task</dt><dd class="mt-1 break-all">{{ $attempt->failure_task_key ?: '–' }}</dd></div></dl></details>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">Noch keine technischen Task-Versuche vorhanden.</div>
                    @endforelse
                </div>
            @elseif($activeToolModal === 'steps')
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach($steps as $step)
                        @php($isCurrentStep = (int) $cursorStepId === (int) $step->id)
                        <article class="rounded-xl border bg-white p-4 shadow-sm {{ $isCurrentStep ? 'border-amber-300 ring-2 ring-amber-100' : 'border-slate-200' }}"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-[9px] font-black uppercase tracking-wide text-slate-400">{{ $step->position }} · {{ $step->action_key }}</p><h3 class="mt-1 truncate text-sm font-bold text-slate-900">{{ $step->name }}</h3></div><span class="rounded-full px-2 py-1 text-[9px] font-black {{ $step->is_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $step->is_enabled ? 'aktiv' : 'pausiert' }}</span></div><p class="mt-3 text-xs text-slate-500">{{ count($step->task_cards) }} Tasks</p>@if(! $autonomousMode)<button type="button" wire:click="openBuilderForStepFromTool({{ $step->id }})" class="mt-3 rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-[10px] font-bold text-cyan-800">Schritt bearbeiten</button>@endif</article>
                    @endforeach
                </div>
            @elseif($activeToolModal === 'tasks')
                <div class="space-y-4">
                    @foreach($steps as $step)
                        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3"><h3 class="text-sm font-bold text-slate-900">{{ $step->name }}</h3><span class="text-[10px] font-bold text-slate-400">{{ count($step->task_cards) }} Tasks</span></header><div class="divide-y divide-slate-100">@forelse($step->task_cards as $task)<div class="flex items-center gap-3 px-4 py-3 transition hover:bg-cyan-50"><button type="button" wire:click="selectTaskFromTool({{ $step->id }}, @js((string) ($task['key'] ?? '')))" class="min-w-0 flex-1 text-left"><strong class="block truncate text-xs text-slate-900">{{ $task['title'] ?? $task['key'] ?? 'Task' }}</strong><span class="mt-1 block truncate font-mono text-[10px] text-slate-400">{{ $task['task_key'] ?? $task['key'] ?? '' }}</span></button><button type="button" wire:click="selectTaskFromTool({{ $step->id }}, @js((string) ($task['key'] ?? '')))" class="shrink-0 rounded border border-slate-200 px-2 py-1 text-[9px] font-bold text-slate-500">Auswählen</button>@if(! $autonomousMode)<button type="button" wire:click="editTaskFromTool({{ $step->id }}, @js((string) ($task['key'] ?? '')))" class="shrink-0 rounded border border-cyan-200 bg-cyan-50 px-2 py-1 text-[9px] font-bold text-cyan-800">Bearbeiten</button>@endif</div>@empty<div class="px-4 py-5 text-xs text-slate-500">Keine Tasks konfiguriert.</div>@endforelse</div></section>
                    @endforeach
                </div>
            @elseif($activeToolModal === 'variables')
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white"><header class="border-b border-slate-200 px-4 py-3"><div class="flex items-center justify-between gap-3"><h3 class="text-sm font-bold text-slate-900">Workflow-Variablen</h3><span class="text-[10px] font-bold text-slate-400">{{ is_array($variables) ? count($variables) : 0 }} Werte</span></div></header><pre class="max-h-[36rem] overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
            @elseif($activeToolModal === 'artifacts')
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @forelse($run?->artifacts?->sortByDesc('id') ?? collect() as $artifact)
                        <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="border-b border-slate-100 px-4 py-3"><div class="flex items-center justify-between gap-2"><strong class="truncate text-xs text-slate-900">{{ $artifact->title ?: $artifact->artifact_type }}</strong><span class="rounded bg-slate-100 px-2 py-1 text-[9px] font-bold uppercase text-slate-500">{{ $artifact->artifact_type }}</span></div><p class="mt-1 truncate text-[10px] text-slate-400">{{ $artifact->browser_window ?: 'main' }} · {{ $artifact->phase ?: 'Lauf' }}</p></div>
                            @if($artifact->status === 'success' && $artifact->artifact_type === 'screenshot')<a href="{{ route('workflow-run-artifacts.show', ['run' => $run, 'artifact' => $artifact]) }}" target="_blank" rel="noopener" class="block bg-slate-100"><img src="{{ route('workflow-run-artifacts.show', ['run' => $run, 'artifact' => $artifact]) }}" alt="Debug-Screenshot {{ $artifact->title }}" class="aspect-video w-full object-contain"></a>@else<div class="flex aspect-video items-center justify-center bg-slate-100 px-4 text-center text-xs text-slate-500">{{ $artifact->status === 'success' ? 'DOM- oder Datenartefakt' : ($artifact->error_message ?: 'Artefakt nicht verfügbar') }}</div>@endif
                            @if($artifact->status === 'success')<div class="flex gap-2 border-t border-slate-100 px-4 py-3"><a href="{{ route('workflow-run-artifacts.show', ['run' => $run, 'artifact' => $artifact]) }}" target="_blank" rel="noopener" class="rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold text-slate-600">Vorschau</a><a href="{{ route('workflow-run-artifacts.download', ['run' => $run, 'artifact' => $artifact]) }}" class="rounded-md border border-slate-200 px-2 py-1 text-[10px] font-bold text-slate-600">Download</a></div>@endif
                        </article>
                    @empty
                        <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">Noch keine Debug-Artefakte gespeichert.</div>
                    @endforelse
                </div>
            @endif
        </div>
    </section>
</div>
