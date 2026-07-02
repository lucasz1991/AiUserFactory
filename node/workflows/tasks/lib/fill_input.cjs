'use strict';

const { normalizeElementCandidates } = require('../../lib/selector.cjs');
const {
  findFirstVisibleElement,
  heldHoverForContext,
  matchingCachedElement,
  removeCachedElement,
} = require('./find_visible_element.cjs');

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

function selectorList(selectors = []) {
  return normalizeElementCandidates(selectors, { defaultKind: 'auto' });
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

async function fillHandleValue(handle, value, delay = 35, options = {}) {
  const nextValue = String(value ?? '');
  const preserveMousePosition = options.preserveMousePosition === true;

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

  if (!preserveMousePosition) {
    await handle.click({ clickCount: 3 }).catch(() => {});
  }

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
  const failureCounts = new Map();
  let activeCandidates = [...candidates];
  let matchedElementCount = 0;
  let lastError = '';
  const cachedEntry = matchingCachedElement(options.context, candidates, page);
  const preserveMousePosition = options.preserveMousePosition === true
    || heldHoverForContext(options.context, page) !== null;

  if (cachedEntry) {
    const cachedCandidate = cachedEntry.candidate || candidates[0];
    const selector = cachedEntry.selector || String(cachedCandidate?.value || '');
    const currentFrameUrl = frameUrl(cachedEntry.frame);
    matchedElementCount += 1;

    try {
      const state = await elementState(cachedEntry.handle);

      if (!state.usable) {
        lastError = 'Gespeichertes Element ist nicht editierbar.';
        if (attempts.length < 30) attempts.push({ selector, frameUrl: currentFrameUrl, state, error: lastError, cachedElement: true });
      } else {
        const enteredValue = await fillHandleValue(cachedEntry.handle, value, delay, { preserveMousePosition });

        if (String(enteredValue || '') === String(value ?? '')) {
          await removeCachedElement(options.context, cachedEntry);

          return {
            ok: true,
            cachedElement: true,
            hoverPreservedDuringFill: preserveMousePosition,
            selector,
            matchedBy: cachedCandidate?.kind || 'selector',
            matchedCandidate: cachedCandidate?.value || selector,
            frameUrl: currentFrameUrl,
            attemptedSelectors: candidates.map((candidate) => candidate.value),
            matchedElementCount,
          };
        }

        lastError = 'Gespeichertes Element wurde gefuellt, aber nicht im Feld bestaetigt.';
        if (attempts.length < 30) {
          attempts.push({
            selector,
            frameUrl: currentFrameUrl,
            state,
            error: lastError,
            enteredLength: String(enteredValue || '').length,
            cachedElement: true,
          });
        }
      }
    } catch (error) {
      lastError = error.message;
      if (attempts.length < 30) attempts.push({ selector, frameUrl: currentFrameUrl, error: error.message, cachedElement: true });
    }

    await removeCachedElement(options.context, cachedEntry);
  }

  while (Date.now() < stopAt && activeCandidates.length > 0) {
    const found = await findFirstVisibleElement(
      page,
      activeCandidates,
      Math.max(1, stopAt - Date.now()),
      {
        editableOnly: true,
        textSelector: 'input,textarea,[contenteditable="true"]',
      },
    );

    if (!found) {
      break;
    }

    const handle = found.handle;
    const selector = found.selector;
    const currentFrameUrl = frameUrl(found.frame);
    const candidateKey = `${found.candidate.kind}:${found.candidate.value}:${found.candidate.exact === true}`;
    matchedElementCount += 1;

    try {
      const state = await elementState(handle);

      if (!state.usable) {
        lastError = 'Gefundenes Element ist nicht editierbar.';
        if (attempts.length < 30) attempts.push({ selector, frameUrl: currentFrameUrl, state, error: lastError });
      } else {
        const enteredValue = await fillHandleValue(handle, value, delay, { preserveMousePosition });

        if (String(enteredValue || '') === String(value ?? '')) {
          return {
            ok: true,
            hoverPreservedDuringFill: preserveMousePosition,
            selector,
            matchedBy: found.matchedBy,
            matchedCandidate: found.candidate.value,
            frameUrl: currentFrameUrl,
            attemptedSelectors: candidates.map((candidate) => candidate.value),
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
      }
    } catch (error) {
      lastError = error.message;
      if (attempts.length < 30) attempts.push({ selector, frameUrl: currentFrameUrl, error: error.message });
    } finally {
      await handle.dispose?.().catch(() => {});
    }

    const failures = (failureCounts.get(candidateKey) || 0) + 1;
    failureCounts.set(candidateKey, failures);

    if (failures >= 2) {
      activeCandidates = activeCandidates.filter((candidate) => (
        `${candidate.kind}:${candidate.value}:${candidate.exact === true}` !== candidateKey
      ));
    }

    await sleep(250);
  }

  return {
    ok: false,
    attemptedSelectors: candidates.map((candidate) => candidate.value),
    attempts,
    matchedElementCount,
    lastError,
  };
}

module.exports = {
  fillFirstMatchingInput,
};
