'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const pressKey = require('./browser/press_key.cjs');

function pageStub({ pressError = null } = {}) {
  const pressedKeys = [];
  const page = {
    keyboard: {
      press: async (key) => {
        pressedKeys.push(key);

        if (pressError) {
          throw pressError;
        }
      },
    },
    url: () => 'https://example.test/formular',
  };

  return { page, pressedKeys };
}

test('browser.press_key verwendet den konfigurierten Wert und niemals den Karten-Key', async () => {
  const { page, pressedKeys } = pageStub();
  const result = await pressKey.run({
    page,
    input: {
      key: 'enter-button-klick',
      value: 'Enter',
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.status, 'success');
  assert.equal(result.key, 'Enter');
  assert.deepEqual(pressedKeys, ['Enter']);
});

test('browser.press_key canonicalisiert Enter- und Tab-Aliase', async () => {
  const { page, pressedKeys } = pageStub();

  const enterResult = await pressKey.run({ page, input: { keyboard_key: 'return' } });
  const tabResult = await pressKey.run({ page, input: { value: 'tabulator' } });

  assert.equal(enterResult.key, 'Enter');
  assert.equal(tabResult.key, 'Tab');
  assert.deepEqual(pressedKeys, ['Enter', 'Tab']);
});

test('browser.press_key weist unbekannte Werte vor Playwright eindeutig zurueck', async () => {
  const { page, pressedKeys } = pageStub();
  const result = await pressKey.run({
    page,
    input: {
      key: 'nur-der-karten-key',
      value: 'Zurueck',
    },
  });

  assert.equal(result.ok, false);
  assert.equal(result.status, 'failed');
  assert.equal(result.reason_code, 'keyboard_key_unsupported');
  assert.deepEqual(result.allowedKeys, ['Enter', 'Tab']);
  assert.deepEqual(pressedKeys, []);
});

test('browser.press_key faellt ohne Tastenkonfiguration nicht auf den Karten-Key zurueck', async () => {
  const { page, pressedKeys } = pageStub();
  const result = await pressKey.run({
    page,
    input: { key: 'enter' },
  });

  assert.equal(result.ok, false);
  assert.equal(result.reason_code, 'keyboard_key_unsupported');
  assert.match(result.statusMessage, /Taste auswaehlen/);
  assert.deepEqual(pressedKeys, []);
});

test('browser.press_key meldet Playwright-Fehler mit canonicalisiertem Key', async () => {
  const { page } = pageStub({ pressError: new Error('Keyboard ist blockiert') });
  const result = await pressKey.run({ page, input: { value: 'enter' } });

  assert.equal(result.ok, false);
  assert.equal(result.key, 'Enter');
  assert.equal(result.reason_code, 'keyboard_press_failed');
  assert.equal(result.error, 'Keyboard ist blockiert');
});
