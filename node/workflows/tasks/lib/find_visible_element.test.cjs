'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
  clickVisibleElement,
  findVisibleElement,
  framesForPage,
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

test('button:has-text also finds a link explicitly styled as a button', async () => {
  const selectors = [];
  const linkHandle = elementHandle(async () => {});
  const frame = {
    detached: false,
    async evaluateHandle(_callback, css) {
      selectors.push(css);
      const handle = css === 'button' ? null : linkHandle;

      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
  };
  let synchronized = 0;
  const page = {
    frames: () => [frame],
    async evaluate() {
      synchronized += 1;
    },
  };

  const found = await findVisibleElement(page, 'button:has-text("Zur Startseite")', 100);

  assert.equal(found, linkHandle);
  assert.equal(synchronized, 1);
  assert.deepEqual(selectors, [
    'button',
    'button,a[data-component="button"],[role="button"],input[type="button"],input[type="submit"]',
  ]);
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
