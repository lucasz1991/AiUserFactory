'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '../..');
const workflowRunnerPath = path.join(projectRoot, 'node', 'workflows', 'run_step.cjs');

function executeWorkflow(tasks, workflowVariables = {}) {
  const runDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-loop-routes-'));
  const runtimePath = path.join(runDirectory, 'runtime.json');
  const resultPath = path.join(runDirectory, 'result.json');
  const statusPath = path.join(runDirectory, 'status.json');

  fs.writeFileSync(runtimePath, JSON.stringify({
    resultPath,
    statusPath,
    runDirectory,
    livePreviewEnabled: false,
    workflow: { workflow_variables: workflowVariables },
    tasks,
  }));

  try {
    const processResult = spawnSync(process.execPath, [workflowRunnerPath, runtimePath], {
      cwd: projectRoot,
      encoding: 'utf8',
      timeout: 30000,
    });

    assert.equal(processResult.error, undefined, String(processResult.error || ''));
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);

    return JSON.parse(fs.readFileSync(resultPath, 'utf8'));
  } finally {
    fs.rmSync(runDirectory, { recursive: true, force: true });
  }
}

function loopTasks(loopConfiguration) {
  return [
    {
      key: 'large-loop-start',
      task_key: 'loop.for_each_element',
      title: 'Large loop start',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/loop/for_each_element.cjs',
      loop_end_key: 'large-loop-end',
      ...loopConfiguration,
    },
    {
      key: 'large-loop-body',
      task_key: 'data.workflow_return',
      title: 'Large loop body',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/data/workflow_return.cjs',
      value: true,
    },
    {
      key: 'large-loop-end',
      task_key: 'loop.end',
      title: 'Large loop end',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/loop/end.cjs',
      loop_start_key: 'large-loop-start',
    },
    {
      key: 'large-loop-result',
      task_key: 'data.workflow_return',
      title: 'Large loop result',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/data/workflow_return.cjs',
      value: 'done',
    },
  ];
}

test('route budget permits 5000 iterations, the camel-case alias, and source arrays', { timeout: 60000 }, () => {
  const cases = [
    {
      label: 'iteration_count',
      tasks: loopTasks({ iteration_count: 5000, limit: 1 }),
      variables: {},
    },
    {
      label: 'iterationCount',
      tasks: loopTasks({ iterationCount: 150, limit: 1 }),
      variables: {},
    },
    {
      label: 'source_array',
      tasks: loopTasks({ source_array: 'source_items', iteration_count: 0, limit: 1 }),
      variables: { source_items: Array.from({ length: 150 }, (_, index) => index) },
    },
  ];

  for (const loopCase of cases) {
    const result = executeWorkflow(loopCase.tasks, loopCase.variables);

    assert.equal(result.ok, true, `${loopCase.label}: ${result.statusMessage}`);
    assert.equal(result.workflowReturn, 'done', loopCase.label);
  }
});

test('a small source loop does not enlarge the budget of a later dynamic self-cycle', () => {
  const tasks = loopTasks({ source_array: 'source_items', iteration_count: 0 });
  tasks.push({
    key: 'route-cycle-after-loop',
    task_key: 'decision.array_length',
    title: 'Route cycle after loop',
    kind: 'data',
    runner: 'node',
    node_script: 'node/workflows/tasks/decision/array_length.cjs',
    array_name: 'empty_items',
    operator: '>=',
    compare_value: 1,
    success_target: 'route-cycle-after-loop',
    error_target: 'route-cycle-after-loop',
  });

  const result = executeWorkflow(tasks, {
    source_items: ['one', 'two'],
    empty_items: [],
  });

  assert.equal(result.ok, false);
  assert.match(result.statusMessage, /Zu viele dynamische Task-Routenwechsel/);
  assert.ok(result.events.length < 500, `Self-Cycle wurde nicht frueh gestoppt (${result.events.length} Events).`);
});

test('route budget still interrupts an actual unbounded dynamic route cycle', () => {
  const result = executeWorkflow([{
    key: 'route-cycle',
    task_key: 'decision.array_length',
    title: 'Route cycle',
    kind: 'data',
    runner: 'node',
    node_script: 'node/workflows/tasks/decision/array_length.cjs',
    array_name: 'empty_items',
    operator: '>=',
    compare_value: 1,
    success_target: 'route-cycle',
    error_target: 'route-cycle',
  }], { empty_items: [] });

  assert.equal(result.ok, false);
  assert.match(result.statusMessage, /Zu viele dynamische Task-Routenwechsel/);
});
