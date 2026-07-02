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

const DEFAULT_TITLE_SELECTORS = [
  '[title]',
  '[data-testid*="title" i]',
  '[data-test*="title" i]',
  '[class*="title" i]',
  '[aria-label*="title" i]',
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

function parseListLiteral(text) {
  const normalized = normalizeText(text);

  if (!normalized.startsWith('[') || !normalized.endsWith(']')) {
    return null;
  }

  try {
    const parsed = JSON.parse(normalized);

    if (Array.isArray(parsed)) {
      return parsed.map(normalizeText).filter(Boolean);
    }
  } catch {
    // The UI accepts compact PHP/JS-like examples such as ['queued', 'running'].
  }

  const body = normalized.slice(1, -1);
  const values = [];
  const pattern = /'([^']*)'|"([^"]*)"|([^,\s][^,]*)/g;
  let match;

  while ((match = pattern.exec(body)) !== null) {
    const value = normalizeText(match[1] ?? match[2] ?? match[3] ?? '');

    if (value) {
      values.push(value);
    }
  }

  return values;
}

function stringListFrom(value, fallback = [], splitComma = true) {
  if (Array.isArray(value)) {
    return value.map(normalizeText).filter(Boolean);
  }

  const literalValues = parseListLiteral(value);

  if (literalValues) {
    return literalValues;
  }

  const text = normalizeText(value);

  if (!text) {
    return fallback;
  }

  const separator = splitComma ? /\r?\n|;|,/ : /\r?\n|;/;

  return text
    .split(separator)
    .map((item) => item.replace(/^['"]|['"]$/g, ''))
    .map(normalizeText)
    .filter(Boolean);
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

  const literalValues = parseListLiteral(value);

  if (literalValues) {
    return literalValues;
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

function ageFromDate(date, now = new Date()) {
  const age = Math.round((now.getTime() - date.getTime()) / 1000);

  return age >= 0 ? age : null;
}

function ageFromDateParts(now, day, month, year, hour = 0, minute = 0) {
  const normalizedYear = Number(year) < 100
    ? (Number(year) >= 70 ? 1900 + Number(year) : 2000 + Number(year))
    : Number(year);
  const normalizedDay = Number(day);
  const normalizedMonth = Number(month);
  const normalizedHour = Number(hour || 0);
  const normalizedMinute = Number(minute || 0);

  if (
    normalizedMonth < 1
    || normalizedMonth > 12
    || normalizedDay < 1
    || normalizedDay > 31
    || normalizedHour < 0
    || normalizedHour > 23
    || normalizedMinute < 0
    || normalizedMinute > 59
  ) {
    return null;
  }

  const date = new Date(now);
  date.setFullYear(normalizedYear, normalizedMonth - 1, normalizedDay);
  date.setHours(normalizedHour, normalizedMinute, 0, 0);

  if (
    date.getFullYear() !== normalizedYear
    || date.getMonth() !== normalizedMonth - 1
    || date.getDate() !== normalizedDay
  ) {
    return null;
  }

  return ageFromDate(date, now);
}

function monthNumber(value) {
  const normalized = lowerText(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
  const months = {
    jan: 1,
    januar: 1,
    january: 1,
    feb: 2,
    februar: 2,
    february: 2,
    mar: 3,
    marz: 3,
    maerz: 3,
    march: 3,
    apr: 4,
    april: 4,
    mai: 5,
    may: 5,
    jun: 6,
    juni: 6,
    june: 6,
    jul: 7,
    juli: 7,
    july: 7,
    aug: 8,
    august: 8,
    sep: 9,
    sept: 9,
    september: 9,
    okt: 10,
    oktober: 10,
    oct: 10,
    october: 10,
    nov: 11,
    november: 11,
    dez: 12,
    dezember: 12,
    dec: 12,
    december: 12,
  };

  return months[normalized] || null;
}

function ageSecondsFromText(value, now = new Date()) {
  const rawText = normalizeText(value);
  const text = rawText.toLowerCase();

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

  match = text.match(/(?:^|[^\d])(\d{1,2})\.(\d{1,2})\.(\d{2,4})(?:\s*(?:,|um|at)?\s*(\d{1,2})[:.](\d{2}))?/);

  if (match) {
    const age = ageFromDateParts(now, match[1], match[2], match[3], match[4] || 0, match[5] || 0);

    if (age !== null) {
      return age;
    }
  }

  match = text.match(/(?:^|[^\d])(\d{1,2})\.\s*([a-zäöü]+)\s+(\d{2,4})(?:\s*(?:,|um|at)?\s*(\d{1,2})[:.](\d{2}))?/i);

  if (match) {
    const month = monthNumber(match[2]);

    if (month) {
      const age = ageFromDateParts(now, match[1], month, match[3], match[4] || 0, match[5] || 0);

      if (age !== null) {
        return age;
      }
    }
  }

  const parsed = Date.parse(rawText);

  if (Number.isFinite(parsed)) {
    return ageFromDate(new Date(parsed), now);
  }

  match = text.match(/(?:^|[^\d.])(?:heute|today)?\s*,?\s*(\d{1,2})[:.](\d{2})(?![.\d])/);

  if (match) {
    const hour = Number(match[1]);
    const minute = Number(match[2]);

    if (hour < 0 || hour > 23 || minute < 0 || minute > 59) {
      return null;
    }

    const date = new Date(now);
    date.setHours(hour, minute, 0, 0);

    let age = Math.round((now.getTime() - date.getTime()) / 1000);

    if (age < -3600) {
      age += 86400;
    }

    return age >= 0 ? age : null;
  }

  return null;
}

function normalizeGmtOffsetHours(value, fallback = null) {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }

  const text = normalizeText(value).replace(/^gmt\s*/i, '');
  const colonMatch = text.match(/^([+-]?)(\d{1,2}):([0-5]\d)$/);
  const hours = colonMatch
    ? (colonMatch[1] === '-' ? -1 : 1) * (Number(colonMatch[2]) + (Number(colonMatch[3]) / 60))
    : Number(text.replace(',', '.'));

  return Number.isFinite(hours) && hours >= -14 && hours <= 14 ? hours : fallback;
}

function dateFromPartsAtOffset(now, day, month, year, hour = 0, minute = 0, sourceOffsetHours = null) {
  const normalizedYear = Number(year) < 100
    ? (Number(year) >= 70 ? 1900 + Number(year) : 2000 + Number(year))
    : Number(year);
  const normalized = {
    year: normalizedYear,
    month: Number(month),
    day: Number(day),
    hour: Number(hour || 0),
    minute: Number(minute || 0),
  };

  if (
    normalized.month < 1 || normalized.month > 12
    || normalized.day < 1 || normalized.day > 31
    || normalized.hour < 0 || normalized.hour > 23
    || normalized.minute < 0 || normalized.minute > 59
  ) {
    return null;
  }

  if (sourceOffsetHours === null) {
    const date = new Date(now);
    date.setFullYear(normalized.year, normalized.month - 1, normalized.day);
    date.setHours(normalized.hour, normalized.minute, 0, 0);

    return date;
  }

  const offsetMs = sourceOffsetHours * 60 * 60 * 1000;
  const date = new Date(Date.UTC(
    normalized.year,
    normalized.month - 1,
    normalized.day,
    normalized.hour,
    normalized.minute,
  ) - offsetMs);
  const shifted = new Date(date.getTime() + offsetMs);

  return shifted.getUTCFullYear() === normalized.year
    && shifted.getUTCMonth() === normalized.month - 1
    && shifted.getUTCDate() === normalized.day
    ? date
    : null;
}

function timeOnlyDateAtOffset(now, hour, minute, sourceOffsetHours = null) {
  if (sourceOffsetHours === null) {
    const date = new Date(now);
    date.setHours(hour, minute, 0, 0);

    if (date.getTime() - now.getTime() > 3600000) {
      date.setDate(date.getDate() - 1);
    }

    return date;
  }

  const offsetMs = sourceOffsetHours * 60 * 60 * 1000;
  const sourceNow = new Date(now.getTime() + offsetMs);
  let timestamp = Date.UTC(
    sourceNow.getUTCFullYear(),
    sourceNow.getUTCMonth(),
    sourceNow.getUTCDate(),
    hour,
    minute,
  ) - offsetMs;

  if (timestamp - now.getTime() > 3600000) {
    timestamp -= 86400000;
  }

  return new Date(timestamp);
}

function mailReceivedTimeFromText(value, now = new Date(), options = {}) {
  const rawText = normalizeText(value);
  const text = rawText.toLowerCase();
  const sourceOffsetHours = normalizeGmtOffsetHours(
    options.sourceGmtOffsetHours
      ?? options.source_gmt_offset_hours
      ?? options.mailTimeGmtOffsetHours
      ?? options.mail_time_gmt_offset_hours,
    null,
  );
  const result = (date, kind) => {
    if (!(date instanceof Date) || !Number.isFinite(date.getTime())) {
      return null;
    }

    const rawAgeSeconds = Math.round((now.getTime() - date.getTime()) / 1000);

    return {
      date,
      receivedAt: date.toISOString(),
      ageSeconds: rawAgeSeconds >= -300 ? Math.max(0, rawAgeSeconds) : null,
      kind,
      sourceGmtOffsetHours: sourceOffsetHours,
    };
  };

  if (!text) {
    return null;
  }

  const relativeAge = ageSecondsFromText(rawText, now);
  const relativePattern = /(gerade eben|soeben|jetzt|now|just now|(?:vor\s*)?\d{1,3}\s*(?:sek|sec|second|seconds|sekunden|min|minute|minutes|minuten|h|std|stunde|stunden|hour|hours|d|tag|tage|day|days)\b)/i;

  if (relativeAge !== null && relativePattern.test(text)) {
    return result(new Date(now.getTime() - (relativeAge * 1000)), 'relative');
  }

  let match = text.match(/(?:^|[^\d])(\d{1,2})\.(\d{1,2})\.(\d{2,4})(?:\s*(?:,|um|at)?\s*(\d{1,2})[:.](\d{2}))?/);

  if (match) {
    return result(dateFromPartsAtOffset(now, match[1], match[2], match[3], match[4], match[5], sourceOffsetHours), 'absolute');
  }

  match = text.match(/(?:^|[^\d])(\d{1,2})\.\s*([a-zäöü]+)\s+(\d{2,4})(?:\s*(?:,|um|at)?\s*(\d{1,2})[:.](\d{2}))?/i);

  if (match) {
    const month = monthNumber(match[2]);

    if (month) {
      return result(dateFromPartsAtOffset(now, match[1], month, match[3], match[4], match[5], sourceOffsetHours), 'absolute');
    }
  }

  match = text.match(/(?:^|[^\d])(\d{4})-(\d{1,2})-(\d{1,2})(?:[t\s]+(\d{1,2}):(\d{2}))?(?![\d])/i);

  if (match && !/(?:z|[+-]\d{2}:?\d{2}|gmt|utc)\s*$/i.test(text)) {
    return result(dateFromPartsAtOffset(now, match[3], match[2], match[1], match[4], match[5], sourceOffsetHours), 'absolute');
  }

  if (/(?:z|[+-]\d{2}:?\d{2}|gmt|utc)\s*$/i.test(text)) {
    const explicitlyZoned = Date.parse(rawText);

    if (Number.isFinite(explicitlyZoned)) {
      return result(new Date(explicitlyZoned), 'explicit-zone');
    }
  }

  match = text.match(/(?:^|[^\d.])(?:heute|today)?\s*,?\s*(\d{1,2})[:.](\d{2})(?![.\d])/);

  if (match && Number(match[1]) <= 23 && Number(match[2]) <= 59) {
    return result(timeOnlyDateAtOffset(now, Number(match[1]), Number(match[2]), sourceOffsetHours), 'time-only');
  }

  const parsed = Date.parse(rawText);

  if (Number.isFinite(parsed)) {
    return result(new Date(parsed), 'parsed');
  }

  return relativeAge === null
    ? null
    : result(new Date(now.getTime() - (relativeAge * 1000)), 'legacy');
}

function formatDateInTimezone(date, timezone = '') {
  if (!(date instanceof Date) || !Number.isFinite(date.getTime())) {
    return null;
  }

  try {
    return new Intl.DateTimeFormat('de-DE', {
      timeZone: timezone || undefined,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    }).format(date);
  } catch {
    return date.toISOString();
  }
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
  const titleSelectors = selectorsFrom(options.titleSelector || options.title_selector, DEFAULT_TITLE_SELECTORS);
  const senderSelectors = selectorsFrom(options.senderSelector || options.sender_selector, DEFAULT_SENDER_SELECTORS);
  const dateSelectors = selectorsFrom(options.dateSelector || options.date_selector, DEFAULT_DATE_SELECTORS);
  const dateAttributes = stringListFrom(options.dateAttribute || options.date_attribute || options.dateAttributes || options.date_attributes, [
    'datetime',
    'title',
    'aria-label',
    'data-date',
    'data-time',
    'text',
  ]);
  const previewSelectors = selectorsFrom(options.previewSelector || options.preview_selector, DEFAULT_PREVIEW_SELECTORS);
  const limit = Math.max(1, Math.min(200, Number(options.maxItems || options.max_items || options.limit || 50)));
  const tokenPrefix = normalizeText(options.tokenPrefix || options.token_prefix || `wf-mail-${Date.now()}`);
  const rows = [];
  const firstFrame = framesForPage(page)[0];
  let browserClock = null;

  if (firstFrame && typeof firstFrame.evaluate === 'function') {
    browserClock = await firstFrame.evaluate(() => ({
      nowMs: Date.now(),
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      offsetMinutes: -new Date().getTimezoneOffset(),
    })).catch(() => null);
  }

  browserClock ||= {
    nowMs: Date.now(),
    timezone: process.env.TZ || 'Europe/Berlin',
    offsetMinutes: -new Date().getTimezoneOffset(),
  };
  const sourceGmtOffsetHours = normalizeGmtOffsetHours(
    options.mailTimeGmtOffsetHours
      ?? options.mail_time_gmt_offset_hours
      ?? options.sourceGmtOffsetHours
      ?? options.source_gmt_offset_hours,
    null,
  );
  const scanNow = new Date(Number(browserClock.nowMs) || Date.now());

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
            if (node !== document && typeof node.matches === 'function' && node.matches(selector)) {
              matches.push(node);
            }

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
      const attributeText = (element, attribute) => {
        const normalizedAttribute = normalize(attribute).toLowerCase();

        if (!element || !normalizedAttribute) {
          return '';
        }

        if (['text', 'innertext'].includes(normalizedAttribute)) {
          return normalize(element.innerText || element.textContent);
        }

        if (['textcontent', 'content'].includes(normalizedAttribute)) {
          return normalize(element.textContent);
        }

        if (['html', 'innerhtml'].includes(normalizedAttribute)) {
          return normalize(element.innerHTML);
        }

        return normalize(element.getAttribute?.(attribute));
      };
      const textFor = (element, selectors, attributes = ['aria-label', 'title', 'datetime', 'text']) => {
        for (const selector of selectors || []) {
          const match = deepQueryAll(selector, element).find(visible);

          if (!match) {
            continue;
          }

          for (const attribute of attributes || []) {
            const text = attributeText(match, attribute);

            if (text) {
              return { text, attribute };
            }
          }

          const text = normalize(match.innerText || match.textContent);

          if (text) {
            return { text, attribute: 'text' };
          }
        }

        return { text: '', attribute: '' };
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
            const subjectMatch = textFor(element, payload.subjectSelectors);
            const titleMatch = textFor(element, payload.titleSelectors, ['title', 'aria-label', 'text']);
            const senderMatch = textFor(element, payload.senderSelectors);
            const dateMatch = textFor(element, payload.dateSelectors, payload.dateAttributes);
            const previewMatch = textFor(element, payload.previewSelectors);
            const subject = subjectMatch.text;
            const title = titleMatch.text;
            const sender = senderMatch.text;
            const dateText = dateMatch.text;
            const preview = previewMatch.text;
            const identityElement = [
              element,
              ...deepQueryAll('[data-message-id],[data-mail-id],[data-id],[message-id],a[href]', element),
            ].find((candidate) => (
              candidate.getAttribute?.('data-message-id')
              || candidate.getAttribute?.('data-mail-id')
              || candidate.getAttribute?.('data-id')
              || candidate.getAttribute?.('message-id')
              || candidate.id
              || candidate.getAttribute?.('href')
            )) || element;
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
              mailId: normalize(
                identityElement.getAttribute('data-message-id')
                || identityElement.getAttribute('data-mail-id')
                || identityElement.getAttribute('data-id')
                || identityElement.getAttribute('message-id')
                || identityElement.id
                || identityElement.getAttribute('href')
                || ''
              ),
              subject,
              title,
              sender,
              dateText,
              titleAttribute: titleMatch.attribute,
              title_attribute: titleMatch.attribute,
              dateAttribute: dateMatch.attribute,
              date_attribute: dateMatch.attribute,
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
      titleSelectors,
      senderSelectors,
      dateSelectors,
      dateAttributes,
      previewSelectors,
      limit,
      minTextLength: Math.max(0, Number(options.minTextLength || options.min_text_length || 4)),
      maxTextLength: Math.max(80, Number(options.maxTextLength || options.max_text_length || 2000)),
      tokenPrefix: `${tokenPrefix}-${frameIndex}`,
    }).catch(() => []);

    const frameUrl = typeof frame.url === 'function' ? frame.url() : '';
    const frameName = typeof frame.name === 'function' ? frame.name() : '';

    for (const row of frameRows) {
      const receivedTime = mailReceivedTimeFromText(row.dateText || row.text, scanNow, {
        sourceGmtOffsetHours,
      });
      const browserReceivedAt = receivedTime
        ? formatDateInTimezone(receivedTime.date, browserClock.timezone)
        : null;

      rows.push({
        ...row,
        mail_id: row.mailId || '',
        index: rows.length,
        frameIndex,
        frameUrl,
        frameName,
        ageSeconds: receivedTime?.ageSeconds ?? null,
        receivedAt: receivedTime?.receivedAt ?? null,
        received_at: receivedTime?.receivedAt ?? null,
        receivedAtBrowser: browserReceivedAt,
        received_at_browser: browserReceivedAt,
        receivedAtBrowserTimezone: browserClock.timezone || null,
        received_at_browser_timezone: browserClock.timezone || null,
        browserGmtOffsetHours: Number(browserClock.offsetMinutes || 0) / 60,
        browser_gmt_offset_hours: Number(browserClock.offsetMinutes || 0) / 60,
        sourceGmtOffsetHours,
        source_gmt_offset_hours: sourceGmtOffsetHours,
        dateParseKind: receivedTime?.kind ?? null,
        date_parse_kind: receivedTime?.kind ?? null,
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

  patterns.forEach((pattern, patternIndex) => {
    let match;

    while ((match = pattern.exec(normalized)) !== null) {
      const code = String(match[1] || '').replace(/[^A-Z0-9]/gi, '').toUpperCase();

      if (code.length < 4 || code.length > 10 || !/\d/.test(code)) {
        continue;
      }

      const start = Math.max(0, match.index - 140);
      const end = Math.min(normalized.length, match.index + 190);
      const snippet = normalized.slice(start, end).trim();
      const lowerSnippet = lowerText(snippet);
      let score = /^\d{6}$/.test(code) ? 20 : 8;

      if (patternIndex === 0) {
        score += 35;
      }

      if (/(enter|use|verification|verify|verifizierung|bestaetigung|security|sicherheits)[^.\n]{0,80}(code|pin|token)/i.test(snippet)) {
        score += 35;
      } else if (/(code|passcode|pin|token|verification|verifizierung|sicherheitscode|bestaetigungscode)/i.test(snippet)) {
        score += 15;
      }

      if (/(navsid|iac_token|csrf|gdpr|consent|adservice|prebid|bannerid|campaignid|cookie|oauthbridge|tracking)/i.test(snippet)) {
        score -= 40;
      }

      if (query && lowerText(snippet).includes(lowerText(query))) {
        score += 20;
      }

      candidates.push({ value: code, snippet, score, index: match.index });
    }
  });

  candidates.sort((left, right) => (right.score - left.score) || (left.index - right.index));

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
  DEFAULT_TITLE_SELECTORS,
  ageSecondsFromText,
  mailReceivedTimeFromText,
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
  stringListFrom,
  taskOptions,
  valueFromPath,
  variableName,
  wait,
  workflowVariableRoot,
};
