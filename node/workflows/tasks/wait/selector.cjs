'use strict';

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const selector = String(input.selector || '').trim();
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);

  if (!page || typeof page.waitForSelector !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Selector-Wait vorhanden.' };
  }

  if (!selector) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector fuer Warte-Task uebergeben.' };
  }

  try {
    await page.waitForSelector(selector, { state: input.state || 'visible', timeout });

    return { ok: true, status: 'success', statusMessage: 'Selector wurde gefunden.', selector };
  } catch (error) {
    return {
      ok: false,
      status: 'timeout',
      statusMessage: `Selector wurde innerhalb des Timeouts nicht gefunden: ${selector}`,
      selector,
    };
  }
}

module.exports = { key: 'wait.selector', run };
