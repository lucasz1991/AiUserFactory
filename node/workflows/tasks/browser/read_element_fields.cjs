'use strict';

const {
  cleanName,
  parseJson,
  privateRegistry,
  readFields,
  setWorkflowVariable,
  text,
} = require('../lib/collection.cjs');

async function run(context = {}) {
  const input = context.input || {};
  const scopeName = cleanName(input.scope_variable ?? input.scopeVariable, 'current_element');
  const outputName = cleanName(input.output_variable ?? input.outputVariable, scopeName);
  const fields = parseJson(input.fields ?? input.value ?? input.success_payload, []);
  const scope = privateRegistry(context, '__workflowElementScopes')[scopeName];

  if (!scope) {
    return { ok: false, status: 'failed', statusMessage: `DOM-Scope "${scopeName}" ist nicht aktiv.` };
  }
  if (!Array.isArray(fields) || fields.length === 0) {
    return { ok: false, status: 'failed', statusMessage: 'Keine Felddefinitionen fuer browser.read_element_fields angegeben.' };
  }

  const pageUrl = typeof context.page?.url === 'function' ? context.page.url() : '';
  const extracted = await readFields(scope, fields, pageUrl);
  setWorkflowVariable(context, outputName, extracted.result);
  const ok = extracted.requiredMissing.length === 0;
  if (!ok) privateRegistry(context, '__workflowLoopSkippedScopes')[scopeName] = true;

  return {
    ok,
    status: ok ? 'success' : 'failed',
    statusMessage: ok
      ? `${Object.keys(extracted.result).length} Feld(er) aus "${scopeName}" gelesen.`
      : `Pflichtfelder ohne Wert: ${extracted.requiredMissing.join(', ')}.`,
    result: extracted.result,
    scope_variable: scopeName,
    output_variable: outputName,
    selectors_used: extracted.selectors,
    empty_fields: extracted.emptyFields,
    required_missing: extracted.requiredMissing,
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'browser.read_element_fields', run };
