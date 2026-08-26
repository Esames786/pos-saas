/**
 * Per-printer isolation + circuit-breaker test for the Bingoo Print Agent (v2.4.0).
 *
 * No real printers, no cloud. Spins up:
 *   - a fake cloud HTTP server (heartbeat / pending / printed / failed),
 *   - a fake ALIVE thermal printer (accepts a connection, reads the ticket),
 *   - a fake DEAD thermal printer (accepts, then drops the socket after a delay).
 *
 * Then drives the real agent module and asserts the three properties the v2.4.0
 * change exists to guarantee:
 *
 *   1. CORRECTNESS — receipt, KOT and reminder all reach a healthy printer; a
 *      dead printer's jobs are PARKED (deferred / re-queued), never lost.
 *   2. ISOLATION   — the healthy printer's tickets finish FAST even when a dead
 *      printer is in the same batch AHEAD of them. Under the old serial code
 *      they would have queued behind the dead printer's ~seconds-long stalls.
 *   3. BREAKER     — after repeated failures a dead printer is skipped (its jobs
 *      fast-deferred with NO further connection attempts) until a cooldown lapses.
 *
 * Run:  node test/isolation-test.js
 */

const http   = require('http');
const net    = require('net');
const assert = require('assert');

const agent = require('../print-agent.js');

/* ── tiny test log ────────────────────────────────────────────────────── */
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let passed = 0;
function ok(msg)  { passed++; console.log(`  ✓ ${msg}`); }
function head(t)  { console.log(`\n── ${t}`); }

/* ── fake ALIVE printer: reads the ticket, records payload + arrival time ── */
function startAlivePrinter() {
    const received = [];   // { payload, at }
    const server = net.createServer((socket) => {
        let buf = '';
        socket.on('data', (d) => { buf += d.toString('utf8'); });
        socket.on('end',  () => { received.push({ payload: buf, at: Date.now() }); socket.destroy(); });
        socket.on('error', () => {});
    });
    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => {
            resolve({ server, received, port: server.address().port });
        });
    });
}

/* ── DEAD printer = a port with nothing listening (real "printer offline"):
 *    connect is REFUSED, which is exactly the ECONNREFUSED the live shop saw.
 *    Each ticket burns three quick attempts + backoff (~1.6s) before failing —
 *    slow enough to prove the healthy lane runs alongside it, not behind it. ── */
function reservedClosedPort() {
    // Bind to grab a free port, then close it so subsequent connects are refused.
    const tmp = net.createServer();
    return new Promise((resolve) => {
        tmp.listen(0, '127.0.0.1', () => {
            const port = tmp.address().port;
            tmp.close(() => resolve(port));
        });
    });
}

/* ── fake cloud: minimal print-agent API over the queued-jobs registry ───── */
function startCloud() {
    const jobs = new Map();   // id -> { ...job, state }
    const events = [];        // { id, action: 'printed'|'failed', message, at }

    const server = http.createServer((req, res) => {
        const send = (obj) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const url = req.url;
            if (url.endsWith('/api/print-agent/heartbeat')) return send({ ok: true });
            if (url.endsWith('/api/print-agent/pending')) {
                const queued = [...jobs.values()].filter((j) => j.state === 'queued');
                return send({ jobs: queued });
            }
            let m;
            if ((m = url.match(/\/api\/print-agent\/jobs\/(.+)\/printed$/))) {
                const j = jobs.get(m[1]); if (j) j.state = 'printed';
                events.push({ id: m[1], action: 'printed', at: Date.now() });
                return send({ ok: true });
            }
            if ((m = url.match(/\/api\/print-agent\/jobs\/(.+)\/failed$/))) {
                const j = jobs.get(m[1]); if (j) j.state = 'failed';
                let msg = ''; try { msg = JSON.parse(body || '{}').error_message || ''; } catch (_) {}
                events.push({ id: m[1], action: 'failed', message: msg, at: Date.now() });
                return send({ ok: true });
            }
            if ((m = url.match(/\/api\/print-agent\/jobs\/(.+)\/defer$/))) {
                // PRINTER-HEALTH-1: an unreachable printer's ticket is PARKED (re-queued), never failed.
                const j = jobs.get(m[1]); if (j) j.state = 'deferred';
                let reason = ''; try { reason = JSON.parse(body || '{}').reason || ''; } catch (_) {}
                events.push({ id: m[1], action: 'deferred', message: reason, at: Date.now() });
                return send({ ok: true });
            }
            res.writeHead(404); res.end('{}');
        });
    });

    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => {
            resolve({
                server,
                baseUrl: `http://127.0.0.1:${server.address().port}`,
                load:    (list) => { jobs.clear(); for (const j of list) jobs.set(j.id, { ...j, state: 'queued' }); },
                add:     (j) => jobs.set(j.id, { ...j, state: 'queued' }),
                state:   (id) => (jobs.get(id) || {}).state,
                events,
            });
        });
    });
}

function job(id, docType, printer) {
    return {
        id:           String(id),
        job_no:       `JOB-${id}`,
        document_type: docType,
        raw_payload:  `*** ${docType.toUpperCase()} #${id} ***`,
        printer,
    };
}

function netPrinter(idNum, ip, port, name) {
    return { id: idNum, printer_type: 'network', ip_address: ip, port, name };
}

async function main() {
    const cloud    = await startCloud();
    const alive    = await startAlivePrinter();
    const deadPort = await reservedClosedPort();   // refuses every connect

    agent._setConfig({ baseUrl: cloud.baseUrl, agentCode: 'AG-TEST', token: 't', pollMs: 3000, source: 'test' });

    const alivePrinter = netPrinter(1, '127.0.0.1', alive.port, 'Alive Counter');
    const deadPrinter  = netPrinter(2, '127.0.0.1', deadPort,   'Dead Counter');

    /* ═══ 1 + 2. Correctness & isolation ═══════════════════════════════════ */
    head('Scenario A — dead printer must not delay a healthy one (receipt/KOT/reminder)');

    // Batch ordered DEAD-FIRST on purpose: under the old serial loop the three
    // healthy tickets would have waited behind ~4.5s of dead stalls.
    cloud.load([
        job('d1', 'receipt',  deadPrinter),
        job('d2', 'kot',      deadPrinter),
        job('d3', 'reminder', deadPrinter),
        job('a1', 'receipt',  alivePrinter),
        job('a2', 'kot',      alivePrinter),
        job('a3', 'reminder', alivePrinter),
    ]);
    agent._resetHealth();

    const t0 = Date.now();
    await agent.tick();
    const tickMs = Date.now() - t0;

    // Healthy printer got all three document types.
    const alivePayloads = alive.received.map((r) => r.payload);
    assert.strictEqual(alive.received.length, 3, `alive printer should receive 3 tickets, got ${alive.received.length}`);
    for (const dt of ['RECEIPT', 'KOT', 'REMINDER']) {
        assert.ok(alivePayloads.some((p) => p.includes(dt)), `alive printer missing ${dt}`);
    }
    ok('receipt, KOT and reminder all reached the healthy printer');

    // …and they arrived FAST — not serialised behind the dead printer's ~4.5s.
    const lastAliveArrival = Math.max(...alive.received.map((r) => r.at)) - t0;
    assert.ok(lastAliveArrival < 1500,
        `healthy tickets should land <1500ms (parallel lanes); took ${lastAliveArrival}ms — dead lane blocked them`);
    ok(`healthy tickets landed in ${lastAliveArrival}ms while the dead lane stalled (tick total ${tickMs}ms) — isolated`);

    // Healthy jobs printed; dead jobs PARKED (deferred) so they reprint on recovery — never lost.
    for (const id of ['a1', 'a2', 'a3']) assert.strictEqual(cloud.state(id), 'printed',  `${id} should be printed`);
    for (const id of ['d1', 'd2', 'd3']) assert.strictEqual(cloud.state(id), 'deferred', `${id} should be deferred (re-queued), not lost`);
    ok('healthy jobs → printed, dead jobs → deferred (re-queued, never permanently failed)');

    /* ═══ 3. Circuit breaker ═══════════════════════════════════════════════ */
    head('Scenario B — a persistently dead printer gets skipped (circuit breaker)');

    // tick() schedules a 250ms follow-up drain; let it fire on an EMPTY queue so
    // it cannot interleave with the direct processLane() calls below.
    cloud.load([]);
    await sleep(400);

    agent._resetHealth();
    const deadKey = `127.0.0.1:${deadPort}`;

    // Two failing lanes arm the breaker (threshold = 2). A REAL attempt burns
    // retries + backoff, so time it to contrast with the skipped one below.
    cloud.add(job('b1', 'receipt', deadPrinter));
    const ta = Date.now();
    await agent.processLane(deadKey, [job('b1', 'receipt', deadPrinter)]);
    const attemptMs = Date.now() - ta;
    cloud.add(job('b2', 'receipt', deadPrinter));
    await agent.processLane(deadKey, [job('b2', 'receipt', deadPrinter)]);

    assert.ok(attemptMs > 800, `a real dead attempt should burn retries/backoff (>800ms); took ${attemptMs}ms`);
    assert.strictEqual(agent._printerHealth.get(deadKey).fails >= agent.CB_FAILURE_THRESHOLD, true,
        'breaker should be armed after the failure threshold');
    assert.strictEqual(cloud.state('b1'), 'deferred', 'b1 parked (re-queued), not failed');
    assert.strictEqual(cloud.state('b2'), 'deferred', 'b2 parked (re-queued), not failed');
    ok(`breaker armed after ${agent.CB_FAILURE_THRESHOLD} failing ticks (each real attempt ~${attemptMs}ms)`);

    // Third lane while cooling: fast-failed with NO connection attempt — proven
    // by the time collapsing from ~${attemptMs}ms to well under it.
    cloud.add(job('b3', 'receipt', deadPrinter));
    const t1 = Date.now();
    await agent.processLane(deadKey, [job('b3', 'receipt', deadPrinter)]);
    const coolMs = Date.now() - t1;

    assert.ok(coolMs < 300, `a cooling lane should fast-defer (<300ms, no connect); took ${coolMs}ms`);
    assert.strictEqual(cloud.state('b3'), 'deferred', 'the held ticket is parked (re-queued) so it reprints on recovery — never lost');
    ok(`cooling printer skipped: job parked in ${coolMs}ms (vs ~${attemptMs}ms for a real attempt) — no connect made`);

    /* ── recovery: once the cooldown lapses the printer is probed again ───── */
    head('Scenario C — breaker recovers when the printer answers again');

    agent._resetHealth();
    // Point a lane at the ALIVE printer but pre-arm its health as if it had been
    // down and its cooldown has already lapsed → it must probe and then close.
    const aliveKey = `127.0.0.1:${alive.port}`;
    agent._printerHealth.set(aliveKey, { fails: agent.CB_FAILURE_THRESHOLD, cooldownUntil: Date.now() - 1 });
    const priorReceived = alive.received.length;
    cloud.add(job('c1', 'receipt', alivePrinter));
    await agent.processLane(aliveKey, [{ ...job('c1', 'receipt', alivePrinter) }]);

    assert.strictEqual(alive.received.length, priorReceived + 1, 'a lapsed-cooldown printer must be probed, not skipped');
    assert.strictEqual(cloud.state('c1'), 'printed', 'the probe ticket printed');
    assert.strictEqual(agent._printerHealth.get(aliveKey).fails, 0, 'a successful probe closes the breaker');
    ok('cooldown lapsed → printer probed → ticket printed → breaker closed');

    /* ── done ─────────────────────────────────────────────────────────────── */
    cloud.server.close(); alive.server.close();
    console.log(`\n✅ ALL PASSED (${passed} assertions)\n`);
    process.exit(0);
}

main().catch((err) => {
    console.error('\n❌ TEST FAILED:', err.message);
    console.error(err.stack);
    process.exit(1);
});
