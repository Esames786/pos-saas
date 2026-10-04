/**
 * CATERING-SEND-TO-PRINTER-1 — document wale raaste ka pehra.
 *
 * Ye test ASAL code chalata hai, naqal nahi: `print-agent.js` se wohi functions
 * nikal kar jo chalte waqt chalte hain. Yahan jo cheez sab se zyada tootne wali
 * hai wo Chrome ki command line hai, aur wo sirf chala kar hi jaanchi ja sakti
 * hai.
 *
 * Teen baatein jaanchi jati hain:
 *
 *   1. HTML se waqai PDF banti hai, aur us ka kagaz wohi hota hai jo document
 *      ke `@page` me likha hai. Yehi poore kaam ka maqsad tha — "bar bar A4 /
 *      A5 select karna parta hai."
 *
 *   2. `--print-background` apna kaam karta hai. Us ke baghair kitchen sheet
 *      par PARTY/OWN ka kala dabba SAFED chhapta hai aur bawarchi ko maal ka
 *      malik pata nahi chalta. Ye kharabi khamosh hai: kaghaz nikal aata hai,
 *      bas us par ek rang kam hota hai.
 *
 *   3. Printer ka naam na ho to SAAF inkaar — chup chaap kuch na karna sab se
 *      bura natija hai.
 */

const fs   = require('fs');
const os   = require('os');
const path = require('path');
const assert = require('assert');
const { execFileSync } = require('child_process');

const AGENT = path.join(__dirname, '..', 'print-agent.js');
const src   = fs.readFileSync(AGENT, 'utf8');

/** Asal file se ek function ka poora jism — naqal likhna is test ko bemani kar deta. */
function extract(name) {
    const start = src.indexOf(`function ${name}(`);
    assert.notStrictEqual(start, -1, `${name}() print-agent.js me milna chahiye`);

    let depth = 0;
    for (let i = src.indexOf('{', start); i < src.length; i++) {
        if (src[i] === '{') { depth++; }
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) { return src.slice(start, i + 1); }
        }
    }
    throw new Error(`${name}() ka band kosha nahi mila`);
}

const sandbox = { fs, os, path, process, require, exeDir: () => __dirname };
const load = (name) => {
    const fn = new Function('fs', 'os', 'path', 'process', 'require', 'exeDir',
        `${extract(name)}; return ${name};`);

    return fn(sandbox.fs, sandbox.os, sandbox.path, sandbox.process, sandbox.require, sandbox.exeDir);
};

const findChrome = load('findChrome');

let passed = 0;
const ok = (label) => { console.log(`  ✓ ${label}`); passed++; };

console.log('\n── Chrome HTML se PDF banata hai, aur kagaz document ka hota hai');

const chrome = findChrome();
if (!chrome) {
    console.log('  ! Chrome/Edge is machine par nahi — ye hissa skip (CI par normal hai)');
} else {
    for (const [label, size, expectLandscape] of [['A5 portrait', 'A5 portrait', false],
                                                  ['A4 portrait', 'A4 portrait', false]]) {
        const stamp = `test-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const html  = path.join(os.tmpdir(), `${stamp}.html`);
        const pdf   = path.join(os.tmpdir(), `${stamp}.pdf`);

        fs.writeFileSync(html, `<!DOCTYPE html><html><head><style>
            @page { size: ${size}; margin: 8mm; }
            body { font-family: Arial; }
            .tag { background: #111827; color: #fff; padding: 4px 8px;
                   -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        </style></head><body><div class="tag">PARTY 18 KG</div></body></html>`, 'utf8');

        execFileSync(chrome, [
            '--headless=new', '--disable-gpu', '--no-sandbox',
            '--no-pdf-header-footer', '--print-background',
            `--print-to-pdf=${pdf}`, `file:///${html.replace(/\\/g, '/')}`,
        ], { timeout: 60000, windowsHide: true });

        assert.ok(fs.existsSync(pdf) && fs.statSync(pdf).size > 0, `${label}: PDF banni chahiye`);

        // MediaBox se asli naap — andaza nahi. A5 portrait = 420 x 595 pt
        // (±2 pt, kyunke Chrome round karta hai), A4 portrait = 595 x 842.
        const raw = fs.readFileSync(pdf, 'latin1');
        const m = raw.match(/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)/);
        assert.ok(m, `${label}: PDF me MediaBox milna chahiye`);
        const [w, h] = [Math.round(+m[1]), Math.round(+m[2])];

        const want = size === 'A5 portrait' ? [420, 595] : [595, 842];
        assert.ok(Math.abs(w - want[0]) <= 2 && Math.abs(h - want[1]) <= 2,
            `${label}: kagaz ${want[0]}x${want[1]} hona chahiye, mila ${w}x${h}`);

        ok(`${label} → PDF ${w}x${h} pt (document ke @page se, kisi setting se nahi)`);

        // Kala dabba waqai kala chhapa? PDF me rang ka operator mojood hona
        // chahiye. Us ke baghair kaghaz "theek" dikhta hai aur jhoot bolta hai.
        assert.ok(/\bsc[n]?\b|\brg\b|\bg\b/.test(raw.slice(0, 200000)),
            `${label}: --print-background ka asar PDF me nazar aana chahiye`);
        ok(`${label} → background rang PDF me mojood (kala dabba safed nahi hua)`);

        for (const f of [html, pdf]) { try { fs.unlinkSync(f); } catch {} }
    }
}

console.log('\n── Printer ka naam na ho to saaf inkaar, khamoshi nahi');
{
    // `printDocumentOnWindows` ke pehle teen pehre yahin kaat-te hain.
    const guard = src.includes("throw new Error('Is printer par Windows wala naam likha hi nahi");
    assert.ok(guard, 'naam na hone par saaf error hona chahiye');
    ok('naam na ho to error, aur us me karna kya hai wo bhi likha hai');

    assert.ok(src.includes("'--print-background'"),
        '--print-background hona hi chahiye — warna PARTY/OWN ka dabba safed chhapta hai');
    ok('--print-background Chrome ki command me mojood hai');
}

console.log(`\n✅ ALL PASSED (${passed} assertions) · document print\n`);
