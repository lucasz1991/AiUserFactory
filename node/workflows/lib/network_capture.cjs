'use strict';

// Debug-Aufzeichnung der Netzwerkantworten, die ein Klick ausloest. Ein Anbieter
// lehnt z. B. einen Login ab, ohne dass die Seite den Grund nennt - Statuscode
// und Antwortform der ausgeloesten Anfrage sind dann der einzige Hinweis.
// Antwortinhalte koennen Tokens enthalten: gespeichert wird nur die Form, Werte
// ausschliesslich fuer wenige unkritische Diagnosefelder.

const CAPTURED_RESOURCE_TYPES = new Set(['xhr', 'fetch', 'document']);
const DIAGNOSTIC_VALUE_KEYS = /^(flow_?state|next_?step|status|error|errors|error_?code|error_?description|error_?message|message|reason|type|result|outcome|challenge|captcha|blocked|locked|retry_?after)$/i;
const MAX_COLLECTED = 60;
const MAX_BODY_BYTES = 32768;

function describeValue(value) {
  if (value === null) return 'null';
  if (Array.isArray(value)) return `array(${value.length})`;
  if (typeof value === 'string') return `string(${value.length})`;
  if (typeof value === 'object') return `object(${Object.keys(value).length})`;

  return typeof value;
}

function summarizeJson(value, depth = 0) {
  if (value === null || typeof value !== 'object') {
    return describeValue(value);
  }

  if (Array.isArray(value)) {
    return depth >= 2
      ? describeValue(value)
      : value.slice(0, 3).map((item) => summarizeJson(item, depth + 1));
  }

  const summary = {};

  for (const [key, item] of Object.entries(value).slice(0, 24)) {
    const isPrimitive = item === null || typeof item !== 'object';

    if (isPrimitive && DIAGNOSTIC_VALUE_KEYS.test(key) && String(item ?? '').length <= 160) {
      summary[key] = item;
    } else if (typeof item === 'number' && /^code$/i.test(key)) {
      // Numerische API-Fehlercodes (z. B. {"Code": 2001, "Error": "..."}); ein
      // "code" als Zeichenkette koennte dagegen ein Autorisierungscode sein.
      summary[key] = item;
    } else if (!isPrimitive && depth < 2) {
      summary[key] = summarizeJson(item, depth + 1);
    } else {
      summary[key] = describeValue(item);
    }
  }

  return summary;
}

function summarizeResponseBody(text, contentType = '') {
  const body = String(text ?? '');

  if (body === '' || !/json/i.test(String(contentType || ''))) {
    return null;
  }

  if (body.length > MAX_BODY_BYTES) {
    return { truncated: true, length: body.length };
  }

  try {
    return summarizeJson(JSON.parse(body));
  } catch {
    return { unparsable: true, length: body.length };
  }
}

function urlWithoutQuery(value) {
  try {
    const url = new URL(String(value || ''));

    return `${url.origin}${url.pathname}`;
  } catch {
    return String(value || '').split(/[?#]/)[0].slice(0, 300);
  }
}

function selectResponses(entries, limit) {
  const failed = entries.filter((entry) => Number(entry.status) >= 400);
  const others = entries.filter((entry) => Number(entry.status) < 400);

  return [...failed, ...others]
    .slice(0, limit)
    .sort((left, right) => left.sequence - right.sequence)
    .map(({ sequence, ...entry }) => entry);
}

function startResponseCapture(page, options = {}) {
  const limit = Math.max(1, Number(options.limit || 12));
  const entries = [];
  const pending = [];
  let lastResponseAt = 0;
  let sequence = 0;

  const onResponse = (response) => {
    try {
      const request = response.request();
      const resourceType = String(request.resourceType() || '');

      if (!CAPTURED_RESOURCE_TYPES.has(resourceType) || entries.length >= MAX_COLLECTED) {
        return;
      }

      const headers = response.headers() || {};
      const contentType = String(headers['content-type'] || '');
      const entry = {
        sequence: sequence += 1,
        method: String(request.method() || ''),
        url: urlWithoutQuery(response.url()),
        status: Number(response.status()),
        resourceType,
        contentType: contentType.split(';')[0].trim(),
      };

      entries.push(entry);
      lastResponseAt = Date.now();

      if (resourceType !== 'document' && /json/i.test(contentType)) {
        pending.push(Promise.resolve()
          .then(() => response.text())
          .then((text) => {
            const body = summarizeResponseBody(text, contentType);

            if (body !== null) {
              entry.body = body;
            }
          })
          .catch(() => {}));
      }
    } catch {
      // Aufzeichnung ist reine Diagnose und darf die Task nie stoeren.
    }
  };

  page.on('response', onResponse);

  return {
    async stop(settle = {}) {
      const quietMs = Math.max(0, Number(settle.quietMs ?? 400));
      const idleMs = Math.max(0, Number(settle.idleMs ?? 600));
      const startedAt = Date.now();
      const deadline = startedAt + Math.max(0, Number(settle.maxWaitMs ?? 1500));

      // Die Antwort auf den Klick kann erst nach dem Klick-Task eintreffen: kurz
      // warten, bis nichts mehr kommt - ohne jede Antwort aber nur idleMs.
      while (Date.now() < deadline) {
        const waited = lastResponseAt === 0 ? Date.now() - startedAt : Date.now() - lastResponseAt;

        if (waited >= (lastResponseAt === 0 ? idleMs : quietMs)) {
          break;
        }

        await new Promise((resolve) => setTimeout(resolve, 50));
      }

      if (typeof page.off === 'function') {
        page.off('response', onResponse);
      } else if (typeof page.removeListener === 'function') {
        page.removeListener('response', onResponse);
      }

      await Promise.race([
        Promise.allSettled(pending),
        new Promise((resolve) => setTimeout(resolve, 1000)),
      ]);

      return selectResponses(entries, limit);
    },
  };
}

module.exports = {
  startResponseCapture,
  summarizeResponseBody,
};
