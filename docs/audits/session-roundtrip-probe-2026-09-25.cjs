'use strict';

// Acceptance probe for the 2026-09-25 session capture/restore fixes.
// No existing browser profile, application database or real account is used.
// All page requests are intercepted; only synthetic .test origins are served.
// Run from AiUserFactory: node docs/audits/session-roundtrip-probe-2026-09-25.cjs

const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const puppeteer = require(`${root}/node_modules/puppeteer`);
const { captureBrowserSession } = require(`${root}/node/workflows/tasks/lib/webmail_session_capture.cjs`);
const { restoreBrowserSession } = require(`${root}/node/workflows/tasks/lib/browser_session_restore.cjs`);
const deleteTask = require(`${root}/node/workflows/tasks/data/delete_browser_session.cjs`);

const one = 'https://one.followflow.test';
const two = 'https://two.followflow.test';

async function localPage(context) {
  const page = await context.newPage();
  if (!page.syntheticInterceptionInstalled) await interceptSyntheticOrigins(page);
  return page;
}

async function interceptSyntheticOrigins(page) {
  if (page.syntheticInterceptionInstalled) return page;
  await page.setRequestInterception(true);
  page.on('request', async (request) => {
    try {
      const origin = new URL(request.url()).origin;
      if ([one, two].includes(origin)) {
        await request.respond({
          status: 200,
          contentType: 'text/html',
          body: '<!doctype html><title>Isolated session audit</title><p>Synthetic test page</p>',
        });
      } else {
        await request.abort();
      }
    } catch (error) {
      // A closed target may cancel its final request while cleanup is running.
      if (!page.isClosed()) console.error('Synthetic request interception failed:', error.message);
    }
  });
  page.syntheticInterceptionInstalled = true;
  return page;
}

function interceptCreatedPages(context) {
  const createPage = context.newPage.bind(context);
  context.newPage = async () => interceptSyntheticOrigins(await createPage());
}

async function state(page) {
  return page.evaluate(() => ({
    localStorage: Object.fromEntries(Object.entries(localStorage)),
    sessionStorage: Object.fromEntries(Object.entries(sessionStorage)),
  }));
}

(async () => {
  const browser = await puppeteer.launch({ headless: true, args: ['--disable-background-networking'] });
  try {
    console.log(JSON.stringify({
      runtime: process.version,
      puppeteer: require(`${root}/node_modules/puppeteer/package.json`).version,
      browser: await browser.version(),
    }));
    const source = await browser.createBrowserContext();
    console.log('source context created');
    const sourceOne = await localPage(source);
    console.log('first synthetic page created');
    const sourceTwo = await localPage(source);
    console.log('second synthetic page created');
    const sourceOneSecondTab = await localPage(source);
    console.log('synthetic pages created');
    await sourceOne.goto(one + '/account', { timeout: 15000 });
    console.log('first synthetic page navigated');
    await sourceTwo.goto(two + '/inbox', { timeout: 15000 });
    console.log('second synthetic page navigated');
    await sourceOneSecondTab.goto(one + '/second-tab', { timeout: 15000 });
    console.log('synthetic pages navigated');
    await sourceOne.evaluate(() => {
      localStorage.setItem('one-local', 'synthetic-one');
      sessionStorage.setItem('one-session', 'synthetic-one');
    });
    await sourceTwo.evaluate(() => {
      localStorage.setItem('two-local', 'synthetic-two');
      sessionStorage.setItem('two-session', 'synthetic-two');
    });
    await sourceOneSecondTab.evaluate(() => {
      sessionStorage.setItem('one-session', 'synthetic-second-tab');
    });
    await sourceOne.setCookie({ name: 'sid-one', value: 'synthetic-one', domain: 'one.followflow.test', path: '/', secure: true, httpOnly: true });
    await sourceTwo.setCookie({ name: 'sid-two', value: 'synthetic-two', domain: 'two.followflow.test', path: '/', secure: true, httpOnly: true });
    const capturedOne = await captureBrowserSession(sourceOne, {
      authorizedOrigins: [two],
      windowId: 'primary',
    });
    console.log('multi-tab session captured');
    assert.deepEqual(capturedOne.origins.map(value => value.origin), [one, two]);
    assert.deepEqual(capturedOne.cookies.map(value => value.name).sort(), ['sid-one', 'sid-two']);
    assert.equal(capturedOne.schemaVersion, 2);
    assert.equal(capturedOne.windows.length, 3);
    assert.equal(capturedOne.windows[0].origins[0].sessionStorage['one-session'], 'synthetic-one');
    assert.equal(capturedOne.windows[2].origins[0].sessionStorage['one-session'], 'synthetic-second-tab');
    console.log(JSON.stringify({
      probe: 'multi_tab_capture',
      openTabs: (await source.pages()).length,
      capturedOrigins: capturedOne.origins.map(value => value.origin),
      capturedCookieNames: capturedOne.cookies.map(value => value.name),
      perTabSessionStorage: capturedOne.windows.map(value => ({ windowId: value.windowId, origins: value.origins })),
    }));

    const restoredContext = await browser.createBrowserContext();
    interceptCreatedPages(restoredContext);
    const restoredPage = await localPage(restoredContext);
    const baseline = await restoreBrowserSession(restoredPage, capturedOne, one + '/account');
    const restoredState = await state(restoredPage);
    const restoredCookies = await restoredPage.cookies();
    assert.equal(restoredState.localStorage['one-local'], 'synthetic-one');
    assert.equal(restoredState.sessionStorage['one-session'], 'synthetic-one');
    assert.equal(restoredCookies.find(cookie => cookie.name === 'sid-one').httpOnly, true);
    console.log(JSON.stringify({
      probe: 'fresh_context_same_origin_roundtrip',
      cookieRestored: restoredCookies.some(cookie => cookie.name === 'sid-one'),
      localStorageRestored: Boolean(restoredState.localStorage['one-local']),
      sessionStorageRestored: Boolean(restoredState.sessionStorage['one-session']),
      summary: baseline,
    }));

    const multiContext = await browser.createBrowserContext();
    interceptCreatedPages(multiContext);
    const multiPage = await localPage(multiContext);
    const multiSummary = await restoreBrowserSession(multiPage, capturedOne, one + '/account', { windowId: 'primary' });
    await multiPage.goto(two + '/inbox');
    const laterState = await state(multiPage);
    assert.equal(multiSummary.storageOriginCount, 2);
    assert.equal(laterState.localStorage['two-local'], 'synthetic-two');
    assert.equal(laterState.sessionStorage['two-session'], undefined);
    assert.equal(multiSummary.sessionStorageRestoredCount, 1);
    assert.equal(multiSummary.sessionStorageFailureCount, 2);
    console.log(JSON.stringify({
      probe: 'multi_origin_restore_late_navigation',
      reportedOriginsRestored: multiSummary.storageOriginCount,
      laterOrigin: two,
      localStorageRestored: laterState.localStorage['two-local'] === 'synthetic-two',
      activeWindowSessionStorageRestored: multiSummary.sessionStorageRestoredCount,
      otherWindowSessionStorageFailures: multiSummary.sessionStorageFailureCount,
      selectedWindow: 'primary',
    }));

    const deletion = await deleteTask.run({
      page: restoredPage,
      input: { target_domain: 'two.followflow.test', clear_storage: false, clear_cookies: false },
      observabilityLevel: 'off',
    });
    const stateAfterDeletion = await state(restoredPage);
    assert.equal(stateAfterDeletion.localStorage['one-local'], 'synthetic-one');
    assert.equal(stateAfterDeletion.sessionStorage['one-session'], 'synthetic-one');
    console.log(JSON.stringify({
      probe: 'false_delete_flags_preserve_unrelated_origin',
      currentOrigin: one,
      targetDomain: 'two.followflow.test',
      resultFlags: { clearCookies: deletion.clearCookies, clearStorage: deletion.clearStorage },
      currentOriginLocalStoragePreserved: stateAfterDeletion.localStorage['one-local'] === 'synthetic-one',
      currentOriginSessionStoragePreserved: stateAfterDeletion.sessionStorage['one-session'] === 'synthetic-one',
    }));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
