<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Aktionen</h1>
            <p class="mt-1 text-sm text-gray-500">
                Alle geplanten internen Sandbox-Aktionen aus den Persona-Aktivitaetsplaenen.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="planNetworkNow" wire:loading.attr="disabled" wire:target="planNetworkNow" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">
                <span wire:loading.remove wire:target="planNetworkNow">Alle planen</span>
                <span wire:loading wire:target="planNetworkNow">Plane...</span>
            </button>
            <a href="{{ route('persons.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Personen
            </a>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase text-gray-500">Personen mit Plan</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['persons_with_plans'] ?? 0 }}</p>
            </div>
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase text-gray-500">Sichtbare Aktionen</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['visible_actions'] ?? 0 }}</p>
            </div>
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase text-gray-500">Content</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['content_actions'] ?? 0 }}</p>
            </div>
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase text-gray-500">Session-Schritte</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['step_actions'] ?? 0 }}</p>
            </div>
            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase text-gray-500">Review</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['review_actions'] ?? 0 }}</p>
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-5">
            <div>
                <label class="block text-sm font-medium text-gray-700">Plan-Tage</label>
                <input type="number" min="1" max="14" wire:model.defer="planningDays" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                @error('planningDays') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Plan-Intensitaet</label>
                <select wire:model.defer="planningIntensity" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                    <option value="quiet">Ruhig</option>
                    <option value="balanced">Ausgewogen</option>
                    <option value="active">Aktiv</option>
                    <option value="creator">Creator</option>
                </select>
                @error('planningIntensity') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Person</label>
                <select wire:model.live="personFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                    <option value="">Alle Personen</option>
                    @foreach($personOptions as $person)
                        <option value="{{ $person['id'] }}">{{ $person['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Typ</label>
                <select wire:model.live="typeFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                    <option value="all">Alle Aktionen</option>
                    <option value="step">Session-Schritte</option>
                    <option value="content">Content</option>
                </select>
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="resetFilters" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Filter zuruecksetzen
                </button>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 p-5">
            <h2 class="text-lg font-semibold text-gray-900">Aktionsliste</h2>
            <p class="mt-1 text-sm text-gray-500">
                Diese Liste beschreibt interne Zustandswechsel und Content-Ideen, keine echten Plattformaktionen.
            </p>
        </div>

        @if($actions === [])
            <div class="p-6">
                <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500">
                    Keine Aktionen gefunden. Erstelle zuerst in einer Person unter <span class="font-semibold">Interne Aktivitaeten</span> einen Aktivitaetsplan.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Zeit</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Person</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Typ</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Aktion</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Kontext</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach($actions as $action)
                            @php
                                $riskClass = match($action['risk_level'] ?? 'low') {
                                    'review' => 'bg-red-50 text-red-700 ring-red-200',
                                    'moderate' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                    default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                };
                            @endphp
                            <tr wire:key="network-action-{{ $action['id'] }}">
                                <td class="whitespace-nowrap px-4 py-3 align-top text-gray-700">
                                    <p class="font-semibold text-gray-900">{{ $action['date'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $action['weekday'] }} {{ $action['time'] }}</p>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <a href="{{ route('persons.show', ['profileId' => $action['person_key']]) }}" class="font-semibold text-blue-700 hover:text-blue-900">
                                        {{ $action['person_name'] }}
                                    </a>
                                    <p class="mt-1 text-xs text-gray-500">{{ $action['person_key'] }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                        {{ $action['type_label'] }}
                                    </span>
                                    <p class="mt-2 text-xs text-gray-500">{{ $action['session_type'] }}</p>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <p class="font-semibold text-gray-900">{{ $action['label'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $action['action'] }}</p>
                                </td>
                                <td class="min-w-[280px] px-4 py-3 align-top text-gray-700">
                                    {{ $action['details'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $riskClass }}">
                                        Risiko {{ $action['risk_score'] }}
                                    </span>
                                    <p class="mt-2 text-xs text-gray-500">{{ $action['plan_status'] }}{{ $action['intensity_label'] !== '' ? ' / '.$action['intensity_label'] : '' }}</p>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
