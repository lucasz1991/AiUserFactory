'use strict';

const { normalizeElementCandidates } = require('../../lib/selector.cjs');
const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  collectVisibleElements,
  elementCandidatesFromInput,
} = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};

  if (!page || (
    typeof page.frames !== 'function'
    && typeof page.mainFrame !== 'function'
    && typeof page.evaluate !== 'function'
  )) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Input-Suche vorhanden.' };
  }

  const configuredCandidates = elementCandidatesFromInput(input, {
    textKeys: ['text', 'texts', 'label', 'labels'],
  });
  const candidates = configuredCandidates.length > 0
    ? configuredCandidates
    : normalizeElementCandidates(
      ['input', 'textarea', 'select', '[contenteditable="true"]'],
      { defaultKind: 'selector' },
    );
  const inputs = await collectVisibleElements(page, candidates, {
    elementSelector: 'input,textarea,select,[contenteditable="true"]',
    textSelector: 'input,textarea,select,[contenteditable="true"]',
  });

  return captureTaskPreview(context, {
    ok: inputs.length > 0,
    status: inputs.length > 0 ? 'success' : 'partial',
    statusMessage: inputs.length > 0 ? 'Input-Felder gefunden.' : 'Keine sichtbaren Input-Felder gefunden.',
    attemptedCandidates: candidates.map((candidate) => candidate.value),
    inputs: inputs.map((element, index) => ({
      ...element,
      index,
      selector: element.generatedSelector || element.selector,
    })),
  });
}

module.exports = { key: 'browser.find_inputs', run };
