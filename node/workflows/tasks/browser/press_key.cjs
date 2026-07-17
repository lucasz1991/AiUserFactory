'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const key = String(input.key ?? input.value ?? input.input ?? '').trim();

  if (!page?.keyboard || typeof page.keyboard.press !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Browser-Keyboard fuer die Taste vorhanden.' };
  }
  if (!key || key.length > 80 || !/^[A-Za-z0-9+_-]+$/.test(key)) {
    return { ok: false, status: 'failed', statusMessage: 'Die Taste ist leer oder nicht im erlaubten Format.' };
  }

  try {
    await page.keyboard.press(key);

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: `Taste ${key} wurde gesendet.`,
      key,
      url: typeof page.url === 'function' ? page.url() : null,
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: `Taste ${key} konnte nicht gesendet werden.`,
      key,
      error: error.message,
    });
  }
}

module.exports = { key: 'browser.press_key', run };
