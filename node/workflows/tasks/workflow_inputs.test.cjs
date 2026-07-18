'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const validateInputs = require('./data/validate_inputs.cjs');
const variableCondition = require('./decision/variable.cjs');
const mailActionLoop = require('./mail/list_action_loop.cjs');
const fillField = require('./input/fill_field.cjs');

test('workflow input definitions accept trailing comma and create mail scan overrides', async () => {
  const definitions = `[
    {"name":"browser_window","type":"browser_window","required":false},
    {"name":"Mail-Inbox-Liste-Scan.subject_filter","required":false},
    {"name":"Mail-Inbox-Liste-Scan.titel_filter","required":false},
    {"name":"Mail-Inbox-Liste-Scan.max_age_minutes","type":"number","required":false},
    {"name":"Mail-Inbox-Liste-Scan.mail_ids","required":false},
  ]`;
  const result = await validateInputs.run({
    input: { input_definitions: definitions },
    browserWindows: [{ key: 'main' }],
    workflow_variables: {
      browser_window: 'main',
      'Mail-Inbox-Liste-Scan.subject_filter': ['Invoice'],
      'Mail-Inbox-Liste-Scan.titel_filter': ['Unread'],
      'Mail-Inbox-Liste-Scan.max_age_minutes': 30,
      'Mail-Inbox-Liste-Scan.mail_ids': ['mail-1'],
    },
  });
  const scanOverride = result.task_overrides.find((override) => override.match === 'Mail-Inbox-Liste-Scan');

  assert.equal(result.ok, true);
  assert.equal(result.checked_inputs.length, 5);
  assert.deepEqual(scanOverride.values, {
    subject_filter: ['Invoice'],
    title_filter: ['Unread'],
    max_age_minutes: 30,
    mail_ids: ['mail-1'],
  });
});

test('IF variable task checks validated inputs and open browser windows', async () => {
  const context = {
    input: {
      variable_path: 'browser_window',
      operator: 'browser_window_open',
    },
    workflow_variables: { browser_window: 'main' },
    browserWindows: [{ key: 'main' }],
  };
  const success = await variableCondition.run(context);
  const missing = await variableCondition.run({
    input: { variable_path: 'subject_filter', operator: 'exists' },
    workflow_variables: {},
  });

  assert.equal(success.conditionMatched, true);
  assert.equal(success.status, 'success');
  assert.equal(missing.conditionMatched, false);
  assert.equal(missing.branchOutcome, 'failed');
});

test('IF variable task routes supplied and missing workflow inputs on different outcomes', async () => {
  const supplied = await variableCondition.run({
    input: { variable_path: 'google_search_url', operator: 'exists' },
    workflow_variables: { google_search_url: 'https://www.google.com/search?q=workflow' },
  });
  const missing = await variableCondition.run({
    input: { variable_path: 'google_search_url', operator: 'exists' },
    workflow_variables: { search_count: 3 },
  });

  assert.equal(supplied.conditionMatched, true);
  assert.equal(supplied.status, 'success');
  assert.equal(supplied.actual, 'https://www.google.com/search?q=workflow');
  assert.equal(missing.conditionMatched, false);
  assert.equal(missing.status, 'condition_not_met');
  assert.equal(missing.branchOutcome, 'failed');
});

test('declared optional workflow inputs remain known when their value is missing', async () => {
  const result = await validateInputs.run({
    input: {
      input_definitions: '[{"name":"google_search_url","required":false},{"name":"search_count","required":false,"default":3}]',
    },
    workflow_variables: {},
  });

  assert.equal(result.ok, true);
  assert.equal(Object.prototype.hasOwnProperty.call(result.workflow_variables, 'google_search_url'), true);
  assert.equal(result.workflow_variables.google_search_url, null);
  assert.equal(result.workflow_variables.search_count, 3);
  assert.equal(result.branchOutcome, undefined);
  assert.equal(result.workflow_variables.workflow_inputs.google_search_url, null);
  assert.equal(result.workflow_variables.workflow_inputs.search_count, 3);
  assert.deepEqual(result.workflow_variables.workflow_inputs._inputs, [
    {
      name: 'google_search_url',
      source: 'google_search_url',
      type: 'string',
      required: false,
      set: false,
      present: false,
      used_default: false,
      browser_window_open: null,
      value: null,
    },
    {
      name: 'search_count',
      source: 'search_count',
      type: 'string',
      required: false,
      set: false,
      present: true,
      used_default: true,
      browser_window_open: null,
      value: 3,
    },
  ]);
  assert.deepEqual(result.workflow_variables.workflow_inputs._summary, {
    valid: true,
    has_required_inputs: false,
    required_count: 0,
    missing_required_count: 0,
  });
});

test('input validation only follows the failure branch for missing required values', async () => {
  const optionalOnly = await validateInputs.run({
    input: {
      input_definitions: [
        { name: 'browser_window', type: 'browser_window', required: false },
        { name: 'search_pages', required: false },
      ],
      output_group: 'search_inputs',
    },
    workflow_variables: { search_pages: [] },
    browserWindows: [],
  });
  const requiredMissing = await validateInputs.run({
    input: {
      input_definitions: [
        { name: 'search_pages', required: false },
        { name: 'google_search_url', required: true },
      ],
      output_group: 'search_inputs',
    },
    workflow_variables: {},
  });

  assert.equal(optionalOnly.status, 'success');
  assert.equal(optionalOnly.branchOutcome, undefined);
  assert.equal(optionalOnly.workflow_variables.search_inputs._inputs[0].set, false);
  assert.equal(optionalOnly.workflow_variables.search_inputs._inputs[1].set, true);
  assert.equal(optionalOnly.workflow_variables.search_inputs._inputs[1].present, false);
  assert.equal(requiredMissing.status, 'missing_required');
  assert.equal(requiredMissing.branchOutcome, 'failed');
  assert.deepEqual(requiredMissing.missing_inputs.map((item) => item.name), ['google_search_url']);
});

test('input validation with no definitions succeeds because no required input is missing', async () => {
  const result = await validateInputs.run({
    input: { input_definitions: [], output_group: 'workflow_inputs' },
    workflow_variables: {},
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.branchOutcome, undefined);
  assert.deepEqual(result.workflow_variables.workflow_inputs._inputs, []);
  assert.equal(result.workflow_variables.workflow_inputs._summary.valid, true);
});

test('mail action loop accepts an ordered JSON click sequence with trailing comma', () => {
  const steps = mailActionLoop.parseActionSteps(`[
    {"selector":"button.delete","wait_ms":200},
    {"selector":"button.confirm","wait_ms":300},
  ]`);

  assert.equal(steps.length, 2);
  assert.equal(steps[0].selector, 'button.delete');
  assert.equal(steps[1].selector, 'button.confirm');
});

test('input fill reports a missing configured workflow variable without typing its name', async () => {
  const result = await fillField.run({
    page: {},
    input: {
      value: '',
      value_source: 'workflow_variable',
      workflow_variable: 'google_search_url',
      value_resolution_status: 'missing_workflow_variable',
    },
  });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.workflowVariable, 'google_search_url');
  assert.match(result.statusMessage, /kein Fallback-Wert/);
});
