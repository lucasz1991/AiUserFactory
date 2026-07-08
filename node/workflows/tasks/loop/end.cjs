'use strict';

const { cleanName, privateRegistry, text } = require('../lib/collection.cjs');

function routeResult(target, outcome) {
  const normalized = text(target);

  return normalized === '' ? {} : {
    route_target_key: normalized,
    routeTargetKey: normalized,
    route_outcome: outcome,
    routeOutcome: outcome,
  };
}

async function run(context = {}) {
  const input = context.input || {};
  const loopStartKey = cleanName(
    input.loop_start_key
    ?? input.loopStartKey
    ?? input.start_key
    ?? input.startKey
    ?? '',
    '',
  );

  if (loopStartKey === '') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Loop-Ende hat keine Startkarte.',
    };
  }

  const states = privateRegistry(context, '__workflowLoopStates');
  const state = states[loopStartKey] || null;

  if (!state || state.complete || !state.active) {
    return {
      ok: true,
      status: 'loop_complete',
      statusMessage: 'Loop-Ende ist erreicht; die Schleife ist abgeschlossen.',
      loop_complete: true,
      loop_start_key: loopStartKey,
    };
  }

  return {
    ok: true,
    status: 'loop_continue',
    statusMessage: 'Loop-Ende springt zur Startkarte zurueck.',
    loop_complete: false,
    loop_start_key: loopStartKey,
    ...routeResult(loopStartKey, 'loop'),
  };
}

module.exports = { key: 'loop.end', run };
