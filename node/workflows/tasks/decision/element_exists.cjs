'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { findVisibleElement } = require('../lib/find_visible_element.cjs');

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

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die IF-Element-Pruefung vorhanden.' };
  }

  if (!selector) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector fuer die IF-Element-Pruefung angegeben.' };
  }

  const handle = await findVisibleElement(page, selector, timeout);

  if (!handle) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'not_found',
      statusMessage: `IF-Bedingung nicht erfuellt: Element nicht gefunden (${selector}).`,
      selector,
      elementExists: false,
    });
  }

  try {
    const element = await handle.evaluate((node, matchedSelector) => ({
      selector: matchedSelector,
      tag: node.tagName.toLowerCase(),
      id: node.id || '',
      text: String(node.innerText || node.textContent || '').trim().slice(0, 500),
    }), selector);

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
