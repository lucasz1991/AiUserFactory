'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  clickFirstVisibleElement,
  elementCandidatesFromInput,
} = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels', 'value'],
  });

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Klick-Task vorhanden.' };
  }

  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Klicktext uebergeben.' };
  }

  try {
    const clicked = await clickFirstVisibleElement(page, candidates, timeout, { context });

    if (clicked) {
      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: clicked.matchedBy === 'text'
          ? 'Element wurde ueber Text geklickt.'
          : 'Element wurde geklickt.',
        selector: clicked.selector,
        matchedBy: clicked.matchedBy,
        matchedCandidate: clicked.candidate.value,
        element: clicked.element,
        cachedElement: clicked.cachedElement === true,
        clickMode: clicked.clickMode || 'mouse',
        hoverPreservedDuringClick: clicked.hoverPreservedDuringClick === true,
        hoverReleased: clicked.hoverReleased === true,
        url: typeof page.url === 'function' ? page.url() : null,
      });
    }
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Keines der gefundenen Ziele konnte geklickt werden.',
      attemptedCandidates: candidates.map((candidate) => candidate.value),
      error: error.message,
    });
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein klickbares Ziel uebergeben oder gefunden.',
    attemptedCandidates: candidates.map((candidate) => candidate.value),
  });
}

module.exports = { key: 'browser.click', run };
