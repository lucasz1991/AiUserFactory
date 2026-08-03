'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const basePath = path.resolve(__dirname, '..', '..');
const runnerPath = path.join(__dirname, 'run_step.cjs');
const returnScript = 'node/workflows/tasks/data/workflow_return.cjs';
const waitScript = 'node/workflows/tasks/wait/seconds.cjs';
const closeBrowserScript = 'node/workflows/tasks/browser/close.cjs';
const branchScript = 'tests/Fixtures/Workflows/branch_result.cjs';
const captureInputScript = 'tests/Fixtures/Workflows/capture_input.cjs';

function returnTask(key, value, frameKey = null) {
  return {
    key,
    task_key: 'data.workflow_return',
    title: key,
    kind: 'data',
    runner: 'node',
    node_script: returnScript,
    value,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
  };
}

function waitTask(key, frameKey = null, extra = {}) {
  return {
    key,
    task_key: 'wait.seconds',
    title: key,
    kind: 'wait',
    runner: 'node',
    node_script: waitScript,
    value: 0,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
    ...extra,
  };
}

function branchTask(key, onError, frameKey = null, extra = {}) {
  return {
    key,
    task_key: 'test.branch_result',
    title: key,
    kind: 'data',
    runner: 'node',
    node_script: branchScript,
    on_error: onError,
    ...(frameKey ? { embedded_workflow_frame_key: frameKey } : {}),
    ...extra,
  };
}

function captureInputTask(key, extra = {}) {
  return {
    key,
    task_key: 'test.capture_input',
    title: key,
    kind: 'data',
    runner: 'node',
    node_script: captureInputScript,
    ...extra,
  };
}

function executeEmbeddedWorkflow(workflowReturn, workflow = {}) {
  return executeTasks([
    returnTask('embedded-return', workflowReturn, 'embedded-frame'),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
      next: {
        type: 'card',
        card_key: 'success-target',
      },
    },
    returnTask('must-be-skipped', true),
    returnTask('success-target', true),
  ], workflow);
}

function executeTasks(tasks, workflow = {}, includeStatus = false) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-boundary-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  const runtime = {
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    keepWorkflowBrowserAlive: false,
    // Test-Fixtures liegen ausserhalb von node/workflows – explizit als
    // zusaetzliche, vertrauenswuerdige Skript-Wurzel freigeben (Produktion nie).
    additionalTaskScriptRoots: ['tests/Fixtures/Workflows'],
    workflow,
    tasks,
  };

  fs.writeFileSync(runtimePath, JSON.stringify(runtime));

  const processResult = spawnSync(process.execPath, [runnerPath, runtimePath], {
    cwd: basePath,
    encoding: 'utf8',
    timeout: 15000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);

    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));

    if (includeStatus) {
      return {
        result,
        status: JSON.parse(fs.readFileSync(statusPath, 'utf8')),
      };
    }

    return result;
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}

test('embedded workflow true return follows the workflow task success route', () => {
  const result = executeEmbeddedWorkflow(true);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'embedded-return',
    'embedded-boundary',
    'success-target',
  ]);
});

test('workflow runner keeps Chromium sandbox enabled by default', () => {
  const result = executeTasks([returnTask('sandbox-default', true)]);

  assert.equal(result.runnerDiagnostics.chromiumNoSandboxFlag, false);
  assert.equal(typeof result.browserIdentity.runnerProcessId, 'number');
  assert.equal(result.browserIdentity.connectedToExistingBrowser, false);
});

test('browser close without a persisted browser does not launch a replacement', () => {
  const result = executeTasks([{
    key: 'close-missing-browser',
    task_key: 'browser.close',
    title: 'Close missing browser',
    kind: 'browser',
    runner: 'node',
    node_script: closeBrowserScript,
    browser_window: 'main',
  }]);

  assert.equal(result.ok, true);
  assert.equal(result.tasks[0].statusMessage, 'Kein Browser-Handle zum Schliessen vorhanden.');
  assert.equal(result.browserWsEndpoint, '');
});

test('explicit task value sources resolve variables, fallbacks and fixed values deterministically', () => {
  const workflow = {
    workflow_variables: {
      google_search_url: 'https://www.google.com/search?q=workflow',
    },
  };
  const variableResult = executeTasks([captureInputTask('variable-value', {
    value_source: 'workflow_variable',
    workflow_variable: 'google_search_url',
    value_fallback: 'fallback-query',
  })], workflow);
  const fallbackResult = executeTasks([captureInputTask('fallback-value', {
    value_source: 'workflow_variable',
    workflow_variable: 'missing_query',
    value_fallback: 'fallback-query',
  })], workflow);
  const missingResult = executeTasks([captureInputTask('missing-value', {
    value_source: 'workflow_variable',
    workflow_variable: 'missing_query',
  })], workflow);
  const fixedResult = executeTasks([captureInputTask('fixed-value', {
    value_source: 'fixed',
    value: 'google_search_url',
  })], workflow);
  const legacyDeclaredMissingResult = executeTasks([captureInputTask('legacy-declared-missing', {
    value: 'google_search_url',
  })], {
    workflow_variables: {
      google_search_url: null,
    },
  });

  assert.deepEqual(variableResult.tasks[0].capturedInput, {
    value: 'https://www.google.com/search?q=workflow',
    inputValue: 'https://www.google.com/search?q=workflow',
    valueSource: 'workflow_variable',
    workflowVariable: 'google_search_url',
    valueResolutionStatus: 'variable_resolved',
    valueFallbackUsed: false,
  });
  assert.equal(fallbackResult.tasks[0].capturedInput.value, 'fallback-query');
  assert.equal(fallbackResult.tasks[0].capturedInput.valueResolutionStatus, 'fallback_used');
  assert.equal(fallbackResult.tasks[0].capturedInput.valueFallbackUsed, true);
  assert.equal(missingResult.tasks[0].capturedInput.value, '');
  assert.equal(missingResult.tasks[0].capturedInput.valueResolutionStatus, 'missing_workflow_variable');
  // Ein fester Wert, der zufaellig wie ein Variablenname aussieht, bleibt
  // woertlich – er wird NICHT ueber die gleichnamige Workflow-Variable aufgeloest.
  assert.equal(fixedResult.tasks[0].capturedInput.value, 'google_search_url');
  assert.equal(fixedResult.tasks[0].capturedInput.valueSource, 'fixed');
  assert.equal(legacyDeclaredMissingResult.tasks[0].capturedInput.value, '');
  assert.equal(legacyDeclaredMissingResult.tasks[0].capturedInput.valueSource, 'legacy_auto');
});

test('fixed person login paths resolve from context while missing paths and literals stay deterministic', () => {
  const personWorkflow = {
    workflow_variables: {
      'person.loginUsername': 'shadowed-by-workflow-variable',
      google_search_url: 'https://example.test/search',
    },
    person: {
      email: 'bezug@example.test',
      loginUsername: 'instagram-user',
      loginPassword: 'instagram-secret',
      emailAccount: { email: 'bezug@example.test', password: 'bezug-secret' },
    },
  };

  const personEmail = executeTasks([captureInputTask('fixed-person-email', {
    value_source: 'fixed',
    value: 'person.email',
  })], personWorkflow);
  const personPassword = executeTasks([captureInputTask('fixed-person-password', {
    value_source: 'fixed',
    value: 'person.password',
  })], personWorkflow);
  const loginUsername = executeTasks([captureInputTask('fixed-login-username', {
    value_source: 'fixed',
    value: 'person.loginUsername',
  })], personWorkflow);
  const loginPassword = executeTasks([captureInputTask('fixed-login-password', {
    value_source: 'fixed',
    value: 'person.loginPassword',
  })], personWorkflow);
  const missingSocialMediaPath = executeTasks([captureInputTask('fixed-missing-social-media-path', {
    value_source: 'fixed',
    value: 'person.socialmedia.instagram.username',
  })], personWorkflow);
  const legacyPlainText = executeTasks([captureInputTask('legacy-fixed-plain', {
    value_source: 'fixed',
    value: 'Claude AI',
  })], personWorkflow);
  const explicitLiteral = executeTasks([captureInputTask('explicit-literal', {
    value_source: 'literal',
    value: 'google_search_url',
  })], personWorkflow);

  // Mailkonto und Instagram-Zugang sind getrennte Datenbereiche.
  assert.equal(personEmail.tasks[0].capturedInput.value, 'bezug@example.test');
  assert.equal(personEmail.tasks[0].capturedInput.valueSource, 'fixed');
  assert.equal(personPassword.tasks[0].capturedInput.value, 'bezug-secret');
  assert.equal(loginUsername.tasks[0].capturedInput.value, 'instagram-user');
  assert.equal(loginPassword.tasks[0].capturedInput.value, 'instagram-secret');
  // Ein gleichnamiger Workflow-Variablen-Key darf den festen Personenpfad
  // nicht ueberschreiben.
  assert.notEqual(loginUsername.tasks[0].capturedInput.value, 'shadowed-by-workflow-variable');
  assert.equal(missingSocialMediaPath.tasks[0].capturedInput.value, '');
  assert.equal(missingSocialMediaPath.tasks[0].capturedInput.valueResolutionStatus, 'missing_context_value');
  // Bestehende fixed-Literale bleiben kompatibel; die neue explizite Quelle
  // loest auch gleichnamige Workflow-Variablen bewusst nicht auf.
  assert.equal(legacyPlainText.tasks[0].capturedInput.value, 'Claude AI');
  assert.equal(explicitLiteral.tasks[0].capturedInput.value, 'google_search_url');
  assert.equal(explicitLiteral.tasks[0].capturedInput.valueSource, 'literal');
  assert.equal(explicitLiteral.tasks[0].capturedInput.valueResolutionStatus, 'literal');
});

test('public workflow status redacts the Instagram login password', () => {
  const loginPassword = 'instagram-secret-must-not-be-public';
  const run = executeTasks([returnTask('public-redaction', true)], {
    person: {
      loginUsername: 'instagram-user',
      loginPassword,
      hasLoginPassword: true,
    },
  }, true);
  const publicPerson = run.status.workflow.person;

  assert.equal(publicPerson.loginUsername, 'instagram-user');
  assert.equal(publicPerson.hasLoginPassword, true);
  assert.equal(Object.prototype.hasOwnProperty.call(publicPerson, 'loginPassword'), false);
  assert.equal(JSON.stringify(run.status.workflow).includes(loginPassword), false);
});

test('person.accounts paths resolve per account type and their passwords stay out of the public status', () => {
  const mailPassword = 'mail-secret-must-not-be-public';
  const instagramPassword = 'instagram-secret-must-not-be-public';
  const accountsWorkflow = {
    person: {
      accounts: {
        email: {
          username: 'nora.brandt',
          address: 'nora.brandt@proton.me',
          password: mailPassword,
          url: 'https://mail.proton.me',
          hasPassword: true,
        },
        instagram: {
          username: 'nora_brandt',
          address: 'https://www.instagram.com/nora_brandt/',
          password: instagramPassword,
          hasPassword: true,
        },
      },
    },
  };

  const mailUsername = executeTasks([captureInputTask('accounts-mail-username', {
    value_source: 'fixed',
    value: 'person.accounts.email.username',
  })], accountsWorkflow);
  const mailAddress = executeTasks([captureInputTask('accounts-mail-address', {
    value_source: 'fixed',
    value: 'person.accounts.email.address',
  })], accountsWorkflow);
  const mailSecret = executeTasks([captureInputTask('accounts-mail-password', {
    value_source: 'fixed',
    value: 'person.accounts.email.password',
  })], accountsWorkflow);
  const instagramUsername = executeTasks([captureInputTask('accounts-instagram-username', {
    value_source: 'fixed',
    value: 'person.accounts.instagram.username',
  })], accountsWorkflow);
  const unknownType = executeTasks([captureInputTask('accounts-unknown-type', {
    value_source: 'fixed',
    value: 'person.accounts.myspace.username',
  })], accountsWorkflow);

  assert.equal(mailUsername.tasks[0].capturedInput.value, 'nora.brandt');
  assert.equal(mailAddress.tasks[0].capturedInput.value, 'nora.brandt@proton.me');
  assert.equal(mailSecret.tasks[0].capturedInput.value, mailPassword);
  assert.equal(instagramUsername.tasks[0].capturedInput.value, 'nora_brandt');
  assert.equal(unknownType.tasks[0].capturedInput.value, '');
  assert.equal(unknownType.tasks[0].capturedInput.valueResolutionStatus, 'missing_context_value');

  const run = executeTasks([returnTask('accounts-public-redaction', true)], accountsWorkflow, true);
  const publicAccounts = run.status.workflow.person.accounts;

  assert.equal(publicAccounts.email.username, 'nora.brandt');
  assert.equal(publicAccounts.email.hasPassword, true);
  assert.equal(publicAccounts.email.password, '');
  assert.equal(publicAccounts.instagram.password, '');
  assert.equal(JSON.stringify(run.status.workflow).includes(mailPassword), false);
  assert.equal(JSON.stringify(run.status.workflow).includes(instagramPassword), false);
});

test('a fixed person.email falls back to the main verification account without a reference person', () => {
  const verificationWorkflow = {
    // Keine Bezugsperson, aber ein Haupt-Verifikationskonto.
    verificationMailbox: { email: 'haupt-verifizierung@example.test' },
  };

  const result = executeTasks([captureInputTask('fixed-veri-email', {
    value_source: 'fixed',
    value: 'person.email',
  })], verificationWorkflow);

  assert.equal(result.tasks[0].capturedInput.value, 'haupt-verifizierung@example.test');
});

test('embedded workflow boundary preserves browser windows for parent workflow', () => {
  const browserWindows = [
    {
      key: 'child-session',
      label: 'Child session',
      url: 'https://example.test',
    },
  ];
  const result = executeEmbeddedWorkflow(true, { browserWindows });
  const boundary = result.tasks.find((task) => task.runner === 'workflow-boundary');

  assert.deepEqual(boundary.browserWindows, browserWindows);
  assert.deepEqual(result.browserWindows, browserWindows);
});

test('embedded workflow false return fails at the workflow boundary', () => {
  const result = executeEmbeddedWorkflow(false);

  assert.equal(result.ok, false);
  assert.equal(result.workflow_return, false);
  assert.equal(result.workflow_return_ok, false);
  assert.equal(result.failedTaskKey, 'embedded-boundary');
  assert.equal(result.tasks.at(-1).parent_task_key, 'embedded-workflow');
});

test('failed task follows a forward on_error route in the same Node run', () => {
  const failedTask = returnTask('mailbox-not-found', false);
  failedTask.on_error = {
    type: 'card',
    card_key: 'check-postbox-button',
  };
  const result = executeTasks([
    failedTask,
    returnTask('must-be-skipped-after-error', true),
    returnTask('check-postbox-button', true),
  ]);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'mailbox-not-found',
    'check-postbox-button',
  ]);
  assert.ok(result.events.some((event) => (
    event.stage === 'task-error-route-followed'
    && event.taskKey === 'mailbox-not-found'
    && event.targetTaskKey === 'check-postbox-button'
  )));
});

test('failed task requests a configured external step route without failing the workflow', () => {
  const failedTask = returnTask('mailbox-not-found', false);
  failedTask.on_error = {
    type: 'step',
    action_key: 'check-alternative-mailbox',
    step: 'check-alternative-mailbox',
  };
  const result = executeTasks([failedTask]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'mailbox-not-found');
  assert.ok(result.events.some((event) => (
    event.stage === 'task-error-route-requested'
    && event.taskKey === 'mailbox-not-found'
    && event.targetStepKey === 'check-alternative-mailbox'
  )));
});

test('failed task remains a workflow failure for fail and missing error routes', () => {
  for (const route of [
    null,
    { type: 'fail', step: 'fail' },
  ]) {
    const failedTask = returnTask('terminal-failure', false);

    if (route) {
      failedTask.on_error = route;
    }

    const result = executeTasks([failedTask]);
    assert.equal(result.ok, false);
    assert.equal(result.routeRequested, undefined);
    assert.equal(result.failedTaskKey, 'terminal-failure');
  }
});

test('configured end route completes a false branch without marking it as an error', () => {
  const result = executeTasks([
    branchTask('condition-not-met', { type: 'end', step: 'end' }),
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.logicalOutcome, 'condition_false');
  assert.equal(result.routeDisposition, 'complete');
});

test('unmatched condition follows on_error without marking the task as failed', () => {
  const result = executeTasks([
    branchTask('condition-not-met', {
      type: 'card',
      card_key: 'failure-target',
    }),
    returnTask('must-be-skipped-after-condition', true),
    returnTask('failure-target', true),
  ]);

  assert.equal(result.ok, true);
  assert.deepEqual(result.tasks.map((task) => task.key), [
    'condition-not-met',
    'failure-target',
  ]);
  assert.ok(result.events.some((event) => event.stage === 'task-condition-not-met'));
  assert.ok(result.events.some((event) => event.stage === 'task-branch-route-followed'));
  assert.equal(result.tasks[0].logicalOutcome, 'condition_false');
  assert.equal(result.events.some((event) => (
    event.stage === 'task-failed' && event.taskKey === 'condition-not-met'
  )), false);
});

test('unmatched condition requests an external failure route without failing Node execution', () => {
  const result = executeTasks([
    branchTask('condition-not-met', {
      type: 'card',
      card_key: 'earlier-task-not-in-runtime-slice',
    }),
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'condition-not-met');
  assert.equal(result.events.some((event) => event.stage === 'task-failed'), false);
});

test('unresolved embedded success route bubbles to the parent failure route', () => {
  const result = executeTasks([
    waitTask('embedded-task', 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
      next: {
        type: 'card',
        card_key: 'missing-child-task',
      },
    }),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'embedded-workflow');
  assert.match(result.statusMessage, /Interne Erfolgsroute/);
  assert.deepEqual(result.tasks.map((task) => task.key), ['embedded-task']);
});

test('backward success route stops after configured max attempts', () => {
  const result = executeTasks([
    returnTask('loop-entry', true),
    {
      ...returnTask('loop-back', true),
      next: {
        type: 'card',
        card_key: 'loop-entry',
        max_attempts: 2,
      },
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'loop-back');
  assert.match(result.statusMessage, /Erfolgsroute wurde zu oft wiederholt/);
  assert.equal(result.events.filter((event) => (
    event.stage === 'task-route-attempts-exhausted'
  )).length, 1);
});

test('backward success route without max attempts stops at the default limit', () => {
  const result = executeTasks([
    returnTask('loop-entry', true),
    {
      ...returnTask('loop-back', true),
      next: {
        type: 'card',
        card_key: 'loop-entry',
      },
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.match(result.statusMessage, /Erfolgsroute wurde zu oft wiederholt/);
});

test('forward on_error route stops after configured max attempts', () => {
  const failingLoop = branchTask('failing-check', {
    type: 'card',
    card_key: 'forward-target',
    max_attempts: 1,
  });
  const result = executeTasks([
    failingLoop,
    returnTask('must-be-skipped', true),
    {
      ...returnTask('forward-target', true),
      next: {
        type: 'card',
        card_key: 'failing-check',
      },
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.match(result.statusMessage, /zu oft wiederholt/);
});

test('embedded workflow follows backward on_error routes until max attempts is reached', () => {
  const result = executeTasks([
    waitTask('embedded-first', 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
    }),
    branchTask('embedded-check', {
      type: 'card',
      card_key: 'embedded-first',
      max_attempts: 1,
    }, 'embedded-frame', {
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_boundary_key: 'embedded-boundary',
    }),
    {
      key: 'embedded-boundary',
      task_key: 'workflow.boundary',
      title: 'Embedded workflow',
      kind: 'workflow',
      runner: 'workflow-boundary',
      parent_task_key: 'embedded-workflow',
      route_source_task_key: 'embedded-workflow',
      embedded_workflow_name: 'Embedded workflow',
      embedded_workflow_frame_key: 'embedded-frame',
    },
  ]);

  assert.equal(result.ok, true);
  assert.equal(result.routeRequested, true);
  assert.equal(result.routeOutcome, 'failed');
  assert.equal(result.completedTaskKey, 'embedded-workflow');
  assert.match(result.statusMessage, /zu oft wiederholt/);
  assert.equal(result.events.filter((event) => (
    event.stage === 'task-branch-route-followed'
    && event.taskKey === 'embedded-check'
    && event.targetTaskKey === 'embedded-first'
  )).length, 1);
});
