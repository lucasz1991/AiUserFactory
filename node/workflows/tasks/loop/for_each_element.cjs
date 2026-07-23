'use strict';

const {
  cleanName,
  number,
  privateRegistry,
  resolveVariable,
  setWorkflowVariable,
  text,
} = require('../lib/collection.cjs');

function routeResult(target, outcome) {
  const normalized = text(target);

  return normalized === '' ? {} : {
    route_target_key: normalized,
    routeTargetKey: normalized,
    route_outcome: outcome,
    routeOutcome: outcome,
  };
}

function stateVariableName(taskKey) {
  return `__workflow_loop_state_${taskKey}`;
}

function persistState(context, taskKey, state) {
  setWorkflowVariable(context, stateVariableName(taskKey), { ...state });
}

function configuredIterationCount(input, hasSource) {
  const raw = input.iteration_count ?? input.iterationCount;

  if (raw === undefined || raw === null || text(raw) === '') {
    return hasSource ? 0 : 1;
  }

  return Math.floor(number(raw, hasSource ? 0 : 1, 0, 100000));
}

function truthy(value) {
  if (typeof value === 'string') {
    return !['', '0', 'false', 'no', 'nein', 'off', 'null', 'undefined'].includes(value.trim().toLowerCase());
  }

  return Boolean(value);
}

function parsedComparisonValue(value) {
  if (typeof value !== 'string') return value;

  const normalized = value.trim();
  if (normalized === '') return '';
  if (normalized === 'true') return true;
  if (normalized === 'false') return false;
  if (normalized === 'null') return null;
  if (/^-?(?:\d+\.?\d*|\.\d+)$/.test(normalized)) return Number(normalized);

  if ((normalized.startsWith('{') && normalized.endsWith('}'))
    || (normalized.startsWith('[') && normalized.endsWith(']'))) {
    try {
      return JSON.parse(normalized);
    } catch {
      return normalized;
    }
  }

  return normalized;
}

function equalValues(actual, expected) {
  if (typeof actual === 'number' && typeof expected === 'number') return actual === expected;
  if (typeof actual === 'boolean' || typeof expected === 'boolean') return truthy(actual) === truthy(expected);
  if ((actual && typeof actual === 'object') || (expected && typeof expected === 'object')) {
    try {
      return JSON.stringify(actual) === JSON.stringify(expected);
    } catch {
      return actual === expected;
    }
  }

  return String(actual ?? '') === String(expected ?? '');
}

function containsValue(actual, expected) {
  if (Array.isArray(actual)) return actual.some((entry) => equalValues(entry, expected));
  if (actual && typeof actual === 'object') return Object.prototype.hasOwnProperty.call(actual, String(expected));

  return String(actual ?? '').includes(String(expected ?? ''));
}

function evaluateCondition(context, input) {
  const variable = text(input.condition_variable ?? input.conditionVariable);
  const operator = text((input.condition_operator ?? input.conditionOperator) || 'truthy').toLowerCase();

  if (variable === '') {
    return { configured: false, met: true, variable: '', operator, actual: undefined, expected: undefined };
  }

  const missing = Symbol('missing');
  const actual = resolveVariable(context, variable, missing);
  const expected = parsedComparisonValue(input.condition_value ?? input.conditionValue ?? '');
  let met = false;

  if (actual !== missing) {
    switch (operator) {
      case 'falsy':
        met = !truthy(actual);
        break;
      case '=':
      case '==':
      case 'equals':
        met = equalValues(actual, expected);
        break;
      case '!=':
      case '<>':
      case 'not_equals':
        met = !equalValues(actual, expected);
        break;
      case '>':
      case 'greater_than':
        met = Number(actual) > Number(expected);
        break;
      case '>=':
      case 'greater_or_equal':
        met = Number(actual) >= Number(expected);
        break;
      case '<':
      case 'less_than':
        met = Number(actual) < Number(expected);
        break;
      case '<=':
      case 'less_or_equal':
        met = Number(actual) <= Number(expected);
        break;
      case 'contains':
        met = containsValue(actual, expected);
        break;
      case 'not_contains':
        met = !containsValue(actual, expected);
        break;
      case 'truthy':
      default:
        met = truthy(actual);
        break;
    }
  }

  return {
    configured: true,
    met,
    variable,
    operator,
    actual: actual === missing ? undefined : actual,
    expected,
    missing: actual === missing,
  };
}

function sourceArray(context, input) {
  const configured = input.source_array ?? input.sourceArray;

  if (Array.isArray(configured)) {
    return { configured: true, name: '', value: configured };
  }

  const name = text(configured);
  if (name === '') return { configured: false, name: '', value: null };

  return {
    configured: true,
    name,
    value: resolveVariable(context, name, undefined),
  };
}

async function run(context = {}) {
  const input = context.input || {};
  const taskKey = cleanName(input.key || input.task_key || input.taskKey, 'loop');
  const loopEndKey = input.loop_end_key ?? input.loopEndKey;
  const itemName = cleanName(input.store_current_item_as ?? input.storeCurrentItemAs, 'current_item');
  const indexName = cleanName(input.store_index_as ?? input.storeIndexAs, 'loop_index');
  const states = privateRegistry(context, '__workflowLoopStates');
  let state = states[taskKey];

  if (!state) {
    const persisted = resolveVariable(context, stateVariableName(taskKey), null);

    if (persisted && typeof persisted === 'object' && persisted.mode === 'control' && Number(persisted.version) === 2) {
      state = { ...persisted };
      states[taskKey] = state;
    }
  }

  if (!state) {
    state = {
      version: 2,
      mode: 'control',
      cursor: 0,
      processed: 0,
      active: false,
      complete: false,
      completionReason: null,
    };
    states[taskKey] = state;
  } else if (state.active) {
    state.cursor += 1;
    state.processed += 1;
    state.active = false;
  }

  const source = sourceArray(context, input);
  if (source.configured && !Array.isArray(source.value)) {
    persistState(context, taskKey, state);

    return {
      ok: false,
      status: 'failed',
      statusMessage: source.value === undefined
        ? `Die Loop-Quellvariable "${source.name}" wurde nicht gefunden.`
        : `Die Loop-Quellvariable "${source.name}" ist kein Array.`,
      reason_code: source.value === undefined ? 'loop_source_missing' : 'loop_source_not_array',
      source_array: source.name,
    };
  }

  const configuredCount = configuredIterationCount(input, source.configured);
  const total = source.configured
    ? (configuredCount > 0 ? Math.min(configuredCount, source.value.length) : source.value.length)
    : configuredCount;
  const condition = evaluateCondition(context, input);
  const completedByCount = state.cursor >= total;
  const completedByCondition = !condition.met;

  state.total = total;
  state.sourceArray = source.name;

  if (state.complete || completedByCount || completedByCondition) {
    state.complete = true;
    state.active = false;
    state.completionReason = state.completionReason
      || (completedByCondition ? 'condition_false' : 'iteration_count_reached');
    setWorkflowVariable(context, itemName, null);
    setWorkflowVariable(context, indexName, null);
    persistState(context, taskKey, state);

    if (text(loopEndKey) === '') {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Loop-Start hat kein gekoppeltes Loop-Ende.',
        reason_code: 'loop_end_missing',
        loop_complete: true,
        current_index: null,
        completed_iterations: state.processed,
        iteration_count: total,
        workflow_variables: context.workflow_variables,
      };
    }

    return {
      ok: true,
      status: 'loop_complete',
      statusMessage: completedByCondition
        ? `Loop nach ${state.processed} Durchlaeufen durch die Bedingung beendet.`
        : `Loop nach ${state.processed} von ${total} Durchlaeufen abgeschlossen.`,
      loop_complete: true,
      completion_reason: state.completionReason,
      current_index: null,
      completed_iterations: state.processed,
      processed_count: state.processed,
      iteration_count: total,
      condition_met: condition.met,
      condition_variable: condition.variable,
      workflow_variables: context.workflow_variables,
      ...routeResult(loopEndKey, state.completionReason),
    };
  }

  const currentItem = source.configured ? source.value[state.cursor] : null;
  setWorkflowVariable(context, itemName, currentItem);
  setWorkflowVariable(context, indexName, state.cursor);
  state.active = true;
  persistState(context, taskKey, state);

  return {
    ok: true,
    status: 'success',
    statusMessage: `Loop-Durchlauf ${state.cursor + 1} von ${total} gestartet.`,
    loop_complete: false,
    current_index: state.cursor,
    current_iteration: state.cursor + 1,
    completed_iterations: state.processed,
    processed_count: state.processed,
    iteration_count: total,
    current_item_variable: itemName,
    index_variable: indexName,
    source_array: source.name,
    condition_met: condition.met,
    condition_variable: condition.variable,
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'loop.for_each_element', run };
