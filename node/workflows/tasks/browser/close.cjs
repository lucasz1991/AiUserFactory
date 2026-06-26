'use strict';

async function run(context = {}) {
  const page = context.page || null;
  const contextHandle = context.context || null;
  const browser = context.browser || null;

  if (page && typeof page.close === 'function') {
    await page.close({ runBeforeUnload: false }).catch(() => {});
    return { ok: true, status: 'success', statusMessage: 'Browser-Seite wurde geschlossen.' };
  }

  if (contextHandle && typeof contextHandle.close === 'function') {
    await contextHandle.close().catch(() => {});
    return { ok: true, status: 'success', statusMessage: 'Browser-Kontext wurde geschlossen.' };
  }

  if (browser && typeof browser.close === 'function') {
    await browser.close().catch(() => {});
    return { ok: true, status: 'success', statusMessage: 'Browser wurde geschlossen.' };
  }

  return { ok: true, status: 'success', statusMessage: 'Kein Browser-Handle zum Schliessen vorhanden.' };
}

module.exports = { key: 'browser.close', run };
