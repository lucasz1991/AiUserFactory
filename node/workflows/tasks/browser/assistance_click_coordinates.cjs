'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

function ratio(value) {
  const parsed = Number(value);

  return Number.isFinite(parsed) && parsed >= 0 && parsed <= 1 ? parsed : null;
}

async function viewportFor(page) {
  if (typeof page?.viewport === 'function') {
    const viewport = page.viewport();
    if (Number(viewport?.width) > 0 && Number(viewport?.height) > 0) {
      return { width: Number(viewport.width), height: Number(viewport.height) };
    }
  }

  return page.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const xRatio = ratio(input.x_ratio ?? input.xRatio);
  const yRatio = ratio(input.y_ratio ?? input.yRatio);

  if (!page || !page.mouse || typeof page.mouse.click !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Das Browserfenster kann keinen manuellen Klick empfangen.' };
  }

  if (xRatio === null || yRatio === null) {
    return { ok: false, status: 'failed', statusMessage: 'Die Klickposition liegt ausserhalb des Browserbildes.' };
  }

  const viewport = await viewportFor(page);
  const x = Math.max(0, Math.min(viewport.width - 1, xRatio * viewport.width));
  const y = Math.max(0, Math.min(viewport.height - 1, yRatio * viewport.height));

  await page.mouse.click(x, y);
  await new Promise((resolve) => setTimeout(resolve, 450));

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Der manuelle Browserklick wurde ausgefuehrt.',
    clickedAt: { x: Math.round(x), y: Math.round(y) },
    clickedRatio: { x: xRatio, y: yRatio },
    viewport,
  }, true);
}

module.exports = { key: 'browser.assistance_click_coordinates', run };
