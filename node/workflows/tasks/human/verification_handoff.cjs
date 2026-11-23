'use strict';

const crypto = require('node:crypto');
const { captureTaskPreview } = require('../lib/preview.cjs');
const { findFirstVisibleElement } = require('../lib/find_visible_element.cjs');

// Generische Variante von human.recaptcha_handoff: Die Verifizierung wird ueber
// frei konfigurierbare Selektoren erkannt (z. B. Protons Human-Verification-
// Modal mit Puzzle-CAPTCHA). Der Task erkennt und pausiert ausschliesslich; er
// loest, umgeht oder beantwortet keine Verifizierung selbst.

const DETECTION_TIMEOUT_MS = 2500;

function normalizeBoolean(value) {
  return value === true || value === 'true' || value === 1 || value === '1';
}

function boundedMinutes(value) {
  const parsed = Number(value);

  return Number.isFinite(parsed) ? Math.max(5, Math.min(60, Math.round(parsed))) : 15;
}

function detectionSelector(input = {}) {
  return String(input.selector || input.element_selector || input.elementSelector || '').trim();
}

function providerName(input = {}) {
  const provider = String(input.provider || '').trim().toLowerCase().replace(/[^a-z0-9_-]+/g, '');

  return provider || 'generic';
}

async function inspectVerification(page, selector, timeoutMs = DETECTION_TIMEOUT_MS) {
  if (selector === '') {
    return { detected: false, selectorConfigured: false, matchedSelector: null };
  }

  const match = await findFirstVisibleElement(page, selector, timeoutMs).catch(() => null);

  return {
    detected: Boolean(match),
    selectorConfigured: true,
    matchedSelector: match ? String(match.selector || match.candidate?.value || match.candidate || '') || null : null,
  };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const selector = detectionSelector(input);
  const provider = providerName(input);

  if (!page) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Browserfenster fuer die Verifizierungspruefung vorhanden.',
    };
  }

  if (selector === '') {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Selector fuer die Erkennung der Verifizierung angegeben.',
    };
  }

  const inspection = await inspectVerification(page, selector);
  const verificationOnly = normalizeBoolean(input.verification_only ?? input.verificationOnly);
  // run_step.cjs reicht vom Interventions-Marker nur feste Felder weiter;
  // evidence wird dagegen unveraendert uebernommen. Deshalb stehen Handoff-
  // Task und Erkennungs-Selector zusaetzlich hier.
  const evidence = {
    provider,
    selectorSignals: inspection.detected ? 1 : 0,
    matchedSelector: inspection.matchedSelector,
    handoffTaskKey: 'human.verification_handoff',
    detectionSelector: selector,
  };
  const common = {
    ok: true,
    status: inspection.detected ? 'partial' : 'success',
    statusMessage: inspection.detected
      ? 'Die Verifizierung ist weiterhin sichtbar und muss von einem Administrator geloest werden.'
      : 'Keine offene Verifizierung erkannt.',
    // Gleiche Ergebnisfelder wie human.recaptcha_handoff, damit die
    // Admin-Aufgabe dieselbe Freigabepruefung verwenden kann.
    captchaDetected: inspection.detected,
    captcha_detected: inspection.detected,
    captchaSolved: false,
    captcha_solved: false,
    verificationDetected: inspection.detected,
    verification_detected: inspection.detected,
    captcha: evidence,
    diagnostic_reason_code: inspection.detected ? 'verification_detected' : null,
  };

  if (!inspection.detected || verificationOnly) {
    return captureTaskPreview(context, common, true);
  }

  const expiresAfterMinutes = boundedMinutes(input.expires_after_minutes ?? input.expiresAfterMinutes);
  const browserWindow = String(input.browser_window || input.browserWindow || context.activeBrowserWindow || 'main');
  const instructions = String(input.instructions || '').trim().slice(0, 2000);
  const intervention = {
    id: crypto.randomUUID(),
    type: 'captcha',
    provider,
    reasonCode: 'captcha_detected',
    reason_code: 'captcha_detected',
    browserWindow,
    browser_window: browserWindow,
    expiresAfterMinutes,
    expires_after_minutes: expiresAfterMinutes,
    instructions,
    evidence,
    handoffTaskKey: 'human.verification_handoff',
    handoff_task_key: 'human.verification_handoff',
    detectionSelector: selector,
    detection_selector: selector,
  };

  return captureTaskPreview(context, {
    ...common,
    status: 'waiting_for_human',
    statusMessage: 'Verifizierung erkannt. Der Workflow wartet auf die manuelle Bearbeitung durch einen Administrator.',
    manualInterventionRequired: true,
    manual_intervention_required: true,
    humanIntervention: intervention,
    human_intervention: intervention,
  }, true);
}

module.exports = { key: 'human.verification_handoff', run, inspectVerification };
