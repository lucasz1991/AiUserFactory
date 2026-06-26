'use strict';

async function run(context = {}) {
  const page = context.page || null;
  const browser = context.browser || null;

  if (page) {
    return { ok: true, status: 'success', statusMessage: 'Bestehende Browser-Seite uebernommen.' };
  }

  if (browser && typeof browser.newPage === 'function') {
    const nextPage = await browser.newPage();
    return { ok: true, status: 'success', statusMessage: 'Neue Browser-Seite geoeffnet.', page: nextPage };
  }

  return {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein Browser- oder Page-Handle im Task-Kontext vorhanden.',
  };
}

module.exports = { key: 'browser.open', run };
