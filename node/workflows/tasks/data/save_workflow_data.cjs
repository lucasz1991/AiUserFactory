'use strict';

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function trimString(value) {
  return String(value ?? '').trim();
}

function truthy(value, fallback = false) {
  const normalized = trimString(value).toLowerCase();

  if (normalized === '') {
    return fallback;
  }

  return ['1', 'true', 'yes', 'ja', 'on'].includes(normalized);
}

function normalizeVariableName(value) {
  const normalized = trimString(value).replace(/[^A-Za-z0-9_.-]+/g, '_').replace(/^_+|_+$/g, '');

  return normalized || '';
}

function parseJsonishObject(value) {
  if (isObject(value)) {
    return value;
  }

  const raw = trimString(value);

  if (raw === '') {
    return {};
  }

  try {
    const decoded = JSON.parse(raw);

    return isObject(decoded) ? decoded : {};
  } catch {
    return {};
  }
}

function parseFieldMap(value) {
  const json = parseJsonishObject(value);

  if (Object.keys(json).length > 0) {
    return json;
  }

  const raw = trimString(value);

  if (raw === '') {
    return {};
  }

  return raw
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('#'))
    .reduce((fields, line) => {
      const separator = line.includes('=>') ? '=>' : '=';
      const parts = line.split(separator);
      const key = normalizeVariableName(parts.shift());
      const source = trimString(parts.join(separator));

      if (key !== '' && source !== '') {
        fields[key] = source;
      }

      return fields;
    }, {});
}

function getPath(source, path) {
  const normalized = trimString(path);

  if (normalized === '') {
    return undefined;
  }

  return normalized
    .replace(/\[(\w+)\]/g, '.$1')
    .split('.')
    .filter(Boolean)
    .reduce((current, part) => {
      if (current === undefined || current === null) {
        return undefined;
      }

      return current[part];
    }, source);
}

function resolveSource(context, source, options = {}) {
  const raw = trimString(source);
  const allowBareLiteral = options.allowBareLiteral === true;

  if (raw === '') {
    return undefined;
  }

  if (raw.startsWith('literal:')) {
    return raw.slice('literal:'.length);
  }

  if (raw.startsWith('json:')) {
    const decoded = parseJsonishObject(raw.slice('json:'.length));

    return Object.keys(decoded).length > 0 ? decoded : undefined;
  }

  const direct = getPath(context, raw);

  if (direct !== undefined && direct !== null && direct !== '') {
    return direct;
  }

  const workflowVariables = context.workflow_variables || context.workflowVariables || {};
  const variable = getPath(workflowVariables, raw);

  if (variable !== undefined && variable !== null && variable !== '') {
    return variable;
  }

  return allowBareLiteral && !raw.includes('.') && !raw.includes('[') ? raw : undefined;
}

function firstResolved(context, sources, options = {}) {
  for (const source of sources) {
    const value = resolveSource(context, source, options);

    if (value !== undefined && value !== null && trimString(value) !== '') {
      return value;
    }
  }

  return undefined;
}

function setPath(target, path, value) {
  const parts = normalizeVariableName(path).split('.').filter(Boolean);

  if (parts.length === 0) {
    return;
  }

  let current = target;

  while (parts.length > 1) {
    const part = parts.shift();

    if (!isObject(current[part])) {
      current[part] = {};
    }

    current = current[part];
  }

  current[parts[0]] = value;
}

function withoutEmptyValues(input) {
  return Object.entries(input)
    .filter(([, value]) => value !== undefined && value !== null && trimString(value) !== '')
    .reduce((result, [key, value]) => {
      result[key] = value;

      return result;
    }, {});
}

function publicData(value) {
  if (Array.isArray(value)) {
    return value.map(publicData);
  }

  if (!isObject(value)) {
    return value;
  }

  return Object.entries(value).reduce((result, [key, item]) => {
    if (['password', 'passwordEncrypted', 'password_encrypted'].includes(key)) {
      result.hasPassword = true;

      return result;
    }

    result[key] = publicData(item);

    return result;
  }, {});
}

function accountFromData(context, data) {
  const currentAccount = isObject(context.account) ? context.account : {};
  const lastAccount = isObject(context.lastResult?.account) ? context.lastResult.account : {};
  const provider = data.provider ?? currentAccount.provider ?? lastAccount.provider ?? 'proton';

  return withoutEmptyValues({
    ...currentAccount,
    ...lastAccount,
    email: data.email ?? currentAccount.email ?? lastAccount.email,
    username: data.username ?? currentAccount.username ?? lastAccount.username ?? data.email,
    password: data.password ?? currentAccount.password ?? lastAccount.password ?? context.new_password ?? context.generated_password,
    provider,
    recoveryEmail: data.recovery_email ?? data.recoveryEmail ?? currentAccount.recoveryEmail ?? currentAccount.recovery_email ?? lastAccount.recoveryEmail ?? lastAccount.recovery_email,
    webmailUrl: data.webmail_url ?? data.webmailUrl ?? currentAccount.webmailUrl ?? currentAccount.webmail_url ?? lastAccount.webmailUrl ?? lastAccount.webmail_url,
  });
}

function configuredData(context, input) {
  const map = parseFieldMap(input.field_map || input.fieldMap || input.value);
  const data = {};

  for (const [target, source] of Object.entries(map)) {
    const key = normalizeVariableName(target);
    const value = resolveSource(context, source, {
      allowBareLiteral: key === 'provider' || key.endsWith('.provider'),
    });

    if (key !== '' && value !== undefined && value !== null && trimString(value) !== '') {
      setPath(data, key, value);
    }
  }

  const sourceDefaults = {
    email: ['email_source', 'emailSource', 'account.email', 'email_account.email', 'new_mail_address', 'person.email', 'workflow.account.email'],
    username: ['username_source', 'usernameSource', 'account.username', 'email_account.username', 'new_mail_username', 'account.email'],
    password: ['password_source', 'passwordSource', 'account.password', 'new_password', 'generated_password', 'generated-password', 'lastResult.account.password'],
    provider: ['provider_source', 'providerSource', 'account.provider', 'email_account.provider', 'workflow.account.provider'],
    webmail_url: ['webmail_url_source', 'webmailUrlSource', 'account.webmailUrl', 'account.webmail_url', 'email_account.webmailUrl', 'email_account.webmail_url'],
    recovery_email: ['recovery_email_source', 'recoveryEmailSource', 'account.recoveryEmail', 'account.recovery_email', 'email_account.recoveryEmail', 'email_account.recovery_email'],
  };

  for (const [key, sources] of Object.entries(sourceDefaults)) {
    if (Object.prototype.hasOwnProperty.call(data, key)) {
      continue;
    }

    const configuredSource = sources
      .slice(0, 2)
      .map((sourceKey) => trimString(input[sourceKey]))
      .find((source) => source !== '');

    const resolved = firstResolved(context, configuredSource ? [configuredSource] : sources.slice(2), {
      allowBareLiteral: key === 'provider',
    });

    if (resolved !== undefined && resolved !== null && trimString(resolved) !== '') {
      data[key] = resolved;
    }
  }

  if (!data.provider && Object.keys(data).length > 0) {
    data.provider = 'proton';
  }

  return data;
}

function workflowVariablesFromData(input, data) {
  const variables = {};
  const defaults = {
    email: 'email',
    username: 'username',
    password: 'password',
    provider: 'provider',
    webmail_url: 'webmail_url',
    recovery_email: 'recovery_email',
  };

  for (const [key, defaultName] of Object.entries(defaults)) {
    const configuredName = normalizeVariableName(
      input[`${key}_variable`]
      || input[`${key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase())}Variable`]
      || defaultName,
    );

    if (configuredName !== '' && Object.prototype.hasOwnProperty.call(data, key)) {
      setPath(variables, configuredName, data[key]);
    }
  }

  const groupVariable = normalizeVariableName(
    input.group_variable
    || input.groupVariable
    || input.output_group
    || input.outputGroup
    || input.save_as
    || input.saveAs
    || 'email_account',
  );

  if (groupVariable !== '') {
    setPath(variables, groupVariable, data);
  }

  return variables;
}

async function run(context = {}) {
  const input = context.input || {};
  const data = configuredData(context, input);
  const account = accountFromData(context, data);
  const persistAccount = truthy(input.persist_account ?? input.persistAccount, true);
  const workflowVariables = workflowVariablesFromData(input, data);

  if (Object.keys(data).length === 0) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Workflow-Daten zum Speichern gefunden.',
    };
  }

  context.workflow_variables = {
    ...(context.workflow_variables || {}),
    ...workflowVariables,
  };
  context.workflowVariables = {
    ...(context.workflowVariables || {}),
    ...workflowVariables,
  };

  if (Object.keys(account).length > 0) {
    context.account = {
      ...(context.account || {}),
      ...account,
      hasPassword: Boolean(account.password || context.account?.hasPassword),
    };
  }

  return {
    ok: true,
    status: 'success',
    statusMessage: persistAccount
      ? 'Workflow- und Mail-Accountdaten wurden zum Speichern vorbereitet.'
      : 'Workflow-Daten wurden gespeichert.',
    workflow_variables: workflowVariables,
    workflowVariables,
    saved_data: publicData(data),
    savedData: publicData(data),
    account,
    persist_mail_account: persistAccount,
    persistMailAccount: persistAccount,
  };
}

module.exports = { key: 'data.save_workflow_data', run };
