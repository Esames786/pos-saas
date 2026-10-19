/**
 * AGENT-ONE-EXE-1 — ek hi .exe, restaurant aur catering dono ke liye.
 *
 * ── MALIK KA SAWAL, 4 OCTOBER ─────────────────────────────────────────────
 *
 * "Tum 2.6 upload kar rahe ho — to ye restaurant aur catering DONO pe kaam
 *  karega na, agar ek he exe daal rahe ho?"
 *
 * Jawab "haan" kehna kaafi nahi tha. Baqi saare test SOURCE par chalte hain
 * (`require('../print-agent.js')`), magar client ke PC par jo cheez jati hai wo
 * BINARY hai. Ye test wohi binary chalata hai jo upload hogi.
 *
 * Do baatein ek saath sabit hoti hain:
 *
 *   1. THERMAL ABHI BHI CHALTA HAI. Naya exe ek purana KOT ticket uthata hai
 *      aur ESC/POS bytes network printer par bhejta hai — bilkul 2.5.0 ki
 *      tarah. Restaurant ka kaam isi raaste par hai; agar ye toot jaye to ek
 *      upload chaar chalte hue karobar band kar deta.
 *
 *   2. NAYI SALAHIYAT SAATH CHALTI HAI. Wohi exe `X-Print-Agent-Caps: document`
 *      bhejta hai, jis se server usay A4/A5 document bhi de sakta hai. Yani do
 *      alag agent ki zarurat nahi — ek hi binary dono kaam karta hai.
 *
 * ── MEHFOOZ TAREEQA ────────────────────────────────────────────────────────
 *
 * Is machine par asli agent (v2.5.0) service ki tarah chal raha hai aur us ki
 * config `%ProgramData%` me hai. Use CHHUA NAHI jata: exe ko env vars se chalaya
 * jata hai, jo `loadConfig()` me sab se pehle jeette hain. Naqli server aur
 * naqli printer dono 127.0.0.1 par hain.
 *
 * Run:  node test/exe-thermal-test.js
 */

const http   = require('http');
const net    = require('net');
const path   = require('path');
const fs     = require('fs');
const assert = require('assert');
const { spawn } = require('child_process');

const EXE = path.join(__dirname, '..', 'dist', 'BingooPrintAgent.exe');
const TICKET = 'KOT-TEST-PAYLOAD-\u001B@\u001DVB\u0000';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let passed = 0;
const ok = (m) => { passed++; console.log(`  ✓ ${m}`); };

if (!fs.existsSync(EXE)) {
    console.log(`\n!  ${path.basename(EXE)} nahi mila — pehle banayein:`);
    console.log('   npx pkg print-agent.js --targets node18-win-x64 --output dist/BingooPrintAgent.exe\n');
    process.exit(0);
}

/** Naqli thermal printer — jo bytes aayen, jama kar lo. */
function startPrinter() {
    const got = [];
    const srv = net.createServer((sock) => {
        let buf = '';
        sock.on('data', (d) => { buf += d.toString('binary'); });
        sock.on('close', () => { if (buf) { got.push(buf); } });
    });

    return new Promise((res) => srv.listen(0, '127.0.0.1', () => res({ srv, got, port: srv.address().port })));
}

/** Naqli cloud — ek thermal job deta hai, aur dekhta hai agent ne kya bheja. */
function startCloud(printerPort) {
    const seen = { caps: null, printed: [], polls: 0 };
    let handedOut = false;

    const srv = http.createServer((req, res) => {
        const url = req.url.split('?')[0];
        const send = (obj) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };

        if (url === '/api/print-agent/heartbeat') { return send({ ok: true }); }
        if (url === '/api/print-agent/commands')  { return send({ ok: true, commands: [] }); }

        if (url === '/api/print-agent/pending') {
            seen.polls++;
            // Agent apni salahiyat HEADER me bhejta hai — URL me nahi. Yehi
            // wo baat hai jo purane agent ko mehfooz rakhti hai.
            seen.caps = req.headers['x-print-agent-caps'] ?? null;

            if (handedOut) { return send({ ok: true, jobs: [] }); }
            handedOut = true;

            return send({ ok: true, jobs: [{
                id: 4242, job_no: 'PJ-TEST-4242',
                document_type: 'kot', reference_no: 'ORD-1',
                printer: {
                    id: 7, name: 'Kitchen — Test', printer_type: 'network',
                    ip_address: '127.0.0.1', port: printerPort, paper_size: '80mm',
                    windows_printer_name: null,
                },
                raw_payload: TICKET,
                payload: {},
            }] });
        }

        if (/\/api\/print-agent\/jobs\/\d+\/printed$/.test(url)) {
            seen.printed.push(url);

            return send({ ok: true });
        }

        res.writeHead(404); res.end('{}');
    });

    return new Promise((res) => srv.listen(0, '127.0.0.1', () => res({ srv, seen, port: srv.address().port })));
}

(async () => {
    console.log('\n── Wohi .exe jo client ke PC par jayegi: thermal + document salahiyat');

    const printer = await startPrinter();
    const cloud   = await startCloud(printer.port);

    const child = spawn(EXE, ['run'], {
        env: {
            ...process.env,
            // Env sab se pehle jeetta hai — is machine ki asli config chhui hi nahi jati.
            POS_BASE_URL:           `http://127.0.0.1:${cloud.port}`,
            POS_PRINT_AGENT_CODE:   'AG-TEST',
            POS_PRINT_AGENT_TOKEN:  'tok-test',
            POS_PRINT_POLL_MS:      '400',
        },
        windowsHide: true,
        stdio: 'ignore',
    });

    try {
        for (let i = 0; i < 60 && (printer.got.length === 0 || cloud.seen.printed.length === 0); i++) {
            await sleep(500);
        }

        assert.ok(cloud.seen.polls > 0, 'exe ko server se baat karni chahiye thi');
        ok(`exe ne server se baat ki (${cloud.seen.polls} poll)`);

        assert.strictEqual(cloud.seen.caps, 'document',
            'exe ko apni salahiyat header me batani chahiye — isi se purane agents mehfooz rehte hain');
        ok('X-Print-Agent-Caps: document — catering ka kaam is exe se ho sakta hai');

        assert.ok(printer.got.length > 0, 'thermal printer tak ticket pahunchna chahiye tha');
        assert.ok(printer.got[0].includes('KOT-TEST-PAYLOAD-'),
            `printer par wohi bytes aane chahiyen the; mila: ${JSON.stringify(printer.got[0].slice(0, 60))}`);
        ok('ESC/POS ticket network printer tak pahuncha — restaurant ka kaam waisa hi chalta hai');

        assert.ok(cloud.seen.printed.length > 0, 'agent ko "printed" wapas batana chahiye tha');
        ok('job "printed" nishan-zada hui');

        console.log(`\n✅ ALL PASSED (${passed} assertions) · ek exe, dono kaam\n`);
    } finally {
        try { child.kill('SIGKILL'); } catch {}
        printer.srv.close();
        cloud.srv.close();
    }
})().catch((err) => {
    console.error('\n❌ FAILED:', err.message, '\n');
    process.exit(1);
});
