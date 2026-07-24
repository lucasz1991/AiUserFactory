'use strict';

const fs = require('fs');
const path = require('path');

const DEFAULT_MAX_DEPTH = 32;
const DEFAULT_MAX_NODES = 2500;
const DEFAULT_MAX_BYTES = 512 * 1024;
const MAX_NODE_TEXT_LENGTH = 160;
const MAX_CLASSES = 8;

function boundedInteger(value, fallback, minimum, maximum) {
  const parsed = Number(value);

  if (!Number.isFinite(parsed)) {
    return fallback;
  }

  return Math.max(minimum, Math.min(maximum, Math.floor(parsed)));
}

function normalizeText(value, limit = MAX_NODE_TEXT_LENGTH) {
  return String(value || '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, limit);
}

function normalizeRect(rect = {}, offset = {}) {
  const number = (value) => {
    const parsed = Number(value);

    return Number.isFinite(parsed) ? Math.round(parsed * 100) / 100 : 0;
  };

  return {
    x: number(rect.x) + number(offset.x),
    y: number(rect.y) + number(offset.y),
    width: Math.max(0, number(rect.width)),
    height: Math.max(0, number(rect.height)),
  };
}

function jsonBytes(value) {
  return Buffer.byteLength(JSON.stringify(value), 'utf8');
}

function materializeFrameNodes(records, options = {}, limit = records.length) {
  const frameRef = normalizeText(options.frameRef, 120) || 'frame-1';
  const windowKey = normalizeText(options.windowKey, 120) || 'main';
  const offset = {
    x: Number(options.offsetX || 0),
    y: Number(options.offsetY || 0),
  };
  const accepted = records.slice(0, Math.max(0, limit));
  const indexToRef = new Map();

  accepted.forEach((record, position) => {
    const stablePath = normalizeText(record.path, 240) || String(record.index ?? position);
    indexToRef.set(record.index ?? position, `${windowKey}:${frameRef}:${stablePath}`);
  });

  return accepted.map((record, position) => {
    const index = record.index ?? position;
    const parentIndex = Number.isInteger(record.parentIndex) ? record.parentIndex : null;

    return {
      nodeRef: indexToRef.get(index),
      parentRef: parentIndex !== null ? (indexToRef.get(parentIndex) || null) : null,
      depth: Math.max(0, Number(record.depth || 0)),
      tag: normalizeText(record.tag, 40).toLowerCase(),
      id: normalizeText(record.id, 120),
      classes: Array.isArray(record.classes)
        ? record.classes.map((item) => normalizeText(item, 80)).filter(Boolean).slice(0, MAX_CLASSES)
        : [],
      text: normalizeText(record.text),
      selector: normalizeText(record.selector, 500),
      role: normalizeText(record.role, 80),
      type: normalizeText(record.type, 80),
      name: normalizeText(record.name, 120),
      ariaLabel: normalizeText(record.ariaLabel, 160),
      rect: normalizeRect(record.rect, offset),
      visible: record.visible === true,
      enabled: record.enabled !== false,
      inShadowDom: record.inShadowDom === true,
    };
  });
}

/**
 * Turns the browser-side pre-order records into a bounded, flat tree.
 * `parentRef` keeps the hierarchy without deeply nested JSON/Livewire payloads.
 */
function buildFrameTree(records = [], options = {}) {
  const maxNodes = boundedInteger(options.maxNodes, DEFAULT_MAX_NODES, 1, DEFAULT_MAX_NODES);
  const maxDepth = boundedInteger(options.maxDepth, DEFAULT_MAX_DEPTH, 1, DEFAULT_MAX_DEPTH);
  const maxBytes = boundedInteger(options.maxBytes, DEFAULT_MAX_BYTES, 4096, DEFAULT_MAX_BYTES);
  const source = Array.isArray(records) ? records : [];
  const withinDepth = source.filter((record) => Number(record?.depth || 0) <= maxDepth);
  const nodeLimited = withinDepth.slice(0, maxNodes);
  let nodes = materializeFrameNodes(nodeLimited, options);
  let bytesTruncated = false;

  if (jsonBytes(nodes) > maxBytes) {
    let low = 0;
    let high = nodes.length;

    while (low < high) {
      const middle = Math.ceil((low + high) / 2);
      const candidate = materializeFrameNodes(nodeLimited, options, middle);

      if (jsonBytes(candidate) <= maxBytes) {
        low = middle;
      } else {
        high = middle - 1;
      }
    }

    nodes = materializeFrameNodes(nodeLimited, options, low);
    bytesTruncated = nodes.length < nodeLimited.length;
  }

  return {
    nodes,
    nodeCount: nodes.length,
    byteSize: jsonBytes(nodes),
    truncated: {
      nodes: source.length > maxNodes || withinDepth.length > maxNodes,
      depth: source.some((record) => Number(record?.depth || 0) > maxDepth)
        || options.depthTruncated === true,
      bytes: bytesTruncated,
    },
  };
}

async function frameOffset(frame) {
  if (!frame || typeof frame.parentFrame !== 'function' || !frame.parentFrame()) {
    return { x: 0, y: 0 };
  }

  if (typeof frame.frameElement !== 'function') {
    return { x: 0, y: 0 };
  }

  const handle = await frame.frameElement().catch(() => null);

  if (!handle) {
    return { x: 0, y: 0 };
  }

  try {
    const box = typeof handle.boundingBox === 'function'
      ? await handle.boundingBox().catch(() => null)
      : null;

    return box
      ? { x: Number(box.x || 0), y: Number(box.y || 0) }
      : { x: 0, y: 0 };
  } finally {
    await handle.dispose?.().catch(() => {});
  }
}

async function frameRecords(frame, options = {}) {
  if (!frame || typeof frame.evaluate !== 'function') {
    return {
      records: [],
      viewport: null,
      depthTruncated: false,
    };
  }

  const maxNodes = boundedInteger(options.maxNodes, DEFAULT_MAX_NODES, 1, DEFAULT_MAX_NODES);
  const maxDepth = boundedInteger(options.maxDepth, DEFAULT_MAX_DEPTH, 1, DEFAULT_MAX_DEPTH);

  return frame.evaluate(({ nodeLimit, depthLimit, textLimit, classLimit }) => {
    const clean = (value, limit = textLimit) => String(value || '')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, limit);
    const cssString = (value) => String(value || '')
      .replace(/\\/g, '\\\\')
      .replace(/"/g, '\\"')
      .replace(/\r/g, '\\d ')
      .replace(/\n/g, '\\a ');
    const cssIdentifier = (value) => {
      if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(String(value || ''));
      }

      return String(value || '').replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character}`);
    };
    const uniqueSelector = (element) => {
      const tag = String(element.tagName || '').toLowerCase();

      if (!tag) {
        return '';
      }

      if (element.id) {
        const idSelector = `#${cssIdentifier(element.id)}`;

        try {
          if (document.querySelectorAll(idSelector).length === 1) {
            return idSelector;
          }
        } catch {
          // Continue with stable attributes/path.
        }
      }

      for (const attribute of ['data-testid', 'data-test', 'data-cy', 'data-qa', 'name', 'aria-label', 'title']) {
        const value = element.getAttribute(attribute);

        if (!value) {
          continue;
        }

        const candidate = `${tag}[${attribute}="${cssString(value)}"]`;

        try {
          if (document.querySelectorAll(candidate).length === 1) {
            return candidate;
          }
        } catch {
          // Continue with the structural path.
        }
      }

      const segments = [];
      let current = element;

      while (current && current.nodeType === Node.ELEMENT_NODE && segments.length < depthLimit) {
        const currentTag = String(current.tagName || '').toLowerCase();

        if (!currentTag) {
          break;
        }

        let segment = currentTag;
        const parent = current.parentElement;

        if (parent) {
          const sameTagSiblings = Array.from(parent.children)
            .filter((candidate) => String(candidate.tagName || '').toLowerCase() === currentTag);

          if (sameTagSiblings.length > 1) {
            segment += `:nth-of-type(${sameTagSiblings.indexOf(current) + 1})`;
          }
        }

        segments.unshift(segment);

        const root = current.getRootNode?.();
        if (root && root.host && !current.parentElement) {
          current = root.host;
        } else {
          current = parent;
        }
      }

      return segments.join(' > ');
    };
    const directText = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      if (tag === 'input' && ['password', 'hidden'].includes(type)) {
        return '';
      }

      const ownText = Array.from(element.childNodes || [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent || '')
        .join(' ');

      return clean(
        ownText
        || element.getAttribute('aria-label')
        || element.getAttribute('title')
        || element.getAttribute('placeholder')
        || '',
      );
    };
    const visible = (element, rect) => {
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number.parseFloat(style.opacity || '1') > 0
        && element.getAttribute('aria-hidden') !== 'true';
    };
    const records = [];
    let depthTruncated = false;
    const root = document.documentElement;
    const stack = root
      ? [{ element: root, parentIndex: null, depth: 0, path: '0', inShadowDom: false }]
      : [];

    while (stack.length > 0 && records.length < nodeLimit) {
      const current = stack.pop();
      const element = current.element;
      const rect = element.getBoundingClientRect();
      const index = records.length;
      const tag = String(element.tagName || '').toLowerCase();

      records.push({
        index,
        parentIndex: current.parentIndex,
        depth: current.depth,
        path: current.path,
        tag,
        id: element.id || '',
        classes: Array.from(element.classList || []).slice(0, classLimit),
        text: directText(element),
        selector: uniqueSelector(element),
        role: element.getAttribute('role') || '',
        type: element.getAttribute('type') || '',
        name: element.getAttribute('name') || '',
        ariaLabel: element.getAttribute('aria-label') || '',
        rect: {
          x: Number(rect.x.toFixed(2)),
          y: Number(rect.y.toFixed(2)),
          width: Number(rect.width.toFixed(2)),
          height: Number(rect.height.toFixed(2)),
        },
        visible: visible(element, rect),
        enabled: !element.disabled && element.getAttribute('aria-disabled') !== 'true',
        inShadowDom: current.inShadowDom,
      });

      const lightChildren = Array.from(element.children || [])
        .map((child, childIndex) => ({
          element: child,
          parentIndex: index,
          depth: current.depth + 1,
          path: `${current.path}.${childIndex}`,
          inShadowDom: current.inShadowDom,
        }));
      const shadowChildren = element.shadowRoot
        ? Array.from(element.shadowRoot.children || []).map((child, childIndex) => ({
          element: child,
          parentIndex: index,
          depth: current.depth + 1,
          path: `${current.path}.s${childIndex}`,
          inShadowDom: true,
        }))
        : [];
      const children = lightChildren.concat(shadowChildren);

      if (current.depth >= depthLimit) {
        if (children.length > 0) {
          depthTruncated = true;
        }
        continue;
      }

      for (let childIndex = children.length - 1; childIndex >= 0; childIndex -= 1) {
        stack.push(children[childIndex]);
      }
    }

    return {
      records,
      depthTruncated,
      nodeLimitReached: stack.length > 0,
      viewport: {
        width: window.innerWidth,
        height: window.innerHeight,
        deviceScaleFactor: window.devicePixelRatio || 1,
        scrollX: window.scrollX || 0,
        scrollY: window.scrollY || 0,
      },
    };
  }, {
    nodeLimit: maxNodes,
    depthLimit: maxDepth,
    textLimit: MAX_NODE_TEXT_LENGTH,
    classLimit: MAX_CLASSES,
  });
}

function framesForPage(page) {
  if (!page) {
    return [];
  }

  if (typeof page.frames === 'function') {
    try {
      const frames = page.frames();

      if (Array.isArray(frames) && frames.length > 0) {
        return frames;
      }
    } catch {
      // A navigation can replace the frame tree; fall back to the page itself.
    }
  }

  return typeof page.evaluate === 'function' ? [page] : [];
}

async function captureDomTree(page, options = {}) {
  const maxNodes = boundedInteger(options.maxNodes, DEFAULT_MAX_NODES, 1, DEFAULT_MAX_NODES);
  const maxDepth = boundedInteger(options.maxDepth, DEFAULT_MAX_DEPTH, 1, DEFAULT_MAX_DEPTH);
  const maxBytes = boundedInteger(options.maxBytes, DEFAULT_MAX_BYTES, 4096, DEFAULT_MAX_BYTES);
  const frames = framesForPage(page);
  const windowKey = normalizeText(options.windowKey, 120) || 'main';
  const frameReferences = new Map(frames.map((frame, index) => [frame, `frame-${index + 1}`]));
  const capturedFrames = [];
  let remainingNodes = maxNodes;
  let remainingBytes = maxBytes;

  for (let index = 0; index < frames.length && remainingNodes > 0 && remainingBytes >= 4096; index += 1) {
    const frame = frames[index];
    const frameRef = frameReferences.get(frame) || `frame-${index + 1}`;
    const parent = typeof frame.parentFrame === 'function' ? frame.parentFrame() : null;
    const offset = await frameOffset(frame);
    const framesRemaining = Math.max(1, frames.length - index);
    const frameByteBudget = Math.max(4096, Math.floor(remainingBytes / framesRemaining));

    try {
      const snapshot = await frameRecords(frame, {
        maxNodes: remainingNodes,
        maxDepth,
      });
      const built = buildFrameTree(snapshot.records, {
        frameRef,
        windowKey,
        offsetX: offset.x,
        offsetY: offset.y,
        maxNodes: remainingNodes,
        maxDepth,
        maxBytes: frameByteBudget,
        depthTruncated: snapshot.depthTruncated,
      });
      const framePayload = {
        frameRef,
        parentFrameRef: parent ? (frameReferences.get(parent) || null) : null,
        name: typeof frame.name === 'function' ? normalizeText(frame.name(), 120) : '',
        url: typeof frame.url === 'function' ? normalizeText(frame.url(), 2000) : '',
        offsetX: offset.x,
        offsetY: offset.y,
        viewport: snapshot.viewport || null,
        nodes: built.nodes,
        nodeCount: built.nodeCount,
        truncated: {
          ...built.truncated,
          nodes: built.truncated.nodes || snapshot.nodeLimitReached === true,
        },
      };

      capturedFrames.push(framePayload);
      remainingNodes -= built.nodeCount;
      remainingBytes -= jsonBytes(framePayload);
    } catch (error) {
      capturedFrames.push({
        frameRef,
        parentFrameRef: parent ? (frameReferences.get(parent) || null) : null,
        name: typeof frame.name === 'function' ? normalizeText(frame.name(), 120) : '',
        url: typeof frame.url === 'function' ? normalizeText(frame.url(), 2000) : '',
        offsetX: offset.x,
        offsetY: offset.y,
        viewport: null,
        nodes: [],
        nodeCount: 0,
        truncated: { nodes: false, depth: false, bytes: false },
        error: normalizeText(error?.message || error, 500),
      });
    }
  }

  const payload = {
    version: 1,
    capturedAt: new Date().toISOString(),
    windowKey,
    targetId: normalizeText(options.targetId, 200),
    viewport: capturedFrames[0]?.viewport || null,
    frames: capturedFrames,
    nodeCount: capturedFrames.reduce((total, frame) => total + Number(frame.nodeCount || 0), 0),
    truncated: {
      nodes: remainingNodes <= 0 || capturedFrames.some((frame) => frame.truncated?.nodes === true),
      depth: capturedFrames.some((frame) => frame.truncated?.depth === true),
      bytes: remainingBytes < 4096 || capturedFrames.some((frame) => frame.truncated?.bytes === true),
    },
  };

  payload.byteSize = jsonBytes(payload);

  return payload;
}

function writeJsonAtomic(filePath, payload) {
  const directory = path.dirname(filePath);
  const temporaryPath = `${filePath}.${process.pid}.${Date.now()}.tmp`;

  fs.mkdirSync(directory, { recursive: true });

  try {
    fs.writeFileSync(temporaryPath, JSON.stringify(payload));
    fs.renameSync(temporaryPath, filePath);
  } finally {
    if (fs.existsSync(temporaryPath)) {
      fs.rmSync(temporaryPath, { force: true });
    }
  }
}

module.exports = {
  DEFAULT_MAX_BYTES,
  DEFAULT_MAX_DEPTH,
  DEFAULT_MAX_NODES,
  buildFrameTree,
  captureDomTree,
  writeJsonAtomic,
};
