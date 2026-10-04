'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { RecorderSession } = require('../../node/recorder/session.cjs');

function fixture(overrides = {}) {
  const calls = []; let now = 100000; let url = 'https://example.test/'; let enteredValue = '';
  const target = { selector: '#field, input[name="field"]', selectors: ['#field', 'input[name="field"]'],
    stable_selectors: ['#field', 'input[name="field"]'], target_signature: { tag: overrides.tag ?? 'input', type: overrides.input_type ?? 'text', name: 'field' },
    label: 'Eingabe', input_type: 'text', tag: 'input', sensitive: false, editable: true, interactive: true,
    scope: 'main', x: 50, y: 50, ...overrides };
  const handle = { focus: async () => calls.push(['focus']), evaluate: async (fn, expected) => {
    calls.push(['handle-evaluate']);
    if (fn.toString().includes('const prototype =')) enteredValue = '';
    if (fn.toString().includes('=== expected')) return enteredValue === expected;
    return fn.toString().includes('elementFromPoint') ? true : undefined;
  },
    type: async (value) => { enteredValue = value; calls.push(['type', value]); }, dispose: async () => calls.push(['dispose']),
    select: async (value) => { calls.push(['select', target.selectors[0], value]); return [value]; } };
  const page = { url: () => url, title: async () => 'Testseite',
    goto: async (value) => { calls.push(['goto', value]); url = value; },
    mouse: { down: async (...args) => calls.push(['click', ...args]), up: async (...args) => calls.push(['up', ...args]), move: async (...args) => calls.push(['move', ...args]),
      wheel: async (...args) => calls.push(['wheel', ...args]) },
    keyboard: { press: async (key) => calls.push(['key', key]) },
    evaluate: async (fn, value) => { calls.push(['evaluate', fn.name, value]);
      return fn.name === 'resolveRecordedDom' ? { ...target, selector: target.selectors[0], type: target.input_type } : target;
    },
    evaluateHandle: async () => ({ asElement: () => handle, dispose: async () => {} }),
    $: async () => handle, select: async (...args) => { calls.push(['select', ...args]); return [args[1]]; },
    screenshot: async () => Buffer.from('jpeg') };
  return { page, calls, target, session: new RecorderSession({ page, clock: () => now,
    resolveTarget: async (value) => ({ url: new URL(value) }) }), advance: (ms) => { now += ms; } };
}

test('real actions produce monotonic stable catalog-convertible capture evidence', async () => {
  const f = fixture();
  await f.session.command({ type: 'navigate', url: 'https://example.test/form' });
  await f.session.command({ type: 'click', x: 50, y: 50 });
  await f.session.command({ type: 'key', key: 'Tab' });
  const state = await f.session.snapshot();
  assert.deepEqual(state.events.map((event) => [event.id, event.sequence, event.type]),
    [['event-1', 1, 'navigate'], ['event-2', 2, 'click'], ['event-3', 3, 'key']]);
  assert.deepEqual(state.events[1].selectors, ['#field', 'input[name="field"]']);
  assert.equal(f.calls.some((call) => call[0] === 'click'), true);
});

test('literal fields are filled, variable paths are never typed as visible literal text', async () => {
  const f = fixture();
  await f.session.command({ type: 'fill', selector: '#field', source: 'literal', value: 'Suchbegriff' });
  assert.equal(f.calls.some((call) => call[0] === 'type' && call[1] === 'Suchbegriff'), true);
  await f.session.command({ type: 'fill', selector: '#field', source: 'fixed', value: 'person.email' });
  assert.equal(f.calls.some((call) => call[0] === 'type' && call[1] === 'person.email'), false);
  assert.equal(f.session.events.at(-1).value_source, 'fixed');
});

test('sensitive preview values remain ephemeral and literal/raw keyboard capture is refused', async () => {
  const f = fixture({ sensitive: true, input_type: 'password' });
  await assert.rejects(f.session.command({ type: 'fill', selector: '#field', source: 'literal', value: 'secret' }), { code: 'sensitive_literal' });
  await assert.rejects(f.session.command({ type: 'key', key: 's' }), { code: 'invalid_key' });
  await f.session.command({ type: 'fill', selector: '#field', source: 'workflow_variable',
    workflow_variable: 'workflow_inputs.password', previewValue: 'synthetic-secret-sample' });
  assert.equal(f.calls.some((call) => call[0] === 'type' && call[1] === 'synthetic-secret-sample'), true);
  const state = await f.session.snapshot();
  assert.equal(JSON.stringify(state).includes('synthetic-secret-sample'), false);
  assert.equal(state.events[0].value, undefined); assert.equal(state.events[0].workflow_variable, 'workflow_inputs.password');
});

test('native select controls capture the same explicit input binding', async () => {
  const f = fixture({ tag: 'select', input_type: '' });
  await f.session.command({ type: 'fill', selector: '#field', source: 'literal', value: 'option-2' });
  assert.deepEqual(f.calls.find((call) => call[0] === 'select'), ['select', '#field', 'option-2']);
  assert.equal(f.session.events[0].value, 'option-2');
});

test('meaningful pointer movement coalesces into bounded normalized hover path', async () => {
  const f = fixture();
  for (let index = 0; index < 50; index += 1) {
    f.advance(10); await f.session.command({ type: 'move', x: 50 + index, y: 50 });
  }
  assert.equal(f.session.events.length, 0);
  f.advance(250); const state = await f.session.snapshot();
  assert.equal(state.events.length, 1); assert.equal(state.events[0].type, 'hover');
  assert.equal(state.events[0].mouse_path.length, 32);
  assert.equal(state.events[0].mouse_path.every(({ x, y, ms }) => x >= 0 && x <= 1 && y >= 0 && y <= 1 && ms >= 0), true);
  assert.equal(f.calls.filter((call) => call[0] === 'move').length, 50);
});

test('tiny fleeting movement and non-interactive page hover do not inflate task count', async () => {
  const f = fixture({ interactive: false });
  await f.session.command({ type: 'move', x: 50, y: 50 }); f.advance(150);
  await f.session.command({ type: 'move', x: 55, y: 50 }); f.advance(250);
  assert.equal((await f.session.snapshot()).events.length, 0);
});

test('a single purposeful mouse move followed by dwell records a replayable hover', async () => {
  const f = fixture(); await f.session.command({ type: 'move', x: 80, y: 60 });
  f.advance(250); const state = await f.session.snapshot();
  assert.equal(state.events[0].type, 'hover'); assert.equal(state.events[0].mouse_path.length, 1);
});

test('known sensitive samples are also removed from echoed title/URL without persisting the sample', async () => {
  const f = fixture({ sensitive: true, input_type: 'password' });
  await f.session.command({ type: 'fill', selector: '#field', source: 'workflow_variable',
    workflow_variable: 'password', previewValue: 'synthetic-secret-sample' });
  f.page.url = () => 'https://example.test/?password=synthetic-secret-sample';
  f.page.title = async () => 'Echo synthetic-secret-sample';
  assert.equal(JSON.stringify(await f.session.snapshot()).includes('synthetic-secret-sample'), false);
});

test('published scroll evidence stays immutable and retains chronological directions', async () => {
  const f = fixture();
  await f.session.command({ type: 'scroll', deltaY: 120 });
  const firstPublished = JSON.stringify(f.session.events[0]);
  f.advance(100); await f.session.command({ type: 'scroll', deltaY: 100 });
  f.advance(100); await f.session.command({ type: 'scroll', deltaY: -80 });
  assert.deepEqual(f.session.events.map(({ type, direction, pixels }) => ({ type, direction, pixels })),
    [{ type: 'scroll', direction: 'down', pixels: 120 }, { type: 'scroll', direction: 'down', pixels: 100 },
      { type: 'scroll', direction: 'up', pixels: 80 }]);
  assert.equal(JSON.stringify(f.session.events[0]), firstPublished);
});

test('paused recording forbids unrecorded mutations, inspection stays available, stop is terminal', async () => {
  const f = fixture();
  await f.session.command({ type: 'pause' });
  await assert.rejects(f.session.command({ type: 'click', x: 50, y: 50 }), { code: 'session_paused' });
  await f.session.command({ type: 'inspect', x: 50, y: 50 });
  assert.equal(f.session.events.length, 0);
  await f.session.command({ type: 'resume' }); await f.session.command({ type: 'key', key: 'Enter' });
  await f.session.command({ type: 'stop' });
  await assert.rejects(f.session.command({ type: 'resume' }), { code: 'session_stopped' });
});

test('event bounds failclosed before further mouse mutations', async () => {
  const f = fixture(); f.session.maxEvents = 1;
  await f.session.command({ type: 'key', key: 'Tab' });
  await assert.rejects(f.session.command({ type: 'click', x: 50, y: 50 }), { code: 'event_limit' });
  assert.equal(f.calls.some((call) => call[0] === 'click'), false); assert.equal(f.session.state, 'paused');
});

test('a pending hover exhausting event capacity blocks the following click before mutation', async () => {
  const f = fixture(); f.session.maxEvents = 1;
  await f.session.command({ type: 'move', x: 80, y: 60 }); f.advance(250);
  await assert.rejects(f.session.command({ type: 'click', x: 80, y: 60 }), { code: 'event_limit' });
  assert.equal(f.session.events[0].type, 'hover'); assert.equal(f.calls.some((call) => call[0] === 'click'), false);
  assert.equal(f.session.state, 'paused');
});

test('capture rechecks exact target identity after mouse movement before pressing', async () => {
  const f = fixture(); const original = f.page.evaluate;
  let moved = false; f.page.mouse.move = async () => { moved = true; };
  f.page.evaluate = async (fn, ...args) => fn.name === 'resolveRecordedDom' && moved ? { changed: true } : original(fn, ...args);
  await assert.rejects(f.session.command({ type: 'click', x: 50, y: 50 }), { code: 'recorded_target_changed' });
  assert.equal(f.calls.some((call) => call[0] === 'click'), false); assert.equal(f.session.events.length, 0);
});

test('known sensitive samples in target signature metadata are rejected rather than saved', async () => {
  const f = fixture(); f.session.privateSamples.add('private-sample');
  f.target.target_signature = { tag: 'input', type: 'text', name: 'private-sample' };
  await assert.rejects(f.session.command({ type: 'inspect', x: 50, y: 50 }), { code: 'sensitive_selector' });
  assert.equal(f.session.events.length, 0);
});

test('frame is JPEG and always removes masks even after screenshot errors', async () => {
  const f = fixture();
  assert.equal((await f.session.frame()).toString(), 'jpeg');
  assert.equal(f.calls.filter((call) => call[0] === 'evaluate').length, 2);
  f.page.screenshot = async () => { throw new Error('capture failed'); };
  await assert.rejects(f.session.frame());
  assert.equal(f.calls.filter((call) => call[0] === 'evaluate').length, 4);
});
