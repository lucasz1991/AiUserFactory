'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { cookieMatchesDomains, domainFromUrl, normalizeDomain } = require('../lib/webmail_session_capture.cjs');

function normalizeText(value) {
  return String(value ?? '').trim();
}

function boolValue(value, fallback = true) {
  const normalized = normalizeText(value).toLowerCase();

  if (normalized === '') {
    return fallback;
  }

  return !['0', 'false', 'nein', 'no', 'off'].includes(normalized);
}

function activeBrowserWindowUrl(context = {}) {
  const windows = []
    .concat(Array.isArray(context.browserWindows) ? context.browserWindows : [])
    .concat(Array.isArray(context.windows) ? context.windows : []);
  const activeName = normalizeText(context.activeBrowserWindow || context.browserWindow || '');
  const httpUrl = (entry) => {
    const url = normalizeText(entry && typeof entry === 'object' ? entry.url : '');

    return /^https?:\/\//i.test(url) ? url : '';
  };

  if (activeName !== '') {
    const match = windows.find((entry) => normalizeText(entry?.key || entry?.name || '') === activeName && httpUrl(entry) !== '');

    if (match) {
      return httpUrl(match);
    }
  }

  const anyMatch = windows.find((entry) => httpUrl(entry) !== '');

  return anyMatch ? httpUrl(anyMatch) : '';
}

function originFromUrl(value) {
  try {
    return new URL(String(value || '')).origin;
  } catch {
    return '';
  }
}

function uniqueValues(values = []) {
  return Array.from(new Set(values.map((value) => String(value || '').trim()).filter(Boolean)));
}

function sessionKeyFromDomain(domain) {
  return normalizeDomain(domain).replace(/[^a-z0-9.-]+/g, '-').replace(/^-+|-+$/g, '') || 'browser-session';
}

async function createClient(page) {
  if (!page || typeof page.target !== 'function') {
    return null;
  }

  try {
    return await page.target().createCDPSession();
  } catch {
    return null;
  }
}

async function allCookies(page, client) {
  if (client) {
    try {
      const result = await client.send('Network.getAllCookies');

      return Array.isArray(result.cookies) ? result.cookies : [];
    } catch {
      // Fallback below.
    }
  }

  if (page && typeof page.cookies === 'function') {
    return page.cookies().catch(() => []);
  }

  return [];
}

async function deleteCookie(client, cookie) {
  if (!client || !cookie || !cookie.name) {
    return false;
  }

  const payload = {
    name: cookie.name,
    domain: cookie.domain,
    path: cookie.path || '/',
  };

  try {
    await client.send('Network.deleteCookies', payload);

    return true;
  } catch {
    return false;
  }
}

function storageOriginsForDomain(page, targetUrl, targetDomain) {
  const frameUrls = typeof page.frames === 'function'
    ? page.frames().map((frame) => (typeof frame.url === 'function' ? frame.url() : '')).filter(Boolean)
    : [];
  const urls = uniqueValues([targetUrl, ...frameUrls]);

  return uniqueValues(urls
    .filter((url) => domainFromUrl(url) !== '' && cookieMatchesDomains({ domain: domainFromUrl(url), name: 'origin-match' }, [targetDomain]))
    .map(originFromUrl));
}

async function clearStorage(page, client, targetUrl, targetDomain, clearStorageEnabled) {
  if (!clearStorageEnabled) {
    return false;
  }

  const origins = storageOriginsForDomain(page, targetUrl, targetDomain);
  let clearedByCdp = false;

  if (client && origins.length > 0) {
    for (const origin of origins) {
      try {
        await client.send('Storage.clearDataForOrigin', {
          origin,
          storageTypes: 'local_storage,session_storage,indexeddb,cache_storage,service_workers,websql',
        });

        clearedByCdp = true;
      } catch {
        // Fallback below.
      }
    }
  }

  if (!page || typeof page.evaluate !== 'function') {
    return clearedByCdp;
  }

  const clearedCurrentPage = await page.evaluate(() => {
    if (window.localStorage) {
      window.localStorage.clear();
    }

    if (window.sessionStorage) {
      window.sessionStorage.clear();
    }

    return true;
  }).catch(() => false);

  return clearedByCdp || clearedCurrentPage;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const currentUrl = page && typeof page.url === 'function' ? page.url() : '';
  // about:blank & Co. sind keine echte Seite und liefern keine Domain.
  const realCurrentUrl = /^https?:\/\//i.test(currentUrl) ? currentUrl : '';
  // Domain zusaetzlich aus dem aktiven Workflow-Browserfenster ableiten, falls
  // die Task ohne target_domain und auf einer leeren Seite (about:blank) laeuft
  // – etwa im segmentierten Einzeltask-/Copilot-Test.
  const windowUrl = activeBrowserWindowUrl(context);
  const targetUrl = normalizeText(input.url || input.value || realCurrentUrl || windowUrl);
  const targetDomain = normalizeDomain(
    input.target_domain || input.targetDomain || input.domain || targetUrl,
  );
  const sessionKey = sessionKeyFromDomain(input.session_key || input.sessionKey || targetDomain);
  const clearCookies = boolValue(input.clear_cookies || input.clearCookies, true);
  const clearStorageEnabled = boolValue(input.clear_storage || input.clearStorage, true);

  if (!page || typeof page.url !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Loeschen der Browser-Session vorhanden.' };
  }

  if (targetDomain === '') {
    // Kein Ziel und keine geladene Seite: es gibt schlicht nichts zu loeschen.
    // Das ist kein Fehler, sondern ein No-op – der Workflow laeuft normal weiter,
    // statt an einer leeren Startseite abzubrechen.
    return captureTaskPreview(context, {
      ok: true,
      status: 'skipped',
      statusMessage: 'Keine geladene Seite und keine Ziel-Domain – es ist keine Session zum Loeschen vorhanden.',
      finalUrl: currentUrl,
      deletedCookieCount: 0,
      storageCleared: false,
    });
  }

  const client = await createClient(page);
  let deletedCookieCount = 0;

  if (clearCookies) {
    const cookies = await allCookies(page, client);
    const matchingCookies = cookies.filter((cookie) => cookieMatchesDomains(cookie, [targetDomain]));

    for (const cookie of matchingCookies) {
      if (await deleteCookie(client, cookie)) {
        deletedCookieCount++;
      }
    }
  }

  const storageCleared = await clearStorage(page, client, /^https?:\/\//i.test(targetUrl) ? targetUrl : currentUrl, targetDomain, clearStorageEnabled);

  if (client) {
    await client.detach().catch(() => {});
  }

  const result = {
    ok: true,
    status: 'success',
    statusMessage: `Session und Cookies fuer ${targetDomain} wurden geloescht.`,
    browserSessionDeleted: true,
    deletedBrowserSession: true,
    domain: targetDomain,
    sessionDomain: targetDomain,
    sessionKey,
    finalUrl: currentUrl,
    targetUrl,
    deletedCookieCount,
    storageCleared,
    clearCookies,
    clearStorage: clearStorageEnabled,
    scriptName: 'delete_browser_session.cjs',
    scriptVersion: 1,
  };

  return captureTaskPreview(context, result, true);
}

module.exports = { key: 'data.delete_browser_session', run };
