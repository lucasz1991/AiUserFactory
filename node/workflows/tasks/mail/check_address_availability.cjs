'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

const DEFAULT_UNAVAILABLE_PATTERNS = [
  'already taken',
  'already exists',
  'not available',
  'unavailable',
  'is taken',
  'username taken',
  'address taken',
  'adresse ist vergeben',
  'bereits vergeben',
  'nicht verfuegbar',
  'nicht verfügbar',
  'existiert bereits',
  'schon vergeben',
  'ist vergeben',
];

const DEFAULT_AVAILABLE_PATTERNS = [
  'available',
  'is available',
  'verfuegbar',
  'verfügbar',
  'kann verwendet werden',
];

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

function configuredPatterns(value, fallback) {
  if (Array.isArray(value)) {
    return value.map((entry) => String(entry).toLowerCase()).filter(Boolean);
  }

  if (typeof value === 'string' && value.trim() !== '') {
    return value.split('|').map((entry) => entry.trim().toLowerCase()).filter(Boolean);
  }

  return fallback;
}

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

function currentCandidate(context = {}) {
  const registration = context.mailRegistration || {};
  const candidates = Array.isArray(registration.candidates) ? registration.candidates : [];
  const index = Number(registration.candidateIndex || 0);

  return candidates[index] || null;
}

function advanceCandidate(context = {}) {
  const registration = context.mailRegistration || {};
  const candidates = Array.isArray(registration.candidates) ? registration.candidates : [];
  const nextIndex = Number(registration.candidateIndex || 0) + 1;
  const candidate = candidates[nextIndex] || null;

  if (!candidate) {
    return null;
  }

  context.mailRegistration = {
    ...registration,
    candidateIndex: nextIndex,
  };
  context.account = {
    ...(context.account || {}),
    username: candidate.username,
    email: candidate.email,
    generated: true,
  };

  return candidate;
}

async function fillFirstMatching(page, selectors, value, timeout) {
  for (const selector of selectors) {
    try {
      const locator = typeof page.locator === 'function' ? page.locator(selector).first() : null;

      if (locator && await locator.count() > 0) {
        await locator.fill(String(value), { timeout });
        return selector;
      }

      if (typeof page.fill === 'function') {
        await page.fill(selector, String(value), { timeout });
        return selector;
      }
    } catch (error) {
      // Try next selector.
    }
  }

  return null;
}

async function pageSnapshot(page) {
  return page.evaluate(() => ({
    url: window.location.href,
    title: document.title,
    text: document.body ? document.body.innerText.slice(0, 30000) : '',
  }));
}

function matchedPattern(text, patterns) {
  const normalized = String(text || '').toLowerCase();

  return patterns.find((pattern) => pattern !== '' && normalized.includes(pattern)) || '';
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);
  const maxAttempts = Math.max(1, Number(input.maxAttempts || input.max_attempts || 8));
  const settleMs = Math.max(500, Number(input.settleMs || input.settle_ms || 1800));
  const unavailablePatterns = configuredPatterns(input.unavailablePatterns || input.unavailable_patterns, DEFAULT_UNAVAILABLE_PATTERNS);
  const availablePatterns = configuredPatterns(input.availablePatterns || input.available_patterns, DEFAULT_AVAILABLE_PATTERNS);
  const selectors = selectorsFromInput(input);
  const useFullEmail = input.useFullEmail === true || input.mode === 'email';
  const tried = [];

  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Mailadress-Pruefung vorhanden.' };
  }

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const account = context.account || currentCandidate(context);
    const value = useFullEmail ? account?.email : (account?.username || account?.email);

    if (!account || !value) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine Mailadress-Kandidaten fuer die Verfuegbarkeitspruefung vorhanden.',
        tried,
      };
    }

    tried.push(account.email || value);
    await sleep(settleMs);

    const snapshot = await pageSnapshot(page);
    const unavailable = matchedPattern(snapshot.text, unavailablePatterns);
    const available = matchedPattern(snapshot.text, availablePatterns);

    if (!unavailable || available) {
      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: `Mailadresse ist nutzbar: ${account.email || value}`,
        account: {
          provider: account.provider,
          username: account.username,
          email: account.email,
          webmailUrl: account.webmailUrl,
          generated: account.generated === true,
        },
        matchedPattern: available || null,
        tried,
      });
    }

    const next = advanceCandidate(context);

    if (!next) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: 'Alle Mailadress-Kandidaten sind vergeben.',
        matchedPattern: unavailable,
        tried,
      });
    }

    const nextValue = useFullEmail ? next.email : next.username;
    const selector = await fillFirstMatching(page, selectors, nextValue, timeout);

    if (!selector) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Naechster Mailadress-Kandidat konnte nicht eingetragen werden.',
        tried,
      };
    }
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Keine freie Mailadresse innerhalb der Versuchszahl gefunden.',
    tried,
  });
}

module.exports = { key: 'mail.check_address_availability', run };
