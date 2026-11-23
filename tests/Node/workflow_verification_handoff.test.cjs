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

test('run_step keeps handoff task and detection selector in the marker it reports to PHP', () => {
  const fs = require('node:fs');
  const os = require('node:os');
  const { spawnSync } = require('node:child_process');
  const basePath = path.resolve(__dirname, '..', '..');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-verification-handoff-'));
  const fixturesPath = path.join(directory, 'fixtures');
  const gateScriptPath = path.join(fixturesPath, 'verification-gate.cjs');
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const libPath = JSON.stringify(finderPath);
  const taskPath = JSON.stringify(path.join(basePath, 'node', 'workflows', 'tasks', 'human', 'verification_handoff.cjs'));

  fs.mkdirSync(fixturesPath, { recursive: true });
  // Echte Handoff-Task, nur Element-Finder und Seite sind gestubbt.
  fs.writeFileSync(gateScriptPath, `'use strict';
const finder = require(${libPath});
finder.findFirstVisibleElement = async () => ({ selector: '[data-testid="verification"]' });
const handoff = require(${taskPath});
module.exports = { run: (context) => handoff.run({ ...context, page: { frames: () => [] } }) };
`);
  fs.writeFileSync(runtimePath, JSON.stringify({
    resultPath,
    statusPath: path.join(directory, 'status.json'),
    runDirectory: directory,
    livePreviewEnabled: false,
    keepWorkflowBrowserAlive: false,
    additionalTaskScriptRoots: [fixturesPath],
    workflow: {},
    tasks: [{
      key: 'verifizierung-an-admin',
      task_key: 'test.verification_gate',
      title: 'Verifizierung',
      kind: 'data',
      runner: 'node',
      node_script: gateScriptPath,
      selector: SELECTOR,
      provider: 'proton',
    }],
  }));

  const processResult = spawnSync(process.execPath, [path.join(basePath, 'node', 'workflows', 'run_step.cjs'), runtimePath], {
    cwd: basePath,
    encoding: 'utf8',
    timeout: 20000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));

    assert.equal(result.status, 'waiting_for_human');
    assert.equal(result.humanIntervention.type, 'captcha');
    assert.equal(result.humanIntervention.provider, 'proton');
    assert.equal(result.humanIntervention.evidence.handoffTaskKey, 'human.verification_handoff');
    assert.equal(result.humanIntervention.evidence.detectionSelector, SELECTOR);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
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
