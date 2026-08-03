'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const text = String(input.value ?? input.inputValue ?? '').slice(0, 500);

  if (!page?.keyboard || typeof page.keyboard.type !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Das Browserfenster kann keine manuelle Texteingabe empfangen.' };
  }

  if (text === '') {
    return { ok: false, status: 'failed', statusMessage: 'Fuer die manuelle Texteingabe wurde kein Text angegeben.' };
  }

  await page.keyboard.type(text, { delay: 25 });

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Der Text wurde in das fokussierte Browserfeld eingegeben.',
    typedCharacterCount: text.length,
  }, true);
}

module.exports = { key: 'browser.assistance_type_text', run };
