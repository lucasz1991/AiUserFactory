'use strict';

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function originFromUrl(value) {
  try {
    return new URL(text(value)).origin;
  } catch {
    return '';
  }
}

function sessionFinalUrl(session = {}) {
  return text(
    session.finalUrl
    || session.final_url
    || session.lastUrl
    || session.last_url
    || session.url
    || '',
  );
}

function storageValues(value = {}) {
  const storage = isObject(value.storage) ? value.storage : {};

  return {
    localStorage: isObject(value.localStorage)
      ? value.localStorage
      : (isObject(value.local_storage) ? value.local_storage : (isObject(storage.localStorage) ? storage.localStorage : {})),
    sessionStorage: isObject(value.sessionStorage)
      ? value.sessionStorage
      : (isObject(value.session_storage) ? value.session_storage : (isObject(storage.sessionStorage) ? storage.sessionStorage : {})),
  };
}

function hasStorage(values = {}) {
  return Object.keys(values.localStorage || {}).length > 0
    || Object.keys(values.sessionStorage || {}).length > 0;
}

function storageEntries(session = {}) {
  const byOrigin = new Map();
  const add = (value = {}) => {
    if (!isObject(value)) {
      return;
    }

    const origin = text(value.origin || originFromUrl(value.url));
    const values = storageValues(value);

    if (!/^https?:\/\//i.test(origin) || !hasStorage(values)) {
      return;
    }

    const current = byOrigin.get(origin) || {
      origin,
      localStorage: {},
      sessionStorage: {},
    };
    current.localStorage = { ...current.localStorage, ...values.localStorage };
    current.sessionStorage = { ...current.sessionStorage, ...values.sessionStorage };
    byOrigin.set(origin, current);
  };

  for (const entry of Array.isArray(session.origins) ? session.origins : []) {
    add(entry);
  }

  const primaryValues = storageValues(session);
  const primaryOrigin = text(session.origin || originFromUrl(sessionFinalUrl(session)));

  if (hasStorage(primaryValues) && primaryOrigin !== '') {
    add({ origin: primaryOrigin, ...primaryValues });
  }

  return Array.from(byOrigin.values());
}

function safeCookie(cookie = {}) {
  if (!isObject(cookie) || text(cookie.name) === '' || (!cookie.domain && !cookie.url)) {
    return null;
  }

  const normalized = {
    name: text(cookie.name),
    value: String(cookie.value ?? ''),
  };

  for (const key of ['url', 'domain', 'path']) {
    if (text(cookie[key]) !== '') {
      normalized[key] = text(cookie[key]);
    }
  }

  for (const key of ['httpOnly', 'secure']) {
    if (typeof cookie[key] === 'boolean') {
      normalized[key] = cookie[key];
    }
  }

  if (Number.isFinite(Number(cookie.expires)) && Number(cookie.expires) > 0) {
    normalized.expires = Number(cookie.expires);
  }

  if (['Strict', 'Lax', 'None'].includes(cookie.sameSite)) {
    normalized.sameSite = cookie.sameSite;
  }

  return normalized;
}

function safeCookies(cookies = []) {
  return (Array.isArray(cookies) ? cookies : []).map(safeCookie).filter(Boolean);
}

async function restoreCookies(page, cookies = []) {
  const normalized = safeCookies(cookies);
  let restored = 0;

  if (!page || typeof page.setCookie !== 'function') {
    return { attempted: normalized.length, restored: 0, failed: normalized.length };
  }

  for (const cookie of normalized) {
    try {
      await page.setCookie(cookie);
      restored += 1;
    } catch {
      // A single stale or unsupported cookie must not block the remaining session.
    }
  }

  return {
    attempted: normalized.length,
    restored,
    failed: normalized.length - restored,
  };
}

async function installStoragePreload(page, entries = []) {
  if (!page || typeof page.evaluateOnNewDocument !== 'function' || entries.length === 0) {
    return null;
  }

  const registration = await page.evaluateOnNewDocument((payload) => {
    const entry = payload.find((candidate) => candidate.origin === window.location.origin);

    if (!entry) {
      return;
    }

    for (const [key, value] of Object.entries(entry.localStorage || {})) {
      window.localStorage.setItem(key, String(value));
    }

    for (const [key, value] of Object.entries(entry.sessionStorage || {})) {
      window.sessionStorage.setItem(key, String(value));
    }
  }, entries);

  return typeof registration === 'string' ? registration : text(registration?.identifier);
}

async function restoreStorageFallback(page, entries = [], timeout = 120000, waitUntil = 'domcontentloaded') {
  let restored = 0;

  for (const entry of entries) {
    try {
      await page.goto(entry.origin, { waitUntil, timeout });

      const actualOrigin = typeof page.url === 'function' ? originFromUrl(page.url()) : entry.origin;

      if (actualOrigin !== '' && actualOrigin !== entry.origin) {
        continue;
      }

      const applied = await page.evaluate((payload) => {
        for (const [key, value] of Object.entries(payload.localStorage || {})) {
          window.localStorage.setItem(key, String(value));
        }

        for (const [key, value] of Object.entries(payload.sessionStorage || {})) {
          window.sessionStorage.setItem(key, String(value));
        }

        return true;
      }, entry);

      if (applied) {
        restored += 1;
      }
    } catch {
      // Continue with the remaining origins and the final target URL.
    }
  }

  return restored;
}

async function restoreBrowserSession(page, session = {}, targetUrl = '', options = {}) {
  const timeout = Number(options.timeout || 120000);
  const waitUntil = text(options.waitUntil || 'domcontentloaded') || 'domcontentloaded';
  const cookies = await restoreCookies(page, session.cookies);
  const entries = storageEntries(session);
  let storageOriginCount = 0;
  let storageStrategy = 'none';
  let preloadIdentifier = null;

  try {
    preloadIdentifier = await installStoragePreload(page, entries);
  } catch {
    preloadIdentifier = null;
  }

  if (preloadIdentifier !== null) {
    storageOriginCount = entries.length;
    storageStrategy = 'preload';
  } else if (entries.length > 0) {
    storageOriginCount = await restoreStorageFallback(page, entries, timeout, waitUntil);
    storageStrategy = 'origin-navigation';
  }

  try {
    await page.goto(targetUrl, { waitUntil, timeout });
  } finally {
    if (preloadIdentifier !== null && typeof page.removeScriptToEvaluateOnNewDocument === 'function') {
      await page.removeScriptToEvaluateOnNewDocument(preloadIdentifier).catch(() => {});
    }
  }

  return {
    cookieAttemptCount: cookies.attempted,
    cookieCount: cookies.restored,
    cookieFailureCount: cookies.failed,
    storageOriginCount,
    storageOriginFailureCount: Math.max(0, entries.length - storageOriginCount),
    storageStrategy,
  };
}

module.exports = {
  restoreBrowserSession,
  safeCookies,
  sessionFinalUrl,
  storageEntries,
};
