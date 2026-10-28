// The reverse proxy in front of the server under test: the clients talk to
// nothing but HTTPS, and only the address in DOMAIN.
//
// Usage: node proxy.mjs <port> <certificate> <key> <backend> [<hub backend>]
//
// Requests go to the backend (http://host:port). WebSocket upgrades go to the
// hub backend when there is one (our notifications daemon), else to the backend
// (Vaultwarden serves its hubs itself).
import { readFileSync } from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';

const [port, certificate, key, backend, hubBackend] = process.argv.slice(2);
if (!port || !certificate || !key || !backend) {
    console.error('Usage: node proxy.mjs <port> <certificate> <key> <backend> [<hub backend>]');
    process.exit(2);
}
const target = new URL(backend);
const hubTarget = new URL(hubBackend ?? backend);

function forwardedHeaders(request) {
    return {
        ...request.headers,
        'x-forwarded-for': request.socket.remoteAddress,
        'x-forwarded-proto': 'https',
    };
}

const server = https.createServer({ cert: readFileSync(certificate), key: readFileSync(key) }, (request, response) => {
    const upstream = http.request({
        host: target.hostname,
        port: target.port,
        method: request.method,
        path: request.url,
        headers: forwardedHeaders(request),
    }, (answer) => {
        response.writeHead(answer.statusCode, answer.statusMessage, answer.rawHeaders);
        answer.pipe(response);
    });
    upstream.on('error', (error) => {
        console.error(`${request.method} ${request.url}: ${error.message}`);
        response.writeHead(502).end();
    });
    request.pipe(upstream);
});

server.on('upgrade', (request, socket, head) => {
    const upstream = net.connect(hubTarget.port, hubTarget.hostname, () => {
        const headers = forwardedHeaders(request);
        let lines = `${request.method} ${request.url} HTTP/1.1\r\n`;
        for (const [name, value] of Object.entries(headers)) {
            for (const item of Array.isArray(value) ? value : [value]) {
                lines += `${name}: ${item}\r\n`;
            }
        }
        upstream.write(`${lines}\r\n`);
        upstream.write(head);
        upstream.pipe(socket);
        socket.pipe(upstream);
    });
    upstream.on('error', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
});

server.listen(Number(port), '127.0.0.1', () => console.log(`Listening on https://127.0.0.1:${port}`));
