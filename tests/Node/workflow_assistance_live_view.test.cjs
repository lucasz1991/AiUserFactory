'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const { FrameStore, LiveViewSession, startLiveView } = require('../../node/workflows/assistance/live_view.cjs');

const JPEG = Buffer.from([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10]);
const SECRET = 'a'.repeat(64);

function fakeCdp() {
  const sent = [];
  const listeners = {};

  return {
    sent,
    listeners,
    on: (event, handler) => { listeners[event] = handler; },
    send: async (method, params) => {
      sent.push([method, params]);
      if (method === 'Page.getLayoutMetrics') return { cssVisualViewport: { clientWidth: 1000, clientHeight: 500 } };
      return {};
    },
    detach: async () => sent.push(['detach']),
  };
}

function mouseEvents(cdp) {
  return cdp.sent
    .filter(([method]) => method === 'Input.dispatchMouseEvent')
    .map(([, params]) => [params.type, Math.round(params.x), Math.round(params.y), params.buttons]);
}

test('frame store long-poll resolves as soon as a newer frame arrives', async () => {
  const frames = new FrameStore();
  frames.push(JPEG, null);
  const started = Date.now();
  const waiting = frames.next(1, 1500);
  setTimeout(() => frames.push(JPEG, null), 50);
  await waiting;

  assert.equal(frames.sequence, 2);
  assert.ok(Date.now() - started < 1000);
});

test('drag sends press, moves with the left button held and a release at the target', async () => {
  const cdp = fakeCdp();
  const session = new LiveViewSession({ page: {}, cdp, frames: new FrameStore() });

  await session.input({ type: 'down', x: 0.1, y: 0.2 });
  await session.input({ type: 'move', x: 0.3, y: 0.2 });
  await session.input({ type: 'move', x: 0.5, y: 0.2 });
  await session.input({ type: 'up', x: 0.5, y: 0.2 });

  assert.deepEqual(mouseEvents(cdp), [
    ['mouseMoved', 100, 100, 0],
    ['mousePressed', 100, 100, 1],
    ['mouseMoved', 300, 100, 1],
    ['mouseMoved', 500, 100, 1],
    ['mouseMoved', 500, 100, 1],
    ['mouseReleased', 500, 100, 0],
  ]);
});

test('live input rejects positions outside the image and unknown keys', async () => {
  const session = new LiveViewSession({ page: {}, cdp: fakeCdp(), frames: new FrameStore() });

  await assert.rejects(() => session.input({ type: 'click', x: 1.2, y: 0.5 }), /ausserhalb/);
  await assert.rejects(() => session.input({ type: 'key', key: 'F12' }), /nicht erlaubt/);
  await assert.rejects(() => session.input({ type: 'eval' }), /nicht erlaubt/);
});

test('daemon serves authenticated frames and inputs and only disconnects the browser on stop', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'assistance-live-'));
  const configPath = path.join(directory, 'config.json');
  fs.writeFileSync(configPath, JSON.stringify({ secret: SECRET, wsEndpoint: 'ws://127.0.0.1:9/devtools/browser/x', targetId: 'target-1' }));
  const cdp = fakeCdp();
  const browserEvents = [];
  const page = {
    target: () => ({ _targetId: 'target-1' }),
    url: () => 'https://mail.proton.me/',
    createCDPSession: async () => cdp,
    screenshot: async () => JPEG,
  };
  const browser = {
    pages: async () => [page],
    on: () => {},
    disconnect: () => browserEvents.push('disconnect'),
    close: () => browserEvents.push('close'),
  };
  const live = await startLiveView(configPath, {
    puppeteer: { connect: async (options) => { browserEvents.push(['connect', options.browserWSEndpoint]); return browser; } },
  });
  const base = `http://127.0.0.1:${live.port}`;
  const auth = { Authorization: `Bearer ${SECRET}` };

  try {
    assert.equal((await fetch(`${base}/frame`)).status, 401);

    const frame = await fetch(`${base}/frame`, { headers: auth });
    assert.equal(frame.status, 200);
    assert.equal(frame.headers.get('x-frame-sequence'), '1');
    assert.deepEqual(Buffer.from(await frame.arrayBuffer()), JPEG);

    const input = await fetch(`${base}/input`, {
      method: 'POST',
      headers: { ...auth, 'Content-Type': 'application/json' },
      body: JSON.stringify({ type: 'click', x: 0.5, y: 0.5 }),
    });
    assert.equal(input.status, 200);
    assert.deepEqual(mouseEvents(cdp).map((event) => event[0]), ['mouseMoved', 'mousePressed', 'mouseReleased']);
    assert.ok(cdp.sent.some(([method]) => method === 'Page.startScreencast'));
  } finally {
    await live.cleanup();
    fs.rmSync(directory, { recursive: true, force: true });
  }

  assert.ok(browserEvents.includes('disconnect'));
  assert.ok(! browserEvents.includes('close'));
  assert.ok(cdp.sent.some(([method]) => method === 'Page.stopScreencast'));
});
