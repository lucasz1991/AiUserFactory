<section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900">Versionsverlauf</h3>
            <p class="mt-1 text-xs text-slate-500">Zwei Revisionen wählen, um ihre Änderungen zu vergleichen.</p>
        </div>
        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $revisions->count() }} Revisionen</span>
    </div>

    <div class="p-4">
        <div class="grid gap-2 sm:grid-cols-2">
            <select wire:model.live="compareRevision" class="rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                <option value="">Von Revision …</option>
                @foreach($revisions as $revision)<option value="{{ $revision->revision_number }}">R{{ $revision->revision_number }} · {{ Str::limit($revision->reason, 45) }}</option>@endforeach
            </select>
            <select wire:model.live="selectedRevision" class="rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                <option value="">Mit Revision …</option>
                @foreach($revisions as $revision)<option value="{{ $revision->revision_number }}">R{{ $revision->revision_number }} · {{ Str::limit($revision->reason, 45) }}</option>@endforeach
            </select>
        </div>

        @if($selected && $compare)
            <div class="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900">
                <div class="font-bold">{{ count($comparison) }} Änderungen zwischen R{{ $compare->revision_number }} und R{{ $selected->revision_number }}</div>
                <pre class="mt-2 max-h-52 overflow-auto whitespace-pre-wrap rounded-md bg-white/80 p-3 text-[10px] leading-4 text-slate-600">{{ json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif

        <div class="mt-4 grid max-h-[430px] gap-3 overflow-y-auto pr-1 md:grid-cols-2">
            @forelse($revisions as $revision)
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <strong class="text-sm text-slate-900">Revision {{ $revision->revision_number }}</strong>
                            <div class="mt-1 text-[10px] text-slate-500">{{ $revision->actor }} · {{ $revision->created_at?->format('d.m.Y H:i') }}</div>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[9px] font-bold {{ $revision->is_verified ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $revision->is_verified ? 'geprüft' : 'ungeprüft' }}</span>
                    </div>
                    <p class="mt-3 min-h-[40px] text-xs leading-5 text-slate-600">{{ $revision->reason }}</p>
                    <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
                        <span class="text-[10px] text-slate-400">{{ count($revision->diff_json ?: []) }} Diff-Einträge</span>
                        <button type="button" wire:click="restoreRevision({{ $revision->revision_number }})" wire:confirm="Revision {{ $revision->revision_number }} als neuen aktuellen Stand wiederherstellen?" class="rounded-lg border border-violet-200 bg-violet-50 px-2.5 py-1.5 text-[10px] font-bold text-violet-700 transition hover:bg-violet-100">Wiederherstellen</button>
                    </div>
                </article>
            @empty
                <p class="col-span-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm text-slate-500">Noch keine Revisionen vorhanden.</p>
            @endforelse
        </div>
    </div>
</section>
