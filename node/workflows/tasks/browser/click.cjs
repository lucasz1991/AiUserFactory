'use strict';

function firstNonEmpty(...values) {
  for (const value of values) {
    const normalized = String(value ?? '').trim();

    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

async function clickLocator(locator, timeout) {
  if (await locator.count() < 1) {
    return false;
  }

  await locator.first().click({ timeout });

  return true;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const selector = firstNonEmpty(input.elementSelector, input.element_selector, input.selector);
  const text = firstNonEmpty(input.text, input.label, input.value);

  if (!page || typeof page.locator !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Klick-Task vorhanden.' };
  }

  if (selector !== '') {
    try {
      const clicked = await clickLocator(page.locator(selector), timeout);

      if (clicked) {
        return {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde geklickt.',
          selector,
          url: typeof page.url === 'function' ? page.url() : null,
        };
      }
    } catch (error) {
      if (text === '') {
        return {
          ok: false,
          status: 'failed',
          statusMessage: `Element konnte nicht geklickt werden: ${selector}`,
          selector,
          error: error.message,
        };
      }
    }
  }

  if (text !== '' && typeof page.getByText === 'function') {
    try {
      const clicked = await clickLocator(page.getByText(text, { exact: false }), timeout);

      if (clicked) {
        return {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde ueber Text geklickt.',
          text,
          url: typeof page.url === 'function' ? page.url() : null,
        };
      }
    } catch (error) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: `Textziel konnte nicht geklickt werden: ${text}`,
        text,
        error: error.message,
      };
    }
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein klickbares Ziel uebergeben oder gefunden.',
    selector,
    text,
  };
}

module.exports = { key: 'browser.click', run };
