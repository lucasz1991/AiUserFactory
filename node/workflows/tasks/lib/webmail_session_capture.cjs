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

async function captureWebmailSession(page, account = {}) {
  const finalUrl = typeof page.url === 'function' ? page.url() : '';
  const origins = await captureStorage(page);
  const currentOrigin = originFromUrl(finalUrl);
  const currentStorage = origins.find((entry) => entry.origin === currentOrigin) || {};
  const cookies = (await allCookies(page)).map(safeCookie);

  return {
    capturedAt: new Date().toISOString(),
    provider: account.provider || '',
    email: account.email || '',
    username: account.username || account.email || '',
    finalUrl,
    origin: currentOrigin,
    cookies,
    storage: {
      localStorage: currentStorage.localStorage || {},
      sessionStorage: currentStorage.sessionStorage || {},
    },
    origins,
  };
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
  captureWebmailSession,
  writeSessionPayload,
};
