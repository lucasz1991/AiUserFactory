'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const recaptchaHandoff = require('../../node/workflows/tasks/human/recaptcha_handoff.cjs');

const basePath = path.resolve(__dirname, '..', '..');
const runnerPath = path.join(basePath, 'node', 'workflows', 'run_step.cjs');

function pageWithEvidence(evidence, frameUrl = 'https://example.test/form') {
  const frame = {
    url: () => frameUrl,
    evaluate: async () => ({
      matchedSelectors: [],
      responsePresent: false,
      anchorChecked: false,
      textSignal: false,
      ...evidence,
    }),
  };

  return {
    frames: () => [frame],
  };
}

function handoffContext(evidence, frameUrl) {
  return {
    page: pageWithEvidence(evidence, frameUrl),
    input: {
      browser_window: 'verification',
      expires_after_minutes: 20,
      instructions: 'Bitte das reCAPTCHA manuell loesen.',
    },
    observability: { level: 'off' },
  };
}

test('reCAPTCHA handoff passes through when no reCAPTCHA is present', async () => {
  const result = await recaptchaHandoff.run(handoffContext({}));

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.captchaDetected, false);
  assert.equal(result.captchaSolved, false);
  assert.equal(result.manualInterventionRequired, undefined);
  assert.equal(result.humanIntervention, undefined);
});

test('reCAPTCHA handoff emits a human-intervention marker for an unresolved challenge', async () => {
  const result = await recaptchaHandoff.run(handoffContext({
    matchedSelectors: ['#recaptcha-anchor'],
    textSignal: true,
  }, 'https://www.google.com/recaptcha/api2/anchor'));

  assert.equal(result.ok, true);
  assert.equal(result.status, 'waiting_for_human');
  assert.equal(result.captchaDetected, true);
  assert.equal(result.captchaSolved, false);
  assert.equal(result.manualInterventionRequired, true);
  assert.equal(result.manual_intervention_required, true);
  assert.equal(result.humanIntervention.type, 'captcha');
  assert.equal(result.humanIntervention.provider, 'recaptcha');
  assert.equal(result.humanIntervention.reasonCode, 'captcha_detected');
  assert.equal(result.humanIntervention.browserWindow, 'verification');
  assert.equal(result.humanIntervention.expiresAfterMinutes, 20);
  assert.match(result.humanIntervention.id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
});

test('reCAPTCHA handoff does not emit a marker for a response token or checked anchor', async () => {
  for (const solvedEvidence of [
    { responsePresent: true, matchedSelectors: ['textarea[name="g-recaptcha-response"]'] },
    { anchorChecked: true, matchedSelectors: ['#recaptcha-anchor'] },
  ]) {
    const result = await recaptchaHandoff.run(handoffContext(
      solvedEvidence,
      'https://www.google.com/recaptcha/api2/anchor',
    ));

    assert.equal(result.ok, true);
    assert.equal(result.status, 'success');
    assert.equal(result.captchaDetected, false);
    assert.equal(result.captchaSolved, true);
    assert.equal(result.manualInterventionRequired, undefined);
    assert.equal(result.humanIntervention, undefined);
  }
});

test('run_step stops after a human marker and never executes the following task', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-recaptcha-handoff-'));
  const fixturesPath = path.join(directory, 'fixtures');
  const markerScriptPath = path.join(fixturesPath, 'manual-marker.cjs');
  const followingScriptPath = path.join(fixturesPath, 'following-task.cjs');
  const sentinelPath = path.join(directory, 'following-task-ran.txt');
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');

  fs.mkdirSync(fixturesPath, { recursive: true });
  fs.writeFileSync(markerScriptPath, `'use strict';
module.exports = {
  run: async () => ({
    ok: true,
    status: 'waiting_for_human',
    statusMessage: 'reCAPTCHA wartet auf einen Administrator.',
    manualInterventionRequired: true,
    humanIntervention: {
      id: 'recaptcha-intervention-test',
      type: 'captcha',
      provider: 'recaptcha',
      reasonCode: 'captcha_detected',
      browserWindow: 'main',
      expiresAfterMinutes: 15,
    },
  }),
};
`);
  fs.writeFileSync(followingScriptPath, `'use strict';
const fs = require('node:fs');
module.exports = {
  run: async (context) => {
    fs.writeFileSync(context.input.sentinel_path, 'executed');
    return { ok: true, status: 'success' };
  },
};
`);

  fs.writeFileSync(runtimePath, JSON.stringify({
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    keepWorkflowBrowserAlive: false,
    additionalTaskScriptRoots: [fixturesPath],
    workflow: {},
    tasks: [
      {
        key: 'recaptcha-gate',
        task_key: 'test.recaptcha_marker',
        title: 'reCAPTCHA gate',
        kind: 'data',
        runner: 'node',
        node_script: markerScriptPath,
      },
      {
        key: 'must-not-run',
        task_key: 'test.following_task',
        title: 'Must not run',
        kind: 'data',
        runner: 'node',
        node_script: followingScriptPath,
        sentinel_path: sentinelPath,
      },
    ],
  }));

  const processResult = spawnSync(process.execPath, [runnerPath, runtimePath], {
    cwd: basePath,
    encoding: 'utf8',
    timeout: 15000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    assert.equal(fs.existsSync(sentinelPath), false);

    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));

    assert.equal(result.ok, true);
    assert.equal(result.status, 'waiting_for_human');
    assert.equal(result.manualInterventionRequired, true);
    assert.equal(result.completedTaskKey, 'recaptcha-gate');
    assert.equal(result.humanIntervention.type, 'captcha');
    assert.equal(result.humanIntervention.reasonCode, 'captcha_detected');
    assert.equal(result.tasks[0].key, 'recaptcha-gate');
    assert.equal(result.tasks.some((taskResult) => taskResult.key === 'must-not-run'), false);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
