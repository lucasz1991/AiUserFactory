'use strict';

const {
  appendWorkflowArray,
  text,
} = require('../lib/collection.cjs');

async function run(context = {}) {
  const input = context.input || {};
  const arrayName = input.array_name ?? input.arrayName ?? 'items';
  const source = text(input.value_from_variable ?? input.valueFromVariable);
  const result = appendWorkflowArray(context, {
    arrayName,
    valueFromVariable: source,
    dedupeBy: input.dedupe_by ?? input.dedupeBy,
    maxItems: input.max_items ?? input.maxItems,
  });

  if (!result.ok) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: result.message,
      array_name: result.arrayName,
      source_variable: result.source || source || null,
      reason_code: result.reason,
      workflow_variables: context.workflow_variables,
    };
  }

  return {
    ok: true,
    status: result.appended ? 'success' : 'skipped',
    statusMessage: result.appended
      ? `Wert an "${result.arrayName}" angehaengt (${result.newLength}).`
      : (result.limitReached
        ? `Maximale Anzahl fuer "${result.arrayName}" ist erreicht (${result.newLength}).`
        : `Doppelter Wert fuer "${result.arrayName}" wurde uebersprungen.`),
    array_name: result.arrayName,
    source_variable: result.source || source || null,
    new_length: result.newLength,
    appended: result.appended,
    deduped: result.deduped,
    limit_reached: result.limitReached,
    preview: result.items.slice(0, 3),
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'data.append_to_array', run };
