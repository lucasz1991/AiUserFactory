'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
  clickAt,
  cursorForWindow,
  moveCursorTo,
  targetForBox,
} = require('../../node/workflows/tasks/lib/cursor.cjs');

function context(level = 'preview', overrides = {}) {
  return {
    activeBrowserWindow: 'main',
    observability: {
      level,
      showsCursor: level !== 'off',
      ...overrides,
    },
  };
}

function pageWithMouse(viewport = { width: 800, height: 600 }) {
  const calls = [];

  return {
    calls,
    viewport: () => viewport,
    mouse: {
      async move(x, y, options) {
        calls.push(['move', x, y, options]);
      },
      async down(options) {
        calls.push(['down', options]);
      },
      async up(options) {
        calls.push(['up', options]);
      },
    },
  };
}

test('target geometry uses the element center and clamps to the viewport', () => {
  assert.deepEqual(
    targetForBox(
      { x: 100, y: 40, width: 80, height: 20 },
      { width: 800, height: 600 },
    ),
    { x: 140, y: 50 },
  );
  assert.deepEqual(
    targetForBox(
      { x: 900, y: 700, width: 20, height: 20 },
      { width: 800, height: 600 },
    ),
    { x: 799, y: 599 },
  );
});

test('click moves smoothly then sends real mouse down and up with cursor telemetry', async () => {
  const page = pageWithMouse();
  const runContext = context('debug');
  const result = await clickAt(page, { x: 90, y: 70, width: 20, height: 10 }, {
    context: runContext,
    action: 'click',
    steps: 7,
  });

  assert.equal(result.handled, true);
  assert.deepEqual(page.calls.map((call) => call[0]), ['move', 'down', 'up']);
  assert.deepEqual(page.calls[0].slice(1, 3), [100, 75]);
  assert.equal(page.calls[0][3].steps, 7);
  assert.equal(result.cursor.clicked, true);
  assert.equal(result.cursor.window, 'main');
  assert.deepEqual(cursorForWindow(runContext, 'main'), result.cursor);
});

test('off mode still performs the real functional movement but emits no overlay telemetry', async () => {
  const page = pageWithMouse();
  const runContext = context('off', { showsCursor: true });
  const result = await moveCursorTo(page, { x: 30, y: 40, width: 20, height: 20 }, {
    context: runContext,
    action: 'hover',
  });

  assert.equal(result.handled, true);
  assert.equal(result.cursor, null);
  assert.equal(cursorForWindow(runContext, 'main'), null);
  assert.equal(page.calls[0][0], 'move');
});

test('offscreen targets fail soft so the caller can use the legacy handle click', async () => {
  const page = pageWithMouse();
  const result = await clickAt(page, { x: 900, y: 700, width: 20, height: 20 }, {
    context: context('preview'),
  });

  assert.equal(result.handled, false);
  assert.deepEqual(page.calls, []);
});

test('a page without down and up is rejected before any misleading move', async () => {
  const calls = [];
  const page = {
    viewport: () => ({ width: 800, height: 600 }),
    mouse: {
      async move(...args) {
        calls.push(args);
      },
    },
  };

  const result = await clickAt(page, { x: 10, y: 10, width: 20, height: 20 }, {
    context: context('preview'),
  });

  assert.equal(result.handled, false);
  assert.deepEqual(calls, []);
});
