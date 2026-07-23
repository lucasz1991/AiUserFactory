'use strict';

const {
  bool,
  cleanName,
  isObject,
  number,
  parseJson,
  privateRegistry,
  queryElements,
  readFields,
  resolveVariable,
  setWorkflowVariable,
  text,
} = require('../lib/collection.cjs');

const AUTOMATIC_SELECTORS = Object.freeze({
  title: ['h3', 'h2', 'h1', 'h4', '[role="heading"]', '.result-title', '.title'],
  link: ['a[href]'],
  description: ['.VwiC3b', '.snippet', '.description', '[class*="snippet"]', '[data-sncf]', 'p'],
  site_name: ['.VuuXrf', '.site-name', '[data-site-name]', 'cite'],
  breadcrumb: ['.tjvcx', '.breadcrumb', 'cite'],
});

const AUTOMATIC_AD_SELECTOR = [
  '[data-text-ad]',
  '[data-ad]',
  '[data-ad-result]',
  '[data-sponsored]',
  '[aria-label="Anzeige"]',
  '[aria-label="Werbung"]',
  '[aria-label="Sponsored"]',
  '[aria-label="Promoted"]',
  '.uEierd',
].join(', ');

const AUTOMATIC_AD_LABELS = ['anzeige', 'werbung', 'gesponsert', 'sponsored', 'promoted', 'ad'];

function fallbackList(fallbacks, key) {
  const value = isObject(fallbacks) ? fallbacks[key] : null;
  if (Array.isArray(value)) return value;
  if (text(value) !== '') return text(value).split(/\r?\n|\|\|/);
  return [];
}

function uniqueStrings(values = []) {
  return [...new Set(values.map((value) => text(value)).filter(Boolean))];
}

function selectorCandidates(input, fallbacks, snakeName, camelName, fallbackKey) {
  return uniqueStrings([
    input[snakeName] ?? input[camelName],
    ...fallbackList(fallbacks, fallbackKey),
    ...(AUTOMATIC_SELECTORS[fallbackKey] || []),
  ]);
}

function exclusionPatterns(value) {
  const parsed = parseJson(value, null);
  if (Array.isArray(parsed)) return uniqueStrings(parsed);
  if (typeof parsed === 'string') return uniqueStrings([parsed]);
  return uniqueStrings(text(value).split(/\r?\n|\|\|/));
}

function failed(reasonCode, statusMessage, payload = {}) {
  return {
    ok: false,
    status: 'failed',
    reason_code: reasonCode,
    reasonCode,
    statusMessage,
    ...payload,
  };
}

async function listRoots(page, selector) {
  if (!page || typeof page.$$ !== 'function') {
    return { roots: [], error: 'Es ist keine aktive Browserseite verfuegbar.' };
  }

  if (text(selector) === '') return { roots: [page], error: null };

  try {
    return { roots: await page.$$(selector), error: null };
  } catch (error) {
    return { roots: [], error: error.message || String(error) };
  }
}

async function listItemHandles(roots, selector) {
  const handles = [];

  try {
    for (const root of roots) {
      if (!root || typeof root.$$ !== 'function') continue;
      handles.push(...await root.$$(selector));
    }
  } catch (error) {
    return { handles: [], error: error.message || String(error) };
  }

  return { handles: [...new Set(handles)], error: null };
}

async function extractListItem(handle, payload) {
  if (!handle || typeof handle.evaluate !== 'function') {
    return { invalid: true, invalidReason: 'element_handle_missing' };
  }

  return handle.evaluate((item, options) => {
    const normalizedText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
    const lowercase = (value) => normalizedText(value).toLocaleLowerCase();
    const invalidSelectors = [];
    const selectorUsed = {};

    const visible = (() => {
      if (!item || item.isConnected === false) return false;
      const style = globalThis.getComputedStyle ? globalThis.getComputedStyle(item) : null;
      const rect = typeof item.getBoundingClientRect === 'function'
        ? item.getBoundingClientRect()
        : { width: 1, height: 1 };
      return (!style || (style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) !== 0))
        && rect.width > 0
        && rect.height > 0;
    })();

    if (options.onlyVisible && !visible) return { hidden: true };

    if (options.excludeSelector) {
      try {
        if (item.matches?.(options.excludeSelector) || item.querySelector?.(options.excludeSelector)) {
          return { excluded: true, excludedBy: 'selector' };
        }
      } catch (error) {
        return { selectorError: error.message || String(error), selectorErrorField: 'exclude_item_selector' };
      }
    }

    const itemText = normalizedText(item.innerText ?? item.textContent ?? '');
    const loweredItemText = lowercase(itemText);
    const matchedTextPattern = (options.excludeTextPatterns || []).find((pattern) => (
      pattern && loweredItemText.includes(lowercase(pattern))
    ));

    if (matchedTextPattern) {
      return { excluded: true, excludedBy: 'text', excludedPattern: matchedTextPattern };
    }

    if (options.excludeAds) {
      const labels = [];
      for (const attribute of ['aria-label', 'data-text-ad', 'data-ad-label', 'data-sponsored']) {
        const ownValue = item.getAttribute?.(attribute);
        if (ownValue) labels.push(ownValue);
        for (const node of item.querySelectorAll?.(`[${attribute}]`) || []) {
          labels.push(node.getAttribute?.(attribute) || '');
        }
      }
      const firstLine = String(item.innerText ?? item.textContent ?? '').split(/\r?\n/).map(normalizedText).find(Boolean) || '';
      labels.push(firstLine);
      const escapeRegExp = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const automaticAd = labels.some((label) => {
        const normalizedLabel = lowercase(label);

        return (options.automaticAdLabels || []).some((marker) => {
          const normalizedMarker = lowercase(marker);
          if (!normalizedLabel || !normalizedMarker) return false;

          return new RegExp(
            `(^|[^\\p{L}\\p{N}])${escapeRegExp(normalizedMarker)}(?=$|[^\\p{L}\\p{N}])`,
            'u',
          ).test(normalizedLabel);
        });
      });
      if (automaticAd) return { excluded: true, excludedBy: 'automatic_ad_label' };
    }

    const findNode = (selectors, allowSelf = false) => {
      for (const selector of selectors || []) {
        try {
          if (allowSelf && item.matches?.(selector)) {
            return { node: item, selector: ':scope' };
          }
          const node = item.querySelector?.(selector);
          if (node) return { node, selector };
        } catch {
          invalidSelectors.push(selector);
        }
      }
      return { node: null, selector: '' };
    };

    const titleMatch = findNode(options.selectors.title);
    const linkMatch = findNode(options.selectors.link, true);
    const descriptionMatch = findNode(options.selectors.description);
    const siteMatch = findNode(options.selectors.site_name);
    const breadcrumbMatch = findNode(options.selectors.breadcrumb);
    selectorUsed.titel = titleMatch.selector;
    selectorUsed.url = linkMatch.selector;
    selectorUsed.description = descriptionMatch.selector;
    selectorUsed.site_name = siteMatch.selector;
    selectorUsed.breadcrumb = breadcrumbMatch.selector;

    const title = normalizedText(titleMatch.node?.innerText ?? titleMatch.node?.textContent ?? linkMatch.node?.innerText ?? linkMatch.node?.textContent ?? '');
    let url = normalizedText(linkMatch.node?.href ?? linkMatch.node?.getAttribute?.('href') ?? '');
    if (url && options.normalizeUrl) {
      try {
        url = new URL(url, options.pageUrl || undefined).toString();
      } catch {
        // Keep useful non-standard URLs unchanged.
      }
    }

    const description = normalizedText(descriptionMatch.node?.innerText ?? descriptionMatch.node?.textContent ?? '');
    let siteName = normalizedText(siteMatch.node?.innerText ?? siteMatch.node?.textContent ?? '');
    const breadcrumb = normalizedText(breadcrumbMatch.node?.innerText ?? breadcrumbMatch.node?.textContent ?? '');
    if (!siteName && url) {
      try {
        siteName = new URL(url, options.pageUrl || undefined).hostname.replace(/^www\./i, '');
      } catch {
        // Site name remains optional.
      }
    }

    return {
      visible,
      invalid: title === '' || url === '',
      invalidReason: title === '' ? 'required_title_missing' : (url === '' ? 'required_url_missing' : null),
      result: { titel: title, url, description, site_name: siteName, breadcrumb },
      selectorsUsed: selectorUsed,
      invalidSelectors: [...new Set(invalidSelectors)],
    };
  }, payload).catch((error) => ({ invalid: true, invalidReason: 'item_evaluation_failed', error: error.message || String(error) }));
}

async function runListMode(context, input, listItemSelector) {
  const containerSelector = text(input.list_container_selector ?? input.listContainerSelector);
  const outputName = cleanName(input.output_array_name ?? input.outputArrayName, 'top_results');
  const fallbacks = parseJson(input.fallbacks, {});
  const excludeAds = bool(input.exclude_ads ?? input.excludeAds, true);
  const explicitExcludeSelector = text(input.exclude_item_selector ?? input.excludeItemSelector);
  const excludeSelector = uniqueStrings([
    explicitExcludeSelector,
    excludeAds ? AUTOMATIC_AD_SELECTOR : '',
  ]).join(', ');
  const rootsResult = await listRoots(context.page, containerSelector);

  if (rootsResult.error) {
    return failed('selector_invalid', `Der Listencontainer-Selector ist ungueltig: ${rootsResult.error}`, {
      selector_field: 'list_container_selector',
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
    });
  }
  if (rootsResult.roots.length === 0) {
    return failed('list_container_missing', `Kein Listencontainer fuer "${containerSelector}" gefunden.`, {
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
      matched_count: 0,
    });
  }

  const itemResult = await listItemHandles(rootsResult.roots, listItemSelector);
  if (itemResult.error) {
    return failed('selector_invalid', `Der Listenelement-Selector ist ungueltig: ${itemResult.error}`, {
      selector_field: 'list_item_selector',
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
    });
  }
  if (itemResult.handles.length === 0) {
    return failed('list_items_missing', `Keine Listenelemente fuer "${listItemSelector}" gefunden.`, {
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
      matched_count: 0,
    });
  }

  const pageUrl = typeof context.page?.url === 'function' ? context.page.url() : '';
  const payload = {
    onlyVisible: bool(input.visible_only ?? input.visibleOnly, true),
    normalizeUrl: bool(input.normalize_url ?? input.normalizeUrl, true),
    excludeAds,
    excludeSelector,
    excludeTextPatterns: exclusionPatterns(input.exclude_item_text ?? input.excludeItemText),
    automaticAdLabels: AUTOMATIC_AD_LABELS,
    pageUrl,
    selectors: {
      title: selectorCandidates(input, fallbacks, 'title_selector', 'titleSelector', 'title'),
      link: selectorCandidates(input, fallbacks, 'link_selector', 'linkSelector', 'link'),
      description: selectorCandidates(input, fallbacks, 'description_selector', 'descriptionSelector', 'description'),
      site_name: selectorCandidates(input, fallbacks, 'site_name_selector', 'siteNameSelector', 'site_name'),
      breadcrumb: selectorCandidates(input, fallbacks, 'breadcrumb_selector', 'breadcrumbSelector', 'breadcrumb'),
    },
  };
  const extracted = await Promise.all(itemResult.handles.map((handle) => extractListItem(handle, payload)));
  const selectorError = extracted.find((entry) => entry?.selectorError);
  if (selectorError) {
    return failed('selector_invalid', `Der Ausschluss-Selector ist ungueltig: ${selectorError.selectorError}`, {
      selector_field: selectorError.selectorErrorField,
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
    });
  }

  const hiddenCount = extracted.filter((entry) => entry?.hidden).length;
  const excludedBySelectorCount = extracted.filter((entry) => entry?.excludedBy === 'selector').length;
  const excludedByTextCount = extracted.filter((entry) => entry?.excludedBy === 'text').length;
  const excludedByAutomaticAdCount = extracted.filter((entry) => entry?.excludedBy === 'automatic_ad_label').length;
  const invalidEntries = extracted.filter((entry) => entry?.invalid && !entry?.hidden && !entry?.excluded);
  const validEntries = extracted.filter((entry) => entry?.result && !entry.invalid && !entry.hidden && !entry.excluded);
  const dedupeByUrl = bool(input.dedupe_by_url ?? input.dedupeByUrl, true);
  const seenUrls = new Set();
  let duplicateCount = 0;
  const deduplicated = validEntries.filter((entry) => {
    if (!dedupeByUrl) return true;
    const key = String(entry.result.url || '').trim().toLocaleLowerCase();
    if (!key || !seenUrls.has(key)) {
      if (key) seenUrls.add(key);
      return true;
    }
    duplicateCount += 1;
    return false;
  });
  const offset = Math.floor(number(input.offset, 0, 0, 100000));
  const limit = Math.floor(number(input.limit, 10, 0, 100000));
  const selectedEntries = deduplicated.slice(offset, limit > 0 ? offset + limit : undefined);
  const results = selectedEntries.map((entry) => entry.result);
  const selectorsUsed = selectedEntries.map((entry) => entry.selectorsUsed || {});
  const excludedCount = excludedBySelectorCount + excludedByTextCount + excludedByAutomaticAdCount;

  if (results.length === 0 && !bool(input.allow_empty ?? input.allowEmpty, false)) {
    return failed('valid_result_missing', 'Nach Sichtbarkeits-, Werbe- und Pflichtfeldfiltern blieb kein gueltiger Suchtreffer uebrig.', {
      result: [],
      results: [],
      list_container_selector: containerSelector,
      list_item_selector: listItemSelector,
      matched_count: itemResult.handles.length,
      visible_count: itemResult.handles.length - hiddenCount,
      hidden_count: hiddenCount,
      excluded_count: excludedCount,
      excluded_by_selector_count: excludedBySelectorCount,
      excluded_by_text_count: excludedByTextCount,
      excluded_by_automatic_ad_count: excludedByAutomaticAdCount,
      invalid_count: invalidEntries.length,
      duplicate_count: duplicateCount,
      selected_count: 0,
    });
  }

  setWorkflowVariable(context, outputName, results);

  return {
    ok: true,
    status: 'success',
    statusMessage: `${results.length} Suchmaschinentreffer wurden gelesen${excludedCount > 0 ? `; ${excludedCount} Werbe-/Filtertreffer wurden uebersprungen` : ''}.`,
    mode: 'list',
    result: results,
    results,
    output_array_name: outputName,
    list_container_selector: containerSelector,
    list_item_selector: listItemSelector,
    matched_count: itemResult.handles.length,
    visible_count: itemResult.handles.length - hiddenCount,
    hidden_count: hiddenCount,
    excluded_count: excludedCount,
    excluded_by_selector_count: excludedBySelectorCount,
    excluded_by_text_count: excludedByTextCount,
    excluded_by_automatic_ad_count: excludedByAutomaticAdCount,
    invalid_count: invalidEntries.length,
    duplicate_count: duplicateCount,
    selected_count: results.length,
    selectors_used: selectorsUsed,
    workflow_variables: context.workflow_variables,
  };
}

async function runLegacyScopeMode(context, input) {
  const scopeName = cleanName(input.scope_variable ?? input.scopeVariable, 'current_result');
  const outputName = cleanName(input.output_variable ?? input.outputVariable, scopeName);
  let scope = privateRegistry(context, '__workflowElementScopes')[scopeName];
  const fallbacks = parseJson(input.fallbacks, {});

  if (!scope) {
    const descriptor = resolveVariable(context, scopeName, null);
    if (isObject(descriptor) && typeof context.page?.$$ === 'function') {
      const queried = await queryElements(
        context.page,
        descriptor.selector,
        bool(descriptor.only_visible ?? descriptor.onlyVisible, true),
      );
      const offset = Math.max(0, Number(descriptor.offset || 0));
      const index = Math.max(0, Number(descriptor.index || 0));
      scope = queried.elements[offset + index] || null;
    }
  }

  if (!scope) {
    return failed('scope_missing', `Suchtreffer-Scope "${scopeName}" ist nicht aktiv.`);
  }

  const common = {
    trim: bool(input.trim_text ?? input.trimText, true),
    normalize_whitespace: true,
    only_visible: bool(input.visible_only ?? input.visibleOnly, true),
  };
  const fields = [
    { name: 'titel', selector: input.title_selector ?? input.titleSelector ?? 'h3', fallback_selectors: fallbackList(fallbacks, 'title'), type: 'text', required: true, ...common },
    { name: 'url', selector: input.link_selector ?? input.linkSelector ?? 'a', fallback_selectors: fallbackList(fallbacks, 'link'), scope_self_fallback: true, type: 'href', required: true, normalize_url: bool(input.normalize_url ?? input.normalizeUrl, true), ...common },
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
    reason_code: ok ? null : `required_${extracted.requiredMissing[0] || 'field'}_missing`,
    statusMessage: ok ? 'Suchmaschinentreffer wurde gelesen.' : `Suchtreffer ohne Pflichtfelder: ${extracted.requiredMissing.join(', ')}.`,
    mode: 'legacy_scope',
    result: extracted.result,
    scope_variable: scopeName,
    output_variable: outputName,
    selectors_used: extracted.selectors,
    empty_fields: extracted.emptyFields,
    required_missing: extracted.requiredMissing,
    workflow_variables: context.workflow_variables,
  };
}

async function run(context = {}) {
  const input = context.input || {};
  const listItemSelector = text(input.list_item_selector ?? input.listItemSelector);
  return listItemSelector !== ''
    ? runListMode(context, input, listItemSelector)
    : runLegacyScopeMode(context, input);
}

module.exports = { key: 'browser.read_searchengine_result', run };
