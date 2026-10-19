/**
 * ELEVATION — 9 October ka poora din, ek test me bandh.
 *
 * ── HUA KYA THA ───────────────────────────────────────────────────────────
 *
 * Kashif Kitchen par catering ke A4/A5 document chhap hi nahi rahe thay.
 * Chrome `--headless=new` par ek second me `exit 0` de kar nikal jata tha —
 * bina PDF banaye, bina kisi shikayat ke. Edge ne bhi bilkul wohi kiya.
 *
 * Jo jo shak kiya aur jo jo GHALAT nikla:
 *
 *   • "Chrome khula hua hai, profile par taala hai"  -> jaanch kar ghalat:
 *     19 Chrome process chalte hue bhi PDF ban jati hai
 *   • "Chrome purana hai"                            -> Chrome 154, Edge 155
 *   • "enterprise policy ne profile tay kar di hai"  -> paanchon registry keys
 *     khali; probe me ek maujood key bhi rakhi gayi thi taake us ka zinda hona
 *     sabit rahe
 *
 * Asal wajah: agent shuru se ADMINISTRATOR par chal raha tha — pehle
 * "Administrator: Windows PowerShell" me, phir scheduled task `RunLevel
 * Highest` par. Chromium elevated process se chalne par kaam karne se inkar
 * kar deta hai.
 *
 * Sabit aise hua: WOHI command, WOHI PC, browser khule hue, sirf aam
 * (non-admin) PowerShell me — PDF foran ban gayi, 12,409 bytes.
 *
 * ── YE TEST KYUN ──────────────────────────────────────────────────────────
 *
 * Wo `Highest` meri apni likhi hui script me tha. Yani ye ghalti dobara likhi
 * ja sakti hai, aur us ki qeemat ghanton me hai — kyunke nakami KHAMOSH hoti
 * hai: thermal parchi theek chhapti rehti hai, sirf document rukte hain.
 *
 * Is liye teen cheezon par pehra:
 *   1. installer script kabhi `RunLevel Highest` par wapas na jaye
 *   2. agent me elevation pehchanne ka tareeqa mojood rahe
 *   3. wo pehchan SHURU me aur DOCUMENT KI NAKAMI par, dono jagah boley
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const agent = fs.readFileSync(path.join(root, 'print-agent.js'), 'utf8');
const ps1 = fs.readFileSync(
    path.join(root, 'installer', 'windows', 'install-autostart-user.ps1'), 'utf8'
);

let passed = 0;
const ok = (label) => { console.log(`  ✓ ${label}`); passed++; };

console.log('\n── Elevation: wo cheez jo 9 Oct ko poora din le gayi');

/* 1. Script ka task aam darje par chale. */
assert.ok(
    /-RunLevel\s+Limited/.test(ps1),
    'install-autostart-user.ps1 ka task RunLevel Limited par hona chahiye'
);
assert.ok(
    !/-RunLevel\s+Highest/.test(ps1),
    'install-autostart-user.ps1 me RunLevel Highest wapas aa gaya hai — '
    + 'us par Chrome PDF nahi banata, aur nakami KHAMOSH hoti hai: thermal parchi '
    + 'chhapti rahegi aur sirf A4/A5 document rukenge'
);
ok('installer ka task Limited par hai, Highest par nahi');

/* 2. Elevation pehchanne ka tareeqa mojood ho — aur asal integrity SID se. */
assert.ok(/function isElevated\s*\(/.test(agent), 'isElevated() mojood honi chahiye');
assert.ok(
    agent.includes('S-1-16-12288'),
    'isElevated() ko High Mandatory Level (S-1-16-12288) dekhna chahiye'
);
assert.ok(
    agent.includes('S-1-16-8192'),
    'isElevated() ko Medium (S-1-16-8192) bhi pehchanna chahiye — warna '
    + '"aam user" aur "pata nahi" ek hi cheez ban jate hain'
);
assert.ok(
    /System32[\\'",\s]+.?whoami\.exe|'whoami\.exe'/.test(agent),
    'whoami poore raaste se bulao — PATH par Git ka Unix wala whoami saamne '
    + 'aa sakta hai aur jaanch chup chaap "pata nahi" dene lagti hai'
);
ok('isElevated() asal integrity SID parhti hai, poore raaste wale whoami se');

/* 3. "Pata nahi" ko "haan" na banaya jaye — HAR jagah, ek jagah nahi.
 *
 * Pehli likhai sirf itna dekhti thi ke `=== true` KAHIN mojood hai. Sabotage
 * kar ke dekha to ek jagah se hata dene par bhi test green raha — yani wo
 * pehra jhoota tha. Ab HAR call site parkha jata hai. */
const loose = [...agent.matchAll(/(?<!function )isElevated\(\)(?!\s*===\s*true)/g)];
assert.strictEqual(
    loose.length, 0,
    `isElevated() ka muqabla HAR jagah === true se ho (${loose.length} jagah nahi hai). `
    + 'Wo null bhi lauta sakti hai jab pata na chale, aur null ko sach maan lena har '
    + 'us PC par jhooti warning de dega jahan jaanch chal hi na saki'
);
assert.ok(
    agent.match(/isElevated\(\)\s*===\s*true/g).length >= 2,
    'isElevated() dono jagah istemal honi chahiye — shuru me aur nakami par'
);
ok(`null ("pata nahi") ko haan nahi samjha jata — ${agent.match(/isElevated\(\)\s*===\s*true/g).length} jagah parkha`);

/* 4. Dono jagah boley: shuru me, aur document ki nakami par. */
const startup = agent.slice(agent.indexOf('function run(config)'));
assert.ok(
    /isElevated\(\)\s*===\s*true/.test(startup) && /ADMINISTRATOR/.test(startup),
    'shuru hote hi warning milni chahiye — 9 Oct ko yehi ek satar ghanton ki '
    + 'talash bacha deti'
);
assert.ok(
    agent.includes('AGENT ADMINISTRATOR PAR CHAL RAHA HAI'),
    'document ki nakami par elevation pehla sabab bana kar likha jaye'
);
ok('warning shuru me bhi, aur document ki nakami par bhi');

/* 5. Warning kisi kaam ko ROKE nahi. Thermal parchi elevated par theek chalti
 *    hai; ek ghalat warning par parchi rok dena us masle se bara masla hai. */
assert.ok(
    !/if\s*\(\s*isElevated\(\)[^)]*\)\s*\{[^}]*throw/.test(agent),
    'elevation par kuch throw nahi hona chahiye — ye sirf ittila hai'
);
ok('ye sirf batati hai, kisi parchi ko rokti nahi');

/* ── KAGHAZ KA NAAP PRINTER TAK PAHUNCHE ──────────────────────────────────
 *
 * 10/11 Oct: kitchen sheet A5 par bheji jati thi aur A4 par nikalti thi. Malik
 * ne theek pakra. Hamari taraf sab durust tha — job me `a5_portrait`, HTML me
 * `@page { size: A5 portrait }`, aur PDF ka MediaBox waqai 420x595 pt (= A5).
 *
 * Kami AAGE thi: SumatraPDF ko kagaz ka naap bataya hi nahi jata tha, is liye
 * Windows printer ki APNI default (aam tor par A4) lag jati thi.
 *
 * Qeematein andaze se nahi li gayin: SumatraPDF 3.5.2 ki binary me paper ki
 * fehrist dekhi gayi aur `-print-settings` ke chunao bhi; phir asli PDF ke
 * saath chala kar dekha ke wo exit 0 deta hai.
 */
{
    const settingsFor = (() => {
        const start = agent.indexOf('function printSettingsFor(');
        const end = agent.indexOf('\n}', start) + 2;

        return new Function(`${agent.slice(start, end)}; return printSettingsFor;`)();
    })();

    const say = (paper) => settingsFor({ payload: { paper } });

    assert.strictEqual(say('a5_portrait'), 'paper=A5,portrait,noscale',
        'A5 ke parche par printer ko A5 hi kehna chahiye');
    assert.strictEqual(say('a4_portrait'), 'paper=A4,portrait,noscale');
    assert.strictEqual(say('a4_landscape'), 'paper=A4,landscape,noscale');
    ok('kagaz ka naap printer tak jata hai (paper=A5 / A4)');

    // Na-pehchana kagaz koi setting na bheje. Ek na-samjhi ki wajah se parchi
    // rokna us masle se bara masla hai jo wo batati.
    assert.strictEqual(say('koora'), null, 'na-pehchana kagaz koi setting na bheje');
    assert.strictEqual(say(''), null);
    assert.strictEqual(settingsFor({}), null, 'payload hi na ho to bhi na gire');
    ok('na-samjha kagaz koi setting nahi bhejta - purana rawaiyya chalta hai');

    // `-print-settings` SIRF SumatraPDF samajhta hai. PDFtoPrinter ya Acrobat
    // ko dena unhein tor deta - aur wo nakami theek us PC par hoti jahan
    // SumatraPDF mojood hi na ho, yani sab se bure waqt par.
    const pdfBlock = agent.slice(agent.indexOf('function findPdfPrinter('),
        agent.indexOf('function runExe('));
    assert.ok(/PDFtoPrinter\.exe'\), args: \(f, p\) => \[f, p\]/.test(pdfBlock),
        'PDFtoPrinter ko print-settings nahi milni chahiye');
    assert.ok(!/AcroRd32[^\n]*print-settings/.test(pdfBlock),
        'Acrobat ko bhi nahi');
    ok('print-settings sirf SumatraPDF ko jati hai');
}
console.log(`\n✅ ALL PASSED (${passed} assertions) · elevation + paper\n`);
