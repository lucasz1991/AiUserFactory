'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const validateInputs = require('./data/validate_inputs.cjs');
const variableCondition = require('./decision/variable.cjs');
const mailActionLoop = require('./mail/list_action_loop.cjs');

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

test('mail action loop accepts an ordered JSON click sequence with trailing comma', () => {
  const steps = mailActionLoop.parseActionSteps(`[
    {"selector":"button.delete","wait_ms":200},
    {"selector":"button.confirm","wait_ms":300},
  ]`);

  assert.equal(steps.length, 2);
  assert.equal(steps[0].selector, 'button.delete');
  assert.equal(steps[1].selector, 'button.confirm');
});
