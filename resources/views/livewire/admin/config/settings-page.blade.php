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

                <button type="button" wire:click="switchTab('assistant')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'assistant' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    AI Chatbot
                </button>

                <button type="button" wire:click="switchTab('client-controller')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'client-controller' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    ClientController
                </button>

                <button type="button" wire:click="switchTab('activity-planning')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'activity-planning' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Aktivitaetsplanung
                </button>

                <button type="button" wire:click="switchTab('processes')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'processes' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Prozesse
                </button>

                <button type="button" wire:click="switchTab('mail-registration')" class="rounded-md px-4 py-2 text-sm font-semibold {{ $activeTab === 'mail-registration' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                    Mail-Registrierung
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
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900">Modell-Profile</h3>
                        <p class="text-xs text-gray-500">Tests nutzen die gespeicherte API-Verbindung und das aktuell eingetragene Modell.</p>
                    </div>

                    <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-text-model" class="block text-sm font-medium text-gray-700">Textausgabe Modell</label>
                            <input id="openrouter-text-model" type="text" wire:model.defer="openRouterTextModel" placeholder="openai/gpt-4o-mini" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTextModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3">
                                <button type="button" wire:click="testOpenRouterTextModel" wire:loading.attr="disabled" wire:target="testOpenRouterTextModel" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testOpenRouterTextModel">Testen</span>
                                    <span wire:loading wire:target="testOpenRouterTextModel">Test laeuft ...</span>
                                </button>
                            </div>
                            <x-settings.openrouter-test-result :result="$openRouterModelTests['text'] ?? null" />
                        </div>

                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-data-model" class="block text-sm font-medium text-gray-700">Datenanalyse Modell</label>
                            <input id="openrouter-data-model" type="text" wire:model.defer="openRouterDataModel" placeholder="openai/gpt-4o" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterDataModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3">
                                <button type="button" wire:click="testOpenRouterDataModel" wire:loading.attr="disabled" wire:target="testOpenRouterDataModel" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testOpenRouterDataModel">Testen</span>
                                    <span wire:loading wire:target="testOpenRouterDataModel">Test laeuft ...</span>
                                </button>
                            </div>
                            <x-settings.openrouter-test-result :result="$openRouterModelTests['data'] ?? null" />
                        </div>

                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-image-generation-model" class="block text-sm font-medium text-gray-700">Bilderstellung Modell</label>
                            <input id="openrouter-image-generation-model" type="text" wire:model.defer="openRouterImageGenerationModel" placeholder="openai/gpt-image-1" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            <p class="mt-1 text-xs text-gray-500">Fuer Personenbilder mit Referenzfotos muss das Modell Bild-Eingabe und Bild-Ausgabe unterstuetzen.</p>
                            @error('openRouterImageGenerationModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3">
                                <button type="button" wire:click="testOpenRouterImageGenerationModel" wire:loading.attr="disabled" wire:target="testOpenRouterImageGenerationModel" class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testOpenRouterImageGenerationModel">Testbild erzeugen</span>
                                    <span wire:loading wire:target="testOpenRouterImageGenerationModel">Test laeuft ...</span>
                                </button>
                            </div>
                            <x-settings.openrouter-test-result :result="$openRouterModelTests['image_generation'] ?? null" />
                        </div>

                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-image-understanding-model" class="block text-sm font-medium text-gray-700">Bildverstehen Modell</label>
                            <input id="openrouter-image-understanding-model" type="text" wire:model.defer="openRouterImageUnderstandingModel" placeholder="openai/gpt-4o" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterImageUnderstandingModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <label for="openrouter-vision-test-image" class="mt-3 block text-xs font-medium text-gray-600">Testbild hochladen (das Modell beschreibt es)</label>
                            <input id="openrouter-vision-test-image" type="file" accept="image/*" wire:model="openRouterVisionTestImage" class="mt-1 block w-full text-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200" />
                            @error('openRouterVisionTestImage') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3">
                                <button type="button" wire:click="testOpenRouterImageUnderstandingModel" wire:loading.attr="disabled" wire:target="testOpenRouterImageUnderstandingModel, openRouterVisionTestImage" @if(! $openRouterVisionTestImage) disabled @endif class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testOpenRouterImageUnderstandingModel">Bild beschreiben lassen</span>
                                    <span wire:loading wire:target="testOpenRouterImageUnderstandingModel">Test laeuft ...</span>
                                </button>
                            </div>
                            <x-settings.openrouter-test-result :result="$openRouterModelTests['image_understanding'] ?? null" />
                        </div>

                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-stt-model" class="block text-sm font-medium text-gray-700">Speech-to-Text Modell</label>
                            <input id="openrouter-stt-model" type="text" wire:model.defer="openRouterSpeechToTextModel" placeholder="openai/whisper-1" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterSpeechToTextModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <label for="openrouter-stt-test-audio" class="mt-3 block text-xs font-medium text-gray-600">Audiodatei hochladen (das Modell transkribiert sie)</label>
                            <input id="openrouter-stt-test-audio" type="file" accept="audio/*" wire:model="openRouterSpeechTestAudio" class="mt-1 block w-full text-xs text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-700 hover:file:bg-slate-200" />
                            @error('openRouterSpeechTestAudio') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3">
                                <button type="button" wire:click="testOpenRouterSpeechToTextModel" wire:loading.attr="disabled" wire:target="testOpenRouterSpeechToTextModel, openRouterSpeechTestAudio" @if(! $openRouterSpeechTestAudio) disabled @endif class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="testOpenRouterSpeechToTextModel">Transkribieren</span>
                                    <span wire:loading wire:target="testOpenRouterSpeechToTextModel">Test laeuft ...</span>
                                </button>
                            </div>
                            <x-settings.openrouter-test-result :result="$openRouterModelTests['speech_to_text'] ?? null" />
                        </div>

                        <div class="flex flex-col rounded-lg border border-gray-200 p-4">
                            <label for="openrouter-tts-model" class="block text-sm font-medium text-gray-700">Text-to-Speech Modell</label>
                            <input id="openrouter-tts-model" type="text" wire:model.defer="openRouterTextToSpeechModel" placeholder="x-ai/grok-voice-tts-1.0" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('openRouterTextToSpeechModel') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-3 rounded-md bg-slate-50 p-2 text-xs text-slate-500">
                                Kein Test auf dieser Seite: Die Audioausgabe (Stimme, Format, Ausgabe-Endpunkt) wird ueber die Sprachverarbeitung im Tab "AI Chatbot" gesteuert und ist hier bewusst nicht ausfuehrbar.
                            </p>
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
                        Die Audioausgabe-Werte (<code>audio_output_api_url</code>, <code>audio_output_voice</code>, <code>audio_output_format</code>) bleiben gespeichert, werden hier aber nicht mehr bearbeitet.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="saveOpenRouter" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Speichern
                    </button>
                </div>
            </div>
        @endif

        @if($activeTab === 'assistant')
            <div class="space-y-8 px-6 py-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">AI Workflow Chatbot</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Floating Copilot fuer Workflow-Analyse, neue Workflows, Listen, Tags, Tasks und Workflow-Imports.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Darstellung und Verhalten</h3>

                    <div class="mt-5 grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="assistant-name" class="block text-sm font-medium text-gray-700">Name im Chatfenster</label>
                            <input id="assistant-name" type="text" wire:model.defer="assistantName" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-tool-rounds" class="block text-sm font-medium text-gray-700">Maximale Tool-Runden pro Nachricht</label>
                            <input id="assistant-tool-rounds" type="number" min="1" max="8" wire:model.defer="assistantMaxToolRounds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantMaxToolRounds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-speech-rate" class="block text-sm font-medium text-gray-700">Vorlese-Geschwindigkeit</label>
                            <input id="assistant-speech-rate" type="number" min="0.5" max="2" step="0.1" wire:model.defer="assistantSpeechRate" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantSpeechRate') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                <input type="checkbox" wire:model.defer="assistantEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                                <span>AI Chatbot aktivieren</span>
                            </label>
                            @error('assistantEnabled') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700">
                                <input type="checkbox" wire:model.defer="assistantAutoReadDefault" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                                <span>Antworten automatisch vorlesen</span>
                            </label>
                            @error('assistantAutoReadDefault') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Sprachverarbeitung</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Whisper und Piper laufen direkt auf dem Laravel-Server, ohne externen Sprachdienst oder offenen Netzwerk-Port. Browser, OpenRouter, Vosk und eSpeak NG bleiben als Alternativen erhalten.
                    </p>

                    @php
                        $localVoiceEnabled = (bool) ($assistantLocalVoiceStatus['enabled'] ?? false);
                        $localWhisperReady = (bool) ($assistantLocalVoiceStatus['transcription_ready'] ?? false);
                        $localPiperReady = (bool) ($assistantLocalVoiceStatus['synthesis_ready'] ?? false);
                        $localVoiceMissing = $assistantLocalVoiceStatus['missing'] ?? [];
                    @endphp

                    <div class="mt-4 flex flex-wrap items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs">
                        <span class="font-semibold text-gray-700">Serverlokale Runtime</span>
                        <span class="rounded px-2 py-1 font-medium {{ $localVoiceEnabled ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $localVoiceEnabled ? 'aktiviert' : 'deaktiviert' }}
                        </span>
                        <span class="rounded px-2 py-1 font-medium {{ $localWhisperReady ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                            Whisper {{ $localWhisperReady ? 'bereit' : 'nicht bereit' }}
                        </span>
                        <span class="rounded px-2 py-1 font-medium {{ $localPiperReady ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                            Piper {{ $localPiperReady ? 'bereit' : 'nicht bereit' }}
                        </span>
                        <button type="button" wire:click="refreshAssistantLocalVoiceStatus" class="ml-auto rounded border border-gray-300 bg-white px-2 py-1 font-semibold text-gray-700 hover:bg-gray-100">
                            Status pruefen
                        </button>
                        @if($localVoiceMissing !== [])
                            <span class="basis-full text-gray-500">Fehlt: {{ implode(', ', $localVoiceMissing) }}</span>
                        @endif
                    </div>

                    <div class="mt-5 grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="assistant-speech-input-provider" class="block text-sm font-medium text-gray-700">Spracheingabe</label>
                            <select id="assistant-speech-input-provider" wire:model.live="assistantSpeechInputProvider" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="whisper_local">Whisper (serverlokal, empfohlen)</option>
                                <option value="browser">Browser Spracheingabe</option>
                                <option value="vosk">Vosk HTTP-Service (Legacy)</option>
                            </select>
                            @error('assistantSpeechInputProvider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-speech-output-provider" class="block text-sm font-medium text-gray-700">Sprachausgabe</label>
                            <select id="assistant-speech-output-provider" wire:model.live="assistantSpeechOutputProvider" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="piper_local">Piper (serverlokal, empfohlen)</option>
                                <option value="ai">AI/OpenRouter Audio</option>
                                <option value="espeak_ng">eSpeak NG HTTP-Service (Legacy)</option>
                            </select>
                            @error('assistantSpeechOutputProvider') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        @if($assistantSpeechInputProvider === 'vosk')
                            <div class="md:col-span-2">
                                <label for="assistant-vosk-transcription-url" class="block text-sm font-medium text-gray-700">Vosk Transcription URL</label>
                                <input id="assistant-vosk-transcription-url" type="url" wire:model.defer="assistantVoskTranscriptionUrl" placeholder="http://127.0.0.1:2700/transcribe" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <p class="mt-1 text-xs text-gray-500">Laravel sendet die Mikrofonaufnahme als multipart <code>audio</code> mit <code>language=de-DE</code>.</p>
                                @error('assistantVoskTranscriptionUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if($assistantSpeechOutputProvider === 'espeak_ng')
                            <div>
                                <label for="assistant-espeak-ng-speech-url" class="block text-sm font-medium text-gray-700">eSpeak NG Speech URL</label>
                                <input id="assistant-espeak-ng-speech-url" type="url" wire:model.defer="assistantEspeakNgSpeechUrl" placeholder="http://127.0.0.1:2701/speech" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                @error('assistantEspeakNgSpeechUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="assistant-espeak-ng-voice" class="block text-sm font-medium text-gray-700">eSpeak NG Stimme</label>
                                <input id="assistant-espeak-ng-voice" type="text" wire:model.defer="assistantEspeakNgVoice" placeholder="de" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                                <p class="mt-1 text-xs text-gray-500">Default: <code>de</code>.</p>
                                @error('assistantEspeakNgVoice') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Zusatzinstruktionen</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Diese Hinweise werden zusaetzlich zum festen Workflow-Copilot-Systemprompt an die AI gegeben.
                    </p>
                    <textarea rows="8" wire:model.defer="assistantInstructions" class="mt-4 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Beispiel: Erstelle neue Workflows immer in Kategorie registration und nutze Webmail-Workflows bevorzugt als eingebettete Workflows."></textarea>
                    @error('assistantInstructions') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Autonome Workflow-Optimierung</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Die Reparatur nutzt ausschliesslich die System-Ausfuehrung. Bildverstehen kombiniert Screenshots mit einer bereinigten DOM-Elementkarte; Fallback-Modelle werden der Reihe nach versucht.
                    </p>

                    <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                        <div class="md:col-span-2 xl:col-span-4 rounded-lg border border-cyan-200 bg-cyan-50 p-4">
                            <label class="flex items-start gap-3 text-sm text-cyan-950">
                                <input type="checkbox" wire:model.defer="assistantCopilotAutoExecute" class="mt-0.5 rounded border-cyan-300 text-cyan-700 shadow-sm focus:ring-cyan-600">
                                <span>
                                    <strong class="block">Autonome Workflow-Aktionen nach bewusstem Start erlauben</strong>
                                    <span class="mt-1 block text-xs leading-5 text-cyan-800">Die Freigabe gilt nur fuer eine vom Benutzer bewusst gestartete Copilot-Reparatur und ausschliesslich fuer die System-Ausfuehrung, nie ClientController.</span>
                                </span>
                            </label>
                            @error('assistantCopilotAutoExecute') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="md:col-span-2 xl:col-span-4">
                            <label for="assistant-vision-fallback-models" class="block text-sm font-medium text-gray-700">Vision-Fallback-Modelle</label>
                            <textarea id="assistant-vision-fallback-models" rows="4" wire:model.defer="assistantVisionFallbackModels" placeholder="google/gemini-2.5-flash\nanthropic/claude-sonnet-4" class="mt-1 block w-full rounded-md border border-gray-300 p-3 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            <p class="mt-1 text-xs text-gray-500">Ein Modell pro Zeile, in der gewuenschten Reihenfolge. Das primaere Bildverstehen-Modell wird weiterhin unter OpenRouter festgelegt.</p>
                            @error('assistantVisionFallbackModels') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-copilot-max-minutes" class="block text-sm font-medium text-gray-700">Maximale Laufzeit (Min.)</label>
                            <input id="assistant-copilot-max-minutes" type="number" min="5" max="1440" wire:model.defer="assistantCopilotMaxMinutes" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantCopilotMaxMinutes') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-copilot-max-repair-iterations" class="block text-sm font-medium text-gray-700">Reparaturrunden</label>
                            <input id="assistant-copilot-max-repair-iterations" type="number" min="1" max="100" wire:model.defer="assistantCopilotMaxRepairIterations" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantCopilotMaxRepairIterations') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-copilot-max-probe-actions" class="block text-sm font-medium text-gray-700">Probeaktionen</label>
                            <input id="assistant-copilot-max-probe-actions" type="number" min="1" max="500" wire:model.defer="assistantCopilotMaxProbeActions" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('assistantCopilotMaxProbeActions') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="assistant-copilot-max-same-state-repeats" class="block text-sm font-medium text-gray-700">Gleicher Zustand</label>
                            <input id="assistant-copilot-max-same-state-repeats" type="number" min="1" max="10" wire:model.defer="assistantCopilotMaxSameStateRepeats" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            <p class="mt-1 text-xs text-gray-500">Danach gilt der Lauf als festgefahren.</p>
                            @error('assistantCopilotMaxSameStateRepeats') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                    <p class="font-semibold">Verfuegbare Chatbot-Funktionen</p>
                    <p class="mt-1">
                        Workflows/listen, Workflow-Kontext laden, letzten Run analysieren, Workflow erstellen/aktualisieren,
                        Listen erstellen, Tags setzen, Task-Katalog abfragen, Tasks hinzufuegen/aendern, eingebettete Workflows vorschlagen und Workflow-CSV/ZIP importieren.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="saveAssistant" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Speichern
                    </button>
                </div>
            </div>
        @endif

        @if($activeTab === 'client-controller')
            <div class="space-y-8 px-6 py-6">
                <livewire:admin.client-controller.update-settings />

                <div>
                    <h2 class="text-lg font-semibold text-gray-900">ClientController: Server & Sicherheit</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Einstellungen fuer Node-Bindung, Heartbeats, Job-Sicherheit und initiale API-Key-Anmeldung.
                    </p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Server-Bindung</h3>
                    <div class="mt-5 grid gap-6 md:grid-cols-2">
                        <div>
                            <label for="cc-server-domain" class="block text-sm font-medium text-gray-700">Primäre Server-Domain</label>
                            <input id="cc-server-domain" type="url" wire:model.defer="ccServerDomain" placeholder="https://app.followflow.de" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('ccServerDomain') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="cc-fallback-domain" class="block text-sm font-medium text-gray-700">Fallback-Domain</label>
                            <input id="cc-fallback-domain" type="url" wire:model.defer="ccFallbackServerDomain" placeholder="https://backup.followflow.de" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('ccFallbackServerDomain') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Sicherheit & Defaults</h3>

                    <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label for="cc-heartbeat-interval" class="block text-sm font-medium text-gray-700">Heartbeat-Intervall (Sek.)</label>
                            <input id="cc-heartbeat-interval" type="number" min="5" max="3600" wire:model.defer="ccHeartbeatIntervalSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('ccHeartbeatIntervalSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="cc-job-timeout" class="block text-sm font-medium text-gray-700">Job-Timeout (Sek.)</label>
                            <input id="cc-job-timeout" type="number" min="5" max="86400" wire:model.defer="ccJobTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('ccJobTimeoutSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="cc-bootstrap-api-key" class="block text-sm font-medium text-gray-700">Bootstrap API-Key (ClientController)</label>
                            <input id="cc-bootstrap-api-key" type="text" wire:model.defer="ccBootstrapApiKey" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                            @error('ccBootstrapApiKey') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-500">Dieser Key wird nur fuer die initiale Node-Registrierung verwendet (Bootstrap).</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.defer="ccRequireSignedJobs" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                            Signierte Jobs erzwingen
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.defer="ccAllowServerRebind" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                            Server-Rebind global erlauben
                        </label>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                    <p class="font-semibold">Gespeicherte Setting-Keys</p>
                    <p class="mt-1">
                        Gruppe <code>client_controller</code> mit den Keys <code>server</code> und <code>security</code>.
                        Der Bootstrap-Key liegt in <code>security.bootstrap_api_key</code>.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button type="button" wire:click="saveClientControllerSettings" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Speichern
                    </button>
                </div>
            </div>
        @endif

        @if($activeTab === 'activity-planning')
            <div class="px-6 py-6">
                <livewire:admin.config.activity-planning-settings />
            </div>
        @endif

        @if($activeTab === 'processes')
            <div class="px-6 py-6">
                <livewire:admin.config.process-settings />
            </div>
        @endif

        @if($activeTab === 'mail-registration')
            <div class="px-6 py-6">
                <livewire:admin.config.mail-registration-settings />
            </div>
        @endif
    </div>
</div>
