'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { accountFromContext, normalizeText } = require('../lib/webmail_context.cjs');

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

async function visibleText(page) {
  const frames = typeof page.frames === 'function' ? page.frames() : [];
  const chunks = [];

  for (const frame of frames) {
    const text = await frame.evaluate(() => document.body ? document.body.innerText : '').catch(() => '');

    if (text) {
      chunks.push(text);
    }
  }

  return chunks.join('\n').replace(/\s+/g, ' ').trim();
}

function sessionLooksActive(url, text, account = {}) {
  const haystack = `${url}\n${text}`.toLowerCase();
  const email = normalizeText(account.email).toLowerCase();

  if (email && haystack.includes(email)) {
    return true;
  }

  return [
    'posteingang',
    'inbox',
    'e-mail',
    'webmailer',
    'gmx freemail',
    'logout',
    'abmelden',
  ].some((needle) => haystack.includes(needle));
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const { account, mailboxSource } = accountFromContext(context, input);
  const session = account.webmailSession || account.webmail_session || null;
  const fallbackUrl = normalizeText(account.webmailUrl || account.webmail_url || 'https://mail.proton.me');
  const targetUrl = normalizeText(input.url || session?.finalUrl || fallbackUrl);
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer den Webmail-Session-Check vorhanden.' };
  }

  if (session && typeof session === 'object' && /^https?:\/\//i.test(targetUrl)) {
    const cookies = safeCookies(Array.isArray(session.cookies) ? session.cookies : []);

    if (cookies.length > 0 && typeof page.setCookie === 'function') {
      await page.setCookie(...cookies).catch(() => {});
    }

    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout }).catch(() => {});

    if (await restoreStorage(page, session)) {
      await page.reload({ waitUntil: 'domcontentloaded', timeout }).catch(() => {});
    }
  }

  const url = typeof page.url === 'function' ? page.url() : targetUrl;
  const text = await visibleText(page);
  const active = sessionLooksActive(url, text, account);

  return captureTaskPreview(context, {
    ok: active,
    status: active ? 'success' : 'failed',
    statusMessage: active
      ? 'Webmailportal-Session ist aktiv.'
      : 'Webmailportal-Session konnte nicht als aktiv bestaetigt werden.',
    mailboxSource,
    mailboxEmail: account.email || '',
    url,
    hasStoredSession: Boolean(session),
  }, true);
}

module.exports = { key: 'webmail.check_session', run };
