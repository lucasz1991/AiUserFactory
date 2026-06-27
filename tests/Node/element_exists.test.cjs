'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const task = require('../../node/workflows/tasks/decision/element_exists.cjs');

test('IF element task follows success when the element exists', async () => {
  const result = await task.run({
    input: { selector: '#login', timeout_seconds: 1 },
    page: {
      waitForSelector: async () => ({
        evaluate: async (_callback, selector) => ({ selector, tag: 'button', id: 'login', text: 'Login' }),
        dispose: async () => {},
      }),
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.elementExists, true);
});

test('IF element task follows failure when the element is absent', async () => {
  const result = await task.run({
    input: { selector: '#missing', timeout_seconds: 1 },
    page: { waitForSelector: async () => null },
  });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'not_found');
  assert.equal(result.elementExists, false);
});
