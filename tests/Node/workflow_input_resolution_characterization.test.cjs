'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const projectRoot = path.resolve(__dirname, '../..');
const workflowRunnerPath = path.join(projectRoot, 'node', 'workflows', 'run_step.cjs');

function executeInputWorkflow(workflow, tasks) {
  const runDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-input-resolution-'));
  const resultPath = path.join(runDirectory, 'result.json');
  const statusPath = path.join(runDirectory, 'status.json');
  const runtimePath = path.join(runDirectory, 'runtime.json');
  const fixturePath = path.join(runDirectory, 'capture-input.cjs');

  try {
    fs.writeFileSync(fixturePath, `'use strict';
module.exports = {
  async run(context = {}) {
    return {
      ok: true,
      status: 'success',
      capturedInput: context.input,
    };
  },
};
`);
    fs.writeFileSync(runtimePath, JSON.stringify({
      resultPath,
      statusPath,
      runDirectory,
      livePreviewEnabled: false,
      additionalTaskScriptRoots: [runDirectory],
      workflow,
      tasks: tasks.map((task) => ({
        title: task.key,
        task_key: 'test.capture_input',
        kind: 'data',
        runner: 'node',
        node_script: fixturePath,
        ...task,
      })),
    }));

    const processResult = spawnSync(process.execPath, [workflowRunnerPath, runtimePath], {
      cwd: projectRoot,
      encoding: 'utf8',
      timeout: 15000,
    });

    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    assert.equal(fs.existsSync(resultPath), true, 'Runner hat keine result.json geschrieben.');

    return JSON.parse(fs.readFileSync(resultPath, 'utf8'));
  } finally {
    fs.rmSync(runDirectory, { recursive: true, force: true });
  }
}

test('runner preserves the fixed, literal, variable, fallback, and legacy input-source contract', () => {
  const result = executeInputWorkflow({
    person: {
      email: 'person@example.test',
    },
    workflow_variables: {
      custom_token: 'ABC123',
      nested: { code: 'N-7' },
      'person.email': 'must-not-shadow-person-context',
    },
  }, [
    {
      key: 'literal-source',
      value: 'custom_token',
      value_source: 'literal',
    },
    {
      key: 'workflow-variable-source',
      value: 'ignored',
      value_source: 'workflow_variable',
      workflow_variable: 'nested.code',
    },
    {
      key: 'workflow-variable-fallback',
      value_source: 'workflow_variable',
      workflow_variable: 'missing.value',
      value_fallback: 'safe-fallback',
    },
    {
      key: 'fixed-context-source',
      value: 'person.email',
      value_source: 'fixed',
    },
    {
      key: 'legacy-auto-source',
      value: 'custom_token',
      browser_window_name: 'Checkout Dialog',
      selector: '#checkout',
    },
  ]);
  const tasks = Object.fromEntries(result.tasks.map((task) => [task.key, task]));

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.slice(0, 5).map((task) => task.key), [
    'literal-source',
    'workflow-variable-source',
    'workflow-variable-fallback',
    'fixed-context-source',
    'legacy-auto-source',
  ]);
  assert.equal(
    result.tasks.some((task) => task.key === '__automatic-browser-session-save'),
    true,
  );

  assert.equal(tasks['literal-source'].capturedInput.value, 'custom_token');
  assert.equal(tasks['literal-source'].capturedInput.valueSource, 'literal');
  assert.equal(tasks['literal-source'].capturedInput.valueResolutionStatus, 'literal');

  assert.equal(tasks['workflow-variable-source'].capturedInput.value, 'N-7');
  assert.equal(tasks['workflow-variable-source'].capturedInput.workflowVariable, 'nested.code');
  assert.equal(tasks['workflow-variable-source'].capturedInput.valueResolutionStatus, 'variable_resolved');

  assert.equal(tasks['workflow-variable-fallback'].capturedInput.value, 'safe-fallback');
  assert.equal(tasks['workflow-variable-fallback'].capturedInput.valueFallbackUsed, true);
  assert.equal(tasks['workflow-variable-fallback'].capturedInput.valueResolutionStatus, 'fallback_used');

  assert.equal(tasks['fixed-context-source'].capturedInput.value, 'person@example.test');
  assert.equal(tasks['fixed-context-source'].capturedInput.contextValuePath, 'person.email');
  assert.equal(tasks['fixed-context-source'].capturedInput.valueResolutionStatus, 'fixed_context_resolved');

  assert.equal(tasks['legacy-auto-source'].capturedInput.value, 'ABC123');
  assert.equal(tasks['legacy-auto-source'].capturedInput.valueSource, 'legacy_auto');
  assert.equal(tasks['legacy-auto-source'].capturedInput.browserWindowName, 'checkout-dialog');
  assert.equal(tasks['legacy-auto-source'].capturedInput.selector, '#checkout');
  assert.equal(tasks['legacy-auto-source'].capturedInput.elementSelector, '#checkout');
  assert.equal(tasks['legacy-auto-source'].capturedInput.inputSelector, '#checkout');
});

test('runner resolves verification-mailbox context without changing the task result contract', () => {
  const result = executeInputWorkflow({
    person: {
      email: 'person@example.test',
    },
    verification_mailbox: {
      email: 'verify@example.test',
      username: 'verify-user',
      password: 'verify-password',
      provider: 'example',
    },
    workflow_variables: {},
  }, [{
    key: 'verification-mailbox-source',
    value: 'person.email',
    value_source: 'fixed',
    script_person_source: 'verification',
  }]);
  const task = result.tasks[0];

  assert.equal(result.ok, true);
  assert.equal(task.key, 'verification-mailbox-source');
  assert.equal(task.status, 'success');
  assert.equal(task.capturedInput.value, 'verify@example.test');
  assert.equal(task.capturedInput.mailboxSource, 'verification');
  assert.equal(task.capturedInput.scriptPersonSource, 'verification');
  assert.equal(task.capturedInput.valueSource, 'fixed');
  assert.equal(task.capturedInput.valueResolutionStatus, 'fixed_context_resolved');
});
