<div>
    <x-dialog-modal wire:model="showModal" maxWidth="6xl">
        <x-slot name="title">
            Person bearbeiten
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                @if (session()->has('success'))
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                        {{ session('success') }}
                    </div>
                @endif

                @if (session()->has('error'))
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                        {{ session('error') }}
                    </div>
                @endif

                <section class="rounded-lg border border-purple-200 bg-purple-50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">AI-Vorschlag</h3>
                            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                                Beschreibe die gewuenschte Persona. Die AI aktualisiert nur die editierbaren Felder in diesem Modal.
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="generate"
                            wire:loading.attr="disabled"
                            wire:target="generate"
                            class="rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="generate">Vorschlag erstellen</span>
                            <span wire:loading wire:target="generate">Erstelle Vorschlag...</span>
                        </button>
                    </div>

                    <textarea
                        rows="4"
                        wire:model.defer="profilePrompt"
                        class="mt-4 w-full rounded-md border-purple-200 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                        placeholder="z. B. 28-jaehrige fiktive Person aus Berlin, sportlich, technisch interessiert, freundlich-direkter Schreibstil, glaubwuerdiger beruflicher Hintergrund im Marketing."
                    ></textarea>
                    @error('profilePrompt') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-900">Stammdaten</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Vorname</label>
                            <input type="text" wire:model.defer="preview.root.person_first_name" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Nachname</label>
                            <input type="text" wire:model.defer="preview.root.person_last_name" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Alias</label>
                            <input type="text" wire:model.defer="preview.root.person_alias" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Geburtsdatum</label>
                            <input type="date" wire:model.live="preview.root.person_date_of_birth" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <p class="mt-1 text-xs text-slate-500">Alter: {{ $this->previewAgeLabel() ?: 'Nicht berechnet' }}</p>
                            @error('preview.root.person_date_of_birth') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Geschlecht / Rolle</label>
                            <input type="text" wire:model.defer="preview.root.person_gender" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Zeitzone</label>
                            <input type="text" wire:model.defer="preview.root.person_timezone" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-900">Kontakt und Adresse</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">E-Mail</label>
                            <input type="email" wire:model.defer="preview.root.person_email" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Telefon</label>
                            <input type="text" wire:model.defer="preview.root.person_phone" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Land</label>
                            <input type="text" wire:model.defer="preview.root.person_country" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Strasse</label>
                            <input type="text" wire:model.defer="preview.root.person_address_line1" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Adresszusatz</label>
                            <input type="text" wire:model.defer="preview.root.person_address_line2" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">PLZ</label>
                            <input type="text" wire:model.defer="preview.root.person_postal_code" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Ort</label>
                            <input type="text" wire:model.defer="preview.root.person_city" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Region / Bundesland</label>
                            <input type="text" wire:model.defer="preview.root.person_state" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div class="md:col-span-2 xl:col-span-3">
                            <label class="block text-sm font-medium text-slate-700">Notizen</label>
                            <textarea rows="4" wire:model.defer="preview.root.person_notes" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-900">Identity- und AI-Profil</h3>
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Nationalitaet</label>
                            <input type="text" wire:model.defer="preview.identity_profile.nationality" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Beruf / Taetigkeit</label>
                            <input type="text" wire:model.defer="preview.identity_profile.occupation" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Beziehungsstatus</label>
                            <input type="text" wire:model.defer="preview.identity_profile.relationship_status" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sprachen</label>
                            <textarea rows="3" wire:model.defer="preview.identity_profile.languages" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Interessen</label>
                            <textarea rows="3" wire:model.defer="preview.identity_profile.interests" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Persoenlichkeitsmerkmale</label>
                            <textarea rows="3" wire:model.defer="preview.identity_profile.personality_traits" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Werte</label>
                            <textarea rows="3" wire:model.defer="preview.identity_profile.values" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Kommunikationsstil</label>
                            <textarea rows="3" wire:model.defer="preview.bot_profile.communication_style" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Schreibstil</label>
                            <textarea rows="3" wire:model.defer="preview.bot_profile.writing_style" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Tagesablauf</label>
                            <textarea rows="4" wire:model.defer="preview.identity_profile.daily_routine" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">Optische Beschreibung</label>
                            <textarea rows="4" wire:model.defer="preview.identity_profile.physical_appearance" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">Hintergrundgeschichte</label>
                            <textarea rows="5" wire:model.defer="preview.identity_profile.background_story" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                        <div class="lg:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">AI-Verhaltensrichtlinien</label>
                            <textarea rows="5" wire:model.defer="preview.bot_profile.behavior_guidelines" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                        </div>
                    </div>
                </section>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex flex-wrap justify-end gap-3">
                @if($person)
                    <button type="button" wire:click="$dispatch('open-person-image-modal', { personId: {{ $person->id }} })" class="rounded-md border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                        Bilder erstellen
                    </button>
                @endif
                <button type="button" wire:click="close" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="save" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Speichern
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
