'use strict';

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

module.exports = { parseExtendedSelector };
