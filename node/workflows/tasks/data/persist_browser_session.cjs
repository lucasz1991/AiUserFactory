'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { captureBrowserSession, normalizeDomain, writeSessionPayload } = require('../lib/webmail_session_capture.cjs');

function normalizeText(value) {
  return String(value ?? '').trim();
}

function sessionKeyFromDomain(domain) {
  return normalizeDomain(domain).replace(/[^a-z0-9.-]+/g, '-').replace(/^-+|-+$/g, '') || 'browser-session';
}

function normalizeSessionKey(value) {
  return normalizeText(value).toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'browser-session';
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const automation = context.browserSessionAutomation || context.browser_session_automation || {};
  const automatic = input.automatic_browser_session === true || input.automaticBrowserSession === true;
  const finalUrl = page && typeof page.url === 'function' ? page.url() : '';
  const targetDomain = normalizeDomain(input.target_domain || input.targetDomain || input.domain || input.value || finalUrl);
  const effectiveKey = normalizeText(automation.effective_session_key || automation.effectiveSessionKey);
  const explicitlyConfiguredKey = normalizeText(automation.configured_session_key || automation.configuredSessionKey);
  const inputKey = normalizeText(input.session_key || input.sessionKey);
  const inputKeyIsAutomaticScope = automatic && explicitlyConfiguredKey === '' && inputKey !== '' && inputKey === effectiveKey;
  const configuredKey = explicitlyConfiguredKey || (inputKeyIsAutomaticScope ? '' : inputKey);
  const ownerSessionKey = normalizeSessionKey(
    configuredKey
    || effectiveKey
    || targetDomain,
  );
  const domainScopedAutomaticKey = configuredKey === ''
    && effectiveKey !== ''
    && targetDomain !== '';
  const sessionKey = domainScopedAutomaticKey
    ? `${ownerSessionKey}--${sessionKeyFromDomain(targetDomain)}`
    : ownerSessionKey;
  const label = normalizeText(input.label || input.session_label || input.sessionLabel || sessionKey);

  if (!page || typeof page.url !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Speichern der Browser-Session vorhanden.' };
  }

  if (!/^https?:\/\//i.test(finalUrl)) {
    if (automatic) {
      return {
        ok: true,
        status: 'skipped',
        statusMessage: 'Keine gueltige Browserseite geoeffnet; es wurde keine automatische Browser-Session gespeichert.',
        finalUrl,
        sessionKey,
        ownerSessionKey,
        automaticBrowserSession: true,
      };
    }

    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Die aktuelle Seite ist keine gueltige Website-URL.',
      finalUrl,
    };
  }

  const session = await captureBrowserSession(page, {
    domain: targetDomain,
    authorizedOrigins: input.authorized_origins || input.authorizedOrigins
      || automation.authorized_origins || automation.authorizedOrigins || [],
    label,
    type: 'browser-session',
    windowId: context.activeBrowserWindow || context.browserWindow || automation.browser_window || automation.browserWindow || 'main',
    browserWindows: context.browserWindows || context.browser_windows || context.windows || [],
  });
  const cookieCount = Array.isArray(session.cookies) ? session.cookies.length : 0;
  const storageOriginCount = Array.isArray(session.origins) ? session.origins.length : 0;
  const storage = session.storage || {};
  const hasOriginStorage = Array.isArray(session.origins) && session.origins.some((origin) => {
    return Object.keys(origin.localStorage || {}).length > 0
      || Object.keys(origin.sessionStorage || {}).length > 0;
  });
  const hasStorage = Boolean(
    Object.keys(storage.localStorage || {}).length > 0
    || Object.keys(storage.sessionStorage || {}).length > 0
    || hasOriginStorage,
  );

  if (cookieCount === 0 && !hasStorage) {
    if (automatic) {
      return {
        ok: true,
        status: 'skipped',
        statusMessage: 'Die geoeffnete Seite enthaelt keine Cookies oder Browser-Storage-Daten; es wurde keine leere Session gespeichert.',
        finalUrl: session.finalUrl,
        domain: session.domain,
        sessionKey,
        ownerSessionKey,
        automaticBrowserSession: true,
      };
    }

    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Es konnten keine Cookies oder Browser-Storage-Daten fuer diese Website gelesen werden.',
      finalUrl: session.finalUrl,
      domain: session.domain,
      sessionKey,
      ownerSessionKey,
    };
  }

  const written = writeSessionPayload(session, context.workflowTaskRunDirectory || context.runDirectory, 'browser-session');
  const result = {
    ok: true,
    status: 'success',
    statusMessage: 'Browser-Session wurde erfasst und fuer Laravel-Persistierung vorbereitet.',
    browserSessionFilePath: written.filePath,
    browserSessionPayloadHash: written.hash,
    browserSessionSummary: {
      capturedAt: session.capturedAt,
      finalUrl: session.finalUrl,
      origin: session.origin,
      domain: session.domain,
      domains: session.domains,
      cookieDomains: session.cookieDomains,
      cookieCount,
      storageOriginCount,
      sessionKey,
      ownerSessionKey,
      label,
    },
    domain: session.domain,
    domains: session.domains,
    cookieDomains: session.cookieDomains,
    sessionKey,
    ownerSessionKey,
    sessionLabel: label,
    cookieCount,
    finalUrl: session.finalUrl,
    scriptName: 'persist_browser_session.cjs',
    scriptVersion: 1,
    automaticBrowserSession: automatic,
  };

  return captureTaskPreview(context, result, true);
}

module.exports = { key: 'data.persist_browser_session', run };
