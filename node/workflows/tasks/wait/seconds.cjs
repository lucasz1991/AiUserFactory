'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function numericValue(...values) {
  for (const value of values) {
    const number = Number(value);

    if (Number.isFinite(number) && number >= 0) {
      return number;
    }
  }

  return 0;
}

async function run(context = {}) {
  const input = context.input || {};
  const seconds = Math.min(3600, numericValue(
    input.seconds,
    input.waitSeconds,
    input.wait_seconds,
    input.value,
  ));
  const milliseconds = Math.round(seconds * 1000);

  if (milliseconds > 0) {
    startTaskPreview(context);
    await wait(milliseconds);
  }

  context.lastWaitSeconds = seconds;
  context.last_wait_seconds = seconds;

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: seconds > 0 ? `Wartezeit abgeschlossen: ${seconds}s.` : 'Warte-Task ohne Verzoegerung abgeschlossen.',
    seconds,
  });
}

module.exports = { key: 'wait.seconds', run };
