'use strict';

const crypto = require('node:crypto');
const dns = require('node:dns').promises;
const net = require('node:net');

class RecorderError extends Error {
  constructor(code, message, status = 422) {
    super(message);
    this.name = 'RecorderError';
    this.code = code;
    this.status = status;
  }
}

function authorized(header, secret) {
  const candidate = Buffer.from(String(header || '').replace(/^Bearer /, ''));
  const expected = Buffer.from(String(secret || ''));
  return /^Bearer /.test(String(header || '')) && expected.length >= 32
    && candidate.length === expected.length && crypto.timingSafeEqual(candidate, expected);
}

function isPublicAddress(address) {
  const normalized = String(address || '').toLowerCase().replace(/^\[|\]$/g, '');
  const kind = net.isIP(normalized);
  if (kind === 4) {
    const [a, b, c] = normalized.split('.').map(Number);
    return !(a === 0 || a === 10 || a === 127 || a >= 224
      || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254)
      || (a === 172 && b >= 16 && b <= 31) || (a === 192 && (b === 168 || (b === 0 && (c === 0 || c === 2)) || (b === 88 && c === 99)))
      || (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100)))
      || (a === 203 && b === 0 && c === 113));
  }
  // Permit global unicast only, not mapped IPv4, transition mechanisms or documentation blocks.
  const [first, second = '0'] = normalized.split(':');
  const secondNumber = parseInt(second || '0', 16);
  return kind === 6 && /^[23][0-9a-f]{3}:/.test(normalized)
    && !(first === '2001' && (secondNumber < 0x200 || secondNumber === 0xdb8))
    && first !== '2002' && !(first === '3fff' && secondNumber <= 0xfff);
}

function isLoopback(address) {
  return address === '::1' || /^127\./.test(address);
}

function parseNavigationUrl(value) {
  let url;
  try { url = new URL(String(value || '')); } catch { throw new RecorderError('invalid_url', 'Bitte eine vollstaendige HTTP(S)-URL angeben.'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || !url.hostname) {
    throw new RecorderError('unsafe_url', 'Nur HTTP(S)-URLs ohne Zugangsdaten sind erlaubt.');
  }
  if (url.href.length > 4096) throw new RecorderError('invalid_url', 'Die URL ist zu lang.');
  return url;
}

async function resolvePublicTarget(value, { testingLocalHosts = false, lookup = dns.lookup } = {}) {
  const url = parseNavigationUrl(value);
  const hostname = url.hostname.replace(/^\[|\]$/g, '').toLowerCase();
  const localHost = hostname === 'localhost' || isLoopback(hostname);
  if (/(?:^|\.)(?:localhost|local|internal)$/.test(hostname) && !(testingLocalHosts && localHost)) {
    throw new RecorderError('private_target', 'Private oder lokale Netzwerkziele sind nicht erlaubt.');
  }
  let addresses;
  try {
    addresses = net.isIP(hostname) ? [{ address: hostname, family: net.isIP(hostname) }]
      : await lookup(hostname, { all: true, verbatim: true });
  } catch { throw new RecorderError('unresolved_target', 'Das Netzwerkziel konnte nicht sicher aufgeloest werden.'); }
  if (!addresses.length || addresses.some(({ address }) => !isPublicAddress(address)
    && !(testingLocalHosts && localHost && isLoopback(address)))) {
    throw new RecorderError('private_target', 'Private, reservierte oder lokale Netzwerkziele sind nicht erlaubt.');
  }
  return { url, hostname, address: addresses[0].address, family: addresses[0].family,
    port: Number(url.port || (url.protocol === 'https:' ? 443 : 80)) };
}

function point(input, viewport) {
  const x = Number(input?.x); const y = Number(input?.y);
  if (!Number.isFinite(x) || !Number.isFinite(y) || x < 0 || y < 0
    || x >= viewport.width || y >= viewport.height) {
    throw new RecorderError('invalid_point', 'Die Position liegt ausserhalb des Browserfensters.');
  }
  return { x: Math.min(viewport.width - 1, Math.round(x)), y: Math.min(viewport.height - 1, Math.round(y)) };
}

function inputBinding(command, sensitive = false) {
  const source = String(command.source || 'literal');
  if (!['fixed', 'workflow_variable', 'literal'].includes(source)) {
    throw new RecorderError('invalid_binding', 'Die Eingabequelle ist ungueltig.');
  }
  if (sensitive && source === 'literal') {
    throw new RecorderError('sensitive_literal', 'Sensible Felder benoetigen eine Personen-/Systemquelle oder Workflow-Variable.');
  }
  if (source === 'workflow_variable') {
    const variable = String(command.workflow_variable || '');
    if (!/^[A-Za-z_][A-Za-z0-9_.-]{0,159}$/.test(variable)) {
      throw new RecorderError('invalid_binding', 'Bitte einen gueltigen Workflow-Variablennamen angeben.');
    }
    return { source, workflow_variable: variable };
  }
  const value = String(command.value ?? '');
  if (value.length > 8192) throw new RecorderError('invalid_binding', 'Der Eingabewert ist zu lang.');
  if (source === 'fixed' && !/^(?:person|account|email_account|verificationMailbox|verification_mailbox|workflow)\.[A-Za-z0-9_.-]+$/.test(value)
    && !['new_mail_address', 'new_mail_username', 'generated_password', 'verification_code'].includes(value)) {
    throw new RecorderError('invalid_binding', 'Personen-/Systemdaten muessen als Datenpfad gebunden werden.');
  }
  return { source, value };
}

module.exports = { RecorderError, authorized, inputBinding, isLoopback, isPublicAddress,
  parseNavigationUrl, point, resolvePublicTarget };
