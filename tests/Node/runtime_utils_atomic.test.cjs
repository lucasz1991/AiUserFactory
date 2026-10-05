'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../resources/node/register/lib/runtime-utils.cjs');
const source = fs.readFileSync(sourcePath, 'utf8');

function fakeWriter(errors, platform = 'win32') {
  const destination = path.join('fixture', 'status.json');
  const oldValue = JSON.stringify({ state: 'running' });
  const files = new Map([[destination, oldValue]]);
  const waits = [];
  const removals = [];
  const writes = [];
  let temporaryPath;
  let attempts = 0;
  let closes = 0;
  const fakeFs = {
    mkdirSync() {},
    openSync(file, flags, mode) {
      assert.equal(flags, 'wx');
      assert.equal(mode, 0o600);
      assert.notEqual(file, destination);
      temporaryPath = file;
      files.set(file, '');
      return 17;
    },
    writeFileSync(descriptor, value) {
      assert.equal(descriptor, 17, 'Only the private temporary descriptor may be written');
      writes.push(descriptor);
      files.set(temporaryPath, value);
    },
    closeSync(descriptor) {
      assert.equal(descriptor, 17);
      closes++;
    },
    renameSync(from, to) {
      attempts++;
      assert.equal(from, temporaryPath);
      assert.equal(to, destination);
      assert.equal(files.get(destination), oldValue, 'Old status must remain readable during every failed attempt');
      const error = errors[attempts - 1];

      if (error) throw error;
      files.set(to, files.get(from));
      files.delete(from);
    },
    rmSync(file) {
      assert.equal(file, temporaryPath, 'Cleanup must never delete the destination');
      removals.push(file);
      files.delete(file);
    },
    unlinkSync() {
      assert.fail('Never unlink a destination before rename');
    },
  };
  const sandbox = {
    module: { exports: {} },
    process: { platform, pid: 1234 },
    Atomics: { wait: (_array, _index, _expected, delay) => waits.push(delay) },
    Int32Array,
    SharedArrayBuffer,
    require(name) {
      if (name === 'fs') return fakeFs;
      if (name === 'path') return path;
      if (name === 'crypto') return { randomBytes: (size) => Buffer.alloc(size, 7) };
      throw new Error(`Unexpected module ${name}`);
    },
  };
  vm.runInNewContext(source, sandbox, { filename: sourcePath });

  return {
    write: (payload) => sandbox.module.exports.writeJsonFile(destination, payload),
    files, waits, removals, writes, destination, oldValue,
    attempts: () => attempts,
    closes: () => closes,
    temporaryPath: () => temporaryPath,
  };
}

function error(code) {
  return Object.assign(new Error(`Synthetic ${code}`), { code });
}

test('transient Windows EPERM/EACCES/EBUSY rename failures retry the same private file then atomically succeed', () => {
  for (const code of ['EPERM', 'EACCES', 'EBUSY']) {
    const writer = fakeWriter([error(code)]);
    writer.write({ state: 'completed' });
    assert.equal(writer.attempts(), 2);
    assert.equal(writer.closes(), 1);
    assert.deepEqual(writer.waits, [10]);
    assert.deepEqual(JSON.parse(writer.files.get(writer.destination)), { state: 'completed' });
    assert.equal(writer.files.has(writer.temporaryPath()), false);
    assert.deepEqual(writer.removals, []);
    assert.deepEqual(writer.writes, [17]);
  }
});

test('Windows retries are bounded to five attempts with the existing short atomic-writer backoff', () => {
  const writer = fakeWriter(['EPERM', 'EACCES', 'EBUSY', 'EPERM'].map(error));
  writer.write({ state: 'completed' });
  assert.equal(writer.attempts(), 5);
  assert.deepEqual(writer.waits, [10, 20, 30, 40]);
  assert.deepEqual(JSON.parse(writer.files.get(writer.destination)), { state: 'completed' });
});

test('exhausted Windows rename errors still throw and preserve the old destination while cleaning only own TMP', () => {
  const errors = Array.from({ length: 5 }, () => error('EPERM'));
  const writer = fakeWriter(errors);
  assert.throws(() => writer.write({ state: 'completed' }), (thrown) => thrown === errors[4]);
  assert.equal(writer.attempts(), 5);
  assert.deepEqual(writer.waits, [10, 20, 30, 40]);
  assert.equal(writer.files.get(writer.destination), writer.oldValue);
  assert.equal(writer.files.has(writer.temporaryPath()), false);
  assert.deepEqual(writer.removals, [writer.temporaryPath()]);
});

test('permanent rename errors are never retried or replaced by an unsafe direct destination write', () => {
  const failure = error('EIO');
  const writer = fakeWriter([failure]);
  assert.throws(() => writer.write({ state: 'completed' }), (thrown) => thrown === failure);
  assert.equal(writer.attempts(), 1);
  assert.deepEqual(writer.waits, []);
  assert.equal(writer.files.get(writer.destination), writer.oldValue);
  assert.deepEqual(writer.writes, [17]);
  assert.deepEqual(writer.removals, [writer.temporaryPath()]);
});

test('non-Windows rename errors retain immediate failure without broadening retry semantics', () => {
  const failure = error('EPERM');
  const writer = fakeWriter([failure], 'linux');
  assert.throws(() => writer.write({ state: 'completed' }), (thrown) => thrown === failure);
  assert.equal(writer.attempts(), 1);
  assert.deepEqual(writer.waits, []);
  assert.equal(writer.files.get(writer.destination), writer.oldValue);
});

test('the real writer replaces an existing file atomically and leaves no temporary files', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'workflow-runtime-atomic-'));
  const file = path.join(directory, 'status.json');
  const { writeJsonFile } = require(sourcePath);

  try {
    writeJsonFile(file, { state: 'running' });
    writeJsonFile(file, { state: 'completed' });
    assert.deepEqual(JSON.parse(fs.readFileSync(file, 'utf8')), { state: 'completed' });
    assert.deepEqual(fs.readdirSync(directory), ['status.json']);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
