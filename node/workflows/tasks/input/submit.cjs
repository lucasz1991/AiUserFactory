'use strict';

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const selectors = []
    .concat(input.selector || [])
    .concat(input.selectors || [])
    .concat([
      'button[type=submit]',
      'input[type=submit]',
      'button:has-text("Weiter")',
      'button:has-text("Login")',
      'button:has-text("Anmelden")',
      'button:has-text("Create")',
    ])
    .filter(Boolean);

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Submit vorhanden.' };
  }

  for (const selector of selectors) {
    try {
      const locator = typeof page.locator === 'function' ? page.locator(selector).first() : null;

      if (locator && await locator.count() > 0) {
        await locator.click({ timeout });
        return { ok: true, status: 'success', statusMessage: 'Submit wurde ausgeloest.', selector };
      }
    } catch (error) {
      // Try the next selector.
    }
  }

  return { ok: false, status: 'failed', statusMessage: 'Kein Submit-Element gefunden.', attemptedSelectors: selectors };
}

module.exports = { key: 'input.submit', run };
