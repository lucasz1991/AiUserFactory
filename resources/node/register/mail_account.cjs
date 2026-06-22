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
const PROVIDER_MODE_OBSERVED_MANUAL = 'observed_manual';
const PROVIDER_MODE_PROTON_USERNAME_CHECK = 'proton_username_check';
const DOM_DEBUG_TEXT_LIMIT = 3000;
const DOM_DEBUG_HTML_LIMIT = 6000;

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
    debugDom: data.debugDom || null,
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

function truncateText(value, limit) {
  const text = normalizeText(value);

  return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
}

async function frameDomDebug(frame) {
  return frame.evaluate((limits) => {
    const compactText = (value = '', limit = 1000) => {
      const text = String(value || '').replace(/\s+/g, ' ').trim();

      return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
    };

    const attributes = (element, names) => Object.fromEntries(
      names
        .map((name) => [name, element.getAttribute(name)])
        .filter(([, value]) => value !== null && value !== ''),
    );

    const elementSummary = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return {
        tag: element.tagName.toLowerCase(),
        text: compactText(element.innerText || element.textContent || element.value || '', 220),
        visible: rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none',
        disabled: Boolean(element.disabled),
        attrs: attributes(element, [
          'id',
          'name',
          'type',
          'class',
          'autocomplete',
          'placeholder',
          'aria-label',
          'data-testid',
          'role',
          'title',
        ]),
      };
    };

    const main = document.querySelector('main') || document.body;

    return {
      url: window.location.href,
      title: document.title || '',
      text: compactText(document.body?.innerText || '', limits.text),
      forms: Array.from(document.querySelectorAll('form')).slice(0, 5).map((form) => ({
        attrs: attributes(form, ['id', 'name', 'method', 'action', 'class']),
        inputs: Array.from(form.querySelectorAll('input, textarea, select')).slice(0, 20).map(elementSummary),
        buttons: Array.from(form.querySelectorAll('button, [role="button"], input[type="submit"]')).slice(0, 12).map(elementSummary),
      })),
      inputs: Array.from(document.querySelectorAll('input, textarea, select')).slice(0, 30).map(elementSummary),
      buttons: Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"]')).slice(0, 30).map(elementSummary),
      iframes: Array.from(document.querySelectorAll('iframe')).slice(0, 12).map((iframe) => ({
        src: iframe.src || '',
        title: iframe.title || '',
        class: iframe.className || '',
      })),
      html: compactText(main?.outerHTML || document.body?.innerHTML || '', limits.html),
    };
  }, {
    text: DOM_DEBUG_TEXT_LIMIT,
    html: DOM_DEBUG_HTML_LIMIT,
  });
}

async function domDebugSnapshot(page) {
  const frames = [];

  for (const frame of page.frames()) {
    try {
      frames.push({
        name: frame.name() || '',
        ...await frameDomDebug(frame),
      });
    } catch (error) {
      frames.push({
        name: frame.name() || '',
        url: frame.url(),
        error: truncateText(error?.message || String(error), 500),
      });
    }
  }

  return {
    capturedAt: new Date().toISOString(),
    frameCount: frames.length,
    frames: frames.slice(0, 8),
  };
}

async function pageSnapshot(page, runtimeConfig, force = false, includeDom = false) {
  const [title, finalUrl, screenshot, debugDom] = await Promise.all([
    page.title().catch(() => null),
    Promise.resolve(page.url()).catch(() => null),
    captureLivePreviewScreenshot(page, runtimeConfig, force),
    includeDom ? domDebugSnapshot(page).catch((error) => ({
      error: truncateText(error?.message || String(error), 800),
    })) : Promise.resolve(null),
  ]);

  return {
    title,
    finalUrl,
    debugDom,
    ...screenshot,
  };
}

function validateProvider(runtimeConfig) {
  const provider = runtimeConfig.provider || {};
  let mode = normalizeText(provider.mode || PROVIDER_MODE_OBSERVED_MANUAL);
  const rawRegistrationUrl = normalizeText(provider.registrationUrl || provider.registration_url);

  if (mode === 'proton') {
    mode = PROVIDER_MODE_PROTON_USERNAME_CHECK;
  }

  if (mode === PROVIDER_MODE_OBSERVED_MANUAL && /(^|\/\/|\.)(proton\.me|account\.proton\.me|account-api\.proton\.me)/i.test(rawRegistrationUrl)) {
    mode = PROVIDER_MODE_PROTON_USERNAME_CHECK;
  }

  if (![PROVIDER_MODE_OBSERVED_MANUAL, PROVIDER_MODE_PROTON_USERNAME_CHECK].includes(mode)) {
    throw new Error(`Provider-Adapter "${mode}" ist noch nicht implementiert.`);
  }

  const registrationUrl = rawRegistrationUrl
    || (mode === PROVIDER_MODE_PROTON_USERNAME_CHECK ? 'https://account.proton.me/mail/signup' : '');

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

function usernameFromSubject(runtimeConfig) {
  const subject = runtimeConfig.subject || {};
  const accountUsername = normalizeText(subject.accountUsername || subject.account_username);
  const desiredEmail = normalizeText(subject.desiredEmail || subject.desired_email);
  const source = accountUsername || desiredEmail;
  const localPart = source.includes('@') ? source.split('@')[0] : source;

  return localPart
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .replace(/[._-]{2,}/g, '-')
    .slice(0, 64);
}

async function findVisibleInput(pageOrFrame, selectors) {
  for (const selector of selectors) {
    const handle = await pageOrFrame.$(selector).catch(() => null);

    if (!handle) {
      continue;
    }

    const visible = await handle.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    }).catch(() => false);

    if (visible) {
      return handle;
    }
  }

  return pageOrFrame.evaluateHandle(() => {
    const inputs = Array.from(document.querySelectorAll('input'));

    return inputs.find((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);
      const haystack = [
        input.name,
        input.id,
        input.autocomplete,
        input.placeholder,
        input.getAttribute('aria-label'),
        input.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !input.disabled
        && /(username|email|mail|text)/i.test(haystack);
    }) || null;
  }).then((handle) => handle.asElement()).catch(() => null);
}

async function findVisibleInputIncludingFrames(page, selectors) {
  const mainFrameInput = await findVisibleInput(page, selectors);

  if (mainFrameInput) {
    return mainFrameInput;
  }

  for (const frame of page.frames()) {
    const frameUrl = normalizeText(frame.url());

    if (frame === page.mainFrame() || !/proton|challenge|account-api/i.test(frameUrl)) {
      continue;
    }

    const frameInput = await findVisibleInput(frame, selectors);

    if (frameInput) {
      return frameInput;
    }
  }

  return null;
}

async function waitForVisibleInputIncludingFrames(page, selectors, timeoutMs = 20000) {
  const stopAt = Date.now() + timeoutMs;
  let input = await findVisibleInputIncludingFrames(page, selectors);

  while (!input && Date.now() < stopAt) {
    await sleep(500);
    input = await findVisibleInputIncludingFrames(page, selectors);
  }

  return input;
}

async function fillInputValue(inputHandle, value) {
  await inputHandle.evaluate((element, nextValue) => {
    element.focus();

    const prototype = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

    if (descriptor?.set) {
      descriptor.set.call(element, nextValue);
    } else {
      element.value = nextValue;
    }

    if (typeof InputEvent === 'function') {
      element.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'insertText',
        data: nextValue,
      }));
    } else {
      element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    element.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);

  return inputHandle.evaluate((element) => element.value || '');
}

async function clickFirstMatchingButton(page, labels) {
  const clicked = await page.evaluate((buttonLabels) => {
    const normalizedLabels = buttonLabels.map((label) => String(label).toLowerCase());
    const candidates = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"]'));
    const button = candidates.find((candidate) => {
      const rect = candidate.getBoundingClientRect();
      const style = window.getComputedStyle(candidate);
      const text = [
        candidate.innerText,
        candidate.textContent,
        candidate.value,
        candidate.getAttribute('aria-label'),
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !candidate.disabled
        && normalizedLabels.some((label) => text.includes(label));
    });

    if (!button) {
      return false;
    }

    button.click();

    return true;
  }, labels);

  if (clicked) {
    return true;
  }

  const submitted = await page.evaluate(() => {
    const form = document.querySelector('form[name="accountForm"], form');

    if (!form) {
      return false;
    }

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }

    return true;
  }).catch(() => false);

  if (submitted) {
    return true;
  }

  await page.keyboard.press('Enter').catch(() => {});

  return false;
}

async function protonUsernameStatus(page) {
  return page.evaluate(() => {
    const text = document.body?.innerText || '';
    const normalized = text.toLowerCase();
    const url = window.location.href;
    const passwordInput = document.querySelector('input[type="password"], input[name*="password" i], input[id*="password" i]');
    const invalidPatterns = [
      'already taken',
      'username is not available',
      'not available',
      'unavailable',
      'already exists',
      'invalid username',
      'choose another',
      'please try another',
      'ist nicht verf',
      'bereits vergeben',
      'nicht verfügbar',
    ];

    if (invalidPatterns.some((pattern) => normalized.includes(pattern))) {
      return {
        available: false,
        reason: 'username-unavailable',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (
      passwordInput
      || /create (a )?password|set (a )?password|choose (a )?password|human verification|captcha|verify you.?re human/.test(normalized)
    ) {
      return {
        available: true,
        reason: 'advanced-to-next-step',
        message: text.slice(0, 1200),
        url,
      };
    }

    return {
      available: null,
      reason: 'pending',
      message: text.slice(0, 1200),
      url,
    };
  });
}

async function runProtonUsernameCheckProvider(page, runtimeConfig, provider) {
  const username = usernameFromSubject(runtimeConfig);

  if (!username) {
    throw new Error('Fuer Proton wird ein Username oder eine gewuenschte E-Mail-Adresse benoetigt.');
  }

  progress(runtimeConfig, 'proton-opening', 'Proton-Registrierungsseite wird geoeffnet.');

  await page.goto(provider.registrationUrl, {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  progress(
    runtimeConfig,
    'proton-page-loaded',
    'Proton-Startseite ist geladen.',
    await pageSnapshot(page, runtimeConfig, true, true),
  );

  const usernameInputSelectors = [
    'input[name="username"]',
    'input[id="username"]',
    'input[name*="username" i]',
    'input[id*="username" i]',
    'input[data-testid="input-input-element"]',
    'input[autocomplete="username"]',
    'input[autocomplete="off"]',
    'input[type="email"]',
    'input[type="text"]',
  ];
  const usernameInput = await waitForVisibleInputIncludingFrames(page, usernameInputSelectors, 20000);

  if (!usernameInput) {
    progress(
      runtimeConfig,
      'proton-username-input-not-found',
      'Proton-Username-Feld wurde nicht gefunden.',
      await pageSnapshot(page, runtimeConfig, true, true),
      'failed',
    );

    throw new Error('Proton-Username-Feld wurde nicht gefunden.');
  }

  const enteredUsername = await fillInputValue(usernameInput, username);

  progress(
    runtimeConfig,
    'proton-username-entered',
    `Username "${username}" wurde eingetragen. Feldwert: "${enteredUsername}".`,
    await pageSnapshot(page, runtimeConfig, true, true),
  );

  await clickFirstMatchingButton(page, [
    'create free account',
    'create account',
    'kostenloses konto',
    'konto jetzt erstellen',
    'kostenloses konto jetzt erstellen',
    'continue',
    'weiter',
    'next',
    'free account',
  ]);

  const stopAt = Date.now() + Math.max(10000, Number(runtimeConfig.protonUsernameCheckTimeoutMs || 30000));
  let status = await protonUsernameStatus(page);

  while (status.available === null && Date.now() < stopAt) {
    await sleep(1000);
    status = await protonUsernameStatus(page);

    progress(
      runtimeConfig,
      'proton-checking-username',
      'Proton prueft den Username.',
      await pageSnapshot(page, runtimeConfig, false, true),
    );
  }

  const snapshot = await pageSnapshot(page, runtimeConfig, true, true);

  return {
    completed: status.available === true,
    completionReason: status.reason,
    username,
    email: `${username}@proton.me`,
    usernameAvailable: status.available === true,
    finalUrl: snapshot.finalUrl,
    title: snapshot.title,
    liveScreenshotPath: snapshot.liveScreenshotPath || null,
    liveScreenshotAt: snapshot.liveScreenshotAt || null,
    statusMessage: status.available === true
      ? 'Proton-Username ist verfuegbar; naechster Registrierungsschritt wurde erreicht.'
      : 'Proton-Username ist nicht verfuegbar oder konnte nicht bestaetigt werden.',
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
  const providerMode = normalizeText(provider.mode);

  if (!completed && providerMode !== PROVIDER_MODE_PROTON_USERNAME_CHECK) {
    return null;
  }

  if (providerMode === PROVIDER_MODE_PROTON_USERNAME_CHECK) {
    const protonUsername = usernameFromSubject(runtimeConfig);

    if (!completed || !protonUsername) {
      return null;
    }

    return {
      email: `${protonUsername}@proton.me`,
      username: protonUsername,
      provider: provider.label || 'Proton',
      webmailUrl: normalizeText(provider.webmailUrl || provider.webmail_url || 'https://mail.proton.me'),
      recoveryEmail: normalizeText(subject.recoveryEmail || subject.recovery_email),
    };
  }

  if (!desiredEmail) {
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

    const providerResult = provider.mode === PROVIDER_MODE_PROTON_USERNAME_CHECK
      ? await runProtonUsernameCheckProvider(page, runtimeConfig, provider)
      : await runObservedManualProvider(page, runtimeConfig, provider);
    const account = buildAccountPayload(runtimeConfig, provider, providerResult.completed);
    const ok = providerResult.completed;
    const result = {
      ok,
      statusLevel: ok ? 'success' : 'partial',
      statusMessage: ok
        ? (providerResult.statusMessage || 'Mail-Registrierung wurde als abgeschlossen erkannt.')
        : (providerResult.statusMessage || 'Beobachtung beendet. Eine automatische Registrierung wurde nicht bestaetigt.'),
      runId: runtimeConfig.runId || null,
      providerKey: provider.key || null,
      providerLabel: provider.label || null,
      providerMode: provider.mode,
      registrationCompleted: ok,
      completionReason: providerResult.completionReason,
      usernameAvailable: providerResult.usernameAvailable ?? null,
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
