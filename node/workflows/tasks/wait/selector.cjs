'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const {
  elementCandidatesFromInput,
  findFirstVisibleElement,
  rememberFoundElement,
} = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels'],
  });
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Selector-Wait vorhanden.' };
  }

  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Suchtext fuer Warte-Task uebergeben.' };
  }

  try {
    startTaskPreview(context);
    let found = await findFirstVisibleElement(page, candidates, timeout);

    if (!found && typeof context.refreshActivePage === 'function') {
      const refreshedPage = await context.refreshActivePage().catch(() => null);

      if (refreshedPage && refreshedPage !== page) {
        found = await findFirstVisibleElement(refreshedPage, candidates, Math.min(timeout, 15000));
      }
    }

    if (!found) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'timeout',
        statusMessage: `Kein Ziel wurde innerhalb des Timeouts gefunden: ${candidates.map((candidate) => candidate.value).join(', ')}`,
        attemptedCandidates: candidates.map((candidate) => candidate.value),
      });
    }

    const cached = await rememberFoundElement(context, found, {
      sourceTaskType: 'wait.selector',
    });

    if (!cached) {
      await found.handle.dispose?.().catch(() => {});
    }

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Ziel wurde gefunden.',
      selector: found.selector,
      matchedBy: found.matchedBy,
      matchedCandidate: found.candidate.value,
    });
  } catch (error) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'timeout',
      statusMessage: `Kein Ziel wurde innerhalb des Timeouts gefunden: ${candidates.map((candidate) => candidate.value).join(', ')}`,
      attemptedCandidates: candidates.map((candidate) => candidate.value),
    });
  }
}

module.exports = { key: 'wait.selector', run };
