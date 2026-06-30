'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  normalizeElementCandidates,
  parseExtendedSelector,
  splitTopLevelSelectorList,
} = require('../../node/workflows/lib/selector.cjs');

test('parses CSS-like text selectors', () => {
  assert.deepEqual(parseExtendedSelector('button:has(span):has-text("Login")'), {
    css: 'button:has(span)',
    text: 'Login',
    exact: false,
  });

  assert.deepEqual(parseExtendedSelector('button:has(span:has-text("Login"))'), {
    css: 'button',
    descendantCss: 'span',
    text: 'Login',
    exact: false,
  });

  assert.deepEqual(parseExtendedSelector('button:text-is("Login")'), {
    css: 'button',
    text: 'Login',
    exact: true,
  });

  assert.equal(parseExtendedSelector('button[type=submit]'), null);
});

test('splits selector alternatives without splitting commas in text or attributes', () => {
  assert.deepEqual(splitTopLevelSelectorList([
    'button:has-text("Login, account")',
    'a:has-text("Login")',
    '[aria-label="Back, login"]',
  ].join(', ')), [
    'button:has-text("Login, account")',
    'a:has-text("Login")',
    '[aria-label="Back, login"]',
  ]);
});

test('normalizes selector and plain-text alternatives in their configured order', () => {
  assert.deepEqual(normalizeElementCandidates([
    'button:has-text("Login") , a:has-text("Login")',
    'login, Zurück zum Login',
  ]), [
    { kind: 'selector', value: 'button:has-text("Login")', exact: false },
    { kind: 'selector', value: 'a:has-text("Login")', exact: false },
    { kind: 'text', value: 'login', exact: false },
    { kind: 'text', value: 'Zurück zum Login', exact: false },
  ]);
});
