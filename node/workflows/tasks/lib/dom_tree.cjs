'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const DEFAULT_MAX_DEPTH = 32;
const DEFAULT_MAX_NODES = 2500;
const DEFAULT_MAX_BYTES = 512 * 1024;
const MAX_NODE_TEXT_LENGTH = 160;
const MAX_CLASSES = 8;
const MAX_SELECTOR_CANDIDATES = 8;
const SAFE_ATTRIBUTE_NAMES = Object.freeze([
  'aria-label',
  'aria-labelledby',
  'autocomplete',
  'checked',
  'contenteditable',
  'data-cy',
  'data-qa',
  'data-test',
  'data-testid',
  'disabled',
  'href',
  'name',
  'placeholder',
  'readonly',
  'required',
  'role',
  'selected',
  'title',
  'type',
]);

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

  const scaleX = Number(offset.scaleX) > 0 ? Number(offset.scaleX) : 1;
  const scaleY = Number(offset.scaleY) > 0 ? Number(offset.scaleY) : 1;

  return {
    x: number(number(offset.x) + (number(rect.x) * scaleX)),
    y: number(number(offset.y) + (number(rect.y) * scaleY)),
    width: Math.max(0, number(number(rect.width) * scaleX)),
    height: Math.max(0, number(number(rect.height) * scaleY)),
  };
}

function jsonBytes(value) {
  return Buffer.byteLength(JSON.stringify(value), 'utf8');
}

function normalizeAttributes(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }

  return Object.fromEntries(
    SAFE_ATTRIBUTE_NAMES
      .filter((name) => Object.prototype.hasOwnProperty.call(value, name))
      .map((name) => [name, normalizeText(value[name], 300)])
      .filter(([, attributeValue]) => attributeValue !== ''),
  );
}

function normalizeSelectorCandidates(value) {
  const candidates = Array.isArray(value) ? value : [];
  const seen = new Set();

  return candidates
    .map((candidate) => {
      const source = typeof candidate === 'string'
        ? { selector: candidate }
        : (candidate && typeof candidate === 'object' ? candidate : {});
      const selector = normalizeText(source.selector, 500);

      if (selector === '' || seen.has(selector)) {
        return null;
      }

      seen.add(selector);

      const rawMatchCount = Number(source.matchCount);
      const rawScore = Number(source.score);

      return {
        selector,
        kind: normalizeText(source.kind, 40) || 'css',
        unique: source.unique === true,
        matchCount: Number.isFinite(rawMatchCount) ? Math.max(0, rawMatchCount) : 0,
        score: Number.isFinite(rawScore) ? Math.max(0, Math.min(100, rawScore)) : 0,
      };
    })
    .filter(Boolean)
    .slice(0, MAX_SELECTOR_CANDIDATES);
}

function materializeFrameNodes(records, options = {}, limit = records.length) {
  const frameRef = normalizeText(options.frameRef, 120) || 'frame-1';
  const windowKey = normalizeText(options.windowKey, 120) || 'main';
  const offset = {
    x: Number(options.offsetX || 0),
    y: Number(options.offsetY || 0),
    scaleX: Number(options.scaleX || 1),
    scaleY: Number(options.scaleY || 1),
  };
  const accepted = records.slice(0, Math.max(0, limit));
  const indexToRef = new Map();

  accepted.forEach((record, position) => {
    const structuralPath = normalizeText(record.path, 240) || String(record.index ?? position);
    const selector = normalizeText(record.selector, 500);
    const attributes = normalizeAttributes(record.attributes);
    const selectorIsStable = /^(?:#[^\s>+~]+|[a-z0-9_-]+\[(?:data-testid|data-test|data-cy|data-qa|name|aria-label|title)=)/i.test(selector);
    const identity = selectorIsStable && record.inShadowDom !== true
      ? `selector:${selector}`
      : [
        `path:${structuralPath}`,
        `tag:${normalizeText(record.tag, 40)}`,
        `id:${normalizeText(record.id, 120)}`,
        `selector:${selector}`,
        `role:${normalizeText(record.role || attributes.role, 80)}`,
      ].join('|');
    const fingerprint = crypto.createHash('sha1').update(identity).digest('hex').slice(0, 20);

    indexToRef.set(record.index ?? position, `${windowKey}:${frameRef}:${fingerprint}`);
  });

  return accepted.map((record, position) => {
    const index = record.index ?? position;
    const parentIndex = Number.isInteger(record.parentIndex) ? record.parentIndex : null;
    const classes = Array.isArray(record.classes)
      ? record.classes.map((item) => normalizeText(item, 80)).filter(Boolean).slice(0, MAX_CLASSES)
      : [];
    const selectorCandidates = normalizeSelectorCandidates(record.selectorCandidates);
    const attributes = normalizeAttributes(record.attributes);
    const optionalText = {
      id: normalizeText(record.id, 120),
      text: normalizeText(record.text),
      selector: normalizeText(record.selector, 500),
      role: normalizeText(record.role, 80),
      type: normalizeText(record.type, 80),
      name: normalizeText(record.name, 120),
      ariaLabel: normalizeText(record.ariaLabel, 160),
      label: normalizeText(record.label, 160),
      placeholder: normalizeText(record.placeholder, 160),
      title: normalizeText(record.title, 160),
      href: normalizeText(record.href, 300),
    };
    const node = {
      nodeRef: indexToRef.get(index),
      parentRef: parentIndex !== null ? (indexToRef.get(parentIndex) || null) : null,
      depth: Math.max(0, Number(record.depth || 0)),
      tag: normalizeText(record.tag, 40).toLowerCase(),
      rect: normalizeRect(record.rect, offset),
      visible: record.visible === true,
    };

    if (classes.length > 0) {
      node.classes = classes;
    }
    if (selectorCandidates.length > 0) {
      node.selectorCandidates = selectorCandidates;
    }
    if (Object.keys(attributes).length > 0) {
      node.attributes = attributes;
    }
    for (const [key, value] of Object.entries(optionalText)) {
      if (value !== '') {
        node[key] = value;
      }
    }
    if (record.enabled === false) {
      node.enabled = false;
    }
    for (const flag of ['focused', 'editable', 'actionable', 'inShadowDom']) {
      if (record[flag] === true) {
        node[flag] = true;
      }
    }

    return node;
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

async function frameGeometry(frame, viewport = null) {
  if (!frame || typeof frame.parentFrame !== 'function' || !frame.parentFrame()) {
    return { x: 0, y: 0, scaleX: 1, scaleY: 1 };
  }

  if (typeof frame.frameElement !== 'function') {
    return { x: 0, y: 0, scaleX: 1, scaleY: 1 };
  }

  const handle = await frame.frameElement().catch(() => null);

  if (!handle) {
    return { x: 0, y: 0, scaleX: 1, scaleY: 1 };
  }

  try {
    const box = typeof handle.boundingBox === 'function'
      ? await handle.boundingBox().catch(() => null)
      : null;
    const metrics = typeof handle.evaluate === 'function'
      ? await handle.evaluate((element) => {
        const style = window.getComputedStyle(element);
        const number = (value) => Number.parseFloat(value || '0') || 0;

        return {
          offsetWidth: element.offsetWidth || 0,
          offsetHeight: element.offsetHeight || 0,
          clientLeft: element.clientLeft || 0,
          clientTop: element.clientTop || 0,
          clientWidth: element.clientWidth || 0,
          clientHeight: element.clientHeight || 0,
          paddingLeft: number(style.paddingLeft),
          paddingRight: number(style.paddingRight),
          paddingTop: number(style.paddingTop),
          paddingBottom: number(style.paddingBottom),
        };
      }).catch(() => null)
      : null;

    if (!box) {
      return { x: 0, y: 0, scaleX: 1, scaleY: 1 };
    }

    const outerScaleX = metrics?.offsetWidth > 0 ? Number(box.width || 0) / metrics.offsetWidth : 1;
    const outerScaleY = metrics?.offsetHeight > 0 ? Number(box.height || 0) / metrics.offsetHeight : 1;
    const contentWidth = Math.max(
      0,
      Number(metrics?.clientWidth || box.width || 0)
        - Number(metrics?.paddingLeft || 0)
        - Number(metrics?.paddingRight || 0),
    ) * outerScaleX;
    const contentHeight = Math.max(
      0,
      Number(metrics?.clientHeight || box.height || 0)
        - Number(metrics?.paddingTop || 0)
        - Number(metrics?.paddingBottom || 0),
    ) * outerScaleY;
    const viewportWidth = Number(viewport?.width || 0);
    const viewportHeight = Number(viewport?.height || 0);

    return {
      x: Number(box.x || 0)
        + ((Number(metrics?.clientLeft || 0) + Number(metrics?.paddingLeft || 0)) * outerScaleX),
      y: Number(box.y || 0)
        + ((Number(metrics?.clientTop || 0) + Number(metrics?.paddingTop || 0)) * outerScaleY),
      scaleX: viewportWidth > 0 && contentWidth > 0 ? contentWidth / viewportWidth : outerScaleX,
      scaleY: viewportHeight > 0 && contentHeight > 0 ? contentHeight / viewportHeight : outerScaleY,
    };
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
    const safeHref = (element) => {
      const rawHref = String(element.getAttribute('href') || '').trim();

      if (rawHref === '' || /^(?:javascript|data|vbscript):/i.test(rawHref)) {
        return '';
      }

      try {
        const url = new URL(rawHref, window.location.href);

        return `${url.origin === window.location.origin ? '' : url.origin}${url.pathname}`;
      } catch {
        return rawHref.split(/[?#]/, 1)[0].slice(0, 300);
      }
    };
    const labelFor = (element) => {
      const labels = element.labels?.length
        ? Array.from(element.labels).map((label) => label.textContent || '').join(' ')
        : '';

      return clean(
        labels
        || element.closest?.('label')?.textContent
        || element.getAttribute('aria-label')
        || '',
      );
    };
    const actionable = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      return ['a', 'button', 'select', 'textarea'].includes(tag)
        || (tag === 'input' && type !== 'hidden')
        || ['button', 'link', 'checkbox', 'radio', 'switch', 'textbox', 'combobox', 'option'].includes(
          String(element.getAttribute('role') || '').toLowerCase(),
        )
        || element.isContentEditable === true;
    };
    const editable = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      return element.isContentEditable === true
        || tag === 'textarea'
        || tag === 'select'
        || (tag === 'input' && type !== 'hidden');
    };
    const safeAttributes = (element) => {
      const attributes = {};

      for (const attribute of [
        'aria-label',
        'aria-labelledby',
        'autocomplete',
        'data-cy',
        'data-qa',
        'data-test',
        'data-testid',
        'name',
        'placeholder',
        'role',
        'title',
        'type',
      ]) {
        const value = clean(element.getAttribute(attribute) || '', 300);

        if (value !== '') {
          attributes[attribute] = value;
        }
      }

      for (const attribute of ['checked', 'disabled', 'readonly', 'required', 'selected']) {
        if (element.hasAttribute(attribute) || element[attribute] === true) {
          attributes[attribute] = 'true';
        }
      }
      if (element.isContentEditable === true) {
        attributes.contenteditable = 'true';
      }

      const href = safeHref(element);
      if (href !== '') {
        attributes.href = href;
      }

      return attributes;
    };
    const structuralSelectorFor = (element) => {
      const tag = String(element.tagName || '').toLowerCase();

      if (!tag) {
        return '';
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
          break;
        } else {
          current = parent;
        }
      }

      return segments.join(' > ');
    };
    const primarySelectorFor = (element, attributes) => {
      const tag = String(element.tagName || '').toLowerCase();

      if (!tag) {
        return '';
      }

      for (const attribute of [
        'data-testid',
        'data-test',
        'data-cy',
        'data-qa',
        'aria-label',
        'name',
        'placeholder',
        'title',
      ]) {
        const value = attributes[attribute];

        if (value) {
          return `${tag}[${attribute}="${cssString(value)}"]`;
        }
      }

      if (attributes.role && attributes['aria-label']) {
        return `[role="${cssString(attributes.role)}"][aria-label="${cssString(attributes['aria-label'])}"]`;
      }

      if (element.id) {
        return `#${cssIdentifier(element.id)}`;
      }

      if (attributes.type) {
        return `${tag}[type="${cssString(attributes.type)}"]`;
      }

      const stableClasses = Array.from(element.classList || [])
        .filter((className) => (
          className.length <= 80
          && !/(?:^css-|^sc-|__|[a-f0-9]{10,}|\d{5,})/i.test(className)
        ))
        .slice(0, 2);

      if (stableClasses.length > 0) {
        return `${tag}${stableClasses.map((className) => `.${cssIdentifier(className)}`).join('')}`;
      }

      return structuralSelectorFor(element) || tag;
    };
    const directText = (element) => {
      const tag = String(element.tagName || '').toLowerCase();
      const type = String(element.getAttribute('type') || '').toLowerCase();

      if (
        ['script', 'style', 'template', 'noscript', 'input', 'textarea', 'select'].includes(tag)
        || element.isContentEditable
        || (tag === 'input' && ['password', 'hidden'].includes(type))
      ) {
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
    const root = document.body;
    const stack = root
      ? [{ element: root, parentIndex: null, depth: 0, path: '0', inShadowDom: false }]
      : [];

    while (stack.length > 0 && records.length < nodeLimit) {
      const current = stack.pop();
      const element = current.element;
      const rect = element.getBoundingClientRect();
      const index = records.length;
      const tag = String(element.tagName || '').toLowerCase();
      const attributes = safeAttributes(element);

      records.push({
        index,
        parentIndex: current.parentIndex,
        depth: current.depth,
        path: current.path,
        tag,
        id: element.id || '',
        classes: Array.from(element.classList || []).slice(0, classLimit),
        text: directText(element),
        selector: primarySelectorFor(element, attributes),
        attributes,
        label: labelFor(element),
        rect: {
          x: Number(rect.x.toFixed(2)),
          y: Number(rect.y.toFixed(2)),
          width: Number(rect.width.toFixed(2)),
          height: Number(rect.height.toFixed(2)),
        },
        visible: visible(element, rect),
        enabled: !element.disabled && element.getAttribute('aria-disabled') !== 'true',
        focused: document.activeElement === element,
        editable: editable(element),
        actionable: actionable(element),
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
      rootTag: root ? 'body' : '',
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
  const mainFrame = typeof page?.mainFrame === 'function'
    ? page.mainFrame()
    : frames[0];
  const frameReferences = new Map(frames.map((frame, index) => {
    if (frame === mainFrame) {
      return [frame, 'main'];
    }

    const runtimeFrameId = normalizeText(frame?._id, 120);

    return [frame, runtimeFrameId ? `frame-${runtimeFrameId}` : `frame-${index + 1}`];
  }));
  const capturedFrames = [];
  let remainingNodes = maxNodes;
  let remainingBytes = maxBytes;

  for (let index = 0; index < frames.length && remainingNodes > 0 && remainingBytes >= 4096; index += 1) {
    const frame = frames[index];
    const frameRef = frameReferences.get(frame) || `frame-${index + 1}`;
    const parent = typeof frame.parentFrame === 'function' ? frame.parentFrame() : null;
    const framesRemaining = Math.max(1, frames.length - index);
    const frameByteBudget = Math.max(4096, Math.floor(remainingBytes / framesRemaining));

    try {
      const snapshot = await frameRecords(frame, {
        maxNodes: remainingNodes,
        maxDepth,
      });
      const geometry = await frameGeometry(frame, snapshot.viewport);
      const built = buildFrameTree(snapshot.records, {
        frameRef,
        windowKey,
        offsetX: geometry.x,
        offsetY: geometry.y,
        scaleX: geometry.scaleX,
        scaleY: geometry.scaleY,
        maxNodes: remainingNodes,
        maxDepth,
        maxBytes: frameByteBudget,
        depthTruncated: snapshot.depthTruncated,
      });
      const framePayload = {
        frameRef,
        parentFrameRef: parent ? (frameReferences.get(parent) || null) : null,
        rootTag: normalizeText(snapshot.rootTag, 20) || 'body',
        name: typeof frame.name === 'function' ? normalizeText(frame.name(), 120) : '',
        url: typeof frame.url === 'function' ? normalizeText(frame.url(), 2000) : '',
        offsetX: geometry.x,
        offsetY: geometry.y,
        scaleX: geometry.scaleX,
        scaleY: geometry.scaleY,
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
        rootTag: 'body',
        name: typeof frame.name === 'function' ? normalizeText(frame.name(), 120) : '',
        url: typeof frame.url === 'function' ? normalizeText(frame.url(), 2000) : '',
        offsetX: 0,
        offsetY: 0,
        scaleX: 1,
        scaleY: 1,
        viewport: null,
        nodes: [],
        nodeCount: 0,
        truncated: { nodes: false, depth: false, bytes: false },
        error: normalizeText(error?.message || error, 500),
      });
    }
  }

  const payload = {
    version: 2,
    capturedAt: new Date().toISOString(),
    windowKey,
    targetId: normalizeText(options.targetId, 200),
    rootTag: 'body',
    viewport: capturedFrames[0]?.viewport || null,
    frames: capturedFrames,
    nodeCount: capturedFrames.reduce((total, frame) => total + Number(frame.nodeCount || 0), 0),
    truncated: {
      nodes: remainingNodes <= 0 || capturedFrames.some((frame) => frame.truncated?.nodes === true),
      depth: capturedFrames.some((frame) => frame.truncated?.depth === true),
      bytes: remainingBytes < 4096 || capturedFrames.some((frame) => frame.truncated?.bytes === true),
    },
  };

  const finalByteSize = () => {
    let previous = -1;

    while (payload.byteSize !== previous) {
      previous = payload.byteSize;
      payload.byteSize = jsonBytes(payload);
    }

    return payload.byteSize;
  };

  while (finalByteSize() > maxBytes) {
    const frameWithNodes = [...payload.frames].reverse().find((frame) => frame.nodes.length > 0);

    if (frameWithNodes) {
      const excess = Math.max(1, payload.byteSize - maxBytes);
      const averageNodeBytes = Math.max(1, Math.floor(payload.byteSize / Math.max(1, payload.nodeCount)));
      const removeCount = Math.max(1, Math.ceil(excess / averageNodeBytes));
      frameWithNodes.nodes.splice(Math.max(0, frameWithNodes.nodes.length - removeCount), removeCount);
      frameWithNodes.nodeCount = frameWithNodes.nodes.length;
      frameWithNodes.truncated.bytes = true;
      payload.truncated.bytes = true;
      payload.nodeCount = payload.frames.reduce((total, frame) => total + Number(frame.nodeCount || 0), 0);
      continue;
    }

    if (payload.frames.length > 1) {
      payload.frames.pop();
      payload.truncated.bytes = true;
      continue;
    }

    const firstFrame = payload.frames[0];
    if (firstFrame && (firstFrame.url || firstFrame.name || firstFrame.error)) {
      firstFrame.url = '';
      firstFrame.name = '';
      delete firstFrame.error;
      payload.targetId = '';
      payload.truncated.bytes = true;
      continue;
    }

    break;
  }
  finalByteSize();

  return payload;
}

function writeJsonAtomic(filePath, payload) {
  const directory = path.dirname(filePath);
  const temporaryPath = `${filePath}.${process.pid}.${Date.now()}.${Math.random().toString(16).slice(2)}.tmp`;

  fs.mkdirSync(directory, { recursive: true });

  try {
    fs.writeFileSync(temporaryPath, JSON.stringify(payload));
    let lastError = null;

    for (let attempt = 0; attempt < 5; attempt += 1) {
      try {
        fs.renameSync(temporaryPath, filePath);
        lastError = null;
        break;
      } catch (error) {
        lastError = error;

        if (!['EACCES', 'EBUSY', 'EPERM'].includes(error?.code) || attempt === 4) {
          throw error;
        }

        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10 * (attempt + 1));
      }
    }

    if (lastError) {
      throw lastError;
    }
  } finally {
    if (fs.existsSync(temporaryPath)) {
      try {
        fs.rmSync(temporaryPath, { force: true });
      } catch {
        // Best effort only; never hide the original write/rename error.
      }
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
