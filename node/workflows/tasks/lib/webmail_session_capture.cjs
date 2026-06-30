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

function safeCookie(cookie) {
  const nextCookie = { ...cookie };
  delete nextCookie.partitionKey;
  delete nextCookie.sourcePort;
  delete nextCookie.sourceScheme;

  return nextCookie;
}

async function captureBrowserSession(page, options = {}) {
  const account = options.account || {};
  const finalUrl = typeof page.url === 'function' ? page.url() : '';
  const origins = await captureStorage(page);
  const currentOrigin = originFromUrl(finalUrl);
  const currentStorage = origins.find((entry) => entry.origin === currentOrigin) || {};
  const primaryDomain = normalizeDomain(options.domain || options.targetDomain || domainFromUrl(finalUrl));
  const storageDomains = uniqueValues(origins.map((entry) => domainFromUrl(entry.url || entry.origin)));
  const relatedDomains = uniqueValues([primaryDomain, ...storageDomains]);
  const includeAllCookies = options.includeAllCookies === true;
  const cookies = (await allCookies(page))
    .filter((cookie) => includeAllCookies || relatedDomains.length === 0 || cookieMatchesDomains(cookie, relatedDomains))
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
  writeSessionPayload,
};
