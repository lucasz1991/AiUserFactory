'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { findFirstVisibleElement } = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const url = String(input.url || input.value || input.inputValue || input.webmailUrl || input.registrationUrl || '').trim();
  const waitUntil = input.waitUntil || 'domcontentloaded';
  const timeout = Number(input.timeoutMs || context.timeoutMs || 120000);

  if (!page || typeof page.goto !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Navigation vorhanden.' };
  }

  if (!url) {
    return { ok: false, status: 'failed', statusMessage: 'Keine URL fuer Navigation uebergeben.' };
  }

  await page.goto(url, { waitUntil, timeout });

  if (input.waitForSelector) {
    const selectorTimeout = Number(input.selectorTimeoutMs || 30000);
    const found = await findFirstVisibleElement(page, input.waitForSelector, selectorTimeout);

    if (!found) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'timeout',
        statusMessage: `Selector wurde nach Navigation nicht gefunden: ${input.waitForSelector}`,
        url: typeof page.url === 'function' ? page.url() : url,
        selector: input.waitForSelector,
      });
    }

    await found.handle.dispose?.().catch(() => {});
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'URL wurde geoeffnet.',
    url: typeof page.url === 'function' ? page.url() : url,
  });
}

module.exports = { key: 'browser.open_url', run };
