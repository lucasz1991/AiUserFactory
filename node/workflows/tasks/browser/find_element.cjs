'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  elementSnapshot,
  findVisibleElement,
  findVisibleElementByText,
} = require('../lib/find_visible_element.cjs');

function firstNonEmpty(...values) {
  for (const value of values) {
    const normalized = String(value ?? '').trim();

    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

async function snapshotAndDispose(handle, selector) {
  try {
    return await elementSnapshot(handle, selector);
  } finally {
    await handle.dispose?.().catch(() => {});
  }
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);
  const selector = firstNonEmpty(
    input.elementSelector,
    input.element_selector,
    input.selector,
    input.inputSelector,
    input.input_selector,
  );
  const text = firstNonEmpty(input.text, input.label, input.name, input.value);

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Element-Suche vorhanden.' };
  }

  if (selector !== '') {
    const handle = await findVisibleElement(page, selector, timeout);

    if (handle) {
      const match = await snapshotAndDispose(handle, selector);

      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: 'Element wurde gefunden.',
        selector,
        element: match,
      });
    }
  }

  if (text !== '') {
    const handle = await findVisibleElementByText(page, text, Math.min(timeout, 15000));

    if (handle) {
      const match = await snapshotAndDispose(handle, `text=${text}`);

      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: 'Element wurde ueber Text gefunden.',
        text,
        element: match,
      });
    }
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'partial',
    statusMessage: 'Kein Element gefunden. Weiterleitung kann ueber Teilstatus oder Fehler erfolgen.',
    selector,
    text,
  });
}

module.exports = { key: 'browser.find_element', run };
