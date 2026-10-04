const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
let buildWorkflowRouteLines;
let workflowRouteSurface;

test.before(async () => {
    ({ buildWorkflowRouteLines, workflowRouteSurface } = await import('../../resources/js/components/workflow-route-surface.js'));
});

const node = (index) => ({
    index, column: `column-${index}`,
    rect: { left: index * 360 + 20, right: index * 360 + 300, top: 160, bottom: 220, width: 280, height: 60, centerX: index * 360 + 160, centerY: 190 },
    columnRect: { left: index * 360, right: index * 360 + 320, top: 100, bottom: 600 },
});
const nodes = () => new Map([['a', node(0)], ['b', node(1)]]);
const edge = (details = {}) => ({ id: 'connection', source: 'a', target: 'b', outcome: 'success', ...details });
const surfaceFor = (edges, routeEvidenceMode = true) => {
    const surface = workflowRouteSurface({ instance: 'qa', routeEvidenceMode });
    surface.routeLines = buildWorkflowRouteLines(edges, nodes());
    surface.showAllRoutes = true;
    surface.renderRouteLines();
    return surface;
};
const assertNeutral = (surface) => {
    assert.match(surface.routeSvgMarkup, /stroke="#94a3b8"/);
    assert.match(surface.routeSvgMarkup, /marker-end="url\(#qa-arrow-neutral\)"/);
    assert.match(surface.routeSvgMarkup, /data-route-tone="neutral"/);
    assert.match(surface.routeSvgMarkup, /data-route-observed="false"/);
    assert.doesNotMatch(surface.routeSvgMarkup, /stroke="#(?:10b981|fb7185|3b82f6|8b5cf6|0ea5e9)"/);
};

test('all untraversed outcomes stay neutral during a run, including hovered, selected and All routes', () => {
    for (const outcome of ['success', 'failed', 'partial', 'timeout', 'runtime', 'implicit', 'default']) {
        const surface = surfaceFor([edge({ outcome, runtime: true, pending: true, executed: false, runtimeCount: 0, visualTone: 'runtime' })]);
        const geometry = JSON.stringify(surface.routeLines);
        assertNeutral(surface);
        surface.showAllRoutes = false;
        surface.setHoveredRouteNode('a');
        assertNeutral(surface);
        surface.setActiveRouteNode('a');
        assertNeutral(surface);
        surface.toggleAllRoutes();
        assertNeutral(surface);
        assert.equal(JSON.stringify(surface.routeLines), geometry);
    }
});

test('only a positive runtime count or true executed evidence colors a runtime edge', () => {
    for (const evidence of [{ runtimeCount: 1 }, { runtime_count: 2 }, { runtimeCount: '1' }, { executed: true }]) {
        const surface = surfaceFor([edge({ runtime: true, visualTone: 'runtime', ...evidence })]);
        assert.equal(surface.routeLines[0].runtimeObserved, true);
        assert.match(surface.routeSvgMarkup, /stroke="#0ea5e9"/);
        assert.match(surface.routeSvgMarkup, /marker-end="url\(#qa-arrow-runtime\)"/);
        assert.match(surface.routeSvgMarkup, /data-route-outcome="success"/);
        assert.match(surface.routeSvgMarkup, /data-route-tone="runtime"/);
        assert.match(surface.routeSvgMarkup, /data-route-observed="true"/);
    }
});

test('pending, active, malformed counts and definition flags cannot claim an executed connection', () => {
    for (const details of [
        { runtime: true },
        { runtime: true, pending: true },
        { runtime: true, runtimeActive: true },
        { runtime: true, runtimeCount: -1 },
        { runtime: true, runtimeCount: '0' },
        { runtime: true, runtimeCount: 'not-a-count' },
        { runtime: true, runtimeCount: Infinity },
        { runtime: true, executed: 'false' },
        { runtime: false, runtimeCount: 9, executed: true },
    ]) assertNeutral(surfaceFor([edge(details)]));
});

test('editor without a run and observed historical runs retain semantic route colors', () => {
    const colors = { success: '#10b981', failed: '#fb7185', partial: '#3b82f6', timeout: '#8b5cf6' };
    for (const [outcome, color] of Object.entries(colors)) {
        const definition = surfaceFor([edge({ outcome })], false);
        assert.match(definition.routeSvgMarkup, new RegExp(`stroke="${color}"`));
        assert.match(definition.routeSvgMarkup, /data-route-observed="false"/);
        const history = surfaceFor([edge({ outcome, runtime: true, runtimeCount: 1 })]);
        assert.match(history.routeSvgMarkup, new RegExp(`stroke="${color}"`));
        assert.match(history.routeSvgMarkup, /data-route-observed="true"/);
    }
});

test('observed evidence survives duplicate configured or pending edges in either order', () => {
    const observed = edge({ runtime: true, executed: true, visualTone: 'runtime' });
    const planned = edge({ visualTone: 'neutral' });
    const pending = edge({ runtime: true, pending: true, visualTone: 'neutral' });
    for (const edges of [[planned, pending, observed], [observed, pending, planned]]) {
        const surface = surfaceFor(edges);
        assert.equal(surface.routeLines.length, 1);
        assert.equal(surface.routeLines[0].runtimeObserved, true);
        assert.match(surface.routeSvgMarkup, /stroke="#0ea5e9"/);
    }
});

test('pending to observed only changes paint, not real outcome, ports or lane geometry', () => {
    const outcomes = ['success', 'failed', 'partial', 'timeout'];
    const before = buildWorkflowRouteLines(outcomes.map((outcome) => edge({ outcome, runtime: true, pending: true, visualTone: 'neutral' })), nodes());
    const after = buildWorkflowRouteLines(outcomes.map((outcome) => edge({ outcome, runtime: true, executed: true, visualTone: 'runtime' })), nodes());
    const geometry = (lines) => lines.map(({ outcome, kind, slot, path, points }) => ({ outcome, kind, slot, path, points }));
    assert.deepEqual(geometry(after), geometry(before));
});

test('an existing Alpine renderer switches idle to run and back using the morphed route-map flag', () => {
    const surface = workflowRouteSurface({ instance: 'qa', routeEvidenceMode: false });
    const rect = (left, top, width, height) => ({ left, top, right: left + width, bottom: top + height, width, height });
    const column = { getBoundingClientRect: () => rect(0, 100, 320, 500) };
    const source = { dataset: { workflowTaskNode: 'a', workflowStepAction: 'main' }, getBoundingClientRect: () => rect(20, 160, 280, 60), closest: () => column };
    const target = { dataset: { workflowTaskNode: 'b', workflowStepAction: 'main' }, getBoundingClientRect: () => rect(20, 280, 280, 60), closest: () => column };
    const root = {
        offsetWidth: 900, offsetHeight: 600, scrollWidth: 900, clientWidth: 900, scrollHeight: 600, clientHeight: 600, scrollTop: 0, scrollLeft: 0,
        getBoundingClientRect: () => rect(0, 0, 900, 600),
        querySelectorAll: (selector) => selector.includes('task-node') ? [source, target] : selector.includes('route-node') ? [] : [column],
    };
    surface.$refs = { routeSurface: root, routeMap: { textContent: '' } };
    for (const active of [false, true, false]) {
        surface.$refs.routeMap.textContent = JSON.stringify({ routeEvidenceMode: active, edges: [edge()] });
        surface.refreshRouteLines();
        assert.equal(surface.routeEvidenceMode, active);
        if (active) assertNeutral(surface);
        else assert.match(surface.routeSvgMarkup, /stroke="#10b981"/);
    }
});

test('the shared SVG marker definitions include an actually neutral arrowhead', () => {
    const template = readFileSync(join(__dirname, '../../resources/views/components/workflows/route-markers.blade.php'), 'utf8');
    assert.match(template, /'neutral' => '#94a3b8'/);
    assert.match(template, /fill="\{\{ \$markerColor \}\}"/);
});
