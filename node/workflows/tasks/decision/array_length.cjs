'use strict';

const {
  cleanName,
  number,
  resolveVariable,
  text,
} = require('../lib/collection.cjs');

function compare(actual, operator, expected) {
  switch (operator) {
    case '>': return actual > expected;
    case '<': return actual < expected;
    case '<=': return actual <= expected;
    case '=':
    case '==':
    case '===': return actual === expected;
    case '!=':
    case '!==': return actual !== expected;
    case '>=':
    default: return actual >= expected;
  }
}

async function run(context = {}) {
  const input = context.input || {};
  const arrayName = cleanName(input.array_name ?? input.arrayName, 'items');
  const operator = text(input.operator || '>=').toLowerCase();
  const compareValue = Math.floor(number(input.compare_value ?? input.compareValue, 1, 0));
  const value = resolveVariable(context, arrayName, []);
  const arrayLength = Array.isArray(value) ? value.length : 0;
  const conditionMet = compare(arrayLength, operator, compareValue);
  const target = text(conditionMet
    ? (input.success_target ?? input.successTarget)
    : (input.error_target ?? input.errorTarget));

  return {
    ok: true,
    status: conditionMet ? 'success' : 'condition_not_met',
    statusMessage: `Array "${arrayName}" hat ${arrayLength} Eintraege; Bedingung ${operator} ${compareValue} ist ${conditionMet ? 'erfuellt' : 'nicht erfuellt'}.`,
    array_name: arrayName,
    array_length: arrayLength,
    operator,
    compare_value: compareValue,
    condition_met: conditionMet,
    preview: Array.isArray(value) ? value.slice(0, 3) : [],
    ...(!conditionMet ? { branchOutcome: 'failed', branch_outcome: 'failed' } : {}),
    ...(target !== '' ? { route_target_key: target, routeTargetKey: target } : {}),
  };
}

module.exports = { key: 'decision.array_length', run, compare };
