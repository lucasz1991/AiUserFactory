'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

function workflowContext(context = {}) {
  return context.workflow && typeof context.workflow === 'object' ? context.workflow : {};
}

function normalizeText(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '')
    .trim();
}

function normalizeDomain(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/^@+/, '')
    .replace(/[^a-z0-9.-]+/g, '')
    .replace(/^\.+|\.+$/g, '')
    .trim();
}

function randomNumber(min = 10, max = 9999) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function unique(values) {
  return Array.from(new Set(values.filter(Boolean)));
}

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

function personNameParts(person = {}) {
  const displayParts = String(person.displayName || '').split(/\s+/).filter(Boolean);
  const firstName = normalizeText(person.firstName || displayParts[0] || 'mail');
  const lastName = normalizeText(person.lastName || displayParts.slice(1).join('') || 'account');

  return { firstName, lastName };
}

function buildCandidates(person, domain) {
  const { firstName, lastName } = personNameParts(person);
  const firstInitial = firstName.slice(0, 1);
  const lastInitial = lastName.slice(0, 1);
  const year = String(new Date().getFullYear());
  const shortYear = year.slice(-2);
  const seeds = [
    `${firstName}${lastName}`,
    `${firstName}.${lastName}`,
    `${firstName}${lastInitial}`,
    `${firstInitial}${lastName}`,
    `${lastName}${firstName}`,
    `${firstName}${randomNumber(10, 999)}`,
    `${firstName}.${lastName}${randomNumber(10, 999)}`,
    `${firstName}${lastName}${randomNumber(10, 9999)}`,
    `${firstInitial}${lastName}${shortYear}`,
    `${firstName}${lastInitial}${randomNumber(10, 999)}`,
    `${lastName}${firstInitial}${randomNumber(10, 999)}`,
  ];

  return unique(seeds)
    .map((username) => ({
      username,
      email: domain ? `${username}@${domain}` : username,
    }));
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

async function run(context = {}) {
  const workflow = workflowContext(context);
  const input = context.input || {};
  const page = context.page;
  const person = workflow.person || context.person || null;
  const domain = normalizeDomain(input.domain || workflow.mailDomain || 'proton.me') || 'proton.me';
  const provider = String(input.provider || workflow.provider || workflow.provider_key || 'proton').trim() || 'proton';
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);

  if (!person) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Person fuer Mailadress-Generierung gefunden.',
    };
  }

  if (!page) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Page-Handle fuer die Username-Eingabe vorhanden.',
    };
  }

  const candidates = buildCandidates(person, domain);

  if (candidates.length < 1) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Es konnte kein Mailadress-Kandidat erzeugt werden.',
    };
  }

  context.person = person;
  context.mailRegistration = {
    ...(context.mailRegistration || {}),
    candidates,
    candidateIndex: 0,
    domain,
    provider,
  };
  context.account = {
    ...(context.account || {}),
    provider,
    username: candidates[0].username,
    email: candidates[0].email,
    webmailUrl: provider.toLowerCase().includes('gmx') ? 'https://www.gmx.net' : 'https://mail.proton.me',
    generated: true,
  };
  const selector = await fillFirstMatching(page, selectorsFromInput(input), context.account.username, timeout);

  if (!selector) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Username-/E-Mail-Feld konnte fuer den generierten Wert gefuellt werden.',
      candidateCount: candidates.length,
    };
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: `Username-Kandidat wurde eingetragen: ${context.account.username}`,
    selector,
    account: {
      provider: context.account.provider,
      username: context.account.username,
      email: context.account.email,
      webmailUrl: context.account.webmailUrl,
      generated: true,
    },
    candidateCount: candidates.length,
  });
}

module.exports = { key: 'mail.generate_address', run };
