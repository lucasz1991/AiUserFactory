'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { fillFirstMatchingInput } = require('../lib/fill_input.cjs');

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

  const fillResult = await fillFirstMatchingInput(page, candidates, value, timeout, { context });

  if (fillResult.ok) {
    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Input-Feld wurde gefuellt.',
      selector: fillResult.selector,
      cachedElement: fillResult.cachedElement === true,
      hoverPreservedDuringFill: fillResult.hoverPreservedDuringFill === true,
      frameUrl: fillResult.frameUrl,
    });
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein passendes Input-Feld konnte gefuellt werden.',
    attemptedSelectors: fillResult.attemptedSelectors,
    inputAttempts: fillResult.attempts,
    matchedElementCount: fillResult.matchedElementCount,
    lastFillError: fillResult.lastError || null,
  };
}

module.exports = { key: 'input.fill_field', run };
