'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs').promises;
const path = require('node:path');
const os = require('node:os');
const { startRecorder, recorderExecutablePath } = require('../../node/recorder/serve.cjs');

async function fixture(overrides = {}) {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'followflow-recorder-test-'));
  const configFile = path.join(directory, 'config.json');
  const config = { runtimeDir: directory, secret: 'a'.repeat(64), maxEvents: 500, ...overrides };
  await fs.writeFile(configFile, JSON.stringify(config), { mode: 0o600 });
  const calls = [];
  const page = { url: () => 'about:blank', title: async () => '', setViewport: async () => {},
    setBypassServiceWorker: async () => {}, setRequestInterception: async () => {}, on: () => {},
    createCDPSession: async () => ({ send: async () => {} }), evaluate: async () => {},
    screenshot: async () => Buffer.from([255, 216, 255, 217]) };
  const browser = { newPage: async () => page, on: () => {}, close: async () => calls.push('browser-closed') };
  const dependencies = { puppeteer: {}, launchBrowser: async (options) => { calls.push(options); return browser; },
    resolveExecutablePath: async () => '/synthetic/chrome',
    createEgressProxy: async () => ({ port: 12345, close: async () => calls.push('proxy-closed') }) };
  const dispose = async () => {
    // This is the exact directory created by this test, not a computed workspace/home path.
    assert.equal(path.dirname(directory), os.tmpdir()); assert.match(path.basename(directory), /^followflow-recorder-test-/);
    await fs.rm(directory, { recursive: true, force: true });
  };
  return { directory, configFile, config, dependencies, calls, page, browser, dispose };
}

function request(port, secret, endpoint = '/state', options = {}) {
  return fetch(`http://127.0.0.1:${port}${endpoint}`, { ...options,
    headers: { Authorization: `Bearer ${secret}`, ...options.headers }, signal: AbortSignal.timeout(5000) });
}

test('daemon binds loopback random port, private descriptor and bearer auth without real browser', async () => {
  const f = await fixture(); let daemon;
  try {
    daemon = await startRecorder(f.configFile, f.dependencies);
    assert.equal(daemon.server.address().address, '127.0.0.1'); assert.equal(daemon.port >= 1024, true);
    assert.deepEqual(JSON.parse(await fs.readFile(path.join(f.directory, 'descriptor.json'), 'utf8')), { port: daemon.port, pid: process.pid });
    const unauthorized = await request(daemon.port, 'wrong'); assert.equal(unauthorized.status, 401);
    const state = await request(daemon.port, f.config.secret); assert.equal(state.status, 200);
    assert.equal((await state.json()).state, 'recording');
    const frame = await request(daemon.port, f.config.secret, '/frame'); assert.equal(frame.headers.get('content-type'), 'image/jpeg');
    assert.equal(Buffer.from(await frame.arrayBuffer())[0], 255);
    const options = f.calls.find((call) => typeof call === 'object');
    assert.equal(options.headless, true); assert.equal(options.timeout, 20000);
    assert.equal(options.args.includes('--proxy-bypass-list=<-loopback>'), true);
    assert.equal(options.args.includes('--no-sandbox'), false);
  } finally { await daemon?.cleanup(); await f.dispose(); }
});

test('daemon commands serialize, safe errors do not reveal browser payloads, cleanup removes owned profile and secret', async () => {
  const f = await fixture(); let daemon;
  try {
    daemon = await startRecorder(f.configFile, f.dependencies);
    const paused = await request(daemon.port, f.config.secret, '/command', { method: 'POST', body: JSON.stringify({ type: 'pause' }) });
    assert.equal((await paused.json()).state, 'paused');
    const rejected = await request(daemon.port, f.config.secret, '/command', { method: 'POST', body: JSON.stringify({ type: 'evaluate', script: 'sensitive-payload' }) });
    assert.equal(rejected.status, 422); assert.equal((await rejected.text()).includes('sensitive-payload'), false);
    f.page.title = async () => { throw new Error('SECRET_browser_exception_with_token'); };
    const failure = await request(daemon.port, f.config.secret); const body = await failure.text();
    assert.equal(body.includes('SECRET_browser_exception_with_token'), false); assert.equal(body.includes('browser_action_failed'), true);
    await Promise.all([daemon.cleanup(), daemon.cleanup()]);
    await assert.rejects(fs.stat(f.configFile), { code: 'ENOENT' });
    await assert.rejects(fs.stat(path.join(f.directory, 'profile')), { code: 'ENOENT' });
    await assert.rejects(fs.stat(path.join(f.directory, 'descriptor.json')), { code: 'ENOENT' });
    assert.deepEqual(f.calls.filter((call) => typeof call === 'string'), ['browser-closed', 'proxy-closed']);
  } finally { await daemon?.cleanup(); await f.dispose(); }
});

test('startup failure writes only a safe error and closes proxy/profile without exposing launch exception', async () => {
  const f = await fixture();
  f.dependencies.launchBrowser = async () => { throw new Error('SECRET_BROWSER_PATH_AND_SAMPLE'); };
  try {
    await assert.rejects(startRecorder(f.configFile, f.dependencies));
    const failure = await fs.readFile(path.join(f.directory, 'startup-error.json'), 'utf8');
    assert.equal(failure.includes('SECRET_BROWSER_PATH_AND_SAMPLE'), false); assert.equal(failure.includes('browser_start_failed'), true);
    await assert.rejects(fs.stat(f.configFile), { code: 'ENOENT' });
    await assert.rejects(fs.stat(path.join(f.directory, 'profile')), { code: 'ENOENT' });
    assert.equal(f.calls.includes('proxy-closed'), true);
  } finally { await f.dispose(); }
});

test('auto-discovery uses installed candidates without new configuration and explicit missing path fails closed', async () => {
  const f = await fixture();
  try {
    const existing = process.execPath;
    assert.equal(await recorderExecutablePath({ executablePath: () => '/missing/cached-browser' }, {}, () => [existing]), existing);
    assert.equal(await recorderExecutablePath({ executablePath: () => existing }, {}, () => []), existing);
    await assert.rejects(recorderExecutablePath({ executablePath: () => existing }, { browserExecutablePath: '/missing/explicit-browser' }, () => [existing]),
      { code: 'browser_not_found' });
  } finally { await f.dispose(); }
});
