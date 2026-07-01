'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  elementCandidatesFromInput,
  elementSnapshot,
  findFirstVisibleElement,
  rememberFoundElement,
} = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 45000);
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels', 'name', 'value'],
  });

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Element-Suche vorhanden.' };
  }

  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Suchtext fuer die Element-Suche angegeben.' };
  }

  const found = await findFirstVisibleElement(page, candidates, timeout);

  if (found) {
    let element = null;

    try {
      element = await elementSnapshot(found.handle, found.selector);
    } catch (error) {
      await found.handle.dispose?.().catch(() => {});

      throw error;
    }

    const cached = await rememberFoundElement(context, found, {
      sourceTaskType: 'browser.find_element',
    });

    if (!cached) {
      await found.handle.dispose?.().catch(() => {});
    }

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: found.matchedBy === 'text'
        ? 'Element wurde ueber Text gefunden.'
        : 'Element wurde gefunden.',
      selector: found.selector,
      matchedBy: found.matchedBy,
      matchedCandidate: found.candidate.value,
      element,
    });
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'partial',
    statusMessage: 'Kein Element gefunden. Weiterleitung kann ueber Teilstatus oder Fehler erfolgen.',
    attemptedCandidates: candidates.map((candidate) => candidate.value),
  });
}

module.exports = { key: 'browser.find_element', run };
