<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content min-h-screen bg-slate-50 dark:bg-zinc-700">
        <div class="container-fluid space-y-6 px-[0.625rem]">
            @include('admin.client-controller._navigation')

            <header class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-600">Node Inventory</p>
                    <h1 class="mt-1 text-2xl font-semibold text-slate-950">Installationen und Updates</h1>
                    <p class="mt-1 text-sm text-slate-500">Ein Update wird nur nach Klick eingeplant und beim nächsten Job-Poll übertragen.</p>
                </div>
                <div class="flex items-center gap-3 rounded-xl bg-slate-950 px-4 py-3 text-white">
                    <div><div class="text-xs text-slate-400">GitHub aktuell</div><div class="font-mono font-semibold">{{ $latestRelease ? 'v'.$latestRelease['version'] : '–' }}</div></div>
                    <button wire:click="refreshRelease" class="rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold hover:bg-white/20">Prüfen</button>
                </div>
            </header>

            @if(session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>@endif
            @if($releaseError)<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ $releaseError }}</div>@endif
            @error('update')<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $message }}</div>@enderror

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nodes durchsuchen</label>
                    <input wire:model.live.debounce.300ms="search" placeholder="Name, UUID, IP oder Betriebssystem" class="mt-2 w-full rounded-lg border border-slate-300 px-4 py-3 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <form wire:submit="createNode" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="font-semibold text-slate-900">Node vorbereiten</h2>
                    <div class="mt-3 grid gap-3">
                        <input wire:model="name" placeholder="Node-Name" class="rounded-lg border border-slate-300 p-2.5 text-sm">
                        <input wire:model="currentServerDomain" placeholder="https://factory.follow-flow.de" class="rounded-lg border border-slate-300 p-2.5 text-sm">
                        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" wire:model="allowServerRebind"> Rebind erlauben</label>
                        <button class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white">Node anlegen</button>
                    </div>
                </form>
            </div>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" wire:poll.10s>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3 text-left">Node</th><th class="px-4 py-3 text-left">System</th><th class="px-4 py-3 text-left">Version</th><th class="px-4 py-3 text-left">Update-Status</th><th class="px-4 py-3 text-left">Kontakt</th><th class="px-4 py-3 text-right">Aktionen</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($nodes as $node)
                                @php($online = $node->isAvailable())
                                @php($outdated = $latestRelease && $releaseService->updateAvailable($node->version, $latestRelease['version']))
                                <tr class="align-top">
                                    <td class="px-4 py-4"><a href="{{ route('client-controller.nodes.show', $node) }}" class="font-semibold text-slate-900 hover:text-blue-700">{{ $node->name }}</a><div class="mt-1 max-w-xs break-all font-mono text-[11px] text-slate-400">{{ $node->node_uuid }}</div></td>
                                    <td class="px-4 py-4"><div>{{ $node->os ?: '–' }}</div><div class="text-xs text-slate-400">{{ $node->public_ip ?: 'keine IP' }}</div></td>
                                    <td class="px-4 py-4"><span class="rounded-md bg-slate-100 px-2 py-1 font-mono text-xs">{{ $node->version ? 'v'.$node->version : 'unbekannt' }}</span>@if($outdated)<div class="mt-2 text-xs font-semibold text-cyan-700">v{{ $latestRelease['version'] }} verfügbar</div>@endif</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ in_array($node->update_status, ['pending','installing','awaiting_restart']) ? 'bg-amber-100 text-amber-800' : ($node->update_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $node->update_status ?: 'idle' }}</span>@if($node->update_target_version)<div class="mt-2 text-xs text-slate-500">Ziel v{{ $node->update_target_version }}</div>@endif</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $online ? 'online' : 'offline' }}</span><div class="mt-2 whitespace-nowrap text-xs text-slate-400">{{ $node->last_seen_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?? 'nie' }}</div></td>
                                    <td class="px-4 py-4"><div class="flex justify-end gap-2">@if($outdated)<button wire:click="queueUpdate({{ $node->id }})" wire:confirm="Update auf v{{ $latestRelease['version'] }} beim nächsten Kontakt installieren?" class="rounded-lg bg-cyan-600 px-3 py-2 text-xs font-bold text-white hover:bg-cyan-700">Update freigeben</button>@endif<a href="{{ route('client-controller.nodes.show', $node) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">Details</a><button wire:click="regenerateApiKey({{ $node->id }})" wire:confirm="API-Key erneuern?" class="rounded-lg border border-amber-300 px-3 py-2 text-xs text-amber-800">Key</button><button wire:click="deleteNode({{ $node->id }})" wire:confirm="Node wirklich löschen?" class="rounded-lg border border-red-300 px-3 py-2 text-xs text-red-700">Löschen</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Keine Nodes gefunden.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 p-4">{{ $nodes->links() }}</div>
            </section>
        </div>
    </div>
</div>
