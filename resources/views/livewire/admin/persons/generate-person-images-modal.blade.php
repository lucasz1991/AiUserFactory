<div>
    <x-dialog-modal wire:model="showModal" maxWidth="6xl">
        <x-slot name="title">
            Bilder erstellen
        </x-slot>

        <x-slot name="content">
            <div @if($showModal) wire:poll.5s="refreshImageStatus" @endif class="space-y-5">
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
                    </aside>
                </div>

                <div class="grid gap-5 lg:grid-cols-2">
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
        </x-slot>

        <x-slot name="footer">
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" wire:click="close" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Schliessen
                </button>
                <button
                    type="button"
                    wire:click="generateImage"
                    wire:loading.attr="disabled"
                    wire:target="generateImage"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="generateImage">Bildauftrag starten</span>
                    <span wire:loading wire:target="generateImage">Starte Auftrag...</span>
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
