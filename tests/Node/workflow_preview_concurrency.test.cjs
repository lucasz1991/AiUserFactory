'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const {
  captureTaskPreview,
  startTaskPreview,
  stopTaskPreview,
} = require('../../node/workflows/tasks/lib/preview.cjs');

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });

  return { promise, resolve, reject };
}

function fixture(t, name = 'main') {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-preview-concurrency-'));
  const captures = [];
  let active = 0;
  let maxActive = 0;
  const page = {
    isClosed: () => false,
    url: () => 'https://fixture.test/preview',
    title: async () => 'Preview fixture',
    target: () => ({ _targetId: 'fixture-target' }),
    async screenshot(options) {
      const pending = deferred();
      active++;
      maxActive = Math.max(maxActive, active);
      captures.push({ ...pending, path: options.path });

      try {
        await pending.promise;
      } finally {
        active--;
      }
    },
  };
  const contextFor = (windowName) => ({
    browserWindows: [{
      key: windowName,
      page,
      livePreviewPath: path.join(directory, `${windowName}.png`),
      livePreviewRelativePath: `tests/preview/${windowName}.png`,
    }],
    livePreviewEnabled: true,
    preview: { enabled: true, intervalMs: 1000 },
    observability: { level: 'preview', capturesScreenshots: true, capturesDom: false },
  });
  const context = contextFor(name);

  t.after(() => {
    stopTaskPreview(context);
    for (const capture of captures) capture.resolve();
    fs.rmSync(directory, { recursive: true, force: true });
  });

  return { page, captures, context, contextFor, maxActive: () => maxActive };
}

const turn = () => new Promise((resolve) => setImmediate(resolve));

test('50 background ticks do not queue behind one stalled screenshot or republish old state', { timeout: 3000 }, async (t) => {
  const { context, captures } = fixture(t);
  const first = captureTaskPreview(context, { task: 'first' }, false);
  await turn();
  assert.equal(captures.length, 1);

  const results = await Promise.all(Array.from({ length: 50 }, (_, index) => {
    const result = { task: `tick-${index}` };
    return captureTaskPreview(context, result, false).then((returned) => {
      assert.equal(returned, result);
      return returned;
    });
  }));
  assert.equal(results.length, 50);
  assert.equal(captures.length, 1);

  captures[0].resolve();
  assert.equal((await first).browserWindows[0].targetId, 'fixture-target');
  await turn();
  assert.equal(captures.length, 1);
});

test('forced callers drain the pending screenshot and share exactly one fresh completion capture', { timeout: 3000 }, async (t) => {
  const { context, captures } = fixture(t);
  const background = captureTaskPreview(context, {}, false);
  await turn();
  let finished = false;
  const firstForce = captureTaskPreview(context, { task: 'first-force' }, true).then((result) => {
    finished = true;
    return result;
  });
  const secondForce = captureTaskPreview(context, { task: 'second-force' }, true);
  await turn();
  assert.equal(captures.length, 1);
  assert.equal(finished, false);

  captures[0].resolve();
  await background;
  await turn();
  assert.equal(captures.length, 2);
  assert.equal(finished, false);
  const thirdForce = captureTaskPreview(context, { task: 'third-force' }, true);
  await Promise.all(Array.from({ length: 50 }, () => captureTaskPreview(context, {}, false)));
  assert.equal(captures.length, 2);

  captures[1].resolve();
  const results = await Promise.all([firstForce, secondForce, thirdForce]);
  assert.deepEqual(results.map((result) => result.task), ['first-force', 'second-force', 'third-force']);
  assert.deepEqual(results[0].browserWindows, results[1].browserWindows);
  assert.deepEqual(results[1].browserWindows, results[2].browserWindows);
  await turn();
  assert.equal(captures.length, 2);
});

test('different task contexts sharing a page serialize captures and retain their own window paths', { timeout: 3000 }, async (t) => {
  const { context, contextFor, captures, maxActive } = fixture(t, 'parent');
  const child = contextFor('child');
  const parentResult = captureTaskPreview(context, { task: 'parent' }, true);
  await turn();
  const childResult = captureTaskPreview(child, { task: 'child' }, true);
  await turn();
  assert.equal(captures.length, 1);

  captures[0].resolve();
  const parent = await parentResult;
  await turn();
  assert.equal(captures.length, 2);
  assert.equal(maxActive(), 1);
  captures[1].resolve();
  const result = await childResult;
  assert.equal(parent.browserWindows[0].key, 'parent');
  assert.equal(parent.browserWindows[0].livePreviewRelativePath, 'tests/preview/parent.png');
  assert.equal(result.browserWindows[0].key, 'child');
  assert.equal(result.browserWindows[0].livePreviewRelativePath, 'tests/preview/child.png');
  assert.equal(path.basename(captures[0].path), 'parent.png');
  assert.equal(path.basename(captures[1].path), 'child.png');
});

test('a failed pending shot releases the guard and fresh forced capture has no false stale error', { timeout: 3000 }, async (t) => {
  const { context, captures } = fixture(t);
  const background = captureTaskPreview(context, {}, false);
  await turn();
  const forced = captureTaskPreview(context, { task: 'completion' }, true);
  captures[0].reject(new Error('Synthetic screenshot failure'));
  const stale = await background;
  assert.equal(stale.browserWindows[0].stale, true);
  await turn();
  assert.equal(captures.length, 2);
  captures[1].resolve();
  const result = await forced;
  assert.equal(result.task, 'completion');
  assert.equal(result.browserWindows[0].stale, undefined);
  assert.equal(result.browserWindows[0].error, undefined);
});

test('the runner-managed preview suppresses a duplicate task interval while standalone tasks still capture', { timeout: 3000 }, async (t) => {
  const { context, captures } = fixture(t);
  context.__workflowRunnerPreviewActive = true;
  startTaskPreview(context);
  await turn();
  assert.equal(context.__workflowPreviewTimer, undefined);
  assert.equal(captures.length, 0);

  context.__workflowRunnerPreviewActive = false;
  startTaskPreview(context);
  await turn();
  assert.ok(context.__workflowPreviewTimer);
  assert.equal(captures.length, 1);
  stopTaskPreview(context);
  assert.equal(context.__workflowPreviewTimer, null);
  captures[0].resolve();
  await turn();
});

test('off observability creates no capture or timer despite legacy preview and DOM flags', async (t) => {
  const { context, captures } = fixture(t);
  context.observability = { level: 'off', capturesScreenshots: true, capturesDom: true };
  context.devDebug = { enabled: true, captureDom: true };
  const result = { task: 'off' };
  startTaskPreview(context);
  assert.equal(await captureTaskPreview(context, result, true), result);
  await turn();
  assert.equal(captures.length, 0);
  assert.equal(context.__workflowPreviewTimer, undefined);
});

test('optional runner preview returns the original task within its budget but holds physical guards until drain', { timeout: 6000 }, async (t) => {
  const { context, captures } = fixture(t);
  context.__workflowRunnerPreviewActive = true;
  const result = { ok: true, task: 'business-result' };
  const started = performance.now();
  assert.equal(await captureTaskPreview(context, result, true), result);
  assert.ok(performance.now() - started < 2500, 'Optional screenshot must not hold business progress indefinitely');
  assert.equal(captures.length, 1);
  const ticks = await Promise.all(Array.from({ length: 50 }, () => captureTaskPreview(context, {}, false)));
  const completions = await Promise.all(Array.from({ length: 50 }, () => captureTaskPreview(context, {}, true)));
  assert.equal(ticks.every((entry) => entry.browserWindows === undefined), true);
  assert.equal(completions.every((entry) => entry.browserWindows === undefined), true);
  assert.equal(captures.length, 1);

  captures[0].resolve();
  await turn();
  const actual = await captureTaskPreview(context, {}, false);
  assert.equal(actual.browserWindows[0].targetId, 'fixture-target');
  assert.equal(actual.browserWindows[0].livePreviewRelativePath, 'tests/preview/main.png');
  assert.equal(captures.length, 1);

  const next = captureTaskPreview(context, {}, false);
  await turn();
  assert.equal(captures.length, 2);
  captures[1].resolve();
  assert.equal((await next).browserWindows[0].targetId, 'fixture-target');
});

test('budget expiry keeps at most one queued physical followup and later ticks publish only real captures', { timeout: 6000 }, async (t) => {
  const { context, captures } = fixture(t);
  context.__workflowRunnerPreviewActive = true;
  const background = captureTaskPreview(context, {}, false);
  await turn();
  const result = { task: 'completion' };
  const completion = captureTaskPreview(context, result, true);
  assert.equal(await completion, result);
  await background;
  assert.equal(captures.length, 1);
  await Promise.all(Array.from({ length: 50 }, () => captureTaskPreview(context, {}, true)));
  captures[0].resolve();
  await turn();
  assert.equal(captures.length, 2);
  const baseline = await captureTaskPreview(context, {}, false);
  assert.equal(baseline.browserWindows[0].targetId, 'fixture-target');
  await Promise.all(Array.from({ length: 50 }, () => captureTaskPreview(context, {}, true)));
  assert.equal(captures.length, 2);
  captures[1].resolve();
  await turn();
  const actual = await captureTaskPreview(context, {}, false);
  assert.equal(actual.browserWindows[0].targetId, 'fixture-target');
  assert.equal(captures.length, 2);
});

test('a late budget-limited screenshot cannot resurrect a closed or replaced window with the same name', { timeout: 6000 }, async (t) => {
  const { context, captures } = fixture(t, 'child');
  context.__workflowRunnerPreviewActive = true;
  await captureTaskPreview(context, {}, true);
  const replacement = {
    isClosed: () => false,
    url: () => 'https://fixture.test/replacement',
    title: async () => 'Replacement',
    target: () => ({ _targetId: 'replacement-target' }),
    screenshot: async () => {},
  };
  context.browserWindows = [{ ...context.browserWindows[0], page: replacement }];
  captures[0].resolve();
  await turn();
  const stale = await captureTaskPreview(context, { task: 'after-reopen' }, false);
  assert.equal(stale.browserWindows, undefined);
  const actual = await captureTaskPreview(context, {}, false);
  assert.equal(actual.browserWindows[0].targetId, 'replacement-target');
  assert.equal(actual.browserWindows[0].url, 'https://fixture.test/replacement');
});

test('a queued optional followup does not start after the runner stops', { timeout: 6000 }, async (t) => {
  const { context, captures } = fixture(t);
  context.__workflowRunnerPreviewActive = true;
  const background = captureTaskPreview(context, {}, false);
  await turn();
  const completion = captureTaskPreview(context, {}, true);
  await Promise.all([background, completion]);
  context.__workflowRunnerPreviewActive = false;
  captures[0].resolve();
  await turn();
  await turn();
  assert.equal(captures.length, 1);
});

test('debug and copilot capture stay strict even in a runner-managed context', { timeout: 6000 }, async (t) => {
  for (const level of ['debug', 'copilot']) {
    const { context, captures } = fixture(t, level);
    context.__workflowRunnerPreviewActive = true;
    context.observability = { level, capturesScreenshots: true, capturesDom: false };
    let returned = false;
    const pending = captureTaskPreview(context, { task: level }, true).then((result) => {
      returned = true;
      return result;
    });
    await new Promise((resolve) => setTimeout(resolve, 1600));
    assert.equal(returned, false);
    assert.equal(captures.length, 1);
    captures[0].resolve();
    assert.equal((await pending).browserWindows[0].targetId, 'fixture-target');
  }
});
