'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

const SUPPORTED_KEYS = Object.freeze({
  enter: 'Enter',
  return: 'Enter',
  eingabe: 'Enter',
  tab: 'Tab',
  tabulator: 'Tab',
});

function keyboardKey(input = {}) {
  // `input.key` is deliberately not considered here. In the workflow runtime it
  // identifies the task card (for example `search-submit`) and is not a key that
  // Playwright can press.
  const configuredKey = String(
    input.keyboard_key
    ?? input.keyboardKey
    ?? input.value
    ?? input.inputValue
    ?? input.input_value
    ?? input.input
    ?? '',
  ).trim();

  return {
    configuredKey,
    key: SUPPORTED_KEYS[configuredKey.toLowerCase()] || '',
  };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const { configuredKey, key } = keyboardKey(input);

  if (!page?.keyboard || typeof page.keyboard.press !== 'function') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Browser-Keyboard fuer die Taste vorhanden.',
      reason_code: 'browser_keyboard_missing',
    };
  }
  if (!key) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: configuredKey === ''
        ? 'Bitte eine Taste auswaehlen. Erlaubt sind Enter und Tab.'
        : `Die Taste ${configuredKey} wird nicht unterstuetzt. Erlaubt sind Enter und Tab.`,
      reason_code: 'keyboard_key_unsupported',
      configuredKey,
      allowedKeys: ['Enter', 'Tab'],
    };
  }

  try {
    await page.keyboard.press(key);

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: `Taste ${key} wurde gesendet.`,
      key,
      configuredKey,
      url: typeof page.url === 'function' ? page.url() : null,
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: `Taste ${key} konnte nicht gesendet werden.`,
      key,
      configuredKey,
      reason_code: 'keyboard_press_failed',
      error: error.message,
    });
  }
}

module.exports = { key: 'browser.press_key', run, keyboardKey };
