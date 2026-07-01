'use strict';

const crypto = require('crypto');
const { captureTaskPreview } = require('../lib/preview.cjs');
const { fillFirstMatchingInput } = require('../lib/fill_input.cjs');

const LOWER = 'abcdefghijkmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';
const SYMBOLS = '!@#$%*-_+=?';

function randomChar(chars) {
  return chars[crypto.randomInt(0, chars.length)];
}

function shuffle(value) {
  const chars = value.split('');

  for (let index = chars.length - 1; index > 0; index -= 1) {
    const next = crypto.randomInt(0, index + 1);
    [chars[index], chars[next]] = [chars[next], chars[index]];
  }

  return chars.join('');
}

function generatePassword(length = 18) {
  const size = Math.max(12, Math.min(64, Number(length || 18)));
  const all = LOWER + UPPER + DIGITS + SYMBOLS;
  let password = randomChar(LOWER) + randomChar(UPPER) + randomChar(DIGITS) + randomChar(SYMBOLS);

  while (password.length < size) {
    password += randomChar(all);
  }

  return shuffle(password);
}

function selectorsFromInput(input = {}) {
  return []
    .concat(input.selector || [])
    .concat(input.selectors || [])
    .concat(input.inputSelector || [])
    .concat(input.input_selector || [])
    .concat([
      'input[type="password"]',
      'input[name*="password" i]',
      'input[id*="password" i]',
      'input[autocomplete="new-password"]',
    ])
    .filter(Boolean);
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Passwort-Eingabe vorhanden.' };
  }

  const password = generatePassword(input.length || input.passwordLength || input.password_length || 18);
  const fillResult = await fillFirstMatchingInput(page, selectorsFromInput(input), password, timeout, { context });

  if (!fillResult.ok) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Passwortfeld konnte gefunden werden.',
      attemptedSelectors: fillResult.attemptedSelectors,
      inputAttempts: fillResult.attempts,
      matchedElementCount: fillResult.matchedElementCount,
      lastFillError: fillResult.lastError || null,
    };
  }

  context.new_password = password;
  context.generated_password = password;
  context.account = {
    ...(context.account || {}),
    password,
    hasPassword: true,
  };

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Wunschpasswort wurde eingetragen.',
    selector: fillResult.selector,
    cachedElement: fillResult.cachedElement === true,
    frameUrl: fillResult.frameUrl,
    passwordFilled: true,
    new_password: password,
    generated_password: password,
    'generated-password': password,
    account: {
      ...(context.account || {}),
      password,
      hasPassword: true,
    },
  });
}

module.exports = { key: 'mail.generate_password', run };
