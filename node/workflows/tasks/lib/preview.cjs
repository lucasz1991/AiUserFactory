'use strict';

const fs = require('fs');
const path = require('path');
const {
  captureDomTree,
  writeJsonAtomic,
} = require('./dom_tree.cjs');
const {
  cursorForWindow,
} = require('./cursor.cjs');

const pageKeys = new WeakMap();
let nextPageKey = 1;

const OBSERVABILITY_LEVELS = Object.freeze({
  off: 0,
  preview: 1,
  debug: 2,
  copilot: 3,
});

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
  const observability = context.observability || {};

  if (
    typeof observability === 'object'
    && Object.prototype.hasOwnProperty.call(observability, 'capturesScreenshots')
  ) {
    return observability.capturesScreenshots === true
      && preview.enabled !== false
      && context.livePreviewEnabled !== false
      && context.previewEnabled !== false;
  }

  return observabilityLevel(context) !== 'off'
    && preview.enabled !== false
    && context.livePreviewEnabled !== false
    && context.previewEnabled !== false;
}

function normalizeObservabilityLevel(value) {
  const normalized = normalizeText(value).toLowerCase();

  return Object.prototype.hasOwnProperty.call(OBSERVABILITY_LEVELS, normalized)
    ? normalized
    : '';
}

function observabilityLevel(context = {}) {
  const preview = context.preview || context.livePreview || {};
  const devDebug = context.devDebug || context.dev_debug || {};
  const observability = context.observability || {};
  const explicitLevel = normalizeObservabilityLevel(
    typeof observability === 'string' ? observability : observability.level,
  );

  // Feature R6: the PHP policy is authoritative. In particular, explicit
  // `off` must not be elevated again by legacy dev/live-preview flags.
  if (explicitLevel) {
    return explicitLevel;
  }

  const candidates = [
    context.observabilityLevel,
    context.observability_level,
    preview.observability,
    preview.observabilityLevel,
    preview.observability_level,
    devDebug.observability,
    devDebug.level,
  ];
  let effectiveLevel = 'off';

  for (const candidate of candidates) {
    const level = normalizeObservabilityLevel(candidate);

    if (level && OBSERVABILITY_LEVELS[level] > OBSERVABILITY_LEVELS[effectiveLevel]) {
      effectiveLevel = level;
    }
  }

  if (devDebug.copilotObservation === true || devDebug.copilot_observation === true) {
    return 'copilot';
  }

  if (
    preview.captureDom === true
    || preview.capture_dom === true
    || devDebug.captureDom === true
    || devDebug.capture_dom === true
    || devDebug.enabled === true
    || devDebug.dev_mode === true
  ) {
    effectiveLevel = OBSERVABILITY_LEVELS.debug > OBSERVABILITY_LEVELS[effectiveLevel]
      ? 'debug'
      : effectiveLevel;
  }

  return effectiveLevel;
}

function debugDomEnabled(context = {}) {
  const observability = context.observability || {};

  if (
    typeof observability === 'object'
    && Object.prototype.hasOwnProperty.call(observability, 'capturesDom')
  ) {
    return observability.capturesDom === true;
  }

  return OBSERVABILITY_LEVELS[observabilityLevel(context)] >= OBSERVABILITY_LEVELS.debug;
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

function pageTargetId(page) {
  if (!page || typeof page.target !== 'function') {
    return '';
  }

  try {
    return String(page.target()?._targetId || '');
  } catch {
    return '';
  }
}

function windowIdentityKey(page, fallbackIndex = 0) {
  const targetId = pageTargetId(page);

  return targetId !== ''
    ? `target:${targetId}`
    : pageKey(page, fallbackIndex);
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

function debugDomPathFor(windowConfig = {}, context = {}) {
  const preview = context.preview || context.livePreview || {};
  const privateRunDirectory = normalizeText(
    preview.debugDomDirectory
    || preview.debug_dom_directory
    || context.debugDomDirectory
    || context.debug_dom_directory
    || context.runDirectory
    || context.workflowTaskRunDirectory,
  );

  if (!privateRunDirectory) {
    return '';
  }

  const livePreviewFilename = path.basename(normalizeText(windowConfig.livePreviewPath) || 'live.png');
  const ext = path.extname(livePreviewFilename) || '.png';
  const base = livePreviewFilename.slice(0, -ext.length) || 'live';

  return path.join(privateRunDirectory, `${base}-dom.json`);
}

function domTreePathFor(windowConfig = {}, context = {}) {
  const debugDomPath = debugDomPathFor(windowConfig, context);

  if (!debugDomPath) {
    return '';
  }

  return debugDomPath.replace(/-dom\.json$/i, '-dom-tree.json');
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

      const identityKey = windowIdentityKey(page, index);

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

async function frameDomSnapshot(frame) {
  const frameUrl = typeof frame.url === 'function' ? String(frame.url() || '') : '';
  const frameName = typeof frame.name === 'function' ? String(frame.name() || '') : '';

  try {
    return await frame.evaluate(() => {
      const inputSelector = 'input, textarea, select, button, [contenteditable="true"]';
      const fieldValue = (element) => {
        if (element instanceof HTMLInputElement && ['password', 'hidden'].includes(String(element.type || '').toLowerCase())) {
          return '';
        }

        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement) {
          return element.value || '';
        }

        return element.textContent || '';
      };
      const safeDebugValue = (value) => {
        if (value === undefined) {
          return null;
        }

        try {
          return JSON.parse(JSON.stringify(value));
        } catch {
          return String(value);
        }
      };
      const visible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none';
      };
      return {
        url: window.location.href,
        title: document.title || '',
        text: document.body ? document.body.innerText || '' : '',
        html: document.documentElement ? document.documentElement.outerHTML || '' : '',
        workflowDebug: safeDebugValue(window.__workflowDebug),
        workflowMailListScanDebug: safeDebugValue(window.__workflowMailListScanDebug),
        fields: Array.from(document.querySelectorAll(inputSelector)).map((element, index) => ({
          index,
          tag: String(element.tagName || '').toLowerCase(),
          type: element.getAttribute('type') || '',
          id: element.id || '',
          name: element.getAttribute('name') || '',
          autocomplete: element.getAttribute('autocomplete') || '',
          placeholder: element.getAttribute('placeholder') || '',
          ariaLabel: element.getAttribute('aria-label') || '',
          disabled: element.disabled === true || element.getAttribute('aria-disabled') === 'true',
          readOnly: element.readOnly === true,
          visible: visible(element),
          value: fieldValue(element),
        })),
      };
    });
  } catch (error) {
    return {
      url: frameUrl,
      name: frameName,
      error: error.message,
    };
  }
}

async function captureDebugDom(windowConfig, context = {}, capture = {}) {
  const debugDomPath = debugDomPathFor(windowConfig, context);
  const domTreePath = domTreePathFor(windowConfig, context);

  if (!debugDomPath || !domTreePath || !windowConfig.page || typeof windowConfig.page.frames !== 'function') {
    return {};
  }

  const [frames, domTree] = await Promise.all([
    Promise.all(
      windowConfig.page.frames().map(async (frame, index) => ({
        index,
        name: typeof frame.name === 'function' ? String(frame.name() || '') : '',
        ...(await frameDomSnapshot(frame)),
      })),
    ),
    captureDomTree(windowConfig.page, {
      windowKey: windowConfig.key,
      targetId: capture.targetId || '',
    }),
  ]);
  const payload = {
    capturedAt: new Date().toISOString(),
    key: windowConfig.key,
    label: windowConfig.label,
    url: capture.url || (typeof windowConfig.page.url === 'function' ? String(windowConfig.page.url() || '') : ''),
    title: capture.title || '',
    targetId: capture.targetId || '',
    frames,
  };

  fs.mkdirSync(path.dirname(debugDomPath), { recursive: true });
  writeJsonAtomic(debugDomPath, payload);
  writeJsonAtomic(domTreePath, domTree);

  return {
    debugDomAvailable: true,
    domTree,
    domTreeAvailable: true,
    domTreeCapturedAt: domTree.capturedAt,
  };
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
  const targetId = typeof windowConfig.page.target === 'function'
    ? String(windowConfig.page.target()?._targetId || '')
    : '';
  const debugDom = debugDomEnabled(context)
    ? await captureDebugDom(windowConfig, context, { url, title, targetId }).catch((error) => ({
      debugDomError: error.message,
    }))
    : {};
  Object.assign(windowConfig, {
    url,
    title,
    targetId,
    livePreviewRelativePath: windowConfig.livePreviewRelativePath || null,
    ...debugDom,
    capturedAt: new Date(now).toISOString(),
  });
  const cursor = cursorForWindow(context, windowConfig.key);

  return {
    key: windowConfig.key,
    label: windowConfig.label,
    url,
    title,
    targetId,
    livePreviewRelativePath: windowConfig.livePreviewRelativePath || null,
    ...debugDom,
    ...(cursor ? { cursor } : {}),
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
        url: windowConfig.url || null,
        title: windowConfig.title || '',
        targetId: windowConfig.targetId || '',
        livePreviewRelativePath: windowConfig.livePreviewRelativePath || null,
        debugDomRelativePath: windowConfig.debugDomRelativePath || null,
        debugDomAvailable: windowConfig.debugDomAvailable === true,
        domTreeAvailable: windowConfig.domTreeAvailable === true,
        ...(windowConfig.domTree ? { domTree: windowConfig.domTree } : {}),
        ...(cursorForWindow(context, windowConfig.key)
          ? { cursor: cursorForWindow(context, windowConfig.key) }
          : {}),
        capturedAt: windowConfig.capturedAt || null,
        stale: true,
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
