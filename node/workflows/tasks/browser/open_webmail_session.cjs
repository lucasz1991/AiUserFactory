'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { accountFromContext, normalizeText } = require('../lib/webmail_context.cjs');
const {
  restoreBrowserSession,
  sessionFinalUrl,
} = require('../lib/browser_session_restore.cjs');

function isMailboxSourceToken(value) {
  return ['person', 'reference_person', 'verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master']
    .includes(normalizeText(value).toLowerCase());
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const { account, mailboxSource } = accountFromContext(context, input);
  const session = account.webmailSession || account.webmail_session || null;
  const fallbackUrl = normalizeText(account.webmailUrl || account.webmail_url || 'https://mail.proton.me');
  const valueAsUrl = isMailboxSourceToken(input.value) ? '' : input.value;
  const explicitUrl = normalizeText(input.url || input.target_url || input.targetUrl || input.webmailUrl || input.webmail_url || valueAsUrl);
  const storedFinalUrl = sessionFinalUrl(session || {});
  const targetUrl = explicitUrl || storedFinalUrl || fallbackUrl;
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Webmail-Session vorhanden.' };
  }

  if (!session || typeof session !== 'object') {
    const accountLabel = mailboxSource === 'verification' ? 'Haupt-Verifikationskonto' : 'Bezugs-Person';

    return {
      ok: false,
      status: 'failed',
      statusMessage: `Keine gespeicherte Webmail-Session fuer ${accountLabel} gefunden.`,
      mailboxSource,
    };
  }

  if (!/^https?:\/\//i.test(targetUrl)) {
    return { ok: false, status: 'failed', statusMessage: 'Keine gueltige Webmail-URL fuer die Session vorhanden.' };
  }

  const restored = await restoreBrowserSession(page, session, targetUrl, {
    timeout,
    waitUntil: input.waitUntil || 'domcontentloaded',
  });

  if (restored.cookieAttemptCount > 0 && restored.cookieCount === 0 && restored.storageOriginCount === 0) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Die gespeicherten Webmail-Cookies und Storage-Daten konnten nicht geladen werden.',
      mailboxSource,
      cookieAttemptCount: restored.cookieAttemptCount,
      cookieFailureCount: restored.cookieFailureCount,
      storageOriginFailureCount: restored.storageOriginFailureCount,
    };
  }

  const actualUrl = typeof page.url === 'function' ? page.url() : targetUrl;
  const redirected = normalizeText(actualUrl) !== '' && normalizeText(actualUrl) !== targetUrl;

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: redirected
      ? 'Webmail-Session wurde geladen; die gespeicherte URL hat auf eine andere Seite weitergeleitet.'
      : 'Webmail-Session wurde geladen und die zuletzt aktive URL wurde geoeffnet.',
    url: actualUrl,
    finalUrl: targetUrl,
    requestedUrl: targetUrl,
    redirected,
    targetUrlSource: explicitUrl !== '' ? 'input' : (storedFinalUrl !== '' ? 'session' : 'fallback'),
    mailboxSource,
    mailboxEmail: account.email || '',
    cookieAttemptCount: restored.cookieAttemptCount,
    cookieCount: restored.cookieCount,
    cookieFailureCount: restored.cookieFailureCount,
    storageRestored: restored.storageOriginCount > 0,
    storageOriginCount: restored.storageOriginCount,
    storageOriginFailureCount: restored.storageOriginFailureCount,
    storageStrategy: restored.storageStrategy,
  });
}

module.exports = { key: 'browser.open_webmail_session', run };
