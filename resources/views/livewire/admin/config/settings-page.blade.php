<div class="space-y-6" wire:loading.class="opacity-50 pointer-events-none cursor-wait">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Einstellungen</h1>
                <p class="mt-2 text-sm text-gray-500">
                    Zentrale Konfiguration fuer externe Dienste und Transfers.
                </p>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-6 py-4">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="switchTab('scraper-transfer')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'scraper-transfer' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Scraper Transfer
                </button>

                <button type="button" wire:click="switchTab('openrouter')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'openrouter' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    OpenRouter / AI Connection
                </button>
            </div>
        </div>

        @if($activeTab === 'scraper-transfer')
            <div class="space-y-6 px-6 py-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Scraper Transfer</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Verbindung zur Base-Installation fuer den Profiltransfer.
                    </p>
                </div>

                <div>
                    <label for="base-api-url" class="block text-sm font-medium text-gray-700">Base API URL</label>
                    <input id="base-api-url" type="url" wire:model.defer="baseApiUrl" placeholder="https://base.example.com/api/scraper-profiles/sync" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    @error('baseApiUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="api-password" class="block text-sm font-medium text-gray-700">API Passwort</label>
                    <input id="api-password" type="password" wire:model.defer="apiPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    @error('apiPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                    <p class="font-semibold">Hinweis</p>
                    <p class="mt-1">Die Werte koennen aus der `.env` kommen oder hier in der Datenbank gespeichert werden.</p>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="saveScraperTransfer" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Speichern
                    </button>
                </div>
            </div>
        @endif

        @if($activeTab === 'openrouter')
            <div class="space-y-8 px-6 py-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">OpenRouter / AI Connection</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Generische Verbindung fuer Textausgabe, Datenanalyse, Bilderstellung, Bildverstehen, Speech-to-Text und Text-to-Speech.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">
                    <h3 class="text-sm font-semibold text-gray-900">API Verbindung</h3>

                    <div class="mt-5 grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="openrouter-api-url" class="block text-sm font-medium text-gray-700">API URL</label>
                            <input id="openrouter-api-url" type="url" wire:model.defer="openRouterApiUrl" placeholder="https://openrouter.ai/api/v1/chat/completions" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterApiUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-api-key" class="block text-sm font-medium text-gray-700">API Key</label>
                            <input id="openrouter-api-key" type="password" wire:model.defer="openRouterApiKey" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterApiKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-referer-url" class="block text-sm font-medium text-gray-700">HTTP-Referer / Site URL</label>
                            <input id="openrouter-referer-url" type="url" wire:model.defer="openRouterRefererUrl" placeholder="https://example.com" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterRefererUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-model-title" class="block text-sm font-medium text-gray-700">X-Title / App Name</label>
                            <input id="openrouter-model-title" type="text" wire:model.defer="openRouterModelTitle" placeholder="AiUserFactory" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterModelTitle') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Modell-Profile</h3>

                    <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label for="openrouter-text-model" class="block text-sm font-medium text-gray-700">Textausgabe Modell</label>
                            <input id="openrouter-text-model" type="text" wire:model.defer="openRouterTextModel" placeholder="openai/gpt-4o-mini" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTextModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-data-model" class="block text-sm font-medium text-gray-700">Datenanalyse Modell</label>
                            <input id="openrouter-data-model" type="text" wire:model.defer="openRouterDataModel" placeholder="openai/gpt-4o" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterDataModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-image-generation-model" class="block text-sm font-medium text-gray-700">Bilderstellung Modell</label>
                            <input id="openrouter-image-generation-model" type="text" wire:model.defer="openRouterImageGenerationModel" placeholder="openai/gpt-image-1" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            <p class="mt-1 text-xs text-gray-500">Fuer Personenbilder mit Referenzfotos muss das Modell Bild-Eingabe und Bild-Ausgabe unterstuetzen.</p>
                            @error('openRouterImageGenerationModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-image-understanding-model" class="block text-sm font-medium text-gray-700">Bildverstehen Modell</label>
                            <input id="openrouter-image-understanding-model" type="text" wire:model.defer="openRouterImageUnderstandingModel" placeholder="openai/gpt-4o" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterImageUnderstandingModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-stt-model" class="block text-sm font-medium text-gray-700">Speech-to-Text Modell</label>
                            <input id="openrouter-stt-model" type="text" wire:model.defer="openRouterSpeechToTextModel" placeholder="openai/whisper-1" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterSpeechToTextModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-tts-model" class="block text-sm font-medium text-gray-700">Text-to-Speech Modell</label>
                            <input id="openrouter-tts-model" type="text" wire:model.defer="openRouterTextToSpeechModel" placeholder="openai/tts-1" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTextToSpeechModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Request Defaults</h3>

                    <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="openrouter-timeout" class="block text-sm font-medium text-gray-700">Timeout Sekunden</label>
                            <input id="openrouter-timeout" type="number" min="5" max="600" wire:model.defer="openRouterTimeout" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTimeout') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-temperature" class="block text-sm font-medium text-gray-700">Temperature</label>
                            <input id="openrouter-temperature" type="number" min="0" max="2" step="0.1" wire:model.defer="openRouterTemperature" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTemperature') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-max-completion-tokens" class="block text-sm font-medium text-gray-700">Max Completion Tokens</label>
                            <input id="openrouter-max-completion-tokens" type="number" min="1" max="200000" wire:model.defer="openRouterMaxCompletionTokens" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterMaxCompletionTokens') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="openrouter-stream-enabled" class="block text-sm font-medium text-gray-700">Streaming aktiv</label>
                            <div class="mt-1 flex h-[46px] items-center rounded-md border border-gray-300 bg-white px-3 shadow-sm">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input id="openrouter-stream-enabled" type="checkbox" wire:model.defer="openRouterStreamEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                                    <span>stream: true erlauben</span>
                                </label>
                            </div>
                            @error('openRouterStreamEnabled') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                    <p class="font-semibold">Gespeicherte Setting-Keys</p>
                    <p class="mt-1">
                        Gruppe: <code>services</code>, Key: <code>openrouter</code>.
                        Enthalten sind <code>api_url</code>, <code>api_key</code>, <code>referer_url</code>, <code>model_title</code>,
                        alle Modell-Profile sowie <code>timeout</code>, <code>temperature</code>, <code>max_completion_tokens</code> und <code>stream_enabled</code>.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="saveOpenRouter" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Speichern
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
