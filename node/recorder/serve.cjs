'use strict';

const fs = require('node:fs').promises;
const { constants } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromiumSandboxArgs, systemChromeCandidates } = require('../../resources/node/register/lib/browser-launcher.cjs');
const { RecorderError, authorized } = require('./security.cjs');
const { createEgressProxy } = require('./proxy.cjs');
const { RecorderSession } = require('./session.cjs');

const MAX_BODY_BYTES = 65536;

async function atomicJson(file, value) {
  const temporary = `${file}.tmp-${process.pid}`;
  await fs.writeFile(temporary, JSON.stringify(value), { mode: 0o600 });
  await fs.rename(temporary, file);
  await fs.chmod(file, 0o600);
}

async function readBody(request) {
  const chunks = []; let length = 0;
  for await (const chunk of request) {
    length += chunk.length;
    if (length > MAX_BODY_BYTES) throw new RecorderError('body_too_large', 'Die Browseraktion ist zu gross.', 413);
    chunks.push(chunk);
  }
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); }
  catch { throw new RecorderError('invalid_json', 'Die Browseraktion ist nicht gueltig kodiert.', 400); }
}

function jsonResponse(response, status, value) {
  response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff', 'Connection': 'close' });
  response.end(JSON.stringify(value));
}

async function recorderExecutablePath(puppeteer, config = {}, candidates = systemChromeCandidates) {
  const explicit = String(config.browserExecutablePath || '').trim();
  let cached = '';
  try { cached = String(puppeteer.executablePath() || ''); } catch { /* Use the existing installed browser candidates. */ }
  const installed = explicit ? [] : candidates(config);
  const files = explicit ? [explicit] : [...installed.slice(0, 5), cached, ...installed.slice(5)];
  for (const file of [...new Set(files.filter(Boolean))]) {
    try { await fs.access(file, constants.X_OK); if ((await fs.stat(file)).isFile()) return file; }
    catch { /* A missing known executable is not a browser launch attempt. */ }
  }
  throw new RecorderError('browser_not_found', 'Kein vorhandener Chrome-/Chromium-Browser wurde gefunden. Bitte die vorhandene Browserinstallation pruefen.');
}

async function startRecorder(configFile, dependencies = {}) {
  const configPath = await fs.realpath(path.resolve(configFile));
  const config = JSON.parse(await fs.readFile(configPath, 'utf8'));
  if (!config || typeof config !== 'object' || typeof config.secret !== 'string' || config.secret.length < 32 || config.secret.length > 256) {
    throw new RecorderError('invalid_configuration', 'Die private Aufnahmekonfiguration ist ungueltig.');
  }
  const runtimeDir = await fs.realpath(path.resolve(config.runtimeDir || path.dirname(configPath)));
  if (path.dirname(configPath) !== runtimeDir || path.basename(runtimeDir) === 'private') {
    throw new RecorderError('invalid_configuration', 'Die Aufnahmekonfiguration benoetigt ein eigenes privates Verzeichnis.');
  }
  await fs.chmod(runtimeDir, 0o700); await fs.chmod(configPath, 0o600);
  const profilePath = path.join(runtimeDir, 'profile');
  try { await fs.lstat(profilePath); throw new RecorderError('profile_conflict', 'Das Aufnahmeprofil ist bereits vorhanden. Bitte eine neue Aufnahme starten.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  await fs.mkdir(profilePath, { mode: 0o700 });
  const viewport = { width: Math.max(800, Math.min(1920, Math.round(Number(config.viewport?.width) || 1280))),
    height: Math.max(600, Math.min(1080, Math.round(Number(config.viewport?.height) || 800))) };
  const idleTimeoutMs = Math.max(60000, Math.min(1800000, Number(config.idleTimeoutMs) || 600000));
  const proxy = await (dependencies.createEgressProxy || createEgressProxy)({ testingLocalHosts: config.testingLocalHosts === true });
  let browser; let server; let stopping = false; let idleTimer; let lastActivity = Date.now();
  const descriptorPath = path.join(runtimeDir, 'descriptor.json');
  let cleanupPromise;
  const cleanup = () => {
    if (cleanupPromise) return cleanupPromise;
    stopping = true;
    cleanupPromise = (async () => {
      clearInterval(idleTimer);
      if (server) { server.closeAllConnections?.(); await new Promise((resolve) => server.close(resolve)); }
      let browserClosed = !browser;
      if (browser) {
        try { await browser.close(); browserClosed = true; } catch { /* Never delete an in-use browser profile. */ }
      }
      await proxy.close();
      // Delete only this daemon's own fresh profile, after its browser has closed.
      const profile = await fs.lstat(profilePath).catch(() => null);
      if (browserClosed && profile?.isDirectory() && !profile.isSymbolicLink() && path.dirname(profilePath) === runtimeDir) {
        await fs.rm(profilePath, { recursive: true, force: true });
      }
      for (const file of [descriptorPath, configPath]) await fs.unlink(file).catch(() => {});
    })();
    return cleanupPromise;
  };
  try {
    const puppeteer = dependencies.puppeteer || (await import('puppeteer')).default;
    const executablePath = await (dependencies.resolveExecutablePath || recorderExecutablePath)(puppeteer, config);
    // Recorder is deliberately Chrome-only, never a shared resident/runtime profile.
    browser = await (dependencies.launchBrowser || ((options) => puppeteer.launch(options)))({
        headless: true, userDataDir: profilePath, defaultViewport: viewport, timeout: 20000,
        args: [...chromiumSandboxArgs(config), '--disable-quic', '--disable-background-networking',
          '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
          `--proxy-server=http://127.0.0.1:${proxy.port}`, '--proxy-bypass-list=<-loopback>'],
        executablePath });
    const page = await browser.newPage();
    await page.setViewport(viewport);
    await page.setBypassServiceWorker(true);
    await page.setRequestInterception(true);
    page.on('request', (request) => {
      const url = request.url();
      // HTTP/HTTPS targets are pinned by the egress proxy; local data/blob subresources do not open sockets.
      if (/^(?:https?:|data:|blob:)/i.test(url) || url === 'about:blank') request.continue().catch(() => {});
      else request.abort('blockedbyclient').catch(() => {});
    });
    page.on('dialog', (dialog) => dialog.dismiss().catch(() => {}));
    const cdp = await page.createCDPSession();
    await cdp.send('Page.setDownloadBehavior', { behavior: 'deny' });
    browser.on('targetcreated', async (target) => {
      if (target.type() !== 'page') return;
      const popup = await target.page().catch(() => null);
      if (popup && popup !== page) await popup.close().catch(() => {});
    });
    const session = new RecorderSession({ page, viewport, testingLocalHosts: config.testingLocalHosts === true, maxEvents: config.maxEvents });
    let queue = Promise.resolve(); let queued = 0;
    const enqueue = (operation) => {
      if (queued >= 32 || stopping) throw new RecorderError('busy', 'Die Aufnahme verarbeitet bereits Aktionen. Bitte kurz warten.', 429);
      queued += 1;
      const result = queue.then(operation);
      queue = result.catch(() => {}).finally(() => { queued -= 1; });
      return result;
    };
    server = http.createServer(async (request, response) => {
      if (!authorized(request.headers.authorization, config.secret)) {
        jsonResponse(response, 401, { error: { code: 'unauthorized', message: 'Nicht autorisiert.' } }); return;
      }
      if (request.headers.origin || (Number(request.headers['content-length'] || 0) > MAX_BODY_BYTES)) {
        jsonResponse(response, 413, { error: { code: 'invalid_request', message: 'Die Browseraktion ist nicht erlaubt.' } }); return;
      }
      lastActivity = Date.now();
      try {
        if (request.method === 'GET' && request.url === '/state') {
          jsonResponse(response, 200, await enqueue(() => session.snapshot())); return;
        }
        if (request.method === 'GET' && request.url === '/frame') {
          const bytes = await enqueue(() => session.frame());
          response.writeHead(200, { 'Content-Type': 'image/jpeg', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
          response.end(Buffer.from(bytes)); return;
        }
        if (request.method === 'POST' && request.url === '/command') {
          const command = await readBody(request);
          jsonResponse(response, 200, await enqueue(() => session.command(command)));
          if (command.type === 'stop') setImmediate(() => cleanup().then(() => dependencies.onStop?.()).catch(() => {}));
          return;
        }
        jsonResponse(response, 404, { error: { code: 'not_found', message: 'Aktion nicht gefunden.' } });
      } catch (error) {
        // Third-party browser errors can contain URLs/values. Publish only our fixed safe errors.
        const safe = error instanceof RecorderError ? error
          : new RecorderError('browser_action_failed', 'Die Browseraktion konnte nicht ausgefuehrt werden. Bitte Seite und Ziel pruefen.', 422);
        if (!response.headersSent) jsonResponse(response, safe.status, { error: { code: safe.code, message: safe.message } });
      }
    });
    server.requestTimeout = 45000; server.headersTimeout = 10000;
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    await atomicJson(descriptorPath, { port: server.address().port, pid: process.pid });
    idleTimer = setInterval(() => {
      if (Date.now() - lastActivity >= idleTimeoutMs) cleanup().then(() => dependencies.onStop?.()).catch(() => {});
    }, 5000);
    idleTimer.unref();
    browser.on('disconnected', () => { if (!stopping) cleanup().then(() => dependencies.onStop?.()).catch(() => {}); });
    return { session, server, cleanup, port: server.address().port };
  } catch (error) {
    await cleanup();
    await atomicJson(path.join(runtimeDir, 'startup-error.json'), { error: {
      code: error instanceof RecorderError ? error.code : 'browser_start_failed',
      message: error instanceof RecorderError ? error.message : 'Der Aufnahmebrowser konnte nicht gestartet werden. Bitte Node und Chromium-Konfiguration pruefen.',
    } });
    throw error;
  }
}

if (require.main === module) {
  const configFile = process.argv[2];
  let active;
  const terminate = () => active?.cleanup().then(() => process.exit(0)).catch(() => process.exit(1));
  process.once('SIGTERM', terminate); process.once('SIGINT', terminate);
  if (!configFile) process.exitCode = 1;
  else startRecorder(configFile, { onStop: () => process.exit(0) }).then((result) => { active = result; }).catch(() => { process.exitCode = 1; });
}

module.exports = { MAX_BODY_BYTES, atomicJson, readBody, recorderExecutablePath, startRecorder };
