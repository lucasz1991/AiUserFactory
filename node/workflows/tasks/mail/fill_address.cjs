'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { fillFirstMatchingInput } = require('../lib/fill_input.cjs');

function selectorsFromInput(input = {}) {
  return []
    .concat(input.selector || [])
    .concat(input.selectors || [])
    .concat(input.inputSelector || [])
    .concat(input.input_selector || [])
    .concat([
      'input[name*="username" i]',
      'input[id*="username" i]',
      'input[autocomplete="username"]',
      'input[name*="email" i]',
      'input[id*="email" i]',
      'input[type="email"]',
      'input[type="text"]',
    ])
    .filter(Boolean);
}

function currentAccount(context = {}) {
  return context.account || context.mailRegistration?.account || null;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const account = currentAccount(context);
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);
  const value = input.useFullEmail === true || input.mode === 'email'
    ? account?.email
    : (account?.username || account?.email);

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Mailadress-Eingabe vorhanden.' };
  }

  if (!value) {
    return { ok: false, status: 'failed', statusMessage: 'Keine generierte Mailadresse zum Eintragen vorhanden.' };
  }

  const fillResult = await fillFirstMatchingInput(page, selectorsFromInput(input), value, timeout, { context });

  if (!fillResult.ok) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein E-Mail-/Username-Feld konnte gefuellt werden.',
      attemptedSelectors: fillResult.attemptedSelectors,
      inputAttempts: fillResult.attempts,
      matchedElementCount: fillResult.matchedElementCount,
      lastFillError: fillResult.lastError || null,
    };
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: `Mailadresse wurde eingetragen: ${account.email || value}`,
    selector: fillResult.selector,
    cachedElement: fillResult.cachedElement === true,
    hoverPreservedDuringFill: fillResult.hoverPreservedDuringFill === true,
    frameUrl: fillResult.frameUrl,
    account: {
      provider: account.provider,
      username: account.username,
      email: account.email,
      webmailUrl: account.webmailUrl,
      generated: account.generated === true,
    },
  });
}

module.exports = { key: 'mail.fill_address', run };
