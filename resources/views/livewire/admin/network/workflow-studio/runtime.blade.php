<div class="h-full min-h-0 overflow-y-auto">
    <div class="mx-auto grid max-w-[1500px] gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,.55fr)]">
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-bold text-slate-950">Laufzustand</h2>
                        <p class="mt-1 text-xs text-slate-500">Cursor, Variablen und Loop-Stack des aktuellen Tests.</p>
                    </div>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $statusTone }}">{{ $statusLabel }}</span>
                </div>
                <dl class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Run</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ $run ? '#'.$run->id : 'Noch keiner' }}</dd></div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Revision</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ $workflow->copilot_revision }}</dd></div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Aktive Liste</dt><dd class="mt-1 truncate text-sm font-semibold text-slate-800">{{ $steps->firstWhere('id', (int) $cursorStepId)?->name ?? '–' }}</dd></div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Nächste Task</dt><dd class="mt-1 truncate text-sm font-semibold text-slate-800">{{ $cursorTaskKey ?: '–' }}</dd></div>
                </dl>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-4 py-3"><h3 class="text-sm font-bold text-slate-900">Workflow-Variablen</h3><p class="mt-1 text-xs text-slate-500">Bleiben mit Checkpoints und Pausen erhalten.</p></div>
                    <pre class="max-h-[520px] min-h-[260px] overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </section>
                <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-4 py-3"><h3 class="text-sm font-bold text-slate-900">Loop-Stack</h3><p class="mt-1 text-xs text-slate-500">Aktuelle Iteration und gespeicherte Schleifenergebnisse.</p></div>
                    <pre class="max-h-[520px] min-h-[260px] overflow-auto whitespace-pre-wrap p-4 text-[11px] leading-5 text-slate-600">{{ json_encode($loopState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </section>
            </div>
        </div>

        <aside class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 py-3">
                <h3 class="text-sm font-bold text-slate-900">Studio-Protokoll</h3>
                <p class="mt-1 text-xs text-slate-500">Die letzten Aktionen dieser Sitzung.</p>
            </div>
            <div class="max-h-[720px] space-y-2 overflow-y-auto p-3">
                @forelse($events as $event)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
                        <div class="flex items-center justify-between gap-2"><strong class="truncate text-slate-800">{{ $event->event_type }}</strong><span class="shrink-0 text-[10px] text-slate-400">{{ $event->occurred_at?->format('H:i:s') }}</span></div>
                        <p class="mt-1.5 leading-5 text-slate-600">{{ $event->message }}</p>
                    </div>
                @empty
                    <div class="px-3 py-8 text-center text-xs text-slate-500">Noch keine Studio-Aktionen protokolliert.</div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
