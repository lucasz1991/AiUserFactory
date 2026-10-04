'use strict';

const { splitTopLevelSelectorList } = require('../../lib/selector.cjs');
const { moveCursorTo, moveCursorToHandle, setPagePosition, targetForBox } = require('./cursor.cjs');

class RecordedTargetError extends Error {
  constructor(code, message) { super(message); this.name = 'RecordedTargetError'; this.code = code; }
}

function recordedSelectors(input = {}) {
  const selectors = [...new Set([].concat(input.selector || [], input.selectors || [])
    .flatMap((value) => splitTopLevelSelectorList(String(value || ''))).map((value) => value.trim()).filter(Boolean))];
  if (!selectors.length || selectors.length > 8 || selectors.some((value) => value.length > 1024 || /^(?:text|xpath|has-text|text-is)=/i.test(value))) {
    throw new RecordedTargetError('recorded_selector_invalid', 'Die Aufnahme benoetigt gueltige native CSS-Alternativen.');
  }
  return selectors;
}

// Main-document CSS only. Missing/ambiguous alternatives are skipped, conflicting unique targets fail.
function resolveRecordedDom(selectors, expected = null, policy = null, returnElement = false) {
  const visible = (element) => {
    if (element.getRootNode() !== document || element.shadowRoot || element.closest?.('[hidden],[aria-hidden="true"],[inert]')) return false;
    const rect = element.getBoundingClientRect(); const style = window.getComputedStyle(element);
    return rect.width > 0 && rect.height > 0 && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
  };
  let target = null; let winning = '';
  const eligible = policy?.stableSelectors?.length ? selectors.filter((selector) => policy.stableSelectors.includes(selector)) : selectors;
  for (const selector of eligible) {
    let elements;
    try { elements = [...document.querySelectorAll(selector)].filter(visible); } catch { continue; }
    if (elements.length !== 1) continue;
    if (target && target !== elements[0]) return returnElement ? null : { conflict: true };
    target = elements[0]; if (!winning) winning = selector;
  }
  if (!target) return returnElement ? null : { missing: true };
  if (expected && target !== expected) return returnElement ? null : { changed: true };
  const tag = target.tagName.toLowerCase();
  const type = String(target.getAttribute('type') || (tag === 'input' ? 'text' : tag === 'textarea' ? 'textarea' : '')).toLowerCase();
  if (['iframe', 'frame'].includes(tag)) return returnElement ? null : { missing: true };
  if (policy?.signature) {
    const normalized = (value, length = 160) => String(value || '').replace(/\s+/g, ' ').trim().slice(0, length);
    const actual = { tag, type, name: normalized(target.getAttribute('name')), role: normalized(target.getAttribute('role')),
      test_id: normalized(target.getAttribute('data-testid') || target.getAttribute('data-test') || target.getAttribute('data-cy')),
      aria_label: normalized(target.getAttribute('aria-label')), placeholder: normalized(target.getAttribute('placeholder')),
      text: normalized(target.textContent, 120) };
    if (Object.entries(policy.signature).some(([key, value]) => actual[key] !== value)) {
      return returnElement ? null : { signatureMismatch: true };
    }
  }
  if (returnElement) return target;
  return { selector: winning, tag, type, editable: ((tag === 'input' && !['hidden', 'checkbox', 'radio', 'button', 'submit', 'reset', 'image', 'file'].includes(type))
    || ['textarea', 'select'].includes(tag) || target.isContentEditable) && !target.disabled && !target.readOnly };
}

function recordedPolicy(input, selectors) {
  const signature = input?.recorded_target_signature;
  const keys = ['tag', 'type', 'name', 'role', 'test_id', 'aria_label', 'placeholder', 'text'];
  if (!signature || typeof signature !== 'object' || Array.isArray(signature)
    || !/^[a-z][a-z0-9-]{0,29}$/.test(String(signature.tag || ''))
    || typeof signature.type !== 'string'
    || Object.entries(signature).some(([key, value]) => !keys.includes(key) || typeof value !== 'string' || value.length > (key === 'text' ? 120 : 160))) {
    throw new RecordedTargetError('recorded_signature_invalid', 'Das aufgenommene Ziel benoetigt eine gueltige Merkmalpruefung.');
  }
  const stableSelectors = input.recorded_stable_selectors;
  if (!Array.isArray(stableSelectors) || stableSelectors.length > 5 || stableSelectors.some((selector) => !selectors.includes(selector))) {
    throw new RecordedTargetError('recorded_signature_invalid', 'Die stabilen CSS-Merkmale der Aufnahme sind ungueltig.');
  }
  if (!stableSelectors.length && !['name', 'test_id', 'aria_label', 'placeholder', 'text'].some((key) => signature[key])) {
    throw new RecordedTargetError('recorded_signature_invalid', 'Ein reiner Strukturpfad ohne sichere Zielmerkmale darf nicht ausgefuehrt werden.');
  }
  return { signature, stableSelectors };
}

async function recheckRecordedTarget(page, found) {
  const result = await page.evaluate(resolveRecordedDom, found.selectors, found.handle, found.policy);
  if (result?.conflict) throw new RecordedTargetError('recorded_selector_conflict', 'Die aufgenommenen Selektoren zeigen auf unterschiedliche Ziele.');
  if (result?.signatureMismatch) throw new RecordedTargetError('recorded_signature_changed', 'Die Merkmale des aufgenommenen Ziels haben sich geaendert.');
  if (result?.changed || result?.missing || !result?.selector) {
    throw new RecordedTargetError('recorded_target_changed', 'Das aufgenommene Ziel hat sich vor der Aktion geaendert.');
  }
  return result;
}

async function resolveRecordedTarget(page, input, timeoutMs = 10000, options = {}) {
  if (!page || typeof page.evaluate !== 'function' || typeof page.evaluateHandle !== 'function') {
    throw new RecordedTargetError('recorded_page_missing', 'Die aufgenommene Hauptseite ist nicht verfuegbar.');
  }
  const selectors = recordedSelectors(input);
  const policy = recordedPolicy(input, selectors);
  const deadline = Date.now() + Math.max(0, Math.min(30000, Number(timeoutMs) || 0));
  do {
    const result = await page.evaluate(resolveRecordedDom, selectors, null, policy);
    if (result?.conflict) throw new RecordedTargetError('recorded_selector_conflict', 'Die aufgenommenen Selektoren zeigen auf unterschiedliche Ziele.');
    if (result?.signatureMismatch) throw new RecordedTargetError('recorded_signature_changed', 'Die Merkmale des aufgenommenen Ziels haben sich geaendert.');
    if (result?.selector && (!options.editable || result.editable)) {
      const candidate = await page.evaluateHandle(resolveRecordedDom, selectors, null, policy, true);
      const handle = candidate.asElement();
      if (handle) {
        const found = { ...result, handle, selectors, policy };
        try { await recheckRecordedTarget(page, found); return found; }
        catch (error) { await handle.dispose?.().catch(() => {}); throw error; }
      } else await candidate.dispose?.().catch(() => {});
    }
    if (Date.now() >= deadline) break;
    await new Promise((resolve) => setTimeout(resolve, Math.min(100, Math.max(0, deadline - Date.now()))));
  } while (Date.now() <= deadline);
  throw new RecordedTargetError('recorded_target_missing', 'Keine eindeutige sichtbare CSS-Alternative der Aufnahme wurde gefunden.');
}

function normalizedRecordedPath(value) {
  if (value === undefined || value === null) return [];
  if (!Array.isArray(value) || value.length > 32) throw new RecordedTargetError('recorded_path_invalid', 'Der aufgenommene Mausweg ist ungueltig.');
  let previous = 0;
  const result = value.map((point, index) => {
    const x = Number(point?.x); const y = Number(point?.y); const ms = Number(point?.ms ?? index * 15);
    if (!Number.isFinite(x) || !Number.isFinite(y) || x < 0 || x > 1 || y < 0 || y > 1
      || !Number.isFinite(ms) || ms < previous || ms < 0 || ms > 10000) {
      throw new RecordedTargetError('recorded_path_invalid', 'Der aufgenommene Mausweg liegt ausserhalb der erlaubten Grenzen.');
    }
    previous = ms; return { x, y, ms };
  });
  const total = result.at(-1)?.ms || 0;
  return result.map((point) => ({ ...point, ms: Math.round(point.ms * (total > 2000 ? 2000 / total : 1)) }));
}

async function replayRecordedPath(page, path, { wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)) } = {}) {
  const points = normalizedRecordedPath(path);
  if (!points.length) return 0;
  if (!page?.mouse?.move) throw new RecordedTargetError('recorded_mouse_missing', 'Der Mausweg kann nicht wiedergegeben werden.');
  const viewport = typeof page.viewport === 'function' ? page.viewport() : null;
  if (!(Number(viewport?.width) > 0) || !(Number(viewport?.height) > 0)) {
    throw new RecordedTargetError('recorded_viewport_missing', 'Die Browsergroesse ist fuer die Mausaufnahme nicht verfuegbar.');
  }
  let previous = 0;
  for (const point of points) {
    if (point.ms > previous) await wait(point.ms - previous);
    const x = Math.min(viewport.width - 1, point.x * viewport.width);
    const y = Math.min(viewport.height - 1, point.y * viewport.height);
    await page.mouse.move(x, y, { steps: 1 }); setPagePosition(page, { x, y }); previous = point.ms;
  }
  return points.length;
}

function failedResult(error) {
  return { ok: false, status: 'failed', statusMessage: error instanceof RecordedTargetError ? error.message : 'Das aufgenommene Ziel konnte nicht sicher bedient werden.',
    ...(error instanceof RecordedTargetError ? { recordedTargetError: error.code } : {}) };
}

async function runRecordedClick(context, timeoutMs) {
  let found;
  try {
    found = await resolveRecordedTarget(context.page, context.input, timeoutMs);
    await found.handle.evaluate((element) => element.scrollIntoView?.({ block: 'center', inline: 'center', behavior: 'auto' }));
    await recheckRecordedTarget(context.page, found);
    const box = await found.handle.boundingBox();
    const viewport = context.page.viewport();
    if (!box || !viewport || !context.page.mouse?.down || !context.page.mouse?.up) {
      throw new RecordedTargetError('recorded_target_not_clickable', 'Das aufgenommene Ziel ist nicht sicher klickbar.');
    }
    const clicked = await moveCursorTo(context.page, box, { action: 'click', context });
    if (!clicked.handled) throw new RecordedTargetError('recorded_target_not_clickable', 'Das aufgenommene Ziel ist nicht sicher klickbar.');
    // Hover can change the DOM while the mouse moves. Recheck identity and occlusion before mouse-down.
    await recheckRecordedTarget(context.page, found);
    const reachable = await found.handle.evaluate((element, position) => {
      const hit = document.elementFromPoint(position.x, position.y);
      return hit === element || element.contains(hit);
    }, targetForBox(box, viewport));
    if (!reachable) throw new RecordedTargetError('recorded_target_covered', 'Das aufgenommene Klickziel wird von einem anderen Element verdeckt.');
    let pressed = false;
    try { await context.page.mouse.down({ button: 'left' }); pressed = true; }
    finally { if (pressed) await context.page.mouse.up({ button: 'left' }); }
    if (clicked.cursor) {
      clicked.cursor = { ...clicked.cursor, clicked: true, clickedAt: new Date().toISOString() };
      context.__workflowCursor = clicked.cursor;
      if (context.__workflowCursorByWindow) context.__workflowCursorByWindow[clicked.cursor.window] = clicked.cursor;
    }
    if (context.__workflowHeldHover?.recordedStrict) {
      await context.__workflowHeldHover.handle?.dispose?.().catch(() => {}); context.__workflowHeldHover = null;
    }
    return { ok: true, status: 'success', statusMessage: 'Aufgenommenes Ziel wurde eindeutig geklickt.', selector: found.selector,
      matchedBy: 'selector', recordedSelectorStrict: true, ...(clicked.cursor ? { cursor: clicked.cursor } : {}) };
  } catch (error) { return failedResult(error); }
  finally { await found?.handle?.dispose?.().catch(() => {}); }
}

async function runRecordedHover(context, timeoutMs, options = {}) {
  let found; let keep = false;
  try {
    const path = normalizedRecordedPath(context.input?.recorded_mouse_path);
    found = await resolveRecordedTarget(context.page, context.input, timeoutMs);
    await recheckRecordedTarget(context.page, found);
    const replayedPoints = await replayRecordedPath(context.page, path, options);
    await recheckRecordedTarget(context.page, found);
    const moved = await moveCursorToHandle(context.page, found.handle, { action: 'hover', context });
    if (!moved.handled) throw new RecordedTargetError('recorded_target_not_hoverable', 'Das aufgenommene Ziel kann nicht sicher erreicht werden.');
    await recheckRecordedTarget(context.page, found);
    if (context.__workflowHeldHover?.recordedStrict) await context.__workflowHeldHover.handle?.dispose?.().catch(() => {});
    context.__workflowHeldHover = { page: context.page, handle: found.handle, selector: found.selector, recordedStrict: true,
      browserWindow: String(context.activeBrowserWindow || context.browserWindow || 'main'), releaseAfterClick: true, heldAt: Date.now() };
    keep = true;
    return { ok: true, status: 'success', statusMessage: 'Aufgenommener Mausweg und eindeutiges Hover-Ziel wurden wiedergegeben.',
      selector: found.selector, matchedBy: 'selector', recordedSelectorStrict: true, replayedMousePoints: replayedPoints,
      hoverHeld: true, ...(moved.cursor ? { cursor: moved.cursor } : {}) };
  } catch (error) { return failedResult(error); }
  finally { if (!keep) await found?.handle?.dispose?.().catch(() => {}); }
}

async function runRecordedFill(context, value, timeoutMs, options = {}) {
  let found;
  try {
    found = await resolveRecordedTarget(context.page, context.input, timeoutMs, { editable: true });
    await recheckRecordedTarget(context.page, found);
    if (found.tag === 'select') {
      const selected = await found.handle.select(String(value));
      if (!selected.includes(String(value))) throw new RecordedTargetError('recorded_option_missing', 'Die aufgenommene Auswahloption wurde nicht gefunden.');
    } else {
      await found.handle.focus();
      await recheckRecordedTarget(context.page, found);
      await found.handle.evaluate((element) => {
        if (element.isContentEditable) element.textContent = '';
        else {
          const prototype = element.tagName.toLowerCase() === 'textarea' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
          const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
          if (setter) setter.call(element, ''); else element.value = '';
        }
        element.dispatchEvent(new Event('input', { bubbles: true }));
      });
      if (String(value)) await found.handle.type(String(value), { delay: Math.max(0, Math.min(20, Number(options.typeDelayMs ?? 20))) });
      await found.handle.evaluate((element) => element.dispatchEvent(new Event('change', { bubbles: true })));
      const matches = await found.handle.evaluate((element, expected) => String(element.isContentEditable ? element.textContent : element.value) === expected, String(value));
      if (!matches) throw new RecordedTargetError('recorded_fill_unconfirmed', 'Der eingegebene Wert konnte im eindeutigen Ziel nicht bestaetigt werden.');
    }
    return { ok: true, status: 'success', statusMessage: 'Aufgenommenes Eingabefeld wurde eindeutig gefuellt.', selector: found.selector,
      recordedSelectorStrict: true, valueSource: context.input.valueSource || context.input.value_source || 'legacy_auto',
      workflowVariable: context.input.workflowVariable || context.input.workflow_variable || null };
  } catch (error) { return failedResult(error); }
  finally { await found?.handle?.dispose?.().catch(() => {}); }
}

module.exports = { RecordedTargetError, normalizedRecordedPath, recordedPolicy, recordedSelectors, recheckRecordedTarget,
  replayRecordedPath, resolveRecordedDom, resolveRecordedTarget, runRecordedClick, runRecordedFill, runRecordedHover };
