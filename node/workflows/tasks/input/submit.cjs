'use strict';

const { normalizeElementCandidates } = require('../../lib/selector.cjs');
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
  const searchCandidates = [
    ...candidates,
    ...normalizeElementCandidates([
      'button[type=submit]',
      'input[type=submit]',
      'button:has-text("Weiter")',
      'button:has-text("Login")',
      'button:has-text("Anmelden")',
      'button:has-text("Create")',
    ], { defaultKind: 'selector' }),
  ];

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Submit vorhanden.' };
  }

  try {
    const clicked = await clickFirstVisibleElement(page, searchCandidates, timeout);

    if (clicked) {
      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: 'Submit wurde ausgeloest.',
        selector: clicked.selector,
        matchedBy: clicked.matchedBy,
        matchedCandidate: clicked.candidate.value,
        element: clicked.element,
      });
    }
  } catch (error) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Submit-Element wurde gefunden, konnte aber nicht geklickt werden.',
      attemptedCandidates: searchCandidates.map((candidate) => candidate.value),
      error: error.message,
    };
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein Submit-Element gefunden.',
    attemptedCandidates: searchCandidates.map((candidate) => candidate.value),
  };
}

module.exports = { key: 'input.submit', run };
