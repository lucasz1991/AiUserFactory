'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  extractValueFromText,
  normalizeText,
  optionBoolean,
  optionString,
  readTextFromFrames,
  scalarInputValue,
  selectorsFrom,
  setWorkflowVariable,
  taskOptions,
  valueFromPath,
  variableName,
  workflowVariableRoot,
} = require('../lib/mail_list.cjs');

async function sourceText(context = {}, page = null, input = {}, options = {}) {
  const configuredSource = optionString(options, input, ['source', 'source_path', 'sourcePath'], '');

  if (configuredSource && !['visible_text', 'page_text', 'mail_body'].includes(configuredSource)) {
    const value = valueFromPath(workflowVariableRoot(context), configuredSource)
      ?? valueFromPath(workflowVariableRoot(context), `workflow_variables.${configuredSource}`)
      ?? valueFromPath(workflowVariableRoot(context), `workflowVariables.${configuredSource}`);

    if (value !== undefined && value !== null) {
      return typeof value === 'string' ? value : JSON.stringify(value);
    }
  }

  const directText = optionString(options, input, ['text', 'mail_text', 'mailText'], '');

  if (directText) {
    return directText;
  }

  const selector = optionString(options, input, ['body_selector', 'bodySelector', 'mail_body_selector', 'mailBodySelector'], input.selector || input.elementSelector || input.element_selector || '');

  if (page && (typeof page.frames === 'function' || typeof page.evaluate === 'function')) {
    const chunks = await readTextFromFrames(page, selectorsFrom(selector));

    return chunks.map((chunk) => chunk.text).filter(Boolean).join('\n\n');
  }

  return '';
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const options = taskOptions(input);
  const scalarValue = scalarInputValue(input);
  const regex = optionString(options, input, ['regex', 'extract_regex', 'extractRegex'], scalarValue);
  const extractOptions = {
    ...options,
    regex: regex || options.regex || options.extract_regex || options.extractRegex,
  };
  const outputValueName = variableName(optionString(options, input, ['output_value_name', 'outputValueName', 'output_name', 'outputName'], 'mail_value'), 'mail_value');
  const required = optionBoolean(options, input, ['required', 'require_value', 'requireValue'], true);
  const text = await sourceText(context, page, input, options);
  const extracted = extractValueFromText(text, extractOptions);

  if (!extracted) {
    return captureTaskPreview(context, {
      ok: !required,
      status: required ? 'failed' : 'partial',
      statusMessage: required
        ? 'Kein Wert konnte aus der Mail ermittelt werden.'
        : 'Kein Wert konnte aus der Mail ermittelt werden; Task ist optional.',
      outputValueName,
      output_value_name: outputValueName,
      textLength: normalizeText(text).length,
      text_length: normalizeText(text).length,
    }, true);
  }

  setWorkflowVariable(context, outputValueName, extracted.value);

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Wert wurde aus der Mail ermittelt.',
    outputValueName,
    output_value_name: outputValueName,
    value: extracted.value,
    extractedValue: extracted.value,
    extracted_value: extracted.value,
    sourceSnippet: extracted.snippet || '',
    source_snippet: extracted.snippet || '',
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = { key: 'mail.extract_value', run };
