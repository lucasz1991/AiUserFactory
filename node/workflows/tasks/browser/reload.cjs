'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const waitUntil = input.waitUntil || 'domcontentloaded';
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.reload !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer das Neuladen vorhanden.' };
  }

  try {
    await page.reload({ waitUntil, timeout });

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Seite wurde neu geladen.',
      url: typeof page.url === 'function' ? page.url() : null,
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Seite konnte nicht neu geladen werden.',
      error: error.message,
      url: typeof page.url === 'function' ? page.url() : null,
    });
  }
}

module.exports = { key: 'browser.reload', run };
