/**
 * v2.5.0 health + remote-command test for the Bingoo Print Agent.
 *
 * No real printers, no cloud. Spins up a fake cloud (heartbeat / commands / result) and fake TCP
 * printers (one alive, one on a closed port), then drives the real agent module and asserts:
 *   1. STATUS   — keep-awake pokes are reported in the heartbeat with reachable + latency.
 *   2. PING     — a "ping" command tests the printer's port and reports reachable / unreachable.
 *   3. REBOOT   — a "reboot" command runs and reports a result (no web module here → graceful fail).
 *
 * Run:  node test/health-commands-test.js
 */

const http   = require('http');
const net    = require('net');
const assert = require('assert');

const agent = require('../print-agent.js');

let passed = 0;
const ok = (m) => { passed++; console.log(`  ✓ ${m}`); };
const head = (t) => console.log(`\n── ${t}`);

function startAlivePrinter() {
    const server = net.createServer((s) => { s.on('error', () => {}); s.on('data', () => {}); s.on('end', () => s.destroy()); });
    return new Promise((res) => server.listen(0, '127.0.0.1', () => res({ server, port: server.address().port })));
}
function reservedClosedPort() {
    const tmp = net.createServer();
    return new Promise((res) => tmp.listen(0, '127.0.0.1', () => { const p = tmp.address().port; tmp.close(() => res(p)); }));
}

function startCloud() {
    let heartbeatBody = null;
    let commandQueue = [];          // handed out once, then empty
    const results = {};             // id -> result payload
    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const send = (o) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
            const url = req.url;
            if (url.endsWith('/api/print-agent/heartbeat')) { heartbeatBody = JSON.parse(body || '{}'); return send({ ok: true }); }
            if (url.endsWith('/api/print-agent/commands')) { const q = commandQueue; commandQueue = []; return send({ ok: true, commands: q }); }
            let m;
            if ((m = url.match(/\/api\/print-agent\/commands\/(.+)\/result$/))) { results[m[1]] = JSON.parse(body || '{}'); return send({ ok: true }); }
            res.writeHead(404); res.end('{}');
        });
    });
    return new Promise((res) => server.listen(0, '127.0.0.1', () => res({
        server,
        baseUrl: `http://127.0.0.1:${server.address().port}`,
        queue: (c) => { commandQueue = c; },
        heartbeat: () => heartbeatBody,
        results,
    })));
}

async function main() {
    const cloud = await startCloud();
    const alive = await startAlivePrinter();
    const deadPort = await reservedClosedPort();

    agent._setConfig({ baseUrl: cloud.baseUrl, agentCode: 'AG-TEST', token: 't', pollMs: 3000, source: 'test' });
    agent.syncKnownPrinters([
        { id: 1, ip: '127.0.0.1', port: alive.port },
        { id: 2, ip: '127.0.0.1', port: deadPort },
    ]);

    /* ═══ 1. STATUS via heartbeat ═══ */
    head('Live status — keep-awake pokes reported in the heartbeat');
    await agent.keepPrintersAwake();
    await agent.heartbeat();
    const st = cloud.heartbeat()?.printers_status || [];
    const s1 = st.find((s) => s.id === 1);
    const s2 = st.find((s) => s.id === 2);
    assert.ok(s1 && s1.reachable === true, 'alive printer must report reachable');
    assert.ok(typeof s1.latency_ms === 'number', 'alive printer must report a latency');
    assert.ok(s2 && s2.reachable === false, 'closed-port printer must report unreachable');
    ok(`heartbeat carried status: printer#1 online (${s1.latency_ms}ms), printer#2 offline`);

    /* ═══ 2. PING command ═══ */
    head('Test button — a "ping" command probes the printer and reports back');
    cloud.queue([
        { id: 'c-ping-ok',   type: 'ping', printer: { id: 1, ip: '127.0.0.1', port: alive.port } },
        { id: 'c-ping-dead', type: 'ping', printer: { id: 2, ip: '127.0.0.1', port: deadPort } },
    ]);
    await agent.commandTick();
    assert.strictEqual(cloud.results['c-ping-ok']?.status, 'done', 'ping to alive printer → done');
    assert.strictEqual(cloud.results['c-ping-ok']?.result, 'reachable', 'ping result reachable');
    assert.ok(typeof cloud.results['c-ping-ok']?.latency_ms === 'number', 'ping carries latency');
    assert.strictEqual(cloud.results['c-ping-dead']?.status, 'failed', 'ping to closed port → failed');
    ok(`ping: alive → done/reachable (${cloud.results['c-ping-ok'].latency_ms}ms), dead → failed (${cloud.results['c-ping-dead'].result})`);

    /* ═══ 3. REBOOT command ═══ */
    head('Reboot button — a "reboot" command runs and reports a result');
    cloud.queue([{ id: 'c-reboot', type: 'reboot', printer: { id: 2, ip: '127.0.0.1', port: deadPort } }]);
    await agent.commandTick();
    assert.ok(cloud.results['c-reboot'], 'reboot command must post a result');
    assert.ok(['done', 'failed'].includes(cloud.results['c-reboot'].status), 'reboot result has a status');
    ok(`reboot ran and reported: ${cloud.results['c-reboot'].status} — "${cloud.results['c-reboot'].result}" (no web module in test → graceful fail)`);

    cloud.server.close(); alive.server.close();
    console.log(`\n✅ ALL PASSED (${passed} assertions) · agent v${agent.AGENT_VERSION}\n`);
    process.exit(0);
}

main().catch((e) => { console.error('\n❌ FAILED:', e.message); console.error(e.stack); process.exit(1); });
