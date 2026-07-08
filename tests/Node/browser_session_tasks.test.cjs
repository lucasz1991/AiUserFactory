'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const openBrowserSessionTask = require('../../node/workflows/tasks/browser/open_browser_session.cjs');

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
          finalUrl: 'https://shop.example/account',
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
  assert.equal(result.storageOriginCount, 1);
  assert.deepEqual(calls[0], ['setCookie', [{ name: 'sid', value: 'abc', domain: '.shop.example', path: '/' }]]);
  assert.deepEqual(calls.at(-2), ['goto', 'https://shop.example/account']);
  assert.deepEqual(calls.at(-1), ['reload']);
});
