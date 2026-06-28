'use strict';

const { captureTaskPreview } = require('../lib/preview.cjs');
const { normalizeText } = require('../lib/webmail_context.cjs');

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
    score += 20;
  }

  for (const needle of ['code', 'verifizierung', 'verification', 'sicherheitscode', 'security code', 'bestaetigung', 'bestätigung', 'login']) {
    if (lower.includes(needle)) {
      score += 5;
    }
  }

  if (/^\d{6}$/.test(candidate)) {
    score += 10;
  } else if (/^\d{4,8}$/.test(candidate)) {
    score += 6;
  }

  return score;
}

function extractCode(chunks, query) {
  const candidates = [];
  const codePattern = /(?:^|[^\d])(\d[\d\s-]{2,10}\d)(?!\d)/g;

  for (const chunk of chunks) {
    const normalizedText = normalizeText(chunk.text).replace(/\s+/g, ' ');
    let match;

    while ((match = codePattern.exec(normalizedText)) !== null) {
      const code = String(match[1] || '').replace(/[^\d]/g, '');

      if (code.length < 4 || code.length > 8) {
        continue;
      }

      const start = Math.max(0, match.index - 120);
      const end = Math.min(normalizedText.length, match.index + 160);
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

  candidates.sort((left, right) => right.score - left.score);

  return candidates[0] || null;
}

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const query = normalizeText(input.value || input.inputValue || input.input_value || input.search || '');

  if (!page) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle zum Lesen des Verifizierungscodes vorhanden.' };
  }

  const chunks = await frameTexts(page);
  const match = extractCode(chunks, query);

  if (!match) {
    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: 'Kein Verifizierungscode im aktuell sichtbaren Webmailportal gefunden.',
      query,
      scannedFrames: chunks.length,
    }, true);
  }

  context.verificationCode = match.code;
  context.verification_code = match.code;

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: 'Verifizierungscode wurde aus dem Webmailportal gelesen.',
    verificationCode: match.code,
    verification_code: match.code,
    query,
    sourceSnippet: match.snippet,
    frameUrl: match.frameUrl,
    frameTitle: match.frameTitle,
    scannedFrames: chunks.length,
  }, true);
}

module.exports = { key: 'webmail.read_verification_code', run };
