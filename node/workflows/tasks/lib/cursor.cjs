'use strict';

const pagePositions = new WeakMap();

const LEVEL_RANK = Object.freeze({
  off: 0,
  preview: 1,
  debug: 2,
  copilot: 3,
});

function normalizeLevel(value) {
  const level = String(value || '').trim().toLowerCase();

  return Object.prototype.hasOwnProperty.call(LEVEL_RANK, level) ? level : '';
}

function observabilityLevel(context = {}) {
  const observability = context.observability || {};
  const explicit = normalizeLevel(
    typeof observability === 'string'
      ? observability
      : observability.level,
  );

  if (explicit) {
    return explicit;
  }

  const legacy = normalizeLevel(
    context.observabilityLevel
    || context.observability_level
    || context.preview?.observability
    || context.devDebug?.observability,
  );

  if (legacy) {
    return legacy;
  }

  if (context.devDebug?.copilotObservation === true) {
    return 'copilot';
  }

  if (context.devDebug?.enabled === true || context.devDebug?.dev_mode === true) {
    return 'debug';
  }

  return context.livePreviewEnabled === false ? 'off' : 'preview';
}

function telemetryEnabled(context = {}) {
  const observability = context.observability || {};
  const level = observabilityLevel(context);

  if (LEVEL_RANK[level] < LEVEL_RANK.preview) {
    return false;
  }

  if (
    typeof observability === 'object'
    && Object.prototype.hasOwnProperty.call(observability, 'showsCursor')
  ) {
    return observability.showsCursor === true;
  }

  return true;
}

function normalizeWindowName(context = {}, override = '') {
  return String(
    override
    || context.activeBrowserWindow
    || context.browserWindow
    || 'main',
  ).trim() || 'main';
}

function normalizeBox(box = {}) {
  const number = (value) => {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : 0;
  };

  return {
    x: number(box.x),
    y: number(box.y),
    width: Math.max(0, number(box.width)),
    height: Math.max(0, number(box.height)),
  };
}

async function viewportFor(page) {
  if (page && typeof page.viewport === 'function') {
    try {
      const viewport = page.viewport();

      if (viewport && Number(viewport.width) > 0 && Number(viewport.height) > 0) {
        return {
          width: Number(viewport.width),
          height: Number(viewport.height),
          deviceScaleFactor: Number(viewport.deviceScaleFactor || 1),
        };
      }
    } catch {
      // Continue with an evaluated viewport.
    }
  }

  if (page && typeof page.evaluate === 'function') {
    try {
      const viewport = await page.evaluate(() => ({
        width: window.innerWidth,
        height: window.innerHeight,
        deviceScaleFactor: window.devicePixelRatio || 1,
      }));

      if (viewport && Number(viewport.width) > 0 && Number(viewport.height) > 0) {
        return {
          width: Number(viewport.width),
          height: Number(viewport.height),
          deviceScaleFactor: Number(viewport.deviceScaleFactor || 1),
        };
      }
    } catch {
      // A navigation may briefly destroy the execution context.
    }
  }

  return {
    width: 1366,
    height: 900,
    deviceScaleFactor: 1,
  };
}

function targetForBox(box, viewport) {
  const normalized = normalizeBox(box);
  const rawX = normalized.x + (normalized.width / 2);
  const rawY = normalized.y + (normalized.height / 2);
  const maxX = Math.max(0, Number(viewport.width || 0) - 1);
  const maxY = Math.max(0, Number(viewport.height || 0) - 1);

  return {
    x: Math.max(0, Math.min(maxX, rawX)),
    y: Math.max(0, Math.min(maxY, rawY)),
  };
}

function movementSteps(from, target, configured = null) {
  const requested = Number(configured);

  if (Number.isFinite(requested) && requested > 0) {
    return Math.max(1, Math.min(40, Math.round(requested)));
  }

  const distance = Math.hypot(target.x - from.x, target.y - from.y);

  return Math.max(4, Math.min(24, Math.ceil(distance / 70)));
}

function cursorStore(context = {}) {
  if (!context || typeof context !== 'object') {
    return {};
  }

  if (!context.__workflowCursorByWindow || typeof context.__workflowCursorByWindow !== 'object') {
    context.__workflowCursorByWindow = {};
  }

  return context.__workflowCursorByWindow;
}

function rememberCursor(context, cursor) {
  if (!cursor || !telemetryEnabled(context)) {
    return null;
  }

  context.__workflowCursorSequence = Number(context.__workflowCursorSequence || 0) + 1;
  const stored = {
    ...cursor,
    sequence: context.__workflowCursorSequence,
  };
  cursorStore(context)[stored.window] = stored;
  context.__workflowCursor = stored;

  return stored;
}

function cursorForWindow(context = {}, windowName = '') {
  if (!telemetryEnabled(context)) {
    return null;
  }

  const key = normalizeWindowName(context, windowName);
  const cursor = cursorStore(context)[key];

  return cursor && typeof cursor === 'object' ? { ...cursor } : null;
}

async function moveCursorTo(page, box, options = {}) {
  if (!page?.mouse || typeof page.mouse.move !== 'function') {
    return {
      handled: false,
      cursor: null,
    };
  }

  const context = options.context || {};
  const viewport = await viewportFor(page);
  const normalizedBox = normalizeBox(box);
  const centerX = normalizedBox.x + (normalizedBox.width / 2);
  const centerY = normalizedBox.y + (normalizedBox.height / 2);

  if (
    centerX < 0
    || centerY < 0
    || centerX >= Number(viewport.width || 0)
    || centerY >= Number(viewport.height || 0)
  ) {
    return {
      handled: false,
      cursor: null,
    };
  }

  const target = targetForBox(box, viewport);
  const from = pagePositions.get(page) || { x: 1, y: 1 };
  const steps = movementSteps(from, target, options.steps);
  const startedAt = new Date().toISOString();

  await page.mouse.move(target.x, target.y, { steps });
  pagePositions.set(page, target);

  const cursor = rememberCursor(context, {
    version: 1,
    window: normalizeWindowName(context, options.window),
    action: String(options.action || 'move'),
    fromX: from.x,
    fromY: from.y,
    toX: target.x,
    toY: target.y,
    steps,
    startedAt,
    arrivedAt: new Date().toISOString(),
    clicked: false,
    viewport,
  });

  return {
    handled: true,
    cursor,
  };
}

async function clickAt(page, box, options = {}) {
  if (
    !page?.mouse
    || typeof page.mouse.down !== 'function'
    || typeof page.mouse.up !== 'function'
  ) {
    return {
      handled: false,
      cursor: null,
    };
  }

  const moved = await moveCursorTo(page, box, {
    ...options,
    action: options.action || 'click',
  });

  if (!moved.handled) {
    return moved;
  }

  let mouseDown = false;

  try {
    await page.mouse.down({ button: 'left' });
    mouseDown = true;
  } finally {
    if (mouseDown) {
      await page.mouse.up({ button: 'left' });
    }
  }

  if (!moved.cursor) {
    return moved;
  }

  const clickedCursor = rememberCursor(options.context || {}, {
    ...moved.cursor,
    clicked: true,
    clickedAt: new Date().toISOString(),
  });

  return {
    handled: true,
    cursor: clickedCursor,
  };
}

async function boxForHandle(handle) {
  if (!handle) {
    return null;
  }

  if (typeof handle.evaluate === 'function') {
    await handle.evaluate((element) => {
      element?.scrollIntoView?.({
        block: 'center',
        inline: 'center',
        behavior: 'auto',
      });
    }).catch(() => {});
  }

  if (typeof handle.boundingBox !== 'function') {
    return null;
  }

  const box = await handle.boundingBox().catch(() => null);

  if (!box || Number(box.width) <= 0 || Number(box.height) <= 0) {
    return null;
  }

  return normalizeBox(box);
}

async function moveCursorToHandle(page, handle, options = {}) {
  const box = await boxForHandle(handle);

  return box
    ? moveCursorTo(page, box, options)
    : { handled: false, cursor: null };
}

async function clickHandle(page, handle, options = {}) {
  const box = await boxForHandle(handle);

  return box
    ? clickAt(page, box, options)
    : { handled: false, cursor: null };
}

function setPagePosition(page, position = {}) {
  if (!page || (typeof page !== 'object' && typeof page !== 'function')) {
    return;
  }

  const normalized = normalizeBox(position);
  pagePositions.set(page, {
    x: normalized.x,
    y: normalized.y,
  });
}

module.exports = {
  clickAt,
  clickHandle,
  cursorForWindow,
  moveCursorTo,
  moveCursorToHandle,
  observabilityLevel,
  setPagePosition,
  targetForBox,
  telemetryEnabled,
};
