'use strict';

const {
  bool,
  number,
  queryElements,
  text,
} = require('../lib/collection.cjs');

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, milliseconds)));
}

async function pageSnapshot(page) {
  return page.evaluate(() => ({
    scrollY: Number(globalThis.scrollY || document.documentElement?.scrollTop || 0),
    scrollHeight: Number(document.documentElement?.scrollHeight || document.body?.scrollHeight || 0),
    contentLength: Number(document.body?.innerText?.length || 0),
  })).catch(() => ({ scrollY: 0, scrollHeight: 0, contentLength: 0 }));
}

async function containerSnapshot(container) {
  return container.evaluate((element) => ({
    scrollY: Number(element.scrollTop || 0),
    scrollHeight: Number(element.scrollHeight || 0),
    contentLength: Number(element.innerText?.length || element.textContent?.length || 0),
  })).catch(() => ({ scrollY: 0, scrollHeight: 0, contentLength: 0 }));
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer browser.scroll vorhanden.' };
  }

  const selector = text(input.selector);
  const direction = text(input.direction || 'down').toLowerCase() === 'up' ? 'up' : 'down';
  const pixels = number(input.pixels, 600, 1, 100000) * (direction === 'up' ? -1 : 1);
  const requestedSteps = Math.floor(number(input.steps, 1, 1, 1000));
  const maxRounds = Math.floor(number(input.max_rounds ?? input.maxRounds, requestedSteps, 1, 1000));
  const rounds = Math.min(requestedSteps, maxRounds);
  const delay = number(input.delay_ms_between_steps ?? input.delayMsBetweenSteps, 250, 0, 60000);
  const untilSelector = text(input.until_selector ?? input.untilSelector);
  const stopIfNoChange = bool(input.stop_if_no_change ?? input.stopIfNoChange, true);
  let container = null;

  if (selector !== '') {
    const match = await queryElements(page, selector, true);
    container = match.elements[0] || null;
    if (!container) {
      return { ok: false, status: 'failed', statusMessage: `Scroll-Container nicht gefunden: ${selector}`, selector_used: selector };
    }
  }

  const initial = container ? await containerSnapshot(container) : await pageSnapshot(page);
  let previous = initial;
  let final = initial;
  let scrollRounds = 0;
  let contentChanged = false;
  let untilSelectorFound = false;

  for (let round = 0; round < rounds; round += 1) {
    if (container) {
      await container.evaluate((element, amount) => element.scrollBy({ top: amount, behavior: 'auto' }), pixels);
    } else {
      await page.evaluate((amount) => globalThis.scrollBy({ top: amount, behavior: 'auto' }), pixels);
    }
    scrollRounds += 1;
    if (delay > 0) await sleep(delay);
    final = container ? await containerSnapshot(container) : await pageSnapshot(page);
    const changed = final.scrollY !== previous.scrollY
      || final.scrollHeight !== previous.scrollHeight
      || final.contentLength !== previous.contentLength;
    contentChanged = contentChanged || final.scrollHeight !== initial.scrollHeight || final.contentLength !== initial.contentLength;

    if (untilSelector !== '') {
      untilSelectorFound = (await queryElements(page, untilSelector, true)).elements.length > 0;
      if (untilSelectorFound) break;
    }
    if (stopIfNoChange && !changed) break;
    previous = final;
  }

  return {
    ok: true,
    status: 'success',
    statusMessage: `Scrollen abgeschlossen: ${scrollRounds} Runde(n).`,
    selector_used: selector || 'window',
    scroll_rounds: scrollRounds,
    final_scroll_y: final.scrollY,
    content_changed: contentChanged,
    until_selector_found: untilSelectorFound,
  };
}

module.exports = { key: 'browser.scroll', run };
