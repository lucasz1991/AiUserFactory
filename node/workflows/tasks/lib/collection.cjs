'use strict';

function text(value) {
  return String(value ?? '').trim();
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function parseJson(value, fallback = null) {
  if (Array.isArray(value) || isObject(value)) {
    return value;
  }

  const normalized = text(value);
  if (normalized === '') return fallback;

  try {
    return JSON.parse(normalized);
  } catch {
    return fallback;
  }
}

function bool(value, fallback = false) {
  if (typeof value === 'boolean') return value;
  if (value === undefined || value === null || text(value) === '') return fallback;
  return ['1', 'true', 'yes', 'ja', 'on'].includes(text(value).toLowerCase());
}

function number(value, fallback = 0, min = Number.NEGATIVE_INFINITY, max = Number.POSITIVE_INFINITY) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.min(max, Math.max(min, parsed)) : fallback;
}

function cleanName(value, fallback = '') {
  return text(value)
    .replace(/\s+/g, '_')
    .replace(/[^A-Za-z0-9_.-]+/g, '')
    .slice(0, 120) || fallback;
}

function getPath(source, path) {
  const normalized = text(path).replace(/\[(\w+)\]/g, '.$1');
  if (normalized === '' || !source || typeof source !== 'object') return undefined;
  if (Object.prototype.hasOwnProperty.call(source, normalized)) return source[normalized];

  return normalized.split('.').filter(Boolean).reduce((current, segment) => {
    if (current === undefined || current === null || typeof current !== 'object') return undefined;
    return current[segment];
  }, source);
}

function variableRoot(context = {}) {
  const workflow = isObject(context.workflow) ? context.workflow : {};
  const variables = {
    ...(isObject(workflow.workflow_variables) ? workflow.workflow_variables : {}),
    ...(isObject(workflow.workflowVariables) ? workflow.workflowVariables : {}),
    ...(isObject(context.workflow_variables) ? context.workflow_variables : {}),
    ...(isObject(context.workflowVariables) ? context.workflowVariables : {}),
  };

  return {
    ...workflow,
    ...context,
    workflow,
    workflow_variables: variables,
    workflowVariables: variables,
    lastResult: context.lastResult || null,
  };
}

function resolveVariable(context = {}, path = '', fallback) {
  const normalized = text(path);
  if (normalized === '') return fallback;
  const root = variableRoot(context);
  const candidates = [
    normalized,
    `workflow_variables.${normalized}`,
    `workflowVariables.${normalized}`,
    `lastResult.${normalized}`,
    `lastResult.result.${normalized}`,
  ];

  for (const candidate of candidates) {
    const value = getPath(root, candidate);
    if (value !== undefined) return value;
  }

  return fallback;
}

function setWorkflowVariable(context = {}, name, value) {
  const key = cleanName(name);
  if (key === '') return;
  const variables = {
    ...(isObject(context.workflow_variables) ? context.workflow_variables : {}),
    ...(isObject(context.workflowVariables) ? context.workflowVariables : {}),
    [key]: value,
  };
  context.workflow_variables = variables;
  context.workflowVariables = variables;
}

function appendWorkflowArray(context = {}, options = {}) {
  const arrayName = cleanName(options.arrayName ?? options.array_name, 'items');
  const source = text(options.valueFromVariable ?? options.value_from_variable);
  const dedupeBy = text(options.dedupeBy ?? options.dedupe_by);
  const maxItems = Math.floor(number(options.maxItems ?? options.max_items, 0, 0, 100000));
  const current = resolveVariable(context, arrayName, []);

  if (!Array.isArray(current)) {
    return {
      ok: false,
      arrayName,
      reason: 'array_not_array',
      message: `Workflow-Variable "${arrayName}" ist kein Array.`,
    };
  }

  const value = Object.prototype.hasOwnProperty.call(options, 'value')
    ? options.value
    : (source !== ''
      ? resolveVariable(context, source)
      : (context.lastResult?.result ?? context.lastResult?.value));

  if (value === undefined || value === null || value === '') {
    return {
      ok: false,
      arrayName,
      source,
      reason: 'value_missing',
      message: source === ''
        ? `Kein Wert zum Anhaengen an "${arrayName}" gefunden.`
        : `Workflow-Variable "${source}" enthaelt keinen Wert fuer "${arrayName}".`,
    };
  }

  const items = [...current];
  let appended = false;
  let deduped = false;
  let limitReached = false;

  if (maxItems > 0 && items.length >= maxItems) {
    limitReached = true;
  } else if (dedupeBy !== '') {
    const candidate = getPath(value, dedupeBy);
    deduped = candidate !== undefined && items.some((item) => getPath(item, dedupeBy) === candidate);
    if (!deduped) {
      items.push(value);
      appended = true;
    }
  } else {
    items.push(value);
    appended = true;
  }

  setWorkflowVariable(context, arrayName, items);

  return {
    ok: true,
    arrayName,
    source,
    items,
    appended,
    deduped,
    limitReached,
    newLength: items.length,
  };
}

function privateRegistry(context = {}, key) {
  if (!isObject(context[key])) {
    Object.defineProperty(context, key, {
      value: {},
      enumerable: false,
      configurable: true,
      writable: true,
    });
  }

  return context[key];
}

async function elementVisible(handle) {
  if (!handle || typeof handle.evaluate !== 'function') return false;

  return handle.evaluate((element) => {
    if (!element || !element.isConnected) return false;
    const style = globalThis.getComputedStyle ? globalThis.getComputedStyle(element) : null;
    const rect = typeof element.getBoundingClientRect === 'function'
      ? element.getBoundingClientRect()
      : { width: 1, height: 1 };
    return (!style || (style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) !== 0))
      && rect.width > 0
      && rect.height > 0;
  }).catch(() => false);
}

async function queryElements(root, selector, onlyVisible = true) {
  const normalized = text(selector);
  if (!root || normalized === '' || typeof root.$$ !== 'function') {
    return { elements: [], matchedCount: 0, hiddenCount: 0 };
  }

  const matched = await root.$$(normalized).catch(() => []);
  if (!onlyVisible) return { elements: matched, matchedCount: matched.length, hiddenCount: 0 };

  const visibility = await Promise.all(matched.map((handle) => elementVisible(handle)));
  const elements = matched.filter((_handle, index) => visibility[index]);

  return {
    elements,
    matchedCount: matched.length,
    hiddenCount: matched.length - elements.length,
  };
}

function selectorList(field = {}) {
  const fallbacks = parseJson(field.fallback_selectors ?? field.fallbackSelectors, null);
  const values = [field.selector];

  if (Array.isArray(fallbacks)) values.push(...fallbacks);
  else if (text(field.fallback_selectors ?? field.fallbackSelectors) !== '') {
    values.push(...text(field.fallback_selectors ?? field.fallbackSelectors).split(/\r?\n|\|\|/));
  }

  return [...new Set(values.map(text).filter(Boolean))];
}

async function rawFieldValue(handle, type, attributeName) {
  return handle.evaluate((element, payload) => {
    const fieldType = String(payload.type || 'text').toLowerCase();
    if (fieldType === 'exists') return true;
    if (fieldType === 'html') return element.innerHTML ?? '';
    if (fieldType === 'inner_text') return element.innerText ?? '';
    if (fieldType === 'href') return element.href || element.getAttribute?.('href') || '';
    if (fieldType === 'attribute') return element.getAttribute?.(payload.attributeName || '') ?? '';
    return element.textContent ?? '';
  }, { type, attributeName }).catch(() => undefined);
}

function normalizeFieldValue(value, field = {}, pageUrl = '') {
  if (typeof value !== 'string') return value;
  let result = value;
  if (bool(field.trim, true)) result = result.trim();
  if (bool(field.normalize_whitespace ?? field.normalizeWhitespace, true)) result = result.replace(/\s+/g, ' ').trim();

  if (String(field.type || '').toLowerCase() === 'href' && bool(field.normalize_url ?? field.normalizeUrl, true) && result !== '') {
    try {
      result = new URL(result, pageUrl || undefined).toString();
    } catch {
      // Preserve a non-standard but useful URL value.
    }
  }

  return result;
}

async function readField(scope, field = {}, pageUrl = '') {
  const type = text(field.type || 'text').toLowerCase();
  const multiple = bool(field.multiple, false);
  const selectors = selectorList(field);
  const onlyVisible = bool(field.only_visible ?? field.onlyVisible, false);
  let usedSelector = selectors[0] || ':scope';
  let candidates = selectors.length === 0 && bool(field.scope_self ?? field.scopeSelf, false) ? [scope] : [];

  for (const selector of selectors) {
    const queried = await queryElements(scope, selector, onlyVisible);
    if (queried.elements.length > 0) {
      candidates = queried.elements;
      usedSelector = selector;
      break;
    }
  }

  if (candidates.length === 0 && bool(field.scope_self_fallback ?? field.scopeSelfFallback, false)) {
    candidates = [scope];
    usedSelector = ':scope';
  }

  if (type === 'exists') {
    return { value: candidates.length > 0, usedSelector, empty: candidates.length === 0 };
  }

  const values = [];
  for (const candidate of candidates) {
    const value = await rawFieldValue(candidate, type, text(field.attribute_name ?? field.attributeName));
    if (value !== undefined && value !== null) values.push(normalizeFieldValue(value, field, pageUrl));
    if (!multiple) break;
  }

  let value = multiple
    ? values.filter((item) => item !== '').join(String(field.join_with ?? field.joinWith ?? ', '))
    : values[0];
  if (value === undefined || value === null || value === '') value = field.default_value ?? field.defaultValue ?? '';

  return { value, usedSelector, empty: value === '' || value === null || value === undefined };
}

async function readFields(scope, fields = [], pageUrl = '') {
  const result = {};
  const selectors = {};
  const emptyFields = [];
  const requiredMissing = [];

  for (const definition of fields) {
    if (!isObject(definition)) continue;
    const name = cleanName(definition.name);
    if (name === '') continue;
    const fieldResult = await readField(scope, definition, pageUrl);
    result[name] = fieldResult.value;
    selectors[name] = fieldResult.usedSelector;
    if (fieldResult.empty) {
      emptyFields.push(name);
      if (bool(definition.required, false)) requiredMissing.push(name);
    }
  }

  return { result, selectors, emptyFields, requiredMissing };
}

module.exports = {
  appendWorkflowArray,
  bool,
  cleanName,
  getPath,
  isObject,
  number,
  parseJson,
  privateRegistry,
  queryElements,
  readFields,
  resolveVariable,
  setWorkflowVariable,
  text,
};
