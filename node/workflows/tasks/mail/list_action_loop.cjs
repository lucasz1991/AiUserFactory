'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const {
  clickMailCandidate,
  normalizeText,
  optionBoolean,
  optionNumber,
  optionString,
  setWorkflowVariable,
  taskOptions,
  valueFromPath,
  variableName,
  wait,
  workflowVariableRoot,
} = require('../lib/mail_list.cjs');
const {
  clickFirstVisibleElement,
  elementCandidatesFromInput,
} = require('../lib/find_visible_element.cjs');

function arrayFromContext(context = {}, name = '') {
  const root = workflowVariableRoot(context);

  for (const path of [
    name,
    `workflow_variables.${name}`,
    `workflowVariables.${name}`,
    `lastResult.workflow_variables.${name}`,
    `lastResult.workflowVariables.${name}`,
  ]) {
    const value = valueFromPath(root, path);

    if (Array.isArray(value)) {
      return value;
    }
  }

  return [];
}

function parseActionSteps(value) {
  if (Array.isArray(value)) {
    return value;
  }

  const raw = normalizeText(value);

  if (raw === '') {
    return [];
  }

  try {
    const parsed = JSON.parse(raw.replace(/,\s*([}\]])/g, '$1'));

    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return raw
      .split(/\r?\n|;/)
      .map((selector) => ({ selector: normalizeText(selector) }))
      .filter((step) => step.selector);
  }
}

function normalizedActionSteps(input = {}, options = {}) {
  const configured = parseActionSteps(
    options.action_steps
    || options.actionSteps
    || input.action_steps
    || input.actionSteps
    || '',
  );

  if (configured.length > 0) {
    return configured
      .map((step) => (typeof step === 'string' ? { selector: step } : step))
      .filter((step) => step && normalizeText(step.selector || step.text || step.value));
  }

  const steps = [];
  const actionSelector = optionString(options, input, [
    'action_selector',
    'actionSelector',
    'delete_selector',
    'deleteSelector',
  ], input.selector || input.elementSelector || input.element_selector || '');
  const confirmSelector = optionString(options, input, ['confirm_selector', 'confirmSelector'], '');

  if (actionSelector) {
    steps.push({ selector: actionSelector, label: 'Aktion' });
  }

  if (confirmSelector) {
    steps.push({ selector: confirmSelector, label: 'Bestaetigung' });
  }

  return steps;
}

async function clickConfiguredTarget(page, selector, timeoutMs, context) {
  const candidates = elementCandidatesFromInput({ selector }, {
    textKeys: ['text', 'value', 'label'],
  });

  if (candidates.length === 0) {
    return null;
  }

  return clickFirstVisibleElement(page, candidates, timeoutMs, { context });
}

async function run(context = {}) {
  let page = context.page;
  const input = context.input || {};
  const options = taskOptions(input);
  const inputArrayName = variableName(optionString(options, input, ['input_array_name', 'inputArrayName'], 'inbox_mails'), 'inbox_mails');
  const outputArrayName = variableName(optionString(options, input, ['output_array_name', 'outputArrayName'], 'mail_action_results'), 'mail_action_results');
  const openSelector = optionString(options, input, ['open_selector', 'openSelector', 'mail_open_selector', 'mailOpenSelector'], '');
  const returnSelector = optionString(options, input, ['return_selector', 'returnSelector', 'back_selector', 'backSelector'], '');
  const maxItems = Math.max(1, Math.min(200, optionNumber(options, input, ['max_items', 'maxItems', 'limit'], 50)));
  const openWaitMs = Math.max(0, Math.min(30000, optionNumber(options, input, ['open_wait_ms', 'openWaitMs'], 700)));
  const actionWaitMs = Math.max(0, Math.min(30000, optionNumber(options, input, ['action_wait_ms', 'actionWaitMs'], 500)));
  const timeoutMs = Math.max(250, Math.min(120000, optionNumber(options, input, ['action_timeout_ms', 'actionTimeoutMs'], 10000)));
  const continueOnError = optionBoolean(options, input, ['continue_on_error', 'continueOnError'], true);
  const failOnItemError = optionBoolean(options, input, ['fail_on_item_error', 'failOnItemError'], true);
  const sourceMails = arrayFromContext(context, inputArrayName).slice(0, maxItems);
  const actionSteps = normalizedActionSteps(input, options);
  const results = [];

  if (!page || (typeof page.frames !== 'function' && typeof page.evaluate !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer die Mail-Aktionsschleife vorhanden.' };
  }

  if (sourceMails.length === 0) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: `Keine Mail-Liste unter "${inputArrayName}" gefunden.`,
      inputArrayName,
      input_array_name: inputArrayName,
    }, true);
  }

  if (actionSteps.length === 0) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Aktions-Selector fuer die Mail-Aktionsschleife konfiguriert.',
    }, true);
  }

  startTaskPreview(context);

  for (const [index, candidate] of sourceMails.entries()) {
    const result = {
      index,
      mailId: candidate.mailId || candidate.mail_id || null,
      subject: candidate.subject || '',
      opened: false,
      actions: [],
      success: false,
    };

    try {
      const stableCandidate = { ...candidate };

      delete stableCandidate.selectorIndex;

      if (openSelector) {
        stableCandidate.selector = openSelector;
      }

      result.opened = await clickMailCandidate(page, stableCandidate, {
        ...options,
        list_item_selector: openSelector || candidate.selector,
      });

      if (!result.opened) {
        throw new Error('Mail-Listeneintrag konnte nicht geoeffnet werden.');
      }

      if (openWaitMs > 0) {
        await wait(openWaitMs);
      }

      if (typeof context.refreshActivePage === 'function') {
        page = await context.refreshActivePage().catch(() => page) || page;
      }

      for (const [stepIndex, step] of actionSteps.entries()) {
        const selector = normalizeText(step.selector || step.text || step.value);
        const stepTimeoutMs = Math.max(250, Number(step.timeout_ms || step.timeoutMs || timeoutMs));
        const clicked = await clickConfiguredTarget(page, selector, stepTimeoutMs, context);

        result.actions.push({
          index: stepIndex,
          label: normalizeText(step.label || `Aktion ${stepIndex + 1}`),
          selector,
          clicked: Boolean(clicked),
          matchedBy: clicked?.matchedBy || null,
        });

        if (!clicked && step.required !== false) {
          throw new Error(`Aktions-Selector nicht gefunden: ${selector}`);
        }

        const stepWaitMs = Math.max(0, Number(step.wait_ms || step.waitMs || actionWaitMs));

        if (stepWaitMs > 0) {
          await wait(stepWaitMs);
        }

        if (typeof context.refreshActivePage === 'function') {
          page = await context.refreshActivePage().catch(() => page) || page;
        }
      }

      if (returnSelector) {
        const returned = await clickConfiguredTarget(page, returnSelector, timeoutMs, context);

        result.returnedToList = Boolean(returned);

        if (!returned) {
          throw new Error(`Rueckkehr-Selector nicht gefunden: ${returnSelector}`);
        }

        if (actionWaitMs > 0) {
          await wait(actionWaitMs);
        }
      }

      result.success = true;
    } catch (error) {
      result.error = error.message;

      if (!continueOnError) {
        results.push(result);
        break;
      }
    }

    results.push(result);
  }

  const successful = results.filter((result) => result.success).length;
  const failed = results.length - successful;

  setWorkflowVariable(context, outputArrayName, results);

  return captureTaskPreview(context, {
    ok: !failOnItemError || failed === 0,
    status: failed === 0 ? 'success' : (successful > 0 ? 'partial' : 'failed'),
    statusMessage: `${successful} von ${results.length} Mail-Listeneintraegen wurden erfolgreich verarbeitet.`,
    inputArrayName,
    input_array_name: inputArrayName,
    outputArrayName,
    output_array_name: outputArrayName,
    sourceCount: sourceMails.length,
    source_count: sourceMails.length,
    successfulCount: successful,
    successful_count: successful,
    failedCount: failed,
    failed_count: failed,
    results,
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = {
  key: 'mail.list_action_loop',
  run,
  parseActionSteps,
};
