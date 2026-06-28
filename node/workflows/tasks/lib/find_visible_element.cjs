'use strict';

const { parseExtendedSelector } = require('../../lib/selector.cjs');

function framesForPage(page) {
  if (page && typeof page.frames === 'function') {
    return page.frames().filter(Boolean);
  }

  if (page && typeof page.mainFrame === 'function') {
    return [page.mainFrame()].filter(Boolean);
  }

  return page ? [page] : [];
}

async function extendedSelectorHandle(frame, selector) {
  const extendedSelector = parseExtendedSelector(selector);

  if (!extendedSelector || typeof frame.evaluateHandle !== 'function') {
    return null;
  }

  const handle = await frame.evaluateHandle((css, descendantCss, text, exact) => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const expected = normalize(text);
    const deepQueryAll = (root, selector) => {
      const results = [];
      const visit = (node) => {
        if (!node || typeof node.querySelectorAll !== 'function') {
          return;
        }

        try {
          results.push(...Array.from(node.querySelectorAll(selector)));
        } catch {
          return;
        }

        Array.from(node.querySelectorAll('*')).forEach((element) => {
          if (element.shadowRoot) {
            visit(element.shadowRoot);
          }
        });
      };

      visit(root);

      return Array.from(new Set(results));
    };
    const visible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none';
    };

    return deepQueryAll(document, css).find((element) => {
      if (!visible(element)) {
        return false;
      }

      const textElements = descendantCss
        ? deepQueryAll(element, descendantCss)
        : [element];

      return textElements.some((textElement) => {
        const actual = normalize(textElement.innerText || textElement.textContent);

        return exact ? actual === expected : actual.includes(expected);
      });
    }) || null;
  }, extendedSelector.css, extendedSelector.descendantCss || null, extendedSelector.text, extendedSelector.exact).catch(() => null);

  if (!handle) {
    return null;
  }

  const element = typeof handle.asElement === 'function' ? handle.asElement() : null;

  if (!element) {
    await handle.dispose?.().catch(() => {});
  }

  return element;
}

async function deepCssSelectorHandle(frame, selector) {
  if (typeof frame.evaluateHandle !== 'function') {
    return null;
  }

  const handle = await frame.evaluateHandle((css) => {
    const deepQueryAll = (root, selector) => {
      const results = [];
      const visit = (node) => {
        if (!node || typeof node.querySelectorAll !== 'function') {
          return;
        }

        try {
          results.push(...Array.from(node.querySelectorAll(selector)));
        } catch {
          return;
        }

        Array.from(node.querySelectorAll('*')).forEach((element) => {
          if (element.shadowRoot) {
            visit(element.shadowRoot);
          }
        });
      };
      visit(root);

      return Array.from(new Set(results));
    };
    const visible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none';
    };

    return deepQueryAll(document, css).find((element) => visible(element)) || null;
  }, selector).catch(() => null);
  const element = handle && typeof handle.asElement === 'function' ? handle.asElement() : null;

  if (!element) {
    await handle?.dispose?.().catch(() => {});
  }

  return element;
}

async function cssSelectorHandle(frame, selector, timeout) {
  if (typeof frame.waitForSelector !== 'function') {
    return null;
  }

  return frame.waitForSelector(selector, {
    visible: true,
    timeout,
  }).catch(() => null);
}

async function visibleElementInFrame(frame, selector, timeout) {
  const extendedHandle = await extendedSelectorHandle(frame, selector);

  if (extendedHandle) {
    return extendedHandle;
  }

  const deepHandle = await deepCssSelectorHandle(frame, selector);

  if (deepHandle) {
    return deepHandle;
  }

  return cssSelectorHandle(frame, selector, timeout);
}

async function findVisibleElement(page, selector, timeout = 15000) {
  const startedAt = Date.now();
  const frameTimeout = Math.max(250, Math.min(2500, Number(timeout || 15000)));

  while (Date.now() - startedAt < timeout) {
    for (const frame of framesForPage(page)) {
      const handle = await visibleElementInFrame(frame, selector, frameTimeout);

      if (handle) {
        return handle;
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 150));
  }

  return null;
}

async function countVisibleElements(page, selector, timeout = 1500) {
  const handle = await findVisibleElement(page, selector, timeout);

  if (!handle) {
    return 0;
  }

  await handle.dispose?.().catch(() => {});

  return 1;
}

module.exports = {
  countVisibleElements,
  findVisibleElement,
  framesForPage,
};
