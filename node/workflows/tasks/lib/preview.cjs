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
const pageCaptures = new WeakMap();
const contextCaptures = new WeakMap();
let nextPageKey = 1;
const OPTIONAL_PREVIEW_WAIT_MS = 1500;

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
  const level = observabilityLevel(context);

  if (level === 'off') {
    return false;
  }

  if (
    typeof observability === 'object'
    && Object.prototype.hasOwnProperty.call(observability, 'capturesScreenshots')
  ) {
    return observability.capturesScreenshots === true
      && preview.enabled !== false
      && context.livePreviewEnabled !== false
      && context.previewEnabled !== false;
  }

  return OBSERVABILITY_LEVELS[level] >= OBSERVABILITY_LEVELS.preview
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
  const level = observabilityLevel(context);

  if (OBSERVABILITY_LEVELS[level] < OBSERVABILITY_LEVELS.debug) {
    return false;
  }

  if (
    typeof observability === 'object'
    && Object.prototype.hasOwnProperty.call(observability, 'capturesDom')
  ) {
    return observability.capturesDom === true;
  }

  return true;
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
        targetId: pageTargetId(page),
        url: typeof page.url === 'function' ? String(page.url() || '') : '',
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
        html: document.body ? document.body.outerHTML || '' : '',
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

function domTreeViewModel(domTree = {}) {
  const frames = Array.isArray(domTree.frames) ? domTree.frames : [];

  return {
    version: Number(domTree.version || 2),
    capturedAt: normalizeText(domTree.capturedAt),
    windowKey: normalizeText(domTree.windowKey),
    targetId: normalizeText(domTree.targetId),
    rootTag: normalizeText(domTree.rootTag) || 'body',
    viewport: domTree.viewport && typeof domTree.viewport === 'object'
      ? {
        width: Number(domTree.viewport.width || 0),
        height: Number(domTree.viewport.height || 0),
        deviceScaleFactor: Number(domTree.viewport.deviceScaleFactor || 1),
        scrollX: Number(domTree.viewport.scrollX || 0),
        scrollY: Number(domTree.viewport.scrollY || 0),
      }
      : null,
    frames: frames.map((frame) => ({
      frameRef: normalizeText(frame.frameRef),
      parentFrameRef: normalizeText(frame.parentFrameRef) || null,
      rootTag: normalizeText(frame.rootTag) || 'body',
      name: normalizeText(frame.name),
      url: normalizeText(frame.url),
      offsetX: Number(frame.offsetX || 0),
      offsetY: Number(frame.offsetY || 0),
      scaleX: Number(frame.scaleX || 1),
      scaleY: Number(frame.scaleY || 1),
      nodeCount: Number(frame.nodeCount || 0),
      truncated: frame.truncated && typeof frame.truncated === 'object'
        ? {
          nodes: frame.truncated.nodes === true,
          depth: frame.truncated.depth === true,
          bytes: frame.truncated.bytes === true,
        }
        : null,
      nodes: (Array.isArray(frame.nodes) ? frame.nodes : []).map((node) => {
        const attributes = node.attributes && typeof node.attributes === 'object' && !Array.isArray(node.attributes)
          ? Object.fromEntries(
            Object.entries(node.attributes)
              .map(([key, value]) => [normalizeText(key), normalizeText(value)])
              .filter(([key, value]) => key !== '' && value !== ''),
          )
          : {};
        const selectorCandidates = (Array.isArray(node.selectorCandidates) ? node.selectorCandidates : [])
          .map((candidate) => ({
            selector: normalizeText(candidate?.selector),
            kind: normalizeText(candidate?.kind) || 'css',
            unique: candidate?.unique === true,
            matchCount: Math.max(0, Number(candidate?.matchCount || 0)),
            score: Math.max(0, Math.min(100, Number(candidate?.score || 0))),
          }))
          .filter((candidate) => candidate.selector !== '');
        const optionalText = {
          id: normalizeText(node.id),
          className: Array.isArray(node.classes) ? node.classes.map((item) => normalizeText(item)).filter(Boolean).join(' ') : '',
          text: normalizeText(node.text),
          selector: normalizeText(node.selector),
          label: normalizeText(node.label),
        };
        const view = {
          nodeRef: normalizeText(node.nodeRef),
          parentRef: normalizeText(node.parentRef) || null,
          depth: Number(node.depth || 0),
          tag: normalizeText(node.tag),
          x: Number(node.rect?.x || 0),
          y: Number(node.rect?.y || 0),
          width: Number(node.rect?.width || 0),
          height: Number(node.rect?.height || 0),
          visible: node.visible === true,
        };

        if (Object.keys(attributes).length > 0) {
          view.attributes = attributes;
        }
        if (selectorCandidates.length > 0) {
          view.selectorCandidates = selectorCandidates;
        }
        for (const [key, value] of Object.entries(optionalText)) {
          if (value !== '') {
            view[key] = value;
          }
        }
        for (const [key, attribute] of Object.entries({
          role: 'role',
          type: 'type',
          name: 'name',
          ariaLabel: 'aria-label',
          placeholder: 'placeholder',
          title: 'title',
          href: 'href',
        })) {
          const value = normalizeText(node[key]);

          if (value !== '' && !attributes[attribute]) {
            view[key] = value;
          }
        }
        if (node.enabled === false) {
          view.enabled = false;
        }
        for (const flag of ['focused', 'editable', 'actionable', 'inShadowDom']) {
          if (node[flag] === true) {
            view[flag] = true;
          }
        }

        return view;
      }),
    })),
    nodeCount: Number(domTree.nodeCount || 0),
    truncated: domTree.truncated && typeof domTree.truncated === 'object'
      ? {
        nodes: domTree.truncated.nodes === true,
        depth: domTree.truncated.depth === true,
        bytes: domTree.truncated.bytes === true,
      }
      : null,
    byteSize: Number(domTree.byteSize || 0),
  };
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
  const viewModel = domTreeViewModel(domTree);

  return {
    debugDomAvailable: true,
    domTree: viewModel,
    domTreeAvailable: true,
    domTreeCapturedAt: domTree.capturedAt,
  };
}

async function captureWindowContents(windowConfig, context = {}, force = false) {
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

async function captureWindow(windowConfig, context = {}, force = false) {
  const previous = pageCaptures.get(windowConfig.page);
  const pending = Promise.resolve(previous).catch(() => {}).then(
    () => captureWindowContents(windowConfig, context, force),
  );

  // A page may be shared by two task contexts. Serialize the complete capture,
  // including title/DOM metadata, so one caller cannot publish another's shot.
  pageCaptures.set(windowConfig.page, pending);

  try {
    return await pending;
  } finally {
    if (pageCaptures.get(windowConfig.page) === pending) {
      pageCaptures.delete(windowConfig.page);
    }
  }
}

async function capturePreviewWindows(context, force) {
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

  return captures;
}

function startCaptureBatch(context, state, force) {
  const pending = Promise.resolve().then(() => capturePreviewWindows(context, force));
  state.inFlight = pending;

  const release = () => {
    if (state.inFlight === pending) {
      state.inFlight = null;
    }

    if (!state.inFlight && !state.forcedFollowup) {
      state.budgetExpired = false;
    }
  };

  pending.then((captures) => {
    state.readyCapture = { pending, captures };
    release();
  }, release);

  return pending;
}

function currentPreviewCaptures(context, captures) {
  const windows = new Map(normalizeWindows(context).map((windowConfig) => [windowConfig.key, windowConfig]));

  return captures.filter((capture) => {
    const windowConfig = windows.get(capture.key);

    // A delayed shot must never restore a closed/replaced physical window, nor
    // label a previous navigation's pixels as the current browser destination.
    return windowConfig
      && capture.targetId === windowConfig.targetId
      && (capture.stale === true || !capture.url || capture.url === windowConfig.url);
  });
}

function previewResult(context, result, captures) {
  const currentCaptures = currentPreviewCaptures(context, captures);

  return currentCaptures.length === 0 ? result : {
    ...result,
    browserWindows: currentCaptures,
    livePreviewIntervalMs: intervalMs(context),
    livePreviewIntervalSeconds: Math.ceil(intervalMs(context) / 1000),
  };
}

function waitForOptionalPreview(pending, state) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      // This is only a caller budget. Do not release the real context/page
      // capture guards: Puppeteer may still be using its screenshot mutex.
      state.budgetExpired = true;
      resolve(null);
    }, OPTIONAL_PREVIEW_WAIT_MS);

    pending.then((captures) => {
      clearTimeout(timer);
      resolve(captures);
    }, (error) => {
      clearTimeout(timer);
      reject(error);
    });
  });
}

async function captureTaskPreview(context = {}, result = {}, force = true) {
  if (!enabled(context)) {
    return result;
  }

  let state = contextCaptures.get(context);

  if (!state) {
    state = { inFlight: null, forcedFollowup: null, readyCapture: null, budgetExpired: false };
    contextCaptures.set(context, state);
  }

  const optionalPreview = observabilityLevel(context) === 'preview'
    && context.__workflowRunnerPreviewActive === true;

  // A previously budget-limited physical capture can be published by the next
  // runner tick once it really completes, without starting another screenshot.
  if (optionalPreview && !force && state.readyCapture) {
    const { captures } = state.readyCapture;
    state.readyCapture = null;

    return previewResult(context, result, captures);
  }

  if (optionalPreview && state.budgetExpired && (state.inFlight || state.forcedFollowup)) {
    return result;
  }

  let pending;

  if (state.forcedFollowup || state.inFlight) {
    // Background ticks must not accumulate behind a slow Puppeteer screenshot.
    // Keep the last published status unchanged until a real new capture exists.
    if (!force) {
      return result;
    }

    if (!state.forcedFollowup) {
      const followup = state.inFlight.catch(() => {}).then(
        () => optionalPreview && context.__workflowRunnerPreviewActive !== true
          ? []
          : startCaptureBatch(context, state, true),
      );
      state.forcedFollowup = followup;
      const release = () => {
        if (state.forcedFollowup === followup) {
          state.forcedFollowup = null;
        }

        if (!state.inFlight && !state.forcedFollowup) {
          state.budgetExpired = false;
        }
      };

      followup.then(release, release);
    }

    pending = state.forcedFollowup;
  } else {
    pending = startCaptureBatch(context, state, force);
  }

  const captures = optionalPreview
    ? await waitForOptionalPreview(pending, state)
    : await pending;

  if (!captures) {
    return result;
  }

  if (state.readyCapture?.pending === pending) {
    state.readyCapture = null;
  }

  return previewResult(context, result, captures);
}

function startTaskPreview(context = {}) {
  if (!enabled(context)) {
    return;
  }

  if (context.__workflowRunnerPreviewActive === true || context.__workflowPreviewTimer) {
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
