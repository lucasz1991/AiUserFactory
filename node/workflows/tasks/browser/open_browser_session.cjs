'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  domainFromUrl,
  domainMatches,
  normalizeDomain,
} = require('../lib/webmail_session_capture.cjs');
const {
  restoreBrowserSession,
  sessionFinalUrl,
} = require('../lib/browser_session_restore.cjs');

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sessionKey(value) {
  return text(value).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
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

    return null;
  }

  if (targetDomain !== '') {
    const domainMatch = sessions.find((session) => sessionMatchesDomain(session, targetDomain));

    if (domainMatch) {
      return domainMatch;
    }
  }

  return [...sessions].sort((left, right) => sessionTimestamp(right) - sessionTimestamp(left))[0] || null;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);
  const automatic = input.automatic_browser_session === true || input.automaticBrowserSession === true;
  const alreadyLoaded = context.browserSessionAutoLoaded === true || context.browser_session_auto_loaded === true;

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Laden der Browser-Session vorhanden.' };
  }

  if (automatic && alreadyLoaded) {
    return {
      ok: true,
      status: 'skipped',
      statusMessage: 'Die Browser-Session wurde in diesem Workflow-Lauf bereits geladen.',
      browserSessionAutoLoaded: true,
      browser_session_auto_loaded: true,
    };
  }

  const sessions = browserSessionsFromContext(context);
  const session = selectSession(sessions, input);
  const fallbackUrl = text(input.fallback_url || input.fallbackUrl || input.url || '');

  if (!session) {
    if (/^https?:\/\//i.test(fallbackUrl)) {
      await page.goto(fallbackUrl, {
        waitUntil: input.waitUntil || 'domcontentloaded',
        timeout,
      });
    }

    const actualUrl = typeof page.url === 'function' ? page.url() : fallbackUrl;

    return captureTaskPreview(context, {
      ok: true,
      status: 'skipped',
      statusMessage: /^https?:\/\//i.test(fallbackUrl)
        ? 'Keine gespeicherte Browser-Session gefunden; die Fallback-URL wurde geoeffnet.'
        : 'Keine gespeicherte Browser-Session gefunden; der Workflow wird ohne Wiederherstellung fortgesetzt.',
      requestedSessionKey: input.session_key || input.sessionKey || '',
      targetDomain: input.target_domain || input.targetDomain || input.domain || '',
      url: actualUrl,
      finalUrl: fallbackUrl || actualUrl,
      fallbackUrl,
      sessionFound: false,
      browserSessionAutoLoaded: automatic,
      browser_session_auto_loaded: automatic,
    }, true);
  }

  const storedFinalUrl = sessionFinalUrl(session);
  const targetUrl = /^https?:\/\//i.test(storedFinalUrl) ? storedFinalUrl : fallbackUrl;

  if (!/^https?:\/\//i.test(targetUrl)) {
    return {
      ok: true,
      status: 'skipped',
      statusMessage: 'Die gespeicherte Browser-Session enthaelt keine gueltige letzte URL und es ist keine Fallback-URL konfiguriert.',
      sessionKey: session.sessionKey || session.session_key || '',
      sessionFound: true,
      browserSessionAutoLoaded: automatic,
      browser_session_auto_loaded: automatic,
    };
  }

  const restored = await restoreBrowserSession(page, session, targetUrl, {
    timeout,
    waitUntil: input.waitUntil || 'domcontentloaded',
  });

  if (restored.cookieAttemptCount > 0 && restored.cookieCount === 0 && restored.storageOriginCount === 0) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Die gespeicherten Cookies und Browser-Storage-Daten konnten nicht geladen werden.',
      sessionKey: session.sessionKey || session.session_key || '',
      cookieAttemptCount: restored.cookieAttemptCount,
      cookieFailureCount: restored.cookieFailureCount,
      storageOriginFailureCount: restored.storageOriginFailureCount,
    };
  }

  const actualUrl = typeof page.url === 'function' ? page.url() : targetUrl;
  const redirected = text(actualUrl) !== '' && text(actualUrl) !== targetUrl;

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: redirected
      ? 'Browser-Session wurde geladen; die gespeicherte URL hat auf eine andere Seite weitergeleitet.'
      : 'Browser-Session wurde geladen und die letzte URL wurde geoeffnet.',
    url: actualUrl,
    finalUrl: targetUrl,
    requestedUrl: targetUrl,
    redirected,
    sessionKey: session.sessionKey || session.session_key || '',
    sessionLabel: session.label || '',
    domain: session.domain || domainFromUrl(targetUrl),
    cookieAttemptCount: restored.cookieAttemptCount,
    cookieCount: restored.cookieCount,
    cookieFailureCount: restored.cookieFailureCount,
    storageOriginCount: restored.storageOriginCount,
    storageOriginFailureCount: restored.storageOriginFailureCount,
    storageStrategy: restored.storageStrategy,
    sessionFound: true,
    fallbackUrl,
    browserSessionAutoLoaded: automatic,
    browser_session_auto_loaded: automatic,
  });
}

module.exports = { key: 'browser.open_browser_session', run };
