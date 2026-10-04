'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { inspectDom, inspectPage, splitSelectorList } = require('../../node/recorder/selectors.cjs');

function fakeDom({ id = 'email', name = 'email', type = 'email', sensitiveLabel = '', ambiguousName = false } = {}) {
  const attrs = { name, type, 'data-testid': 'login-email', 'aria-label': sensitiveLabel || 'E-Mail' };
  const element = { id, tagName: 'INPUT', nodeType: 1, parentElement: null,
    labels: [], textContent: '', disabled: false, readOnly: false, isContentEditable: false,
    getAttribute: (key) => attrs[key] || null,
    getBoundingClientRect: () => ({ x: 10, y: 20, width: 120, height: 30 }),
    closest: () => element, matches: () => true,
    get value() { throw new Error('Inspect must never read field values.'); },
    getRootNode: () => doc };
  const doc = { elementFromPoint: () => element, querySelectorAll: (selector) => {
    if (selector === '#different') return [{}];
    if (ambiguousName && selector.includes('[name=')) return [element, {}];
    if (selector.startsWith('#') || selector.startsWith('input')) return [element];
    return [];
  } };
  return { doc, element };
}

test('CSS comma splitting preserves quoted, escaped and nested comma syntax', () => {
  assert.deepEqual(splitSelectorList('#field, input[name="last,first"], :is(input, textarea)'),
    ['#field', 'input[name="last,first"]', ':is(input, textarea)']);
  assert.deepEqual(splitSelectorList('#a\\,b, #field'), ['#a\\,b', '#field']);
  for (const value of ['', '#field,', '[name="unfinished]', '#field, , #other']) assert.throws(() => splitSelectorList(value));
});

test('selector alternatives are unique and contain no live field value', () => {
  const original = global.document; const f = fakeDom(); global.document = f.doc;
  try {
    const result = inspectDom({ x: 20, y: 30 });
    assert.equal(result.selector.startsWith('#email, '), true);
    assert.equal(result.selectors.length >= 3, true);
    assert.equal(result.selectors.every((selector) => f.doc.querySelectorAll(selector).length === 1), true);
    assert.equal(result.editable, true); assert.equal(result.sensitive, false);
    assert.deepEqual(result.target_signature, { tag: 'input', type: 'email', name: 'email', test_id: 'login-email', aria_label: 'E-Mail' });
    assert.equal(result.stable_selectors.every((selector) => result.selectors.includes(selector)), true);
    assert.equal(JSON.stringify(result).includes('value'), false);
  } finally { global.document = original; }
});

test('ambiguous candidates are discarded and commas are escaped rather than treated as alternate IDs', () => {
  const original = global.document; const f = fakeDom({ id: 'field,one', ambiguousName: true }); global.document = f.doc;
  try {
    const result = inspectDom({ x: 20, y: 30 });
    assert.equal(result.selectors.some((selector) => selector.includes('[name=')), false);
    assert.equal(result.selectors[0], '#field\\2c one');
    assert.equal(splitSelectorList(result.selector)[0], '#field\\2c one');
  } finally { global.document = original; }
});

test('password metadata is masked and sensitive placeholders are excluded from CSS alternatives', () => {
  const original = global.document; const f = fakeDom({ type: 'password', sensitiveLabel: 'Password login' }); global.document = f.doc;
  try {
    const result = inspectDom({ x: 20, y: 30 });
    assert.equal(result.sensitive, true); assert.equal(result.label, 'Sensibles Eingabefeld');
    assert.equal(result.selectors.some((selector) => selector.includes('aria-label')), false);
    assert.equal(result.target_signature.aria_label, undefined); assert.equal(result.target_signature.text, undefined);
  } finally { global.document = original; }
});

test('structural capture without semantic identity is refused but a named button carries its signature', () => {
  const original = global.document; const f = fakeDom({ id: '' }); global.document = f.doc;
  f.element.getAttribute = () => null;
  try {
    assert.equal(inspectDom({ x: 20, y: 30 }).error.code, 'missing_target_signature');
    f.element.tagName = 'BUTTON'; f.element.textContent = 'Suchen';
    f.doc.querySelectorAll = (selector) => selector === 'button' ? [f.element] : [];
    const recorded = inspectDom({ x: 20, y: 30 });
    assert.deepEqual(recorded.stable_selectors, []);
    assert.deepEqual(recorded.target_signature, { tag: 'button', type: '', text: 'Suchen' });
  } finally { global.document = original; }
});

test('targeted selector actions reject alternatives that resolve to a different element', async () => {
  const original = global.document; const f = fakeDom(); global.document = f.doc;
  try {
    const page = { evaluate: async (fn, request) => fn(request) };
    await assert.rejects(inspectPage(page, { selector: '#email, #different' }), { code: 'ambiguous_selector' });
    assert.equal((await inspectPage(page, { selector: '#email, input[name="email"]' })).selector.startsWith('#email'), true);
  } finally { global.document = original; }
});

test('frame/shadow/hidden targets failclosed instead of inventing a coordinate selector', () => {
  const original = global.document; const f = fakeDom(); global.document = f.doc;
  try {
    f.element.tagName = 'IFRAME'; assert.equal(inspectDom({ x: 20, y: 30 }).error.code, 'unsupported_scope');
    f.element.tagName = 'INPUT'; f.element.shadowRoot = {};
    assert.equal(inspectDom({ x: 20, y: 30 }).error.code, 'unsupported_scope');
    f.element.shadowRoot = null; f.element.getBoundingClientRect = () => ({ width: 0, height: 0 });
    assert.equal(inspectDom({ x: 20, y: 30 }).error.code, 'hidden_element');
  } finally { global.document = original; }
});
