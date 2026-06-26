'use strict';

async function run(context = {}) {
  const page = context.page;

  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Input-Suche vorhanden.' };
  }

  const inputs = await page.evaluate(() => {
    const visible = (element) => {
      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return style && style.visibility !== 'hidden' && style.display !== 'none' && rect.width > 0 && rect.height > 0;
    };

    const labelText = (element) => {
      if (element.id) {
        const explicit = document.querySelector(`label[for="${CSS.escape(element.id)}"]`);
        if (explicit) return explicit.textContent.trim();
      }

      const parentLabel = element.closest('label');
      return parentLabel ? parentLabel.textContent.trim() : '';
    };

    return Array.from(document.querySelectorAll('input, textarea, select'))
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
  });

  return {
    ok: inputs.length > 0,
    status: inputs.length > 0 ? 'success' : 'partial',
    statusMessage: inputs.length > 0 ? 'Input-Felder gefunden.' : 'Keine sichtbaren Input-Felder gefunden.',
    inputs,
  };
}

module.exports = { key: 'browser.find_inputs', run };
