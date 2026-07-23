'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '../..');
const workflowRunnerPath = path.join(projectRoot, 'node', 'workflows', 'run_step.cjs');
const workflowScriptsRoot = path.join(projectRoot, 'node', 'workflows');

function runWorkflow(tasks, prepareScripts = () => {}, inspectArtifacts = () => ({})) {
  const runDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-task-preload-run-'));
  let scriptDirectory = null;

  try {
    scriptDirectory = fs.mkdtempSync(path.join(workflowScriptsRoot, '.test-task-preload-'));
    const resultPath = path.join(runDirectory, 'result.json');
    const statusPath = path.join(runDirectory, 'status.json');
    const prepared = prepareScripts({ runDirectory, scriptDirectory });
    const runtimeTasks = typeof tasks === 'function'
      ? tasks({ runDirectory, scriptDirectory, ...prepared })
      : tasks;
    const runtimePath = path.join(runDirectory, 'runtime.json');

    fs.writeFileSync(runtimePath, JSON.stringify({
      resultPath,
      statusPath,
      runDirectory,
      livePreviewEnabled: false,
      workflow: { workflow_variables: {} },
      tasks: runtimeTasks,
    }));

    const processResult = spawnSync(process.execPath, [workflowRunnerPath, runtimePath], {
      cwd: projectRoot,
      encoding: 'utf8',
      timeout: 15000,
    });

    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    assert.equal(fs.existsSync(resultPath), true, 'Runner hat keine result.json geschrieben.');

    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));

    return {
      result,
      prepared,
      inspected: inspectArtifacts({ result, prepared, runDirectory, scriptDirectory }),
    };
  } finally {
    fs.rmSync(runDirectory, { recursive: true, force: true });

    if (scriptDirectory) {
      fs.rmSync(scriptDirectory, { recursive: true, force: true });
    }
  }
}

test('preload reports all module errors before an earlier task can execute a side effect', () => {
  const { result, inspected } = runWorkflow(
    ({ runDirectory, scriptDirectory, markerPath }) => [
      {
        key: 'first-valid-task',
        task_key: 'test.side_effect',
        kind: 'data',
        runner: 'node',
        node_script: path.join(scriptDirectory, 'side-effect.test.cjs'),
      },
      {
        key: 'later-invalid-export',
        task_key: 'test.invalid_export',
        kind: 'data',
        runner: 'node',
        node_script: path.join(scriptDirectory, 'invalid-export.test.cjs'),
      },
      {
        key: 'later-missing-module',
        task_key: 'test.missing_module',
        kind: 'data',
        runner: 'node',
        node_script: path.join(scriptDirectory, 'missing.test.cjs'),
      },
      {
        key: 'later-missing-config',
        task_key: 'test.missing_config',
        kind: 'data',
        runner: 'node',
      },
      {
        key: 'later-outside-root',
        task_key: 'test.outside_root',
        kind: 'data',
        runner: 'node',
        node_script: path.join(runDirectory, 'outside.cjs'),
      },
    ],
    ({ scriptDirectory }) => {
      const markerPath = path.join(scriptDirectory, 'task-ran.marker');

      fs.writeFileSync(path.join(scriptDirectory, 'side-effect.test.cjs'), `'use strict';
const fs = require('node:fs');
module.exports = {
  async run() {
    fs.writeFileSync(${JSON.stringify(markerPath)}, 'ran');
    return { ok: true, status: 'success' };
  },
};
`);
      fs.writeFileSync(path.join(scriptDirectory, 'invalid-export.test.cjs'), `'use strict';
module.exports = { key: 'test.invalid_export' };
`);

      return { markerPath };
    },
    ({ prepared }) => ({ markerExists: fs.existsSync(prepared.markerPath) }),
  );

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.deepEqual(result.tasks, []);
  assert.equal(inspected.markerExists, false, 'Der erste Task wurde trotz spaeterem Preload-Fehler ausgefuehrt.');
  assert.match(result.statusMessage, /4 Fehler/);
  assert.match(result.statusMessage, /later-invalid-export[\s\S]*invalid-export\.test\.cjs[\s\S]*Exportfehler/);
  assert.match(result.statusMessage, /later-missing-module[\s\S]*missing\.test\.cjs[\s\S]*Ladefehler/);
  assert.match(result.statusMessage, /later-missing-config[\s\S]*<fehlt>[\s\S]*Konfigurationsfehler/);
  assert.match(result.statusMessage, /later-outside-root[\s\S]*outside\.cjs[\s\S]*ausserhalb von node\/workflows/);
});

test('preload evaluates a shared task module only once', () => {
  const { result, inspected } = runWorkflow(
    ({ sharedModulePath }) => [
      {
        key: 'shared-one',
        task_key: 'test.shared',
        kind: 'data',
        runner: 'node',
        node_script: sharedModulePath,
      },
      {
        key: 'shared-two',
        task_key: 'test.shared',
        kind: 'data',
        runner: 'node',
        node_script: sharedModulePath,
      },
    ],
    ({ scriptDirectory }) => {
      const loadMarkerPath = path.join(scriptDirectory, 'module-loaded.marker');
      const sharedModulePath = path.join(scriptDirectory, 'shared.test.cjs');

      fs.writeFileSync(sharedModulePath, `'use strict';
const fs = require('node:fs');
fs.appendFileSync(${JSON.stringify(loadMarkerPath)}, 'loaded\\n');
module.exports = {
  async run() {
    return { ok: true, status: 'success' };
  },
};
`);

      return { loadMarkerPath, sharedModulePath };
    },
    ({ prepared }) => ({ loadMarker: fs.readFileSync(prepared.loadMarkerPath, 'utf8') }),
  );

  assert.equal(result.ok, true);
  assert.equal(result.tasks.length, 2);
  assert.deepEqual(
    inspected.loadMarker.trim().split(/\r?\n/),
    ['loaded'],
  );
});
