<div>
    @if($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-8">
                <div class="fixed inset-0 bg-slate-900/60" wire:click="close"></div>

                <div class="relative z-10 w-full max-w-6xl rounded-lg bg-white shadow-xl">
                    <div class="flex items-start justify-between border-b border-gray-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">
                                Person mit AI komplettieren
                            </h2>
                            <p class="mt-1 text-sm text-gray-500">
                                Textprofil vervollstaendigen und Bilder mit vorhandenen Personenbildern als Referenz erzeugen.
                            </p>
                        </div>

                        <button type="button" wire:click="close" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                            ✕
                        </button>
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

                        <div class="mb-5 flex flex-wrap justify-end gap-3">
                            <button
                                type="button"
                                wire:click="generate"
                                wire:loading.attr="disabled"
                                class="rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700 disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="generate">AI-Vorschlag erstellen</span>
                                <span wire:loading wire:target="generate">Erstelle Vorschlag...</span>
                            </button>

                            <button
                                type="button"
                                wire:click="save"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                            >
                                Vorschlag speichern
                            </button>
                        </div>

                        <div class="grid gap-6 lg:grid-cols-2">
                            <section class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <h3 class="text-sm font-semibold text-gray-900">Basis-Textfelder</h3>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Vorname</label>
                                        <input type="text" wire:model.defer="preview.root.person_first_name" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Nachname</label>
                                        <input type="text" wire:model.defer="preview.root.person_last_name" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Alias</label>
                                        <input type="text" wire:model.defer="preview.root.person_alias" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Geschlecht / Rolle</label>
                                        <input type="text" wire:model.defer="preview.root.person_gender" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">E-Mail</label>
                                        <input type="email" wire:model.defer="preview.root.person_email" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Telefon</label>
                                        <input type="text" wire:model.defer="preview.root.person_phone" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Zeitzone</label>
                                        <input type="text" wire:model.defer="preview.root.person_timezone" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Land</label>
                                        <input type="text" wire:model.defer="preview.root.person_country" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Strasse</label>
                                        <input type="text" wire:model.defer="preview.root.person_address_line1" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Adresszusatz</label>
                                        <input type="text" wire:model.defer="preview.root.person_address_line2" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">PLZ</label>
                                        <input type="text" wire:model.defer="preview.root.person_postal_code" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Ort</label>
                                        <input type="text" wire:model.defer="preview.root.person_city" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700">Region / Bundesland</label>
                                        <input type="text" wire:model.defer="preview.root.person_state" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700">Notizen</label>
                                        <textarea rows="5" wire:model.defer="preview.root.person_notes" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-gray-200 bg-white p-4">
                                <h3 class="text-sm font-semibold text-gray-900">Identity- und AI-Textprofil</h3>

                                <div class="mt-4 space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Nationalitaet</label>
                                        <input type="text" wire:model.defer="preview.identity_profile.nationality" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Beruf / Taetigkeit</label>
                                        <input type="text" wire:model.defer="preview.identity_profile.occupation" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Beziehungsstatus</label>
                                        <input type="text" wire:model.defer="preview.identity_profile.relationship_status" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Optische Beschreibung</label>
                                        <textarea rows="4" wire:model.defer="preview.identity_profile.physical_appearance" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm" placeholder="z. B. Groesse, Statur, Haarfarbe, Frisur, Kleidungsstil, markante Merkmale"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Sprachen</label>
                                        <textarea rows="3" wire:model.defer="preview.identity_profile.languages" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Interessen</label>
                                        <textarea rows="3" wire:model.defer="preview.identity_profile.interests" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Persoenlichkeitsmerkmale</label>
                                        <textarea rows="3" wire:model.defer="preview.identity_profile.personality_traits" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Werte</label>
                                        <textarea rows="3" wire:model.defer="preview.identity_profile.values" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Tagesablauf</label>
                                        <textarea rows="4" wire:model.defer="preview.identity_profile.daily_routine" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Hintergrundgeschichte</label>
                                        <textarea rows="5" wire:model.defer="preview.identity_profile.background_story" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Kommunikationsstil</label>
                                        <textarea rows="3" wire:model.defer="preview.bot_profile.communication_style" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Schreibstil</label>
                                        <textarea rows="3" wire:model.defer="preview.bot_profile.writing_style" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">AI-Verhaltensrichtlinien</label>
                                        <textarea rows="5" wire:model.defer="preview.bot_profile.behavior_guidelines" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <section class="mt-6 rounded-lg border border-gray-200 bg-white p-4">
                            @php($imagePresetOptions = $this->imagePresetOptions())

                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">Bilderstellung</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        Erst Profilportrait, danach passende Hobby-, Arbeits- und Charakterbilder mit denselben Referenzen.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    wire:click="generateImage"
                                    wire:loading.attr="disabled"
                                    wire:target="generateImage"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="generateImage">Bildtyp erstellen</span>
                                    <span wire:loading wire:target="generateImage">Erstelle Bild...</span>
                                </button>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach($imagePresetOptions as $presetKey => $presetLabel)
                                    <button
                                        type="button"
                                        wire:click="applyImagePreset('{{ $presetKey }}')"
                                        class="rounded-md px-3 py-1.5 text-xs font-semibold {{ $imagePreset === $presetKey ? 'bg-slate-900 text-white' : 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
                                    >
                                        {{ $presetLabel }}
                                    </button>
                                @endforeach
                            </div>

                            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                                <div class="lg:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700">Bildprompt</label>
                                    <textarea rows="5" wire:model.defer="imagePrompt" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm"></textarea>
                                    @error('imagePrompt') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                    @error('imagePreset') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Format</label>
                                    <select wire:model.defer="imageAspectRatio" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm">
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

                                    <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                                        Referenzbilder: {{ count($referenceImages) }}
                                    </div>

                                    <label class="mt-4 flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                                        <input type="checkbox" wire:model.defer="setGeneratedImageAsAvatar" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                                        Profilportrait direkt als Profilbild setzen
                                    </label>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">Verwendete Referenzen</h4>

                                    @if($referenceImages === [])
                                        <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                            Noch keine Bilddateien vorhanden. Die Erstellung nutzt dann nur den Textprompt.
                                        </p>
                                    @else
                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            @foreach($referenceImages as $referenceImage)
                                                <div class="overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                                                    @if(($referenceImage['url'] ?? '') !== '')
                                                        <img src="{{ $referenceImage['url'] }}" alt="{{ $referenceImage['name'] ?? 'Referenzbild' }}" class="aspect-square w-full object-cover">
                                                    @endif
                                                    <div class="truncate px-2 py-1.5 text-xs text-gray-600">
                                                        {{ $referenceImage['name'] ?? 'Referenzbild' }}
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">Zuletzt generiert</h4>

                                    @if($generatedImages === [])
                                        <p class="mt-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                                            Noch kein Bild in dieser Sitzung generiert.
                                        </p>
                                    @else
                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            @foreach($generatedImages as $generatedImage)
                                                <div class="overflow-hidden rounded-md border border-gray-200 bg-gray-50">
                                                    @if(($generatedImage['url'] ?? '') !== '')
                                                        <img src="{{ $generatedImage['url'] }}" alt="{{ $generatedImage['name'] ?? 'Generiertes Bild' }}" class="aspect-square w-full object-cover">
                                                    @endif
                                                    <div class="truncate px-2 py-1.5 text-xs text-gray-600">
                                                        {{ $generatedImage['name'] ?? 'Generiertes Bild' }}
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4">
                        <button type="button" wire:click="close" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
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
</div>
