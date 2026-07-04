const fs = require('fs');
const path = require('path');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const SCRIPT_NAME = 'scrape-instagram.cjs';
const SCRIPT_VERSION = 1;
const DEFAULT_VIEWPORT = { width: 1365, height: 900 };

function normalizeText(value) {
  return String(value || '').trim();
}

function chromiumSandboxArgs(runtimeConfig = {}) {
  const configured = runtimeConfig.chromiumNoSandbox
    ?? runtimeConfig.chromium_no_sandbox
    ?? runtimeConfig.disableChromiumSandbox
    ?? runtimeConfig.disable_chromium_sandbox;

  return configured === true || configured === 1 || configured === 'true' || configured === '1'
    ? ['--no-sandbox', '--disable-setuid-sandbox']
    : [];
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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

async function fillInput(page, selector, value, delay = 35) {
  const input = await page.$(selector).catch(() => null);

  if (!input) {
    return false;
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

async function clickFirstVisible(page, selectors) {
  for (const selector of selectors) {
    const clicked = await page.evaluate((candidateSelector) => {
      const element = Array.from(document.querySelectorAll(candidateSelector)).find((candidate) => {
        const rect = candidate.getBoundingClientRect();
        const style = window.getComputedStyle(candidate);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && !candidate.disabled;
      });

      if (!element) {
        return false;
      }

      element.click();

      return true;
    }, selector).catch(() => false);

    if (clicked) {
      return true;
    }
  }

  return false;
}

async function instagramSessionCookie(page) {
  const cookies = await page.cookies('https://www.instagram.com').catch(() => []);

  return cookies.find((cookie) => cookie.name === 'sessionid') || null;
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
  })).catch(() => ({
    localStorage: {},
    sessionStorage: {},
    url: page.url(),
    title: '',
  }));
}

async function waitForInstagramSession(page, timeoutMs) {
  const stopAt = Date.now() + Math.max(30000, Number(timeoutMs) || 300000);
  let sessionCookie = await instagramSessionCookie(page);

  while (!sessionCookie && Date.now() < stopAt) {
    await sleep(2000);
    sessionCookie = await instagramSessionCookie(page);
  }

  return sessionCookie;
}

async function attemptInstagramLogin(page, runtimeConfig) {
  const username = normalizeText(runtimeConfig.loginUsername);
  const password = normalizeText(runtimeConfig.loginPassword);
  const typingDelay = Math.max(0, Number(runtimeConfig.typingDelayMs || 35));
  const diagnostics = {
    attempted: false,
    formDetected: false,
    submitted: false,
    success: false,
    sessionCookiePresent: false,
  };

  if (!runtimeConfig.autoLoginEnabled || !username || !password) {
    return diagnostics;
  }

  diagnostics.attempted = true;
  await page.goto('https://www.instagram.com/accounts/login/', {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  await page.waitForSelector('input[name="username"], input[aria-label*="username" i]', {
    visible: true,
    timeout: 20000,
  }).catch(() => null);

  diagnostics.formDetected = Boolean(await page.$('input[name="username"], input[aria-label*="username" i]').catch(() => null));

  if (!diagnostics.formDetected) {
    return diagnostics;
  }

  await fillInput(page, 'input[name="username"], input[aria-label*="username" i]', username, typingDelay);
  await fillInput(page, 'input[name="password"], input[type="password"]', password, typingDelay);
  diagnostics.submitted = await clickFirstVisible(page, [
    'button[type="submit"]',
    'div[role="button"]',
  ]);

  await sleep(Math.max(1500, Number(runtimeConfig.postLoginWaitMs || 2500)));
  const sessionCookie = await waitForInstagramSession(page, Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000) / 2));
  diagnostics.sessionCookiePresent = Boolean(sessionCookie);
  diagnostics.success = diagnostics.sessionCookiePresent;

  return diagnostics;
}

async function main() {
  const hasPlaceholderArgument = normalizeText(process.argv[2]) === '';
  const runtimeConfigPath = hasPlaceholderArgument
    ? normalizeText(process.argv[3])
    : normalizeText(process.argv[2]);
  const mode = normalizeText(hasPlaceholderArgument ? process.argv[4] : process.argv[3]) || 'login-session';
  const runtimeConfig = readJsonFile(runtimeConfigPath, {});
  const cookieFilePath = normalizeText(runtimeConfig.cookieFilePath);
  const notes = [];
  const warnings = [];
  let browser = null;
  let page = null;
  let loginDiagnostics = {
    attempted: false,
    formDetected: false,
    submitted: false,
    success: false,
    sessionCookiePresent: false,
  };

  if (!runtimeConfigPath || !cookieFilePath) {
    throw new Error('Runtime-Konfiguration oder Cookie-Dateipfad fehlt.');
  }

  try {
    browser = await puppeteer.launch({
      headless: runtimeConfig.headlessEnabled === true ? 'new' : false,
      defaultViewport: DEFAULT_VIEWPORT,
      args: [
        ...chromiumSandboxArgs(runtimeConfig),
        `--window-size=${DEFAULT_VIEWPORT.width},${DEFAULT_VIEWPORT.height}`,
      ],
    });
    page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)));

    if (mode === 'register-account') {
      notes.push('Instagram-Registrierung wurde im sichtbaren Browser geoeffnet. Registrierung manuell abschliessen; danach wird die Session gespeichert.');
      await page.goto('https://www.instagram.com/accounts/emailsignup/', {
        waitUntil: 'domcontentloaded',
        timeout: Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
      });
    } else {
      loginDiagnostics = await attemptInstagramLogin(page, runtimeConfig);

      if (!loginDiagnostics.success) {
        notes.push('Instagram-Login ist nicht automatisch abgeschlossen. Browser bleibt fuer manuellen Login offen.');
        await page.goto('https://www.instagram.com/accounts/login/', {
          waitUntil: 'domcontentloaded',
          timeout: Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
        }).catch(() => {});
      }
    }

    const sessionCookie = await waitForInstagramSession(page, runtimeConfig.observationTimeoutMs || runtimeConfig.navigationTimeoutMs || 300000);
    const cookies = await page.cookies('https://www.instagram.com').catch(() => []);
    const storage = await captureStorage(page);
    const sessionCookiePresent = Boolean(sessionCookie);

    if (!sessionCookiePresent) {
      warnings.push('Kein Instagram-sessionid-Cookie gefunden. Session wurde gespeichert, ist aber vermutlich nicht eingeloggt.');
    }

    writeJsonFile(cookieFilePath, {
      capturedAt: new Date().toISOString(),
      platform: 'instagram',
      mode,
      cookies,
      storage,
    });

    const result = {
      ok: sessionCookiePresent,
      statusMessage: sessionCookiePresent
        ? 'Instagram-Session wurde gespeichert.'
        : 'Instagram-Sessiondaten wurden gespeichert, aber kein Login-Cookie wurde erkannt.',
      scriptName: SCRIPT_NAME,
      scriptVersion: SCRIPT_VERSION,
      mode,
      cookieFilePath,
      cookieDiagnostics: {
        cookieCount: cookies.length,
        sessionCookieProvided: sessionCookiePresent,
        sessionCookieAccepted: sessionCookiePresent,
        sessionCookieRetained: sessionCookiePresent,
      },
      loginDiagnostics: {
        ...loginDiagnostics,
        sessionCookiePresent,
        success: loginDiagnostics.success || sessionCookiePresent,
      },
      notes,
      warnings,
    };

    console.log(JSON.stringify(result));
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

main().catch((error) => {
  console.log(JSON.stringify({
    ok: false,
    statusMessage: 'Instagram-Session-Skript ist fehlgeschlagen.',
    scriptName: SCRIPT_NAME,
    scriptVersion: SCRIPT_VERSION,
    warnings: [normalizeText(error?.message || String(error))],
    notes: [],
  }));
  process.exitCode = 1;
});
