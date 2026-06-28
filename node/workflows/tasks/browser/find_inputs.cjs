'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { framesForPage } = require('../lib/find_visible_element.cjs');

async function run(context = {}) {
  const page = context.page;

  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Input-Suche vorhanden.' };
  }

  const inputs = [];

  for (const frame of framesForPage(page)) {
    const frameInputs = await frame.evaluate(() => {
      const visible = (element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style && style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0;
      };
      const deepQueryAll = (selector, root = document) => {
        const matches = [];

        try {
          matches.push(...Array.from(root.querySelectorAll(selector)));
        } catch {
          return matches;
        }

        Array.from(root.querySelectorAll('*')).forEach((element) => {
          if (element.shadowRoot) {
            matches.push(...deepQueryAll(selector, element.shadowRoot));
          }
        });

        return Array.from(new Set(matches));
      };

      const labelText = (element) => {
        if (element.id) {
          const explicit = document.querySelector(`label[for="${CSS.escape(element.id)}"]`);
          if (explicit) return explicit.textContent.trim();
        }

        const parentLabel = element.closest('label');
        return parentLabel ? parentLabel.textContent.trim() : '';
      };

      return deepQueryAll('input, textarea, select')
        .filter(visible)
        .map((element, index) => ({
          index,
          tag: element.tagName.toLowerCase(),
          type: element.getAttribute('type') || '',
          name: element.getAttribute('name') || '',
          id: element.id || '',
          placeholder: element.getAttribute('placeholder') || '',
          autocomplete: element.getAttribute('autocomplete') || '',
          ariaLabel: element.getAttribute('aria-label') || '',
          label: labelText(element),
          selector: element.id
            ? `#${CSS.escape(element.id)}`
            : (element.getAttribute('name') ? `${element.tagName.toLowerCase()}[name="${CSS.escape(element.getAttribute('name'))}"]` : `${element.tagName.toLowerCase()}:nth-of-type(${index + 1})`),
        }));
    }).catch(() => []);
    const frameUrl = typeof frame.url === 'function' ? frame.url() : '';

    inputs.push(...frameInputs.map((input) => ({ ...input, frameUrl })));
  }

  return captureTaskPreview(context, {
    ok: inputs.length > 0,
    status: inputs.length > 0 ? 'success' : 'partial',
    statusMessage: inputs.length > 0 ? 'Input-Felder gefunden.' : 'Keine sichtbaren Input-Felder gefunden.',
    inputs,
  });
}

module.exports = { key: 'browser.find_inputs', run };
