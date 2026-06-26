'use strict';

const path = require('path');
const {
  ensureDirectory,
  normalizeText,
  writeJsonFile,
} = require('./runtime-utils.cjs');

const DEFAULT_MIN_INTERVAL_MS = 2500;

function createLivePreviewController({
  getCurrentStatusPayload,
  setCurrentStatusPayload,
  minIntervalMs = DEFAULT_MIN_INTERVAL_MS,
} = {}) {
  const lastLivePreviewByPath = new Map();
  const activePreviewWindows = new Map();
  let livePreviewTimer = null;
  let livePreviewTickRunning = false;

  function livePreviewIntervalMs(runtimeConfig = {}) {
    const configuredMs = Number(runtimeConfig.livePreviewIntervalMs);
    const configuredSeconds = Number(runtimeConfig.livePreviewIntervalSeconds);

    if (Number.isFinite(configuredMs) && configuredMs > 0) {
      return Math.max(500, configuredMs);
    }

    if (Number.isFinite(configuredSeconds) && configuredSeconds > 0) {
      return Math.max(500, configuredSeconds * 1000);
    }

    return minIntervalMs;
  }

  async function captureScreenshotToPath(page, runtimeConfig = {}, livePreviewPath = '', force = false) {
    if (!page || !livePreviewPath || runtimeConfig.livePreviewEnabled === false) {
      return {};
    }

    const now = Date.now();
    const lastLivePreviewAt = lastLivePreviewByPath.get(livePreviewPath) || 0;

    if (!force && now - lastLivePreviewAt < livePreviewIntervalMs(runtimeConfig)) {
      return {};
    }

    try {
      await page.bringToFront().catch(() => {});
      ensureDirectory(path.dirname(livePreviewPath));
      await page.screenshot({
        path: livePreviewPath,
        fullPage: false,
        type: 'png',
      });

      lastLivePreviewByPath.set(livePreviewPath, now);

      return {
        liveScreenshotPath: livePreviewPath,
        liveScreenshotAt: new Date(now).toISOString(),
      };
    } catch (error) {
      return {
        liveScreenshotError: normalizeText(error?.message || String(error)),
      };
    }
  }

  async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
    return captureScreenshotToPath(page, runtimeConfig, normalizeText(runtimeConfig.livePreviewPath), force);
  }

  async function captureWebmailLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
    return captureScreenshotToPath(page, runtimeConfig, normalizeText(runtimeConfig.webmailLivePreviewPath), force);
  }

  function publicRelativePathForPreview(runtimeConfig = {}, kind = 'registration', index = 0) {
    const registrationPath = normalizeText(runtimeConfig.livePreviewRelativePath);
    const webmailPath = normalizeText(runtimeConfig.webmailLivePreviewRelativePath);

    if (kind === 'registration' && registrationPath) {
      return registrationPath;
    }

    if (kind === 'webmail' && webmailPath) {
      return webmailPath;
    }

    if (!registrationPath) {
      return '';
    }

    const ext = path.extname(registrationPath) || '.png';
    const base = registrationPath.slice(0, -ext.length);

    return `${base}-window-${index + 1}${ext}`;
  }

  function absolutePathForPreview(runtimeConfig = {}, kind = 'registration', index = 0) {
    const registrationPath = normalizeText(runtimeConfig.livePreviewPath);
    const webmailPath = normalizeText(runtimeConfig.webmailLivePreviewPath);

    if (kind === 'registration' && registrationPath) {
      return registrationPath;
    }

    if (kind === 'webmail' && webmailPath) {
      return webmailPath;
    }

    if (!registrationPath) {
      return '';
    }

    const ext = path.extname(registrationPath) || '.png';
    const base = registrationPath.slice(0, -ext.length);

    return `${base}-window-${index + 1}${ext}`;
  }

  function registerPreviewWindow(page, runtimeConfig = {}, label = 'Browserfenster', kind = 'registration') {
    if (!page || typeof page.screenshot !== 'function') {
      return;
    }

    const key = `${kind}:${activePreviewWindows.size + 1}`;

    activePreviewWindows.set(key, {
      key,
      page,
      label,
      kind,
      livePreviewPath: absolutePathForPreview(runtimeConfig, kind, activePreviewWindows.size),
      livePreviewRelativePath: publicRelativePathForPreview(runtimeConfig, kind, activePreviewWindows.size),
    });

    startLivePreviewTimer(runtimeConfig);
  }

  async function captureRegisteredPreviewWindows(runtimeConfig = {}, force = false) {
    const browserWindows = [];

    for (const [key, windowConfig] of Array.from(activePreviewWindows.entries())) {
      if (!windowConfig.page || (typeof windowConfig.page.isClosed === 'function' && windowConfig.page.isClosed())) {
        activePreviewWindows.delete(key);
        continue;
      }

      const screenshot = await captureScreenshotToPath(windowConfig.page, runtimeConfig, windowConfig.livePreviewPath, force);

      browserWindows.push({
        key,
        label: windowConfig.label,
        kind: windowConfig.kind,
        livePreviewPath: windowConfig.livePreviewPath,
        livePreviewRelativePath: windowConfig.livePreviewRelativePath,
        liveScreenshotPath: screenshot.liveScreenshotPath || windowConfig.livePreviewPath,
        liveScreenshotAt: screenshot.liveScreenshotAt || null,
        error: screenshot.liveScreenshotError || null,
      });
    }

    return browserWindows;
  }

  function startLivePreviewTimer(runtimeConfig = {}) {
    const statusPath = normalizeText(runtimeConfig.statusPath);

    if (!statusPath || livePreviewTimer || runtimeConfig.livePreviewEnabled === false) {
      return;
    }

    const tick = async () => {
      const currentStatusPayload = typeof getCurrentStatusPayload === 'function'
        ? getCurrentStatusPayload()
        : null;

      if (livePreviewTickRunning || !currentStatusPayload) {
        return;
      }

      livePreviewTickRunning = true;

      try {
        const browserWindows = await captureRegisteredPreviewWindows(runtimeConfig, true);
        const heartbeatAt = new Date().toISOString();
        const nextStatusPayload = {
          ...currentStatusPayload,
          at: heartbeatAt,
          heartbeatAt,
          browserWindows,
          livePreviewIntervalSeconds: Math.max(1, Math.round(livePreviewIntervalMs(runtimeConfig) / 1000)),
          livePreviewPollIntervalSeconds: Math.max(1, Math.round(livePreviewIntervalMs(runtimeConfig) / 1000)),
        };

        if (typeof setCurrentStatusPayload === 'function') {
          setCurrentStatusPayload(nextStatusPayload);
        }

        writeJsonFile(statusPath, nextStatusPayload);
      } finally {
        livePreviewTickRunning = false;
      }
    };

    livePreviewTimer = setInterval(tick, livePreviewIntervalMs(runtimeConfig));

    if (typeof livePreviewTimer.unref === 'function') {
      livePreviewTimer.unref();
    }

    tick();
  }

  function stopLivePreviewTimer() {
    if (livePreviewTimer) {
      clearInterval(livePreviewTimer);
      livePreviewTimer = null;
    }
  }

  return {
    livePreviewIntervalMs,
    captureScreenshotToPath,
    captureLivePreviewScreenshot,
    captureWebmailLivePreviewScreenshot,
    registerPreviewWindow,
    captureRegisteredPreviewWindows,
    startLivePreviewTimer,
    stopLivePreviewTimer,
  };
}

module.exports = {
  createLivePreviewController,
};
