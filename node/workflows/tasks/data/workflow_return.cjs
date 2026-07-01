'use strict';

function normalizeText(value) {
  return String(value ?? '').trim();
}

function parseValue(value) {
  const text = normalizeText(value);

  if (text === '') {
    return true;
  }

  if (['true', 'success', 'ok', 'ja', 'yes', '1'].includes(text.toLowerCase())) {
    return true;
  }

  if (['false', 'failed', 'fail', 'nein', 'no', '0'].includes(text.toLowerCase())) {
    return false;
  }

  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function variableName(input = {}) {
  return normalizeText(input.selector || input.elementSelector || input.element_selector || 'workflow_return')
    .replace(/\s+/g, '_')
    .replace(/[^A-Za-z0-9_.-]+/g, '')
    .slice(0, 120) || 'workflow_return';
}

async function run(context = {}) {
  const input = context.input || {};
  const key = variableName(input);
  const value = parseValue(input.value ?? input.inputValue ?? input.input ?? true);
  const ok = value !== false;
  const variables = {
    ...(context.workflow_variables || {}),
    [key]: value,
    workflow_return: value,
    workflow_return_ok: ok,
  };

  context.workflow_variables = variables;
  context.workflowVariables = {
    ...(context.workflowVariables || {}),
    ...variables,
  };
  context.workflow_return = value;
  context.workflowReturn = value;
  context.workflow_return_ok = ok;

  return {
    ok,
    status: ok ? 'success' : 'failed',
    statusMessage: ok
      ? 'Workflow-Rueckgabewert wurde gesetzt.'
      : 'Workflow-Rueckgabewert wurde als Fehler gesetzt.',
    workflow_return_key: key,
    workflowReturnKey: key,
    workflow_return: value,
    workflowReturn: value,
    workflow_return_ok: ok,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'data.workflow_return', run };
