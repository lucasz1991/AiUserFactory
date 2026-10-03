const { test } = require('node:test');
const assert = require('node:assert/strict');

test('inspector refresh preserves search and collapsed state without scrolling or replaying the cursor', async () => {
    const { workflowDomInspector } = await import('../../resources/js/components/workflow-dom-inspector.js');
    const inspector = workflowDomInspector({ interactive: true, canProbe: true });
    const payload = {
        nodes: [{ nodeRef: 'button', tag: 'button', visible: true, text: 'Before' }],
        cursor: { sequence: 1, toX: 40, toY: 30 },
        canProbe: true,
    };
    inspector.$refs = { payload: { textContent: JSON.stringify(payload) } };
    inspector.$nextTick = (callback) => callback();
    inspector.readState = () => ({ query: 'button', selectedRef: 'button' });
    inspector.buildSearchFrames = () => {};
    inspector.refreshSuggestions = () => {};
    inspector.revealAncestors = () => {};
    const searches = [];
    inspector.search = (selectFirst) => searches.push(selectFirst);
    let scrolls = 0;
    let animations = 0;
    inspector.scrollSelectedIntoView = () => { scrolls++; };
    inspector.animateCursor = () => { animations++; };
    inspector.refreshPayload(true);
    inspector.collapsed = { body: true };
    inspector.query = '#edited-search';

    payload.nodes[0].text = 'After';
    payload.canProbe = false;
    inspector.$refs.payload.textContent = JSON.stringify(payload);
    inspector.refreshPayload(false);
    assert.equal(inspector.nodes[0].text, 'After');
    assert.equal(inspector.query, '#edited-search');
    assert.equal(inspector.selectedRef, 'button');
    assert.deepEqual(inspector.collapsed, { body: true });
    assert.equal(inspector.canProbe, false);
    assert.equal(scrolls, 1);
    assert.equal(animations, 1);
    assert.deepEqual(searches, [true, false]);

    inspector.refreshPayload(false);
    assert.equal(searches.length, 2, 'identical payload is a no-op');
    payload.cursor.sequence = 2;
    inspector.$refs.payload.textContent = JSON.stringify(payload);
    inspector.refreshPayload(false);
    assert.equal(animations, 2, 'a genuinely new cursor event still animates');
    assert.equal(searches.length, 2, 'cursor-only updates do not rebuild DOM search');
    assert.equal(scrolls, 1);

    inspector.$refs.payload.textContent = 'invalid JSON';
    inspector.refreshPayload(false);
    assert.equal(inspector.nodes[0].text, 'After', 'incomplete payload must not clear the inspector');
});

test('inspector teardown disconnects its payload observer', async () => {
    const { workflowDomInspector } = await import('../../resources/js/components/workflow-dom-inspector.js');
    const inspector = workflowDomInspector();
    let disconnected = false;
    inspector._payloadObserver = { disconnect() { disconnected = true; } };
    inspector.destroy();
    assert.equal(disconnected, true);
    assert.equal(inspector._destroyed, true);
});
