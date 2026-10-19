/**
 * POORA RAASTA, ASLI EXE — HTML se le kar Windows printer tak.
 *
 * ── MALIK KA SAWAL, 10 OCTOBER ────────────────────────────────────────────
 *
 * "Tumne yahan test kar ke dekha, fake printer ya HP pe?"
 *
 * Jawab tha: NAHI. Ab tak sirf AADHA raasta jaancha gaya tha —
 * `document-print-test.js` dekhta hai ke Chrome HTML se PDF bana leta hai, aur
 * bas. Us ke BAAD wala qadam, yani PDF ko Windows printer tak pahunchana, kabhi
 * kahin nahi chala: na yahan, na client ke PC par. Poora kaam usi par khara hai.
 *
 * Ye test wohi BINARY chalata hai jo client ke PC par jati hai, usay ek asli
 * catering document job deta hai, aur dekhta hai ke agent ne SERVER ko kya
 * jawab bheja — "chhap gaya" ya "ye nakami hui".
 *
 * ── YE TEST "PASS/FAIL" NAHI, SACH DIKHATA HAI ─────────────────────────────
 *
 * Har machine par jawab alag hoga, aur yehi is ka maqsad hai. Agent ko PDF
 * chhapne ke liye SumatraPDF / PDFtoPrinter / Acrobat me se koi ek chahiye; na
 * mile to wo Windows ke apne `PrintTo` verb par girta hai, jo har machine par
 * darj nahi hota.
 *
 * Is liye test teen cheezein PAKKI karta hai (jo har machine par sach honi
 * chahiye), aur chauthi — asal natija — naap kar DIKHA deta hai:
 *
 *   1. exe document wale raaste par jati hai (printer_type = windows)
 *   2. wo server ko JAWAB zaroor bhejti hai — chup nahi rehti
 *   3. nakami ho to us me wajah likhi ho, khali na ho
 *   4. aur phir: kya chhapa, ya kya rok raha hai
 *
 * ── MEHFOOZ ───────────────────────────────────────────────────────────────
 *
 * Naqli server 127.0.0.1 par. Printer ka naam jaan bujh kar AISA hai jo kisi
 * machine par mojood nahi — koi kaghaz zaya nahi hota. Agent ki asli config
 * (%ProgramData%) chhui nahi jati: env vars `loadConfig()` me sab se pehle
 * jeette hain.
 *
 * Run:  node test/exe-document-e2e-test.js
 */

const http = require('http');
const path = require('path');
const fs = require('fs');
const assert = require('assert');
const { spawn, execFileSync } = require('child_process');

const EXE = path.join(__dirname, '..', 'dist', 'BingooPrintAgent.exe');

// Koi machine is naam ka printer nahi rakhti. Maqsad ye nahi ke printer mile —
// maqsad ye hai ke dekha jaye raasta KAHAN tootta hai, aur kaghaz zaya na ho.
const FAKE_NAME = 'BINGOO-E2E-TEST-PRINTER-DOES-NOT-EXIST';

// Default jaan bujh kar wo naam hai jo kisi machine par nahi — koi kaghaz zaya
// nahi hota. ASLI printer par chalana ho to:
//   BINGOO_E2E_PRINTER="HP LaserJet P2055dn" node test/exe-document-e2e-test.js
// Client ke PC par yehi tareeqa poora raasta sabit karne ke kaam aata hai.
const PRINTER_NAME = process.env.BINGOO_E2E_PRINTER || FAKE_NAME;
const USING_REAL = PRINTER_NAME !== FAKE_NAME;

const HTML = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    + '@page { size: A5 portrait; margin: 8mm; }'
    + 'body { font-family: Arial, sans-serif; }'
    + '</style></head><body><h1>BINGOO E2E</h1><p>catering document test</p></body></html>';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let passed = 0;
const ok = (m) => { passed++; console.log(`  \u2713 ${m}`); };

if (!fs.existsSync(EXE)) {
    console.log(`\n!  ${path.basename(EXE)} nahi mila \u2014 pehle build karein.\n`);
    process.exit(0);
}

/** Naqli cloud: ek document job deta hai, aur agent ka jawab jama karta hai. */
function startCloud() {
    const seen = { polls: 0, printed: [], failed: [] };
    let handedOut = false;

    const srv = http.createServer((req, res) => {
        const url = req.url.split('?')[0];
        const send = (obj) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };

        if (url === '/api/print-agent/heartbeat') { return send({ ok: true }); }
        if (url === '/api/print-agent/commands') { return send({ ok: true, commands: [] }); }

        if (url === '/api/print-agent/pending') {
            seen.polls++;
            if (handedOut) { return send({ ok: true, jobs: [] }); }
            handedOut = true;

            return send({ ok: true, jobs: [{
                id: 9001, job_no: 'CD-E2E-9001',
                document_type: 'catering_document', reference_no: 'EV-E2E-0001',
                printer: {
                    id: 99, name: 'E2E Document Printer',
                    printer_type: 'windows', print_role: 'document',
                    paper_size: 'A5', ip_address: null, port: null,
                    windows_printer_name: PRINTER_NAME,
                },
                raw_payload: HTML,
                payload: { paper: 'A5 portrait' },
            }] });
        }

        if (/\/api\/print-agent\/jobs\/\d+\/printed$/.test(url)) {
            seen.printed.push(url);

            return send({ ok: true });
        }

        if (/\/api\/print-agent\/jobs\/\d+\/failed$/.test(url)) {
            let body = '';
            req.on('data', (d) => { body += d; });
            req.on('end', () => {
                try { seen.failed.push(JSON.parse(body).error_message || ''); } catch { seen.failed.push(body); }
                send({ ok: true });
            });

            return undefined;
        }

        res.writeHead(404); res.end('{}');
    });

    return new Promise((r) => srv.listen(0, '127.0.0.1', () => r({ srv, seen, port: srv.address().port })));
}

/** Is machine par PDF ka `printto` verb darj hai? Yehi agent ka aakhri sahara hai. */
function printToVerbRegistered() {
    try {
        const out = execFileSync('reg', ['query', 'HKCU\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FileExts\\.pdf\\UserChoice', '/v', 'ProgId'],
            { timeout: 10000, windowsHide: true }).toString();
        const m = out.match(/ProgId\s+REG_SZ\s+(\S+)/);
        if (!m) { return null; }
        execFileSync('reg', ['query', `HKCR\\${m[1]}\\shell\\printto\\command`], { timeout: 10000, windowsHide: true });

        return m[1];
    } catch {
        return false;
    }
}

(async () => {
    console.log('\n\u2500\u2500 Poora raasta, asli exe: HTML -> PDF -> Windows printer');

    const cloud = await startCloud();
    const child = spawn(EXE, ['run'], {
        env: {
            ...process.env,
            POS_BASE_URL: `http://127.0.0.1:${cloud.port}`,
            POS_PRINT_AGENT_CODE: 'AG-E2E',
            POS_PRINT_AGENT_TOKEN: 'tok-e2e',
            POS_PRINT_POLL_MS: '400',
        },
        windowsHide: true,
        stdio: 'ignore',
    });

    try {
        // Chrome ko do koshishein mil sakti hain (60s + 30s), phir spool.
        for (let i = 0; i < 300 && cloud.seen.printed.length === 0 && cloud.seen.failed.length === 0; i++) {
            await sleep(500);
        }

        assert.ok(cloud.seen.polls > 0, 'exe ko server se baat karni chahiye thi');
        ok(`exe ne server se baat ki (${cloud.seen.polls} poll)`);

        // SAB SE AHEM: agent chup na rahe. Ek job jo na chhape aur na nakami
        // bataye, wo sab se bura natija hai — malik intezar karta rehta hai.
        const answered = cloud.seen.printed.length + cloud.seen.failed.length;
        assert.strictEqual(answered, 1,
            'agent ko server ko theek EK jawab dena chahiye tha (chhapa, ya ye nakami hui) \u2014 '
            + `mila: printed=${cloud.seen.printed.length} failed=${cloud.seen.failed.length}`);
        ok('agent chup nahi raha \u2014 server ko jawab diya');

        // SAB SE SAKHT SHART, aur ye har machine par sach honi chahiye.
        //
        // 10 Oct: isi jagah pakra gaya ke agent ek na-mojood printer par
        // "chhap gaya" keh raha tha. Wajah `printViaWindowsVerb` me thi —
        // PowerShell throw karta tha magar exit 0 deta tha. Chup-chaap jhooti
        // kamyabi us nakami se badtar hai jo saaf nazar aaye: job DB me
        // "printed" lag jati aur kisi ko pata na chalta ke kaghaz nikla hi nahi.
        if (! USING_REAL) {
            assert.strictEqual(cloud.seen.printed.length, 0,
                'jis printer ka wajood hi nahi, us par agent ne "chhap gaya" kaha — '
                + 'ye jhooti kamyabi hai aur nakami se zyada nuqsan deti hai');
            ok('na-mojood printer par "chhap gaya" NAHI kaha');
        }

        if (cloud.seen.failed.length) {
            const err = cloud.seen.failed[0];
            assert.ok(err && err.trim().length > 10,
                'nakami ka paigham khali nahi hona chahiye \u2014 warna agli baar phir andaze lagane parenge');
            ok('nakami me wajah likhi hui hai, khali nahi');
        }

        // ── Aur ab asal natija, naap kar ────────────────────────────────────
        const verb = printToVerbRegistered();
        console.log('\n  \u2500\u2500 is machine ki soorat-e-haal');
        console.log('     PDF chhapne ka tool : '
            + (fs.existsSync(path.join(path.dirname(EXE), 'SumatraPDF.exe')) ? 'SumatraPDF (agent ke saath)'
                : fs.existsSync('C:\\Program Files\\SumatraPDF\\SumatraPDF.exe') ? 'SumatraPDF (installed)'
                    : 'KOI NAHI'));
        console.log('     PDF ka printto verb : '
            + (verb === false ? 'DARJ NAHI \u2014 Windows ka aakhri sahara bhi band'
                : verb === null ? 'pata nahi chala' : `darj hai (${verb})`));

        if (cloud.seen.printed.length) {
            console.log('\n  NATIJA: agent ne "chhap gaya" kaha.');
        } else {
            console.log('\n  NATIJA: raasta yahan tuta \u2014');
            console.log('     ' + cloud.seen.failed[0]);
        }

        console.log(`\n\u2705 ALL PASSED (${passed} assertions) \u00b7 document e2e\n`);
    } finally {
        try { child.kill(); } catch { /* chhor do */ }
        cloud.srv.close();
    }
})().catch((e) => { console.error('\n\u274C ' + e.message + '\n'); process.exit(1); });
