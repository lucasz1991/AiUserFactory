'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');

const finderPath = path.resolve(__dirname, '..', '..', 'node', 'workflows', 'tasks', 'lib', 'find_visible_element.cjs');
const finder = require(finderPath);

// Die Erkennung wird ueber den Element-Finder gestubbt, damit der Test ohne
// echten Browser laeuft. Der Stub muss vor dem Laden des Tasks greifen.
let visibleMatch = null;
let lastFinderCall = null;
finder.findFirstVisibleElement = async (page, values, timeout) => {
  lastFinderCall = { values, timeout };

  return visibleMatch;
};

const handoff = require('../../node/workflows/tasks/human/verification_handoff.cjs');
const drag = require('../../node/workflows/tasks/browser/assistance_drag_coordinates.cjs');

const SELECTOR = '[data-testid="verification"], .human-verification-modal';

function handoffContext(input = {}) {
  return {
    page: { frames: () => [] },
    input: {
      selector: SELECTOR,
      provider: 'Proton',
      browser_window: 'main',
      expires_after_minutes: 20,
      instructions: 'Bitte das Puzzle loesen.',
      ...input,
    },
    observability: { level: 'off' },
  };
}

test('verification handoff passes through when the configured selector finds nothing', async () => {
  visibleMatch = null;
  const result = await handoff.run(handoffContext());

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.captchaDetected, false);
  assert.equal(result.verificationDetected, false);
  assert.equal(result.humanIntervention, undefined);
  assert.equal(lastFinderCall.values, SELECTOR);
});

test('verification handoff pauses with a generic captcha marker carrying provider and selector', async () => {
  visibleMatch = { selector: '[data-testid="verification"]' };
  const result = await handoff.run(handoffContext());

  assert.equal(result.ok, true);
  assert.equal(result.status, 'waiting_for_human');
  assert.equal(result.captchaDetected, true);
  assert.equal(result.manualInterventionRequired, true);
  assert.equal(result.humanIntervention.type, 'captcha');
  assert.equal(result.humanIntervention.provider, 'proton');
  assert.equal(result.humanIntervention.handoffTaskKey, 'human.verification_handoff');
  assert.equal(result.humanIntervention.detectionSelector, SELECTOR);
  assert.equal(result.humanIntervention.expiresAfterMinutes, 20);
  assert.equal(result.captcha.matchedSelector, '[data-testid="verification"]');
});

test('verification-only probe reports the state without pausing again', async () => {
  visibleMatch = { selector: '.human-verification-modal' };
  const result = await handoff.run(handoffContext({ verification_only: true }));

  assert.equal(result.status, 'partial');
  assert.equal(result.captchaDetected, true);
  assert.equal(result.humanIntervention, undefined);
});

test('verification handoff refuses to run without a detection selector', async () => {
  visibleMatch = { selector: 'x' };
  const result = await handoff.run(handoffContext({ selector: '' }));

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
});

function recordingPage() {
  const calls = [];

  return {
    calls,
    viewport: () => ({ width: 1000, height: 500 }),
    mouse: {
      move: async (x, y) => calls.push(['move', Math.round(x), Math.round(y)]),
      down: async () => calls.push(['down']),
      up: async () => calls.push(['up']),
    },
  };
}

test('assistance drag presses, moves in steps to the target and releases', async () => {
  const page = recordingPage();
  const result = await drag.run({
    page,
    input: { from_x_ratio: 0.1, from_y_ratio: 0.2, to_x_ratio: 0.5, to_y_ratio: 0.2 },
    observability: { level: 'off' },
  });

  assert.equal(result.ok, true);
  assert.deepEqual(page.calls[0], ['move', 100, 100]);
  assert.deepEqual(page.calls[1], ['down']);
  assert.deepEqual(page.calls.at(-1), ['up']);
  assert.deepEqual(page.calls.at(-2), ['move', 500, 100]);
  assert.ok(page.calls.filter((call) => call[0] === 'move').length >= 12);
  assert.deepEqual(result.draggedTo, { x: 500, y: 100 });
});

test('assistance drag rejects coordinates outside the browser image', async () => {
  const page = recordingPage();
  const result = await drag.run({
    page,
    input: { from_x_ratio: 0.1, from_y_ratio: 0.2, to_x_ratio: 1.5, to_y_ratio: 0.2 },
    observability: { level: 'off' },
  });

  assert.equal(result.ok, false);
  assert.deepEqual(page.calls, []);
});
