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
const waitScript = 'node/workflows/tasks/wait/seconds.cjs';
const closeBrowserScript = 'node/workflows/tasks/browser/close.cjs';
const branchScript = 'tests/Fixtures/Workflows/branch_result.cjs';

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

function waitTask(key, frameKey = null, extra = {}) {
  return {
    key,
    task_key: 'wait.seconds',
    title: key,
    kind: 'wait',
    runner: 'node',
    node_script: waitScript,
    value: 0,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
    ...extra,
  };
}

function branchTask(key, onError, frameKey = null, extra = {}) {
  return {
    key,
    task_key: 'test.branch_result',
    title: key,
    kind: 'data',
    runner: 'node',
    node_script: branchScript,
    on_error: onError,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
    ...extra,
  };
}

function executeEmbeddedWorkflow(workflowReturn, workflow = {}) {
  return executeTasks([
    returnTask('embedded-return', workflowReturn, 'embedded-frame'),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
      next: {
        type: 'card',
        card_key: 'success-target',
      },
    },
    returnTask('must-be-skipped', true),
    returnTask('success-target', true),
  ], workflow);
}

function executeTasks(tasks, workflow = {}) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-boundary-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const runtime = {
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    workflow,
    tasks,
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

test('workflow runner keeps Chromium sandbox enabled by default', () => {
  const result = executeTasks([returnTask('sandbox-default', true)]);

  assert.equal(result.runnerDiagnostics.chromiumNoSandboxFlag, false);
  assert.equal(typeof result.browserIdentity.runnerProcessId, 'number');
  assert.equal(result.browserIdentity.connectedToExistingBrowser, false);
});

test('browser close without a persisted browser does not launch a replacement', () => {
  const result = executeTasks([{
    key: 'close-missing-browser',
    task_key: 'browser.close',
    title: 'Close missing browser',
    kind: 'browser',
    runner: 'node',
    node_script: closeBrowserScript,
    browser_window: 'main',
  }]);

  assert.equal(result.ok, true);
  assert.equal(result.tasks[0].statusMessage, 'Kein Browser-Handle zum Schliessen vorhanden.');
  assert.equal(result.browserWsEndpoint, '');
});

test('embedded workflow boundary preserves browser windows for parent workflow', () => {
  const browserWindows = [
    {
      key: 'child-session',
      label: 'Child session',
      url: 'https://example.test',
    },
  ];
  const result = executeEmbeddedWorkflow(true, { browserWindows });
  const boundary = result.tasks.find((task) => task.runner === 'workflow-boundary');

  assert.deepEqual(boundary.browserWindows, browserWindows);
  assert.deepEqual(result.browserWindows, browserWindows);
});

test('embedded workflow false return fails at the workflow boundary', () => {
  const result = executeEmbeddedWorkflow(false);

  assert.equal(result.ok, false);
  assert.equal(result.workflow_return, false);
  assert.equal(result.workflow_return_ok, false);
  assert.equal(result.failedTaskKey, 'embedded-boundary');
  assert.equal(result.tasks.at(-1).parent_task_key, 'embedded-workflow');
});

test('failed task follows a forward on_error route in the same Node run', () => {
  const failedTask = returnTask('mailbox-not-found', false);
  failedTask.on_error = {
    type: 'card',
    card_key: 'check-postbox-button',
  };
  const result = executeTasks([
    failedTask,
    returnTask('must-be-skipped-after-error', true),
    returnTask('check-postbox-button', true),
  ]);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'mailbox-not-found',
    'check-postbox-button',
  ]);
  assert.ok(result.events.some((event) => (
    event.stage === 'task-error-route-followed'
    && event.taskKey === 'mailbox-not-found'
    && event.targetTaskKey === 'check-postbox-button'
  )));
});

test('unmatched condition follows on_error without marking the task as failed', () => {
  const result = executeTasks([
    branchTask('condition-not-met', {
      type: 'card',
      card_key: 'failure-target',
    }),
    returnTask('must-be-skipped-after-condition', true),
    returnTask('failure-target', true),
  ]);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'condition-not-met',
    'failure-target',
  ]);
  assert.ok(result.events.some((event) => event.stage === 'task-condition-not-met'));
  assert.ok(result.events.some((event) => event.stage === 'task-branch-route-followed'));
  assert.equal(result.events.some((event) => (
    event.stage === 'task-failed' && event.taskKey === 'condition-not-met'
  )), false);
});

test('unmatched condition requests an external failure route without failing Node execution', () => {
  const result = executeTasks([
    branchTask('condition-not-met', {
      type: 'card',
      card_key: 'earlier-task-not-in-runtime-slice',
    }),
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'condition-not-met');
  assert.equal(result.events.some((event) => event.stage === 'task-failed'), false);
});

test('unresolved embedded success route bubbles to the parent failure route', () => {
  const result = executeTasks([
    waitTask('embedded-task', 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
      next: {
        type: 'card',
        card_key: 'missing-child-task',
      },
    }),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'embedded-workflow');
  assert.match(result.statusMessage, /Interne Erfolgsroute/);
  assert.deepEqual(result.tasks.map((task) => task.key), ['embedded-task']);
});

test('embedded workflow follows backward on_error routes until max attempts is reached', () => {
  const result = executeTasks([
    waitTask('embedded-first', 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
    }),
    branchTask('embedded-check', {
      type: 'card',
      card_key: 'embedded-first',
      max_attempts: 1,
    }, 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
    }),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'embedded-workflow');
  assert.match(result.statusMessage, /zu oft wiederholt/);
  assert.equal(result.events.filter((event) => (
    event.stage === 'task-branch-route-followed'
    && event.taskKey === 'embedded-check'
    && event.targetTaskKey === 'embedded-first'
  )).length, 1);
});
