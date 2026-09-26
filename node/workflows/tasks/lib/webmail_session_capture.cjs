'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

function originFromUrl(value) {
  try {
    return new URL(String(value || '')).origin;
  } catch {
    return '';
  }
}

function normalizeDomain(value) {
  const rawValue = String(value || '').trim().toLowerCase();

  if (rawValue === '') {
    return '';
  }

  try {
    return new URL(rawValue).hostname.replace(/^\.+/, '').replace(/\.+$/, '');
  } catch {
    return rawValue
      .replace(/^https?:\/\//i, '')
      .replace(/^\/+/, '')
      .split('/')[0]
      .split(':')[0]
      .replace(/^\.+/, '')
      .replace(/\.+$/, '');
  }
}

function domainFromUrl(value) {
  try {
    return normalizeDomain(new URL(String(value || '')).hostname);
  } catch {
    return '';
  }
}

function uniqueValues(values = []) {
  return Array.from(new Set(values.map((value) => String(value || '').trim()).filter(Boolean)));
}

function cookieDomain(cookie = {}) {
  return normalizeDomain(cookie.domain || cookie.url || '');
}

function domainMatches(candidate, target) {
  const candidateDomain = normalizeDomain(candidate);
  const targetDomain = normalizeDomain(target);

  if (candidateDomain === '' || targetDomain === '') {
    return false;
  }

  return candidateDomain === targetDomain
    || candidateDomain.endsWith(`.${targetDomain}`)
    || targetDomain.endsWith(`.${candidateDomain}`);
}

function normalizeOrigin(value) {
  const rawValue = String(value || '').trim();

  if (rawValue === '') {
    return '';
  }

  try {
    return new URL(/^https?:\/\//i.test(rawValue) ? rawValue : `https://${rawValue}`).origin;
  } catch {
    return '';
  }
}

function cookieMatchesDomains(cookie = {}, domains = []) {
  const currentDomain = cookieDomain(cookie);

  return domains.some((domain) => domainMatches(currentDomain, domain));
}

async function allCookies(page) {
  if (!page || typeof page.target !== 'function') {
    return [];
  }

  try {
    const client = await page.target().createCDPSession();
    const result = await client.send('Network.getAllCookies');
    await client.detach().catch(() => {});

    return Array.isArray(result.cookies) ? result.cookies : [];
  } catch {
    if (typeof page.cookies === 'function') {
      return page.cookies().catch(() => []);
    }
  }

  return [];
}

async function storageForFrame(frame) {
  try {
    return await frame.evaluate(() => {
      const localStorageEntries = {};
      const sessionStorageEntries = {};

      for (let index = 0; index < window.localStorage.length; index += 1) {
        const key = window.localStorage.key(index);

        if (key !== null) {
          localStorageEntries[key] = window.localStorage.getItem(key);
        }
      }

      for (let index = 0; index < window.sessionStorage.length; index += 1) {
        const key = window.sessionStorage.key(index);

        if (key !== null) {
          sessionStorageEntries[key] = window.sessionStorage.getItem(key);
        }
      }

      return {
        url: window.location.href,
        origin: window.location.origin,
        localStorage: localStorageEntries,
        sessionStorage: sessionStorageEntries,
      };
    });
  } catch {
    return null;
  }
}

async function captureStorage(page) {
  const frames = typeof page.frames === 'function' ? page.frames() : [page.mainFrame?.()].filter(Boolean);
  const byOrigin = new Map();

  for (const frame of frames) {
    if (!frame) {
      continue;
    }

    const storage = await storageForFrame(frame);

    if (!storage || !storage.origin) {
      continue;
    }

    const current = byOrigin.get(storage.origin) || {
      origin: storage.origin,
      url: storage.url,
      localStorage: {},
      sessionStorage: {},
    };

    current.localStorage = { ...current.localStorage, ...(storage.localStorage || {}) };
    current.sessionStorage = { ...current.sessionStorage, ...(storage.sessionStorage || {}) };
    byOrigin.set(storage.origin, current);
  }

  return Array.from(byOrigin.values());
}

async function pagesInContext(page) {
  try {
    const context = typeof page?.browserContext === 'function' ? page.browserContext() : null;
    const pages = context && typeof context.pages === 'function' ? await context.pages() : [];

    return pages.length > 0 ? pages : [page];
  } catch {
    return [page];
  }
}

function logicalWindowId(page, index, activePage, options = {}) {
  if (page === activePage) {
    return String(options.windowId || options.activeWindowId || `tab-${index + 1}`);
  }

  const pageUrl = typeof page?.url === 'function' ? page.url() : '';
  const knownWindows = Array.isArray(options.browserWindows) ? options.browserWindows : [];
  const known = knownWindows.find((entry) => {
    const url = String(entry?.url ?? entry?.finalUrl ?? '').trim();

    return url !== '' && originFromUrl(url) === originFromUrl(pageUrl);
  });

  return String(known?.key || known?.name || `tab-${index + 1}`);
}

function safeCookie(cookie) {
  const nextCookie = { ...cookie };
  delete nextCookie.sourcePort;
  delete nextCookie.sourceScheme;

  if (nextCookie.partitionKey && typeof nextCookie.partitionKey === 'object') {
    const partitionKey = {};
    const topLevelSite = String(nextCookie.partitionKey.topLevelSite || '').trim();

    if (topLevelSite !== '') {
      partitionKey.topLevelSite = topLevelSite;
    }

    if (typeof nextCookie.partitionKey.hasCrossSiteAncestor === 'boolean') {
      partitionKey.hasCrossSiteAncestor = nextCookie.partitionKey.hasCrossSiteAncestor;
    }

    nextCookie.partitionKey = Object.keys(partitionKey).length > 0 ? partitionKey : undefined;
  }

  return nextCookie;
}

async function captureBrowserSession(page, options = {}) {
  const account = options.account || {};
  const finalUrl = typeof page.url === 'function' ? page.url() : '';
  const currentOrigin = originFromUrl(finalUrl);
  const allowedOrigins = new Set([
    currentOrigin,
    ...(Array.isArray(options.authorizedOrigins) ? options.authorizedOrigins : []),
    ...(Array.isArray(options.allowedOrigins) ? options.allowedOrigins : []),
  ].map(normalizeOrigin).filter(Boolean));
  const pages = await pagesInContext(page);
  const originsByKey = new Map();
  const windows = [];

  for (const [index, browserPage] of pages.entries()) {
    const pageUrl = typeof browserPage?.url === 'function' ? browserPage.url() : '';
    const windowId = logicalWindowId(browserPage, index, page, options);
    const windowOrigins = (await captureStorage(browserPage))
      .filter((entry) => allowedOrigins.has(normalizeOrigin(entry.origin)));

    if (!allowedOrigins.has(normalizeOrigin(pageUrl)) && windowOrigins.length === 0) {
      continue;
    }

    for (const entry of windowOrigins) {
      const current = originsByKey.get(entry.origin) || {
        origin: entry.origin,
        url: entry.url,
        localStorage: {},
        sessionStorage: {},
      };
      current.localStorage = { ...current.localStorage, ...(entry.localStorage || {}) };

      // Backward-compatible primary-tab field; sessionStorage is tab-scoped
      // and is deliberately not merged across tabs.
      if (browserPage === page) {
        current.sessionStorage = { ...(entry.sessionStorage || {}) };
      }

      originsByKey.set(entry.origin, current);
    }

    if (windowOrigins.length > 0) {
      windows.push({
        windowId,
        url: pageUrl,
        active: browserPage === page,
        origins: windowOrigins.map((entry) => ({
          origin: entry.origin,
          url: entry.url,
          sessionStorage: entry.sessionStorage || {},
        })),
      });
    }
  }

  const origins = Array.from(originsByKey.values());
  const currentStorage = origins.find((entry) => entry.origin === currentOrigin) || {};
  const primaryDomain = normalizeDomain(options.domain || options.targetDomain || domainFromUrl(finalUrl));
  const storageDomains = uniqueValues(origins.map((entry) => domainFromUrl(entry.url || entry.origin)));
  const relatedDomains = uniqueValues([primaryDomain, ...storageDomains]);
  const includeAllCookies = options.includeAllCookies === true;
  const cookies = (await allCookies(page))
    .filter((cookie) => includeAllCookies || Array.from(allowedOrigins).some((origin) => {
      const originDomain = domainFromUrl(origin);
      const candidateDomain = cookieDomain(cookie);

      return candidateDomain !== '' && originDomain !== ''
        && (originDomain === candidateDomain || originDomain.endsWith(`.${candidateDomain}`));
    }))
    .map(safeCookie);
  const cookieDomains = uniqueValues(cookies.map(cookieDomain));
  const domains = uniqueValues([primaryDomain, ...storageDomains, ...cookieDomains]);

  return {
    capturedAt: new Date().toISOString(),
    type: options.type || 'browser-session',
    label: options.label || '',
    provider: account.provider || '',
    email: account.email || '',
    username: account.username || account.email || '',
    finalUrl,
    origin: currentOrigin,
    domain: primaryDomain,
    domains,
    cookieDomains,
    cookies,
    schemaVersion: 2,
    capabilities: {
      cookies: 'captured',
      cookiePartitionKeys: 'preserved_when_present',
      localStorage: 'captured_per_origin',
      sessionStorage: 'captured_per_window_and_origin',
      indexedDb: 'not_captured',
      serviceWorkers: 'not_captured',
    },
    activeWindowId: String(options.windowId || options.activeWindowId || 'tab-1'),
    windows,
    storage: {
      localStorage: currentStorage.localStorage || {},
      sessionStorage: currentStorage.sessionStorage || {},
    },
    origins,
  };
}

async function captureWebmailSession(page, account = {}) {
  return captureBrowserSession(page, {
    account,
    type: 'webmail-session',
  });
}

function writeSessionPayload(session, directory, prefix = 'webmail-session') {
  const payload = JSON.stringify(session, null, 2);
  const hash = crypto.createHash('sha256').update(payload).digest('hex');
  const runDirectory = String(directory || process.cwd());
  fs.mkdirSync(runDirectory, { recursive: true });
  const filePath = path.join(runDirectory, `${prefix}-${Date.now()}-${hash.slice(0, 12)}.json`);
  fs.writeFileSync(filePath, payload);

  return { filePath, hash, payload };
}

module.exports = {
  captureBrowserSession,
  captureWebmailSession,
  cookieMatchesDomains,
  domainFromUrl,
  domainMatches,
  normalizeDomain,
  normalizeOrigin,
  writeSessionPayload,
};
