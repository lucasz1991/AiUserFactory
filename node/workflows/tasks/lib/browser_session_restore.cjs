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

function storageEntries(session = {}, windowId = '') {
  const byOrigin = new Map();
  const addLocalStorage = (value = {}, includeLegacySessionStorage = false) => {
    if (!isObject(value)) {
      return;
    }

    const origin = text(value.origin || originFromUrl(value.url));
    const values = storageValues(value);
    const sessionStorageValues = includeLegacySessionStorage ? values.sessionStorage : {};

    if (!/^https?:\/\//i.test(origin)
      || (Object.keys(values.localStorage).length === 0 && Object.keys(sessionStorageValues).length === 0)) {
      return;
    }

    const current = byOrigin.get(origin) || {
      origin,
      localStorage: {},
      sessionStorage: {},
    };
    current.localStorage = { ...current.localStorage, ...values.localStorage };
    current.sessionStorage = { ...current.sessionStorage, ...sessionStorageValues };
    byOrigin.set(origin, current);
  };

  const windows = Array.isArray(session.windows) ? session.windows.filter(isObject) : [];
  const selectedWindow = windows.find((entry) => windowId !== '' && text(entry.windowId || entry.window_id) === windowId)
    || windows.find((entry) => entry.active === true || entry.isActive === true)
    || windows[0]
    || null;

  for (const entry of Array.isArray(session.origins) ? session.origins : []) {
    addLocalStorage(entry, windows.length === 0);
  }

  if (selectedWindow) {
    for (const entry of Array.isArray(selectedWindow.origins) ? selectedWindow.origins : []) {
      const origin = text(entry.origin || originFromUrl(entry.url));

      if (!/^https?:\/\//i.test(origin)) {
        continue;
      }

      const current = byOrigin.get(origin) || { origin, localStorage: {}, sessionStorage: {} };
      current.sessionStorage = { ...storageValues(entry).sessionStorage };
      byOrigin.set(origin, current);
    }
  }

  const primaryValues = storageValues(session);
  const primaryOrigin = text(session.origin || originFromUrl(sessionFinalUrl(session)));

  if (windows.length === 0 && hasStorage(primaryValues) && primaryOrigin !== '') {
    addLocalStorage({ origin: primaryOrigin, ...primaryValues }, true);
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

  if (isObject(cookie.partitionKey)) {
    const partitionKey = {};
    const topLevelSite = text(cookie.partitionKey.topLevelSite);

    if (topLevelSite !== '') {
      partitionKey.topLevelSite = topLevelSite;
    }

    if (typeof cookie.partitionKey.hasCrossSiteAncestor === 'boolean') {
      partitionKey.hasCrossSiteAncestor = cookie.partitionKey.hasCrossSiteAncestor;
    }

    if (Object.keys(partitionKey).length > 0) {
      normalized.partitionKey = partitionKey;
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

async function applyStorageEntry(page, entry, timeout, waitUntil, includeSessionStorage = false) {
  await page.goto(entry.origin, { waitUntil, timeout });
  const actualOrigin = typeof page.url === 'function' ? originFromUrl(page.url()) : entry.origin;

  if (actualOrigin !== entry.origin) {
    return { localStorageRestored: false, sessionStorageRestored: false };
  }

  const applied = await page.evaluate((payload) => {
    for (const [key, value] of Object.entries(payload.localStorage || {})) {
      window.localStorage.setItem(key, String(value));
    }

    for (const [key, value] of Object.entries(payload.sessionStorage || {})) {
      window.sessionStorage.setItem(key, String(value));
    }

    return {
      localStorageRestored: Object.keys(payload.localStorage || {}).length > 0
        && Object.entries(payload.localStorage || {}).every(([key, value]) => window.localStorage.getItem(key) === String(value)),
      sessionStorageRestored: Object.keys(payload.sessionStorage || {}).length > 0
        && Object.entries(payload.sessionStorage || {}).every(([key, value]) => window.sessionStorage.getItem(key) === String(value)),
    };
  }, {
    localStorage: entry.localStorage || {},
    sessionStorage: includeSessionStorage ? (entry.sessionStorage || {}) : {},
  });

  return isObject(applied) ? applied : { localStorageRestored: false, sessionStorageRestored: false };
}

async function verifyStorageEntry(page, entry) {
  if (!page || typeof page.evaluate !== 'function') {
    return { localStorageRestored: false, sessionStorageRestored: false };
  }

  const verified = await page.evaluate((payload) => {
    if (window.location.origin !== payload.origin) {
      return { localStorageRestored: false, sessionStorageRestored: false };
    }

    return {
      localStorageRestored: Object.keys(payload.localStorage || {}).length > 0
        && Object.entries(payload.localStorage || {}).every(([key, value]) => window.localStorage.getItem(key) === String(value)),
      sessionStorageRestored: Object.keys(payload.sessionStorage || {}).length > 0
        && Object.entries(payload.sessionStorage || {}).every(([key, value]) => window.sessionStorage.getItem(key) === String(value)),
    };
  }, entry);

  return isObject(verified) ? verified : { localStorageRestored: false, sessionStorageRestored: false };
}

async function restoreBrowserSession(page, session = {}, targetUrl = '', options = {}) {
  const timeout = Number(options.timeout || 120000);
  const waitUntil = text(options.waitUntil || 'domcontentloaded') || 'domcontentloaded';
  const cookies = await restoreCookies(page, session.cookies);
  const entries = storageEntries(session, text(options.windowId || options.window_id || ''));
  const targetOrigin = originFromUrl(targetUrl);
  const targetEntry = entries.find((entry) => entry.origin === targetOrigin) || null;
  const otherEntries = entries.filter((entry) => entry.origin !== targetOrigin);
  const savedWindowStorage = (Array.isArray(session.windows) ? session.windows : [])
    .flatMap((window) => Array.isArray(window?.origins) ? window.origins : [])
    .filter((entry) => Object.keys(storageValues(entry).sessionStorage).length > 0);
  const legacySessionStorageEntries = Array.isArray(session.windows) && session.windows.length > 0
    ? []
    : entries.filter((entry) => Object.keys(entry.sessionStorage || {}).length > 0);
  const sessionStorageEntryCount = savedWindowStorage.length || legacySessionStorageEntries.length;
  let localStorageRestoredCount = 0;
  let sessionStorageRestoredCount = 0;
  let storageStrategy = entries.length === 0 ? 'none' : 'context-pages';
  let targetNavigated = false;

  if (targetEntry) {
    if (entries.length === 1 && typeof page.evaluateOnNewDocument === 'function') {
      let preloadIdentifier = null;

      try {
        preloadIdentifier = await installStoragePreload(page, entries);
      } catch {
        preloadIdentifier = null;
      }

      if (preloadIdentifier !== null) {
        try {
          await page.goto(targetUrl, { waitUntil, timeout });
          targetNavigated = true;
          storageStrategy = 'preload';
        } finally {
          if (typeof page.removeScriptToEvaluateOnNewDocument === 'function') {
            await page.removeScriptToEvaluateOnNewDocument(preloadIdentifier).catch(() => {});
          }
        }
      }
    }

    if (!targetNavigated) {
      const targetState = await applyStorageEntry(page, targetEntry, timeout, waitUntil, true).catch(() => ({
        localStorageRestored: false,
        sessionStorageRestored: false,
      }));

      if (targetState.localStorageRestored || targetState.sessionStorageRestored) localStorageRestoredCount++;
      if (targetState.sessionStorageRestored) sessionStorageRestoredCount++;
      if (targetState.localStorageRestored || targetState.sessionStorageRestored) storageStrategy = 'origin-navigation';
    } else {
      const targetState = await verifyStorageEntry(page, targetEntry).catch(() => ({
        localStorageRestored: false,
        sessionStorageRestored: false,
      }));

      if (targetState.localStorageRestored || targetState.sessionStorageRestored) localStorageRestoredCount++;
      if (targetState.sessionStorageRestored) sessionStorageRestoredCount++;
    }
  }

  const browserContext = typeof page.browserContext === 'function' ? page.browserContext() : null;

  for (const entry of otherEntries) {
    const hasSessionStorage = Object.keys(entry.sessionStorage || {}).length > 0;

    if (browserContext && typeof browserContext.newPage === 'function') {
      let helperPage = null;

      try {
        helperPage = await browserContext.newPage();
        const restored = await applyStorageEntry(helperPage, entry, timeout, waitUntil, false);

        if (restored.localStorageRestored) localStorageRestoredCount++;
      } catch {
        // Keep trying the remaining allowed origins.
      } finally {
        if (helperPage && helperPage !== page && typeof helperPage.close === 'function') {
          await helperPage.close().catch(() => {});
        }
      }

      // sessionStorage belongs to a specific tab. A closed helper page cannot
      // faithfully recreate another saved tab, so report this as unsupported.
      if (hasSessionStorage) {
        continue;
      }
    } else {
      try {
        const restored = await applyStorageEntry(page, entry, timeout, waitUntil, false);

        if (restored.localStorageRestored) localStorageRestoredCount++;
      } catch {
        // Keep trying the remaining allowed origins.
      }
    }
  }

  if (!targetNavigated) {
    await page.goto(targetUrl, { waitUntil, timeout });
  }

  return {
    cookieAttemptCount: cookies.attempted,
    cookieCount: cookies.restored,
    cookieFailureCount: cookies.failed,
    storageOriginCount: localStorageRestoredCount,
    storageOriginFailureCount: Math.max(0, entries.length - localStorageRestoredCount),
    sessionStorageEntryCount,
    sessionStorageRestoredCount,
    sessionStorageFailureCount: Math.max(0, sessionStorageEntryCount - sessionStorageRestoredCount),
    storageStrategy,
  };
}

module.exports = {
  restoreBrowserSession,
  safeCookies,
  sessionFinalUrl,
  storageEntries,
};
