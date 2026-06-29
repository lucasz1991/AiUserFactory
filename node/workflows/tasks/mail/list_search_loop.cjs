'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const {
  clickMailCandidate,
  extractValueFromText,
  mailMatches,
  maxAgeSeconds,
  normalizeText,
  optionBoolean,
  optionNumber,
  optionString,
  readTextFromFrames,
  scalarInputValue,
  selectorsFrom,
  setWorkflowVariable,
  taskOptions,
  valueFromPath,
  variableName,
  wait,
  workflowVariableRoot,
} = require('../lib/mail_list.cjs');

function fieldsFrom(value) {
  if (Array.isArray(value)) {
    return value.map(normalizeText).filter(Boolean);
  }

  const text = normalizeText(value);

  if (!text) {
    return ['subject', 'sender', 'preview', 'text', 'body'];
  }

  return text.split(/[,;\s]+/).map(normalizeText).filter(Boolean);
}

function arrayFromContext(context = {}, name = '') {
  const root = workflowVariableRoot(context);
  const candidates = [
    name,
    `workflow_variables.${name}`,
    `workflowVariables.${name}`,
    `lastResult.workflow_variables.${name}`,
    `lastResult.workflowVariables.${name}`,
  ];

  for (const path of candidates) {
    const value = valueFromPath(root, path);

    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
}

function outputValueFromMail(mail = {}, source = 'body') {
  const value = valueFromPath(mail, source);

  if (value !== undefined && value !== null && value !== '') {
    return value;
  }

  return mail.body || mail.text || mail.preview || mail.subject || '';
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const options = taskOptions(input);
  const scalarValue = scalarInputValue(input);
  const inputArrayName = variableName(optionString(options, input, ['input_array_name', 'inputArrayName', 'array_name', 'arrayName'], 'inbox_mails'), 'inbox_mails');
  const searchText = optionString(options, input, ['search_text', 'searchText', 'query', 'contains'], scalarValue);
  const searchFields = fieldsFrom(optionString(options, input, ['search_fields', 'searchFields'], 'subject,sender,preview,text,body'));
  const bodySelectors = selectorsFrom(optionString(options, input, ['body_selector', 'bodySelector', 'mail_body_selector', 'mailBodySelector'], input.selector || input.elementSelector || input.element_selector || ''));
  const outputMailName = variableName(optionString(options, input, ['output_mail_name', 'outputMailName'], 'matched_mail'), 'matched_mail');
  const outputValueName = normalizeText(optionString(options, input, ['output_value_name', 'outputValueName'], ''));
  const outputValueSource = optionString(options, input, ['output_value_source', 'outputValueSource'], 'body');
  const maximumAgeSeconds = maxAgeSeconds(options, input, 0);
  const includeUnknownAge = optionBoolean(options, input, ['include_unknown_age', 'includeUnknownAge'], true);
  const maxOpenCount = Math.max(1, Math.min(50, optionNumber(options, input, ['max_open_count', 'maxOpenCount', 'max_mail_clicks', 'maxMailClicks', 'limit'], 8)));
  const openWaitMs = Math.max(0, Math.min(10000, optionNumber(options, input, ['open_wait_ms', 'openWaitMs'], 900)));
  const stopOnFirstMatch = optionBoolean(options, input, ['stop_on_first_match', 'stopOnFirstMatch'], true);
  const requireExtractedValue = optionBoolean(options, input, ['require_extracted_value', 'requireExtractedValue', 'require_value', 'requireValue'], Boolean(options.regex || options.extract_regex || options.extractRegex));
  const sourceMails = arrayFromContext(context, inputArrayName);
  const openedMails = [];
  const matches = [];

  if (!page || (typeof page.frames !== 'function' && typeof page.evaluate !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die Mail-Suchschleife vorhanden.' };
  }

  if (sourceMails.length === 0) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: `Keine Mail-Liste unter "${inputArrayName}" gefunden.`,
      inputArrayName,
      input_array_name: inputArrayName,
    }, true);
  }

  startTaskPreview(context);

  for (const candidate of sourceMails) {
    if (openedMails.length >= maxOpenCount) {
      break;
    }

    if (maximumAgeSeconds && candidate.ageSeconds !== null && candidate.ageSeconds !== undefined && Number(candidate.ageSeconds) > maximumAgeSeconds) {
      continue;
    }

    if (maximumAgeSeconds && (candidate.ageSeconds === null || candidate.ageSeconds === undefined) && !includeUnknownAge) {
      continue;
    }

    const needsBodySearch = searchText === '' || searchFields.includes('body') || searchFields.includes('mail_body');
    const preFields = searchFields.filter((field) => !['body', 'mail_body'].includes(field));

    if (searchText && preFields.length > 0 && !needsBodySearch && !mailMatches(candidate, searchText, preFields)) {
      continue;
    }

    const clicked = await clickMailCandidate(page, candidate, options);

    openedMails.push({
      clicked,
      subject: candidate.subject || '',
      sender: candidate.sender || '',
      preview: candidate.preview || '',
      ageSeconds: candidate.ageSeconds ?? null,
      frameUrl: candidate.frameUrl || '',
    });

    if (!clicked) {
      continue;
    }

    if (openWaitMs > 0) {
      await wait(openWaitMs);
    }

    const bodyChunks = await readTextFromFrames(page, bodySelectors);
    const body = bodyChunks.map((chunk) => chunk.text).filter(Boolean).join('\n\n');
    const mail = {
      ...candidate,
      body,
      bodyChunks,
      body_chunks: bodyChunks,
      openedAt: new Date().toISOString(),
    };

    if (searchText && !mailMatches(mail, searchText, searchFields)) {
      continue;
    }

    const extracted = (options.regex || options.extract_regex || options.extractRegex || options.extract_mode || options.extractMode)
      ? extractValueFromText(body, { ...options, search_text: searchText })
      : null;

    if (requireExtractedValue && !extracted) {
      continue;
    }

    matches.push({
      mail,
      extractedValue: extracted?.value || null,
      extracted_value: extracted?.value || null,
      snippet: extracted?.snippet || '',
    });

    if (stopOnFirstMatch) {
      break;
    }
  }

  if (matches.length === 0) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine passende Mail in der Suchschleife gefunden.',
      inputArrayName,
      input_array_name: inputArrayName,
      searchText,
      search_text: searchText,
      sourceCount: sourceMails.length,
      source_count: sourceMails.length,
      openedMails,
      opened_mails: openedMails,
    }, true);
  }

  const firstMatch = matches[0];
  const matchedMail = firstMatch.mail;

  setWorkflowVariable(context, outputMailName, matchedMail);

  if (outputValueName) {
    setWorkflowVariable(
      context,
      outputValueName,
      firstMatch.extractedValue || outputValueFromMail(matchedMail, outputValueSource),
    );
  }

  setWorkflowVariable(context, `${outputMailName}_matches`, matches.map((match) => match.mail));

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Passende Mail wurde in der Liste gefunden.',
    inputArrayName,
    input_array_name: inputArrayName,
    outputMailName,
    output_mail_name: outputMailName,
    outputValueName: outputValueName || null,
    output_value_name: outputValueName || null,
    extractedValue: firstMatch.extractedValue || null,
    extracted_value: firstMatch.extractedValue || null,
    matchedMail,
    matched_mail: matchedMail,
    matchCount: matches.length,
    match_count: matches.length,
    openedMails,
    opened_mails: openedMails,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = { key: 'mail.list_search_loop', run };
