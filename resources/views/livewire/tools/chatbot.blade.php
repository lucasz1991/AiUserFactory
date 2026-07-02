<div
    data-workflow-copilot-root
    data-open="0"
    x-data="{
        showChat: false,
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        submitting: false,
        pendingLabel: '',
        voiceSupported: false,
        listening: false,
        recognition: null,
        ttsEndpoint: @js(\Illuminate\Support\Facades\Route::has('assistant.audio-output.stream') ? route('assistant.audio-output.stream', [], false) : null),
        csrfToken: @js(csrf_token()),
        speechSupported: false,
        autoRead: @js($assistantAutoReadDefault),
        speechRate: @js($assistantSpeechRate),
        ttsAudio: null,
        ttsAbortController: null,
        ttsObjectUrl: null,
        ttsError: '',
        speaking: false,
        speakingIndex: null,
        lastAssistantMessageKey: null,
        refreshTimer: null,
        livewireComponent() {
            const root = this.$root.closest('[wire\\:id]');
            const id = root ? root.getAttribute('wire:id') : null;

            return id && window.Livewire && typeof window.Livewire.find === 'function'
                ? window.Livewire.find(id)
                : null;
        },
        async callLivewire(method, ...parameters) {
            const component = this.livewireComponent();

            if (!component || typeof component.call !== 'function') {
                console.warn('Workflow Copilot Livewire-Komponente nicht bereit:', method);
                return null;
            }

            return await component.call(method, ...parameters);
        },
        init() {
            this.showChat = sessionStorage.getItem('workflow-copilot-open') === '1';
            this.autoRead = this.readBool('workflow-copilot-auto-read', this.autoRead);
            this.speechRate = this.readNumber('workflow-copilot-speech-rate', this.speechRate);
            this.voiceSupported = ('SpeechRecognition' in window) || ('webkitSpeechRecognition' in window);
            this.speechSupported = Boolean(this.ttsEndpoint && window.fetch && window.Audio && window.URL);
            this.lastAssistantMessageKey = this.latestAssistantMessageKey(this.chatHistory);
            this.$watch('chatHistory', (history) => {
                this.handleNewAssistantMessage(history);
                this.scrollMessages();
            });
            this.$watch('autoRead', (enabled) => {
                localStorage.setItem('workflow-copilot-auto-read', enabled ? '1' : '0');
                if (!enabled) this.stopSpeaking();
            });
            this.$watch('speechRate', (rate) => localStorage.setItem('workflow-copilot-speech-rate', String(rate || 1)));
            this.$nextTick(() => window.setTimeout(() => this.syncContext(), 0));
        },
        readBool(key, fallback) {
            const stored = localStorage.getItem(key);
            return stored === null ? Boolean(fallback) : stored === '1';
        },
        readNumber(key, fallback) {
            const stored = Number(localStorage.getItem(key));
            return Number.isFinite(stored) && stored > 0 ? stored : Number(fallback || 1);
        },
        setOpen(open) {
            this.showChat = Boolean(open);
            sessionStorage.setItem('workflow-copilot-open', this.showChat ? '1' : '0');
            if (this.showChat) {
                this.syncContext();
                this.scrollMessages();
            } else {
                this.stopSpeaking();
            }
        },
        busy() {
            return this.submitting || this.isLoading;
        },
        collectContext() {
            const path = window.location.pathname;
            const workflowMatch = path.match(/\/netzwerk\/workflows\/([^/?#]+)/);

            return {
                route_name: null,
                path,
                page_title: document.title,
                workflow_id: workflowMatch && /^\\d+$/.test(workflowMatch[1]) ? workflowMatch[1] : null,
                workflow_slug: workflowMatch && !/^\\d+$/.test(workflowMatch[1]) ? workflowMatch[1] : null,
            };
        },
        async syncContext() {
            await this.callLivewire('updatePageContext', this.collectContext());
        },
        scrollMessages() {
            this.$nextTick(() => {
                const messages = this.$refs.messages;
                if (!messages) return;
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
            });
        },
        resizeComposer() {
            const composer = this.$refs.composer;
            if (!composer) return;
            composer.style.height = 'auto';
            composer.style.height = `${Math.min(composer.scrollHeight, 132)}px`;
        },
        async send() {
            if (this.busy()) return;

            const outgoing = String(this.draft || '').trim();
            if (!outgoing) return;

            this.stopSpeaking();
            this.submitting = true;
            this.pendingLabel = outgoing;
            this.draft = '';
            this.resizeComposer();
            this.scrollMessages();

            try {
                await this.syncContext();
                await this.callLivewire('sendMessage', outgoing);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.resizeComposer();
                this.scrollMessages();
            }
        },
        async quick(prompt) {
            if (this.busy()) return;
            this.setOpen(true);
            this.draft = prompt;
            await this.send();
        },
        latestToolEvents() {
            return Array.isArray(this.toolEvents) ? [...this.toolEvents].reverse().slice(0, 10) : [];
        },
        latestAssistantMessageKey(history) {
            const messages = Array.isArray(history) ? history : [];
            const item = [...messages].reverse().find((message) => message && message.role === 'assistant');
            return item ? `${item.time || ''}|${item.content || ''}` : null;
        },
        handleNewAssistantMessage(history) {
            const messages = Array.isArray(history) ? history : [];
            const index = messages.map((item) => item && item.role).lastIndexOf('assistant');
            if (index < 0) return;

            const item = messages[index];
            const key = `${item.time || ''}|${item.content || ''}`;
            if (key === this.lastAssistantMessageKey) return;

            this.lastAssistantMessageKey = key;

            if (this.autoRead && item.content) {
                this.speak(item.content, index);
            }
        },
        async speak(text, index = null) {
            if (!this.speechSupported || !text) return;

            this.stopSpeaking();
            this.ttsError = '';
            this.speaking = true;
            this.speakingIndex = index;
            this.ttsAbortController = new AbortController();

            try {
                const response = await fetch(this.ttsEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'audio/mpeg',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: this.ttsAbortController.signal,
                    body: JSON.stringify({
                        text: String(text).slice(0, 4000),
                        speed: Number(this.speechRate || 1),
                    }),
                });

                if (!response.ok) {
                    throw new Error(await response.text() || `HTTP ${response.status}`);
                }

                const blob = await response.blob();
                this.ttsObjectUrl = URL.createObjectURL(blob);
                this.ttsAudio = new Audio(this.ttsObjectUrl);
                this.ttsAudio.onended = () => this.stopSpeaking(false);
                this.ttsAudio.onerror = () => this.stopSpeaking(false);
                await this.ttsAudio.play();
            } catch (error) {
                if (error && error.name !== 'AbortError') {
                    this.ttsError = 'Audioausgabe fehlgeschlagen.';
                    console.warn('Workflow Copilot Audioausgabe fehlgeschlagen:', error);
                }
                this.stopSpeaking(false);
            }
        },
        stopSpeaking(abort = true) {
            if (abort && this.ttsAbortController) {
                this.ttsAbortController.abort();
            }
            if (this.ttsAudio) {
                this.ttsAudio.pause();
                this.ttsAudio.src = '';
            }
            if (this.ttsObjectUrl) {
                URL.revokeObjectURL(this.ttsObjectUrl);
            }
            this.ttsAbortController = null;
            this.ttsAudio = null;
            this.ttsObjectUrl = null;
            this.speaking = false;
            this.speakingIndex = null;
        },
        toggleListening() {
            if (this.listening) {
                this.recognition && this.recognition.stop();
                this.listening = false;
                return;
            }

            this.startListening();
        },
        startListening() {
            if (!this.voiceSupported || this.busy()) return;

            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.recognition = new SpeechRecognition();
            this.recognition.lang = 'de-DE';
            this.recognition.interimResults = true;
            this.recognition.continuous = false;
            const baseDraft = String(this.draft || '').trim();
            let finalTranscript = '';

            this.recognition.onresult = (event) => {
                let interim = '';
                for (let index = event.resultIndex; index < event.results.length; index++) {
                    const transcript = event.results[index][0].transcript;
                    if (event.results[index].isFinal) {
                        finalTranscript = [finalTranscript, transcript].filter(Boolean).join(' ');
                    } else {
                        interim += transcript;
                    }
                }

                this.draft = [baseDraft, finalTranscript || interim].filter(Boolean).join(' ');
                this.resizeComposer();
            };
            this.recognition.onend = () => {
                this.listening = false;
                if (finalTranscript.trim()) {
                    this.draft = [baseDraft, finalTranscript.trim()].filter(Boolean).join(' ');
                    this.resizeComposer();
                }
            };
            this.recognition.onerror = () => {
                this.listening = false;
            };
            this.listening = true;
            this.recognition.start();
        },
        handleUiAction(event) {
            const detail = Array.isArray(event.detail) ? (event.detail[0] || {}) : (event.detail || {});
            const action = detail.action || detail;
            if (action && action.type === 'navigate' && action.url) {
                window.location.assign(action.url);
            }
        },
        refreshWorkflowPage() {
            if (this.refreshTimer) {
                window.clearTimeout(this.refreshTimer);
            }

            this.refreshTimer = window.setTimeout(() => {
                this.refreshTimer = null;
                try {
                    const roots = Array.from(document.querySelectorAll('[wire\\:id]'));
                    roots.forEach((element) => {
                        if (this.$root.contains(element)) return;
                        const id = element.getAttribute('wire:id');
                        const component = window.Livewire && window.Livewire.find ? window.Livewire.find(id) : null;
                        if (!component) return;

                        if (typeof component.$refresh === 'function') {
                            component.$refresh();
                        } else if (typeof component.call === 'function') {
                            component.call('$refresh');
                        }
                    });
                } catch (error) {
                    console.warn('Workflow Copilot Seitenrefresh fehlgeschlagen:', error);
                }
            }, 250);
        },
    }"
    x-on:assistant-ui-action.window="handleUiAction($event)"
    x-on:assistant-workflow-page-refresh.window="refreshWorkflowPage()"
    x-on:keydown.escape.window="if (showChat) setOpen(false)"
    x-bind:data-open="showChat ? '1' : '0'"
    class="fixed bottom-4 right-4 z-[80] sm:bottom-5 sm:right-5"
>
    <style>
        [x-cloak] { display: none !important; }
        [data-workflow-copilot-root] .workflow-copilot-panel { display: none; }
        [data-workflow-copilot-root][data-open="1"] .workflow-copilot-panel { display: flex; }
        [data-workflow-copilot-root][data-open="1"] .workflow-copilot-button { display: none; }
        [data-workflow-copilot-root][data-open="0"] .workflow-copilot-button { display: flex; }
    </style>

    <button
        type="button"
        x-show="!showChat"
        x-transition
        x-on:click="setOpen(true)"
        class="workflow-copilot-button group h-14 w-14 items-center justify-center rounded-full bg-slate-950 text-sm font-black text-white shadow-xl shadow-slate-900/25 ring-1 ring-white/20 transition hover:-translate-y-0.5 hover:bg-cyan-700"
        aria-label="AI Workflow Copilot oeffnen"
    >
        AI
        <span class="absolute -right-0.5 -top-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400"></span>
    </button>

    <section
        x-cloak
        x-show="showChat"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="translate-y-3 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-3 opacity-0"
        style="display: none;"
        class="workflow-copilot-panel h-[min(760px,calc(100vh-2rem))] w-[min(470px,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl shadow-slate-900/25"
        aria-label="AI Workflow Copilot"
    >
        <header class="shrink-0 border-b border-slate-800 bg-slate-950 px-4 py-3 text-white">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="truncate text-sm font-black">{{ $assistantName }}</div>
                    <div class="mt-0.5 truncate text-[11px] font-semibold uppercase tracking-[0.12em] text-cyan-200">Workflow-Seite aktiv</div>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" x-on:click="autoRead = !autoRead" class="rounded px-2 py-1 text-xs font-semibold transition" :class="autoRead ? 'bg-emerald-400 text-slate-950' : 'bg-white/10 text-slate-200 hover:bg-white/15'">
                        Audio
                    </button>
                    <button type="button" x-show="speaking" x-cloak x-on:click="stopSpeaking()" class="rounded bg-rose-500 px-2 py-1 text-xs font-semibold text-white">
                        Stop
                    </button>
                    <button type="button" wire:click="clearChat" class="rounded px-2 py-1 text-xs font-semibold text-slate-200 hover:bg-white/10">
                        Leeren
                    </button>
                    <button type="button" x-on:click="setOpen(false)" class="rounded px-2 py-1 text-lg leading-none text-slate-200 hover:bg-white/10" aria-label="Schliessen">
                        &times;
                    </button>
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2 text-xs text-slate-300">
                <label class="flex items-center gap-2">
                    <span class="text-slate-400">Tempo</span>
                    <input type="range" min="0.5" max="2" step="0.1" x-model.number="speechRate" class="h-1.5 w-24 accent-cyan-400">
                    <span class="w-8 text-right" x-text="Number(speechRate || 1).toFixed(1)"></span>
                </label>
                <span x-show="ttsError" x-cloak class="truncate text-rose-200" x-text="ttsError"></span>
            </div>
        </header>

        <div class="shrink-0 border-b border-slate-200 bg-white px-3 py-2">
            <div x-show="latestToolEvents().length === 0" class="flex items-center justify-between gap-2 text-xs text-slate-500">
                <span>Bereit fuer Workflow-Analyse, Imports und Testlaeufe.</span>
                <span x-show="busy()" x-cloak class="inline-flex items-center gap-1 font-semibold text-cyan-700">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-cyan-500"></span>
                    aktiv
                </span>
            </div>
            <div x-show="latestToolEvents().length > 0" x-cloak class="flex max-h-24 gap-2 overflow-x-auto pb-1">
                <template x-for="event in latestToolEvents()" :key="event.id">
                    <div class="flex min-w-[210px] items-start justify-between gap-2 rounded border bg-slate-50 px-2.5 py-2 text-xs shadow-sm" :class="event.status === 'success' ? 'border-emerald-200' : 'border-rose-200'">
                        <div class="min-w-0">
                            <div class="truncate font-black" :class="event.status === 'success' ? 'text-emerald-700' : 'text-rose-700'" x-text="event.tool || 'Tool'"></div>
                            <div class="mt-0.5 line-clamp-2 text-slate-600" x-text="event.message || ''"></div>
                            <div class="mt-1 text-[10px] text-slate-400" x-text="event.time || ''"></div>
                        </div>
                        <button type="button" x-on:click="callLivewire('dismissToolEvent', event.id)" class="shrink-0 rounded px-1 text-slate-400 hover:bg-white hover:text-slate-800" aria-label="Toolmeldung entfernen">&times;</button>
                    </div>
                </template>
            </div>
        </div>

        <div x-ref="messages" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4">
            @forelse($chatHistory as $index => $item)
                @php
                    $role = $item['role'] ?? 'assistant';
                    $tone = $item['tone'] ?? 'neutral';
                    $isUser = $role === 'user';
                    $bubbleClass = $isUser
                        ? 'ml-auto bg-slate-950 text-white shadow-slate-900/15'
                        : ($tone === 'error'
                            ? 'mr-auto border border-rose-200 bg-rose-50 text-rose-950'
                            : ($tone === 'success'
                                ? 'mr-auto border border-emerald-200 bg-emerald-50 text-emerald-950'
                                : 'mr-auto border border-slate-200 bg-white text-slate-800'));
                @endphp
                <div class="max-w-[90%] rounded-lg px-3 py-2 text-sm shadow-sm {{ $bubbleClass }}">
                    <div class="mb-1 flex items-center justify-between gap-3">
                        <span class="text-[10px] font-black uppercase tracking-[0.12em] {{ $isUser ? 'text-slate-300' : 'text-slate-400' }}">
                            {{ $isUser ? 'Du' : $assistantName }}
                        </span>
                        @if(! $isUser)
                            <button
                                type="button"
                                x-show="speechSupported"
                                x-cloak
                                x-on:click="speak(@js($item['content'] ?? ''), {{ $index }})"
                                class="rounded border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600 hover:border-cyan-300 hover:text-cyan-700"
                            >
                                Vorlesen
                            </button>
                        @endif
                    </div>
                    <div class="whitespace-pre-wrap break-words leading-relaxed">{!! nl2br(e($item['content'] ?? '')) !!}</div>
                    @if(($item['options'] ?? null) && is_array($item['options']))
                        <div class="mt-3 space-y-1.5">
                            @foreach($item['options'] as $optionIndex => $option)
                                <button
                                    type="button"
                                    wire:click="sendChatOption({{ $index }}, {{ $optionIndex }})"
                                    @disabled(($item['selected_option_index'] ?? null) !== null)
                                    class="block w-full rounded border border-slate-300 bg-white px-2 py-1.5 text-left text-xs font-semibold text-slate-700 hover:border-cyan-300 hover:bg-cyan-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {{ $option['label'] ?? 'Option' }}
                                    @if(! blank($option['description'] ?? null))
                                        <span class="block font-normal text-slate-500">{{ $option['description'] }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-1 text-[10px] {{ $isUser ? 'text-slate-300' : 'text-slate-400' }}">{{ $item['time'] ?? '' }}</div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-600">
                    Frage nach einem Workflow, dem letzten Run, Task-Imports, Listen-Imports oder starte einen Testlauf.
                </div>
            @endforelse

            <div
                x-show="busy() && pendingLabel"
                x-cloak
                class="ml-auto max-w-[90%] rounded-lg bg-slate-950 px-3 py-2 text-sm text-white shadow-sm"
            >
                <div class="mb-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-300">Du</div>
                <p class="whitespace-pre-line" x-text="pendingLabel"></p>
            </div>

            <div
                x-show="busy()"
                x-cloak
                class="mr-auto max-w-[94%] rounded-lg border border-cyan-200 bg-white px-3 py-3 text-sm text-slate-700 shadow-sm"
                role="status"
                aria-live="polite"
            >
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-cyan-600 text-white">
                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-black text-slate-900">Copilot arbeitet</p>
                            <span class="flex shrink-0 items-center gap-1">
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-cyan-500 [animation-delay:-.3s]"></span>
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500 [animation-delay:-.15s]"></span>
                                <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-500"></span>
                            </span>
                        </div>
                        <p wire:stream="assistant-status-stream" class="mt-1 text-xs text-slate-500">Kontext wird geprueft.</p>
                    </div>
                </div>
                <p wire:stream="assistant-response-stream" class="mt-3 whitespace-pre-line border-t border-cyan-100 pt-3 text-sm leading-6 text-slate-700 [&:empty]:hidden"></p>
            </div>
        </div>

        <div class="shrink-0 border-t border-slate-200 bg-white p-3">
            <div class="mb-2 rounded border border-slate-200 bg-slate-50 p-2">
                <div class="flex items-center gap-2">
                    <input type="file" wire:model="workflowImportFile" accept=".csv,.zip" class="block min-w-0 flex-1 text-xs text-slate-600 file:mr-2 file:rounded file:border-0 file:bg-slate-950 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-white">
                    <button type="button" wire:click="importWorkflowUpdate" wire:loading.attr="disabled" wire:target="workflowImportFile,importWorkflowUpdate" class="rounded bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-cyan-700 disabled:opacity-50">
                        Import
                    </button>
                </div>
                @error('workflowImportFile') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
            </div>

            <div class="mb-2 flex flex-wrap gap-1.5">
                <button type="button" x-on:click="quick('Analysiere bitte den letzten Workflow-Lauf und nenne Fehlerursache, betroffene Liste/Task und naechste Reparatur.')" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:border-cyan-300 hover:bg-cyan-50">Letzten Run</button>
                <button type="button" x-on:click="quick('Finde den Workflow DIBAG oeffnen, analysiere den letzten Lauf und schlage konkrete Korrekturen vor.')" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:border-cyan-300 hover:bg-cyan-50">DIBAG</button>
                <button type="button" x-on:click="quick('Zeige mir verfuegbare Variablen und aktuelle Werte fuer diesen Workflow.')" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:border-cyan-300 hover:bg-cyan-50">Variablen</button>
                <button type="button" x-on:click="quick('Hilf mir, einen neuen Workflow zu planen. Frage zuerst nach Ziel, Webseiten, benoetigten Listen, Tasks und eingebetteten Workflows.')" class="rounded border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:border-cyan-300 hover:bg-cyan-50">Neu</button>
            </div>

            <form x-on:submit.prevent="send()" class="flex items-end gap-2">
                <button
                    type="button"
                    x-on:click="toggleListening()"
                    x-bind:disabled="!voiceSupported || busy()"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded border text-sm font-black transition disabled:cursor-not-allowed disabled:opacity-40"
                    :class="listening ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-slate-300 bg-white text-slate-700 hover:border-cyan-300 hover:text-cyan-700'"
                    aria-label="Spracheingabe"
                >
                    Mic
                </button>
                <textarea
                    x-ref="composer"
                    x-model="draft"
                    rows="2"
                    placeholder="Workflow besprechen, Task einstellen, Import planen..."
                    class="min-h-[44px] flex-1 resize-none rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    x-on:input="resizeComposer()"
                    x-on:keydown.enter.meta.prevent="send()"
                    x-on:keydown.enter.ctrl.prevent="send()"
                ></textarea>
                <button type="submit" x-bind:disabled="busy() || !(draft || '').trim()" class="h-10 rounded-md bg-slate-950 px-4 text-sm font-semibold text-white shadow-sm hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Senden
                </button>
            </form>

            <div x-show="listening" x-cloak class="mt-2 flex items-center gap-2 text-xs font-semibold text-rose-700">
                <span class="h-2 w-2 animate-pulse rounded-full bg-rose-500"></span>
                Spracheingabe aktiv.
            </div>
        </div>
    </section>
</div>
