const ACTIVE_STATES = new Set(['recording', 'paused']);
const KEY_COMMANDS = new Set(['Enter', 'Tab']);

function text(value) {
    return String(value ?? '').trim();
}

export function recordingErrorMessage(value) {
    const message = text(value);
    return ({
        browser_unavailable: 'Der Aufnahmebrowser ist nicht erreichbar. Bitte die Aufnahme erneut starten.',
        session_stopped: 'Die Aufnahme ist beendet. Bitte eine neue Aufnahme starten.',
        event_limit: 'Die maximale Anzahl an Aktionen ist erreicht. Aufnahme beenden und speichern.',
    })[message] || message;
}

/** Map only the visible image content, never the object-fit letterbox. */
export function mapRecordingCoordinates(point, rect, viewport) {
    const width = Number(viewport?.width);
    const height = Number(viewport?.height);
    if (!width || !height || !rect?.width || !rect?.height) return null;

    const scale = Math.min(rect.width / width, rect.height / height);
    const left = rect.left + (rect.width - width * scale) / 2;
    const top = rect.top + (rect.height - height * scale) / 2;
    const x = (Number(point.clientX) - left) / scale;
    const y = (Number(point.clientY) - top) / scale;
    if (!Number.isFinite(x) || !Number.isFinite(y) || x < 0 || y < 0 || x >= width || y >= height) return null;

    return { x: Math.round(Math.min(width - 1, x)), y: Math.round(Math.min(height - 1, y)) };
}

export function normalizeRecordingValueOptions(groups = {}) {
    return Object.entries(groups).map(([key, group]) => ({
        key,
        label: text(group?.label) || key,
        options: Object.entries(group?.options || {}).map(([value, label]) => ({ value, label: text(label) })),
    })).filter((group) => group.options.length);
}

/** Passwords may have a transient test sample, but never a literal binding. */
export function buildRecordingFillCommand(selected, draft, allowedValues = []) {
    if (!selected?.editable || !text(selected.selector)) throw new Error('Bitte zuerst ein Eingabefeld auswählen.');
    const source = text(draft.source);
    if (!['fixed', 'workflow_variable', 'literal'].includes(source)) throw new Error('Bitte eine gültige Wertquelle auswählen.');
    if (selected.sensitive && source === 'literal') throw new Error('Sensible Eingaben benötigen eine Datenquelle oder Workflow-Variable.');

    const command = { type: 'fill', selector: text(selected.selector), source };
    if (source === 'fixed') {
        if (!allowedValues.includes(text(draft.value))) throw new Error('Bitte einen Datenwert auswählen.');
        command.value = text(draft.value);
    } else if (source === 'workflow_variable') {
        if (text(draft.variable).length > 160 || !/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/.test(text(draft.variable))) throw new Error('Bitte einen gültigen Variablennamen eingeben.');
        command.workflow_variable = text(draft.variable);
    } else {
        command.value = String(draft.value ?? '').slice(0, 4000);
    }
    if (source !== 'literal' && String(draft.previewValue ?? '').length) {
        command.previewValue = String(draft.previewValue).slice(0, 4000);
    }
    return command;
}

/** One in-flight request. Pointer bursts replace only the last pending move. */
export function createRecordingCommandQueue(send, maxPending = 24) {
    const pending = [];
    let running = false;
    let closed = false;
    let completion = Promise.resolve();

    const pump = async () => {
        if (running || closed) return;
        running = true;
        while (pending.length && !closed) {
            const item = pending.shift();
            try {
                item.resolve(await send(item.command));
            } catch (error) {
                item.reject(error);
                // Never replay queued clicks after a failed or uncertain action.
                for (const waiting of pending.splice(0)) waiting.reject(error);
                break;
            }
        }
        running = false;
    };

    return {
        enqueue(command) {
            if (closed) return Promise.reject(new Error('Die Browsersteuerung wurde geschlossen.'));
            return new Promise((resolve, reject) => {
                const last = pending.at(-1);
                if (command.type === 'move' && last?.command.type === 'move') {
                    last.resolve({ coalesced: true });
                    pending[pending.length - 1] = { command, resolve, reject };
                } else if (pending.length >= maxPending) {
                    reject(new Error('Die Browsersteuerung ist beschäftigt. Bitte kurz warten.'));
                } else {
                    pending.push({ command, resolve, reject });
                }
                if (!running) completion = pump();
            });
        },
        close() {
            closed = true;
            for (const item of pending.splice(0)) item.reject(new Error('Die Browsersteuerung wurde geschlossen.'));
        },
        idle() { return completion; },
        get pendingCount() { return pending.length; },
        get running() { return running; },
    };
}

export function workflowLiveRecording(config = {}) {
    let queue;
    let timer;
    let moveTimer;
    let nextMove;
    let stateController;
    let commandController;
    let lastConfig = '';
    let destroyed = false;
    let visibilityListener;
    let navigationListener;
    let lastStateAt = 0;
    let lastFrameAt = 0;
    let imagePendingAt = 0;
    let consecutiveFrameErrors = 0;
    let serverState = config.initialState || 'idle';
    let toolbarSyncPending = false;
    let toolbarSyncInFlight = false;
    let lastToolbarSync = '';

    return {
        recordingId: config.recordingId || null,
        commandUrl: config.commandUrl || '',
        stateUrl: config.stateUrl || '',
        frameUrl: config.frameUrl || '',
        csrfToken: config.csrfToken || '',
        state: config.initialState || 'idle',
        events: Array.isArray(config.initialEvents) ? config.initialEvents : [],
        valueGroups: normalizeRecordingValueOptions(config.valueOptions),
        viewport: { width: 1280, height: 800 },
        pageTitle: '',
        pageUrl: '',
        frameSource: '',
        frameReady: false,
        frameFailed: false,
        transportError: '',
        interactionError: '',
        commandBusy: false,
        serverBusy: false,
        transitioning: false,
        selectedTarget: null,
        cursor: null,
        menuOpen: false,
        menuX: 16,
        menuY: 16,
        valueSource: 'fixed',
        fillValue: '',
        variableName: '',
        previewValue: '',
        selectedEventId: config.selectedEventId || null,

        init() {
            queue = createRecordingCommandQueue((command) => this.sendCommand(command));
            visibilityListener = () => {
                if (!document.hidden) {
                    lastStateAt = 0;
                    lastFrameAt = 0;
                    void this.tick();
                }
            };
            navigationListener = () => this.destroy();
            document.addEventListener('visibilitychange', visibilityListener);
            document.addEventListener('livewire:navigating', navigationListener);
            timer = setInterval(() => void this.tick(), 500);
            this.applyState(config.initialBrowserState || {});
            this.syncConfiguration();
            void this.tick();
        },

        destroy() {
            if (destroyed) return;
            destroyed = true;
            clearInterval(timer);
            clearTimeout(moveTimer);
            stateController?.abort();
            commandController?.abort();
            queue?.close();
            document.removeEventListener('visibilitychange', visibilityListener);
            document.removeEventListener('livewire:navigating', navigationListener);
            this.clearFillDraft();
        },

        syncConfiguration() {
            const raw = this.$root?.querySelector('[data-live-recording-config]')?.textContent || '';
            if (!raw || raw === lastConfig) return;
            try {
                const next = JSON.parse(raw);
                const changedSession = this.recordingId !== next.recordingId;
                this.recordingId = next.recordingId || null;
                this.commandUrl = next.commandUrl || '';
                this.stateUrl = next.stateUrl || '';
                this.frameUrl = next.frameUrl || '';
                this.csrfToken = next.csrfToken || '';
                serverState = next.initialState || 'idle';
                this.state = serverState;
                this.events = Array.isArray(next.initialEvents) ? next.initialEvents : [];
                this.valueGroups = normalizeRecordingValueOptions(next.valueOptions);
                this.selectedEventId = next.selectedEventId || null;
                if (changedSession) {
                    this.frameReady = false;
                    this.frameFailed = false;
                    this.frameSource = '';
                    this.selectedTarget = null;
                    this.transportError = '';
                    consecutiveFrameErrors = 0;
                    lastStateAt = 0;
                    lastFrameAt = 0;
                    this.persistRecordingId();
                }
                this.applyState(next.initialBrowserState || {});
                if (this.state !== 'recording') this.closeMenu(false);
                lastConfig = raw;
            } catch {
                this.transportError = 'Der Aufnahmezustand konnte nicht gelesen werden. Bitte die Seite neu laden.';
            }
        },

        async tick() {
            if (destroyed) return;
            this.syncConfiguration();
            void this.syncToolbarState();
            if (document.hidden || !this.recordingId) return;
            const now = Date.now();
            if (ACTIVE_STATES.has(this.state) && now - lastStateAt >= 2000 && !stateController && !this.commandBusy && !toolbarSyncInFlight) {
                lastStateAt = now;
                await this.pollState();
            }
            if (this.frameUrl && ACTIVE_STATES.has(this.state) && now - lastFrameAt >= 1000 && (!imagePendingAt || now - imagePendingAt > 5000)) {
                this.requestFrame();
            }
        },

        async pollState() {
            if (!this.stateUrl || stateController || destroyed) return;
            stateController = new AbortController();
            try {
                const response = await window.axios.get(this.stateUrl, { signal: stateController.signal, timeout: 12000 });
                if (!destroyed && !this.commandBusy) this.applyState(response.data, true);
            } catch (error) {
                if (error?.code !== 'ERR_CANCELED' && !destroyed) this.showError(error, 'Der Browserzustand ist momentan nicht erreichbar.');
            } finally {
                stateController = null;
                void this.syncToolbarState();
            }
        },

        applyState(payload = {}, fromHttp = false) {
            const next = payload.recording || payload;
            if (next.state) this.state = next.state;
            if (Array.isArray(next.events)) this.events = next.events;
            if (Number(next.viewport?.width) > 0 && Number(next.viewport?.height) > 0) this.viewport = next.viewport;
            this.pageTitle = text(next.title);
            this.pageUrl = text(next.url);
            this.cursor = next.cursor || this.cursor;
            if (next.selected) this.selectedTarget = next.selected;
            this.transportError = recordingErrorMessage(next.error);
            if (fromHttp && this.state !== serverState) toolbarSyncPending = true;
        },

        async syncToolbarState() {
            if (destroyed || !toolbarSyncPending || toolbarSyncInFlight || this.serverBusy || this.transitioning || this.commandBusy || !this.recordingId || typeof this.$wire?.refreshRecording !== 'function') return;
            toolbarSyncPending = false;
            if (this.state === serverState) return;
            const key = `${this.recordingId}:${serverState}->${this.state}`;
            if (key === lastToolbarSync) return;
            lastToolbarSync = key;
            toolbarSyncInFlight = true;
            try {
                // A real automatic pause/failure needs one Blade control render,
                // not a Livewire roundtrip for every ordinary screenshot poll.
                await this.$wire.refreshRecording();
                if (!destroyed) this.syncConfiguration();
            } catch (error) {
                if (!destroyed) this.showError(error, 'Die Aufnahmesteuerung konnte nicht synchronisiert werden.');
            } finally {
                toolbarSyncInFlight = false;
            }
        },

        requestFrame() {
            if (!this.frameUrl || destroyed || document.hidden) return;
            imagePendingAt = Date.now();
            lastFrameAt = imagePendingAt;
            const separator = this.frameUrl.includes('?') ? '&' : '?';
            this.frameSource = `${this.frameUrl}${separator}frame=${imagePendingAt}`;
        },

        onFrameLoaded() {
            imagePendingAt = 0;
            consecutiveFrameErrors = 0;
            this.frameReady = true;
            this.frameFailed = false;
        },

        onFrameError() {
            imagePendingAt = 0;
            consecutiveFrameErrors += 1;
            this.frameFailed = true;
            if (consecutiveFrameErrors >= 3) lastFrameAt = Date.now() + 4000;
        },

        canControl() {
            return this.state === 'recording' && this.frameReady && !this.frameFailed && !this.transportError && !this.transitioning;
        },

        persistRecordingId() {
            if (!this.recordingId || typeof window === 'undefined' || !window.history?.replaceState) return;
            const url = new URL(window.location.href);
            url.searchParams.set('recording', String(this.recordingId));
            window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
        },

        async transition(method) {
            if (this.serverBusy || destroyed) return;
            this.serverBusy = true;
            this.transitioning = true;
            clearTimeout(moveTimer);
            moveTimer = null;
            nextMove = null;
            this.closeMenu(false);
            queue?.close();
            stateController?.abort();
            commandController?.abort();
            try {
                await queue?.idle();
                await this.$wire[method]();
                this.syncConfiguration();
            } catch (error) {
                if (!destroyed) this.showError(error, 'Die Aufnahmesteuerung konnte nicht aktualisiert werden.');
            } finally {
                if (!destroyed) queue = createRecordingCommandQueue((command) => this.sendCommand(command));
                this.serverBusy = false;
                this.transitioning = false;
                lastStateAt = 0;
                lastFrameAt = 0;
                void this.tick();
            }
        },

        coordinates(event) {
            const frame = this.$refs?.frame;
            if (!frame) return null;
            return mapRecordingCoordinates(event, frame.getBoundingClientRect(), this.viewport);
        },

        movePointer(event) {
            if (!this.canControl() || this.menuOpen) return;
            nextMove = this.coordinates(event);
            if (!nextMove || moveTimer) return;
            moveTimer = setTimeout(() => {
                moveTimer = null;
                if (nextMove && this.canControl() && !this.menuOpen) this.queueControl({ type: 'move', ...nextMove });
                nextMove = null;
            }, 100);
        },

        clickPointer(event) {
            if (!this.canControl() || this.menuOpen || event.button !== 0) return;
            const point = this.coordinates(event);
            if (!point) return;
            this.$refs.preview.focus({ preventScroll: true });
            this.queueControl({ type: 'click', ...point });
        },

        wheelPointer(event) {
            if (!this.canControl() || this.menuOpen || !this.coordinates(event)) return;
            event.preventDefault();
            const multiplier = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? this.viewport.height : 1;
            const deltaY = Math.round(Math.max(-1600, Math.min(1600, Number(event.deltaY) * multiplier)));
            if (deltaY) this.queueControl({ type: 'scroll', deltaY });
        },

        keyPointer(event) {
            if (!this.canControl() || this.menuOpen || event.ctrlKey || event.metaKey || event.altKey) return;
            if (event.key === 'Escape') {
                this.$refs.preview.blur();
                return;
            }
            if (!KEY_COMMANDS.has(event.key)) return;
            event.preventDefault();
            this.queueControl({ type: 'key', key: event.key });
        },

        async openInputMenu(event = null) {
            if (!this.canControl()) return;
            const point = event ? this.coordinates(event) : this.cursor;
            if (!point || !Number.isFinite(Number(point.x)) || !Number.isFinite(Number(point.y))) {
                this.interactionError = 'Bitte das Eingabefeld zuerst mit der rechten Maustaste auswählen.';
                return;
            }
            try {
                await this.control({ type: 'inspect', x: Number(point.x), y: Number(point.y) });
                if (!this.selectedTarget?.editable) {
                    this.interactionError = 'An dieser Stelle wurde kein bearbeitbares Eingabefeld gefunden.';
                    return;
                }
                const preview = this.$refs.preview.getBoundingClientRect();
                this.menuX = Math.max(12, Math.min(preview.width - 312, event ? event.clientX - preview.left : 24));
                this.menuY = Math.max(12, Math.min(preview.height - 400, event ? event.clientY - preview.top : 24));
                this.valueSource = 'fixed';
                this.interactionError = '';
                this.clearFillDraft();
                this.menuOpen = true;
                this.$nextTick(() => this.$refs.valueSource?.focus());
            } catch { /* The visible transport error is set by control(). */ }
        },

        clearFillDraft() {
            this.fillValue = '';
            this.variableName = '';
            this.previewValue = '';
        },

        closeMenu(returnFocus = true) {
            this.menuOpen = false;
            this.clearFillDraft();
            if (returnFocus) this.$refs?.preview?.focus({ preventScroll: true });
        },

        async fillTarget() {
            try {
                const allowedValues = this.valueGroups.flatMap((group) => group.options.map((option) => option.value));
                const command = buildRecordingFillCommand(this.selectedTarget, {
                    source: this.valueSource, value: this.fillValue,
                    variable: this.variableName, previewValue: this.previewValue,
                }, allowedValues);
                await this.control(command);
                this.closeMenu();
            } catch (error) {
                this.interactionError = text(error?.message) || 'Die Eingabe konnte nicht übernommen werden.';
            }
        },

        queueControl(command) {
            void this.control(command).catch(() => { /* Error is rendered in the control surface. */ });
        },

        async control(command) {
            if (destroyed || !queue) return;
            try {
                return await queue.enqueue(command);
            } catch (error) {
                if (!destroyed && !this.transitioning) this.showError(error, 'Die Browseraktion konnte nicht ausgeführt werden.');
                throw error;
            }
        },

        async sendCommand(command) {
            if (destroyed || !this.commandUrl) throw new Error('Es ist kein Browser verbunden.');
            stateController?.abort();
            commandController = new AbortController();
            this.commandBusy = true;
            try {
                const response = await window.axios.post(this.commandUrl, command, {
                    signal: commandController.signal,
                    timeout: 20000,
                    headers: { 'X-CSRF-TOKEN': this.csrfToken },
                });
                if (!destroyed) {
                    this.applyState(response.data, true);
                    if (command.type !== 'move') lastFrameAt = 0;
                }
                return response.data;
            } finally {
                // Payloads containing ephemeral test inputs are not retained.
                if (command.type === 'fill') {
                    delete command.previewValue;
                    if (command.source === 'literal') delete command.value;
                }
                this.commandBusy = false;
                commandController = null;
                void this.syncToolbarState();
            }
        },

        showError(error, fallback) {
            const message = error?.response?.data?.message || error?.message;
            this.transportError = text(message) || fallback;
        },

        async refresh() {
            this.transportError = '';
            lastStateAt = 0;
            lastFrameAt = 0;
            await this.pollState();
            this.requestFrame();
        },

        stateLabel() {
            return ({ draft: 'Bereit', idle: 'Bereit', starting: 'Browser startet', recording: 'Aufnahme läuft', paused: 'Pausiert', stopped: 'Aufnahme beendet', saved: 'Workflow gespeichert', failed: 'Verbindung unterbrochen', error: 'Verbindung unterbrochen' })[this.state] || this.state;
        },

        eventLabel(event) {
            return text(event.label) || ({ navigate: 'Seite öffnen', click: 'Klicken', hover: 'Maus bewegen', fill: 'Eingabe ausfüllen', key: 'Taste drücken', scroll: 'Scrollen' })[event.type] || 'Browseraktion';
        },
    };
}
