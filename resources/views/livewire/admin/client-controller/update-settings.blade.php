<section class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-5" wire:loading.class="opacity-70">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-cyan-700">Separates Livewire-Modul</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">GitHub-Releases und kontrollierte Node-Updates</h3>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">Die Prüfung ist rein lesend. Installationen werden erst nach einer expliziten Freigabe als signierter Node-Job erstellt.</p>
        </div>
        <button type="button" wire:click="checkRelease" class="rounded-lg border border-cyan-300 bg-white px-4 py-2 text-sm font-semibold text-cyan-800">GitHub jetzt prüfen</button>
    </div>

    @if($releaseError)<div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $releaseError }}</div>@endif
    @if($latestRelease)
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">Neuestes Release</div><div class="mt-1 font-mono text-xl font-bold">v{{ $latestRelease['version'] }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">Updater-Manifest</div><div class="mt-1 font-semibold {{ $latestRelease['has_manifest'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $latestRelease['has_manifest'] ? 'latest.json vorhanden' : 'Fallback-URL wird verwendet' }}</div></div>
            <div class="rounded-lg border border-slate-200 bg-white p-4"><div class="text-xs text-slate-500">Veraltete Nodes</div><div class="mt-1 text-xl font-bold text-cyan-700">{{ $outdatedCount }}</div></div>
        </div>
    @endif

    <form wire:submit="save" class="mt-5 grid gap-5">
        <div class="grid gap-5 md:grid-cols-2">
            <div><label class="block text-sm font-medium text-slate-700">GitHub-Repository</label><input wire:model.defer="githubRepository" placeholder="owner/repository" class="mt-1 w-full rounded-lg border border-slate-300 p-3 text-sm">@error('githubRepository')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="block text-sm font-medium text-slate-700">Prüfintervall (Minuten)</label><input type="number" min="1" max="1440" wire:model.defer="checkIntervalMinutes" class="mt-1 w-full rounded-lg border border-slate-300 p-3 text-sm">@error('checkIntervalMinutes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="md:col-span-2"><label class="block text-sm font-medium text-slate-700">Tauri-Updater-Manifest</label><input type="url" wire:model.defer="manifestUrl" class="mt-1 w-full rounded-lg border border-slate-300 p-3 font-mono text-xs">@error('manifestUrl')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
            <div class="md:col-span-2"><label class="block text-sm font-medium text-slate-700">Öffentlicher Tauri-Signaturschlüssel</label><textarea rows="4" wire:model.defer="updaterPublicKey" placeholder="Inhalt der öffentlichen .pub-Datei" class="mt-1 w-full rounded-lg border border-slate-300 p-3 font-mono text-xs"></textarea><p class="mt-1 text-xs text-slate-500">Nur der öffentliche Schlüssel gehört hier hinein. Der private Signaturschlüssel darf niemals in AiUserFactory oder auf einem Node gespeichert werden.</p>@error('updaterPublicKey')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
        </div>
        @error('bulkUpdate')<div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror
        <div class="flex flex-wrap justify-end gap-3"><button type="submit" class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white">Einstellungen speichern</button>@if($latestRelease && $outdatedCount > 0)<button type="button" wire:click="queueAllOutdated" wire:confirm="Updates für alle {{ $outdatedCount }} veralteten aktiven Nodes freigeben?" class="rounded-lg bg-cyan-600 px-5 py-2.5 text-sm font-semibold text-white">Alle veralteten Nodes aktualisieren</button>@endif</div>
    </form>
</section>
