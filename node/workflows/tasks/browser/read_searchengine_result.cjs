'use strict';

const {
  bool,
  cleanName,
  isObject,
  parseJson,
  privateRegistry,
  readFields,
  setWorkflowVariable,
} = require('../lib/collection.cjs');

function fallbackList(fallbacks, key) {
  const value = isObject(fallbacks) ? fallbacks[key] : null;
  return Array.isArray(value) ? value : [];
}

async function run(context = {}) {
  const input = context.input || {};
  const scopeName = cleanName(input.scope_variable ?? input.scopeVariable, 'current_result');
  const outputName = cleanName(input.output_variable ?? input.outputVariable, scopeName);
  const scope = privateRegistry(context, '__workflowElementScopes')[scopeName];
  const fallbacks = parseJson(input.fallbacks, {});

  if (!scope) {
    return { ok: false, status: 'failed', statusMessage: `Suchtreffer-Scope "${scopeName}" ist nicht aktiv.` };
  }

  const common = {
    trim: bool(input.trim_text ?? input.trimText, true),
    normalize_whitespace: true,
    only_visible: bool(input.visible_only ?? input.visibleOnly, true),
  };
  const fields = [
    { name: 'titel', selector: input.title_selector ?? input.titleSelector ?? 'h3', fallback_selectors: fallbackList(fallbacks, 'title'), type: 'text', required: true, ...common },
    { name: 'url', selector: input.link_selector ?? input.linkSelector ?? 'a', fallback_selectors: fallbackList(fallbacks, 'link'), type: 'href', required: true, normalize_url: bool(input.normalize_url ?? input.normalizeUrl, true), ...common },
    { name: 'description', selector: input.description_selector ?? input.descriptionSelector, fallback_selectors: fallbackList(fallbacks, 'description'), type: 'text', required: false, ...common },
    { name: 'site_name', selector: input.site_name_selector ?? input.siteNameSelector, fallback_selectors: fallbackList(fallbacks, 'site_name'), type: 'text', required: false, ...common },
    { name: 'breadcrumb', selector: input.breadcrumb_selector ?? input.breadcrumbSelector, fallback_selectors: fallbackList(fallbacks, 'breadcrumb'), type: 'text', required: false, ...common },
  ];
  const pageUrl = typeof context.page?.url === 'function' ? context.page.url() : '';
  const extracted = await readFields(scope, fields, pageUrl);
  setWorkflowVariable(context, outputName, extracted.result);
  const ok = extracted.requiredMissing.length === 0;
  if (!ok) privateRegistry(context, '__workflowLoopSkippedScopes')[scopeName] = true;

  return {
    ok,
    status: ok ? 'success' : 'failed',
    statusMessage: ok ? 'Suchmaschinentreffer wurde gelesen.' : `Suchtreffer ohne Pflichtfelder: ${extracted.requiredMissing.join(', ')}.`,
    result: extracted.result,
    scope_variable: scopeName,
    output_variable: outputName,
    selectors_used: extracted.selectors,
    empty_fields: extracted.emptyFields,
    required_missing: extracted.requiredMissing,
    workflow_variables: context.workflow_variables,
  };
}

module.exports = { key: 'browser.read_searchengine_result', run };
