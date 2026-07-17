<details class="rounded-xl border border-slate-700 bg-slate-950/60 p-4">
    <summary class="cursor-pointer text-xs font-bold text-cyan-100">Versionsverlauf</summary>
    <div class="mt-3 space-y-3">
        <div class="grid grid-cols-2 gap-2">
            <select wire:model.live="compareRevision" class="rounded-lg border-slate-600 bg-slate-900 text-xs text-white">
                <option value="">Von Revision …</option>
                @foreach($revisions as $revision)<option value="{{ $revision->revision_number }}">R{{ $revision->revision_number }} · {{ Str::limit($revision->reason, 32) }}</option>@endforeach
            </select>
            <select wire:model.live="selectedRevision" class="rounded-lg border-slate-600 bg-slate-900 text-xs text-white">
                <option value="">Mit Revision …</option>
                @foreach($revisions as $revision)<option value="{{ $revision->revision_number }}">R{{ $revision->revision_number }} · {{ Str::limit($revision->reason, 32) }}</option>@endforeach
            </select>
        </div>
        @if($selected && $compare)
            <div class="rounded-lg border border-slate-700 bg-slate-900 p-3 text-[10px] text-slate-400">
                <strong class="text-slate-200">{{ count($comparison) }} Änderungen</strong>
                <pre class="mt-2 max-h-44 overflow-auto whitespace-pre-wrap">{{ json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @endif
        <div class="max-h-72 space-y-2 overflow-y-auto">
            @forelse($revisions as $revision)
                <div class="rounded-lg border border-slate-700 bg-slate-900 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div><strong class="text-xs text-white">Revision {{ $revision->revision_number }}</strong><div class="text-[10px] text-slate-500">{{ $revision->actor }} · {{ $revision->created_at?->format('d.m.Y H:i') }}</div></div>
                        <span class="rounded-full px-2 py-0.5 text-[9px] font-bold {{ $revision->is_verified ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200' }}">{{ $revision->is_verified ? 'geprüft' : 'ungeprüft' }}</span>
                    </div>
                    <p class="mt-2 text-[10px] text-slate-300">{{ $revision->reason }}</p>
                    <div class="mt-2 flex items-center justify-between"><span class="text-[9px] text-slate-500">{{ count($revision->diff_json ?: []) }} Diff-Einträge</span><button type="button" wire:click="restoreRevision({{ $revision->revision_number }})" wire:confirm="Revision {{ $revision->revision_number }} als neuen aktuellen Stand wiederherstellen?" class="rounded-md border border-violet-600 px-2 py-1 text-[10px] font-bold text-violet-200 hover:bg-violet-500/10">Wiederherstellen</button></div>
                </div>
            @empty
                <p class="text-xs text-slate-500">Noch keine Revisionen vorhanden.</p>
            @endforelse
        </div>
    </div>
</details>
