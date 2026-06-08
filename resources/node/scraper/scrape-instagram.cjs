const fs = require('fs');
const os = require('os');
const path = require('path');

function ensureDirectory(directoryPath) {
  fs.mkdirSync(directoryPath, { recursive: true });
  return directoryPath;
}

function looksBrokenWindowsPath(directoryPath) {
  return /^undefined([\\/]|$)/i.test(directoryPath);
}

function isUsableDirectory(directoryPath) {
  if (!directoryPath || typeof directoryPath !== 'string') {
    return false;
  }

  if (looksBrokenWindowsPath(directoryPath)) {
    return false;
  }

  try {
    ensureDirectory(directoryPath);
    fs.accessSync(directoryPath, fs.constants.W_OK);
    return true;
  } catch (error) {
    return false;
  }
}

function resolveRuntimeTempDirectory() {
  const explicitCandidates = [
    process.env.PUPPETEER_TMP_DIR,
    process.env.TEMP,
    process.env.TMP,
    process.env.TMPDIR,
  ].filter(Boolean);

  for (const candidate of explicitCandidates) {
    if (isUsableDirectory(candidate)) {
      return candidate;
    }
  }

  try {
    const systemTmp = os.tmpdir();

    if (isUsableDirectory(systemTmp)) {
      return systemTmp;
    }
  } catch (error) {
    // Ignore invalid system temp resolution and use the project-local fallback below.
  }

  return ensureDirectory(path.resolve(__dirname, '../../../storage/app/tmp'));
}

function resolveWindowsSystemRoot() {
  if (process.platform !== 'win32') {
    return null;
  }

  const candidates = [
    process.env.SystemRoot,
    process.env.windir,
    'C:\\Windows',
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (looksBrokenWindowsPath(candidate)) {
      continue;
    }

    try {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    } catch (error) {
      // Ignore broken candidates and continue with the next one.
    }
  }

  return null;
}

const runtimeTempDirectory = resolveRuntimeTempDirectory();
const windowsSystemRoot = resolveWindowsSystemRoot();

process.env.PUPPETEER_TMP_DIR = runtimeTempDirectory;
process.env.TEMP = process.env.TEMP || runtimeTempDirectory;
process.env.TMP = process.env.TMP || runtimeTempDirectory;
process.env.TMPDIR = process.env.TMPDIR || runtimeTempDirectory;

if (windowsSystemRoot) {
  process.env.SystemRoot = process.env.SystemRoot || windowsSystemRoot;
  process.env.windir = process.env.windir || windowsSystemRoot;
}

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

const rawUsername = process.argv[2] || '';
const username = rawUsername.replace(/^@/, '').trim();
const runtimeConfigPath = process.argv[3] || '';
const operationMode = normalizeText(process.argv[4] || 'analyze').toLowerCase();
const isLoginSessionMode = operationMode === 'login-session';
const isMiniScanMode = ['mini', 'mini-scan', 'public', 'public-profile'].includes(operationMode);
const isProfileOnlyMode = isMiniScanMode || ['profile', 'basic', 'grunddaten'].includes(operationMode);
const isFollowersOnlyMode = operationMode === 'followers';
const isFollowingOnlyMode = operationMode === 'following';
const isFollowersSearchMode = ['followers-search', 'search-followers'].includes(operationMode);
const isFollowingSearchMode = ['following-search', 'search-following'].includes(operationMode);
const isSuggestionsMode = ['suggestions', 'profile-suggestions', 'suggestion-connections'].includes(operationMode);
const isRelationshipSearchOnlyMode = isFollowersSearchMode || isFollowingSearchMode;
const isRelationshipOnlyMode = isFollowersOnlyMode || isFollowingOnlyMode || isRelationshipSearchOnlyMode;
const shouldCollectFollowers = operationMode === 'analyze' || isFollowersOnlyMode || isFollowersSearchMode;
const shouldCollectFollowing = operationMode === 'analyze' || isFollowingOnlyMode || isFollowingSearchMode;
const DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS = 0;
const DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS = 100000;
const RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT = 2;
const LIVE_PREVIEW_MIN_INTERVAL_MS = 2500;
const RELATIONSHIP_SEARCH_PARTITION_CHARACTERS = [
  'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
  'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
  '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '_',
];
const RELATIONSHIP_SEARCH_PARTITION_QUERIES = RELATIONSHIP_SEARCH_PARTITION_CHARACTERS;

class ScraperAbortError extends Error {
  constructor(message, code = 'SCRAPER_ABORTED') {
    super(message);
    this.name = 'ScraperAbortError';
    this.code = code;
  }
}

const scriptWatchdog = {
  enabled: false,
  interval: null,
  timeoutMs: 0,
  lastActivityAt: Date.now(),
  lastActivityReason: 'start',
  abortError: null,
  abortListeners: new Set(),
  browser: null,
  intentionalBrowserClose: false,
  browserDisconnectAbort: true,
};

function markScriptActivity(reason = 'activity') {
  scriptWatchdog.lastActivityAt = Date.now();
  scriptWatchdog.lastActivityReason = reason;
}

function requestScriptAbort(message, code = 'SCRAPER_ABORTED') {
  if (scriptWatchdog.abortError) {
    return scriptWatchdog.abortError;
  }

  const error = new ScraperAbortError(message, code);
  scriptWatchdog.abortError = error;

  process.stderr.write(`[SCRAPER ABORT] ${JSON.stringify({
    at: new Date().toISOString(),
    code,
    message,
    lastActivityReason: scriptWatchdog.lastActivityReason,
    idleMs: Date.now() - scriptWatchdog.lastActivityAt,
  })}\n`);

  try {
    recordRunDebug('script-abort-requested', {
      code,
      message,
      lastActivityReason: scriptWatchdog.lastActivityReason,
      idleMs: Date.now() - scriptWatchdog.lastActivityAt,
    });
  } catch {
    // Debug logging must never block abort handling.
  }

  for (const listener of Array.from(scriptWatchdog.abortListeners)) {
    try {
      listener(error);
    } catch {
      // Ignore listener cleanup failures.
    }
  }

  try {
    scriptWatchdog.browser?.process?.()?.kill?.('SIGTERM');
  } catch {
    // The browser may already be gone.
  }

  return error;
}

function assertScriptAlive() {
  if (scriptWatchdog.abortError) {
    throw scriptWatchdog.abortError;
  }
}

function configureScriptWatchdog(runtimeConfig = {}) {
  const enabled = runtimeConfig.scriptWatchdogEnabled !== false;
  const configuredTimeout = Number(runtimeConfig.scriptStallTimeoutMs || runtimeConfig.nodeStallTimeoutMs || 900000);
  const timeoutMs = Number.isFinite(configuredTimeout)
    ? Math.max(60000, configuredTimeout)
    : 900000;

  scriptWatchdog.enabled = enabled;
  scriptWatchdog.timeoutMs = timeoutMs;
  scriptWatchdog.browserDisconnectAbort = runtimeConfig.browserDisconnectAbort !== false;
  markScriptActivity('watchdog-configured');

  if (scriptWatchdog.interval) {
    clearInterval(scriptWatchdog.interval);
    scriptWatchdog.interval = null;
  }

  if (!enabled) {
    return;
  }

  scriptWatchdog.interval = setInterval(() => {
    if (scriptWatchdog.abortError) {
      return;
    }

    const idleMs = Date.now() - scriptWatchdog.lastActivityAt;

    if (idleMs >= scriptWatchdog.timeoutMs) {
      requestScriptAbort(
        `Node-Scraper abgebrochen: seit ${Math.round(idleMs / 1000)} Sekunden kein Fortschritt (${scriptWatchdog.lastActivityReason}).`,
        'SCRIPT_STALLED',
      );
    }
  }, Math.min(30000, Math.max(5000, Math.floor(timeoutMs / 6))));

  scriptWatchdog.interval.unref?.();
}

function stopScriptWatchdog() {
  if (scriptWatchdog.interval) {
    clearInterval(scriptWatchdog.interval);
    scriptWatchdog.interval = null;
  }
}

function sleep(ms) {
  assertScriptAlive();

  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (callback, value) => {
      if (settled) {
        return;
      }

      settled = true;
      clearTimeout(timer);
      scriptWatchdog.abortListeners.delete(onAbort);
      callback(value);
    };
    const onAbort = (error) => finish(reject, error);
    const timer = setTimeout(() => {
      try {
        assertScriptAlive();
        finish(resolve);
      } catch (error) {
        finish(reject, error);
      }
    }, Math.max(0, Number(ms) || 0));

    scriptWatchdog.abortListeners.add(onAbort);
  });
}

function resolveNavigationWaitMs(runtimeConfig, fallbackMs = 120000) {
  const configured = Number(runtimeConfig?.navigationTimeoutMs);

  if (Number.isFinite(configured) && configured > 0) {
    return configured;
  }

  return fallbackMs;
}

async function navigateWithSoftTimeout(page, url, runtimeConfig, options = {}) {
  const timeoutMs = Math.max(15000, Number(options.timeoutMs || resolveNavigationWaitMs(runtimeConfig)));
  const waitUntil = options.waitUntil || 'domcontentloaded';

  try {
    const response = await page.goto(url, {
      timeout: timeoutMs,
      waitUntil,
    });
    const status = response?.status?.() ?? null;
    const ok = response && status < 400;

    if (!ok) {
      const errorMessage = `HTTP ${status}`;
      recordRunDebug('navigation-http-error', {
        url,
        finalUrl: page.url(),
        timeoutMs,
        waitUntil,
        status,
      });

      return {
        ok: false,
        status,
        error: errorMessage,
        finalUrl: page.url(),
        timeoutMs,
      };
    }

    return {
      ok: true,
      status,
      finalUrl: page.url(),
      timeoutMs,
    };
  } catch (error) {
    const message = normalizeText(error.message || String(error));

    await page.evaluate(() => window.stop()).catch(() => {});
    recordRunDebug('navigation-soft-timeout', {
      url,
      finalUrl: page.url(),
      timeoutMs,
      waitUntil,
      error: message,
    });

    return {
      ok: false,
      error: message,
      finalUrl: page.url(),
      timeoutMs,
    };
  }
}

async function closeBrowserSoftly(browser, timeoutMs = 10000) {
  if (!browser) {
    return;
  }

  try {
    await Promise.race([
      browser.close(),
      sleep(timeoutMs).then(() => {
        throw new Error(`browser.close soft timeout after ${timeoutMs}ms`);
      }),
    ]);
  } catch (error) {
    recordRunDebug('browser-close-soft-failed', {
      error: normalizeText(error.message || String(error)),
    });

    try {
      browser.process()?.kill?.('SIGKILL');
    } catch (killError) {
      recordRunDebug('browser-kill-failed', {
        error: normalizeText(killError.message || String(killError)),
      });
    }
  }
}

function normalizeText(value = '') {
  return value.replace(/\s+/g, ' ').trim();
}

function debugLog(message, data) {
  const enabled = true;
  if (!enabled) {
    return;
  }

  const payload = typeof data !== 'undefined'
    ? `${message} ${JSON.stringify(data, null, 2)}`
    : message;

  process.stderr.write(`[SCRAPER DEBUG] ${payload}\n`);
}

let activeScraperProfile = null;
let activeGracefulStopFilePath = null;
let gracefulStopProgressLogged = false;
let lastLivePreviewAt = 0;

function summarizeScraperProfile(runtimeConfig = {}) {
  const profileLabel = normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || 'instagram-default';
  const loginUsername = normalizeText(String(runtimeConfig.loginUsername || runtimeConfig.login_username || ''));
  const profileId = normalizeText(String(runtimeConfig.profileId || runtimeConfig.profile_id || ''));

  return {
    label: profileLabel,
    loginUsername: loginUsername || null,
    id: profileId || null,
  };
}

function setActiveScraperProfile(runtimeConfig = {}) {
  activeScraperProfile = summarizeScraperProfile(runtimeConfig);
}

function setGracefulStopFilePath(runtimeConfig = {}) {
  activeGracefulStopFilePath = normalizeText(String(
    runtimeConfig.gracefulStopFilePath || runtimeConfig.graceful_stop_file_path || '',
  )) || null;
}

function progressLog(stage, data = {}) {
  assertScriptAlive();
  markScriptActivity(`progress:${stage}`);

  process.stderr.write(`[SCRAPER PROGRESS] ${JSON.stringify({
    at: new Date().toISOString(),
    mode: operationMode,
    stage,
    ...(activeScraperProfile ? {
      scraperProfile: activeScraperProfile,
      scraperProfileLabel: activeScraperProfile.label,
      scraperProfileLoginUsername: activeScraperProfile.loginUsername,
      scraperProfileId: activeScraperProfile.id,
    } : {}),
    ...data,
  })}\n`);
}

function readGracefulStopRequest() {
  if (!activeGracefulStopFilePath) {
    return null;
  }

  try {
    if (!fs.existsSync(activeGracefulStopFilePath)) {
      return null;
    }

    const rawPayload = fs.readFileSync(activeGracefulStopFilePath, 'utf8');
    const payload = rawPayload ? JSON.parse(rawPayload) : {};

    return {
      reason: normalizeText(String(payload?.reason || 'Scan wurde in der Oberflaeche beendet.')),
      requestedAt: normalizeText(String(payload?.requestedAt || '')) || null,
    };
  } catch (error) {
    return {
      reason: 'Scan wurde in der Oberflaeche beendet.',
      requestedAt: null,
    };
  }
}

function markGracefulStopIfRequested(relationship = null, data = {}) {
  const request = readGracefulStopRequest();

  if (!request) {
    return null;
  }

  if (!gracefulStopProgressLogged) {
    gracefulStopProgressLogged = true;
    progressLog('scan-stop-requested', {
      relationship,
      gracefullyStopped: true,
      stopReason: 'ui-stop-requested',
      message: 'Stop angefordert. Der aktuelle Zwischestand wird gespeichert.',
      ...data,
    });
  }

  return request;
}

function sanitizeDebugPayload(value, depth = 0) {
  if (depth > 5) {
    return '[depth-limit]';
  }

  if (value === null || typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }

  if (typeof value === 'string') {
    return value.length > 2500 ? `${value.slice(0, 2500)}…` : value;
  }

  if (Array.isArray(value)) {
    return value.slice(0, 60).map((entry) => sanitizeDebugPayload(entry, depth + 1));
  }

  if (typeof value === 'object') {
    const sanitized = {};

    for (const [key, entry] of Object.entries(value)) {
      if (/^(password|loginPassword|login_password.*)$/i.test(key)) {
        sanitized[key] = '[redacted]';
        continue;
      }

      sanitized[key] = sanitizeDebugPayload(entry, depth + 1);
    }

    return sanitized;
  }

  return String(value);
}

function buildRunDebugLogPath(mode, currentUsername) {
  const debugDirectory = ensureDirectory(
    path.resolve(__dirname, '../../../storage/logs/instagram-scraper'),
  );
  const safeUsername = normalizeText(currentUsername || 'session')
    .replace(/[^a-z0-9._-]+/gi, '_')
    .slice(0, 80) || 'session';
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');

  return path.join(debugDirectory, `${stamp}-${mode}-${safeUsername}.json`);
}

let activeRunDebug = null;

function initializeRunDebug(mode, currentUsername, runtimeConfig) {
  activeRunDebug = {
    filePath: buildRunDebugLogPath(mode, currentUsername),
    startedAt: new Date().toISOString(),
    mode,
    username: currentUsername || null,
    profileLabel: runtimeConfig?.profileLabel || null,
    persistentProfileEnabled: Boolean(runtimeConfig?.persistentProfileEnabled),
    browserProfilePath: runtimeConfig?.browserProfilePath || null,
    cookieFilePath: runtimeConfig?.cookieFilePath || null,
    headlessEnabled: Boolean(runtimeConfig?.headlessEnabled),
    autoLoginEnabled: Boolean(runtimeConfig?.autoLoginEnabled),
    loginUsername: runtimeConfig?.loginUsername || null,
    loginPasswordConfigured: Boolean(runtimeConfig?.loginPasswordConfigured),
    loginPasswordDecryptable: runtimeConfig?.loginPasswordDecryptable !== false,
    loginPasswordSource: runtimeConfig?.loginPasswordSource || null,
    events: [],
  };

  return activeRunDebug.filePath;
}

function recordRunDebug(step, data = {}) {
  if (!activeRunDebug) {
    return;
  }

  activeRunDebug.events.push({
    at: new Date().toISOString(),
    step,
    data: sanitizeDebugPayload(data),
  });
}

function flushRunDebug(finalPayload = {}) {
  if (!activeRunDebug?.filePath) {
    return null;
  }

  const logPayload = {
    ...activeRunDebug,
    finishedAt: new Date().toISOString(),
    final: sanitizeDebugPayload(finalPayload),
  };

  try {
    fs.writeFileSync(activeRunDebug.filePath, JSON.stringify(logPayload, null, 2), 'utf8');
  } catch (error) {
    debugLog('Fehler beim Schreiben des Debug-Logs:', error.message);
  }

  return activeRunDebug.filePath;
}

function summarizeCookieCollection(cookies = []) {
  return cookies.slice(0, 30).map((cookie) => ({
    name: cookie.name,
    domain: cookie.domain,
    path: cookie.path,
    expires: cookie.expires ?? null,
    httpOnly: Boolean(cookie.httpOnly),
    secure: Boolean(cookie.secure),
    sameSite: cookie.sameSite ?? null,
  }));
}

function dedupe(values) {
  return [...new Set(values.filter(Boolean))];
}

function normalizeInstagramUsername(value = '') {
  return normalizeText(String(value || ''))
    .replace(/^@/, '')
    .replace(/[^a-z0-9._]/gi, '')
    .toLowerCase();
}

function normalizeOptionalPositiveInteger(value, fallback = 0) {
  const normalizedValue = Number(value ?? fallback);

  if (!Number.isFinite(normalizedValue) || normalizedValue <= 0) {
    return 0;
  }

  return Math.floor(normalizedValue);
}

function normalizeNumberAtLeast(value, fallback, minimum) {
  const normalizedValue = Number(value ?? fallback);

  if (!Number.isFinite(normalizedValue)) {
    return Math.max(minimum, fallback);
  }

  return Math.max(minimum, normalizedValue);
}

function normalizeSuggestionCandidateHistory(value = {}) {
  const input = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  const history = {};

  for (const [rawUsername, rawState] of Object.entries(input)) {
    const username = normalizeInstagramUsername(rawUsername);

    if (!username) {
      continue;
    }

    const state = rawState && typeof rawState === 'object' && !Array.isArray(rawState) ? rawState : {};
    const noMatchChecks = Math.max(0, Math.floor(Number(state.noMatchChecks || state.no_match_checks || 0)));

    history[username] = {
      hasMatch: state.hasMatch === true || state.has_match === true,
      noMatchChecks,
      permanentlyDismissed:
        state.permanentlyDismissed === true
        || state.permanently_dismissed === true
        || noMatchChecks >= 2,
    };
  }

  return history;
}

function normalizeRuntimeConfigShape(config = {}, defaults = {}) {
  const input = config && typeof config === 'object' ? config : {};
  const merged = {
    ...defaults,
    ...input,
  };

  return {
    ...merged,
    profileId: normalizeText(String(input.profileId || input.profile_id || merged.profileId || '')),
    profileLabel: normalizeText(String(input.profileLabel || input.profile_label || merged.profileLabel || 'instagram-default')) || 'instagram-default',
    persistentProfileEnabled: isLoginSessionMode && input?.persistentProfileEnabled !== false,
    headlessEnabled: input?.headlessEnabled !== false,
    autoLoginEnabled: input?.autoLoginEnabled === true,
    miniScanUseSession: input?.miniScanUseSession === true || input?.mini_scan_use_session === true,
    navigationTimeoutMs: Math.max(30000, Number(input?.navigationTimeoutMs || merged.navigationTimeoutMs || 120000)),
    postLoginWaitMs: Math.max(500, Number(input?.postLoginWaitMs || merged.postLoginWaitMs || 2500)),
    typingDelayMs: Math.max(0, Number(input?.typingDelayMs || merged.typingDelayMs || 0)),
    followerListMaxItems: normalizeOptionalPositiveInteger(input?.followerListMaxItems, merged.followerListMaxItems),
    followingListMaxItems: normalizeOptionalPositiveInteger(input?.followingListMaxItems, merged.followingListMaxItems),
    relationshipListMaxScrollRounds: Math.max(
      20,
      normalizeOptionalPositiveInteger(
        input?.relationshipListMaxScrollRounds,
        merged.relationshipListMaxScrollRounds,
      ) || merged.relationshipListMaxScrollRounds || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    ),
    expectedFollowerCount: normalizeOptionalPositiveInteger(input?.expectedFollowerCount, merged.expectedFollowerCount),
    expectedFollowingCount: normalizeOptionalPositiveInteger(input?.expectedFollowingCount, merged.expectedFollowingCount),
    relationshipSearchOnly: input?.relationshipSearchOnly === true || input?.relationship_search_only === true,
    relationshipSearchTargetUsername: normalizeInstagramUsername(input?.relationshipSearchTargetUsername || merged.relationshipSearchTargetUsername || ''),
    relationshipSearchTargetMaxItems: normalizeOptionalPositiveInteger(input?.relationshipSearchTargetMaxItems, merged.relationshipSearchTargetMaxItems),
    relationshipSearchTargetMaxScrollRounds: normalizeOptionalPositiveInteger(input?.relationshipSearchTargetMaxScrollRounds, merged.relationshipSearchTargetMaxScrollRounds),
    relationshipSearchInputMaxAttempts: Math.floor(normalizeNumberAtLeast(input?.relationshipSearchInputMaxAttempts, merged.relationshipSearchInputMaxAttempts || 3, 1)),
    suggestionScanMaxItems: normalizeOptionalPositiveInteger(input?.suggestionScanMaxItems, merged.suggestionScanMaxItems || 12) || 12,
    suggestionCandidateMaxItems: normalizeOptionalPositiveInteger(input?.suggestionCandidateMaxItems, merged.suggestionCandidateMaxItems || 8) || 8,
    suggestionPublicListSearchMaxScrollRounds: normalizeOptionalPositiveInteger(
      input?.suggestionPublicListSearchMaxScrollRounds,
      merged.suggestionPublicListSearchMaxScrollRounds || 60,
    ) || 60,
    suggestionDismissChecked: input?.suggestionDismissChecked === true || input?.suggestion_dismiss_checked === true,
    suggestionCandidateHistory: normalizeSuggestionCandidateHistory(input?.suggestionCandidateHistory || input?.suggestion_candidate_history || merged.suggestionCandidateHistory),
    scriptWatchdogEnabled: input?.scriptWatchdogEnabled !== false && input?.script_watchdog_enabled !== false,
    scriptStallTimeoutMs: normalizeNumberAtLeast(input?.scriptStallTimeoutMs || input?.nodeStallTimeoutMs, merged.scriptStallTimeoutMs || 900000, 60000),
    browserDisconnectAbort: input?.browserDisconnectAbort !== false && input?.browser_disconnect_abort !== false,
    publicConnectionCandidateMaxAttempts: Math.min(
      3,
      Math.floor(normalizeNumberAtLeast(input?.publicConnectionCandidateMaxAttempts, merged.publicConnectionCandidateMaxAttempts || 3, 1)),
    ),
    publicConnectionCandidateMaxDurationMs: normalizeNumberAtLeast(input?.publicConnectionCandidateMaxDurationMs, merged.publicConnectionCandidateMaxDurationMs || 1200000, 60000),
    publicConnectionDialogMissingMaxAttempts: Math.floor(normalizeNumberAtLeast(input?.publicConnectionDialogMissingMaxAttempts, merged.publicConnectionDialogMissingMaxAttempts || 2, 1)),
    publicConnectionRateLimitAccountSwitchEnabled: input?.publicConnectionRateLimitAccountSwitchEnabled !== false
      && input?.public_connection_rate_limit_account_switch_enabled !== false
      && merged.publicConnectionRateLimitAccountSwitchEnabled !== false,
    gracefulStopFilePath: normalizeText(String(input?.gracefulStopFilePath || input?.graceful_stop_file_path || merged.gracefulStopFilePath || '')),
    livePreviewPath: normalizeText(String(input?.livePreviewPath || input?.live_preview_path || merged.livePreviewPath || '')),
    livePreviewEnabled: input?.livePreviewEnabled !== false && input?.live_preview_enabled !== false && merged.livePreviewEnabled !== false,
    skipDebugArtifacts: input?.skipDebugArtifacts === true || input?.skip_debug_artifacts === true,
    blockHeavyResources: input?.blockHeavyResources === true || input?.block_heavy_resources === true,
    accountPool: Array.isArray(input.accountPool) ? input.accountPool : [],
  };
}

function normalizeRuntimeAccountConfig(account, baseConfig) {
  const input = account && typeof account === 'object' ? account : {};

  return normalizeRuntimeConfigShape({
    ...baseConfig,
    autoLoginEnabled: false,
    loginUsername: '',
    loginPassword: '',
    loginPasswordConfigured: false,
    loginPasswordDecryptable: true,
    loginPasswordSource: null,
    ...input,
    accountPool: [],
  }, baseConfig);
}

function getScraperAccountKey(runtimeConfig = {}) {
  const candidates = [
    runtimeConfig.profileId,
    runtimeConfig.cookieFilePath,
    runtimeConfig.loginUsername,
    runtimeConfig.profileLabel,
  ];

  for (const candidate of candidates) {
    const normalized = normalizeText(String(candidate || ''));

    if (normalized) {
      return normalized;
    }
  }

  return '';
}

function normalizeRuntimeAccountPool(accountPool, runtimeConfig) {
  const rawAccounts = Array.isArray(accountPool) ? accountPool : [];
  const normalizedAccounts = [
    { ...runtimeConfig, accountPool: [] },
    ...rawAccounts.map((account) => normalizeRuntimeAccountConfig(account, runtimeConfig)),
  ];
  const seenAccountKeys = new Set();
  const pool = [];

  for (const account of normalizedAccounts) {
    const accountKey = getScraperAccountKey(account);

    if (!accountKey || seenAccountKeys.has(accountKey)) {
      continue;
    }

    seenAccountKeys.add(accountKey);
    pool.push({
      ...account,
      accountPool: [],
    });
  }

  return pool;
}

function loadRuntimeConfig(configPath) {
  const defaults = {
    profileId: '',
    profileLabel: 'instagram-default',
    persistentProfileEnabled: isLoginSessionMode,
    browserProfilePath: path.resolve(__dirname, '../../../storage/app/browser-profiles/instagram/default'),
    cookieFilePath: path.resolve(__dirname, '../../../storage/app/cookies/instagram-cookies.json'),
    headlessEnabled: true,
    autoLoginEnabled: false,
    miniScanUseSession: false,
    loginUsername: '',
    loginPassword: '',
    loginPasswordConfigured: false,
    loginPasswordDecryptable: true,
    loginPasswordSource: null,
    navigationTimeoutMs: 120000,
    postLoginWaitMs: 2500,
    typingDelayMs: 35,
    followerListMaxItems: DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS,
    followingListMaxItems: DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS,
    relationshipListMaxScrollRounds: DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    expectedFollowerCount: 0,
    expectedFollowingCount: 0,
    relationshipSearchOnly: false,
    relationshipSearchTargetUsername: '',
    relationshipSearchTargetMaxItems: 0,
    relationshipSearchTargetMaxScrollRounds: 60,
    relationshipSearchInputMaxAttempts: 3,
    suggestionScanMaxItems: 12,
    suggestionCandidateMaxItems: 8,
    suggestionPublicListSearchMaxScrollRounds: 60,
    suggestionDismissChecked: false,
    suggestionCandidateHistory: {},
    scriptWatchdogEnabled: true,
    scriptStallTimeoutMs: 900000,
    browserDisconnectAbort: true,
    publicConnectionCandidateMaxAttempts: 3,
    publicConnectionCandidateMaxDurationMs: 1200000,
    publicConnectionDialogMissingMaxAttempts: 2,
    publicConnectionRateLimitAccountSwitchEnabled: true,
    gracefulStopFilePath: '',
    livePreviewPath: '',
    livePreviewEnabled: true,
    skipDebugArtifacts: false,
    blockHeavyResources: false,
    accountPool: [],
  };

  if (!configPath || !fs.existsSync(configPath)) {
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig);

    return runtimeConfig;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    const runtimeConfig = normalizeRuntimeConfigShape(parsed, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool(parsed?.accountPool, runtimeConfig);

    return runtimeConfig;
  } catch (error) {
    debugLog('Fehler beim Laden der Runtime-Konfiguration:', error.message);
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig);

    return runtimeConfig;
  }
}

function buildBrowserUserDataDir(runtimeConfig) {
  if (runtimeConfig?.persistentProfileEnabled && isUsableDirectory(runtimeConfig.browserProfilePath)) {
    return ensureDirectory(runtimeConfig.browserProfilePath);
  }

  return fs.mkdtempSync(
    path.join(runtimeTempDirectory, 'puppeteer-profile-'),
  );
}

function hasDisplayServer() {
  if (process.platform === 'win32' || process.platform === 'darwin') {
    return true;
  }

  return Boolean(process.env.DISPLAY || process.env.WAYLAND_DISPLAY);
}

function resolvePuppeteerHeadlessMode(runtimeConfig) {
  const requestedHeadful = isLoginSessionMode || runtimeConfig?.headlessEnabled === false;

  if (requestedHeadful && hasDisplayServer()) {
    return false;
  }

  return 'new';
}

function shouldCleanupBrowserProfile(runtimeConfig, browserUserDataDir) {
  if (!browserUserDataDir) {
    return false;
  }

  if (runtimeConfig?.persistentProfileEnabled && runtimeConfig.browserProfilePath === browserUserDataDir) {
    return false;
  }

  return true;
}

function escapeHtml(value = '') {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function buildArtifactPaths(username) {
  const artifactBasePath = path.join(
    __dirname,
    '../../../storage/app/public/screenshots/instagram',
    username,
  );

  fs.mkdirSync(artifactBasePath, { recursive: true });

  const stamp = Date.now();

  return {
    htmlPath: path.join(artifactBasePath, `profile-page-${stamp}.html`),
    screenshotPath: path.join(artifactBasePath, `instagram-page-${stamp}.png`),
    livePreviewPath: path.join(artifactBasePath, `instagram-live-preview-${stamp}.png`),
  };
}

function buildRelatedScreenshotPath(baseScreenshotPath, suffix) {
  const safeSuffix = normalizeText(String(suffix || 'debug'))
    .replace(/[^a-z0-9._-]+/gi, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 90) || 'debug';
  const directory = path.dirname(baseScreenshotPath);
  const extension = path.extname(baseScreenshotPath) || '.png';
  const baseName = path.basename(baseScreenshotPath, extension);

  ensureDirectory(directory);

  return path.join(directory, `${baseName}-${safeSuffix}${extension}`);
}

function buildCookiePath(runtimeConfig) {
  const configuredPath = normalizeText(String(runtimeConfig?.cookieFilePath || ''));

  if (configuredPath) {
    ensureDirectory(path.dirname(configuredPath));
    return configuredPath;
  }

  const cookieBasePath = path.join(
    __dirname,
    '../../../storage/app/cookies',
  );
  ensureDirectory(cookieBasePath);
  return path.join(cookieBasePath, 'instagram-cookies.json');
}

function buildScraperProfileBlockPath(runtimeConfig = {}) {
  const profileLabel = normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default'))
    .replace(/[^a-z0-9._-]+/gi, '_')
    .slice(0, 80) || 'instagram-default';
  const profileDirectory = isUsableDirectory(runtimeConfig.browserProfilePath)
    ? runtimeConfig.browserProfilePath
    : path.join(__dirname, '../../../storage/app/browser-profiles/instagram', profileLabel);

  ensureDirectory(profileDirectory);
  return path.join(profileDirectory, 'profile-blocked.json');
}

function persistScraperProfileBlock(runtimeConfig = {}, reason = 'instagram-rate-limit', details = {}) {
  try {
    const blockPath = buildScraperProfileBlockPath(runtimeConfig);
    const blockInfo = {
      profileLabel: normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || null,
      loginUsername: normalizeText(String(runtimeConfig.loginUsername || runtimeConfig.login_username || '')) || null,
      blockedAt: new Date().toISOString(),
      reason: normalizeText(String(reason || 'instagram-rate-limit')) || 'instagram-rate-limit',
      details: {
        relationship: details.relationship || null,
        status: details.status || null,
        source: details.source || null,
        text: normalizeText(String(details.text || '')) || null,
      },
    };

    fs.writeFileSync(blockPath, JSON.stringify(blockInfo, null, 2), 'utf8');
    return blockInfo;
  } catch (error) {
    recordRunDebug('scraper-profile-block-write-failed', {
      error: normalizeText(error.message || String(error)),
      profileLabel: normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || null,
    });
    return null;
  }
}

async function loadCookiesFromFile(page, cookieFilePath) {
  try {
    if (!fs.existsSync(cookieFilePath)) {
      debugLog('Cookie-Datei nicht gefunden:', cookieFilePath);
      return false;
    }

    const cookieData = fs.readFileSync(cookieFilePath, 'utf8');
    const cookies = JSON.parse(cookieData);

    if (!Array.isArray(cookies) || cookies.length === 0) {
      debugLog('Cookie-Datei enthält keine Cookies.');
      return false;
    }

    await page.setCookie(...cookies);
    debugLog('Instagram-Cookies geladen', cookies.map((cookie) => cookie.name));
    return true;
  } catch (error) {
    debugLog('Fehler beim Laden der Cookies:', error.message);
    return false;
  }
}

function extractCookieArray(rawCookiePayload) {
  if (Array.isArray(rawCookiePayload)) {
    return rawCookiePayload;
  }

  if (Array.isArray(rawCookiePayload?.cookies)) {
    return rawCookiePayload.cookies;
  }

  return [];
}

function normalizeSameSiteValue(rawSameSite) {
  const normalizedValue = normalizeText(String(rawSameSite || '')).toLowerCase();

  if (!normalizedValue || normalizedValue === 'unspecified') {
    return undefined;
  }

  if (normalizedValue === 'no_restriction' || normalizedValue === 'none') {
    return 'None';
  }

  if (normalizedValue === 'lax') {
    return 'Lax';
  }

  if (normalizedValue === 'strict') {
    return 'Strict';
  }

  return undefined;
}

function normalizeCookieRecord(rawCookie) {
  if (!rawCookie || typeof rawCookie !== 'object') {
    return null;
  }

  const name = normalizeText(rawCookie.name || '');
  const value = typeof rawCookie.value === 'string' ? rawCookie.value : String(rawCookie.value ?? '');
  const rawDomain = normalizeText(rawCookie.domain || rawCookie.host || '');
  const pathValue = normalizeText(rawCookie.path || '/');
  const secure = Boolean(rawCookie.secure ?? rawCookie.isSecure);
  const httpOnly = Boolean(rawCookie.httpOnly ?? rawCookie.isHttpOnly);
  const sameSite = normalizeSameSiteValue(rawCookie.sameSite);
  const expires = Number(rawCookie.expires ?? rawCookie.expirationDate ?? rawCookie.expiry ?? 0);

  if (!name || value === '' || !rawDomain) {
    return null;
  }

  const normalizedCookie = {
    name,
    value,
    domain: rawDomain.toLowerCase(),
    path: pathValue || '/',
    secure,
    httpOnly,
  };

  if (sameSite) {
    normalizedCookie.sameSite = sameSite;
  }

  if (Number.isFinite(expires) && expires > 0) {
    normalizedCookie.expires = expires;
  }

  return normalizedCookie;
}

function isRelevantCookieDomain(domain) {
  const normalizedDomain = normalizeText(String(domain || ''))
    .replace(/^\./, '')
    .toLowerCase();

  return normalizedDomain.endsWith('instagram.com') || normalizedDomain.endsWith('facebook.com');
}

async function loadCookiesFromFileWithDiagnostics(page, cookieFilePath) {
  const diagnostics = {
    loaded: false,
    providedCount: 0,
    acceptedCount: 0,
    providedNames: [],
    acceptedNames: [],
    sessionCookieProvided: false,
    sessionCookieAccepted: false,
    warnings: [],
  };

  try {
    if (!fs.existsSync(cookieFilePath)) {
      debugLog('Cookie-Datei nicht gefunden:', cookieFilePath);
      diagnostics.warnings.push('Cookie-Datei nicht gefunden.');
      return diagnostics;
    }

    const cookieData = fs.readFileSync(cookieFilePath, 'utf8');
    const rawCookiePayload = JSON.parse(cookieData);
    const cookies = extractCookieArray(rawCookiePayload)
      .map((cookie) => normalizeCookieRecord(cookie))
      .filter((cookie) => cookie && isRelevantCookieDomain(cookie.domain));

    if (!Array.isArray(cookies) || cookies.length === 0) {
      debugLog('Cookie-Datei enthaelt keine verwertbaren Cookies.');
      diagnostics.warnings.push('Cookie-Datei enthaelt keine verwertbaren Instagram-Cookies.');
      return diagnostics;
    }

    diagnostics.providedCount = cookies.length;
    diagnostics.providedNames = cookies.map((cookie) => cookie.name);
    diagnostics.sessionCookieProvided = diagnostics.providedNames.includes('sessionid');

    if (!diagnostics.sessionCookieProvided) {
      diagnostics.warnings.push(
        'Die Cookie-Datei enthaelt keine sessionid; daraus kann kein angemeldeter Instagram-Zustand wiederhergestellt werden.',
      );
    }

    await page.browserContext().setCookie(...cookies);

    const acceptedCookies = await page.cookies('https://www.instagram.com/');
    diagnostics.acceptedNames = acceptedCookies.map((cookie) => cookie.name);
    diagnostics.acceptedCount = acceptedCookies.length;
    diagnostics.sessionCookieAccepted = diagnostics.acceptedNames.includes('sessionid');
    diagnostics.loaded = true;

    debugLog('Instagram-Cookies geladen', diagnostics.providedNames);
    recordRunDebug('cookies-loaded-from-file', {
      cookieFilePath,
      providedNames: diagnostics.providedNames,
      acceptedNames: diagnostics.acceptedNames,
      sessionCookieProvided: diagnostics.sessionCookieProvided,
      sessionCookieAccepted: diagnostics.sessionCookieAccepted,
    });

    if (diagnostics.sessionCookieProvided && !diagnostics.sessionCookieAccepted) {
      diagnostics.warnings.push('Die importierte sessionid wurde vom Browser-Context nicht akzeptiert.');
    }

    return diagnostics;
  } catch (error) {
    debugLog('Fehler beim Laden der Cookies:', error.message);
    diagnostics.warnings.push(`Fehler beim Laden der Cookies: ${normalizeText(error.message)}`);
    return diagnostics;
  }
}

async function primeInstagramSession(page, cookieFilePath, runtimeConfig = {}) {
  const diagnostics = {
    ...await loadCookiesFromFileWithDiagnostics(page, cookieFilePath),
    sessionCookieRetained: false,
    loginViewDetectedAfterReload: false,
    preflightUrl: 'https://www.instagram.com/',
    postReloadUrl: null,
    postReloadTitle: null,
    postReloadBodyPreview: '',
    cookieNamesAfterReload: [],
  };

  if (!diagnostics.loaded) {
    return diagnostics;
  }

  const navigation = await navigateWithSoftTimeout(
    page,
    'https://www.instagram.com/',
    runtimeConfig,
    isLoginSessionMode
      ? { timeoutMs: Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), 20000) }
      : {},
  );

  if (!navigation.ok) {
    diagnostics.warnings.push(`Instagram-Session-Vorabtest konnte nicht stabil geladen werden: ${navigation.error}`);
  }

  await sleep(1000);

  diagnostics.postReloadUrl = page.url();
  diagnostics.postReloadTitle = await page.title().catch(() => null);
  diagnostics.postReloadBodyPreview = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  ).slice(0, 600);

  const cookiesAfterReload = await page.cookies('https://www.instagram.com/');
  diagnostics.cookieNamesAfterReload = cookiesAfterReload.map((cookie) => cookie.name);
  diagnostics.sessionCookieRetained = diagnostics.cookieNamesAfterReload.includes('sessionid');
  diagnostics.loginViewDetectedAfterReload = /log into instagram|melde dich an|password|passwort|forgot password|log in with facebook/i.test(
    diagnostics.postReloadBodyPreview,
  );
  recordRunDebug('session-preflight', diagnostics);

  if (diagnostics.sessionCookieAccepted && !diagnostics.sessionCookieRetained) {
    diagnostics.warnings.push(
      'Instagram hat die importierte sessionid beim ersten Request wieder entfernt; die Session ist sehr wahrscheinlich serverseitig ungueltig oder an Browser, IP oder Device gebunden.',
    );
  }

  if (diagnostics.sessionCookieRetained && diagnostics.loginViewDetectedAfterReload) {
    diagnostics.warnings.push(
      'Die sessionid blieb zwar gesetzt, Instagram hat aber trotzdem keinen angemeldeten Zustand ausgeliefert.',
    );
  }

  return diagnostics;
}

async function saveCookiesToFile(page, cookieFilePath) {
  try {
    const cookies = await page.cookies();

    // Nur Instagram-relevante Cookies speichern
    const filteredCookies = cookies.filter((cookie) =>
      cookie.domain && (cookie.domain.includes('instagram.com') || cookie.domain.includes('facebook.com')),
    );

    const cookieNames = filteredCookies.map((cookie) => cookie.name);
    const sessionCookieSaved = cookieNames.includes('sessionid');

    if (filteredCookies.length > 0) {
      const tempPath = `${cookieFilePath}.tmp`;
      fs.writeFileSync(tempPath, JSON.stringify(filteredCookies, null, 2), 'utf8');
      fs.renameSync(tempPath, cookieFilePath);
      recordRunDebug('cookies-saved-to-file', {
        cookieFilePath,
        count: filteredCookies.length,
        cookieNames,
        sessionCookieSaved,
      });

      return {
        saved: true,
        count: filteredCookies.length,
        cookieNames,
        sessionCookieSaved,
      };
    }

    recordRunDebug('cookies-not-saved', {
      cookieFilePath,
      reason: 'no-relevant-cookies',
    });

    return {
      saved: false,
      count: 0,
      cookieNames: [],
      sessionCookieSaved: false,
    };
  } catch (error) {
    recordRunDebug('cookies-save-error', {
      cookieFilePath,
      error: normalizeText(error.message),
    });

    return {
      saved: false,
      count: 0,
      cookieNames: [],
      sessionCookieSaved: false,
      error: normalizeText(error.message),
    };
  }
}

function shouldSaveCookies(finalUrl, profile) {
  if ((finalUrl || '').includes('/accounts/login')) {
    return false;
  }

  if (profile?.requiresLogin) {
    return false;
  }

  return true;
}

function cleanupDirectory(directoryPath) {
  try {
    fs.rmSync(directoryPath, { recursive: true, force: true });
  } catch (error) {
    // Temporary browser profiles can stay locked briefly on Windows; ignore cleanup failures.
  }
}

function deriveScrapeOutcome({ title, finalUrl, profile, warnings, cookieDiagnostics, loginDiagnostics }) {
  const normalizedTitle = normalizeText(title || '').toLowerCase();
  const normalizedBodyPreview = normalizeText(profile?.bodyTextPreview || '').toLowerCase();
  const normalizedDescription = normalizeText(profile?.description || '').toLowerCase();
  const normalizedWarnings = warnings.map((warning) => normalizeText(warning).toLowerCase()).join(' ');
  const rejectedImportedSession = Boolean(
    cookieDiagnostics?.sessionCookieProvided &&
    cookieDiagnostics?.sessionCookieAccepted &&
    cookieDiagnostics?.sessionCookieRetained === false
  );

  const hasFailureScreen = /seite konnte nicht geladen werden|something went wrong|couldn'?t load|fehler konnte die seite nicht laden/.test(
    `${normalizedTitle} ${normalizedBodyPreview}`,
  );

  const hasAuthOrRateLimitBlock = /status of 401|status of 403|http 401|http 403|require_login|please wait a few minutes|bitte warte einige minuten|instagram-rate-limit/.test(
    normalizedWarnings,
  );

  const redirectedToLogin = (finalUrl || '').includes('/accounts/login');
  const autoLoginFailed = Boolean(loginDiagnostics?.attempted && !loginDiagnostics?.success);
  const hasUsefulMetadata = Boolean(profile?.ogTitle || profile?.description || profile?.ogImage);
  const hasRenderedProfileContent =
    Boolean(profile?.usernameSeen) &&
    (profile?.imageCount ?? 0) > 0 &&
    !hasFailureScreen &&
    !redirectedToLogin &&
    !profile?.requiresLogin;

  if (rejectedImportedSession && (redirectedToLogin || profile?.requiresLogin)) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram verwirft die importierte Login-Session; es wurden nur Metadaten extrahiert.'
        : 'Instagram verwirft die importierte Login-Session; es konnten keine brauchbaren Daten geladen werden.',
    };
  }

  if (autoLoginFailed && (redirectedToLogin || profile?.requiresLogin)) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram-Login wurde versucht, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert; es wurden nur Metadaten extrahiert.'
        : 'Instagram-Login wurde versucht, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert.',
    };
  }

  if (redirectedToLogin || profile?.requiresLogin) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram verlangt fuer dieses Profil einen Login; es wurden nur Metadaten extrahiert.'
        : 'Instagram verlangt fuer dieses Profil einen Login; es konnten keine brauchbaren Daten geladen werden.',
    };
  }

  if (hasFailureScreen || hasAuthOrRateLimitBlock) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram blockiert oder stoert die Profilansicht; es wurden nur Metadaten und ein Snapshot erzeugt.'
        : 'Instagram blockiert den Abruf; es konnten keine brauchbaren Profildaten geladen werden.',
    };
  }

  if (hasRenderedProfileContent) {
    return {
      ok: true,
      statusLevel: 'success',
      statusMessage: 'Instagram-Profil erfolgreich geladen.',
    };
  }

  if (hasUsefulMetadata || normalizedDescription.includes(username.toLowerCase())) {
    return {
      ok: false,
      statusLevel: 'partial',
      statusMessage: 'Instagram-Profil nur teilweise geladen; es sind vor allem Metadaten verfuegbar.',
    };
  }

  return {
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Instagram-Scrape fehlgeschlagen.',
  };
}

async function collectProfileInfo(page, username) {
  const bodyText = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  );
  const counts = await page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeCountValue = (rawValue = '') => {
      const value = normalizeElementText(rawValue).toLowerCase();

      if (!value) {
        return null;
      }

      let multiplier = 1;

      if (/\bmio\b|\bm\b/.test(value)) {
        multiplier = 1000000;
      } else if (/\bk\b|\btsd\b/.test(value)) {
        multiplier = 1000;
      }

      const numericPart = value.replace(/[^\d.,]/g, '');

      if (!numericPart) {
        return null;
      }

      if (multiplier === 1) {
        const digits = numericPart.replace(/[^\d]/g, '');

        return digits ? Number.parseInt(digits, 10) : null;
      }

      const decimalValue = Number.parseFloat(numericPart.replace(',', '.'));

      return Number.isFinite(decimalValue) ? Math.round(decimalValue * multiplier) : null;
    };
    const patterns = {
      posts: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:beitr(?:ag|aege|äge)|posts?)/iu,
      ],
      followers: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:follower|followers)/iu,
      ],
      following: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:gefolgt|following)/iu,
      ],
    };
    const values = {
      posts: null,
      followers: null,
      following: null,
    };
    const sources = {
      posts: null,
      followers: null,
      following: null,
    };
    const textSources = [
      ['body_text_preview', document.body?.innerText || ''],
      ...Array.from(document.querySelectorAll('header, main, section, ul, li, a[href*="/followers"], a[href*="/following"]'))
        .flatMap((element) => ([
          ['profile_dom', element.innerText || element.textContent || ''],
          ['profile_dom', element.getAttribute('aria-label') || ''],
          ['profile_dom', element.getAttribute('title') || ''],
        ])),
    ];

    for (const [source, text] of textSources) {
      const normalizedText = normalizeElementText(text);

      if (!normalizedText) {
        continue;
      }

      for (const [metric, metricPatterns] of Object.entries(patterns)) {
        if (values[metric] !== null) {
          continue;
        }

        for (const pattern of metricPatterns) {
          const match = normalizedText.match(pattern);

          if (!match) {
            continue;
          }

          const count = normalizeCountValue(match[1] || '');

          if (count !== null) {
            values[metric] = count;
            sources[metric] = source;
            break;
          }
        }
      }
    }

    return {
      ...values,
      sources,
    };
  }).catch(() => ({
    posts: null,
    followers: null,
    following: null,
    sources: {
      posts: null,
      followers: null,
      following: null,
    },
  }));

  const description = await page
    .$eval('meta[name="description"]', (element) => element.content)
    .catch(() => null);

  const ogImage = await page
    .$eval('meta[property="og:image"]', (element) => element.content)
    .catch(() => null);

  const ogTitle = await page
    .$eval('meta[property="og:title"]', (element) => element.content)
    .catch(() => null);

  const imageCount = await page
    .evaluate(
      () => Array.from(document.images).filter((image) => image.naturalWidth > 50).length,
    )
    .catch(() => 0);

  return {
    bodyTextPreview: bodyText.slice(0, 1200),
    counts,
    description,
    imageCount,
    isPrivate: /dieses profil ist privat|this account is private/i.test(bodyText + ' ' + (description || '')),
    ogImage,
    ogTitle,
    requiresLogin: /melde dich an|anmelden|log in|sign up/i.test(bodyText),
    usernameSeen:
      bodyText.toLowerCase().includes(username.toLowerCase()) ||
      (ogTitle || '').toLowerCase().includes(username.toLowerCase()),
  };
}

async function closeInstagramDialog(page) {
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(400);
}

async function collectFollowerEntriesFromDialog(page) {
  return page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const rateLimitPattern = /(?:versuche es sp(?:a|\u00e4)ter noch einmal|zu viele anfragen|wir schr(?:a|\u00e4)nken die h(?:a|\u00e4)ufigkeit|try again later|too many requests|429|error 429|we restrict certain activity|we limit how often|this action was blocked|rate limit)/i;
    const rateLimitDialog = Array.from(document.querySelectorAll('div[role="dialog"]'))
      .find((dialogElement) => rateLimitPattern.test(
        normalizeElementText(dialogElement.innerText || dialogElement.textContent || ''),
      )) || null;
    const rateLimitContainer = rateLimitDialog || (
      rateLimitPattern.test(normalizeElementText(document.body?.innerText || document.body?.textContent || ''))
        ? document.body
        : null
    );

    if (rateLimitContainer) {
      return {
        entries: [],
        suggestionsVisible: false,
        suggestionHeadingText: null,
        rateLimited: true,
        rateLimitText: normalizeElementText(rateLimitContainer.innerText || rateLimitContainer.textContent || '').slice(0, 500),
      };
    }

    const reservedPaths = new Set([
      'accounts',
      'direct',
      'explore',
      'p',
      'reel',
      'reels',
      'stories',
      'tv',
    ]);
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|suggested for you|suggestions for you|suggested)$/i;
    const getOwnText = (element) => normalizeElementText(
      Array.from(element.childNodes || [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent || '')
        .join(' '),
    );
    const isSuggestionHeading = (element) => {
      const ownText = getOwnText(element);
      const fullText = normalizeElementText(element.innerText || element.textContent || '');
      const text = ownText || fullText;

      return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
    };
    const suggestionHeading = Array.from(dialog.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .find(isSuggestionHeading) || null;
    const isAfterSuggestionHeading = (element) => {
      if (!suggestionHeading) {
        return false;
      }

      return Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING);
    };
    const findEntryContainer = (anchor, username) => {
      const fallback = anchor.closest('div[role="button"], li, article, div') || anchor;
      const normalizedUsername = String(username || '').toLowerCase();

      for (let element = anchor; element && element !== dialog; element = element.parentElement) {
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = element.getBoundingClientRect();
        const hasProfileImage = Boolean(element.querySelector('img'));

        if (
          hasProfileImage
          && (!normalizedUsername || text.toLowerCase().includes(normalizedUsername))
          && rect.width >= 120
          && rect.height >= 36
          && text.length <= 900
        ) {
          return element;
        }

        if (text.length > 900 || rect.height > 420) {
          break;
        }
      }

      return fallback;
    };
    const anchors = Array.from(dialog.querySelectorAll('a[href]'))
      .filter((anchor) => !isAfterSuggestionHeading(anchor));

    const entries = anchors
      .map((anchor) => {
        let pathname = '';

        try {
          pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
        } catch (error) {
          return null;
        }

        const parts = pathname.split('/').filter(Boolean);

        if (parts.length !== 1 || reservedPaths.has(parts[0])) {
          return null;
        }

        const username = parts[0].trim();

        if (!/^[a-z0-9._]+$/i.test(username)) {
          return null;
        }

        const textLines = (anchor.innerText || '')
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean);
        const row = findEntryContainer(anchor, username);
        const image = Array.from(row.querySelectorAll('img'))
          .find((candidate) => {
            const rect = candidate.getBoundingClientRect();
            const src = candidate.currentSrc || candidate.src || candidate.getAttribute('src') || '';

            return src !== ''
              && rect.width > 12
              && rect.height > 12
              && !/emoji|sprite|blank|transparent/i.test(src);
          }) || null;
        const profileImageUrl = image
          ? (image.currentSrc || image.src || image.getAttribute('src') || null)
          : null;

        return {
          username,
          displayName: textLines.find((line) => line.toLowerCase() !== username.toLowerCase()) || null,
          profileUrl: `https://www.instagram.com/${username}/`,
          profileImageUrl,
        };
      })
      .filter(Boolean);

    return {
      entries,
      suggestionsVisible: Boolean(suggestionHeading),
      suggestionHeadingText: suggestionHeading
        ? normalizeElementText(suggestionHeading.innerText || suggestionHeading.textContent || '')
        : null,
      rateLimited: false,
      rateLimitText: null,
    };
  }).catch(() => ({
    entries: [],
    suggestionsVisible: false,
    suggestionHeadingText: null,
    rateLimited: false,
    rateLimitText: null,
  }));
}

async function scrollFollowerDialog(page) {
  return page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]');

    if (!dialog) {
      const before = window.scrollY;
      window.scrollBy(0, Math.max(480, window.innerHeight * 0.85));
      const after = window.scrollY;
      const scrollHeight = document.documentElement.scrollHeight;

      return {
        scrolled: after > before,
        scrollTop: after,
        scrollHeight,
        clientHeight: window.innerHeight,
        atBottom: after + window.innerHeight >= scrollHeight - 8,
        profileLinkCount: Array.from(document.querySelectorAll('a[href]')).length,
      };
    }

    const candidates = Array.from(dialog.querySelectorAll('div, ul, section'))
      .filter((element) => element.scrollHeight > element.clientHeight + 40);
    const scrollTarget = candidates
      .sort((left, right) => (right.scrollHeight - right.clientHeight) - (left.scrollHeight - left.clientHeight))[0];

    if (!scrollTarget) {
      return {
        scrolled: false,
        scrollTop: 0,
        scrollHeight: 0,
        clientHeight: 0,
        atBottom: true,
        profileLinkCount: Array.from(dialog.querySelectorAll('a[href]')).length,
      };
    }

    const before = scrollTarget.scrollTop;
    const step = Math.max(420, scrollTarget.clientHeight * 0.85);
    scrollTarget.scrollTop = Math.min(
      scrollTarget.scrollTop + step,
      scrollTarget.scrollHeight,
    );
    const after = scrollTarget.scrollTop;

    return {
      scrolled: after > before,
      scrollTop: after,
      scrollHeight: scrollTarget.scrollHeight,
      clientHeight: scrollTarget.clientHeight,
      atBottom: after + scrollTarget.clientHeight >= scrollTarget.scrollHeight - 8,
      profileLinkCount: Array.from(dialog.querySelectorAll('a[href]')).length,
    };
  }).catch(() => ({
    scrolled: false,
    scrollTop: 0,
    scrollHeight: 0,
    clientHeight: 0,
    atBottom: false,
    profileLinkCount: 0,
  }));
}

async function relationshipDialogSearchInputAvailable(page) {
  return page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
    const isVisibleEditable = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      if (
        rect.width <= 2
        || rect.height <= 2
        || style.display === 'none'
        || style.visibility === 'hidden'
        || style.opacity === '0'
      ) {
        return false;
      }

      if ('disabled' in element && element.disabled) {
        return false;
      }

      if ('readOnly' in element && element.readOnly) {
        return false;
      }

      return true;
    };
    const editableElements = Array.from(dialog.querySelectorAll([
      'input',
      'textarea',
      '[contenteditable="true"]',
      '[role="textbox"]',
      '[aria-label]',
    ].join(','))).filter((element) => {
      const tagName = element.tagName.toLowerCase();
      const role = (element.getAttribute('role') || '').toLowerCase();
      const contentEditable = (element.getAttribute('contenteditable') || '').toLowerCase();

      if (!['input', 'textarea'].includes(tagName) && role !== 'textbox' && contentEditable !== 'true') {
        return false;
      }

      if (tagName === 'input') {
        const type = (element.getAttribute('type') || 'text').toLowerCase();

        if (!['text', 'search', 'email', ''].includes(type)) {
          return false;
        }
      }

      return isVisibleEditable(element);
    });
    const preferredInput = editableElements.find((input) => {
      const haystack = [
        input.getAttribute('placeholder') || '',
        input.getAttribute('aria-label') || '',
        input.getAttribute('aria-placeholder') || '',
        input.getAttribute('name') || '',
        input.getAttribute('type') || '',
        input.getAttribute('role') || '',
        normalizeElementText(input.textContent || ''),
      ].join(' ');

      return /suchen|suche|search|durchsuchen|filter/i.test(haystack);
    }) || editableElements.find((input) => {
      const type = (input.getAttribute('type') || 'text').toLowerCase();

      return type === 'text' || type === 'search' || input.getAttribute('role') === 'textbox';
    });

    return Boolean(preferredInput);
  }).catch(() => false);
}

async function setRelationshipDialogSearchQuery(page, query, waitMs = 900) {
  const applySearchQuery = async () => page.evaluate((value) => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const normalizeElementText = (text = '') => String(text || '').replace(/\s+/g, ' ').trim();
    const isVisibleEditable = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      if (
        rect.width <= 2
        || rect.height <= 2
        || style.display === 'none'
        || style.visibility === 'hidden'
        || style.opacity === '0'
      ) {
        return false;
      }

      if ('disabled' in element && element.disabled) {
        return false;
      }

      if ('readOnly' in element && element.readOnly) {
        return false;
      }

      return true;
    };
    const scoreSearchElement = (candidate) => {
      const tagName = candidate.tagName.toLowerCase();
      const type = (candidate.getAttribute('type') || 'text').toLowerCase();
      const role = (candidate.getAttribute('role') || '').toLowerCase();
      const contentEditable = (candidate.getAttribute('contenteditable') || '').toLowerCase();

      if (!['input', 'textarea'].includes(tagName) && role !== 'textbox' && contentEditable !== 'true') {
        return -1;
      }

      if (tagName === 'input' && !['text', 'search', 'email', ''].includes(type)) {
        return -1;
      }

      if (!isVisibleEditable(candidate)) {
        return -1;
      }

      const haystack = [
        candidate.getAttribute('placeholder') || '',
        candidate.getAttribute('aria-label') || '',
        candidate.getAttribute('aria-placeholder') || '',
        candidate.getAttribute('name') || '',
        candidate.getAttribute('type') || '',
        candidate.getAttribute('role') || '',
        normalizeElementText(candidate.textContent || ''),
      ].join(' ');
      let score = 0;

      if (/suchen|suche|search|durchsuchen|filter/i.test(haystack)) {
        score += 100;
      }

      if (type === 'search') {
        score += 35;
      }

      if (role === 'textbox') {
        score += 20;
      }

      const rect = candidate.getBoundingClientRect();
      const dialogRect = dialog.getBoundingClientRect();

      if (rect.top <= dialogRect.top + Math.max(160, dialogRect.height * 0.28)) {
        score += 15;
      }

      if (tagName === 'input' || tagName === 'textarea') {
        score += 10;
      }

      return score;
    };
    const input = Array.from(dialog.querySelectorAll([
      'input',
      'textarea',
      '[contenteditable="true"]',
      '[role="textbox"]',
      '[aria-label]',
    ].join(',')))
      .map((candidate) => ({ candidate, score: scoreSearchElement(candidate) }))
      .filter((entry) => entry.score >= 0)
      .sort((left, right) => right.score - left.score)[0]?.candidate || null;

    if (!input) {
      return {
        applied: false,
        reason: 'search-input-not-found',
      };
    }

    input.scrollIntoView({ block: 'center', inline: 'nearest' });
    input.click();
    input.focus();

    const isContentEditable = (input.getAttribute('contenteditable') || '').toLowerCase() === 'true'
      || (input.getAttribute('role') || '').toLowerCase() === 'textbox';
    const nativeValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
    const nativeTextareaValueSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value')?.set;

    if (isContentEditable && !('value' in input)) {
      input.textContent = '';
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'deleteContentBackward',
      }));
      input.textContent = value;
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
    } else {
      const setter = input.tagName.toLowerCase() === 'textarea'
        ? nativeTextareaValueSetter
        : nativeValueSetter;

      if (setter) {
        setter.call(input, '');
      } else {
        input.value = '';
      }

      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'deleteContentBackward',
      }));

      if (setter) {
        setter.call(input, value);
      } else {
        input.value = value;
      }

      input.dispatchEvent(new InputEvent('beforeinput', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: value.slice(-1) || 'Backspace' }));

    const scrollTargets = Array.from(dialog.querySelectorAll('div, ul, section'))
      .filter((element) => element.scrollHeight > element.clientHeight + 40);

    for (const target of scrollTargets) {
      target.scrollTop = 0;
    }

    const currentValue = 'value' in input ? input.value : normalizeElementText(input.textContent || '');

    return {
      applied: currentValue === value,
      reason: currentValue === value ? null : 'search-query-not-verified',
      currentValue,
    };
  }, String(query || '')).catch(() => false);

  for (let attempt = 0; attempt < 3; attempt++) {
    const result = await applySearchQuery();

    if (result && result.applied) {
      await sleep(query ? waitMs : Math.min(600, waitMs));

      return true;
    }

    await sleep(350);
  }

  return false;
}

async function openInstagramRelationshipDialog(page, username, relationship, runtimeConfig = {}) {
  const normalizedUsername = normalizeInstagramUsername(username);
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const clicked = await page.evaluate((targetUsername, targetRelationship) => {
    const anchors = Array.from(document.querySelectorAll('a[href]'));
    const targetPath = `/${targetUsername}/${targetRelationship}/`;
    const labelPatternText = targetRelationship === 'following'
      ? 'following|gefolgt|abonniert'
      : 'followers|follower';
    const labelPattern = new RegExp(labelPatternText, 'i');
    const target = anchors.find((anchor) => {
      let pathname = '';

      try {
        pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
      } catch (error) {
        return false;
      }

      return pathname.toLowerCase() === targetPath;
    }) || anchors.find((anchor) => labelPattern.test(anchor.innerText || ''));

    if (!target) {
      return false;
    }

    target.click();
    return true;
  }, normalizedUsername, normalizedRelationship).catch(() => false);

  if (clicked) {
    await sleep(1800);
  }

  const hasDialog = await page.evaluate(() => Boolean(document.querySelector('div[role="dialog"]'))).catch(() => false);

  if (hasDialog) {
    return true;
  }

  await navigateWithSoftTimeout(
    page,
    `https://www.instagram.com/${normalizedUsername}/${normalizedRelationship}/`,
    runtimeConfig,
  );
  await sleep(2200);

  return page.evaluate(() => Boolean(document.querySelector('div[role="dialog"]'))).catch(() => false);
}

async function openFollowersDialog(page, username, runtimeConfig = {}) {
  return openInstagramRelationshipDialog(page, username, 'followers', runtimeConfig);
}

async function openFollowingDialog(page, username, runtimeConfig = {}) {
  return openInstagramRelationshipDialog(page, username, 'following', runtimeConfig);
}

function addRelationshipEntriesToMap(entries, usersByUsername, targetUsername) {
  let added = 0;

  for (const entry of entries) {
    const relatedUsername = normalizeInstagramUsername(entry?.username);

    if (!relatedUsername || relatedUsername === targetUsername) {
      continue;
    }

    const existing = usersByUsername.get(relatedUsername);
    const nextEntry = {
      username: relatedUsername,
      displayName: entry.displayName || existing?.displayName || null,
      profileUrl: entry.profileUrl || existing?.profileUrl || `https://www.instagram.com/${relatedUsername}/`,
      profileImageUrl: entry.profileImageUrl || entry.profile_image_url || existing?.profileImageUrl || null,
    };

    usersByUsername.set(relatedUsername, nextEntry);

    if (!existing) {
      added++;
    }
  }

  return added;
}

function getRelationshipSearchPartitionQueries(runtimeConfig = {}) {
  const configuredQueries = Array.isArray(runtimeConfig.relationshipSearchPartitionQueries)
    ? runtimeConfig.relationshipSearchPartitionQueries
    : [];
  const normalizedQueries = configuredQueries
    .map((query) => normalizeText(String(query || '')).toLowerCase())
    .filter((query) => query !== '');

  return normalizedQueries.length > 0
    ? [...new Set(normalizedQueries)]
    : RELATIONSHIP_SEARCH_PARTITION_QUERIES;
}

function scoreRelationshipSearchQuery(query, usersByUsername) {
  let score = 0;

  for (const item of usersByUsername.values()) {
    const haystack = `${item.username || ''} ${item.displayName || ''}`.toLowerCase();

    if (haystack.includes(query)) {
      score++;
    }
  }

  return score;
}

function buildSecondLevelRelationshipSearchQueries(queryStats, usersByUsername, alreadyQueuedQueries) {
  const productiveBaseQueries = queryStats
    .filter((stat) => stat.depth === 1 && (stat.visibleCount > 0 || stat.addedCount > 0))
    .map((stat) => stat.query)
    .filter((query) => query.length === 1);
  const candidates = new Set();

  for (const baseQuery of productiveBaseQueries) {
    for (const character of RELATIONSHIP_SEARCH_PARTITION_CHARACTERS) {
      candidates.add(`${baseQuery}${character}`);
      candidates.add(`${character}${baseQuery}`);
    }
  }

  return Array.from(candidates)
    .filter((query) => !alreadyQueuedQueries.has(query))
    .map((query) => ({
      query,
      score: scoreRelationshipSearchQuery(query, usersByUsername),
    }))
    .sort((left, right) => right.score - left.score || left.query.localeCompare(right.query))
    .map((entry) => entry.query);
}

async function collectRelationshipSearchPartitions(page, username, relationship, options, usersByUsername) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const runtimeConfig = options.runtimeConfig || {};
  const maxItems = normalizeOptionalPositiveInteger(options.maxItems, DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS);
  const expectedCount = normalizeOptionalPositiveInteger(options.expectedCount, 0);
  const maxScrollRounds = Math.max(
    20,
    normalizeOptionalPositiveInteger(options.searchMaxScrollRounds, options.maxScrollRounds)
      || options.maxScrollRounds
      || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
  );
  const hasItemLimit = maxItems > 0;
  const targetUsername = normalizeInstagramUsername(username);
  const searchTargetUsername = normalizeInstagramUsername(
    options.targetUsername || runtimeConfig.relationshipSearchTargetUsername || '',
  );
  const queries = getRelationshipSearchPartitionQueries(runtimeConfig);
  const maxSearchDepth = Math.min(
    2,
    Math.max(1, normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchMaxDepth, 2) || 2),
  );
  const configuredSearchWaitMs = Number(runtimeConfig.relationshipSearchWaitMs || 900);
  const searchWaitMs = Number.isFinite(configuredSearchWaitMs)
    ? Math.max(500, configuredSearchWaitMs)
    : 900;
  const searchInputMaxAttempts = Math.floor(normalizeNumberAtLeast(
    runtimeConfig.relationshipSearchInputMaxAttempts,
    3,
    1,
  ));
  const queryQueue = queries.map((query) => ({ query, depth: 1 }));
  const queuedQueries = new Set(queries);
  const queryStats = [];
  const queriesRun = [];
  let searchRounds = 0;
  let openAttempts = 0;
  let addedCount = 0;
  let expandedQueryCount = 0;
  let stopReason = null;

  const targetReached = () => {
    if (searchTargetUsername && usersByUsername.has(searchTargetUsername)) {
      return true;
    }

    if (hasItemLimit && usersByUsername.size >= maxItems) {
      return true;
    }

    return expectedCount > 0 && usersByUsername.size >= expectedCount;
  };

  progressLog('relationship-search-opening', {
    relationship: normalizedRelationship,
    loaded: usersByUsername.size,
    expectedCount,
    maxItems,
    maxScrollRounds,
    queryCount: queries.length,
    targetUsername: searchTargetUsername || null,
  });

  const openDialog = async () => (normalizedRelationship === 'following'
    ? openFollowingDialog(page, username, runtimeConfig)
    : openFollowersDialog(page, username, runtimeConfig));
  const openDialogWithSearchInput = async () => {
    while (openAttempts < searchInputMaxAttempts) {
      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
      })) {
        return {
          opened: false,
          inputAvailable: false,
          stopReason: 'ui-stop-requested',
        };
      }

      openAttempts++;

      const dialogOpened = await openDialog();

      if (!dialogOpened) {
        return {
          opened: false,
          inputAvailable: false,
          stopReason: 'search-dialog-not-found',
        };
      }

      const searchInputAvailable = await relationshipDialogSearchInputAvailable(page);

      if (searchInputAvailable) {
        return {
          opened: true,
          inputAvailable: true,
          stopReason: null,
        };
      }

      progressLog('relationship-search-input-missing', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        openAttempts,
        maxAttempts: searchInputMaxAttempts,
        targetUsername: searchTargetUsername || null,
        message: `Suchfeld fuer ${normalizedRelationship} nicht gefunden (${openAttempts}/${searchInputMaxAttempts}).`,
      });

      await closeInstagramDialog(page);

      if (openAttempts < searchInputMaxAttempts) {
        await sleep(650);
      }
    }

    return {
      opened: true,
      inputAvailable: false,
      stopReason: 'search-input-not-found',
    };
  };

  let searchDialogState = await openDialogWithSearchInput();
  let opened = searchDialogState.opened;
  let inputAvailable = searchDialogState.inputAvailable;

  if (!opened) {
    const searchStopReason = searchDialogState.stopReason || 'search-dialog-not-found';

    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: searchStopReason,
      rateLimited: false,
      rateLimitText: null,
      gracefullyStopped: searchStopReason === 'ui-stop-requested',
    };
  }

  if (!inputAvailable) {
    const searchStopReason = searchDialogState.stopReason || 'search-input-not-found';

    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: searchStopReason,
      rateLimited: false,
      rateLimitText: null,
      gracefullyStopped: searchStopReason === 'ui-stop-requested',
    };
  }

  for (let queryIndex = 0; queryIndex < queryQueue.length; queryIndex++) {
    const { query, depth } = queryQueue[queryIndex];

    if (targetReached() || searchRounds >= maxScrollRounds) {
      break;
    }

    if (markGracefulStopIfRequested(normalizedRelationship, {
      loaded: usersByUsername.size,
      expectedCount,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
    })) {
      stopReason = 'ui-stop-requested';
      break;
    }

    let queryApplied = await setRelationshipDialogSearchQuery(page, query, searchWaitMs);

    if (!queryApplied && openAttempts < searchInputMaxAttempts) {
      await closeInstagramDialog(page);
      searchDialogState = await openDialogWithSearchInput();
      opened = searchDialogState.opened;
      inputAvailable = searchDialogState.inputAvailable;
      queryApplied = opened && inputAvailable
        ? await setRelationshipDialogSearchQuery(page, query, searchWaitMs)
        : false;

      if (!queryApplied && searchDialogState.stopReason === 'ui-stop-requested') {
        stopReason = 'ui-stop-requested';
        break;
      }
    }

    if (!queryApplied) {
      stopReason = inputAvailable ? 'search-query-not-applied' : 'search-input-lost';
      break;
    }

    queriesRun.push(query);
    const queryStartCount = usersByUsername.size;
    let previousCount = usersByUsername.size;
    let unchangedRounds = 0;
    let queryRounds = 0;
    let queryAddedCount = 0;
    const queryVisibleUsernames = new Set();
    let lastScrollState = null;
    let queryStopReason = null;

    progressLog('relationship-search-query-start', {
      relationship: normalizedRelationship,
      query,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      maxScrollRounds,
      searchRounds,
      depth,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
    });

    while (!targetReached() && searchRounds < maxScrollRounds) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const entries = Array.isArray(collection) ? collection : (collection.entries || []);
      const suggestionsVisible = !Array.isArray(collection) && Boolean(collection.suggestionsVisible);
      const rateLimited = !Array.isArray(collection) && Boolean(collection.rateLimited);

      if (rateLimited) {
        stopReason = 'instagram-rate-limit';
        await closeInstagramDialog(page);

        return {
          attempted: true,
          inputAvailable,
          queries: queriesRun,
          rounds: searchRounds,
          addedCount,
          openAttempts,
          stopReason,
          rateLimited: true,
          rateLimitText: collection.rateLimitText || null,
          maxDepth: maxSearchDepth,
          expandedQueryCount,
        };
      }

      for (const entry of entries) {
        const relatedUsername = normalizeInstagramUsername(entry?.username);

        if (relatedUsername) {
          queryVisibleUsernames.add(relatedUsername);
        }
      }

      const addedThisRound = addRelationshipEntriesToMap(entries, usersByUsername, targetUsername);
      addedCount += addedThisRound;
      queryAddedCount += addedThisRound;
      searchRounds++;
      queryRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      const livePreview = await captureLivePreviewScreenshot(page, runtimeConfig);

      progressLog('relationship-search-progress', {
        relationship: normalizedRelationship,
        query,
        round: searchRounds,
        queryRound: queryRounds,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        maxScrollRounds,
        unchangedRounds,
        addedThisRound,
        atBottom: Boolean(lastScrollState?.atBottom),
        suggestionsVisible,
        depth,
        queryIndex: queryIndex + 1,
        queryCount: queryQueue.length,
        ...livePreview,
      });

      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
        query,
        round: searchRounds,
        queryRound: queryRounds,
      })) {
        queryStopReason = 'ui-stop-requested';
        stopReason = queryStopReason;
        break;
      }

      if (targetReached()) {
        queryStopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : searchTargetUsername && usersByUsername.has(searchTargetUsername)
          ? 'target-found'
          : 'max-items-reached';
        break;
      }

      if (entries.length === 0) {
        if (searchTargetUsername && queryRounds < 3 && searchRounds < maxScrollRounds) {
          await sleep(searchWaitMs);
          continue;
        }

        queryStopReason = 'search-empty';
        break;
      }

      if (suggestionsVisible) {
        queryStopReason = 'search-suggestions-section-reached';
        break;
      }

      const scrollState = await scrollFollowerDialog(page);
      lastScrollState = scrollState;

      if (scrollState.atBottom && unchangedRounds >= 3) {
        queryStopReason = 'search-bottom-stale';
        break;
      }

      if (!scrollState.scrolled && unchangedRounds >= 2) {
        queryStopReason = 'search-scroll-stale';
        break;
      }

      if (unchangedRounds >= 8) {
        queryStopReason = 'search-no-new-items';
        break;
      }

      await sleep(scrollState.atBottom ? 900 : 650);
    }

    progressLog('relationship-search-query-complete', {
      relationship: normalizedRelationship,
      query,
      loaded: usersByUsername.size,
      addedThisQuery: usersByUsername.size - queryStartCount,
      expectedCount,
      maxItems,
      queryRounds,
      searchRounds,
      reason: queryStopReason,
      complete: targetReached(),
      depth,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
      visibleCount: queryVisibleUsernames.size,
    });

    queryStats.push({
      query,
      depth,
      rounds: queryRounds,
      addedCount: queryAddedCount,
      visibleCount: queryVisibleUsernames.size,
      stopReason: queryStopReason,
    });

    if (targetReached()) {
      stopReason = queryStopReason;
      break;
    }

    if (stopReason === 'ui-stop-requested') {
      break;
    }

    if (searchRounds >= maxScrollRounds) {
      stopReason = 'search-max-scroll-rounds-reached';
      break;
    }

    if (
      depth === 1
      && queryIndex === queries.length - 1
      && maxSearchDepth >= 2
      && !targetReached()
      && searchRounds < maxScrollRounds
    ) {
      const secondLevelQueries = buildSecondLevelRelationshipSearchQueries(
        queryStats,
        usersByUsername,
        queuedQueries,
      );

      for (const secondLevelQuery of secondLevelQueries) {
        queuedQueries.add(secondLevelQuery);
        queryQueue.push({
          query: secondLevelQuery,
          depth: 2,
        });
      }

      expandedQueryCount = secondLevelQueries.length;

      progressLog('relationship-search-expanded', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        searchRounds,
        generatedQueries: secondLevelQueries.length,
        totalQueries: queryQueue.length,
        maxDepth: maxSearchDepth,
      });
    }

    await sleep(450);
  }

  await setRelationshipDialogSearchQuery(page, '', searchWaitMs);
  await closeInstagramDialog(page);

  return {
    attempted: true,
    inputAvailable,
    queries: queriesRun,
    rounds: searchRounds,
    addedCount,
    openAttempts,
    stopReason: stopReason || (
      searchTargetUsername && usersByUsername.has(searchTargetUsername)
        ? 'target-found'
        : (targetReached() ? 'expected-count-reached' : 'search-partitions-exhausted')
    ),
    rateLimited: false,
    rateLimitText: null,
    maxDepth: maxSearchDepth,
    expandedQueryCount,
    gracefullyStopped: stopReason === 'ui-stop-requested',
  };
}

async function collectPublicRelationshipSearchOnlyList(page, username, profile, relationship, options = {}) {
  const runtimeConfig = options.runtimeConfig || {};
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const targetUsername = normalizeInstagramUsername(runtimeConfig.relationshipSearchTargetUsername || options.targetUsername || '');
  const maxItems = normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchTargetMaxItems, 0);
  const maxScrollRounds = Math.max(
    5,
    normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchTargetMaxScrollRounds, 60) || 60,
  );
  const result = {
    attempted: false,
    available: false,
    complete: false,
    count: 0,
    maxItems,
    expectedCount: targetUsername ? 1 : 0,
    items: [],
    targetUsername: targetUsername || null,
    targetFound: false,
    targetItem: null,
    reason: null,
    rateLimited: false,
    rateLimitText: null,
    openAttempts: 0,
    scrollRounds: 0,
    noProgressReopenLimit: 0,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchRounds: 0,
    searchAddedCount: 0,
    searchStopReason: null,
    searchMaxDepth: 0,
    searchExpandedQueryCount: 0,
  };

  if (profile?.isPrivate) {
    result.reason = 'private-profile';
    return result;
  }

  if (profile?.requiresLogin) {
    result.reason = 'login-required';
    return result;
  }

  if (!targetUsername) {
    result.reason = 'search-target-missing';
    return result;
  }

  result.attempted = true;
  const usersByUsername = new Map();
  const searchResult = await collectRelationshipSearchPartitions(
    page,
    username,
    normalizedRelationship,
    {
      ...options,
      maxItems,
      expectedCount: 0,
      maxScrollRounds,
      searchMaxScrollRounds: maxScrollRounds,
      targetUsername,
      runtimeConfig: {
        ...runtimeConfig,
        relationshipSearchPartitionQueries: [targetUsername],
        relationshipSearchMaxDepth: 1,
        relationshipSearchTargetUsername: targetUsername,
      },
    },
    usersByUsername,
  );
  const items = Array.from(usersByUsername.values());
  const targetItem = usersByUsername.get(targetUsername) || null;

  result.searchAttempted = Boolean(searchResult.attempted);
  result.searchInputAvailable = Boolean(searchResult.inputAvailable);
  result.searchQueries = searchResult.queries || [];
  result.searchRounds = searchResult.rounds || 0;
  result.searchAddedCount = searchResult.addedCount || 0;
  result.searchStopReason = searchResult.stopReason || null;
  result.searchMaxDepth = searchResult.maxDepth || 0;
  result.searchExpandedQueryCount = searchResult.expandedQueryCount || 0;
  result.openAttempts = searchResult.openAttempts || 0;
  result.scrollRounds = searchResult.rounds || 0;
  result.rateLimited = Boolean(searchResult.rateLimited);
  result.rateLimitText = searchResult.rateLimitText || null;
  result.gracefullyStopped = Boolean(searchResult.gracefullyStopped);
  result.items = items;
  result.count = items.length;
  result.available = items.length > 0;
  result.targetFound = Boolean(targetItem);
  result.targetItem = targetItem;
  result.complete = Boolean(targetItem) && !result.rateLimited;
  result.reason = result.rateLimited
    ? 'instagram-rate-limit'
    : result.gracefullyStopped
    ? 'ui-stop-requested'
    : result.targetFound
    ? null
    : (searchResult.stopReason || `${normalizedRelationship}-target-not-found`);

  progressLog('relationship-search-complete', {
    relationship: normalizedRelationship,
    loaded: result.count,
    expectedCount: result.expectedCount,
    maxItems,
    searchQueries: result.searchQueries.length,
    searchRounds: result.searchRounds,
    searchAddedCount: result.searchAddedCount,
    reason: result.reason,
    complete: result.complete,
    targetUsername,
    targetFound: result.targetFound,
  });

  return result;
}

async function collectPublicRelationshipList(page, username, profile, relationship, options = {}) {
  const maxItems = normalizeOptionalPositiveInteger(options.maxItems, DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS);
  const expectedCount = normalizeOptionalPositiveInteger(options.expectedCount, 0);
  const runtimeConfig = options.runtimeConfig || {};
  const maxScrollRounds = Math.max(
    20,
    normalizeOptionalPositiveInteger(
      options.maxScrollRounds,
      DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    ) || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
  );
  const hasItemLimit = maxItems > 0;
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const result = {
    attempted: false,
    available: false,
    complete: false,
    count: 0,
    maxItems,
    expectedCount,
    items: [],
    reason: null,
    rateLimited: false,
    rateLimitText: null,
    openAttempts: 0,
    scrollRounds: 0,
    noProgressReopenLimit: RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchRounds: 0,
    searchAddedCount: 0,
    searchStopReason: null,
    searchMaxDepth: 0,
    searchExpandedQueryCount: 0,
  };

  if (profile?.isPrivate) {
    result.reason = 'private-profile';
    return result;
  }

  if (profile?.requiresLogin) {
    result.reason = 'login-required';
    return result;
  }

  result.attempted = true;
  const usersByUsername = new Map();
  const targetUsername = normalizeInstagramUsername(username);
  let lastScrollState = null;
  let totalScrollRounds = 0;
  let openAttempts = 0;
  let noProgressOpenAttempts = 0;
  let stopReason = null;

  const targetReached = () => {
    if (hasItemLimit && usersByUsername.size >= maxItems) {
      return true;
    }

    return expectedCount > 0 && usersByUsername.size >= expectedCount;
  };

  while (totalScrollRounds < maxScrollRounds && !targetReached()) {
    if (markGracefulStopIfRequested(normalizedRelationship, {
      loaded: usersByUsername.size,
      expectedCount,
    })) {
      stopReason = 'ui-stop-requested';
      break;
    }

    openAttempts++;

    progressLog(openAttempts === 1 ? 'relationship-opening' : 'relationship-reopening', {
      relationship: normalizedRelationship,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      openAttempt: openAttempts,
      maxScrollRounds,
    });

    const opened = normalizedRelationship === 'following'
      ? await openFollowingDialog(page, username, runtimeConfig)
      : await openFollowersDialog(page, username, runtimeConfig);

    if (!opened) {
      stopReason = `${normalizedRelationship}-dialog-not-found`;

      progressLog('relationship-dialog-missing', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        openAttempt: openAttempts,
        reason: stopReason,
      });

      if (usersByUsername.size === 0) {
        result.reason = stopReason;
        return result;
      }

      break;
    }

    const passStartCount = usersByUsername.size;
    let unchangedRounds = 0;
    let previousCount = usersByUsername.size;
    let passRounds = 0;

    while (totalScrollRounds < maxScrollRounds && !targetReached()) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const entries = Array.isArray(collection) ? collection : (collection.entries || []);
      const suggestionsVisible = !Array.isArray(collection) && Boolean(collection.suggestionsVisible);
      const rateLimited = !Array.isArray(collection) && Boolean(collection.rateLimited);

      if (rateLimited) {
        stopReason = 'instagram-rate-limit';
        result.rateLimited = true;
        result.rateLimitText = collection.rateLimitText || null;
        recordRunDebug('relationship-rate-limit-detected', {
          relationship: normalizedRelationship,
          loaded: usersByUsername.size,
          expectedCount,
          openAttempt: openAttempts,
          round: totalScrollRounds,
          text: collection.rateLimitText || null,
        });
        progressLog('relationship-rate-limited', {
          relationship: normalizedRelationship,
          loaded: usersByUsername.size,
          expectedCount,
          openAttempt: openAttempts,
          reason: stopReason,
        });
        break;
      }

      addRelationshipEntriesToMap(entries, usersByUsername, targetUsername);

      totalScrollRounds++;
      passRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      const livePreview = await captureLivePreviewScreenshot(page, runtimeConfig);

      progressLog('relationship-progress', {
        relationship: normalizedRelationship,
        round: totalScrollRounds,
        passRound: passRounds,
        openAttempt: openAttempts,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        maxScrollRounds,
        unchangedRounds,
        atBottom: Boolean(lastScrollState?.atBottom),
        suggestionsVisible,
        ...livePreview,
      });

      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
        round: totalScrollRounds,
        passRound: passRounds,
        openAttempt: openAttempts,
      })) {
        stopReason = 'ui-stop-requested';
        break;
      }

      if (targetReached()) {
        stopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : 'max-items-reached';
        break;
      }

      if (suggestionsVisible) {
        stopReason = 'suggestions-section-reached';
        break;
      }

      const scrollState = await scrollFollowerDialog(page);
      lastScrollState = scrollState;

      if (scrollState.atBottom && unchangedRounds >= 5) {
        stopReason = 'pass-bottom-stale';
        break;
      }

      if (!scrollState.scrolled && unchangedRounds >= 3) {
        stopReason = 'pass-scroll-stale';
        break;
      }

      if (unchangedRounds >= 12) {
        stopReason = 'pass-no-new-items';
        break;
      }

      await sleep(scrollState.atBottom ? 1400 : 850);
    }

    const passAdded = usersByUsername.size - passStartCount;

    progressLog('relationship-pass-complete', {
      relationship: normalizedRelationship,
      round: totalScrollRounds,
      passRound: passRounds,
      openAttempt: openAttempts,
      loaded: usersByUsername.size,
      addedThisPass: passAdded,
      expectedCount,
      maxItems,
      maxScrollRounds,
      complete: targetReached(),
      reason: stopReason,
    });

    await closeInstagramDialog(page);

    if (stopReason === 'instagram-rate-limit' || stopReason === 'ui-stop-requested') {
      break;
    }

    if (targetReached()) {
      break;
    }

    if (totalScrollRounds >= maxScrollRounds) {
      stopReason = 'max-scroll-rounds-reached';
      break;
    }

    if (passAdded <= 0) {
      noProgressOpenAttempts++;
    } else {
      noProgressOpenAttempts = 0;
    }

    if (noProgressOpenAttempts >= RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT) {
      stopReason = 'no-new-items-after-reopen';
      break;
    }

    await sleep(900);
  }

  const missingKnownRelationshipSearchQueries = getMissingPrioritizedRelationshipSearchQueries(
    runtimeConfig,
    normalizedRelationship,
    usersByUsername,
  );
  const shouldAttemptRelationshipSearch = Boolean(
    missingKnownRelationshipSearchQueries.length > 0
    || (Array.isArray(runtimeConfig.relationshipSearchPartitionQueries)
      && runtimeConfig.relationshipSearchPartitionQueries.length > 0)
    || normalizeInstagramUsername(options.targetUsername || runtimeConfig.relationshipSearchTargetUsername || ''),
  );

  if (
    stopReason !== 'instagram-rate-limit'
    && stopReason !== 'ui-stop-requested'
    && !targetReached()
    && expectedCount > 0
    && usersByUsername.size < expectedCount
    && shouldAttemptRelationshipSearch
  ) {
    const searchResult = await collectRelationshipSearchPartitions(
      page,
      username,
      normalizedRelationship,
      {
        ...options,
        maxScrollRounds,
      },
      usersByUsername,
    );

    result.searchAttempted = Boolean(searchResult.attempted);
    result.searchInputAvailable = Boolean(searchResult.inputAvailable);
    result.searchQueries = searchResult.queries || [];
    result.searchRounds = searchResult.rounds || 0;
    result.searchAddedCount = searchResult.addedCount || 0;
    result.searchStopReason = searchResult.stopReason || null;
    result.searchMaxDepth = searchResult.maxDepth || 0;
    result.searchExpandedQueryCount = searchResult.expandedQueryCount || 0;
    openAttempts += searchResult.openAttempts || 0;
    totalScrollRounds += searchResult.rounds || 0;

    progressLog('relationship-search-complete', {
      relationship: normalizedRelationship,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      searchQueries: result.searchQueries.length,
      searchRounds: result.searchRounds,
      searchAddedCount: result.searchAddedCount,
      searchMaxDepth: result.searchMaxDepth,
      searchExpandedQueryCount: result.searchExpandedQueryCount,
      reason: result.searchStopReason,
      complete: targetReached(),
    });

    if (searchResult.gracefullyStopped) {
      stopReason = 'ui-stop-requested';
    } else if (searchResult.rateLimited) {
      stopReason = 'instagram-rate-limit';
      result.rateLimited = true;
      result.rateLimitText = searchResult.rateLimitText || null;
    } else {
      stopReason = targetReached()
        ? (expectedCount > 0 && usersByUsername.size >= expectedCount ? 'expected-count-reached-after-search' : 'max-items-reached-after-search')
        : (searchResult.stopReason || 'search-partitions-exhausted');
    }
  }

  result.items = hasItemLimit
    ? Array.from(usersByUsername.values()).slice(0, maxItems)
    : Array.from(usersByUsername.values());
  result.count = result.items.length;
  result.available = result.count > 0;
  result.openAttempts = openAttempts;
  result.scrollRounds = totalScrollRounds;
  result.complete = stopReason !== 'instagram-rate-limit' && (expectedCount > 0
    ? result.count >= expectedCount
    : ((!hasItemLimit || result.count < maxItems) && [
      'suggestions-section-reached',
      'no-new-items-after-reopen',
      'pass-bottom-stale',
      'pass-scroll-stale',
      'pass-no-new-items',
      'search-partitions-exhausted',
    ].includes(stopReason)));
  result.reason = stopReason === 'instagram-rate-limit'
    ? stopReason
    : stopReason === 'ui-stop-requested'
    ? stopReason
    : result.available
    ? (result.complete ? null : (stopReason || `incomplete-${normalizedRelationship}-list`))
    : `no-${normalizedRelationship}-found`;
  result.gracefullyStopped = stopReason === 'ui-stop-requested';

  progressLog('relationship-complete', {
    relationship: normalizedRelationship,
    loaded: result.count,
    expectedCount,
    maxItems,
    openAttempts,
    scrollRounds: totalScrollRounds,
    complete: result.complete,
    reason: result.reason,
    rateLimited: result.rateLimited,
  });

  return result;
}

async function collectPublicFollowersList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'followers', options);
}

async function collectPublicFollowingList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'following', options);
}

async function runProfileSuggestionConnectionScan(page, runtimeState, notes, targetUsername, profileUrl) {
  let runtimeConfig = runtimeState.runtimeConfig;
  const maxTargetSuggestions = Math.max(1, Number(runtimeConfig.suggestionScanMaxItems || 12));
  const maxCandidateSuggestions = Math.max(1, Number(runtimeConfig.suggestionCandidateMaxItems || 8));
  const checkedCandidates = [];
  const matchedCandidates = [];
  const skippedCandidates = [];
  const dismissedCandidates = [];
  const dismissedUsernames = new Set();
  const candidateHistory = normalizeSuggestionCandidateHistory(runtimeConfig.suggestionCandidateHistory || {});
  let gracefullyStopped = false;
  let rateLimited = false;
  let rateLimitText = null;
  let dismissedCount = 0;

  progressLog('suggestions-opening', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: maxTargetSuggestions,
    message: `Profilvorschlaege fuer @${targetUsername} werden gesucht.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  await scrollToProfileSuggestions(page, 6);
  const targetSeeAllClicked = await clickProfileSuggestionsSeeAll(page);

  if (targetSeeAllClicked) {
    await sleep(1600);
  }

  const targetSuggestions = await collectProfileSuggestionItems(
    page,
    targetUsername,
    maxTargetSuggestions,
  );

  if (targetSeeAllClicked) {
    await closeInstagramDialog(page);
    await scrollToProfileSuggestions(page, 2);
  }

  const candidates = targetSuggestions.items.slice(0, maxTargetSuggestions);
  const candidatesToCheck = [];

  rateLimited = Boolean(targetSuggestions.rateLimited);
  rateLimitText = targetSuggestions.rateLimitText || null;

  for (const candidate of candidates) {
    const candidateUsername = normalizeInstagramUsername(candidate.username);
    const history = candidateHistory[candidateUsername] || {
      hasMatch: false,
      noMatchChecks: 0,
      permanentlyDismissed: false,
    };
    const shouldDismissAsKnownMatch = Boolean(history.hasMatch);
    const shouldDismissAsFinalMiss = Boolean(history.permanentlyDismissed) || Number(history.noMatchChecks || 0) >= 2;
    const shouldDismissBeforeRetry = !shouldDismissAsKnownMatch
      && !shouldDismissAsFinalMiss
      && Number(history.noMatchChecks || 0) >= 1;

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss || shouldDismissBeforeRetry) {
      const dismissed = await dismissVisibleSuggestion(page, candidate.username);

      if (dismissed) {
        dismissedCount += 1;
        dismissedUsernames.add(candidateUsername);
        dismissedCandidates.push({
          ...candidate,
          dismissed: true,
          dismissedBeforeCheck: true,
          dismissReason: shouldDismissAsKnownMatch
            ? 'already-saved-match'
            : (shouldDismissAsFinalMiss ? 'already-dismissed-no-match' : 'retry-no-match-candidate'),
          previousNoMatchChecks: Number(history.noMatchChecks || 0),
          previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
        });
        await sleep(350);
      }
    }

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss) {
      skippedCandidates.push({
        ...candidate,
        checked: false,
        skipped: true,
        skippedReason: shouldDismissAsKnownMatch
          ? 'already-saved-match'
          : 'already-dismissed-no-match',
        dismissedFromSuggestions: dismissedUsernames.has(candidateUsername),
        previousNoMatchChecks: Number(history.noMatchChecks || 0),
        previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
      });

      continue;
    }

    candidatesToCheck.push({
      ...candidate,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      dismissedBeforeCheck: shouldDismissBeforeRetry && dismissedUsernames.has(candidateUsername),
    });
  }

  progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-target-list', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: candidatesToCheck.length,
    suggestionsObserved: candidates.length,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    message: rateLimited
      ? 'Instagram hat die Profilvorschlaege per Rate-Limit blockiert.'
      : `${candidates.length} Profilvorschlaege gefunden. ${candidatesToCheck.length} Kandidaten werden geprueft.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  if (runtimeConfig.suggestionDismissChecked && candidates.length > 0) {
    for (const candidate of candidates) {
      if (dismissedUsernames.has(normalizeInstagramUsername(candidate.username))) {
        continue;
      }

      const dismissed = await dismissVisibleSuggestion(page, candidate.username);

      if (dismissed) {
        dismissedCount += 1;
        dismissedUsernames.add(normalizeInstagramUsername(candidate.username));
        dismissedCandidates.push({
          ...candidate,
          dismissed: true,
          dismissedBeforeCheck: true,
          dismissReason: 'configured-dismiss-checked',
        });
        await sleep(350);
      }
    }
  }

  if (!rateLimited) {
    for (let index = 0; index < candidatesToCheck.length; index += 1) {
      const candidate = candidatesToCheck[index];
      const stopRequest = markGracefulStopIfRequested('suggestions', {
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
      });

      if (stopRequest) {
        gracefullyStopped = true;
        break;
      }

      progressLog('suggestions-candidate-opening', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        candidateUsername: candidate.username,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
        message: Number(candidate.previousNoMatchChecks || 0) > 0
          ? `@${candidate.username} wird nach frueherem Nichttreffer erneut geprueft.`
          : `@${candidate.username} wird auf Verbindung zu @${targetUsername} geprueft.`,
      });

      const candidateNavigation = await navigateWithSoftTimeout(page, candidate.profileUrl, runtimeConfig);

      if (!candidateNavigation.ok) {
        checkedCandidates.push({
          ...candidate,
          checked: false,
          error: candidateNavigation.error,
        });

        progressLog('suggestions-candidate-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
          message: `@${candidate.username} konnte nicht stabil geladen werden.`,
        });

        continue;
      }

      await sleep(1300);
      const candidateProfile = await collectProfileInfo(page, candidate.username);
      const candidateIsPublic = !candidateProfile.isPrivate && !candidateProfile.requiresLogin;
      let targetFound = false;
      let checkedCandidate = null;
      let progressMessage = null;
      let progressPayload = {};

      if (candidateIsPublic) {
        progressLog('suggestions-public-list-search', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
          message: `@${candidate.username} ist oeffentlich; Follower und Gefolgt werden nach @${targetUsername} durchsucht.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
        });

        const publicListSearch = await collectSuggestionCandidatePublicListConnection(
          page,
          candidate,
          candidateProfile,
          targetUsername,
          runtimeConfig,
        );

        if (publicListSearch.rateLimited) {
          rateLimited = true;
          rateLimitText = publicListSearch.rateLimitText || rateLimitText;
        }

        if (publicListSearch.gracefullyStopped) {
          gracefullyStopped = true;
        }

        targetFound = Boolean(publicListSearch.targetFound);
        const definitiveNoMatch = !targetFound
          && Boolean(publicListSearch.conclusive)
          && !Boolean(publicListSearch.rateLimited)
          && !Boolean(publicListSearch.gracefullyStopped);

        checkedCandidate = {
          ...candidate,
          checked: true,
          checkMode: 'public-lists',
          targetFoundAsSuggestion: false,
          targetFoundInPublicLists: targetFound,
          targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
          targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
          finalDismissedAfterSecondMiss: definitiveNoMatch && Number(candidate.previousNoMatchChecks || 0) >= 1,
          dismissedFromSuggestions: Boolean(candidate.dismissedBeforeCheck),
          suggestionsObserved: 0,
          suggestionsAvailable: false,
          suggestionsRateLimited: false,
          suggestionPreview: [],
          publicListSearchAttempted: Boolean(publicListSearch.attempted),
          publicListSearchConclusive: Boolean(publicListSearch.conclusive),
          publicListRateLimited: Boolean(publicListSearch.rateLimited),
          publicListSearch,
        };

        const foundLists = [
          publicListSearch.targetFoundInFollowers ? 'Followern' : null,
          publicListSearch.targetFoundInFollowing ? 'Gefolgt' : null,
        ].filter(Boolean).join(' und ');

        progressMessage = targetFound
          ? `Listen-Verbindung gefunden: @${targetUsername} steht bei @${candidate.username} in ${foundLists}.`
          : (checkedCandidate.finalDismissedAfterSecondMiss
            ? `@${candidate.username} erneut ohne Listentreffer geprueft und endgueltig aus den sichtbaren Vorschlaegen entfernt.`
            : `@${candidate.username} oeffentlich geprueft; @${targetUsername} wurde in den normalen Listen nicht gefunden.`);
        progressPayload = {
          targetFoundInPublicLists: targetFound,
          targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
          targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
          publicListSearchConclusive: Boolean(publicListSearch.conclusive),
        };

        if (targetFound) {
          matchedCandidates.push({
            username: candidate.username,
            displayName: candidate.displayName || null,
            profileUrl: candidate.profileUrl,
            profileImageUrl: candidate.profileImageUrl || null,
            sourceSuggestionUsername: candidate.username,
            sourcePublicUsername: candidate.username,
            targetFoundAsSuggestion: false,
            targetFoundInPublicLists: true,
            targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
            targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
            sourceLists: [
              ...(publicListSearch.targetFoundInFollowers ? ['public_profile_followers'] : []),
              ...(publicListSearch.targetFoundInFollowing ? ['public_profile_following'] : []),
            ],
            publicListSearch,
            suggestionPreview: [],
          });
        }
      } else {
        await scrollToProfileSuggestions(page, 5);
        const candidateSeeAllClicked = await clickProfileSuggestionsSeeAll(page);

        if (candidateSeeAllClicked) {
          await sleep(1300);
        }

        const candidateSuggestions = await collectProfileSuggestionItems(
          page,
          candidate.username,
          maxCandidateSuggestions,
        );

        if (candidateSuggestions.rateLimited) {
          rateLimited = true;
          rateLimitText = candidateSuggestions.rateLimitText || rateLimitText;
        }

        targetFound = candidateSuggestions.items.some((item) => (
          normalizeInstagramUsername(item.username) === targetUsername
        ));
        const definitiveNoMatch = !targetFound
          && Boolean(candidateSuggestions.available)
          && !Boolean(candidateSuggestions.rateLimited);

        checkedCandidate = {
          ...candidate,
          checked: true,
          checkMode: 'profile-suggestions',
          targetFoundAsSuggestion: targetFound,
          finalDismissedAfterSecondMiss: definitiveNoMatch && Number(candidate.previousNoMatchChecks || 0) >= 1,
          dismissedFromSuggestions: Boolean(candidate.dismissedBeforeCheck),
          suggestionsObserved: candidateSuggestions.items.length,
          suggestionsAvailable: Boolean(candidateSuggestions.available),
          suggestionsRateLimited: Boolean(candidateSuggestions.rateLimited),
          suggestionPreview: candidateSuggestions.items.slice(0, maxCandidateSuggestions),
        };

        progressMessage = targetFound
          ? `Vorschlag-Verbindung gefunden: @${candidate.username} zeigt @${targetUsername}.`
          : (checkedCandidate.finalDismissedAfterSecondMiss
            ? `@${candidate.username} erneut ohne Treffer geprueft und endgueltig aus den sichtbaren Vorschlaegen entfernt.`
            : `@${candidate.username} geprueft; @${targetUsername} war nicht in den ersten Vorschlaegen.`);
        progressPayload = {
          targetFoundAsSuggestion: targetFound,
          suggestionsObserved: candidateSuggestions.items.length,
        };

        if (targetFound) {
          matchedCandidates.push({
            username: candidate.username,
            displayName: candidate.displayName || null,
            profileUrl: candidate.profileUrl,
            profileImageUrl: candidate.profileImageUrl || null,
            sourceSuggestionUsername: candidate.username,
            sourcePublicUsername: candidate.username,
            targetFoundAsSuggestion: true,
            sourceLists: ['profile_suggestions'],
            suggestionPreview: candidateSuggestions.items.slice(0, maxCandidateSuggestions),
          });
        }
      }

      checkedCandidates.push(checkedCandidate);

      progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-candidate-checked', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        candidateUsername: candidate.username,
        ...progressPayload,
        dismissedSuggestions: dismissedCount,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
        message: progressMessage,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
      });

      if (rateLimited || gracefullyStopped) {
        break;
      }
    }
  }

  const candidatesNeedingPostDismissal = [
    ...matchedCandidates.map((candidate) => ({
      ...candidate,
      dismissReason: 'newly-saved-match',
    })),
    ...checkedCandidates
      .filter((candidate) => candidate.finalDismissedAfterSecondMiss)
      .map((candidate) => ({
        ...candidate,
        dismissReason: 'second-no-match',
      })),
  ].filter((candidate) => (
    !dismissedUsernames.has(normalizeInstagramUsername(candidate.username))
  ));

  if (!rateLimited && !gracefullyStopped && candidatesNeedingPostDismissal.length > 0) {
    await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
    await sleep(900);
    await scrollToProfileSuggestions(page, 4);

    for (const candidate of candidatesNeedingPostDismissal) {
      const dismissed = await dismissVisibleSuggestion(page, candidate.username);

      if (!dismissed) {
        continue;
      }

      dismissedCount += 1;
      dismissedUsernames.add(normalizeInstagramUsername(candidate.username));
      dismissedCandidates.push({
        ...candidate,
        dismissed: true,
        dismissedBeforeCheck: false,
        dismissReason: candidate.dismissReason,
      });
      await sleep(350);
    }
  }

  const statusLevel = gracefullyStopped || rateLimited ? 'partial' : 'success';
  const statusMessage = gracefullyStopped
    ? 'Profilvorschlag-Verbindungsscan wurde beendet; bisherige Treffer wurden gespeichert.'
    : (rateLimited
      ? 'Profilvorschlag-Verbindungsscan wurde wegen Instagram-Rate-Limit pausiert.'
      : 'Profilvorschlag-Verbindungsscan abgeschlossen.');

  progressLog('suggestions-complete', {
    relationship: 'suggestions',
    loaded: checkedCandidates.length,
    expectedCount: candidatesToCheck.length,
    foundSuggestions: matchedCandidates.length,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    suggestionConnectionsPreview: matchedCandidates.slice(-40),
    gracefullyStopped,
    rateLimited,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  notes.push(
    `Profilvorschlag-Verbindungsscan: ${checkedCandidates.length} von ${candidatesToCheck.length} Kandidaten geprueft, ${matchedCandidates.length} Treffer.`,
  );

  if (dismissedCount > 0) {
    notes.push(`${dismissedCount} Vorschlaege wurden aus der sichtbaren Vorschlagsliste entfernt.`);
  }

  if (rateLimited) {
    notes.push('Instagram hat die Profilvorschlaege per Rate-Limit blockiert; der Scan wurde pausiert.');
  }

  return {
    ok: !rateLimited,
    statusLevel,
    statusMessage,
    targetUsername,
    attempted: true,
    available: Boolean(targetSuggestions.available),
    seeAllClicked: targetSeeAllClicked,
    observedCount: candidates.length,
    checkedCount: checkedCandidates.length,
    matchCount: matchedCandidates.length,
    dismissedCount,
    skippedCount: skippedCandidates.length,
    rateLimited,
    rateLimitText,
    gracefullyStopped,
    suggestions: candidates,
    candidatesToCheck,
    skippedCandidates,
    dismissedCandidates,
    checkedCandidates,
    matches: matchedCandidates,
  };
}

function summarizeSuggestionPublicListResult(result = {}) {
  const targetItem = result.targetItem && typeof result.targetItem === 'object'
    ? {
      username: normalizeInstagramUsername(result.targetItem.username || '') || null,
      displayName: normalizeText(String(result.targetItem.displayName || result.targetItem.fullName || '')) || null,
      profileUrl: normalizeText(String(result.targetItem.profileUrl || result.targetItem.url || '')) || null,
      profileImageUrl: normalizeText(String(result.targetItem.profileImageUrl || result.targetItem.profile_image_url || '')) || null,
    }
    : null;

  return {
    attempted: Boolean(result.attempted),
    available: Boolean(result.available || result.searchAttempted),
    targetFound: Boolean(result.targetFound),
    targetItem,
    reason: result.reason || null,
    rateLimited: Boolean(result.rateLimited),
    rateLimitText: result.rateLimitText || null,
    searchAttempted: Boolean(result.searchAttempted),
    searchInputAvailable: Boolean(result.searchInputAvailable),
    searchQueries: Array.isArray(result.searchQueries) ? result.searchQueries.slice(0, 12) : [],
    searchRounds: Number(result.searchRounds || 0),
    searchStopReason: result.searchStopReason || null,
    openAttempts: Number(result.openAttempts || 0),
    gracefullyStopped: Boolean(result.gracefullyStopped),
  };
}

function isSuggestionPublicListSearchConclusive(result = {}) {
  return Boolean(result.attempted)
    && Boolean(result.searchAttempted)
    && Boolean(result.searchInputAvailable)
    && !Boolean(result.rateLimited)
    && !Boolean(result.gracefullyStopped);
}

async function collectSuggestionCandidatePublicListConnection(
  page,
  candidate,
  candidateProfile,
  targetUsername,
  runtimeConfig,
) {
  const searchRuntimeConfig = {
    ...runtimeConfig,
    relationshipSearchOnly: true,
    relationshipSearchTargetUsername: targetUsername,
    relationshipSearchTargetMaxItems: 1,
    relationshipSearchTargetMaxScrollRounds: Math.max(
      5,
      normalizeOptionalPositiveInteger(
        runtimeConfig.suggestionPublicListSearchMaxScrollRounds,
        runtimeConfig.relationshipSearchTargetMaxScrollRounds || 60,
      ) || 60,
    ),
  };
  const searchOptionsFor = (relationship) => ({
    ...buildRelationshipCollectionOptions(searchRuntimeConfig, relationship),
    runtimeConfig: searchRuntimeConfig,
    targetUsername,
  });
  const followers = await collectPublicRelationshipSearchOnlyList(
    page,
    candidate.username,
    candidateProfile,
    'followers',
    searchOptionsFor('followers'),
  );
  let following = {
    attempted: false,
    targetFound: false,
    reason: followers.rateLimited ? 'skipped-after-rate-limit' : null,
    rateLimited: false,
    gracefullyStopped: false,
  };

  if (!followers.rateLimited && !followers.gracefullyStopped) {
    following = await collectPublicRelationshipSearchOnlyList(
      page,
      candidate.username,
      candidateProfile,
      'following',
      searchOptionsFor('following'),
    );
  }

  const summarizedFollowers = summarizeSuggestionPublicListResult(followers);
  const summarizedFollowing = summarizeSuggestionPublicListResult(following);
  const rateLimited = Boolean(followers.rateLimited || following.rateLimited);
  const gracefullyStopped = Boolean(followers.gracefullyStopped || following.gracefullyStopped);
  const conclusive = isSuggestionPublicListSearchConclusive(followers)
    && isSuggestionPublicListSearchConclusive(following);

  return {
    attempted: true,
    conclusive,
    targetFound: Boolean(followers.targetFound || following.targetFound),
    targetFoundInFollowers: Boolean(followers.targetFound),
    targetFoundInFollowing: Boolean(following.targetFound),
    rateLimited,
    rateLimitText: followers.rateLimitText || following.rateLimitText || null,
    gracefullyStopped,
    followers: summarizedFollowers,
    following: summarizedFollowing,
  };
}

async function clickButtonByText(page, candidateTexts) {
  const normalizedCandidates = candidateTexts.map((text) => normalizeText(text).toLowerCase());

  return page.evaluate((texts) => {
    const buttons = Array.from(document.querySelectorAll('button, a, div[role="button"]'));
    const target = buttons.find((element) => texts.includes((element.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase()));

    if (!target) {
      return false;
    }

    target.click();
    return true;
  }, normalizedCandidates).catch(() => false);
}

async function scrollToProfileSuggestions(page, maxRounds = 5) {
  let lastScrollY = -1;

  for (let round = 0; round < maxRounds; round += 1) {
    const state = await page.evaluate(() => {
      const before = window.scrollY;
      window.scrollBy(0, Math.max(700, window.innerHeight * 0.9));
      const after = window.scrollY;
      const text = String(document.body?.innerText || document.body?.textContent || '');
      const suggestionsVisible = /(?:vorschl(?:a|\u00e4)ge|f(?:u|\u00fc)r dich vorgeschlagen|suggested|suggestions)/i.test(text);

      return {
        before,
        after,
        atBottom: after + window.innerHeight >= document.documentElement.scrollHeight - 12,
        suggestionsVisible,
      };
    }).catch(() => ({
      before: 0,
      after: 0,
      atBottom: true,
      suggestionsVisible: false,
    }));

    await sleep(650);

    if (state.suggestionsVisible || state.atBottom || state.after === lastScrollY) {
      return state;
    }

    lastScrollY = state.after;
  }

  return {
    suggestionsVisible: false,
    atBottom: false,
  };
}

async function clickProfileSuggestionsSeeAll(page) {
  return page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const visible = (element) => {
      const rect = element.getBoundingClientRect();

      return rect.width > 0 && rect.height > 0;
    };
    const seeAllPattern = /^(alle ansehen|alle anzeigen|see all|show all)$/i;
    const elements = Array.from(document.querySelectorAll('button, a, div[role="button"], span'))
      .filter(visible);
    const target = elements.find((element) => {
      const text = normalizeElementText(element.innerText || element.textContent || '');

      return text !== '' && text.length <= 60 && seeAllPattern.test(text);
    });

    if (!target) {
      return false;
    }

    const clickable = target.closest('button, a, div[role="button"]') || target;
    clickable.click();

    return true;
  }).catch(() => false);
}

async function collectProfileSuggestionItems(page, currentUsername, maxItems = 12) {
  const normalizedCurrentUsername = normalizeInstagramUsername(currentUsername);
  const limit = Math.max(1, Number(maxItems || 12));

  return page.evaluate(({ currentUsername, limit }) => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
    const rateLimitPattern = /(?:versuche es sp(?:a|\u00e4)ter noch einmal|zu viele anfragen|wir schr(?:a|\u00e4)nken die h(?:a|\u00e4)ufigkeit|try again later|too many requests|429|error 429|we restrict certain activity|we limit how often|this action was blocked|rate limit)/i;
    const bodyText = normalizeElementText(document.body?.innerText || document.body?.textContent || '');

    if (rateLimitPattern.test(bodyText)) {
      return {
        items: [],
        available: false,
        rateLimited: true,
        rateLimitText: bodyText.slice(0, 500),
        headingText: null,
      };
    }

    const reservedPaths = new Set([
      'accounts',
      'direct',
      'explore',
      'p',
      'reel',
      'reels',
      'stories',
      'tv',
    ]);
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|vorschl(?:a|\u00e4)ge|suggested for you|suggestions for you|suggested|suggestions)$/i;
    const dialog = document.querySelector('div[role="dialog"]');
    const container = dialog || document.body;
    const getOwnText = (element) => normalizeElementText(
      Array.from(element.childNodes || [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent || '')
        .join(' '),
    );
    const isSuggestionHeading = (element) => {
      const ownText = getOwnText(element);
      const fullText = normalizeElementText(element.innerText || element.textContent || '');
      const text = ownText || fullText;

      return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
    };
    const suggestionHeading = Array.from(container.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .find(isSuggestionHeading) || null;
    const isAfterSuggestionHeading = (element) => {
      if (!suggestionHeading) {
        return false;
      }

      return Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING);
    };
    const anchorScope = dialog || suggestionHeading ? container : document.body;
    const anchors = Array.from(anchorScope.querySelectorAll('a[href]'))
      .filter((anchor) => dialog || isAfterSuggestionHeading(anchor));
    const itemsByUsername = new Map();

    for (const anchor of anchors) {
      let pathname = '';

      try {
        pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
      } catch (error) {
        continue;
      }

      const parts = pathname.split('/').filter(Boolean);

      if (parts.length !== 1 || reservedPaths.has(parts[0])) {
        continue;
      }

      const username = normalizeUsername(parts[0]);

      if (!username || username === currentUsername || !/^[a-z0-9._]+$/i.test(username)) {
        continue;
      }

      const row = anchor.closest('div[role="button"], li, article, div') || anchor;
      const rawLines = `${anchor.innerText || ''}\n${row.innerText || ''}`
        .split('\n')
        .map((line) => normalizeElementText(line))
        .filter(Boolean);
      const lines = Array.from(new Set(rawLines));
      const ignoredLinePattern = /^(folgen|abonniert|entfernen|remove|follow|following|x)$/i;
      const displayName = lines.find((line) => (
        line.toLowerCase() !== username
        && !ignoredLinePattern.test(line)
        && line.length <= 120
      )) || null;

      if (!itemsByUsername.has(username)) {
        itemsByUsername.set(username, {
          username,
          displayName,
          profileUrl: `https://www.instagram.com/${username}/`,
        });
      }

      if (itemsByUsername.size >= limit) {
        break;
      }
    }

    return {
      items: Array.from(itemsByUsername.values()),
      available: Boolean(dialog || suggestionHeading || itemsByUsername.size > 0),
      rateLimited: false,
      rateLimitText: null,
      headingText: suggestionHeading
        ? normalizeElementText(suggestionHeading.innerText || suggestionHeading.textContent || '')
        : null,
    };
  }, { currentUsername: normalizedCurrentUsername, limit }).catch(() => ({
    items: [],
    available: false,
    rateLimited: false,
    rateLimitText: null,
    headingText: null,
  }));
}

async function dismissVisibleSuggestion(page, username) {
  const normalizedUsername = normalizeInstagramUsername(username);

  if (!normalizedUsername) {
    return false;
  }

  for (let attempt = 0; attempt < 8; attempt += 1) {
    const result = await page.evaluate((username) => {
      const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
      const normalizeUsername = (value = '') => String(value || '')
        .replace(/^@/, '')
        .replace(/[^a-z0-9._]/gi, '')
        .toLowerCase();
      const isInsideDialog = (element) => Boolean(element?.closest?.('div[role="dialog"]'));
      const isVisible = (element) => {
        if (!element || isInsideDialog(element)) {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none';
      };
      const profileUsernameFromAnchor = (anchor) => {
        try {
          const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
            .split('/')
            .filter(Boolean);

          return parts.length === 1 ? normalizeUsername(parts[0]) : '';
        } catch (error) {
          return '';
        }
      };
      const findAnchor = () => Array.from(document.querySelectorAll('a[href]'))
        .filter((anchorElement) => !isInsideDialog(anchorElement))
        .find((anchorElement) => profileUsernameFromAnchor(anchorElement) === username) || null;
      const removePattern = /^(x|entfernen|remove|close|schliessen|schlie(?:ss|\u00df)en|dismiss)$/i;
      const removeTextPattern = /(?:entfernen|remove|close|schliessen|schlie(?:ss|\u00df)en|dismiss)/i;
      const nonDismissPattern = /(?:folgen|abonniert|follow|following|nachricht|message|profil|profile|mehr|more)/i;
      const clickableFor = (element) => element.closest('button, [role="button"]') || element;
      const elementLabel = (element) => normalizeElementText([
        element.getAttribute?.('aria-label') || '',
        element.getAttribute?.('title') || '',
        element.innerText || '',
        element.textContent || '',
      ].join(' '));
      const findCardContainer = (anchor) => {
        let best = anchor;

        for (let element = anchor; element && element !== document.body; element = element.parentElement) {
          if (isInsideDialog(element)) {
            return null;
          }

          const text = normalizeElementText(element.innerText || element.textContent || '');
          const rect = element.getBoundingClientRect();

          if (
            text.toLowerCase().includes(username)
            && rect.width >= 80
            && rect.height >= 40
            && text.length <= 1200
          ) {
            best = element;
          }

          if (text.length > 1200 || rect.height > 650 || rect.width > Math.max(900, window.innerWidth * 0.95)) {
            break;
          }
        }

        return best;
      };
      const findDismissControl = (card, anchor) => {
        const controls = Array.from(card.querySelectorAll('button, [role="button"], [aria-label], svg[aria-label], svg[role="img"]'))
          .filter((element) => !isInsideDialog(element));

        for (const control of controls) {
          const label = elementLabel(control);

          if (
            label
            && !nonDismissPattern.test(label)
            && (removePattern.test(label) || removeTextPattern.test(label))
          ) {
            const clickable = clickableFor(control);

            if (isVisible(clickable)) {
              return clickable;
            }
          }
        }

        const buttonControls = Array.from(card.querySelectorAll('button, [role="button"]'))
          .filter(isVisible);
        const cardRect = card.getBoundingClientRect();
        const anchorRect = anchor.getBoundingClientRect();

        for (const control of buttonControls) {
          const label = elementLabel(control);

          if (label && nonDismissPattern.test(label)) {
            continue;
          }

          const rect = control.getBoundingClientRect();
          const smallControl = rect.width <= 56 && rect.height <= 56;
          const nearTop = rect.top <= Math.max(anchorRect.top + 70, cardRect.top + 90);
          const nearRight = rect.left >= cardRect.left + (cardRect.width * 0.45);

          if (smallControl && nearTop && nearRight) {
            return control;
          }
        }

        return null;
      };
      const clickCarouselNext = () => {
        const nextPattern = /(?:weiter|n(?:a|\u00e4)chste|next)/i;
        const controls = Array.from(document.querySelectorAll('button, [role="button"], [aria-label]'))
          .filter(isVisible)
          .filter((element) => {
            const label = elementLabel(element);

            if (!nextPattern.test(label)) {
              return false;
            }

            const rect = element.getBoundingClientRect();

            return rect.top > 0
              && rect.top < document.documentElement.scrollHeight
              && rect.width <= 90
              && rect.height <= 90;
          });

        const control = controls[0] || null;

        if (!control) {
          return false;
        }

        control.click();
        return true;
      };
      const anchor = findAnchor();

      if (!anchor) {
        return {
          status: clickCarouselNext() ? 'advanced' : 'not-found',
        };
      }

      anchor.scrollIntoView({
        block: 'center',
        inline: 'center',
      });

      const card = findCardContainer(anchor);

      if (!card) {
        return { status: 'card-missing' };
      }

      const control = findDismissControl(card, anchor);

      if (!control) {
        return { status: 'control-missing' };
      }

      control.click();

      return { status: 'dismissed' };
    }, normalizedUsername).catch(() => ({ status: 'error' }));

    if (result?.status === 'dismissed') {
      return true;
    }

    await sleep(result?.status === 'advanced' ? 550 : 300);
  }

  return false;
}

async function dismissCookieConsentIfPresent(page) {
  for (const candidateTexts of [
    ['Alle Cookies erlauben', 'Allow all cookies', 'Allow All Cookies'],
    ['Nur erforderliche Cookies erlauben', 'Allow essential cookies', 'Decline optional cookies'],
  ]) {
    const clicked = await clickButtonByText(page, candidateTexts);

    if (clicked) {
      await sleep(900);
    }
  }
}

async function dismissPostLoginPrompts(page) {
  for (const candidateTexts of [
    ['Jetzt nicht', 'Not now', 'Not Now'],
    ['Informationen speichern', 'Save info', 'Save Info'],
    ['Benachrichtigungen jetzt nicht', 'Turn On Notifications'],
  ]) {
    const clicked = await clickButtonByText(page, candidateTexts);

    if (clicked) {
      await sleep(900);
    }
  }
}

async function collectPageDiagnostics(page, options = {}) {
  const includeCookies = options.includeCookies === true;
  const bodyText = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  );
  const selectors = await page.evaluate(() => ({
    usernameField: Boolean(document.querySelector('input[name="username"]')),
    passwordField: Boolean(document.querySelector('input[name="password"]')),
    submitButton: Boolean(document.querySelector('button[type="submit"]')),
    totalInputs: document.querySelectorAll('input').length,
    textLikeInputs: document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input:not([type])').length,
    passwordInputs: document.querySelectorAll('input[type="password"]').length,
  })).catch(() => ({
    usernameField: false,
    passwordField: false,
    submitButton: false,
    totalInputs: 0,
    textLikeInputs: 0,
    passwordInputs: 0,
  }));
  const diagnostics = {
    url: page.url(),
    title: await page.title().catch(() => null),
    bodyPreview: bodyText.slice(0, 1200),
    selectors,
  };

  if (includeCookies) {
    const cookies = await page.cookies('https://www.instagram.com/').catch(() => []);
    diagnostics.cookies = summarizeCookieCollection(cookies);
  }

  return diagnostics;
}

async function findFirstExistingSelector(page, selectors) {
  for (const selector of selectors) {
    const handle = await page.$(selector).catch(() => null);

    if (handle) {
      await handle.dispose().catch(() => {});
      return selector;
    }
  }

  return null;
}

async function fillLoginInput(page, selector, value, runtimeConfig, fieldName) {
  const timeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 10000 : 20000);

  await page.waitForSelector(selector, {
    timeout,
    visible: true,
  });
  await page.focus(selector);
  await page.click(selector, {
    clickCount: 3,
  }).catch(() => {});
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => {});
  await page.keyboard.press('Backspace').catch(() => {});
  await page.type(selector, value, {
    delay: runtimeConfig.typingDelayMs,
  });

  let currentValue = await page.$eval(selector, (element) => element.value || '').catch(() => '');

  if (currentValue !== value) {
    await page.$eval(selector, (element, nextValue) => {
      const valueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

      if (valueSetter) {
        valueSetter.call(element, nextValue);
      } else {
        element.value = nextValue;
      }

      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
      element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
    }, value).catch(() => {});

    currentValue = await page.$eval(selector, (element) => element.value || '').catch(() => '');
  }

  recordRunDebug('auto-login-input-filled', {
    fieldName,
    selector,
    expectedLength: String(value || '').length,
    actualLength: String(currentValue || '').length,
    matched: currentValue === value,
  });

  return currentValue === value;
}

async function waitForLoginSubmitAttempt(page, runtimeConfig, action, label) {
  const timeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 8000 : 12000);

  await Promise.allSettled([
    page.waitForNavigation({
      timeout,
      waitUntil: 'domcontentloaded',
    }),
    action(),
  ]);
  await sleep(1200);

  recordRunDebug('auto-login-submit-attempt', {
    label,
    url: page.url(),
    cookies: summarizeCookieCollection(
      await page.cookies('https://www.instagram.com/').catch(() => []),
    ),
  });
}

async function submitInstagramLoginForm(page, submitSelector, passwordSelector, runtimeConfig) {
  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.click(submitSelector),
    'submit-button-click',
  );

  let activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);

  if (activeCookies.some((cookie) => cookie.name === 'sessionid')) {
    return;
  }

  await page.focus(passwordSelector).catch(() => {});
  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.keyboard.press('Enter'),
    'password-enter',
  );

  activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);

  if (activeCookies.some((cookie) => cookie.name === 'sessionid')) {
    return;
  }

  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button[type="submit"], form button'));
      const clickableButton = buttons.find((button) =>
        !button.disabled &&
        button.getAttribute('aria-disabled') !== 'true' &&
        button.offsetParent !== null
      ) || buttons.find((button) => !button.disabled) || buttons[0] || null;

      if (clickableButton) {
        clickableButton.click();
      }
    }),
    'dom-submit-button-click',
  );
}

async function performInstagramLogin(page, runtimeConfig) {
  const diagnostics = {
    attempted: false,
    success: false,
    finalUrl: null,
    title: null,
    bodyPreview: '',
    formDetected: false,
    invalidCredentials: false,
    challengeDetected: false,
    sessionCookiePresent: false,
    warnings: [],
  };

  if (!runtimeConfig?.autoLoginEnabled) {
    return diagnostics;
  }

  const loginUsername = normalizeText(runtimeConfig.loginUsername || '');
  const loginPassword = String(runtimeConfig.loginPassword || '');

  if (!loginUsername || !loginPassword) {
    diagnostics.warnings.push(
      !loginUsername
        ? 'Auto-Login ist aktiviert, aber der Instagram-Benutzername fehlt.'
        : (runtimeConfig?.loginPasswordConfigured && runtimeConfig?.loginPasswordDecryptable === false)
          ? 'Auto-Login ist aktiviert, aber das gespeicherte Passwort konnte von der Base-Installation nicht entschluesselt werden.'
          : 'Auto-Login ist aktiviert, aber Benutzername oder Passwort fehlen.',
    );
    recordRunDebug('auto-login-missing-credentials', {
      hasUsername: Boolean(loginUsername),
      hasPassword: Boolean(loginPassword),
      loginPasswordConfigured: Boolean(runtimeConfig?.loginPasswordConfigured),
      loginPasswordDecryptable: runtimeConfig?.loginPasswordDecryptable !== false,
      loginPasswordSource: runtimeConfig?.loginPasswordSource || null,
    });
    return diagnostics;
  }

  diagnostics.attempted = true;
  recordRunDebug('auto-login-start', {
    loginUsername,
    hasPassword: Boolean(loginPassword),
  });

  const usernameSelectors = [
    'input[name="username"]',
    'input[autocomplete="username"]',
    'input[aria-label*="Benutzername"]',
    'input[aria-label*="username" i]',
    'input[placeholder*="Benutzername"]',
    'input[placeholder*="username" i]',
    'input[type="email"]',
    'input[type="text"]',
    'input[type="tel"]',
  ];
  const passwordSelectors = [
    'input[name="password"]',
    'input[autocomplete="current-password"]',
    'input[aria-label*="Passwort"]',
    'input[aria-label*="password" i]',
    'input[type="password"]',
  ];
  const submitSelectors = [
    'button[type="submit"]',
    'form button',
  ];

  let loginNavigation = null;
  const loginUrl = 'https://www.instagram.com/accounts/login/';
  const loginTimeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 15000 : 20000);

  for (let attempt = 1; attempt <= 3; attempt++) {
    loginNavigation = await navigateWithSoftTimeout(
      page,
      loginUrl,
      runtimeConfig,
      { timeoutMs: loginTimeout },
    );

    if (loginNavigation.ok) {
      break;
    }

    diagnostics.warnings.push(`Instagram-Login-Seite konnte nicht stabil geladen werden: ${loginNavigation.error}`);
    recordRunDebug('auto-login-navigation-retry', {
      attempt,
      status: loginNavigation.status,
      error: loginNavigation.error,
      url: loginUrl,
    });

    if (loginNavigation.status !== 429 || attempt === 3) {
      break;
    }

    await sleep(1500 + attempt * 500);
  }

  if (!loginNavigation.ok) {
    const pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.warnings.push('Instagram-Login-Seite konnte nach mehreren Versuchen nicht geladen werden. Auto-Login wird abgebrochen.');
    recordRunDebug('auto-login-navigation-failed', pageDiagnostics);
    return diagnostics;
  }

  await dismissCookieConsentIfPresent(page);

  let usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
  let passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
  let submitSelector = await findFirstExistingSelector(page, submitSelectors);

  const loginFormTimeout = Math.min(runtimeConfig.navigationTimeoutMs, isLoginSessionMode ? 10000 : 20000);

  try {
    if (!usernameSelector || !passwordSelector || !submitSelector) {
      await page.waitForFunction(() => {
        const textLikeInput = document.querySelector('input[name="username"], input[autocomplete="username"], input[type="email"], input[type="text"], input[type="tel"], input[aria-label*="Benutzername"], input[aria-label*="username" i]');
        const passwordInput = document.querySelector('input[name="password"], input[autocomplete="current-password"], input[type="password"], input[aria-label*="Passwort"], input[aria-label*="password" i]');
        const submitButton = document.querySelector('button[type="submit"], form button');

        return Boolean(textLikeInput && passwordInput && submitButton);
      }, {
        timeout: loginFormTimeout,
      });
    }

    usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
    passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
    submitSelector = await findFirstExistingSelector(page, submitSelectors);
    await page.waitForSelector(usernameSelector, {
      timeout: loginFormTimeout,
    });
  } catch (error) {
    const switchedAccount = await clickButtonByText(page, [
      'Zu einem anderen Konto wechseln',
      'Switch accounts',
      'Switch Accounts',
      'Anderes Konto verwenden',
    ]);

    if (switchedAccount) {
      await sleep(1500);
      await dismissCookieConsentIfPresent(page);
      usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
      passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
      submitSelector = await findFirstExistingSelector(page, submitSelectors);
    }
  }

  diagnostics.formDetected = Boolean(usernameSelector && passwordSelector && submitSelector);

  if (!diagnostics.formDetected) {
    const pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.warnings.push('Das Instagram-Login-Formular wurde nicht gefunden.');
    recordRunDebug('auto-login-form-missing', pageDiagnostics);

    return diagnostics;
  }

  recordRunDebug('auto-login-form-ready', await collectPageDiagnostics(page, { includeCookies: true }));

  await sleep(1200);

  const usernameFilled = await fillLoginInput(page, usernameSelector, loginUsername, runtimeConfig, 'username');
  const passwordFilled = await fillLoginInput(page, passwordSelector, loginPassword, runtimeConfig, 'password');

  if (!usernameFilled || !passwordFilled) {
    diagnostics.warnings.push('Die Instagram-Login-Felder konnten nicht stabil befuellt werden.');
  }

  await submitInstagramLoginForm(page, submitSelector, passwordSelector, runtimeConfig);

  await sleep(runtimeConfig.postLoginWaitMs);
  await dismissPostLoginPrompts(page);

  let pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
  diagnostics.finalUrl = pageDiagnostics.url;
  diagnostics.title = pageDiagnostics.title;
  diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
  diagnostics.sessionCookiePresent = (pageDiagnostics.cookies || []).some((cookie) => cookie.name === 'sessionid');
  recordRunDebug('auto-login-after-submit', pageDiagnostics);

  const normalizedBody = diagnostics.bodyPreview.toLowerCase();
  diagnostics.invalidCredentials = /password was incorrect|incorrect password|dein passwort war nicht korrekt|falsches passwort/.test(normalizedBody);
  diagnostics.challengeDetected = /security code|bestaetige, dass du es bist|confirm it'?s you|two-factor|authenticator|suspicious login|challenge/.test(normalizedBody);
  diagnostics.success =
    !diagnostics.invalidCredentials &&
    !diagnostics.challengeDetected &&
    diagnostics.sessionCookiePresent &&
    !diagnostics.finalUrl.includes('/accounts/login') &&
    !/log into instagram|melde dich an|password|passwort/.test(normalizedBody);

  if (diagnostics.success) {
    await navigateWithSoftTimeout(page, 'https://www.instagram.com/', runtimeConfig);
    await sleep(1200);

    pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.sessionCookiePresent = (pageDiagnostics.cookies || []).some((cookie) => cookie.name === 'sessionid');
    diagnostics.success =
      diagnostics.sessionCookiePresent &&
      !diagnostics.finalUrl.includes('/accounts/login') &&
      !/log into instagram|melde dich an|password|passwort/.test(
        diagnostics.bodyPreview.toLowerCase(),
      );
    recordRunDebug('auto-login-post-verify', pageDiagnostics);
  }

  if (diagnostics.invalidCredentials) {
    diagnostics.warnings.push('Instagram hat die Zugangsdaten als ungueltig abgelehnt.');
  }

  if (diagnostics.challengeDetected) {
    diagnostics.warnings.push('Instagram verlangt nach dem Login eine zusaetzliche Verifizierung oder Challenge.');
  }

  if (!diagnostics.success && !diagnostics.invalidCredentials && !diagnostics.challengeDetected) {
    diagnostics.warnings.push('Der Auto-Login wurde ausgefuehrt, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert.');
  }

  recordRunDebug('auto-login-result', diagnostics);

  return diagnostics;
}

async function waitForInteractiveLoginCompletion(page, runtimeConfig) {
  const diagnostics = {
    attempted: true,
    success: false,
    finalUrl: null,
    title: null,
    bodyPreview: '',
    warnings: [],
    waitedForManualLogin: true,
  };

  const timeoutAt = Date.now() + Math.max(runtimeConfig.navigationTimeoutMs, 180000);
  recordRunDebug('manual-login-wait-start', {
    timeoutAt: new Date(timeoutAt).toISOString(),
  });

  while (Date.now() < timeoutAt) {
    await dismissPostLoginPrompts(page);

    const currentBody = normalizeText(
      await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
    ).slice(0, 1000);
    const currentUrl = page.url();
    const activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);
    const hasSessionCookie = activeCookies.some((cookie) => cookie.name === 'sessionid');
    const stillLoginView = /log into instagram|melde dich an|password|passwort|forgot password/.test(currentBody.toLowerCase());
    const challengeDetected = /security code|bestaetige, dass du es bist|confirm it'?s you|two-factor|authenticator|suspicious login|challenge/.test(currentBody.toLowerCase());

    if (hasSessionCookie && !stillLoginView && !currentUrl.includes('/accounts/login')) {
      diagnostics.success = true;
      diagnostics.finalUrl = currentUrl;
      diagnostics.title = await page.title().catch(() => null);
      diagnostics.bodyPreview = currentBody;
      recordRunDebug('manual-login-success', diagnostics);

      return diagnostics;
    }

    diagnostics.finalUrl = currentUrl;
    diagnostics.title = await page.title().catch(() => null);
    diagnostics.bodyPreview = currentBody;

    if (challengeDetected) {
      diagnostics.warnings.push('Instagram verlangt weiterhin eine Verifizierung oder Challenge.');
      recordRunDebug('manual-login-challenge-visible', {
        currentUrl,
        bodyPreview: currentBody,
      });
    }

    await sleep(1500);
  }

  diagnostics.warnings.push('Die manuelle Instagram-Anmeldung wurde nicht innerhalb des Zeitlimits abgeschlossen.');
  recordRunDebug('manual-login-timeout', diagnostics);

  return diagnostics;
}

async function establishInstagramSession(page, runtimeConfig, notes) {
  const cookieDiagnostics = await primeInstagramSession(page, buildCookiePath(runtimeConfig), runtimeConfig);
  let loginDiagnostics = {
    attempted: false,
    success: false,
    warnings: [],
  };

  if (cookieDiagnostics.loaded) {
    notes.push('Instagram-Cookies aus Speicher geladen.');
  }

  if (cookieDiagnostics.sessionCookieProvided && cookieDiagnostics.sessionCookieAccepted) {
    notes.push('Die importierte sessionid wurde lokal in den Browser-Context uebernommen.');
  }

  if (cookieDiagnostics.sessionCookieProvided && !cookieDiagnostics.sessionCookieAccepted) {
    notes.push('Die importierte sessionid wurde bereits vor dem Profilaufruf nicht akzeptiert.');
  }

  if (cookieDiagnostics.sessionCookieAccepted && !cookieDiagnostics.sessionCookieRetained) {
    notes.push('Instagram hat die importierte sessionid beim Vorabtest wieder verworfen.');
  }

  if (cookieDiagnostics.sessionCookieRetained && !cookieDiagnostics.loginViewDetectedAfterReload) {
    notes.push('Die importierte Instagram-Session wurde im Vorabtest zunaechst akzeptiert.');
  }

  const alreadyAuthenticated =
    cookieDiagnostics.sessionCookieRetained &&
    !cookieDiagnostics.loginViewDetectedAfterReload &&
    !cookieDiagnostics.postReloadUrl?.includes('/accounts/login');

  if (alreadyAuthenticated) {
    recordRunDebug('session-established-from-existing-state', cookieDiagnostics);
    return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
  }

  if (runtimeConfig.autoLoginEnabled) {
    loginDiagnostics = await performInstagramLogin(page, runtimeConfig);

    if (loginDiagnostics.attempted) {
      notes.push(
        isLoginSessionMode
          ? 'Auto-Login fuer Instagram wurde gestartet.'
          : 'Auto-Login fuer Instagram wurde im Hintergrund gestartet.',
      );
    }

    if (loginDiagnostics.success) {
      notes.push('Instagram-Login wurde erfolgreich abgeschlossen.');
      recordRunDebug('session-established-via-auto-login', loginDiagnostics);
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }

    notes.push('Auto-Login konnte keinen stabilen angemeldeten Zustand herstellen.');
  }

  if (isLoginSessionMode && resolvePuppeteerHeadlessMode(runtimeConfig) === false) {
    notes.push('Warte auf eine manuelle Anmeldung im sichtbaren Browserfenster.');
    loginDiagnostics = await waitForInteractiveLoginCompletion(page, runtimeConfig);

    if (loginDiagnostics.success) {
      notes.push('Manuelle Instagram-Anmeldung erfolgreich abgeschlossen.');
      recordRunDebug('session-established-via-manual-login', loginDiagnostics);
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }
  } else if (isLoginSessionMode) {
    const headlessWarning = 'Manuelle Instagram-Anmeldung wurde uebersprungen, weil auf dem Server kein sichtbarer Browser/Display-Server verfuegbar ist.';
    notes.push(`${headlessWarning} Der Session-Aufbau nutzt nur gespeicherte Cookies oder Auto-Login.`);
    loginDiagnostics = {
      ...loginDiagnostics,
      success: false,
      manualLoginAttempted: false,
      warnings: [
        ...(Array.isArray(loginDiagnostics.warnings) ? loginDiagnostics.warnings : []),
        headlessWarning,
      ],
    };
    recordRunDebug('manual-login-skipped-headless', loginDiagnostics);
  }

  recordRunDebug('session-establish-failed', {
    cookieDiagnostics,
    loginDiagnostics,
  });

  return {
    cookieDiagnostics,
    loginDiagnostics,
    sessionEstablished: false,
  };
}

function getRuntimeAccountPool(runtimeConfig = {}) {
  const pool = Array.isArray(runtimeConfig.accountPool) ? runtimeConfig.accountPool : [];

  if (pool.length > 0) {
    return pool;
  }

  return [{
    ...runtimeConfig,
    accountPool: [],
  }];
}

function selectNextRuntimeAccount(runtimeConfig, usedAccountKeys) {
  const accountPool = getRuntimeAccountPool(runtimeConfig);

  for (const account of accountPool) {
    const candidate = {
      ...runtimeConfig,
      ...account,
      accountPool,
    };
    const accountKey = getScraperAccountKey(candidate);

    if (!accountKey || usedAccountKeys.has(accountKey)) {
      continue;
    }

    return candidate;
  }

  return null;
}

async function clearInstagramSessionState(page) {
  await closeInstagramDialog(page).catch(() => {});

  const cookies = await page
    .cookies(
      'https://www.instagram.com/',
      'https://instagram.com/',
      'https://www.facebook.com/',
      'https://facebook.com/',
    )
    .catch(() => []);

  if (cookies.length > 0) {
    await page.deleteCookie(...cookies.map((cookie) => ({
      name: cookie.name,
      domain: cookie.domain,
      path: cookie.path || '/',
    }))).catch((error) => {
      recordRunDebug('account-switch-cookie-clear-failed', {
        error: normalizeText(error.message || String(error)),
      });
    });
  }

  await page.evaluate(() => {
    try {
      window.localStorage?.clear();
      window.sessionStorage?.clear();
    } catch (error) {
      // Storage can be inaccessible on transient browser pages.
    }
  }).catch(() => {});

  recordRunDebug('account-switch-state-cleared', {
    clearedCookieCount: cookies.length,
  });
}

async function switchScraperAccountAfterRateLimit(page, runtimeConfig, notes, relationship, rateLimitText, usedAccountKeys) {
  const accountPool = getRuntimeAccountPool(runtimeConfig);
  const currentKey = getScraperAccountKey(runtimeConfig);
  let lastFailure = null;

  if (currentKey) {
    usedAccountKeys.add(currentKey);
  }

  const blockedInfo = persistScraperProfileBlock(runtimeConfig, rateLimitText || 'instagram-rate-limit', {
    relationship,
    text: rateLimitText || null,
  });

  if (blockedInfo) {
    notes.push(`Scraper-Profil gesperrt: ${blockedInfo.reason}.`);
    recordRunDebug('scraper-profile-blocked', {
      relationship,
      profileLabel: runtimeConfig.profileLabel || null,
      blockedInfo,
    });
  }

  if (accountPool.length <= 1) {
    recordRunDebug('account-switch-skipped', {
      relationship,
      reason: 'no-alternate-account',
      accountPoolSize: accountPool.length,
      rateLimitText,
    });
    return null;
  }

  while (true) {
    const nextRuntimeConfig = selectNextRuntimeAccount(runtimeConfig, usedAccountKeys);

    if (!nextRuntimeConfig) {
      recordRunDebug('account-switch-exhausted', {
        relationship,
        accountPoolSize: accountPool.length,
        usedAccountCount: usedAccountKeys.size,
        rateLimitText,
      });
      return lastFailure
        ? {
          ...lastFailure,
          sessionEstablished: false,
          failed: true,
        }
        : null;
    }

    const nextKey = getScraperAccountKey(nextRuntimeConfig);
    const fromLabel = runtimeConfig.profileLabel || currentKey || 'instagram-default';
    const toLabel = nextRuntimeConfig.profileLabel || nextKey || 'instagram-default';

    if (nextKey) {
      usedAccountKeys.add(nextKey);
    }

    progressLog('account-switching', {
      relationship,
      fromProfileLabel: fromLabel,
      toProfileLabel: toLabel,
      accountPoolSize: accountPool.length,
    });
    notes.push(`Rate-Limit erkannt; Scraper-Account wird gewechselt (${fromLabel} -> ${toLabel}).`);
    recordRunDebug('account-switch-start', {
      relationship,
      fromProfileLabel: fromLabel,
      toProfileLabel: toLabel,
      fromAccountKey: currentKey,
      toAccountKey: nextKey,
      rateLimitText,
    });

    await clearInstagramSessionState(page);

    const sessionResult = await establishInstagramSession(page, nextRuntimeConfig, notes);

    if (sessionResult.sessionEstablished) {
      const switchCookiePath = buildCookiePath(nextRuntimeConfig);
      const cookiesSavedAfterSwitch = await saveCookiesToFile(page, switchCookiePath);

      if (cookiesSavedAfterSwitch.saved) {
        notes.push(`Sessiondaten fuer ${toLabel} wurden gespeichert.`);
      }

      notes.push(`Scraper-Account aktiv: ${toLabel}.`);
      setActiveScraperProfile(nextRuntimeConfig);
      recordRunDebug('account-switch-success', {
        relationship,
        toProfileLabel: toLabel,
        cookieFilePath: switchCookiePath,
        cookiesSaved: cookiesSavedAfterSwitch,
      });

      return {
        runtimeConfig: nextRuntimeConfig,
        cookieDiagnostics: sessionResult.cookieDiagnostics,
        loginDiagnostics: sessionResult.loginDiagnostics,
        sessionEstablished: true,
        failed: false,
      };
    }

    notes.push(`Scraper-Account ${toLabel} konnte keine stabile Instagram-Session herstellen.`);
    lastFailure = {
      runtimeConfig: nextRuntimeConfig,
      cookieDiagnostics: sessionResult.cookieDiagnostics,
      loginDiagnostics: sessionResult.loginDiagnostics,
    };
    recordRunDebug('account-switch-session-failed', {
      relationship,
      toProfileLabel: toLabel,
      cookieDiagnostics: sessionResult.cookieDiagnostics,
      loginDiagnostics: sessionResult.loginDiagnostics,
    });
  }
}

function buildRelationshipCollectionOptions(runtimeConfig, relationship) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';

  return {
    maxItems: normalizedRelationship === 'following'
      ? runtimeConfig.followingListMaxItems
      : runtimeConfig.followerListMaxItems,
    maxScrollRounds: runtimeConfig.relationshipListMaxScrollRounds,
    expectedCount: normalizedRelationship === 'following'
      ? runtimeConfig.expectedFollowingCount
      : runtimeConfig.expectedFollowerCount,
    runtimeConfig,
  };
}

async function collectRelationshipListForRuntime(page, username, profile, relationship, runtimeConfig) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const options = buildRelationshipCollectionOptions(runtimeConfig, normalizedRelationship);

  if (runtimeConfig.relationshipSearchOnly || isRelationshipSearchOnlyMode) {
    return collectPublicRelationshipSearchOnlyList(page, username, profile, normalizedRelationship, options);
  }

  return normalizedRelationship === 'following'
    ? collectPublicFollowingList(page, username, profile, options)
    : collectPublicFollowersList(page, username, profile, options);
}

function mergeRelationshipLists(previousList, nextList) {
  if (!previousList) {
    return nextList;
  }

  if (!nextList) {
    return previousList;
  }

  const itemsByUsername = new Map();

  for (const item of [
    ...(Array.isArray(previousList.items) ? previousList.items : []),
    ...(Array.isArray(nextList.items) ? nextList.items : []),
  ]) {
    const relatedUsername = normalizeInstagramUsername(item?.username || '');

    if (!relatedUsername || itemsByUsername.has(relatedUsername)) {
      continue;
    }

    itemsByUsername.set(relatedUsername, {
      ...item,
      username: relatedUsername,
    });
  }

  const maxItems = normalizeOptionalPositiveInteger(nextList.maxItems, previousList.maxItems);
  const mergedItems = Array.from(itemsByUsername.values());
  const limitedItems = maxItems > 0 ? mergedItems.slice(0, maxItems) : mergedItems;
  const recoveredFromRateLimit = Boolean(
    (previousList.rateLimited || previousList.rateLimitRecovered) && !nextList.rateLimited,
  );

  return {
    ...previousList,
    ...nextList,
    items: limitedItems,
    count: limitedItems.length,
    available: limitedItems.length > 0 || Boolean(previousList.available) || Boolean(nextList.available),
    attempted: Boolean(previousList.attempted || nextList.attempted),
    rateLimited: Boolean(nextList.rateLimited),
    rateLimitText: nextList.rateLimited ? (nextList.rateLimitText || previousList.rateLimitText || null) : null,
    rateLimitRecovered: recoveredFromRateLimit,
    previousRateLimitText: previousList.rateLimitText || previousList.previousRateLimitText || null,
    searchAttempted: Boolean(previousList.searchAttempted || nextList.searchAttempted),
    searchInputAvailable: Boolean(previousList.searchInputAvailable || nextList.searchInputAvailable),
    searchQueries: [
      ...new Set([
        ...(Array.isArray(previousList.searchQueries) ? previousList.searchQueries : []),
        ...(Array.isArray(nextList.searchQueries) ? nextList.searchQueries : []),
      ]),
    ],
    searchRounds: (Number(previousList.searchRounds) || 0) + (Number(nextList.searchRounds) || 0),
    searchAddedCount: (Number(previousList.searchAddedCount) || 0) + (Number(nextList.searchAddedCount) || 0),
    searchStopReason: nextList.searchStopReason || previousList.searchStopReason || null,
    searchMaxDepth: Math.max(Number(previousList.searchMaxDepth) || 0, Number(nextList.searchMaxDepth) || 0),
    searchExpandedQueryCount: (Number(previousList.searchExpandedQueryCount) || 0) + (Number(nextList.searchExpandedQueryCount) || 0),
  };
}

async function refreshProfileAfterAccountSwitch(page, username, profileUrl, runtimeConfig, notes) {
  progressLog('profile-opening', {
    relationship: null,
    url: profileUrl,
    accountSwitch: true,
    profileLabel: runtimeConfig.profileLabel || null,
  });

  const profileNavigation = await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);

  if (!profileNavigation.ok) {
    notes.push(`Profilseite konnte nach Account-Wechsel nicht stabil geladen werden: ${profileNavigation.error}`);
  }

  await sleep(1800);

  const profile = await collectProfileInfo(page, username);
  const html = await page.content().catch(() => null);
  const title = await page.title().catch(() => null);
  const finalUrl = page.url();

  progressLog('profile-collected', {
    usernameSeen: Boolean(profile.usernameSeen),
    isPrivate: Boolean(profile.isPrivate),
    requiresLogin: Boolean(profile.requiresLogin),
    imageCount: profile.imageCount || 0,
    accountSwitch: true,
    profileLabel: runtimeConfig.profileLabel || null,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  return {
    profile,
    html,
    title,
    finalUrl,
  };
}

async function collectRelationshipListWithAccountSwitches(page, username, profile, relationship, runtimeState, notes, profileUrl) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const maxPasses = Math.max(1, getRuntimeAccountPool(runtimeState.runtimeConfig).length);
  let currentProfile = profile;
  let latestHtml = null;
  let latestTitle = null;
  let latestFinalUrl = page.url();
  let switched = false;
  let list = null;

  for (let pass = 0; pass < maxPasses; pass++) {
    const collectedList = await collectRelationshipListForRuntime(
      page,
      username,
      currentProfile,
      normalizedRelationship,
      runtimeState.runtimeConfig,
    );
    list = mergeRelationshipLists(list, collectedList);
    recordRunDebug(
      `${normalizedRelationship}-list-collected${switched ? '-after-account-switch' : ''}`,
      list,
    );

    if (list?.gracefullyStopped) {
      break;
    }

    if (!list?.rateLimited) {
      break;
    }

    const switchResult = await switchScraperAccountAfterRateLimit(
      page,
      runtimeState.runtimeConfig,
      notes,
      normalizedRelationship,
      list.rateLimitText || null,
      runtimeState.usedAccountKeys,
    );

    if (!switchResult) {
      break;
    }

    runtimeState.runtimeConfig = switchResult.runtimeConfig;
    runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig);
    runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
    runtimeState.loginDiagnostics = switchResult.loginDiagnostics;

    if (!switchResult.sessionEstablished) {
      runtimeState.cookieSaveDisabled = true;
      latestFinalUrl = page.url();
      break;
    }

    switched = true;

    const refreshed = await refreshProfileAfterAccountSwitch(
      page,
      username,
      profileUrl,
      runtimeState.runtimeConfig,
      notes,
    );

    currentProfile = {
      ...currentProfile,
      ...refreshed.profile,
    };
    latestHtml = refreshed.html;
    latestTitle = refreshed.title;
    latestFinalUrl = refreshed.finalUrl;
  }

  return {
    list,
    profile: currentProfile,
    html: latestHtml,
    title: latestTitle,
    finalUrl: latestFinalUrl,
    switched,
  };
}

function emptyBatchRelationshipList(relationship, profile, reason = null) {
  return {
    relationship: relationship === 'following' ? 'following' : 'followers',
    checked: false,
    available: false,
    complete: false,
    targetFound: false,
    targetItem: null,
    observedCount: 0,
    expectedCount: 0,
    reportedCount: 0,
    rateLimited: false,
    profileIsPrivate: typeof profile?.isPrivate === 'boolean' ? profile.isPrivate : null,
    profileRequiresLogin: typeof profile?.requiresLogin === 'boolean' ? profile.requiresLogin : null,
    reason,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchStopReason: null,
    debugLogPath: activeRunDebug?.filePath || null,
    error: null,
  };
}

function summarizeBatchRelationshipList(list, profile, relationship) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const items = Array.isArray(list?.items) ? list.items : [];

  return {
    relationship: normalizedRelationship,
    checked: Boolean(list?.attempted || list?.searchAttempted || list?.available || items.length > 0),
    available: Boolean(list?.available),
    complete: Boolean(list?.complete),
    targetFound: Boolean(list?.targetFound || list?.targetItem),
    targetItem: list?.targetItem || null,
    observedCount: items.length,
    expectedCount: Number(list?.expectedCount || 0),
    reportedCount: Number(list?.count || items.length || 0),
    rateLimited: Boolean(list?.rateLimited),
    gracefullyStopped: Boolean(list?.gracefullyStopped),
    profileIsPrivate: typeof profile?.isPrivate === 'boolean' ? profile.isPrivate : null,
    profileRequiresLogin: typeof profile?.requiresLogin === 'boolean' ? profile.requiresLogin : null,
    reason: list?.reason || null,
    searchAttempted: Boolean(list?.searchAttempted),
    searchInputAvailable: Boolean(list?.searchInputAvailable),
    searchQueries: Array.isArray(list?.searchQueries) ? list.searchQueries : [],
    searchStopReason: list?.searchStopReason || null,
    debugLogPath: activeRunDebug?.filePath || null,
    error: null,
  };
}

function isBatchRelationshipSearchDefinitive(list) {
  if (!list || list.rateLimited) {
    return false;
  }

  if (list.targetFound) {
    return true;
  }

  if (!list.searchAttempted || !list.searchInputAvailable) {
    return false;
  }

  return [
    'search-empty',
    'search-suggestions-section-reached',
    'search-bottom-stale',
    'search-scroll-stale',
    'search-no-new-items',
    'search-partitions-exhausted',
    'target-found',
    'followers-target-not-found',
    'following-target-not-found',
  ].includes(list.searchStopReason || list.reason || '');
}

function isBatchCandidateConnectionDefinitive(connection) {
  if (!connection) {
    return false;
  }

  if (connection.skippedReason) {
    return true;
  }

  return isBatchRelationshipSearchDefinitive(connection.followers)
    && isBatchRelationshipSearchDefinitive(connection.following);
}

function isBatchCandidateSearchInputMissing(connection) {
  if (!connection) {
    return false;
  }

  return [connection.followers, connection.following].some((list) => [
    'search-input-not-found',
    'search-input-lost',
  ].includes(list?.searchStopReason || list?.reason || ''));
}

function isBatchCandidateSearchDialogMissing(connection) {
  if (!connection) {
    return false;
  }

  return [connection.followers, connection.following].some((list) => (
    list?.searchStopReason || list?.reason || ''
  ) === 'search-dialog-not-found');
}

function isBatchCandidateRateLimited(connection) {
  return Boolean(connection?.followers?.rateLimited || connection?.following?.rateLimited);
}

function hasUnusedRuntimeAccount(runtimeConfig, usedAccountKeys) {
  return getRuntimeAccountPool(runtimeConfig).some((account) => {
    const candidate = {
      ...runtimeConfig,
      ...account,
      accountPool: getRuntimeAccountPool(runtimeConfig),
    };
    const accountKey = getScraperAccountKey(candidate);

    return accountKey && !usedAccountKeys.has(accountKey);
  });
}

function describeBatchCandidatePendingReason(connection) {
  if (!connection) {
    return 'kein Suchergebnis';
  }

  const reasons = [];

  if (!isBatchRelationshipSearchDefinitive(connection.followers)) {
    reasons.push(`Followerliste: ${connection.followers?.reason || connection.followers?.searchStopReason || 'nicht eindeutig durchsucht'}`);
  }

  if (!isBatchRelationshipSearchDefinitive(connection.following)) {
    reasons.push(`Gefolgt-Liste: ${connection.following?.reason || connection.following?.searchStopReason || 'nicht eindeutig durchsucht'}`);
  }

  return reasons.join('; ') || 'nicht eindeutig durchsucht';
}

async function captureCandidateDebugScreenshot(page, baseScreenshotPath, candidate, attempt, reason, notes, bucket = []) {
  if (!baseScreenshotPath) {
    return null;
  }

  const screenshotPath = buildRelatedScreenshotPath(
    baseScreenshotPath,
    `candidate-${candidate.username}-attempt-${attempt}`,
  );
  const capturedPath = await captureDebugPageScreenshot(page, screenshotPath, notes);

  if (!capturedPath) {
    return null;
  }

  const entry = {
    candidateUsername: candidate.username,
    attempt,
    reason,
    screenshotPath: capturedPath,
  };

  bucket.push(entry);
  notes.push(`Debug-Screenshot fuer Kandidat @${candidate.username} gespeichert: ${capturedPath}`);

  return entry;
}

function resolvePublicConnectionRetryDelayMs(runtimeConfig = {}, attempt = 1, connection = null) {
  const rateLimited = Boolean(connection?.followers?.rateLimited || connection?.following?.rateLimited);
  const configuredBase = Number(runtimeConfig.publicConnectionRetryDelayMs || (rateLimited ? 15000 : 6000));
  const configuredMax = Number(runtimeConfig.publicConnectionRetryMaxDelayMs || (rateLimited ? 90000 : 30000));
  const baseDelay = Number.isFinite(configuredBase) ? Math.max(1500, configuredBase) : (rateLimited ? 15000 : 6000);
  const maxDelay = Number.isFinite(configuredMax) ? Math.max(baseDelay, configuredMax) : (rateLimited ? 90000 : 30000);

  return Math.min(maxDelay, baseDelay * Math.min(Math.max(1, attempt), 6));
}

function normalizePublicConnectionCandidate(candidate) {
  if (!candidate || typeof candidate !== 'object') {
    return null;
  }

  const candidateUsername = normalizeInstagramUsername(candidate.username);

  if (!candidateUsername) {
    return null;
  }

  return {
    username: candidateUsername,
    displayName: normalizeText(String(candidate.displayName || '')) || null,
    profileUrl: normalizeText(String(candidate.profileUrl || '')) || `https://www.instagram.com/${candidateUsername}/`,
    profileImageUrl: normalizeText(String(candidate.profileImageUrl || candidate.profile_image_url || '')) || null,
    sourceLists: Array.isArray(candidate.sourceLists)
      ? candidate.sourceLists.map((sourceList) => normalizeText(String(sourceList || ''))).filter(Boolean)
      : [],
  };
}

function buildBatchCandidateConnection(candidate, candidateFollowers, candidateFollowing) {
  const candidateFollowsTarget = Boolean(candidateFollowing.targetFound);
  const targetFollowsCandidate = Boolean(candidateFollowers.targetFound);
  const gracefullyStopped = Boolean(candidateFollowers.gracefullyStopped || candidateFollowing.gracefullyStopped);
  const skippedReason = candidateFollowers.profileIsPrivate || candidateFollowing.profileIsPrivate
    ? 'private-profile'
    : candidateFollowers.profileRequiresLogin || candidateFollowing.profileRequiresLogin
    ? 'login-required'
    : gracefullyStopped
    ? 'ui-stop-requested'
    : null;

  return {
    username: candidate.username,
    displayName: candidate.displayName,
    profileUrl: candidate.profileUrl,
    profileImageUrl: candidate.profileImageUrl || null,
    sourceLists: candidate.sourceLists,
    candidateFollowsTarget,
    targetFollowsCandidate,
    followerOfPrivateProfile: candidateFollowsTarget,
    followedByPrivateProfile: targetFollowsCandidate,
    skippedReason,
    gracefullyStopped,
    followers: candidateFollowers,
    following: candidateFollowing,
  };
}

function summarizeBatchCandidateConnectionForProgress(connection) {
  return {
    username: connection.username,
    displayName: connection.displayName || null,
    profileUrl: connection.profileUrl || `https://www.instagram.com/${connection.username}/`,
    profileImageUrl: connection.profileImageUrl || null,
    sourceLists: Array.isArray(connection.sourceLists) ? connection.sourceLists : [],
  };
}

function buildPublicConnectionProgressPreviews(candidateConnections) {
  return {
    inferredFollowersPreview: candidateConnections
      .filter((connection) => connection.followerOfPrivateProfile)
      .slice(-40)
      .map(summarizeBatchCandidateConnectionForProgress),
    inferredFollowingPreview: candidateConnections
      .filter((connection) => connection.followedByPrivateProfile)
      .slice(-40)
      .map(summarizeBatchCandidateConnectionForProgress),
  };
}

function markBatchCandidateConnectionFailed(connection, candidate, reason, attempt, screenshots = []) {
  const failureMessage = normalizeText(String(reason || 'candidate-error')) || 'candidate-error';
  const failedConnection = connection || buildBatchCandidateConnection(
    candidate,
    emptyBatchRelationshipList('followers', null, 'candidate-error'),
    emptyBatchRelationshipList('following', null, 'candidate-error'),
  );

  failedConnection.skippedReason = 'candidate-error';
  failedConnection.candidateError = failureMessage;
  failedConnection.scanAttempts = attempt;
  failedConnection.debugScreenshotPaths = screenshots.map((entry) => entry.screenshotPath).filter(Boolean);

  for (const list of [failedConnection.followers, failedConnection.following]) {
    if (!list) {
      continue;
    }

    list.reason = list.reason || 'candidate-error';
    list.searchStopReason = list.searchStopReason || 'candidate-error';
    list.error = failureMessage;
  }

  return failedConnection;
}

async function collectBatchCandidateConnectionOnce(page, runtimeState, notes, candidate, candidateProfileUrl) {
  const navigation = await navigateWithSoftTimeout(page, candidateProfileUrl, runtimeState.runtimeConfig, {
    timeoutMs: Math.max(15000, Math.min(resolveNavigationWaitMs(runtimeState.runtimeConfig), 45000)),
  });

  if (!navigation.ok) {
    notes.push(`Kandidat @${candidate.username} konnte nicht stabil geladen werden: ${navigation.error}`);
  }

  await sleep(750);

  const candidateProfile = await collectProfileInfo(page, candidate.username);
  let candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, null);
  let candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, null);

  if (candidateProfile.isPrivate) {
    candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, 'private-profile');
    candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, 'private-profile');
  } else if (candidateProfile.requiresLogin) {
    candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, 'login-required');
    candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, 'login-required');
  } else {
    const followersResult = await collectRelationshipListWithAccountSwitches(
      page,
      candidate.username,
      candidateProfile,
      'followers',
      runtimeState,
      notes,
      candidateProfileUrl,
    );
    candidateFollowers = summarizeBatchRelationshipList(followersResult.list, followersResult.profile, 'followers');

    if (page.url().includes('/followers')) {
      await navigateWithSoftTimeout(page, candidateProfileUrl, runtimeState.runtimeConfig, {
        timeoutMs: Math.max(15000, Math.min(resolveNavigationWaitMs(runtimeState.runtimeConfig), 45000)),
      });
      await sleep(350);
    }

    const followingResult = await collectRelationshipListWithAccountSwitches(
      page,
      candidate.username,
      followersResult.profile || candidateProfile,
      'following',
      runtimeState,
      notes,
      candidateProfileUrl,
    );
    candidateFollowing = summarizeBatchRelationshipList(followingResult.list, followingResult.profile, 'following');
  }

  return buildBatchCandidateConnection(candidate, candidateFollowers, candidateFollowing);
}

async function collectBatchCandidateConnectionUntilDefinitive(
  page,
  runtimeState,
  notes,
  candidate,
  candidateProfileUrl,
  progressContext,
) {
  let attempt = 0;
  let lastConnection = null;
  const candidateScreenshots = [];
  const startedAt = Date.now();
  const maxAttempts = Math.min(
    3,
    Math.floor(normalizeNumberAtLeast(runtimeState.runtimeConfig.publicConnectionCandidateMaxAttempts, 3, 1)),
  );
  const maxDurationMs = normalizeNumberAtLeast(
    runtimeState.runtimeConfig.publicConnectionCandidateMaxDurationMs,
    1200000,
    60000,
  );
  const dialogMissingMaxAttempts = Math.floor(normalizeNumberAtLeast(
    runtimeState.runtimeConfig.publicConnectionDialogMissingMaxAttempts,
    2,
    1,
  ));

  while (true) {
    assertScriptAlive();
    attempt++;

    if (attempt > 1) {
      progressLog('candidate-scan-retry', {
        relationship: 'public-connections',
        loaded: progressContext.loaded,
        expectedCount: progressContext.expectedCount,
        candidateUsername: candidate.username,
        attempt,
        foundFollowers: progressContext.foundFollowers,
        foundFollowing: progressContext.foundFollowing,
        inferredFollowersPreview: progressContext.inferredFollowersPreview || [],
        inferredFollowingPreview: progressContext.inferredFollowingPreview || [],
        rateLimitedCandidates: progressContext.rateLimitedCandidates,
        message: `Kandidat @${candidate.username} wird erneut geprueft (Versuch ${attempt}); ${describeBatchCandidatePendingReason(lastConnection)}.`,
      });
    }

    const connection = await collectBatchCandidateConnectionOnce(
      page,
      runtimeState,
      notes,
      candidate,
      candidateProfileUrl,
    );
    connection.scanAttempts = attempt;
    connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
    lastConnection = connection;

    if (isBatchCandidateConnectionDefinitive(connection)) {
      connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
      return connection;
    }

    const pendingReason = describeBatchCandidatePendingReason(connection);
    const screenshotEntry = await captureCandidateDebugScreenshot(
      page,
      progressContext.screenshotPath,
      candidate,
      attempt,
      pendingReason,
      notes,
      progressContext.candidateErrorScreenshots,
    );

    if (screenshotEntry) {
      candidateScreenshots.push(screenshotEntry);
      connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
    }

    if (
      isBatchCandidateRateLimited(connection)
      && runtimeState.runtimeConfig.publicConnectionRateLimitAccountSwitchEnabled !== false
      && hasUnusedRuntimeAccount(runtimeState.runtimeConfig, runtimeState.usedAccountKeys)
      && attempt < maxAttempts
    ) {
      progressLog('candidate-rate-limit-account-switch', {
        relationship: 'public-connections',
        loaded: progressContext.loaded,
        expectedCount: progressContext.expectedCount,
        candidateUsername: candidate.username,
        attempt,
        foundFollowers: progressContext.foundFollowers,
        foundFollowing: progressContext.foundFollowing,
        inferredFollowersPreview: progressContext.inferredFollowersPreview || [],
        inferredFollowingPreview: progressContext.inferredFollowingPreview || [],
        accountPoolSize: getRuntimeAccountPool(runtimeState.runtimeConfig).length,
        usedAccountCount: runtimeState.usedAccountKeys.size,
        message: `Rate-Limit bei @${candidate.username}; Scraper-Account wird gewechselt.`,
      });

      const switchResult = await switchScraperAccountAfterRateLimit(
        page,
        runtimeState.runtimeConfig,
        notes,
        'public-connections',
        connection.followers?.rateLimitText || connection.following?.rateLimitText || pendingReason,
        runtimeState.usedAccountKeys,
      );

      if (switchResult?.sessionEstablished) {
        runtimeState.runtimeConfig = switchResult.runtimeConfig;
        runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig);
        runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
        runtimeState.loginDiagnostics = switchResult.loginDiagnostics;
        await closeInstagramDialog(page);
        notes.push(`Kandidat @${candidate.username} wird nach Account-Wechsel erneut geprueft.`);

        continue;
      }

      if (switchResult?.failed) {
        runtimeState.cookieSaveDisabled = true;
        notes.push(`Kein weiterer Scraper-Account konnte fuer @${candidate.username} stabil aktiviert werden.`);
      }
    }

    if (isBatchCandidateSearchDialogMissing(connection) && attempt >= dialogMissingMaxAttempts) {
      const message = `Kandidat @${candidate.username} wird ausgelassen, weil der Listen-Dialog nach ${dialogMissingMaxAttempts} Versuch(en) nicht gefunden wurde (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (isBatchCandidateSearchInputMissing(connection)) {
      const message = `Kandidat @${candidate.username} konnte nicht geprueft werden, weil das Suchfeld nach 3 Versuch(en) nicht stabil gefunden wurde (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (attempt >= maxAttempts || Date.now() - startedAt >= maxDurationMs) {
      const message = `Kandidat @${candidate.username} konnte nach ${attempt} Versuch(en) nicht eindeutig geprueft werden (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (attempt === 1 || attempt % 5 === 0) {
      notes.push(`Kandidat @${candidate.username} wurde noch nicht abgeschlossen; ${pendingReason}. Naechster Versuch folgt in derselben Session.`);
    }

    await closeInstagramDialog(page);
    await sleep(resolvePublicConnectionRetryDelayMs(runtimeState.runtimeConfig, attempt, connection));
  }
}

function resolvePublicConnectionBatchStatus(candidatesTotal, candidatesChecked, candidateConnections) {
  if (candidatesTotal === 0) {
    return 'partial';
  }

  if (candidatesChecked === 0) {
    return 'error';
  }

  return candidateConnections.length > 0 ? 'success' : 'partial';
}

function resolvePublicConnectionBatchMessage(inferredFollowers, inferredFollowing, candidatesTotal, candidatesChecked, privateSkipped, rateLimitedCandidates = 0, failedCandidates = 0) {
  if (inferredFollowers.length > 0 || inferredFollowing.length > 0) {
    return `Teilrekonstruktion abgeschlossen: ${inferredFollowers.length} moegliche Follower und ${inferredFollowing.length} moegliche Gefolgt-Profile gefunden, ${failedCandidates} Kandidaten mit Fehlern.`;
  }

  if (candidatesChecked > 0) {
    return `Teilrekonstruktion abgeschlossen; ${candidatesChecked} von ${candidatesTotal} gespeicherten Kandidaten wurden geprueft, ${privateSkipped} private/gesperrte Profile uebersprungen, ${rateLimitedCandidates} per Rate-Limit blockiert, ${failedCandidates} mit Fehlern.`;
  }

  return 'Keine gespeicherten Kandidatenlisten fuer dieses bekannte Profil gefunden.';
}

async function runPublicConnectionBatch(page, runtimeState, notes, publicUsername, targetUsername, options = {}) {
  const allCandidates = Array.isArray(runtimeState.runtimeConfig.publicConnectionCandidates)
    ? runtimeState.runtimeConfig.publicConnectionCandidates
      .map(normalizePublicConnectionCandidate)
      .filter(Boolean)
      .filter((candidate) => candidate.username !== publicUsername && candidate.username !== targetUsername)
    : [];
  const configuredSkipCandidateUsernames = Array.isArray(runtimeState.runtimeConfig.publicConnectionSkipCandidateUsernames)
    ? runtimeState.runtimeConfig.publicConnectionSkipCandidateUsernames
      .map((candidateUsername) => normalizeInstagramUsername(candidateUsername))
      .filter(Boolean)
    : [];
  const configuredSkipSet = new Set(configuredSkipCandidateUsernames);
  const skippedCandidates = allCandidates.filter((candidate) => configuredSkipSet.has(candidate.username));
  const candidates = allCandidates.filter((candidate) => !configuredSkipSet.has(candidate.username));
  const expectedTotalCount = skippedCandidates.length + candidates.length;
  const candidateConnections = [];
  const checkedConnections = [];
  let foundFollowersCount = 0;
  let foundFollowingCount = 0;
  let rateLimitedCandidates = 0;
  let failedCandidatesCount = 0;
  let stoppedForRateLimit = false;
  let gracefullyStopped = false;
  let rateLimitedCandidateUsername = null;
  const candidateErrorScreenshots = [];

  progressLog('candidate-pool-ready', {
    relationship: 'public-connections',
    loaded: skippedCandidates.length,
    expectedCount: expectedTotalCount,
    resumeSkippedCount: skippedCandidates.length,
    foundFollowers: foundFollowersCount,
    foundFollowing: foundFollowingCount,
    ...buildPublicConnectionProgressPreviews(candidateConnections),
    rateLimitedCandidates,
    failedCandidates: failedCandidatesCount,
    message: `${candidates.length} offene von ${expectedTotalCount} gespeicherten Kandidaten aus @${publicUsername} werden gegen @${targetUsername} geprueft.`,
  });

  for (let index = 0; index < candidates.length; index++) {
    const candidate = candidates[index];
    const candidateProfileUrl = `https://www.instagram.com/${candidate.username}/`;
    const loadedBeforeCandidate = skippedCandidates.length + checkedConnections.length;

    if (markGracefulStopIfRequested('public-connections', {
      loaded: loadedBeforeCandidate,
      expectedCount: expectedTotalCount,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
    })) {
      gracefullyStopped = true;
      break;
    }

    progressLog('candidate-scan-start', {
      relationship: 'public-connections',
      loaded: loadedBeforeCandidate,
      expectedCount: expectedTotalCount,
      resumeSkippedCount: skippedCandidates.length,
      candidateUsername: candidate.username,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
      rateLimitedCandidates,
      failedCandidates: failedCandidatesCount,
      ...(await captureLivePreviewScreenshot(page, runtimeState.runtimeConfig)),
      message: `Kandidat @${candidate.username} wird geprueft (${loadedBeforeCandidate + 1}/${expectedTotalCount}). Gefunden: ${foundFollowersCount} Follower, ${foundFollowingCount} Gefolgt.`,
    });

    const connection = await collectBatchCandidateConnectionUntilDefinitive(
      page,
      runtimeState,
      notes,
      candidate,
      candidateProfileUrl,
      {
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        screenshotPath: options.screenshotPath || null,
        candidateErrorScreenshots,
      },
    );

    if (connection.followerOfPrivateProfile || connection.followedByPrivateProfile) {
      candidateConnections.push(connection);
    }

    if (connection.followerOfPrivateProfile) {
      foundFollowersCount++;
    }

    if (connection.followedByPrivateProfile) {
      foundFollowingCount++;
    }

    if (connection.gracefullyStopped) {
      gracefullyStopped = true;

      progressLog('candidate-scan-stopped', {
        relationship: 'public-connections',
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        resumeSkippedCount: skippedCandidates.length,
        candidateUsername: candidate.username,
        candidateConnection: connection,
        targetFoundInFollowers: connection.followedByPrivateProfile,
        targetFoundInFollowing: connection.followerOfPrivateProfile,
        skippedReason: connection.skippedReason,
        gracefullyStopped: true,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        failedCandidates: failedCandidatesCount,
        message: `Scan bei @${candidate.username} beendet. Bisherige Treffer bleiben gespeichert.`,
      });
      notes.push(`Public-Profile-Verbindungsscan bei @${candidate.username} ueber die Oberflaeche beendet.`);
      break;
    }

    if (isBatchCandidateRateLimited(connection)) {
      rateLimitedCandidates++;
      stoppedForRateLimit = true;
      rateLimitedCandidateUsername = candidate.username;
      connection.skippedReason = 'rate-limited';

      progressLog('candidate-scan-rate-limited', {
        relationship: 'public-connections',
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        resumeSkippedCount: skippedCandidates.length,
        candidateUsername: candidate.username,
        candidateConnection: connection,
        targetFoundInFollowers: connection.followedByPrivateProfile,
        targetFoundInFollowing: connection.followerOfPrivateProfile,
        skippedReason: connection.skippedReason,
        stoppedForRateLimit: true,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        failedCandidates: failedCandidatesCount,
        message: `Instagram-Rate-Limit bei @${candidate.username}. Scan wird pausiert; bisherige Treffer bleiben gespeichert.`,
      });
      notes.push(`Public-Profile-Verbindungsscan bei @${candidate.username} wegen Instagram-Rate-Limit pausiert.`);
      break;
    }

    checkedConnections.push(connection);

    if (connection.skippedReason === 'candidate-error') {
      failedCandidatesCount++;
    }

    progressLog('candidate-scan-complete', {
      relationship: 'public-connections',
      loaded: skippedCandidates.length + checkedConnections.length,
      expectedCount: expectedTotalCount,
      resumeSkippedCount: skippedCandidates.length,
      candidateUsername: candidate.username,
      candidateConnection: connection,
      targetFoundInFollowers: connection.followedByPrivateProfile,
      targetFoundInFollowing: connection.followerOfPrivateProfile,
      skippedReason: connection.skippedReason,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
      rateLimitedCandidates,
      failedCandidates: failedCandidatesCount,
      ...(await captureLivePreviewScreenshot(page, runtimeState.runtimeConfig, true)),
      message: `Kandidat @${candidate.username} abgeschlossen (${index + 1}/${candidates.length}). Gefunden: ${foundFollowersCount} Follower, ${foundFollowingCount} Gefolgt, Fehler: ${failedCandidatesCount}.`,
    });

    notes.push(`Kandidat @${candidate.username} eindeutig geprueft nach ${connection.scanAttempts || 1} Versuch(en).`);
  }

  const inferredFollowers = candidateConnections.filter((connection) => connection.followerOfPrivateProfile);
  const inferredFollowing = candidateConnections.filter((connection) => connection.followedByPrivateProfile);
  const privateSkipped = checkedConnections.filter((connection) => ['private-profile', 'login-required'].includes(connection.skippedReason)).length;
  const checkedCandidateUsernames = [
    ...skippedCandidates.map((candidate) => candidate.username),
    ...checkedConnections.map((connection) => connection.username),
  ];
  const statusLevel = stoppedForRateLimit
    ? 'partial'
    : gracefullyStopped
    ? 'partial'
    : resolvePublicConnectionBatchStatus(expectedTotalCount, checkedCandidateUsernames.length, candidateConnections);

  return {
    ok: statusLevel !== 'error',
    statusLevel,
    statusMessage: stoppedForRateLimit
      ? `Verbindungsscan wegen Instagram-Rate-Limit pausiert: ${checkedCandidateUsernames.length} von ${expectedTotalCount} Kandidaten geprueft.`
      : gracefullyStopped
      ? `Verbindungsscan beendet: ${checkedCandidateUsernames.length} von ${expectedTotalCount} Kandidaten geprueft. Bisherige Treffer wurden gespeichert.`
      : resolvePublicConnectionBatchMessage(
      inferredFollowers,
      inferredFollowing,
      expectedTotalCount,
      checkedCandidateUsernames.length,
      privateSkipped,
      rateLimitedCandidates,
      failedCandidatesCount,
    ),
    publicUsername,
    targetUsername,
    relationType: 'candidate_search',
    targetFollowsPublicProfile: false,
    publicProfileFollowsTarget: false,
    candidatesTotal: expectedTotalCount,
    candidatesChecked: checkedCandidateUsernames.length,
    candidatesSkippedPrivate: privateSkipped,
    candidatesRateLimited: rateLimitedCandidates,
    candidatesFailed: failedCandidatesCount,
    stoppedForRateLimit,
    gracefullyStopped,
    rateLimitedCandidateUsername,
    checkedCandidateUsernames,
    resumeSkippedCount: skippedCandidates.length,
    inferredFollowers,
    inferredFollowing,
    candidateErrorScreenshots,
    checkedPreview: checkedConnections.slice(0, 25),
  };
}

async function renderProfileSnapshot(browser, screenshotPath, username, profileUrl, title, profile, notes) {
  const snapshotPage = await browser.newPage();

  try {
    await snapshotPage.setViewport({
      width: 940,
      height: 560,
      deviceScaleFactor: 1,
    });

    const badgeMarkup = [
      profile.isPrivate ? '<span class="badge">Privates Profil</span>' : '',
      profile.requiresLogin ? '<span class="badge muted">Login-Hinweis</span>' : '',
      profile.usernameSeen ? '' : '<span class="badge warning">Profiltext eingeschraenkt</span>',
    ]
      .filter(Boolean)
      .join('');

    const headline = escapeHtml(profile.ogTitle || title || `@${username}`);
    const description = escapeHtml(
      profile.description ||
        profile.bodyTextPreview ||
        'Instagram hat fuer dieses Profil aktuell nur eingeschraenkte Daten geliefert.',
    );

    const avatarMarkup = profile.ogImage
      ? `<img src="${escapeHtml(profile.ogImage)}" alt="Profilbild von ${escapeHtml(username)}">`
      : '<div class="avatar-fallback">@</div>';

    await snapshotPage.setContent(
      `<!DOCTYPE html>
      <html lang="de">
        <head>
          <meta charset="utf-8">
          <title>Instagram Profil-Snapshot</title>
          <style>
            * { box-sizing: border-box; }
            body {
              margin: 0;
              font-family: "Segoe UI", Arial, sans-serif;
              background:
                radial-gradient(circle at top left, #feda75 0%, rgba(254, 218, 117, 0.25) 18%, transparent 40%),
                radial-gradient(circle at top right, #d62976 0%, rgba(214, 41, 118, 0.22) 18%, transparent 45%),
                linear-gradient(135deg, #f6f7fb 0%, #eef2ff 45%, #f8fafc 100%);
              color: #111827;
            }
            .frame {
              width: 940px;
              min-height: 560px;
              padding: 34px;
            }
            .card {
              display: grid;
              grid-template-columns: 220px 1fr;
              gap: 30px;
              min-height: 492px;
              padding: 30px;
              border: 1px solid rgba(15, 23, 42, 0.08);
              border-radius: 28px;
              background: rgba(255, 255, 255, 0.92);
              box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
              backdrop-filter: blur(12px);
            }
            .brand {
              display: inline-flex;
              align-items: center;
              gap: 10px;
              margin-bottom: 14px;
              font-size: 13px;
              font-weight: 700;
              letter-spacing: 0.08em;
              text-transform: uppercase;
              color: #be185d;
            }
            .avatar {
              width: 220px;
              height: 220px;
              overflow: hidden;
              border: 4px solid rgba(255, 255, 255, 0.96);
              border-radius: 28px;
              box-shadow: 0 18px 50px rgba(190, 24, 93, 0.18);
              background: linear-gradient(135deg, #f9a8d4 0%, #c084fc 100%);
            }
            .avatar img,
            .avatar-fallback {
              width: 100%;
              height: 100%;
            }
            .avatar img {
              display: block;
              object-fit: cover;
            }
            .avatar-fallback {
              display: grid;
              place-items: center;
              font-size: 86px;
              font-weight: 800;
              color: white;
            }
            .meta {
              display: flex;
              flex-direction: column;
              justify-content: space-between;
              gap: 18px;
            }
            h1 {
              margin: 0;
              font-size: 34px;
              line-height: 1.1;
            }
            .username {
              margin-top: 8px;
              font-size: 15px;
              font-weight: 700;
              letter-spacing: 0.04em;
              text-transform: uppercase;
              color: #475569;
            }
            .badges {
              display: flex;
              flex-wrap: wrap;
              gap: 10px;
              margin-top: 18px;
            }
            .badge {
              padding: 8px 12px;
              border-radius: 999px;
              background: #fce7f3;
              color: #9d174d;
              font-size: 13px;
              font-weight: 700;
            }
            .badge.muted {
              background: #e0e7ff;
              color: #3730a3;
            }
            .badge.warning {
              background: #fef3c7;
              color: #92400e;
            }
            .description {
              margin: 0;
              padding: 18px 20px;
              border-radius: 20px;
              background: #f8fafc;
              font-size: 16px;
              line-height: 1.6;
              color: #1f2937;
            }
            .facts {
              display: grid;
              grid-template-columns: repeat(3, minmax(0, 1fr));
              gap: 12px;
            }
            .fact {
              padding: 16px;
              border-radius: 18px;
              background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
              border: 1px solid rgba(148, 163, 184, 0.18);
            }
            .fact-label {
              font-size: 12px;
              font-weight: 700;
              letter-spacing: 0.08em;
              text-transform: uppercase;
              color: #64748b;
            }
            .fact-value {
              margin-top: 8px;
              font-size: 15px;
              font-weight: 700;
              color: #0f172a;
              word-break: break-word;
            }
            .footer {
              display: flex;
              justify-content: space-between;
              align-items: center;
              gap: 16px;
              font-size: 12px;
              color: #64748b;
            }
            .footer strong {
              color: #0f172a;
            }
          </style>
        </head>
        <body>
          <div class="frame">
            <div class="card">
              <div>
                <div class="brand">Instagram Snapshot</div>
                <div class="avatar">${avatarMarkup}</div>
              </div>
              <div class="meta">
                <div>
                  <h1>${headline}</h1>
                  <div class="username">@${escapeHtml(username)}</div>
                  <div class="badges">${badgeMarkup}</div>
                </div>
                <p class="description">${description}</p>
                <div class="facts">
                  <div class="fact">
                    <div class="fact-label">Quelle</div>
                    <div class="fact-value">Instagram HTML</div>
                  </div>
                  <div class="fact">
                    <div class="fact-label">Bilder erkannt</div>
                    <div class="fact-value">${escapeHtml(String(profile.imageCount || 0))}</div>
                  </div>
                  <div class="fact">
                    <div class="fact-label">Snapshot-Typ</div>
                    <div class="fact-value">Profilkarte</div>
                  </div>
                </div>
                <div class="footer">
                  <span><strong>Profil:</strong> ${escapeHtml(profileUrl)}</span>
                  <span>${escapeHtml(notes.join(' | ') || 'Metadaten erfolgreich extrahiert.')}</span>
                </div>
              </div>
            </div>
          </div>
        </body>
      </html>`,
      {
        waitUntil: 'domcontentloaded',
      },
    );

    await sleep(1200);

    await snapshotPage.screenshot({
      path: screenshotPath,
    });
  } finally {
    await snapshotPage.close();
  }
}

async function captureDebugPageScreenshot(page, screenshotPath, notes) {
  if (!page || !screenshotPath) {
    return null;
  }

  try {
    await page.screenshot({
      path: screenshotPath,
      fullPage: false,
    });

    return screenshotPath;
  } catch (error) {
    const message = normalizeText(error?.message || String(error));

    if (Array.isArray(notes)) {
      notes.push(`Debug-Screenshot konnte nicht erstellt werden: ${message}`);
    }

    recordRunDebug('debug-screenshot-failed', {
      screenshotPath,
      error: message,
    });

    return null;
  }
}

async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
  const livePreviewPath = normalizeText(String(runtimeConfig.livePreviewPath || ''));

  if (!page || !livePreviewPath || runtimeConfig.livePreviewEnabled === false) {
    return {};
  }

  const now = Date.now();

  if (!force && now - lastLivePreviewAt < LIVE_PREVIEW_MIN_INTERVAL_MS) {
    return {};
  }

  try {
    ensureDirectory(path.dirname(livePreviewPath));
    await page.screenshot({
      path: livePreviewPath,
      fullPage: false,
      type: 'png',
    });

    lastLivePreviewAt = now;

    return {
      liveScreenshotPath: livePreviewPath,
      liveScreenshotAt: new Date(now).toISOString(),
    };
  } catch (error) {
    recordRunDebug('live-preview-screenshot-failed', {
      livePreviewPath,
      error: normalizeText(error?.message || String(error)),
    });

    return {};
  }
}

(async () => {
  if (!username && !isLoginSessionMode) {
    console.log(
      JSON.stringify({
        ok: false,
        error: 'Kein Instagram-Username uebergeben.',
      }),
    );
    process.exit(1);
  }

  const startedAt = Date.now();
  const notes = [];
  const consoleMessages = [];
    let runtimeConfig = loadRuntimeConfig(runtimeConfigPath);
    setActiveScraperProfile(runtimeConfig);
    setGracefulStopFilePath(runtimeConfig);
  configureScriptWatchdog(runtimeConfig);
  const debugLogPath = initializeRunDebug(operationMode, username, runtimeConfig);
  const profileUrl = isLoginSessionMode
    ? 'https://www.instagram.com/'
    : `https://www.instagram.com/${username}/`;
  const artifacts = buildArtifactPaths(isLoginSessionMode ? '__session__' : username);
  runtimeConfig.livePreviewPath = artifacts.livePreviewPath;
  let cookieFilePath = buildCookiePath(runtimeConfig);
  const browserUserDataDir = buildBrowserUserDataDir(runtimeConfig);
  let activeBrowserUserDataDir = browserUserDataDir;
  let cleanupBrowserProfileOnExit = shouldCleanupBrowserProfile(runtimeConfig, browserUserDataDir);

  let browser;
  let page;
  let initialHtml = '';
  let debugScreenshotPath = null;
  let initialProfile = {
    bodyTextPreview: '',
    description: null,
    imageCount: 0,
    isPrivate: false,
    ogImage: null,
    ogTitle: null,
    requiresLogin: false,
    usernameSeen: false,
  };
  let title = null;
  let finalUrl = profileUrl;
  let suggestionScanResult = null;
  let loginDiagnostics = {
    attempted: false,
    success: false,
    warnings: [],
  };
  let cookieDiagnostics = {
    loaded: false,
    warnings: [],
  };
  recordRunDebug('run-start', {
    runtimeConfigPath,
    profileUrl,
    artifacts,
    cookieFilePath,
    browserUserDataDir,
    isLoginSessionMode,
  });

  try {
    const resolvedHeadlessMode = resolvePuppeteerHeadlessMode(runtimeConfig);

    if (resolvedHeadlessMode !== false && (isLoginSessionMode || runtimeConfig.headlessEnabled === false)) {
      notes.push('Kein Display-Server erkannt; Chrome wird headless gestartet. Fuer einen sichtbaren Browser DISPLAY/xvfb konfigurieren.');
    }

    const launchOptions = {
      headless: resolvedHeadlessMode,
      userDataDir: activeBrowserUserDataDir,
      protocolTimeout: Math.max(300000, resolveNavigationWaitMs(runtimeConfig, 300000)),
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-blink-features=AutomationControlled',
        '--lang=de-DE,de;q=0.9',
      ],
    };
    recordRunDebug('browser-launch-options', {
      headless: launchOptions.headless,
      userDataDir: launchOptions.userDataDir,
      args: launchOptions.args,
    });

    try {
      browser = await puppeteer.launch(launchOptions);
    } catch (launchError) {
      const launchErrorMessage = normalizeText(launchError.message || String(launchError));
      const browserProfileLocked = /already running|processsingleton|userdatadir|user data dir|user data directory/i.test(launchErrorMessage);

      if (runtimeConfig.persistentProfileEnabled && browserProfileLocked) {
        notes.push('Das persistente Browser-Profil ist bereits durch Chrome belegt; diese Sitzung nutzt ein temporaeres Profil und speichert die Cookies separat.');
        activeBrowserUserDataDir = fs.mkdtempSync(
          path.join(runtimeTempDirectory, 'puppeteer-profile-fallback-'),
        );
        cleanupBrowserProfileOnExit = true;
        runtimeConfig.persistentProfileEnabled = false;
        recordRunDebug('browser-launch-fallback', {
          previousError: launchErrorMessage,
          fallbackUserDataDir: activeBrowserUserDataDir,
        });
        browser = await puppeteer.launch({
          ...launchOptions,
          userDataDir: activeBrowserUserDataDir,
        });
      } else {
        throw launchError;
      }
    }

    scriptWatchdog.browser = browser;
    browser.on('disconnected', () => {
      if (!scriptWatchdog.intentionalBrowserClose && scriptWatchdog.browserDisconnectAbort) {
        requestScriptAbort(
          'Node-Scraper abgebrochen: Verbindung zu Chrome/Puppeteer wurde getrennt.',
          'BROWSER_DISCONNECTED',
        );
      }
    });

    page = await browser.newPage();
    page.setDefaultTimeout(0);
    page.setDefaultNavigationTimeout(0);
    page.on('close', () => {
      if (!scriptWatchdog.intentionalBrowserClose && scriptWatchdog.browserDisconnectAbort) {
        requestScriptAbort(
          'Node-Scraper abgebrochen: Browser-Seite wurde unerwartet geschlossen.',
          'PAGE_CLOSED',
        );
      }
    });

    page.on('console', (message) => {
      const text = normalizeText(message.text());

      if (!text) {
        return;
      }

      if (message.type() === 'error' || /rate limit exceeded/i.test(text)) {
        consoleMessages.push(`${message.type()}: ${text}`);
      }
    });

    page.on('pageerror', (error) => {
      consoleMessages.push(`pageerror: ${normalizeText(error.message)}`);
      recordRunDebug('pageerror', {
        message: normalizeText(error.message),
      });
    });

    page.on('requestfailed', (request) => {
      const requestUrl = request.url();
      const failureText = normalizeText(request.failure()?.errorText || 'requestfailed');

      if (
        runtimeConfig.blockHeavyResources
        && failureText === 'net::ERR_FAILED'
        && ['image', 'media', 'font'].includes(request.resourceType())
      ) {
        return;
      }

      if (!/instagram|facebook/i.test(requestUrl)) {
        return;
      }

      const message = `requestfailed: ${failureText} ${requestUrl}`;
      consoleMessages.push(message);
      recordRunDebug('requestfailed', {
        url: requestUrl,
        resourceType: request.resourceType(),
        failureText,
      });
    });

    page.on('response', (response) => {
      const responseUrl = response.url();

      if (response.status() < 400 || !/instagram|facebook/i.test(responseUrl)) {
        return;
      }

      const message = `response ${response.status()}: ${responseUrl}`;
      consoleMessages.push(message);
      recordRunDebug('http-error-response', {
        status: response.status(),
        url: responseUrl,
        resourceType: response.request().resourceType(),
      });
    });

    if (runtimeConfig.blockHeavyResources) {
      await page.setRequestInterception(true);
      page.on('request', (request) => {
        if (['image', 'media', 'font'].includes(request.resourceType())) {
          request.abort().catch(() => {});

          return;
        }

        request.continue().catch(() => {});
      });
    }

    await page.setExtraHTTPHeaders({
      'Accept-Language': 'de-DE,de;q=0.9,en;q=0.8',
    });

    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    );

    await page.setViewport({
      width: 1280,
      height: 1600,
      deviceScaleFactor: 1,
    });

    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', {
        get: () => false,
      });
    });

    notes.push(`Scraper-Profil: ${runtimeConfig.profileLabel}`);

    if (runtimeConfig.persistentProfileEnabled) {
      notes.push('Persistentes Browser-Profil aktiviert.');
    }
    recordRunDebug('page-ready', await collectPageDiagnostics(page, { includeCookies: true }));

    let sessionEstablished = false;

    const miniScanUsesSession = isMiniScanMode && runtimeConfig.miniScanUseSession === true;

    if (isMiniScanMode && !miniScanUsesSession) {
      notes.push('Mini-Scan aktiv: oeffentliche Profildaten werden ohne Instagram-Anmeldung ausgelesen.');
      cookieDiagnostics = {
        loaded: false,
        skipped: true,
        warnings: [],
      };
      loginDiagnostics = {
        attempted: false,
        success: false,
        skipped: true,
        warnings: [],
      };
      progressLog('profile-session-check', {
        relationship: null,
        skipped: true,
      });
    } else {
      progressLog('profile-session-check', {
        relationship: null,
      });

      const sessionResult = await establishInstagramSession(
        page,
        runtimeConfig,
        notes,
      );
      cookieDiagnostics = sessionResult.cookieDiagnostics;
      loginDiagnostics = sessionResult.loginDiagnostics;
      sessionEstablished = sessionResult.sessionEstablished;

      if (sessionEstablished) {
        const cookiesSavedAfterSession = await saveCookiesToFile(page, cookieFilePath);

        if (cookiesSavedAfterSession.saved) {
          notes.push('Sessiondaten wurden gespeichert.');

          if (!cookiesSavedAfterSession.sessionCookieSaved) {
            notes.push('Es wurden zwar Cookies gespeichert, aber keine sessionid.');
          }
        }
      }

      if (isLoginSessionMode) {
        debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

        const responsePayload = {
          ok: sessionEstablished,
          statusLevel: sessionEstablished ? 'success' : 'error',
          statusMessage: sessionEstablished
            ? 'Instagram-Session wurde erfolgreich aufgebaut und gespeichert.'
            : 'Instagram-Session konnte nicht stabil aufgebaut werden.',
          username: null,
          finalUrl: page.url(),
          htmlBytes: 0,
          htmlPath: null,
          htmlPreview: '',
          notes: dedupe(notes),
          cookieDiagnostics,
          loginDiagnostics,
          profile: null,
          profileUrl,
          screenshotPath: debugScreenshotPath,
          scrapedAt: new Date().toISOString(),
          screenshotMode: debugScreenshotPath ? 'page' : null,
          title: await page.title().catch(() => null),
          warnings: dedupe([
            ...consoleMessages,
            ...(cookieDiagnostics.warnings || []),
            ...(loginDiagnostics.warnings || []),
          ]),
          durationMs: Date.now() - startedAt,
          operationMode,
          debugLogPath,
        };
        flushRunDebug(responsePayload);
        console.log(JSON.stringify(responsePayload));

        return;
      }
    }

    const runtimeState = {
      runtimeConfig,
      cookieFilePath,
      cookieDiagnostics,
      loginDiagnostics,
      usedAccountKeys: new Set([getScraperAccountKey(runtimeConfig)].filter(Boolean)),
      cookieSaveDisabled: false,
    };

    if (operationMode === 'public-connections-batch') {
      const targetUsername = normalizeInstagramUsername(runtimeConfig.relationshipSearchTargetUsername || '');
      const batchPayload = await runPublicConnectionBatch(
        page,
        runtimeState,
        notes,
        username,
        targetUsername,
        {
          screenshotPath: artifacts.screenshotPath,
        },
      );
      debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);
      const responsePayload = {
        ...batchPayload,
        username,
        finalUrl: page.url(),
        htmlBytes: 0,
        htmlPath: null,
        htmlPreview: '',
        notes: dedupe(notes),
        cookieDiagnostics,
        loginDiagnostics,
        profile: null,
        profileUrl,
        screenshotPath: debugScreenshotPath,
        scrapedAt: new Date().toISOString(),
        screenshotMode: debugScreenshotPath ? 'page' : null,
        title: await page.title().catch(() => null),
        warnings: dedupe([
          ...consoleMessages,
          ...(cookieDiagnostics.warnings || []),
          ...(loginDiagnostics.warnings || []),
        ]),
        durationMs: Date.now() - startedAt,
        operationMode,
        debugLogPath,
      };
      flushRunDebug(responsePayload);
      console.log(JSON.stringify(responsePayload));

      return;
    }

    progressLog('profile-opening', {
      relationship: null,
      url: profileUrl,
    });

    const profileNavigation = await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);

    if (!profileNavigation.ok) {
      notes.push(`Profilseite konnte nicht stabil geladen werden: ${profileNavigation.error}`);
    }

    progressLog('profile-page-loaded', {
      relationship: null,
      url: page.url(),
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    await sleep(1800);

    initialHtml = await page.content();
    initialProfile = await collectProfileInfo(page, username);
    title = await page.title().catch(() => null);
    finalUrl = page.url();

    progressLog('profile-collected', {
      usernameSeen: Boolean(initialProfile.usernameSeen),
      isPrivate: Boolean(initialProfile.isPrivate),
      requiresLogin: Boolean(initialProfile.requiresLogin),
      imageCount: initialProfile.imageCount || 0,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    let gracefullyStopped = Boolean(markGracefulStopIfRequested(null, {
      loaded: 0,
      expectedCount: 0,
    }));

    if (gracefullyStopped) {
      notes.push('Instagram-Scan wurde ueber die Oberflaeche nach den Grunddaten beendet.');
    }

    if (isSuggestionsMode && !gracefullyStopped) {
      suggestionScanResult = await runProfileSuggestionConnectionScan(
        page,
        runtimeState,
        notes,
        username,
        profileUrl,
      );
      initialProfile.suggestionScan = suggestionScanResult;
      initialHtml = await page.content().catch(() => initialHtml);
      title = await page.title().catch(() => title);
      finalUrl = page.url();

      if (suggestionScanResult.gracefullyStopped) {
        gracefullyStopped = true;
      }
    }

    if (shouldCollectFollowers && !gracefullyStopped) {
      const followersResult = await collectRelationshipListWithAccountSwitches(
        page,
        username,
        initialProfile,
        'followers',
        runtimeState,
        notes,
        profileUrl,
      );

      initialProfile = followersResult.profile;
      initialProfile.followersList = followersResult.list;
      runtimeConfig = runtimeState.runtimeConfig;
      cookieFilePath = runtimeState.cookieFilePath;
      cookieDiagnostics = runtimeState.cookieDiagnostics;
      loginDiagnostics = runtimeState.loginDiagnostics;

      if (followersResult.html !== null) {
        initialHtml = followersResult.html;
      }

      if (followersResult.title !== null) {
        title = followersResult.title;
      }

      finalUrl = followersResult.finalUrl || page.url();

      if (initialProfile.followersList?.rateLimited) {
        notes.push('Instagram hat die Followerliste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
        consoleMessages.push('rate-limit: instagram-rate-limit followers');
      } else if (initialProfile.followersList?.gracefullyStopped) {
        gracefullyStopped = true;
        notes.push('Followerliste wurde ueber die Oberflaeche beendet; bisherige Eintraege werden gespeichert.');
      } else if (initialProfile.followersList?.available) {
        notes.push(`Followerliste ausgelesen: ${initialProfile.followersList.count} Eintraege.`);
      } else if (initialProfile.followersList?.attempted) {
        notes.push('Followerliste konnte nicht ausgelesen werden.');
      }

      if (page.url().includes('/followers')) {
        await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
        await sleep(900);
      }
    }

    if (shouldCollectFollowing && !gracefullyStopped) {
      const followingResult = await collectRelationshipListWithAccountSwitches(
        page,
        username,
        initialProfile,
        'following',
        runtimeState,
        notes,
        profileUrl,
      );

      initialProfile = followingResult.profile;
      initialProfile.followingList = followingResult.list;
      runtimeConfig = runtimeState.runtimeConfig;
      cookieFilePath = runtimeState.cookieFilePath;
      cookieDiagnostics = runtimeState.cookieDiagnostics;
      loginDiagnostics = runtimeState.loginDiagnostics;

      if (followingResult.html !== null) {
        initialHtml = followingResult.html;
      }

      if (followingResult.title !== null) {
        title = followingResult.title;
      }

      finalUrl = followingResult.finalUrl || page.url();

      if (initialProfile.followingList?.rateLimited) {
        notes.push('Instagram hat die Gefolgt-Liste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
        consoleMessages.push('rate-limit: instagram-rate-limit following');
      } else if (initialProfile.followingList?.gracefullyStopped) {
        gracefullyStopped = true;
        notes.push('Gefolgt-Liste wurde ueber die Oberflaeche beendet; bisherige Eintraege werden gespeichert.');
      } else if (initialProfile.followingList?.available) {
        notes.push(`Gefolgt-Liste ausgelesen: ${initialProfile.followingList.count} Eintraege.`);
      } else if (initialProfile.followingList?.attempted) {
        notes.push('Gefolgt-Liste konnte nicht ausgelesen werden.');
      }

      if (page.url().includes('/followers') || page.url().includes('/following')) {
        await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
        await sleep(900);
      }
    }

    finalUrl = page.url();

    if (finalUrl.includes('/accounts/login')) {
      notes.push('Instagram hat den Seitenaufruf direkt auf die Login-Seite umgeleitet.');
    }

    if (! initialProfile.usernameSeen) {
      notes.push('Profilname wurde im sichtbaren DOM nur eingeschraenkt erkannt.');
    }

    if (initialProfile.requiresLogin) {
      notes.push('Instagram blendet einen Login-Hinweis fuer dieses Profil ein.');
    }

    if (consoleMessages.some((message) => /rate limit exceeded/i.test(message))) {
      notes.push('Instagram meldet ein Rate-Limit; die Daten koennen unvollstaendig sein.');
    }

    const dedupedWarnings = dedupe([
      ...consoleMessages,
      ...(cookieDiagnostics.warnings || []),
      ...(loginDiagnostics.warnings || []),
    ]);
    const outcome = isSuggestionsMode && suggestionScanResult
      ? {
        ok: suggestionScanResult.statusLevel !== 'error',
        statusLevel: suggestionScanResult.statusLevel || 'unknown',
        statusMessage: suggestionScanResult.statusMessage || 'Profilvorschlag-Verbindungsscan abgeschlossen.',
      }
      : gracefullyStopped
      ? {
        ok: false,
        statusLevel: 'partial',
        statusMessage: 'Instagram-Scan wurde beendet; bisherige Ergebnisse wurden gespeichert.',
      }
      : deriveScrapeOutcome({
      title,
      finalUrl,
      profile: initialProfile,
      warnings: dedupedWarnings,
      cookieDiagnostics,
      loginDiagnostics,
    });

    if (!runtimeConfig.skipDebugArtifacts) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    if (isMiniScanMode && !miniScanUsesSession) {
      notes.push('Cookies wurden im Mini-Scan nicht geladen oder gespeichert.');
    } else if (runtimeState.cookieSaveDisabled) {
      notes.push('Cookies wurden nicht gespeichert, weil der Account-Wechsel keine stabile Session herstellen konnte.');
    } else if (shouldSaveCookies(finalUrl, initialProfile)) {
      const cookiesSaved = await saveCookiesToFile(page, cookieFilePath);
      if (cookiesSaved.saved) {
        notes.push('Aktualisierte Instagram-Cookies gespeichert.');
        if (!cookiesSaved.sessionCookieSaved) {
          notes.push('Die gespeicherten Cookies enthalten weiterhin keine sessionid.');
        }
      } else {
        notes.push('Es wurden keine Cookies gespeichert, weil keine relevanten Instagram-Cookies gefunden wurden.');
      }
    } else {
      notes.push('Cookies wurden nicht gespeichert, weil die Session offenbar nicht gueltig war.');
    }

    debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

    const responsePayload = {
      ok: outcome.ok,
      statusLevel: outcome.statusLevel,
      statusMessage: outcome.statusMessage,
      username,
      finalUrl,
      htmlBytes: Buffer.byteLength(initialHtml, 'utf8'),
      htmlPath: runtimeConfig.skipDebugArtifacts ? null : artifacts.htmlPath,
      htmlPreview: initialHtml.slice(0, 4000),
      notes: dedupe(notes),
      cookieDiagnostics,
      loginDiagnostics,
      profile: initialProfile,
      suggestionScan: suggestionScanResult,
      suggestionConnections: Array.isArray(suggestionScanResult?.matches)
        ? suggestionScanResult.matches
        : [],
      gracefullyStopped,
      profileUrl,
      screenshotPath: debugScreenshotPath,
      scrapedAt: new Date().toISOString(),
      screenshotMode: debugScreenshotPath ? 'page' : null,
      title,
      warnings: dedupedWarnings,
      durationMs: Date.now() - startedAt,
      operationMode,
      debugLogPath,
    };
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));
  } catch (error) {
    const aborted = error instanceof ScraperAbortError || error?.name === 'ScraperAbortError';

    if (initialHtml && !runtimeConfig.skipDebugArtifacts) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

    const responsePayload = {
      ok: false,
      statusLevel: 'error',
      statusMessage: aborted
        ? error.message
        : 'Instagram-Scrape fehlgeschlagen.',
      username,
      error: normalizeText(error.message),
      candidateUsername: error.candidateUsername || null,
      candidateErrorScreenshots: Array.isArray(error.candidateDebugScreenshots)
        ? error.candidateDebugScreenshots
        : [],
      finalUrl,
      htmlPath: initialHtml && !runtimeConfig.skipDebugArtifacts ? artifacts.htmlPath : null,
      notes: dedupe(notes),
      screenshotPath: debugScreenshotPath,
      screenshotMode: debugScreenshotPath ? 'page' : null,
      warnings: dedupe(consoleMessages),
      durationMs: Date.now() - startedAt,
      debugLogPath,
    };
    recordRunDebug('run-error', responsePayload);
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));

    process.exitCode = 1;
  } finally {
    scriptWatchdog.intentionalBrowserClose = true;

    if (browser) {
      await closeBrowserSoftly(browser);
    }

    stopScriptWatchdog();

    if (cleanupBrowserProfileOnExit) {
      cleanupDirectory(activeBrowserUserDataDir);
    }
  }
})();
