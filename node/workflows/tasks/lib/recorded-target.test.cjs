'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizedRecordedPath, recordedSelectors, replayRecordedPath, resolveRecordedDom,
  resolveRecordedTarget, runRecordedClick, runRecordedFill, runRecordedHover } = require('./recorded-target.cjs');

function mockBrowser({ selectors = {}, tag = 'INPUT' } = {}) {
  const calls = []; let fillValue = ''; let covered = false;
  const element = { tagName: tag, isContentEditable: false, disabled: false, readOnly: false,
    getRootNode: () => doc, getBoundingClientRect: () => ({ x: 30, y: 40, width: 80, height: 30 }),
    getAttribute: (key) => key === 'type' ? (element.tagName === 'INPUT' ? 'text' : '') : key === 'name' ? 'target' : '', closest: () => null,
    scrollIntoView: () => calls.push('scrollIntoView'), contains: (other) => other === element,
    get value() { return fillValue; }, set value(value) { fillValue = value; }, dispatchEvent: () => {} };
  const other = { ...element, getRootNode: () => doc };
  const mapping = { '#target': [element], '[name="target"]': [element], '#missing': [], '#ambiguous': [element, other], '#wrong': [other], ...selectors };
  const doc = { querySelectorAll: (selector) => mapping[selector] || [], elementFromPoint: () => covered ? other : element };
  const handle = { element, dispose: async () => calls.push('dispose'), focus: async () => calls.push('focus'),
    boundingBox: async () => ({ x: 30, y: 40, width: 80, height: 30 }),
    evaluate: async (fn, value) => {
      if (fn.toString().includes('const prototype =')) { fillValue = ''; return; }
      return fn(element, value);
    }, type: async (value) => { fillValue += value; calls.push(['type', value]); },
    select: async (value) => { fillValue = value; calls.push(['select', value]); return [value]; } };
  handle.asElement = () => handle;
  const page = { evaluate: async (fn, ...args) => fn(...args.map((value) => value === handle ? element : value)),
    evaluateHandle: async (fn, ...args) => {
      const result = fn(...args.map((value) => value === handle ? element : value));
      return result === element ? handle : { asElement: () => null, dispose: async () => {} };
    },
    $: async (selector) => mapping[selector]?.[0] === element ? handle : null,
    viewport: () => ({ width: 800, height: 600 }), mouse: {
      move: async (x, y) => calls.push(['move', x, y]), down: async () => calls.push('down'), up: async () => calls.push('up'),
    } };
  return { page, doc, element, other, mapping, handle, calls, cover: () => { covered = true; } };
}

async function withDom(fixture, callback) {
  const previous = { document: global.document, window: global.window };
  global.document = fixture.doc;
  global.window = { getComputedStyle: (element) => ({ display: element.hidden ? 'none' : 'block', visibility: 'visible', opacity: '1' }) };
  try { return await callback(); } finally { global.document = previous.document; global.window = previous.window; }
}

function recordedInput(selector, overrides = {}) {
  return { selector, recorded_target_signature: { tag: 'input', type: 'text', name: 'target' },
    recorded_stable_selectors: recordedSelectors({ selector }).filter((item) => item.startsWith('#') || item.includes('[name=')), ...overrides };
}

test('recorded selectors keep comma-qualified CSS alternatives and forbid heuristic text fallback', () => {
  assert.deepEqual(recordedSelectors({ selector: '#target, [name="a,b"]', selectors: ['#target'] }), ['#target', '[name="a,b"]']);
  assert.throws(() => recordedSelectors({ text: 'guess this button' }), { code: 'recorded_selector_invalid' });
  assert.throws(() => recordedSelectors({ selector: 'text=guess' }), { code: 'recorded_selector_invalid' });
});

test('strict CSS resolution skips missing/ambiguous alternatives but rejects conflicting unique matches', async () => {
  const f = mockBrowser();
  await withDom(f, async () => {
    assert.equal(resolveRecordedDom(['#missing', '#ambiguous', '#target']).selector, '#target');
    assert.equal(resolveRecordedDom(['#target', '#wrong']).conflict, true);
    const found = await resolveRecordedTarget(f.page, recordedInput('#missing, #ambiguous, #target'), 0);
    assert.equal(found.handle, f.handle);
    await assert.rejects(resolveRecordedTarget(f.page, recordedInput('#target, #wrong'), 0), { code: 'recorded_selector_conflict' });
    await assert.rejects(resolveRecordedTarget(f.page, recordedInput('#missing, #ambiguous'), 0), { code: 'recorded_target_missing' });
  });
});

test('hidden duplicate before the only visible match never substitutes the first DOM match', async () => {
  const f = mockBrowser(); const hidden = { ...f.other, hidden: true };
  f.mapping['#target'] = [hidden, f.element]; f.page.$ = async () => { throw new Error('First DOM-match lookup must never be used.'); };
  await withDom(f, async () => {
    const found = await resolveRecordedTarget(f.page, recordedInput('#target'), 0);
    assert.equal(found.handle, f.handle);
  });
});

test('stable recorded alternatives may not fall back to an old structural nth-of-type position', async () => {
  const f = mockBrowser(); f.mapping['form > input:nth-of-type(1)'] = [f.element];
  await withDom(f, async () => {
    await assert.rejects(resolveRecordedTarget(f.page, recordedInput('#missing, form > input:nth-of-type(1)'), 0), { code: 'recorded_target_missing' });
    assert.equal(f.calls.includes('down'), false);
  });
});

test('changed target signature prevents an old structural path from clicking a different button', async () => {
  const f = mockBrowser({ tag: 'BUTTON' }); f.element.textContent = 'Konto loeschen';
  f.mapping['form > button:nth-of-type(1)'] = [f.element];
  await withDom(f, async () => {
    const input = recordedInput('form > button:nth-of-type(1)', { recorded_stable_selectors: [],
      recorded_target_signature: { tag: 'button', type: '', text: 'Suchen' } });
    const result = await runRecordedClick({ page: f.page, input }, 0);
    assert.equal(result.recordedTargetError, 'recorded_signature_changed'); assert.equal(f.calls.includes('down'), false);
    f.element.textContent = 'Suchen'; assert.equal((await runRecordedClick({ page: f.page, input }, 0)).ok, true);
  });
});

test('structural-only targets without semantic identity fail before mouse mutation', async () => {
  const f = mockBrowser(); f.mapping['form > input'] = [f.element];
  await withDom(f, async () => {
    const input = recordedInput('form > input', { recorded_stable_selectors: [], recorded_target_signature: { tag: 'input', type: 'text' } });
    await assert.rejects(resolveRecordedTarget(f.page, input, 0), { code: 'recorded_signature_invalid' });
    assert.equal(f.calls.some((call) => Array.isArray(call) && call[0] === 'move'), false);
  });
});

test('recheck refuses a changed element before execution and never follows it into another frame', async () => {
  const f = mockBrowser();
  await withDom(f, async () => {
    let passes = 0;
    f.page.evaluate = async (fn, ...args) => { passes += 1;
      if (passes === 2) f.mapping['#target'] = [f.other];
      return fn(...args.map((value) => value === f.handle ? f.element : value));
    };
    await assert.rejects(resolveRecordedTarget(f.page, recordedInput('#target'), 0), { code: 'recorded_target_changed' });
    assert.equal(f.calls.includes('dispose'), true);
    f.element.getRootNode = () => ({}); f.mapping['#target'] = [f.element];
    assert.equal(resolveRecordedDom(['#target']).missing, true);
  });
});

test('recorded path validates all positions before moving and caps total playback to two seconds', async () => {
  assert.throws(() => normalizedRecordedPath([{ x: 1.1, y: 0, ms: 0 }]), { code: 'recorded_path_invalid' });
  assert.throws(() => normalizedRecordedPath(Array.from({ length: 33 }, () => ({ x: 0.5, y: 0.5 }))), { code: 'recorded_path_invalid' });
  assert.throws(() => normalizedRecordedPath([{ x: 0, y: 0, ms: 10 }, { x: 1, y: 1, ms: 5 }]), { code: 'recorded_path_invalid' });
  const f = mockBrowser(); const waits = [];
  assert.equal(await replayRecordedPath(f.page, [{ x: 0, y: 0, ms: 0 }, { x: 1, y: 1, ms: 10000 }],
    { wait: async (ms) => waits.push(ms) }), 2);
  assert.deepEqual(waits, [2000]);
  assert.deepEqual(f.calls.filter((call) => Array.isArray(call)), [['move', 0, 0], ['move', 799, 599]]);
});

test('strict click sends real mouse events only after same-target and overlay rechecks', async () => {
  const f = mockBrowser();
  await withDom(f, async () => {
    const result = await runRecordedClick({ page: f.page, input: recordedInput('#target', { recorded_selector_strict: true }), observability: 'off' }, 0);
    assert.equal(result.ok, true); assert.equal(f.calls.includes('down'), true); assert.equal(f.calls.includes('up'), true);
    const blocked = mockBrowser(); blocked.cover();
    await withDom(blocked, async () => {
      const failure = await runRecordedClick({ page: blocked.page, input: recordedInput('#target') }, 0);
      assert.equal(failure.recordedTargetError, 'recorded_target_covered'); assert.equal(blocked.calls.includes('down'), false);
    });
  });
});

test('strict click detects conflicting targets introduced by hover before mouse-down', async () => {
  const f = mockBrowser();
  f.page.mouse.move = async () => { f.mapping['[name="target"]'] = [f.other]; };
  await withDom(f, async () => {
    const result = await runRecordedClick({ page: f.page, input: recordedInput('#target, [name="target"]') }, 0);
    assert.equal(result.recordedTargetError, 'recorded_selector_conflict'); assert.equal(f.calls.includes('down'), false);
  });
});

test('hover replays the recorded path, then reaches the authoritative resolved element center', async () => {
  const f = mockBrowser();
  await withDom(f, async () => {
    const context = { page: f.page, input: recordedInput('#target', { recorded_mouse_path: [
      { x: 0.1, y: 0.1, ms: 0 }, { x: 0.2, y: 0.2, ms: 150 },
    ] }), observability: 'off' };
    const result = await runRecordedHover(context, 0, { wait: async () => {} });
    assert.equal(result.ok, true); assert.equal(result.replayedMousePoints, 2);
    assert.deepEqual(f.calls.filter((call) => Array.isArray(call)).at(-1), ['move', 70, 55]);
    assert.equal(context.__workflowHeldHover.handle, f.handle); assert.equal(context.__workflowHeldHover.recordedStrict, true);
  });
});

test('strict input preserves literal whitespace, confirms value, supports selects and no generic fallback', async () => {
  const f = mockBrowser();
  await withDom(f, async () => {
    const result = await runRecordedFill({ page: f.page, input: recordedInput('#target', { value_source: 'literal' }) }, '  exact value  ', 0);
    assert.equal(result.ok, true); assert.equal(f.element.value, '  exact value  ');
    const missing = await runRecordedFill({ page: f.page, input: recordedInput('#missing') }, 'not typed', 0);
    assert.equal(missing.recordedTargetError, 'recorded_target_missing'); assert.equal(f.element.value, '  exact value  ');
    f.element.tagName = 'SELECT';
    const select = await runRecordedFill({ page: f.page, input: recordedInput('#target', {
      recorded_target_signature: { tag: 'select', type: '', name: 'target' },
    }) }, 'option-2', 0);
    assert.equal(select.ok, true); assert.equal(f.element.value, 'option-2');
  });
});
