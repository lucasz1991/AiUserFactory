'use strict';

const fs = require('fs');
const path = require('path');
const { captureTaskPreview, stopTaskPreview } = require('./tasks/lib/preview.cjs');
const { parseExtendedSelector } = require('./lib/selector.cjs');
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
const embeddedWorkflowResults = new Map();
let browser = null;
let browserDriver = '';
let page = null;
const browserWindowsByName = new Map();
let previewTimer = null;
let lastBrowserWindows = initialBrowserWindowsFromWorkflow();
let requestedBrowserEngine = null;
let activeBrowserEngine = null;
let browserFallbackReason = null;
let connectedToExistingBrowser = false;
let browserDisconnected = false;
let shutdownInProgress = false;

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

function publicAccount(account = null, includePassword = false) {
  if (!account || typeof account !== 'object') {
    return null;
  }

  const copy = { ...account };

  if (!includePassword) {
    delete copy.password;
  }

  delete copy.passwordEncrypted;
  delete copy.password_encrypted;
  delete copy.webmailSession;
  delete copy.webmail_session;

  if (account.password || account.password_encrypted || account.hasPassword === true) {
    copy.hasPassword = true;
  }

  return copy;
}

function redactPublicSecrets(value) {
  const copy = cleanForJson(value);

  const scrub = (item) => {
    if (!item || typeof item !== 'object') {
      return item;
    }

    if (Array.isArray(item)) {
      return item.map(scrub);
    }

    for (const key of Object.keys(item)) {
      if ([
        'password',
        'passwordEncrypted',
        'password_encrypted',
        'webmailSession',
        'webmail_session',
        'webmailSessionPayload',
        'webmail_session_payload',
        'sessionPayload',
        'session_payload',
        'webmailSessionFilePath',
        'webmail_session_file_path',
        'browserSessionFilePath',
        'browser_session_file_path',
        'browserSessionPayload',
        'browser_session_payload',
        'encryptedBrowserSessionPayload',
        'payload_encrypted',
        'browser_sessions',
      ].includes(key)) {
        delete item[key];
        continue;
      }

      item[key] = scrub(item[key]);
    }

    return item;
  };

  return scrub(copy);
}

function publicWorkflow(workflow = null) {
  if (!workflow || typeof workflow !== 'object') {
    return null;
  }

  const copy = cleanForJson(workflow);
  delete copy.browser;
  delete copy.browser_runtime;
  delete copy.browserWsEndpoint;
  delete copy.browser_ws_endpoint;

  for (const key of ['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account']) {
    if (copy[key] && typeof copy[key] === 'object') {
      delete copy[key].password;
      delete copy[key].passwordEncrypted;
      delete copy[key].password_encrypted;
      delete copy[key].webmailSession;
      delete copy[key].webmail_session;
    }
  }

  if (copy.person?.emailAccount && typeof copy.person.emailAccount === 'object') {
    delete copy.person.emailAccount.password;
    delete copy.person.emailAccount.password_encrypted;
    delete copy.person.emailAccount.passwordEncrypted;
    delete copy.person.emailAccount.webmailSession;
    delete copy.person.emailAccount.webmail_session;
  }

  if (copy.person?.metadata && typeof copy.person.metadata === 'object') {
    delete copy.person.metadata.browser_sessions;

    if (copy.person.metadata.email_account && typeof copy.person.metadata.email_account === 'object') {
      delete copy.person.metadata.email_account.webmail_session;
    }
  }

  if (copy.person && typeof copy.person === 'object') {
    delete copy.person.password;
    delete copy.person.passwordEncrypted;
    delete copy.person.password_encrypted;
  }

  return copy;
}

function statusPayload(state, stage, message, extra = {}) {
  const publicExtra = redactPublicSecrets(extra);

  return {
    runId: runtime.runId,
    workflow: publicWorkflow(runtime.workflow || null),
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
    browserWsEndpoint: browserWsEndpoint(),
    tasks: runtime.tasks.map((task) => {
      const result = taskResults.find((candidate) => candidate.key === task.key);

      if (result) {
        return redactPublicSecrets({ ...task, ...result });
      }

      return redactPublicSecrets({ ...task, status: task.status || 'configured' });
    }),
    events: redactPublicSecrets(events),
    browserWindows: lastBrowserWindows,
    ...publicExtra,
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

function trackBrowserLifecycle(nextBrowser) {
  if (!nextBrowser || typeof nextBrowser.on !== 'function') {
    return;
  }

  nextBrowser.on('disconnected', () => {
    browserDisconnected = true;
  });
}

function browserWsEndpoint() {
  if (!browser || typeof browser.wsEndpoint !== 'function') {
    return '';
  }

  try {
    return String(browser.wsEndpoint() || '');
  } catch {
    return '';
  }
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

function normalizeMailboxSource(value) {
  const normalized = String(value ?? '').trim().toLowerCase();

  return ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'].includes(normalized)
    ? 'verification'
    : 'person';
}

function scopedWorkflowContext(context = {}, mailboxSource = 'person') {
  const source = normalizeMailboxSource(mailboxSource);
  const workflow = runtime.workflow || {};

  if (source !== 'verification') {
    return context;
  }

  const verificationAccount = context.verificationMailbox
    || context.verification_mailbox
    || context.veri_account
    || workflow.verificationMailbox
    || workflow.verification_mailbox
    || workflow.veri_account
    || workflow['veri-account']
    || null;

  if (!verificationAccount || typeof verificationAccount !== 'object') {
    return context;
  }

  const verificationPerson = {
    ...(context.person || workflow.person || {}),
    id: null,
    displayName: 'Verification Mailbox',
    firstName: '',
    lastName: '',
    email: verificationAccount.email || '',
    username: verificationAccount.username || verificationAccount.email || '',
    password: verificationAccount.password || '',
    provider: verificationAccount.provider || '',
    webmailUrl: verificationAccount.webmailUrl || verificationAccount.webmail_url || '',
    webmail_url: verificationAccount.webmail_url || verificationAccount.webmailUrl || '',
    hasPassword: verificationAccount.hasPassword ?? Boolean(verificationAccount.password),
    loginUsername: verificationAccount.username || verificationAccount.email || '',
    emailAccount: verificationAccount,
    email_account: verificationAccount,
    isVerificationMailbox: true,
  };

  return {
    ...context,
    person: verificationPerson,
    account: verificationAccount,
    verificationMailbox: verificationAccount,
    verification_mailbox: verificationAccount,
    veri_account: verificationAccount,
    'veri-account': verificationAccount,
    workflow: {
      ...workflow,
      person: verificationPerson,
      account: verificationAccount,
      email_account: verificationAccount,
    },
  };
}

function resolveString(value, context = {}) {
  const normalized = String(value ?? '').trim();

  const directRuntimeKeys = [
    'new_password',
    'generated_password',
    'generated-password',
    'new_mail_username',
    'new_mail_address',
    'verification_code',
    'verificationCode',
    'workflow_return',
    'workflowReturn',
    'workflow_return_ok',
  ];

  if ((!normalized.includes('.') && !directRuntimeKeys.includes(normalized)) || normalized.includes('://')) {
    return value;
  }

  const workflow = runtime.workflow || {};
  const verificationAccount = context.verificationMailbox
    || context.verification_mailbox
    || context.veri_account
    || workflow.verificationMailbox
    || workflow.verification_mailbox
    || workflow.veri_account
    || workflow['veri-account']
    || null;
  const basePerson = context.person || workflow.person || null;
  const personEmailAccount = (basePerson && typeof basePerson === 'object'
    ? (basePerson.emailAccount || basePerson.email_account || null)
    : null);
  const workflowAccount = workflow.account || workflow.email_account || null;
  const account = context.account
    || context.lastResult?.account
    || personEmailAccount
    || workflowAccount
    || verificationAccount
    || null;
  const personAccount = personEmailAccount || (basePerson ? (workflowAccount || context.account || verificationAccount) : account);
  const personForLookup = basePerson && typeof basePerson === 'object'
    ? {
      ...basePerson,
      email: personAccount?.email || basePerson.email || '',
      username: personAccount?.username || basePerson.username || basePerson.loginUsername || '',
      password: personAccount?.password || basePerson.password || '',
      provider: personAccount?.provider || basePerson.provider || '',
      webmailUrl: personAccount?.webmailUrl || personAccount?.webmail_url || basePerson.webmailUrl || basePerson.webmail_url || '',
      webmail_url: personAccount?.webmail_url || personAccount?.webmailUrl || basePerson.webmail_url || basePerson.webmailUrl || '',
      hasPassword: personAccount?.hasPassword ?? basePerson.hasPassword ?? Boolean(personAccount?.password || basePerson.password),
      emailAccount: personAccount || basePerson.emailAccount || basePerson.email_account || null,
      email_account: personAccount || basePerson.email_account || basePerson.emailAccount || null,
    }
    : (account ? {
      email: account.email || '',
      username: account.username || '',
      password: account.password || '',
      provider: account.provider || '',
      webmailUrl: account.webmailUrl || account.webmail_url || '',
      webmail_url: account.webmail_url || account.webmailUrl || '',
      hasPassword: account.hasPassword ?? Boolean(account.password),
      emailAccount: account,
      email_account: account,
      isVerificationMailbox: account === verificationAccount,
    } : null);
  const lookupRoot = {
    ...workflow,
    workflow,
    workflowVariables: context.workflowVariables || context.lastResult?.workflowVariables || {},
    workflow_variables: context.workflow_variables || context.lastResult?.workflow_variables || {},
    person: personForLookup,
    account,
    email_account: account,
    verificationMailbox: verificationAccount,
    verification_mailbox: verificationAccount,
    veri_account: verificationAccount,
    'veri-account': verificationAccount,
    new_password: context.new_password || context.generated_password || account?.password || context.lastResult?.new_password || '',
    generated_password: context.generated_password || context.new_password || account?.password || context.lastResult?.generated_password || context.lastResult?.new_password || '',
    'generated-password': context.generated_password || context.new_password || account?.password || context.lastResult?.['generated-password'] || context.lastResult?.generated_password || context.lastResult?.new_password || '',
    new_mail_username: account?.username || context.lastResult?.account?.username || '',
    new_mail_address: account?.email || context.lastResult?.account?.email || '',
    verification_code: context.verification_code || context.verificationCode || context.lastResult?.verification_code || context.lastResult?.verificationCode || '',
    verificationCode: context.verificationCode || context.verification_code || context.lastResult?.verificationCode || context.lastResult?.verification_code || '',
    workflow_return: context.workflow_return ?? context.workflowReturn ?? context.lastResult?.workflow_return ?? context.lastResult?.workflowReturn ?? '',
    workflowReturn: context.workflowReturn ?? context.workflow_return ?? context.lastResult?.workflowReturn ?? context.lastResult?.workflow_return ?? '',
    workflow_return_ok: context.workflow_return_ok ?? context.lastResult?.workflow_return_ok ?? '',
  };
  const resolved = valueFromPath(lookupRoot, normalized);

  if (resolved === undefined || resolved === null || resolved === '') {
    return /^(person|account|email_account|workflow|workflowVariables|workflow_variables|verificationMailbox|verification_mailbox|veri_account|veri-account)\./.test(normalized) || directRuntimeKeys.includes(normalized) ? '' : value;
  }

  return resolved;
}

function taskInput(task, context = {}) {
  const mailboxSource = normalizeMailboxSource(task.script_person_source || task.scriptPersonSource || task.mailbox_source || task.mailboxSource || 'person');
  const valueContext = scopedWorkflowContext(context, mailboxSource);
  const browserWindow = normalizeBrowserWindowName(
    task.browser_window_name
    || task.browser_window
    || task.browserWindowName
    || task.browserWindow
    || context.activeBrowserWindow
    || 'main',
  );
  const input = {
    ...task,
    browserWindow,
    browserWindowName: browserWindow,
    browser_window: browserWindow,
    browser_window_name: browserWindow,
    mailboxSource,
    mailbox_source: mailboxSource,
    scriptPersonSource: mailboxSource,
    script_person_source: mailboxSource,
    value: resolveString(task.value ?? task.input ?? '', valueContext),
    inputValue: resolveString(task.input ?? task.value ?? '', valueContext),
    input_value: resolveString(task.input ?? task.value ?? '', valueContext),
    url: resolveString(task.url ?? task.value ?? task.input ?? '', valueContext),
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

function normalizeBrowserWindowName(value) {
  const normalized = String(value || '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[^A-Za-z0-9._-]+/g, '')
    .toLowerCase()
    .slice(0, 80);

  return normalized || 'main';
}

function browserWindowNameForTask(task = {}, input = {}) {
  return normalizeBrowserWindowName(
    input.browserWindowName
    || input.browserWindow
    || input.browser_window_name
    || input.browser_window
    || task.browser_window_name
    || task.browser_window
    || task.browserWindowName
    || task.browserWindow
    || 'main',
  );
}

function browserWindowLabel(name) {
  return name === 'main' ? 'Main' : name;
}

function startedFromFailureRoute() {
  const workflow = runtime.workflow || {};
  const outcome = String(
    workflow.nextTaskRouteOutcome
    || workflow.next_task_route_outcome
    || '',
  ).trim().toLowerCase();

  return ['failed', 'timeout'].includes(outcome);
}

function workflowBrowserWindowState(windowName = 'main') {
  const normalizedName = normalizeBrowserWindowName(windowName);
  const workflow = runtime.workflow || {};
  const windows = workflow.browserWindows || workflow.browser_windows || {};

  if (Array.isArray(windows)) {
    return windows.find((windowEntry) => {
      const key = normalizeBrowserWindowName(
        windowEntry?.key
        || windowEntry?.name
        || windowEntry?.browserWindow
        || windowEntry?.browser_window
        || '',
      );

      return key === normalizedName;
    }) || null;
  }

  if (windows && typeof windows === 'object') {
    return windows[normalizedName] || null;
  }

  return null;
}

function workflowBrowserRuntime() {
  const workflow = runtime.workflow || {};
  const browserRuntime = workflow.browser || workflow.browser_runtime || {};

  return {
    wsEndpoint: String(
      browserRuntime.wsEndpoint
      || browserRuntime.ws_endpoint
      || workflow.browserWsEndpoint
      || workflow.browser_ws_endpoint
      || '',
    ).trim(),
  };
}

function workflowHasBrowserWindows() {
  const workflow = runtime.workflow || {};
  const windows = workflow.browserWindows || workflow.browser_windows || {};

  if (Array.isArray(windows)) {
    return windows.length > 0;
  }

  return windows && typeof windows === 'object' && Object.keys(windows).length > 0;
}

function initialBrowserWindowsFromWorkflow() {
  const workflow = runtime.workflow || {};
  const windows = workflow.browserWindows || workflow.browser_windows || {};

  if (Array.isArray(windows)) {
    return windows.filter((windowEntry) => windowEntry && typeof windowEntry === 'object');
  }

  if (windows && typeof windows === 'object') {
    return Object.entries(windows)
      .map(([key, windowEntry]) => {
        if (!windowEntry || typeof windowEntry !== 'object') {
          return null;
        }

        return {
          key: windowEntry.key || key,
          ...windowEntry,
        };
      })
      .filter(Boolean);
  }

  return [];
}

function pageTargetId(candidatePage) {
  if (!candidatePage || typeof candidatePage.target !== 'function') {
    return '';
  }

  try {
    return String(candidatePage.target()?._targetId || '');
  } catch {
    return '';
  }
}

async function existingPageForWindow(currentBrowser, windowName = 'main') {
  const state = workflowBrowserWindowState(windowName);
  const targetId = String(state?.targetId || state?.target_id || '').trim();
  const url = String(state?.url || '').trim();

  if (!currentBrowser || typeof currentBrowser.pages !== 'function') {
    return null;
  }

  const pages = await currentBrowser.pages().catch(() => []);
  const openPages = pages.filter((candidatePage) => (
    candidatePage
    && typeof candidatePage.screenshot === 'function'
    && (!candidatePage.isClosed || !candidatePage.isClosed())
  ));

  if (targetId !== '') {
    const exactPage = openPages.find((candidatePage) => pageTargetId(candidatePage) === targetId);

    if (exactPage) {
      return exactPage;
    }
  }

  if (/^https?:\/\//i.test(url)) {
    const exactUrlPage = openPages.find((candidatePage) => (
      typeof candidatePage.url === 'function'
      && String(candidatePage.url() || '') === url
    ));

    if (exactUrlPage) {
      return exactUrlPage;
    }
  }

  return openPages.find((candidatePage) => /^https?:\/\//i.test(String(candidatePage.url?.() || '')))
    || openPages.find((candidatePage) => String(candidatePage.url?.() || '') === 'about:blank')
    || null;
}

async function pageIsUsable(candidatePage) {
  if (
    !candidatePage
    || typeof candidatePage.screenshot !== 'function'
    || (candidatePage.isClosed && candidatePage.isClosed())
  ) {
    return false;
  }

  if (typeof candidatePage.evaluate !== 'function') {
    return true;
  }

  return candidatePage.evaluate(() => document.readyState).then(() => true).catch(() => false);
}

async function restoreBrowserWindowState(context, nextPage, windowName = 'main') {
  const state = workflowBrowserWindowState(windowName);
  const url = String(state?.url || '').trim();

  if (!state || !/^https?:\/\//i.test(url)) {
    return false;
  }

  const currentUrl = typeof nextPage.url === 'function' ? String(nextPage.url() || '') : '';

  if (currentUrl && currentUrl !== 'about:blank') {
    return false;
  }

  try {
    await nextPage.goto(url, {
      waitUntil: 'domcontentloaded',
      timeout: Number(runtime.navigationTimeoutMs || 120000),
    });
    pushEvent('browser-window-restored', 'Browserfenster wurde aus dem Workflow-Kontext wiederhergestellt.', {
      browserWindow: normalizeBrowserWindowName(windowName),
      url,
    });

    return true;
  } catch (error) {
    pushEvent('browser-window-restore-failed', error.message, {
      browserWindow: normalizeBrowserWindowName(windowName),
      url,
    });

    return false;
  }
}

async function runDataTask(task, context = {}) {
  const mailboxSource = normalizeMailboxSource(task.script_person_source || task.scriptPersonSource || task.mailbox_source || task.mailboxSource || 'person');
  const scopedContext = scopedWorkflowContext(context, mailboxSource);
  const person = scopedContext.person || runtimePerson();
  const account = person && typeof person === 'object' && person.emailAccount
    ? person.emailAccount
    : scopedContext.account || scopedContext.workflow?.account || scopedContext.workflow?.email_account || runtime.workflow?.account || runtime.workflow?.email_account || null;
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
  const existingRuntime = workflowBrowserRuntime();
  let staleWorkflowBrowserEndpoint = false;

  if (existingRuntime.wsEndpoint) {
    try {
      browser = await puppeteer.connect({
        browserWSEndpoint: existingRuntime.wsEndpoint,
        defaultViewport: { width: 1366, height: 900 },
      });
      trackBrowserLifecycle(browser);
      browserDriver = 'puppeteer';
      activeBrowserEngine = 'connected';
      connectedToExistingBrowser = true;
      pushEvent('workflow-browser-active', 'Workflow-Browser ist aktiv und wird fuer diese Liste genutzt.');

      return browser;
    } catch (error) {
      const connectError = String(error?.message || error);
      pushEvent('browser-connect-failed', 'Workflow-Browser konnte nicht erreicht werden.', {
        browserConnectError: connectError.slice(0, 1200),
      });

      if (!/(ECONNREFUSED|ECONNRESET|socket hang up|connect|closed|refused)/i.test(connectError)) {
        throw new Error('Workflow-Browser ist nicht erreichbar. Das Browserfenster wird nicht automatisch neu geoeffnet, weil es workflow-weit aktiv bleiben muss.');
      }

      staleWorkflowBrowserEndpoint = true;
      pushEvent('workflow-browser-recovering', 'Gespeicherter Workflow-Browser ist nicht erreichbar; Browser wird aus dem Workflow-Kontext wiederhergestellt.');
    }
  }

  if (workflowHasBrowserWindows() && !staleWorkflowBrowserEndpoint) {
    throw new Error('Workflow-Kontext enthaelt aktive Browserfenster, aber keinen erreichbaren Workflow-Browser. Bitte das Browserfenster per Task schliessen oder den Workflow neu starten.');
  }

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
  trackBrowserLifecycle(browser);
  browserDriver = 'puppeteer';
  connectedToExistingBrowser = false;
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
  nextPage.waitForSelector = async (selector, options = {}) => {
    const normalizedOptions = { ...options };

    if (normalizedOptions.state) {
      normalizedOptions.visible = normalizedOptions.state === 'visible';
      delete normalizedOptions.state;
    }

    const extendedSelector = parseExtendedSelector(selector);

    if (extendedSelector) {
      const handle = await nextPage.waitForFunction((css, descendantCss, text, exact, visible) => {
        const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
        const expected = normalize(text).toLowerCase();
        const isVisible = (element) => {
          if (!visible) return true;

          const rect = element.getBoundingClientRect();
          const style = window.getComputedStyle(element);

          return rect.width > 0
            && rect.height > 0
            && style.visibility !== 'hidden'
            && style.display !== 'none';
        };

        return Array.from(document.querySelectorAll(css)).find((element) => {
          const textElements = descendantCss
            ? Array.from(element.querySelectorAll(descendantCss))
            : [element];
          const textMatches = textElements.some((textElement) => {
            const actual = normalize(textElement.innerText || textElement.textContent).toLowerCase();

            return exact ? actual === expected : actual.includes(expected);
          });

          return isVisible(element) && textMatches;
        }) || false;
      }, {
        timeout: normalizedOptions.timeout,
        polling: 100,
      }, extendedSelector.css, extendedSelector.descendantCss || null, extendedSelector.text, extendedSelector.exact, normalizedOptions.visible === true);

      return handle.asElement();
    }

    return originalWaitForSelector(selector, normalizedOptions);
  };

  nextPage.locator = (selector) => ({
    first() {
      return this;
    },
    async count() {
      const extendedSelector = parseExtendedSelector(selector);

      if (extendedSelector) {
        return nextPage.evaluate((css, descendantCss, text, exact) => {
          const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
          const expected = normalize(text);

          return Array.from(document.querySelectorAll(css))
            .filter((element) => {
              const textElements = descendantCss
                ? Array.from(element.querySelectorAll(descendantCss))
                : [element];

              return textElements.some((textElement) => {
                const actual = normalize(textElement.innerText || textElement.textContent);

                return exact ? actual === expected : actual.includes(expected);
              });
            })
            .length;
        }, extendedSelector.css, extendedSelector.descendantCss || null, extendedSelector.text, extendedSelector.exact);
      }

      return (await nextPage.$$(selector)).length;
    },
    async fill(value, options = {}) {
      const handle = await nextPage.waitForSelector(selector, { visible: true, timeout: options.timeout });
      await handle.evaluate((element, nextValue) => {
        element.focus();
        element.value = nextValue;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }, value);
      await handle.dispose?.().catch(() => {});
    },
    async click(options = {}) {
      const handle = await nextPage.waitForSelector(selector, { visible: true, timeout: options.timeout });
      await handle.click();
      await handle.dispose?.().catch(() => {});
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

function registerBrowserWindow(context, nextPage, windowName = 'main', label = '') {
  const normalizedName = normalizeBrowserWindowName(windowName);
  const patchedPage = patchPuppeteerPage(nextPage);
  const existing = browserWindowsByName.get(normalizedName);
  const windowConfig = {
    ...(existing || {}),
    key: normalizedName,
    name: normalizedName,
    windowName: normalizedName,
    browserWindow: normalizedName,
    browser_window: normalizedName,
    label: label || browserWindowLabel(normalizedName),
    page: patchedPage,
  };

  browserWindowsByName.set(normalizedName, windowConfig);
  context.browserWindows = Array.from(browserWindowsByName.values());
  context.windows = context.browserWindows;
  context.pages = Array.from(new Set(context.browserWindows.map((windowEntry) => windowEntry.page)));
  context.page = patchedPage;
  context.activeBrowserWindow = normalizedName;
  page = patchedPage;

  return patchedPage;
}

async function ensurePage(context, windowName = 'main', label = '') {
  const normalizedName = normalizeBrowserWindowName(windowName);
  const registered = browserWindowsByName.get(normalizedName);

  if (
    registered?.page
    && typeof registered.page.screenshot === 'function'
    && (!registered.page.isClosed || !registered.page.isClosed())
  ) {
    if (await pageIsUsable(registered.page)) {
      return registerBrowserWindow(context, registered.page, normalizedName, registered.label || label);
    }

    browserWindowsByName.delete(normalizedName);
    pushEvent('workflow-browser-window-stale', 'Gespeicherter Page-Handle ist nicht mehr nutzbar; Browserfenster wird neu zugeordnet.', {
      browserWindow: normalizedName,
    });
  }

  const currentBrowser = await loadBrowser();
  const existingPage = await existingPageForWindow(currentBrowser, normalizedName);

  if (existingPage) {
    await restoreBrowserWindowState(context, existingPage, normalizedName);

    pushEvent('workflow-browser-window-active', 'Workflow-Browserfenster ist aktiv.', {
      browserWindow: normalizedName,
      url: typeof existingPage.url === 'function' ? existingPage.url() : '',
      targetId: pageTargetId(existingPage),
    });

    return registerBrowserWindow(context, existingPage, normalizedName, label);
  }

  const nextPage = await currentBrowser.newPage();
  const registeredPage = registerBrowserWindow(context, nextPage, normalizedName, label);
  await restoreBrowserWindowState(context, registeredPage, normalizedName);

  return registeredPage;
}

function selectExistingPage(context, windowName = 'main') {
  const normalizedName = normalizeBrowserWindowName(windowName);
  const registered = browserWindowsByName.get(normalizedName);

  if (
    registered?.page
    && typeof registered.page.screenshot === 'function'
    && (!registered.page.isClosed || !registered.page.isClosed())
  ) {
    context.page = registered.page;
    context.activeBrowserWindow = normalizedName;
    page = registered.page;

    return registered.page;
  }

  context.page = null;
  context.activeBrowserWindow = normalizedName;

  return null;
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

async function openBrowserWindowCount() {
  if (!browser || typeof browser.pages !== 'function') {
    return 0;
  }

  const pages = await browser.pages().catch(() => []);

  return pages.filter((candidatePage) => (
    candidatePage
    && (!candidatePage.isClosed || !candidatePage.isClosed())
  )).length;
}

async function keepWorkflowBrowserAlive(state = 'completed', stage = 'workflow-browser-kept-active', message = 'Workflow-Browser bleibt aktiv.') {
  if (
    connectedToExistingBrowser
    || !browser
    || browserDisconnected
    || browserWindowsByName.size === 0
  ) {
    return;
  }

  pushEvent('workflow-browser-kept-active', 'Workflow-Browser bleibt aktiv bis ein Browser-schliessen-Task ihn beendet.');
  writeStatus(state, stage, message);

  await new Promise((resolve) => {
    const interval = setInterval(async () => {
      if (!browser || browserDisconnected) {
        clearInterval(interval);
        resolve();

        return;
      }

      const openWindows = await openBrowserWindowCount();

      if (openWindows <= 0) {
        clearInterval(interval);
        resolve();
      }
    }, 3000);
  });
}

async function closeWorkflowBrowser(state = 'cancelled') {
  if (!browser || browserDisconnected) {
    browserWindowsByName.clear();
    page = null;

    return;
  }

  if (connectedToExistingBrowser) {
    if (typeof browser.disconnect === 'function') {
      browser.disconnect();
    }

    browser = null;
    browserWindowsByName.clear();
    page = null;

    return;
  }

  const stage = state === 'cancelled' ? 'cancelled-browser-closing' : 'workflow-browser-closing';
  const message = state === 'cancelled'
    ? 'Workflow wurde gestoppt; Browser wird geschlossen.'
    : 'Workflow-Browser wird geschlossen.';

  pushEvent(stage, message);
  writeStatus(state, stage, message);

  if (typeof browser.close === 'function') {
    await browser.close().catch((error) => {
      pushEvent('workflow-browser-close-failed', error.message);
    });
  }

  browser = null;
  browserWindowsByName.clear();
  page = null;
}

async function finalizeBrowserLifecycle(state = 'completed') {
  const context = { __workflowPreviewTimer: null };
  stopPreviewLoop(context);

  if (state === 'cancelled') {
    await closeWorkflowBrowser(state);

    return;
  }

  await keepWorkflowBrowserAlive(
    state,
    state === 'cancelled' ? 'cancelled-browser-kept-active' : 'workflow-browser-kept-active',
    state === 'cancelled' ? 'Workflow wurde gestoppt; Browserfenster bleibt aktiv.' : 'Workflow-Browser bleibt aktiv.',
  );

  if (browser && connectedToExistingBrowser && typeof browser.disconnect === 'function') {
    browser.disconnect();
  } else if (browser && browserWindowsByName.size === 0 && typeof browser.close === 'function') {
    await browser.close().catch(() => {});
  }
}

async function handleShutdownSignal(signal) {
  if (shutdownInProgress) {
    return;
  }

  shutdownInProgress = true;

  const result = {
    ok: false,
    status: 'cancelled',
    statusMessage: 'Workflow-Task-Lauf wurde gestoppt.',
    signal,
    tasks: taskResults,
    browserWindows: lastBrowserWindows,
    browserWsEndpoint: browserWsEndpoint(),
    events,
    finishedAt: now(),
  };

  pushEvent('cancelled', result.statusMessage, { signal });
  writeJson(runtime.resultPath, result);
  writeStatus('cancelled', 'cancelled', result.statusMessage, { result });

  try {
    await finalizeBrowserLifecycle('cancelled');
  } finally {
    process.exit(0);
  }
}

process.once('SIGTERM', () => {
  handleShutdownSignal('SIGTERM').catch(() => process.exit(0));
});

process.once('SIGINT', () => {
  handleShutdownSignal('SIGINT').catch(() => process.exit(0));
});

function embeddedFrameKeyForTask(task = {}) {
  return String(task?.embedded_workflow_frame_key || '').trim();
}

function embeddedBoundaryKeyForTask(task = {}) {
  return String(task?.embedded_workflow_boundary_key || '').trim();
}

function enclosingEmbeddedBoundaryKeyForTask(task = {}) {
  return String(task?.enclosing_embedded_workflow_boundary_key || '').trim();
}

function routeTargetCardKey(route = {}) {
  return String(route?.card_key || route?.card || '').trim();
}

function routeStepKey(route = {}) {
  return String(route?.action_key || route?.step || '').trim();
}

function routeType(route = {}) {
  return String(route?.type || route?.action_key || route?.step || '').trim().toLowerCase();
}

function routeHasExplicitTarget(route = {}) {
  const type = String(route?.type || '').trim().toLowerCase();
  const step = routeStepKey(route).toLowerCase();

  return routeTargetCardKey(route) !== ''
    || (step !== '' && !['next', 'end', 'fail'].includes(step))
    || type === 'card';
}

function routeMaxAttempts(route = {}) {
  const attempts = Number(route?.max_attempts ?? route?.retry_limit ?? 0);

  return Number.isFinite(attempts) ? Math.max(0, Math.floor(attempts)) : 0;
}

function routeAttemptKey(task = {}, route = {}, targetCardKey = '') {
  return [
    embeddedFrameKeyForTask(task),
    String(task?.key || ''),
    routeType(route),
    routeStepKey(route),
    targetCardKey,
  ].join(':');
}

function workflowBoundaryIndex(runtimeTasks = [], frameKey = '', fromIndex = -1, boundaryKey = '') {
  const normalizedBoundaryKey = String(boundaryKey || '').trim();

  if (normalizedBoundaryKey !== '') {
    const keyedBoundaryIndex = runtimeTasks.findIndex((candidate) => (
      String(candidate?.key || '') === normalizedBoundaryKey
      && candidate?.runner === 'workflow-boundary'
    ));

    if (keyedBoundaryIndex >= 0) {
      return keyedBoundaryIndex;
    }
  }

  const normalizedFrameKey = String(frameKey || '').trim();

  if (normalizedFrameKey === '') {
    return -1;
  }

  return runtimeTasks.findIndex((candidate, candidateIndex) => (
    candidateIndex > fromIndex
    && candidate?.runner === 'workflow-boundary'
    && embeddedFrameKeyForTask(candidate) === normalizedFrameKey
  ));
}

function embeddedBoundaryIndexForTask(runtimeTasks = [], task = {}, taskIndex = -1) {
  return workflowBoundaryIndex(
    runtimeTasks,
    embeddedFrameKeyForTask(task),
    taskIndex,
    embeddedBoundaryKeyForTask(task) || enclosingEmbeddedBoundaryKeyForTask(task),
  );
}

async function run() {
  pushEvent('starting', 'Workflow-Task-Runner startet.');
  writeStatus('starting', 'starting', 'Workflow-Task-Runner startet.');

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
    runDirectory: runtime.runDirectory || path.dirname(runtime.resultPath),
    workflowTaskRunDirectory: runtime.runDirectory || path.dirname(runtime.resultPath),
    timeoutMs: runtime.observationTimeoutMs || 90000,
    pages: [],
    browserWindows: lastBrowserWindows,
    windows: lastBrowserWindows,
    activeBrowserWindow: 'main',
  };

  const runtimeTasks = runtime.tasks || [];
  let taskIndex = 0;
  let requestedSuccessRouteTask = null;
  let requestedFailureRouteTask = null;
  let requestedRouteMessage = null;
  let routeTransitions = 0;
  const routeAttemptCounts = new Map();
  const preserveBrowserForFailureRoute = startedFromFailureRoute();

  while (taskIndex < runtimeTasks.length) {
    const task = runtimeTasks[taskIndex];
    const taskStartedAt = now();
    const taskLabel = task.title || task.task_key || task.key || 'Task';

    pushEvent('task-started', taskLabel, { taskKey: task.key, taskType: task.task_key });
    const existingTaskResult = taskResults.find((candidate) => candidate.key === task.key);

    if (existingTaskResult) {
      Object.assign(existingTaskResult, { title: taskLabel, status: 'running', startedAt: taskStartedAt });
    } else {
      taskResults.push({ key: task.key, title: taskLabel, status: 'running', startedAt: taskStartedAt });
    }

    writeStatus('running', 'task-started', taskLabel);

    let result;

    try {
      if (task.runner === 'workflow-boundary') {
        const frameKey = String(task.embedded_workflow_frame_key || '').trim();
        const workflowResult = embeddedWorkflowResults.get(frameKey) || {
          ok: true,
          value: true,
          explicit: false,
        };
        const workflowName = task.embedded_workflow_name || taskLabel;

        result = {
          ok: workflowResult.ok,
          status: workflowResult.ok ? 'success' : 'failed',
          statusMessage: workflowResult.explicit
            ? `Eingebetteter Workflow "${workflowName}" hat ${workflowResult.ok ? 'true' : 'false'} zurueckgegeben.`
            : `Eingebetteter Workflow "${workflowName}" wurde erfolgreich abgeschlossen.`,
          workflow_return: workflowResult.value,
          workflowReturn: workflowResult.value,
          workflow_return_ok: workflowResult.ok,
          embeddedWorkflowCompleted: true,
          embeddedWorkflowReturnExplicit: workflowResult.explicit,
          embeddedWorkflowBrowserWindow: task.embedded_workflow_browser_window || null,
          browserWindows: lastBrowserWindows,
          browserWsEndpoint: browserWsEndpoint(),
        };
      } else if (task.runner === 'php') {
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

        const input = taskInput(task, context);
        const targetBrowserWindow = browserWindowNameForTask(task, input);

        if (task.task_key === 'browser.close') {
          selectExistingPage(context, targetBrowserWindow);
        } else if (task.kind !== 'data' || ['data.persist_webmail_session', 'data.persist_browser_session', 'data.delete_browser_session'].includes(String(task.task_key || ''))) {
          await ensurePage(context, targetBrowserWindow, targetBrowserWindow === 'main' ? 'Main' : taskLabel);
          startPreviewLoop(context);
        }

        context.browser = browser;
        context.page = task.task_key === 'browser.close'
          ? context.page
          : (browserWindowsByName.get(targetBrowserWindow)?.page || page);
        context.activeBrowserWindow = targetBrowserWindow;
        context.input = input;
        context.timeoutMs = Math.max(1000, Number(task.timeout_seconds || 60) * 1000);
        context.refreshActivePage = async () => {
          const refreshedPage = await ensurePage(context, targetBrowserWindow, targetBrowserWindow === 'main' ? 'Main' : taskLabel);
          context.page = browserWindowsByName.get(targetBrowserWindow)?.page || refreshedPage || page;

          return context.page;
        };

        if (task.task_key === 'browser.close' && preserveBrowserForFailureRoute) {
          pushEvent('browser-close-skipped', 'Browserfenster bleibt offen, weil diese Task ueber eine Fehlerroute erreicht wurde.', {
            browserWindow: targetBrowserWindow,
            routeOutcome: runtime.workflow?.nextTaskRouteOutcome || runtime.workflow?.next_task_route_outcome || null,
            routeSourceTaskKey: runtime.workflow?.nextTaskRouteSourceKey || runtime.workflow?.next_task_route_source_key || null,
          });

          result = await captureTaskPreview(context, {
            ok: true,
            status: 'skipped',
            statusMessage: 'Browserfenster bleibt offen, weil diese Task ueber eine Fehlerroute erreicht wurde.',
            skippedBrowserClose: true,
            browserWindow: targetBrowserWindow,
          }, true).catch(() => ({
            ok: true,
            status: 'skipped',
            statusMessage: 'Browserfenster bleibt offen, weil diese Task ueber eine Fehlerroute erreicht wurde.',
            skippedBrowserClose: true,
            browserWindow: targetBrowserWindow,
          }));
        } else {
          result = await module.run(context);
        }

        if (task.task_key === 'browser.close' && !result?.skippedBrowserClose) {
          result = result || {};
          browserWindowsByName.delete(targetBrowserWindow);
          context.browserWindows = Array.from(browserWindowsByName.values());
          context.windows = context.browserWindows;
          context.pages = Array.from(new Set(context.browserWindows.map((windowEntry) => windowEntry.page)));
          context.page = context.pages[0] || null;
          page = context.page;

          if (Array.isArray(result?.browserWindows)) {
            result.browserWindows = result.browserWindows.filter((windowEntry) => {
              const key = normalizeBrowserWindowName(windowEntry?.key || windowEntry?.name || '');

              return key !== targetBrowserWindow;
            });
          }

          result.closedBrowserWindow = targetBrowserWindow;

          if (browserWindowsByName.size === 0 && browser && typeof browser.close === 'function') {
            await browser.close().catch(() => {});
            browser = null;
            result.closedBrowser = true;
          }
        }

        if (result && result.page) {
          registerBrowserWindow(context, result.page, targetBrowserWindow, targetBrowserWindow === 'main' ? 'Main' : taskLabel);
        }
      }

      result = cleanForJson(result || {});
      context.lastResult = result;

      const resultWorkflowVariables = {
        ...(result.workflow_variables && typeof result.workflow_variables === 'object' ? result.workflow_variables : {}),
        ...(result.workflowVariables && typeof result.workflowVariables === 'object' ? result.workflowVariables : {}),
      };

      if (Object.keys(resultWorkflowVariables).length > 0) {
        context.workflow_variables = {
          ...(context.workflow_variables || {}),
          ...resultWorkflowVariables,
        };
        context.workflowVariables = {
          ...(context.workflowVariables || {}),
          ...resultWorkflowVariables,
        };
      }

      if (Object.prototype.hasOwnProperty.call(result, 'workflow_return') || Object.prototype.hasOwnProperty.call(result, 'workflowReturn')) {
        const workflowReturn = Object.prototype.hasOwnProperty.call(result, 'workflow_return')
          ? result.workflow_return
          : result.workflowReturn;
        const workflowReturnOk = Object.prototype.hasOwnProperty.call(result, 'workflow_return_ok')
          ? result.workflow_return_ok
          : workflowReturn !== false;

        context.workflow_return = workflowReturn;
        context.workflowReturn = workflowReturn;
        context.workflow_return_ok = workflowReturnOk;
        context.workflow_variables = {
          ...(context.workflow_variables || {}),
          workflow_return: workflowReturn,
          workflow_return_ok: workflowReturnOk,
        };
        context.workflowVariables = {
          ...(context.workflowVariables || {}),
          workflow_return: workflowReturn,
          workflow_return_ok: workflowReturnOk,
        };
      }

      const generatedPassword = result.generated_password
        || result['generated-password']
        || result.new_password
        || result.account?.password
        || '';

      if (generatedPassword) {
        context.new_password = generatedPassword;
        context.generated_password = generatedPassword;
        context.account = {
          ...(context.account || {}),
          password: generatedPassword,
          hasPassword: true,
        };
      }

      const verificationCode = result.verification_code
        || result.verificationCode
        || result.workflow_variables?.verification_code
        || result.workflowVariables?.verificationCode
        || '';

      if (verificationCode) {
        context.verification_code = verificationCode;
        context.verificationCode = verificationCode;
        context.workflow_variables = {
          ...(context.workflow_variables || {}),
          verification_code: verificationCode,
          verificationCode,
        };
        context.workflowVariables = {
          ...(context.workflowVariables || {}),
          verification_code: verificationCode,
          verificationCode,
        };
      }
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

    const branchOutcome = String(result.branchOutcome || result.branch_outcome || '').trim().toLowerCase();
    const branchFailed = branchOutcome === 'failed';
    const ok = result.ok !== false && !['failed', 'timeout'].includes(String(result.status || ''));
    const status = ok ? String(result.status || 'success') : String(result.status || 'failed');
    const current = taskResults.find((candidate) => candidate.key === task.key);
    Object.assign(current, {
      ...cleanForJson(task),
      ...result,
      status,
      finishedAt: now(),
    });

    const taskEventStage = branchFailed
      ? 'task-condition-not-met'
      : (ok ? 'task-completed' : 'task-failed');
    pushEvent(taskEventStage, result.statusMessage || taskLabel, { taskKey: task.key, status, branchOutcome });
    writeStatus('running', taskEventStage, result.statusMessage || taskLabel);

    const embeddedWorkflowFrameKey = embeddedFrameKeyForTask(task);
    const hasWorkflowReturn = (
      Object.prototype.hasOwnProperty.call(result, 'workflow_return')
      || Object.prototype.hasOwnProperty.call(result, 'workflowReturn')
    );

    if (task.runner !== 'workflow-boundary' && embeddedWorkflowFrameKey !== '' && hasWorkflowReturn) {
      const workflowReturn = Object.prototype.hasOwnProperty.call(result, 'workflow_return')
        ? result.workflow_return
        : result.workflowReturn;
      const workflowReturnOk = Object.prototype.hasOwnProperty.call(result, 'workflow_return_ok')
        ? result.workflow_return_ok === true
        : workflowReturn !== false;
      const boundaryTaskIndex = embeddedBoundaryIndexForTask(runtimeTasks, task, taskIndex);

      if (boundaryTaskIndex >= 0) {
        embeddedWorkflowResults.set(embeddedWorkflowFrameKey, {
          ok: workflowReturnOk,
          value: workflowReturn,
          explicit: true,
        });
        pushEvent('embedded-workflow-returned', `Eingebetteter Workflow hat ${workflowReturnOk ? 'true' : 'false'} zurueckgegeben.`, {
          workflowFrameKey: embeddedWorkflowFrameKey,
          workflowReturn,
          workflowReturnOk,
        });
        taskIndex = boundaryTaskIndex;
        continue;
      }
    }

    if (!ok || branchFailed) {
      const failurePreview = branchFailed
        ? {}
        : await captureTaskPreview(context, result, true).catch(() => ({}));

      if (Array.isArray(failurePreview.browserWindows)) {
        lastBrowserWindows = failurePreview.browserWindows;
        result = {
          ...result,
          browserWindows: failurePreview.browserWindows,
        };
      }

      const failureRoute = task.on_error && typeof task.on_error === 'object'
        ? task.on_error
        : null;

      if (failureRoute) {
        const targetCardKey = routeTargetCardKey(failureRoute);
        const targetTaskIndex = targetCardKey === ''
          ? -1
          : runtimeTasks.findIndex((candidate) => String(candidate.key || '') === targetCardKey);
        const targetTask = targetTaskIndex >= 0 ? runtimeTasks[targetTaskIndex] : null;
        const currentEmbeddedFrameKey = embeddedFrameKeyForTask(task);
        const targetIsInSameEmbeddedFrame = currentEmbeddedFrameKey !== ''
          && embeddedFrameKeyForTask(targetTask) === currentEmbeddedFrameKey;
        const failureRouteType = routeType(failureRoute);
        const boundaryIndex = failureRouteType === 'end'
          ? embeddedBoundaryIndexForTask(runtimeTasks, task, taskIndex)
          : -1;

        if (boundaryIndex >= 0) {
          routeTransitions += 1;

          if (routeTransitions > Math.max(100, runtimeTasks.length * 20)) {
            throw new Error('Zu viele Task-Routenwechsel. Moegliche Schleife in der Fehlerroute.');
          }

          pushEvent('embedded-workflow-error-route-ended', 'Fehlerroute beendet den eingebetteten Workflow.', {
            taskKey: task.key,
            boundaryTaskKey: runtimeTasks[boundaryIndex]?.key || null,
            status,
          });
          taskIndex = boundaryIndex;
          continue;
        }

        const canFollowFailureRouteInNode = targetTaskIndex > taskIndex || targetIsInSameEmbeddedFrame;

        if (canFollowFailureRouteInNode) {
          const maxAttempts = routeMaxAttempts(failureRoute);
          const isBackRoute = targetTaskIndex <= taskIndex;

          if (isBackRoute && targetIsInSameEmbeddedFrame && maxAttempts > 0) {
            const attemptKey = routeAttemptKey(task, failureRoute, targetCardKey);
            const attempts = routeAttemptCounts.get(attemptKey) || 0;

            if (attempts >= maxAttempts) {
              requestedFailureRouteTask = {
                ...task,
                key: task.route_source_task_key || task.parent_task_key || task.key,
              };
              requestedRouteMessage = 'Fehlerroute im eingebetteten Workflow wurde zu oft wiederholt.';
              break;
            }

            routeAttemptCounts.set(attemptKey, attempts + 1);
          }

          routeTransitions += 1;

          if (routeTransitions > Math.max(100, runtimeTasks.length * 20)) {
            throw new Error('Zu viele Task-Routenwechsel. Moegliche Schleife in der Fehlerroute.');
          }

          pushEvent(branchFailed ? 'task-branch-route-followed' : 'task-error-route-followed', `Fehlerroute wird fortgesetzt: ${targetCardKey}.`, {
            taskKey: task.key,
            targetTaskKey: targetCardKey,
            status,
          });
          taskIndex = targetTaskIndex;
          continue;
        }

        if (
          currentEmbeddedFrameKey !== ''
          && task.runner !== 'workflow-boundary'
          && failureRouteType !== 'fail'
          && routeHasExplicitTarget(failureRoute)
        ) {
          requestedFailureRouteTask = {
            ...task,
            key: task.route_source_task_key || task.parent_task_key || task.key,
          };
          requestedRouteMessage = targetCardKey !== ''
            ? `Interne Fehlerroute im eingebetteten Workflow konnte nicht aufgeloest werden: ${targetCardKey}.`
            : 'Interne Fehlerroute im eingebetteten Workflow konnte nicht aufgeloest werden.';
          break;
        }
      }

      if (branchFailed) {
        requestedFailureRouteTask = {
          ...task,
          key: task.route_source_task_key || task.parent_task_key || task.key,
        };
        break;
      }

      const failedResult = {
        ok: false,
        status,
        statusMessage: result.statusMessage || `Task fehlgeschlagen: ${taskLabel}`,
        failedTaskKey: task.key,
        account: publicAccount(context.account, true),
        new_password: context.new_password || context.account?.password || null,
        generated_password: context.generated_password || context.new_password || context.account?.password || null,
        'generated-password': context.generated_password || context.new_password || context.account?.password || null,
        verification_code: context.verification_code || context.verificationCode || null,
        verificationCode: context.verificationCode || context.verification_code || null,
        workflow_return: context.workflow_return ?? context.workflowReturn ?? null,
        workflowReturn: context.workflowReturn ?? context.workflow_return ?? null,
        workflow_return_ok: context.workflow_return_ok ?? null,
        workflow_variables: context.workflow_variables || null,
        workflowVariables: context.workflowVariables || null,
        tasks: taskResults,
        browserWindows: lastBrowserWindows,
        browserWsEndpoint: browserWsEndpoint(),
        events,
        finishedAt: now(),
      };
      writeJson(runtime.resultPath, failedResult);
      writeStatus('failed', 'failed', failedResult.statusMessage, { result: failedResult });

      return;
    }

    const successRoute = task.next && typeof task.next === 'object' ? task.next : null;

    if (successRoute) {
      const successRouteType = routeType(successRoute);

      if (successRouteType === 'fail') {
        requestedFailureRouteTask = {
          ...task,
          key: task.route_source_task_key || task.parent_task_key || task.key,
        };
        break;
      }

      const targetCardKey = routeTargetCardKey(successRoute);
      const targetTaskIndex = targetCardKey === ''
        ? -1
        : runtimeTasks.findIndex((candidate) => String(candidate.key || '') === targetCardKey);

      if (targetTaskIndex >= 0) {
        routeTransitions += 1;

        if (routeTransitions > Math.max(100, runtimeTasks.length * 20)) {
          throw new Error('Zu viele Task-Routenwechsel. Moegliche Schleife in der Erfolgsroute.');
        }

        taskIndex = targetTaskIndex;
        continue;
      }

      const currentEmbeddedFrameKey = embeddedFrameKeyForTask(task);
      const isEmbeddedInternalRoute = currentEmbeddedFrameKey !== ''
        && (task.runner !== 'workflow-boundary' || enclosingEmbeddedBoundaryKeyForTask(task) !== '');

      if (isEmbeddedInternalRoute) {
        const boundaryIndex = successRouteType === 'end'
          ? embeddedBoundaryIndexForTask(runtimeTasks, task, taskIndex)
          : -1;

        if (boundaryIndex >= 0) {
          routeTransitions += 1;

          if (routeTransitions > Math.max(100, runtimeTasks.length * 20)) {
            throw new Error('Zu viele Task-Routenwechsel. Moegliche Schleife in der Erfolgsroute.');
          }

          taskIndex = boundaryIndex;
          continue;
        }

        if (!routeHasExplicitTarget(successRoute)) {
          taskIndex += 1;
          continue;
        }

        requestedFailureRouteTask = {
          ...task,
          key: task.route_source_task_key || task.parent_task_key || task.key,
        };
        requestedRouteMessage = targetCardKey !== ''
          ? `Interne Erfolgsroute im eingebetteten Workflow konnte nicht aufgeloest werden: ${targetCardKey}.`
          : 'Interne Erfolgsroute im eingebetteten Workflow konnte nicht aufgeloest werden.';
        break;
      }

      requestedSuccessRouteTask = {
        ...task,
        key: task.route_source_task_key || task.parent_task_key || task.key,
      };
      break;
    }

    taskIndex += 1;
  }

  const finalPreview = await captureTaskPreview(context, {}, true).catch(() => ({}));

  if (Array.isArray(finalPreview.browserWindows)) {
    lastBrowserWindows = finalPreview.browserWindows;
  }

  const result = {
    ok: true,
    status: 'success',
    statusMessage: requestedRouteMessage || 'Workflow-Tasks wurden ausgefuehrt.',
    account: publicAccount(context.account, true),
    new_password: context.new_password || context.account?.password || null,
    generated_password: context.generated_password || context.new_password || context.account?.password || null,
    'generated-password': context.generated_password || context.new_password || context.account?.password || null,
    verification_code: context.verification_code || context.verificationCode || null,
    verificationCode: context.verificationCode || context.verification_code || null,
    workflow_return: context.workflow_return ?? context.workflowReturn ?? null,
    workflowReturn: context.workflowReturn ?? context.workflow_return ?? null,
    workflow_return_ok: context.workflow_return_ok ?? null,
    workflow_variables: context.workflow_variables || null,
    workflowVariables: context.workflowVariables || null,
    tasks: taskResults,
    browserWindows: lastBrowserWindows,
    browserWsEndpoint: browserWsEndpoint(),
    events,
    finishedAt: now(),
    ...((requestedFailureRouteTask || requestedSuccessRouteTask) ? {
      routeRequested: true,
      completedTaskKey: (requestedFailureRouteTask || requestedSuccessRouteTask).key,
      routeOutcome: requestedFailureRouteTask ? 'failed' : 'success',
    } : {}),
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
      browserWsEndpoint: browserWsEndpoint(),
      events,
      finishedAt: now(),
    };
    writeJson(runtime.resultPath, result);
    writeStatus('failed', 'failed', error.message, { result });
  })
  .finally(async () => {
    if (!shutdownInProgress) {
      await finalizeBrowserLifecycle();
    }
  });
