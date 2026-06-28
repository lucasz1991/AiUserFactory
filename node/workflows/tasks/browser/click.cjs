'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { findVisibleElement, framesForPage } = require('../lib/find_visible_element.cjs');

function firstNonEmpty(...values) {
  for (const value of values) {
    const normalized = String(value ?? '').trim();

    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

async function clickSelector(page, selector, timeout) {
  const handle = await findVisibleElement(page, selector, timeout);

  if (!handle) {
    return false;
  }

  try {
    await handle.click({ timeout });
  } finally {
    await handle.dispose?.().catch(() => {});
  }

  return true;
}

async function clickText(page, text, timeout) {
  const normalizedText = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();

  if (normalizedText === '') {
    return false;
  }

  for (const frame of framesForPage(page)) {
    const handle = await frame.evaluateHandle((needle) => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
      const visible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none';
      };
      const candidates = Array.from(document.querySelectorAll('a,button,[role=button],input[type=button],input[type=submit]'));

      return candidates.find((element) => visible(element) && normalize(element.innerText || element.value || '').includes(needle)) || null;
    }, normalizedText).catch(() => null);
    const element = handle && typeof handle.asElement === 'function' ? handle.asElement() : null;

    if (!element) {
      await handle?.dispose?.().catch(() => {});
      continue;
    }

    try {
      await element.click({ timeout });
    } finally {
      await element.dispose?.().catch(() => {});
    }

    return true;
  }

  return false;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const selector = firstNonEmpty(input.elementSelector, input.element_selector, input.selector);
  const text = firstNonEmpty(input.text, input.label, input.value);

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Klick-Task vorhanden.' };
  }

  if (selector !== '') {
    try {
      const clicked = await clickSelector(page, selector, timeout);

      if (clicked) {
        return captureTaskPreview(context, {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde geklickt.',
          selector,
          url: typeof page.url === 'function' ? page.url() : null,
        });
      }
    } catch (error) {
      if (text === '') {
        return captureTaskPreview(context, {
          ok: false,
          status: 'failed',
          statusMessage: `Element konnte nicht geklickt werden: ${selector}`,
          selector,
          error: error.message,
        });
      }
    }
  }

  if (text !== '') {
    try {
      const clicked = await clickText(page, text, timeout);

      if (clicked) {
        return captureTaskPreview(context, {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde ueber Text geklickt.',
          text,
          url: typeof page.url === 'function' ? page.url() : null,
        });
      }
    } catch (error) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: `Textziel konnte nicht geklickt werden: ${text}`,
        text,
        error: error.message,
      });
    }
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein klickbares Ziel uebergeben oder gefunden.',
    selector,
    text,
  });
}

module.exports = { key: 'browser.click', run };
