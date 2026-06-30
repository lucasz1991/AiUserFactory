'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const {
  findFirstVisibleElement,
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

test('multiple selectors use the first candidate that currently has a match', async () => {
  const attempted = [];
  const linkHandle = elementHandle(async () => {});
  const frame = {
    detached: false,
    async evaluateHandle(_callback, css, _descendantCss, text) {
      attempted.push({ css, text });
      const handle = css === 'a' && text === 'Login' ? linkHandle : null;

      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
  };
  const found = await findFirstVisibleElement(
    { frames: () => [frame] },
    'button:has-text("Missing"), a:has-text("Login"), Zurück zum Login',
    100,
  );

  assert.equal(found.handle, linkHandle);
  assert.equal(found.matchedBy, 'selector');
  assert.equal(found.candidate.value, 'a:has-text("Login")');
  assert.deepEqual(attempted, [
    { css: 'button', text: 'Missing' },
    {
      css: 'button,a[data-component="button"],[role="button"],input[type="button"],input[type="submit"]',
      text: 'Missing',
    },
    { css: 'a', text: 'Login' },
  ]);
});

test('plain comma-separated values are searched as text alternatives', async () => {
  const attemptedTexts = [];
  const backHandle = elementHandle(async () => {});
  const frame = {
    detached: false,
    async evaluateHandle(_callback, _selector, text) {
      attemptedTexts.push(text);
      const handle = text === 'zurück zum login' ? backHandle : null;

      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
  };
  const found = await findFirstVisibleElement(
    { frames: () => [frame] },
    'login, Zurück zum Login',
    100,
  );

  assert.equal(found.handle, backHandle);
  assert.equal(found.matchedBy, 'text');
  assert.equal(found.candidate.value, 'Zurück zum Login');
  assert.deepEqual(attemptedTexts, ['login', 'zurück zum login']);
});

test('detached frames are ignored before searching', () => {
  const detachedFrame = { detached: true };
  const activeFrame = { detached: false };

  assert.deepEqual(framesForPage({ frames: () => [detachedFrame, activeFrame] }), [activeFrame]);
});

test('nested child frames are collected even when page.frames only returns the main frame', () => {
  const nestedFrame = { detached: false, childFrames: () => [] };
  const childFrame = { detached: false, childFrames: () => [nestedFrame] };
  const mainFrame = { detached: false, childFrames: () => [childFrame] };
  const page = {
    mainFrame: () => mainFrame,
    frames: () => [mainFrame],
  };

  assert.deepEqual(framesForPage(page), [mainFrame, childFrame, nestedFrame]);
});

test('a CSS selector searches child frames before a main-frame wait can consume the timeout', async () => {
  const handle = elementHandle(async () => {});
  const emptyHandle = {
    asElement: () => null,
    async dispose() {},
  };
  const mainFrame = {
    detached: false,
    async evaluateHandle() {
      return emptyHandle;
    },
    async waitForSelector(_selector, options = {}) {
      await new Promise((resolve) => setTimeout(resolve, Number(options.timeout || 1)));

      return null;
    },
  };
  const mailFrame = {
    detached: false,
    async evaluateHandle() {
      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
  };
  const page = {
    mainFrame: () => mainFrame,
    frames: () => [mainFrame, mailFrame],
  };

  const found = await findVisibleElement(page, 'webmailer-mail-list#list', 40);

  assert.equal(found, handle);
});

test('a CSS selector is found inside a recursively discovered nested frame', async () => {
  const handle = elementHandle(async () => {});
  const emptyFrame = (children = []) => ({
    detached: false,
    childFrames: () => children,
    async evaluateHandle() {
      return {
        asElement: () => null,
        async dispose() {},
      };
    },
  });
  const nestedFrame = {
    detached: false,
    childFrames: () => [],
    async evaluateHandle() {
      return {
        asElement: () => handle,
        async dispose() {},
      };
    },
  };
  const childFrame = emptyFrame([nestedFrame]);
  const mainFrame = emptyFrame([childFrame]);
  const page = {
    mainFrame: () => mainFrame,
    frames: () => [mainFrame],
  };

  const found = await findVisibleElement(page, 'webmailer-mail-list#list', 100);

  assert.equal(found, handle);
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
