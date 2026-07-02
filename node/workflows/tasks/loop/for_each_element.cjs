'use strict';

const {
  bool,
  cleanName,
  number,
  privateRegistry,
  queryElements,
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

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const selector = text(input.selector);
  const scopeName = cleanName(input.store_current_element_as ?? input.storeCurrentElementAs, 'current_element');
  const indexName = cleanName(input.store_index_as ?? input.storeIndexAs, 'element_index');
  const taskKey = cleanName(input.key || input.task_key || `${selector}:${scopeName}`, 'loop');
  const successTarget = input.success_target ?? input.successTarget;
  const emptyTarget = input.empty_target ?? input.emptyTarget;
  const errorTarget = input.error_target ?? input.errorTarget;

  if (!page || typeof page.$$ !== 'function') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Page-Handle fuer loop.for_each_element vorhanden.',
      ...routeResult(errorTarget, 'error'),
    };
  }
  if (selector === '') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Treffer-Selector fuer loop.for_each_element angegeben.',
      ...routeResult(errorTarget, 'error'),
    };
  }

  try {
    const states = privateRegistry(context, '__workflowLoopStates');
    const scopes = privateRegistry(context, '__workflowElementScopes');
    const skippedScopes = privateRegistry(context, '__workflowLoopSkippedScopes');
    let state = states[taskKey];

    if (!state) {
      const queried = await queryElements(page, selector, bool(input.only_visible ?? input.onlyVisible, true));
      const offset = Math.floor(number(input.offset, 0, 0));
      const limit = Math.floor(number(input.limit, 0, 0));
      const selected = queried.elements.slice(offset, limit > 0 ? offset + limit : undefined);
      state = {
        selector,
        elements: selected,
        cursor: 0,
        processed: 0,
        matchedCount: queried.matchedCount,
        skipped: queried.hiddenCount + Math.min(offset, queried.elements.length),
        active: false,
        complete: false,
      };
      states[taskKey] = state;
    } else if (state.active) {
      state.processed += 1;
      if (skippedScopes[scopeName]) {
        state.skipped += 1;
        delete skippedScopes[scopeName];
      }
      state.cursor += 1;
      state.active = false;
    }

    if (state.cursor >= state.elements.length) {
      state.complete = true;
      delete scopes[scopeName];
      setWorkflowVariable(context, indexName, null);
      return {
        ok: true,
        status: 'loop_complete',
        statusMessage: state.matchedCount === 0
          ? `Keine Elemente fuer Selector "${selector}" gefunden.`
          : `Element-Schleife abgeschlossen: ${state.processed} Treffer verarbeitet.`,
        selector_used: selector,
        matched_count: state.matchedCount,
        processed_count: state.processed,
        skipped_count: state.skipped,
        current_index: null,
        loop_complete: true,
        ...routeResult(emptyTarget, 'empty'),
      };
    }

    const currentIndex = state.cursor;
    scopes[scopeName] = state.elements[currentIndex];
    state.active = true;
    setWorkflowVariable(context, scopeName, { index: currentIndex, selector });
    setWorkflowVariable(context, indexName, currentIndex);

    return {
      ok: true,
      status: 'success',
      statusMessage: `Treffer ${currentIndex + 1} von ${state.elements.length} ist aktiv.`,
      selector_used: selector,
      matched_count: state.matchedCount,
      processed_count: state.processed,
      skipped_count: state.skipped,
      current_index: currentIndex,
      scope_variable: scopeName,
      loop_complete: false,
      workflow_variables: context.workflow_variables,
      ...routeResult(successTarget, 'success'),
    };
  } catch (error) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: `Element-Schleife fehlgeschlagen: ${error.message}`,
      selector_used: selector,
      ...routeResult(errorTarget, 'error'),
    };
  }
}

module.exports = { key: 'loop.for_each_element', run };
