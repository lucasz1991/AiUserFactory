<div class="space-y-5">
    <div class="rounded-xl border border-violet-200 bg-violet-50 p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="block text-sm font-semibold text-violet-950">Manuellen Checkpoint erstellen</label>
                <p class="mt-1 text-xs text-violet-700">Speichert Cursor, Variablen, Loop-Stack und den aktuellen Browserzustand.</p>
                <input type="text" wire:model="checkpointName" class="mt-3 w-full rounded-lg border-violet-200 bg-white text-sm text-slate-800 shadow-sm focus:border-violet-500 focus:ring-violet-500" placeholder="z. B. Suche funktioniert">
            </div>
            <button type="button" wire:click="createCheckpoint" @disabled(! $run) class="rounded-lg bg-violet-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-40">Checkpoint erstellen</button>
        </div>
    </div>

    <div>
        <div class="mb-3 flex items-center justify-between gap-3">
            <div><h3 class="text-sm font-bold text-slate-900">Gespeicherte Zustände</h3><p class="mt-1 text-xs text-slate-500">Ein Zustand wird niemals durch das Laden gelöscht.</p></div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $checkpoints->count() }} Checkpoints</span>
        </div>
        <div class="grid max-h-[440px] gap-3 overflow-y-auto pr-1 md:grid-cols-2">
            @forelse($checkpoints as $checkpoint)
                @php
                    $incompatible = $checkpoint->revision && (int) $checkpoint->revision->revision_number !== (int) $workflow->copilot_revision;
                    $selectedCheckpoint = (string) $selectedCheckpointId === (string) $checkpoint->id;
                @endphp
                <button
                    type="button"
                    wire:click="$set('selectedCheckpointId', '{{ $checkpoint->id }}')"
                    class="rounded-xl border p-4 text-left transition {{ $selectedCheckpoint ? 'border-violet-400 bg-violet-50 ring-2 ring-violet-200' : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm' }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0"><div class="text-xs font-bold text-slate-900">#{{ $checkpoint->sequence }} {{ $checkpoint->name ?: 'Automatischer Checkpoint' }}</div><div class="mt-1 truncate text-[10px] text-slate-500">{{ $checkpoint->created_at?->format('d.m.Y H:i:s') }} · Task {{ $checkpoint->task_key ?: '–' }}</div></div>
                        <span class="h-3 w-3 shrink-0 rotate-45 {{ $selectedCheckpoint ? 'bg-violet-500' : 'bg-amber-400' }}"></span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[9px] font-bold text-slate-600">Revision {{ $checkpoint->revision?->revision_number ?? '–' }}</span>
                        @if(! $checkpoint->is_reproducible)<span class="rounded-full bg-rose-50 px-2 py-0.5 text-[9px] font-bold text-rose-700">nicht reproduzierbar</span>@endif
                        @if($incompatible)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-bold text-amber-700">andere Revision</span>@endif
                    </div>
                </button>
            @empty
                <div class="col-span-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">Noch keine Checkpoints vorhanden.</div>
            @endforelse
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
        <p class="max-w-xl text-xs leading-5 text-slate-500">Zum Zurücksetzen des aktuellen Laufs muss dieser sicher pausiert sein. Alternativ kannst du einen neuen, pausierten Lauf vom Checkpoint abzweigen.</p>
        <div class="flex gap-2">
            <button type="button" wire:click="restoreCheckpoint" wire:confirm="Aktuellen Lauf auf diesen Checkpoint zurücksetzen?" @disabled(! $isPaused || $selectedCheckpointId === '') class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40">Lauf zurücksetzen</button>
            <button type="button" wire:click="branchFromCheckpoint" @disabled($selectedCheckpointId === '') class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-40">Neuen Lauf abzweigen</button>
        </div>
    </div>
</div>
