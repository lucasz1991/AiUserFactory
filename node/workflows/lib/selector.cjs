'use strict';

const HTML_TAG_NAMES = new Set([
  'a', 'article', 'aside', 'button', 'details', 'dialog', 'div', 'fieldset', 'footer',
  'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'iframe', 'img', 'input',
  'label', 'legend', 'li', 'main', 'nav', 'option', 'p', 'section', 'select', 'slot',
  'span', 'summary', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'tr',
  'ul', 'video', 'svg', 'path', 'g', 'rect', 'circle', 'use', 'webmailer-mail-list',
]);

function splitTopLevelSelectorList(value) {
  const input = String(value ?? '').trim();

  if (input === '') {
    return [];
  }

  const entries = [];
  let current = '';
  let quote = '';
  let escaped = false;
  let parentheses = 0;
  let brackets = 0;

  for (const character of input) {
    if (escaped) {
      current += character;
      escaped = false;
      continue;
    }

    if (character === '\\') {
      current += character;
      escaped = true;
      continue;
    }

    if (quote !== '') {
      current += character;

      if (character === quote) {
        quote = '';
      }

      continue;
    }

    if (character === '"' || character === "'") {
      current += character;
      quote = character;
      continue;
    }

    if (character === '(') parentheses += 1;
    if (character === ')') parentheses = Math.max(0, parentheses - 1);
    if (character === '[') brackets += 1;
    if (character === ']') brackets = Math.max(0, brackets - 1);

    if (character === ',' && parentheses === 0 && brackets === 0) {
      if (current.trim() !== '') {
        entries.push(current.trim());
      }

      current = '';
      continue;
    }

    current += character;
  }

  if (current.trim() !== '') {
    entries.push(current.trim());
  }

  return entries;
}

function looksLikeCssSelector(value) {
  const candidate = String(value || '').trim();

  if (candidate === '') {
    return false;
  }

  if (/^(css|selector)\s*=/i.test(candidate)) {
    return true;
  }

  if (/^[#.\[*:>+~]/.test(candidate) || /[\[\]#>:~+]/.test(candidate)) {
    return true;
  }

  const firstToken = candidate.match(/^([a-z][a-z0-9-]*)/i)?.[1]?.toLowerCase() || '';

  return HTML_TAG_NAMES.has(firstToken);
}

function normalizeElementCandidates(values, options = {}) {
  const defaultKind = options.defaultKind || 'auto';
  const candidates = [];
  const seen = new Set();

  const add = (rawValue, forcedKind = defaultKind) => {
    if (rawValue && typeof rawValue === 'object' && !Array.isArray(rawValue)) {
      const kind = rawValue.kind === 'text' ? 'text' : 'selector';
      const value = String(rawValue.value ?? rawValue.selector ?? rawValue.text ?? '').trim();

      if (value !== '') {
        const key = `${kind}:${value.toLowerCase()}:${rawValue.exact === true}`;

        if (!seen.has(key)) {
          seen.add(key);
          candidates.push({ kind, value, exact: rawValue.exact === true });
        }
      }

      return;
    }

    for (let entry of splitTopLevelSelectorList(rawValue)) {
      let kind = forcedKind;
      let exact = false;
      const explicitText = entry.match(/^(text|has-text|text-is)\s*=\s*(.+)$/i);
      const explicitSelector = entry.match(/^(css|selector)\s*=\s*(.+)$/i);

      if (explicitText) {
        kind = 'text';
        exact = explicitText[1].toLowerCase() === 'text-is';
        entry = String(explicitText[2] || '').replace(/^("|')|("|')$/g, '').trim();
      } else if (explicitSelector) {
        kind = 'selector';
        entry = String(explicitSelector[2] || '').trim();
      } else if (kind === 'auto') {
        kind = looksLikeCssSelector(entry) ? 'selector' : 'text';
      }

      kind = kind === 'text' ? 'text' : 'selector';

      if (entry === '') {
        continue;
      }

      const key = `${kind}:${entry.toLowerCase()}:${exact}`;

      if (seen.has(key)) {
        continue;
      }

      seen.add(key);
      candidates.push({ kind, value: entry, exact });
    }
  };

  for (const value of [].concat(values || []).flat(Infinity)) {
    add(value);
  }

  return candidates;
}

function parseExtendedSelector(selector) {
  const value = String(selector || '').trim();
  const nestedMatch = value.match(/^(.*?):has\(\s*(.*?):(has-text|text-is)\(\s*(["'])(.*?)\4\s*\)\s*\)$/i);

  if (nestedMatch) {
    return {
      css: String(nestedMatch[1] || '').trim() || '*',
      descendantCss: String(nestedMatch[2] || '').trim() || '*',
      text: nestedMatch[5],
      exact: nestedMatch[3].toLowerCase() === 'text-is',
    };
  }

  const match = value.match(/^(.*?)(?::(has-text|text-is)\(\s*(["'])(.*?)\3\s*\))$/i);

  if (!match) {
    return null;
  }

  const css = String(match[1] || '').trim() || '*';

  return {
    css,
    text: match[4],
    exact: match[2].toLowerCase() === 'text-is',
  };
}

module.exports = {
  looksLikeCssSelector,
  normalizeElementCandidates,
  parseExtendedSelector,
  splitTopLevelSelectorList,
};
