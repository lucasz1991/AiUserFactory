'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { captureTaskPreview } = require('../../node/workflows/tasks/lib/preview.cjs');

class FakeFrame {
  constructor(url = 'https://example.test/form') {
    this.frameUrl = url;
  }

  url() {
    return this.frameUrl;
  }

  name() {
    return 'main';
  }

  async evaluate() {
    return {
      url: this.frameUrl,
      title: 'Private form',
      text: 'Account details',
      html: '<html><body><input name="email" value="person@example.test"></body></html>',
      workflowDebug: null,
      workflowMailListScanDebug: null,
      fields: [{ name: 'email', value: 'person@example.test', visible: true }],
    };
  }
}

class FakePage {
  constructor() {
    this.frame = new FakeFrame();
  }

  isClosed() {
    return false;
  }

  async screenshot(options = {}) {
    fs.mkdirSync(path.dirname(options.path), { recursive: true });
    fs.writeFileSync(options.path, 'png');
  }

  url() {
    return this.frame.url();
  }

  async title() {
    return 'Private form';
  }

  target() {
    return { _targetId: 'target-private-form' };
  }

  frames() {
    return [this.frame];
  }
}

function previewContext(root, devDebug) {
  const publicDirectory = path.join(root, 'public');
  const privateDirectory = path.join(root, 'private');

  return {
    page: new FakePage(),
    preview: {
      enabled: true,
      livePreviewPath: path.join(publicDirectory, 'live.png'),
      livePreviewRelativePath: 'workflow-task-runs/1/live.png',
      intervalMs: 3000,
    },
    livePreviewEnabled: true,
    runDirectory: privateDirectory,
    workflowTaskRunDirectory: privateDirectory,
    devDebug,
  };
}

test('live preview never writes a DOM dump below the public screenshot directory when debug is off', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-preview-off-'));
  const context = previewContext(directory, { enabled: false, observability: 'off' });

  try {
    const result = await captureTaskPreview(context, {}, true);

    assert.equal(fs.existsSync(path.join(directory, 'public', 'live.png')), true);
    assert.equal(fs.existsSync(path.join(directory, 'public', 'live-dom.json')), false);
    assert.equal(fs.existsSync(path.join(directory, 'private', 'live-dom.json')), false);
    assert.equal(result.browserWindows[0].debugDomPath, undefined);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('debug live preview writes its DOM dump only to the private run directory', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-preview-debug-'));
  const context = previewContext(directory, { enabled: true, observability: 'debug' });

  try {
    const result = await captureTaskPreview(context, {}, true);
    const privateDomPath = path.join(directory, 'private', 'live-dom.json');

    assert.equal(fs.existsSync(path.join(directory, 'public', 'live.png')), true);
    assert.equal(fs.existsSync(path.join(directory, 'public', 'live-dom.json')), false);
    assert.equal(fs.existsSync(privateDomPath), true);
    assert.equal(result.browserWindows[0].debugDomPath, privateDomPath);
    assert.equal(result.browserWindows[0].debugDomRelativePath, undefined);

    const snapshot = JSON.parse(fs.readFileSync(privateDomPath, 'utf8'));
    assert.equal(snapshot.frames[0].fields[0].value, 'person@example.test');
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

test('debug capture fails closed when no private run directory is available', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-preview-no-private-'));
  const context = previewContext(directory, { enabled: true, observability: 'debug' });
  delete context.runDirectory;
  delete context.workflowTaskRunDirectory;

  try {
    const result = await captureTaskPreview(context, {}, true);

    assert.equal(fs.existsSync(path.join(directory, 'public', 'live.png')), true);
    assert.equal(fs.existsSync(path.join(directory, 'public', 'live-dom.json')), false);
    assert.equal(result.browserWindows[0].debugDomPath, undefined);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});

function executeRuntime(devDebug, options = {}) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-status-observability-'));
  let scriptDirectory = null;
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const taskScript = 'node/workflows/tasks/data/workflow_return.cjs';

  try {
    scriptDirectory = fs.mkdtempSync(path.resolve(__dirname, '../../node/workflows/.test-observability-'));
    const contextProbePath = path.join(scriptDirectory, 'context-probe.test.cjs');

    fs.writeFileSync(contextProbePath, `'use strict';
module.exports = {
  key: 'test.context_probe',
  async run(context = {}) {
    return {
      ok: true,
      status: 'success',
      statusMessage: 'Context observed.',
      observedContext: {
        devDebugEnabled: context.devDebug?.enabled,
        devDebugLevel: context.devDebug?.level,
        observabilityLevel: context.observability?.level,
        previewCaptureDom: context.preview?.captureDom,
        debugDomDirectory: context.preview?.debugDomDirectory,
        runDirectory: context.runDirectory,
      },
    };
  },
};
`);

    fs.writeFileSync(runtimePath, JSON.stringify({
      resultPath,
      statusPath,
      runDirectory: directory,
      livePreviewEnabled: options.livePreviewEnabled === true,
      statusWriteIntervalMs: 60000,
      devDebug,
      workflow: { workflow_variables: {} },
      tasks: [
        {
          key: 'observe-context',
          task_key: 'test.context_probe',
          title: 'Observe context',
          kind: 'data',
          runner: 'node',
          node_script: contextProbePath,
        },
        {
          key: 'return-one',
          task_key: 'data.workflow_return',
          title: 'Return one',
          kind: 'data',
          runner: 'node',
          node_script: taskScript,
          value: 'one',
        },
        {
          key: 'return-two',
          task_key: 'data.workflow_return',
          title: 'Return two',
          kind: 'data',
          runner: 'node',
          node_script: taskScript,
          value: 'two',
        },
      ],
    }));

    const processResult = spawnSync(
      process.execPath,
      [path.resolve(__dirname, '../../node/workflows/run_step.cjs'), runtimePath],
      {
        cwd: path.resolve(__dirname, '../..'),
        encoding: 'utf8',
        timeout: 15000,
      },
    );

    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);

    return {
      status: JSON.parse(fs.readFileSync(statusPath, 'utf8')),
      result: JSON.parse(fs.readFileSync(resultPath, 'utf8')),
    };
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });

    if (scriptDirectory) {
      fs.rmSync(scriptDirectory, { recursive: true, force: true });
    }
  }
}

test('off status omits debug fields while a throttled run still publishes its terminal state', () => {
  const { status, result } = executeRuntime({ enabled: false, observability: 'off' });
  const observedContext = result.tasks.find((task) => task.key === 'observe-context').observedContext;

  assert.equal(status.state, 'completed');
  assert.equal(status.observabilityLevel, 'off');
  assert.equal(status.result.completedTaskKey, 'return-two');
  assert.equal(result.completedTaskKey, 'return-two');
  assert.equal(result.completed_task_key, 'return-two');
  assert.equal(Object.hasOwn(status, 'events'), false);
  assert.equal(Object.hasOwn(status, 'debugArtifacts'), false);
  assert.equal(Object.hasOwn(status, 'debug_artifacts'), false);
  assert.equal(Object.hasOwn(status.result, 'events'), false);
  assert.equal(Object.hasOwn(status.result, 'debugArtifacts'), false);
  assert.equal(Object.hasOwn(status.result, 'debug_artifacts'), false);
  assert.equal(Object.hasOwn(result, 'debug_artifacts'), false);
  assert.deepEqual(observedContext, {
    devDebugEnabled: false,
    devDebugLevel: 'off',
    observabilityLevel: 'off',
    previewCaptureDom: false,
    debugDomDirectory: observedContext.runDirectory,
    runDirectory: observedContext.runDirectory,
  });
});

test('debug status contains one canonical debug artifact field and keeps terminal state immediate', () => {
  const { status, result } = executeRuntime({ enabled: true, observability: 'debug' });
  const observedContext = result.tasks.find((task) => task.key === 'observe-context').observedContext;

  assert.equal(status.state, 'completed');
  assert.equal(status.observabilityLevel, 'debug');
  assert.equal(status.result.completedTaskKey, 'return-two');
  assert.equal(Array.isArray(status.events), true);
  assert.equal(Array.isArray(status.debugArtifacts), true);
  assert.equal(Object.hasOwn(status, 'debug_artifacts'), false);
  assert.equal(Object.hasOwn(status.result, 'events'), false);
  assert.equal(Object.hasOwn(status.result, 'debugArtifacts'), false);
  assert.equal(Object.hasOwn(status.result, 'debug_artifacts'), false);
  assert.equal(Array.isArray(result.debugArtifacts), true);
  assert.equal(Object.hasOwn(result, 'debug_artifacts'), false);
  assert.equal(observedContext.devDebugEnabled, true);
  assert.equal(observedContext.devDebugLevel, 'debug');
  assert.equal(observedContext.observabilityLevel, 'debug');
  assert.equal(observedContext.previewCaptureDom, true);
  assert.equal(observedContext.debugDomDirectory, observedContext.runDirectory);
});

test('preview observability keeps status debug fields disabled', () => {
  const { status } = executeRuntime(
    { enabled: false, observability: 'off' },
    { livePreviewEnabled: true },
  );

  assert.equal(status.state, 'completed');
  assert.equal(status.observabilityLevel, 'preview');
  assert.equal(Object.hasOwn(status, 'events'), false);
  assert.equal(Object.hasOwn(status, 'debugArtifacts'), false);
  assert.equal(Object.hasOwn(status, 'debug_artifacts'), false);
});
