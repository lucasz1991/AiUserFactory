'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '../..');
const workflowRunnerPath = path.join(projectRoot, 'node', 'workflows', 'run_step.cjs');

async function runWorkflow(callbackUrl, callbackTimeoutMs = 3000) {
  const runDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-completion-callback-'));
  const runtimePath = path.join(runDirectory, 'runtime.json');
  const resultPath = path.join(runDirectory, 'result.json');
  const statusPath = path.join(runDirectory, 'status.json');

  fs.writeFileSync(runtimePath, JSON.stringify({
    runId: 'callback-runtime-test',
    workflowRunId: 41,
    workflowStepRunId: 73,
    resultPath,
    statusPath,
    runDirectory,
    livePreviewEnabled: false,
    completionCallback: {
      url: callbackUrl,
      timeoutMs: callbackTimeoutMs,
    },
    workflow: { workflow_variables: {} },
    tasks: [{
      key: 'return-result',
      task_key: 'data.workflow_return',
      kind: 'data',
      runner: 'node',
      node_script: 'node/workflows/tasks/data/workflow_return.cjs',
      value: 'callback-ok',
    }],
  }));

  try {
    const child = spawn(process.execPath, [workflowRunnerPath, runtimePath], {
      cwd: projectRoot,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
    child.stderr.on('data', (chunk) => { stderr += chunk.toString(); });
    const exitCode = await new Promise((resolve, reject) => {
      child.once('error', reject);
      child.once('close', resolve);
    });

    assert.equal(exitCode, 0, stderr || stdout);

    return {
      result: JSON.parse(fs.readFileSync(resultPath, 'utf8')),
      status: JSON.parse(fs.readFileSync(statusPath, 'utf8')),
      stderr,
    };
  } finally {
    fs.rmSync(runDirectory, { recursive: true, force: true });
  }
}

test('terminal runtime status is pushed exactly once before the runner exits', { timeout: 20000 }, async () => {
  const requests = [];
  const server = http.createServer((request, response) => {
    let body = '';
    request.setEncoding('utf8');
    request.on('data', (chunk) => { body += chunk; });
    request.on('end', () => {
      requests.push({ method: request.method, url: request.url, body: JSON.parse(body) });
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end('{"ok":true}');
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  try {
    const address = server.address();
    const execution = await runWorkflow(`http://127.0.0.1:${address.port}/signed-callback?signature=test`);

    assert.equal(execution.result.ok, true);
    assert.equal(execution.status.state, 'completed');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'POST');
    assert.match(requests[0].url, /signature=test/);
    assert.deepEqual(requests[0].body, {
      state: 'completed',
      runId: 'callback-runtime-test',
      workflowRunId: 41,
      workflowStepRunId: 73,
      at: requests[0].body.at,
    });
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('callback failure never changes a successful workflow result', { timeout: 20000 }, async () => {
  const probe = http.createServer();
  await new Promise((resolve) => probe.listen(0, '127.0.0.1', resolve));
  const address = probe.address();
  await new Promise((resolve) => probe.close(resolve));

  const execution = await runWorkflow(`http://127.0.0.1:${address.port}/unreachable`, 250);

  assert.equal(execution.result.ok, true);
  assert.equal(execution.status.state, 'completed');
  assert.match(execution.stderr, /Workflow-Abschluss-Callback fehlgeschlagen/);
});
