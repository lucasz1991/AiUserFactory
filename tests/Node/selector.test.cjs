'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { parseExtendedSelector } = require('../../node/workflows/lib/selector.cjs');

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
