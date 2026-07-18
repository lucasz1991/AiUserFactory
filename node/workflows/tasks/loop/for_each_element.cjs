'use strict';

const {
  appendWorkflowArray,
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

async function elementIdentity(handle, index) {
  if (!handle || typeof handle.evaluate !== 'function') return `index:${index}`;

  return handle.evaluate((element, fallbackIndex) => {
    const attributeNames = ['data-id', 'data-key', 'data-testid', 'id', 'href', 'name', 'aria-label'];
    const attributes = attributeNames
      .map((name) => `${name}=${element?.getAttribute?.(name) || ''}`)
      .filter((entry) => !entry.endsWith('='));
    const textValue = String(element?.innerText || element?.textContent || '')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 240);

    return attributes.length > 0 || textValue !== ''
      ? `${attributes.join('|')}|text=${textValue}`
      : `index:${fallbackIndex}`;
  }, index).catch(() => `index:${index}`);
}

async function selectedElements(page, state) {
  const queried = await queryElements(page, state.selector, state.onlyVisible);
  const elements = queried.elements.slice(
    state.offset,
    state.limit > 0 ? state.offset + state.limit : undefined,
  );
  const identities = await Promise.all(elements.map((handle, index) => elementIdentity(handle, index)));
  const occurrences = new Map();
  const identityKeys = identities.map((identity) => {
    const occurrence = occurrences.get(identity) || 0;
    occurrences.set(identity, occurrence + 1);
    return `${identity}#${occurrence}`;
  });

  return { queried, elements, identityKeys };
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
  const completionTarget = input.completion_target ?? input.completionTarget;
  const errorTarget = input.error_target ?? input.errorTarget;
  const loopEndKey = input.loop_end_key ?? input.loopEndKey;

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
    let currentSelection = null;

    if (!state) {
      const offset = Math.floor(number(input.offset, 0, 0, 10000));
      const limit = Math.floor(number(input.limit, 0, 0, 10000));
      state = {
        selector,
        offset,
        limit,
        onlyVisible: bool(input.only_visible ?? input.onlyVisible, true),
        cursor: 0,
        visited: 0,
        processed: 0,
        matchedCount: 0,
        selectedCount: 0,
        hiddenCount: 0,
        skipped: 0,
        collected: 0,
        active: false,
        complete: false,
      };
      currentSelection = await selectedElements(page, state);
      state.matchedCount = currentSelection.queried.matchedCount;
      state.selectedCount = currentSelection.elements.length;
      state.hiddenCount = currentSelection.queried.hiddenCount;
      state.skipped = state.hiddenCount + Math.min(offset, currentSelection.queried.elements.length);
      state.identityKeys = currentSelection.identityKeys;
      states[taskKey] = state;
    } else if (state.active) {
      const skipped = Boolean(skippedScopes[scopeName]);
      const collectToArray = text(input.collect_to_array ?? input.collectToArray);

      if (!skipped && collectToArray !== '') {
        const collection = appendWorkflowArray(context, {
          arrayName: collectToArray,
          valueFromVariable: input.collect_from_variable ?? input.collectFromVariable ?? scopeName,
          dedupeBy: input.collect_dedupe_by ?? input.collectDedupeBy,
          maxItems: input.collect_max_items ?? input.collectMaxItems,
        });

        if (!collection.ok) {
          return {
            ok: false,
            status: 'failed',
            statusMessage: `Loop konnte Daten nicht speichern: ${collection.message}`,
            selector_used: selector,
            current_index: state.cursor,
            array_name: collection.arrayName,
            reason_code: collection.reason,
            ...routeResult(errorTarget, 'error'),
          };
        }

        if (collection.appended) state.collected += 1;
      }

      state.visited += 1;
      if (skipped) {
        state.skipped += 1;
        delete skippedScopes[scopeName];
      } else {
        state.processed += 1;
      }
      state.cursor += 1;
      state.active = false;
    }

    if (state.cursor >= state.selectedCount) {
      state.complete = true;
      delete scopes[scopeName];
      setWorkflowVariable(context, indexName, null);
      const empty = state.selectedCount === 0;
      const exitTarget = empty
        ? (emptyTarget || completionTarget || loopEndKey)
        : (completionTarget || emptyTarget || loopEndKey);
      return {
        ok: true,
        status: empty ? 'loop_empty' : 'loop_complete',
        statusMessage: empty
          ? `Keine Elemente fuer Selector "${selector}" gefunden.`
          : `Element-Schleife abgeschlossen: ${state.processed} Treffer verarbeitet.`,
        selector_used: selector,
        matched_count: state.matchedCount,
        selected_count: state.selectedCount,
        hidden_count: state.hiddenCount,
        visited_count: state.visited,
        processed_count: state.processed,
        skipped_count: state.skipped,
        collected_count: state.collected,
        current_index: null,
        loop_complete: true,
        workflow_variables: context.workflow_variables,
        ...routeResult(exitTarget, empty ? 'empty' : 'complete'),
      };
    }

    currentSelection = currentSelection || await selectedElements(page, state);
    const currentIndex = state.cursor;
    const identityKey = state.identityKeys[currentIndex];
    const refreshedIndex = identityKey ? currentSelection.identityKeys.indexOf(identityKey) : -1;
    const currentElement = currentSelection.elements[refreshedIndex >= 0 ? refreshedIndex : currentIndex];

    if (!currentElement) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: `Treffer ${currentIndex + 1} fuer Selector "${selector}" ist nach einer DOM-Aenderung nicht mehr vorhanden.`,
        selector_used: selector,
        current_index: currentIndex,
        reason_code: 'loop_element_missing_after_refresh',
        ...routeResult(errorTarget, 'error'),
      };
    }

    scopes[scopeName] = currentElement;
    state.active = true;
    setWorkflowVariable(context, scopeName, {
      index: currentIndex,
      selector,
      identity: identityKey || null,
    });
    setWorkflowVariable(context, indexName, currentIndex);

    return {
      ok: true,
      status: 'success',
      statusMessage: `Treffer ${currentIndex + 1} von ${state.selectedCount} ist aktiv.`,
      selector_used: selector,
      matched_count: state.matchedCount,
      selected_count: state.selectedCount,
      visited_count: state.visited,
      processed_count: state.processed,
      skipped_count: state.skipped,
      collected_count: state.collected,
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
