'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { Readable } = require('node:stream');
const { authorized, inputBinding, isPublicAddress, parseNavigationUrl, point, resolvePublicTarget } = require('../../node/recorder/security.cjs');
const { MAX_BODY_BYTES, readBody } = require('../../node/recorder/serve.cjs');

test('bearer authorization is exact and rejects short or missing secrets', () => {
  const secret = 's'.repeat(48);
  assert.equal(authorized(`Bearer ${secret}`, secret), true);
  for (const candidate of ['', `bearer ${secret}`, `Bearer ${secret}x`, secret, `Basic ${secret}`]) {
    assert.equal(authorized(candidate, secret), false);
  }
  assert.equal(authorized('Bearer short', 'short'), false);
});

test('private, link-local, documentation and mapped IPv6 addresses are failclosed', () => {
  for (const ip of ['0.0.0.0', '10.0.0.1', '127.0.0.1', '100.64.0.1', '169.254.169.254', '172.31.1.1',
    '192.168.1.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
    '::', '::1', '::ffff:127.0.0.1', '::ffff:7f00:1', 'fc00::1', 'fe80::1', '2001:db8::1', '2001:0db8::1',
    '2002:7f00:1::', '2001:0:1234::1', '2001:2::1', '3fff::1', '3fff:0fff::1']) {
    assert.equal(isPublicAddress(ip), false, ip);
  }
  for (const ip of ['8.8.8.8', '1.1.1.1', '192.0.78.24', '2606:4700:4700::1111', '2001:4860:4860::8888']) assert.equal(isPublicAddress(ip), true, ip);
});

test('URL policy accepts HTTP(S) only and never accepts embedded credentials', () => {
  assert.equal(parseNavigationUrl('https://example.test/path').protocol, 'https:');
  for (const value of ['javascript:alert(1)', 'file:///etc/passwd', 'data:text/html,hello', 'ftp://example.test/',
    'https://user:password@example.test/', 'not a URL']) assert.throws(() => parseNavigationUrl(value));
});

test('DNS checks all answers and pins only an entirely public address set', async () => {
  const target = await resolvePublicTarget('https://example.test/', { lookup: async () => [{ address: '8.8.8.8', family: 4 }] });
  assert.equal(target.address, '8.8.8.8'); assert.equal(target.hostname, 'example.test'); assert.equal(target.port, 443);
  await assert.rejects(resolvePublicTarget('https://example.test/', { lookup: async () => [
    { address: '8.8.8.8', family: 4 }, { address: '127.0.0.1', family: 4 },
  ] }), { code: 'private_target' });
  await assert.rejects(resolvePublicTarget('http://2130706433/'), { code: 'private_target' });
  await assert.rejects(resolvePublicTarget('http://[::ffff:127.0.0.1]/'), { code: 'private_target' });
});

test('isolated testing exception permits exact localhost only and remains off by default', async () => {
  await assert.rejects(resolvePublicTarget('http://127.0.0.1:8722/'), { code: 'private_target' });
  assert.equal((await resolvePublicTarget('http://127.0.0.1:8722/', { testingLocalHosts: true })).port, 8722);
  const local = await resolvePublicTarget('http://localhost/', { testingLocalHosts: true,
    lookup: async () => [{ address: '::1', family: 6 }] });
  assert.equal(local.address, '::1');
  await assert.rejects(resolvePublicTarget('http://localhost/', { testingLocalHosts: true,
    lookup: async () => [{ address: '192.168.1.1', family: 4 }] }), { code: 'private_target' });
  await assert.rejects(resolvePublicTarget('http://server.local/', { testingLocalHosts: true }), { code: 'private_target' });
  await assert.rejects(resolvePublicTarget('http://192.168.1.1/', { testingLocalHosts: true }), { code: 'private_target' });
});

test('sensitive values must be reference bindings, not typed literals', () => {
  assert.throws(() => inputBinding({ source: 'literal', value: 'not-allowed' }, true), { code: 'sensitive_literal' });
  assert.deepEqual(inputBinding({ source: 'workflow_variable', workflow_variable: 'workflow_inputs.password', previewValue: 'ephemeral' }, true),
    { source: 'workflow_variable', workflow_variable: 'workflow_inputs.password' });
  assert.deepEqual(inputBinding({ source: 'fixed', value: 'person.loginPassword', previewValue: 'ephemeral' }, true),
    { source: 'fixed', value: 'person.loginPassword' });
  assert.throws(() => inputBinding({ source: 'fixed', value: 'actual-secret' }, true), { code: 'invalid_binding' });
  assert.throws(() => inputBinding({ source: 'workflow_variable', workflow_variable: 'invalid variable' }), { code: 'invalid_binding' });
  for (const value of ['new_mail_address', 'new_mail_username', 'generated_password', 'verification_code']) {
    assert.equal(inputBinding({ source: 'fixed', value }, true).value, value);
  }
});

test('body and coordinate boundaries reject oversized or out-of-frame commands', async () => {
  assert.deepEqual(await readBody(Readable.from([Buffer.from('{"type":"pause"}')])), { type: 'pause' });
  await assert.rejects(readBody(Readable.from([Buffer.alloc(MAX_BODY_BYTES + 1)])), { code: 'body_too_large' });
  await assert.rejects(readBody(Readable.from([Buffer.from('bad')])), { code: 'invalid_json' });
  assert.deepEqual(point({ x: 1279.9, y: 799.9 }, { width: 1280, height: 800 }), { x: 1279, y: 799 });
  for (const x of [-1, Infinity, NaN, 1280]) assert.throws(() => point({ x, y: 0 }, { width: 1280, height: 800 }));
});
