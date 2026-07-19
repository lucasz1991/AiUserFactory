'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const navigateBack = require('./browser/navigate_back.cjs');
const navigateForward = require('./browser/navigate_forward.cjs');
const reload = require('./browser/reload.cjs');

function pageStub(overrides = {}) {
  const calls = [];
  const page = {
    url: () => 'https://example.test/aktuell',
    goBack: async (options) => {
      calls.push({ method: 'goBack', options });

      return { status: () => 200 };
    },
    goForward: async (options) => {
      calls.push({ method: 'goForward', options });

      return { status: () => 200 };
    },
    reload: async (options) => {
      calls.push({ method: 'reload', options });

      return { status: () => 200 };
    },
    ...overrides,
  };

  return { page, calls };
}

test('browser.navigate_back geht in der History zurueck und respektiert waitUntil/timeout', async () => {
  const { page, calls } = pageStub();
  const result = await navigateBack.run({
    page,
    input: { waitUntil: 'load', timeoutMs: 5000 },
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.statusMessage, 'Vorherige Seite wurde geoeffnet.');
  assert.equal(result.url, 'https://example.test/aktuell');
  assert.deepEqual(calls, [{ method: 'goBack', options: { waitUntil: 'load', timeout: 5000 } }]);
});

test('browser.navigate_back meldet fehlende History als sauberen Task-Fehler', async () => {
  const { page } = pageStub({ goBack: async () => null });
  const result = await navigateBack.run({ page, input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.statusMessage, 'Keine vorherige Seite in der Browser-History.');
});

test('browser.navigate_back scheitert ohne Page-Handle', async () => {
  const result = await navigateBack.run({ input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.match(result.statusMessage, /Kein Page-Handle/);
});

test('browser.navigate_back faengt Navigationsfehler ohne Absturz ab', async () => {
  const { page } = pageStub({
    goBack: async () => {
      throw new Error('Timeout beim Navigieren');
    },
  });
  const result = await navigateBack.run({ page, input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.statusMessage, 'Navigation zur vorherigen Seite ist fehlgeschlagen.');
  assert.equal(result.error, 'Timeout beim Navigieren');
});

test('browser.navigate_forward geht in der History vorwaerts und nutzt Task-Timeout aus dem Kontext', async () => {
  const { page, calls } = pageStub();
  const result = await navigateForward.run({
    page,
    input: {},
    timeoutMs: 45000,
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.statusMessage, 'Naechste Seite wurde geoeffnet.');
  assert.deepEqual(calls, [{ method: 'goForward', options: { waitUntil: 'domcontentloaded', timeout: 45000 } }]);
});

test('browser.navigate_forward meldet fehlende History als sauberen Task-Fehler', async () => {
  const { page } = pageStub({ goForward: async () => null });
  const result = await navigateForward.run({ page, input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.statusMessage, 'Keine naechste Seite in der Browser-History.');
});

test('browser.navigate_forward scheitert ohne Page-Handle', async () => {
  const result = await navigateForward.run({});

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.match(result.statusMessage, /Kein Page-Handle/);
});

test('browser.reload laedt die Seite mit derselben waitUntil-Strategie wie browser.open_url neu', async () => {
  const { page, calls } = pageStub();
  const result = await reload.run({ page, input: { timeoutMs: 8000 } });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.statusMessage, 'Seite wurde neu geladen.');
  assert.equal(result.url, 'https://example.test/aktuell');
  assert.deepEqual(calls, [{ method: 'reload', options: { waitUntil: 'domcontentloaded', timeout: 8000 } }]);
});

test('browser.reload faengt Fehler beim Neuladen ohne Absturz ab', async () => {
  const { page } = pageStub({
    reload: async () => {
      throw new Error('Verbindung unterbrochen');
    },
  });
  const result = await reload.run({ page, input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.statusMessage, 'Seite konnte nicht neu geladen werden.');
  assert.equal(result.error, 'Verbindung unterbrochen');
});

test('browser.reload scheitert ohne Page-Handle', async () => {
  const result = await reload.run({ page: {}, input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.match(result.statusMessage, /Kein Page-Handle/);
});
