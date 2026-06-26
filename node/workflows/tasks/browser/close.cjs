'use strict';

const { captureTaskPreview, stopTaskPreview } = require('../lib/preview.cjs');

async function run(context = {}) {
  const page = context.page || null;
  const contextHandle = context.context || null;
  const browser = context.browser || null;

  if (page && typeof page.close === 'function') {
    const result = await captureTaskPreview(context, { ok: true, status: 'success', statusMessage: 'Browser-Seite wurde geschlossen.' }, true);
    await page.close({ runBeforeUnload: false }).catch(() => {});
    stopTaskPreview(context);

    return result;
  }

  if (contextHandle && typeof contextHandle.close === 'function') {
    const result = await captureTaskPreview(context, { ok: true, status: 'success', statusMessage: 'Browser-Kontext wurde geschlossen.' }, true);
    await contextHandle.close().catch(() => {});
    stopTaskPreview(context);

    return result;
  }

  if (browser && typeof browser.close === 'function') {
    const result = await captureTaskPreview(context, { ok: true, status: 'success', statusMessage: 'Browser wurde geschlossen.' }, true);
    await browser.close().catch(() => {});
    stopTaskPreview(context);

    return result;
  }

  stopTaskPreview(context);

  return { ok: true, status: 'success', statusMessage: 'Kein Browser-Handle zum Schliessen vorhanden.' };
}

module.exports = { key: 'browser.close', run };
