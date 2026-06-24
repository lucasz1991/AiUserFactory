const fs = require('fs');
const path = require('path');
const {
  BROWSER_LAUNCHER_SCRIPT_VERSION,
  launchConfiguredBrowser,
  resolveBrowserEngine,
} = require('./lib/browser-launcher.cjs');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const SCRIPT_NAME = 'check_verification_webmail.cjs';
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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

function verificationMailboxFromConfig(runtimeConfig = {}) {
  const mailbox = runtimeConfig.verificationMailbox || runtimeConfig.verification_mailbox || {};
  const email = normalizeText(mailbox.email);
  const username = normalizeText(mailbox.username || email);
  const password = normalizeText(mailbox.password);
  const webmailUrl = normalizeText(mailbox.webmailUrl || mailbox.webmail_url || runtimeConfig.provider?.webmailUrl);

  return {
    enabled: mailbox.enabled !== false,
    email,
    username,
    password,
    webmailUrl,
    usable: email !== '' && webmailUrl !== '',
  };
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

async function clickVisibleTextTarget(page, patterns, selector = 'button, [role="button"], a, input[type="submit"]') {
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

async function attemptWebmailLogin(page, mailbox) {
  const diagnostics = {
    attempted: false,
    usernameFilled: false,
    passwordFilled: false,
    submitted: false,
  };

  if (!mailbox.username || !mailbox.password) {
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
  ], mailbox.username);

  if (diagnostics.usernameFilled) {
    await sleep(600);
    await clickVisibleTextTarget(page, [
      'next',
      'continue',
      'weiter',
      'sign in',
      'log in',
      'login',
      'anmelden',
      'einloggen',
    ]);
    await sleep(1500);
  }

  diagnostics.passwordFilled = await fillFirstMatchingInput(page, [
    'input[type="password"]',
    'input[name*="pass" i]',
    'input[id*="pass" i]',
    'input[autocomplete="current-password"]',
  ], mailbox.password);

  if (diagnostics.passwordFilled) {
    diagnostics.submitted = await clickVisibleTextTarget(page, [
      'sign in',
      'log in',
      'login',
      'anmelden',
      'einloggen',
      'next',
      'continue',
      'weiter',
    ]);
  }

  return diagnostics;
}

async function pageVerificationState(page) {
  return page.evaluate(() => {
    const normalized = (document.body?.innerText || '').replace(/\s+/g, ' ').trim();
    const detected = /verification code|verify your email|email verification|one-time verification|confirm your email|bestaetigungscode|\b\d{6}\b/i.test(normalized);
    const codeMatch = normalized.match(/\b\d{6}\b/);

    return {
      detected,
      code: codeMatch ? codeMatch[0] : '',
      textSample: normalized.slice(0, 1600),
      title: document.title || '',
      url: window.location.href,
    };
  }).catch(() => ({
    detected: false,
    code: '',
    textSample: '',
    title: '',
    url: page.url(),
  }));
}

async function openLikelyVerificationMessage(page) {
  return page.evaluate(() => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const candidates = Array.from(document.querySelectorAll('a, button, [role="button"], [role="row"], [data-testid], li, tr'));
    const target = candidates.find((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const text = normalize([
        element.innerText,
        element.textContent,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
      ].join(' '));

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && /(proton|verification|verify|code|bestaetigung|confirm)/.test(text);
    });

    if (!target) {
      return false;
    }

    target.click();

    return true;
  }).catch(() => false);
}

async function waitForVerificationEmail(page, timeoutMs) {
  const stopAt = Date.now() + Math.max(15000, Number(timeoutMs) || 120000);
  let lastState = await pageVerificationState(page);

  while (Date.now() < stopAt) {
    if (lastState.detected) {
      return lastState;
    }

    await openLikelyVerificationMessage(page);
    await sleep(1500);
    lastState = await pageVerificationState(page);

    if (lastState.detected) {
      return lastState;
    }

    await clickVisibleTextTarget(page, [
      'refresh',
      'aktualisieren',
      'reload',
      'inbox',
      'posteingang',
    ]).catch(() => false);

    await sleep(5000);
    lastState = await pageVerificationState(page);
  }

  return lastState;
}

async function main() {
  const runtimeConfigPath = process.argv[2] || '';
  const runtimeConfig = readJsonFile(runtimeConfigPath, {});
  const mailbox = verificationMailboxFromConfig(runtimeConfig);
  const warnings = [];
  let browser = null;

  if (!mailbox.usable) {
    throw new Error('Verifikations-Mailbox oder Webmail-URL ist nicht konfiguriert.');
  }

  const requestedBrowserEngine = resolveBrowserEngine(runtimeConfig);
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
    launchOptions.userDataDir = `${runtimeConfig.browserProfilePath}-webmail-check`;
    ensureDirectory(launchOptions.userDataDir);
  }

  try {
    const launchResult = await launchConfiguredBrowser({
      puppeteer,
      runtimeConfig,
      launchOptions,
    });

    browser = launchResult.browser;
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)));
    await page.setViewport(DEFAULT_VIEWPORT);
    await page.goto(mailbox.webmailUrl, {
      waitUntil: 'domcontentloaded',
      timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
    });

    const login = await attemptWebmailLogin(page, mailbox);
    await sleep(Math.max(2000, Number(runtimeConfig.verificationWebmailPostLoginWaitMs || 4000)));

    const verificationState = await waitForVerificationEmail(
      page,
      runtimeConfig.verificationWebmailJobTimeoutMs
        || runtimeConfig.verificationEmailWaitTimeoutMs
        || runtimeConfig.manualVerificationEmailWaitTimeoutMs
        || 60000,
    );

    const result = {
      ok: verificationState.detected === true,
      opened: true,
      mailDetected: verificationState.detected === true,
      verificationCode: verificationState.code || '',
      statusMessage: verificationState.detected === true
        ? 'Verifikationsmail wurde im Webmail erkannt.'
        : 'Keine Verifikationsmail im Webmail erkannt.',
      finalUrl: verificationState.url || page.url(),
      title: verificationState.title || await page.title().catch(() => ''),
      textSample: verificationState.textSample || '',
      login,
      requestedBrowserEngine,
      activeBrowserEngine: launchResult.activeEngine,
      browserFallbackReason: launchResult.fallbackReason || null,
      warnings,
      scriptName: SCRIPT_NAME,
      scriptVersion: SCRIPT_VERSION,
      scriptVersions: {
        webmailCheck: SCRIPT_VERSION,
        browserLauncher: BROWSER_LAUNCHER_SCRIPT_VERSION || 1,
      },
      checkedAt: new Date().toISOString(),
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
    opened: false,
    mailDetected: false,
    verificationCode: '',
    statusMessage: 'Webmail-Check konnte nicht ausgefuehrt werden.',
    error: normalizeText(error?.message || String(error)),
    scriptName: SCRIPT_NAME,
    scriptVersion: SCRIPT_VERSION,
    checkedAt: new Date().toISOString(),
  }));
  process.exitCode = 1;
});
