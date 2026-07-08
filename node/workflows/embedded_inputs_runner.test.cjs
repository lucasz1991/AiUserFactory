'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const basePath = path.resolve(__dirname, '..', '..');
const runnerPath = path.join(__dirname, 'run_step.cjs');

test('embedded workflow inputs are resolved before input validation runs', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-inputs-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const runtime = {
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    workflow: {
      workflow_variables: {
        parent_subject_filter: ['Invoice'],
      },
    },
    tasks: [{
      key: 'child-validate-inputs',
      task_key: 'data.validate_inputs',
      title: 'Workflow-Eingaben pruefen',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/data/validate_inputs.cjs',
      embedded_workflow_frame_key: 'child-frame',
      embedded_workflow_inputs: {
        browser_window: { literal: 'webmail' },
        'Mail-Inbox-Liste-Scan.subject_filter': 'workflow_variables.parent_subject_filter',
        fixed_value: 'literal:test-value',
      },
      input_definitions: [
        { name: 'browser_window', required: false },
        { name: 'Mail-Inbox-Liste-Scan.subject_filter', required: true },
        { name: 'fixed_value', required: true },
      ],
    }],
  };

  fs.writeFileSync(runtimePath, JSON.stringify(runtime));
  const processResult = spawnSync(process.execPath, [runnerPath, runtimePath], {
    cwd: basePath,
    encoding: 'utf8',
    timeout: 15000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));

    assert.equal(result.ok, true);
    assert.equal(result.workflow_variables.browser_window, 'webmail');
    assert.deepEqual(result.workflow_variables['Mail-Inbox-Liste-Scan.subject_filter'], ['Invoice']);
    assert.equal(result.workflow_variables.fixed_value, 'test-value');
    assert.ok(result.events.some((event) => event.stage === 'embedded-workflow-inputs-applied'));
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('workflow variables from persisted context can be used as task input values', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-variable-values-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const echoTaskPath = path.join(directory, 'echo_input.cjs');

  fs.writeFileSync(echoTaskPath, `
'use strict';

async function run(context = {}) {
  return {
    ok: true,
    status: 'success',
    resolvedValue: context.input?.value,
    resolvedInputValue: context.input?.inputValue,
  };
}

module.exports = { key: 'test.echo_input', run };
`);

  const runtime = {
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    workflow: {
      workflow_return: '654321',
      workflow_variables: {
        custom_token: 'ABC123',
      },
    },
    tasks: [
      {
        key: 'custom-variable',
        task_key: 'test.echo_input',
        title: 'Custom variable',
        kind: 'data',
        runner: 'node',
        node_script: echoTaskPath,
        value: 'custom_token',
        input: 'custom_token',
      },
      {
        key: 'input-only-variable',
        task_key: 'test.echo_input',
        title: 'Input only variable',
        kind: 'data',
        runner: 'node',
        node_script: echoTaskPath,
        value: '',
        input: 'custom_token',
      },
      {
        key: 'workflow-return',
        task_key: 'test.echo_input',
        title: 'Workflow return',
        kind: 'data',
        runner: 'node',
        node_script: echoTaskPath,
        value: 'workflow_return',
        input: 'workflow_return',
      },
      {
        key: 'literal-value',
        task_key: 'test.echo_input',
        title: 'Literal value',
        kind: 'data',
        runner: 'node',
        node_script: echoTaskPath,
        value: 'normaler Text',
        input: 'normaler Text',
      },
    ],
  };

  fs.writeFileSync(runtimePath, JSON.stringify(runtime));
  const processResult = spawnSync(process.execPath, [runnerPath, runtimePath], {
    cwd: basePath,
    encoding: 'utf8',
    timeout: 15000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
    const tasks = Object.fromEntries(result.tasks.map((task) => [task.key, task]));

    assert.equal(result.ok, true);
    assert.equal(tasks['custom-variable'].resolvedValue, 'ABC123');
    assert.equal(tasks['custom-variable'].resolvedInputValue, 'ABC123');
    assert.equal(tasks['input-only-variable'].resolvedValue, 'ABC123');
    assert.equal(tasks['input-only-variable'].resolvedInputValue, 'ABC123');
    assert.equal(tasks['workflow-return'].resolvedValue, '654321');
    assert.equal(tasks['literal-value'].resolvedValue, 'normaler Text');
    assert.equal(result.workflow_variables.custom_token, 'ABC123');
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
