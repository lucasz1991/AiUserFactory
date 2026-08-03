'use strict';

const crypto = require('node:crypto');
const { captureTaskPreview } = require('../lib/preview.cjs');

const RECAPTCHA_SELECTORS = [
  'iframe[src*="google.com/recaptcha"]',
  'iframe[src*="recaptcha.net/recaptcha"]',
  '.g-recaptcha',
  '[data-sitekey]',
  'textarea[name="g-recaptcha-response"]',
  '#recaptcha-anchor',
];

function normalizeBoolean(value) {
  return value === true || value === 'true' || value === 1 || value === '1';
}

function boundedMinutes(value) {
  const parsed = Number(value);

  return Number.isFinite(parsed) ? Math.max(5, Math.min(60, Math.round(parsed))) : 15;
}

async function inspectFrame(frame, index) {
  const url = typeof frame?.url === 'function' ? String(frame.url() || '') : '';
  const urlSignalsRecaptcha = /(?:google\.com|recaptcha\.net)\/recaptcha/i.test(url);
  let pageEvidence = {};

  if (frame && typeof frame.evaluate === 'function') {
    pageEvidence = await frame.evaluate((selectors) => {
      const visible = (element) => {
        if (!element) return false;
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();

        return style.display !== 'none'
          && style.visibility !== 'hidden'
          && rect.width > 0
          && rect.height > 0;
      };
      const matchedSelectors = selectors.filter((selector) => {
        try {
          return Array.from(document.querySelectorAll(selector)).some(visible);
        } catch {
          return false;
        }
      });
      const response = document.querySelector('textarea[name="g-recaptcha-response"]');
      const anchor = document.querySelector('#recaptcha-anchor');
      const bodyText = String(document.body?.innerText || '').toLowerCase();

      return {
        matchedSelectors,
        responsePresent: String(response?.value || '').trim().length > 0,
        anchorChecked: anchor?.getAttribute('aria-checked') === 'true',
        textSignal: bodyText.includes('recaptcha')
          || bodyText.includes("i'm not a robot")
          || bodyText.includes('ich bin kein roboter'),
      };
    }, RECAPTCHA_SELECTORS).catch(() => ({}));
  }

  return {
    frameIndex: index,
    frameUrlSignal: urlSignalsRecaptcha,
    matchedSelectors: Array.isArray(pageEvidence.matchedSelectors) ? pageEvidence.matchedSelectors : [],
    responsePresent: pageEvidence.responsePresent === true,
    anchorChecked: pageEvidence.anchorChecked === true,
    textSignal: pageEvidence.textSignal === true,
  };
}

async function inspectRecaptcha(page) {
  const frames = typeof page?.frames === 'function'
    ? page.frames()
    : (typeof page?.mainFrame === 'function' ? [page.mainFrame()] : [page]);
  const evidence = await Promise.all(frames.filter(Boolean).map(inspectFrame));
  const solved = evidence.some((item) => item.responsePresent || item.anchorChecked);
  // A recaptcha frame URL alone is not enough: invisible reCAPTCHA/v3 loads
  // background frames that never require human interaction. At least one
  // visible DOM/text signal must be present before a workflow is paused.
  const signalled = evidence.some((item) => item.matchedSelectors.length > 0 || item.textSignal);

  return {
    detected: signalled && !solved,
    solved,
    evidence: {
      provider: 'recaptcha',
      frameCount: evidence.length,
      frameUrlSignals: evidence.filter((item) => item.frameUrlSignal).length,
      selectorSignals: evidence.reduce((count, item) => count + item.matchedSelectors.length, 0),
      textSignals: evidence.filter((item) => item.textSignal).length,
      checked: evidence.some((item) => item.anchorChecked),
      responsePresent: evidence.some((item) => item.responsePresent),
    },
  };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};

  if (!page) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Browserfenster fuer die reCAPTCHA-Pruefung vorhanden.',
    };
  }

  const inspection = await inspectRecaptcha(page);
  const verificationOnly = normalizeBoolean(input.verification_only ?? input.verificationOnly);
  const common = {
    ok: true,
    status: inspection.detected ? 'partial' : 'success',
    statusMessage: inspection.detected
      ? 'reCAPTCHA ist weiterhin sichtbar und muss von einem Administrator geloest werden.'
      : (inspection.solved ? 'reCAPTCHA wurde als geloest erkannt.' : 'Kein ungelöstes reCAPTCHA erkannt.'),
    captchaDetected: inspection.detected,
    captcha_detected: inspection.detected,
    captchaSolved: inspection.solved,
    captcha_solved: inspection.solved,
    captcha: inspection.evidence,
    diagnostic_reason_code: inspection.detected ? 'captcha_detected' : null,
  };

  if (!inspection.detected || verificationOnly) {
    return captureTaskPreview(context, common, true);
  }

  const interventionId = crypto.randomUUID();
  const expiresAfterMinutes = boundedMinutes(input.expires_after_minutes ?? input.expiresAfterMinutes);
  const browserWindow = String(input.browser_window || input.browserWindow || context.activeBrowserWindow || 'main');
  const instructions = String(input.instructions || '').trim().slice(0, 2000);
  const intervention = {
    id: interventionId,
    type: 'captcha',
    provider: 'recaptcha',
    reasonCode: 'captcha_detected',
    reason_code: 'captcha_detected',
    browserWindow,
    browser_window: browserWindow,
    expiresAfterMinutes,
    expires_after_minutes: expiresAfterMinutes,
    instructions,
    evidence: inspection.evidence,
  };

  return captureTaskPreview(context, {
    ...common,
    status: 'waiting_for_human',
    statusMessage: 'reCAPTCHA erkannt. Der Workflow wartet auf die manuelle Bearbeitung durch einen Administrator.',
    manualInterventionRequired: true,
    manual_intervention_required: true,
    humanIntervention: intervention,
    human_intervention: intervention,
  }, true);
}

module.exports = { key: 'human.recaptcha_handoff', run, inspectRecaptcha };
