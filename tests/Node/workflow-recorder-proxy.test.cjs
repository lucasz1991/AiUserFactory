'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const { createEgressProxy } = require('../../node/recorder/proxy.cjs');

function throughProxy(port, target) {
  return new Promise((resolve, reject) => {
    const request = http.get({ hostname: '127.0.0.1', port, path: target, timeout: 2000 }, (response) => {
      const chunks = []; response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString() }));
    });
    request.on('error', reject); request.on('timeout', () => request.destroy(new Error('timeout')));
  });
}

test('egress proxy blocks private HTTP and mapped IPv6 targets without reaching them', async () => {
  const proxy = await createEgressProxy();
  try {
    for (const target of ['http://127.0.0.1:8722/', 'http://169.254.169.254/', 'http://[::ffff:127.0.0.1]/']) {
      const response = await throughProxy(proxy.port, target);
      assert.equal(response.status, 403); assert.equal(response.body, 'Network target blocked.');
    }
  } finally { await proxy.close(); }
});

test('explicit isolated testing allows owned localhost HTTP and each redirect target is independently guarded', async () => {
  let requests = 0;
  const origin = http.createServer((request, response) => {
    requests += 1;
    if (request.url === '/redirect') { response.writeHead(302, { Location: 'http://169.254.169.254/' }); response.end(); }
    else response.end('owned synthetic page');
  });
  await new Promise((resolve) => origin.listen(0, '127.0.0.1', resolve));
  const proxy = await createEgressProxy({ testingLocalHosts: true });
  try {
    const url = `http://127.0.0.1:${origin.address().port}`;
    const ordinary = await throughProxy(proxy.port, `${url}/`); assert.equal(ordinary.body, 'owned synthetic page');
    const redirect = await throughProxy(proxy.port, `${url}/redirect`); assert.equal(redirect.status, 302);
    const blocked = await throughProxy(proxy.port, redirect.headers.location); assert.equal(blocked.status, 403);
    assert.equal(requests, 2);
  } finally {
    await proxy.close(); origin.closeAllConnections(); await new Promise((resolve) => origin.close(resolve));
  }
});

test('CONNECT tunnels enforce the same private-address guard before opening a socket', async () => {
  const proxy = await createEgressProxy();
  try {
    const response = await new Promise((resolve, reject) => {
      const client = net.connect({ host: '127.0.0.1', port: proxy.port }, () => {
        client.write('CONNECT 127.0.0.1:8722 HTTP/1.1\r\nHost: 127.0.0.1:8722\r\n\r\n');
      });
      client.setTimeout(2000, () => client.destroy(new Error('timeout')));
      client.once('data', (bytes) => { resolve(bytes.toString()); client.destroy(); }); client.on('error', reject);
    });
    assert.equal(response.startsWith('HTTP/1.1 403 Forbidden'), true);
  } finally { await proxy.close(); }
});
