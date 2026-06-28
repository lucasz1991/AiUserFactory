'use strict';

const { parseExtendedSelector } = require('../../lib/selector.cjs');

const buttonLikeSelector = 'button,a[data-component="button"],[role="button"],input[type="button"],input[type="submit"]';

function normalizeText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function frameIsDetached(frame) {
  if (!frame) {
    return true;
  }

  try {
    return frame.detached === true
      || (typeof frame.isDetached === 'function' && frame.isDetached());
  } catch {
    return true;
  }
}

function childFramesFor(frame) {
  if (!frame) {
    return [];
  }

  try {
    const children = typeof frame.childFrames === 'function'
      ? frame.childFrames()
      : frame.childFrames;

    return Array.isArray(children) ? children.filter(Boolean) : [];
  } catch {
    return [];
  }
}

function framesForPage(page) {
  if (!page) {
    return [];
  }

  const candidates = [];

  if (typeof page.mainFrame === 'function') {
    try {
      candidates.push(page.mainFrame());
    } catch {
      // A navigation can briefly replace the main frame. The next polling pass retries it.
    }
  }

  if (typeof page.frames === 'function') {
    try {
      const pageFrames = page.frames();

      if (Array.isArray(pageFrames)) {
        candidates.push(...pageFrames);
      }
    } catch {
      // Keep already collected frames and retry with a fresh frame tree on the next pass.
    }
  }

  if (
    candidates.length === 0
    && (typeof page.evaluateHandle === 'function' || typeof page.waitForSelector === 'function')
  ) {
    candidates.push(page);
  }

  const frames = [];
  const queue = candidates.filter(Boolean);
  const seen = new Set();

  while (queue.length > 0) {
    const frame = queue.shift();

    if (!frame || seen.has(frame)) {
      continue;
    }

    seen.add(frame);

    if (frameIsDetached(frame)) {
      continue;
    }

    frames.push(frame);
    queue.push(...childFramesFor(frame));
  }

  return frames;
}

function isTransientDomError(error) {
  const message = String(error?.message || error || '').toLowerCase();

  return [
    'detached frame',
    'frame was detached',
    'execution context was destroyed',
    'cannot find context with specified id',
    'node is detached',
    'not attached to the dom',
  ].some((part) => message.includes(part));
}

function remainingTimeout(deadline) {
  return Math.max(0, deadline - Date.now());
}

function wait(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function synchronizeLiveDom(page) {
  if (!page || typeof page.evaluate !== 'function') {
    return;
  }

  await page.evaluate(() => new Promise((resolve) => {
    let finished = false;
    const finish = () => {
      if (!finished) {
        finished = true;
        resolve();
      }
    };

    setTimeout(finish, 100);

    if (typeof requestAnimationFrame !== 'function') {
      finish();

      return;
    }

    requestAnimationFrame(() => requestAnimationFrame(finish));
  })).catch(() => {});
}

function parseTextSelector(selector) {
  const value = String(selector || '').trim();
  const match = value.match(/^(text|has-text|text-is)\s*=\s*(.+)$/i);

  if (!match) {
    return null;
  }

  return {
    text: String(match[2] || '').replace(/^["']|["']$/g, ''),
    exact: match[1].toLowerCase() === 'text-is',
  };
}

async function elementSnapshot(handle, selector = '') {
  return handle.evaluate((element, fallbackSelector) => {
    const rect = element.getBoundingClientRect();

    return {
      selector: fallbackSelector,
      tag: String(element.tagName || '').toLowerCase(),
      id: element.id || '',
      name: element.getAttribute('name') || '',
      type: element.getAttribute('type') || '',
      role: element.getAttribute('role') || '',
      ariaLabel: element.getAttribute('aria-label') || '',
      placeholder: element.getAttribute('placeholder') || '',
      href: element.getAttribute('href') || '',
      value: element.value || '',
      text: String(element.innerText || element.value || element.textContent || '').trim().slice(0, 500),
      rect: {
        x: Math.round(rect.x),
        y: Math.round(rect.y),
        width: Math.round(rect.width),
        height: Math.round(rect.height),
      },
    };
  }, selector);
}

async function clickableHandleFor(handle) {
  if (!handle || typeof handle.evaluateHandle !== 'function') {
    return handle;
  }

  const nextHandle = await handle.evaluateHandle((element) => {
    if (!element) {
      return element;
    }

    const clickableSelector = 'button,a,[role="button"],input[type="button"],input[type="submit"]';
    const ownClickable = typeof element.closest === 'function'
      ? element.closest(clickableSelector)
      : null;

    if (ownClickable) {
      return ownClickable;
    }

    const root = typeof element.getRootNode === 'function' ? element.getRootNode() : null;
    const host = root && root.host ? root.host : null;
    const hostClickable = host && typeof host.closest === 'function'
      ? host.closest(clickableSelector)
      : null;

    return hostClickable || host || element;
  }).catch(() => null);
  const nextElement = nextHandle && typeof nextHandle.asElement === 'function' ? nextHandle.asElement() : null;

  if (!nextElement) {
    await nextHandle?.dispose?.().catch(() => {});

    return handle;
  }

  return nextElement;
}

async function extendedSelectorHandle(frame, selector, cssOverride = '') {
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
      const ownText = normalize([
        element.innerText,
        element.value,
        element.textContent,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
      ].filter(Boolean).join(' '));

      if (ownText && (exact ? ownText === expected : ownText.includes(expected))) {
        return true;
      }

      return textElements.some((textElement) => {
        const actual = normalize([
          textElement.innerText,
          textElement.value,
          textElement.textContent,
          textElement.getAttribute('aria-label'),
          textElement.getAttribute('title'),
        ].filter(Boolean).join(' '));

        return exact ? actual === expected : actual.includes(expected);
      });
    }) || null;
  }, cssOverride || extendedSelector.css, extendedSelector.descendantCss || null, extendedSelector.text, extendedSelector.exact).catch(() => null);

  if (!handle) {
    return null;
  }

  const element = typeof handle.asElement === 'function' ? handle.asElement() : null;

  if (!element) {
    await handle.dispose?.().catch(() => {});
  }

  return element;
}

async function textElementHandle(frame, text, options = {}) {
  const expected = normalizeText(text);

  if (expected === '' || typeof frame.evaluateHandle !== 'function') {
    return null;
  }

  const selector = options.selector || 'a,button,[role=button],input[type=button],input[type=submit],label,span,div';
  const exact = options.exact === true;
  const handle = await frame.evaluateHandle((targetSelector, needle, mustMatchExactly) => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
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
        && style.display !== 'none'
        && style.opacity !== '0';
    };
    const textFor = (element) => normalize([
      element.innerText,
      element.value,
      element.textContent,
      element.getAttribute('aria-label'),
      element.getAttribute('title'),
    ].filter(Boolean).join(' '));

    return deepQueryAll(document, targetSelector)
      .find((element) => {
        if (!visible(element)) {
          return false;
        }

        const actual = textFor(element);

        return mustMatchExactly ? actual === needle : actual.includes(needle);
      }) || null;
  }, selector, expected, exact).catch(() => null);
  const element = handle && typeof handle.asElement === 'function' ? handle.asElement() : null;

  if (!element) {
    await handle?.dispose?.().catch(() => {});
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
  if (frameIsDetached(frame)) {
    return null;
  }

  const textSelector = parseTextSelector(selector);

  if (textSelector) {
    return textElementHandle(frame, textSelector.text, { exact: textSelector.exact });
  }

  if (parseExtendedSelector(selector)) {
    const extendedSelector = parseExtendedSelector(selector);
    const handle = await extendedSelectorHandle(frame, selector);

    if (handle) {
      return handle;
    }

    if (
      String(extendedSelector.css || '').trim().toLowerCase() === 'button'
      && !extendedSelector.descendantCss
    ) {
      return extendedSelectorHandle(frame, selector, buttonLikeSelector);
    }

    return null;
  }

  const deepHandle = await deepCssSelectorHandle(frame, selector);

  if (deepHandle) {
    return deepHandle;
  }

  return cssSelectorHandle(frame, selector, timeout);
}

async function firstHandleAcrossFrames(frames, findInFrame) {
  if (!Array.isArray(frames) || frames.length === 0) {
    return null;
  }

  const handles = await Promise.all(frames.map(async (frame) => {
    if (frameIsDetached(frame)) {
      return null;
    }

    try {
      return await findInFrame(frame);
    } catch (error) {
      if (isTransientDomError(error)) {
        return null;
      }

      return null;
    }
  }));
  const winner = handles.find(Boolean) || null;

  await Promise.all(handles.map(async (handle) => {
    if (!handle || handle === winner) {
      return;
    }

    await handle.dispose?.().catch(() => {});
  }));

  return winner;
}

async function findVisibleElement(page, selector, timeout = 15000) {
  const normalizedTimeout = Math.max(1, Number(timeout || 15000));
  const deadline = Date.now() + normalizedTimeout;

  await synchronizeLiveDom(page);

  while (remainingTimeout(deadline) > 0) {
    const remaining = remainingTimeout(deadline);
    const frames = framesForPage(page);
    const frameTimeout = Math.max(1, Math.min(100, remaining));
    const handle = await firstHandleAcrossFrames(
      frames,
      (frame) => visibleElementInFrame(frame, selector, frameTimeout),
    );

    if (handle) {
      return handle;
    }

    await wait(Math.min(100, remainingTimeout(deadline)));
  }

  return null;
}

async function findVisibleElementByText(page, text, timeout = 15000, options = {}) {
  const normalizedTimeout = Math.max(1, Number(timeout || 15000));
  const deadline = Date.now() + normalizedTimeout;

  await synchronizeLiveDom(page);

  while (remainingTimeout(deadline) > 0) {
    const frames = framesForPage(page);
    const handle = await firstHandleAcrossFrames(
      frames,
      (frame) => textElementHandle(frame, text, options),
    );

    if (handle) {
      return handle;
    }

    await wait(Math.min(100, remainingTimeout(deadline)));
  }

  return null;
}

async function clickWithFreshHandle(findHandle, snapshotSelector, timeout) {
  const normalizedTimeout = Math.max(1, Number(timeout || 15000));
  const deadline = Date.now() + normalizedTimeout;
  let lastTransientError = null;

  while (remainingTimeout(deadline) > 0) {
    const handle = await findHandle(remainingTimeout(deadline));

    if (!handle) {
      break;
    }

    const clickableHandle = await clickableHandleFor(handle);

    try {
      const snapshot = await elementSnapshot(clickableHandle, snapshotSelector).catch(() => ({ selector: snapshotSelector }));
      await clickableHandle.click({ timeout: Math.max(1, remainingTimeout(deadline)) });

      return snapshot;
    } catch (error) {
      if (!isTransientDomError(error)) {
        throw error;
      }

      lastTransientError = error;
    } finally {
      if (clickableHandle !== handle) {
        await clickableHandle.dispose?.().catch(() => {});
      }

      await handle.dispose?.().catch(() => {});
    }

    await wait(Math.min(100, remainingTimeout(deadline)));
  }

  if (lastTransientError) {
    throw lastTransientError;
  }

  return null;
}

async function clickVisibleElement(page, selector, timeout = 15000) {
  return clickWithFreshHandle(
    (remaining) => findVisibleElement(page, selector, remaining),
    selector,
    timeout,
  );
}

async function clickVisibleElementByText(page, text, timeout = 15000, options = {}) {
  return clickWithFreshHandle(
    (remaining) => findVisibleElementByText(page, text, remaining, {
      selector: 'a,button,[role=button],input[type=button],input[type=submit]',
      ...options,
    }),
    `text=${text}`,
    timeout,
  );
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
  clickVisibleElement,
  clickVisibleElementByText,
  countVisibleElements,
  elementSnapshot,
  findVisibleElement,
  findVisibleElementByText,
  framesForPage,
};
