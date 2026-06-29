'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  maxAgeSeconds,
  normalizeText,
  optionBoolean,
  optionNumber,
  optionString,
  scalarInputValue,
  scanMailList,
  setWorkflowVariable,
  taskOptions,
  variableName,
} = require('../lib/mail_list.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const options = taskOptions(input);
  const scalarValue = scalarInputValue(input);
  const listSelector = optionString(options, input, ['list_selector', 'listSelector'], normalizeText(input.selector || input.elementSelector || input.element_selector));
  const listItemSelector = optionString(options, input, ['list_item_selector', 'listItemSelector', 'item_selector', 'itemSelector'], scalarValue);
  const outputArrayName = variableName(optionString(options, input, ['output_array_name', 'outputArrayName', 'output_name', 'outputName'], 'inbox_mails'), 'inbox_mails');
  const includeUnknownAge = optionBoolean(options, input, ['include_unknown_age', 'includeUnknownAge'], true);
  const maximumAgeSeconds = maxAgeSeconds(options, input, 0);
  const maxItems = Math.max(1, Math.min(200, optionNumber(options, input, ['max_items', 'maxItems', 'limit'], 50)));

  if (!page || (typeof page.frames !== 'function' && typeof page.evaluate !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer den Mail-Inbox-Scan vorhanden.' };
  }

  const candidates = await scanMailList(page, {
    ...options,
    list_selector: listSelector,
    list_item_selector: listItemSelector,
    max_items: maxItems,
  });
  const filtered = candidates
    .filter((candidate) => {
      if (!maximumAgeSeconds) {
        return true;
      }

      if (candidate.ageSeconds === null || candidate.ageSeconds === undefined) {
        return includeUnknownAge;
      }

      return Number(candidate.ageSeconds) <= maximumAgeSeconds;
    })
    .slice(0, maxItems)
    .map((candidate, index) => ({ ...candidate, index }));

  setWorkflowVariable(context, outputArrayName, filtered);

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: `${filtered.length} Mail-Listeneintraege wurden ermittelt.`,
    outputArrayName,
    output_array_name: outputArrayName,
    candidateCount: filtered.length,
    candidate_count: filtered.length,
    maxAgeSeconds: maximumAgeSeconds || null,
    max_age_seconds: maximumAgeSeconds || null,
    listSelector,
    list_selector: listSelector,
    listItemSelector,
    list_item_selector: listItemSelector,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = { key: 'mail.inbox_list_scan', run };
