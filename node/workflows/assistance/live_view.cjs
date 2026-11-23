'use strict';

// Privater Live-View-Daemon fuer Admin-Aufgaben (Human-in-the-Loop).
//
// Haengt sich an den geparkten Workflow-Browser (puppeteer.connect), streamt das
// Fenster per CDP-Screencast und nimmt Maus-/Tastatureingaben direkt entgegen.
// So kann ein Administrator z. B. ein Puzzle-CAPTCHA per Drag-and-drop loesen,
// ohne dass fuer jede Aktion ein eigener Workflow-Prozess startet.
//
// Sicherheit: lauscht nur auf 127.0.0.1, jede Anfrage braucht das Bearer-Secret
// aus der privaten Konfigurationsdatei. Der Browser wird beim Beenden nur
// getrennt (disconnect), nie geschlossen: er gehoert weiterhin dem Workflow-Lauf.

const fs = require('node:fs').promises;
const path = require('node:path');
const http = require('node:http');
const { authorized } = require('../../recorder/security.cjs');

const MAX_BODY_BYTES = 8192;
const FRAME_WAIT_MAX_MS = 2000;
const ALLOWED_KEYS = Object.freeze({
  Enter: { key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' },
  Tab: { key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 },
  Backspace: { key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 },
  Escape: { key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 },
});

class LiveViewError extends Error {
  constructor(code, message, status = 422) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

async function atomicJson(file, value) {
  const temporary = `${file}.tmp-${process.pid}`;
  await fs.writeFile(temporary, JSON.stringify(value), { mode: 0o600 });
  await fs.rename(temporary, file);
}

async function readBody(request) {
  const chunks = [];
  let length = 0;
  for await (const chunk of request) {
    length += chunk.length;
    if (length > MAX_BODY_BYTES) throw new LiveViewError('body_too_large', 'Die Eingabe ist zu gross.', 413);
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new LiveViewError('invalid_json', 'Die Eingabe ist nicht gueltig kodiert.', 400);
  }
}

function jsonResponse(response, status, value) {
  response.writeHead(status, {
    'Content-Type': 'application/json',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  response.end(JSON.stringify(value));
}

function ratio(value) {
  const parsed = Number(value);

  if (!Number.isFinite(parsed) || parsed < 0 || parsed > 1) {
    throw new LiveViewError('invalid_position', 'Die Position liegt ausserhalb des Browserbildes.', 400);
  }

  return parsed;
}

function pageTargetId(page) {
  try {
    return String(page.target()?._targetId || '');
  } catch {
    return '';
  }
}

async function selectPage(browser, targetId) {
  const pages = await browser.pages();
  const exact = targetId ? pages.find((page) => pageTargetId(page) === targetId) : null;

  if (exact) return exact;
  if (targetId) {
    throw new LiveViewError('window_not_found', 'Das Browserfenster des Workflows wurde nicht gefunden.', 409);
  }

  const visible = pages.filter((page) => !/^about:blank$/i.test(page.url()));

  return visible[visible.length - 1] || pages[0] || null;
}

/**
 * Haelt das zuletzt empfangene Screencast-Bild und weckt wartende Frame-Anfragen,
 * sobald ein neueres Bild eintrifft (Long-Polling statt festem Intervall).
 */
class FrameStore {
  constructor() {
    this.sequence = 0;
    this.jpeg = null;
    this.metadata = null;
    this.waiters = new Set();
  }

  push(jpeg, metadata) {
    this.sequence += 1;
    this.jpeg = jpeg;
    this.metadata = metadata || null;
    for (const resolve of this.waiters) resolve();
    this.waiters.clear();
  }

  async next(afterSequence, timeoutMs) {
    if (this.jpeg && this.sequence > afterSequence) return;
    await new Promise((resolve) => {
      const done = () => {
        clearTimeout(timer);
        this.waiters.delete(done);
        resolve();
      };
      const timer = setTimeout(done, timeoutMs);
      this.waiters.add(done);
    });
  }
}

class LiveViewSession {
  constructor({ page, cdp, frames }) {
    this.page = page;
    this.cdp = cdp;
    this.frames = frames;
    this.buttonDown = false;
    this.lastPoint = { x: 0, y: 0 };
  }

  async viewport() {
    const metrics = await this.cdp.send('Page.getLayoutMetrics');
    const viewport = metrics.cssVisualViewport || metrics.cssLayoutViewport || metrics.layoutViewport || {};

    return {
      width: Math.max(1, Number(viewport.clientWidth) || 1366),
      height: Math.max(1, Number(viewport.clientHeight) || 900),
    };
  }

  async point(input) {
    const viewport = await this.viewport();

    return {
      x: Math.max(0, Math.min(viewport.width - 1, ratio(input.x) * viewport.width)),
      y: Math.max(0, Math.min(viewport.height - 1, ratio(input.y) * viewport.height)),
    };
  }

  async mouse(type, point, extra = {}) {
    await this.cdp.send('Input.dispatchMouseEvent', {
      type,
      x: point.x,
      y: point.y,
      button: extra.button || 'none',
      buttons: extra.buttons ?? (this.buttonDown ? 1 : 0),
      clickCount: extra.clickCount || 0,
      pointerType: 'mouse',
      ...(extra.deltaX !== undefined ? { deltaX: extra.deltaX, deltaY: extra.deltaY } : {}),
    });
    this.lastPoint = point;
  }

  async input(command = {}) {
    const type = String(command.type || '');

    switch (type) {
      case 'down': {
        const point = await this.point(command);
        await this.mouse('mouseMoved', point);
        this.buttonDown = true;
        await this.mouse('mousePressed', point, { button: 'left', buttons: 1, clickCount: 1 });
        break;
      }
      case 'move': {
        await this.mouse('mouseMoved', await this.point(command), this.buttonDown ? { button: 'left', buttons: 1 } : {});
        break;
      }
      case 'up': {
        const point = command.x === undefined ? this.lastPoint : await this.point(command);
        if (this.buttonDown) {
          await this.mouse('mouseMoved', point, { button: 'left', buttons: 1 });
          this.buttonDown = false;
          await this.mouse('mouseReleased', point, { button: 'left', buttons: 0, clickCount: 1 });
        }
        break;
      }
      case 'click': {
        const point = await this.point(command);
        await this.mouse('mouseMoved', point);
        this.buttonDown = true;
        await this.mouse('mousePressed', point, { button: 'left', buttons: 1, clickCount: 1 });
        this.buttonDown = false;
        await this.mouse('mouseReleased', point, { button: 'left', buttons: 0, clickCount: 1 });
        break;
      }
      case 'wheel': {
        const deltaY = Math.max(-2000, Math.min(2000, Number(command.deltaY) || 0));
        const deltaX = Math.max(-2000, Math.min(2000, Number(command.deltaX) || 0));
        await this.mouse('mouseWheel', await this.point(command), { deltaX, deltaY });
        break;
      }
      case 'key': {
        const definition = ALLOWED_KEYS[String(command.key || '')];
        if (!definition) throw new LiveViewError('key_not_allowed', 'Diese Taste ist nicht erlaubt.', 400);
        await this.cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', ...definition });
        await this.cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', ...definition, text: undefined });
        break;
      }
      case 'text': {
        const text = String(command.text || '');
        if (text === '' || text.length > 500) throw new LiveViewError('invalid_text', 'Der Text ist leer oder zu lang.', 400);
        await this.cdp.send('Input.insertText', { text });
        break;
      }
      default:
        throw new LiveViewError('unknown_input', 'Diese Eingabe ist nicht erlaubt.', 400);
    }

    return { ok: true, sequence: this.frames.sequence };
  }

  async releaseMouse() {
    if (!this.buttonDown) return;
    this.buttonDown = false;
    await this.mouse('mouseReleased', this.lastPoint, { button: 'left', buttons: 0, clickCount: 1 }).catch(() => {});
  }
}

async function startLiveView(configFile, dependencies = {}) {
  const configPath = await fs.realpath(path.resolve(configFile));
  const config = JSON.parse(await fs.readFile(configPath, 'utf8'));
  if (!config || typeof config.secret !== 'string' || config.secret.length < 32 || typeof config.wsEndpoint !== 'string') {
    throw new LiveViewError('invalid_configuration', 'Die Live-Ansicht ist nicht gueltig konfiguriert.');
  }
  const runtimeDir = path.dirname(configPath);
  const descriptorPath = path.join(runtimeDir, 'descriptor.json');
  const idleTimeoutMs = Math.max(15000, Math.min(600000, Number(config.idleTimeoutMs) || 90000));
  const maxLifetimeMs = Math.max(60000, Math.min(3600000, Number(config.maxLifetimeMs) || 1800000));
  const frames = new FrameStore();
  const startedAt = Date.now();
  let lastActivity = Date.now();
  let browser = null;
  let cdp = null;
  let session = null;
  let server = null;
  let watchdog = null;
  let cleanupPromise = null;

  const cleanup = () => {
    if (cleanupPromise) return cleanupPromise;
    cleanupPromise = (async () => {
      clearInterval(watchdog);
      if (server) {
        server.closeAllConnections?.();
        await new Promise((resolve) => server.close(resolve));
      }
      await session?.releaseMouse();
      await cdp?.send('Page.stopScreencast').catch(() => {});
      await cdp?.detach().catch(() => {});
      // Nur trennen: Der Browser gehoert dem pausierten Workflow-Lauf.
      try { browser?.disconnect(); } catch { /* bereits getrennt */ }
      for (const file of [descriptorPath, configPath]) await fs.unlink(file).catch(() => {});
    })();

    return cleanupPromise;
  };

  try {
    const puppeteer = dependencies.puppeteer || require('puppeteer');
    browser = await puppeteer.connect({ browserWSEndpoint: config.wsEndpoint, defaultViewport: null });
    const page = await selectPage(browser, String(config.targetId || ''));
    if (!page) throw new LiveViewError('window_not_found', 'Das Browserfenster des Workflows wurde nicht gefunden.', 409);

    cdp = await page.createCDPSession();
    cdp.on('Page.screencastFrame', (event) => {
      frames.push(Buffer.from(event.data, 'base64'), event.metadata);
      cdp.send('Page.screencastFrameAck', { sessionId: event.sessionId }).catch(() => {});
    });
    await cdp.send('Page.startScreencast', {
      format: 'jpeg',
      quality: Math.max(30, Math.min(90, Number(config.quality) || 70)),
      maxWidth: 1600,
      maxHeight: 1200,
      everyNthFrame: 1,
    });
    session = new LiveViewSession({ page, cdp, frames });

    // Statische Seiten liefern nur bei Aenderungen Screencast-Frames; das
    // Startbild kommt deshalb einmal per Screenshot.
    const initial = await page.screenshot({ type: 'jpeg', quality: 70 }).catch(() => null);
    if (initial && frames.sequence === 0) frames.push(Buffer.from(initial), null);

    let queue = Promise.resolve();
    const enqueue = (operation) => {
      const result = queue.then(operation);
      queue = result.catch(() => {});

      return result;
    };

    server = http.createServer(async (request, response) => {
      if (!authorized(request.headers.authorization, config.secret) || request.headers.origin) {
        jsonResponse(response, 401, { error: { code: 'unauthorized', message: 'Nicht autorisiert.' } });
        return;
      }
      lastActivity = Date.now();
      try {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (request.method === 'GET' && url.pathname === '/frame') {
          const after = Math.max(0, Number(url.searchParams.get('after')) || 0);
          const waitMs = Math.max(0, Math.min(FRAME_WAIT_MAX_MS, Number(url.searchParams.get('wait')) || 0));
          await frames.next(after, waitMs);
          if (!frames.jpeg) throw new LiveViewError('no_frame', 'Noch kein Browserbild verfuegbar.', 409);
          response.writeHead(200, {
            'Content-Type': 'image/jpeg',
            'Cache-Control': 'no-store',
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Sequence': String(frames.sequence),
          });
          response.end(frames.jpeg);
          return;
        }
        if (request.method === 'POST' && url.pathname === '/input') {
          const command = await readBody(request);
          jsonResponse(response, 200, await enqueue(() => session.input(command)));
          return;
        }
        if (request.method === 'POST' && url.pathname === '/stop') {
          jsonResponse(response, 200, { ok: true });
          setImmediate(() => cleanup().then(() => dependencies.onStop?.()).catch(() => {}));
          return;
        }
        jsonResponse(response, 404, { error: { code: 'not_found', message: 'Aktion nicht gefunden.' } });
      } catch (error) {
        const safe = error instanceof LiveViewError
          ? error
          : new LiveViewError('browser_action_failed', 'Die Browseraktion konnte nicht ausgefuehrt werden.', 422);
        if (!response.headersSent) jsonResponse(response, safe.status, { error: { code: safe.code, message: safe.message } });
      }
    });
    server.requestTimeout = 15000;
    server.headersTimeout = 10000;
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });
    await atomicJson(descriptorPath, { port: server.address().port, pid: process.pid });

    watchdog = setInterval(() => {
      const now = Date.now();
      if (now - lastActivity >= idleTimeoutMs || now - startedAt >= maxLifetimeMs) {
        cleanup().then(() => dependencies.onStop?.()).catch(() => {});
      }
    }, 2000);
    watchdog.unref?.();
    browser.on('disconnected', () => cleanup().then(() => dependencies.onStop?.()).catch(() => {}));

    return { session, frames, server, cleanup, port: server.address().port };
  } catch (error) {
    await cleanup();
    await atomicJson(path.join(runtimeDir, 'startup-error.json'), {
      error: {
        code: error instanceof LiveViewError ? error.code : 'live_view_start_failed',
        message: error instanceof LiveViewError ? error.message : 'Die Live-Ansicht konnte nicht gestartet werden.',
      },
    }).catch(() => {});
    throw error;
  }
}

if (require.main === module) {
  const configFile = process.argv[2];
  let active = null;
  const terminate = () => (active ? active.cleanup() : Promise.resolve()).then(() => process.exit(0)).catch(() => process.exit(1));
  process.once('SIGTERM', terminate);
  process.once('SIGINT', terminate);
  if (!configFile) {
    process.exitCode = 1;
  } else {
    startLiveView(configFile, { onStop: () => process.exit(0) })
      .then((result) => { active = result; })
      .catch(() => { process.exitCode = 1; });
  }
}

module.exports = { ALLOWED_KEYS, FrameStore, LiveViewError, LiveViewSession, selectPage, startLiveView };
