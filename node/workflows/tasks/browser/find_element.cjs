'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

function firstNonEmpty(...values) {
  for (const value of values) {
    const normalized = String(value ?? '').trim();

    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

async function elementSnapshot(handle, selector) {
  return handle.evaluate((element, fallbackSelector) => ({
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
  if (typeof page.waitForSelector === 'function') {
    const handle = await page.waitForSelector(selector, { visible: true, timeout }).catch(() => null);

    if (!handle) {
      return null;
    }

    try {
      return await elementSnapshot(handle, selector);
    } finally {
      await handle.dispose?.().catch(() => {});
    }
  }

  const handle = typeof page.$ === 'function' ? await page.$(selector).catch(() => null) : null;

  if (!handle) {
    return null;
  }

  try {
    return await elementSnapshot(handle, selector);
  } finally {
    await handle.dispose?.().catch(() => {});
  }
}

async function tryText(page, text) {
  if (!page || typeof page.evaluate !== 'function') {
    return null;
  }

  return page.evaluate((needle) => {
    const normalizedNeedle = String(needle || '').toLowerCase();
    const visible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none';
    };
    const element = Array.from(document.querySelectorAll('a,button,[role=button],input[type=button],input[type=submit],label,span,div'))
      .find((candidate) => visible(candidate) && String(candidate.innerText || candidate.value || candidate.textContent || '').toLowerCase().includes(normalizedNeedle));

    if (!element) {
      return null;
    }

    return {
      selector: `text=${needle}`,
      tag: element.tagName.toLowerCase(),
      id: element.id || '',
      name: element.getAttribute('name') || '',
      type: element.getAttribute('type') || '',
      role: element.getAttribute('role') || '',
      ariaLabel: element.getAttribute('aria-label') || '',
      placeholder: element.getAttribute('placeholder') || '',
      href: element.getAttribute('href') || '',
      text: (element.innerText || element.textContent || '').trim().slice(0, 500),
    };
  }, text).catch(() => null);
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
        return captureTaskPreview(context, { ok: true, status: 'success', statusMessage: 'Element wurde gefunden.', element: match });
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

  if (text !== '') {
    try {
      const match = await tryText(page, text);

      if (match) {
        return captureTaskPreview(context, {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde ueber Text gefunden.',
          element: match,
        });
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

  return captureTaskPreview(context, {
    ok: false,
    status: 'partial',
    statusMessage: 'Kein Element gefunden. Weiterleitung kann ueber Teilstatus oder Fehler erfolgen.',
    selector,
    text,
  });
}

module.exports = { key: 'browser.find_element', run };
