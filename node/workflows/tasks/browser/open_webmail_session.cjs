'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { accountFromContext, normalizeText } = require('../lib/webmail_context.cjs');
const {
  restoreBrowserSession,
  sessionFinalUrl,
} = require('../lib/browser_session_restore.cjs');

function booleanValue(value) {
  return value === true || value === 1 || value === '1' || String(value || '').toLowerCase() === 'true';
}

function sessionTimestamp(session = {}) {
  const value = normalizeText(
    session.capturedAt
    || session.captured_at
    || session.updatedAt
    || session.updated_at
    || '',
  );
  const timestamp = Date.parse(value);

  return Number.isFinite(timestamp) ? timestamp : 0;
}

function mailboxIdentity(account = {}) {
  return normalizeText(account.email || account.username || '').toLowerCase();
}

function accountSession(account = {}) {
  const session = account.webmailSession || account.webmail_session || null;

  return session && typeof session === 'object' ? session : null;
}

function sessionCandidates(context = {}, input = {}) {
  const requested = accountFromContext(context, input);
  const requestedIdentity = mailboxIdentity(requested.account);
  const strictSource = booleanValue(input.strict_mailbox_source || input.strictMailboxSource);
  const candidates = [{ ...requested, session: accountSession(requested.account), requested: true }];

  if (!strictSource && requestedIdentity !== '') {
    for (const mailboxSource of ['person', 'verification']) {
      const candidate = accountFromContext(context, {
        ...input,
        value: '',
        account_source: mailboxSource,
        accountSource: mailboxSource,
        mailbox_source: mailboxSource,
        mailboxSource,
        script_person_source: mailboxSource,
        scriptPersonSource: mailboxSource,
      });

      if (
        candidate.mailboxSource === requested.mailboxSource
        || mailboxIdentity(candidate.account) !== requestedIdentity
      ) {
        continue;
      }

      candidates.push({ ...candidate, session: accountSession(candidate.account), requested: false });
    }
  }

  return candidates
    .filter((candidate) => candidate.session)
    .sort((left, right) => {
      const timestampDifference = sessionTimestamp(right.session) - sessionTimestamp(left.session);

      return timestampDifference !== 0 ? timestampDifference : Number(right.requested) - Number(left.requested);
    });
}

function isMailboxSourceToken(value) {
  return ['person', 'reference_person', 'verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master']
    .includes(normalizeText(value).toLowerCase());
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const requested = accountFromContext(context, input);
  const candidates = sessionCandidates(context, input);
  const selected = candidates[0] || { ...requested, session: null };
  const { account, mailboxSource, session } = selected;
  const requestedMailboxSource = requested.mailboxSource;
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
      mailboxSource: requestedMailboxSource,
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
    requestedMailboxSource,
    mailboxSourceAdjusted: mailboxSource !== requestedMailboxSource,
    sessionCapturedAt: session.capturedAt || session.captured_at || session.updatedAt || session.updated_at || null,
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
