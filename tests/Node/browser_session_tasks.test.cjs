'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const openBrowserSessionTask = require('../../node/workflows/tasks/browser/open_browser_session.cjs');
const openWebmailSessionTask = require('../../node/workflows/tasks/browser/open_webmail_session.cjs');
const persistBrowserSessionTask = require('../../node/workflows/tasks/data/persist_browser_session.cjs');
const deleteBrowserSessionTask = require('../../node/workflows/tasks/data/delete_browser_session.cjs');
const { captureBrowserSession } = require('../../node/workflows/tasks/lib/webmail_session_capture.cjs');

test('session capture scopes open tabs to authorized origins and keeps per-tab session storage', async () => {
  const makePage = (url, values) => ({
    url: () => url,
    frames: () => [{ evaluate: async () => ({ url, origin: new URL(url).origin, ...values }) }],
  });
  const active = makePage('https://app.example.test/home', {
    localStorage: { shared: 'app' },
    sessionStorage: { tab: 'main' },
  });
  const auth = makePage('https://auth.example.test/sso', {
    localStorage: { auth: 'shared' },
    sessionStorage: { tab: 'auth-window' },
  });
  const unrelated = makePage('https://private.example.test/inbox', {
    localStorage: { private: 'must-not-capture' },
    sessionStorage: { privateTab: 'must-not-capture' },
  });
  const context = { pages: async () => [active, auth, unrelated] };
  [active, auth, unrelated].forEach((page) => { page.browserContext = () => context; });
  active.cookies = async () => [];

  const session = await captureBrowserSession(active, {
    authorizedOrigins: ['https://auth.example.test/login'],
    windowId: 'main',
  });

  assert.deepEqual(session.origins.map((entry) => entry.origin), [
    'https://app.example.test',
    'https://auth.example.test',
  ]);
  assert.deepEqual(session.windows.map((window) => window.windowId), ['main', 'tab-2']);
  assert.deepEqual(session.windows[0].origins[0].sessionStorage, { tab: 'main' });
  assert.deepEqual(session.windows[1].origins[0].sessionStorage, { tab: 'auth-window' });
  assert.equal(JSON.stringify(session).includes('must-not-capture'), false);
});

test('capture and restore retain partitioned-cookie identity and declare unsupported storage types', async () => {
  const partitionKey = {
    topLevelSite: 'https://top.example.test',
    hasCrossSiteAncestor: true,
  };
  const page = {
    url: () => 'https://app.example.test/account',
    frames: () => [{ evaluate: async () => ({
      url: 'https://app.example.test/account',
      origin: 'https://app.example.test',
      localStorage: {},
      sessionStorage: {},
    }) }],
    cookies: async () => [{
      name: 'partitioned',
      value: 'synthetic',
      domain: 'app.example.test',
      path: '/',
      partitionKey,
    }],
    target: () => ({ createCDPSession: async () => { throw new Error('use page cookie fallback'); } }),
    async setCookie(cookie) {
      this.restoredCookie = cookie;
    },
    async goto(url) {
      this.currentUrl = url;
    },
    browserContext: () => ({ pages: async () => [page] }),
  };
  const captured = await captureBrowserSession(page);

  assert.deepEqual(captured.cookies[0].partitionKey, partitionKey);
  assert.equal(captured.capabilities.cookiePartitionKeys, 'preserved_when_present');
  assert.equal(captured.capabilities.indexedDb, 'not_captured');

  await require('../../node/workflows/tasks/lib/browser_session_restore.cjs')
    .restoreBrowserSession(page, captured, 'https://app.example.test/account');

  assert.deepEqual(page.restoredCookie.partitionKey, partitionKey);
});

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
      return {
        localStorageRestored: Object.keys(payload.localStorage || {}).length > 0,
        sessionStorageRestored: Object.keys(payload.sessionStorage || {}).length > 0,
      };
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

test('missing requested browser session opens only the configured fallback URL', async () => {
  const calls = [];
  const page = {
    currentUrl: 'about:blank',
    async goto(url) {
      this.currentUrl = url;
      calls.push(url);
    },
    url() {
      return this.currentUrl;
    },
  };
  const result = await openBrowserSessionTask.run({
    page,
    input: {
      session_key: 'shared-webmail',
      fallback_url: 'https://mail.example.test/login',
      automatic_browser_session: true,
    },
    browser_sessions: {
      unrelated: {
        session_key: 'unrelated',
        final_url: 'https://other.example.test/account',
        cookies: [{ name: 'sid', value: 'wrong', domain: '.other.example.test', path: '/' }],
      },
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'skipped');
  assert.equal(result.sessionFound, false);
  assert.equal(result.browserSessionAutoLoaded, true);
  assert.deepEqual(calls, ['https://mail.example.test/login']);
});

test('domain lookup never falls back to a newer session from another domain', async () => {
  const calls = [];
  const page = {
    currentUrl: 'about:blank',
    async setCookie(cookie) {
      calls.push(['setCookie', cookie]);
    },
    async goto(url) {
      this.currentUrl = url;
      calls.push(['goto', url]);
    },
    url() {
      return this.currentUrl;
    },
  };
  const result = await openBrowserSessionTask.run({
    page,
    input: { target_domain: 'wanted.example.test' },
    browser_sessions: {
      newer: {
        session_key: 'newer',
        final_url: 'https://unrelated.example.test/account',
        domain: 'unrelated.example.test',
        cookies: [{ name: 'sid', value: 'wrong', domain: '.unrelated.example.test', path: '/' }],
      },
    },
  });

  assert.equal(result.sessionFound, false);
  assert.deepEqual(calls, []);
});

test('explicit browser session key rejects a conflicting target domain', async () => {
  const calls = [];
  const page = {
    currentUrl: 'about:blank',
    async setCookie(cookie) {
      calls.push(['setCookie', cookie]);
    },
    async goto(url) {
      this.currentUrl = url;
      calls.push(['goto', url]);
    },
    url() {
      return this.currentUrl;
    },
  };
  const result = await openBrowserSessionTask.run({
    page,
    input: { session_key: 'shop', target_domain: 'mail.example.test' },
    browser_sessions: {
      shop: {
        session_key: 'shop',
        final_url: 'https://shop.example.test/account',
        domain: 'shop.example.test',
        cookies: [{ name: 'sid', value: 'shop-only', domain: '.shop.example.test', path: '/' }],
      },
    },
  });

  assert.equal(result.sessionFound, false);
  assert.deepEqual(calls, []);
});

test('automatic session owner lookup selects only that owner and requested domain', async () => {
  const calls = [];
  const page = {
    currentUrl: 'about:blank',
    async setCookie(cookie) {
      calls.push(['setCookie', cookie.value]);
    },
    async goto(url) {
      this.currentUrl = url;
      calls.push(['goto', url]);
    },
    async evaluate(_callback, payload) {
      calls.push(['storage', payload]);
      return {
        localStorageRestored: Object.keys(payload.localStorage || {}).length > 0,
        sessionStorageRestored: Object.keys(payload.sessionStorage || {}).length > 0,
      };
    },
    async reload() {},
    url() {
      return this.currentUrl;
    },
  };
  const result = await openBrowserSessionTask.run({
    page,
    input: { session_key: 'person-9-account-3', target_domain: 'two.example.test' },
    browser_sessions: {
      'person-9-account-3--one.example.test': {
        session_key: 'person-9-account-3--one.example.test',
        owner_session_key: 'person-9-account-3',
        domain: 'one.example.test',
        final_url: 'https://one.example.test/account',
        cookies: [{ name: 'sid', value: 'wrong-owner-domain', domain: '.one.example.test', path: '/' }],
      },
      'person-9-account-3--two.example.test': {
        session_key: 'person-9-account-3--two.example.test',
        owner_session_key: 'person-9-account-3',
        domain: 'two.example.test',
        final_url: 'https://two.example.test/inbox',
        cookies: [{ name: 'sid', value: 'right-owner-domain', domain: '.two.example.test', path: '/' }],
      },
      'person-10-account-3--two.example.test': {
        session_key: 'person-10-account-3--two.example.test',
        owner_session_key: 'person-10-account-3',
        domain: 'two.example.test',
        final_url: 'https://two.example.test/other-account',
        cookies: [{ name: 'sid', value: 'wrong-owner', domain: '.two.example.test', path: '/' }],
      },
    },
  });

  assert.equal(result.sessionFound, true);
  assert.equal(result.sessionKey, 'person-9-account-3--two.example.test');
  assert.deepEqual(calls[0], ['setCookie', 'right-owner-domain']);
  assert.equal(page.url(), 'https://two.example.test/inbox');
});

test('automatic snapshots use stable owner identity and retain one entry per domain', async (t) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'followflow-session-test-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));

  const makeContext = (domain) => {
    const url = `https://${domain}/account`;
    const page = {
      url: () => url,
      frames: () => [{
        async evaluate() {
          return {
            origin: `https://${domain}`,
            url,
            localStorage: { token: `storage-${domain}` },
            sessionStorage: {},
          };
        },
      }],
      target: () => ({
        async createCDPSession() {
          return {
            async send() {
              return { cookies: [{ name: 'sid', value: `cookie-${domain}`, domain: `.${domain}`, path: '/' }] };
            },
            async detach() {},
          };
        },
      }),
    };

    return {
      page,
      input: {
        automatic_browser_session: true,
        session_key: 'person-9-account-3',
        target_domain: domain,
      },
      browserSessionAutomation: {
        configured_session_key: '',
        effective_session_key: 'person-9-account-3',
      },
      workflowTaskRunDirectory: directory,
      observabilityLevel: 'off',
    };
  };

  const one = await persistBrowserSessionTask.run(makeContext('one.example.test'));
  const two = await persistBrowserSessionTask.run(makeContext('two.example.test'));

  assert.equal(one.sessionKey, 'person-9-account-3--one.example.test');
  assert.equal(two.sessionKey, 'person-9-account-3--two.example.test');
  assert.equal(one.ownerSessionKey, 'person-9-account-3');
  assert.equal(two.ownerSessionKey, 'person-9-account-3');
});

test('delete session honors false flags and leaves the active unrelated origin untouched', async () => {
  const calls = [];
  const page = {
    async cookies() {
      calls.push('cookies');
      return [{ name: 'sid', value: 'active', domain: '.one.example.test', path: '/' }];
    },
    async evaluate() {
      calls.push('evaluate');
      return true;
    },
    url: () => 'https://one.example.test/account',
  };
  const result = await deleteBrowserSessionTask.run({
    page,
    input: {
      target_domain: 'two.example.test',
      clear_cookies: false,
      clear_storage: false,
    },
    observabilityLevel: 'off',
  });

  assert.equal(result.clearCookies, false);
  assert.equal(result.clearStorage, false);
  assert.deepEqual(calls, []);
});

test('automatic browser session load without session or fallback is a non-blocking no-op', async () => {
  const page = {
    url: () => 'about:blank',
    async goto() {
      throw new Error('goto must not run');
    },
  };
  const result = await openBrowserSessionTask.run({
    page,
    input: {
      session_key: 'workflow-12-person-null',
      automatic_browser_session: true,
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'skipped');
  assert.equal(result.browserSessionAutoLoaded, true);
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
    async evaluate(_callback, payload) {
      return {
        localStorageRestored: Object.keys(payload.localStorage || {}).length > 0,
        sessionStorageRestored: Object.keys(payload.sessionStorage || {}).length > 0,
      };
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

test('webmail session uses newer matching person session instead of stale requested verification session', async () => {
  const calls = [];
  const page = {
    currentUrl: '',
    async setCookie(cookie) {
      calls.push(['setCookie', cookie.value]);
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
    input: { mailbox_source: 'verification' },
    account: {
      email: 'same@example.test',
      webmailSession: {
        capturedAt: '2026-07-10T00:27:07.000Z',
        finalUrl: 'https://mail.example.test/inbox/new',
        cookies: [{ name: 'sid', value: 'new-person-session', domain: '.example.test', path: '/' }],
      },
    },
    verificationMailbox: {
      email: 'same@example.test',
      webmailSession: {
        capturedAt: '2026-07-09T00:00:00.000Z',
        finalUrl: 'https://mail.example.test/inbox/stale',
        cookies: [{ name: 'sid', value: 'stale-verification-session', domain: '.example.test', path: '/' }],
      },
    },
  };

  const result = await openWebmailSessionTask.run(context);

  assert.equal(result.ok, true);
  assert.equal(result.mailboxSource, 'person');
  assert.equal(result.requestedMailboxSource, 'verification');
  assert.equal(result.mailboxSourceAdjusted, true);
  assert.equal(result.finalUrl, 'https://mail.example.test/inbox/new');
  assert.deepEqual(calls, [
    ['setCookie', 'new-person-session'],
    ['goto', 'https://mail.example.test/inbox/new'],
  ]);
});

test('strict webmail source does not substitute a newer matching session', async () => {
  const page = {
    currentUrl: '',
    async setCookie() {},
    async goto(url) {
      this.currentUrl = url;
    },
    url() {
      return this.currentUrl;
    },
  };
  const context = {
    page,
    input: { mailbox_source: 'verification', strict_mailbox_source: true },
    account: {
      email: 'same@example.test',
      webmailSession: {
        capturedAt: '2026-07-10T00:27:07.000Z',
        finalUrl: 'https://mail.example.test/inbox/new',
        cookies: [{ name: 'sid', value: 'new', domain: '.example.test', path: '/' }],
      },
    },
    verificationMailbox: {
      email: 'same@example.test',
      webmailSession: {
        capturedAt: '2026-07-09T00:00:00.000Z',
        finalUrl: 'https://mail.example.test/inbox/stale',
        cookies: [{ name: 'sid', value: 'stale', domain: '.example.test', path: '/' }],
      },
    },
  };

  const result = await openWebmailSessionTask.run(context);

  assert.equal(result.mailboxSource, 'verification');
  assert.equal(result.mailboxSourceAdjusted, false);
  assert.equal(result.finalUrl, 'https://mail.example.test/inbox/stale');
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
