const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { pathToFileURL } = require('node:url');

const modulePromise = import(pathToFileURL(resolve(__dirname, '../../resources/js/components/workflow-live-recording.js')).href);

test('maps pointer coordinates through horizontal and vertical object-fit letterboxes', async () => {
    const { mapRecordingCoordinates } = await modulePromise;
    const viewport = { width: 1280, height: 800 };
    assert.deepEqual(mapRecordingCoordinates({ clientX: 400, clientY: 350 }, { left: 0, top: 0, width: 800, height: 700 }, viewport), { x: 640, y: 400 });
    assert.equal(mapRecordingCoordinates({ clientX: 400, clientY: 50 }, { left: 0, top: 0, width: 800, height: 700 }, viewport), null);
    assert.deepEqual(mapRecordingCoordinates({ clientX: 600, clientY: 300 }, { left: 100, top: 50, width: 1000, height: 500 }, viewport), { x: 640, y: 400 });
    assert.equal(mapRecordingCoordinates({ clientX: 110, clientY: 300 }, { left: 100, top: 50, width: 1000, height: 500 }, viewport), null);
});

test('rejects missing viewport, nonfinite coordinates and right/bottom edges', async () => {
    const { mapRecordingCoordinates } = await modulePromise;
    const viewport = { width: 1280, height: 800 };
    const rect = { left: 0, top: 0, width: 1280, height: 800 };
    assert.equal(mapRecordingCoordinates({ clientX: 1280, clientY: 800 }, rect, viewport), null);
    assert.equal(mapRecordingCoordinates({ clientX: NaN, clientY: 30 }, rect, viewport), null);
    assert.equal(mapRecordingCoordinates({ clientX: 30, clientY: 30 }, rect, {}), null);
    assert.equal(mapRecordingCoordinates({ clientX: 30, clientY: 30 }, { ...rect, width: 0 }, viewport), null);
    assert.deepEqual(mapRecordingCoordinates({ clientX: 1279.9, clientY: 799.9 }, rect, viewport), { x: 1279, y: 799 });
});

test('normalizes the established associative catalog without introducing unknown value paths', async () => {
    const { normalizeRecordingValueOptions } = await modulePromise;
    assert.deepEqual(normalizeRecordingValueOptions({ person: { label: 'Person', options: { 'person.email': 'E-Mail', 'person.first_name': 'Vorname' } }, empty: { options: {} } }), [
        { key: 'person', label: 'Person', options: [{ value: 'person.email', label: 'E-Mail' }, { value: 'person.first_name', label: 'Vorname' }] },
    ]);
});

test('sensitive input is fail-closed for literal bindings and never reads the live page value', async () => {
    const { buildRecordingFillCommand } = await modulePromise;
    const target = { editable: true, selector: '#password, input[name="password"]', sensitive: true, value: 'should-never-be-read' };
    assert.throws(() => buildRecordingFillCommand(target, { source: 'literal', value: 'secret' }), /Sensible/);
    assert.deepEqual(buildRecordingFillCommand(target, { source: 'workflow_variable', variable: 'account.password', previewValue: 'one-shot-test' }), {
        type: 'fill', selector: '#password, input[name="password"]', source: 'workflow_variable', workflow_variable: 'account.password', previewValue: 'one-shot-test',
    });
});

test('fixed bindings require an allowed catalog choice; variables are constrained', async () => {
    const { buildRecordingFillCommand } = await modulePromise;
    const target = { editable: true, selector: '#email, input[type="email"]', sensitive: false };
    assert.throws(() => buildRecordingFillCommand(target, { source: 'fixed', value: 'made.up' }, ['person.email']), /Datenwert/);
    assert.throws(() => buildRecordingFillCommand(target, { source: 'workflow_variable', variable: '../secret' }), /Variablennamen/);
    for (const variable of ['account-password', 'account..password', '.password', 'password.', 'account.1password', 'a'.repeat(161)]) {
        assert.throws(() => buildRecordingFillCommand(target, { source: 'workflow_variable', variable }), /Variablennamen/);
    }
    assert.equal(buildRecordingFillCommand(target, { source: 'workflow_variable', variable: '_account.password_2' }).workflow_variable, '_account.password_2');
    assert.throws(() => buildRecordingFillCommand({ editable: false }, { source: 'literal' }), /Eingabefeld/);
    assert.deepEqual(buildRecordingFillCommand(target, { source: 'fixed', value: 'person.email' }, ['person.email']), {
        type: 'fill', selector: '#email, input[type="email"]', source: 'fixed', value: 'person.email',
    });
});

test('queue keeps one request in flight, replaces pending moves, and preserves click order', async () => {
    const { createRecordingCommandQueue } = await modulePromise;
    const seen = [];
    const gates = [];
    const queue = createRecordingCommandQueue((command) => new Promise((resolve) => { seen.push(command); gates.push(resolve); }));
    const first = queue.enqueue({ type: 'click', x: 1, y: 1 });
    const superseded = queue.enqueue({ type: 'move', x: 2, y: 2 });
    const latest = queue.enqueue({ type: 'move', x: 9, y: 9 });
    const last = queue.enqueue({ type: 'click', x: 10, y: 10 });
    assert.equal(queue.pendingCount, 2);
    assert.equal(seen.length, 1);
    assert.deepEqual(await superseded, { coalesced: true });
    gates.shift()({ ok: true });
    await first;
    await Promise.resolve();
    assert.deepEqual(seen[1], { type: 'move', x: 9, y: 9 });
    gates.shift()({ ok: true });
    await latest;
    await Promise.resolve();
    assert.deepEqual(seen[2], { type: 'click', x: 10, y: 10 });
    gates.shift()({ ok: true });
    await last;
    assert.equal(queue.pendingCount, 0);
});

test('queue bounds pending actions and cancels uncertain later actions after an error', async () => {
    const { createRecordingCommandQueue } = await modulePromise;
    let rejectFirst;
    const queue = createRecordingCommandQueue(() => new Promise((resolve, reject) => { rejectFirst = reject; }), 1);
    const first = queue.enqueue({ type: 'click' });
    const pending = queue.enqueue({ type: 'click' });
    const full = queue.enqueue({ type: 'click' });
    const firstFailure = assert.rejects(first, /failed/);
    const pendingFailure = assert.rejects(pending, /failed/);
    await assert.rejects(full, /beschäftigt/);
    rejectFirst(new Error('failed'));
    await Promise.all([firstFailure, pendingFailure]);
    assert.equal(queue.pendingCount, 0);
    queue.close();
    await assert.rejects(queue.enqueue({ type: 'click' }), /geschlossen/);
});

test('closing a queue cancels pending commands and idle waits for the in-flight command', async () => {
    const { createRecordingCommandQueue } = await modulePromise;
    let finish;
    const queue = createRecordingCommandQueue(() => new Promise((resolve) => { finish = resolve; }));
    const first = queue.enqueue({ type: 'click' });
    const pending = queue.enqueue({ type: 'move' });
    const pendingFailure = assert.rejects(pending, /geschlossen/);
    let settled = false;
    const idle = queue.idle().then(() => { settled = true; });
    queue.close();
    await pendingFailure;
    assert.equal(settled, false);
    finish({ done: true });
    await first;
    await idle;
    assert.equal(settled, true);
});

test('only Enter and Tab are sent; printable text and shortcut keys are not recorded', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ initialState: 'recording' });
    ui.frameReady = true;
    const sent = [];
    ui.queueControl = (command) => sent.push(command);
    const event = (key, overrides = {}) => ({ key, preventDefault() {}, ...overrides });
    ui.keyPointer(event('p'));
    ui.keyPointer(event('Backspace'));
    ui.keyPointer(event('Enter', { ctrlKey: true }));
    ui.keyPointer(event('Tab'));
    ui.keyPointer(event('Enter'));
    assert.deepEqual(sent, [{ type: 'key', key: 'Tab' }, { type: 'key', key: 'Enter' }]);
    ui.state = 'paused';
    ui.keyPointer(event('Enter'));
    assert.equal(sent.length, 2);
});

test('frame failure blocks controls while fixed frame geometry and recovery are retained', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ initialState: 'recording' });
    assert.equal(ui.canControl(), false);
    ui.onFrameLoaded();
    assert.equal(ui.canControl(), true);
    ui.onFrameError();
    assert.equal(ui.canControl(), false);
    ui.onFrameLoaded();
    assert.equal(ui.canControl(), true);
    ui.state = 'stopped';
    assert.equal(ui.canControl(), false);
});

test('server projection is read without inventing an active browser or retaining sensitive previews', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ initialState: 'draft' });
    ui.applyState({ state: 'paused', title: 'Test', url: 'https://example.test', viewport: { width: 1280, height: 800 }, events: [{ id: 'event-1', type: 'navigate' }], selected: { sensitive: true, editable: true, selector: '#password' }, cursor: { x: 40, y: 60 } });
    assert.equal(ui.stateLabel(), 'Pausiert');
    assert.equal(ui.canControl(), false);
    assert.equal(ui.events.length, 1);
    assert.equal(ui.selectedTarget.sensitive, true);
    ui.previewValue = 'only-for-this-browser';
    ui.fillValue = 'literal';
    ui.clearFillDraft();
    assert.equal(ui.previewValue, '');
    assert.equal(ui.fillValue, '');
});

test('server morph can bind a newly started recording without replacing the image workbench', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ initialState: 'draft' });
    let config = { recordingId: 'test-id', commandUrl: '/command', stateUrl: '/state', frameUrl: '/frame', initialState: 'recording', initialEvents: [{ id: 'event-1' }], valueOptions: {} };
    ui.$root = { querySelector: () => ({ textContent: JSON.stringify(config) }) };
    ui.syncConfiguration();
    assert.equal(ui.recordingId, 'test-id');
    assert.equal(ui.state, 'recording');
    assert.equal(ui.frameReady, false);
    ui.onFrameLoaded();
    ui.applyState({ events: [{ id: 'event-1' }, { id: 'event-2' }] });
    ui.syncConfiguration();
    assert.equal(ui.events.length, 2, 'unchanged server script must not overwrite fresh REST events');
    config = { ...config, initialState: 'paused', initialEvents: ui.events };
    ui.syncConfiguration();
    assert.equal(ui.state, 'paused');
    assert.equal(ui.frameReady, true, 'pause keeps the existing frame mounted');
});

test('repeated failed starts remain retryable using one stable Alpine instance and morphable server config', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const previousWindow = global.window;
    const previousDocument = global.document;
    global.document = { hidden: true, addEventListener() {}, removeEventListener() {} };
    global.window = { history: { replaceState() {}, state: {} }, location: { href: 'https://factory.example.test/netzwerk/live-aufnahme' } };
    let config = { recordingId: null, initialState: 'draft', initialBrowserState: {}, initialEvents: [], valueOptions: {}, csrfToken: 'synthetic-csrf' };
    let starts = 0;
    const ui = workflowLiveRecording();
    ui.$root = { querySelector: () => ({ textContent: JSON.stringify(config) }) };
    ui.$wire = { async startRecording() {
        starts += 1;
        config = { ...config, recordingId: starts, initialState: 'failed', initialBrowserState: { state: 'failed', error: 'browser_unavailable' } };
        // Livewire changes this payload, not the stable x-data expression.
        ui.syncConfiguration();
    } };
    try {
        ui.init();
        assert.equal(ui.csrfToken, 'synthetic-csrf');
        await ui.transition('startRecording');
        assert.equal(starts, 1);
        assert.equal(ui.serverBusy, false);
        assert.equal(ui.transitioning, false);
        await ui.transition('startRecording');
        assert.equal(starts, 2);
        assert.equal(ui.recordingId, 2);
        assert.equal(ui.serverBusy, false);
        assert.match(ui.transportError, /Aufnahmebrowser.*nicht erreichbar/);
    } finally {
        ui.destroy();
        global.window = previousWindow;
        global.document = previousDocument;
    }
});

test('known safe browser error codes render readable German messages', async () => {
    const { recordingErrorMessage } = await modulePromise;
    assert.match(recordingErrorMessage('browser_unavailable'), /erneut starten/);
    assert.match(recordingErrorMessage('event_limit'), /speichern/);
    assert.equal(recordingErrorMessage('Die Aufnahme ist nicht erreichbar.'), 'Die Aufnahme ist nicht erreichbar.');
    assert.equal(recordingErrorMessage(''), '');
});

test('automatic HTTP pause and failure synchronize Blade controls once per real state transition', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ recordingId: 8, initialState: 'recording' });
    let config = { recordingId: 8, initialState: 'recording', initialBrowserState: {}, initialEvents: [] };
    let refreshes = 0;
    ui.$root = { querySelector: () => ({ textContent: JSON.stringify(config) }) };
    ui.$wire = { async refreshRecording() {
        refreshes += 1;
        config = { ...config, initialState: ui.state };
    } };
    ui.syncConfiguration();
    ui.onFrameLoaded();
    ui.applyState({ state: 'recording' }, true);
    await ui.syncToolbarState();
    assert.equal(refreshes, 0);
    ui.applyState({ state: 'paused' }, true);
    await ui.syncToolbarState();
    assert.equal(refreshes, 1);
    assert.equal(ui.frameReady, true, 'control refresh must not remount the image');
    for (let index = 0; index < 3; index += 1) {
        ui.applyState({ state: 'paused' }, true);
        await ui.syncToolbarState();
    }
    assert.equal(refreshes, 1);
    ui.applyState({ state: 'failed', error: 'browser_unavailable' }, true);
    await ui.syncToolbarState();
    assert.equal(refreshes, 2);
    ui.applyState({ state: 'failed' }, true);
    await ui.syncToolbarState();
    assert.equal(refreshes, 2);
});

test('automatic control sync defers during commands and server transitions and never loops on a failed refresh', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const ui = workflowLiveRecording({ recordingId: 8, initialState: 'recording' });
    let refreshes = 0;
    ui.$wire = { async refreshRecording() { refreshes += 1; throw new Error('synchronization failed'); } };
    ui.applyState({ state: 'paused' }, true);
    ui.commandBusy = true;
    await ui.syncToolbarState();
    ui.commandBusy = false;
    ui.serverBusy = true;
    await ui.syncToolbarState();
    ui.serverBusy = false;
    ui.transitioning = true;
    await ui.syncToolbarState();
    assert.equal(refreshes, 0);
    ui.transitioning = false;
    await ui.syncToolbarState();
    assert.equal(refreshes, 1);
    for (let index = 0; index < 3; index += 1) {
        ui.applyState({ state: 'paused' }, true);
        await ui.syncToolbarState();
    }
    assert.equal(refreshes, 1, 'an unchanged state must not create an automatic Livewire retry loop');
});

test('automatic control sync stops at Alpine teardown', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const previousDocument = global.document;
    global.document = { removeEventListener() {} };
    try {
        const ui = workflowLiveRecording({ recordingId: 8, initialState: 'recording' });
        let refreshes = 0;
        ui.$wire = { async refreshRecording() { refreshes += 1; } };
        ui.applyState({ state: 'failed' }, true);
        ui.destroy();
        await ui.syncToolbarState();
        assert.equal(refreshes, 0);
    } finally { global.document = previousDocument; }
});

test('pause transition aborts transport and discards pending clicks before the Livewire action', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const previousWindow = global.window;
    const previousDocument = global.document;
    let starts = 0;
    let aborted = false;
    const listeners = new Map();
    global.document = { hidden: true, addEventListener(name, handler) { listeners.set(name, handler); }, removeEventListener(name) { listeners.delete(name); } };
    global.window = { axios: { post(url, command, options) { starts += 1; return new Promise((resolve, reject) => { options.signal.addEventListener('abort', () => { aborted = true; reject(Object.assign(new Error('canceled'), { code: 'ERR_CANCELED' })); }); }); } } };
    const ui = workflowLiveRecording({ recordingId: 1, commandUrl: '/command', initialState: 'recording' });
    ui.$root = { querySelector: () => null };
    ui.$wire = { async pauseRecording() { assert.equal(aborted, true); assert.equal(starts, 1); ui.state = 'paused'; } };
    try {
        ui.init();
        ui.frameReady = true;
        const first = ui.control({ type: 'click', x: 1, y: 1 }).catch(() => {});
        const pending = ui.control({ type: 'click', x: 2, y: 2 }).catch(() => {});
        await ui.transition('pauseRecording');
        await Promise.all([first, pending]);
        assert.equal(ui.transportError, '');
        assert.equal(ui.state, 'paused');
        assert.equal(ui.serverBusy, false);
        ui.previewValue = 'transient';
        ui.destroy();
        assert.equal(listeners.size, 0);
        assert.equal(ui.previewValue, '');
    } finally {
        ui.destroy();
        global.window = previousWindow;
        global.document = previousDocument;
    }
});

test('new owned recording URL persists only its query parameter without changing the page', async () => {
    const { workflowLiveRecording } = await modulePromise;
    const previousWindow = global.window;
    const calls = [];
    global.window = { location: { href: 'https://factory.example.test/netzwerk/workflows/live-aufnahme?filter=mine#draft' }, history: { state: { test: true }, replaceState(...args) { calls.push(args); } } };
    try {
        const ui = workflowLiveRecording({ recordingId: 25 });
        ui.persistRecordingId();
        assert.deepEqual(calls, [[{ test: true }, '', '/netzwerk/workflows/live-aufnahme?filter=mine&recording=25#draft']]);
    } finally { global.window = previousWindow; }
});

test('markup isolates the remote image from Livewire and has keyboard/context menu plus safe draft controls', () => {
    const blade = readFileSync(resolve(__dirname, '../../resources/views/livewire/admin/network/workflow-live-recording.blade.php'), 'utf8');
    const css = readFileSync(resolve(__dirname, '../../resources/css/workflow-live-recording.css'), 'utf8');
    assert.match(blade, /x-ref="preview" wire:ignore tabindex="0"/);
    assert.match(blade, /x-data="workflowLiveRecording\(\)"/);
    assert.doesNotMatch(blade, /x-data="workflowLiveRecording\(@js/);
    assert.match(blade, /wire:key="workflow-live-recording-shell"/);
    assert.match(blade, /wire:key="live-recording-start-button"/);
    assert.match(blade, /x-on:contextmenu\.prevent="openInputMenu\(\$event\)"/);
    assert.match(blade, /aria-label="Eingabe am letzten Mauspunkt zuordnen"/);
    assert.match(blade, /x-on:keydown\.escape\.prevent\.stop="closeMenu\(\)"/);
    assert.match(blade, /state !== 'stopped'/);
    assert.match(blade, /transition\('pauseRecording'\)/);
    assert.match(blade, /!\['paused', 'stopped'\]\.includes\(state\)/);
    assert.match(blade, /'initialBrowserState' => \$initialBrowserState/);
    assert.match(blade, /x-bind:disabled="selectedTarget\?\.sensitive"/);
    assert.match(blade, /x-bind:type="selectedTarget\?\.sensitive \? 'password' : 'text'"/);
    assert.match(blade, /live-recording-editor-\{\{ \$selectedEventId \}\}/);
    assert.match(blade, /wire:click="moveEvent/);
    assert.match(blade, /wire:click="removeEvent/);
    assert.doesNotMatch(blade, /<iframe|x-html|wire:model="previewValue"/);
    assert.match(css, /object-fit:contain/);
    assert.match(css, /prefers-reduced-motion:reduce/);
    assert.doesNotMatch(css, /transition:all|ease-in-out|backdrop-filter/);
});
