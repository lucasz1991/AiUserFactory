'use strict';

const {
  normalizeElementCandidates,
  parseExtendedSelector,
} = require('../../lib/selector.cjs');

const buttonLikeSelector = 'button,a[data-component="button"],[role="button"],input[type="button"],input[type="submit"]';
const clickableElementSelector = 'a,button,[role="button"],input[type="button"],input[type="submit"]';

function normalizeText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function elementCandidatesFromInput(input = {}, options = {}) {
  const selectorKeys = options.selectorKeys || [
    'elementSelector',
    'element_selector',
    'inputSelector',
    'input_selector',
    'selector',
    'selectors',
  ];
  const textKeys = options.textKeys || ['text', 'texts', 'label', 'labels'];
  const candidates = normalizeElementCandidates(
    selectorKeys.flatMap((key) => [].concat(input[key] || [])),
    { defaultKind: options.selectorDefaultKind || 'auto' },
  );

  return normalizeElementCandidates([
    ...candidates,
    ...normalizeElementCandidates(
      textKeys.flatMap((key) => [].concat(input[key] || [])),
      { defaultKind: 'text' },
    ),
  ]);
}

function candidateSelector(candidate) {
  return candidate?.kind === 'text'
    ? `text=${candidate.value}`
    : String(candidate?.value || '');
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
    && (
      typeof page.evaluate === 'function'
      || typeof page.evaluateHandle === 'function'
      || typeof page.waitForSelector === 'function'
    )
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

async function extendedSelectorHandle(frame, selector, cssOverride = '', options = {}) {
  const extendedSelector = parseExtendedSelector(selector);

  if (!extendedSelector || typeof frame.evaluateHandle !== 'function') {
    return null;
  }

  const handle = await frame.evaluateHandle((css, descendantCss, text, exact, editableOnly) => {
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
    const editable = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      return !editableOnly || (
        (tag === 'textarea' || element.isContentEditable || (tag === 'input' && type !== 'hidden'))
        && element.disabled !== true
        && element.readOnly !== true
        && element.getAttribute('aria-disabled') !== 'true'
      );
    };

    const labelText = (element) => element.labels?.length
      ? Array.from(element.labels).map((label) => label.textContent || '').join(' ')
      : (element.closest?.('label')?.textContent || '');

    return deepQueryAll(document, css).find((element) => {
      if (!visible(element) || !editable(element)) {
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
        element.getAttribute('placeholder'),
        element.getAttribute('name'),
        labelText(element),
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
          textElement.getAttribute('placeholder'),
          textElement.getAttribute('name'),
          labelText(textElement),
        ].filter(Boolean).join(' '));

        return exact ? actual === expected : actual.includes(expected);
      });
    }) || null;
  }, cssOverride || extendedSelector.css, extendedSelector.descendantCss || null, extendedSelector.text, extendedSelector.exact, options.editableOnly === true).catch(() => null);

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
  const handle = await frame.evaluateHandle((targetSelector, needle, mustMatchExactly, editableOnly) => {
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
    const labelText = (element) => element.labels?.length
      ? Array.from(element.labels).map((label) => label.textContent || '').join(' ')
      : (element.closest?.('label')?.textContent || '');
    const textFor = (element) => normalize([
      element.innerText,
      element.value,
      element.textContent,
      element.getAttribute('aria-label'),
      element.getAttribute('title'),
      element.getAttribute('placeholder'),
      element.getAttribute('name'),
      labelText(element),
    ].filter(Boolean).join(' '));
    const editable = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      return !editableOnly || (
        (tag === 'textarea' || element.isContentEditable || (tag === 'input' && type !== 'hidden'))
        && element.disabled !== true
        && element.readOnly !== true
        && element.getAttribute('aria-disabled') !== 'true'
      );
    };

    return deepQueryAll(document, targetSelector)
      .find((element) => {
        if (!visible(element) || !editable(element)) {
          return false;
        }

        const actual = textFor(element);

        return mustMatchExactly ? actual === needle : actual.includes(needle);
      }) || null;
  }, selector, expected, exact, options.editableOnly === true).catch(() => null);
  const element = handle && typeof handle.asElement === 'function' ? handle.asElement() : null;

  if (!element) {
    await handle?.dispose?.().catch(() => {});
  }

  return element;
}

async function deepCssSelectorHandle(frame, selector, options = {}) {
  if (typeof frame.evaluateHandle !== 'function') {
    return null;
  }

  const handle = await frame.evaluateHandle((css, editableOnly) => {
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
    const editable = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      return !editableOnly || (
        (tag === 'textarea' || element.isContentEditable || (tag === 'input' && type !== 'hidden'))
        && element.disabled !== true
        && element.readOnly !== true
        && element.getAttribute('aria-disabled') !== 'true'
      );
    };

    return deepQueryAll(document, css).find((element) => visible(element) && editable(element)) || null;
  }, selector, options.editableOnly === true).catch(() => null);
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

async function visibleElementInFrame(frame, selector, timeout, options = {}) {
  if (frameIsDetached(frame)) {
    return null;
  }

  const textSelector = parseTextSelector(selector);

  if (textSelector) {
    return textElementHandle(frame, textSelector.text, { ...options, exact: textSelector.exact });
  }

  if (parseExtendedSelector(selector)) {
    const extendedSelector = parseExtendedSelector(selector);
    const handle = await extendedSelectorHandle(frame, selector, '', options);

    if (handle) {
      return handle;
    }

    if (
      String(extendedSelector.css || '').trim().toLowerCase() === 'button'
      && !extendedSelector.descendantCss
    ) {
      return extendedSelectorHandle(frame, selector, buttonLikeSelector, options);
    }

    return null;
  }

  const deepHandle = await deepCssSelectorHandle(frame, selector, options);

  if (deepHandle) {
    return deepHandle;
  }

  return cssSelectorHandle(frame, selector, timeout);
}

async function firstMatchAcrossFrames(frames, findInFrame) {
  if (!Array.isArray(frames) || frames.length === 0) {
    return null;
  }

  const matches = await Promise.all(frames.map(async (frame) => {
    if (frameIsDetached(frame)) {
      return null;
    }

    try {
      const handle = await findInFrame(frame);

      return handle ? { handle, frame } : null;
    } catch (error) {
      if (isTransientDomError(error)) {
        return null;
      }

      return null;
    }
  }));
  const winner = matches.find(Boolean) || null;

  await Promise.all(matches.map(async (match) => {
    if (!match || match === winner) {
      return;
    }

    await match.handle.dispose?.().catch(() => {});
  }));

  return winner;
}

async function findFirstVisibleElement(page, values, timeout = 15000, options = {}) {
  const candidates = normalizeElementCandidates(values, {
    defaultKind: options.defaultKind || 'auto',
  });
  const normalizedTimeout = Math.max(1, Number(timeout || 15000));
  const deadline = Date.now() + normalizedTimeout;

  if (candidates.length === 0) {
    return null;
  }

  await synchronizeLiveDom(page);

  while (remainingTimeout(deadline) > 0) {
    const remaining = remainingTimeout(deadline);
    const frames = framesForPage(page);
    const frameTimeout = candidates.length === 1
      ? Math.max(1, remaining)
      : Math.max(1, Math.min(100, remaining));

    for (const candidate of candidates) {
      const match = await firstMatchAcrossFrames(
        frames,
        (frame) => candidate.kind === 'text'
          ? textElementHandle(frame, candidate.value, {
            exact: candidate.exact,
            selector: options.textSelector,
            editableOnly: options.editableOnly === true,
          })
          : visibleElementInFrame(frame, candidate.value, frameTimeout, options),
      );

      if (match) {
        return {
          ...match,
          candidate,
          matchedBy: candidate.kind,
          selector: candidateSelector(candidate),
        };
      }
    }

    await wait(Math.min(100, remainingTimeout(deadline)));
  }

  return null;
}

async function findVisibleElement(page, selector, timeout = 15000) {
  const match = await findFirstVisibleElement(page, selector, timeout, { defaultKind: 'selector' });

  return match?.handle || null;
}

async function findVisibleElementByText(page, text, timeout = 15000, options = {}) {
  const match = await findFirstVisibleElement(
    page,
    [{ kind: 'text', value: text, exact: options.exact === true }],
    timeout,
    { textSelector: options.selector },
  );

  return match?.handle || null;
}

async function clickFirstVisibleElement(page, values, timeout = 15000, options = {}) {
  const candidates = normalizeElementCandidates(values, {
    defaultKind: options.defaultKind || 'auto',
  });
  const normalizedTimeout = Math.max(1, Number(timeout || 15000));
  const deadline = Date.now() + normalizedTimeout;
  let lastTransientError = null;

  while (remainingTimeout(deadline) > 0) {
    const match = await findFirstVisibleElement(
      page,
      candidates,
      remainingTimeout(deadline),
      {
        defaultKind: options.defaultKind || 'auto',
        textSelector: options.textSelector || clickableElementSelector,
      },
    );

    if (!match) {
      break;
    }

    const { handle } = match;
    const clickableHandle = await clickableHandleFor(handle);

    try {
      const snapshot = await elementSnapshot(clickableHandle, match.selector).catch(() => ({ selector: match.selector }));
      await clickableHandle.click({ timeout: Math.max(1, remainingTimeout(deadline)) });

      return {
        candidate: match.candidate,
        element: snapshot,
        frame: match.frame,
        matchedBy: match.matchedBy,
        selector: match.selector,
      };
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
  const match = await clickFirstVisibleElement(page, selector, timeout, { defaultKind: 'selector' });

  return match?.element || null;
}

async function clickVisibleElementByText(page, text, timeout = 15000, options = {}) {
  const match = await clickFirstVisibleElement(
    page,
    [{ kind: 'text', value: text, exact: options.exact === true }],
    timeout,
    { textSelector: options.selector || clickableElementSelector },
  );

  return match?.element || null;
}

async function collectVisibleElements(page, values, options = {}) {
  const candidates = normalizeElementCandidates(values, {
    defaultKind: options.defaultKind || 'auto',
  });
  const payloadCandidates = candidates.map((candidate) => ({
    ...candidate,
    extended: candidate.kind === 'selector' ? parseExtendedSelector(candidate.value) : null,
  }));
  const matches = [];

  if (payloadCandidates.length === 0) {
    return matches;
  }

  for (const frame of framesForPage(page)) {
    if (typeof frame.evaluate !== 'function') {
      continue;
    }

    const frameMatches = await frame.evaluate((payload) => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
      const deepQueryAll = (root, selector) => {
        const results = [];
        const visit = (node) => {
          if (!node || typeof node.querySelectorAll !== 'function') return;

          try {
            results.push(...Array.from(node.querySelectorAll(selector)));
          } catch {
            return;
          }

          Array.from(node.querySelectorAll('*')).forEach((element) => {
            if (element.shadowRoot) visit(element.shadowRoot);
          });
        };

        visit(root);

        return Array.from(new Set(results));
      };
      const visible = (element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && style.opacity !== '0';
      };
      const labelText = (element) => {
        if (element.labels?.length) {
          return Array.from(element.labels).map((label) => label.textContent || '').join(' ');
        }

        if (element.id) {
          try {
            const explicit = document.querySelector(`label[for="${CSS.escape(element.id)}"]`);
            if (explicit) return explicit.textContent || '';
          } catch {
            // Continue with an enclosing label.
          }
        }

        return element.closest?.('label')?.textContent || '';
      };
      const textFor = (element) => normalize([
        element.innerText,
        element.value,
        element.textContent,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
        element.getAttribute('placeholder'),
        element.getAttribute('name'),
        labelText(element),
      ].filter(Boolean).join(' '));
      const allowed = (element) => !payload.elementSelector
        || (typeof element.matches === 'function' && element.matches(payload.elementSelector));
      const seen = new Set();
      const results = [];

      for (const candidate of payload.candidates) {
        const extended = candidate.extended;
        const query = candidate.kind === 'text'
          ? (payload.textSelector || '*')
          : (extended?.css || candidate.value);
        let elements = deepQueryAll(document, query);

        if (
          extended
          && normalize(extended.css) === 'button'
          && !extended.descendantCss
          && elements.length === 0
        ) {
          elements = deepQueryAll(document, payload.buttonLikeSelector);
        }

        for (const element of elements) {
          if (seen.has(element) || !visible(element) || !allowed(element)) continue;

          const actual = textFor(element);
          let matchesCandidate = true;

          if (candidate.kind === 'text') {
            const expected = normalize(candidate.value);
            matchesCandidate = candidate.exact ? actual === expected : actual.includes(expected);
          } else if (extended) {
            const expected = normalize(extended.text);
            const descendants = extended.descendantCss
              ? deepQueryAll(element, extended.descendantCss)
              : [];
            matchesCandidate = (extended.exact ? actual === expected : actual.includes(expected))
              || descendants.some((descendant) => {
                const descendantText = textFor(descendant);
                return extended.exact ? descendantText === expected : descendantText.includes(expected);
              });
          }

          if (!matchesCandidate) continue;

          seen.add(element);
          const rect = element.getBoundingClientRect();
          const tag = String(element.tagName || '').toLowerCase();
          let generatedSelector = tag;

          if (element.id) {
            generatedSelector = `#${CSS.escape(element.id)}`;
          } else if (element.getAttribute('name')) {
            generatedSelector = `${tag}[name="${CSS.escape(element.getAttribute('name'))}"]`;
          }

          results.push({
            matchedBy: candidate.kind,
            matchedCandidate: candidate.value,
            selector: candidate.kind === 'text' ? `text=${candidate.value}` : candidate.value,
            generatedSelector,
            tag,
            type: element.getAttribute('type') || '',
            name: element.getAttribute('name') || '',
            id: element.id || '',
            placeholder: element.getAttribute('placeholder') || '',
            autocomplete: element.getAttribute('autocomplete') || '',
            ariaLabel: element.getAttribute('aria-label') || '',
            label: String(labelText(element) || '').replace(/\s+/g, ' ').trim(),
            text: String(element.innerText || element.value || element.textContent || '').trim().slice(0, 500),
            rect: {
              x: Math.round(rect.x),
              y: Math.round(rect.y),
              width: Math.round(rect.width),
              height: Math.round(rect.height),
            },
          });

          if (payload.maxResults > 0 && results.length >= payload.maxResults) {
            return results;
          }
        }
      }

      return results;
    }, {
      buttonLikeSelector,
      candidates: payloadCandidates,
      elementSelector: options.elementSelector || '',
      maxResults: Math.max(0, Number(options.maxResults || 0)),
      textSelector: options.textSelector || '',
    }).catch(() => []);
    const frameUrl = typeof frame.url === 'function' ? frame.url() : '';

    matches.push(...frameMatches.map((match) => ({ ...match, frameUrl })));

    if (options.maxResults > 0 && matches.length >= options.maxResults) {
      return matches.slice(0, options.maxResults);
    }
  }

  return matches;
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
  buttonLikeSelector,
  candidateSelector,
  clickableElementSelector,
  clickFirstVisibleElement,
  clickVisibleElement,
  clickVisibleElementByText,
  collectVisibleElements,
  countVisibleElements,
  elementCandidatesFromInput,
  elementSnapshot,
  findFirstVisibleElement,
  findVisibleElement,
  findVisibleElementByText,
  framesForPage,
};
