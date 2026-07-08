'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  domainFromUrl,
  domainMatches,
  normalizeDomain,
} = require('../lib/webmail_session_capture.cjs');

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sessionKey(value) {
  return text(value).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
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

function sessionEntries(value, source = '') {
  if (!isObject(value)) {
    return [];
  }

  if (Array.isArray(value.cookies) || Array.isArray(value.origins) || isObject(value.storage)) {
    return [{ ...value, source }];
  }

  return Object.entries(value)
    .filter(([, session]) => isObject(session))
    .map(([key, session]) => ({
      ...session,
      sessionKey: session.sessionKey || session.session_key || key,
      session_key: session.session_key || session.sessionKey || key,
      source,
    }));
}

function browserSessionsFromContext(context = {}) {
  const workflow = isObject(context.workflow) ? context.workflow : {};
  const person = isObject(context.person) ? context.person : (isObject(workflow.person) ? workflow.person : {});
  const account = isObject(context.account)
    ? context.account
    : (isObject(context.email_account) ? context.email_account : {});
  const metadata = isObject(person.metadata) ? person.metadata : {};
  const candidates = [
    [context.browserSessions, 'context.browserSessions'],
    [context.browser_sessions, 'context.browser_sessions'],
    [workflow.browserSessions, 'workflow.browserSessions'],
    [workflow.browser_sessions, 'workflow.browser_sessions'],
    [account.browserSessions, 'account.browserSessions'],
    [account.browser_sessions, 'account.browser_sessions'],
    [person.browserSessions, 'person.browserSessions'],
    [person.browser_sessions, 'person.browser_sessions'],
    [metadata.browser_sessions, 'person.metadata.browser_sessions'],
    [metadata.browserSessions, 'person.metadata.browserSessions'],
  ];

  return candidates.flatMap(([value, source]) => sessionEntries(value, source));
}

function storageEntries(session = {}) {
  const origins = Array.isArray(session.origins) ? session.origins : [];
  const entries = origins
    .filter((entry) => isObject(entry))
    .map((entry) => ({
      url: text(entry.url || entry.origin),
      origin: text(entry.origin || ''),
      localStorage: isObject(entry.localStorage) ? entry.localStorage : {},
      sessionStorage: isObject(entry.sessionStorage) ? entry.sessionStorage : {},
    }));
  const storage = isObject(session.storage) ? session.storage : {};
  const finalUrl = text(session.finalUrl || session.final_url || session.url || '');
  const finalOrigin = originFromUrl(finalUrl);

  if (
    finalOrigin !== ''
    && (
      Object.keys(storage.localStorage || {}).length > 0
      || Object.keys(storage.sessionStorage || {}).length > 0
    )
    && !entries.some((entry) => entry.origin === finalOrigin)
  ) {
    entries.push({
      url: finalUrl || finalOrigin,
      origin: finalOrigin,
      localStorage: isObject(storage.localStorage) ? storage.localStorage : {},
      sessionStorage: isObject(storage.sessionStorage) ? storage.sessionStorage : {},
    });
  }

  return entries;
}

function originFromUrl(value) {
  try {
    return new URL(text(value)).origin;
  } catch {
    return '';
  }
}

function sessionTimestamp(session = {}) {
  const value = text(session.updated_at || session.updatedAt || session.captured_at || session.capturedAt);
  const timestamp = Date.parse(value);

  return Number.isFinite(timestamp) ? timestamp : 0;
}

function sessionMatchesDomain(session = {}, targetDomain = '') {
  const normalizedTarget = normalizeDomain(targetDomain);

  if (normalizedTarget === '') {
    return false;
  }

  const domains = [
    session.domain,
    session.sessionDomain,
    session.session_domain,
    domainFromUrl(session.finalUrl || session.final_url || ''),
    ...(Array.isArray(session.domains) ? session.domains : []),
    ...(Array.isArray(session.cookieDomains) ? session.cookieDomains : []),
    ...(Array.isArray(session.cookie_domains) ? session.cookie_domains : []),
  ].map(normalizeDomain).filter(Boolean);

  return domains.some((domain) => domainMatches(domain, normalizedTarget));
}

function selectSession(sessions = [], input = {}) {
  const requestedKey = sessionKey(input.session_key || input.sessionKey || input.value || '');
  const targetDomain = normalizeDomain(input.target_domain || input.targetDomain || input.domain || input.url || '');

  if (requestedKey !== '') {
    const keyed = sessions.find((session) => {
      const currentKey = sessionKey(session.sessionKey || session.session_key || session.key || '');

      return currentKey === requestedKey;
    });

    if (keyed) {
      return keyed;
    }
  }

  if (targetDomain !== '') {
    const domainMatch = sessions.find((session) => sessionMatchesDomain(session, targetDomain));

    if (domainMatch) {
      return domainMatch;
    }
  }

  return [...sessions].sort((left, right) => sessionTimestamp(right) - sessionTimestamp(left))[0] || null;
}

async function restoreStorageEntry(page, entry = {}, timeout = 120000) {
  const targetUrl = text(entry.url || entry.origin);

  if (!/^https?:\/\//i.test(targetUrl)) {
    return false;
  }

  const hasStorage = Object.keys(entry.localStorage || {}).length > 0
    || Object.keys(entry.sessionStorage || {}).length > 0;

  if (!hasStorage) {
    return false;
  }

  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout });

  return page.evaluate((payload) => {
    for (const [key, value] of Object.entries(payload.localStorage || {})) {
      window.localStorage.setItem(key, String(value));
    }

    for (const [key, value] of Object.entries(payload.sessionStorage || {})) {
      window.sessionStorage.setItem(key, String(value));
    }

    return true;
  }, {
    localStorage: entry.localStorage || {},
    sessionStorage: entry.sessionStorage || {},
  }).catch(() => false);
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Laden der Browser-Session vorhanden.' };
  }

  const sessions = browserSessionsFromContext(context);
  const session = selectSession(sessions, input);

  if (!session) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine gespeicherte Browser-Session gefunden.',
      requestedSessionKey: input.session_key || input.sessionKey || '',
      targetDomain: input.target_domain || input.targetDomain || input.domain || '',
    };
  }

  const targetUrl = text(input.url || input.target_url || input.targetUrl || session.finalUrl || session.final_url || session.url || '');

  if (!/^https?:\/\//i.test(targetUrl)) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Die gespeicherte Browser-Session enthaelt keine gueltige letzte URL.',
      sessionKey: session.sessionKey || session.session_key || '',
    };
  }

  const cookies = safeCookies(Array.isArray(session.cookies) ? session.cookies : []);
  let cookiesRestored = 0;

  if (cookies.length > 0 && typeof page.setCookie === 'function') {
    await page.setCookie(...cookies).catch(() => {});
    cookiesRestored = cookies.length;
  }

  let storageOriginsRestored = 0;

  for (const entry of storageEntries(session)) {
    if (await restoreStorageEntry(page, entry, timeout)) {
      storageOriginsRestored += 1;
    }
  }

  await page.goto(targetUrl, { waitUntil: input.waitUntil || 'domcontentloaded', timeout });

  if (storageOriginsRestored > 0) {
    await page.reload({ waitUntil: input.waitUntil || 'domcontentloaded', timeout }).catch(() => {});
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Browser-Session wurde geladen und die letzte URL wurde geoeffnet.',
    url: typeof page.url === 'function' ? page.url() : targetUrl,
    finalUrl: targetUrl,
    sessionKey: session.sessionKey || session.session_key || '',
    sessionLabel: session.label || '',
    domain: session.domain || domainFromUrl(targetUrl),
    cookieCount: cookiesRestored,
    storageOriginCount: storageOriginsRestored,
  });
}

module.exports = { key: 'browser.open_browser_session', run };
