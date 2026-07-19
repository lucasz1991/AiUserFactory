<div wire:loading.class="cursor-wait" class="space-y-6">
    {{-- Hero --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary-base via-[#2c5391] to-secondary-base p-6 text-white shadow-lg sm:p-8">
        <div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 left-1/3 h-64 w-64 rounded-full bg-secondary-base/20 blur-3xl"></div>
        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div>
                <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-white/70">
                    <span class="h-1.5 w-1.5 rounded-full bg-white"></span> Dashboard
                </span>
                <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Factory AI</h1>
                <p class="mt-1.5 max-w-xl text-sm text-white/80">
                    Zentrale Uebersicht fuer Personen, Instagram-Sessions und spaetere Bot-Automation.
                </p>
            </div>
            <a href="{{ route('persons.index') }}"
               class="rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-primary-base shadow-sm transition hover:bg-white/90">
                Personen verwalten
            </a>
        </div>
    </div>

    {{-- Kennzahlen --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
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
