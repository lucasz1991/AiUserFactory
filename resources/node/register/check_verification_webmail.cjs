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

const SCRIPT_NAME = process.env.VERIFICATION_WEBMAIL_CHECK_SCRIPT_NAME || 'check_verification_webmail.cjs';
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
    provider: webmailProviderKey(mailbox.provider || runtimeConfig.provider?.key || runtimeConfig.provider?.label),
    email,
    username,
    password,
    webmailUrl,
    webmailSession: mailbox.webmailSession || mailbox.webmail_session || null,
    usable: email !== '' && webmailUrl !== '',
  };
}

function webmailProviderKey(value) {
  const provider = normalizeText(process.env.VERIFICATION_WEBMAIL_CHECK_PROVIDER || value).toLowerCase();

  if (provider.includes('gmx')) {
    return 'gmx';
  }

  if (provider.includes('proton')) {
    return 'proton';
  }

  return provider || 'proton';
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

async function clickSelectorInPageOrFrames(page, selector, timeoutMs = 5000) {
  const stopAt = Date.now() + Math.max(500, Number(timeoutMs) || 5000);

  while (Date.now() < stopAt) {
    for (const frame of page.frames()) {
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

async function restoreWebmailSession(page, mailbox) {
  const session = mailbox.webmailSession;
  const diagnostics = {
    attempted: false,
    cookiesRestored: 0,
    storageRestored: false,
    targetUrl: mailbox.webmailUrl,
  };

  if (!session || typeof session !== 'object') {
    return diagnostics;
  }

  diagnostics.attempted = true;
  diagnostics.targetUrl = normalizeText(session.finalUrl || session.webmailUrl || mailbox.webmailUrl);

  const cookies = Array.isArray(session.cookies) ? session.cookies : [];
  const safeCookies = cookies
    .filter((cookie) => cookie && cookie.name && (cookie.domain || cookie.url))
    .map((cookie) => {
      const nextCookie = { ...cookie };
      delete nextCookie.partitionKey;
      delete nextCookie.sourcePort;
      delete nextCookie.sourceScheme;

      return nextCookie;
    });

  if (safeCookies.length > 0) {
    await page.setCookie(...safeCookies).catch(() => {});
    diagnostics.cookiesRestored = safeCookies.length;
  }

  if (diagnostics.targetUrl && /^https?:\/\//i.test(diagnostics.targetUrl)) {
    await page.goto(diagnostics.targetUrl, {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    }).catch(() => null);
  }

  const storage = session.storage || {};
  const localStorageValues = storage.localStorage || {};
  const sessionStorageValues = storage.sessionStorage || {};

  if (Object.keys(localStorageValues).length > 0 || Object.keys(sessionStorageValues).length > 0) {
    diagnostics.storageRestored = await page.evaluate((payload) => {
      for (const [key, value] of Object.entries(payload.localStorage || {})) {
        window.localStorage.setItem(key, String(value));
      }

      for (const [key, value] of Object.entries(payload.sessionStorage || {})) {
        window.sessionStorage.setItem(key, String(value));
      }

      return true;
    }, {
      localStorage: localStorageValues,
      sessionStorage: sessionStorageValues,
    }).catch(() => false);

    if (diagnostics.storageRestored) {
      await page.reload({
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      }).catch(() => null);
    }
  }

  return diagnostics;
}

async function prepareGmxLogin(page) {
  await clickSelectorInPageOrFrames(page, '#save-all-pur', 10000);
  await sleep(1000);
  await clickSelectorInPageOrFrames(page, 'account-avatar[role="button"], account-avatar, a[aria-label="Login"]', 8000);
  await sleep(1000);
  const dropdownLoginClicked = await clickSelectorInPageOrFrames(
    page,
    'button.account-avatar__button, .account-avatar__button, button[data-component="button"][data-importance="primary"][data-size="l"][data-type="text"]',
    8000,
  );

  if (!dropdownLoginClicked) {
    await clickVisibleTextTarget(page, [
      '^login$',
      'log in',
      'einloggen',
      'anmelden',
    ], 'button.account-avatar__button, button[data-component="button"], button, [role="button"], a').catch(() => false);
  }

  await sleep(1000);
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

  if (mailbox.provider === 'gmx') {
    await prepareGmxLogin(page);
  }

  diagnostics.usernameFilled = await fillFirstMatchingInput(page, [
    ...(mailbox.provider === 'gmx' ? [
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
    ...(mailbox.provider === 'gmx' ? [
      '#login-password',
      'input[name="password"]',
      'input[data-testid*="password" i]',
    ] : []),
    'input[type="password"]',
    'input[name*="pass" i]',
    'input[id*="pass" i]',
    'input[autocomplete="current-password"]',
  ], mailbox.password);

  if (diagnostics.passwordFilled) {
    diagnostics.submitted = mailbox.provider === 'gmx'
      ? await clickVisibleTextTarget(page, [
        'login',
        'log in',
        'einloggen',
        'anmelden',
        'weiter',
      ], '#login-submit, button, input[type="submit"], [role="button"]')
      : await clickVisibleTextTarget(page, [
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
    const collectDeepText = (root) => {
      const parts = [];
      const visit = (node) => {
        if (!node) {
          return;
        }

        if (node.nodeType === Node.TEXT_NODE) {
          parts.push(node.textContent || '');
          return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.DOCUMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
          return;
        }

        const element = node.nodeType === Node.ELEMENT_NODE ? node : null;

        if (element) {
          const tagName = element.tagName.toLowerCase();

          if (['script', 'style', 'noscript'].includes(tagName)) {
            return;
          }

          parts.push(element.getAttribute('aria-label') || '');
          parts.push(element.getAttribute('title') || '');
        }

        node.childNodes?.forEach(visit);

        if (element?.shadowRoot) {
          visit(element.shadowRoot);
        }
      };

      visit(root);

      return parts.join(' ');
    };
    const deepText = collectDeepText(document);
    const normalized = [
      document.body?.innerText || '',
      deepText,
    ].join(' ').replace(/\s+/g, ' ').trim();
    const gmxMailSurfaceReached = Boolean(document.querySelector('mail-list-container, webmailer-mail-detail'));
    const detected = /verification code|verify your email|email verification|one-time verification|confirm your email|bestaetigungscode|\b\d{6}\b/i.test(normalized);
    const codeMatch = normalized.match(/\b\d{6}\b/);

    return {
      detected,
      code: codeMatch ? codeMatch[0] : '',
      textSample: normalized.slice(0, 1600),
      gmxMailSurfaceReached,
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
    const collectElements = (root) => {
      const elements = [];
      const visit = (node) => {
        if (!node || !node.querySelectorAll) {
          return;
        }

        const nextElements = Array.from(node.querySelectorAll('a, button, [role="button"], [role="row"], [data-testid], li, tr, mail-list-container, webmailer-mail-detail, webmailer-mail-list-item'));
        elements.push(...nextElements);

        nextElements.forEach((element) => {
          if (element.shadowRoot) {
            visit(element.shadowRoot);
          }
        });
      };

      visit(root);

      return elements;
    };
    const candidates = collectElements(document);
    const target = candidates.find((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const text = normalize([
        element.innerText,
        element.textContent,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
        element.shadowRoot?.textContent,
      ].join(' '));
      const exactText = text.replace(/\s+/g, ' ').trim();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && exactText !== 'anzeige'
        && !/^anzeige\b/.test(exactText)
        && /(proton|verification|verify|code|bestaetigung|confirm|security|sicherheit)/.test(text);
    });

    if (!target) {
      return false;
    }

    target.click();

    return true;
  }).catch(() => false);
}

async function openInbox(page) {
  return clickVisibleTextTarget(page, [
    'posteingang',
    'inbox',
    'e-mail',
    'mail',
  ], 'button, [role="button"], a, [data-testid]').catch(() => false);
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
    const restoredSession = await restoreWebmailSession(page, mailbox);

    if (!restoredSession.attempted || !page.url().startsWith('http')) {
      await page.goto(mailbox.webmailUrl, {
        waitUntil: 'domcontentloaded',
        timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
      });
    }

    const sessionUsable = restoredSession.cookiesRestored > 0 || restoredSession.storageRestored === true;
    const login = sessionUsable
      ? {
        attempted: false,
        skippedBecauseSessionRestored: true,
      }
      : await attemptWebmailLogin(page, mailbox);
    await sleep(Math.max(2000, Number(runtimeConfig.verificationWebmailPostLoginWaitMs || 4000)));
    await openInbox(page);
    await sleep(1500);

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
      restoredSession,
      providerKey: mailbox.provider,
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
