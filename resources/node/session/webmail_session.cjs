const fs = require('fs');
const path = require('path');
const {
  BROWSER_LAUNCHER_SCRIPT_VERSION,
  launchConfiguredBrowser,
  resolveBrowserEngine,
} = require('../register/lib/browser-launcher.cjs');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const SCRIPT_NAME = process.env.WEBMAIL_SESSION_SCRIPT_NAME || 'webmail_session.cjs';
const SCRIPT_VERSION = 1;
const DEFAULT_VIEWPORT = { width: 1365, height: 900 };
const LIVE_PREVIEW_MIN_INTERVAL_MS = 2500;
const DOM_DEBUG_TEXT_LIMIT = 3000;
const DOM_DEBUG_HTML_LIMIT = 6000;
const statusEvents = [];
let lastLivePreviewAt = 0;
let currentStatusPayload = null;
let heartbeatTimer = null;
let heartbeatCounter = 0;

function normalizeText(value) {
  return String(value || '').trim();
}

function readJsonFile(filePath, fallback = {}) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return fallback;
  }
}

function ensureDirectory(directoryPath) {
  if (directoryPath) {
    fs.mkdirSync(directoryPath, { recursive: true });
  }
}

function writeJsonFile(filePath, payload) {
  ensureDirectory(path.dirname(filePath));
  fs.writeFileSync(filePath, JSON.stringify(payload, null, 2), 'utf8');
}

function publicStatusPayload(runtimeConfig, state, stage, message, data = {}) {
  const processIdentity = runtimeConfig.processIdentity || {};
  const heartbeatAt = new Date().toISOString();
  const livePreviewIntervalSeconds = Math.max(
    1,
    Math.round((
      Number(runtimeConfig.livePreviewIntervalMs)
      || (Number(runtimeConfig.livePreviewIntervalSeconds) > 0 ? Number(runtimeConfig.livePreviewIntervalSeconds) * 1000 : 0)
      || LIVE_PREVIEW_MIN_INTERVAL_MS
    ) / 1000),
  );

  return {
    runId: runtimeConfig.runId || null,
    processKey: processIdentity.processKey || runtimeConfig.processKey || null,
    processIdentity: Object.keys(processIdentity).length > 0 ? processIdentity : null,
    providerKey: webmailProviderKey(runtimeConfig),
    state,
    stage,
    message,
    at: heartbeatAt,
    heartbeatAt,
    heartbeatCounter: heartbeatCounter += 1,
    pid: process.pid,
    runtimeConfigPath: process.argv[2] || null,
    scriptName: SCRIPT_NAME,
    scriptVersion: SCRIPT_VERSION,
    scriptVersions: {
      webmailSession: SCRIPT_VERSION,
      browserLauncher: BROWSER_LAUNCHER_SCRIPT_VERSION || 1,
    },
    requestedBrowserEngine: data.requestedBrowserEngine || null,
    activeBrowserEngine: data.activeBrowserEngine || null,
    browserFallbackReason: data.browserFallbackReason || null,
    livePreviewEnabled: runtimeConfig.livePreviewEnabled !== false,
    livePreviewIntervalSeconds,
    livePreviewPollIntervalSeconds: livePreviewIntervalSeconds,
    finalUrl: data.finalUrl || null,
    title: data.title || null,
    debugDom: data.debugDom || null,
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
    liveScreenshotAt: data.liveScreenshotAt || null,
  };

  statusEvents.push(event);

  if (statusEvents.length > 80) {
    statusEvents.shift();
  }

  if (runtimeConfig.statusPath) {
    currentStatusPayload = publicStatusPayload(runtimeConfig, state, stage, message, data);
    writeJsonFile(runtimeConfig.statusPath, currentStatusPayload);
  }
}

function livePreviewIntervalMs(runtimeConfig = {}) {
  return Math.max(
    500,
    Number(runtimeConfig.livePreviewIntervalMs)
      || (Number(runtimeConfig.livePreviewIntervalSeconds) > 0 ? Number(runtimeConfig.livePreviewIntervalSeconds) * 1000 : 0)
      || LIVE_PREVIEW_MIN_INTERVAL_MS,
  );
}

function startProcessHeartbeat(runtimeConfig) {
  const statusPath = normalizeText(runtimeConfig.statusPath);

  if (!statusPath || heartbeatTimer) {
    return;
  }

  const intervalMs = Math.max(5000, Number(runtimeConfig.processHeartbeatIntervalSeconds || 0) * 1000 || livePreviewIntervalMs(runtimeConfig));

  heartbeatTimer = setInterval(() => {
    if (!currentStatusPayload) {
      return;
    }

    const heartbeatAt = new Date().toISOString();
    currentStatusPayload = {
      ...currentStatusPayload,
      at: heartbeatAt,
      heartbeatAt,
      heartbeatCounter: heartbeatCounter += 1,
      pid: process.pid,
    };

    writeJsonFile(statusPath, currentStatusPayload);
  }, intervalMs);

  if (typeof heartbeatTimer.unref === 'function') {
    heartbeatTimer.unref();
  }
}

function stopProcessHeartbeat() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }
}

function writeResult(runtimeConfig, result) {
  if (runtimeConfig.resultPath) {
    writeJsonFile(runtimeConfig.resultPath, result);
  }

  console.log(JSON.stringify(result));
}

async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
  const livePreviewPath = normalizeText(runtimeConfig.livePreviewPath);

  if (!page || !livePreviewPath || runtimeConfig.livePreviewEnabled === false) {
    return {};
  }

  const now = Date.now();
  const intervalMs = livePreviewIntervalMs(runtimeConfig);

  if (!force && now - lastLivePreviewAt < intervalMs) {
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

async function frameDomDebug(frame) {
  return frame.evaluate((limits) => {
    const compactText = (value = '', limit = 1000) => {
      const text = String(value || '').replace(/\s+/g, ' ').trim();

      return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
    };

    const attrs = (element, names) => Object.fromEntries(
      names
        .map((name) => [name, element.getAttribute(name)])
        .filter(([, value]) => value !== null && value !== ''),
    );

    const summary = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const haystack = [
        element.getAttribute('type'),
        element.getAttribute('name'),
        element.getAttribute('id'),
        element.getAttribute('autocomplete'),
        element.getAttribute('placeholder'),
        element.getAttribute('aria-label'),
      ].join(' ').toLowerCase();
      const secret = haystack.includes('password');
      const text = secret && element.value
        ? '[redacted]'
        : (element.innerText || element.textContent || element.value || '');

      return {
        tag: element.tagName.toLowerCase(),
        text: compactText(text, 220),
        visible: rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none',
        disabled: Boolean(element.disabled),
        attrs: attrs(element, [
          'id',
          'name',
          'type',
          'class',
          'autocomplete',
          'placeholder',
          'aria-label',
          'data-testid',
          'data-id',
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
      inputs: Array.from(document.querySelectorAll('input, textarea, select')).slice(0, 35).map(summary),
      buttons: Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"], a')).slice(0, 45).map(summary),
      iframes: Array.from(document.querySelectorAll('iframe')).slice(0, 12).map((iframe) => ({
        src: iframe.src || '',
        title: iframe.title || '',
        id: iframe.id || '',
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
  if (!page) {
    return null;
  }

  const frames = [];

  for (const frame of page.frames()) {
    try {
      frames.push({
        name: frame.name() || '',
        url: frame.url(),
        dom: await frameDomDebug(frame),
      });
    } catch (error) {
      frames.push({
        name: frame.name() || '',
        url: frame.url(),
        error: normalizeText(error?.message || String(error)),
      });
    }
  }

  return {
    capturedAt: new Date().toISOString(),
    frames,
  };
}

async function pageProgressData(page, runtimeConfig, livePreview = {}, browserData = {}) {
  return {
    ...livePreview,
    ...browserData,
    finalUrl: page?.url?.() || null,
    title: page ? await page.title().catch(() => '') : null,
    debugDom: runtimeConfig.domDebugEnabled === false ? null : await domDebugSnapshot(page),
  };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

function sameOriginUrl(value) {
  try {
    return new URL(value).origin;
  } catch {
    return '';
  }
}

function isWebmailPortalReached(state, providerKey) {
  const url = normalizeText(state?.url).toLowerCase();
  const title = normalizeText(state?.title).toLowerCase();
  const text = normalizeText(state?.text).toLowerCase();

  if (providerKey === 'gmx') {
    return url.includes('bap.navigator.gmx.net/mail')
      || (title.includes('gmx freemail') && text.includes('e-mail') && text.includes('logout'));
  }

  if (providerKey === 'proton') {
    return url.includes('mail.proton.me') && !/sign in|login|anmelden/.test(text);
  }

  return false;
}

async function collectSessionCookies(page, browser, urls = []) {
  const normalizedUrls = urls.map(sameOriginUrl).filter(Boolean);
  const cookieMap = new Map();

  const addCookies = (cookies = []) => {
    for (const cookie of cookies) {
      const key = [
        cookie.name,
        cookie.domain,
        cookie.path,
      ].join('|');
      cookieMap.set(key, cookie);
    }
  };

  for (const url of normalizedUrls) {
    addCookies(await page.cookies(url).catch(() => []));
  }

  addCookies(await page.cookies().catch(() => []));

  const context = browser?.defaultBrowserContext?.();

  if (context && typeof context.cookies === 'function') {
    addCookies(await context.cookies(...normalizedUrls).catch(() => []));
  }

  return Array.from(cookieMap.values());
}

async function fillFirstMatchingInput(page, selectors, value, delay = 35) {
  for (const selector of selectors) {
    const input = await page.$(selector).catch(() => null);

    if (!input) {
      continue;
    }

    const usable = await input.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && !element.readOnly;
    }).catch(() => false);

    if (!usable) {
      continue;
    }

    await input.click({ clickCount: 3 }).catch(() => {});
    await input.type(value, { delay }).catch(async () => {
      await input.evaluate((element, nextValue) => {
        element.value = nextValue;
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }, value);
    });

    return true;
  }

  return false;
}

async function clickSubmit(page) {
  return page.evaluate(() => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const candidates = Array.from(document.querySelectorAll('button, input[type="submit"], [role="button"]'));
    const target = candidates.find((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const text = normalize([
        element.innerText,
        element.textContent,
        element.value,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
      ].join(' '));

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && (
          element.getAttribute('type') === 'submit'
          || /(login|log in|sign in|anmelden|einloggen|weiter|continue|next)/.test(text)
        );
    });

    if (!target) {
      return false;
    }

    target.click();

    return true;
  }).catch(() => false);
}

function webmailProviderKey(runtimeConfig) {
  const provider = normalizeText(process.env.WEBMAIL_SESSION_PROVIDER || runtimeConfig.provider).toLowerCase();

  if (provider.includes('gmx')) {
    return 'gmx';
  }

  if (provider.includes('proton')) {
    return 'proton';
  }

  return provider || 'proton';
}

async function clickByText(page, patterns, selector = 'button, [role="button"], a, input[type="submit"]') {
  return page.evaluate((patternValues, targetSelector) => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const patterns = patternValues.map((pattern) => new RegExp(pattern, 'i'));
    const candidates = Array.from(document.querySelectorAll(targetSelector));
    const target = candidates.find((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const text = normalize([
        element.innerText,
        element.textContent,
        element.value,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
        element.getAttribute('data-testid'),
      ].join(' '));

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && patterns.some((pattern) => pattern.test(text));
    });

    if (!target) {
      return false;
    }

    target.click();

    return true;
  }, patterns, selector).catch(() => false);
}

async function clickSelectorInPageOrFrames(page, selector, timeoutMs = 5000) {
  const stopAt = Date.now() + Math.max(500, Number(timeoutMs) || 5000);

  while (Date.now() < stopAt) {
    const frames = page.frames();

    for (const frame of frames) {
      const clicked = await frame.evaluate((targetSelector) => {
        const element = document.querySelector(targetSelector);

        if (!element) {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        if (rect.width <= 0 || rect.height <= 0 || style.visibility === 'hidden' || style.display === 'none' || element.disabled) {
          return false;
        }

        element.scrollIntoView({ block: 'center', inline: 'center' });
        element.focus?.({ preventScroll: true });
        element.click();

        return true;
      }, selector).catch(() => false);

      if (clicked) {
        await sleep(700);

        return true;
      }
    }

    await sleep(250);
  }

  return false;
}

async function prepareGmxLogin(page) {
  const diagnostics = {
    cookieAccepted: false,
    avatarClicked: false,
    dropdownLoginClicked: false,
  };

  diagnostics.cookieAccepted = await clickSelectorInPageOrFrames(page, '#save-all-pur', 10000);

  if (!diagnostics.cookieAccepted) {
    diagnostics.cookieAccepted = await clickByText(page, [
      'akzeptieren und weiter',
      'accept',
      'akzeptieren',
      'zustimmen',
      'alle akzeptieren',
      'einverstanden',
    ], '#save-all-pur, button, [role="button"], a').catch(() => false);
  }

  await sleep(1000);
  diagnostics.avatarClicked = await clickSelectorInPageOrFrames(page, 'account-avatar[role="button"], account-avatar, a[aria-label="Login"]', 8000);
  await sleep(1000);
  diagnostics.dropdownLoginClicked = await clickSelectorInPageOrFrames(
    page,
    'button.account-avatar__button, .account-avatar__button, button[data-component="button"][data-importance="primary"][data-size="l"][data-type="text"]',
    8000,
  );

  if (!diagnostics.dropdownLoginClicked) {
    diagnostics.dropdownLoginClicked = await clickByText(page, [
      '^login$',
      'log in',
      'einloggen',
      'anmelden',
    ], 'button.account-avatar__button, button[data-component="button"], button, [role="button"], a').catch(() => false);
  }

  await sleep(1000);

  return diagnostics;
}

async function attemptWebmailLogin(page, runtimeConfig) {
  const username = normalizeText(runtimeConfig.username || runtimeConfig.email);
  const password = normalizeText(runtimeConfig.password);
  const typingDelay = Math.max(0, Number(runtimeConfig.typingDelayMs || 35));
  const providerKey = webmailProviderKey(runtimeConfig);
  const diagnostics = {
    attempted: false,
    providerKey,
    usernameFilled: false,
    passwordFilled: false,
    submitted: false,
  };

  if (!username || !password) {
    return diagnostics;
  }

  diagnostics.attempted = true;

  if (providerKey === 'gmx') {
    diagnostics.gmxStart = await prepareGmxLogin(page);
  }

  diagnostics.usernameFilled = await fillFirstMatchingInput(page, [
    ...(providerKey === 'gmx' ? [
      '#login-email',
      'input[name="username"]',
      'input[name="login"]',
      'input[data-testid*="email" i]',
      'input[data-testid*="login" i]',
    ] : []),
    'input[type="email"]',
    'input[name*="user" i]',
    'input[id*="user" i]',
    'input[name*="email" i]',
    'input[id*="email" i]',
    'input[name*="login" i]',
    'input[id*="login" i]',
    'input[autocomplete="username"]',
    'input[type="text"]',
  ], username, typingDelay);

  if (diagnostics.usernameFilled) {
    await sleep(500);
    await clickSubmit(page);
    await sleep(1000);
  }

  diagnostics.passwordFilled = await fillFirstMatchingInput(page, [
    ...(providerKey === 'gmx' ? [
      '#login-password',
      'input[name="password"]',
      'input[data-testid*="password" i]',
    ] : []),
    'input[type="password"]',
    'input[name*="pass" i]',
    'input[id*="pass" i]',
    'input[autocomplete="current-password"]',
  ], password, typingDelay);

  if (diagnostics.passwordFilled) {
    diagnostics.submitted = providerKey === 'gmx'
      ? await clickByText(page, [
        'login',
        'log in',
        'einloggen',
        'anmelden',
        'weiter',
      ], '#login-submit, button, input[type="submit"], [role="button"]')
      : await clickSubmit(page);
  }

  return diagnostics;
}

async function captureStorage(page) {
  return page.evaluate(() => ({
    localStorage: (() => {
      const values = {};

      for (let index = 0; index < window.localStorage.length; index += 1) {
        const key = window.localStorage.key(index);
        values[key] = window.localStorage.getItem(key);
      }

      return values;
    })(),
    sessionStorage: (() => {
      const values = {};

      for (let index = 0; index < window.sessionStorage.length; index += 1) {
        const key = window.sessionStorage.key(index);
        values[key] = window.sessionStorage.getItem(key);
      }

      return values;
    })(),
    url: window.location.href,
    title: document.title || '',
    text: (document.body?.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 1200),
  })).catch(() => ({
    localStorage: {},
    sessionStorage: {},
    url: page.url(),
    title: '',
    text: '',
  }));
}

async function main() {
  const runtimeConfigPath = process.argv[2] || '';
  const runtimeConfig = readJsonFile(runtimeConfigPath, {});
  const webmailUrl = normalizeText(runtimeConfig.webmailUrl);
  const sessionFilePath = normalizeText(runtimeConfig.sessionFilePath);
  const providerKey = webmailProviderKey(runtimeConfig);
  const observationTimeoutMs = Math.max(30000, Number(runtimeConfig.observationTimeoutMs || 300000));
  const notes = [];
  const warnings = [];
  const requestedBrowserEngine = resolveBrowserEngine(runtimeConfig);
  let browser = null;
  let activeBrowserEngine = null;
  let browserFallbackReason = null;

  startProcessHeartbeat(runtimeConfig);

  progress(runtimeConfig, 'starting', 'Webmail-Sessionlauf wird vorbereitet.', {
    requestedBrowserEngine,
  }, 'starting');

  if (!webmailUrl || !/^https?:\/\//i.test(webmailUrl)) {
    throw new Error('Gueltige Webmail-URL fehlt.');
  }

  if (!sessionFilePath) {
    throw new Error('Session-Dateipfad fehlt.');
  }

  try {
    const launchOptions = {
      headless: runtimeConfig.headlessEnabled === true ? 'new' : false,
      defaultViewport: DEFAULT_VIEWPORT,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-features=IsolateOrigins,site-per-process,Translate,BackForwardCache',
        '--disable-site-isolation-trials',
        '--process-per-site',
        '--renderer-process-limit=2',
        `--window-size=${DEFAULT_VIEWPORT.width},${DEFAULT_VIEWPORT.height}`,
      ],
    };

    if (runtimeConfig.browserProfilePath) {
      launchOptions.userDataDir = runtimeConfig.browserProfilePath;
      ensureDirectory(runtimeConfig.browserProfilePath);
    }

    const launchResult = await launchConfiguredBrowser({
      puppeteer,
      runtimeConfig,
      launchOptions,
    });

    browser = launchResult.browser;
    activeBrowserEngine = launchResult.activeEngine;
    browserFallbackReason = launchResult.fallbackReason;

    progress(runtimeConfig, 'browser-started', 'Browser fuer Webmail-Session wurde gestartet.', {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    });

    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)));
    await page.setViewport(DEFAULT_VIEWPORT);
    await page.goto(webmailUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
    });
    let livePreview = await captureLivePreviewScreenshot(page, runtimeConfig, true);
    progress(runtimeConfig, 'webmail-opened', `Webmail-Portal wurde geoeffnet: ${webmailUrl}`, await pageProgressData(page, runtimeConfig, livePreview, {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    }));

    const loginDiagnostics = await attemptWebmailLogin(page, runtimeConfig);
    livePreview = await captureLivePreviewScreenshot(page, runtimeConfig, true);
    progress(runtimeConfig, 'webmail-login-attempted', 'Webmail-Login wurde versucht.', await pageProgressData(page, runtimeConfig, livePreview, {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    }));
    notes.push(loginDiagnostics.attempted
      ? 'Webmail-Login wurde mit hinterlegten Zugangsdaten versucht. Falls ein zweiter Faktor oder Provider-Zwischenschritt erscheint, bitte manuell abschliessen.'
      : 'Keine vollstaendigen Webmail-Zugangsdaten vorhanden. Bitte Login im sichtbaren Browser manuell abschliessen.');

    await sleep(Math.max(1500, Number(runtimeConfig.postLoginWaitMs || 2500)));
    const stopAt = Date.now() + observationTimeoutMs;

    while (Date.now() < stopAt) {
      livePreview = await captureLivePreviewScreenshot(page, runtimeConfig);
      progress(runtimeConfig, 'webmail-observing', 'Webmail-Session wird beobachtet.', await pageProgressData(page, runtimeConfig, livePreview, {
        requestedBrowserEngine,
        activeBrowserEngine,
        browserFallbackReason,
      }));
      await sleep(1000);
    }

    livePreview = await captureLivePreviewScreenshot(page, runtimeConfig, true);

    const origin = sameOriginUrl(page.url()) || sameOriginUrl(webmailUrl);
    const storage = await captureStorage(page);
    const portalReached = isWebmailPortalReached(storage, providerKey);
    const cookies = await collectSessionCookies(page, browser, [
      webmailUrl,
      page.url(),
      storage.url,
      ...(providerKey === 'gmx' ? ['https://www.gmx.net', 'https://bap.navigator.gmx.net'] : []),
      ...(providerKey === 'proton' ? ['https://mail.proton.me', 'https://account.proton.me'] : []),
    ]);
    const sessionPayload = {
      capturedAt: new Date().toISOString(),
      provider: providerKey,
      email: normalizeText(runtimeConfig.email),
      username: normalizeText(runtimeConfig.username || runtimeConfig.email),
      webmailUrl,
      finalUrl: page.url(),
      origin,
      portalReached,
      cookies,
      storage,
    };

    if (cookies.length === 0 && Object.keys(storage.localStorage || {}).length === 0 && Object.keys(storage.sessionStorage || {}).length === 0) {
      warnings.push('Es wurden keine Cookies oder Browser-Storage-Daten erkannt.');
    }

    if (portalReached) {
      notes.push('Webmail-Portal wurde erreicht; Session wird fuer spaetere Mail-Abrufe gespeichert.');
    }

    writeJsonFile(sessionFilePath, sessionPayload);

    const hasSessionMaterial = cookies.length > 0 || Object.keys(storage.localStorage || {}).length > 0 || Object.keys(storage.sessionStorage || {}).length > 0;
    const result = {
      ok: hasSessionMaterial || portalReached,
      portalReached,
      statusMessage: portalReached
        ? 'Webmail-Portal wurde erreicht; Sessiondaten wurden gespeichert.'
        : 'Webmail-Sessiondaten wurden gespeichert.',
      runId: runtimeConfig.runId || null,
      scriptName: SCRIPT_NAME,
      scriptVersion: SCRIPT_VERSION,
      scriptVersions: {
        webmailSession: SCRIPT_VERSION,
        browserLauncher: BROWSER_LAUNCHER_SCRIPT_VERSION || 1,
      },
      providerKey,
      sessionFilePath,
      finalUrl: page.url(),
      title: await page.title().catch(() => ''),
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
      liveScreenshotPath: livePreview.liveScreenshotPath || runtimeConfig.livePreviewPath || null,
      liveScreenshotAt: livePreview.liveScreenshotAt || null,
      cookieCount: cookies.length,
      loginDiagnostics,
      notes,
      warnings,
      finishedAt: new Date().toISOString(),
    };

    progress(runtimeConfig, result.ok ? 'completed' : 'completed-with-warnings', result.statusMessage, await pageProgressData(page, runtimeConfig, livePreview, {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    }), 'completed');

    writeResult(runtimeConfig, result);
  } finally {
    stopProcessHeartbeat();
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

main().catch((error) => {
  const runtimeConfig = process.argv[2] ? readJsonFile(process.argv[2], {}) : {};
  const result = {
    ok: false,
    statusMessage: 'Webmail-Session-Skript ist fehlgeschlagen.',
    runId: runtimeConfig.runId || null,
    scriptName: SCRIPT_NAME,
    scriptVersion: SCRIPT_VERSION,
    scriptVersions: {
      webmailSession: SCRIPT_VERSION,
      browserLauncher: BROWSER_LAUNCHER_SCRIPT_VERSION || 1,
    },
    warnings: [normalizeText(error?.message || String(error))],
    notes: [],
    finishedAt: new Date().toISOString(),
  };

  try {
    progress(runtimeConfig, 'failed', normalizeText(error?.message || String(error)), {}, 'failed');
    writeResult(runtimeConfig, result);
  } catch {
    console.log(JSON.stringify(result));
  }

  process.exitCode = 1;
});
