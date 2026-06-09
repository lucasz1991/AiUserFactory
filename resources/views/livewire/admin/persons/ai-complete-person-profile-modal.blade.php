<div>
    @if($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-8">
                <div class="fixed inset-0 bg-slate-900/60" wire:click="close"></div>

                <div class="relative z-10 w-full max-w-6xl overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="border-b border-slate-200 bg-slate-50 px-6 py-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Persona</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-900">Person bearbeiten</h2>
                                <p class="mt-1 max-w-3xl text-sm text-slate-600">
                                    Stammdaten, Kontakt, AI-Profil und Bot-Verhalten pruefen, manuell bearbeiten oder per Prompt vorschlagen lassen.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="openImageModal" class="rounded-md border border-indigo-200 bg-white px-3 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50">
                                    Bilder erstellen
                                </button>
                                <button type="button" wire:click="close" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                    Schliessen
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="max-h-[75vh] overflow-y-auto px-6 py-5">
                        @if (session()->has('success'))
                            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if (session()->has('error'))
                            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
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

                                <div class="flex flex-wrap gap-2">
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

                                    <button type="button" wire:click="save" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                        Speichern
                                    </button>
                                </div>
                            </div>

                            <textarea
                                rows="4"
                                wire:model.defer="profilePrompt"
                                class="mt-4 w-full rounded-md border-purple-200 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                placeholder="z. B. 28-jaehrige fiktive Person aus Berlin, sportlich, technisch interessiert, freundlich-direkter Schreibstil, glaubwuerdiger beruflicher Hintergrund im Marketing."
                            ></textarea>
                            @error('profilePrompt') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </section>

                        <div class="mt-5 grid gap-5 xl:grid-cols-3">
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm xl:col-span-1">
                                <h3 class="text-sm font-semibold text-slate-900">Stammdaten</h3>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
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
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm xl:col-span-2">
                                <h3 class="text-sm font-semibold text-slate-900">Kontakt und Adresse</h3>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">E-Mail</label>
                                        <input type="email" wire:model.defer="preview.root.person_email" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Telefon</label>
                                        <input type="text" wire:model.defer="preview.root.person_phone" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Zeitzone</label>
                                        <input type="text" wire:model.defer="preview.root.person_timezone" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
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

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">Region / Bundesland</label>
                                        <input type="text" wire:model.defer="preview.root.person_state" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700">Notizen</label>
                                        <textarea rows="4" wire:model.defer="preview.root.person_notes" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <section class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">Identity- und AI-Profil</h3>
                                    <p class="mt-1 text-sm text-slate-500">Diese Felder steuern Kontext, Stil und Verhalten der Persona.</p>
                                </div>
                            </div>

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
                                    <textarea rows="4" wire:model.defer="preview.identity_profile.physical_appearance" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="z. B. Groesse, Statur, Haarfarbe, Frisur, Kleidungsstil, markante Merkmale"></textarea>
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

                    <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
                        <button type="button" wire:click="openImageModal" class="rounded-md border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            Bilder erstellen
                        </button>
                        <button type="button" wire:click="close" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Abbrechen
                        </button>
                        <button type="button" wire:click="save" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            Speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showImageModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" wire:poll.5s="refreshImageStatus">
            <div class="flex min-h-screen items-center justify-center px-4 py-8">
                <div class="fixed inset-0 bg-slate-900/70" wire:click="closeImageModal"></div>

                <div class="relative z-10 w-full max-w-6xl overflow-hidden rounded-lg bg-white shadow-xl">
                    <div class="border-b border-slate-200 bg-indigo-50 px-6 py-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">AI-Bilder</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-900">Bilder erstellen</h2>
                                <p class="mt-1 max-w-3xl text-sm text-slate-600">
                                    Bildprompt, Format und Referenzen separat steuern. Doppelte Referenzen mit gleichem Dateipfad werden nur einmal genutzt.
                                </p>
                            </div>

                            <button type="button" wire:click="closeImageModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Schliessen
                            </button>
                        </div>
                    </div>

                    <div class="max-h-[75vh] overflow-y-auto px-6 py-5">
                        @if (session()->has('success'))
                            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if (session()->has('error'))
                            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                                {{ session('error') }}
                            </div>
                        @endif

                        @php($imagePresetOptions = $this->imagePresetOptions())

                        <div class="grid gap-5 lg:grid-cols-3">
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                                <div class="flex flex-wrap gap-2">
                                    @foreach($imagePresetOptions as $presetKey => $presetLabel)
                                        <button
                                            type="button"
                                            wire:click="applyImagePreset('{{ $presetKey }}')"
                                            class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $imagePreset === $presetKey ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            {{ $presetLabel }}
                                        </button>
                                    @endforeach
                                </div>

                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-slate-700">Kurze Bildidee</label>
                                    <textarea
                                        rows="3"
                                        wire:model.defer="imagePromptBrief"
                                        class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"
                                        placeholder="z. B. Portrait im Abendlicht am Fenster, natuerliches Lachen, urbaner Hintergrund, hochwertiger Instagram-Look"
                                    ></textarea>
                                    @error('imagePromptBrief') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div class="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        wire:click="improveImagePrompt"
                                        wire:loading.attr="disabled"
                                        wire:target="improveImagePrompt"
                                        class="rounded-md border border-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="improveImagePrompt">Prompt mit AI vorbereiten</span>
                                        <span wire:loading wire:target="improveImagePrompt">Bereite Prompt vor...</span>
                                    </button>
                                </div>

                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-slate-700">Finaler Bildprompt</label>
                                    <textarea rows="7" wire:model.defer="imagePrompt" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"></textarea>
                                    @error('imagePrompt') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    @error('imagePreset') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </section>

                            <aside class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Format</label>
                                    <select wire:model.defer="imageAspectRatio" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                        <option value="1:1">1:1 Quadrat</option>
                                        <option value="2:3">2:3 Portrait</option>
                                        <option value="3:2">3:2 Querformat</option>
                                        <option value="3:4">3:4 Portrait</option>
                                        <option value="4:3">4:3 Querformat</option>
                                        <option value="4:5">4:5 Portrait</option>
                                        <option value="5:4">5:4 Querformat</option>
                                        <option value="9:16">9:16 Story</option>
                                        <option value="16:9">16:9 Wide</option>
                                        <option value="21:9">21:9 Ultra Wide</option>
                                    </select>
                                    @error('imageAspectRatio') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Anzahl Bilder</label>
                                    <input type="number" min="1" max="8" wire:model.defer="imageCount" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    @error('imageCount') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div class="rounded-md border border-slate-200 bg-white p-3 text-sm text-slate-600">
                                    Referenzbilder: <span class="font-semibold text-slate-900">{{ count($referenceImages) }}</span>
                                </div>

                                <label class="flex items-center gap-3 rounded-md border border-slate-200 bg-white p-3 text-sm font-medium text-slate-700">
                                    <input type="checkbox" wire:model.defer="setGeneratedImageAsAvatar" class="rounded border-slate-300 text-slate-900 shadow-sm focus:ring-slate-900">
                                    Erstes Profilportrait als Profilbild setzen
                                </label>

                                <button
                                    type="button"
                                    wire:click="generateImage"
                                    wire:loading.attr="disabled"
                                    wire:target="generateImage"
                                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="generateImage">Bildauftrag starten</span>
                                    <span wire:loading wire:target="generateImage">Starte Auftrag...</span>
                                </button>
                            </aside>
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-2">
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-slate-900">Verwendete Referenzen</h3>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ count($referenceImages) }}</span>
                                </div>

                                @if($referenceImages === [])
                                    <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                        Noch keine Bilddateien vorhanden. Die Erstellung nutzt nur den Textprompt.
                                    </p>
                                @else
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        @foreach($referenceImages as $referenceImage)
                                            <div class="overflow-hidden rounded-md border border-slate-200 bg-slate-50" wire:key="reference-image-{{ $referenceImage['id'] }}">
                                                @if(($referenceImage['url'] ?? '') !== '')
                                                    <img src="{{ $referenceImage['url'] }}" alt="{{ $referenceImage['name'] ?? 'Referenzbild' }}" class="aspect-square w-full object-cover">
                                                @endif
                                                <div class="truncate px-2 py-1.5 text-xs text-slate-600">
                                                    {{ $referenceImage['name'] ?? 'Referenzbild' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-slate-900">Bildauftrag</h3>
                                    @if($isGeneratingImage)
                                        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200">Laeuft</span>
                                    @endif
                                </div>

                                @if($isGeneratingImage)
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        @for($index = 0; $index < max(1, $imageJobPlaceholderCount); $index++)
                                            <div class="overflow-hidden rounded-md border border-indigo-200 bg-indigo-50" wire:key="image-placeholder-{{ $index }}">
                                                <div class="flex aspect-square w-full items-center justify-center">
                                                    <div class="h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-600"></div>
                                                </div>
                                                <div class="px-2 py-1.5 text-xs font-medium text-indigo-700">
                                                    Bild {{ $index + 1 }} wird erstellt
                                                </div>
                                            </div>
                                        @endfor
                                    </div>
                                @endif

                                @if($generatedImages === [] && ! $isGeneratingImage)
                                    <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                                        Noch kein laufender oder zuletzt erkannter Bildauftrag.
                                    </p>
                                @elseif($generatedImages !== [])
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                        @foreach($generatedImages as $generatedImage)
                                            <div class="overflow-hidden rounded-md border border-slate-200 bg-slate-50" wire:key="generated-image-{{ $generatedImage['id'] }}">
                                                @if(($generatedImage['url'] ?? '') !== '')
                                                    <img src="{{ $generatedImage['url'] }}" alt="{{ $generatedImage['name'] ?? 'Generiertes Bild' }}" class="aspect-square w-full object-cover">
                                                @endif
                                                <div class="truncate px-2 py-1.5 text-xs text-slate-600">
                                                    {{ $generatedImage['name'] ?? 'Generiertes Bild' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
                        <button type="button" wire:click="closeImageModal" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Schliessen
                        </button>
                        <button type="button" wire:click="generateImage" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Bildauftrag starten
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
