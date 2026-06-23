const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { launchConfiguredBrowser, resolveBrowserEngine } = require('./lib/browser-launcher.cjs');

let puppeteer = null;

try {
  puppeteer = require('puppeteer-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  puppeteer.use(StealthPlugin());
} catch {
  puppeteer = require('puppeteer');
}

const LIVE_PREVIEW_MIN_INTERVAL_MS = 2500;
const DEFAULT_VIEWPORT = { width: 1365, height: 900 };
const PROVIDER_MODE_OBSERVED_MANUAL = 'observed_manual';
const PROVIDER_MODE_PROTON_USERNAME_CHECK = 'proton_username_check';
const DOM_DEBUG_TEXT_LIMIT = 3000;
const DOM_DEBUG_HTML_LIMIT = 6000;
const STEP_DELAY_MS = 150;
const TYPING_DELAY_MS = 150;
const SUBMIT_DELAY_MS = 1500;

const runtimeConfigPath = process.argv[2] || '';
const statusEvents = [];
let lastLivePreviewAt = 0;
let activeBrowserEngine = null;
let requestedBrowserEngine = null;
let browserFallbackReason = null;

function normalizeText(value) {
  return String(value || '').trim();
}

function ensureDirectory(directoryPath) {
  if (!directoryPath) {
    return directoryPath;
  }

  fs.mkdirSync(directoryPath, { recursive: true });

  return directoryPath;
}

function readJsonFile(filePath, fallback = {}) {
  try {
    const raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function writeJsonFile(filePath, payload) {
  ensureDirectory(path.dirname(filePath));
  const temporaryPath = `${filePath}.${process.pid}.tmp`;
  fs.writeFileSync(temporaryPath, JSON.stringify(payload, null, 2), 'utf8');
  fs.renameSync(temporaryPath, filePath);
}

function publicStatusPayload(runtimeConfig, state, stage, message, data = {}) {
  return {
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    providerLabel: runtimeConfig.provider?.label || null,
    state,
    stage,
    message,
    at: new Date().toISOString(),
    pid: process.pid,
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    livePreviewEnabled: runtimeConfig.livePreviewEnabled !== false,
    domDebugEnabled: runtimeConfig.domDebugEnabled !== false,
    finalUrl: data.finalUrl || null,
    title: data.title || null,
    liveScreenshotPath: data.liveScreenshotPath || null,
    liveScreenshotAt: data.liveScreenshotAt || null,
    events: statusEvents.slice(-40),
  };
}

function progress(runtimeConfig, stage, message, data = {}, state = 'running') {
  const event = {
    at: new Date().toISOString(),
    stage,
    message,
    finalUrl: data.finalUrl || null,
    title: data.title || null,
    debugDom: data.debugDom || null,
  };

  statusEvents.push(event);

  if (statusEvents.length > 80) {
    statusEvents.shift();
  }

  const statusPath = normalizeText(runtimeConfig.statusPath);

  if (statusPath) {
    writeJsonFile(statusPath, publicStatusPayload(runtimeConfig, state, stage, message, data));
  }

  process.stderr.write(`[MAIL REGISTER PROGRESS] ${JSON.stringify({
    ...event,
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    state,
  })}\n`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

async function pauseStep() {
  await sleep(STEP_DELAY_MS);
}

async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
  const livePreviewPath = normalizeText(runtimeConfig.livePreviewPath);

  if (!page || !livePreviewPath || runtimeConfig.livePreviewEnabled === false) {
    return {};
  }

  const now = Date.now();

  if (!force && now - lastLivePreviewAt < LIVE_PREVIEW_MIN_INTERVAL_MS) {
    return {};
  }

  try {
    ensureDirectory(path.dirname(livePreviewPath));
    await page.screenshot({
      path: livePreviewPath,
      fullPage: false,
      type: 'png',
    });

    lastLivePreviewAt = now;

    return {
      liveScreenshotPath: livePreviewPath,
      liveScreenshotAt: new Date(now).toISOString(),
    };
  } catch (error) {
    return {
      liveScreenshotError: normalizeText(error?.message || String(error)),
    };
  }
}

function truncateText(value, limit) {
  const text = normalizeText(value);

  return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
}

async function frameDomDebug(frame) {
  return frame.evaluate((limits) => {
    const compactText = (value = '', limit = 1000) => {
      const text = String(value || '').replace(/\s+/g, ' ').trim();

      return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
    };

    const attributes = (element, names) => Object.fromEntries(
      names
        .map((name) => [name, element.getAttribute(name)])
        .filter(([, value]) => value !== null && value !== ''),
    );

    const elementSummary = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const secretHaystack = [
        element.getAttribute('type'),
        element.getAttribute('name'),
        element.getAttribute('id'),
        element.getAttribute('autocomplete'),
        element.getAttribute('placeholder'),
        element.getAttribute('aria-label'),
      ].join(' ').toLowerCase();
      const isSecret = secretHaystack.includes('password');
      const textValue = isSecret && element.value
        ? '[redacted]'
        : (element.innerText || element.textContent || element.value || '');

      return {
        tag: element.tagName.toLowerCase(),
        text: compactText(textValue, 220),
        visible: rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none',
        disabled: Boolean(element.disabled),
        attrs: attributes(element, [
          'id',
          'name',
          'type',
          'class',
          'autocomplete',
          'placeholder',
          'aria-label',
          'data-testid',
          'role',
          'title',
        ]),
      };
    };

    const main = document.querySelector('main') || document.body;

    return {
      url: window.location.href,
      title: document.title || '',
      text: compactText(document.body?.innerText || '', limits.text),
      forms: Array.from(document.querySelectorAll('form')).slice(0, 5).map((form) => ({
        attrs: attributes(form, ['id', 'name', 'method', 'action', 'class']),
        inputs: Array.from(form.querySelectorAll('input, textarea, select')).slice(0, 20).map(elementSummary),
        buttons: Array.from(form.querySelectorAll('button, [role="button"], input[type="submit"]')).slice(0, 12).map(elementSummary),
      })),
      inputs: Array.from(document.querySelectorAll('input, textarea, select')).slice(0, 30).map(elementSummary),
      buttons: Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"]')).slice(0, 30).map(elementSummary),
      iframes: Array.from(document.querySelectorAll('iframe')).slice(0, 12).map((iframe) => ({
        src: iframe.src || '',
        title: iframe.title || '',
        class: iframe.className || '',
      })),
      html: compactText(main?.outerHTML || document.body?.innerHTML || '', limits.html),
    };
  }, {
    text: DOM_DEBUG_TEXT_LIMIT,
    html: DOM_DEBUG_HTML_LIMIT,
  });
}

async function domDebugSnapshot(page) {
  const frames = [];

  for (const frame of page.frames()) {
    try {
      frames.push({
        name: frame.name() || '',
        ...await frameDomDebug(frame),
      });
    } catch (error) {
      frames.push({
        name: frame.name() || '',
        url: frame.url(),
        error: truncateText(error?.message || String(error), 500),
      });
    }
  }

  return {
    capturedAt: new Date().toISOString(),
    frameCount: frames.length,
    frames: frames.slice(0, 8),
  };
}

async function pageSnapshot(page, runtimeConfig, force = false, includeDom = false) {
  const shouldIncludeDom = includeDom && runtimeConfig.domDebugEnabled !== false;
  const [title, finalUrl, screenshot, debugDom] = await Promise.all([
    page.title().catch(() => null),
    Promise.resolve(page.url()).catch(() => null),
    captureLivePreviewScreenshot(page, runtimeConfig, force),
    shouldIncludeDom ? domDebugSnapshot(page).catch((error) => ({
      error: truncateText(error?.message || String(error), 800),
    })) : Promise.resolve(null),
  ]);

  return {
    title,
    finalUrl,
    debugDom,
    ...screenshot,
  };
}

function validateProvider(runtimeConfig) {
  const provider = runtimeConfig.provider || {};
  let mode = normalizeText(provider.mode || PROVIDER_MODE_OBSERVED_MANUAL);
  const rawRegistrationUrl = normalizeText(provider.registrationUrl || provider.registration_url);

  if (mode === 'proton') {
    mode = PROVIDER_MODE_PROTON_USERNAME_CHECK;
  }

  if (mode === PROVIDER_MODE_OBSERVED_MANUAL && /(^|\/\/|\.)(proton\.me|account\.proton\.me|account-api\.proton\.me)/i.test(rawRegistrationUrl)) {
    mode = PROVIDER_MODE_PROTON_USERNAME_CHECK;
  }

  if (![PROVIDER_MODE_OBSERVED_MANUAL, PROVIDER_MODE_PROTON_USERNAME_CHECK].includes(mode)) {
    throw new Error(`Provider-Adapter "${mode}" ist noch nicht implementiert.`);
  }

  const registrationUrl = rawRegistrationUrl
    || (mode === PROVIDER_MODE_PROTON_USERNAME_CHECK ? 'https://account.proton.me/mail/signup' : '');

  if (!/^https?:\/\//i.test(registrationUrl)) {
    throw new Error('Fuer den ersten Mail-Provider ist eine gueltige Registrierungs-URL erforderlich.');
  }

  return {
    ...provider,
    mode,
    registrationUrl,
    completionUrlContains: normalizeText(provider.completionUrlContains || provider.completion_url_contains),
    completionSelector: normalizeText(provider.completionSelector || provider.completion_selector),
  };
}

async function detectManualCompletion(page, provider) {
  const finalUrl = page.url();

  if (provider.completionUrlContains && finalUrl.includes(provider.completionUrlContains)) {
    return {
      completed: true,
      reason: 'completion-url',
    };
  }

  if (provider.completionSelector) {
    const element = await page.$(provider.completionSelector).catch(() => null);

    if (element) {
      return {
        completed: true,
        reason: 'completion-selector',
      };
    }
  }

  return {
    completed: false,
    reason: null,
  };
}

function usernameFromSubject(runtimeConfig) {
  const subject = runtimeConfig.subject || {};
  const accountUsername = normalizeText(subject.accountUsername || subject.account_username);
  const desiredEmail = normalizeText(subject.desiredEmail || subject.desired_email);
  const source = accountUsername || desiredEmail;
  const localPart = source.includes('@') ? source.split('@')[0] : source;

  return localPart
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .replace(/[._-]{2,}/g, '-')
    .slice(0, 64);
}

function uniqueValues(values) {
  return Array.from(new Set(values.filter((value) => normalizeText(value) !== '')));
}

function trimUsernameCandidate(value) {
  return normalizeText(value)
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .replace(/[._-]{2,}/g, '-')
    .slice(0, 64);
}

function generateUsernameCandidates(baseUsername, maxAttempts = 12) {
  const base = trimUsernameCandidate(baseUsername);
  const requestedAttempts = Number(maxAttempts);
  const targetAttempts = Number.isFinite(requestedAttempts)
    ? Math.max(1, Math.min(50, Math.floor(requestedAttempts)))
    : 12;

  if (!base) {
    return [];
  }

  const candidates = [base];
  const trailingNumber = base.match(/^(.*?)(\d{1,6})$/);

  if (trailingNumber) {
    const prefix = trailingNumber[1] || base;
    const currentNumber = Number(trailingNumber[2]);
    const width = trailingNumber[2].length;

    for (let offset = 1; candidates.length < targetAttempts && offset <= targetAttempts * 2; offset += 1) {
      candidates.push(trimUsernameCandidate(`${prefix}${String(currentNumber + offset).padStart(width, '0')}`));
    }
  }

  for (let suffix = 1; candidates.length < targetAttempts && suffix <= targetAttempts * 2; suffix += 1) {
    candidates.push(trimUsernameCandidate(`${base}${suffix}`));
  }

  while (candidates.length < targetAttempts) {
    candidates.push(trimUsernameCandidate(`${base}${crypto.randomInt(10, 99999)}`));
  }

  return uniqueValues(candidates).slice(0, targetAttempts);
}

function randomCharacter(characters) {
  return characters[crypto.randomInt(0, characters.length)];
}

function shuffleString(value) {
  const characters = value.split('');

  for (let index = characters.length - 1; index > 0; index -= 1) {
    const swapIndex = crypto.randomInt(0, index + 1);
    [characters[index], characters[swapIndex]] = [characters[swapIndex], characters[index]];
  }

  return characters.join('');
}

function generateAccountPassword(length = 24) {
  const requestedLength = Number(length);
  const targetLength = Number.isFinite(requestedLength)
    ? Math.max(16, Math.floor(requestedLength))
    : 24;
  const categories = [
    'abcdefghijkmnopqrstuvwxyz',
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    '23456789',
    '!@#$%^&*_-+=?',
  ];
  const characters = categories.map(randomCharacter);
  const allCharacters = categories.join('');

  while (characters.length < targetLength) {
    characters.push(randomCharacter(allCharacters));
  }

  return shuffleString(characters.join(''));
}

async function findVisibleInput(pageOrFrame, selectors) {
  for (const selector of selectors) {
    const handle = await pageOrFrame.$(selector).catch(() => null);

    if (!handle) {
      continue;
    }

    const visible = await handle.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    }).catch(() => false);

    if (visible) {
      return handle;
    }
  }

  return pageOrFrame.evaluateHandle(() => {
    const inputs = Array.from(document.querySelectorAll('input'));

    return inputs.find((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);
      const haystack = [
        input.name,
        input.id,
        input.autocomplete,
        input.placeholder,
        input.getAttribute('aria-label'),
        input.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !input.disabled
        && /(username|email|mail|text)/i.test(haystack);
    }) || null;
  }).then((handle) => handle.asElement()).catch(() => null);
}

async function findVisibleInputIncludingFrames(page, selectors) {
  const mainFrameInput = await findVisibleInput(page, selectors);

  if (mainFrameInput) {
    return mainFrameInput;
  }

  for (const frame of page.frames()) {
    const frameUrl = normalizeText(frame.url());

    if (frame === page.mainFrame() || !/proton|challenge|account-api/i.test(frameUrl)) {
      continue;
    }

    const frameInput = await findVisibleInput(frame, selectors);

    if (frameInput) {
      return frameInput;
    }
  }

  return null;
}

async function findVisiblePasswordInputs(pageOrFrame) {
  const inputs = await pageOrFrame.$$('input').catch(() => []);
  const passwordInputs = [];

  for (const input of inputs) {
    const visiblePasswordInput = await input.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const haystack = [
        element.name,
        element.id,
        element.autocomplete,
        element.placeholder,
        element.getAttribute('aria-label'),
        element.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && (element.type === 'password' || haystack.includes('password'));
    }).catch(() => false);

    if (visiblePasswordInput) {
      passwordInputs.push(input);
    }
  }

  return passwordInputs;
}

async function findVisiblePasswordInputPairIncludingFrames(page) {
  const mainFrame = page.mainFrame();
  const frames = [
    mainFrame,
    ...page.frames().filter((frame) => frame !== mainFrame),
  ];

  for (const frame of frames) {
    const frameUrl = normalizeText(frame.url());

    if (frame !== mainFrame && !/proton|challenge|account-api/i.test(frameUrl)) {
      continue;
    }

    const passwordInputs = await findVisiblePasswordInputs(frame);

    if (passwordInputs.length >= 2) {
      return {
        passwordInput: passwordInputs[0],
        confirmationInput: passwordInputs[1],
      };
    }
  }

  return null;
}

async function waitForVisiblePasswordInputPairIncludingFrames(page, timeoutMs = 20000) {
  const stopAt = Date.now() + timeoutMs;
  let inputPair = await findVisiblePasswordInputPairIncludingFrames(page);

  while (!inputPair && Date.now() < stopAt) {
    await sleep(500);
    inputPair = await findVisiblePasswordInputPairIncludingFrames(page);
  }

  return inputPair;
}

async function waitForVisibleInputIncludingFrames(page, selectors, timeoutMs = 20000) {
  const stopAt = Date.now() + timeoutMs;
  let input = await findVisibleInputIncludingFrames(page, selectors);

  while (!input && Date.now() < stopAt) {
    await sleep(500);
    input = await findVisibleInputIncludingFrames(page, selectors);
  }

  return input;
}

async function findVisibleProtonUsernameInput(pageOrFrame, selectors) {
  for (const selector of selectors) {
    const handle = await pageOrFrame.$(selector).catch(() => null);

    if (!handle) {
      continue;
    }

    const usable = await handle.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const blockedTypes = ['button', 'checkbox', 'file', 'hidden', 'password', 'radio', 'reset', 'submit'];

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && !element.readOnly
        && !blockedTypes.includes(String(element.type || '').toLowerCase());
    }).catch(() => false);

    if (usable) {
      return handle;
    }
  }

  return pageOrFrame.evaluateHandle(() => {
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const textNear = (input) => {
      const escapedId = input.id && window.CSS?.escape ? window.CSS.escape(input.id) : '';
      const label = escapedId ? document.querySelector(`label[for="${escapedId}"]`) : null;
      const wrapper = input.closest('label, [data-testid], .field, .input, form, div');

      return normalize([
        input.name,
        input.id,
        input.autocomplete,
        input.placeholder,
        input.getAttribute('aria-label'),
        input.getAttribute('data-testid'),
        input.type,
        label?.innerText,
        wrapper?.innerText,
      ].join(' '));
    };
    const visibleTextInputs = Array.from(document.querySelectorAll('input')).filter((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);
      const type = normalize(input.type || 'text');
      const blockedTypes = ['button', 'checkbox', 'file', 'hidden', 'password', 'radio', 'range', 'reset', 'submit'];
      const haystack = textNear(input);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !input.disabled
        && !input.readOnly
        && !blockedTypes.includes(type)
        && !/(password|passwort|captcha|verification code|verify code|2fa|otp|phone)/.test(haystack);
    });

    if (visibleTextInputs.length === 0) {
      return null;
    }

    const scored = visibleTextInputs
      .map((input, index) => {
        const haystack = textNear(input);
        let score = 0;

        if (/\buser(name)?\b|benutzer/.test(haystack)) {
          score += 100;
        }

        if (/proton/.test(haystack)) {
          score += 25;
        }

        if (/mail|email|e-mail/.test(haystack)) {
          score += 10;
        }

        if (/data-testid|input-input-element/.test(haystack)) {
          score += 5;
        }

        return { input, score, index };
      })
      .sort((left, right) => right.score - left.score || left.index - right.index);

    return scored[0].input;
  }).then((handle) => handle.asElement()).catch(() => null);
}

async function findVisibleProtonUsernameInputIncludingFrames(page, selectors) {
  const mainFrameInput = await findVisibleProtonUsernameInput(page, selectors);

  if (mainFrameInput) {
    return mainFrameInput;
  }

  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }

    const frameInput = await findVisibleProtonUsernameInput(frame, selectors);

    if (frameInput) {
      return frameInput;
    }
  }

  return null;
}

async function waitForVisibleProtonUsernameInputIncludingFrames(page, selectors, timeoutMs = 20000) {
  const stopAt = Date.now() + timeoutMs;
  let input = await findVisibleProtonUsernameInputIncludingFrames(page, selectors);

  while (!input && Date.now() < stopAt) {
    await sleep(500);
    input = await findVisibleProtonUsernameInputIncludingFrames(page, selectors);
  }

  return input;
}

async function fillInputValue(inputHandle, value) {
  const nextValue = String(value ?? '');

  await inputHandle.evaluate((element) => {
    element.focus();

    const prototype = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

    if (descriptor?.set) {
      descriptor.set.call(element, '');
    } else {
      element.value = '';
    }

    if (typeof InputEvent === 'function') {
      element.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'insertText',
        data: '',
      }));
    } else {
      element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    element.dispatchEvent(new Event('change', { bubbles: true }));
  });

  await pauseStep();
  await inputHandle.type(nextValue, { delay: TYPING_DELAY_MS });
  await pauseStep();

  await inputHandle.evaluate((element) => {
    element.dispatchEvent(new Event('change', { bubbles: true }));
  });

  return inputHandle.evaluate((element) => element.value || '');
}

async function forceInputValue(inputHandle, value) {
  const nextValue = String(value ?? '');

  return inputHandle.evaluate((element, forcedValue) => {
    element.focus();

    const prototype = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(prototype, 'value');

    if (descriptor?.set) {
      descriptor.set.call(element, forcedValue);
    } else {
      element.value = forcedValue;
    }

    if (typeof InputEvent === 'function') {
      element.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'insertText',
        data: forcedValue,
      }));
    } else {
      element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    element.dispatchEvent(new Event('change', { bubbles: true }));
    element.blur();

    return element.value || '';
  }, nextValue).catch(() => '');
}

async function clickFirstMatchingButtonInContext(pageOrFrame, labels, allowFormSubmit = true) {
  await sleep(SUBMIT_DELAY_MS);

  const clicked = await pageOrFrame.evaluate((buttonLabels) => {
    const normalizedLabels = buttonLabels.map((label) => String(label).toLowerCase());
    const candidates = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"]'));
    const button = candidates.find((candidate) => {
      const rect = candidate.getBoundingClientRect();
      const style = window.getComputedStyle(candidate);
      const text = [
        candidate.innerText,
        candidate.textContent,
        candidate.value,
        candidate.getAttribute('aria-label'),
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !candidate.disabled
        && normalizedLabels.some((label) => text.includes(label));
    });

    if (!button) {
      return false;
    }

    button.click();

    return true;
  }, labels).catch(() => false);

  if (clicked) {
    await pauseStep();
    return true;
  }

  if (!allowFormSubmit) {
    return false;
  }

  const submitted = await pageOrFrame.evaluate(() => {
    const form = document.querySelector('form[name="accountForm"], form');

    if (!form) {
      return false;
    }

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }

    return true;
  }).catch(() => false);

  if (submitted) {
    await pauseStep();
    return true;
  }

  return false;
}

async function clickFirstMatchingButton(page, labels) {
  if (await clickFirstMatchingButtonInContext(page, labels)) {
    return true;
  }

  await page.keyboard.press('Enter').catch(() => {});
  await pauseStep();

  return false;
}

async function clickFirstMatchingButtonIncludingFrames(page, labels) {
  if (await clickFirstMatchingButtonInContext(page, labels, false)) {
    return true;
  }

  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }

    if (await clickFirstMatchingButtonInContext(frame, labels)) {
      return true;
    }
  }

  if (await clickFirstMatchingButtonInContext(page, labels)) {
    return true;
  }

  await page.keyboard.press('Enter').catch(() => {});
  await pauseStep();

  return false;
}

async function clickFirstExactVisibleText(pageOrFrame, labels, selectors = 'button, [role="button"], [role="tab"], a') {
  const clicked = await pageOrFrame.evaluate((payload) => {
    const normalizedLabels = payload.labels.map((label) => String(label).trim().toLowerCase());
    const candidates = Array.from(document.querySelectorAll(payload.selectors));
    const element = candidates.find((candidate) => {
      const rect = candidate.getBoundingClientRect();
      const style = window.getComputedStyle(candidate);
      const text = [
        candidate.innerText,
        candidate.textContent,
        candidate.value,
        candidate.getAttribute('aria-label'),
        candidate.getAttribute('title'),
      ].join(' ').replace(/\s+/g, ' ').trim().toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !candidate.disabled
        && normalizedLabels.includes(text);
    });

    if (!element) {
      return false;
    }

    element.click();

    return true;
  }, {
    labels,
    selectors,
  }).catch(() => false);

  if (clicked) {
    await pauseStep();
  }

  return clicked;
}

async function clickExactVisibleTextIncludingFrames(page, labels, selectors) {
  if (await clickFirstExactVisibleText(page, labels, selectors)) {
    return true;
  }

  for (const frame of page.frames()) {
    const frameUrl = normalizeText(frame.url());

    if (frame === page.mainFrame() || !/proton|challenge|account-api/i.test(frameUrl)) {
      continue;
    }

    if (await clickFirstExactVisibleText(frame, labels, selectors)) {
      return true;
    }
  }

  return false;
}

async function clickVisibleTextTarget(pageOrFrame, labels, selectors = '*', allowPartial = false) {
  const clicked = await pageOrFrame.evaluate((payload) => {
    const normalizedLabels = payload.labels.map((label) => String(label).trim().toLowerCase());
    const clickableSelector = 'button, [role="button"], [role="tab"], a, label, input[type="button"], input[type="submit"]';
    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const visible = (candidate) => {
      const rect = candidate.getBoundingClientRect();
      const style = window.getComputedStyle(candidate);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !candidate.disabled;
    };
    const textFor = (candidate) => normalize([
      candidate.innerText,
      candidate.textContent,
      candidate.value,
      candidate.getAttribute('aria-label'),
      candidate.getAttribute('title'),
    ].join(' '));
    const matches = (text) => {
      if (normalizedLabels.includes(text)) {
        return true;
      }

      return payload.allowPartial
        && /(^|\b)e-?mail(\b|$)/i.test(text)
        && normalizedLabels.some((label) => text.includes(label));
    };
    const visibleElements = Array.from(document.querySelectorAll(payload.selectors)).filter((candidate) => {
      if (!visible(candidate)) {
        return false;
      }

      return candidate.matches(clickableSelector) || Boolean(candidate.closest(clickableSelector));
    });

    const element = visibleElements.find((candidate) => {
      const clickable = candidate.closest(clickableSelector) || candidate;

      return matches(textFor(candidate)) || matches(textFor(clickable));
    });

    if (!element) {
      return false;
    }

    const clickable = element.closest(clickableSelector) || element;
    clickable.scrollIntoView({ block: 'center', inline: 'center' });
    clickable.click();

    return true;
  }, {
    labels,
    selectors,
    allowPartial,
  }).catch(() => false);

  if (clicked) {
    await pauseStep();
  }

  return clicked;
}

async function selectProtonEmailVerificationTab(page) {
  const labels = [
    'email',
    'e-mail',
    'email address',
    'e-mail address',
    'email verification',
    'e-mail verification',
    'verify by email',
    'verify with email',
    'verify via email',
  ];
  const selectors = 'button, [role="button"], [role="tab"], a, label, input[type="button"], input[type="submit"], span';

  if (await clickExactVisibleTextIncludingFrames(page, labels, selectors)) {
    return true;
  }

  if (await clickVisibleTextTarget(page, labels, selectors, true)) {
    return true;
  }

  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }

    if (await clickVisibleTextTarget(frame, labels, selectors, true)) {
      return true;
    }
  }

  return false;
}

function verificationMailboxFromConfig(runtimeConfig) {
  const mailbox = runtimeConfig.verificationMailbox || {};
  const enabled = mailbox.enabled === true;
  const email = normalizeText(mailbox.email);
  const username = normalizeText(mailbox.username || email);
  const password = normalizeText(mailbox.password);
  const webmailUrl = normalizeText(mailbox.webmailUrl || mailbox.webmail_url);

  return {
    enabled,
    email,
    username,
    password,
    webmailUrl,
    usable: enabled && email !== '',
  };
}

async function findVisibleEmailInput(pageOrFrame) {
  const inputs = await pageOrFrame.$$('input').catch(() => []);

  for (const input of inputs) {
    const matches = await input.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      const haystack = [
        element.name,
        element.id,
        element.autocomplete,
        element.placeholder,
        element.getAttribute('aria-label'),
        element.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !element.disabled
        && element.type !== 'password'
        && !haystack.includes('username')
        && (element.type === 'email' || haystack.includes('email') || haystack.includes('mail'));
    }).catch(() => false);

    if (matches) {
      return input;
    }
  }

  return null;
}

async function findVisibleEmailInputIncludingFrames(page) {
  const mainInput = await findVisibleEmailInput(page);

  if (mainInput) {
    return mainInput;
  }

  for (const frame of page.frames()) {
    const frameUrl = normalizeText(frame.url());

    if (frame === page.mainFrame() || !/proton|challenge|account-api/i.test(frameUrl)) {
      continue;
    }

    const frameInput = await findVisibleEmailInput(frame);

    if (frameInput) {
      return frameInput;
    }
  }

  return null;
}

async function waitForVisibleEmailInputIncludingFrames(page, timeoutMs = 15000) {
  const stopAt = Date.now() + timeoutMs;
  let input = await findVisibleEmailInputIncludingFrames(page);

  while (!input && Date.now() < stopAt) {
    await sleep(500);
    input = await findVisibleEmailInputIncludingFrames(page);
  }

  return input;
}

async function fillProtonVerificationEmail(page, runtimeConfig) {
  const mailbox = verificationMailboxFromConfig(runtimeConfig);

  if (!mailbox.usable) {
    return {
      filled: false,
      reason: 'verification-mailbox-not-configured',
    };
  }

  const input = await waitForVisibleEmailInputIncludingFrames(page, 15000);

  if (!input) {
    return {
      filled: false,
      reason: 'verification-email-input-not-found',
    };
  }

  const enteredEmail = await fillInputValue(input, mailbox.email);

  progress(
    runtimeConfig,
    'proton-verification-email-entered',
    `Verifikations-E-Mail "${mailbox.email}" wurde eingetragen. Feldwert: "${enteredEmail}".`,
    await pageSnapshot(page, runtimeConfig, true, false),
  );

  const submitted = await clickFirstMatchingButtonIncludingFrames(page, [
    'send verification code',
    'send code',
    'send email',
    'send',
    'continue',
    'next',
    'weiter',
    'verify',
  ]);

  if (submitted) {
    await pauseStep();
  }

  return {
    filled: enteredEmail === mailbox.email,
    submitted,
    email: mailbox.email,
    reason: submitted ? 'verification-email-submitted' : 'verification-email-entered',
  };
}

async function fillFirstVisibleInputByHints(page, hints, value, timeoutMs = 12000) {
  const stopAt = Date.now() + timeoutMs;
  let input = null;

  while (!input && Date.now() < stopAt) {
    input = await page.evaluateHandle((hintValues) => {
      const normalizedHints = hintValues.map((hint) => String(hint).toLowerCase());
      const inputs = Array.from(document.querySelectorAll('input'));

      return inputs.find((candidate) => {
        const rect = candidate.getBoundingClientRect();
        const style = window.getComputedStyle(candidate);
        const haystack = [
          candidate.name,
          candidate.id,
          candidate.autocomplete,
          candidate.placeholder,
          candidate.getAttribute('aria-label'),
          candidate.type,
        ].join(' ').toLowerCase();

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && !candidate.disabled
          && normalizedHints.some((hint) => haystack.includes(hint));
      }) || null;
    }, hints).then((handle) => handle.asElement()).catch(() => null);

    if (!input) {
      await sleep(500);
    }
  }

  if (!input) {
    return false;
  }

  await fillInputValue(input, value);
  await pauseStep();

  return true;
}

async function openVerificationWebmailPage(browser, runtimeConfig) {
  const mailbox = verificationMailboxFromConfig(runtimeConfig);

  if (!mailbox.enabled || !mailbox.webmailUrl) {
    return {
      opened: false,
      reason: 'verification-webmail-not-configured',
    };
  }

  const page = await browser.newPage();
  await page.setViewport(DEFAULT_VIEWPORT);
  page.setDefaultNavigationTimeout(Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)));

  await page.goto(mailbox.webmailUrl, {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  progress(
    runtimeConfig,
    'verification-webmail-opened',
    `Webmail-Portal fuer Verifikations-E-Mail wurde geoeffnet: ${mailbox.webmailUrl}`,
    {
      title: await page.title().catch(() => null),
      finalUrl: page.url(),
    },
  );

  if (mailbox.username) {
    await fillFirstVisibleInputByHints(page, ['email', 'mail', 'user', 'login', 'username'], mailbox.username).catch(() => false);
  }

  if (mailbox.username) {
    await clickFirstMatchingButton(page, [
      'next',
      'continue',
      'weiter',
      'sign in',
      'log in',
      'login',
      'anmelden',
    ]).catch(() => false);
    await pauseStep();
  }

  if (mailbox.password) {
    await fillFirstVisibleInputByHints(page, ['password', 'passwort'], mailbox.password).catch(() => false);
  }

  if (mailbox.password) {
    await clickFirstMatchingButton(page, [
      'sign in',
      'log in',
      'login',
      'anmelden',
      'next',
      'continue',
      'weiter',
    ]).catch(() => false);
  }

  return {
    opened: true,
    page,
    url: page.url(),
  };
}

async function protonPasswordSubmitStatus(page) {
  return page.evaluate(() => {
    const text = document.body?.innerText || '';
    const normalized = text.toLowerCase();
    const url = window.location.href;
    const providerBlockPattern = /potentially abusive traffic|blocked any further signups|appeal-abuse|support\/appeal-abuse|abusive traffic/;
    const manualVerificationPattern = /human verification|captcha|recaptcha|hcaptcha|verify you.?re human|to fight spam and abuse|please verify you are human/;
    const visiblePasswordInputs = Array.from(document.querySelectorAll('input')).filter((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);
      const haystack = [
        input.name,
        input.id,
        input.autocomplete,
        input.placeholder,
        input.getAttribute('aria-label'),
        input.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !input.disabled
        && (input.type === 'password' || haystack.includes('password'));
    });
    const validationPatterns = [
      'passwords do not match',
      'password does not match',
      'password is too short',
      'password is required',
      'enter a password',
      'please enter a password',
      'passwort stimmt nicht',
      'passwoerter stimmen nicht',
      'passwort ist zu kurz',
    ];

    if (providerBlockPattern.test(normalized)) {
      return {
        advanced: false,
        providerBlocked: true,
        reason: 'provider-abuse-block',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (validationPatterns.some((pattern) => normalized.includes(pattern))) {
      return {
        advanced: false,
        reason: 'password-validation-error',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (manualVerificationPattern.test(normalized)) {
      return {
        advanced: null,
        manualRequired: true,
        reason: 'manual-verification-required',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (
      /phone verification|verify your email|email verification|confirm you|recovery email/.test(normalized)
      || (visiblePasswordInputs.length < 2 && !normalized.includes('set your password'))
    ) {
      return {
        advanced: true,
        reason: 'advanced-after-password',
        message: text.slice(0, 1200),
        url,
      };
    }

    return {
      advanced: null,
      reason: visiblePasswordInputs.length >= 2 ? 'password-form-still-visible' : 'pending',
      message: text.slice(0, 1200),
      url,
    };
  });
}

async function waitForProtonPasswordSubmit(page, runtimeConfig, timeoutMs = 20000) {
  const stopAt = Date.now() + timeoutMs;
  let status = await protonPasswordSubmitStatus(page);

  while (status.advanced === null && !status.manualRequired && Date.now() < stopAt) {
    await sleep(1000);
    status = await protonPasswordSubmitStatus(page);

    progress(
      runtimeConfig,
      'proton-password-submitting',
      'Proton verarbeitet das Passwort.',
      await pageSnapshot(page, runtimeConfig, false, false),
    );
  }

  return status;
}

async function protonManualVerificationStatus(page) {
  return page.evaluate(() => {
    const text = document.body?.innerText || '';
    const normalized = text.toLowerCase();
    const url = window.location.href;
    const providerBlockPattern = /potentially abusive traffic|blocked any further signups|appeal-abuse|support\/appeal-abuse|abusive traffic/;
    const manualVerificationPattern = /human verification|captcha|recaptcha|hcaptcha|verify you.?re human|to fight spam and abuse|please verify you are human/;
    const visiblePasswordInputs = Array.from(document.querySelectorAll('input')).filter((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);
      const haystack = [
        input.name,
        input.id,
        input.autocomplete,
        input.placeholder,
        input.getAttribute('aria-label'),
        input.type,
      ].join(' ').toLowerCase();

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && !input.disabled
        && (input.type === 'password' || haystack.includes('password'));
    });

    if (providerBlockPattern.test(normalized)) {
      return {
        completed: false,
        providerBlocked: true,
        reason: 'provider-abuse-block',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (manualVerificationPattern.test(normalized)) {
      return {
        completed: null,
        reason: 'manual-verification-required',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (visiblePasswordInputs.length >= 2 && normalized.includes('set your password')) {
      return {
        completed: null,
        reason: 'password-form-visible',
        message: text.slice(0, 1200),
        url,
      };
    }

    return {
      completed: true,
      reason: 'manual-verification-completed',
      message: text.slice(0, 1200),
      url,
    };
  });
}

async function waitForProtonManualVerification(page, browser, runtimeConfig, timeoutMs = 300000) {
  const stopAt = Date.now() + timeoutMs;
  let status = await protonManualVerificationStatus(page);
  let emailTabSelected = false;
  let verificationEmail = {
    filled: false,
    reason: 'email-tab-not-selected',
  };
  let webmail = {
    opened: false,
    reason: 'email-tab-not-selected',
  };
  let webmailAttempted = false;

  const tryEmailVerificationTab = async () => {
    const selected = await selectProtonEmailVerificationTab(page);

    if (!selected) {
      return false;
    }

    emailTabSelected = true;
    await pauseStep();

    if (
      verificationEmail.filled !== true
      && verificationEmail.reason !== 'verification-mailbox-not-configured'
    ) {
      verificationEmail = await fillProtonVerificationEmail(page, runtimeConfig);
    }

    if (!webmailAttempted) {
      webmailAttempted = true;
      webmail = await openVerificationWebmailPage(browser, runtimeConfig);
    }

    return true;
  };

  if (await tryEmailVerificationTab()) {
    status = await protonManualVerificationStatus(page);
  }

  progress(
    runtimeConfig,
    emailTabSelected ? 'proton-email-verification-selected' : 'proton-manual-verification-required',
    emailTabSelected
      ? 'Proton-Tab Email wurde fuer die Human Verification ausgewaehlt. Webmail-Portal wurde geoeffnet, sofern konfiguriert.'
      : 'Proton verlangt eine manuelle Human Verification. Bitte im geoeffneten Browser loesen.',
    await pageSnapshot(page, runtimeConfig, true, false),
  );

  while (status.completed === null && Date.now() < stopAt) {
    await sleep(2500);
    status = await protonManualVerificationStatus(page);

    if (
      status.completed === null
      && status.providerBlocked !== true
      && verificationEmail.filled !== true
      && verificationEmail.reason !== 'verification-mailbox-not-configured'
    ) {
      const selected = await tryEmailVerificationTab();

      if (selected) {
        status = await protonManualVerificationStatus(page);
      }
    }

    progress(
      runtimeConfig,
      'proton-manual-verification-required',
      emailTabSelected
        ? 'Warte auf Email-Human-Verification im geoeffneten Browser.'
        : 'Warte auf manuelle Human Verification im geoeffneten Browser.',
      await pageSnapshot(page, runtimeConfig, false, false),
    );
  }

  if (status.completed === true) {
    progress(
      runtimeConfig,
      'proton-manual-verification-completed',
      'Manuelle Human Verification wurde abgeschlossen; Registrierung wird fortgesetzt.',
      await pageSnapshot(page, runtimeConfig, true, false),
    );

    return {
      ...status,
      emailTabSelected,
      verificationEmail,
      webmailOpened: webmail.opened === true,
    };
  }

  return {
    completed: false,
    providerBlocked: status.providerBlocked === true,
    reason: status.reason || 'manual-verification-timeout',
    message: status.message || '',
    url: status.url || page.url(),
    emailTabSelected,
    verificationEmail,
    webmailOpened: webmail.opened === true,
  };
}

async function completeProtonPasswordStep(page, browser, runtimeConfig) {
  const password = generateAccountPassword(runtimeConfig.accountPasswordLength || runtimeConfig.passwordLength || 24);
  const passwordInputPair = await waitForVisiblePasswordInputPairIncludingFrames(page, 20000);

  if (!passwordInputPair) {
    progress(
      runtimeConfig,
      'proton-password-inputs-not-found',
      'Proton-Passwortfelder wurden nicht gefunden.',
      await pageSnapshot(page, runtimeConfig, true, true),
      'failed',
    );

    throw new Error('Proton-Passwortfelder wurden nicht gefunden.');
  }

  const enteredPassword = await fillInputValue(passwordInputPair.passwordInput, password);
  const enteredConfirmation = await fillInputValue(passwordInputPair.confirmationInput, password);

  if (enteredPassword !== password || enteredConfirmation !== password) {
    throw new Error('Proton-Passwort konnte nicht in beide Felder eingetragen werden.');
  }

  progress(
    runtimeConfig,
    'proton-password-entered',
    'Generiertes Proton-Passwort wurde in beide Felder eingetragen.',
    await pageSnapshot(page, runtimeConfig, true, false),
  );

  const clicked = await clickFirstMatchingButton(page, [
    'get started',
    'create account',
    'konto erstellen',
    'registrieren',
    'continue',
    'weiter',
    'next',
  ]);

  progress(
    runtimeConfig,
    'proton-password-submitted',
    clicked
      ? 'Proton-Passwortformular wurde abgesendet.'
      : 'Proton-Passwortformular wurde per Tastatur bestaetigt.',
    await pageSnapshot(page, runtimeConfig, false, false),
  );

  const submitStatus = await waitForProtonPasswordSubmit(
    page,
    runtimeConfig,
    Math.max(5000, Number(runtimeConfig.protonPasswordSubmitTimeoutMs || 20000)),
  );

  if (submitStatus.manualRequired) {
    const manualVerification = await waitForProtonManualVerification(
      page,
      browser,
      runtimeConfig,
      Math.max(60000, Number(runtimeConfig.manualVerificationTimeoutMs || runtimeConfig.observationTimeoutMs || 300000)),
    );

    return {
      password,
      passwordEntered: true,
      passwordSubmitted: clicked,
      passwordStepAdvanced: manualVerification.completed === true,
      passwordStepReason: manualVerification.reason,
      providerBlocked: manualVerification.providerBlocked === true,
      manualVerificationRequired: true,
      manualVerificationCompleted: manualVerification.completed === true,
      emailVerificationSelected: manualVerification.emailTabSelected === true,
      verificationEmailEntered: manualVerification.verificationEmail?.filled === true,
      verificationWebmailOpened: manualVerification.webmailOpened === true,
    };
  }

  if (submitStatus.providerBlocked) {
    progress(
      runtimeConfig,
      'proton-provider-blocked',
      'Proton blockiert weitere Registrierungen von diesem Netzwerk; der Lauf wird gestoppt.',
      await pageSnapshot(page, runtimeConfig, true, false),
      'failed',
    );

    return {
      password,
      passwordEntered: true,
      passwordSubmitted: clicked,
      passwordStepAdvanced: false,
      passwordStepReason: submitStatus.reason,
      providerBlocked: true,
      manualVerificationRequired: false,
      manualVerificationCompleted: false,
      emailVerificationSelected: false,
      verificationEmailEntered: false,
      verificationWebmailOpened: false,
    };
  }

  if (submitStatus.advanced === false) {
    progress(
      runtimeConfig,
      'proton-password-validation-failed',
      'Proton hat das generierte Passwort nicht akzeptiert.',
      await pageSnapshot(page, runtimeConfig, true, false),
      'failed',
    );

    throw new Error('Proton hat das generierte Passwort nicht akzeptiert.');
  }

  return {
    password,
    passwordEntered: true,
    passwordSubmitted: clicked,
    passwordStepAdvanced: submitStatus.advanced === true,
    passwordStepReason: submitStatus.reason,
    providerBlocked: false,
    manualVerificationRequired: false,
    manualVerificationCompleted: false,
    emailVerificationSelected: false,
    verificationEmailEntered: false,
    verificationWebmailOpened: false,
  };
}

async function protonUsernameStatus(page) {
  return page.evaluate(() => {
    const text = document.body?.innerText || '';
    const normalized = text.toLowerCase();
    const url = window.location.href;
    const providerBlockPattern = /potentially abusive traffic|blocked any further signups|appeal-abuse|support\/appeal-abuse|abusive traffic/;
    const passwordInput = document.querySelector('input[type="password"], input[name*="password" i], input[id*="password" i]');
    const invalidPatterns = [
      'already taken',
      'username is not available',
      'not available',
      'unavailable',
      'already used',
      'username already used',
      'already exists',
      'invalid username',
      'choose another',
      'please try another',
      'ist nicht verf',
      'bereits vergeben',
      'bereits verwendet',
      'nicht verfügbar',
    ];

    if (providerBlockPattern.test(normalized)) {
      return {
        available: false,
        providerBlocked: true,
        reason: 'provider-abuse-block',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (invalidPatterns.some((pattern) => normalized.includes(pattern))) {
      return {
        available: false,
        reason: 'username-unavailable',
        message: text.slice(0, 1200),
        url,
      };
    }

    if (
      passwordInput
      || /create (a )?password|set (a )?password|choose (a )?password|human verification|captcha|verify you.?re human/.test(normalized)
    ) {
      return {
        available: true,
        reason: 'advanced-to-next-step',
        message: text.slice(0, 1200),
        url,
      };
    }

    return {
      available: null,
      reason: 'pending',
      message: text.slice(0, 1200),
      url,
    };
  });
}

async function waitForProtonUsernameStatus(page, runtimeConfig) {
  const stopAt = Date.now() + Math.max(10000, Number(runtimeConfig.protonUsernameCheckTimeoutMs || 30000));
  let status = await protonUsernameStatus(page);

  while (status.available === null && Date.now() < stopAt) {
    await sleep(1000);
    status = await protonUsernameStatus(page);

    progress(
      runtimeConfig,
      'proton-checking-username',
      'Proton prueft den Username.',
      await pageSnapshot(page, runtimeConfig, false, true),
    );
  }

  return status;
}

async function submitProtonUsernameCandidate(page, runtimeConfig, username, usernameInputSelectors) {
  const usernameInput = await waitForVisibleProtonUsernameInputIncludingFrames(page, usernameInputSelectors, 20000);

  if (!usernameInput) {
    progress(
      runtimeConfig,
      'proton-username-input-not-found',
      'Proton-Username-Feld wurde nicht gefunden.',
      await pageSnapshot(page, runtimeConfig, true, true),
      'failed',
    );

    throw new Error('Proton-Username-Feld wurde nicht gefunden.');
  }

  let enteredUsername = await fillInputValue(usernameInput, username);

  if (enteredUsername !== username) {
    enteredUsername = await forceInputValue(usernameInput, username);
  }

  if (enteredUsername !== username) {
    progress(
      runtimeConfig,
      'proton-username-fill-failed',
      `Username-Feld wurde gefunden, aber nicht korrekt befuellt. Erwartet: "${username}", Feldwert: "${enteredUsername}".`,
      await pageSnapshot(page, runtimeConfig, true, true),
      'failed',
    );

    throw new Error('Proton-Username-Feld konnte nicht befuellt werden.');
  }

  progress(
    runtimeConfig,
    'proton-username-entered',
    `Username "${username}" wurde eingetragen. Feldwert: "${enteredUsername}".`,
    await pageSnapshot(page, runtimeConfig, true, true),
  );

  await clickFirstMatchingButton(page, [
    'create free account',
    'create account',
    'create free account now',
    'kostenloses konto',
    'konto jetzt erstellen',
    'kostenloses konto jetzt erstellen',
    'continue',
    'weiter',
    'next',
    'free account',
  ]);

  await pauseStep();

  return waitForProtonUsernameStatus(page, runtimeConfig);
}

async function runProtonUsernameCheckProvider(page, browser, runtimeConfig, provider) {
  const requestedUsername = usernameFromSubject(runtimeConfig);

  if (!requestedUsername) {
    throw new Error('Fuer Proton wird ein Username oder eine gewuenschte E-Mail-Adresse benoetigt.');
  }

  progress(runtimeConfig, 'proton-opening', 'Proton-Registrierungsseite wird geoeffnet.');

  await page.goto(provider.registrationUrl, {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  progress(
    runtimeConfig,
    'proton-page-loaded',
    'Proton-Startseite ist geladen.',
    await pageSnapshot(page, runtimeConfig, true, true),
  );

  const usernameInputSelectors = [
    'input[name="username"]',
    'input[id="username"]',
    'input[name*="username" i]',
    'input[id*="username" i]',
    'input[autocomplete="username"]',
  ];
  const usernameCandidates = generateUsernameCandidates(
    requestedUsername,
    runtimeConfig.protonUsernameMaxAttempts || runtimeConfig.usernameMaxAttempts || 12,
  );
  let username = requestedUsername;
  let status = {
    available: null,
    reason: 'pending',
    message: '',
    url: page.url(),
  };

  for (let attemptIndex = 0; attemptIndex < usernameCandidates.length; attemptIndex += 1) {
    username = usernameCandidates[attemptIndex];

    if (attemptIndex > 0) {
      progress(
        runtimeConfig,
        'proton-username-retrying',
        `Username ist belegt; versuche Variante "${username}" (${attemptIndex + 1}/${usernameCandidates.length}).`,
        await pageSnapshot(page, runtimeConfig, true, true),
      );
    }

    status = await submitProtonUsernameCandidate(page, runtimeConfig, username, usernameInputSelectors);

    if (status.providerBlocked) {
      progress(
        runtimeConfig,
        'proton-provider-blocked',
        'Proton blockiert weitere Registrierungen von diesem Netzwerk; es werden keine weiteren Username-Varianten versucht.',
        await pageSnapshot(page, runtimeConfig, true, true),
        'failed',
      );
      break;
    }

    if (status.available !== false) {
      break;
    }

    progress(
      runtimeConfig,
      'proton-username-unavailable',
      `Username "${username}" ist bei Proton belegt.`,
      await pageSnapshot(page, runtimeConfig, true, true),
    );
  }

  const snapshot = await pageSnapshot(page, runtimeConfig, true, true);
  let passwordStep = null;

  if (status.available === true) {
    passwordStep = await completeProtonPasswordStep(page, browser, runtimeConfig);
  }

  const finalSnapshot = passwordStep
    ? await pageSnapshot(page, runtimeConfig, true, false)
    : snapshot;

  const completed = status.available === true
    && (!passwordStep || (
      passwordStep.passwordEntered
      && passwordStep.providerBlocked !== true
      && (!passwordStep.manualVerificationRequired || passwordStep.manualVerificationCompleted)
    ));

  return {
    completed,
    completionReason: passwordStep?.passwordStepReason || status.reason,
    username,
    email: `${username}@proton.me`,
    usernameAvailable: status.available === true,
    providerBlocked: status.providerBlocked === true || passwordStep?.providerBlocked === true,
    password: passwordStep?.password || null,
    passwordEntered: passwordStep?.passwordEntered || false,
    passwordSubmitted: passwordStep?.passwordSubmitted || false,
    passwordStepAdvanced: passwordStep?.passwordStepAdvanced || false,
    manualVerificationRequired: passwordStep?.manualVerificationRequired || false,
    manualVerificationCompleted: passwordStep?.manualVerificationCompleted || false,
    emailVerificationSelected: passwordStep?.emailVerificationSelected || false,
    verificationEmailEntered: passwordStep?.verificationEmailEntered || false,
    verificationWebmailOpened: passwordStep?.verificationWebmailOpened || false,
    finalUrl: finalSnapshot.finalUrl,
    title: finalSnapshot.title,
    liveScreenshotPath: finalSnapshot.liveScreenshotPath || null,
    liveScreenshotAt: finalSnapshot.liveScreenshotAt || null,
    statusMessage: (status.providerBlocked === true || passwordStep?.providerBlocked === true)
      ? 'Proton blockiert weitere Registrierungen von diesem Netzwerk. Der Lauf wurde gestoppt.'
      : status.available === true
      ? (passwordStep?.manualVerificationRequired && !passwordStep?.manualVerificationCompleted
        ? 'Proton verlangt eine manuelle Human Verification; der Lauf wurde ohne Abschluss beendet.'
        : passwordStep?.passwordStepAdvanced
        ? 'Proton-Passwort wurde gesetzt; naechster Registrierungsschritt wurde erreicht.'
        : 'Proton-Passwort wurde generiert, eingetragen und abgesendet.')
      : 'Proton-Username ist nicht verfuegbar oder konnte nicht bestaetigt werden.',
  };
}

async function runObservedManualProvider(page, runtimeConfig, provider) {
  progress(runtimeConfig, 'provider-opening', 'Registrierungsseite wird geoeffnet.');

  await page.goto(provider.registrationUrl, {
    waitUntil: 'domcontentloaded',
    timeout: Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)),
  });

  progress(
    runtimeConfig,
    'provider-page-loaded',
    'Registrierungsseite ist geladen.',
    await pageSnapshot(page, runtimeConfig, true),
  );

  const timeoutMs = Math.max(30000, Number(runtimeConfig.observationTimeoutMs || 300000));
  const stopAt = Date.now() + timeoutMs;
  let completion = await detectManualCompletion(page, provider);

  while (!completion.completed && Date.now() < stopAt) {
    await sleep(1500);
    completion = await detectManualCompletion(page, provider);

    progress(
      runtimeConfig,
      'observing',
      'Browserflow wird beobachtet.',
      await pageSnapshot(page, runtimeConfig),
    );
  }

  const snapshot = await pageSnapshot(page, runtimeConfig, true);

  return {
    completed: completion.completed,
    completionReason: completion.reason,
    finalUrl: snapshot.finalUrl,
    title: snapshot.title,
    liveScreenshotPath: snapshot.liveScreenshotPath || null,
    liveScreenshotAt: snapshot.liveScreenshotAt || null,
  };
}

function buildAccountPayload(runtimeConfig, provider, providerResult) {
  const subject = runtimeConfig.subject || {};
  const desiredEmail = normalizeText(subject.desiredEmail || subject.desired_email);
  const username = normalizeText(subject.accountUsername || subject.account_username || desiredEmail);
  const providerMode = normalizeText(provider.mode);
  const completed = typeof providerResult === 'boolean'
    ? providerResult
    : Boolean(providerResult?.completed);

  if (!completed && providerMode !== PROVIDER_MODE_PROTON_USERNAME_CHECK) {
    return null;
  }

  if (providerMode === PROVIDER_MODE_PROTON_USERNAME_CHECK) {
    const protonUsername = trimUsernameCandidate(providerResult?.username || usernameFromSubject(runtimeConfig));

    if (!completed || !protonUsername) {
      return null;
    }

    const password = normalizeText(providerResult?.password);

    return {
      email: `${protonUsername}@proton.me`,
      username: protonUsername,
      provider: provider.label || 'Proton',
      webmailUrl: normalizeText(provider.webmailUrl || provider.webmail_url || 'https://mail.proton.me'),
      recoveryEmail: normalizeText(subject.recoveryEmail || subject.recovery_email),
      ...(password ? { password } : {}),
    };
  }

  if (!desiredEmail) {
    return null;
  }

  return {
    email: desiredEmail,
    username: username || desiredEmail,
    provider: provider.label || provider.key || 'Mail Provider',
    webmailUrl: normalizeText(provider.webmailUrl || provider.webmail_url),
    recoveryEmail: normalizeText(subject.recoveryEmail || subject.recovery_email),
  };
}

function writeResult(runtimeConfig, result) {
  const resultPath = normalizeText(runtimeConfig.resultPath);

  if (resultPath) {
    writeJsonFile(resultPath, result);
  }

  console.log(JSON.stringify(result));
}

async function main() {
  if (!runtimeConfigPath) {
    throw new Error('Runtime-Konfigurationspfad fehlt.');
  }

  const runtimeConfig = readJsonFile(runtimeConfigPath, null);

  if (!runtimeConfig || typeof runtimeConfig !== 'object') {
    throw new Error(`Runtime-Konfiguration konnte nicht gelesen werden: ${runtimeConfigPath}`);
  }

  const provider = validateProvider(runtimeConfig);
  runtimeConfig.provider = provider;
  requestedBrowserEngine = resolveBrowserEngine(runtimeConfig);

  progress(runtimeConfig, 'starting', 'Mail-Registrierung wird vorbereitet.', {}, 'starting');

  const launchOptions = {
    headless: runtimeConfig.headlessEnabled === true ? 'new' : false,
    defaultViewport: DEFAULT_VIEWPORT,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      `--window-size=${DEFAULT_VIEWPORT.width},${DEFAULT_VIEWPORT.height}`,
    ],
  };

  if (runtimeConfig.browserProfilePath) {
    launchOptions.userDataDir = runtimeConfig.browserProfilePath;
    ensureDirectory(runtimeConfig.browserProfilePath);
  }

  let browser = null;
  let page = null;

  try {
    const launchResult = await launchConfiguredBrowser({
      puppeteer,
      runtimeConfig,
      launchOptions,
    });

    browser = launchResult.browser;
    activeBrowserEngine = launchResult.activeEngine;
    browserFallbackReason = launchResult.fallbackReason;

    progress(runtimeConfig, 'browser-started', 'Browser wurde gestartet.', {
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
    });

    page = await browser.newPage();
    page.setDefaultNavigationTimeout(Math.max(15000, Number(runtimeConfig.navigationTimeoutMs || 120000)));
    await page.setViewport(DEFAULT_VIEWPORT);

    const providerResult = provider.mode === PROVIDER_MODE_PROTON_USERNAME_CHECK
      ? await runProtonUsernameCheckProvider(page, browser, runtimeConfig, provider)
      : await runObservedManualProvider(page, runtimeConfig, provider);
    const account = buildAccountPayload(runtimeConfig, provider, providerResult);
    const ok = providerResult.completed;
    const providerBlocked = providerResult.providerBlocked === true;
    const result = {
      ok,
      statusLevel: ok ? 'success' : (providerBlocked ? 'error' : 'partial'),
      statusMessage: ok
        ? (providerResult.statusMessage || 'Mail-Registrierung wurde als abgeschlossen erkannt.')
        : (providerResult.statusMessage || 'Beobachtung beendet. Eine automatische Registrierung wurde nicht bestaetigt.'),
      runId: runtimeConfig.runId || null,
      providerKey: provider.key || null,
      providerLabel: provider.label || null,
      providerMode: provider.mode,
      registrationCompleted: ok,
      completionReason: providerResult.completionReason,
      usernameAvailable: providerResult.usernameAvailable ?? null,
      providerBlocked,
      emailVerificationSelected: providerResult.emailVerificationSelected ?? null,
      verificationEmailEntered: providerResult.verificationEmailEntered ?? null,
      verificationWebmailOpened: providerResult.verificationWebmailOpened ?? null,
      account,
      finalUrl: providerResult.finalUrl,
      title: providerResult.title,
      liveScreenshotPath: providerResult.liveScreenshotPath,
      liveScreenshotAt: providerResult.liveScreenshotAt,
      requestedBrowserEngine,
      activeBrowserEngine,
      browserFallbackReason,
      finishedAt: new Date().toISOString(),
    };

    progress(
      runtimeConfig,
      ok ? 'completed' : 'observation-ended',
      result.statusMessage,
      providerResult,
      'completed',
    );

    writeResult(runtimeConfig, result);
  } finally {
    if (browser) {
      await browser.close().catch(() => {});
    }
  }
}

main().catch((error) => {
  const runtimeConfig = runtimeConfigPath ? readJsonFile(runtimeConfigPath, {}) : {};
  const message = normalizeText(error?.message || String(error));
  const result = {
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Mail-Registrierung konnte nicht ausgefuehrt werden.',
    runId: runtimeConfig.runId || null,
    providerKey: runtimeConfig.provider?.key || null,
    providerLabel: runtimeConfig.provider?.label || null,
    error: message,
    requestedBrowserEngine,
    activeBrowserEngine,
    browserFallbackReason,
    finishedAt: new Date().toISOString(),
  };

  try {
    progress(runtimeConfig, 'failed', message, {}, 'failed');
    writeResult(runtimeConfig, result);
  } catch {
    console.log(JSON.stringify(result));
  }

  process.exitCode = 1;
});
