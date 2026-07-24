'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  buildFrameTree,
  captureDomTree,
  writeJsonAtomic,
} = require('../../node/workflows/tasks/lib/dom_tree.cjs');

function record(index, overrides = {}) {
  return {
    index,
    parentIndex: index === 0 ? null : index - 1,
    depth: index,
    path: String(index),
    tag: 'div',
    id: `node-${index}`,
    classes: ['item'],
    text: 'visible label',
    selector: `#node-${index}`,
    role: '',
    type: '',
    name: '',
    ariaLabel: '',
    rect: { x: index, y: index * 2, width: 20, height: 10 },
    visible: true,
    enabled: true,
    inShadowDom: false,
    ...overrides,
  };
}

function fakeFrame({
  id,
  parent = null,
  records = [record(0)],
  viewport = { width: 200, height: 100, deviceScaleFactor: 1 },
  box = null,
  url = 'https://example.test/frame',
}) {
  return {
    _id: id,
    parentFrame: () => parent,
    name: () => id || 'main',
    url: () => url,
    async evaluate(_callback, options) {
      if (options && Object.prototype.hasOwnProperty.call(options, 'nodeLimit')) {
        return {
          records,
          viewport,
          depthTruncated: false,
          nodeLimitReached: false,
        };
      }

      return {};
    },
    async frameElement() {
      return {
        async boundingBox() {
          return box;
        },
        async evaluate() {
          return {
            offsetWidth: box?.width || 0,
            offsetHeight: box?.height || 0,
            clientLeft: 2,
            clientTop: 2,
            clientWidth: Math.max(0, (box?.width || 0) - 4),
            clientHeight: Math.max(0, (box?.height || 0) - 4),
            paddingLeft: 0,
            paddingRight: 0,
            paddingTop: 0,
            paddingBottom: 0,
          };
        },
        async dispose() {},
      };
    },
  };
}

test('flat tree preserves hierarchy while enforcing depth and node limits', () => {
  const tree = buildFrameTree(
    Array.from({ length: 10 }, (_, index) => record(index)),
    { maxNodes: 5, maxDepth: 3, windowKey: 'main', frameRef: 'main' },
  );

  assert.equal(tree.nodeCount, 4);
  assert.equal(tree.nodes[0].parentRef, null);
  assert.equal(tree.nodes[1].parentRef, tree.nodes[0].nodeRef);
  assert.equal(tree.truncated.depth, true);
  assert.equal(tree.truncated.nodes, true);
});

test('iframe rects include border-aware main-frame offsets and content scaling', async () => {
  const main = fakeFrame({
    id: 'main-runtime',
    records: [record(0, { rect: { x: 0, y: 0, width: 800, height: 600 } })],
    viewport: { width: 800, height: 600, deviceScaleFactor: 1 },
  });
  const child = fakeFrame({
    id: 'child-runtime',
    parent: main,
    records: [record(0, { rect: { x: 10, y: 5, width: 20, height: 10 } })],
    viewport: { width: 200, height: 100, deviceScaleFactor: 1 },
    box: { x: 100, y: 50, width: 404, height: 204 },
  });
  const page = {
    frames: () => [main, child],
    mainFrame: () => main,
  };
  const tree = await captureDomTree(page, { windowKey: 'main' });
  const childFrame = tree.frames.find((frame) => frame.frameRef === 'frame-child-runtime');
  const childRect = childFrame.nodes[0].rect;

  assert.equal(tree.frames[0].frameRef, 'main');
  assert.equal(childFrame.parentFrameRef, 'main');
  assert.equal(childRect.x, 122);
  assert.equal(childRect.y, 62);
  assert.equal(childRect.width, 40);
  assert.equal(childRect.height, 20);
});

test('complete multi-frame payload remains inside the configured byte ceiling', async () => {
  const main = fakeFrame({
    id: 'main-runtime',
    records: Array.from({ length: 100 }, (_, index) => record(index, {
      depth: Math.min(index, 4),
      text: 'x'.repeat(160),
      selector: `[data-long="${'y'.repeat(300)}-${index}"]`,
    })),
    url: `https://example.test/${'a'.repeat(1800)}`,
  });
  const child = fakeFrame({
    id: 'child-runtime',
    parent: main,
    records: Array.from({ length: 100 }, (_, index) => record(index, {
      depth: Math.min(index, 4),
      text: 'z'.repeat(160),
      selector: `[data-long="${'q'.repeat(300)}-${index}"]`,
    })),
    box: { x: 0, y: 0, width: 204, height: 104 },
    url: `https://example.test/${'b'.repeat(1800)}`,
  });
  const page = {
    frames: () => [main, child],
    mainFrame: () => main,
  };
  const tree = await captureDomTree(page, {
    maxBytes: 16 * 1024,
    maxNodes: 200,
    windowKey: 'main',
  });
  const actualBytes = Buffer.byteLength(JSON.stringify(tree), 'utf8');

  assert.ok(actualBytes <= 16 * 1024, `${actualBytes} exceeds the configured limit`);
  assert.equal(tree.byteSize, actualBytes);
  assert.equal(tree.truncated.bytes, true);
});

test('atomic JSON writer safely replaces an existing Windows-readable file', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-dom-tree-write-'));
  const file = path.join(directory, 'live-dom-tree.json');

  try {
    writeJsonAtomic(file, { version: 1 });
    writeJsonAtomic(file, { version: 2 });

    assert.deepEqual(JSON.parse(fs.readFileSync(file, 'utf8')), { version: 2 });
    assert.deepEqual(fs.readdirSync(directory), ['live-dom-tree.json']);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
