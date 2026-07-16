'use strict';

async function run(context = {}) {
  const input = context.input || {};

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Task-Eingabe erfasst.',
    capturedInput: {
      value: input.value,
      inputValue: input.inputValue,
      valueSource: input.valueSource,
      workflowVariable: input.workflowVariable,
      valueResolutionStatus: input.valueResolutionStatus,
      valueFallbackUsed: input.valueFallbackUsed,
    },
  };
}

module.exports = { key: 'test.capture_input', run };
