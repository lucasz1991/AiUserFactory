'use strict';

const { RecorderError } = require('./security.cjs');

function splitSelectorList(value) {
  const source = String(value || '').trim();
  if (!source || source.length > 4096) throw new RecorderError('invalid_selector', 'Bitte einen gueltigen Selektor angeben.');
  const parts = []; let current = ''; let quote = ''; let depth = 0; let escaped = false;
  for (const char of source) {
    if (escaped) { current += char; escaped = false; continue; }
    if (char === '\\') { current += char; escaped = true; continue; }
    if (quote) { current += char; if (char === quote) quote = ''; continue; }
    if (char === '"' || char === "'") { quote = char; current += char; continue; }
    if (char === '[' || char === '(') depth += 1;
    if (char === ']' || char === ')') depth -= 1;
    if (char === ',' && depth === 0) { parts.push(current.trim()); current = ''; } else current += char;
  }
  parts.push(current.trim());
  if (quote || depth !== 0 || parts.length > 8 || parts.some((part) => !part)) {
    throw new RecorderError('invalid_selector', 'Die Selektorliste ist ungueltig.');
  }
  return [...new Set(parts)];
}

// Self-contained for page.evaluate. Never reads field values or embeds them in selectors.
function inspectDom(input) {
  const fail = (code, message) => ({ error: { code, message } });
  const doc = document;
  const interactive = 'a,button,input,textarea,select,[role="button"],[role="link"],[contenteditable="true"],label,[tabindex]';
  let element;
  if (input.selectors?.length) {
    for (const selector of input.selectors) {
      let matches;
      try { matches = doc.querySelectorAll(selector); } catch { return fail('invalid_selector', 'Der Selektor ist syntaktisch ungueltig.'); }
      if (matches.length !== 1 || (element && element !== matches[0])) {
        return fail('ambiguous_selector', 'Alle Selektoren muessen eindeutig dasselbe Element treffen. Bitte das Feld erneut auswaehlen.');
      }
      element = matches[0];
    }
  } else {
    const hit = doc.elementFromPoint(input.x, input.y);
    if (!hit) return fail('missing_element', 'An dieser Position wurde kein Element gefunden.');
    if (hit.shadowRoot || hit.tagName?.toLowerCase() === 'iframe' || hit.tagName?.toLowerCase() === 'frame') {
      return fail('unsupported_scope', 'Frames und Shadow-DOM werden in dieser Aufnahme noch nicht unterstuetzt.');
    }
    element = hit.closest(interactive) || hit;
  }
  if (!element || element.getRootNode() !== doc || element.shadowRoot
    || ['iframe', 'frame'].includes(element.tagName.toLowerCase())) {
    return fail('unsupported_scope', 'Das Element liegt ausserhalb der unterstuetzten Hauptseite.');
  }
  const rect = element.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return fail('hidden_element', 'Das Element ist nicht sichtbar.');
  const tag = element.tagName.toLowerCase();
  const inputType = String(element.getAttribute('type') || (tag === 'input' ? 'text' : tag === 'textarea' ? 'textarea' : '')).toLowerCase();
  const rawLabel = element.getAttribute('aria-label') || element.labels?.[0]?.textContent
    || element.getAttribute('placeholder') || element.getAttribute('name') || (tag === 'input' ? '' : element.textContent) || tag;
  const sensitive = inputType === 'password'
    || /(?:password|passwd|secret|token|api.?key|one-time-code|\botp\b|\bpin\b|security.?code)/i.test([
      element.getAttribute('autocomplete'), element.getAttribute('name'), element.id, rawLabel,
    ].join(' '));
  const label = sensitive ? 'Sensibles Eingabefeld' : String(rawLabel).replace(/\s+/g, ' ').trim().slice(0, 120);
  const identifier = (value) => {
    if (globalThis.CSS?.escape) return CSS.escape(String(value));
    return [...String(value)].map((char, index) => /[A-Za-z_-]/.test(char) || (index > 0 && /[0-9]/.test(char))
      ? char : `\\${char.codePointAt(0).toString(16)} `).join('');
  };
  const quote = (value) => String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\n\r\f]/g, ' ');
  const candidates = []; const stableCandidates = [];
  const add = (selector, stable = false) => {
    if (selector.length > 1024 || candidates.includes(selector)) return;
    try {
      const matches = doc.querySelectorAll(selector);
      if (matches.length === 1 && matches[0] === element) {
        candidates.push(selector); if (stable) stableCandidates.push(selector);
      }
    } catch { /* An invalid candidate is not recorded. */ }
  };
  if (element.id) add(`#${identifier(element.id)}`, true);
  for (const attribute of ['data-testid', 'data-test', 'data-cy', 'name', 'aria-label', 'placeholder']) {
    const value = element.getAttribute(attribute);
    if (value && value.length <= 160 && !(sensitive && ['aria-label', 'placeholder'].includes(attribute))) {
      add(`${tag}[${attribute}="${quote(value)}"]`, true);
      if (inputType) add(`${tag}[${attribute}="${quote(value)}"][type="${quote(inputType)}"]`, true);
    }
  }
  // A bounded structural selector is a last alternative, never a false uniqueness promise.
  const path = []; let ancestor = element;
  for (let depth = 0; ancestor?.nodeType === 1 && depth < 8; depth += 1) {
    let part = ancestor.tagName.toLowerCase();
    if (ancestor.id) { path.unshift(`#${identifier(ancestor.id)}`); add(path.join(' > ')); break; }
    const siblings = [...(ancestor.parentElement?.children || [])].filter((item) => item.tagName === ancestor.tagName);
    if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(ancestor) + 1})`;
    path.unshift(part); add(path.join(' > ')); ancestor = ancestor.parentElement;
  }
  if (!candidates.length) return fail('missing_selector', 'Kein eindeutiger Selektor konnte belegt werden.');
  const selectors = candidates.slice(0, 5);
  const normalized = (value, length = 160) => String(value || '').replace(/\s+/g, ' ').trim().slice(0, length);
  const signature = { tag, type: inputType };
  for (const [key, value] of Object.entries({ name: element.getAttribute('name'), role: element.getAttribute('role'),
    test_id: element.getAttribute('data-testid') || element.getAttribute('data-test') || element.getAttribute('data-cy'),
    aria_label: sensitive ? '' : element.getAttribute('aria-label'),
    placeholder: sensitive ? '' : element.getAttribute('placeholder'),
    text: !sensitive && !['input', 'textarea', 'select'].includes(tag) && !element.isContentEditable ? element.textContent : '',
  })) {
    const normalizedValue = normalized(value, key === 'text' ? 120 : 160);
    if (normalizedValue) signature[key] = normalizedValue;
  }
  const stable = stableCandidates.filter((selector) => selectors.includes(selector));
  if (!stable.length && !['name', 'test_id', 'aria_label', 'placeholder', 'text'].some((key) => signature[key])) {
    return fail('missing_target_signature', 'Das Ziel hat keine sicheren wiedererkennbaren Merkmale. Bitte ein eindeutig bezeichnetes Element auswaehlen.');
  }
  return { selector: selectors.join(', '), selectors, stable_selectors: stable, target_signature: signature,
    label, input_type: inputType, tag, sensitive,
    editable: ((tag === 'input' && !['hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'image', 'file'].includes(inputType))
      || ['textarea', 'select'].includes(tag) || element.isContentEditable) && !element.disabled && !element.readOnly,
    interactive: element.matches(interactive), scope: 'main', x: input.x ?? Math.max(0, rect.x + rect.width / 2),
    y: input.y ?? Math.max(0, rect.y + rect.height / 2) };
}

async function inspectPage(page, input) {
  const request = input.selector ? { ...input, selectors: splitSelectorList(input.selector) } : input;
  const result = await page.evaluate(inspectDom, request);
  if (result?.error) throw new RecorderError(result.error.code, result.error.message);
  if (!result?.selector || !Array.isArray(result.selectors)) {
    throw new RecorderError('missing_element', 'Das Element konnte nicht sicher ermittelt werden.');
  }
  return result;
}

module.exports = { inspectDom, inspectPage, splitSelectorList };
