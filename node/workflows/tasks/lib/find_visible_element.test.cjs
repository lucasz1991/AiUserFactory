'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { run: runClickTask } = require('../browser/click.cjs');

const {
  clickVisibleElement,
  findVisibleElement,
  framesForPage,
  selectorDiagnostics,
} = require('./find_visible_element.cjs');

function elementHandle(click) {
  return {
    asElement() {
      return this;
    },
    async click(options) {
      return click(options);
    },
    async dispose() {},
    async evaluate(_callback, selector) {
      return { selector, tag: 'button', text: 'Zum Postfach' };
    },
  };
}

function frameWithElement(handle, onEvaluate = null) {
  return {
    detached: false,
    async evaluateHandle(_callback, css, descendantCss, text, exact) {
      onEvaluate?.({ css, descendantCss, text, exact });

      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
    async waitForSelector() {
      throw new Error('Extended selectors must not use the CSS wait fallback.');
    },
  };
}

test('button:has-text searches the button text in the current frame', async () => {
  let selectorArguments = null;
  const handle = elementHandle(async () => {});
  const frame = frameWithElement(handle, (argumentsValue) => {
    selectorArguments = argumentsValue;
  });
  const page = { frames: () => [frame] };

  const found = await findVisibleElement(page, 'button:has-text("Zum Postfach")', 100);

  assert.equal(found, handle);
  assert.deepEqual(selectorArguments, {
    css: 'button',
    descendantCss: null,
    text: 'Zum Postfach',
    exact: false,
  });
});

test('detached frames are ignored before searching', () => {
  const detachedFrame = { detached: true };
  const activeFrame = { detached: false };

  assert.deepEqual(framesForPage({ frames: () => [detachedFrame, activeFrame] }), [activeFrame]);
});

test('click retries with a fresh handle after GMX replaces the frame', async () => {
  let frameRead = 0;
  let successfulClicks = 0;
  const staleHandle = elementHandle(async () => {
    throw new Error("Attempted to use detached Frame 'old-account-manager'.");
  });
  const freshHandle = elementHandle(async () => {
    successfulClicks += 1;
  });
  const staleFrame = frameWithElement(staleHandle);
  const freshFrame = frameWithElement(freshHandle);
  const page = {
    frames() {
      frameRead += 1;

      return [frameRead === 1 ? staleFrame : freshFrame];
    },
  };

  const result = await clickVisibleElement(page, 'button:has-text("Zum Postfach")', 1000);

  assert.equal(successfulClicks, 1);
  assert.equal(frameRead, 2);
  assert.deepEqual(result, {
    selector: 'button:has-text("Zum Postfach")',
    tag: 'button',
    text: 'Zum Postfach',
  });
});

test('selector diagnostics report visible alternatives from shadow DOM searches', async () => {
  const frame = {
    detached: false,
    url: () => 'https://www.gmx.net/logoutlounge?status=session',
    async evaluate(_callback, css, expectedText) {
      assert.equal(css, 'button');
      assert.equal(expectedText, 'zum postfach');

      return [{
        tag: 'button',
        className: 'account-avatar__button lux-button',
        text: 'Zur Startseite',
        matchesText: false,
        shadowPath: ['account-avatar', 'appa-account-avatar'],
      }];
    },
  };

  const diagnostics = await selectorDiagnostics(
    { frames: () => [frame] },
    'button:has-text("Zum Postfach")',
  );

  assert.equal(diagnostics.frameCount, 1);
  assert.equal(diagnostics.candidates[0].text, 'Zur Startseite');
  assert.deepEqual(diagnostics.candidates[0].shadowPath, ['account-avatar', 'appa-account-avatar']);
});

test('click failure explains which shadow DOM button is actually visible', async () => {
  const frame = {
    detached: false,
    url: () => 'https://www.gmx.net/logoutlounge?status=session',
    async evaluateHandle() {
      return {
        asElement: () => null,
        async dispose() {},
      };
    },
    async evaluate() {
      return [{
        tag: 'button',
        className: 'account-avatar__button lux-button',
        text: 'Zur Startseite',
        matchesText: false,
        shadowPath: ['account-avatar', 'appa-account-avatar'],
      }];
    },
  };
  const page = { frames: () => [frame] };

  const result = await runClickTask({
    page,
    input: {
      elementSelector: 'button:has-text("Zum Postfach")',
      timeoutMs: 20,
    },
    livePreviewEnabled: false,
  });

  assert.equal(result.ok, false);
  assert.match(result.statusMessage, /Zur Startseite/);
  assert.equal(result.selectorDiagnostics.candidates[0].shadowPath[0], 'account-avatar');
});
