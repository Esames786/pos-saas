/**
 * PRINTER-HEALTH-1 hardening — per-printer lock + honest reboot.
 *
 * Proves the two agent-side audit fixes without any real printer:
 *   1. withPrinterLock serialises everything for ONE printer (FIFO) while letting DIFFERENT printers
 *      run fully in parallel — so a Test/Reboot command can never open a second socket to, or
 *      power-cycle, a printer that is mid-ticket.
 *   2. A remote command waits behind an in-flight print on the SAME printer, but not on another.
 *   3. rebootPrinter reports success ONLY on an explicit 2xx — a 404 (no such endpoint on this model)
 *      is a failure, never a false "reboot sent".
 *
 * Run:  node test/lane-lock-test.js
 */

const http   = require('http');
const assert = require('assert');
const agent  = require('../print-agent.js');

let passed = 0;
const ok   = (m) => { passed++; console.log(`  ✓ ${m}`); };
const head = (t) => console.log(`\n── ${t}`);
const listen = (s) => new Promise((r) => s.listen(0, '127.0.0.1', () => r(s.address().port)));
const delay  = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
    /* ═══ 1. same printer → serial FIFO; different printers → parallel ═══ */
    head('Per-printer lock: same printer serial (FIFO), different printers parallel');
    {
        const order = [];
        const op = (tag, ms) => () => new Promise((r) => setTimeout(() => { order.push(tag); r(); }, ms));

        // Same key: A is slow (40ms) and queued first, B is fast (1ms). B must still wait for A.
        await Promise.all([
            agent.withPrinterLock('printerA', op('A', 40)),
            agent.withPrinterLock('printerA', op('B', 1)),
        ]);
        assert.deepStrictEqual(order, ['A', 'B'], 'same printer runs strictly in submission order');
        ok('same printer: slow-first, fast-second still ran A then B (no socket overlap)');

        // Different keys: C slow on one printer, D fast on another → D finishes first (parallel).
        order.length = 0;
        await Promise.all([
            agent.withPrinterLock('printerC', op('C', 40)),
            agent.withPrinterLock('printerD', op('D', 1)),
        ]);
        assert.deepStrictEqual(order, ['D', 'C'], 'different printers do not block each other');
        ok('different printers: fast one finished while the slow one was still working (parallel)');
    }

    /* ═══ 2. a command waits behind a live print on the SAME printer only ═══ */
    head('A Test/Reboot command never runs while its printer is printing');
    {
        const events = [];
        let releasePrint;
        const printHold = new Promise((r) => { releasePrint = r; });

        // A "print" grabs printer P1 and holds it.
        const printOp = agent.withPrinterLock('P1', () => printHold.then(() => events.push('print-done')));
        // A command targets the SAME printer — must wait.
        const cmdSame = agent.withPrinterLock('P1', () => { events.push('cmd-P1'); });
        // A command targets a DIFFERENT printer — must proceed immediately.
        const cmdOther = agent.withPrinterLock('P2', () => { events.push('cmd-P2'); });

        await delay(20);
        assert.deepStrictEqual(events, ['cmd-P2'], 'the other printer\'s command ran; P1\'s command waited for the print');
        releasePrint();
        await Promise.all([printOp, cmdSame, cmdOther]);
        assert.deepStrictEqual(events, ['cmd-P2', 'print-done', 'cmd-P1'], 'P1 command ran only after the print released the lock');
        ok('command on the busy printer waited for the ticket; command on another printer did not');
    }

    /* ═══ 3. reboot success is 2xx-only ═══ */
    head('Reboot reports success only on an explicit 2xx (a 404 is not "reboot sent")');
    {
        const okServer = http.createServer((req, res) => { res.writeHead(200); res.end('ok'); });
        const okPort   = await listen(okServer);
        const r1 = await agent.rebootPrinter('127.0.0.1', okPort);
        assert.strictEqual(r1.ok, true, 'HTTP 200 on /reboot → success');
        okServer.close();

        const nfServer = http.createServer((req, res) => { res.writeHead(404); res.end(); });
        const nfPort   = await listen(nfServer);
        const r2 = await agent.rebootPrinter('127.0.0.1', nfPort);
        assert.strictEqual(r2.ok, false, 'HTTP 404 must NOT be reported as a successful reboot');
        nfServer.close();

        ok(`reboot: 200 → ok (${r1.detail}), 404 → not ok (${r2.detail})`);
    }

    console.log(`\n✅ ALL PASSED (${passed} assertions) · agent v${agent.AGENT_VERSION}\n`);
    process.exit(0);
}

main().catch((e) => { console.error('\n❌ FAILED:', e.message); console.error(e.stack); process.exit(1); });
