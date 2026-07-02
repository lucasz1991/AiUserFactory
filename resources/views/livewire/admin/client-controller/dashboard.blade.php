<div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
    <div class="page-content min-h-screen bg-slate-50 dark:bg-zinc-700">
        <div class="container-fluid space-y-6 px-[0.625rem]">
            @include('admin.client-controller._navigation')

            <header class="overflow-hidden rounded-2xl bg-slate-950 p-6 text-white shadow-lg">
                <div class="flex flex-wrap items-center justify-between gap-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.24em] text-cyan-300">Control Center</p>
                        <h1 class="mt-2 text-3xl font-semibold">ClientController Netzwerk</h1>
                        <p class="mt-2 max-w-2xl text-sm text-slate-300">Live-Status, installierte Versionen und kontrollierte Updates für alle verbundenen Nodes.</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-right">
                        <p class="text-xs uppercase tracking-wider text-slate-400">Aktuelles GitHub-Release</p>
                        <p class="mt-1 text-2xl font-semibold">{{ $latestRelease ? 'v'.$latestRelease['version'] : '–' }}</p>
                        <button wire:click="refreshRelease" wire:loading.attr="disabled" class="mt-2 text-xs font-semibold text-cyan-300 hover:text-cyan-200">Jetzt prüfen</button>
                    </div>
                </div>
            </header>

            @if($releaseError)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>GitHub-Status:</strong> {{ $releaseError }}
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                @foreach([
                    ['Nodes', $stats['nodes_total'], 'text-slate-900'],
                    ['Online', $stats['nodes_online'], 'text-emerald-600'],
                    ['Update verfügbar', $stats['nodes_outdated'], 'text-cyan-600'],
                    ['Geräte', $stats['devices_total'], 'text-indigo-600'],
                    ['Aktive Jobs', $stats['jobs_pending'], 'text-amber-600'],
                    ['Targets', $stats['targets_total'], 'text-violet-600'],
                ] as [$label, $value, $color])
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                        <p class="mt-2 text-3xl font-semibold {{ $color }}">{{ $value }}</p>
                    </article>
                @endforeach
            </div>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" wire:poll.10s>
                <div class="flex items-center justify-between border-b border-slate-100 p-5">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Zuletzt aktive Nodes</h2>
                        <p class="text-sm text-slate-500">Versionen stammen direkt aus Registrierung und Heartbeat.</p>
                    </div>
                    <a href="{{ route('client-controller.nodes.index') }}" class="text-sm font-semibold text-blue-700 hover:underline">Alle Nodes</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3 text-left">Node</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Installiert</th><th class="px-5 py-3 text-left">Update</th><th class="px-5 py-3 text-left">Letzter Kontakt</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($nodes as $node)
                                @php($online = $node->isAvailable())
                                @php($outdated = $latestRelease && app(\App\Services\ClientController\ClientControllerReleaseService::class)->updateAvailable($node->version, $latestRelease['version']))
                                <tr>
                                    <td class="px-5 py-4"><a href="{{ route('client-controller.nodes.show', $node) }}" class="font-semibold text-slate-900 hover:text-blue-700">{{ $node->name }}</a><div class="text-xs text-slate-400">{{ $node->public_ip ?: $node->node_uuid }}</div></td>
                                    <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $online ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $online ? 'Online' : 'Offline' }}</span></td>
                                    <td class="px-5 py-4 font-mono text-xs">{{ $node->version ? 'v'.$node->version : 'unbekannt' }}</td>
                                    <td class="px-5 py-4"><span class="text-xs font-semibold {{ $outdated ? 'text-cyan-700' : 'text-slate-500' }}">{{ $outdated ? 'v'.$latestRelease['version'].' verfügbar' : ($node->update_status === 'installed' ? 'Installiert' : 'Aktuell') }}</span></td>
                                    <td class="px-5 py-4 text-xs text-slate-500">{{ $node->last_seen_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') ?? 'nie' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">Noch keine Nodes registriert.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
