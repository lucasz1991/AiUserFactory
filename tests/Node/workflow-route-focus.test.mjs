import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildWorkflowRouteLines, workflowRouteSurface } from '../../resources/js/components/workflow-route-surface.js';

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
        assert.equal((surface.routeSvgMarkup.match(/data-route-edge=/g) || []).length, 4);
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
    assert.equal((surface.routeSvgMarkup.match(/data-route-edge=/g) || []).length, 6);
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

const layoutNode = (index, top, column, height = 60) => {
    const left = index * 360 + 20;
    return { index, column,
        rect: { left, right: left + 280, top, bottom: top + height, width: 280, height, centerX: left + 140, centerY: top + height / 2 },
        columnRect: { left: index * 360, right: index * 360 + 320, top: 100, bottom: 600 } };
};

test('duplicate routes merge runtime evidence and all outcomes have separate lanes', () => {
    const nodes = new Map([['a', layoutNode(0, 160, 'a')], ['b', layoutNode(1, 280, 'b')]]);
    const edges = ['success', 'failed', 'partial', 'timeout'].map((outcome) => ({ source: 'a', target: 'b', outcome }));
    edges.push({ source: 'a', target: 'b', outcome: 'success', runtimeActive: true });
    const lines = buildWorkflowRouteLines(edges, nodes);
    assert.equal(lines.length, 4);
    assert.equal(lines[0].runtimeActive, true);
    assert.equal(new Set(lines.map((line) => line.points[1].x)).size, 4);
    assert.equal(new Set(lines.map((line) => line.points.at(-1).y)).size, 4);
});

test('long forward routes and backwards routes use different external rails, outside every column', () => {
    const nodes = new Map([['a', layoutNode(0, 160, 'a')], ['middle', layoutNode(1, 280, 'middle')], ['b', layoutNode(2, 320, 'b')]]);
    const edges = [{ source: 'a', target: 'b', outcome: 'success' }, { source: 'b', target: 'a', outcome: 'failed' }];
    const lines = buildWorkflowRouteLines(edges, nodes);
    assert.equal(lines[0].kind, 'top');
    assert.ok(lines[0].points[2].y < 100);
    assert.equal(lines[1].kind, 'bottom');
    assert.ok(lines[1].points[2].y > 600);
    assert.deepEqual(lines.map((line) => line.path), buildWorkflowRouteLines([...edges].reverse(), nodes).map((line) => line.path));
});

test('hover and selection only paint routes, never recalculate or alter layout and lane geometry', () => {
    const nodes = new Map([['a', layoutNode(0, 160, 'a')], ['b', layoutNode(1, 320, 'b')]]);
    const geometry = buildWorkflowRouteLines([{ source: 'a', target: 'b', outcome: 'success' }], nodes);
    const before = JSON.stringify(geometry);
    const surface = workflowRouteSurface();
    surface.routeLines = geometry;
    surface.refreshRouteLines = () => assert.fail('Pointer interaction must not reflow the graph');
    surface.setHoveredRouteNode('a');
    assert.match(surface.routeSvgMarkup, /data-route-source="a"/);
    surface.setHoveredRouteNode('');
    surface.setActiveRouteNode('a');
    assert.equal(JSON.stringify(surface.routeLines), before);
});

test('self loops have distinct return ports and nearby same-column routes do not cross the card', () => {
    const column = {};
    const nodes = new Map([['a', layoutNode(0, 160, column)], ['b', layoutNode(0, 280, column)]]);
    const lines = buildWorkflowRouteLines([{ source: 'a', target: 'a', outcome: 'failed' }, { source: 'a', target: 'b', outcome: 'success' }], nodes);
    for (const line of lines) {
        assert.equal(line.kind, 'side');
        assert.ok(line.points[1].x > 320);
        assert.doesNotMatch(line.path, /NaN|Infinity/);
    }
    const self = lines.find((line) => line.targetNode === 'a');
    assert.notEqual(self.points[0].y, self.points.at(-1).y);
});

test('the default main path is quiet, while hover and All expose the complete branch set', () => {
    const surface = makeSurface();
    surface.renderRouteLines();
    assert.doesNotMatch(surface.routeSvgMarkup, /data-route-edge="(?:error|timeout|other|unrelated)"/);
    surface.setHoveredRouteNode('selected');
    assert.equal((surface.routeSvgMarkup.match(/data-route-edge=/g) || []).length, 4);
    surface.setHoveredRouteNode('');
    surface.toggleAllRoutes();
    assert.equal((surface.routeSvgMarkup.match(/data-route-edge=/g) || []).length, 6);
});
