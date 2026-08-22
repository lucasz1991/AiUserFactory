<div class="workflow-experience space-y-6" data-portal-profiles-root>
    <section class="ff-command-surface px-4 py-5 sm:px-6 sm:py-6" aria-labelledby="portal-profiles-title">
        <div class="relative z-10 flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <p class="ff-kicker">Copilot Governance</p>
                <h1 id="portal-profiles-title" class="ff-page-title mt-2">Portal-Profile</h1>
                <p class="ff-page-copy mt-2 max-w-3xl text-sm">
                    Im echten Probezyklus bestätigte Selektoren prüfen, Konflikte auflösen und fehlerhafte Einträge ohne Datenverlust zurückrollen.
                </p>
            </div>
            <a href="{{ route('network.workflows') }}" class="ff-action-trigger inline-flex min-h-11 items-center px-4 py-2 text-sm font-semibold">
                Zurück zu Workflows
            </a>
        </div>
        <dl class="ff-metric-rail relative z-10 mt-5" aria-label="Portal-Profil-Statistik">
            @foreach([
                ['Gesamt', $summary['total']],
                ['Aktiv', $summary['active']],
                ['Rollback', $summary['disabled']],
                ['Verfallen', $summary['expired']],
                ['Konflikte', $summary['conflicts']],
            ] as [$label, $value])
                <div class="ff-metric"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">{{ session('success') }}</div>
    @endif

    @if($conflictPairs !== [])
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" aria-label="Selector-Konflikte">
            <p class="font-semibold">{{ count($conflictPairs) }} Konfliktgruppen benötigen eine Entscheidung.</p>
            <p class="mt-1 text-amber-800">Mehrere aktive Selektoren konkurrieren um dieselbe Portal-Rolle. Der Copilot nutzt weiter ausschließlich aktuell im DOM bestätigte Kandidaten.</p>
        </section>
    @endif

    <x-admin.panel class="overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
            <label class="min-w-0 flex-1">
                <span class="sr-only">Portal-Profile durchsuchen</span>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Domain, Rolle oder Selector suchen" class="min-h-11 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            </label>
            <label class="sm:w-56">
                <span class="sr-only">Status filtern</span>
                <select wire:model.live="status" class="min-h-11 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="all">Alle Profile</option>
                    <option value="active">Aktiv</option>
                    <option value="disabled">Zurückgerollt</option>
                    <option value="expired">Verfallen</option>
                    <option value="conflicts">Konflikte</option>
                </select>
            </label>
        </div>

        <div class="overflow-x-auto bg-white">
            <table class="min-w-[1050px] w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Portal / Rolle</th>
                        <th class="px-4 py-3">Selector</th>
                        <th class="px-4 py-3">Evidenz</th>
                        <th class="px-4 py-3">Qualität</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aktion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($profiles as $profile)
                        @php
                            $conflict = collect($conflictPairs)->contains(fn ($pair) => $pair['domain'] === $profile->domain && $pair['role'] === $profile->role);
                            $rate = ($profile->hit_count + $profile->miss_count) > 0 ? round($profile->successRate() * 100) : 0;
                        @endphp
                        <tr wire:key="portal-profile-{{ $profile->id }}" class="align-top hover:bg-slate-50/70">
                            <td class="px-4 py-4">
                                <strong class="block text-slate-900">{{ $profile->domain }}</strong>
                                <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ $profile->role }}</span>
                                @if($conflict)<span class="ml-1 inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Konflikt</span>@endif
                            </td>
                            <td class="max-w-md px-4 py-4">
                                <code class="block break-all rounded-md bg-slate-950 px-2.5 py-2 text-xs text-slate-100">{{ $profile->selector }}</code>
                                <span class="mt-1 block text-xs text-slate-500">Version {{ $profile->profile_version }} · Quelle {{ $profile->source ?: 'unbekannt' }}</span>
                            </td>
                            <td class="px-4 py-4 text-xs text-slate-600">
                                <span class="block font-semibold text-slate-800">{{ $profile->hit_count }} Treffer / {{ $profile->miss_count }} Misses</span>
                                <span class="mt-1 block">{{ $rate }} % · zuletzt {{ $profile->last_confirmed_at?->diffForHumans() ?? 'nie' }}</span>
                                @if($profile->evidence_json)
                                    <details class="mt-2"><summary class="cursor-pointer font-semibold text-blue-700">Letzte Evidenz</summary><pre class="mt-2 max-w-sm overflow-auto rounded bg-slate-100 p-2 text-[11px]">{{ json_encode($profile->evidence_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-xs">
                                <span class="font-semibold {{ $profile->has_quality_warnings ? 'text-amber-700' : 'text-emerald-700' }}">{{ $profile->has_quality_warnings ? 'Syntaxwarnung' : 'Warnungsfrei' }}</span>
                                <span class="mt-1 block text-slate-500">Konfidenz {{ number_format($profile->confidenceRate() * 100, 1, ',', '.') }} %</span>
                            </td>
                            <td class="px-4 py-4">
                                @if(!$profile->is_active)
                                    <span class="inline-flex rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-800">Rollback</span>
                                    <span class="mt-1 block max-w-xs text-xs text-slate-500">{{ $profile->disable_reason }}</span>
                                @elseif($profile->isExpired())
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Verfallen</span>
                                @else
                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">Freigegeben</span>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-right">
                                @if($profile->is_active)
                                    <button type="button" wire:click="rollbackProfile({{ $profile->id }})" wire:confirm="Diesen Selector zurückrollen? Er bleibt in der Historie, wird aber nicht mehr verwendet." class="min-h-11 rounded-lg border border-rose-300 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">Rollback</button>
                                @else
                                    <button type="button" wire:click="approveProfile({{ $profile->id }})" wire:confirm="Diesen Selector erneut freigeben?" class="min-h-11 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Freigeben</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">Keine Portal-Profile für diesen Filter gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($profiles->hasPages())<div class="border-t border-slate-200 bg-white p-4">{{ $profiles->links() }}</div>@endif
    </x-admin.panel>
</div>
