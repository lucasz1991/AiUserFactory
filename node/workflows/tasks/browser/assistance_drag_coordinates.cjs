'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');

// Interner Admin-Probe-Task: zieht mit gedrueckter Maustaste von einer
// normalisierten Position zu einer anderen (z. B. ein Puzzleteil in einem
// Verifizierungsdialog). Die Bewegung wird vom Administrator im Browserbild
// vorgegeben; der Task trifft keine eigene Entscheidung ueber das Ziel.

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

function point(viewport, xRatio, yRatio) {
  return {
    x: Math.max(0, Math.min(viewport.width - 1, xRatio * viewport.width)),
    y: Math.max(0, Math.min(viewport.height - 1, yRatio * viewport.height)),
  };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const fromX = ratio(input.from_x_ratio ?? input.fromXRatio);
  const fromY = ratio(input.from_y_ratio ?? input.fromYRatio);
  const toX = ratio(input.to_x_ratio ?? input.toXRatio);
  const toY = ratio(input.to_y_ratio ?? input.toYRatio);
  const mouse = page?.mouse;

  if (!mouse || typeof mouse.move !== 'function' || typeof mouse.down !== 'function' || typeof mouse.up !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Das Browserfenster kann keine manuelle Ziehbewegung empfangen.' };
  }

  if ([fromX, fromY, toX, toY].some((value) => value === null)) {
    return { ok: false, status: 'failed', statusMessage: 'Start oder Ziel der Ziehbewegung liegt ausserhalb des Browserbildes.' };
  }

  const viewport = await viewportFor(page);
  const from = point(viewport, fromX, fromY);
  const to = point(viewport, toX, toY);
  const distance = Math.hypot(to.x - from.x, to.y - from.y);
  const steps = Math.max(12, Math.min(60, Math.round(distance / 6)));

  await mouse.move(from.x, from.y);
  await sleep(120);
  await mouse.down();
  await sleep(150);

  try {
    for (let step = 1; step <= steps; step += 1) {
      const progress = step / steps;
      // Leicht abgebremste Bewegung, damit Drag-Handler jeden Zwischenstand sehen.
      const eased = 1 - ((1 - progress) * (1 - progress));
      await mouse.move(from.x + ((to.x - from.x) * eased), from.y + ((to.y - from.y) * eased));
      await sleep(16);
    }
    await sleep(150);
  } finally {
    await mouse.up();
  }

  await sleep(450);

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Die manuelle Ziehbewegung wurde ausgefuehrt.',
    draggedFrom: { x: Math.round(from.x), y: Math.round(from.y) },
    draggedTo: { x: Math.round(to.x), y: Math.round(to.y) },
    draggedRatio: { from: { x: fromX, y: fromY }, to: { x: toX, y: toY } },
    viewport,
  }, true);
}

module.exports = { key: 'browser.assistance_drag_coordinates', run };
