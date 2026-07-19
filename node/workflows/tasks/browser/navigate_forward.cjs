'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const waitUntil = input.waitUntil || 'domcontentloaded';
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goForward !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die History-Navigation vorhanden.' };
  }

  try {
    const response = await page.goForward({ waitUntil, timeout });

    if (response === null) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine naechste Seite in der Browser-History.',
        url: typeof page.url === 'function' ? page.url() : null,
      });
    }

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Naechste Seite wurde geoeffnet.',
      url: typeof page.url === 'function' ? page.url() : null,
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Navigation zur naechsten Seite ist fehlgeschlagen.',
      error: error.message,
      url: typeof page.url === 'function' ? page.url() : null,
    });
  }
}

module.exports = { key: 'browser.navigate_forward', run };
