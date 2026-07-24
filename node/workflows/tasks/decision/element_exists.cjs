'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  elementCandidatesFromInput,
  elementSnapshot,
  findFirstVisibleElement,
  framesForPage,
  rememberFoundElement,
} = require('../lib/find_visible_element.cjs');
const {
  moveCursorToHandle,
} = require('../lib/cursor.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const candidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels'],
  });
  const timeout = Math.max(0, Number(
    input.timeoutMs
    || (Number(input.timeout_seconds || 0) * 1000)
    || context.timeoutMs
    || 15000,
  ));
  const deadline = Date.now() + timeout;

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die IF-Element-Pruefung vorhanden.' };
  }

  if (candidates.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Selector oder Suchtext fuer die IF-Element-Pruefung angegeben.' };
  }

  let searchedFrames = framesForPage(page).length;
  let foundPage = page;
  let found = await findFirstVisibleElement(page, candidates, timeout);

  searchedFrames = Math.max(searchedFrames, framesForPage(page).length);

  if (!found && typeof context.refreshActivePage === 'function') {
    const refreshedPage = await context.refreshActivePage().catch(() => null);
    const remainingTimeout = Math.max(0, deadline - Date.now());

    if (refreshedPage && refreshedPage !== page && remainingTimeout > 0) {
      found = await findFirstVisibleElement(refreshedPage, candidates, remainingTimeout);
      searchedFrames = Math.max(searchedFrames, framesForPage(refreshedPage).length);
      foundPage = refreshedPage;
    }
  }

  if (!found) {
    return {
      ok: true,
      status: 'not_found',
      statusMessage: `IF-Bedingung nicht erfuellt: Kein Element gefunden (${candidates.map((candidate) => candidate.value).join(', ')}).`,
      attemptedCandidates: candidates.map((candidate) => candidate.value),
      elementExists: false,
      searchedFrames,
      searchedOpenShadowDom: true,
      branchOutcome: 'failed',
      branch_outcome: 'failed',
      conditionMatched: false,
      condition_matched: false,
      logicalOutcome: 'condition_false',
      logical_outcome: 'condition_false',
    };
  }

  let cursor = null;
  let element = null;

  try {
    element = await elementSnapshot(found.handle, found.selector);
    const moved = await moveCursorToHandle(foundPage, found.handle, {
      action: 'condition',
      context,
    });
    cursor = moved.cursor || null;
  } catch (error) {
    await found.handle.dispose?.().catch(() => {});

    throw error;
  }

  const cached = await rememberFoundElement(context, found, {
    sourceTaskType: 'decision.element_exists',
  });

  if (!cached) {
    await found.handle.dispose?.().catch(() => {});
  }

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'IF-Bedingung erfuellt: Element wurde gefunden.',
    selector: found.selector,
    matchedBy: found.matchedBy,
    matchedCandidate: found.candidate.value,
    elementExists: true,
    searchedFrames,
    searchedOpenShadowDom: true,
    element,
    ...(cursor ? { cursor } : {}),
    conditionMatched: true,
    condition_matched: true,
    logicalOutcome: 'condition_true',
    logical_outcome: 'condition_true',
  });
}

module.exports = { key: 'decision.element_exists', run };
