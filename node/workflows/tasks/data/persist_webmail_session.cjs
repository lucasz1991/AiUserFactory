'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { accountFromContext } = require('../lib/webmail_context.cjs');
const { captureWebmailSession, writeSessionPayload } = require('../lib/webmail_session_capture.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const { account, mailboxSource } = accountFromContext(context, input);

  if (!page || typeof page.url !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Speichern der Webmail-Session vorhanden.' };
  }

  const session = await captureWebmailSession(page, account);
  const cookieCount = Array.isArray(session.cookies) ? session.cookies.length : 0;
  const finalUrl = session.finalUrl || '';
  const storage = session.storage || {};

  if (!/^https?:\/\//i.test(finalUrl)) {
    return { ok: false, status: 'failed', statusMessage: 'Die aktuelle Seite ist kein gueltiges Webmailportal.' };
  }

  if (
    cookieCount === 0
    && Object.keys(storage.localStorage || {}).length === 0
    && Object.keys(storage.sessionStorage || {}).length === 0
  ) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Es konnten keine Cookies oder Browser-Storage-Daten fuer die Webmail-Session gelesen werden.',
      finalUrl,
      mailboxSource,
    };
  }

  const written = writeSessionPayload(session, context.workflowTaskRunDirectory || context.runDirectory, 'webmail-session');
  const result = {
    ok: true,
    status: 'success',
    statusMessage: 'Webmail-Session wurde erfasst und fuer Laravel-Persistierung vorbereitet.',
    mailboxSource,
    mailboxEmail: account.email || session.email || '',
    providerKey: account.provider || session.provider || '',
    webmailSessionFilePath: written.filePath,
    sessionPayloadHash: written.hash,
    sessionSummary: {
      capturedAt: session.capturedAt,
      finalUrl: session.finalUrl,
      origin: session.origin,
      domain: session.domain,
      domains: session.domains,
      cookieDomains: session.cookieDomains,
      cookieCount,
      storageOriginCount: Array.isArray(session.origins) ? session.origins.length : 0,
    },
    domain: session.domain,
    domains: session.domains,
    cookieDomains: session.cookieDomains,
    cookieCount,
    finalUrl,
    scriptName: 'persist_webmail_session.cjs',
    scriptVersion: 1,
  };

  return captureTaskPreview(context, result, true);
}

module.exports = { key: 'data.persist_webmail_session', run };
