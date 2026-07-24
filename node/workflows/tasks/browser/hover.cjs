'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const {
  elementCandidatesFromInput,
  elementSnapshot,
  findFirstVisibleElement,
  rememberFoundElement,
} = require('../lib/find_visible_element.cjs');
const {
  moveCursorToHandle,
} = require('../lib/cursor.cjs');

function optionBoolean(input = {}, keys = [], fallback = false) {
  for (const key of keys) {
    if (!Object.prototype.hasOwnProperty.call(input, key)) {
      continue;
    }

    const value = input[key];

    if (typeof value === 'boolean') {
      return value;
    }

    const normalized = String(value ?? '').trim().toLowerCase();

    if (['1', 'true', 'yes', 'ja', 'on'].includes(normalized)) {
      return true;
    }

    if (['0', 'false', 'no', 'nein', 'off'].includes(normalized)) {
      return false;
    }
  }

  return fallback;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 30000);
  const settleMs = Math.max(0, Math.min(10000, Number(input.settle_ms || input.settleMs || 250)));
  const releaseAfterClick = optionBoolean(input, ['release_after_click', 'releaseAfterClick'], true);
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels', 'value'],
  });

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Hover-Task vorhanden.' };
  }

  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Hover-Text uebergeben.' };
  }

  try {
    startTaskPreview(context);
    const found = await findFirstVisibleElement(page, candidates, timeout);

    if (!found) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'timeout',
        statusMessage: `Kein Hover-Ziel wurde innerhalb des Timeouts gefunden: ${candidates.map((candidate) => candidate.value).join(', ')}`,
        attemptedCandidates: candidates.map((candidate) => candidate.value),
      });
    }

    let cursor = null;
    let element = null;

    try {
      element = await elementSnapshot(found.handle, found.selector);
      const moved = await moveCursorToHandle(page, found.handle, {
        action: 'hover',
        context,
      });

      if (!moved.handled) {
        await found.handle.hover();
      }

      cursor = moved.cursor || null;
    } catch (error) {
      await found.handle.dispose?.().catch(() => {});

      throw error;
    }

    if (settleMs > 0) {
      await sleep(settleMs);
    }

    const cached = await rememberFoundElement(context, found, {
      sourceTaskType: 'browser.hover',
    });

    context.__workflowHeldHover = {
      browserWindow: String(context.activeBrowserWindow || context.browserWindow || 'main').trim() || 'main',
      cachedElement: cached === true,
      candidate: found.candidate || null,
      element,
      frame: found.frame || null,
      handle: found.handle,
      heldAt: Date.now(),
      page,
      releaseAfterClick,
      selector: found.selector,
    };

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Cursor wurde auf dem Element positioniert und der Hover-Zustand wird gehalten.',
      selector: found.selector,
      matchedBy: found.matchedBy,
      matchedCandidate: found.candidate.value,
      element,
      hoverHeld: true,
      releaseAfterClick,
      cachedElement: cached === true,
      ...(cursor ? { cursor } : {}),
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Hover-Ziel konnte nicht gehalten werden.',
      attemptedCandidates: candidates.map((candidate) => candidate.value),
      error: error.message,
    });
  }
}

module.exports = { key: 'browser.hover', run };
