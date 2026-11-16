'use strict';

const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const test = require('node:test');

const { startResponseCapture, summarizeResponseBody } = require('../../node/workflows/lib/network_capture.cjs');

function response({ url, status = 200, method = 'POST', resourceType = 'xhr', contentType = 'application/json', body = '' }) {
  return {
    url: () => url,
    status: () => status,
    headers: () => ({ 'content-type': contentType }),
    request: () => ({ method: () => method, resourceType: () => resourceType }),
    text: async () => body,
  };
}

test('response summary keeps diagnostic fields and hides everything else', () => {
  const summary = summarizeResponseBody(JSON.stringify({
    flowState: 'FAILED',
    nextStep: null,
    error: { code: 'x', message: 'Login abgelehnt', token: 'secret-token-value' },
    accessToken: 'eyJhbGciOi.secret',
    sessionId: 'abc123',
    items: [{ id: 7, message: 'ok' }],
  }), 'application/json; charset=utf-8');

  assert.equal(summary.flowState, 'FAILED');
  assert.equal(summary.nextStep, null);
  assert.equal(summary.error.message, 'Login abgelehnt');
  assert.equal(summary.error.token, 'string(18)');
  assert.equal(summary.accessToken, 'string(17)');
  assert.equal(summary.sessionId, 'string(6)');
  assert.deepEqual(summary.items, [{ id: 'number', message: 'ok' }]);
  assert.ok(!JSON.stringify(summary).includes('secret'));
});

test('numeric api error codes are kept while string codes stay hidden', () => {
  const summary = summarizeResponseBody(JSON.stringify({ Code: 12087, Error: 'Human verification failed', code: 'auth-code-secret' }), 'application/json');

  assert.equal(summary.Code, 12087);
  assert.equal(summary.Error, 'Human verification failed');
  assert.equal(summary.code, 'string(16)');
});

test('response summary ignores non-json and oversized bodies', () => {
  assert.equal(summarizeResponseBody('<html></html>', 'text/html'), null);
  assert.equal(summarizeResponseBody('', 'application/json'), null);
  assert.deepEqual(summarizeResponseBody('{broken', 'application/json'), { unparsable: true, length: 7 });
  assert.deepEqual(
    summarizeResponseBody(JSON.stringify({ message: 'x'.repeat(40000) }), 'application/json'),
    { truncated: true, length: 40014 },
  );
  // Lange Werte eines Diagnosefelds bleiben verborgen (koennten Tokens tragen).
  assert.equal(summarizeResponseBody(JSON.stringify({ message: 'y'.repeat(200) }), 'application/json').message, 'string(200)');
});

test('capture records xhr and document responses without query strings and detaches afterwards', async () => {
  const page = new EventEmitter();
  const capture = startResponseCapture(page);

  page.emit('response', response({
    url: 'https://auth.example.test/api/identify?state=secret-state&email=user%40example.test',
    status: 403,
    body: JSON.stringify({ flowState: 'FAILED', reason: 'risk' }),
  }));
  page.emit('response', response({ url: 'https://cdn.example.test/app.js', resourceType: 'script', contentType: 'text/javascript' }));
  page.emit('response', response({ url: 'https://auth.example.test/login?prompt=none', method: 'GET', resourceType: 'document', contentType: 'text/html', body: '<html>' }));

  const entries = await capture.stop({ quietMs: 0, idleMs: 0, maxWaitMs: 0 });

  assert.equal(entries.length, 2);
  assert.deepEqual(entries[0], {
    method: 'POST',
    url: 'https://auth.example.test/api/identify',
    status: 403,
    resourceType: 'xhr',
    contentType: 'application/json',
    body: { flowState: 'FAILED', reason: 'risk' },
  });
  assert.equal(entries[1].url, 'https://auth.example.test/login');
  assert.equal(entries[1].body, undefined);
  assert.equal(page.listenerCount('response'), 0);
  assert.ok(!JSON.stringify(entries).includes('secret-state'));
});

test('capture keeps failed responses when the limit is exceeded', async () => {
  const page = new EventEmitter();
  const capture = startResponseCapture(page, { limit: 3 });

  for (let index = 0; index < 5; index += 1) {
    page.emit('response', response({ url: `https://api.example.test/ok/${index}`, body: '{}' }));
  }

  page.emit('response', response({ url: 'https://api.example.test/denied', status: 429, body: '{"error":"rate"}' }));

  const entries = await capture.stop({ quietMs: 0, idleMs: 0, maxWaitMs: 0 });

  assert.equal(entries.length, 3);
  assert.deepEqual(entries.map((entry) => entry.url), [
    'https://api.example.test/ok/0',
    'https://api.example.test/ok/1',
    'https://api.example.test/denied',
  ]);
});

test('a broken response object never breaks the capture', async () => {
  const page = new EventEmitter();
  const capture = startResponseCapture(page);

  page.emit('response', { request: () => { throw new Error('detached'); } });
  page.emit('response', response({ url: 'https://api.example.test/a', body: '{"status":"ok"}', }));

  const entries = await capture.stop({ quietMs: 0, idleMs: 0, maxWaitMs: 0 });

  assert.equal(entries.length, 1);
  assert.equal(entries[0].body.status, 'ok');
});
