const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
let workflowLiveWorkspace;
test.before(async () => {
    ({ workflowLiveWorkspace } = await import('../../resources/js/components/workflow-live-workspace.js'));
});

test('the rendered editor event guard accepts its live mini but rejects another instance', () => {
    const template = readFileSync(join(__dirname, '../../resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php'), 'utf8');
    const guard = template.match(/eventTargetsThisEditor\(detail = \{\}\) \{([\s\S]+?)\r?\n        \},/);
    assert.ok(guard, 'Exercise the actual Alpine guard used by the shared editor.');
    const targets = new Function('detail', guard[1]
        .replaceAll('@js($routeMarkerId)', JSON.stringify('workflow-route-studio-42'))
        .replaceAll('@js($livePreviewInstance)', JSON.stringify('studio-42-live-preview')));
    const editor = {
        editorInstance: 'studio-42',
        $root: { offsetParent: {}, closest: () => null },
    };
    for (const instance of ['studio-42', 'workflow-route-studio-42', 'studio-42-live-preview']) {
        assert.equal(targets.call(editor, { instance }), true, instance);
    }
    assert.equal(targets.call(editor, { instance: 'studio-43-live-preview', source: 'studio-42' }), false);
    assert.equal(targets.call(editor, { editorInstance: 'studio-43', instance: 'studio-42-live-preview' }), false);
    assert.equal(targets.call(editor, {}), true);
    editor.$root.offsetParent = null;
    assert.equal(targets.call(editor, {}), false);
    editor.$root.offsetParent = {};
    editor.$root.closest = () => ({});
    assert.equal(targets.call(editor, {}), false);
});

function workspace(config = {}, surface = {}) {
    const callbacks = new Map();
    const events = [];
    const view = workflowLiveWorkspace({ previewInstance: 'studio-42-live', ...config }, surface);
    view.$wire = {
        workspaceRunId: config.runId || null,
        workspaceRunPresentation: config.presentation || 'edit',
        workspaceCursorNode: '',
        $watch: (name, callback) => callbacks.set(name, callback),
    };
    view.$nextTick = (callback) => callback();
    view.$dispatch = (name, detail) => events.push({ name, detail });
    view.$refs = {};
    view.eventTargetsThisEditor = () => true;
    view.events = events;
    view.callbacks = callbacks;
    return view;
}

test('an initial active run suppresses both library modes and shows the mini view', () => {
    const view = workspace({ runId: 72, presentation: 'live' });
    view.applyWorkspaceRun(72, 'live');
    assert.equal(view.workspaceRunId, 72);
    assert.equal(view.isLibraryVisible(), false);
    assert.equal(view.showLivePreview(), true);
    assert.equal(view.libraryExpanded, false);
    assert.equal(view.mobileLibraryOpen, false);
    assert.equal(view.events.at(-1).detail.instance, 'studio-42-live');
});

test('live entry retains the desktop preference and no library command can reopen it', () => {
    for (const original of [true, false]) {
        const view = workspace();
        view.libraryExpanded = original;
        view.applyWorkspaceRun(12, 'live');
        view.toggleLibrary();
        view.setLibraryExpanded(true);
        view.enterDefinitionWorkbench({});
        assert.equal(view.isLibraryVisible(), false);
        view.applyWorkspaceRun(12, 'edit');
        assert.equal(view.isLibraryVisible(), original);
        assert.equal(view.showLivePreview(), false);
    }
});

test('pause restores mobile presentation; resume closes it without changing saved choice', () => {
    const view = workspace();
    view.desktopSidebar = false;
    view.mobileLibraryOpen = true;
    view.applyWorkspaceRun(22, 'live');
    assert.equal(view.mobileLibraryOpen, false);
    view.setLibraryExpanded(true);
    assert.equal(view.isLibraryVisible(), false);
    view.applyWorkspaceRun(22, 'edit');
    assert.equal(view.mobileLibraryOpen, true);
    view.applyWorkspaceRun(22, 'live');
    view.applyWorkspaceRun(22, 'result');
    assert.equal(view.mobileLibraryOpen, true);
    assert.equal(view.isLibraryVisible(), false);
    view.editRunResult();
    assert.equal(view.isLibraryVisible(), true);
});

test('terminal result remains compact, edits survive repeated polls, new run returns live', () => {
    const view = workspace();
    view.applyWorkspaceRun(14, 'live');
    view.applyWorkspaceRun(14, 'result');
    assert.equal(view.showLivePreview(), true);
    view.editRunResult();
    assert.equal(view.showLivePreview(), false);
    view.applyWorkspaceRun(14, 'result');
    assert.equal(view.showLivePreview(), false);
    view.applyWorkspaceRun(15, 'live');
    assert.equal(view.showLivePreview(), true);
    assert.equal(view.resultEditing, false);
    assert.equal(view.isLibraryVisible(), false);
    view.applyWorkspaceRun(null, 'edit');
    assert.equal(view.workspaceRunId, 0);
    assert.equal(view.showLivePreview(), false);
});

test('selecting library from result exposes the same editor, never a second run', () => {
    const view = workspace({ runId: 3, presentation: 'result' });
    view.setLibraryExpanded(true);
    assert.equal(view.resultEditing, true);
    assert.equal(view.isLibraryVisible(), true);
    assert.equal(view.workspaceRunId, 3);
});

test('initialization and destruction preserve route ownership and observe own wire properties', () => {
    const priorWindow = globalThis.window;
    let starts = 0;
    let stops = 0;
    globalThis.window = { matchMedia: () => ({ matches: true }) };
    try {
        const view = workspace({}, {
            init() { starts += 1; assert.equal(this.events.length, 0); },
            destroy() { stops += 1; },
        });
        view.init();
        assert.equal(starts, 1);
        assert.deepEqual([...view.callbacks.keys()], ['workspaceRunId', 'workspaceRunPresentation', 'workspaceCursorNode']);
        view.$wire.workspaceRunId = 4;
        view.$wire.workspaceRunPresentation = 'live';
        view.callbacks.get('workspaceRunId')();
        assert.equal(view.isLibraryVisible(), false);
        view.$wire.workspaceRunPresentation = 'result';
        view.callbacks.get('workspaceRunPresentation')();
        assert.equal(view.showLivePreview(), true);
        view.destroy();
        assert.equal(stops, 1);
    } finally {
        globalThis.window = priorWindow;
    }
});

test('unrelated workbench requests preserve the current view', () => {
    const view = workspace({ runId: 5, presentation: 'result' });
    view.eventTargetsThisEditor = () => false;
    view.enterDefinitionWorkbench({ instance: 'some-other-editor' });
    assert.equal(view.showLivePreview(), true);
    assert.equal(view.resultEditing, false);
});

test('new observed cursors follow once, unchanged polls and result updates never steal scroll or focus', () => {
    const view = workspace({ runId: 10, presentation: 'live' });
    const calls = [];
    const targets = ['main::first', 'main::next'].map((node) => ({
        dataset: { minimapNode: node }, offsetParent: {},
        scrollIntoView: (options) => calls.push({ node, options }),
        focus: () => assert.fail('automatic progress must not steal keyboard focus'),
    }));
    view.$root = { querySelector: () => ({ querySelectorAll: () => targets }) };
    view.$wire.workspaceCursorNode = 'main::first';
    view.followLiveCursor();
    view.followLiveCursor();
    view.applyWorkspaceRun(10, 'live');
    assert.equal(calls.length, 1);
    view.$wire.workspaceCursorNode = 'main::next';
    view.followLiveCursor();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].options.behavior, 'auto');
    view.applyWorkspaceRun(10, 'result');
    view.followLiveCursor();
    assert.equal(calls.length, 2);
});
