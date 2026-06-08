<div wire:loading.class="cursor-wait" class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Personen Factory</h1>
            <p class="mt-1 text-sm text-gray-500">
                Verwalte Personen mit Instagram-Session, Persona- und Bot-Daten.
            </p>
        </div>
        <a href="{{ route('admin.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
            Dashboard
        </a>
    </div>

    <section class="rounded-lg border border-indigo-200 bg-indigo-50/50 p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Fiktive Identitaetsvorschlaege</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-600">
                    AI-generierte Ausgangsdaten fuer neue fiktive Testprofile. E-Mail-Adressen verwenden die reservierte
                    Domain example.com; Konten werden nicht automatisch erstellt.
                </p>
            </div>
            <button
                type="button"
                wire:click="generateIdentitySuggestions"
                wire:loading.attr="disabled"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="generateIdentitySuggestions">Neue AI-Vorschlaege</span>
                <span wire:loading wire:target="generateIdentitySuggestions">AI erstellt Vorschlaege...</span>
            </button>
        </div>

        @if (session()->has('error'))
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                {{ session('error') }}
            </div>
        @endif

        @if (session()->has('success'))
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                {{ session('success') }}
            </div>
        @endif

        @if ($identitySuggestions === [])
            <div class="mt-5 rounded-lg border border-dashed border-indigo-200 bg-white/70 p-5 text-sm text-gray-600">
                Noch keine Vorschlaege geladen. Klicke auf <span class="font-semibold text-gray-900">Neue AI-Vorschlaege</span>, um fiktive Personen erstellen zu lassen.
            </div>
        @else
            <div class="mt-5 grid gap-4 xl:grid-cols-3">
                @foreach ($identitySuggestions as $index => $suggestion)
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" wire:key="identity-{{ $suggestion['instagram_handle'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">{{ $suggestion['first_name'] }} {{ $suggestion['last_name'] }}</h3>
                            <p class="text-sm text-indigo-700">{{ '@'.$suggestion['instagram_handle'] }}</p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Fiktiv</span>
                    </div>

                    <dl class="mt-4 space-y-2 text-sm">
                        <div><dt class="font-medium text-gray-500">Profilname / Alias</dt><dd class="text-gray-900">{{ $suggestion['profile_label'] }} / {{ $suggestion['alias'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Geburtsdatum / Geschlecht</dt><dd class="text-gray-900">{{ \Illuminate\Support\Carbon::parse($suggestion['date_of_birth'])->format('d.m.Y') }} / {{ $suggestion['gender'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Adresse</dt><dd class="text-gray-900">{{ $suggestion['address_line1'] }}, {{ $suggestion['postal_code'] }} {{ $suggestion['city'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Region</dt><dd class="text-gray-900">{{ $suggestion['state'] }}, {{ $suggestion['country'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">E-Mail-Vorschlag</dt><dd class="break-all text-gray-900">{{ $suggestion['email'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Telefon-Platzhalter</dt><dd class="text-gray-900">{{ $suggestion['phone'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Zeitzone</dt><dd class="text-gray-900">{{ $suggestion['timezone'] }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Beruf</dt><dd class="text-gray-900">{{ $suggestion['occupation'] ?: 'Nicht gesetzt' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Interessen</dt><dd class="text-gray-900">{{ implode(', ', $suggestion['interests'] ?? []) }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Bio-Idee</dt><dd class="text-gray-900">{{ $suggestion['bio'] }}</dd></div>
                    </dl>

                    <div class="mt-4 flex justify-end">
                        <button
                            type="button"
                            wire:click="saveIdentitySuggestion({{ $index }})"
                            wire:loading.attr="disabled"
                            wire:target="saveIdentitySuggestion({{ $index }})"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60"
                        >
                            Als Person speichern
                        </button>
                    </div>
                </article>
                @endforeach
            </div>
        @endif
    </section>

    @livewire('admin.config.person-list')
</div>
