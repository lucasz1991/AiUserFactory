'use strict';

const crypto = require('crypto');
const { captureTaskPreview } = require('../lib/preview.cjs');

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

async function passwordInputs(page, selectors, timeout) {
  for (const selector of selectors) {
    try {
      const locator = typeof page.locator === 'function' ? page.locator(selector) : null;
      const count = locator ? await locator.count() : 0;

      if (count > 0) {
        return { locator, count, selector };
      }
    } catch (error) {
      // Try next selector.
    }
  }

  if (typeof page.$$ === 'function') {
    const handles = await page.$$('input[type="password"]');

    if (handles.length > 0) {
      return { handles, count: handles.length, selector: 'input[type="password"]' };
    }
  }

  return { locator: null, handles: [], count: 0, selector: '' };
}

async function fillPasswordTargets(targets, password, timeout) {
  const fillCount = Math.min(Math.max(targets.count, 1), 2);

  if (targets.locator) {
    for (let index = 0; index < fillCount; index += 1) {
      await targets.locator.nth(index).fill(password, { timeout });
    }

    return fillCount;
  }

  for (let index = 0; index < Math.min(targets.handles.length, 2); index += 1) {
    await targets.handles[index].click({ clickCount: 3 }).catch(() => {});
    await targets.handles[index].type(password);
  }

  return Math.min(targets.handles.length, 2);
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Passwort-Eingabe vorhanden.' };
  }

  const password = generatePassword(input.length || input.passwordLength || input.password_length || 18);
  const targets = await passwordInputs(page, selectorsFromInput(input), timeout);

  if (targets.count < 1) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Passwortfeld konnte gefunden werden.',
    };
  }

  const filled = await fillPasswordTargets(targets, password, timeout);

  context.new_password = password;
  context.account = {
    ...(context.account || {}),
    password,
    hasPassword: true,
  };

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: `Wunschpasswort wurde in ${filled} Feld(er) eingetragen.`,
    selector: targets.selector,
    passwordFilled: true,
    new_password: password,
    account: {
      ...(context.account || {}),
      password,
      hasPassword: true,
    },
  });
}

module.exports = { key: 'mail.generate_password', run };
