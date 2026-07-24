'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const deleteSession = require('./delete_browser_session.cjs');

function fakePage(url) {
  return {
    url: () => url,
    // Kein target()/screenshot() – erzwingt den einfachen Pfad ohne CDP/Preview.
  };
}

test('delete session is a no-op instead of a hard failure when nothing is loaded', async () => {
  const result = await deleteSession.run({
    page: fakePage('about:blank'),
    input: {},
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'skipped');
  assert.match(result.statusMessage, /keine Session zum Loeschen/i);
});

test('delete session derives the domain from the active browser window when the page is blank', async () => {
  const result = await deleteSession.run({
    page: fakePage('about:blank'),
    activeBrowserWindow: 'main',
    browserWindows: [{ key: 'main', url: 'https://gmx.net/postfach' }],
    input: {},
  });

  // Mit ableitbarer Domain darf es kein No-op mehr sein.
  assert.notEqual(result.status, 'skipped');
  assert.equal(result.domain, 'gmx.net');
});

test('delete session still fails clearly without any page handle', async () => {
  const result = await deleteSession.run({ input: {} });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
});
