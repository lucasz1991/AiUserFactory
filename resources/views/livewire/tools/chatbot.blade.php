<div
    data-workflow-copilot-root
    x-data="{
        showChat: false,
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        submitting: false,
        pendingLabel: '',
        selectedChatOptions: {},
        showImportPanel: false,
        voiceSupported: false,
        listening: false,
        voiceCaptureActive: false,
        voiceCaptureTimer: null,
        recognition: null,
        mediaRecorder: null,
        mediaStream: null,
        voiceChunks: [],
        voiceUploading: false,
        ttsEndpoint: @js(\Illuminate\Support\Facades\Route::has('assistant.audio-output.stream') ? route('assistant.audio-output.stream', [], false) : null),
        sttEndpoint: @js(\Illuminate\Support\Facades\Route::has('assistant.audio-input.transcribe') ? route('assistant.audio-input.transcribe', [], false) : null),
        csrfToken: @js(csrf_token()),
        speechInputProvider: @js($assistantSpeechInputProvider),
        speechOutputProvider: @js($assistantSpeechOutputProvider),
        speechSupported: false,
        autoRead: @js($assistantAutoReadDefault),
        speechRate: @js($assistantSpeechRate),
        ttsAudio: null,
        ttsError: '',
        ttsQueue: [],
        ttsPlaying: false,
        ttsAbortController: null,
        ttsObjectUrls: [],
        ttsCurrentGeneration: 0,
        speaking: false,
        speakingIndex: null,
        lastAssistantMessageKey: null,
        toolAlertTimers: {},
        refreshTimer: null,
        workflowImprovements: [],
        improvementRefreshTimer: null,
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
            sessionStorage.removeItem('workflow-copilot-open');
            this.showChat = false;
            this.showImportPanel = false;
            this.clearVoiceCaptureState();
            this.autoRead = this.readBool('workflow-copilot-auto-read', this.autoRead);
            this.speechRate = this.readNumber('workflow-copilot-speech-rate', this.speechRate);
            this.voiceSupported = this.voiceProviderSupported();
            this.speechSupported = Boolean(this.ttsEndpoint && window.fetch && window.Audio && window.URL);
            this.lastAssistantMessageKey = this.latestAssistantMessageKey(this.chatHistory);
            this.workflowImprovements = this.latestWorkflowImprovements(this.chatHistory);
            this._reapplyImprovementHighlights = () => this.queueImprovementHighlights();
            document.addEventListener('livewire:updated', this._reapplyImprovementHighlights);
            document.addEventListener('livewire:navigated', this._reapplyImprovementHighlights);
            this.$watch('chatHistory', (history) => {
                this.handleNewAssistantMessage(history);
                this.syncWorkflowImprovementsFromHistory(history);
                this.scrollMessages();
            });
            this.$watch('isLoading', (loading) => {
                if (loading) {
                    this.stopSpeaking();
                }
            });
            this.$watch('autoRead', (enabled) => {
                localStorage.setItem('workflow-copilot-auto-read', enabled ? '1' : '0');
                if (!enabled) this.stopSpeaking();
            });
            this.$watch('speechRate', (rate) => localStorage.setItem('workflow-copilot-speech-rate', String(rate || 1)));
            this.$watch('toolEvents', (events) => this.scheduleToolAlerts(events));
            this.$watch('voiceSupported', (supported) => {
                if (!supported) this.clearVoiceCaptureState();
            });
            this.scheduleToolAlerts(this.toolEvents);
            this.$nextTick(() => {
                window.setTimeout(() => this.syncContext(), 0);
                this.scrollMessages(false);
                this.queueImprovementHighlights();
            });
        },
        destroy() {
            document.removeEventListener('livewire:updated', this._reapplyImprovementHighlights);
            document.removeEventListener('livewire:navigated', this._reapplyImprovementHighlights);
            window.clearTimeout(this.improvementRefreshTimer);
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
            if (this.showChat) {
                this.clearVoiceCaptureState();
                this.syncContext();
                this.scrollMessages(false);
                this.queueImprovementHighlights();
            } else {
                this.closeChat();
            }
        },
        closeChat() {
            this.showChat = false;
            this.showImportPanel = false;
            this.stopSpeaking();
            this.stopListening();
        },
        busy() {
            return this.submitting || this.isLoading || this.voiceUploading;
        },
        collectContext(extra = {}) {
            const path = window.location.pathname;
            const workflowMatch = path.match(/\/netzwerk\/workflows\/([^/?#]+)/);
            const selectedTask = document.querySelector('[data-workflow-task-node].assistant-highlight');
            const selectedList = document.querySelector('[data-workflow-step-column].assistant-highlight');

            return {
                route_name: null,
                path,
                page_title: document.title,
                workflow_id: workflowMatch && /^\\d+$/.test(workflowMatch[1]) ? workflowMatch[1] : null,
                workflow_slug: workflowMatch && !/^\\d+$/.test(workflowMatch[1]) ? workflowMatch[1] : null,
                highlighted_workflow_task: selectedTask?.dataset.workflowTaskNode || null,
                highlighted_workflow_list: selectedList?.dataset.workflowStepAction || null,
                ...extra,
            };
        },
        async syncContext(extra = {}) {
            await this.callLivewire('updatePageContext', this.collectContext(extra));
        },
        scrollMessages(smooth = true) {
            this.$nextTick(() => {
                const messages = this.$refs.messages;
                if (!messages) return;
                messages.scrollTo({ top: messages.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
            });
        },
        resizeComposer() {
            const composer = this.$refs.composer;
            if (!composer) return;
            composer.style.height = 'auto';
            composer.style.height = `${Math.min(composer.scrollHeight, 144)}px`;
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
            this.submitting = true;
            this.pendingLabel = prompt;
            this.draft = '';
            this.scrollMessages();

            try {
                await this.syncContext();
                await this.callLivewire('sendMessage', prompt);
            } finally {
                this.submitting = false;
                this.pendingLabel = '';
                this.resizeComposer();
                this.scrollMessages();
            }
        },
        selectedChatOptionIndex(messageIndex, selectedOptionIndex = null) {
            if (Object.prototype.hasOwnProperty.call(this.selectedChatOptions, messageIndex)) {
                return this.selectedChatOptions[messageIndex];
            }

            if (selectedOptionIndex === null || selectedOptionIndex === undefined) {
                return null;
            }

            const storedIndex = Number(selectedOptionIndex);

            return Number.isInteger(storedIndex) && storedIndex >= 0 ? storedIndex : null;
        },
        async chooseChatOption(messageIndex, selectedOptionIndex, option, optionIndex) {
            if (this.busy() || this.selectedChatOptionIndex(messageIndex, selectedOptionIndex) !== null) return;

            this.selectedChatOptions = {
                ...this.selectedChatOptions,
                [messageIndex]: optionIndex,
            };
            this.submitting = true;
            this.pendingLabel = option?.prompt || option?.label || 'Auswahl wird gesendet.';
            this.stopSpeaking();
            this.scrollMessages();

            try {
                await this.syncContext();
                this.draft = '';
                await this.callLivewire('sendChatOption', messageIndex, optionIndex);
            } finally {
                const remainingSelections = { ...this.selectedChatOptions };
                delete remainingSelections[messageIndex];
                this.selectedChatOptions = remainingSelections;
                this.submitting = false;
                this.pendingLabel = '';
                this.resizeComposer();
                this.scrollMessages();
            }
        },
        latestToolEvents() {
            return Array.isArray(this.toolEvents) ? [...this.toolEvents].reverse().slice(0, 4) : [];
        },
        scheduleToolAlerts(events) {
            (Array.isArray(events) ? events : []).forEach((event) => {
                const id = String(event?.id || '');
                if (!id || this.toolAlertTimers[id]) return;

                this.toolAlertTimers[id] = window.setTimeout(async () => {
                    delete this.toolAlertTimers[id];
                    await this.callLivewire('dismissToolEvent', id);
                }, 6500);
            });
        },
        dismissToolAlert(id) {
            const key = String(id || '');
            if (!key) return;

            if (this.toolAlertTimers[key]) {
                window.clearTimeout(this.toolAlertTimers[key]);
                delete this.toolAlertTimers[key];
            }

            this.callLivewire('dismissToolEvent', key);
        },
        latestAssistantMessageKey(history) {
            const messages = Array.isArray(history) ? history : [];
            const item = [...messages].reverse().find((message) => message && message.role === 'assistant');
            return item ? `${item.time || ''}|${item.content || ''}` : null;
        },
        latestWorkflowImprovements(history) {
            const messages = Array.isArray(history) ? history : [];
            const item = [...messages]
                .reverse()
                .find((message) => message && message.role === 'assistant' && Array.isArray(message.improvements));

            return item ? item.improvements : [];
        },
        syncWorkflowImprovementsFromHistory(history) {
            const improvements = this.latestWorkflowImprovements(history);
            const currentKey = JSON.stringify(this.workflowImprovements || []);
            const nextKey = JSON.stringify(improvements || []);

            if (currentKey !== nextKey) {
                this.setWorkflowImprovements(improvements);
            }
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
        speak(text, index = null) {
            if (!this.speechSupported || !text) return;

            this.stopSpeaking();
            this.ttsError = '';
            this.queueTtsSentence(String(text), index);
        },
        queueTtsSentence(text, index = null) {
            const cleanText = String(text || '').trim();
            if (!cleanText) return;

            this.ttsQueue.push({
                text: cleanText.slice(0, 4000),
                index,
                generation: this.ttsCurrentGeneration,
            });

            this.playNextTts();
        },
        async playNextTts() {
            if (this.ttsPlaying || !this.ttsQueue.length) return;

            const item = this.ttsQueue.shift();

            if (!item || item.generation !== this.ttsCurrentGeneration) {
                this.playNextTts();
                return;
            }

            this.ttsPlaying = true;
            this.speaking = false;
            this.speakingIndex = null;

            try {
                await this.playTtsViaBlob(item.text, item.index);
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.ttsError = this.ttsErrorMessage(error);
                    console.warn('Workflow Copilot Audioausgabe fehlgeschlagen:', error);
                }
            } finally {
                this.ttsPlaying = false;

                if (this.ttsQueue.length) {
                    this.playNextTts();
                } else {
                    this.speaking = false;
                    this.speakingIndex = null;
                }
            }
        },
        ttsFetchOptions(text) {
            this.ttsAbortController = new AbortController();

            return {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'audio, application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: this.ttsAbortController.signal,
                body: JSON.stringify({
                    text,
                    speed: Number(this.speechRate || 1),
                }),
            };
        },
        async playTtsViaBlob(text, index = null) {
            const response = await fetch(this.ttsEndpoint, this.ttsFetchOptions(text));

            if (!response.ok) {
                throw new Error(await this.ttsResponseError(response));
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            this.ttsObjectUrls.push(url);

            await this.playAudioUrl(url, index);
        },
        playAudioUrl(url, index = null) {
            return new Promise((resolve, reject) => {
                const audio = new Audio(url);
                this.ttsAudio = audio;

                const markNotSpeaking = () => {
                    this.speaking = false;
                    this.speakingIndex = null;
                };

                audio.onplaying = () => {
                    this.speaking = true;
                    this.speakingIndex = index;
                };
                audio.onwaiting = markNotSpeaking;
                audio.onpause = markNotSpeaking;
                audio.onended = () => {
                    markNotSpeaking();
                    resolve();
                };
                audio.onerror = () => {
                    markNotSpeaking();
                    reject(new Error('Audio konnte nicht abgespielt werden.'));
                };
                audio.play().catch((error) => {
                    markNotSpeaking();
                    reject(error);
                });
            });
        },
        async ttsResponseError(response) {
            const raw = await response.text();
            const responseConnectionId = response.headers.get('X-AI-Connection-ID');

            try {
                const payload = JSON.parse(raw);
                const message = payload?.detail || payload?.message || `HTTP ${response.status}`;
                const connectionId = payload?.connection_id || responseConnectionId;

                return connectionId ? `${message} (Verbindungs-ID: ${connectionId})` : message;
            } catch {
                const message = raw || `HTTP ${response.status}`;

                return responseConnectionId
                    ? `${message} (Verbindungs-ID: ${responseConnectionId})`
                    : message;
            }
        },
        ttsErrorMessage(error) {
            const message = String(error?.message || error || 'Unbekannter Audiofehler.');

            if (message.includes('Failed to fetch')) {
                return `Der Audio-Endpunkt ${this.ttsEndpoint || 'assistant.audio-output.stream'} ist nicht erreichbar.`;
            }

            if (error?.name === 'NotAllowedError') {
                return 'Der Browser hat die Audiowiedergabe blockiert. Bitte den Audio-Test erneut anklicken.';
            }

            return message.length > 420 ? `${message.slice(0, 420)}...` : message;
        },
        stopSpeaking() {
            this.ttsCurrentGeneration++;
            this.ttsQueue = [];

            if (this.ttsAbortController) {
                this.ttsAbortController.abort();
                this.ttsAbortController = null;
            }

            if (this.ttsAudio) {
                this.ttsAudio.pause();
                this.ttsAudio.src = '';
                this.ttsAudio = null;
            }

            this.ttsObjectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.ttsObjectUrls = [];
            this.ttsPlaying = false;
            this.speaking = false;
            this.speakingIndex = null;
        },
        voiceApi() {
            return window.SpeechRecognition || window.webkitSpeechRecognition || null;
        },
        voiceProviderSupported() {
            if (this.speechInputProvider === 'vosk') {
                return Boolean(
                    this.sttEndpoint
                    && window.fetch
                    && window.FormData
                    && window.MediaRecorder
                    && navigator.mediaDevices
                    && typeof navigator.mediaDevices.getUserMedia === 'function'
                );
            }

            return Boolean(this.voiceApi());
        },
        toggleVoice() {
            if (this.busy()) return;

            if (this.speechInputProvider === 'vosk') {
                this.toggleVoskVoice();
                return;
            }

            const SpeechRecognition = this.voiceApi();
            this.voiceSupported = this.voiceProviderSupported();

            if (!SpeechRecognition) {
                this.clearVoiceCaptureState();
                this.ttsError = '';
                return;
            }

            if (!this.recognition) {
                this.recognition = new SpeechRecognition();
                this.recognition.lang = 'de-DE';
                this.recognition.continuous = false;
                this.recognition.interimResults = true;
                let baseDraft = '';
                let finalTranscript = '';

                this.recognition.onstart = () => {
                    baseDraft = String(this.draft || '').trim();
                    finalTranscript = '';
                    this.listening = true;
                    this.voiceCaptureActive = true;
                    this.clearVoiceCaptureTimer();
                    this.voiceCaptureTimer = window.setTimeout(() => this.stopListening(), 45000);
                };
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
                    this.clearVoiceCaptureState();
                };
                this.recognition.onerror = (event) => {
                    this.clearVoiceCaptureState();
                    if (event?.error && !['no-speech', 'aborted'].includes(event.error)) {
                        this.ttsError = `Spracheingabe: ${event.error}`;
                    }
                };
            }

            if (this.listening) {
                this.stopListening();
                return;
            }

            this.ttsError = '';
            try {
                this.recognition.start();
            } catch (error) {
                this.clearVoiceCaptureState();
                this.ttsError = `Spracheingabe: ${error?.message || 'Start fehlgeschlagen.'}`;
            }
        },
        async toggleVoskVoice() {
            this.voiceSupported = this.voiceProviderSupported();

            if (!this.voiceSupported) {
                this.clearVoiceCaptureState();
                this.releaseVoskMediaStream();
                this.ttsError = this.sttEndpoint
                    ? 'Vosk-Spracheingabe benoetigt Mikrofonzugriff und MediaRecorder-Unterstuetzung.'
                    : 'Der Vosk Audio-Endpunkt assistant.audio-input.transcribe ist nicht erreichbar.';
                return;
            }

            if (this.voiceCaptureActive && this.mediaRecorder) {
                this.stopListening();
                return;
            }

            this.ttsError = '';
            this.voiceChunks = [];

            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const mimeType = this.supportedVoskMimeType();
                const options = mimeType ? { mimeType } : {};

                this.mediaStream = stream;
                this.mediaRecorder = new MediaRecorder(stream, options);

                this.mediaRecorder.ondataavailable = (event) => {
                    if (event?.data && event.data.size > 0) {
                        this.voiceChunks.push(event.data);
                    }
                };
                this.mediaRecorder.onstop = () => {
                    this.finishVoskCapture();
                };
                this.mediaRecorder.onerror = (event) => {
                    this.ttsError = `Spracheingabe: ${event?.error?.message || 'Aufnahme fehlgeschlagen.'}`;
                    this.clearVoiceCaptureState();
                    this.releaseVoskMediaStream();
                };

                this.mediaRecorder.start();
                this.listening = true;
                this.voiceCaptureActive = true;
                this.clearVoiceCaptureTimer();
                this.voiceCaptureTimer = window.setTimeout(() => this.stopListening(), 45000);
            } catch (error) {
                this.clearVoiceCaptureState();
                this.releaseVoskMediaStream();
                this.ttsError = `Spracheingabe: ${error?.message || 'Mikrofonstart fehlgeschlagen.'}`;
            }
        },
        supportedVoskMimeType() {
            const candidates = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/ogg',
                'audio/wav',
            ];

            return candidates.find((type) => window.MediaRecorder && MediaRecorder.isTypeSupported(type)) || '';
        },
        voskAudioExtension(mimeType) {
            const type = String(mimeType || '').toLowerCase();

            if (type.includes('ogg')) return 'ogg';
            if (type.includes('wav')) return 'wav';
            if (type.includes('mp4')) return 'mp4';

            return 'webm';
        },
        async finishVoskCapture() {
            const recorder = this.mediaRecorder;
            const chunks = this.voiceChunks;
            const mimeType = recorder?.mimeType || 'audio/webm';

            this.mediaRecorder = null;
            this.voiceChunks = [];
            this.clearVoiceCaptureState();
            this.releaseVoskMediaStream();

            if (!chunks.length) {
                return;
            }

            const blob = new Blob(chunks, { type: mimeType });
            await this.transcribeVoskBlob(blob);
        },
        async transcribeVoskBlob(blob) {
            if (!this.sttEndpoint) {
                this.ttsError = 'Der Vosk Audio-Endpunkt assistant.audio-input.transcribe ist nicht erreichbar.';
                return;
            }

            this.voiceUploading = true;
            this.ttsError = '';

            try {
                const formData = new FormData();
                const extension = this.voskAudioExtension(blob.type);

                formData.append('audio', blob, `workflow-copilot-audio.${extension}`);

                const response = await fetch(this.sttEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: formData,
                });

                if (!response.ok) {
                    throw new Error(await this.ttsResponseError(response));
                }

                const payload = await response.json();
                const transcript = String(payload?.text || '').trim();

                if (!transcript) {
                    throw new Error('Vosk hat keinen Text erkannt.');
                }

                const baseDraft = String(this.draft || '').trim();
                this.draft = [baseDraft, transcript].filter(Boolean).join(' ');
                this.resizeComposer();
            } catch (error) {
                this.ttsError = `Spracheingabe: ${this.ttsErrorMessage(error)}`;
            } finally {
                this.voiceUploading = false;
            }
        },
        releaseVoskMediaStream() {
            if (!this.mediaStream) return;

            this.mediaStream.getTracks().forEach((track) => track.stop());
            this.mediaStream = null;
        },
        clearVoiceCaptureTimer() {
            if (!this.voiceCaptureTimer) return;

            window.clearTimeout(this.voiceCaptureTimer);
            this.voiceCaptureTimer = null;
        },
        clearVoiceCaptureState() {
            this.clearVoiceCaptureTimer();
            this.listening = false;
            this.voiceCaptureActive = false;
            this.resizeComposer();
        },
        stopListening() {
            if (this.speechInputProvider === 'vosk') {
                const recorder = this.mediaRecorder;

                this.clearVoiceCaptureState();

                if (recorder && recorder.state !== 'inactive') {
                    try {
                        recorder.stop();
                    } catch (error) {
                        this.releaseVoskMediaStream();
                        this.mediaRecorder = null;
                        this.ttsError = `Spracheingabe: ${error?.message || 'Stop fehlgeschlagen.'}`;
                    }

                    return;
                }

                this.releaseVoskMediaStream();
                this.mediaRecorder = null;
                return;
            }

            const shouldStop = this.recognition && (this.listening || this.voiceCaptureActive);

            this.clearVoiceCaptureState();

            if (shouldStop) {
                try {
                    this.recognition.stop();
                } catch (error) {
                    this.ttsError = `Spracheingabe: ${error?.message || 'Stop fehlgeschlagen.'}`;
                }
            }
        },
        normalizeEventDetail(event) {
            return Array.isArray(event?.detail) ? (event.detail[0] || {}) : (event?.detail || {});
        },
        handleUiAction(event) {
            const detail = this.normalizeEventDetail(event);
            const action = detail.action || detail;

            if (action?.type === 'navigate' && action.url) {
                this.stopSpeaking();

                if (window.Livewire?.navigate) {
                    window.Livewire.navigate(action.url);
                } else {
                    window.location.assign(action.url);
                }

                return;
            }

            if (action?.type === 'highlight' || action?.type === 'highlight_workflow_element') {
                this.highlightElement(action);

                return;
            }

            if (action?.type === 'highlight_workflow_improvements') {
                const improvements = Array.isArray(action.improvements)
                    ? action.improvements.map((improvement) => ({
                        ...improvement,
                        workflow_id: improvement.workflow_id || action.workflow_id || null,
                        run_id: improvement.run_id || action.run_id || null,
                    }))
                    : [];

                this.setWorkflowImprovements(improvements);
            }
        },
        setWorkflowImprovements(improvements = []) {
            this.workflowImprovements = Array.isArray(improvements) ? improvements.slice(0, 8) : [];
            this.queueImprovementHighlights();
        },
        queueImprovementHighlights() {
            window.clearTimeout(this.improvementRefreshTimer);
            this.improvementRefreshTimer = window.setTimeout(() => {
                this.improvementRefreshTimer = null;
                this.applyImprovementHighlights();
            }, 80);
        },
        improvementTarget(improvement = {}) {
            if (!improvement.highlightable) return null;

            const stepId = String(improvement.step_id || '').trim();
            const stepAction = String(improvement.step_action_key || '').trim();
            const taskCardKey = String(improvement.task_card_key || '').trim();
            const selectors = [];

            if (stepAction && taskCardKey) {
                selectors.push(`[data-workflow-task-node='${this.cssEscape(`${stepAction}::${taskCardKey}`)}']`);
            }
            if (stepId && taskCardKey) {
                selectors.push(`[data-workflow-step-id='${this.cssEscape(stepId)}'] [data-workflow-task-key='${this.cssEscape(taskCardKey)}']`);
            }
            if (stepId && !taskCardKey) {
                selectors.push(`[data-workflow-step-id='${this.cssEscape(stepId)}']`);
            }
            if (stepAction && !taskCardKey) {
                selectors.push(`[data-workflow-step-column][data-workflow-step-action='${this.cssEscape(stepAction)}']`);
            }

            return selectors
                .map((selector) => {
                    try { return document.querySelector(selector); } catch { return null; }
                })
                .find(Boolean) || null;
        },
        applyImprovementHighlights() {
            const classes = [
                'assistant-improvement-error',
                'assistant-improvement-warning',
                'assistant-improvement-info',
            ];
            document.querySelectorAll(classes.map((name) => `.${name}`).join(','))
                .forEach((node) => {
                    node.classList.remove(...classes);
                    node.removeAttribute('data-assistant-improvement-severity');
                });

            const severityRanks = { info: 1, warning: 2, error: 3 };
            const targets = new Map();

            this.workflowImprovements.forEach((improvement) => {
                const target = this.improvementTarget(improvement);
                if (!target) return;

                const severity = ['error', 'warning', 'info'].includes(improvement.severity)
                    ? improvement.severity
                    : 'info';
                const current = targets.get(target);

                if (!current || severityRanks[severity] > severityRanks[current]) {
                    targets.set(target, severity);
                }
            });

            targets.forEach((severity, target) => {
                target.classList.add(`assistant-improvement-${severity}`);
                target.dataset.assistantImprovementSeverity = severity;
            });
        },
        openWorkflowImprovement(improvement = {}) {
            if (!improvement.highlightable) return;

            const target = this.improvementTarget(improvement);
            target?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
            this.closeChat();
            window.setTimeout(() => {
                window.dispatchEvent(new CustomEvent('assistant-open-workflow-improvement', {
                    detail: improvement,
                }));
            }, 80);
        },
        cssEscape(value) {
            const text = String(value ?? '');

            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(text);
            }

            return Array.from(text)
                .map((character) => /[a-zA-Z0-9_-]/.test(character)
                    ? character
                    : `\\${character.codePointAt(0).toString(16)} `)
                .join('');
        },
        highlightElement(action = {}) {
            const selectors = [];
            const type = String(action.target_type || action.element_type || action.target || '').trim();
            const key = String(action.key || action.element_key || '').trim();
            const workflowId = String(action.workflow_id || '').trim();
            const stepId = String(action.step_id || '').trim();
            const stepAction = String(action.step_action_key || action.step_action || '').trim();
            const taskCardKey = String(action.task_card_key || action.task_key || '').trim();

            if (action.selector) selectors.push(String(action.selector));
            if (type && key) selectors.push(`[data-assistant-highlight='${this.cssEscape(type)}:${this.cssEscape(key)}']`);
            if (key) selectors.push(`[data-assistant-highlight-key='${this.cssEscape(key)}']`);
            if (workflowId) selectors.push(`[data-workflow-row-id='${this.cssEscape(workflowId)}']`);
            if (stepId) selectors.push(`[data-workflow-step-id='${this.cssEscape(stepId)}']`);
            if (stepAction) selectors.push(`[data-workflow-step-action='${this.cssEscape(stepAction)}']`);
            if (stepAction && taskCardKey) selectors.push(`[data-workflow-task-node='${this.cssEscape(`${stepAction}::${taskCardKey}`)}']`);
            if (taskCardKey) selectors.push(`[data-workflow-task-key='${this.cssEscape(taskCardKey)}']`);
            if (taskCardKey) selectors.push(`[data-workflow-task-catalog-key='${this.cssEscape(taskCardKey)}']`);

            const target = selectors
                .map((selector) => {
                    try { return document.querySelector(selector); } catch { return null; }
                })
                .find(Boolean);

            if (!target) {
                this.ttsError = 'Das besprochene Workflow-Element ist auf dieser Ansicht gerade nicht sichtbar.';
                return;
            }

            document.querySelectorAll('.assistant-highlight').forEach((node) => node.classList.remove('assistant-highlight'));
            target.classList.add('assistant-highlight');
            target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
            window.setTimeout(() => target.classList.remove('assistant-highlight'), 5500);
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
            }, 180);
        },
    }"
    x-on:assistant-ui-action.window="handleUiAction($event)"
    x-on:assistant-workflow-page-refresh.window="refreshWorkflowPage()"
    x-on:assistant-reapply-workflow-improvements.window="queueImprovementHighlights()"
    x-on:keydown.escape.window="if (showChat) closeChat()"
    class="workflow-copilot"
>
    <style>
        [x-cloak] { display: none !important; }
        .assistant-highlight {
            position: relative;
            z-index: 45;
            outline: 3px solid rgb(34 211 238);
            outline-offset: 4px;
            box-shadow: 0 0 0 8px rgba(34, 211, 238, .18), 0 18px 40px -20px rgba(15, 23, 42, .45);
            transition: outline-color .2s ease, box-shadow .2s ease;
        }
        .assistant-improvement-error,
        .assistant-improvement-warning,
        .assistant-improvement-info {
            position: relative;
            z-index: 44;
            outline: 3px solid transparent;
            outline-offset: 4px;
            transition: outline-color .2s ease, box-shadow .2s ease;
        }
        .assistant-improvement-error {
            outline-color: rgb(244 63 94);
            box-shadow: 0 0 0 7px rgba(244, 63, 94, .16), 0 18px 38px -22px rgba(159, 18, 57, .55);
        }
        .assistant-improvement-warning {
            outline-color: rgb(245 158 11);
            box-shadow: 0 0 0 7px rgba(245, 158, 11, .16), 0 18px 38px -22px rgba(146, 64, 14, .5);
        }
        .assistant-improvement-info {
            outline-color: rgb(14 165 233);
            box-shadow: 0 0 0 7px rgba(14, 165, 233, .15), 0 18px 38px -22px rgba(3, 105, 161, .5);
        }
    </style>

    @if($assistantEnabled)
        <button
            type="button"
            x-show="!showChat"
            x-cloak
            x-on:click.stop.prevent="setOpen(true)"
            class="fixed bottom-5 right-5 z-[80] flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-950 via-cyan-800 to-emerald-700 text-sm font-black text-white shadow-2xl shadow-cyan-950/25 ring-1 ring-white/30 transition hover:-translate-y-0.5 hover:shadow-cyan-700/30"
            aria-label="AI Workflow Copilot oeffnen"
            title="AI Workflow Copilot oeffnen"
        >
            AI
            <span class="absolute -right-0.5 -top-0.5 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-400"></span>
        </button>

        <template x-if="showChat">
            <div>
                <div
                    x-transition.opacity
                    x-on:click.stop.prevent="closeChat()"
                    class="fixed inset-0 z-[70] bg-slate-950/45 backdrop-blur-[2px]"
                    aria-hidden="true"
                ></div>

                <aside
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="translate-y-3 scale-95 opacity-0"
                    x-transition:enter-end="translate-y-0 scale-100 opacity-100"
                    class="fixed bottom-4 right-4 z-[90] flex h-[min(760px,calc(100vh-2rem))] w-[min(480px,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-2xl border border-white/80 bg-white shadow-[0_30px_90px_-22px_rgba(15,23,42,.55)] ring-1 ring-slate-900/5"
                    role="dialog"
                    aria-modal="true"
                    aria-label="AI Workflow Copilot"
                    x-on:click.stop
                >
            <header class="relative shrink-0 overflow-visible border-b border-cyan-300/30 bg-gradient-to-r from-slate-950 via-cyan-900 to-emerald-800 px-4 py-3 text-white">
                <div class="relative flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-black">{{ $assistantName }}</div>
                        <div class="mt-0.5 truncate text-[11px] font-semibold uppercase tracking-[0.12em] text-cyan-100">Workflow-Seite aktiv</div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        <x-ui.dropdown.anchor-dropdown
                            align="right"
                            width="auto"
                            :offset="8"
                            dropdown-classes=""
                            content-classes="w-72 rounded-xl border border-slate-200 bg-white text-slate-900"
                        >
                            <x-slot name="trigger">
                                <button
                                    type="button"
                                    x-bind:aria-expanded="open"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
                                    title="Sprach-Einstellungen"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2"/>
                                        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.1.38.31.73.6 1 .3.27.68.41 1.09.4H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="space-y-4 p-4 text-left">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[.14em] text-slate-500">Sprachausgabe</p>
                                        <p
                                            class="mt-1 text-xs leading-5 text-slate-500"
                                            x-text="speechOutputProvider === 'espeak_ng'
                                                ? 'eSpeak NG-Audiostream mit gespeicherter Service-URL.'
                                                : 'AI-TTS-Audiostream mit gespeicherten OpenRouter-Audioeinstellungen.'"
                                        ></p>
                                    </div>

                                    <div class="flex items-center justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-bold text-slate-800">Audio f&uuml;r neue Antworten</p>
                                            <p
                                                class="text-[11px] text-slate-500"
                                                x-text="autoRead ? 'F&uuml;r kommende Antworten aktiviert' : 'Deaktiviert'"
                                            ></p>
                                        </div>
                                        <x-ui.forms.toggle-button id="workflow-assistant-auto-read" alpine-model="autoRead" />
                                    </div>

                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            x-on:click="speak(speechOutputProvider === 'espeak_ng' ? 'Die eSpeak NG Audioausgabe ist einsatzbereit.' : 'Die AI Audioausgabe ist einsatzbereit.')"
                                            x-bind:disabled="!speechSupported"
                                            class="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                        >
                                            Audio testen
                                        </button>
                                        <template x-if="speaking">
                                            <button
                                                type="button"
                                                x-on:click="stopSpeaking()"
                                                class="inline-flex items-center justify-center rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700"
                                            >
                                                Stop
                                            </button>
                                        </template>
                                    </div>

                                    <template x-if="speaking">
                                        <div
                                            class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800"
                                            role="status"
                                        >
                                            Wird gerade vorgelesen.
                                        </div>
                                    </template>

                                    <div
                                        x-show="ttsError"
                                        x-cloak
                                        class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800"
                                        x-text="ttsError"
                                    ></div>
                                </div>
                            </x-slot>
                        </x-ui.dropdown.anchor-dropdown>

                        <button
                            type="button"
                            wire:click="clearChat"
                            x-on:click="stopSpeaking(); stopListening(); selectedChatOptions = {}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
                            title="Chat leeren"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click.stop.prevent="closeChat()"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/25 bg-white/10 text-white transition hover:bg-white/20"
                            title="Schliessen"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div x-show="busy()" x-cloak class="absolute inset-x-0 bottom-0 h-0.5 overflow-hidden bg-white/20">
                    <span class="block h-full w-1/2 animate-[pulse_1s_ease-in-out_infinite] rounded-full bg-white shadow-[0_0_12px_rgba(255,255,255,.9)]"></span>
                </div>
            </header>

            <section class="relative flex min-h-0 flex-1 flex-col">
                <div class="pointer-events-none absolute inset-x-3 top-3 z-30 space-y-2">
                    <template x-for="event in latestToolEvents()" :key="event.id">
                        <div
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="-translate-y-2 opacity-0"
                            x-transition:enter-end="translate-y-0 opacity-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="translate-y-0 opacity-100"
                            x-transition:leave-end="-translate-y-2 opacity-0"
                            class="pointer-events-auto rounded-xl border px-3 py-3 shadow-lg backdrop-blur"
                            :class="event.status === 'success'
                                ? 'border-emerald-200 bg-emerald-50/95 text-emerald-950'
                                : 'border-rose-200 bg-rose-50/95 text-rose-950'"
                            role="status"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-black text-white"
                                    :class="event.status === 'success' ? 'bg-emerald-600' : 'bg-rose-600'"
                                    x-text="event.status === 'success' ? 'OK' : '!'"
                                ></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <strong class="truncate text-xs" x-text="event.tool || 'Tool'"></strong>
                                        <span class="shrink-0 text-[10px] opacity-60" x-text="event.time || ''"></span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 opacity-80" x-text="event.message || ''"></p>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="dismissToolAlert(event.id)"
                                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-current opacity-50 transition hover:bg-black/5 hover:opacity-100"
                                    aria-label="Meldung schliessen"
                                >
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <div
                    x-ref="messages"
                    class="scroll-container min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4"
                >
                    @forelse($chatHistory as $index => $item)
                        @php
                            $role = $item['role'] ?? 'assistant';
                            $tone = $item['tone'] ?? 'neutral';
                            $isUser = $role === 'user';
                            $bubbleClass = $isUser
                                ? 'ml-auto border-slate-300 bg-white text-slate-900'
                                : ($tone === 'error'
                                    ? 'mr-auto border-rose-200 bg-rose-50 text-rose-950'
                                    : ($tone === 'success'
                                        ? 'mr-auto border-emerald-200 bg-emerald-50 text-emerald-950'
                                        : 'mr-auto border-cyan-200 bg-white text-slate-800'));
                        @endphp
                        <div class="max-w-[92%] rounded-xl border px-4 py-3 text-sm leading-6 shadow-sm {{ $bubbleClass }}">
                            <div class="mb-1 flex items-center justify-between gap-3">
                                <strong class="text-xs uppercase tracking-[.14em] {{ $isUser ? 'text-slate-500' : 'text-cyan-700' }}">
                                    {{ $isUser ? 'Du' : $assistantName }}
                                </strong>
                                <div class="flex items-center gap-2">
                                    @if(! $isUser)
                                        <button
                                            type="button"
                                            x-show="speechSupported"
                                            x-cloak
                                            x-on:click="speaking && speakingIndex === {{ $index }} ? stopSpeaking() : speak(@js($item['content'] ?? ''), {{ $index }})"
                                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-cyan-700"
                                            x-bind:title="speaking && speakingIndex === {{ $index }} ? 'Vorlesen stoppen' : 'Antwort vorlesen'"
                                        >
                                            <svg x-show="!(speaking && speakingIndex === {{ $index }})" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M5 9v6h4l5 4V5L9 9H5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                                <path d="M17 9a4 4 0 0 1 0 6M19.5 6.5a7.5 7.5 0 0 1 0 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                            </svg>
                                            <svg x-show="speaking && speakingIndex === {{ $index }}" class="h-3.5 w-3.5 text-rose-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <rect x="6" y="6" width="12" height="12" rx="2"/>
                                            </svg>
                                        </button>
                                    @endif
                                    <span class="text-[11px] text-slate-400">{{ $item['time'] ?? '' }}</span>
                                </div>
                            </div>
                            <div class="whitespace-pre-line break-words">{!! nl2br(e($item['content'] ?? '')) !!}</div>

                            @if(is_array($item['improvements'] ?? null) && $item['improvements'] !== [])
                                <div class="mt-3 border-t border-slate-200 pt-1">
                                    @foreach($item['improvements'] as $improvement)
                                        @php
                                            $severity = in_array($improvement['severity'] ?? null, ['error', 'warning', 'info'], true)
                                                ? $improvement['severity']
                                                : 'info';
                                            $severityLabel = match ($severity) {
                                                'error' => 'Fehler',
                                                'warning' => 'Optimierung',
                                                default => 'Hinweis',
                                            };
                                            $severityClasses = match ($severity) {
                                                'error' => 'text-rose-700 before:bg-rose-500',
                                                'warning' => 'text-amber-700 before:bg-amber-500',
                                                default => 'text-sky-700 before:bg-sky-500',
                                            };
                                            $highlightable = (bool) ($improvement['highlightable'] ?? false);
                                        @endphp
                                        <button
                                            type="button"
                                            x-on:click="openWorkflowImprovement(@js($improvement))"
                                            @disabled(! $highlightable)
                                            class="group block w-full border-b border-slate-100 py-2.5 text-left last:border-b-0 {{ $highlightable ? 'cursor-pointer hover:bg-slate-50' : 'cursor-default' }}"
                                        >
                                            <span class="block px-1">
                                                <span class="flex items-center justify-between gap-3">
                                                    <span class="min-w-0 truncate text-xs font-black text-slate-900">{{ $improvement['title'] ?? 'Verbesserung' }}</span>
                                                    <span class="relative shrink-0 pl-3 text-[10px] font-bold uppercase before:absolute before:left-0 before:top-1/2 before:h-1.5 before:w-1.5 before:-translate-y-1/2 before:rounded-full {{ $severityClasses }}">
                                                        {{ $severityLabel }}
                                                    </span>
                                                </span>
                                                @if(! blank($improvement['explanation'] ?? null))
                                                    <span class="mt-1 block text-[11px] leading-4 text-slate-600">{{ $improvement['explanation'] }}</span>
                                                @endif
                                                @if(! blank($improvement['recommendation'] ?? null))
                                                    <span class="mt-1 block text-[11px] font-semibold leading-4 text-slate-800">Empfehlung: {{ $improvement['recommendation'] }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            @if(($item['options'] ?? null) && is_array($item['options']))
                                <div class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                                    <div class="grid gap-2">
                                        @foreach($item['options'] as $optionIndex => $option)
                                            <button
                                                type="button"
                                                x-show="selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) === null || selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) === {{ $optionIndex }}"
                                                x-on:click="chooseChatOption({{ $index }}, @js($item['selected_option_index'] ?? null), @js($option), {{ $optionIndex }})"
                                                x-bind:disabled="busy() || selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) !== null"
                                                x-bind:class="selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) === {{ $optionIndex }}
                                                    ? 'cursor-default border-emerald-500 bg-emerald-100 shadow-sm'
                                                    : 'border-cyan-200 bg-gradient-to-r from-cyan-50 to-emerald-50/70 hover:-translate-y-0.5 hover:border-cyan-400 hover:shadow-sm disabled:cursor-wait disabled:opacity-50'"
                                                class="group flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition"
                                            >
                                                <span
                                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm ring-1 transition"
                                                    x-bind:class="selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) === {{ $optionIndex }}
                                                        ? 'text-emerald-700 ring-emerald-200'
                                                        : 'text-cyan-700 ring-cyan-100 group-hover:bg-cyan-600 group-hover:text-white'"
                                                >
                                                    <svg x-show="selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) !== {{ $optionIndex }}" class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                    <svg x-show="selectedChatOptionIndex({{ $index }}, @js($item['selected_option_index'] ?? null)) === {{ $optionIndex }}" class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="m5 12 4 4L19 6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-xs font-black text-slate-900">{{ $option['label'] ?? 'Option' }}</span>
                                                    @if(! blank($option['description'] ?? null))
                                                        <span class="mt-0.5 block text-[11px] leading-4 text-slate-500">{{ $option['description'] }}</span>
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="space-y-3.5">
                            <div class="relative overflow-hidden rounded-2xl border border-cyan-100 bg-white/90 p-4 shadow-sm backdrop-blur">
                                <div class="relative flex items-start gap-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-emerald-600 text-white shadow-lg shadow-cyan-200/60">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M12 3 4 7v6c0 4.5 3.4 7.4 8 8 4.6-.6 8-3.5 8-8V7l-8-4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                            <path d="M8.5 12h7M12 8.5v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-black text-slate-950">Womit soll ich starten?</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Ich nutze die aktuelle Workflow-Seite, sichtbare Listen, Tasks, Runs und Imports als Kontext.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2.5">
                                <button type="button" x-on:click="quick('Analysiere bitte den letzten Workflow-Lauf und nenne Fehlerursache, betroffene Liste/Task und naechste Reparatur.')" class="group rounded-2xl border border-sky-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-50 hover:shadow-md">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-100 text-sky-700 transition group-hover:bg-sky-600 group-hover:text-white">
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17 9 12l3 3 7-8M15 7h4v4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="mt-3 block text-xs font-black text-slate-900">Letzten Run</span>
                                    <span class="mt-1 block text-[10px] leading-4 text-slate-500">Fehlerursache und nächste Reparatur finden.</span>
                                </button>

                                <button type="button" x-on:click="quick('Zeige mir verfuegbare Variablen und aktuelle Werte fuer diesen Workflow.')" class="group rounded-2xl border border-emerald-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 transition group-hover:bg-emerald-600 group-hover:text-white">
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    </span>
                                    <span class="mt-3 block text-xs font-black text-slate-900">Variablen</span>
                                    <span class="mt-1 block text-[10px] leading-4 text-slate-500">Kontextwerte und Step-Rückgaben sehen.</span>
                                </button>

                                <button type="button" x-on:click="quick('Hilf mir, einen neuen Workflow zu planen. Frage zuerst nach Ziel, Webseiten, benoetigten Listen, Tasks und eingebetteten Workflows.')" class="group rounded-2xl border border-violet-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-violet-300 hover:bg-violet-50 hover:shadow-md">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-100 text-violet-700 transition group-hover:bg-violet-600 group-hover:text-white">
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    </span>
                                    <span class="mt-3 block text-xs font-black text-slate-900">Neu planen</span>
                                    <span class="mt-1 block text-[10px] leading-4 text-slate-500">Workflow, Listen und Tasks strukturieren.</span>
                                </button>

                                <button type="button" x-on:click="quick('Welche Assistant-Tools stehen dir fuer Workflow-Analyse, Imports, Testlauf, Navigation und Highlighting zur Verfuegung?')" class="group rounded-2xl border border-amber-200/80 bg-white/90 p-3.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:bg-amber-50 hover:shadow-md">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-700 transition group-hover:bg-amber-500 group-hover:text-white">
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3 14.2 8.8 20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                                    </span>
                                    <span class="mt-3 block text-xs font-black text-slate-900">Tools</span>
                                    <span class="mt-1 block text-[10px] leading-4 text-slate-500">Funktionen und Möglichkeiten anzeigen.</span>
                                </button>
                            </div>
                        </div>
                    @endforelse

                    <div
                        x-show="busy() && pendingLabel"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="translate-y-2 opacity-0"
                        x-transition:enter-end="translate-y-0 opacity-100"
                        class="ml-auto max-w-[88%] rounded-2xl rounded-br-md bg-gradient-to-br from-slate-950 to-cyan-800 px-4 py-3 text-sm leading-6 text-white shadow-md shadow-cyan-200/60"
                    >
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <strong class="text-[10px] uppercase tracking-[.14em] text-cyan-100">Du</strong>
                            <span class="inline-flex items-center gap-1.5 text-[10px] text-cyan-100">
                                Wird gesendet
                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>
                            </span>
                        </div>
                        <p class="whitespace-pre-line" x-text="pendingLabel"></p>
                    </div>

                    <div
                        x-show="busy()"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="translate-y-2 opacity-0"
                        x-transition:enter-end="translate-y-0 opacity-100"
                        class="max-w-[94%] overflow-hidden rounded-2xl border border-cyan-200/80 bg-white/95 px-4 py-3.5 text-sm text-slate-600 shadow-lg shadow-cyan-100/50 backdrop-blur"
                        role="status"
                        aria-live="polite"
                    >
                        <div class="flex items-center gap-3">
                            <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-600 to-emerald-600 text-white shadow-md shadow-cyan-200">
                                <span class="absolute -inset-1 animate-ping rounded-2xl bg-cyan-300/25"></span>
                                <svg class="relative h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                                </svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="font-black text-slate-900">Copilot arbeitet</p>
                                    <span class="flex shrink-0 items-center gap-1" aria-hidden="true">
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-sky-500 [animation-delay:-.3s]"></span>
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-cyan-500 [animation-delay:-.15s]"></span>
                                        <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500"></span>
                                    </span>
                                </div>
                                <p wire:stream="assistant-status-stream" class="mt-1 text-xs leading-5 text-slate-500">Kontext wird geprueft und passende Werkzeuge werden vorbereitet.</p>
                            </div>
                        </div>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full w-full origin-left animate-pulse rounded-full bg-gradient-to-r from-sky-500 via-cyan-400 to-emerald-500"></div>
                        </div>
                        <p wire:stream="assistant-response-stream" class="mt-3 whitespace-pre-line border-t border-cyan-100 pt-3 text-sm leading-6 text-slate-700 [&:empty]:hidden"></p>
                    </div>
                </div>

                <footer class="shrink-0 border-t border-slate-200 bg-white p-3">
                    <form x-on:submit.prevent="send()" class="overflow-hidden rounded-2xl border bg-white shadow-sm transition focus-within:border-cyan-400 focus-within:ring-4 focus-within:ring-cyan-100" :class="voiceSupported && (voiceCaptureActive || voiceUploading) ? 'border-rose-300 ring-4 ring-rose-100' : 'border-slate-300'">
                        <div
                            x-show="showImportPanel"
                            x-cloak
                            x-collapse.duration.180ms
                            class="border-b border-slate-100 bg-slate-50/90 px-3 py-2"
                        >
                            <div class="flex items-center gap-2">
                                <input type="file" wire:model="workflowImportFile" accept=".csv,.zip" class="block min-w-0 flex-1 text-xs text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-cyan-700">
                                <button type="button" wire:click="importWorkflowUpdate" wire:loading.attr="disabled" wire:target="workflowImportFile,importWorkflowUpdate" class="inline-flex h-8 shrink-0 items-center rounded-lg bg-slate-950 px-3 text-xs font-semibold text-white shadow-sm hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50">
                                    Import
                                </button>
                            </div>
                            @error('workflowImportFile') <div class="mt-1 text-xs text-rose-600">{{ $message }}</div> @enderror
                        </div>
                        <template x-if="voiceSupported && (voiceCaptureActive || voiceUploading)">
                            <div class="flex items-center gap-2 border-b border-rose-100 bg-rose-50 px-3 py-1.5 text-[11px] font-bold text-rose-700">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-rose-500"></span>
                                </span>
                                <span x-text="voiceUploading ? 'Sprache wird erkannt' : 'Hoere zu'"></span>
                            </div>
                        </template>

                        <textarea
                            x-ref="composer"
                            x-model="draft"
                            x-init="$nextTick(() => resizeComposer())"
                            rows="1"
                            placeholder="Workflow besprechen, Task einstellen, Import planen..."
                            class="block min-h-[58px] max-h-36 w-full resize-none border-0 bg-transparent px-4 pb-2 pt-3 text-sm leading-6 text-slate-950 placeholder:text-slate-400 focus:ring-0 disabled:bg-slate-50 disabled:text-slate-400"
                            x-bind:disabled="busy()"
                            x-on:input="resizeComposer()"
                            x-on:keydown.enter.exact.prevent="send()"
                            x-on:keydown.enter.meta.prevent="send()"
                            x-on:keydown.enter.ctrl.prevent="send()"
                        ></textarea>

                        <div class="flex items-center justify-between gap-3 px-2 pb-2">
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    x-on:click="showImportPanel = !showImportPanel"
                                    x-bind:aria-expanded="showImportPanel"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-cyan-700"
                                    :class="showImportPanel ? 'bg-cyan-50 text-cyan-700' : ''"
                                    title="Workflow-Import anhaengen"
                                >
                                    <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="m21.4 11.6-8.7 8.7a6 6 0 0 1-8.5-8.5l9.7-9.7a4 4 0 1 1 5.7 5.7l-9.8 9.8a2 2 0 0 1-2.8-2.8l8.8-8.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                                <button
                                    type="button"
                                    x-on:click="toggleVoice()"
                                    x-bind:disabled="busy() || !voiceSupported"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-xl transition disabled:cursor-not-allowed disabled:opacity-30"
                                    :class="voiceSupported && (voiceCaptureActive || voiceUploading) ? 'bg-rose-100 text-rose-700' : (voiceSupported ? 'text-slate-400 hover:bg-slate-100 hover:text-cyan-700' : 'text-slate-300')"
                                    :title="voiceSupported ? (speechInputProvider === 'vosk' ? 'Vosk Spracheingabe' : 'Spracheingabe') : (speechInputProvider === 'vosk' ? 'Vosk Spracheingabe ist nicht verfuegbar' : 'Spracheingabe wird von diesem Browser nicht unterstuetzt')"
                                >
                                    <svg x-show="voiceSupported" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 14a3 3 0 0 0 3-3V7a3 3 0 0 0-6 0v4a3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                                        <path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <svg x-show="!voiceSupported" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M12 14a3 3 0 0 0 3-3V8M9.4 5.4A3 3 0 0 1 15 7v4M5 11a7 7 0 0 0 11.7 5.2M12 18v3M8 21h8M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                </button>
                                <template x-if="speaking">
                                    <button
                                        type="button"
                                        x-on:click="stopSpeaking()"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-700 transition hover:bg-rose-100"
                                        title="Vorlesen stoppen"
                                    >
                                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <rect x="6" y="6" width="12" height="12" rx="2"/>
                                        </svg>
                                    </button>
                                </template>
                            </div>

                            <span class="min-w-0 flex-1"></span>

                            <button
                                type="submit"
                                x-bind:disabled="busy() || !(draft || '').trim()"
                                class="inline-flex h-9 shrink-0 items-center justify-center rounded-xl bg-slate-950 px-3.5 text-sm font-semibold text-white shadow-sm hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Senden
                            </button>
                        </div>
                    </form>
                </footer>
            </section>
                </aside>
            </div>
        </template>
    @endif
</div>
