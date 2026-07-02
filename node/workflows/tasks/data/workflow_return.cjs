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

function valueFromPath(source = {}, path = '') {
  const normalized = normalizeText(path);

  if (!normalized || !source || typeof source !== 'object') {
    return undefined;
  }

  return normalized
    .split('.')
    .filter(Boolean)
    .reduce((value, key) => {
      if (value === undefined || value === null) {
        return undefined;
      }

      return value[key];
    }, source);
}

function workflowVariableRoot(context = {}) {
  return {
    ...(context && typeof context === 'object' ? context : {}),
    ...(context.workflow && typeof context.workflow === 'object' ? context.workflow : {}),
    ...(context.workflow_variables && typeof context.workflow_variables === 'object' ? context.workflow_variables : {}),
    ...(context.workflowVariables && typeof context.workflowVariables === 'object' ? context.workflowVariables : {}),
  };
}

function variableName(input = {}) {
  return normalizeText(input.selector || input.elementSelector || input.element_selector || 'workflow_return')
    .replace(/\s+/g, '_')
    .replace(/[^A-Za-z0-9_.-]+/g, '')
    .slice(0, 120) || 'workflow_return';
}

function resolveReturnValue(context = {}, input = {}, key = 'workflow_return') {
  const rawValue = input.value ?? input.inputValue ?? input.input_value ?? input.input ?? '';
  const text = normalizeText(rawValue);

  if (text !== '') {
    return parseValue(rawValue);
  }

  if (key !== 'workflow_return') {
    const root = workflowVariableRoot(context);
    const existing = valueFromPath(root, key)
      ?? valueFromPath(root, `workflow_variables.${key}`)
      ?? valueFromPath(root, `workflowVariables.${key}`);

    if (existing !== undefined && existing !== null && existing !== '') {
      return existing;
    }
  }

  return true;
}

async function run(context = {}) {
  const input = context.input || {};
  const key = variableName(input);
  const value = resolveReturnValue(context, input, key);
  const ok = value !== false;
  const variables = {
    ...(context.workflow_variables || {}),
    [key]: value,
    workflow_return: value,
    workflow_return_ok: ok,
    workflow_return_preview: Array.isArray(value) ? value.slice(0, 3) : value,
    workflowReturnPreview: Array.isArray(value) ? value.slice(0, 3) : value,
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
    workflow_return_preview: Array.isArray(value) ? value.slice(0, 3) : value,
    workflowReturnPreview: Array.isArray(value) ? value.slice(0, 3) : value,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'data.workflow_return', run };
