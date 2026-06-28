'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  clickVisibleElement,
  clickVisibleElementByText,
  findVisibleElementByText,
} = require('../lib/find_visible_element.cjs');

function normalizeText(value) {
  return String(value ?? '').trim();
}

function isMailboxSourceToken(value) {
  return ['person', 'reference_person', 'verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master']
    .includes(normalizeText(value).toLowerCase());
}

function normalizeMailboxSource(value) {
  const normalized = normalizeText(value).toLowerCase();

  return ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'].includes(normalized)
    ? 'verification'
    : 'person';
}

function personAccountFromContext(context = {}) {
  const workflow = context.workflow || {};
  const person = context.person || workflow.person || {};
  const workflowAccount = workflow.account
    || workflow.email_account
    || person.emailAccount
    || {};

  return {
    ...workflowAccount,
    ...(context.account || {}),
    webmailSession: context.account?.webmailSession || workflowAccount.webmailSession || workflowAccount.webmail_session || null,
    webmail_session: context.account?.webmail_session || workflowAccount.webmail_session || workflowAccount.webmailSession || null,
  };
}

function verificationAccountFromContext(context = {}) {
  const workflow = context.workflow || {};
  const verificationAccount = context.verificationMailbox
    || context.verification_mailbox
    || context.veri_account
    || context['veri-account']
    || workflow.verificationMailbox
    || workflow.verification_mailbox
    || workflow.veri_account
    || workflow['veri-account']
    || {};

  return {
    ...verificationAccount,
    webmailSession: verificationAccount.webmailSession || verificationAccount.webmail_session || null,
    webmail_session: verificationAccount.webmail_session || verificationAccount.webmailSession || null,
  };
}

function accountFromContext(context = {}, input = {}) {
  const mailboxSource = normalizeMailboxSource(input.script_person_source || input.scriptPersonSource || input.mailbox_source || input.mailboxSource || input.account_source || input.accountSource || input.value || 'person');
  const account = mailboxSource === 'verification'
    ? verificationAccountFromContext(context)
    : personAccountFromContext(context);

  return { account, mailboxSource };
}

function storageValues(session = {}) {
  const storage = session.storage || {};

  return {
    localStorage: storage.localStorage || session.localStorage || {},
    sessionStorage: storage.sessionStorage || session.sessionStorage || {},
  };
}

function safeCookies(cookies = []) {
  return cookies
    .filter((cookie) => cookie && cookie.name && (cookie.domain || cookie.url))
    .map((cookie) => {
      const nextCookie = { ...cookie };
      delete nextCookie.partitionKey;
      delete nextCookie.sourcePort;
      delete nextCookie.sourceScheme;

      return nextCookie;
    });
}

async function restoreStorage(page, session = {}) {
  const storage = storageValues(session);

  if (Object.keys(storage.localStorage).length === 0 && Object.keys(storage.sessionStorage).length === 0) {
    return false;
  }

  return page.evaluate((payload) => {
    for (const [key, value] of Object.entries(payload.localStorage || {})) {
      window.localStorage.setItem(key, String(value));
    }

    for (const [key, value] of Object.entries(payload.sessionStorage || {})) {
      window.sessionStorage.setItem(key, String(value));
    }

    return true;
  }, storage).catch(() => false);
}

async function pageText(page) {
  if (!page || typeof page.evaluate !== 'function') {
    return '';
  }

  return page.evaluate(() => document.body ? document.body.innerText || '' : '').catch(() => '');
}

async function isLoggedInLandingPage(page) {
  const url = typeof page.url === 'function' ? String(page.url() || '') : '';

  if (/logoutlounge/i.test(url) && /status=session/i.test(url)) {
    return true;
  }

  const text = await pageText(page);

  return /sie bleiben eingeloggt/i.test(text);
}

async function openMailboxViaAccountDropdown(page, timeout) {
  const avatarSelectors = [
    'section.appa-user-icon__initials',
    '.appa-user-icon__initials',
    'appa-account-avatar section.appa-user-icon__initials',
    'account-avatar section.appa-user-icon__initials',
    '[class*="user-icon__initials"]',
    '[aria-label*="Account" i]',
    '[aria-label*="Profil" i]',
    '[aria-label*="Benutzer" i]',
  ];
  const mailboxButtonSelectors = [
    'button.account-avatar__button:has-text("Zum Postfach")',
    '.account-avatar__button:has-text("Zum Postfach")',
    'button:has-text("Zum Postfach")',
  ];

  for (const avatarSelector of avatarSelectors) {
    const avatarClicked = await clickVisibleElement(page, avatarSelector, Math.min(timeout, 10000)).catch(() => null);

    if (!avatarClicked) {
      continue;
    }

    await new Promise((resolve) => setTimeout(resolve, 300));

    for (const mailboxButtonSelector of mailboxButtonSelectors) {
      const mailboxClicked = await clickVisibleElement(page, mailboxButtonSelector, Math.min(timeout, 10000)).catch(() => null);

      if (mailboxClicked) {
        await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: Math.min(timeout, 30000) }).catch(() => {});

        return {
          clicked: true,
          method: 'account-dropdown',
          avatarSelector,
          mailboxButtonSelector,
          avatarElement: avatarClicked,
          mailboxElement: mailboxClicked,
        };
      }
    }

    const mailboxTextClicked = await clickVisibleElementByText(page, 'Zum Postfach', Math.min(timeout, 10000), {
      selector: 'button.account-avatar__button,button,a,[role=button]',
    }).catch(() => null);

    if (mailboxTextClicked) {
      await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: Math.min(timeout, 30000) }).catch(() => {});

      return {
        clicked: true,
        method: 'account-dropdown-text',
        avatarSelector,
        avatarElement: avatarClicked,
        mailboxElement: mailboxTextClicked,
      };
    }
  }

  return null;
}

async function openMailboxFromLoggedInLanding(page, timeout) {
  const dropdownAction = await openMailboxViaAccountDropdown(page, timeout);

  if (dropdownAction) {
    return dropdownAction;
  }

  const selectors = [
    'a[href*="mail/showStartView"]',
    'a[href*="weblink.gmx.net/mail"]',
    'a[href*="/mail/"]:has-text("E-Mail")',
    'button:has-text("Zum Postfach")',
    'a:has-text("Zum Postfach")',
  ];
  const texts = [
    'Zum Postfach',
    'Zum Posteingang',
    'Postfach öffnen',
  ];

  for (const selector of selectors) {
    const clicked = await clickVisibleElement(page, selector, Math.min(timeout, 10000)).catch(() => null);

    if (clicked) {
      await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: Math.min(timeout, 30000) }).catch(() => {});

      return {
        clicked: true,
        method: 'selector',
        selector,
        element: clicked,
      };
    }
  }

  for (const text of texts) {
    const handle = await findVisibleElementByText(page, text, Math.min(timeout, 5000), {
      selector: 'a,button,[role=button],input[type=button],input[type=submit]',
    }).catch(() => null);

    if (!handle) {
      continue;
    }

    await handle.dispose?.().catch(() => {});

    const clicked = await clickVisibleElementByText(page, text, Math.min(timeout, 10000)).catch(() => null);

    if (clicked) {
      await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: Math.min(timeout, 30000) }).catch(() => {});

      return {
        clicked: true,
        method: 'text',
        text,
        element: clicked,
      };
    }
  }

  const fallbackUrl = 'https://weblink.gmx.net/mail/showStartView';

  await page.goto(fallbackUrl, { waitUntil: 'domcontentloaded', timeout });

  return {
    clicked: false,
    method: 'fallback-url',
    url: fallbackUrl,
  };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const { account, mailboxSource } = accountFromContext(context, input);
  const session = account.webmailSession || account.webmail_session || null;
  const fallbackUrl = normalizeText(account.webmailUrl || account.webmail_url || 'https://mail.proton.me');
  const valueAsUrl = isMailboxSourceToken(input.value) ? '' : input.value;
  const targetUrl = normalizeText(input.url || input.webmailUrl || valueAsUrl || session?.finalUrl || fallbackUrl);
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Webmail-Session vorhanden.' };
  }

  if (!session || typeof session !== 'object') {
    const accountLabel = mailboxSource === 'verification' ? 'Haupt-Verifikationskonto' : 'Bezugs-Person';

    return {
      ok: false,
      status: 'failed',
      statusMessage: `Keine gespeicherte Webmail-Session fuer ${accountLabel} gefunden.`,
      mailboxSource,
    };
  }

  if (!/^https?:\/\//i.test(targetUrl)) {
    return { ok: false, status: 'failed', statusMessage: 'Keine gueltige Webmail-URL fuer die Session vorhanden.' };
  }

  const cookies = safeCookies(Array.isArray(session.cookies) ? session.cookies : []);
  let cookiesRestored = 0;

  if (cookies.length > 0 && typeof page.setCookie === 'function') {
    await page.setCookie(...cookies).catch(() => {});
    cookiesRestored = cookies.length;
  }

  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout });

  const storageRestored = await restoreStorage(page, session);

  if (storageRestored) {
    await page.reload({ waitUntil: 'domcontentloaded', timeout }).catch(() => page.goto(targetUrl, {
      waitUntil: 'domcontentloaded',
      timeout,
    }));
  }

  let mailboxLandingAction = null;

  if (await isLoggedInLandingPage(page)) {
    mailboxLandingAction = await openMailboxFromLoggedInLanding(page, timeout);
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: mailboxLandingAction
      ? 'Webmail-Session wurde geladen und das Postfach wurde von der Eingeloggt-Zwischenseite geoeffnet.'
      : 'Webmail-Session wurde geladen und das Webmailportal wurde geoeffnet.',
    url: typeof page.url === 'function' ? page.url() : targetUrl,
    mailboxSource,
    mailboxEmail: account.email || '',
    cookieCount: cookiesRestored,
    storageRestored,
    mailboxLandingAction,
  });
}

module.exports = { key: 'browser.open_webmail_session', run };
