'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const task = require('../../node/workflows/tasks/decision/element_exists.cjs');

test('IF element task follows success when the element exists', async () => {
  const frame = {
    waitForSelector: async () => ({
      evaluate: async (_callback, selector) => ({ selector, tag: 'button', id: 'login', text: 'Login' }),
      dispose: async () => {},
    }),
  };
  const result = await task.run({
    input: { selector: '#login', timeout_seconds: 1 },
    page: {
      frames: () => [frame],
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.elementExists, true);
});

test('IF element task follows failure when the element is absent', async () => {
  let receivedTimeout = null;
  const frame = {
    waitForSelector: async (_selector, options) => {
      receivedTimeout ??= options.timeout;

      return null;
    },
  };
  const result = await task.run({
    input: { selector: '#missing', timeout_seconds: 2 },
    page: {
      frames: () => [frame],
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'not_found');
  assert.equal(result.elementExists, false);
  assert.equal(result.branchOutcome, 'failed');
  assert.ok(receivedTimeout > 1500 && receivedTimeout <= 2000);
});
