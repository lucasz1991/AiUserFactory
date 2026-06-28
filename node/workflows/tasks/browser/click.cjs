'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  clickVisibleElement,
  clickVisibleElementByText,
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

async function clickSelector(page, selector, timeout) {
  return clickVisibleElement(page, selector, timeout);
}

async function clickText(page, text, timeout) {
  return clickVisibleElementByText(page, text, timeout);
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
          element: clicked,
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
          element: clicked,
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
