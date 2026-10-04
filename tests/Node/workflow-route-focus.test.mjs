import { test } from 'node:test';
import assert from 'node:assert/strict';
import { workflowRouteSurface } from '../../resources/js/components/workflow-route-surface.js';

const makeSurface = () => {
    const surface = workflowRouteSurface({ instance: 'qa' });
    surface.routeLines = [
        { id: 'success', sourceNode: 'selected', targetNode: 'next', outcome: 'success', runtimeActive: true, path: 'M0 0L1 1' },
        { id: 'error', sourceNode: 'selected', targetNode: 'fail', outcome: 'failed', path: 'M0 0L1 1' },
        { id: 'timeout', sourceNode: 'selected', targetNode: 'end', outcome: 'timeout', path: 'M0 0L1 1' },
        { id: 'other', sourceNode: 'selected', targetNode: 'else', outcome: 'partial', path: 'M0 0L1 1' },
        { id: 'incoming', sourceNode: 'previous', targetNode: 'selected', outcome: 'success', path: 'M0 0L1 1' },
        { id: 'unrelated', sourceNode: 'elsewhere', targetNode: 'end', outcome: 'failed', path: 'M0 0L1 1' },
    ];
    return surface;
};

test('desktop and mobile focus render only outgoing routes with semantic colors', () => {
    for (const mobile of [true, false]) {
        const surface = makeSurface();
        surface.compactRouteMode = mobile;
        surface.setActiveRouteNode('selected');
        assert.equal((surface.routeSvgMarkup.match(/<path /g) || []).length, 4);
        assert.doesNotMatch(surface.routeSvgMarkup, /incoming|unrelated/);
        for (const color of ['#10b981', '#fb7185', '#8b5cf6', '#3b82f6']) assert.ok(surface.routeSvgMarkup.includes(color));
    }
});
test('a focused task with no outgoing routes shows no unrelated or incoming arrows', () => {
    const surface = makeSurface();
    surface.setActiveRouteNode('end');
    assert.equal(surface.routeSvgMarkup, '');
});
test('hover cannot replace a deliberate task selection; show all clears that selection', () => {
    const surface = makeSurface();
    surface.setActiveRouteNode('selected');
    surface.setHoveredRouteNode('previous');
    assert.equal(surface.routeFocusNode(), 'selected');
    assert.equal(surface.showAllRoutes, false);
    surface.toggleAllRoutes();
    assert.equal(surface.routeFocusNode(), '');
    assert.equal((surface.routeSvgMarkup.match(/<path /g) || []).length, 6);
});

test('terminal routes use the correct forward port and update after layout changes', () => {
    const surface = makeSurface();
    let targetLeft = 600;
    const rect = (left, top, width, height) => ({ left, top, right: left + width, bottom: top + height, width, height });
    const column = { getBoundingClientRect: () => rect(0, 40, 320, 300) };
    const source = { dataset: { workflowTaskNode: 'selected', workflowStepAction: 'login' }, getBoundingClientRect: () => rect(20, 120, 280, 80), closest: () => column };
    const terminal = { dataset: { workflowRouteNode: 'terminal::fail' }, getBoundingClientRect: () => rect(targetLeft, 80, 160, 80), closest: () => null };
    const root = {
        offsetWidth: 900, offsetHeight: 600, scrollWidth: 900, clientWidth: 900, scrollHeight: 600, clientHeight: 600, scrollTop: 0, scrollLeft: 0,
        getBoundingClientRect: () => rect(0, 0, 900, 600),
        querySelectorAll: (selector) => selector.includes('task-node') ? [source] : selector.includes('route-node') ? [terminal] : [column],
    };
    surface.$refs = { routeSurface: root, routeMap: { textContent: JSON.stringify({ edges: [{ source: 'selected', target: 'terminal::fail', outcome: 'failed' }] }) } };
    surface.refreshRouteLines();
    assert.match(surface.routeLines[0].path, /^M 300 /);
    assert.match(surface.routeLines[0].path, /L 600 120$/);
    targetLeft = 720;
    surface.refreshRouteLines();
    assert.match(surface.routeLines[0].path, /L 720 120$/);
});
