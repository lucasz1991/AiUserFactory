'use strict';

const fs = require('fs');
const path = require('path');

const pageKeys = new WeakMap();
let nextPageKey = 1;

function normalizeText(value) {
  return String(value ?? '').trim();
}

function intervalMs(context = {}) {
  const preview = context.preview || context.livePreview || {};
  const configured = Number(
    preview.intervalMs
    || context.livePreviewIntervalMs
    || (Number(preview.intervalSeconds || context.livePreviewIntervalSeconds || 0) * 1000)
    || 3000,
  );

  return Math.max(1000, Math.min(60000, configured || 3000));
}

function enabled(context = {}) {
  const preview = context.preview || context.livePreview || {};

  return preview.enabled !== false
    && context.livePreviewEnabled !== false
    && context.previewEnabled !== false;
}

function pageKey(page, fallbackIndex = 0) {
  if (!page || (typeof page !== 'object' && typeof page !== 'function')) {
    return `window-${fallbackIndex + 1}`;
  }

  if (!pageKeys.has(page)) {
    pageKeys.set(page, `window-${nextPageKey++}`);
  }

  return pageKeys.get(page);
}

function configuredWindowKey(config = {}, fallback = '') {
  return normalizeText(
    config.key
    || config.name
    || config.windowName
    || config.browserWindow
    || config.browser_window
    || fallback,
  );
}

function withSuffix(filePath, suffix) {
  if (!filePath || suffix <= 1) {
    return filePath;
  }

  const ext = path.extname(filePath) || '.png';
  const base = filePath.slice(0, -ext.length);

  return `${base}-${suffix}${ext}`;
}

function relativeWithSuffix(relativePath, suffix) {
  if (!relativePath || suffix <= 1) {
    return relativePath;
  }

  const ext = path.extname(relativePath) || '.png';
  const base = relativePath.slice(0, -ext.length);

  return `${base}-${suffix}${ext}`;
}

function windowPath(context, index, windowConfig = {}) {
  const preview = context.preview || context.livePreview || {};
  const explicitPath = normalizeText(windowConfig.livePreviewPath || windowConfig.path || windowConfig.screenshotPath);
  const basePath = normalizeText(preview.livePreviewPath || context.livePreviewPath || context.screenshotPath);

  if (explicitPath) {
    return explicitPath;
  }

  if (basePath) {
    return withSuffix(basePath, index + 1);
  }

  const directory = normalizeText(preview.directory || preview.livePreviewDirectory || context.livePreviewDirectory);

  if (!directory) {
    return '';
  }

  return path.join(directory, `window-${index + 1}.png`);
}

function windowRelativePath(context, index, windowConfig = {}) {
  const preview = context.preview || context.livePreview || {};
  const explicitRelativePath = normalizeText(windowConfig.livePreviewRelativePath || windowConfig.relativePath || windowConfig.screenshotRelativePath);
  const baseRelativePath = normalizeText(preview.livePreviewRelativePath || context.livePreviewRelativePath || context.screenshotRelativePath);

  if (explicitRelativePath) {
    return explicitRelativePath;
  }

  if (baseRelativePath) {
    return relativeWithSuffix(baseRelativePath, index + 1);
  }

  return '';
}

function normalizeWindows(context = {}) {
  const preview = context.preview || context.livePreview || {};
  const candidates = []
    .concat(preview.windows || [])
    .concat(context.browserWindows || [])
    .concat(context.windows || [])
    .concat(context.pages || [])
    .concat(context.page ? [context.page] : []);

  const seen = new Set();

  return candidates
    .map((candidate, index) => {
      const page = candidate && typeof candidate === 'object' && candidate.page
        ? candidate.page
        : candidate;

      if (!page || typeof page.screenshot !== 'function') {
        return null;
      }

      if (typeof page.isClosed === 'function' && page.isClosed()) {
        return null;
      }

      const identityKey = pageKey(page, index);

      if (seen.has(identityKey)) {
        return null;
      }

      seen.add(identityKey);

      const config = candidate && typeof candidate === 'object' && candidate.page ? candidate : {};
      const key = configuredWindowKey(config, identityKey);
      const label = normalizeText(config.label || config.title || key || `Fenster ${seen.size}`);

      return {
        key,
        page,
        label,
        livePreviewPath: windowPath(context, seen.size - 1, config),
        livePreviewRelativePath: windowRelativePath(context, seen.size - 1, config),
      };
    })
    .filter(Boolean);
}

async function captureWindow(windowConfig, context = {}, force = false) {
  if (!enabled(context) || !windowConfig.livePreviewPath) {
    return null;
  }

  const now = Date.now();
  const last = Number(windowConfig.lastCapturedAtMs || 0);

  if (!force && last > 0 && now - last < intervalMs(context)) {
    return null;
  }

  fs.mkdirSync(path.dirname(windowConfig.livePreviewPath), { recursive: true });

  await windowConfig.page.screenshot({
    path: windowConfig.livePreviewPath,
    fullPage: false,
  });

  windowConfig.lastCapturedAtMs = now;

  const url = typeof windowConfig.page.url === 'function'
    ? String(windowConfig.page.url() || '')
    : '';
  const title = typeof windowConfig.page.title === 'function'
    ? await windowConfig.page.title().catch(() => '')
    : '';

  return {
    key: windowConfig.key,
    label: windowConfig.label,
    url,
    title,
    liveScreenshotPath: windowConfig.livePreviewPath,
    livePreviewPath: windowConfig.livePreviewPath,
    livePreviewRelativePath: windowConfig.livePreviewRelativePath || null,
    capturedAt: new Date(now).toISOString(),
  };
}

async function captureTaskPreview(context = {}, result = {}, force = true) {
  const windows = normalizeWindows(context);
  const captures = [];

  for (const windowConfig of windows) {
    try {
      const capture = await captureWindow(windowConfig, context, force);

      if (capture) {
        captures.push(capture);
      }
    } catch (error) {
      captures.push({
        key: windowConfig.key,
        label: windowConfig.label,
        error: error.message,
      });
    }
  }

  if (captures.length === 0) {
    return result;
  }

  return {
    ...result,
    browserWindows: captures,
    livePreviewIntervalMs: intervalMs(context),
    livePreviewIntervalSeconds: Math.ceil(intervalMs(context) / 1000),
  };
}

function startTaskPreview(context = {}) {
  if (!enabled(context)) {
    return;
  }

  if (context.__workflowPreviewTimer) {
    return;
  }

  const tick = () => {
    captureTaskPreview(context, {}, false).catch(() => {});
  };

  context.__workflowPreviewTimer = setInterval(tick, intervalMs(context));

  if (typeof context.__workflowPreviewTimer.unref === 'function') {
    context.__workflowPreviewTimer.unref();
  }

  tick();
}

function stopTaskPreview(context = {}) {
  if (context.__workflowPreviewTimer) {
    clearInterval(context.__workflowPreviewTimer);
    context.__workflowPreviewTimer = null;
  }
}

module.exports = {
  captureTaskPreview,
  startTaskPreview,
  stopTaskPreview,
};
