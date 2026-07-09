'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const openBrowserSessionTask = require('../../node/workflows/tasks/browser/open_browser_session.cjs');
const openWebmailSessionTask = require('../../node/workflows/tasks/browser/open_webmail_session.cjs');

test('saved browser session restores cookies and opens the stored final URL', async () => {
  const calls = [];
  const page = {
    currentUrl: '',
    async setCookie(...cookies) {
      calls.push(['setCookie', cookies]);
    },
    async goto(url) {
      this.currentUrl = url;
      calls.push(['goto', url]);
    },
    async evaluate(_callback, payload) {
      calls.push(['evaluate', payload]);
      return true;
    },
    async reload() {
      calls.push(['reload']);
    },
    url() {
      return this.currentUrl;
    },
  };
  const context = {
    page,
    input: {
      session_key: 'shop',
    },
    workflow: {
      browser_sessions: {
        shop: {
          session_key: 'shop',
          final_url: 'https://shop.example/account',
          domain: 'shop.example',
          cookies: [{ name: 'sid', value: 'abc', domain: '.shop.example', path: '/' }],
          origins: [{
            origin: 'https://shop.example',
            url: 'https://shop.example/',
            localStorage: { token: 'stored' },
            sessionStorage: {},
          }],
        },
      },
    },
  };

  const result = await openBrowserSessionTask.run(context);

  assert.equal(result.ok, true);
  assert.equal(result.url, 'https://shop.example/account');
  assert.equal(result.cookieCount, 1);
  assert.equal(result.cookieFailureCount, 0);
  assert.equal(result.storageOriginCount, 1);
  assert.equal(result.storageStrategy, 'origin-navigation');
  assert.equal(result.redirected, false);
  assert.deepEqual(calls[0], ['setCookie', [{ name: 'sid', value: 'abc', domain: '.shop.example', path: '/' }]]);
  assert.deepEqual(calls.at(-1), ['goto', 'https://shop.example/account']);
});

test('webmail session prepares stored origin storage before opening snake-case final URL', async () => {
  const calls = [];
  const page = {
    currentUrl: '',
    async setCookie(cookie) {
      calls.push(['setCookie', cookie]);
    },
    async evaluateOnNewDocument(_callback, payload) {
      calls.push(['evaluateOnNewDocument', payload]);
      return { identifier: 'session-storage-preload' };
    },
    async removeScriptToEvaluateOnNewDocument(identifier) {
      calls.push(['removeScriptToEvaluateOnNewDocument', identifier]);
    },
    async goto(url) {
      this.currentUrl = url;
      calls.push(['goto', url]);
    },
    url() {
      return this.currentUrl;
    },
  };
  const context = {
    page,
    input: {
      mailbox_source: 'verification',
    },
    verificationMailbox: {
      email: 'mailbox@example.test',
      webmailUrl: 'https://mail.example.test',
      webmail_session: {
        final_url: 'https://mail.example.test/inbox/last-message',
        cookies: [{ name: 'sid', value: 'stored', domain: '.example.test', path: '/' }],
        origins: [{
          origin: 'https://mail.example.test',
          localStorage: { token: 'stored' },
          sessionStorage: { view: 'inbox' },
        }],
      },
    },
  };

  const result = await openWebmailSessionTask.run(context);

  assert.equal(result.ok, true);
  assert.equal(result.finalUrl, 'https://mail.example.test/inbox/last-message');
  assert.equal(result.url, 'https://mail.example.test/inbox/last-message');
  assert.equal(result.targetUrlSource, 'session');
  assert.equal(result.storageStrategy, 'preload');
  assert.equal(result.storageOriginCount, 1);
  assert.deepEqual(calls.map(([name]) => name), [
    'setCookie',
    'evaluateOnNewDocument',
    'goto',
    'removeScriptToEvaluateOnNewDocument',
  ]);
});

test('failed cookie writes are reported instead of counted as restored', async () => {
  const page = {
    currentUrl: '',
    async setCookie() {
      throw new Error('unsupported cookie');
    },
    async goto(url) {
      this.currentUrl = url;
    },
    url() {
      return this.currentUrl;
    },
  };
  const context = {
    page,
    input: { session_key: 'broken' },
    browser_sessions: {
      broken: {
        session_key: 'broken',
        final_url: 'https://broken.example/account',
        cookies: [{ name: 'sid', value: 'abc', domain: '.broken.example', path: '/' }],
      },
    },
  };

  const result = await openBrowserSessionTask.run(context);

  assert.equal(result.ok, false);
  assert.equal(result.cookieAttemptCount, 1);
  assert.equal(result.cookieFailureCount, 1);
});
