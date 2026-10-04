'use strict';

const http = require('node:http');
const net = require('node:net');
const { resolvePublicTarget } = require('./security.cjs');

// Chromium uses this proxy exclusively. DNS is checked once and the connection uses that IP.
// A later private-address DNS answer cannot redirect an already validated connection.
async function createEgressProxy(options = {}) {
  const sockets = new Set();
  const server = http.createServer(async (request, response) => {
    try {
      const target = await resolvePublicTarget(request.url, options);
      if (target.url.protocol !== 'http:') throw new Error('HTTPS requires CONNECT.');
      const headers = { ...request.headers, host: target.url.host };
      delete headers['proxy-authorization']; delete headers['proxy-connection'];
      const upstream = http.request({ hostname: target.address, family: target.family, port: target.port,
        method: request.method, path: target.url.pathname + target.url.search, headers,
        timeout: 30000, agent: false }, (incoming) => {
        response.writeHead(incoming.statusCode || 502, incoming.headers);
        incoming.pipe(response);
      });
      upstream.on('socket', (socket) => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
      upstream.on('timeout', () => upstream.destroy());
      upstream.on('error', () => { if (!response.headersSent) response.writeHead(502); response.end(); });
      request.on('aborted', () => upstream.destroy());
      request.pipe(upstream);
    } catch {
      response.writeHead(403, { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' });
      response.end('Network target blocked.');
    }
  });
  server.on('connect', async (request, client, head) => {
    try {
      if (!/^(?:\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+):\d{1,5}$/.test(request.url || '')) throw new Error('Invalid tunnel target.');
      const target = await resolvePublicTarget(`https://${request.url}/`, options);
      const upstream = net.connect({ host: target.address, family: target.family, port: target.port });
      sockets.add(upstream); upstream.on('close', () => sockets.delete(upstream));
      upstream.setTimeout(120000, () => upstream.destroy());
      upstream.once('connect', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head.length) upstream.write(head);
        client.pipe(upstream); upstream.pipe(client);
      });
      upstream.on('error', () => client.destroy());
      client.on('error', () => upstream.destroy());
      client.on('close', () => upstream.destroy());
    } catch { client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); }
  });
  server.on('connection', (socket) => {
    sockets.add(socket); socket.on('close', () => sockets.delete(socket));
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return { port: server.address().port, close: async () => {
    for (const socket of sockets) socket.destroy();
    await new Promise((resolve) => server.close(resolve));
  } };
}

module.exports = { createEgressProxy };
