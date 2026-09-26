'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { launchConfiguredBrowserWithProfileRetry } = require('../../resources/node/register/lib/browser-launcher.cjs');

test('profile lock waits and retries the exact same identity path', async () => {
  const previousEngine = process.env.MAIL_REGISTRATION_BROWSER_ENGINE;
  process.env.MAIL_REGISTRATION_BROWSER_ENGINE = 'chrome';
  const profilePath = '/tmp/followflow-person-17-account-2';
  const attemptedPaths = [];
  const waits = [];
  const browser = { id: 'shared-profile-browser' };
  let attemptCount = 0;

  try {
    const result = await launchConfiguredBrowserWithProfileRetry({
      puppeteer: {
        async launch(options) {
          attemptedPaths.push(options.userDataDir);
          attemptCount += 1;

          if (attemptCount < 3) {
            throw new Error('ProcessSingletonLock: user data directory is already in use');
          }

          return browser;
        },
      },
      runtimeConfig: {
        browserEngine: 'chrome',
        browserProfilePath: profilePath,
        browserProfileLockRetryDelaysMs: [0, 0],
      },
      launchOptions: { userDataDir: profilePath },
      onProfileWait: (wait) => waits.push(wait),
    });

    assert.equal(result.browser, browser);
    assert.deepEqual(attemptedPaths, [profilePath, profilePath, profilePath]);
    assert.equal(waits.length, 2);
    assert.equal(waits[0].nextProfilePath, profilePath);
    assert.equal(waits[0].attempt, 1);
  } finally {
    if (previousEngine === undefined) delete process.env.MAIL_REGISTRATION_BROWSER_ENGINE;
    else process.env.MAIL_REGISTRATION_BROWSER_ENGINE = previousEngine;
  }
});

test('persistent profile lock fails explicitly without making a fresh profile', async () => {
  const previousEngine = process.env.MAIL_REGISTRATION_BROWSER_ENGINE;
  process.env.MAIL_REGISTRATION_BROWSER_ENGINE = 'chrome';
  const profilePath = '/tmp/followflow-person-17-account-2';
  const attemptedPaths = [];
  const waits = [];

  try {
    await assert.rejects(
      launchConfiguredBrowserWithProfileRetry({
        puppeteer: {
          async launch(options) {
            attemptedPaths.push(options.userDataDir);
            throw new Error('user data directory is already in use');
          },
        },
        runtimeConfig: {
          browserEngine: 'chrome',
          browserProfilePath: profilePath,
          browserProfileLockRetryDelaysMs: [0, 0],
        },
        launchOptions: { userDataDir: profilePath },
        onProfileWait: (wait) => waits.push(wait),
      }),
      (error) => error.code === 'BROWSER_PROFILE_IN_USE'
        && /kein Ersatzprofil/i.test(error.message),
    );

    assert.deepEqual(attemptedPaths, [profilePath, profilePath, profilePath]);
    assert.equal(waits.length, 2);
    assert.ok(waits.every((wait) => wait.profilePath === profilePath));
  } finally {
    if (previousEngine === undefined) delete process.env.MAIL_REGISTRATION_BROWSER_ENGINE;
    else process.env.MAIL_REGISTRATION_BROWSER_ENGINE = previousEngine;
  }
});
