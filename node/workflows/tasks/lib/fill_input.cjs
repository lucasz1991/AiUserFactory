'use strict';

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

function selectorList(selectors = []) {
  const seen = new Set();

  return []
    .concat(selectors || [])
    .flat()
    .map((selector) => String(selector || '').trim())
    .filter((selector) => {
      if (selector === '' || seen.has(selector)) {
        return false;
      }

      seen.add(selector);
      return true;
    });
}

function framesForPage(page) {
  if (page && typeof page.frames === 'function') {
    const frames = page.frames().filter(Boolean);

    if (frames.length > 0) {
      return frames;
    }
  }

  return page ? [page] : [];
}

function frameUrl(frame) {
  if (!frame || typeof frame.url !== 'function') {
    return '';
  }

  try {
    return String(frame.url() || '');
  } catch {
    return '';
  }
}

async function elementState(handle) {
  return handle.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const style = window.getComputedStyle(element);
    const tagName = String(element.tagName || '').toLowerCase();
    const type = String(element.getAttribute('type') || '').toLowerCase();
    const disabled = element.disabled === true || element.getAttribute('aria-disabled') === 'true';
    const readOnly = element.readOnly === true;
    const visible = rect.width > 0
      && rect.height > 0
      && style.visibility !== 'hidden'
      && style.display !== 'none'
      && style.opacity !== '0';
    const editable = tagName === 'textarea'
      || element.isContentEditable
      || (tagName === 'input' && type !== 'hidden');

    return {
      usable: visible && editable && !disabled && !readOnly,
      visible,
      editable,
      disabled,
      readOnly,
      tagName,
      type,
      id: element.id || '',
      name: element.getAttribute('name') || '',
      autocomplete: element.getAttribute('autocomplete') || '',
      placeholder: element.getAttribute('placeholder') || '',
      ariaLabel: element.getAttribute('aria-label') || '',
      width: Math.round(rect.width),
      height: Math.round(rect.height),
      display: style.display,
      visibility: style.visibility,
      opacity: style.opacity,
    };
  }).catch((error) => ({
    usable: false,
    error: error.message,
  }));
}

async function queryDeepHandles(frame, selector) {
  const directHandles = await frame.$$(selector).catch(() => []);

  if (directHandles.length > 0) {
    return directHandles;
  }

  const arrayHandle = await frame.evaluateHandle((targetSelector) => {
    const matches = [];
    const visited = new Set();

    const collect = (root) => {
      if (!root || visited.has(root)) {
        return;
      }

      visited.add(root);

      try {
        matches.push(...root.querySelectorAll(targetSelector));
      } catch {
        return;
      }

      root.querySelectorAll('*').forEach((element) => {
        if (element.shadowRoot) {
          collect(element.shadowRoot);
        }
      });
    };

    collect(document);

    return matches;
  }, selector).catch(() => null);

  if (!arrayHandle) {
    return [];
  }

  const properties = await arrayHandle.getProperties().catch(() => new Map());
  await arrayHandle.dispose().catch(() => {});

  return Array.from(properties.values())
    .map((property) => (typeof property.asElement === 'function' ? property.asElement() : null))
    .filter(Boolean);
}

async function fillHandleValue(handle, value, delay = 35) {
  const nextValue = String(value ?? '');

  await handle.evaluate((element) => {
    const dispatchInput = (inputType, data) => {
      try {
        element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType, data }));
      } catch {
        element.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };
    const prototype = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

    element.scrollIntoView({ block: 'center', inline: 'center' });
    element.focus();

    if (descriptor?.set) {
      descriptor.set.call(element, '');
    } else {
      element.value = '';
    }

    dispatchInput('deleteContentBackward', '');
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }).catch(() => {});

  await handle.click({ clickCount: 3 }).catch(() => {});
  await handle.type(nextValue, { delay }).catch(async () => {
    await handle.evaluate((element, typedValue) => {
      const dispatchInput = (inputType, data) => {
        try {
          element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType, data }));
        } catch {
          element.dispatchEvent(new Event('input', { bubbles: true }));
        }
      };
      const prototype = element instanceof HTMLTextAreaElement
        ? HTMLTextAreaElement.prototype
        : HTMLInputElement.prototype;
      const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

      if (descriptor?.set) {
        descriptor.set.call(element, typedValue);
      } else {
        element.value = typedValue;
      }

      dispatchInput('insertText', typedValue);
      element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Unidentified' }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }, nextValue);
  });

  let enteredValue = await handle.evaluate((element) => element.value || element.textContent || '').catch(() => '');

  if (String(enteredValue || '') !== nextValue) {
    await handle.evaluate((element, typedValue) => {
      const dispatchInput = (inputType, data) => {
        try {
          element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType, data }));
        } catch {
          element.dispatchEvent(new Event('input', { bubbles: true }));
        }
      };
      const prototype = element instanceof HTMLTextAreaElement
        ? HTMLTextAreaElement.prototype
        : HTMLInputElement.prototype;
      const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

      if (descriptor?.set) {
        descriptor.set.call(element, typedValue);
      } else {
        element.value = typedValue;
      }

      dispatchInput('insertText', typedValue);
      element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Unidentified' }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }, nextValue).catch(() => {});

    enteredValue = await handle.evaluate((element) => element.value || element.textContent || '').catch(() => enteredValue);
  }

  return enteredValue;
}

async function fillFirstMatchingInput(page, selectors, value, timeoutMs = 12000, options = {}) {
  const candidates = selectorList(selectors);
  const stopAt = Date.now() + Math.max(500, Number(timeoutMs) || 12000);
  const delay = Math.max(0, Number(options.delay ?? 35));
  const attempts = [];
  let matchedElementCount = 0;
  let lastError = '';

  while (Date.now() < stopAt) {
    for (const frame of framesForPage(page)) {
      const currentFrameUrl = frameUrl(frame);

      for (const selector of candidates) {
        let handles = [];

        try {
          handles = await queryDeepHandles(frame, selector);
        } catch (error) {
          lastError = error.message;
          continue;
        }

        matchedElementCount += handles.length;

        for (const handle of handles) {
          try {
            const state = await elementState(handle);

            if (!state.usable) {
              if (attempts.length < 30) {
                attempts.push({ selector, frameUrl: currentFrameUrl, state });
              }

              continue;
            }

            const enteredValue = await fillHandleValue(handle, value, delay);

            if (String(enteredValue || '') === String(value ?? '')) {
              return {
                ok: true,
                selector,
                frameUrl: currentFrameUrl,
                attemptedSelectors: candidates,
                matchedElementCount,
              };
            }

            lastError = 'Wert wurde gesetzt, aber nicht im Feld bestaetigt.';

            if (attempts.length < 30) {
              attempts.push({
                selector,
                frameUrl: currentFrameUrl,
                state,
                error: lastError,
                enteredLength: String(enteredValue || '').length,
              });
            }
          } catch (error) {
            lastError = error.message;

            if (attempts.length < 30) {
              attempts.push({ selector, frameUrl: currentFrameUrl, error: error.message });
            }
          } finally {
            await handle.dispose().catch(() => {});
          }
        }
      }
    }

    await sleep(250);
  }

  return {
    ok: false,
    attemptedSelectors: candidates,
    attempts,
    matchedElementCount,
    lastError,
  };
}

module.exports = {
  fillFirstMatchingInput,
};
