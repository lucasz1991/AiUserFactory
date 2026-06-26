'use strict';

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

async function run(context = {}) {
  const workflow = workflowContext(context);
  const input = context.input || {};
  const person = workflow.person || context.person || null;
  const domain = normalizeDomain(input.value || input.domain || workflow.mailDomain || 'proton.me') || 'proton.me';
  const provider = String(input.provider || workflow.provider || workflow.provider_key || 'proton').trim() || 'proton';

  if (!person) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Person fuer Mailadress-Generierung gefunden.',
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

  return {
    ok: true,
    status: 'success',
    statusMessage: `Mailadress-Kandidat vorbereitet: ${context.account.email}`,
    account: {
      provider: context.account.provider,
      username: context.account.username,
      email: context.account.email,
      webmailUrl: context.account.webmailUrl,
      generated: true,
    },
    candidateCount: candidates.length,
  };
}

module.exports = { key: 'mail.generate_address', run };
