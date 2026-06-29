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
  stringListFrom,
  taskOptions,
  variableName,
  wait,
} = require('../lib/mail_list.cjs');

function candidateKey(candidate = {}) {
  return [
    candidate.subject || '',
    candidate.sender || '',
    candidate.dateText || '',
    String(candidate.text || '').slice(0, 240),
  ].join('|').toLowerCase();
}

function subjectMatches(candidate = {}, filters = []) {
  if (filters.length === 0) {
    return true;
  }

  const subject = normalizeText(candidate.subject || candidate.text).toLowerCase();

  return filters.some((filter) => subject.includes(normalizeText(filter).toLowerCase()));
}

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
  const waitForNewMailSeconds = Math.max(0, Math.min(3600, optionNumber(options, input, ['wait_for_new_mail_seconds', 'waitForNewMailSeconds', 'wait_seconds', 'waitSeconds'], 0)));
  const subjectFilters = stringListFrom(optionString(options, input, ['subject_filter', 'subjectFilter', 'subject_must_contain', 'subjectMustContain'], ''), []);

  if (!page || (typeof page.frames !== 'function' && typeof page.evaluate !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer den Mail-Inbox-Scan vorhanden.' };
  }

  const collectFiltered = async () => {
    const candidates = await scanMailList(page, {
      ...options,
      list_selector: listSelector,
      list_item_selector: listItemSelector,
      max_items: maxItems,
    });

    return candidates.filter((candidate) => {
      if (!maximumAgeSeconds) {
        return subjectMatches(candidate, subjectFilters);
      }

      if (candidate.ageSeconds === null || candidate.ageSeconds === undefined) {
        return includeUnknownAge && subjectMatches(candidate, subjectFilters);
      }

      return Number(candidate.ageSeconds) <= maximumAgeSeconds && subjectMatches(candidate, subjectFilters);
    });
  };

  const byKey = new Map();
  let pollCount = 0;

  for (const candidate of await collectFiltered()) {
    byKey.set(candidateKey(candidate), candidate);
  }

  const initialCount = byKey.size;
  const deadline = Date.now() + (waitForNewMailSeconds * 1000);

  while (waitForNewMailSeconds > 0 && Date.now() < deadline) {
    await wait(Math.min(5000, Math.max(0, deadline - Date.now())));
    pollCount += 1;

    const before = byKey.size;

    for (const candidate of await collectFiltered()) {
      byKey.set(candidateKey(candidate), candidate);
    }

    if (byKey.size > before && byKey.size > initialCount) {
      break;
    }
  }

  const filtered = Array.from(byKey.values())
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
    subjectFilters,
    subject_filters: subjectFilters,
    waitForNewMailSeconds,
    wait_for_new_mail_seconds: waitForNewMailSeconds,
    pollCount,
    poll_count: pollCount,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = { key: 'mail.inbox_list_scan', run };
