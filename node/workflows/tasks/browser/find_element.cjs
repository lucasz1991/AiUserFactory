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

async function elementSnapshot(locator, selector) {
  return locator.evaluate((element, fallbackSelector) => ({
    selector: fallbackSelector,
    tag: element.tagName.toLowerCase(),
    id: element.id || '',
    name: element.getAttribute('name') || '',
    type: element.getAttribute('type') || '',
    role: element.getAttribute('role') || '',
    ariaLabel: element.getAttribute('aria-label') || '',
    placeholder: element.getAttribute('placeholder') || '',
    href: element.getAttribute('href') || '',
    text: (element.innerText || element.textContent || '').trim().slice(0, 500),
  }), selector);
}

async function trySelector(page, selector, timeout) {
  const locator = page.locator(selector).first();

  if (await locator.count() < 1) {
    return null;
  }

  await locator.waitFor({ state: 'visible', timeout });

  return elementSnapshot(locator, selector);
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
  const text = firstNonEmpty(input.text, input.label, input.name);

  if (!page || typeof page.locator !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Element-Suche vorhanden.' };
  }

  if (selector !== '') {
    try {
      const match = await trySelector(page, selector, timeout);

      if (match) {
        return { ok: true, status: 'success', statusMessage: 'Element wurde gefunden.', element: match };
      }
    } catch (error) {
      if (text === '') {
        return {
          ok: false,
          status: 'failed',
          statusMessage: `Element-Selector konnte nicht gefunden werden: ${selector}`,
          selector,
          error: error.message,
        };
      }
    }
  }

  if (text !== '' && typeof page.getByText === 'function') {
    try {
      const locator = page.getByText(text, { exact: false }).first();

      if (await locator.count() > 0) {
        await locator.waitFor({ state: 'visible', timeout });

        return {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde ueber Text gefunden.',
          element: await elementSnapshot(locator, `text=${text}`),
        };
      }
    } catch (error) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: `Element-Text konnte nicht gefunden werden: ${text}`,
        text,
        error: error.message,
      };
    }
  }

  return {
    ok: false,
    status: 'partial',
    statusMessage: 'Kein Element gefunden. Weiterleitung kann ueber Teilstatus oder Fehler erfolgen.',
    selector,
    text,
  };
}

module.exports = { key: 'browser.find_element', run };
