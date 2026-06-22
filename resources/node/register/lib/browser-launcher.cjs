const fs = require('fs');
const childProcess = require('child_process');

const BROWSER_ENGINE_CHROME = 'chrome';
const BROWSER_ENGINE_CLOAK = 'cloak';
const BROWSER_ENGINE_CLOAK_WITH_FALLBACK = 'cloak-with-chrome-fallback';

function normalizeBrowserEngine(value = '') {
  const normalized = String(value || '').trim().toLowerCase();

  if (['cloak', 'cloakbrowser'].includes(normalized)) {
    return BROWSER_ENGINE_CLOAK;
  }

  if ([
    'cloak-with-chrome-fallback',
    'cloak_with_chrome_fallback',
    'cloak-fallback',
  ].includes(normalized)) {
    return BROWSER_ENGINE_CLOAK_WITH_FALLBACK;
  }

  return BROWSER_ENGINE_CHROME;
}

function resolveBrowserEngine(runtimeConfig = {}) {
  return normalizeBrowserEngine(
    process.env.MAIL_REGISTRATION_BROWSER_ENGINE
      || runtimeConfig.browserEngine
      || runtimeConfig.browser_engine
      || BROWSER_ENGINE_CHROME,
  );
}

function isBrowserProfileLockError(error) {
  return /already running|processsingleton|userdatadir|user data dir|user data directory/i.test(
    String(error?.message || error || ''),
  );
}

function buildCloakArgs(args = []) {
  return args.filter((argument) => ![
    '--disable-gpu',
    '--disable-blink-features=AutomationControlled',
  ].includes(argument));
}

function normalizeText(value) {
  return String(value || '').trim();
}

function fileExists(filePath) {
  try {
    return fs.existsSync(filePath);
  } catch {
    return false;
  }
}

function resolveCommand(command) {
  try {
    return normalizeText(childProcess.execFileSync('/bin/sh', ['-lc', `command -v ${command}`], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    }));
  } catch {
    return '';
  }
}

function systemChromeCandidates(runtimeConfig = {}) {
  return [
    runtimeConfig.browserExecutablePath,
    runtimeConfig.browser_executable_path,
    runtimeConfig.chromeExecutablePath,
    runtimeConfig.chrome_executable_path,
    process.env.MAIL_REGISTRATION_BROWSER_EXECUTABLE_PATH,
    process.env.PUPPETEER_EXECUTABLE_PATH,
    process.env.CHROME_PATH,
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
    '/snap/bin/chromium',
    '/opt/google/chrome/chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    resolveCommand('google-chrome-stable'),
    resolveCommand('google-chrome'),
    resolveCommand('chromium-browser'),
    resolveCommand('chromium'),
  ]
    .map(normalizeText)
    .filter((candidate, index, candidates) => candidate !== '' && candidates.indexOf(candidate) === index);
}

function isMissingPuppeteerBrowserError(error) {
  return /could not find chrome|could not find chromium|browser was not found|cache path is incorrectly configured/i.test(
    String(error?.message || error || ''),
  );
}

function isMissingDisplayError(error) {
  return /missing x server|the platform failed to initialize|ozone_platform_x11|no \$display/i.test(
    String(error?.message || error || ''),
  );
}

function shouldRetryHeadless(error, launchOptions = {}) {
  return launchOptions.headless === false && isMissingDisplayError(error);
}

function shouldForceHeadlessOnServer(launchOptions = {}) {
  return process.platform === 'linux'
    && !process.env.DISPLAY
    && !process.env.WAYLAND_DISPLAY
    && launchOptions.headless === false;
}

function browserLaunchOptions(runtimeConfig = {}, launchOptions = {}) {
  if (runtimeConfig.forceVisibleBrowser === true || runtimeConfig.force_visible_browser === true) {
    return launchOptions;
  }

  if (! shouldForceHeadlessOnServer(launchOptions)) {
    return launchOptions;
  }

  return {
    ...launchOptions,
    headless: 'new',
  };
}

async function launchHeadlessFallback(puppeteer, launchOptions, extraOptions = {}) {
  return puppeteer.launch({
    ...launchOptions,
    ...extraOptions,
    headless: 'new',
  });
}

function puppeteerInstallHint(error) {
  const message = String(error?.message || error || '');
  const cachePath = message.match(/cache path is(?: incorrectly configured)? \(which is: ([^)]+)\)/i)?.[1]
    || process.env.PUPPETEER_CACHE_DIR
    || '~/.cache/puppeteer';

  return [
    message,
    '',
    'Chrome/Chromium wurde weder im Puppeteer-Cache noch als System-Browser gefunden.',
    `Server-Fix: cd in das Projektverzeichnis und ausfuehren: PUPPETEER_CACHE_DIR="${cachePath}" npx puppeteer browsers install chrome`,
    'Alternative: MAIL_REGISTRATION_BROWSER_EXECUTABLE_PATH oder PUPPETEER_EXECUTABLE_PATH auf einen vorhandenen Chrome/Chromium setzen.',
  ].join('\n');
}

async function launchChrome(puppeteer, launchOptions) {
  return launchChromeWithFallbacks(puppeteer, {}, launchOptions);
}

async function launchChromeWithFallbacks(puppeteer, runtimeConfig, launchOptions) {
  launchOptions = browserLaunchOptions(runtimeConfig, launchOptions);

  const triedExecutables = [];
  let lastFallbackError = null;

  try {
    return await puppeteer.launch(launchOptions);
  } catch (error) {
    if (shouldRetryHeadless(error, launchOptions)) {
      return launchHeadlessFallback(puppeteer, launchOptions);
    }

    if (! isMissingPuppeteerBrowserError(error)) {
      throw error;
    }

    for (const executablePath of systemChromeCandidates(runtimeConfig)) {
      triedExecutables.push(executablePath);

      if (! fileExists(executablePath)) {
        continue;
      }

      try {
        return await puppeteer.launch({
          ...launchOptions,
          executablePath,
        });
      } catch (fallbackError) {
        if (shouldRetryHeadless(fallbackError, launchOptions)) {
          try {
            return await launchHeadlessFallback(puppeteer, launchOptions, {
              executablePath,
            });
          } catch (headlessFallbackError) {
            lastFallbackError = headlessFallbackError;

            continue;
          }
        }

        lastFallbackError = fallbackError;
        // Continue with the next known system browser path.
      }
    }

    const fallbackDetails = lastFallbackError
      ? `\nLetzter System-Chrome-Startfehler: ${String(lastFallbackError?.message || lastFallbackError)}`
      : '';
    const enriched = new Error(`${puppeteerInstallHint(error)}\nGepruefte Systempfade: ${triedExecutables.join(', ') || 'keine'}${fallbackDetails}`);
    enriched.cause = error;
    throw enriched;
  }
}

async function launchCloak(runtimeConfig, launchOptions) {
  launchOptions = browserLaunchOptions(runtimeConfig, launchOptions);

  const { launch } = await import('cloakbrowser/puppeteer');
  const {
    args = [],
    headless,
    ...puppeteerLaunchOptions
  } = launchOptions;
  const cloakOptions = {
    args: buildCloakArgs(args),
    headless: headless !== false,
    humanize: runtimeConfig.cloakHumanizeEnabled === true,
    launchOptions: puppeteerLaunchOptions,
  };

  if (runtimeConfig.cloakHumanPreset) {
    cloakOptions.humanPreset = String(runtimeConfig.cloakHumanPreset);
  }

  return launch(cloakOptions);
}

async function launchConfiguredBrowser({
  puppeteer,
  runtimeConfig = {},
  launchOptions = {},
}) {
  const requestedEngine = resolveBrowserEngine(runtimeConfig);

  if (requestedEngine === BROWSER_ENGINE_CHROME) {
    return {
      browser: await launchChromeWithFallbacks(puppeteer, runtimeConfig, launchOptions),
      requestedEngine,
      activeEngine: BROWSER_ENGINE_CHROME,
      fallbackReason: null,
    };
  }

  try {
    return {
      browser: await launchCloak(runtimeConfig, launchOptions),
      requestedEngine,
      activeEngine: BROWSER_ENGINE_CLOAK,
      fallbackReason: null,
    };
  } catch (error) {
    if (
      requestedEngine !== BROWSER_ENGINE_CLOAK_WITH_FALLBACK
      || isBrowserProfileLockError(error)
    ) {
      throw error;
    }

    return {
      browser: await launchChromeWithFallbacks(puppeteer, runtimeConfig, launchOptions),
      requestedEngine,
      activeEngine: BROWSER_ENGINE_CHROME,
      fallbackReason: String(error?.message || error || 'CloakBrowser launch failed'),
    };
  }
}

module.exports = {
  BROWSER_ENGINE_CHROME,
  BROWSER_ENGINE_CLOAK,
  BROWSER_ENGINE_CLOAK_WITH_FALLBACK,
  isBrowserProfileLockError,
  launchConfiguredBrowser,
  normalizeBrowserEngine,
  resolveBrowserEngine,
};
