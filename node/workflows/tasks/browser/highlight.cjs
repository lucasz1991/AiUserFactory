'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  elementCandidatesFromInput,
  elementSnapshot,
  findFirstVisibleElement,
} = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 30000);
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels', 'value'],
  });

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Highlight-Task vorhanden.' };
  }
  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Text zum Hervorheben angegeben.' };
  }

  const found = await findFirstVisibleElement(page, candidates, timeout);
  if (!found) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'partial',
      statusMessage: 'Kein sichtbares Element zum Hervorheben gefunden.',
      attemptedCandidates: candidates.map((candidate) => candidate.value),
    });
  }

  try {
    await found.handle.evaluate((element) => {
      element.setAttribute('data-workflow-studio-highlight', 'true');
      element.style.setProperty('outline', '3px solid #22d3ee', 'important');
      element.style.setProperty('outline-offset', '3px', 'important');
      element.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
    });
    const element = await elementSnapshot(found.handle, found.selector);

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Element wurde im Browser hervorgehoben.',
      selector: found.selector,
      matchedBy: found.matchedBy,
      matchedCandidate: found.candidate.value,
      element,
    });
  } finally {
    await found.handle.dispose?.().catch(() => {});
  }
}

module.exports = { key: 'browser.highlight', run };
