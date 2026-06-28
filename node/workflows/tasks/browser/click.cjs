'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const {
  clickVisibleElement,
  clickVisibleElementByText,
  selectorDiagnostics,
} = require('../lib/find_visible_element.cjs');

function firstNonEmpty(...values) {
  for (const value of values) {
    const normalized = String(value ?? '').trim();

    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

async function clickSelector(page, selector, timeout) {
  return clickVisibleElement(page, selector, timeout);
}

async function clickText(page, text, timeout) {
  return clickVisibleElementByText(page, text, timeout);
}

function diagnosticMessage(selector, diagnostics = {}) {
  const candidates = Array.isArray(diagnostics.candidates) ? diagnostics.candidates : [];
  const candidateLabels = candidates
    .map((candidate) => String(candidate.text || candidate.ariaLabel || '').trim())
    .filter(Boolean)
    .filter((label, index, labels) => labels.indexOf(label) === index)
    .slice(0, 6);
  const details = candidateLabels.length > 0
    ? ` Sichtbare ${diagnostics.candidateSelector || 'Kandidaten'}: ${candidateLabels.map((label) => `"${label}"`).join(', ')}.`
    : ` Keine sichtbaren Kandidaten in ${Number(diagnostics.frameCount || 0)} Frames.`;

  return `Element wurde im aktuellen Live-DOM nicht gefunden: ${selector}.${details}`;
}

async function selectorFailure(page, selector, error = null) {
  const diagnostics = await selectorDiagnostics(page, selector).catch((diagnosticError) => ({
    selector,
    diagnosticError: diagnosticError.message,
  }));

  return {
    ok: false,
    status: 'failed',
    statusMessage: diagnosticMessage(selector, diagnostics),
    selector,
    selectorDiagnostics: diagnostics,
    ...(error ? { error: error.message } : {}),
  };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 60000);
  const selector = firstNonEmpty(input.elementSelector, input.element_selector, input.selector);
  const text = firstNonEmpty(input.text, input.label, input.value);

  if (!page || (typeof page.frames !== 'function' && typeof page.mainFrame !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Klick-Task vorhanden.' };
  }

  if (selector !== '') {
    try {
      const clicked = await clickSelector(page, selector, timeout);

      if (clicked) {
        return captureTaskPreview(context, {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde geklickt.',
          selector,
          element: clicked,
          url: typeof page.url === 'function' ? page.url() : null,
        });
      }
    } catch (error) {
      if (text === '') {
        return captureTaskPreview(context, await selectorFailure(page, selector, error));
      }
    }
  }

  if (text !== '') {
    try {
      const clicked = await clickText(page, text, timeout);

      if (clicked) {
        return captureTaskPreview(context, {
          ok: true,
          status: 'success',
          statusMessage: 'Element wurde ueber Text geklickt.',
          text,
          element: clicked,
          url: typeof page.url === 'function' ? page.url() : null,
        });
      }
    } catch (error) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: `Textziel konnte nicht geklickt werden: ${text}`,
        text,
        error: error.message,
      });
    }
  }

  if (selector !== '') {
    return captureTaskPreview(context, {
      ...(await selectorFailure(page, selector)),
      text,
    });
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein klickbares Ziel uebergeben oder gefunden.',
    selector,
    text,
  });
}

module.exports = { key: 'browser.click', run };
