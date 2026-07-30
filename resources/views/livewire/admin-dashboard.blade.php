<div wire:loading.class="cursor-wait" class="space-y-6" data-ff-dashboard>
    <x-ui.page-header
        eyebrow="Control Center"
        title="Factory AI"
        description="Personen, Automationen, Verbindungen und Workflow-Aktivitaet in einer klaren Arbeitsoberflaeche."
    >
        <x-slot:actions>
            <a href="{{ route('persons.index') }}"
               wire:navigate
               class="ff-primary-action">
                Personen verwalten
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Kennzahlen --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5" data-ff-dashboard-metrics>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Benutzer</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-base/10 text-primary-base"><span class="mdi mdi-account-multiple-outline text-lg"></span></span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900">{{ $totalUsers }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personen</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-base/10 text-primary-base"><span class="mdi mdi-account-box-multiple-outline text-lg"></span></span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900">{{ $totalPersons }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Aktiv</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-secondary-base/10 text-secondary-base"><span class="mdi mdi-check-circle-outline text-lg"></span></span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900">{{ $activePersons }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gesperrt</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-500/10 text-red-500"><span class="mdi mdi-block-helper text-base"></span></span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900">{{ $blockedPersons }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bot-bereit</p>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-secondary-base/10 text-secondary-base"><span class="mdi mdi-robot-outline text-lg"></span></span>
            </div>
            <p class="mt-3 text-3xl font-bold text-slate-900">{{ $automationReadyPersons }}</p>
        </div>
    </div>

    {{-- Hinweis --}}
    <div class="flex items-start gap-3 rounded-xl border border-primary-base/20 bg-primary-base/5 p-5 text-sm text-slate-600">
        <span class="mdi mdi-information-outline mt-0.5 text-lg text-primary-base"></span>
        <p>Die Installation ist auf die Verwaltung von Personen fuer Instagram-Sessions reduziert. Alte Shop-, CMS-, Bewertungs- und Kursmodule sind aus der Navigation und den Einstiegsseiten entfernt.</p>
    </div>

    <livewire:admin.processes.process-monitor
        :compact="true"
        :limit="6"
        :show-header="true"
        :auto-refresh="true"
    />
</div>
