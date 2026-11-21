/**
 * SLIP-TOP-GAP-1 (2.7.1, 16 Nov) — no blank feed after a cut.
 *
 * Owner's photo: a reminder with ~2.4 cm of blank paper on top. The agent sent 3 line feeds
 * AFTER every ticket. Each ticket already ends with a cut (GS V 66 0 = feed to the cutter + cut),
 * so those feeds only ever landed on the top of the NEXT slip.
 *
 * The test runs the REAL sendToNetworkPrinter against a fake printer on a TCP port and checks the
 * exact bytes the printer receives:
 *   1. A ticket that ends with a cut (every form the server or a printer may use) gets nothing extra.
 *   2. A ticket WITHOUT a cut still gets the old 3 lines, so its last line still comes out.
 *   3. A cut in the MIDDLE of a ticket (several slips in one job) does not count; only the end does.
 *
 * Run:  node test/slip-top-gap-test.js
 */

const net    = require('net');
const assert = require('assert');
const agent  = require('../print-agent.js');

let passed = 0;
const ok   = (m) => { passed++; console.log(`  ✓ ${m}`); };
const head = (t) => console.log(`\n── ${t}`);

/** One fake printer: collects every byte of one connection, resolves when the agent hangs up. */
function fakePrinter() {
    const received = [];
    const server = net.createServer((socket) => {
        const chunks = [];
        socket.on('data', (d) => chunks.push(d));
        socket.on('end', () => received.push(Buffer.concat(chunks).toString('latin1')));
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port, received })));
}

async function printOnce(printer, payload) {
    const before = printer.received.length;
    await agent.sendToNetworkPrinter('127.0.0.1', printer.port, payload);
    for (let i = 0; i < 50 && printer.received.length === before; i++) {
        await new Promise((r) => setTimeout(r, 10));
    }
    return printer.received[printer.received.length - 1];
}

async function main() {
    const printer = await fakePrinter();

    head('A ticket that ends with a cut gets NO extra feed');
    const KOT_FROM_PROD = 'TABLE: 12\n' + '-'.repeat(42) + '\n\x1D\x56\x42\x00';   // KOT 200344 ended exactly so
    assert.strictEqual(await printOnce(printer, KOT_FROM_PROD), KOT_FROM_PROD,
        'the printer must receive the ticket and nothing after the cut');
    ok('GS V 66 0 (what every Bingoo slip ends with): bytes received == ticket, nothing after it');

    const cuts = {
        'GS V 0 (full cut)': '\x1D\x56\x00',
        'GS V 1 (partial cut)': '\x1D\x56\x01',
        "GS V '0'": '\x1D\x560',
        "GS V '1'": '\x1D\x561',
        'GS V 65 3 (feed + full cut)': '\x1D\x56\x41\x03',
        'GS V 66 10 (feed + partial cut)': '\x1D\x56\x42\x0A',
        'ESC i (old partial cut)': '\x1Bi',
        'ESC m (old partial cut)': '\x1Bm',
    };
    for (const [label, cut] of Object.entries(cuts)) {
        const ticket = 'HELLO\n' + cut;
        assert.strictEqual(await printOnce(printer, ticket), ticket, `${label}: nothing may follow the cut`);
    }
    ok(`every other cut form too (${Object.keys(cuts).length}): nothing sent after the cut`);

    head('A ticket WITHOUT a cut keeps the old 3 lines');
    for (const ticket of ['HELLO\n', '\x1B@', '']) {
        assert.strictEqual(await printOnce(printer, ticket), ticket + '\n\n\n',
            'no cut = the last line must still be pushed out of the printer');
    }
    ok('plain text, the ESC @ wake-up job and an empty job still get \\n\\n\\n');

    const midCut = 'SLIP 1\n\x1D\x56\x42\x00SLIP 2\n';
    assert.strictEqual(await printOnce(printer, midCut), midCut + '\n\n\n',
        'a cut in the middle is not the end of the ticket');
    ok('a cut in the MIDDLE only (several slips, last one uncut) still gets the 3 lines');

    head('trailingFeedFor agrees with what went down the wire');
    assert.strictEqual(agent.trailingFeedFor(KOT_FROM_PROD), '');
    assert.strictEqual(agent.trailingFeedFor('HELLO\n'), '\n\n\n');
    assert.strictEqual(agent.trailingFeedFor(null), '\n\n\n');
    ok('cut → "" ; no cut / null → "\\n\\n\\n"');

    printer.server.close();
    console.log(`\n${passed} checks passed.`);
}

main().catch((err) => {
    console.error('\n✗ FAILED:', err.message);
    process.exit(1);
});
