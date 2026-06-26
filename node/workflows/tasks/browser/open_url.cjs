'use strict';

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const url = String(input.url || input.webmailUrl || input.registrationUrl || '').trim();
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
    await page.waitForSelector(input.waitForSelector, {
      state: input.visible === false ? 'attached' : 'visible',
      timeout: Number(input.selectorTimeoutMs || 30000),
    });
  }

  return {
    ok: true,
    status: 'success',
    statusMessage: 'URL wurde geoeffnet.',
    url: typeof page.url === 'function' ? page.url() : url,
  };
}

module.exports = { key: 'browser.open_url', run };
