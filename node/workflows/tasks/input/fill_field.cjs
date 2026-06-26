'use strict';

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const value = String(input.value ?? input.inputValue ?? input.input_value ?? input.text ?? '').trim();
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const selectors = []
    .concat(input.inputSelector || [])
    .concat(input.input_selector || [])
    .concat(input.elementSelector || [])
    .concat(input.element_selector || [])
    .concat(input.selector || [])
    .concat(input.selectors || [])
    .concat(input.name ? [`input[name="${input.name}"]`, `textarea[name="${input.name}"]`] : [])
    .filter(Boolean);

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Input-Fill vorhanden.' };
  }

  if (value === '') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Wert zum Fuellen uebergeben.' };
  }

  const candidates = selectors.length > 0
    ? selectors
    : ['input[type=email]', 'input[name*=email i]', 'input[name*=user i]', 'input[type=text]', 'textarea'];

  for (const selector of candidates) {
    const locator = typeof page.locator === 'function' ? page.locator(selector).first() : null;

    try {
      if (locator && await locator.count() > 0) {
        await locator.fill(value, { timeout });
        return { ok: true, status: 'success', statusMessage: 'Input-Feld wurde gefuellt.', selector };
      }

      if (typeof page.fill === 'function') {
        await page.fill(selector, value, { timeout });
        return { ok: true, status: 'success', statusMessage: 'Input-Feld wurde gefuellt.', selector };
      }
    } catch (error) {
      // Try the next selector.
    }
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein passendes Input-Feld konnte gefuellt werden.',
    attemptedSelectors: candidates,
  };
}

module.exports = { key: 'input.fill_field', run };
