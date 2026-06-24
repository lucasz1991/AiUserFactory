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

const SCRIPT_NAME = 'webmail_session.cjs';
const SCRIPT_VERSION = 1;
const DEFAULT_VIEWPORT = { width: 1365, height: 900 };

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

async function attemptWebmailLogin(page, runtimeConfig) {
  const username = normalizeText(runtimeConfig.username || runtimeConfig.email);
  const password = normalizeText(runtimeConfig.password);
  const typingDelay = Math.max(0, Number(runtimeConfig.typingDelayMs || 35));
  const diagnostics = {
    attempted: false,
    usernameFilled: false,
    passwordFilled: false,
    submitted: false,
  };

  if (!username || !password) {
    return diagnostics;
  }

  diagnostics.attempted = true;
  diagnostics.usernameFilled = await fillFirstMatchingInput(page, [
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
    'input[type="password"]',
    'input[name*="pass" i]',
    'input[id*="pass" i]',
    'input[autocomplete="current-password"]',
  ], password, typingDelay);

  if (diagnostics.passwordFilled) {
    diagnostics.submitted = await clickSubmit(page);
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
  const observationTimeoutMs = Math.max(30000, Number(runtimeConfig.observationTimeoutMs || 300000));
  const notes = [];
  const warnings = [];
  let browser = null;

  if (!webmailUrl || !/^https?:\/\//i.test(webmailUrl)) {
    throw new Error('Gueltige Webmail-URL fehlt.');
  }

  if (!sessionFilePath) {
    throw new Error('Session-Dateipfad fehlt.');
  }

  try {
    browser = await puppeteer.launch({
      headless: runtimeConfig.headlessEnabled === true ? 'new' : false,
      defaultViewport: DEFAULT_VIEWPORT,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        `--window-size=${DEFAULT_VIEWPORT.width},${DEFAULT_VIEWPORT.height}`,
      ],
    });

    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)));
    await page.goto(webmailUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.max(30000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
    });

    const loginDiagnostics = await attemptWebmailLogin(page, runtimeConfig);
    notes.push(loginDiagnostics.attempted
      ? 'Webmail-Login wurde mit hinterlegten Zugangsdaten versucht. Falls ein zweiter Faktor oder Provider-Zwischenschritt erscheint, bitte manuell abschliessen.'
      : 'Keine vollstaendigen Webmail-Zugangsdaten vorhanden. Bitte Login im sichtbaren Browser manuell abschliessen.');

    await sleep(Math.max(1500, Number(runtimeConfig.postLoginWaitMs || 2500)));
    await sleep(observationTimeoutMs);

    const origin = sameOriginUrl(page.url()) || sameOriginUrl(webmailUrl);
    const cookies = origin ? await page.cookies(origin).catch(() => []) : await page.cookies().catch(() => []);
    const storage = await captureStorage(page);
    const sessionPayload = {
      capturedAt: new Date().toISOString(),
      provider: normalizeText(runtimeConfig.provider),
      email: normalizeText(runtimeConfig.email),
      username: normalizeText(runtimeConfig.username || runtimeConfig.email),
      webmailUrl,
      finalUrl: page.url(),
      origin,
      cookies,
      storage,
    };

    if (cookies.length === 0 && Object.keys(storage.localStorage || {}).length === 0 && Object.keys(storage.sessionStorage || {}).length === 0) {
      warnings.push('Es wurden keine Cookies oder Browser-Storage-Daten erkannt.');
    }

    writeJsonFile(sessionFilePath, sessionPayload);

    console.log(JSON.stringify({
      ok: cookies.length > 0 || Object.keys(storage.localStorage || {}).length > 0 || Object.keys(storage.sessionStorage || {}).length > 0,
      statusMessage: 'Webmail-Sessiondaten wurden gespeichert.',
      scriptName: SCRIPT_NAME,
      scriptVersion: SCRIPT_VERSION,
      sessionFilePath,
      finalUrl: page.url(),
      cookieCount: cookies.length,
      loginDiagnostics,
      notes,
      warnings,
    }));
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

main().catch((error) => {
  console.log(JSON.stringify({
    ok: false,
    statusMessage: 'Webmail-Session-Skript ist fehlgeschlagen.',
    scriptName: SCRIPT_NAME,
    scriptVersion: SCRIPT_VERSION,
    warnings: [normalizeText(error?.message || String(error))],
    notes: [],
  }));
  process.exitCode = 1;
});
