'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

function normalizeText(value) {
  return String(value ?? '').trim();
}

function accountFromContext(context = {}) {
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

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const account = accountFromContext(context);
  const session = account.webmailSession || account.webmail_session || null;
  const fallbackUrl = normalizeText(account.webmailUrl || account.webmail_url || 'https://mail.proton.me');
  const targetUrl = normalizeText(input.url || input.value || input.webmailUrl || session?.finalUrl || fallbackUrl);
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Webmail-Session vorhanden.' };
  }

  if (!session || typeof session !== 'object') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine gespeicherte Webmail-Session an der Person gefunden.',
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

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Webmail-Session wurde geladen und das Webmailportal wurde geoeffnet.',
    url: typeof page.url === 'function' ? page.url() : targetUrl,
    cookieCount: cookiesRestored,
    storageRestored,
  });
}

module.exports = { key: 'browser.open_webmail_session', run };
