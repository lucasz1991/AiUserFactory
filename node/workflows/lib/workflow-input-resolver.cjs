'use strict';

function createWorkflowInputResolver(options = {}) {
  const getWorkflow = typeof options.getWorkflow === 'function'
    ? options.getWorkflow
    : () => ({});
  const normalizeBrowserWindowName = typeof options.normalizeBrowserWindowName === 'function'
    ? options.normalizeBrowserWindowName
    : (value) => {
      const normalized = String(value || '')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/[^A-Za-z0-9._-]+/g, '')
        .toLowerCase()
        .slice(0, 80);

      return normalized || 'main';
    };

  function workflow() {
    return getWorkflow() || {};
  }

  function valueFromPath(source, keyPath) {
    const segments = String(keyPath || '').split('.').filter(Boolean);
    let current = source;

    for (const segment of segments) {
      if (!current || typeof current !== 'object' || !(segment in current)) {
        return undefined;
      }

      current = current[segment];
    }

    return current;
  }

  function isPlainObject(value) {
    return value && typeof value === 'object' && !Array.isArray(value);
  }

  function firstResolvedValue(...values) {
    for (const value of values) {
      if (value !== undefined && value !== null && value !== '') {
        return value;
      }
    }

    return undefined;
  }

  function firstConfiguredValue(...values) {
    for (const value of values) {
      if (value === undefined || value === null) {
        continue;
      }

      if (typeof value === 'string' && value.trim() === '') {
        continue;
      }

      return value;
    }

    return '';
  }

  function workflowVariablesFromContext(context = {}) {
    const contextWorkflow = isPlainObject(context.workflow) ? context.workflow : {};

    return {
      ...(isPlainObject(contextWorkflow.workflow_variables) ? contextWorkflow.workflow_variables : {}),
      ...(isPlainObject(contextWorkflow.workflowVariables) ? contextWorkflow.workflowVariables : {}),
      ...(isPlainObject(context.workflow_variables) ? context.workflow_variables : {}),
      ...(isPlainObject(context.workflowVariables) ? context.workflowVariables : {}),
      ...(isPlainObject(context.lastResult?.workflow_variables) ? context.lastResult.workflow_variables : {}),
      ...(isPlainObject(context.lastResult?.workflowVariables) ? context.lastResult.workflowVariables : {}),
    };
  }

  function valueFromWorkflowVariables(variables = {}, name = '') {
    const normalized = String(name || '').trim();

    if (!normalized || !isPlainObject(variables)) {
      return undefined;
    }

    if (Object.prototype.hasOwnProperty.call(variables, normalized)) {
      return variables[normalized];
    }

    return valueFromPath(variables, normalized);
  }

  function normalizeMailboxSource(value) {
    const normalized = String(value ?? '').trim().toLowerCase();

    return ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'].includes(normalized)
      ? 'verification'
      : 'person';
  }

  function scopedWorkflowContext(context = {}, mailboxSource = 'person') {
    const source = normalizeMailboxSource(mailboxSource);
    const currentWorkflow = workflow();

    if (source !== 'verification') {
      return context;
    }

    const verificationAccount = context.verificationMailbox
      || context.verification_mailbox
      || context.veri_account
      || currentWorkflow.verificationMailbox
      || currentWorkflow.verification_mailbox
      || currentWorkflow.veri_account
      || currentWorkflow['veri-account']
      || null;

    if (!verificationAccount || typeof verificationAccount !== 'object') {
      return context;
    }

    const verificationPerson = {
      ...(context.person || currentWorkflow.person || {}),
      id: null,
      displayName: 'Verification Mailbox',
      firstName: '',
      lastName: '',
      email: verificationAccount.email || '',
      username: verificationAccount.username || verificationAccount.email || '',
      password: verificationAccount.password || '',
      provider: verificationAccount.provider || '',
      webmailUrl: verificationAccount.webmailUrl || verificationAccount.webmail_url || '',
      webmail_url: verificationAccount.webmail_url || verificationAccount.webmailUrl || '',
      hasPassword: verificationAccount.hasPassword ?? Boolean(verificationAccount.password),
      loginUsername: verificationAccount.username || verificationAccount.email || '',
      loginPassword: verificationAccount.password || '',
      hasLoginPassword: verificationAccount.hasPassword ?? Boolean(verificationAccount.password),
      emailAccount: verificationAccount,
      email_account: verificationAccount,
      isVerificationMailbox: true,
    };

    return {
      ...context,
      person: verificationPerson,
      account: verificationAccount,
      verificationMailbox: verificationAccount,
      verification_mailbox: verificationAccount,
      veri_account: verificationAccount,
      'veri-account': verificationAccount,
      workflow: {
        ...currentWorkflow,
        person: verificationPerson,
        account: verificationAccount,
        email_account: verificationAccount,
      },
    };
  }

  function resolveString(value, context = {}, resolveExactWorkflowVariable = true) {
    const normalized = String(value ?? '').trim();
    const currentWorkflow = workflow();
    const workflowVariables = workflowVariablesFromContext({
      workflow: currentWorkflow,
      ...context,
    });
    const exactWorkflowVariable = valueFromWorkflowVariables(workflowVariables, normalized);

    const directRuntimeKeys = [
      'new_password',
      'generated_password',
      'generated-password',
      'new_mail_username',
      'new_mail_address',
      'verification_code',
      'verificationCode',
      'workflow_return',
      'workflowReturn',
      'workflow_return_ok',
    ];

    if (resolveExactWorkflowVariable && exactWorkflowVariable !== undefined) {
      return exactWorkflowVariable ?? '';
    }

    if ((!normalized.includes('.') && !directRuntimeKeys.includes(normalized)) || normalized.includes('://')) {
      return value;
    }

    const verificationAccount = context.verificationMailbox
      || context.verification_mailbox
      || context.veri_account
      || currentWorkflow.verificationMailbox
      || currentWorkflow.verification_mailbox
      || currentWorkflow.veri_account
      || currentWorkflow['veri-account']
      || null;
    const basePerson = context.person || currentWorkflow.person || null;
    const personEmailAccount = (basePerson && typeof basePerson === 'object'
      ? (basePerson.emailAccount || basePerson.email_account || null)
      : null);
    const workflowAccount = currentWorkflow.account || currentWorkflow.email_account || null;
    const account = context.account
      || context.lastResult?.account
      || personEmailAccount
      || workflowAccount
      || verificationAccount
      || null;
    const personAccount = personEmailAccount || (basePerson ? (workflowAccount || context.account || verificationAccount) : account);
    const personForLookup = basePerson && typeof basePerson === 'object'
      ? {
        ...basePerson,
        email: personAccount?.email || basePerson.email || '',
        username: personAccount?.username || basePerson.username || basePerson.loginUsername || '',
        password: personAccount?.password || basePerson.password || '',
        provider: personAccount?.provider || basePerson.provider || '',
        webmailUrl: personAccount?.webmailUrl || personAccount?.webmail_url || basePerson.webmailUrl || basePerson.webmail_url || '',
        webmail_url: personAccount?.webmail_url || personAccount?.webmailUrl || basePerson.webmail_url || basePerson.webmailUrl || '',
        hasPassword: personAccount?.hasPassword ?? basePerson.hasPassword ?? Boolean(personAccount?.password || basePerson.password),
        emailAccount: personAccount || basePerson.emailAccount || basePerson.email_account || null,
        email_account: personAccount || basePerson.email_account || basePerson.emailAccount || null,
      }
      : (account ? {
        email: account.email || '',
        username: account.username || '',
        password: account.password || '',
        provider: account.provider || '',
        webmailUrl: account.webmailUrl || account.webmail_url || '',
        webmail_url: account.webmail_url || account.webmailUrl || '',
        hasPassword: account.hasPassword ?? Boolean(account.password),
        emailAccount: account,
        email_account: account,
        isVerificationMailbox: account === verificationAccount,
      } : null);
    const lookupRoot = {
      ...currentWorkflow,
      workflow: currentWorkflow,
      workflowVariables,
      workflow_variables: workflowVariables,
      person: personForLookup,
      account,
      email_account: account,
      verificationMailbox: verificationAccount,
      verification_mailbox: verificationAccount,
      veri_account: verificationAccount,
      'veri-account': verificationAccount,
      new_password: firstResolvedValue(context.new_password, context.generated_password, currentWorkflow.new_password, currentWorkflow.generated_password, valueFromWorkflowVariables(workflowVariables, 'new_password'), valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'generated-password'), account?.password, context.lastResult?.new_password) ?? '',
      generated_password: firstResolvedValue(context.generated_password, context.new_password, currentWorkflow.generated_password, currentWorkflow.new_password, valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'new_password'), valueFromWorkflowVariables(workflowVariables, 'generated-password'), account?.password, context.lastResult?.generated_password, context.lastResult?.new_password) ?? '',
      'generated-password': firstResolvedValue(context.generated_password, context.new_password, currentWorkflow.generated_password, currentWorkflow.new_password, valueFromWorkflowVariables(workflowVariables, 'generated-password'), valueFromWorkflowVariables(workflowVariables, 'generated_password'), valueFromWorkflowVariables(workflowVariables, 'new_password'), account?.password, context.lastResult?.['generated-password'], context.lastResult?.generated_password, context.lastResult?.new_password) ?? '',
      new_mail_username: firstResolvedValue(account?.username, context.lastResult?.account?.username) ?? '',
      new_mail_address: firstResolvedValue(account?.email, context.lastResult?.account?.email) ?? '',
      verification_code: firstResolvedValue(context.verification_code, context.verificationCode, currentWorkflow.verification_code, currentWorkflow.verificationCode, valueFromWorkflowVariables(workflowVariables, 'verification_code'), valueFromWorkflowVariables(workflowVariables, 'verificationCode'), context.lastResult?.verification_code, context.lastResult?.verificationCode) ?? '',
      verificationCode: firstResolvedValue(context.verificationCode, context.verification_code, currentWorkflow.verificationCode, currentWorkflow.verification_code, valueFromWorkflowVariables(workflowVariables, 'verificationCode'), valueFromWorkflowVariables(workflowVariables, 'verification_code'), context.lastResult?.verificationCode, context.lastResult?.verification_code) ?? '',
      workflow_return: firstResolvedValue(context.workflow_return, context.workflowReturn, currentWorkflow.workflow_return, currentWorkflow.workflowReturn, valueFromWorkflowVariables(workflowVariables, 'workflow_return'), valueFromWorkflowVariables(workflowVariables, 'workflowReturn'), context.lastResult?.workflow_return, context.lastResult?.workflowReturn) ?? '',
      workflowReturn: firstResolvedValue(context.workflowReturn, context.workflow_return, currentWorkflow.workflowReturn, currentWorkflow.workflow_return, valueFromWorkflowVariables(workflowVariables, 'workflowReturn'), valueFromWorkflowVariables(workflowVariables, 'workflow_return'), context.lastResult?.workflowReturn, context.lastResult?.workflow_return) ?? '',
      workflow_return_ok: firstResolvedValue(context.workflow_return_ok, currentWorkflow.workflow_return_ok, valueFromWorkflowVariables(workflowVariables, 'workflow_return_ok'), context.lastResult?.workflow_return_ok) ?? '',
    };
    const resolved = valueFromPath(lookupRoot, normalized);

    if (resolved === undefined || resolved === null || resolved === '') {
      return /^(person|account|email_account|workflow|workflowVariables|workflow_variables|verificationMailbox|verification_mailbox|veri_account|veri-account)\./.test(normalized) || directRuntimeKeys.includes(normalized) ? '' : value;
    }

    return resolved;
  }

  // Feste Werte loesen nur katalogisierte Person-/Kontextpfade auf. Bewusster
  // Freitext bleibt literal; Workflow-Variablen haben ihren eigenen Quelltyp.
  function isContextPathValue(value) {
    const normalized = String(value ?? '').trim();

    if (normalized === '' || normalized.includes('://') || /\s/.test(normalized)) {
      return false;
    }

    if (/^(person|account|email_account|verificationMailbox|verification_mailbox|veri_account|veri-account)\.[a-z0-9_.-]+$/i.test(normalized)) {
      return true;
    }

    return [
      'new_password',
      'generated_password',
      'generated-password',
      'new_mail_username',
      'new_mail_address',
      'verification_code',
      'verificationCode',
    ].includes(normalized);
  }

  function configuredInputValue(task, context, rawValue) {
    const configuredSource = String(task.value_source || task.valueSource || '').trim().toLowerCase();

    if (!['fixed', 'workflow_variable', 'literal'].includes(configuredSource)) {
      return {
        value: resolveString(rawValue, context),
        source: 'legacy_auto',
        workflowVariable: '',
        status: 'legacy_resolved',
        fallbackUsed: false,
      };
    }

    if (configuredSource === 'fixed') {
      if (isContextPathValue(rawValue)) {
        const resolved = resolveString(rawValue, context, false);
        const contextValuePath = String(rawValue ?? '').trim();
        const missingContextValue = resolved === undefined || resolved === null || resolved === '';

        return {
          value: missingContextValue ? '' : resolved,
          source: 'fixed',
          workflowVariable: '',
          contextValuePath,
          status: missingContextValue ? 'missing_context_value' : 'fixed_context_resolved',
          fallbackUsed: false,
        };
      }

      return {
        value: rawValue,
        source: 'fixed',
        workflowVariable: '',
        status: 'fixed',
        fallbackUsed: false,
      };
    }

    if (configuredSource === 'literal') {
      return {
        value: rawValue,
        source: 'literal',
        workflowVariable: '',
        contextValuePath: '',
        status: 'literal',
        fallbackUsed: false,
      };
    }

    const workflowVariable = String(
      task.workflow_variable
      || task.workflowVariable
      || task.variable_name
      || task.variableName
      || '',
    ).trim();
    const workflowVariables = workflowVariablesFromContext({
      workflow: workflow(),
      ...context,
    });
    let resolved = valueFromWorkflowVariables(workflowVariables, workflowVariable);

    if (resolved === undefined && workflowVariable !== '') {
      const contextualValue = resolveString(workflowVariable, context);

      if (contextualValue !== workflowVariable) {
        resolved = contextualValue;
      }
    }

    if (resolved !== undefined && resolved !== null && resolved !== '') {
      return {
        value: resolved,
        source: 'workflow_variable',
        workflowVariable,
        status: 'variable_resolved',
        fallbackUsed: false,
      };
    }

    const fallback = task.value_fallback ?? task.valueFallback;

    if (fallback !== undefined && fallback !== null && String(fallback) !== '') {
      return {
        value: fallback,
        source: 'workflow_variable',
        workflowVariable,
        status: 'fallback_used',
        fallbackUsed: true,
      };
    }

    return {
      value: '',
      source: 'workflow_variable',
      workflowVariable,
      status: 'missing_workflow_variable',
      fallbackUsed: false,
    };
  }

  function taskInput(task, context = {}) {
    const mailboxSource = normalizeMailboxSource(task.script_person_source || task.scriptPersonSource || task.mailbox_source || task.mailboxSource || 'person');
    const valueContext = scopedWorkflowContext(context, mailboxSource);
    const rawValue = firstConfiguredValue(task.value, task.input);
    const rawInput = firstConfiguredValue(task.input, task.value);
    const rawUrl = firstConfiguredValue(task.url, task.value, task.input);
    const configuredValue = configuredInputValue(task, valueContext, rawValue);
    const browserWindow = normalizeBrowserWindowName(
      task.browser_window_name
      || task.browser_window
      || task.browserWindowName
      || task.browserWindow
      || context.activeBrowserWindow
      || 'main',
    );
    const input = {
      ...task,
      browserWindow,
      browserWindowName: browserWindow,
      browser_window: browserWindow,
      browser_window_name: browserWindow,
      mailboxSource,
      mailbox_source: mailboxSource,
      scriptPersonSource: mailboxSource,
      script_person_source: mailboxSource,
      value: configuredValue.value,
      inputValue: ['fixed', 'workflow_variable', 'literal'].includes(configuredValue.source)
        ? configuredValue.value
        : resolveString(rawInput, valueContext),
      input_value: ['fixed', 'workflow_variable', 'literal'].includes(configuredValue.source)
        ? configuredValue.value
        : resolveString(rawInput, valueContext),
      valueSource: configuredValue.source,
      value_source: configuredValue.source,
      workflowVariable: configuredValue.workflowVariable,
      workflow_variable: configuredValue.workflowVariable,
      contextValuePath: configuredValue.contextValuePath || '',
      context_value_path: configuredValue.contextValuePath || '',
      valueResolutionStatus: configuredValue.status,
      value_resolution_status: configuredValue.status,
      valueFallbackUsed: configuredValue.fallbackUsed,
      value_fallback_used: configuredValue.fallbackUsed,
      url: resolveString(rawUrl, valueContext),
      selector: task.selector || task.element_selector || task.input_selector || '',
      elementSelector: task.element_selector || task.selector || '',
      element_selector: task.element_selector || task.selector || '',
      inputSelector: task.input_selector || task.selector || '',
      input_selector: task.input_selector || task.selector || '',
    };

    if (task.task_key === 'wait.seconds') {
      input.seconds = task.value || task.input || 0;
    }

    return input;
  }

  return Object.freeze({
    isPlainObject,
    normalizeMailboxSource,
    resolveString,
    scopedWorkflowContext,
    taskInput,
    workflowVariablesFromContext,
  });
}

module.exports = { createWorkflowInputResolver };
