<div wire:loading.class="cursor-wait" class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Personen Factory</h1>
            <p class="mt-1 text-sm text-gray-500">
                Zentrale Uebersicht fuer Personen, Instagram-Sessions und spaetere Bot-Automation.
            </p>
        </div>
        <a href="{{ route('persons.index') }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
            Personen verwalten
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Benutzer</p>
            <p class="mt-3 text-3xl font-semibold text-gray-900">{{ $totalUsers }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Personen</p>
            <p class="mt-3 text-3xl font-semibold text-gray-900">{{ $totalPersons }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Aktiv</p>
            <p class="mt-3 text-3xl font-semibold text-gray-900">{{ $activePersons }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gesperrt</p>
            <p class="mt-3 text-3xl font-semibold text-gray-900">{{ $blockedPersons }}</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Bot-bereit</p>
            <p class="mt-3 text-3xl font-semibold text-gray-900">{{ $automationReadyPersons }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm text-blue-900">
        Die Installation ist auf die Verwaltung von Personen fuer Instagram-Sessions reduziert. Alte Shop-, CMS-, Bewertungs- und Kursmodule sind aus der Navigation und den Einstiegsseiten entfernt.
    </div>
</div>
