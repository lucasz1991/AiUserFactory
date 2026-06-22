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

async function launchChrome(puppeteer, launchOptions) {
  return puppeteer.launch(launchOptions);
}

async function launchCloak(runtimeConfig, launchOptions) {
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
      browser: await launchChrome(puppeteer, launchOptions),
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
      browser: await launchChrome(puppeteer, launchOptions),
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
