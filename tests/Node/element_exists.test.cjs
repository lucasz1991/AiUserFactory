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
  const receivedTimeouts = [];
  const frame = {
    waitForSelector: async (_selector, options) => {
      receivedTimeouts.push(options.timeout);

      return null;
    },
  };
  const startedAt = Date.now();
  const result = await task.run({
    input: { selector: '#missing', timeout_seconds: 2 },
    page: {
      frames: () => [frame],
    },
  });
  const elapsedMs = Date.now() - startedAt;

  assert.equal(result.ok, true);
  assert.equal(result.status, 'not_found');
  assert.equal(result.elementExists, false);
  assert.equal(result.branchOutcome, 'failed');
  assert.ok(receivedTimeouts.length > 1);
  assert.ok(receivedTimeouts.every((timeout) => timeout > 0 && timeout <= 100));
  assert.ok(elapsedMs >= 1500 && elapsedMs <= 3500);
});
