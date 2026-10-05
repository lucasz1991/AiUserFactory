'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

function normalizeText(value) {
  return String(value || '').trim();
}

function ensureDirectory(directoryPath) {
  if (!directoryPath) {
    return directoryPath;
  }

  fs.mkdirSync(directoryPath, { recursive: true });

  return directoryPath;
}

function readJsonFile(filePath, fallback = {}) {
  try {
    const raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function writeJsonFile(filePath, payload) {
  ensureDirectory(path.dirname(filePath));
  const temporaryPath = `${filePath}.${process.pid}.${crypto.randomBytes(8).toString('hex')}.tmp`;
  let descriptor = null;

  try {
    descriptor = fs.openSync(temporaryPath, 'wx', 0o600);
    fs.writeFileSync(descriptor, JSON.stringify(payload, null, 2), 'utf8');
    fs.closeSync(descriptor);
    descriptor = null;
    for (let attempt = 0; attempt < 5; attempt += 1) {
      try {
        fs.renameSync(temporaryPath, filePath);
        break;
      } catch (error) {
        // Windows readers can briefly deny shared-delete access. Retry only
        // that transient case, preserving the destination until atomic rename.
        if (
          process.platform !== 'win32'
          || !['EPERM', 'EACCES', 'EBUSY'].includes(error?.code)
          || attempt === 4
        ) {
          throw error;
        }

        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10 * (attempt + 1));
      }
    }
  } catch (error) {
    if (descriptor !== null) {
      fs.closeSync(descriptor);
    }
    fs.rmSync(temporaryPath, { force: true });
    throw error;
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

function truncateText(value, limit) {
  const text = normalizeText(value);

  return text.length > limit ? `${text.slice(0, limit)}... [truncated ${text.length - limit} chars]` : text;
}

module.exports = {
  normalizeText,
  ensureDirectory,
  readJsonFile,
  writeJsonFile,
  sleep,
  truncateText,
};
