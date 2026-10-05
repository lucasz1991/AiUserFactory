'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
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

function windowsLauncher(environment, existingFiles = []) {
  const source = fs.readFileSync(path.resolve(__dirname, '../../resources/node/register/lib/browser-launcher.cjs'), 'utf8');
  const module = { exports: {} };
  vm.runInNewContext(source, {
    module,
    process: { platform: 'win32', env: environment },
    require(name) {
      if (name === 'fs') return { existsSync: (filename) => existingFiles.includes(filename) };
      if (name === 'path') return path.win32;
      if (name === 'child_process') return { execFileSync: () => { throw new Error('No Unix shell in the Windows fixture'); } };
      throw new Error('Unexpected dependency: ' + name);
    },
    setTimeout,
  });
  return module.exports;
}

test('Windows system Chrome and Edge paths are discovered without new application configuration', async () => {
  const chrome = 'C:\\Custom Programs\\Google\\Chrome\\Application\\chrome.exe';
  const launcher = windowsLauncher({
    ProgramFiles: 'C:\\Custom Programs',
    'ProgramFiles(x86)': 'C:\\Programs 32',
    LOCALAPPDATA: 'C:\\Fixture User\\AppData\\Local',
  }, [chrome]);
  const candidates = Array.from(launcher.systemChromeCandidates({}));
  assert.equal(candidates[0], chrome);
  assert.ok(candidates.includes('C:\\Programs 32\\Microsoft\\Edge\\Application\\msedge.exe'));
  assert.ok(candidates.includes('C:\\Fixture User\\AppData\\Local\\Google\\Chrome\\Application\\chrome.exe'));
  const launches = [];
  const browser = {};
  const result = await launcher.launchConfiguredBrowser({
    puppeteer: { async launch(options) {
      launches.push(options);
      if (!options.executablePath) throw new Error('Could not find Chrome in configured cache path');
      return browser;
    } },
    runtimeConfig: { browserEngine: 'chrome' },
    launchOptions: { headless: false, userDataDir: 'C:\\Fixture Profile', args: ['--window-size=1366,900'] },
  });
  assert.equal(result.browser, browser);
  assert.equal(launches.length, 2);
  assert.equal(launches[1].executablePath, chrome);
  assert.equal(launches[1].headless, false);
  assert.equal(launches[1].userDataDir, 'C:\\Fixture Profile');
  assert.deepEqual(launches[1].args, ['--window-size=1366,900']);
});

test('configured executable paths stay ahead of Windows candidates and portable mode forbids system discovery', async () => {
  const configured = 'D:\\Authorized Browser\\chrome.exe';
  const launcher = windowsLauncher({
    ProgramFiles: 'C:\\Programs',
    LOCALAPPDATA: 'C:\\Fixture User\\AppData\\Local',
    CLIENTCONTROLLER_PORTABLE_RUNTIME: '1',
  }, [configured]);
  assert.deepEqual(Array.from(launcher.systemChromeCandidates({ browserExecutablePath: configured })), [configured]);
  assert.deepEqual(Array.from(launcher.systemChromeCandidates({})), []);
  const nonPortable = windowsLauncher({ ProgramFiles: 'C:\\Programs' });
  assert.equal(nonPortable.systemChromeCandidates({ browserExecutablePath: configured })[0], configured);
  const launches = [];
  const result = await launcher.launchConfiguredBrowser({
    puppeteer: { async launch(options) {
      launches.push(options);
      if (!options.executablePath) throw new Error('Could not find Chrome');
      return { portableConfigured: true };
    } },
    runtimeConfig: { browserEngine: 'chrome', browserExecutablePath: configured },
    launchOptions: { headless: 'new', args: [] },
  });
  assert.equal(result.browser.portableConfigured, true);
  assert.equal(launches[1].executablePath, configured);
  assert.equal(launches[1].headless, 'new');
});
