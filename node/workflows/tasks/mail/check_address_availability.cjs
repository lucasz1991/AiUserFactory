'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { fillFirstMatchingInput } = require('../lib/fill_input.cjs');
const { clickFirstVisibleElement } = require('../lib/find_visible_element.cjs');

const DEFAULT_UNAVAILABLE_PATTERNS = [
  'already taken',
  'already exists',
  'already used',
  'address already',
  'username already',
  'not available',
  'unavailable',
  'is taken',
  'username taken',
  'address taken',
  'choose a different',
  'try another',
  'cannot be used',
  'can not be used',
  'invalid username',
  'invalid email',
  'adresse ist vergeben',
  'bereits vergeben',
  'nicht verfuegbar',
  'nicht verfügbar',
  'existiert bereits',
  'schon vergeben',
  'ist vergeben',
];

const DEFAULT_AVAILABLE_PATTERNS = [
  'available',
  'is available',
  'verfuegbar',
  'verfügbar',
  'kann verwendet werden',
];

const DEFAULT_SUBMIT_SELECTORS = [
  'button[type="submit"]',
  'button[data-testid*="submit" i]',
  'button[data-testid*="continue" i]',
  'button[data-testid*="next" i]',
];

function selectorsFromInput(input = {}) {
  return []
    .concat(input.selector || [])
    .concat(input.selectors || [])
    .concat(input.inputSelector || [])
    .concat(input.input_selector || [])
    .concat([
      'input[name*="username" i]',
      'input[id*="username" i]',
      'input[autocomplete="username"]',
      'input[name*="email" i]',
      'input[id*="email" i]',
      'input[type="email"]',
      'input[type="text"]',
    ])
    .filter(Boolean);
}

function submitSelectorsFromInput(input = {}) {
  return []
    .concat(input.submitSelector || [])
    .concat(input.submit_selector || [])
    .concat(input.submitSelectors || [])
    .concat(input.submit_selectors || [])
    .concat(DEFAULT_SUBMIT_SELECTORS)
    .filter(Boolean);
}

function configuredPatterns(value, fallback) {
  if (Array.isArray(value)) {
    return value.map((entry) => String(entry).toLowerCase()).filter(Boolean);
  }

  if (typeof value === 'string' && value.trim() !== '') {
    return value.split('|').map((entry) => entry.trim().toLowerCase()).filter(Boolean);
  }

  return fallback;
}

function visibleTextForField(field = {}) {
  return [
    field.validationMessage,
    field.ariaDescription,
    field.ariaErrorMessageText,
    field.nearbyText,
  ].filter(Boolean).join('\n');
}

function candidateMatchesField(field = {}, account = {}, useFullEmail = false) {
  const value = String(field.value || '').trim().toLowerCase();
  const username = String(account.username || '').trim().toLowerCase();
  const email = String(account.email || '').trim().toLowerCase();
  const expected = String(useFullEmail ? account.email : (account.username || account.email || '')).trim().toLowerCase();

  return value !== '' && [expected, username, email].filter(Boolean).includes(value);
}

function fieldLooksInvalid(field = {}) {
  const className = String(field.className || '').toLowerCase();
  const ariaInvalid = String(field.ariaInvalid || '').toLowerCase();
  const invalidAttributes = [
    field.invalid === true,
    field.matchesInvalid === true,
    ariaInvalid === 'true',
    className.includes('error'),
    className.includes('invalid'),
    String(field.validationMessage || '').trim() !== '',
  ];

  return invalidAttributes.some(Boolean);
}

async function sleep(ms) {
  await new Promise((resolve) => setTimeout(resolve, ms));
}

function currentCandidate(context = {}) {
  const registration = context.mailRegistration || {};
  const candidates = Array.isArray(registration.candidates) ? registration.candidates : [];
  const index = Number(registration.candidateIndex || 0);

  return candidates[index] || null;
}

function advanceCandidate(context = {}) {
  const registration = context.mailRegistration || {};
  const candidates = Array.isArray(registration.candidates) ? registration.candidates : [];
  const nextIndex = Number(registration.candidateIndex || 0) + 1;
  const candidate = candidates[nextIndex] || null;

  if (!candidate) {
    return null;
  }

  context.mailRegistration = {
    ...registration,
    candidateIndex: nextIndex,
  };
  context.account = {
    ...(context.account || {}),
    username: candidate.username,
    email: candidate.email,
    generated: true,
  };

  return candidate;
}

async function evaluateFrameSnapshot(frame, index) {
  return frame.evaluate((frameIndex) => {
    const visible = (element) => {
      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();

      return style.visibility !== 'hidden'
        && style.display !== 'none'
        && rect.width > 0
        && rect.height > 0;
    };
    const textOf = (element) => String(element?.innerText || element?.textContent || '').trim();

    return {
      index: frameIndex,
      url: window.location.href,
      title: document.title,
      text: document.body ? document.body.innerText.slice(0, 30000) : '',
      feedbackText: Array.from(document.querySelectorAll([
        '[role="alert"]',
        '[aria-live]',
        '[class*="error" i]',
        '[class*="invalid" i]',
        '[data-testid*="error" i]',
        '[id*="error" i]',
      ].join(','))).map(textOf).filter(Boolean).join('\n').slice(0, 8000),
      fields: Array.from(document.querySelectorAll('input, textarea, select')).map((element, fieldIndex) => {
        const describedBy = String(element.getAttribute('aria-describedby') || '')
          .split(/\s+/)
          .map((id) => document.getElementById(id))
          .filter(Boolean)
          .map(textOf)
          .join('\n');
        const errorMessageId = String(element.getAttribute('aria-errormessage') || '').trim();
        const errorMessage = errorMessageId ? textOf(document.getElementById(errorMessageId)) : '';
        const container = element.closest('[class*="field" i], [class*="input" i], label, form, div');

        return {
          index: fieldIndex,
          tag: element.tagName.toLowerCase(),
          type: String(element.getAttribute('type') || '').toLowerCase(),
          id: element.id || '',
          name: element.getAttribute('name') || '',
          autocomplete: element.getAttribute('autocomplete') || '',
          className: element.className || '',
          ariaInvalid: element.getAttribute('aria-invalid') || '',
          ariaDescription: describedBy,
          ariaErrorMessageText: errorMessage,
          placeholder: element.getAttribute('placeholder') || '',
          disabled: element.disabled === true,
          readOnly: element.readOnly === true,
          visible: visible(element),
          value: element.type === 'password' ? '' : String(element.value || ''),
          validationMessage: element.validationMessage || '',
          matchesInvalid: typeof element.matches === 'function' ? element.matches(':invalid') : false,
          nearbyText: container ? textOf(container).slice(0, 1000) : '',
        };
      }),
    };
  }, index);
}

async function pageSnapshot(page) {
  const frames = typeof page.frames === 'function' ? page.frames() : [page.mainFrame ? page.mainFrame() : null];
  const snapshots = [];

  for (const [index, frame] of frames.filter(Boolean).entries()) {
    try {
      snapshots.push(await evaluateFrameSnapshot(frame, index));
    } catch (error) {
      snapshots.push({
        index,
        url: typeof frame.url === 'function' ? frame.url() : '',
        title: '',
        text: '',
        feedbackText: '',
        fields: [],
        error: error.message,
      });
    }
  }

  const text = snapshots
    .map((snapshot) => [
      snapshot.url,
      snapshot.title,
      snapshot.text,
      snapshot.feedbackText,
      ...(snapshot.fields || []).map(visibleTextForField),
    ].filter(Boolean).join('\n'))
    .join('\n')
    .slice(0, 60000);

  return {
    url: snapshots[0]?.url || '',
    title: snapshots[0]?.title || '',
    text,
    frames: snapshots,
  };
}

function matchedPattern(text, patterns) {
  const normalized = String(text || '').toLowerCase();

  return patterns.find((pattern) => pattern !== '' && normalized.includes(pattern)) || '';
}

function visibleFields(snapshot = {}) {
  return (snapshot.frames || [])
    .flatMap((frame) => (frame.fields || []).map((field) => ({ ...field, frameUrl: frame.url })))
    .filter((field) => field.visible && !field.disabled && !field.readOnly);
}

function availabilityFromSnapshot(snapshot, account, options = {}) {
  const unavailable = matchedPattern(snapshot.text, options.unavailablePatterns || []);
  const available = matchedPattern(snapshot.text, options.availablePatterns || []);
  const fields = visibleFields(snapshot);
  const passwordField = fields.find((field) => field.type === 'password' || /password/i.test(`${field.id} ${field.name} ${field.autocomplete}`));
  const candidateField = fields.find((field) => candidateMatchesField(field, account, options.useFullEmail));
  const invalidField = fields.find((field) => candidateMatchesField(field, account, options.useFullEmail) && fieldLooksInvalid(field));

  if (unavailable || invalidField) {
    return {
      state: 'unavailable',
      matchedPattern: unavailable || 'invalid-field-state',
      field: invalidField || null,
    };
  }

  if (available || passwordField) {
    return {
      state: 'available',
      matchedPattern: available || (passwordField ? 'password-field-visible' : null),
      field: passwordField || null,
    };
  }

  if (candidateField) {
    return {
      state: 'pending',
      matchedPattern: null,
      field: candidateField,
    };
  }

  return {
    state: 'unknown',
    matchedPattern: null,
    field: null,
  };
}

async function observeAvailability(page, account, options = {}) {
  const timeout = Math.max(1000, Number(options.timeout || 90000));
  const settleMs = Math.max(500, Number(options.settleMs || 1800));
  const intervalMs = Math.max(250, Number(options.intervalMs || 700));
  const deadline = Date.now() + timeout;
  let lastSnapshot = null;
  let lastAvailability = null;

  await sleep(settleMs);

  while (Date.now() <= deadline) {
    lastSnapshot = await pageSnapshot(page);
    lastAvailability = availabilityFromSnapshot(lastSnapshot, account, options);

    if (lastAvailability.state === 'available' || lastAvailability.state === 'unavailable') {
      return { ...lastAvailability, snapshot: lastSnapshot };
    }

    await sleep(intervalMs);
  }

  return {
    ...(lastAvailability || { state: 'unknown', matchedPattern: null, field: null }),
    snapshot: lastSnapshot,
  };
}

async function clickFirstMatchingSubmit(page, selectors, timeout, context = {}) {
  const attemptedSelectors = []
    .concat(selectors || [])
    .flat()
    .filter(Boolean);

  try {
    const clicked = await clickFirstVisibleElement(page, attemptedSelectors, timeout, { context, defaultKind: 'selector' });

    if (clicked) {
      return {
        ok: true,
        selector: clicked.selector,
        cachedElement: clicked.cachedElement === true,
        frameUrl: typeof clicked.frame?.url === 'function' ? clicked.frame.url() : '',
        attemptedSelectors,
      };
    }
  } catch {
    // The caller reports the failed submit attempt with the complete candidate list.
  }

  return { ok: false, attemptedSelectors };
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);
  const maxAttempts = Math.max(1, Number(input.maxAttempts || input.max_attempts || 8));
  const settleMs = Math.max(500, Number(input.settleMs || input.settle_ms || 1800));
  const checkIntervalMs = Math.max(250, Number(input.checkIntervalMs || input.check_interval_ms || 700));
  const submitOnRetry = input.submitOnRetry !== false && input.submit_on_retry !== false;
  const retryUnconfirmed = input.retryUnconfirmed !== false && input.retry_unconfirmed !== false;
  const unavailablePatterns = configuredPatterns(input.unavailablePatterns || input.unavailable_patterns, DEFAULT_UNAVAILABLE_PATTERNS);
  const availablePatterns = configuredPatterns(input.availablePatterns || input.available_patterns, DEFAULT_AVAILABLE_PATTERNS);
  const selectors = selectorsFromInput(input);
  const submitSelectors = submitSelectorsFromInput(input);
  const useFullEmail = input.useFullEmail === true || input.mode === 'email';
  const tried = [];
  let lastObservation = null;

  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Mailadress-Pruefung vorhanden.' };
  }

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const account = context.account || currentCandidate(context);
    const value = useFullEmail ? account?.email : (account?.username || account?.email);

    if (!account || !value) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Keine Mailadress-Kandidaten fuer die Verfuegbarkeitspruefung vorhanden.',
        tried,
      };
    }

    tried.push(account.email || value);
    const observation = await observeAvailability(page, account, {
      timeout,
      settleMs,
      intervalMs: checkIntervalMs,
      unavailablePatterns,
      availablePatterns,
      useFullEmail,
    });
    lastObservation = observation;

    if (observation.state === 'available') {
      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: `Mailadresse ist nutzbar: ${account.email || value}`,
        account: {
          provider: account.provider,
          username: account.username,
          email: account.email,
          webmailUrl: account.webmailUrl,
          generated: account.generated === true,
        },
        matchedPattern: observation.matchedPattern || null,
        confirmationState: observation.state,
        tried,
      });
    }

    if (observation.state !== 'unavailable' && !(retryUnconfirmed && observation.state === 'pending')) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: `Mailadresse konnte nicht als verfuegbar bestaetigt werden: ${account.email || value}`,
        matchedPattern: observation.matchedPattern || null,
        confirmationState: observation.state,
        observedUrl: observation.snapshot?.url || '',
        observedFrames: (observation.snapshot?.frames || []).map((frame) => ({
          url: frame.url,
          title: frame.title,
          fieldCount: (frame.fields || []).length,
        })),
        tried,
      });
    }

    const next = advanceCandidate(context);

    if (!next) {
      return captureTaskPreview(context, {
        ok: false,
        status: 'failed',
        statusMessage: 'Alle Mailadress-Kandidaten sind vergeben oder konnten nicht bestaetigt werden.',
        matchedPattern: observation.matchedPattern || null,
        confirmationState: observation.state,
        tried,
      });
    }

    const nextValue = useFullEmail ? next.email : next.username;
    const fillResult = await fillFirstMatchingInput(page, selectors, nextValue, timeout, { context });

    if (!fillResult.ok) {
      return {
        ok: false,
        status: 'failed',
        statusMessage: 'Naechster Mailadress-Kandidat konnte nicht eingetragen werden.',
        tried,
        attemptedSelectors: fillResult.attemptedSelectors,
        inputAttempts: fillResult.attempts,
        matchedElementCount: fillResult.matchedElementCount,
        lastFillError: fillResult.lastError || null,
      };
    }

    if (submitOnRetry) {
      const submitResult = await clickFirstMatchingSubmit(page, submitSelectors, timeout, context);

      if (!submitResult.ok) {
        return {
          ok: false,
          status: 'failed',
          statusMessage: 'Naechster Mailadress-Kandidat wurde eingetragen, konnte aber nicht erneut abgeschickt werden.',
          tried,
          submitAttemptedSelectors: submitResult.attemptedSelectors,
        };
      }
    }
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Keine freie Mailadresse innerhalb der Versuchszahl gefunden.',
    matchedPattern: lastObservation?.matchedPattern || null,
    confirmationState: lastObservation?.state || null,
    tried,
  });
}

module.exports = { key: 'mail.check_address_availability', run };
