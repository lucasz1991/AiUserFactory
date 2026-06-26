'use strict';

const fs = require('fs');
const path = require('path');
const { captureTaskPreview, stopTaskPreview } = require('./tasks/lib/preview.cjs');
const {
  BROWSER_LAUNCHER_SCRIPT_VERSION,
  launchConfiguredBrowserWithProfileRetry,
  resolveBrowserEngine,
} = require('../../resources/node/register/lib/browser-launcher.cjs');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const runtimePath = process.argv[2];

if (!runtimePath) {
  throw new Error('Runtime-Konfiguration fehlt.');
}

const runtime = JSON.parse(fs.readFileSync(runtimePath, 'utf8'));
const basePath = path.resolve(__dirname, '..', '..');
const startedAt = new Date().toISOString();
const taskResults = [];
const events = [];
let browser = null;
let browserDriver = '';
let page = null;
let previewTimer = null;
let lastBrowserWindows = [];
let requestedBrowserEngine = null;
let activeBrowserEngine = null;
let browserFallbackReason = null;

function now() {
  return new Date().toISOString();
}

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, JSON.stringify(payload, null, 2));
}

function cleanForJson(value, depth = 0) {
  if (depth > 8) {
    return '[max-depth]';
  }

  if (value === null || value === undefined) {
    return value;
  }

  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => cleanForJson(item, depth + 1));
  }

  if (typeof value === 'object') {
    const result = {};

    for (const [key, item] of Object.entries(value)) {
      if (['browser', 'page', 'pages', 'context', '__workflowPreviewTimer'].includes(key)) {
        continue;
      }

      if (typeof item === 'function') {
        continue;
      }

      result[key] = cleanForJson(item, depth + 1);
    }

    return result;
  }

  return String(value);
}

function publicAccount(account = null) {
  if (!account || typeof account !== 'object') {
    return null;
  }

  const copy = { ...account };

  delete copy.password;
  delete copy.password_encrypted;

  if (account.password || account.password_encrypted || account.hasPassword === true) {
    copy.hasPassword = true;
  }

  return copy;
}

function statusPayload(state, stage, message, extra = {}) {
  return {
    runId: runtime.runId,
    workflow: runtime.workflow || null,
    workflowRunId: runtime.workflowRunId,
    workflowRunUuid: runtime.workflowRunUuid,
    workflowStepId: runtime.workflowStepId,
    workflowStepRunId: runtime.workflowStepRunId,
    workflowStepName: runtime.workflowStepName,
    workflowStepType: runtime.workflowStepType,
    state,
    stage,
    message,
    isRunning: ['queued', 'starting', 'running'].includes(state),
    startedAt,
    at: now(),
    livePreviewEnabled: runtime.livePreviewEnabled !== false,
    livePreviewIntervalSeconds: Number(runtime.livePreviewIntervalSeconds || 3),
    livePreviewPollIntervalSeconds: Number(runtime.livePreviewPollIntervalSeconds || runtime.livePreviewIntervalSeconds || 3),
    scriptName: runtime.scriptName || 'run_step.cjs',
    scriptVersions: {
      browserLauncher: BROWSER_LAUNCHER_SCRIPT_VERSION || 1,
    },
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    browserProfilePath: runtime.browserProfilePath || null,
    tasks: runtime.tasks.map((task) => {
      const result = taskResults.find((candidate) => candidate.key === task.key);

      if (result) {
        return { ...task, ...result };
      }

      return { ...task, status: task.status || 'configured' };
    }),
    events,
    browserWindows: lastBrowserWindows,
    ...extra,
  };
}

function pushEvent(stage, message, extra = {}) {
  events.push({ at: now(), stage, message, ...extra });

  if (events.length > 100) {
    events.splice(0, events.length - 100);
  }
}

function writeStatus(state, stage, message, extra = {}) {
  writeJson(runtime.statusPath, statusPayload(state, stage, message, extra));
}

function valueFromPath(source, keyPath) {
  const segments = String(keyPath || '').split('.').filter(Boolean);
  let current = source;

  for (const segment of segments) {
    if (!current || typeof current !== 'object' || !(segment in current)) {
      return undefined;
    }

    current = current[segment];
  }

  return current;
}

function resolveString(value, context = {}) {
  const normalized = String(value ?? '').trim();

  if (!normalized.includes('.') || normalized.includes('://')) {
    return value;
  }

  const workflow = runtime.workflow || {};
  const lookupRoot = {
    ...workflow,
    workflow,
    person: context.person || workflow.person || null,
    account: context.account || context.lastResult?.account || workflow.account || null,
    email_account: context.account || context.lastResult?.account || workflow.email_account || null,
  };
  const resolved = valueFromPath(lookupRoot, normalized);

  if (resolved === undefined || resolved === null || resolved === '') {
    return /^(person|account|email_account|workflow)\./.test(normalized) ? '' : value;
  }

  return resolved;
}

function taskInput(task, context = {}) {
  const input = {
    ...task,
    value: resolveString(task.value ?? task.input ?? '', context),
    inputValue: resolveString(task.input ?? task.value ?? '', context),
    input_value: resolveString(task.input ?? task.value ?? '', context),
    url: resolveString(task.url ?? task.value ?? task.input ?? '', context),
    selector: task.selector || task.element_selector || task.input_selector || '',
    elementSelector: task.element_selector || task.selector || '',
    element_selector: task.element_selector || task.selector || '',
    inputSelector: task.input_selector || task.selector || '',
    input_selector: task.input_selector || task.selector || '',
  };

  if (task.task_key === 'wait.seconds') {
    input.seconds = task.value || task.input || 0;
  }

  return input;
}

function runtimePerson() {
  return runtime.workflow && typeof runtime.workflow === 'object' && runtime.workflow.person
    ? runtime.workflow.person
    : null;
}

async function runDataTask(task, context = {}) {
  const person = runtimePerson();
  const account = person && typeof person === 'object' && person.emailAccount
    ? person.emailAccount
    : runtime.workflow?.account || runtime.workflow?.email_account || null;
  const password = String(account?.password || '').trim();
  const hasPassword = account?.hasPassword === true || password !== '';
  const publicAccount = account ? {
    provider: account.provider || 'proton',
    email: String(account.email || person?.email || '').trim(),
    username: String(account.username || account.email || person?.email || '').trim(),
    password,
    webmailUrl: account.webmailUrl || '',
  } : null;
  const taskKey = String(task.task_key || '').trim();
  const handler = String(task.php_handler || '').trim();

  if (taskKey === 'data.resolve_person' || handler.includes('ResolvePersonDataTask')) {
    if (!person) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine Person fuer den Workflow-Task gefunden. Bitte den Test mit einer Person starten.',
      };
    }

    context.person = person;

    return {
      ok: true,
      status: 'success',
      statusMessage: 'Person-Daten wurden ermittelt.',
      person,
    };
  }

  if (taskKey === 'data.read_login_data' || handler.includes('ReadLoginDataTask')) {
    if (!person) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine Person fuer Login-Daten gefunden. Bitte den Test mit einer Person starten.',
      };
    }

    const email = String(publicAccount?.email || person.email || '').trim();
    const username = String(publicAccount?.username || email).trim();

    if (!email || !username || !hasPassword) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Login-Daten sind unvollstaendig.',
        account: {
          provider: publicAccount?.provider || 'proton',
          email,
          username,
          webmailUrl: publicAccount?.webmailUrl || '',
          hasPassword,
        },
      };
    }

    context.person = person;
    context.account = {
      provider: publicAccount?.provider || 'proton',
      email,
      username,
      password,
      webmailUrl: publicAccount?.webmailUrl || '',
    };

    return {
      ok: true,
      status: 'success',
      statusMessage: 'Login-Daten wurden vorbereitet.',
      person,
      account: {
        provider: context.account.provider,
        email: context.account.email,
        username: context.account.username,
        webmailUrl: context.account.webmailUrl,
        hasPassword: true,
      },
    };
  }

  if (taskKey === 'data.read_account_data' || handler.includes('ReadAccountDataTask')) {
    const resultAccount = context.account || context.lastResult?.account || account || null;

    if (!resultAccount) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine Accountdaten im Ergebnis gefunden.',
      };
    }

    context.account = resultAccount;

    return {
      ok: true,
      status: 'success',
      statusMessage: 'Accountdaten wurden gelesen.',
      account: resultAccount,
    };
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: `PHP-Task wird vom Workflow-Runner noch nicht unterstuetzt: ${taskKey || handler || '-'}`,
  };
}

async function loadBrowser() {
  if (browser) {
    return browser;
  }

  requestedBrowserEngine = resolveBrowserEngine(runtime);

  const launchOptions = {
    headless: runtime.headlessEnabled === true ? 'new' : false,
    userDataDir: runtime.browserProfilePath,
    defaultViewport: { width: 1366, height: 900 },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--window-size=1366,900',
    ],
  };

  if (runtime.browserProfilePath) {
    fs.mkdirSync(runtime.browserProfilePath, { recursive: true });
  }

  const launchResult = await launchConfiguredBrowserWithProfileRetry({
    puppeteer,
    runtimeConfig: runtime,
    launchOptions,
    onProfileRetry: ({ previousProfilePath, nextProfilePath, error }) => {
      pushEvent('browser-profile-lock-retry', 'Browser-Profil war gesperrt; neuer Profilordner wird verwendet.', {
        previousBrowserProfilePath: previousProfilePath,
        browserProfilePath: nextProfilePath,
        profileLockError: String(error?.message || error).slice(0, 1200),
      });
      writeStatus('starting', 'browser-profile-lock-retry', 'Browser-Profil war gesperrt; neuer Profilordner wird verwendet.');
    },
  });

  browser = launchResult.browser;
  browserDriver = 'puppeteer';
  activeBrowserEngine = launchResult.activeEngine;
  browserFallbackReason = launchResult.fallbackReason;
  pushEvent('browser-started', 'Browser wurde gestartet.', {
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
  });

  return browser;
}

function patchPuppeteerPage(nextPage) {
  if (browserDriver !== 'puppeteer') {
    return nextPage;
  }

  if (!nextPage || nextPage.__workflowPatched) {
    return nextPage;
  }

  const originalWaitForSelector = nextPage.waitForSelector.bind(nextPage);
  nextPage.waitForSelector = (selector, options = {}) => {
    const normalizedOptions = { ...options };

    if (normalizedOptions.state) {
      normalizedOptions.visible = normalizedOptions.state === 'visible';
      delete normalizedOptions.state;
    }

    return originalWaitForSelector(selector, normalizedOptions);
  };

  nextPage.locator = (selector) => ({
    first() {
      return this;
    },
    async count() {
      return (await nextPage.$$(selector)).length;
    },
    async fill(value, options = {}) {
      await nextPage.waitForSelector(selector, { visible: true, timeout: options.timeout });
      await nextPage.$eval(selector, (element, nextValue) => {
        element.focus();
        element.value = nextValue;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }, value);
    },
    async click(options = {}) {
      await nextPage.waitForSelector(selector, { visible: true, timeout: options.timeout });
      await nextPage.click(selector);
    },
  });

  nextPage.getByText = (text) => ({
    first() {
      return this;
    },
    async count() {
      return nextPage.evaluate((needle) => {
        const normalizedNeedle = String(needle).toLowerCase();

        return Array.from(document.querySelectorAll('a,button,[role=button],input[type=button],input[type=submit]'))
          .filter((element) => String(element.innerText || element.value || '').toLowerCase().includes(normalizedNeedle))
          .length;
      }, text);
    },
    async click() {
      const clicked = await nextPage.evaluate((needle) => {
        const normalizedNeedle = String(needle).toLowerCase();
        const target = Array.from(document.querySelectorAll('a,button,[role=button],input[type=button],input[type=submit]'))
          .find((element) => String(element.innerText || element.value || '').toLowerCase().includes(normalizedNeedle));

        if (!target) {
          return false;
        }

        target.click();

        return true;
      }, text);

      if (!clicked) {
        throw new Error(`Kein Textziel gefunden: ${text}`);
      }
    },
  });

  nextPage.__workflowPatched = true;

  return nextPage;
}

async function ensurePage(context) {
  if (page) {
    return page;
  }

  const currentBrowser = await loadBrowser();
  page = patchPuppeteerPage(await currentBrowser.newPage());
  context.page = page;
  context.pages = [page];

  return page;
}

function startPreviewLoop(context) {
  if (previewTimer || runtime.livePreviewEnabled === false) {
    return;
  }

  const intervalMs = Math.max(1000, Number(runtime.livePreviewIntervalMs || 3000));

  previewTimer = setInterval(async () => {
    try {
      const preview = await captureTaskPreview(context, {}, false);

      if (Array.isArray(preview.browserWindows)) {
        lastBrowserWindows = preview.browserWindows;
        writeStatus('running', 'browser-preview', 'Browser-Screenshot aktualisiert.');
      }
    } catch (error) {
      pushEvent('browser-preview-failed', error.message);
    }
  }, intervalMs);

  if (typeof previewTimer.unref === 'function') {
    previewTimer.unref();
  }
}

function stopPreviewLoop(context) {
  if (previewTimer) {
    clearInterval(previewTimer);
    previewTimer = null;
  }

  stopTaskPreview(context);
}

async function run() {
  writeStatus('starting', 'starting', 'Workflow-Task-Runner startet.');
  pushEvent('starting', 'Workflow-Task-Runner startet.');

  const context = {
    workflow: runtime.workflow || {},
    preview: {
      enabled: runtime.livePreviewEnabled !== false,
      livePreviewPath: runtime.livePreviewPath,
      livePreviewRelativePath: runtime.livePreviewRelativePath,
      intervalMs: runtime.livePreviewIntervalMs || 3000,
    },
    livePreviewEnabled: runtime.livePreviewEnabled !== false,
    livePreviewIntervalMs: runtime.livePreviewIntervalMs || 3000,
    livePreviewIntervalSeconds: runtime.livePreviewIntervalSeconds || 3,
    livePreviewPath: runtime.livePreviewPath,
    livePreviewRelativePath: runtime.livePreviewRelativePath,
    timeoutMs: runtime.observationTimeoutMs || 90000,
    pages: [],
  };

  for (const task of runtime.tasks || []) {
    const taskStartedAt = now();
    const taskLabel = task.title || task.task_key || task.key || 'Task';

    pushEvent('task-started', taskLabel, { taskKey: task.key, taskType: task.task_key });
    taskResults.push({ key: task.key, title: taskLabel, status: 'running', startedAt: taskStartedAt });
    writeStatus('running', 'task-started', taskLabel);

    let result;

    try {
      if (task.runner === 'php') {
        result = await runDataTask(task, context);
      } else if (task.runner !== 'node') {
        throw new Error(`Runner wird vom Node-Orchestrator nicht unterstuetzt: ${task.runner || '-'}`);
      } else {

        if (!task.node_script) {
          throw new Error('Task hat kein node_script.');
        }

        const scriptPath = path.resolve(basePath, task.node_script);
        const module = require(scriptPath);

        if (!module || typeof module.run !== 'function') {
          throw new Error(`Task-Script exportiert keine run()-Funktion: ${task.node_script}`);
        }

        if (task.kind !== 'data') {
          await ensurePage(context);
          startPreviewLoop(context);
        }

        context.browser = browser;
        context.page = page;
        context.input = taskInput(task, context);
        context.timeoutMs = Math.max(1000, Number(task.timeout_seconds || 60) * 1000);

        result = await module.run(context);

        if (result && result.page) {
          page = patchPuppeteerPage(result.page);
          context.page = page;
          context.pages = Array.from(new Set([...(context.pages || []), page]));
        }
      }

      result = cleanForJson(result || {});
      context.lastResult = result;
    } catch (error) {
      result = {
        ok: false,
        status: 'failed',
        statusMessage: error.message,
        error: error.stack || error.message,
      };
    }

    if (Array.isArray(result.browserWindows)) {
      lastBrowserWindows = result.browserWindows;
    }

    const ok = result.ok !== false && !['failed', 'timeout'].includes(String(result.status || ''));
    const status = ok ? String(result.status || 'success') : String(result.status || 'failed');
    const current = taskResults.find((candidate) => candidate.key === task.key);
    Object.assign(current, {
      ...cleanForJson(task),
      ...result,
      status,
      finishedAt: now(),
    });

    pushEvent(ok ? 'task-completed' : 'task-failed', result.statusMessage || taskLabel, { taskKey: task.key, status });
    writeStatus('running', ok ? 'task-completed' : 'task-failed', result.statusMessage || taskLabel);

    if (!ok) {
      const failedResult = {
        ok: false,
        status,
        statusMessage: result.statusMessage || `Task fehlgeschlagen: ${taskLabel}`,
        failedTaskKey: task.key,
        account: publicAccount(context.account),
        tasks: taskResults,
        browserWindows: lastBrowserWindows,
        events,
        finishedAt: now(),
      };
      writeJson(runtime.resultPath, failedResult);
      writeStatus('failed', 'failed', failedResult.statusMessage, { result: failedResult });

      return;
    }
  }

  const finalPreview = await captureTaskPreview(context, {}, true).catch(() => ({}));

  if (Array.isArray(finalPreview.browserWindows)) {
    lastBrowserWindows = finalPreview.browserWindows;
  }

  const result = {
    ok: true,
    status: 'success',
    statusMessage: 'Workflow-Tasks wurden ausgefuehrt.',
    account: publicAccount(context.account),
    tasks: taskResults,
    browserWindows: lastBrowserWindows,
    events,
    finishedAt: now(),
  };

  writeJson(runtime.resultPath, result);
  writeStatus('completed', 'completed', result.statusMessage, { result });
}

run()
  .catch((error) => {
    const result = {
      ok: false,
      status: 'failed',
      statusMessage: error.message,
      error: error.stack || error.message,
      tasks: taskResults,
      browserWindows: lastBrowserWindows,
      events,
      finishedAt: now(),
    };
    writeJson(runtime.resultPath, result);
    writeStatus('failed', 'failed', error.message, { result });
  })
  .finally(async () => {
    const context = { __workflowPreviewTimer: null };
    stopPreviewLoop(context);

    if (browser && typeof browser.close === 'function') {
      await browser.close().catch(() => {});
    }
  });
