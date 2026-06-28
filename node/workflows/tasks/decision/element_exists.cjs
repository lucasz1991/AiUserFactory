'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { elementSnapshot, findVisibleElement } = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const selector = String(
    input.elementSelector
    || input.element_selector
    || input.selector
    || '',
  ).trim();
  const timeout = Math.max(0, Number(
    input.timeoutMs
    || (Number(input.timeout_seconds || 0) * 1000)
    || context.timeoutMs
    || 15000,
  ));
  const deadline = Date.now() + timeout;

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die IF-Element-Pruefung vorhanden.' };
  }

  if (!selector) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector fuer die IF-Element-Pruefung angegeben.' };
  }

  let handle = await findVisibleElement(page, selector, timeout);

  if (!handle && typeof context.refreshActivePage === 'function') {
    const refreshedPage = await context.refreshActivePage().catch(() => null);
    const remainingTimeout = Math.max(0, deadline - Date.now());

    if (refreshedPage && refreshedPage !== page && remainingTimeout > 0) {
      handle = await findVisibleElement(refreshedPage, selector, remainingTimeout);
    }
  }

  if (!handle) {
    return {
      ok: true,
      status: 'not_found',
      statusMessage: `IF-Bedingung nicht erfuellt: Element nicht gefunden (${selector}).`,
      selector,
      elementExists: false,
      branchOutcome: 'failed',
    };
  }

  try {
    const element = await elementSnapshot(handle, selector);

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'IF-Bedingung erfuellt: Element wurde gefunden.',
      selector,
      elementExists: true,
      element,
    });
  } finally {
    await handle.dispose?.().catch(() => {});
  }
}

module.exports = { key: 'decision.element_exists', run };
