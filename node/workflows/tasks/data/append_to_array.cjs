'use strict';

const {
  cleanName,
  getPath,
  number,
  resolveVariable,
  setWorkflowVariable,
  text,
} = require('../lib/collection.cjs');

async function run(context = {}) {
  const input = context.input || {};
  const arrayName = cleanName(input.array_name ?? input.arrayName, 'items');
  const source = text(input.value_from_variable ?? input.valueFromVariable);
  const dedupeBy = text(input.dedupe_by ?? input.dedupeBy);
  const maxItems = Math.floor(number(input.max_items ?? input.maxItems, 0, 0));
  const current = resolveVariable(context, arrayName, []);
  const items = Array.isArray(current) ? [...current] : [];
  const value = source !== ''
    ? resolveVariable(context, source)
    : (context.lastResult?.result ?? context.lastResult?.value);

  if (value === undefined || value === null || value === '') {
    return { ok: false, status: 'failed', statusMessage: `Kein Wert zum Anhaengen an "${arrayName}" gefunden.` };
  }

  let appended = false;
  let deduped = false;
  if (maxItems > 0 && items.length >= maxItems) {
    deduped = true;
  } else if (dedupeBy !== '') {
    const candidate = getPath(value, dedupeBy);
    deduped = candidate !== undefined && items.some((item) => getPath(item, dedupeBy) === candidate);
    if (!deduped) {
      items.push(value);
      appended = true;
    }
  } else {
    items.push(value);
    appended = true;
  }

  setWorkflowVariable(context, arrayName, items);

  return {
    ok: true,
    status: appended ? 'success' : 'skipped',
    statusMessage: appended
      ? `Wert an "${arrayName}" angehaengt (${items.length}).`
      : `Wert fuer "${arrayName}" wurde uebersprungen.`,
    array_name: arrayName,
    new_length: items.length,
    appended,
    deduped,
    preview: items.slice(0, 3),
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'data.append_to_array', run };
