'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const basePath = path.resolve(__dirname, '..', '..');
const runnerPath = path.join(__dirname, 'run_step.cjs');
const returnScript = 'node/workflows/tasks/data/workflow_return.cjs';

function returnTask(key, value, frameKey = null) {
  return {
    key,
    task_key: 'data.workflow_return',
    title: key,
    kind: 'data',
    runner: 'node',
    node_script: returnScript,
    value,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
  };
}

function executeEmbeddedWorkflow(workflowReturn) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-boundary-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const frameKey = 'embedded-frame';
  const runtime = {
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    tasks: [
      returnTask('embedded-return', workflowReturn, frameKey),
      {
        key: 'embedded-boundary',
        task_key: 'workflow.boundary',
        title: 'Embedded workflow',
        kind: 'workflow',
        runner: 'workflow-boundary',
        parent_task_key: 'embedded-workflow',
        route_source_task_key: 'embedded-workflow',
        embedded_workflow_name: 'Embedded workflow',
        embedded_workflow_frame_key: frameKey,
        next: {
          type: 'card',
          card_key: 'success-target',
        },
      },
      returnTask('must-be-skipped', true),
      returnTask('success-target', true),
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

    return JSON.parse(fs.readFileSync(resultPath, 'utf8'));
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

test('embedded workflow true return follows the workflow task success route', () => {
  const result = executeEmbeddedWorkflow(true);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'embedded-return',
    'embedded-boundary',
    'success-target',
  ]);
});

test('embedded workflow false return fails at the workflow boundary', () => {
  const result = executeEmbeddedWorkflow(false);

  assert.equal(result.ok, false);
  assert.equal(result.workflow_return, false);
  assert.equal(result.workflow_return_ok, false);
  assert.equal(result.failedTaskKey, 'embedded-boundary');
  assert.equal(result.tasks.at(-1).parent_task_key, 'embedded-workflow');
});
