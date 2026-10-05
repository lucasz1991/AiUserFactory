'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const projectRoot = path.resolve(__dirname, '..', '..');
const runnerPath = path.join(__dirname, 'run_step.cjs');
const previewPath = path.join(__dirname, 'tasks', 'lib', 'preview.cjs');

function task(key, windowName, extra = {}) {
  return {
    key,
    task_key: 'test.embedded_window',
    kind: 'browser',
    runner: 'node',
    browser_window_name: windowName,
    ...extra,
  };
}

function execute(tasks, options = {}) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-embedded-windows-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const probePath = path.join(directory, 'window-probe.cjs');
  const preloadPath = path.join(directory, 'fake-browser.cjs');
  const browserLogPath = path.join(directory, 'browser-log.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const capturedStartPath = path.join(directory, 'task-started.json');
  const runtime = {
    runId: 'isolated-window-test',
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewPath: path.join(directory, 'live.png'),
    livePreviewRelativePath: 'tests/isolated/live.png',
    livePreviewEnabled: options.observability !== 'off',
    observability: options.observability || 'preview',
    keepWorkflowBrowserAlive: false,
    statusWriteIntervalMs: 250,
    browserSessionAutomation: { enabled: false, save_at_end: false },
    additionalTaskScriptRoots: [directory],
    workflow: {
      ...(options.windows ? {
        browser: { wsEndpoint: 'ws://127.0.0.1:1/isolated-test-browser' },
        browserWindows: options.windows,
      } : {}),
    },
    tasks: tasks.map((item) => ({ node_script: probePath, ...item })),
    testBrowser: {
      pages: options.pages || [],
      blockEvaluate: options.blockEvaluate === true,
      screenshotDelayMs: options.screenshotDelayMs || 0,
      browserLogPath,
      capturedStartPath,
    },
  };

  fs.writeFileSync(probePath, `'use strict';
const fs = require('node:fs');
const { captureTaskPreview, startTaskPreview } = require(${JSON.stringify(previewPath)});
module.exports = { async run(context) {
  if (context.input.forceError) throw new Error('Synthetic failed task');
  const status = JSON.parse(fs.readFileSync(${JSON.stringify(statusPath)}, 'utf8'));
  fs.writeFileSync(${JSON.stringify(capturedStartPath)}, JSON.stringify(status));
  if (context.input.closeSibling) {
    await context.browserWindows.find((entry) => entry.key === context.input.closeSibling)?.page.close();
  }
  if (context.input.url) await context.page.goto(context.input.url);
  if (context.input.startTaskPreview) startTaskPreview(context);
  return captureTaskPreview(context, {
    ok: true, status: 'success',
    boundTarget: context.page.target()._targetId,
    observedWindows: context.browserWindows.map((entry) => ({ key: entry.key, targetId: entry.page.target()._targetId })),
    runnerPreviewActive: context.__workflowRunnerPreviewActive === true,
    duplicateTaskPreviewActive: Boolean(context.__workflowPreviewTimer),
  }, true);
}};
`);
  fs.writeFileSync(preloadPath, `'use strict';
const fs = require('node:fs');
const Module = require('node:module');
const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const log = { launched: 0, connected: 0, newPages: 0, closedPages: [], closedBrowser: 0, disconnected: 0, statuses: [] };
function flush() { fs.writeFileSync(config.testBrowser.browserLogPath, JSON.stringify(log)); }
const originalRename = fs.renameSync;
fs.renameSync = function(source, destination) {
  const result = originalRename.apply(this, arguments);
  if (destination === config.statusPath) {
    const status = JSON.parse(fs.readFileSync(destination, 'utf8'));
    log.statuses.push({ state: status.state, stage: status.stage, taskKey: status.taskKey, activeBrowserWindow: status.activeBrowserWindow });
    flush();
  }
  return result;
};
function page(id, url = 'about:blank') {
  let closed = false;
  return {
    url: () => url, title: async () => 'Fixture ' + id,
    target: () => ({ _targetId: id }), isClosed: () => closed,
    waitForSelector: async () => null,
    evaluate: async () => {
      if (config.testBrowser.blockEvaluate) throw new Error('DOM probe must not be used as window identity');
      return 'complete';
    },
    emulateTimezone: async () => {},
    goto: async (next) => { url = next; },
    screenshot: async (options) => {
      if (config.testBrowser.screenshotDelayMs) await new Promise((resolve) => setTimeout(resolve, config.testBrowser.screenshotDelayMs));
      fs.writeFileSync(options.path, id);
    },
    close: async () => { closed = true; log.closedPages.push(id); flush(); },
  };
}
const pages = config.testBrowser.pages.map((entry) => page(entry.id, entry.url));
const browser = {
  on: () => {},
  pages: async () => pages.filter((entry) => !entry.isClosed()),
  newPage: async () => { const item = page('created-' + ++log.newPages); pages.push(item); flush(); return item; },
  wsEndpoint: () => 'ws://127.0.0.1:1/isolated-test-browser',
  process: () => null,
  close: async () => { log.closedBrowser++; flush(); },
  disconnect: () => { log.disconnected++; flush(); },
};
const puppeteer = { use: () => {}, connect: async () => { log.connected++; flush(); return browser; } };
const originalLoad = Module._load;
Module._load = function(request, parent, isMain) {
  if (request === 'puppeteer-extra' || request === 'puppeteer') return puppeteer;
  if (request === 'puppeteer-extra-plugin-stealth') return () => ({});
  if (request.endsWith('/browser-launcher.cjs')) return {
    BROWSER_LAUNCHER_SCRIPT_VERSION: 1,
    resolveBrowserEngine: () => 'chrome',
    launchConfiguredBrowserWithProfileRetry: async () => { log.launched++; flush(); return { browser, activeEngine: 'chrome' }; },
  };
  return originalLoad.apply(this, arguments);
};
flush();
`);
  fs.writeFileSync(runtimePath, JSON.stringify(runtime));

  try {
    const processResult = spawnSync(process.execPath, ['-r', preloadPath, runnerPath, runtimePath], {
      cwd: projectRoot,
      encoding: 'utf8',
      timeout: 15000,
    });
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
    return {
      result,
      status: JSON.parse(fs.readFileSync(statusPath, 'utf8')),
      started: fs.existsSync(capturedStartPath) ? JSON.parse(fs.readFileSync(capturedStartPath, 'utf8')) : null,
      log: JSON.parse(fs.readFileSync(browserLogPath, 'utf8')),
      screenshots: Object.fromEntries(fs.readdirSync(directory)
        .filter((filename) => filename.endsWith('.png'))
        .map((filename) => [filename, fs.readFileSync(path.join(directory, filename), 'utf8')])),
    };
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

const existingWindows = [
  { key: 'main', targetId: 'root-target', url: 'https://fixture.test/root' },
  { key: 'child', targetId: 'child-target', url: 'https://fixture.test/child' },
  { key: 'grandchild', targetId: 'grandchild-target', url: 'https://fixture.test/grandchild' },
  { key: 'sibling', targetId: 'sibling-target', url: 'https://fixture.test/sibling' },
];
const existingPages = existingWindows.map((entry) => ({ id: entry.targetId, url: entry.url }));

test('the actual runner owns one preview timer and task modules do not start a duplicate', () => {
  const { result } = execute([task('task-preview', 'main', { startTaskPreview: true })]);
  assert.equal(result.ok, true);
  assert.equal(result.tasks[0].runnerPreviewActive, true);
  assert.equal(result.tasks[0].duplicateTaskPreviewActive, false);
});

test('registered exact windows are reused without an unrelated DOM readyState probe', () => {
  const { result, log } = execute([
    task('root-open', 'main'),
    task('child-open', 'child'),
    task('root-reuses-child', 'child'),
  ], { blockEvaluate: true });
  assert.equal(result.ok, true);
  assert.equal(log.newPages, 2);
  assert.equal(result.tasks[1].boundTarget, result.tasks[2].boundTarget);
});

test('a slow optional screenshot does not hold business task results or flip the final lifecycle state', () => {
  const { result, status, log } = execute([task('root-open', 'main')], { screenshotDelayMs: 1900 });
  assert.equal(result.ok, true);
  assert.equal(result.tasks[0].status, 'success');
  assert.equal(result.tasks[0].browserWindows[0].targetId, 'created-1');
  assert.equal(result.tasks[0].browserWindows[0].capturedAt, undefined);
  assert.equal(status.state, 'completed');
  assert.equal(log.statuses.at(-1).state, 'completed');
});

test('resumed ancestor captures every existing child, grandchild and sibling without opening replacement tabs', () => {
  const { result, log, screenshots } = execute([task('root-task', 'main')], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, true);
  assert.equal(log.connected, 1);
  assert.equal(log.newPages, 0);
  assert.equal(log.closedBrowser, 0);
  assert.equal(log.disconnected, 1);
  assert.deepEqual(result.browserWindows.map((entry) => entry.key), ['main', 'child', 'grandchild', 'sibling']);
  assert.equal(result.tasks[0].boundTarget, 'root-target');
  assert.deepEqual(screenshots, {
    'live.png': 'root-target',
    'live-child.png': 'child-target',
    'live-grandchild.png': 'grandchild-target',
    'live-sibling.png': 'sibling-target',
  });
});

test('fresh nested windows remain separately addressable by parent and sibling tasks', () => {
  const { result, log } = execute([
    task('root-open', 'main'),
    task('child-open', 'child'),
    task('grandchild-open', 'grandchild'),
    task('great-grandchild-open', 'great-grandchild'),
    task('sibling-open', 'sibling'),
    task('parent-reuses-grandchild', 'grandchild'),
    task('sibling-reuses-child', 'child'),
  ]);
  assert.equal(result.ok, true);
  assert.equal(log.launched, 1);
  assert.equal(log.newPages, 5);
  assert.equal(result.tasks[5].boundTarget, result.tasks[2].boundTarget);
  assert.equal(result.tasks[6].boundTarget, result.tasks[1].boundTarget);
  assert.equal(result.browserWindows.length, 5);
});

test('closing one child retains all unrelated tabs and stable preview paths', () => {
  const { result, log, screenshots } = execute([
    task('root-before-close', 'main'),
    task('close-child', 'child', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
    task('parent-uses-grandchild', 'grandchild'),
  ], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, true);
  assert.deepEqual(log.closedPages, ['child-target']);
  assert.equal(log.closedBrowser, 0);
  assert.deepEqual(result.browserWindows.map((entry) => entry.key), ['main', 'grandchild', 'sibling']);
  assert.equal(result.tasks[2].boundTarget, 'grandchild-target');
  assert.equal(screenshots['live-grandchild.png'], 'grandchild-target');
  assert.equal(result.browserWindows.find((entry) => entry.key === 'grandchild').livePreviewRelativePath, 'tests/isolated/live-grandchild.png');
});

test('a child opened before main does not silently become the main browser window', () => {
  const { result, log } = execute([task('child-opens-first', 'child'), task('root-opens-later', 'main')]);
  assert.equal(result.ok, true);
  assert.equal(log.newPages, 2);
  assert.notEqual(result.tasks[0].boundTarget, result.tasks[1].boundTarget);
  assert.deepEqual(result.browserWindows.map((entry) => entry.key), ['child', 'main']);
});

test('a missing named close target never closes the workflow browser or any sibling', () => {
  const { result, log } = execute([
    task('close-unknown', 'unknown', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
    task('parent-still-active', 'main'),
  ], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, true);
  assert.deepEqual(log.closedPages, []);
  assert.equal(log.closedBrowser, 0);
  assert.equal(log.newPages, 0);
  assert.equal(result.tasks[0].status, 'skipped');
  assert.equal(result.tasks[1].boundTarget, 'root-target');
});

test('missing persisted target IDs fail closed despite another tab with the same URL', () => {
  const { result, log } = execute([task('missing-child', 'child')], {
    windows: [{ key: 'child', targetId: 'missing-target', url: 'https://fixture.test/same' }],
    pages: [{ id: 'wrong-target', url: 'https://fixture.test/same' }],
  });
  assert.equal(result.ok, false);
  assert.equal(result.tasks[0].status, 'failed');
  assert.equal(result.tasks[0].boundTarget, undefined);
  assert.equal(log.newPages, 0);
  assert.equal(log.closedBrowser, 0);
  assert.equal(result.browserWindows[0].targetId, 'missing-target');
  assert.equal(result.browserWindows[0].connected, false);
});

test('legacy named URL state attaches only a unique exact URL and never another tab', () => {
  const matched = execute([task('legacy-child', 'child')], {
    windows: [{ key: 'child', url: 'https://fixture.test/child' }], pages: existingPages,
  });
  assert.equal(matched.result.ok, true);
  assert.equal(matched.result.tasks[0].boundTarget, 'child-target');
  assert.equal(matched.log.newPages, 0);
  const unmatched = execute([task('new-child', 'child')], {
    windows: [{ key: 'child', url: 'https://fixture.test/missing' }], pages: existingPages,
  });
  assert.equal(unmatched.result.ok, true);
  assert.equal(unmatched.result.tasks[0].boundTarget, 'created-1');
  assert.equal(unmatched.log.newPages, 1);
  const ambiguous = execute([task('ambiguous-child', 'child')], {
    windows: [{ key: 'child', url: 'https://fixture.test/same' }],
    pages: [{ id: 'candidate-one', url: 'https://fixture.test/same' }, { id: 'candidate-two', url: 'https://fixture.test/same' }],
  });
  assert.equal(ambiguous.result.tasks[0].boundTarget, 'created-1');
  assert.equal(ambiguous.log.newPages, 1);
});

test('explicitly closed child windows do not resurrect from the initial persisted context', () => {
  const { result, log } = execute([
    task('close-child', 'child', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
    task('close-grandchild', 'grandchild', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
    task('parent-continues', 'main'),
  ], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, true);
  assert.deepEqual(result.browserWindows.map((entry) => entry.key), ['main', 'sibling']);
  assert.deepEqual(log.closedPages, ['child-target', 'grandchild-target']);
  assert.equal(log.closedBrowser, 0);
});

test('off observability retains all exact window identities without screenshots or capture metadata', () => {
  const { result, screenshots } = execute([task('quiet-parent', 'main')], {
    windows: existingWindows, pages: existingPages, observability: 'off',
  });
  assert.equal(result.ok, true);
  assert.deepEqual(result.browserWindows.map((entry) => entry.targetId), existingWindows.map((entry) => entry.targetId));
  assert.deepEqual(screenshots, {});
  assert.equal(result.browserWindows.some((entry) => Object.hasOwn(entry, 'livePreviewRelativePath')), false);
});

test('an externally closed child retains failed identity instead of attaching a sibling or replacement', () => {
  const { result, log } = execute([
    task('close-child-outside-runner', 'main', { closeSibling: 'child' }),
    task('use-closed-child', 'child'),
  ], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, false);
  assert.equal(result.tasks[1].status, 'failed');
  assert.equal(log.newPages, 0);
  assert.equal(log.closedBrowser, 0);
  const closedChild = result.browserWindows.find((entry) => entry.key === 'child');
  assert.equal(closedChild.connected, false);
  assert.equal(closedChild.targetId, 'child-target');
  assert.equal(result.browserWindows.find((entry) => entry.key === 'sibling').connected, true);
});

test('an explicit later open may create a new child after an intentional close without reviving old identity', () => {
  const { result, log } = execute([
    task('close-child', 'child', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
    task('open-new-child', 'child'),
  ], { windows: existingWindows, pages: existingPages });
  assert.equal(result.ok, true);
  assert.equal(log.newPages, 1);
  assert.equal(result.tasks[1].boundTarget, 'created-1');
  assert.equal(result.browserWindows.find((entry) => entry.key === 'child').targetId, 'created-1');
});

test('task start status and events retain three invocation levels and immutable source task identity', () => {
  const lineage = ['child', 'grandchild', 'great-grandchild'].map((name, index) => ({
    frame_key: 'frame-' + name,
    parent_frame_key: index ? 'frame-' + ['child', 'grandchild'][index - 1] : null,
    workflow_id: index + 10,
    workflow_name: name,
    include_task_key: 'include-' + name,
    source_step_id: index + 100,
    source_step_action_key: 'step-' + name,
    browser_window: name,
  }));
  const { result, started } = execute([task('prefixed-leaf', 'great-grandchild', {
    embedded_workflow_path: lineage,
    embedded_source_step_id: 300,
    embedded_source_action_key: 'leaf-list',
    embedded_source_task_key: 'original-open',
  })]);
  assert.equal(result.ok, true);
  assert.deepEqual(started.tasks[0].embedded_workflow_path, lineage);
  assert.equal(started.tasks[0].status, 'running');
  assert.equal(started.embedded_source_task_key, 'original-open');
  const startEvent = result.events.find((event) => event.stage === 'task-started');
  assert.deepEqual(startEvent.embedded_workflow_path, lineage);
  assert.equal(startEvent.browserWindow, 'great-grandchild');
  assert.equal(result.tasks[0].embedded_source_step_id, 300);
});

test('early child return retains full immutable configured tasks without changing executed-task semantics', () => {
  const lineage = [{ frame_key: 'child-frame', workflow_id: 10, workflow_name: 'Child', browser_window: 'child' }];
  const tasks = [
    task('child-return', 'child', {
      kind: 'data', task_key: 'data.workflow_return', node_script: 'node/workflows/tasks/data/workflow_return.cjs',
      value: true, embedded_workflow_frame_key: 'child-frame', embedded_workflow_path: lineage,
    }),
    task('child-unvisited', 'child', {
      embedded_workflow_frame_key: 'child-frame', embedded_workflow_path: lineage,
      embedded_source_task_key: 'unvisited-source', password: 'fixture-secret-not-for-public-output',
    }),
    task('child-boundary', 'child', {
      task_key: 'workflow.boundary', kind: 'workflow', runner: 'workflow-boundary',
      embedded_workflow_frame_key: 'child-frame', embedded_workflow_path: lineage,
    }),
    task('parent-return', 'main', {
      kind: 'data', task_key: 'data.workflow_return', node_script: 'node/workflows/tasks/data/workflow_return.cjs', value: true,
    }),
  ];
  const { result, status, log } = execute(tasks);
  assert.equal(result.ok, true);
  assert.equal(log.launched, 0);
  assert.deepEqual(result.tasks.map((entry) => entry.key), ['child-return', 'child-boundary', 'parent-return']);
  assert.deepEqual(result.configuredTasks.map((entry) => entry.key), tasks.map((entry) => entry.key));
  assert.equal(result.configuredTasks[1].status, 'configured');
  assert.equal(result.configuredTasks[1].embedded_source_task_key, 'unvisited-source');
  assert.deepEqual(result.configuredTasks[1].embedded_workflow_path, lineage);
  assert.notEqual(result.configuredTasks[1].password, 'fixture-secret-not-for-public-output');
  assert.deepEqual(status.configuredTasks, result.configuredTasks);
  assert.deepEqual(status.result.configuredTasks, result.configuredTasks);
});

test('failed child result retains future frozen definitions without falsely marking them executed', () => {
  const { result, status } = execute([
    task('child-required-inputs', 'child', {
      kind: 'data', forceError: true,
    }),
    task('child-future', 'child', { embedded_source_task_key: 'future-source' }),
  ]);
  assert.equal(result.ok, false);
  assert.deepEqual(result.tasks.map((entry) => entry.key), ['child-required-inputs']);
  assert.deepEqual(result.configuredTasks.map((entry) => entry.key), ['child-required-inputs', 'child-future']);
  assert.equal(result.configuredTasks[1].status, 'configured');
  assert.equal(result.configuredTasks[1].embedded_source_task_key, 'future-source');
  assert.deepEqual(status.result.configuredTasks, result.configuredTasks);
});

test('failed task with a newly opened child stays failed when finalization releases the browser', () => {
  const { result, status, log } = execute([
    task('child-failed-action', 'child', { forceError: true }),
    task('child-unvisited-action', 'child'),
  ]);
  assert.equal(result.ok, false);
  assert.equal(log.launched, 1);
  assert.equal(log.newPages, 1);
  assert.equal(status.state, 'failed');
  assert.equal(status.isRunning, false);
  assert.deepEqual(status.configuredTasks.map((entry) => entry.key), ['child-failed-action', 'child-unvisited-action']);
});

test('active physical child survives a non-browser task and close reports the remaining actual page', () => {
  const childActive = execute([
    task('root-open', 'main'),
    task('child-open', 'child'),
    task('non-browser-delay', 'main', {
      kind: 'data', task_key: 'wait.seconds', node_script: 'node/workflows/tasks/wait/seconds.cjs', value: 0.3,
    }),
  ]);
  assert.equal(childActive.result.ok, true);
  assert.equal(childActive.result.activeBrowserWindow, 'child');
  assert.equal(childActive.status.activeBrowserWindow, 'child');
  assert.equal(childActive.log.statuses.find((entry) => entry.stage === 'task-started' && entry.taskKey === 'non-browser-delay').activeBrowserWindow, 'child');
  const afterClose = execute([
    task('root-open', 'main'),
    task('child-open', 'child'),
    task('close-child', 'child', { task_key: 'browser.close', node_script: 'node/workflows/tasks/browser/close.cjs' }),
  ]);
  assert.equal(afterClose.result.ok, true);
  assert.equal(afterClose.result.activeBrowserWindow, 'main');
  assert.equal(afterClose.status.activeBrowserWindow, 'main');
  const noWindow = execute([task('empty-return', 'main', {
    kind: 'data', task_key: 'data.workflow_return', node_script: 'node/workflows/tasks/data/workflow_return.cjs', value: true,
  })]);
  assert.equal(noWindow.result.activeBrowserWindow, null);
  assert.equal(noWindow.status.activeBrowserWindow, null);
});
