'use strict';

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function getPath(source, path) {
  const normalized = text(path);

  if (normalized === '' || !source || typeof source !== 'object') {
    return undefined;
  }

  if (Object.prototype.hasOwnProperty.call(source, normalized)) {
    return source[normalized];
  }

  return normalized
    .replace(/\[(\w+)\]/g, '.$1')
    .split('.')
    .filter(Boolean)
    .reduce((current, segment) => {
      if (current === undefined || current === null || typeof current !== 'object') {
        return undefined;
      }

      return current[segment];
    }, source);
}

function hasValue(value) {
  if (value === undefined || value === null) {
    return false;
  }

  if (typeof value === 'string') {
    return value.trim() !== '';
  }

  if (Array.isArray(value)) {
    return value.length > 0;
  }

  if (isObject(value)) {
    return Object.keys(value).length > 0;
  }

  return true;
}

function parseExpected(value) {
  if (typeof value !== 'string') {
    return value;
  }

  const normalized = value.trim();

  if (normalized === '') {
    return '';
  }

  try {
    return JSON.parse(normalized);
  } catch {
    return normalized;
  }
}

function variableRoot(context = {}) {
  const workflow = isObject(context.workflow) ? context.workflow : {};
  const variables = {
    ...(isObject(workflow.workflow_variables) ? workflow.workflow_variables : {}),
    ...(isObject(workflow.workflowVariables) ? workflow.workflowVariables : {}),
    ...(isObject(context.workflow_variables) ? context.workflow_variables : {}),
    ...(isObject(context.workflowVariables) ? context.workflowVariables : {}),
  };

  return {
    ...workflow,
    ...context,
    workflow,
    workflow_variables: variables,
    workflowVariables: variables,
    lastResult: context.lastResult || null,
  };
}

function resolveVariable(context = {}, path = '') {
  const root = variableRoot(context);
  const candidates = [
    path,
    `workflow_variables.${path}`,
    `workflowVariables.${path}`,
    `workflow_inputs.${path}`,
    `workflowVariables.workflow_inputs.${path}`,
    `lastResult.${path}`,
  ];

  for (const candidate of candidates) {
    const value = getPath(root, candidate);

    if (value !== undefined) {
      return value;
    }
  }

  return undefined;
}

function normalizeBrowserWindowName(value) {
  if (isObject(value)) {
    return normalizeBrowserWindowName(value.key || value.name || value.browser_window || value.browserWindow || '');
  }

  return text(value)
    .replace(/\s+/g, '-')
    .replace(/[^A-Za-z0-9._-]+/g, '')
    .toLowerCase();
}

function browserWindowExists(context = {}, value = '') {
  const expected = normalizeBrowserWindowName(value);
  const workflow = isObject(context.workflow) ? context.workflow : {};
  const sources = [
    context.browserWindows,
    context.browser_windows,
    workflow.browserWindows,
    workflow.browser_windows,
  ];
  const entries = [];

  for (const source of sources) {
    if (Array.isArray(source)) {
      entries.push(...source);
    } else if (isObject(source)) {
      entries.push(...Object.entries(source).map(([key, entry]) => ({
        key,
        ...(isObject(entry) ? entry : {}),
      })));
    }
  }

  return expected !== '' && entries.some((entry) => normalizeBrowserWindowName(entry) === expected);
}

function valuesEqual(actual, expected) {
  if (typeof actual === 'number' || typeof expected === 'number') {
    return Number(actual) === Number(expected);
  }

  if (typeof actual === 'boolean' || typeof expected === 'boolean') {
    return Boolean(actual) === Boolean(expected);
  }

  if ((Array.isArray(actual) || isObject(actual)) && (Array.isArray(expected) || isObject(expected))) {
    return JSON.stringify(actual) === JSON.stringify(expected);
  }

  return text(actual).toLowerCase() === text(expected).toLowerCase();
}

function conditionMatches(context, actual, operator, expected) {
  switch (operator) {
    case 'missing':
    case 'empty':
      return !hasValue(actual);
    case 'equals':
    case 'eq':
      return valuesEqual(actual, expected);
    case 'not_equals':
    case 'neq':
      return !valuesEqual(actual, expected);
    case 'contains':
      return Array.isArray(actual)
        ? actual.some((item) => valuesEqual(item, expected))
        : text(actual).toLowerCase().includes(text(expected).toLowerCase());
    case 'true':
    case 'truthy':
      return Boolean(actual);
    case 'false':
    case 'falsy':
      return !actual;
    case 'browser_window_open':
    case 'browser-window-open':
      return browserWindowExists(context, actual || expected);
    case 'exists':
    case 'not_empty':
    default:
      return hasValue(actual);
  }
}

async function run(context = {}) {
  const input = context.input || {};
  const path = text(
    input.variable_path
    || input.variablePath
    || input.source
    || input.value
    || input.inputValue
    || input.input_value,
  );
  const operator = text(input.operator || input.condition || 'exists').toLowerCase();
  const expected = parseExpected(input.compare_value ?? input.compareValue ?? input.expected ?? '');

  if (path === '') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Variablenpfad fuer die IF-Pruefung angegeben.',
    };
  }

  const actual = resolveVariable(context, path);
  const matched = conditionMatches(context, actual, operator, expected);
  const result = {
    ok: true,
    status: matched ? 'success' : 'condition_not_met',
    statusMessage: matched
      ? `IF-Bedingung fuer "${path}" ist erfuellt.`
      : `IF-Bedingung fuer "${path}" ist nicht erfuellt.`,
    variablePath: path,
    variable_path: path,
    operator,
    expected,
    actual: actual ?? null,
    conditionMatched: matched,
    condition_matched: matched,
    logicalOutcome: matched ? 'condition_true' : 'condition_false',
    logical_outcome: matched ? 'condition_true' : 'condition_false',
  };

  if (!matched) {
    result.branchOutcome = 'failed';
    result.branch_outcome = 'failed';
  }

  return result;
}

module.exports = {
  key: 'decision.variable',
  run,
  conditionMatches,
  resolveVariable,
};
