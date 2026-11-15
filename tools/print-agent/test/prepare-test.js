/**
 * PRINT-PREPARE-1 + AGENT-UPGRADE-1 (agent 2.7.0) — screen khulte hi PDF tayyar, aur bina nayi pairing ke upgrade.
 *
 * Owner: "user sirf screen khole … agent saara kaam kar le … send dabaye to foran print … order update ho to agent
 * updated file load kare … data kisi PC pe jama na ho … purani pairing / thermal kharab na ho."
 *
 * Asli Chrome se PDF banti hai (document-print-test ki tarah) — naqli sirf server hai. Har test ek ALAG temp folder
 * me (LOCALAPPDATA / ProgramData yahin), is liye is PC ka asli agent ya config chhua nahi jata.
 *
 *   node test/prepare-test.js
 */
const fs     = require('fs');
const os     = require('os');
const path   = require('path');
const http   = require('http');
const assert = require('assert');

const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'bpa-prepare-test-'));
process.env.LOCALAPPDATA = path.join(sandbox, 'Local');
process.env.ProgramData  = path.join(sandbox, 'ProgramData');

const agent = require('../print-agent.js');

let passed = 0;
function ok(msg) { passed++; console.log(`  ✓ ${msg}`); }
function head(t) { console.log(`\n── ${t}`); }
const fp = (c) => c.repeat(64);

function startCloud() {
    const results = [];   // { id, status }
    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            const m = req.url.match(/\/api\/print-agent\/prepares\/(\d+)\/(ready|failed)$/);
            if (m) { results.push({ id: Number(m[1]), status: m[2], body }); }
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: true }));
        });
    });
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({
        server, results, baseUrl: `http://127.0.0.1:${server.address().port}`,
    })));
}

const page = (text) => `<!DOCTYPE html><html><head><style>@page { size: A5 portrait; margin: 8mm; }</style></head>`
    + `<body><h1>${text}</h1><span><!--printed-at-->Printed 11 Nov 2026 5:01 PM<!--/printed-at--></span></body></html>`;

(async () => {
    head('AGENT-UPGRADE-1 — purani pairing naye ghar me (na naya code, na naya agent)');
    {
        assert.ok(agent.preferredConfigPath().startsWith(process.env.LOCALAPPDATA), 'config ab per-user folder me');
        const legacy = agent.legacyConfigPath();
        fs.mkdirSync(path.dirname(legacy), { recursive: true });
        fs.writeFileSync(legacy, JSON.stringify({ baseUrl: 'https://kk.example', agentCode: 'AG-OLD', token: 'tok-old' }));

        const cfg = agent.loadConfig();
        assert.strictEqual(cfg.agentCode, 'AG-OLD', 'purana config parha gaya');
        const copied = JSON.parse(fs.readFileSync(agent.preferredConfigPath(), 'utf8'));
        assert.strictEqual(copied.token, 'tok-old', 'wohi token naye folder me — nayi pairing nahi');
        assert.strictEqual(copied.agentCode, 'AG-OLD');
        ok('2.6.x ki pairing LOCALAPPDATA me copy — agent code + token wohi');

        fs.writeFileSync(agent.preferredConfigPath(), JSON.stringify({ baseUrl: 'https://kk.example', agentCode: 'AG-NEW', token: 't2' }));
        assert.strictEqual(agent.loadConfig().agentCode, 'AG-NEW', 'naya config pehle');
        ok('naya config ho to wohi pehle — purana dobara copy nahi hota');
    }

    head('AGENT-UPGRADE-1 — poll ka waqt server se, had ke andar');
    {
        agent.schedulePoll(1000);
        assert.strictEqual(agent._currentPollMs(), 1000);
        agent.schedulePoll(50);
        assert.strictEqual(agent._currentPollMs(), 500, 'neeche ki had 500 ms');
        agent.schedulePoll(999999);
        assert.strictEqual(agent._currentPollMs(), 10000, 'upar ki had 10 s');
        agent._stopPoll();
        ok('1 s chal sakta hai; ghalat qeemat server ko na dubo sake (500 ms – 10 s)');
    }

    const cloud = await startCloud();
    agent._setConfig({ baseUrl: cloud.baseUrl, agentCode: 'AG-T', token: 't', pollMs: 1000, source: 'test' });

    head('PRINT-PREPARE-1 — PDF tayyar, CHHAPI nahi, server ko khabar');
    const A = fp('a');
    {
        agent.enqueuePrepares([{ id: 1, doc_key: 'kitchen_sheet:5:ur', fingerprint: A, paper: 'a5_portrait', html: page('Biryani 10 KG') }]);
        await agent._waitPrepares();
        const file = path.join(agent.readyDir(), `kitchen_sheet-5-ur__${A}.pdf`);
        assert.ok(fs.existsSync(file) && fs.statSync(file).size > 0, 'PDF ready folder me');
        assert.deepStrictEqual(cloud.results.map((r) => [r.id, r.status]), [[1, 'ready']], 'server ko "ready"');
        assert.strictEqual(await agent.readyPdfFor(A), file, 'Send isi fingerprint par wohi PDF leta hai');
        assert.strictEqual(await agent.readyPdfFor(fp('f')), null, 'doosra fingerprint = koi PDF nahi');
        ok('asli Chrome se PDF bani, ready\\ me rakhi, server ko bataya — kuch chhapa nahi');
    }

    head('Order badla → nayi PDF, purani mitti');
    const B = fp('b');
    {
        agent.enqueuePrepares([{ id: 2, doc_key: 'kitchen_sheet:5:ur', fingerprint: B, paper: 'a5_portrait', html: page('Biryani 25 KG') }]);
        await agent._waitPrepares();
        assert.ok(!fs.existsSync(path.join(agent.readyDir(), `kitchen_sheet-5-ur__${A}.pdf`)), 'purane order ki PDF gayi');
        assert.ok(fs.existsSync(path.join(agent.readyDir(), `kitchen_sheet-5-ur__${B}.pdf`)), 'naye order ki PDF');
        assert.strictEqual(await agent.readyPdfFor(A), null, 'purana fingerprint kabhi PDF nahi deta');
        ok('ek document ki ek hi PDF — badla hua order purani se kabhi nahi');

        agent.enqueuePrepares([{ id: 3, doc_key: 'kitchen_sheet:5:en', fingerprint: fp('e'), paper: 'a5_portrait', html: page('Biryani 25 KG en') }]);
        await agent._waitPrepares();
        assert.ok(fs.existsSync(path.join(agent.readyDir(), `kitchen_sheet-5-ur__${B}.pdf`)), 'Urdu wali bachi');
        ok('doosri zubaan ki PDF alag — Urdu wali nahi mitti');
    }

    head('Kaghaz ka waqt — 10 min se purani PDF Send par nahi');
    {
        const file = path.join(agent.readyDir(), `kitchen_sheet-5-ur__${B}.pdf`);
        const old = (Date.now() - agent.READY_FRESH_MS - 60000) / 1000;
        fs.utimesSync(file, old, old);
        assert.strictEqual(await agent.readyPdfFor(B), null, '11 min purani — Chrome se nayi');
        ok('purani PDF par purana waqt chhapta — is liye Send use nahi leta');
    }

    head('Send ban rahi PDF ka intezar karta hai — Chrome dobara nahi');
    {
        const C = fp('c');
        agent.enqueuePrepares([{ id: 4, doc_key: 'quotation:9:en', fingerprint: C, paper: 'a4_portrait', html: page('Quotation') }]);
        assert.ok(agent._inFlightPrepares.has(C), 'abhi ban rahi hai');
        const got = await agent.readyPdfFor(C);
        assert.ok(got && got.endsWith(`__${C}.pdf`), 'intezar kar ke wohi PDF mili');
        ok('Send aur tayyari takrayein to tayyari hi kaam aati hai');
    }

    head('Safai — PC par kuch jama na ho');
    {
        const dir = agent.readyDir();
        const stamp = (f, days) => { const t = (Date.now() - days * 86400000) / 1000; fs.utimesSync(f, t, t); };
        const month = path.join(dir, `old__${fp('d')}.pdf`);
        const fresh = path.join(dir, `new__${fp('9')}.pdf`);
        const tmp = path.join(dir, '.prep-x.pdf');
        for (const f of [month, fresh, tmp]) { fs.writeFileSync(f, 'x'); }
        stamp(month, 31); stamp(tmp, 2);
        const stray = path.join(os.tmpdir(), `bingoo-prepare-test-${process.pid}.html`);
        fs.writeFileSync(stray, 'x'); stamp(stray, 2);

        agent.cleanupFiles();

        assert.ok(!fs.existsSync(month), '30 din se purani PDF gayi');
        assert.ok(!fs.existsSync(tmp), 'adhoori .tmp gayi');
        assert.ok(fs.existsSync(fresh), 'aaj wali bachi');
        assert.ok(!fs.existsSync(stray), '%TEMP% ki bachi hui bingoo-* gayi');
        ok('30 din / adhoori / temp — sab saaf; taaza bachi');
    }

    cloud.server.close();
    try { fs.rmSync(sandbox, { recursive: true, force: true }); } catch { /* chhor do */ }
    console.log(`\n✅ ALL PASSED (${passed} assertions) · prepare + upgrade · agent v${agent.AGENT_VERSION}\n`);
})().catch((err) => {
    console.error('\n❌ FAILED:', err && err.stack ? err.stack : err);
    process.exit(1);
});
