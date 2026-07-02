'use strict';

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function normalizeVariableName(value, fallback = '') {
  const normalized = text(value)
    .replace(/[^A-Za-z0-9_.-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 120);

  return normalized || fallback;
}

function booleanValue(value, fallback = false) {
  if (typeof value === 'boolean') {
    return value;
  }

  const normalized = text(value).toLowerCase();

  if (normalized === '') {
    return fallback;
  }

  if (['1', 'true', 'yes', 'ja', 'on', 'required', 'pflicht'].includes(normalized)) {
    return true;
  }

  if (['0', 'false', 'no', 'nein', 'off', 'optional'].includes(normalized)) {
    return false;
  }

  return fallback;
}

function parseLiteral(value) {
  const raw = text(value);

  if (raw === '') {
    return '';
  }

  if (raw.startsWith('literal:')) {
    return raw.slice('literal:'.length);
  }

  if (['true', 'false'].includes(raw.toLowerCase())) {
    return raw.toLowerCase() === 'true';
  }

  if (/^-?\d+(\.\d+)?$/.test(raw)) {
    return Number(raw);
  }

  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

function parseJson(value) {
  if (Array.isArray(value) || isObject(value)) {
    return value;
  }

  const raw = text(value);

  if (raw === '') {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function normalizeDefinition(value, fallbackName = '') {
  if (typeof value === 'string') {
    return normalizeLineDefinition(value, fallbackName);
  }

  if (!isObject(value)) {
    return null;
  }

  const name = normalizeVariableName(
    value.name
    ?? value.variable
    ?? value.key
    ?? value.output
    ?? value.output_name
    ?? value.outputName
    ?? fallbackName,
  );

  if (name === '') {
    return null;
  }

  const source = value.source
    ?? value.path
    ?? value.input
    ?? value.from
    ?? value.value
    ?? name;

  return {
    name,
    source: Array.isArray(source) ? source.map(text).filter(Boolean) : text(source),
    required: booleanValue(value.required ?? value.is_required ?? value.isRequired, false),
    defaultValue: value.default
      ?? value.default_value
      ?? value.defaultValue
      ?? value.fallback
      ?? '',
    type: text(value.type || value.kind || 'string').toLowerCase(),
    requireOpen: booleanValue(value.require_open ?? value.requireOpen, true),
  };
}

function normalizeLineDefinition(line, fallbackName = '') {
  const raw = text(line);

  if (raw === '' || raw.startsWith('#')) {
    return null;
  }

  const [left, ...rightParts] = raw.includes('=') ? raw.split('=') : raw.split('|');
  const name = normalizeVariableName(left, fallbackName);
  const parts = raw.includes('=')
    ? rightParts.join('=').split('|').map(text).filter(Boolean)
    : raw.split('|').slice(1).map(text).filter(Boolean);

  if (name === '') {
    return null;
  }

  const source = parts.shift() || name;
  const definition = {
    name,
    source,
    required: false,
    defaultValue: '',
    type: 'string',
    requireOpen: true,
  };

  for (const part of parts) {
    const normalized = part.toLowerCase();

    if (['required', 'pflicht', 'require'].includes(normalized)) {
      definition.required = true;
    } else if (['optional', 'freiwillig'].includes(normalized)) {
      definition.required = false;
    } else if (normalized.startsWith('default:')) {
      definition.defaultValue = part.slice('default:'.length);
    } else if (normalized.startsWith('fallback:')) {
      definition.defaultValue = part.slice('fallback:'.length);
    } else if (normalized.startsWith('type:')) {
      definition.type = part.slice('type:'.length).trim().toLowerCase() || 'string';
    } else if (normalized.startsWith('kind:')) {
      definition.type = part.slice('kind:'.length).trim().toLowerCase() || 'string';
    } else if (['browser_window', 'browser-window', 'browserwindow'].includes(normalized)) {
      definition.type = 'browser_window';
    } else if (normalized === 'require_open:false' || normalized === 'requireopen:false') {
      definition.requireOpen = false;
    }
  }

  return definition;
}

function parseDefinitions(input = {}) {
  const configured = input.input_definitions
    ?? input.inputDefinitions
    ?? input.definitions
    ?? input.fields
    ?? input.value
    ?? input.inputValue
    ?? input.input_value
    ?? '';
  const decoded = parseJson(configured);

  if (Array.isArray(decoded)) {
    return decoded
      .map((definition, index) => normalizeDefinition(definition, `input_${index + 1}`))
      .filter(Boolean);
  }

  if (isObject(decoded)) {
    return Object.entries(decoded)
      .map(([name, definition]) => (
        isObject(definition)
          ? normalizeDefinition({ name, ...definition }, name)
          : normalizeDefinition({ name, source: definition }, name)
      ))
      .filter(Boolean);
  }

  return text(configured)
    .split(/\r?\n/)
    .map((line) => normalizeLineDefinition(line))
    .filter(Boolean);
}

function getPath(source, path) {
  const normalized = text(path);

  if (normalized === '' || !source || typeof source !== 'object') {
    return undefined;
  }

  return normalized
    .replace(/\[(\w+)\]/g, '.$1')
    .split('.')
    .filter(Boolean)
    .reduce((current, segment) => {
      if (current === undefined || current === null || typeof current !== 'object') {
        return undefined;
      }

      return current[segment];
    }, source);
}

function rootContext(context = {}) {
  const workflow = isObject(context.workflow) ? context.workflow : {};
  const workflowVariables = {
    ...(isObject(workflow.workflow_variables) ? workflow.workflow_variables : {}),
    ...(isObject(workflow.workflowVariables) ? workflow.workflowVariables : {}),
    ...(isObject(context.workflow_variables) ? context.workflow_variables : {}),
    ...(isObject(context.workflowVariables) ? context.workflowVariables : {}),
  };

  return {
    ...workflow,
    workflow,
    person: context.person || workflow.person || null,
    account: context.account || workflow.account || workflow.email_account || null,
    email_account: context.account || workflow.email_account || workflow.account || null,
    browserWindows: context.browserWindows || workflow.browserWindows || [],
    browser_windows: context.browser_windows || workflow.browser_windows || {},
    browser: context.browser || workflow.browser || null,
    browser_runtime: workflow.browser_runtime || null,
    activeBrowserWindow: context.activeBrowserWindow || workflow.activeBrowserWindow || '',
    active_browser_window: context.activeBrowserWindow || workflow.active_browser_window || '',
    browserWindow: context.browserWindow || context.browser_window || workflow.browserWindow || workflow.browser_window || '',
    browser_window: context.browser_window || context.browserWindow || workflow.browser_window || workflow.browserWindow || '',
    browserWindowName: context.browserWindowName || context.browser_window_name || workflow.browserWindowName || workflow.browser_window_name || '',
    browser_window_name: context.browser_window_name || context.browserWindowName || workflow.browser_window_name || workflow.browserWindowName || '',
    workflowVariables,
    workflow_variables: workflowVariables,
    lastResult: context.lastResult || null,
  };
}

function hasValue(value) {
  if (value === undefined || value === null) {
    return false;
  }

  if (typeof value === 'string') {
    return value.trim() !== '';
  }

  if (Array.isArray(value)) {
    return value.length > 0;
  }

  if (isObject(value)) {
    return Object.keys(value).length > 0;
  }

  return true;
}

function resolveValue(context, source) {
  const sources = Array.isArray(source) ? source : String(source || '').split(',').map(text).filter(Boolean);
  const root = rootContext(context);

  for (const item of sources) {
    if (item.startsWith('literal:')) {
      return item.slice('literal:'.length);
    }

    if (item.startsWith('json:')) {
      const decoded = parseJson(item.slice('json:'.length));

      if (decoded !== null) {
        return decoded;
      }
    }

    const value = getPath(root, item);

    if (hasValue(value)) {
      return value;
    }
  }

  return undefined;
}

function normalizeBrowserWindowName(value) {
  if (isObject(value)) {
    return normalizeBrowserWindowName(value.key || value.name || value.browserWindow || value.browser_window || value.browserWindowName || value.browser_window_name || '');
  }

  return String(value || '')
    .trim()
    .replace(/\s+/g, '-')
    .replace(/[^A-Za-z0-9._-]+/g, '')
    .toLowerCase()
    .slice(0, 80);
}

function browserWindowEntries(context = {}) {
  const root = rootContext(context);
  const values = []
    .concat(root.browserWindows || [])
    .concat(root.browser_windows || []);

  if (isObject(root.browserWindows)) {
    values.push(...Object.entries(root.browserWindows).map(([key, value]) => ({ key, ...(isObject(value) ? value : {}) })));
  }

  if (isObject(root.browser_windows)) {
    values.push(...Object.entries(root.browser_windows).map(([key, value]) => ({ key, ...(isObject(value) ? value : {}) })));
  }

  return values.filter((value) => value && typeof value === 'object');
}

function browserWindowExists(context = {}, name = '') {
  const normalized = normalizeBrowserWindowName(name);

  if (normalized === '') {
    return false;
  }

  return browserWindowEntries(context).some((entry) => normalizeBrowserWindowName(
    entry.key
    || entry.name
    || entry.browserWindow
    || entry.browser_window
    || entry.browserWindowName
    || entry.browser_window_name
  ) === normalized);
}

function coerceValue(definition, value) {
  const type = text(definition.type || 'string').toLowerCase();

  if (['browser_window', 'browser-window', 'browserwindow'].includes(type)) {
    return normalizeBrowserWindowName(value);
  }

  if (type === 'number') {
    return Number(value);
  }

  if (type === 'boolean' || type === 'bool') {
    return booleanValue(value, false);
  }

  if (type === 'json') {
    const decoded = parseJson(value);

    return decoded === null ? value : decoded;
  }

  return value;
}

function outputGroupName(input = {}) {
  return normalizeVariableName(
    input.output_group
    || input.outputGroup
    || input.group_variable
    || input.groupVariable
    || 'workflow_inputs',
    'workflow_inputs',
  );
}

async function run(context = {}) {
  const input = context.input || {};
  const definitions = parseDefinitions(input);
  const values = {};
  const checked = [];
  const missing = [];

  if (definitions.length === 0) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Eingabewert-Definitionen konfiguriert.',
    };
  }

  for (const definition of definitions) {
    const rawValue = resolveValue(context, definition.source || definition.name);
    const valueWasProvided = hasValue(rawValue);
    let value = valueWasProvided ? rawValue : undefined;
    let usedDefault = false;
    let browserWindowOpen = null;

    if (!valueWasProvided && !definition.required && hasValue(definition.defaultValue)) {
      value = parseLiteral(definition.defaultValue);
      usedDefault = true;
    }

    if (hasValue(value)) {
      value = coerceValue(definition, value);
    }

    if (['browser_window', 'browser-window', 'browserwindow'].includes(text(definition.type).toLowerCase())) {
      browserWindowOpen = browserWindowExists(context, value);

      if (!browserWindowOpen && definition.required && definition.requireOpen !== false) {
        missing.push({
          name: definition.name,
          source: definition.source,
          type: 'browser_window',
          reason: hasValue(value) ? 'browser_window_not_open' : 'missing',
          value,
        });
      }
    } else if (!hasValue(value) && definition.required) {
      missing.push({
        name: definition.name,
        source: definition.source,
        type: definition.type || 'string',
        reason: 'missing',
      });
    }

    if (hasValue(value)) {
      values[definition.name] = value;
      context[definition.name] = value;
    }

    checked.push({
      name: definition.name,
      source: definition.source,
      required: definition.required,
      type: definition.type || 'string',
      present: hasValue(value),
      usedDefault,
      browserWindowOpen,
      value: hasValue(value) ? value : null,
    });
  }

  const groupName = outputGroupName(input);
  const workflowVariables = {
    ...(context.workflow_variables || {}),
    ...values,
    [groupName]: values,
  };

  context.workflow_variables = workflowVariables;
  context.workflowVariables = {
    ...(context.workflowVariables || {}),
    ...workflowVariables,
  };

  if (missing.length > 0) {
    return {
      ok: true,
      status: 'missing_required',
      statusMessage: `Pflicht-Eingabewerte fehlen: ${missing.map((item) => item.name).join(', ')}`,
      branchOutcome: 'failed',
      missing_inputs: missing,
      missingInputs: missing,
      checked_inputs: checked,
      checkedInputs: checked,
      workflow_variables: workflowVariables,
      workflowVariables: context.workflowVariables,
    };
  }

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Workflow-Eingabewerte wurden geprueft.',
    checked_inputs: checked,
    checkedInputs: checked,
    workflow_inputs: values,
    workflowInputs: values,
    workflow_variables: workflowVariables,
    workflowVariables: context.workflowVariables,
  };
}

module.exports = { key: 'data.validate_inputs', run };
