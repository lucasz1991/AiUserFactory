'use strict';

const DEFAULT_LIST_SELECTORS = [
  '[role="list"]',
  '[role="grid"]',
  '[aria-label*="inbox" i]',
  '[aria-label*="posteingang" i]',
  '[class*="inbox" i]',
  '[class*="mail" i]',
  'tbody',
  'ul',
  'ol',
  'main',
  'body',
];

const DEFAULT_ITEM_SELECTORS = [
  '[data-test*="mail" i]',
  '[data-testid*="mail" i]',
  '[data-qa*="mail" i]',
  '[aria-label*="mail" i]',
  '[class*="mail" i]',
  '[class*="message" i]',
  '[role="row"]',
  '[role="option"]',
  '[role="listitem"]',
  'tr',
  'li',
  'a',
  'button',
];

const DEFAULT_SUBJECT_SELECTORS = [
  '[data-testid*="subject" i]',
  '[data-test*="subject" i]',
  '[class*="subject" i]',
  '[class*="betreff" i]',
  '[aria-label*="subject" i]',
];

const DEFAULT_SENDER_SELECTORS = [
  '[data-testid*="sender" i]',
  '[data-test*="sender" i]',
  '[class*="sender" i]',
  '[class*="from" i]',
  '[class*="absender" i]',
  '[aria-label*="sender" i]',
];

const DEFAULT_DATE_SELECTORS = [
  'time',
  '[datetime]',
  '[data-testid*="date" i]',
  '[data-test*="date" i]',
  '[class*="date" i]',
  '[class*="time" i]',
  '[class*="datum" i]',
];

const DEFAULT_PREVIEW_SELECTORS = [
  '[data-testid*="preview" i]',
  '[data-test*="preview" i]',
  '[class*="preview" i]',
  '[class*="snippet" i]',
  '[class*="teaser" i]',
];

const DEFAULT_BODY_SELECTORS = [
  '[data-testid*="body" i]',
  '[data-test*="body" i]',
  '[class*="mail-body" i]',
  '[class*="message-body" i]',
  '[class*="email-body" i]',
  '[role="document"]',
  'article',
  'main',
  'body',
];

function normalizeText(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function lowerText(value) {
  return normalizeText(value).toLowerCase();
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function parseJsonObject(value) {
  if (!value || typeof value === 'number' || typeof value === 'boolean') {
    return {};
  }

  if (typeof value === 'object' && !Array.isArray(value)) {
    return value;
  }

  const text = normalizeText(value);

  if (!text || !/^[{[]/.test(text)) {
    return {};
  }

  try {
    const parsed = JSON.parse(text);

    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function scalarInputValue(input = {}) {
  const value = input.value ?? input.inputValue ?? input.input_value ?? '';

  if (value && typeof value === 'object') {
    return '';
  }

  const text = normalizeText(value);

  return Object.keys(parseJsonObject(text)).length > 0 ? '' : text;
}

function taskOptions(input = {}) {
  return {
    ...parseJsonObject(input.options),
    ...parseJsonObject(input.config),
    ...parseJsonObject(input.success_payload),
    ...parseJsonObject(input.successPayload),
    ...parseJsonObject(input.value),
    ...parseJsonObject(input.inputValue),
    ...parseJsonObject(input.input_value),
  };
}

function valueForKey(source = {}, key) {
  if (!source || typeof source !== 'object') {
    return undefined;
  }

  if (Object.prototype.hasOwnProperty.call(source, key)) {
    return source[key];
  }

  const camel = key.replace(/_([a-z])/g, (_match, letter) => letter.toUpperCase());

  if (Object.prototype.hasOwnProperty.call(source, camel)) {
    return source[camel];
  }

  return undefined;
}

function firstOption(options = {}, input = {}, keys = []) {
  for (const key of keys) {
    const optionValue = valueForKey(options, key);

    if (optionValue !== undefined && optionValue !== null && optionValue !== '') {
      return optionValue;
    }

    const inputValue = valueForKey(input, key);

    if (inputValue !== undefined && inputValue !== null && inputValue !== '') {
      return inputValue;
    }
  }

  return undefined;
}

function optionString(options = {}, input = {}, keys = [], fallback = '') {
  const value = firstOption(options, input, keys);

  if (value === undefined || value === null) {
    return fallback;
  }

  return normalizeText(value);
}

function optionNumber(options = {}, input = {}, keys = [], fallback = 0) {
  const value = firstOption(options, input, keys);
  const number = Number(value);

  return Number.isFinite(number) ? number : fallback;
}

function optionBoolean(options = {}, input = {}, keys = [], fallback = false) {
  const value = firstOption(options, input, keys);

  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  if (typeof value === 'boolean') {
    return value;
  }

  const text = lowerText(value);

  if (['1', 'true', 'yes', 'ja', 'on'].includes(text)) {
    return true;
  }

  if (['0', 'false', 'no', 'nein', 'off'].includes(text)) {
    return false;
  }

  return fallback;
}

function selectorsFrom(value, fallback = []) {
  if (Array.isArray(value)) {
    return value.map(normalizeText).filter(Boolean);
  }

  const objectValue = parseJsonObject(value);

  if (Array.isArray(objectValue.selectors)) {
    return objectValue.selectors.map(normalizeText).filter(Boolean);
  }

  const text = normalizeText(value);

  if (!text) {
    return fallback;
  }

  try {
    const parsed = JSON.parse(text);

    if (Array.isArray(parsed)) {
      return parsed.map(normalizeText).filter(Boolean);
    }
  } catch {
    // Plain CSS selectors are expected here, including comma groups.
  }

  return text
    .split(/\r?\n|;/)
    .map(normalizeText)
    .filter(Boolean);
}

function maxAgeSeconds(options = {}, input = {}, fallback = 0) {
  const direct = optionNumber(options, input, [
    'max_age_seconds',
    'since_seconds',
    'age_seconds',
    'time_window_seconds',
  ], 0);

  if (direct > 0) {
    return direct;
  }

  const minutes = optionNumber(options, input, [
    'max_age_minutes',
    'since_minutes',
    'time_window_minutes',
    'minutes',
  ], 0);

  return minutes > 0 ? minutes * 60 : fallback;
}

function ageSecondsFromText(value, now = new Date()) {
  const text = lowerText(value);

  if (!text) {
    return null;
  }

  if (/(gerade eben|soeben|jetzt|now|just now)/.test(text)) {
    return 0;
  }

  let match = text.match(/(?:vor\s*)?(\d{1,3})\s*(?:sek|sec|second|seconds|sekunden)\b/);

  if (match) {
    return Number(match[1]);
  }

  match = text.match(/(?:vor\s*)?(\d{1,3})\s*(?:min|minute|minutes|minuten)\b/);

  if (match) {
    return Number(match[1]) * 60;
  }

  match = text.match(/(?:vor\s*)?(\d{1,2})\s*(?:h|std|stunde|stunden|hour|hours)\b/);

  if (match) {
    return Number(match[1]) * 3600;
  }

  match = text.match(/(?:vor\s*)?(\d{1,2})\s*(?:d|tag|tage|day|days)\b/);

  if (match) {
    return Number(match[1]) * 86400;
  }

  match = text.match(/(?:heute|today)?\s*(\d{1,2})[:.](\d{2})/);

  if (match) {
    const date = new Date(now);
    date.setHours(Number(match[1]), Number(match[2]), 0, 0);

    let age = Math.round((now.getTime() - date.getTime()) / 1000);

    if (age < -3600) {
      age += 86400;
    }

    return age >= 0 ? age : null;
  }

  const parsed = Date.parse(normalizeText(value));

  if (Number.isFinite(parsed)) {
    const age = Math.round((now.getTime() - parsed) / 1000);

    return age >= 0 ? age : null;
  }

  return null;
}

function framesForPage(page) {
  if (!page) {
    return [];
  }

  if (typeof page.frames === 'function') {
    const frames = page.frames();

    return Array.isArray(frames) && frames.length > 0 ? frames : [page];
  }

  return [page];
}

async function scanMailList(page, options = {}) {
  const listSelectors = selectorsFrom(options.listSelector || options.list_selector, DEFAULT_LIST_SELECTORS);
  const itemSelectors = selectorsFrom(options.listItemSelector || options.list_item_selector, DEFAULT_ITEM_SELECTORS);
  const subjectSelectors = selectorsFrom(options.subjectSelector || options.subject_selector, DEFAULT_SUBJECT_SELECTORS);
  const senderSelectors = selectorsFrom(options.senderSelector || options.sender_selector, DEFAULT_SENDER_SELECTORS);
  const dateSelectors = selectorsFrom(options.dateSelector || options.date_selector, DEFAULT_DATE_SELECTORS);
  const previewSelectors = selectorsFrom(options.previewSelector || options.preview_selector, DEFAULT_PREVIEW_SELECTORS);
  const limit = Math.max(1, Math.min(200, Number(options.maxItems || options.max_items || options.limit || 50)));
  const tokenPrefix = normalizeText(options.tokenPrefix || options.token_prefix || `wf-mail-${Date.now()}`);
  const rows = [];

  for (const [frameIndex, frame] of framesForPage(page).entries()) {
    if (!frame || typeof frame.evaluate !== 'function') {
      continue;
    }

    const frameRows = await frame.evaluate((payload) => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const deepQueryAll = (selector, root = document) => {
        const matches = [];
        const visit = (node) => {
          if (!node || typeof node.querySelectorAll !== 'function') {
            return;
          }

          try {
            matches.push(...Array.from(node.querySelectorAll(selector)));
          } catch {
            return;
          }

          const all = node.querySelectorAll ? Array.from(node.querySelectorAll('*')) : [];

          for (const element of all) {
            if (element.shadowRoot) {
              visit(element.shadowRoot);
            }
          }
        };

        visit(root);

        return Array.from(new Set(matches));
      };
      const visible = (element) => {
        if (!element || typeof element.getBoundingClientRect !== 'function') {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 20
          && rect.height > 8
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && Number(style.opacity || 1) > 0;
      };
      const textFor = (element, selectors) => {
        for (const selector of selectors || []) {
          const match = deepQueryAll(selector, element).find(visible);
          const text = normalize([
            match?.getAttribute?.('aria-label'),
            match?.getAttribute?.('title'),
            match?.getAttribute?.('datetime'),
            match?.innerText,
            match?.textContent,
          ].filter(Boolean).join(' '));

          if (text) {
            return text;
          }
        }

        return '';
      };
      const containers = payload.listSelectors
        .flatMap((selector) => deepQueryAll(selector).filter(visible));
      const roots = containers.length > 0 ? containers : [document];
      const seen = new Set();
      const rows = [];

      for (const root of roots) {
        for (const selector of payload.itemSelectors) {
          const elements = deepQueryAll(selector, root);

          elements.forEach((element, selectorIndex) => {
            if (!visible(element)) {
              return;
            }

            const rawText = normalize([
              element.getAttribute('aria-label'),
              element.getAttribute('title'),
              element.innerText,
              element.textContent,
            ].filter(Boolean).join(' '));

            if (rawText.length < payload.minTextLength || rawText.length > payload.maxTextLength) {
              return;
            }

            const rect = element.getBoundingClientRect();
            const subject = textFor(element, payload.subjectSelectors);
            const sender = textFor(element, payload.senderSelectors);
            const dateText = textFor(element, payload.dateSelectors);
            const preview = textFor(element, payload.previewSelectors);
            const key = `${Math.round(rect.top)}:${Math.round(rect.left)}:${rawText.slice(0, 160)}`;

            if (seen.has(key)) {
              return;
            }

            seen.add(key);

            const token = `${payload.tokenPrefix}-${rows.length}`;

            try {
              element.setAttribute('data-workflow-mail-candidate', token);
            } catch {
              // Some nodes inside foreign trees can be readonly. Fallback click still uses text/selector.
            }

            rows.push({
              token,
              selector,
              selectorIndex,
              subject,
              sender,
              dateText,
              preview,
              text: rawText,
              unread: /(^|\s)(unread|ungelesen)(\s|$)/i.test(rawText)
                || element.getAttribute('aria-selected') === 'true'
                || element.matches('[class*="unread" i],[aria-label*="unread" i],[aria-label*="ungelesen" i]'),
              top: Math.round(rect.top),
              left: Math.round(rect.left),
              width: Math.round(rect.width),
              height: Math.round(rect.height),
            });
          });
        }
      }

      return rows.slice(0, payload.limit);
    }, {
      listSelectors,
      itemSelectors,
      subjectSelectors,
      senderSelectors,
      dateSelectors,
      previewSelectors,
      limit,
      minTextLength: Math.max(0, Number(options.minTextLength || options.min_text_length || 4)),
      maxTextLength: Math.max(80, Number(options.maxTextLength || options.max_text_length || 2000)),
      tokenPrefix: `${tokenPrefix}-${frameIndex}`,
    }).catch(() => []);

    const frameUrl = typeof frame.url === 'function' ? frame.url() : '';
    const frameName = typeof frame.name === 'function' ? frame.name() : '';

    for (const row of frameRows) {
      rows.push({
        ...row,
        index: rows.length,
        frameIndex,
        frameUrl,
        frameName,
        ageSeconds: ageSecondsFromText(`${row.dateText} ${row.text}`),
      });
    }
  }

  return rows.slice(0, limit);
}

async function clickMailCandidate(page, candidate = {}, options = {}) {
  const token = normalizeText(candidate.token);
  const selector = normalizeText(candidate.selector || options.listItemSelector || options.list_item_selector);
  const selectorIndex = Number.isFinite(Number(candidate.selectorIndex)) ? Number(candidate.selectorIndex) : null;
  const expectedText = normalizeText(candidate.text || candidate.subject || candidate.preview);

  for (const frame of framesForPage(page)) {
    if (!frame || typeof frame.evaluate !== 'function') {
      continue;
    }

    const clicked = await frame.evaluate((payload) => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const deepQueryAll = (selectorValue, root = document) => {
        const matches = [];
        const visit = (node) => {
          if (!node || typeof node.querySelectorAll !== 'function') {
            return;
          }

          try {
            matches.push(...Array.from(node.querySelectorAll(selectorValue)));
          } catch {
            return;
          }

          const all = node.querySelectorAll ? Array.from(node.querySelectorAll('*')) : [];

          for (const element of all) {
            if (element.shadowRoot) {
              visit(element.shadowRoot);
            }
          }
        };

        visit(root);

        return Array.from(new Set(matches));
      };
      const visible = (element) => {
        if (!element || typeof element.getBoundingClientRect !== 'function') {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 20
          && rect.height > 8
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && Number(style.opacity || 1) > 0;
      };
      const clickElement = (element) => {
        if (!element) {
          return false;
        }

        const clickable = element.closest?.('a,button,[role="button"],[role="row"],[role="option"],[role="listitem"],tr,li')
          || element;

        clickable.scrollIntoView?.({ block: 'center', inline: 'center' });
        clickable.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, cancelable: true, view: window }));
        clickable.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window }));
        clickable.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, view: window }));
        clickable.click();

        return true;
      };

      if (payload.token) {
        const byToken = deepQueryAll(`[data-workflow-mail-candidate="${payload.token}"]`).find(visible);

        if (byToken) {
          return clickElement(byToken);
        }
      }

      if (payload.selector) {
        const matches = deepQueryAll(payload.selector).filter(visible);

        if (payload.selectorIndex !== null && matches[payload.selectorIndex]) {
          return clickElement(matches[payload.selectorIndex]);
        }

        if (payload.expectedText) {
          const expected = payload.expectedText.toLowerCase().slice(0, 160);
          const byText = matches.find((element) => normalize(element.innerText || element.textContent).toLowerCase().includes(expected));

          if (byText) {
            return clickElement(byText);
          }
        }

        if (matches[0]) {
          return clickElement(matches[0]);
        }
      }

      return false;
    }, {
      token,
      selector,
      selectorIndex,
      expectedText,
    }).catch(() => false);

    if (clicked) {
      return true;
    }
  }

  return false;
}

async function readTextFromFrames(page, selectors = DEFAULT_BODY_SELECTORS) {
  const selectorList = selectorsFrom(selectors, DEFAULT_BODY_SELECTORS);
  const chunks = [];

  for (const frame of framesForPage(page)) {
    if (!frame || typeof frame.evaluate !== 'function') {
      continue;
    }

    const payload = await frame.evaluate((payloadSelectors) => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
      const deepQueryAll = (selector, root = document) => {
        const matches = [];
        const visit = (node) => {
          if (!node || typeof node.querySelectorAll !== 'function') {
            return;
          }

          try {
            matches.push(...Array.from(node.querySelectorAll(selector)));
          } catch {
            return;
          }

          const all = node.querySelectorAll ? Array.from(node.querySelectorAll('*')) : [];

          for (const element of all) {
            if (element.shadowRoot) {
              visit(element.shadowRoot);
            }
          }
        };

        visit(root);

        return Array.from(new Set(matches));
      };
      const visible = (element) => {
        if (!element || typeof element.getBoundingClientRect !== 'function') {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 20
          && rect.height > 8
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && Number(style.opacity || 1) > 0;
      };
      const texts = [];

      for (const selector of payloadSelectors) {
        for (const element of deepQueryAll(selector).filter(visible)) {
          const text = normalize([
            element.getAttribute('aria-label'),
            element.getAttribute('title'),
            element.innerText,
            element.textContent,
          ].filter(Boolean).join(' '));

          if (text) {
            texts.push(text);
          }
        }

        if (texts.length > 0) {
          break;
        }
      }

      if (texts.length === 0 && document.body) {
        texts.push(normalize(document.body.innerText || document.body.textContent || ''));
      }

      return {
        url: window.location.href,
        title: document.title || '',
        text: Array.from(new Set(texts)).join('\n'),
      };
    }, selectorList).catch(() => null);

    if (payload && payload.text) {
      chunks.push(payload);
    }
  }

  return chunks;
}

function valueFromPath(root = {}, path = '') {
  const normalized = normalizeText(path);

  if (!normalized) {
    return undefined;
  }

  return normalized
    .split('.')
    .filter(Boolean)
    .reduce((value, key) => {
      if (value === undefined || value === null) {
        return undefined;
      }

      if (Array.isArray(value) && /^\d+$/.test(key)) {
        return value[Number(key)];
      }

      return value[key];
    }, root);
}

function workflowVariableRoot(context = {}) {
  const workflowVariables = {
    ...(context.workflow_variables && typeof context.workflow_variables === 'object' ? context.workflow_variables : {}),
    ...(context.workflowVariables && typeof context.workflowVariables === 'object' ? context.workflowVariables : {}),
    ...(context.lastResult?.workflow_variables && typeof context.lastResult.workflow_variables === 'object' ? context.lastResult.workflow_variables : {}),
    ...(context.lastResult?.workflowVariables && typeof context.lastResult.workflowVariables === 'object' ? context.lastResult.workflowVariables : {}),
  };

  return {
    ...context,
    workflow_variables: workflowVariables,
    workflowVariables,
    lastResult: context.lastResult || {},
  };
}

function variableName(value, fallback) {
  let name = normalizeText(value || fallback)
    .replace(/^workflow[_-]?variables\./i, '')
    .replace(/\s+/g, '_')
    .replace(/[^A-Za-z0-9_.-]+/g, '')
    .slice(0, 120);

  if (!name) {
    name = fallback;
  }

  return name;
}

function setWorkflowVariable(context = {}, name, value) {
  const key = variableName(name, 'mail_value');

  context.workflow_variables = {
    ...(context.workflow_variables || {}),
    [key]: value,
  };
  context.workflowVariables = {
    ...(context.workflowVariables || {}),
    [key]: value,
  };

  return key;
}

function extractByRegex(text, pattern, flags = 'i', group = 1) {
  const normalized = normalizeText(text);

  if (!pattern || !normalized) {
    return null;
  }

  let regex;

  try {
    regex = new RegExp(pattern, flags);
  } catch {
    return null;
  }

  const match = normalized.match(regex);

  if (!match) {
    return null;
  }

  const index = Number.isFinite(Number(group)) ? Number(group) : 1;

  return normalizeText(match[index] || match[0]) || null;
}

function extractVerificationCode(text, query = '') {
  const normalized = normalizeText(text);
  const candidates = [];
  const patterns = [
    /(?:code|passcode|pin|token|verification|verifizierung|sicherheitscode|bestaetigungscode)[^\dA-Z]{0,40}([A-Z0-9][A-Z0-9\s-]{2,12}[A-Z0-9])/gi,
    /(?:^|[^\d])(\d[\d\s-]{2,10}\d)(?!\d)/g,
  ];

  for (const pattern of patterns) {
    let match;

    while ((match = pattern.exec(normalized)) !== null) {
      const code = String(match[1] || '').replace(/[^A-Z0-9]/gi, '').toUpperCase();

      if (code.length < 4 || code.length > 10 || !/\d/.test(code)) {
        continue;
      }

      const start = Math.max(0, match.index - 140);
      const end = Math.min(normalized.length, match.index + 190);
      const snippet = normalized.slice(start, end).trim();
      let score = /^\d{6}$/.test(code) ? 20 : 8;

      if (query && lowerText(snippet).includes(lowerText(query))) {
        score += 20;
      }

      candidates.push({ value: code, snippet, score });
    }
  }

  candidates.sort((left, right) => right.score - left.score);

  return candidates[0] || null;
}

function extractValueFromText(text, options = {}) {
  const mode = lowerText(options.extractMode || options.extract_mode || options.mode || (options.regex || options.extract_regex ? 'regex' : 'verification_code'));
  const query = normalizeText(options.searchText || options.search_text || options.query || '');

  if (mode === 'regex') {
    const value = extractByRegex(
      text,
      options.regex || options.extractRegex || options.extract_regex || '',
      options.regexFlags || options.regex_flags || 'i',
      options.regexGroup || options.regex_group || 1,
    );

    return value ? { value, snippet: snippetAround(text, value) } : null;
  }

  if (mode === 'contains') {
    const needle = normalizeText(options.contains || options.searchText || options.search_text || '');

    return needle && lowerText(text).includes(lowerText(needle))
      ? { value: needle, snippet: snippetAround(text, needle) }
      : null;
  }

  return extractVerificationCode(text, query);
}

function snippetAround(text, value, radius = 120) {
  const normalized = normalizeText(text);
  const needle = normalizeText(value);
  const index = lowerText(normalized).indexOf(lowerText(needle));

  if (index < 0) {
    return normalized.slice(0, radius * 2);
  }

  return normalized.slice(Math.max(0, index - radius), Math.min(normalized.length, index + needle.length + radius));
}

function mailMatches(mail = {}, searchText = '', fields = ['subject', 'sender', 'preview', 'text']) {
  const needle = lowerText(searchText);

  if (!needle) {
    return true;
  }

  return fields.some((field) => lowerText(valueFromPath(mail, field) ?? '').includes(needle));
}

module.exports = {
  DEFAULT_BODY_SELECTORS,
  DEFAULT_ITEM_SELECTORS,
  ageSecondsFromText,
  clickMailCandidate,
  extractValueFromText,
  mailMatches,
  maxAgeSeconds,
  normalizeText,
  optionBoolean,
  optionNumber,
  optionString,
  readTextFromFrames,
  scalarInputValue,
  scanMailList,
  selectorsFrom,
  setWorkflowVariable,
  taskOptions,
  valueFromPath,
  variableName,
  wait,
  workflowVariableRoot,
};
