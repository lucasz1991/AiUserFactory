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
const workflowTimezone = runtime.timeZone
  || runtime.timezone
  || runtime.workflow?.timeZone
  || runtime.workflow?.timezone
  || runtime.workflow?.person?.timezone
  || runtime.workflow?.person?.person_timezone
  || process.env.APP_TIMEZONE
  || process.env.TZ
  || 'Europe/Berlin';
process.env.TZ = workflowTimezone;
const basePath = path.resolve(__dirname, '..', '..');
const startedAt = new Date().toISOString();
const taskResults = [];
const events = [];
const debugArtifacts = [];
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
// Endzustand des Laufs, damit finalizeBrowserLifecycle den status.json nicht
// faelschlich von 'failed' auf 'completed' zurueckflippt.
let finalRunState = 'completed';
// Referenz auf den echten Task-Kontext, damit der Preview-Timer beim Abschluss
// wirklich gestoppt wird (frueher wurde ein Dummy-Objekt uebergeben -> Leak).
let activeRunContext = null;
// Verhindert doppelte Fatal-Behandlung durch parallele Crash-Signale.
let fatalErrorHandled = false;
let debugManifestDirty = false;
let debugManifestLastWriteAtMs = 0;
const DEBUG_MANIFEST_WRITE_INTERVAL_MS = 2000;
let pendingStatusWrite = null;
let pendingStatusWriteTimer = null;
let lastStatusWriteAtMs = 0;
let lastStatusState = '';
const STATUS_WRITE_INTERVAL_MS = 2000;
const TERMINAL_STATUS_STATES = new Set(['failed', 'completed', 'cancelled']);

function now() {
  return new Date().toISOString();
}

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, JSON.stringify(payload, null, 2));
}

function readJson(filePath) {
  try {
    if (!filePath || !fs.existsSync(filePath)) {
      return {};
    }

    const decoded = JSON.parse(fs.readFileSync(filePath, 'utf8'));

    return decoded && typeof decoded === 'object' ? decoded : {};
  } catch {
    return {};
  }
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
  const secretKeys = [
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
  ];

  const isSecretKey = (key) => {
    if (secretKeys.includes(key)) {
      return true;
    }

    const normalized = String(key || '').toLowerCase();

    if (!normalized.includes('password') && !normalized.includes('passwort')) {
      return false;
    }

    return !normalized.endsWith('_source')
      && !normalized.endsWith('source')
      && !normalized.endsWith('_variable')
      && !normalized.endsWith('variable');
  };

  const scrub = (item) => {
    if (!item || typeof item !== 'object') {
      return item;
    }

    if (Array.isArray(item)) {
      return item.map(scrub);
    }

    for (const key of Object.keys(item)) {
      if (isSecretKey(key)) {
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

function publicStatusExtra(extra = {}, includeDebug = false) {
  const publicExtra = redactPublicSecrets(extra);

  if (!publicExtra || typeof publicExtra !== 'object') {
    return {};
  }

  delete publicExtra.debugArtifacts;
  delete publicExtra.debug_artifacts;
  delete publicExtra.events;

  if (publicExtra.result && typeof publicExtra.result === 'object') {
    delete publicExtra.result.debugArtifacts;
    delete publicExtra.result.debug_artifacts;
    delete publicExtra.result.events;
  }

  if (!includeDebug) {
    delete publicExtra.debug;
  }

  return publicExtra;
}

function statusPayload(state, stage, message, extra = {}) {
  const includeDebug = debugObservabilityEnabled();
  const publicExtra = publicStatusExtra(extra, includeDebug);
  const payload = {
    runId: runtime.runId,
    workflow: publicWorkflow(runtime.workflow || null),
    workflowRunId: runtime.workflowRunId,
    workflowRunUuid: runtime.workflowRunUuid,
    workflowStepId: runtime.workflowStepId,
    workflowStepRunId: runtime.workflowStepRunId,
    workflowStepName: runtime.workflowStepName,
    workflowStepType: runtime.workflowStepType,
    timezone: workflowTimezone,
    timeZone: workflowTimezone,
    state,
    stage,
    message,
    observabilityLevel: effectiveObservabilityLevel(),
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
    browserProfileKey: runtime.browserProfileKey || null,
    browserProfilePath: runtime.browserProfilePath || null,
    browserWsEndpoint: browserWsEndpoint(),
    browserIdentity: browserIdentityPayload(),
    tasks: (Array.isArray(runtime.tasks) ? runtime.tasks : []).map((task) => {
      const result = taskResults.find((candidate) => candidate.key === task.key);

      if (result) {
        return redactPublicSecrets({ ...task, ...result });
      }

      return redactPublicSecrets({ ...task, status: task.status || 'configured' });
    }),
    browserWindows: lastBrowserWindows,
    ...publicExtra,
  };

  if (includeDebug) {
    payload.events = redactPublicSecrets(events);
    payload.debugArtifacts = redactPublicSecrets(debugArtifacts);
  }

  return payload;
}

function pushEvent(stage, message, extra = {}) {
  events.push({ at: now(), stage, message, ...extra });

  if (events.length > 100) {
    events.splice(0, events.length - 100);
  }
}

function statusWriteIntervalMs() {
  const configured = Number(runtime.statusWriteIntervalMs || runtime.status_write_interval_ms || STATUS_WRITE_INTERVAL_MS);

  return Math.max(250, Math.min(60000, configured || STATUS_WRITE_INTERVAL_MS));
}

function statusWriteMustBeImmediate(state, stage) {
  return lastStatusState !== state
    || TERMINAL_STATUS_STATES.has(state)
    || stage === 'task-started';
}

function clearPendingStatusWrite() {
  pendingStatusWrite = null;

  if (pendingStatusWriteTimer) {
    clearTimeout(pendingStatusWriteTimer);
    pendingStatusWriteTimer = null;
  }
}

function commitStatusWrite(entry) {
  clearPendingStatusWrite();
  writeJson(runtime.statusPath, statusPayload(entry.state, entry.stage, entry.message, entry.extra));
  lastStatusWriteAtMs = Date.now();
  lastStatusState = entry.state;
}

function schedulePendingStatusWrite(delayMs) {
  if (pendingStatusWriteTimer) {
    return;
  }

  pendingStatusWriteTimer = setTimeout(() => {
    const entry = pendingStatusWrite;
    pendingStatusWrite = null;
    pendingStatusWriteTimer = null;

    if (entry) {
      commitStatusWrite(entry);
    }
  }, Math.max(1, delayMs));

  if (typeof pendingStatusWriteTimer.unref === 'function') {
    pendingStatusWriteTimer.unref();
  }
}

function writeStatus(state, stage, message, extra = {}) {
  const entry = { state, stage, message, extra };
  const elapsedMs = Date.now() - lastStatusWriteAtMs;
  const interval = statusWriteIntervalMs();

  if (statusWriteMustBeImmediate(state, stage) || lastStatusWriteAtMs === 0 || elapsedMs >= interval) {
    commitStatusWrite(entry);
    return true;
  }

  pendingStatusWrite = entry;
  schedulePendingStatusWrite(interval - elapsedMs);

  return false;
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

function browserProcessId() {
  try {
    return browser && typeof browser.process === 'function'
      ? browser.process()?.pid || null
      : null;
  } catch {
    return null;
  }
}

function browserIdentityPayload() {
  const wsEndpoint = browserWsEndpoint();
  const processId = browserProcessId();
  const windows = Array.isArray(lastBrowserWindows) ? lastBrowserWindows : [];

  return {
    wsEndpoint,
    ws_endpoint: wsEndpoint,
    processId,
    process_id: processId,
    runnerProcessId: process.pid,
    runner_process_id: process.pid,
    runId: runtime.runId || null,
    run_id: runtime.runId || null,
    workflowRunId: runtime.workflowRunId || null,
    workflow_run_id: runtime.workflowRunId || null,
    workflowRunUuid: runtime.workflowRunUuid || null,
    workflow_run_uuid: runtime.workflowRunUuid || null,
    clientControllerJobUuid: runtime.clientControllerJobUuid || null,
    client_controller_job_uuid: runtime.clientControllerJobUuid || null,
    connectedToExistingBrowser,
    connected_to_existing_browser: connectedToExistingBrowser,
    browserDisconnected,
    browser_disconnected: browserDisconnected,
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    browserProfileKey: runtime.browserProfileKey || null,
    windows: windows.map((windowEntry) => ({
      key: windowEntry?.key || windowEntry?.name || '',
      targetId: windowEntry?.targetId || windowEntry?.target_id || '',
      url: windowEntry?.url || '',
      title: windowEntry?.title || '',
      capturedAt: windowEntry?.capturedAt || null,
    })),
  };
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

function isPlainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
}

function firstResolvedValue(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && value !== '') {
      return value;
    }
  }

  return undefined;
}

function firstConfiguredValue(...values) {
  for (const value of values) {
    if (value === undefined || value === null) {
      continue;
    }

    if (typeof value === 'string' && value.trim() === '') {
      continue;
    }

    return value;
  }

  return '';
}

function workflowVariablesFromContext(context = {}) {
  const workflow = isPlainObject(context.workflow) ? context.workflow : {};

  return {
    ...(isPlainObject(workflow.workflow_variables) ? workflow.workflow_variables : {}),
    ...(isPlainObject(workflow.workflowVariables) ? workflow.workflowVariables : {}),
    ...(isPlainObject(context.workflow_variables) ? context.workflow_variables : {}),
    ...(isPlainObject(context.workflowVariables) ? context.workflowVariables : {}),
    ...(isPlainObject(context.lastResult?.workflow_variables) ? context.lastResult.workflow_variables : {}),
    ...(isPlainObject(context.lastResult?.workflowVariables) ? context.lastResult.workflowVariables : {}),
  };
}

function valueFromWorkflowVariables(variables = {}, name = '') {
  const normalized = String(name || '').trim();

  if (!normalized || !isPlainObject(variables)) {
    return undefined;
  }

  if (Object.prototype.hasOwnProperty.call(variables, normalized)) {
    return variables[normalized];
  }

  return valueFromPath(variables, normalized);
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
  const workflow = runtime.workflow || {};
  const workflowVariables = workflowVariablesFromContext({
    workflow,
    ...context,
  });
  const exactWorkflowVariable = valueFromWorkflowVariables(workflowVariables, normalized);

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

  if (exactWorkflowVariable !== undefined) {
    return exactWorkflowVariable ?? '';
  }

  if ((!normalized.includes('.') && !directRuntimeKeys.includes(normalized)) || normalized.includes('://')) {
    return value;
  }

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
    workflowVariables,
    workflow_variables: workflowVariables,
    person: personForLookup,
    account,
    email_account: account,
    verificationMailbox: verificationAccount,
    verification_mailbox: verificationAccount,
    veri_account: verificationAccount,
    'veri-account': verificationAccount,
    new_password: firstResolvedValue(context.new_password, context.generated_password, workflow.new_password, workflow.generated_password, valueFromWorkflowVariables(workflowVariables, 'new_password'), valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'generated-password'), account?.password, context.lastResult?.new_password) ?? '',
    generated_password: firstResolvedValue(context.generated_password, context.new_password, workflow.generated_password, workflow.new_password, valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'new_password'), valueFromWorkflowVariables(workflowVariables, 'generated-password'), account?.password, context.lastResult?.generated_password, context.lastResult?.new_password) ?? '',
    'generated-password': firstResolvedValue(context.generated_password, context.new_password, workflow.generated_password, workflow.new_password, valueFromWorkflowVariables(workflowVariables, 'generated-password'), valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'new_password'), account?.password, context.lastResult?.['generated-password'], context.lastResult?.generated_password, context.lastResult?.new_password) ?? '',
    new_mail_username: firstResolvedValue(account?.username, context.lastResult?.account?.username) ?? '',
    new_mail_address: firstResolvedValue(account?.email, context.lastResult?.account?.email) ?? '',
    verification_code: firstResolvedValue(context.verification_code, context.verificationCode, workflow.verification_code, workflow.verificationCode, valueFromWorkflowVariables(workflowVariables, 'verification_code'), valueFromWorkflowVariables(workflowVariables, 'verificationCode'), context.lastResult?.verification_code, context.lastResult?.verificationCode) ?? '',
    verificationCode: firstResolvedValue(context.verificationCode, context.verification_code, workflow.verificationCode, workflow.verification_code, valueFromWorkflowVariables(workflowVariables, 'verificationCode'), valueFromWorkflowVariables(workflowVariables, 'verification_code'), context.lastResult?.verificationCode, context.lastResult?.verification_code) ?? '',
    workflow_return: firstResolvedValue(context.workflow_return, context.workflowReturn, workflow.workflow_return, workflow.workflowReturn, valueFromWorkflowVariables(workflowVariables, 'workflow_return'), valueFromWorkflowVariables(workflowVariables, 'workflowReturn'), context.lastResult?.workflow_return, context.lastResult?.workflowReturn) ?? '',
    workflowReturn: firstResolvedValue(context.workflowReturn, context.workflow_return, workflow.workflowReturn, workflow.workflow_return, valueFromWorkflowVariables(workflowVariables, 'workflowReturn'), valueFromWorkflowVariables(workflowVariables, 'workflow_return'), context.lastResult?.workflowReturn, context.lastResult?.workflow_return) ?? '',
    workflow_return_ok: firstResolvedValue(context.workflow_return_ok, workflow.workflow_return_ok, valueFromWorkflowVariables(workflowVariables, 'workflow_return_ok'), context.lastResult?.workflow_return_ok) ?? '',
  };
  const resolved = valueFromPath(lookupRoot, normalized);

  if (resolved === undefined || resolved === null || resolved === '') {
    return /^(person|account|email_account|workflow|workflowVariables|workflow_variables|verificationMailbox|verification_mailbox|veri_account|veri-account)\./.test(normalized) || directRuntimeKeys.includes(normalized) ? '' : value;
  }

  return resolved;
}

function configuredInputValue(task, context, rawValue) {
  const configuredSource = String(task.value_source || task.valueSource || '').trim().toLowerCase();

  if (!['fixed', 'workflow_variable'].includes(configuredSource)) {
    return {
      value: resolveString(rawValue, context),
      source: 'legacy_auto',
      workflowVariable: '',
      status: 'legacy_resolved',
      fallbackUsed: false,
    };
  }

  if (configuredSource === 'fixed') {
    return {
      value: rawValue,
      source: 'fixed',
      workflowVariable: '',
      status: 'fixed',
      fallbackUsed: false,
    };
  }

  const workflowVariable = String(
    task.workflow_variable
    || task.workflowVariable
    || task.variable_name
    || task.variableName
    || '',
  ).trim();
  const workflowVariables = workflowVariablesFromContext({
    workflow: runtime.workflow || {},
    ...context,
  });
  let resolved = valueFromWorkflowVariables(workflowVariables, workflowVariable);

  if (resolved === undefined && workflowVariable !== '') {
    const contextualValue = resolveString(workflowVariable, context);

    if (contextualValue !== workflowVariable) {
      resolved = contextualValue;
    }
  }

  if (resolved !== undefined && resolved !== null && resolved !== '') {
    return {
      value: resolved,
      source: 'workflow_variable',
      workflowVariable,
      status: 'variable_resolved',
      fallbackUsed: false,
    };
  }

  const fallback = task.value_fallback ?? task.valueFallback;

  if (fallback !== undefined && fallback !== null && String(fallback) !== '') {
    return {
      value: fallback,
      source: 'workflow_variable',
      workflowVariable,
      status: 'fallback_used',
      fallbackUsed: true,
    };
  }

  return {
    value: '',
    source: 'workflow_variable',
    workflowVariable,
    status: 'missing_workflow_variable',
    fallbackUsed: false,
  };
}

function taskInput(task, context = {}) {
  const mailboxSource = normalizeMailboxSource(task.script_person_source || task.scriptPersonSource || task.mailbox_source || task.mailboxSource || 'person');
  const valueContext = scopedWorkflowContext(context, mailboxSource);
  const rawValue = firstConfiguredValue(task.value, task.input);
  const rawInput = firstConfiguredValue(task.input, task.value);
  const rawUrl = firstConfiguredValue(task.url, task.value, task.input);
  const configuredValue = configuredInputValue(task, valueContext, rawValue);
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
    value: configuredValue.value,
    inputValue: ['fixed', 'workflow_variable'].includes(configuredValue.source)
      ? configuredValue.value
      : resolveString(rawInput, valueContext),
    input_value: ['fixed', 'workflow_variable'].includes(configuredValue.source)
      ? configuredValue.value
      : resolveString(rawInput, valueContext),
    valueSource: configuredValue.source,
    value_source: configuredValue.source,
    workflowVariable: configuredValue.workflowVariable,
    workflow_variable: configuredValue.workflowVariable,
    valueResolutionStatus: configuredValue.status,
    value_resolution_status: configuredValue.status,
    valueFallbackUsed: configuredValue.fallbackUsed,
    value_fallback_used: configuredValue.fallbackUsed,
    url: resolveString(rawUrl, valueContext),
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

function normalizedTaskReference(value = '') {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function embeddedLiteralValue(value) {
  if (typeof value !== 'string') {
    return value;
  }

  const normalized = value.trim();

  if (normalized === '') {
    return '';
  }

  try {
    return JSON.parse(normalized);
  } catch {
    return normalized;
  }
}

function resolveEmbeddedWorkflowInputs(task = {}, context = {}) {
  const definitions = task.embedded_workflow_inputs || task.embeddedWorkflowInputs || {};

  if (!definitions || typeof definitions !== 'object' || Array.isArray(definitions)) {
    return {};
  }

  const resolved = {};

  for (const [name, definition] of Object.entries(definitions)) {
    const target = String(name || '').trim();

    if (target === '') {
      continue;
    }

    let value;

    if (definition && typeof definition === 'object' && !Array.isArray(definition)) {
      if (Object.prototype.hasOwnProperty.call(definition, 'literal')) {
        value = definition.literal;
      } else if (Object.prototype.hasOwnProperty.call(definition, 'source')) {
        value = resolveString(definition.source, context);

        if ((value === undefined || value === null || value === '') && Object.prototype.hasOwnProperty.call(definition, 'default')) {
          value = definition.default;
        }
      } else if (Object.prototype.hasOwnProperty.call(definition, 'value')) {
        value = definition.value;
      } else {
        value = definition;
      }
    } else if (typeof definition === 'string' && definition.trim().startsWith('literal:')) {
      value = embeddedLiteralValue(definition.trim().slice('literal:'.length));
    } else if (typeof definition === 'string') {
      value = resolveString(definition, context);
    } else {
      value = definition;
    }

    if (value !== undefined) {
      resolved[target] = value;
    }
  }

  return resolved;
}

function applyEmbeddedWorkflowInputs(task = {}, context = {}) {
  const inheritedWorkflowVariables = workflowVariablesFromContext(context);

  context.workflow_variables = inheritedWorkflowVariables;
  context.workflowVariables = inheritedWorkflowVariables;

  const values = resolveEmbeddedWorkflowInputs(task, context);

  if (Object.keys(values).length === 0) {
    return values;
  }

  const workflowVariables = {
    ...inheritedWorkflowVariables,
    ...values,
  };

  context.workflow_variables = workflowVariables;
  context.workflowVariables = workflowVariables;

  for (const [name, value] of Object.entries(values)) {
    if (!name.includes('.')) {
      context[name] = value;
    }
  }

  return values;
}

function normalizedTaskReferenceAliases(value = '') {
  const normalized = normalizedTaskReference(value);

  return new Set([
    normalized,
    normalized.replace(/-liste-/g, '-list-').replace(/-scannen$/g, '-scan'),
  ].filter(Boolean));
}

function taskMatchesOverride(task = {}, override = {}) {
  const expected = normalizedTaskReferenceAliases(
    override.matchNormalized
    || override.match
    || override.task
    || override.taskTitle
    || override.task_title
    || '',
  );

  if (expected.size === 0) {
    return false;
  }

  return [
    task.title,
    task.key,
    task.task_key,
    task.name,
  ].some((value) => {
    const candidateAliases = normalizedTaskReferenceAliases(value);

    return Array.from(candidateAliases).some((candidate) => expected.has(candidate));
  });
}

function cleanOverrideFieldName(value = '') {
  return String(value || '')
    .trim()
    .replace(/[^A-Za-z0-9_.-]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

function applyTaskOverrides(runtimeTasks = [], currentIndex = 0, result = {}) {
  const overrides = []
    .concat(Array.isArray(result.task_overrides) ? result.task_overrides : [])
    .concat(Array.isArray(result.taskOverrides) ? result.taskOverrides : [])
    .filter((override) => override && typeof override === 'object');
  const applied = [];

  if (overrides.length === 0) {
    return applied;
  }

  for (const override of overrides) {
    const values = override.values && typeof override.values === 'object'
      ? override.values
      : {};

    if (Object.keys(values).length === 0) {
      continue;
    }

    for (let index = currentIndex + 1; index < runtimeTasks.length; index += 1) {
      const candidate = runtimeTasks[index];

      if (!candidate || typeof candidate !== 'object' || !taskMatchesOverride(candidate, override)) {
        continue;
      }

      for (const [field, value] of Object.entries(values)) {
        const cleanField = cleanOverrideFieldName(field);

        if (cleanField === '') {
          continue;
        }

        candidate[cleanField] = value;
        applied.push({
          taskKey: candidate.key || '',
          taskTitle: candidate.title || '',
          taskType: candidate.task_key || '',
          field: cleanField,
          value,
        });
      }

      break;
    }
  }

  return applied;
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

function chromiumNoSandboxEnabled() {
  const configured = runtime.chromiumNoSandbox
    ?? runtime.chromium_no_sandbox
    ?? runtime.disableChromiumSandbox
    ?? runtime.disable_chromium_sandbox;

  return configured === true || configured === 1 || configured === 'true' || configured === '1';
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
  const normalizedName = normalizeBrowserWindowName(windowName);
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

    // A persisted target id is the authoritative identity. Falling back to a
    // similarly looking URL can attach a workflow to the wrong browser tab.
    return null;
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

  if (!state && normalizedName !== 'main') {
    return null;
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

function devDebugConfig() {
  return runtime.devDebug && typeof runtime.devDebug === 'object'
    ? runtime.devDebug
    : (runtime.dev_debug && typeof runtime.dev_debug === 'object' ? runtime.dev_debug : {});
}

function normalizeObservabilityLevel(value) {
  const normalized = String(value || '').trim().toLowerCase();

  return ['off', 'preview', 'debug', 'copilot'].includes(normalized)
    ? normalized
    : '';
}

function observabilityRank(level) {
  return {
    off: 0,
    preview: 1,
    debug: 2,
    copilot: 3,
  }[normalizeObservabilityLevel(level)] ?? 0;
}

function effectiveObservabilityLevel() {
  const config = devDebugConfig();
  const runtimeObservability = runtime.observability && typeof runtime.observability === 'object'
    ? runtime.observability
    : {};
  const candidates = [
    typeof runtime.observability === 'string' ? runtime.observability : runtimeObservability.level,
    runtime.observabilityLevel,
    runtime.observability_level,
    config.observability,
    config.level,
  ];
  let effectiveLevel = 'off';

  for (const candidate of candidates) {
    const level = normalizeObservabilityLevel(candidate);

    if (level && observabilityRank(level) > observabilityRank(effectiveLevel)) {
      effectiveLevel = level;
    }
  }

  if (config.copilotObservation === true || config.copilot_observation === true) {
    return 'copilot';
  }

  if (config.enabled === true || config.dev_mode === true) {
    effectiveLevel = observabilityRank(effectiveLevel) < observabilityRank('debug')
      ? 'debug'
      : effectiveLevel;
  }

  if (effectiveLevel === 'off' && runtime.livePreviewEnabled !== false) {
    return 'preview';
  }

  return effectiveLevel;
}

function debugObservabilityEnabled() {
  return observabilityRank(effectiveObservabilityLevel()) >= observabilityRank('debug');
}

function devDebugEnabled() {
  return debugObservabilityEnabled();
}

function cleanFileSegment(value, fallback = 'item') {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80);

  return normalized || fallback;
}

function phaseCaptureEnabled(phase, type) {
  const config = devDebugConfig();
  const normalizedPhase = String(phase || '').toLowerCase();
  const normalizedType = String(type || '').toLowerCase();

  if (!devDebugEnabled()) {
    return false;
  }

  if (normalizedType === 'dom') {
    return normalizedPhase === 'before'
      ? config.captureDomBeforeStep !== false
      : config.captureDomAfterStep !== false;
  }

  if (normalizedType === 'screenshot') {
    return normalizedPhase === 'before'
      ? config.captureScreenshotBeforeStep !== false
      : config.captureScreenshotAfterStep !== false;
  }

  return false;
}

function debugArtifactFilename(phase, type, browserWindow = 'main') {
  const config = devDebugConfig();
  const position = Number(config.stepPosition || config.step_position || runtime.workflowStepPosition || 0);
  const actionKey = cleanFileSegment(config.stepActionKey || config.step_action_key || runtime.workflowStepName || 'step', 'step');
  const taskKey = cleanFileSegment(config.currentTaskKey || config.current_task_key || 'workflow', 'task');
  const taskIndex = Number(config.currentTaskIndex || config.current_task_index || 0);
  const windowKey = cleanFileSegment(browserWindow, 'main');
  const extension = type === 'screenshot' ? 'png' : 'html';
  const windowPart = windowKey && windowKey !== 'main' ? `_${windowKey}` : '';
  const taskPart = taskIndex > 0 ? `_task_${String(taskIndex).padStart(3, '0')}_${taskKey}` : `_${taskKey}`;

  return `step_${position}_${actionKey}${taskPart}${windowPart}_${phase}.${extension}`;
}

function debugArtifactPaths(filename) {
  const config = devDebugConfig();
  const directory = String(config.artifactDirectory || config.artifact_directory || '').trim();
  const storagePath = String(config.storagePath || config.storage_path || '').trim();

  if (!directory || !storagePath) {
    return null;
  }

  return {
    absolutePath: path.join(directory, filename),
    storagePath: `${storagePath.replace(/\/+$/g, '')}/${filename}`,
  };
}

function appendDebugArtifact(artifact) {
  const config = devDebugConfig();
  const cleanArtifact = cleanForJson({
    workflow_id: config.workflowId || config.workflow_id || runtime.workflow?.workflowId || runtime.workflowId || null,
    workflow_run_id: config.workflowRunId || config.workflow_run_id || runtime.workflowRunId || null,
    workflow_step_id: config.workflowStepId || config.workflow_step_id || runtime.workflowStepId || null,
    workflow_step_run_id: config.workflowStepRunId || config.workflow_step_run_id || runtime.workflowStepRunId || null,
    step_position: config.stepPosition || config.step_position || null,
    step_action_key: config.stepActionKey || config.step_action_key || '',
    task_index: config.currentTaskIndex || config.current_task_index || null,
    task_card_key: config.currentTaskKey || config.current_task_key || artifact.task_card_key || artifact.taskCardKey || '',
    storage_disk: config.storageDisk || config.storage_disk || 'local',
    created_at: now(),
    ...artifact,
  });

  debugArtifacts.push(cleanArtifact);

  const maxArtifacts = Number(config.maxArtifacts || config.max_artifacts || 0);

  if (maxArtifacts > 0 && debugArtifacts.length > maxArtifacts) {
    debugArtifacts.splice(0, debugArtifacts.length - maxArtifacts);
  } else if (config.keepArtifacts === false && debugArtifacts.length > 500) {
    debugArtifacts.splice(0, debugArtifacts.length - 500);
  }

  debugManifestDirty = true;
  flushDebugArtifactManifest(false);

  return cleanArtifact;
}

function flushDebugArtifactManifest(force = false) {
  if (!debugManifestDirty) {
    return;
  }

  if (!force && Date.now() - debugManifestLastWriteAtMs < DEBUG_MANIFEST_WRITE_INTERVAL_MS) {
    return;
  }

  const config = devDebugConfig();
  const manifestPath = String(config.manifestPath || config.manifest_path || '').trim();

  if (!manifestPath) {
    return;
  }

  writeJson(manifestPath, {
    updatedAt: now(),
    workflowRunId: config.workflowRunId || runtime.workflowRunId || null,
    workflowStepRunId: config.workflowStepRunId || runtime.workflowStepRunId || null,
    artifacts: debugArtifacts,
  });
  debugManifestDirty = false;
  debugManifestLastWriteAtMs = Date.now();
}

async function devDomSnapshot(targetPage) {
  return targetPage.evaluate(() => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const isVisible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number.parseFloat(style.opacity || '1') > 0
        && element.getAttribute('aria-hidden') !== 'true';
    };
    const visibleText = normalize(document.body ? document.body.innerText || '' : '');
    const classifyUiState = () => {
      const haystack = `${window.location.href} ${document.title} ${visibleText}`.toLowerCase();
      const locationAndTitle = `${window.location.href} ${document.title}`.toLowerCase();
      const visibleActionText = normalize(Array.from(document.querySelectorAll('button,a,[role="button"],input[type="button"],input[type="submit"]'))
        .filter((element) => isVisible(element))
        .map((element) => element.innerText || element.textContent || element.value || element.getAttribute('aria-label') || '')
        .join(' ')).toLowerCase();
      const hasVisibleSearchInput = Array.from(document.querySelectorAll('input[type="search"], input[name="q"], textarea[name="q"], [role="searchbox"]'))
        .some((element) => isVisible(element));
      const hasVisibleSearchResults = Array.from(document.querySelectorAll('#search a:has(h2), #search a:has(h3), main a:has(h2), main a:has(h3), [role="main"] a:has(h2), [role="main"] a:has(h3)'))
        .some((element) => isVisible(element));
      const hasVisiblePasswordInput = Array.from(document.querySelectorAll('input[type="password"]'))
        .some((element) => isVisible(element));
      const hasConsentContext = /(?:consent|cookie|einwilligung|datenschutz|privacy)/i.test(haystack);
      const hasConsentAction = /(?:alle\s+ablehnen|alle\s+akzeptieren|reject\s+all|decline\s+all|refuse\s+all|accept\s+all|allow\s+all|nur\s+(?:notwendige|erforderliche)|only\s+(?:necessary|required))/i.test(visibleActionText);

      if (haystack.includes('captcha')) return 'captcha_blocked';
      if (hasConsentContext && hasConsentAction) return 'consent_blocked';
      if (hasVisibleSearchResults) return 'search_results';
      if (hasVisibleSearchInput) return 'search_input';
      if (locationAndTitle.includes('register') || locationAndTitle.includes('signup') || locationAndTitle.includes('registr')) return 'registration_form';
      if (hasVisiblePasswordInput || locationAndTitle.includes('login') || locationAndTitle.includes('signin') || locationAndTitle.includes('anmeld')) return 'login_page';
      if (haystack.includes('verification') || haystack.includes('verifizierung') || haystack.includes('code')) return 'verification_pending';
      if (haystack.includes('inbox') || haystack.includes('posteingang')) return 'inbox_visible';
      if (haystack.includes('empty inbox') || haystack.includes('keine mail') || haystack.includes('0 mail')) return 'empty_inbox';
      if (haystack.includes('expired') || haystack.includes('abgelaufen') || haystack.includes('logged out')) return 'session_expired';

      return 'unknown_browser_state';
    };
    const selectorFor = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const escapeCss = (value) => {
        if (window.CSS && typeof window.CSS.escape === 'function') {
          return window.CSS.escape(String(value));
        }

        return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
      };
      const escapeText = (value) => String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

      if (!tag) return '';

      if (tag === 'a' && element.querySelector('h1, h2, h3')) {
        const searchRoot = element.closest('#search, main, [role="main"]');

        if (searchRoot?.id === 'search') return '#search a:has(h3), #search a:has(h2)';
        if (searchRoot?.matches?.('main')) return 'main a:has(h3), main a:has(h2)';
        if (searchRoot?.getAttribute?.('role') === 'main') return '[role="main"] a:has(h3), [role="main"] a:has(h2)';

        return 'a:has(h3), a:has(h2)';
      }

      for (const attribute of ['title', 'aria-label', 'placeholder', 'data-testid', 'data-test', 'data-cy', 'data-qa', 'name']) {
        const value = element.getAttribute(attribute);

        if (value) return `${tag}[${attribute}="${escapeCss(value)}"]`;
      }

      const role = element.getAttribute('role');
      const type = element.getAttribute('type');
      const text = normalize(element.innerText || element.textContent || '').slice(0, 80);

      if (role && type) return `${tag}[role="${escapeCss(role)}"][type="${escapeCss(type)}"]`;
      if (role) return `${tag}[role="${escapeCss(role)}"]${text ? `:has-text("${escapeText(text)}")` : ''}`;
      if (text && ['a', 'button', 'label', 'span', 'div'].includes(tag)) return `${tag}:has-text("${escapeText(text)}")`;
      if (element.id) return `#${escapeCss(element.id)}`;

      return tag;
    };
    const interactionPriority = (item) => {
      const haystack = normalize(`${item.text} ${item.title} ${item.ariaLabel} ${item.placeholder} ${item.name} ${item.selector}`).toLowerCase();
      const controlPriority = item.tag === 'button' || item.role === 'button' ? 20 : 0;

      if (/(?:alle\s+ablehnen|reject\s+all|decline\s+all|refuse\s+all|nur\s+(?:notwendige|erforderliche)|only\s+(?:necessary|required)|\bablehnen\b|\breject\b|\bdecline\b)/i.test(haystack)) {
        return controlPriority + 1000;
      }

      if (/(?:alle\s+akzeptieren|accept\s+all|allow\s+all|\bakzeptieren\b|\baccept\b|\ballow\b)/i.test(haystack)) {
        return controlPriority + 800;
      }

      if (/(?:consent|cookie|einwilligung|datenschutz|privacy)/i.test(haystack)) {
        return controlPriority + 500;
      }

      return controlPriority;
    };
    const selectorSuggestions = Array.from(document.querySelectorAll('button,a,input,textarea,select,[role],[title],[aria-label],[data-testid],[data-test],[data-cy],[data-qa]'))
      .map((element) => {
        const tag = String(element.tagName || '').toLowerCase();
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);
        const formControl = ['input', 'textarea', 'select'].includes(tag);
        const visible = rect.width > 0
          && rect.height > 0
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && Number.parseFloat(style.opacity || '1') > 0
          && element.getAttribute('aria-hidden') !== 'true';

        return {
          selector: selectorFor(element),
          tag,
          type: element.getAttribute('type') || '',
          text: formControl ? '' : normalize(element.innerText || element.textContent || '').slice(0, 120),
          id: element.id || '',
          role: element.getAttribute('role') || '',
          title: element.getAttribute('title') || '',
          ariaLabel: element.getAttribute('aria-label') || '',
          name: element.getAttribute('name') || '',
          placeholder: element.getAttribute('placeholder') || '',
          visible,
          enabled: !element.disabled && element.getAttribute('aria-disabled') !== 'true',
          focused: document.activeElement === element,
          selected: Boolean(element.selected || element.checked || element.getAttribute('aria-selected') === 'true'),
          boundingBox: {
            x: Number(rect.x.toFixed(2)),
            y: Number(rect.y.toFixed(2)),
            width: Number(rect.width.toFixed(2)),
            height: Number(rect.height.toFixed(2)),
          },
        };
      })
      .filter((item) => item.selector && !/nth-child/i.test(item.selector))
      .map((item, index) => ({ item, index, priority: interactionPriority(item) }))
      .sort((left, right) => right.priority - left.priority || left.index - right.index)
      .slice(0, 80)
      .map(({ item }) => item);

    return {
      html: document.documentElement ? document.documentElement.outerHTML || '' : '',
      title: document.title || '',
      url: window.location.href,
      readyState: document.readyState,
      viewport: {
        width: window.innerWidth,
        height: window.innerHeight,
        deviceScaleFactor: window.devicePixelRatio || 1,
        scrollX: window.scrollX || 0,
        scrollY: window.scrollY || 0,
      },
      visibleTextExcerpt: visibleText.slice(0, 5000),
      uiState: classifyUiState(),
      selectorSuggestions,
    };
  });
}

async function captureStepArtifactPhase(context, phase, task = {}) {
  if (!devDebugEnabled()) {
    return [];
  }

  const targetPage = context.page;
  const browserWindow = normalizeBrowserWindowName(context.activeBrowserWindow || task.browser_window || task.browserWindow || 'main');

  if (!targetPage || typeof targetPage.evaluate !== 'function' || (targetPage.isClosed && targetPage.isClosed())) {
    return [];
  }

  const captured = [];
  let snapshot = null;
  const config = devDebugConfig();
  const previousTaskKey = config.currentTaskKey;
  const previousTaskKeySnake = config.current_task_key;
  const previousTaskIndex = config.currentTaskIndex;
  const previousTaskIndexSnake = config.current_task_index;
  const taskKey = task.key || task.task_key || '';
  const taskIndex = Number(task.__devDebugTaskIndex || task.__dev_debug_task_index || 0);

  const baseArtifact = {
    phase,
    browser_window: browserWindow,
    task_index: taskIndex || null,
    task_card_key: taskKey,
    task_type: task.task_key || '',
    task_title: task.title || '',
    embedded_workflow_id: task.embedded_workflow_id || null,
    embedded_workflow_name: task.embedded_workflow_name || '',
    embedded_workflow_frame_key: task.embedded_workflow_frame_key || '',
    parent_task_key: task.parent_task_key || '',
  };

  config.currentTaskKey = taskKey;
  config.current_task_key = taskKey;
  config.currentTaskIndex = taskIndex;
  config.current_task_index = taskIndex;

  try {
  if (phaseCaptureEnabled(phase, 'dom')) {
    try {
      snapshot = await devDomSnapshot(targetPage);
      const paths = debugArtifactPaths(debugArtifactFilename(phase, 'dom', browserWindow));

      if (!paths) {
        throw new Error('Dev-Debug-Artefaktpfad ist nicht konfiguriert.');
      }

      fs.mkdirSync(path.dirname(paths.absolutePath), { recursive: true });
      fs.writeFileSync(paths.absolutePath, `<!-- workflow-debug-metadata: ${JSON.stringify({
        url: snapshot.url,
        title: snapshot.title,
        readyState: snapshot.readyState,
        uiState: snapshot.uiState,
        viewport: snapshot.viewport,
        capturedAt: now(),
      })} -->\n${snapshot.html}`);

      captured.push(appendDebugArtifact({
        ...baseArtifact,
        artifact_type: 'dom',
        current_url: snapshot.url,
        title: snapshot.title,
        storage_path: paths.storagePath,
        status: 'success',
        metadata: {
          readyState: snapshot.readyState,
          viewport: snapshot.viewport,
          visibleTextExcerpt: snapshot.visibleTextExcerpt,
          uiState: snapshot.uiState,
          selectorSuggestions: snapshot.selectorSuggestions,
        },
      }));
    } catch (error) {
      captured.push(appendDebugArtifact({
        ...baseArtifact,
        artifact_type: 'dom',
        status: 'failed',
        error_message: error.message,
      }));
    }
  }

  if (phaseCaptureEnabled(phase, 'screenshot')) {
    try {
      const paths = debugArtifactPaths(debugArtifactFilename(phase, 'screenshot', browserWindow));

      if (!paths) {
        throw new Error('Dev-Debug-Artefaktpfad ist nicht konfiguriert.');
      }

      fs.mkdirSync(path.dirname(paths.absolutePath), { recursive: true });
      await targetPage.screenshot({ path: paths.absolutePath, fullPage: false });

      if (!snapshot) {
        snapshot = await devDomSnapshot(targetPage).catch(() => ({}));
      }

      captured.push(appendDebugArtifact({
        ...baseArtifact,
        artifact_type: 'screenshot',
        current_url: snapshot.url || (typeof targetPage.url === 'function' ? String(targetPage.url() || '') : ''),
        title: snapshot.title || '',
        storage_path: paths.storagePath,
        status: 'success',
        metadata: {
          readyState: snapshot.readyState || null,
          uiState: snapshot.uiState || null,
          viewport: snapshot.viewport || null,
        },
      }));
    } catch (error) {
      captured.push(appendDebugArtifact({
        ...baseArtifact,
        artifact_type: 'screenshot',
        status: 'failed',
        error_message: error.message,
      }));
    }
  }

  if (captured.length > 0) {
    pushEvent(`dev-debug-${phase}-captured`, `Dev-Debug-Artefakte (${phase}) wurden gespeichert.`, {
      phase,
      browserWindow,
      artifactCount: captured.length,
    });
  }
  } finally {
    config.currentTaskKey = previousTaskKey;
    config.current_task_key = previousTaskKeySnake;
    config.currentTaskIndex = previousTaskIndex;
    config.current_task_index = previousTaskIndexSnake;
  }

  return captured;
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
      await configureBrowserTimezone(browser);
      pushEvent('workflow-browser-active', 'Workflow-Browser ist aktiv und wird fuer diese Liste genutzt.');

      return browser;
    } catch (error) {
      const connectError = String(error?.message || error);
      pushEvent('browser-connect-failed', 'Workflow-Browser konnte nicht erreicht werden.', {
        browserConnectError: connectError.slice(0, 1200),
      });

      if (workflowHasBrowserWindows()) {
        throw new Error(`Workflow-Browser ist nicht erreichbar (${connectError.slice(0, 300)}). Es wird kein Ersatzfenster geoeffnet, damit die gespeicherte Workflow-Fensteridentitaet erhalten bleibt.`);
      }

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

  const launchArgs = [
    '--disable-dev-shm-usage',
    '--window-size=1366,900',
  ];

  if (process.platform === 'linux' && chromiumNoSandboxEnabled()) {
    launchArgs.unshift('--no-sandbox', '--disable-setuid-sandbox');
  }

  const launchOptions = {
    headless: runtime.headlessEnabled === true ? 'new' : false,
    userDataDir: runtime.browserProfilePath,
    defaultViewport: { width: 1366, height: 900 },
    args: launchArgs,
    handleSIGINT: false,
    handleSIGTERM: false,
    handleSIGHUP: false,
  };

  pushEvent('browser-launch-options', 'Browser-Startparameter wurden vorbereitet.', {
    platform: process.platform,
    headless: launchOptions.headless,
    args: launchArgs,
    noSandboxEnabled: launchArgs.includes('--no-sandbox'),
  });

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
  await configureBrowserTimezone(browser);
  pushEvent('browser-started', 'Browser wurde gestartet.', {
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    browserProcessId: browserProcessId(),
    browserWsEndpointAvailable: browserWsEndpoint() !== '',
  });

  return browser;
}

async function configureBrowserTimezone(currentBrowser) {
  if (!currentBrowser) {
    return;
  }

  const apply = async (targetPage) => {
    if (targetPage && typeof targetPage.emulateTimezone === 'function') {
      await targetPage.emulateTimezone(workflowTimezone).catch(() => {});
    }
  };

  if (typeof currentBrowser.pages === 'function') {
    await Promise.all((await currentBrowser.pages()).map(apply));
  }

  if (typeof currentBrowser.on === 'function' && !currentBrowser.__workflowTimezoneListener) {
    currentBrowser.__workflowTimezoneListener = true;
    currentBrowser.on('targetcreated', async (target) => {
      const targetPage = typeof target?.page === 'function' ? await target.page().catch(() => null) : null;
      await apply(targetPage);
    });
  }
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

  const persistedWindow = workflowBrowserWindowState(normalizedName);
  const persistedTargetId = String(persistedWindow?.targetId || persistedWindow?.target_id || '').trim();

  if (persistedTargetId !== '') {
    throw new Error(`Workflow-Browserfenster "${normalizedName}" mit targetId ${persistedTargetId} wurde im aktiven Browser nicht gefunden. Es wird kein Ersatzfenster geoeffnet.`);
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

async function selectWorkflowPageForClose(context, windowName = 'main') {
  const normalizedName = normalizeBrowserWindowName(windowName);
  const selected = selectExistingPage(context, normalizedName);

  if (selected) {
    return selected;
  }

  if (!workflowBrowserRuntime().wsEndpoint && !workflowHasBrowserWindows()) {
    return null;
  }

  const currentBrowser = await loadBrowser();
  const existingPage = await existingPageForWindow(currentBrowser, normalizedName);

  if (!existingPage) {
    const persistedWindow = workflowBrowserWindowState(normalizedName);
    const persistedTargetId = String(persistedWindow?.targetId || persistedWindow?.target_id || '').trim();

    if (persistedTargetId !== '') {
      throw new Error(`Workflow-Browserfenster "${normalizedName}" mit targetId ${persistedTargetId} konnte zum Schliessen nicht eindeutig gefunden werden.`);
    }

    return null;
  }

  return registerBrowserWindow(context, existingPage, normalizedName, persistedWindowLabel(normalizedName));
}

function persistedWindowLabel(windowName = 'main') {
  const state = workflowBrowserWindowState(windowName);

  return String(state?.label || browserWindowLabel(windowName));
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

function shouldKeepWorkflowBrowserProcessAlive() {
  const configured = runtime.keepWorkflowBrowserAlive ?? runtime.keep_workflow_browser_alive;

  if (configured === false || configured === 0 || configured === 'false' || configured === '0') {
    return false;
  }

  return true;
}

// Obergrenze, wie lange ein geparkter Keep-Alive-Prozess ohne Fortschritt
// weiterlaufen darf, bevor er den Browser schliesst und sich selbst beendet.
// Verhindert das unbegrenzte Akkumulieren von Node+Chromium-Paaren, wenn ein
// Workflow ohne browser.close-Task endet oder PHP den Prozess nie aufraeumt.
function keepWorkflowBrowserMaxIdleMs() {
  const raw = runtime.keepWorkflowBrowserMaxIdleMs ?? runtime.keep_workflow_browser_max_idle_ms;

  if (raw === 0 || raw === '0') {
    return 0; // explizit deaktiviert
  }

  const configured = Number(raw);

  if (Number.isFinite(configured) && configured > 0) {
    return configured;
  }

  return 900000; // Default: 15 Minuten
}

async function keepWorkflowBrowserAlive(state = 'completed', stage = 'workflow-browser-kept-active', message = 'Workflow-Browser bleibt aktiv.') {
  if (!shouldKeepWorkflowBrowserProcessAlive()) {
    if (
      !connectedToExistingBrowser
      && browser
      && !browserDisconnected
      && browserWindowsByName.size > 0
    ) {
      pushEvent('workflow-browser-released', 'Workflow-Browser bleibt aktiv; Runner gibt den naechsten Workflow-Schritt frei.');
      writeStatus(state, 'workflow-browser-released', message);
    }

    return;
  }

  if (
    connectedToExistingBrowser
    || !browser
    || browserDisconnected
    || browserWindowsByName.size === 0
  ) {
    return;
  }

  const maxIdleMs = keepWorkflowBrowserMaxIdleMs();
  const idleNote = maxIdleMs > 0
    ? ` (Leerlauf-Limit ${Math.round(maxIdleMs / 1000)}s)`
    : '';
  pushEvent('workflow-browser-kept-active', `Workflow-Browser bleibt aktiv bis ein Browser-schliessen-Task ihn beendet${idleNote}.`);
  writeStatus(state, stage, message);

  const keptAliveSinceMs = Date.now();

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

        return;
      }

      // Selbst-Aufraeumung: nach dem Leerlauf-Limit den Browser schliessen und
      // den Prozess enden lassen, statt ihn unbegrenzt geparkt zu halten.
      if (maxIdleMs > 0 && (Date.now() - keptAliveSinceMs) >= maxIdleMs) {
        clearInterval(interval);
        pushEvent(
          'workflow-browser-idle-timeout',
          `Workflow-Browser wird nach Erreichen des Leerlauf-Limits (${Math.round(maxIdleMs / 1000)}s) geschlossen.`,
        );

        try {
          await closeWorkflowBrowser(state);
        } catch (error) {
          pushEvent('workflow-browser-idle-close-failed', error.message);
        }

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
    // Chromium-Close kann bei defektem Browser haengen. Nach 10s hart per
    // SIGKILL auf den Browser-Prozess nachfassen, damit kein Waise zurueckbleibt.
    const browserProcess = typeof browser.process === 'function' ? browser.process() : null;
    let closeTimer = null;

    await Promise.race([
      browser.close().catch((error) => {
        pushEvent('workflow-browser-close-failed', error.message);
      }),
      new Promise((resolve) => {
        closeTimer = setTimeout(resolve, 10000);

        if (typeof closeTimer.unref === 'function') {
          closeTimer.unref();
        }
      }),
    ]);

    if (closeTimer) {
      clearTimeout(closeTimer);
    }

    if (browserProcess && browserProcess.pid && !browserProcess.killed) {
      try {
        browserProcess.kill('SIGKILL');
        pushEvent('workflow-browser-force-killed', 'Browser-Prozess nach Schliess-Timeout hart beendet.');
      } catch (error) {
        pushEvent('workflow-browser-force-kill-failed', error.message);
      }
    }
  }

  browser = null;
  browserWindowsByName.clear();
  page = null;
}

async function finalizeBrowserLifecycle(state = 'completed') {
  // Den ECHTEN Task-Kontext stoppen, damit der per-Task-Preview-Timer
  // (__workflowPreviewTimer) wirklich freigegeben wird und keine dauerhaften
  // Screenshot-/DOM-Dumps im geparkten Prozess weiterlaufen.
  stopPreviewLoop(activeRunContext || { __workflowPreviewTimer: null });

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
  } else if (browser && !shouldKeepWorkflowBrowserProcessAlive() && typeof browser.close === 'function') {
    await browser.close().catch(() => {});
  } else if (browser && browserWindowsByName.size === 0 && typeof browser.close === 'function') {
    await browser.close().catch(() => {});
  }
}

async function handleShutdownSignal(signal) {
  if (shutdownInProgress) {
    return;
  }

  shutdownInProgress = true;
  const currentStatus = readJson(runtime.statusPath);
  const currentStage = String(currentStatus.stage || '').trim();
  const currentState = String(currentStatus.state || currentStatus.status || '').trim();
  const closeRequested = signal === 'SIGTERM' && currentStage === 'workflow-browser-close-requested';

  if (closeRequested) {
    const finalState = ['completed', 'failed', 'cancelled'].includes(currentState) ? currentState : 'completed';
    const message = String(currentStatus.message || 'Workflow-Browser wird geschlossen.');

    pushEvent('workflow-browser-close-requested', message, { signal });

    try {
      await closeWorkflowBrowser(finalState);
    } finally {
      flushDebugArtifactManifest(true);
      process.exit(0);
    }
  }

  const result = {
    ok: false,
    status: 'cancelled',
    statusMessage: 'Workflow-Task-Lauf wurde gestoppt.',
    signal,
    tasks: taskResults,
    browserWindows: lastBrowserWindows,
    browserWsEndpoint: browserWsEndpoint(),
    browserIdentity: browserIdentityPayload(),
    events,
    finishedAt: now(),
  };

  pushEvent('cancelled', result.statusMessage, { signal });
  writeJson(runtime.resultPath, result);
  writeStatus('cancelled', 'cancelled', result.statusMessage, { result });

  try {
    await finalizeBrowserLifecycle('cancelled');
  } finally {
    flushDebugArtifactManifest(true);
    process.exit(0);
  }
}

process.once('SIGTERM', () => {
  handleShutdownSignal('SIGTERM').catch(() => process.exit(0));
});

process.once('SIGINT', () => {
  handleShutdownSignal('SIGINT').catch(() => process.exit(0));
});

// Ohne diese Handler friert der Prozess bei einem unerwarteten Fehler auf
// 'running' ein (status.json wird nie final geschrieben) und bleibt als Waise
// mit offenem Chromium haengen. Hier: failed-Status schreiben, Browser
// best-effort schliessen, dann beenden.
function handleFatalError(source, error) {
  if (fatalErrorHandled || shutdownInProgress) {
    return;
  }

  fatalErrorHandled = true;
  finalRunState = 'failed';
  const messageText = error && error.message ? error.message : String(error);

  const result = {
    ok: false,
    status: 'failed',
    statusMessage: `Runner-Absturz (${source}): ${messageText}`,
    error: (error && error.stack) || messageText,
    tasks: taskResults,
    browserWindows: lastBrowserWindows,
    browserWsEndpoint: browserWsEndpoint(),
    browserIdentity: browserIdentityPayload(),
    events,
    finishedAt: now(),
  };

  try {
    flushDebugArtifactManifest(true);
    writeJson(runtime.resultPath, result);
    writeStatus('failed', 'failed', result.statusMessage, { result });
  } catch {
    // Schreibfehler beim Abbruch ignorieren – Prozess endet trotzdem.
  }

  const forceExitTimer = setTimeout(() => process.exit(1), 12000);

  if (typeof forceExitTimer.unref === 'function') {
    forceExitTimer.unref();
  }

  Promise.resolve()
    .then(() => closeWorkflowBrowser('failed'))
    .catch(() => {})
    .finally(() => process.exit(1));
}

process.on('unhandledRejection', (reason) => {
  handleFatalError('unhandledRejection', reason);
});

process.on('uncaughtException', (error) => {
  handleFatalError('uncaughtException', error);
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
  const step = routeStepKey(route).toLowerCase();

  if (['end', 'fail'].includes(step)) return step;

  return String(route?.type || step).trim().toLowerCase();
}

function routeHasExplicitTarget(route = {}) {
  const type = String(route?.type || '').trim().toLowerCase();
  const step = routeStepKey(route).toLowerCase();

  return routeTargetCardKey(route) !== ''
    || (step !== '' && !['next', 'end', 'fail'].includes(step))
    || type === 'card';
}

function routeDisposition(route = {}) {
  const type = routeType(route);

  if (type === 'fail') return 'fail';
  if (type === 'end') return 'complete';
  if (type === 'card') return routeTargetCardKey(route) !== '' ? 'continue' : 'invalid';

  return routeStepKey(route) !== '' ? 'continue' : 'invalid';
}

function routeMaxAttempts(route = {}) {
  const attempts = Number(route?.max_attempts ?? route?.retry_limit ?? 0);

  return Number.isFinite(attempts) ? Math.max(0, Math.floor(attempts)) : 0;
}

// Rueckwaerts-Routen ohne konfiguriertes max_attempts wuerden sonst bis zum
// Watchdog zyklieren; jeder Zyklus kann durch Task-Timeouts Minuten kosten.
const DEFAULT_BACK_ROUTE_ATTEMPTS = 3;

function routeAttemptKey(task = {}, route = {}, targetCardKey = '') {
  return [
    embeddedFrameKeyForTask(task),
    String(task?.key || ''),
    routeType(route),
    routeStepKey(route),
    targetCardKey,
  ].join(':');
}

function routeTransitionLimit(tasks = []) {
  const loopLimit = tasks
    .filter((task) => String(task?.task_key || '') === 'loop.for_each_element')
    .reduce((maximum, task) => {
      const configured = Number(task?.limit ?? 0);
      return Math.max(maximum, Number.isFinite(configured) && configured > 0 ? Math.floor(configured) : 1000);
    }, 0);

  return Math.max(100, tasks.length * 20, loopLimit * 5);
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

  const workflowContext = isPlainObject(runtime.workflow) ? runtime.workflow : {};
  const initialWorkflowVariables = workflowVariablesFromContext({ workflow: workflowContext });
  const runDirectory = runtime.runDirectory || path.dirname(runtime.resultPath);
  const observabilityLevel = effectiveObservabilityLevel();
  const debugEnabled = debugObservabilityEnabled();
  const contextDevDebug = {
    ...devDebugConfig(),
    enabled: debugEnabled,
    dev_mode: debugEnabled,
    observability: observabilityLevel,
    level: observabilityLevel,
    captureDom: debugEnabled,
  };
  const context = {
    ...workflowContext,
    workflow: workflowContext,
    workflow_variables: initialWorkflowVariables,
    workflowVariables: initialWorkflowVariables,
    preview: {
      enabled: runtime.livePreviewEnabled !== false,
      livePreviewPath: runtime.livePreviewPath,
      livePreviewRelativePath: runtime.livePreviewRelativePath,
      intervalMs: runtime.livePreviewIntervalMs || 3000,
      observability: observabilityLevel,
      captureDom: debugEnabled,
      debugDomDirectory: runDirectory,
    },
    devDebug: contextDevDebug,
    observability: {
      level: observabilityLevel,
      debug: debugEnabled,
      copilot: observabilityLevel === 'copilot',
    },
    observabilityLevel,
    livePreviewEnabled: runtime.livePreviewEnabled !== false,
    livePreviewIntervalMs: runtime.livePreviewIntervalMs || 3000,
    livePreviewIntervalSeconds: runtime.livePreviewIntervalSeconds || 3,
    livePreviewPath: runtime.livePreviewPath,
    livePreviewRelativePath: runtime.livePreviewRelativePath,
    runDirectory,
    workflowTaskRunDirectory: runDirectory,
    timeoutMs: runtime.observationTimeoutMs || 90000,
    pages: [],
    browserWindows: lastBrowserWindows,
    windows: lastBrowserWindows,
    activeBrowserWindow: 'main',
  };

  // Modulweite Referenz fuer finalizeBrowserLifecycle (Preview-Timer-Stop).
  activeRunContext = context;

  const runtimeTasks = runtime.tasks || [];
  const maxRouteTransitions = routeTransitionLimit(runtimeTasks);
  let taskIndex = 0;
  let requestedSuccessRouteTask = null;
  let requestedFailureRouteTask = null;
  let requestedDynamicRoute = null;
  let requestedRouteMessage = null;
  let lastCompletedTaskKey = '';
  let routeTransitions = 0;
  const routeAttemptCounts = new Map();
  const appliedEmbeddedInputFrames = new Set();
  const preserveBrowserForFailureRoute = startedFromFailureRoute();
  let devDebugTaskExecutionCounter = 0;

  while (taskIndex < runtimeTasks.length) {
    const task = runtimeTasks[taskIndex];
    devDebugTaskExecutionCounter += 1;
    const debugTask = {
      ...task,
      __devDebugTaskIndex: devDebugTaskExecutionCounter,
    };
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
      const embeddedInputFrame = String(task.embedded_workflow_frame_key || '').trim();

      if (
        task.runner !== 'workflow-boundary'
        && embeddedInputFrame !== ''
        && !appliedEmbeddedInputFrames.has(embeddedInputFrame)
      ) {
        const embeddedInputs = applyEmbeddedWorkflowInputs(task, context);

        appliedEmbeddedInputFrames.add(embeddedInputFrame);

        if (Object.keys(embeddedInputs).length > 0) {
          pushEvent('embedded-workflow-inputs-applied', 'Eingabewerte wurden an den eingebetteten Workflow uebergeben.', {
            workflowFrameKey: embeddedInputFrame,
            inputNames: Object.keys(embeddedInputs),
          });
        }
      }

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
          browserIdentity: browserIdentityPayload(),
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
          await selectWorkflowPageForClose(context, targetBrowserWindow);
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
          await captureStepArtifactPhase(context, 'before', debugTask).catch((error) => {
            pushEvent('dev-debug-before-failed', error.message, { taskKey: task.key });
          });

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

          const knownBrowserWindows = [
            ...(Array.isArray(lastBrowserWindows) ? lastBrowserWindows : []),
            ...initialBrowserWindowsFromWorkflow(),
          ];
          result.browserWindows = Array.from(new Map(knownBrowserWindows
            .filter((windowEntry) => {
              const key = normalizeBrowserWindowName(windowEntry?.key || windowEntry?.name || '');

              return key !== targetBrowserWindow;
            })
            .map((windowEntry) => [
              normalizeBrowserWindowName(windowEntry?.key || windowEntry?.name || 'main'),
              windowEntry,
            ])).values());
          lastBrowserWindows = result.browserWindows;

          result.closedBrowserWindow = targetBrowserWindow;

          const closeEntireWorkflowBrowser = runtime.closeWorkflowBrowserAtEnd === true
            || runtime.close_workflow_browser_at_end === true;
          const openWindows = await openBrowserWindowCount();

          if (
            browser
            && typeof browser.close === 'function'
            && (closeEntireWorkflowBrowser || (browserWindowsByName.size === 0 && openWindows <= 0))
          ) {
            await browser.close().catch(() => {});
            browser = null;
            result.browserWindows = [];
            lastBrowserWindows = [];
            result.closedBrowser = true;
          }
        }

        if (result && result.page) {
          registerBrowserWindow(context, result.page, targetBrowserWindow, targetBrowserWindow === 'main' ? 'Main' : taskLabel);
        }
      }

      result = cleanForJson(result || {});
      const appliedTaskOverrides = applyTaskOverrides(runtimeTasks, taskIndex, result);

      if (appliedTaskOverrides.length > 0) {
        result.applied_task_overrides = appliedTaskOverrides;
        result.appliedTaskOverrides = appliedTaskOverrides;
        pushEvent('task-overrides-applied', 'Eingabewerte wurden in folgende Tasks uebernommen.', {
          taskKey: task.key,
          overrides: appliedTaskOverrides,
        });
      }

      context.lastResult = result;

      const resultWorkflowVariables = {
        ...(isPlainObject(result.workflow_variables) ? result.workflow_variables : {}),
        ...(isPlainObject(result.workflowVariables) ? result.workflowVariables : {}),
      };

      if (Object.keys(resultWorkflowVariables).length > 0) {
        context.workflow_variables = {
          ...(isPlainObject(context.workflow_variables) ? context.workflow_variables : {}),
          ...resultWorkflowVariables,
        };
        context.workflowVariables = {
          ...(isPlainObject(context.workflowVariables) ? context.workflowVariables : {}),
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
          ...(isPlainObject(context.workflow_variables) ? context.workflow_variables : {}),
          workflow_return: workflowReturn,
          workflow_return_ok: workflowReturnOk,
        };
        context.workflowVariables = {
          ...(isPlainObject(context.workflowVariables) ? context.workflowVariables : {}),
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
          ...(isPlainObject(context.workflow_variables) ? context.workflow_variables : {}),
          verification_code: verificationCode,
          verificationCode,
        };
        context.workflowVariables = {
          ...(isPlainObject(context.workflowVariables) ? context.workflowVariables : {}),
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
      logicalOutcome: result.logicalOutcome || result.logical_outcome || (branchFailed ? 'condition_false' : (ok ? 'success' : 'technical_error')),
      logical_outcome: result.logical_outcome || result.logicalOutcome || (branchFailed ? 'condition_false' : (ok ? 'success' : 'technical_error')),
      finishedAt: now(),
    });

    if (ok && !branchFailed) {
      lastCompletedTaskKey = String(task.route_source_task_key || task.parent_task_key || task.key || '').trim();
    }

    const taskEventStage = branchFailed
      ? 'task-condition-not-met'
      : (ok ? 'task-completed' : 'task-failed');
    pushEvent(taskEventStage, result.statusMessage || taskLabel, { taskKey: task.key, status, branchOutcome });
    writeStatus('running', taskEventStage, result.statusMessage || taskLabel);

    await captureStepArtifactPhase(context, 'after', debugTask).catch((error) => {
      pushEvent('dev-debug-after-failed', error.message, { taskKey: task.key });
    });

    flushDebugArtifactManifest(true);

    const dynamicRouteTarget = String(result.route_target_key || result.routeTargetKey || '').trim();

    if (dynamicRouteTarget !== '') {
      const dynamicTargetIndex = runtimeTasks.findIndex((candidate) => String(candidate.key || '') === dynamicRouteTarget);

      if (dynamicTargetIndex >= 0) {
        routeTransitions += 1;

        if (routeTransitions > maxRouteTransitions) {
          throw new Error('Zu viele dynamische Task-Routenwechsel. Moegliche Schleife in der Task-Konfiguration.');
        }

        pushEvent('task-dynamic-route-followed', `Dynamische Task-Route wird fortgesetzt: ${dynamicRouteTarget}.`, {
          taskKey: task.key,
          targetTaskKey: dynamicRouteTarget,
          routeOutcome: result.route_outcome || result.routeOutcome || null,
        });
        taskIndex = dynamicTargetIndex;
        continue;
      }

      if (ok) {
        requestedDynamicRoute = {
          sourceTaskKey: task.route_source_task_key || task.parent_task_key || task.key,
          targetTaskKey: dynamicRouteTarget,
          outcome: String(result.route_outcome || result.routeOutcome || 'success').trim() || 'success',
        };
        break;
      }
    }

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

          if (routeTransitions > maxRouteTransitions) {
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

        if (currentEmbeddedFrameKey === '' && failureRouteType === 'end') {
          requestedFailureRouteTask = {
            ...task,
            key: task.route_source_task_key || task.parent_task_key || task.key,
          };
          requestedRouteMessage = branchFailed
            ? 'IF-Falschzweig beendet den Workflow regulaer.'
            : 'Konfigurierte Route beendet den Workflow regulaer.';
          break;
        }

        const canFollowFailureRouteInNode = targetTaskIndex > taskIndex || targetIsInSameEmbeddedFrame;

        if (canFollowFailureRouteInNode) {
          const maxAttempts = routeMaxAttempts(failureRoute);
          const isBackRoute = targetTaskIndex <= taskIndex;
          const attemptLimit = maxAttempts > 0
            ? maxAttempts
            : (isBackRoute ? DEFAULT_BACK_ROUTE_ATTEMPTS : 0);

          if (attemptLimit > 0) {
            const attemptKey = routeAttemptKey(task, failureRoute, targetCardKey);
            const attempts = routeAttemptCounts.get(attemptKey) || 0;

            if (attempts >= attemptLimit) {
              requestedFailureRouteTask = {
                ...task,
                key: task.route_source_task_key || task.parent_task_key || task.key,
              };
              requestedRouteMessage = `Fehlerroute wurde zu oft wiederholt: ${targetCardKey}.`;
              pushEvent('task-route-attempts-exhausted', requestedRouteMessage, {
                taskKey: task.key,
                targetTaskKey: targetCardKey,
                attempts,
                attemptLimit,
              });
              break;
            }

            routeAttemptCounts.set(attemptKey, attempts + 1);
          }

          routeTransitions += 1;

          if (routeTransitions > maxRouteTransitions) {
            throw new Error('Zu viele Task-Routenwechsel. Moegliche Schleife in der Fehlerroute.');
          }

          pushEvent(branchFailed ? 'task-branch-route-followed' : 'task-error-route-followed', branchFailed
            ? `IF-Abzweigung wird fortgesetzt: ${targetCardKey}.`
            : `Fehlerroute wird fortgesetzt: ${targetCardKey}.`, {
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

        if (
          currentEmbeddedFrameKey === ''
          && !['end', 'fail'].includes(failureRouteType)
          && routeHasExplicitTarget(failureRoute)
        ) {
          requestedFailureRouteTask = {
            ...task,
            key: task.route_source_task_key || task.parent_task_key || task.key,
          };
          requestedRouteMessage = targetCardKey !== ''
            ? `Fehlerroute wird ausserhalb des aktuellen Task-Ausschnitts fortgesetzt: ${targetCardKey}.`
            : `Fehlerroute wird in der Workflow-Liste ${routeStepKey(failureRoute)} fortgesetzt.`;
          pushEvent(branchFailed ? 'task-branch-route-requested' : 'task-error-route-requested', requestedRouteMessage, {
            taskKey: task.key,
            targetTaskKey: targetCardKey || null,
            targetStepKey: routeStepKey(failureRoute) || null,
            status,
          });
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
        browserIdentity: browserIdentityPayload(),
        runnerDiagnostics: {
          keepWorkflowBrowserAlive: shouldKeepWorkflowBrowserProcessAlive(),
          workflowBundleStep: runtime.workflowBundleStep === true || runtime.workflow_bundle_step === true,
          chromiumNoSandboxFlag: process.platform === 'linux' && chromiumNoSandboxEnabled(),
          platform: process.platform,
        },
        events,
        finishedAt: now(),
      };

      failedResult.debugArtifacts = debugArtifacts;

      flushDebugArtifactManifest(true);
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
        const isBackRoute = targetTaskIndex <= taskIndex;

        if (isBackRoute) {
          const maxAttempts = routeMaxAttempts(successRoute);
          const attemptLimit = maxAttempts > 0 ? maxAttempts : DEFAULT_BACK_ROUTE_ATTEMPTS;
          const attemptKey = routeAttemptKey(task, successRoute, targetCardKey);
          const attempts = routeAttemptCounts.get(attemptKey) || 0;

          if (attempts >= attemptLimit) {
            requestedFailureRouteTask = {
              ...task,
              key: task.route_source_task_key || task.parent_task_key || task.key,
            };
            requestedRouteMessage = `Erfolgsroute wurde zu oft wiederholt: ${targetCardKey}.`;
            pushEvent('task-route-attempts-exhausted', requestedRouteMessage, {
              taskKey: task.key,
              targetTaskKey: targetCardKey,
              attempts,
              attemptLimit,
            });
            break;
          }

          routeAttemptCounts.set(attemptKey, attempts + 1);
        }

        routeTransitions += 1;

        if (routeTransitions > maxRouteTransitions) {
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

          if (routeTransitions > maxRouteTransitions) {
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
    ...(lastCompletedTaskKey ? {
      completedTaskKey: lastCompletedTaskKey,
      completed_task_key: lastCompletedTaskKey,
    } : {}),
    debugArtifacts,
    browserWindows: lastBrowserWindows,
    browserWsEndpoint: browserWsEndpoint(),
    browserIdentity: browserIdentityPayload(),
    runnerDiagnostics: {
      keepWorkflowBrowserAlive: shouldKeepWorkflowBrowserProcessAlive(),
      workflowBundleStep: runtime.workflowBundleStep === true || runtime.workflow_bundle_step === true,
      chromiumNoSandboxFlag: process.platform === 'linux' && chromiumNoSandboxEnabled(),
      platform: process.platform,
    },
    events,
    finishedAt: now(),
    ...(() => {
      const routedTask = requestedFailureRouteTask || requestedSuccessRouteTask;
      const routedResult = routedTask
        ? taskResults.find((candidate) => String(candidate.key || '') === String(routedTask.key || ''))
        : null;
      const branchOutcome = String(routedResult?.branchOutcome || routedResult?.branch_outcome || '').trim().toLowerCase();
      const logicalOutcome = requestedFailureRouteTask
        ? (branchOutcome === 'failed' ? 'condition_false' : 'technical_error')
        : (routedResult?.logicalOutcome || routedResult?.logical_outcome || 'success');
      const route = requestedFailureRouteTask?.on_error || requestedSuccessRouteTask?.next || null;

      return {
        logicalOutcome,
        logical_outcome: logicalOutcome,
        routeDisposition: route ? routeDisposition(route) : 'continue',
        route_disposition: route ? routeDisposition(route) : 'continue',
      };
    })(),
    ...(requestedDynamicRoute ? {
      routeRequested: true,
      route_requested: true,
      completedTaskKey: requestedDynamicRoute.sourceTaskKey,
      completed_task_key: requestedDynamicRoute.sourceTaskKey,
      routeOutcome: requestedDynamicRoute.outcome,
      route_outcome: requestedDynamicRoute.outcome,
      routeTargetKey: requestedDynamicRoute.targetTaskKey,
      route_target_key: requestedDynamicRoute.targetTaskKey,
    } : (requestedFailureRouteTask || requestedSuccessRouteTask) ? {
      routeRequested: true,
      completedTaskKey: (requestedFailureRouteTask || requestedSuccessRouteTask).key,
      routeOutcome: requestedFailureRouteTask ? 'failed' : 'success',
    } : {}),
  };

  flushDebugArtifactManifest(true);
  writeJson(runtime.resultPath, result);
  writeStatus('completed', 'completed', result.statusMessage, { result });
}

run()
  .catch((error) => {
    finalRunState = 'failed';
    const result = {
      ok: false,
      status: 'failed',
      statusMessage: error.message,
      error: error.stack || error.message,
      tasks: taskResults,
      debugArtifacts,
      browserWindows: lastBrowserWindows,
      browserWsEndpoint: browserWsEndpoint(),
      browserIdentity: browserIdentityPayload(),
      runnerDiagnostics: {
        keepWorkflowBrowserAlive: shouldKeepWorkflowBrowserProcessAlive(),
        workflowBundleStep: runtime.workflowBundleStep === true || runtime.workflow_bundle_step === true,
        chromiumNoSandboxFlag: process.platform === 'linux' && chromiumNoSandboxEnabled(),
        platform: process.platform,
      },
      events,
      finishedAt: now(),
    };
    flushDebugArtifactManifest(true);
    writeJson(runtime.resultPath, result);
    writeStatus('failed', 'failed', error.message, { result });
  })
  .finally(async () => {
    flushDebugArtifactManifest(true);

    if (!shutdownInProgress && !fatalErrorHandled) {
      await finalizeBrowserLifecycle(finalRunState);
    }
  });
