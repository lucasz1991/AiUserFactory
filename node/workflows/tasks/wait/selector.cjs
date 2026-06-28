'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const { findVisibleElement } = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const selector = String(input.elementSelector || input.element_selector || input.inputSelector || input.input_selector || input.selector || '').trim();
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Selector-Wait vorhanden.' };
  }

  if (!selector) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector fuer Warte-Task uebergeben.' };
  }

  try {
    startTaskPreview(context);
    let handle = await findVisibleElement(page, selector, timeout);

    if (!handle && typeof context.refreshActivePage === 'function') {
      const refreshedPage = await context.refreshActivePage().catch(() => null);

      if (refreshedPage && refreshedPage !== page) {
        handle = await findVisibleElement(refreshedPage, selector, Math.min(timeout, 15000));
      }
    }

    if (!handle) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'timeout',
        statusMessage: `Selector wurde innerhalb des Timeouts nicht gefunden: ${selector}`,
        selector,
      });
    }

    await handle.dispose?.().catch(() => {});

    return captureTaskPreview(context, { ok: true, status: 'success', statusMessage: 'Selector wurde gefunden.', selector });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'timeout',
      statusMessage: `Selector wurde innerhalb des Timeouts nicht gefunden: ${selector}`,
      selector,
    });
  }
}

module.exports = { key: 'wait.selector', run };
