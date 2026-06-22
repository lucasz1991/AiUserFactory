const fs = require('fs');
const path = require('path');
const { launchConfiguredBrowser, resolveBrowserEngine } = require('./lib/browser-launcher.cjs');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const LIVE_PREVIEW_MIN_INTERVAL_MS = 2500;
const DEFAULT_VIEWPORT = { width: 1365, height: 900 };

const runtimeConfigPath = process.argv[2] || '';
const statusEvents = [];
let lastLivePreviewAt = 0;
let activeBrowserEngine = null;
let requestedBrowserEngine = null;
let browserFallbackReason = null;

function normalizeText(value) {
  return String(value || '').trim();
}

function ensureDirectory(directoryPath) {
  if (!directoryPath) {
    return directoryPath;
  }

  fs.mkdirSync(directoryPath, { recursive: true });

  return directoryPath;
}

function readJsonFile(filePath, fallback = {}) {
  try {
    const raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function writeJsonFile(filePath, payload) {
  ensureDirectory(path.dirname(filePath));
  const temporaryPath = `${filePath}.${process.pid}.tmp`;
  fs.writeFileSync(temporaryPath, JSON.stringify(payload, null, 2), 'utf8');
  fs.renameSync(temporaryPath, filePath);
}

function publicStatusPayload(runtimeConfig, state, stage, message, data = {}) {
  return {
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    providerLabel: runtimeConfig.provider?.label || null,
    state,
    stage,
    message,
    at: new Date().toISOString(),
    pid: process.pid,
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    finalUrl: data.finalUrl || null,
    title: data.title || null,
    liveScreenshotPath: data.liveScreenshotPath || null,
    liveScreenshotAt: data.liveScreenshotAt || null,
    events: statusEvents.slice(-40),
  };
}

function progress(runtimeConfig, stage, message, data = {}, state = 'running') {
  const event = {
    at: new Date().toISOString(),
    stage,
    message,
    finalUrl: data.finalUrl || null,
    title: data.title || null,
  };

  statusEvents.push(event);

  if (statusEvents.length > 80) {
    statusEvents.shift();
  }

  const statusPath = normalizeText(runtimeConfig.statusPath);

  if (statusPath) {
    writeJsonFile(statusPath, publicStatusPayload(runtimeConfig, state, stage, message, data));
  }

  process.stderr.write(`[MAIL REGISTER PROGRESS] ${JSON.stringify({
    ...event,
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    state,
  })}\n`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
  const livePreviewPath = normalizeText(runtimeConfig.livePreviewPath);

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
    return {
      liveScreenshotError: normalizeText(error?.message || String(error)),
    };
  }
}

async function pageSnapshot(page, runtimeConfig, force = false) {
  const [title, finalUrl, screenshot] = await Promise.all([
    page.title().catch(() => null),
    Promise.resolve(page.url()).catch(() => null),
    captureLivePreviewScreenshot(page, runtimeConfig, force),
  ]);

  return {
    title,
    finalUrl,
    ...screenshot,
  };
}

function validateProvider(runtimeConfig) {
  const provider = runtimeConfig.provider || {};
  const mode = normalizeText(provider.mode || 'observed_manual');

  if (mode !== 'observed_manual') {
    throw new Error(`Provider-Adapter "${mode}" ist noch nicht implementiert.`);
  }

  const registrationUrl = normalizeText(provider.registrationUrl || provider.registration_url);

  if (!/^https?:\/\//i.test(registrationUrl)) {
    throw new Error('Fuer den ersten Mail-Provider ist eine gueltige Registrierungs-URL erforderlich.');
  }

  return {
    ...provider,
    mode,
    registrationUrl,
    completionUrlContains: normalizeText(provider.completionUrlContains || provider.completion_url_contains),
    completionSelector: normalizeText(provider.completionSelector || provider.completion_selector),
  };
}

async function detectManualCompletion(page, provider) {
  const finalUrl = page.url();

  if (provider.completionUrlContains && finalUrl.includes(provider.completionUrlContains)) {
    return {
      completed: true,
      reason: 'completion-url',
    };
  }

  if (provider.completionSelector) {
    const element = await page.$(provider.completionSelector).catch(() => null);

    if (element) {
      return {
        completed: true,
        reason: 'completion-selector',
      };
    }
  }

  return {
    completed: false,
    reason: null,
  };
}

async function runObservedManualProvider(page, runtimeConfig, provider) {
  progress(runtimeConfig, 'provider-opening', 'Registrierungsseite wird geoeffnet.');

  await page.goto(provider.registrationUrl, {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  progress(
    runtimeConfig,
    'provider-page-loaded',
    'Registrierungsseite ist geladen.',
    await pageSnapshot(page, runtimeConfig, true),
  );

  const timeoutMs = Math.max(30000, Number(runtimeConfig.observationTimeoutMs || 300000));
  const stopAt = Date.now() + timeoutMs;
  let completion = await detectManualCompletion(page, provider);

  while (!completion.completed && Date.now() < stopAt) {
    await sleep(1500);
    completion = await detectManualCompletion(page, provider);

    progress(
      runtimeConfig,
      'observing',
      'Browserflow wird beobachtet.',
      await pageSnapshot(page, runtimeConfig),
    );
  }

  const snapshot = await pageSnapshot(page, runtimeConfig, true);

  return {
    completed: completion.completed,
    completionReason: completion.reason,
    finalUrl: snapshot.finalUrl,
    title: snapshot.title,
    liveScreenshotPath: snapshot.liveScreenshotPath || null,
    liveScreenshotAt: snapshot.liveScreenshotAt || null,
  };
}

function buildAccountPayload(runtimeConfig, provider, completed) {
  const subject = runtimeConfig.subject || {};
  const desiredEmail = normalizeText(subject.desiredEmail || subject.desired_email);
  const username = normalizeText(subject.accountUsername || subject.account_username || desiredEmail);

  if (!completed || !desiredEmail) {
    return null;
  }

  return {
    email: desiredEmail,
    username: username || desiredEmail,
    provider: provider.label || provider.key || 'Mail Provider',
    webmailUrl: normalizeText(provider.webmailUrl || provider.webmail_url),
    recoveryEmail: normalizeText(subject.recoveryEmail || subject.recovery_email),
  };
}

function writeResult(runtimeConfig, result) {
  const resultPath = normalizeText(runtimeConfig.resultPath);

  if (resultPath) {
    writeJsonFile(resultPath, result);
  }

  console.log(JSON.stringify(result));
}

async function main() {
  if (!runtimeConfigPath) {
    throw new Error('Runtime-Konfigurationspfad fehlt.');
  }

  const runtimeConfig = readJsonFile(runtimeConfigPath, null);

  if (!runtimeConfig || typeof runtimeConfig !== 'object') {
    throw new Error(`Runtime-Konfiguration konnte nicht gelesen werden: ${runtimeConfigPath}`);
  }

  const provider = validateProvider(runtimeConfig);
  runtimeConfig.provider = provider;
  requestedBrowserEngine = resolveBrowserEngine(runtimeConfig);

  progress(runtimeConfig, 'starting', 'Mail-Registrierung wird vorbereitet.', {}, 'starting');

  const launchOptions = {
    headless: runtimeConfig.headlessEnabled === true ? 'new' : false,
    defaultViewport: DEFAULT_VIEWPORT,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      `--window-size=${DEFAULT_VIEWPORT.width},${DEFAULT_VIEWPORT.height}`,
    ],
  };

  if (runtimeConfig.browserProfilePath) {
    launchOptions.userDataDir = runtimeConfig.browserProfilePath;
    ensureDirectory(runtimeConfig.browserProfilePath);
  }

  let browser = null;
  let page = null;

  try {
    const launchResult = await launchConfiguredBrowser({
      puppeteer,
      runtimeConfig,
      launchOptions,
    });

    browser = launchResult.browser;
    activeBrowserEngine = launchResult.activeEngine;
    browserFallbackReason = launchResult.fallbackReason;

    progress(runtimeConfig, 'browser-started', 'Browser wurde gestartet.', {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    });

    page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)));
    await page.setViewport(DEFAULT_VIEWPORT);

    const providerResult = await runObservedManualProvider(page, runtimeConfig, provider);
    const account = buildAccountPayload(runtimeConfig, provider, providerResult.completed);
    const ok = providerResult.completed;
    const result = {
      ok,
      statusLevel: ok ? 'success' : 'partial',
      statusMessage: ok
        ? 'Mail-Registrierung wurde als abgeschlossen erkannt.'
        : 'Beobachtung beendet. Eine automatische Registrierung wurde nicht bestaetigt.',
      runId: runtimeConfig.runId || null,
      providerKey: provider.key || null,
      providerLabel: provider.label || null,
      providerMode: provider.mode,
      registrationCompleted: ok,
      completionReason: providerResult.completionReason,
      account,
      finalUrl: providerResult.finalUrl,
      title: providerResult.title,
      liveScreenshotPath: providerResult.liveScreenshotPath,
      liveScreenshotAt: providerResult.liveScreenshotAt,
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
      finishedAt: new Date().toISOString(),
    };

    progress(
      runtimeConfig,
      ok ? 'completed' : 'observation-ended',
      result.statusMessage,
      providerResult,
      'completed',
    );

    writeResult(runtimeConfig, result);
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

main().catch((error) => {
  const runtimeConfig = runtimeConfigPath ? readJsonFile(runtimeConfigPath, {}) : {};
  const message = normalizeText(error?.message || String(error));
  const result = {
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Mail-Registrierung konnte nicht ausgefuehrt werden.',
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    providerLabel: runtimeConfig.provider?.label || null,
    error: message,
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    finishedAt: new Date().toISOString(),
  };

  try {
    progress(runtimeConfig, 'failed', message, {}, 'failed');
    writeResult(runtimeConfig, result);
  } catch {
    console.log(JSON.stringify(result));
  }

  process.exitCode = 1;
});
