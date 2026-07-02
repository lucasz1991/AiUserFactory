'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const { normalizeText } = require('../lib/webmail_context.cjs');

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function numericValue(...values) {
  for (const value of values) {
    const number = Number(value);

    if (Number.isFinite(number) && number >= 0) {
      return number;
    }
  }

  return 0;
}

function isLikelySearchText(value) {
  const text = normalizeText(value);

  return text !== '' && !/^\d+(\.\d+)?$/.test(text);
}

function mailboxFrames(page) {
  const frames = typeof page.frames === 'function' ? page.frames() : [];

  return frames
    .map((frame, index) => {
      const url = typeof frame.url === 'function' ? frame.url() : '';
      const name = typeof frame.name === 'function' ? frame.name() : '';
      const haystack = `${url} ${name}`.toLowerCase();
      let score = 0;

      if (haystack.includes('webmailer')) {
        score += 30;
      }

      if (haystack.includes('mail')) {
        score += 20;
      }

      if (haystack.includes('gmx') || haystack.includes('web.de') || haystack.includes('proton') || haystack.includes('mail.google')) {
        score += 10;
      }

      if (frame === page.mainFrame?.()) {
        score += 2;
      }

      return { frame, index, score, url, name };
    })
    .filter((entry) => entry.score > 0)
    .sort((left, right) => right.score - left.score)
    .map((entry) => entry.frame);
}

async function frameTexts(page) {
  const frames = typeof page.frames === 'function' ? page.frames() : [];
  const chunks = [];

  for (const frame of frames) {
    const payload = await frame.evaluate(() => ({
      url: window.location.href,
      title: document.title || '',
      text: document.body ? document.body.innerText : '',
    })).catch(() => null);

    if (payload && payload.text) {
      chunks.push(payload);
    }
  }

  return chunks;
}

function scoreCandidate(candidate, text, query) {
  const lower = text.toLowerCase();
  let score = 0;

  if (query && lower.includes(query.toLowerCase())) {
    score += 35;
  }

  for (const needle of ['code', 'verifizierung', 'verification', 'sicherheitscode', 'security code', 'bestaetigung', 'bestätigung', 'login', 'passcode', 'one-time']) {
    if (lower.includes(needle)) {
      score += 6;
    }
  }

  if (/^\d{6}$/.test(candidate)) {
    score += 15;
  } else if (/^\d{4,8}$/.test(candidate)) {
    score += 9;
  } else if (/^[A-Z0-9]{6,10}$/i.test(candidate)) {
    score += 4;
  }

  return score;
}

function extractCode(chunks, query) {
  const candidates = [];
  const patterns = [
    /(?:code|passcode|pin|token|verification|verifizierung|sicherheitscode|bestätigungscode|bestaetigungscode)[^\dA-Z]{0,40}([A-Z0-9][A-Z0-9\s-]{2,12}[A-Z0-9])/gi,
    /(?:^|[^\d])(\d[\d\s-]{2,10}\d)(?!\d)/g,
  ];

  for (const chunk of chunks) {
    const normalizedText = normalizeText(chunk.text).replace(/\s+/g, ' ');

    for (const pattern of patterns) {
      let match;

      while ((match = pattern.exec(normalizedText)) !== null) {
        const raw = String(match[1] || '');
        const code = raw.replace(/[^A-Z0-9]/gi, '').toUpperCase();

        if (code.length < 4 || code.length > 10) {
          continue;
        }

        if (!/\d/.test(code)) {
          continue;
        }

        const start = Math.max(0, match.index - 140);
        const end = Math.min(normalizedText.length, match.index + 190);
        const snippet = normalizedText.slice(start, end).trim();

        candidates.push({
          code,
          score: scoreCandidate(code, snippet, query),
          snippet,
          frameUrl: chunk.url,
          frameTitle: chunk.title,
        });
      }
    }
  }

  candidates.sort((left, right) => right.score - left.score);

  return candidates[0] || null;
}

function mailAgeSeconds(text, now = new Date()) {
  const value = normalizeText(text).toLowerCase();

  if (/(gerade eben|soeben|jetzt|now|just now)/.test(value)) {
    return 0;
  }

  let match = value.match(/(?:vor\s*)?(\d{1,2})\s*(?:min|minute|minuten)\b/);

  if (match) {
    return Number(match[1]) * 60;
  }

  match = value.match(/(?:heute|today)?\s*(\d{1,2})[:.](\d{2})/);

  if (match) {
    const date = new Date(now);
    date.setHours(Number(match[1]), Number(match[2]), 0, 0);

    let age = Math.round((now.getTime() - date.getTime()) / 1000);

    if (age < -3600) {
      age += 86400;
    }

    return age >= 0 ? age : null;
  }

  return null;
}

async function collectMailCandidates(frame, options = {}) {
  const limit = Math.max(1, Math.min(30, Number(options.limit || 12)));

  return frame.evaluate((candidateLimit) => {
    const selectors = [
      '[data-test*="mail" i]',
      '[data-testid*="mail" i]',
      '[data-qa*="mail" i]',
      '[aria-label*="mail" i]',
      '[class*="mail" i]',
      '[class*="message" i]',
      '[class*="inbox" i]',
      '[role="row"]',
      '[role="option"]',
      '[role="listitem"]',
      'tr',
      'li',
      'a',
      'button',
    ];

    const visible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 40
        && rect.height > 12
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && Number(style.opacity || 1) > 0;
    };

    const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const deepQueryAll = (selector, root = document) => {
      const matches = [];

      try {
        matches.push(...Array.from(root.querySelectorAll(selector)));
      } catch {
        return matches;
      }

      const all = root.querySelectorAll ? Array.from(root.querySelectorAll('*')) : [];

      for (const element of all) {
        if (element.shadowRoot) {
          matches.push(...deepQueryAll(selector, element.shadowRoot));
        }
      }

      return matches;
    };
    const seen = new Set();
    const rows = [];

    for (const selector of selectors) {
      const elements = deepQueryAll(selector);

      elements.forEach((element, index) => {
        if (!visible(element)) {
          return;
        }

        const text = normalize([
          element.getAttribute('aria-label'),
          element.getAttribute('title'),
          element.innerText,
          element.textContent,
        ].filter(Boolean).join(' '));

        if (text.length < 8 || text.length > 1200) {
          return;
        }

        const rect = element.getBoundingClientRect();
        const key = `${Math.round(rect.top)}:${Math.round(rect.left)}:${text.slice(0, 120)}`;

        if (seen.has(key)) {
          return;
        }

        seen.add(key);

        let score = 0;
        const lower = text.toLowerCase();

        if (/(ungelesen|unread|inbox|posteingang|eingang|mail|nachricht|message)/.test(lower)) {
          score += 18;
        }

        if (/(gerade eben|soeben|heute|today|\d{1,2}[:.]\d{2}|vor\s*\d+\s*min|\d+\s*min)/.test(lower)) {
          score += 25;
        }

        if (/(code|verifizierung|verification|sicherheitscode|login|bestätigung|bestaetigung)/.test(lower)) {
          score += 22;
        }

        if (element.matches('a, button, [role="button"], [role="row"], [role="option"], [role="listitem"], tr, li')) {
          score += 6;
        }

        score += Math.max(0, 20 - Math.round(rect.top / 80));

        const token = `wf-mail-${Date.now()}-${rows.length}`;
        const attributes = Array.from(element.attributes || [])
          .filter((attribute) => /^(id|href|data-|aria-|role|title)/i.test(attribute.name))
          .slice(0, 30)
          .reduce((carry, attribute) => {
            carry[attribute.name] = attribute.value;

            return carry;
          }, {});
        const closestLink = element.closest('a[href]');
        const href = element.getAttribute('href') || closestLink?.getAttribute('href') || '';
        const messageId = element.getAttribute('data-message-id')
          || element.getAttribute('data-messageid')
          || element.getAttribute('data-mail-id')
          || element.getAttribute('data-id')
          || element.getAttribute('data-uid')
          || element.getAttribute('message-id')
          || element.getAttribute('data-testid')
          || element.id
          || href
          || token;

        element.setAttribute('data-workflow-mail-candidate', token);

        rows.push({
          token,
          id: messageId,
          messageId,
          message_id: messageId,
          mailId: messageId,
          mail_id: messageId,
          elementId: element.id || '',
          element_id: element.id || '',
          href,
          attributes,
          selector,
          index,
          text,
          score,
          top: rect.top,
          left: rect.left,
          width: rect.width,
          height: rect.height,
        });
      });
    }

    rows.sort((left, right) => right.score - left.score || left.top - right.top);

    return rows.slice(0, candidateLimit);
  }, limit).catch(() => []);
}

async function clickMailCandidate(frame, candidate) {
  return frame.evaluate((payload) => {
    const deepQueryAll = (selector, root = document) => {
      const matches = [];

      try {
        matches.push(...Array.from(root.querySelectorAll(selector)));
      } catch {
        return matches;
      }

      const all = root.querySelectorAll ? Array.from(root.querySelectorAll('*')) : [];

      for (const child of all) {
        if (child.shadowRoot) {
          matches.push(...deepQueryAll(selector, child.shadowRoot));
        }
      }

      return matches;
    };
    const element = payload.token
      ? deepQueryAll(`[data-workflow-mail-candidate="${payload.token}"]`)[0]
      : deepQueryAll(payload.selector)[payload.index];

    if (!element) {
      return false;
    }

    element.scrollIntoView({ block: 'center', inline: 'center' });
    element.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, cancelable: true, view: window }));
    element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window }));
    element.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, view: window }));
    element.click();

    return true;
  }, candidate).catch(() => false);
}

function mailVariable(candidate = {}, match = null) {
  const id = String(candidate.messageId || candidate.message_id || candidate.mailId || candidate.mail_id || candidate.id || candidate.token || '').trim();

  if (!id && !candidate.text && !candidate.selector) {
    return null;
  }

  return {
    id,
    messageId: id,
    message_id: id,
    mailId: id,
    mail_id: id,
    token: candidate.token || '',
    selector: candidate.selector || '',
    index: Number.isFinite(Number(candidate.index)) ? Number(candidate.index) : null,
    frameUrl: candidate.frameUrl || match?.frameUrl || '',
    frame_url: candidate.frameUrl || match?.frameUrl || '',
    elementId: candidate.elementId || candidate.element_id || '',
    element_id: candidate.element_id || candidate.elementId || '',
    href: candidate.href || '',
    text: String(candidate.text || '').slice(0, 500),
    ageSeconds: candidate.ageSeconds ?? null,
    age_seconds: candidate.ageSeconds ?? null,
    sourceSnippet: match?.snippet || '',
    source_snippet: match?.snippet || '',
  };
}

function rememberVerificationCode(context, code, candidate = null, match = null) {
  const mail = candidate ? mailVariable(candidate, match) : null;

  context.verificationCode = code;
  context.verification_code = code;

  if (mail) {
    context.verificationMail = mail;
    context.verification_mail = mail;
    context.verificationMailId = mail.id;
    context.verification_mail_id = mail.id;
    context.matchedMail = mail;
    context.matched_mail = mail;
    context.mailId = mail.id;
    context.mail_id = mail.id;
    context.messageId = mail.id;
    context.message_id = mail.id;
  }

  context.workflowVariables = {
    ...(context.workflowVariables || {}),
    verificationCode: code,
    verification_code: code,
    ...(mail ? {
      verificationMail: mail,
      verification_mail: mail,
      verificationMailId: mail.id,
      verification_mail_id: mail.id,
      matchedMail: mail,
      matched_mail: mail,
      mailId: mail.id,
      mail_id: mail.id,
      messageId: mail.id,
      message_id: mail.id,
    } : {}),
  };
  context.workflow_variables = {
    ...(context.workflow_variables || {}),
    verificationCode: code,
    verification_code: code,
    ...(mail ? {
      verificationMail: mail,
      verification_mail: mail,
      verificationMailId: mail.id,
      verification_mail_id: mail.id,
      matchedMail: mail,
      matched_mail: mail,
      mailId: mail.id,
      mail_id: mail.id,
      messageId: mail.id,
      message_id: mail.id,
    } : {}),
  };

  return mail;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const query = isLikelySearchText(input.value || input.inputValue || input.input_value || input.search)
    ? normalizeText(input.value || input.inputValue || input.input_value || input.search)
    : '';
  const waitWindowSeconds = numericValue(
    input.maxAgeSeconds,
    input.max_age_seconds,
    input.ageSeconds,
    input.age_seconds,
    input.waitSeconds,
    input.wait_seconds,
    context.lastWaitSeconds,
    context.last_wait_seconds,
    isLikelySearchText(input.value || input.inputValue || input.input_value) ? null : input.value,
  );
  const maxAgeSeconds = Math.max(60, waitWindowSeconds || 60);
  const maxMailClicks = Math.max(1, Math.min(20, numericValue(input.maxMailClicks, input.max_mail_clicks, input.limit) || 8));
  const timeout = Number(input.timeoutMs || context.timeoutMs || 90000);
  const deadline = Date.now() + timeout;
  const openedMails = [];

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Lesen des Verifizierungscodes vorhanden.' };
  }

  startTaskPreview(context);

  let chunks = await frameTexts(page);
  let match = extractCode(chunks, query);
  const visibleFallbackMatch = match;

  const now = new Date();
  const candidates = [];
  const collectDeadline = Math.min(deadline, Date.now() + 12000);

  do {
    candidates.splice(0, candidates.length);

    for (const frame of mailboxFrames(page)) {
      const frameCandidates = await collectMailCandidates(frame, { limit: maxMailClicks * 3 });
      const frameUrl = typeof frame.url === 'function' ? frame.url() : '';

      for (const candidate of frameCandidates) {
        const ageSeconds = mailAgeSeconds(candidate.text, now);

        if (ageSeconds !== null && ageSeconds > maxAgeSeconds + 30) {
          continue;
        }

        candidates.push({
          ...candidate,
          frame,
          frameUrl,
          ageSeconds,
          score: candidate.score + (ageSeconds === null ? 0 : Math.max(0, 30 - Math.round(ageSeconds / 10))),
        });
      }
    }

    if (candidates.length > 0 || Date.now() >= collectDeadline) {
      break;
    }

    if (typeof context.refreshActivePage === 'function') {
      await context.refreshActivePage().catch(() => null);
    }

    await wait(1000);
  } while (Date.now() < collectDeadline);

  candidates.sort((left, right) => right.score - left.score || (left.ageSeconds ?? 999999) - (right.ageSeconds ?? 999999));

  for (const candidate of candidates.slice(0, maxMailClicks)) {
    if (Date.now() > deadline) {
      break;
    }

    const clicked = await clickMailCandidate(candidate.frame, candidate);

    openedMails.push({
      clicked,
      id: candidate.messageId || candidate.message_id || candidate.mailId || candidate.mail_id || candidate.id || candidate.token || '',
      messageId: candidate.messageId || candidate.message_id || candidate.mailId || candidate.mail_id || candidate.id || candidate.token || '',
      message_id: candidate.message_id || candidate.messageId || candidate.mail_id || candidate.mailId || candidate.id || candidate.token || '',
      mailId: candidate.mailId || candidate.mail_id || candidate.messageId || candidate.message_id || candidate.id || candidate.token || '',
      mail_id: candidate.mail_id || candidate.mailId || candidate.message_id || candidate.messageId || candidate.id || candidate.token || '',
      token: candidate.token || '',
      selector: candidate.selector || '',
      index: Number.isFinite(Number(candidate.index)) ? Number(candidate.index) : null,
      ageSeconds: candidate.ageSeconds,
      frameUrl: candidate.frameUrl,
      text: candidate.text.slice(0, 240),
    });

    if (!clicked) {
      continue;
    }

    await wait(900);
    chunks = await frameTexts(page);
    match = extractCode(chunks, query);

    if (match) {
      const verificationMail = rememberVerificationCode(context, match.code, candidate, match);

      return captureTaskPreview(context, {
        ok: true,
        status: 'success',
        statusMessage: 'Verifizierungscode wurde aus einer aktuellen Webmail-Nachricht gelesen.',
        verificationCode: match.code,
        verification_code: match.code,
        verificationMail,
        verification_mail: verificationMail,
        verificationMailId: verificationMail?.id || null,
        verification_mail_id: verificationMail?.id || null,
        matchedMail: verificationMail,
        matched_mail: verificationMail,
        mailId: verificationMail?.id || null,
        mail_id: verificationMail?.id || null,
        messageId: verificationMail?.id || null,
        message_id: verificationMail?.id || null,
        workflowVariables: context.workflowVariables,
        workflow_variables: context.workflow_variables,
        query,
        maxAgeSeconds,
        sourceSnippet: match.snippet,
        frameUrl: match.frameUrl,
        frameTitle: match.frameTitle,
        scannedFrames: chunks.length,
        openedMails,
      }, true);
    }
  }

  if (candidates.length === 0 && visibleFallbackMatch) {
    rememberVerificationCode(context, visibleFallbackMatch.code);

    return captureTaskPreview(context, {
      ok: true,
      status: 'success',
      statusMessage: 'Verifizierungscode wurde im aktuell sichtbaren Webmailportal gefunden.',
      verificationCode: visibleFallbackMatch.code,
      verification_code: visibleFallbackMatch.code,
      workflowVariables: context.workflowVariables,
      workflow_variables: context.workflow_variables,
      query,
      maxAgeSeconds,
      sourceSnippet: visibleFallbackMatch.snippet,
      frameUrl: visibleFallbackMatch.frameUrl,
      frameTitle: visibleFallbackMatch.frameTitle,
      scannedFrames: chunks.length,
      openedMails,
    }, true);
  }

  return captureTaskPreview(context, {
    ok: false,
    status: 'failed',
    statusMessage: 'Kein Verifizierungscode in den aktuellen Webmail-Nachrichten gefunden.',
    query,
    maxAgeSeconds,
    candidateCount: candidates.length,
    openedMails,
    scannedFrames: chunks.length,
  }, true);
}

module.exports = { key: 'webmail.read_verification_code', run };
