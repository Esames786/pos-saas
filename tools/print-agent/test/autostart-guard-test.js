/**
 * AUTO-START — "install karte hi chale, aur PC restart par bhi khud chale."
 *
 * ── HUA KYA ───────────────────────────────────────────────────────────────
 *
 * Client ke PC par agent baar baar band mila. Teen alag alag wajhein nikleen,
 * aur teenon ek hi shikayat ki shakl me aati thin ("parchi queue me khari
 * hai"), is liye har baar waqt laga:
 *
 *   1. TASK GHALAT USER KE NAAM PAR BAN JATA THA.
 *      Script me seedha `$env:USERNAME` tha. Installer ADMIN maangta hai; UAC
 *      par kisi doosre admin account ka naam/pass daala jaye to wo variable us
 *      DOOSRE account ka hota hai. Task us ke naam par banta tha, aur counter
 *      wala user log in karta to At-Logon trigger kabhi chalta hi nahi tha.
 *      ("install to kiya tha, phir bhi khud start nahi hota.")
 *
 *   2. AGENT EK KAALI WINDOW ME CHALTA THA.
 *      Console program hai. Client use "nodejs ki window" samajh kar band kar
 *      deta tha, aur agent bhi us ke saath mar jata tha. Scheduled task ka
 *      "Hidden" khana ye hal NAHI karta - wo sirf task ko list me chhupata
 *      hai, program ki window ko nahi.
 *
 *   3. DO COPIES EK SAATH.
 *      Agent me ek waqt me ek hi copy ka koi pehra nahi tha. Task khud agent
 *      chalata hai; user ko nazar na aaye to wo exe par dobara click kar deta
 *      hai. Dono EK hi credentials se server se baat karti hain, is liye
 *      server unhe alag nahi kar sakta - ek hi parchi DO BAAR chhap sakti hai.
 *
 * ── YE TEST KYA PAKADTA HAI ───────────────────────────────────────────────
 *
 * Upar wali teenon cheezein phir se likhi ja sakti hain, aur teenon ki nakami
 * KHAMOSH hai. Is liye:
 *   - installer ka raasta (ps1 + iss + vbs) static tor par parkha jata hai
 *   - aur single-instance ka pehra WAQAI CHALA kar: asli process, asli lock
 *     file, asli stale-lock ki soorat.
 */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const root = path.join(__dirname, '..');
const win = path.join(root, 'installer', 'windows');
const ps1 = fs.readFileSync(path.join(win, 'install-autostart-user.ps1'), 'utf8');
const iss = fs.readFileSync(path.join(win, 'BingooPrintAgent.iss'), 'utf8');
const vbsPath = path.join(win, 'run-hidden.vbs');

let passed = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); passed++; };
const eq = (a, b, msg) => { assert.strictEqual(a, b, msg); passed++; };

/* ── 1. Task USI user ke naam par jo PC par baitha hai ─────────────────── */

ok(/Win32_ComputerSystem\)\.UserName/.test(ps1),
    'task us CONSOLE user ke naam par banna chahiye jo PC par baitha hai, '
    + 'installer chalane wale admin ke naam par nahi');

ok(/-User \$target/.test(ps1) && /-UserId \$target/.test(ps1),
    'trigger aur principal DONO usi hal-shuda user par hon - ek bhi '
    + '$env:USERNAME par reh jaye to task ghalat account par chala jata hai');

/* ── 2. Bina window ke ─────────────────────────────────────────────────── */

ok(fs.existsSync(vbsPath), 'run-hidden.vbs mojood honi chahiye');

const vbs = fs.readFileSync(vbsPath, 'utf8');
ok(/\.Run\s+""""\s*&\s*exe\s*&\s*"""\s+run",\s*0,\s*False/.test(vbs.replace(/\s+/g, ' '))
   || /,\s*0,\s*False/.test(vbs),
    'WScript.Shell.Run ka window-style 0 hona chahiye (0 = bilkul na dikhao)');

ok(/-Execute 'wscript\.exe'/.test(ps1),
    'task ko VBS launcher chalana chahiye, exe ko seedha nahi - warna console '
    + 'window khulti hai aur client use band kar deta hai');

ok(/Source: "run-hidden\.vbs"/.test(iss),
    'installer ko run-hidden.vbs ship karni chahiye, warna task ban kar bhi '
    + 'kuch nahi chalata');

/* ── 3. Elevation ka purana sabaq qayam rahe ───────────────────────────── */

ok(/-RunLevel Limited/.test(ps1) && !/-RunLevel Highest/.test(ps1),
    'task hamesha Limited par - Highest par Chrome PDF banana band kar deta hai');

/* ── 4. Install ke baad JAANCH, sirf "ho gaya" nahi ────────────────────── */

ok(/Get-Process -Name 'BingooPrintAgent'/.test(ps1),
    'script ko khud dekhna chahiye ke agent waqai chal para - "task ban gaya" '
    + 'aur "agent chal raha hai" do alag baatein hain');

/* ── 5. Dono shipped script PURE ASCII ─────────────────────────────────── */

for (const [name, text] of [['install-autostart-user.ps1', ps1], ['run-hidden.vbs', vbs]]) {
    const bad = [...text].filter((c) => c.charCodeAt(0) > 127);
    eq(bad.length, 0,
        `${name} me ${bad.length} non-ASCII character hain. PowerShell 5.1 BOM ke `
        + 'baghair file ko ANSI parhta hai aur em dash ka aakhri byte string beech '
        + 'me band kar deta hai - poori script parse error.');
}

/* ── 6. Single instance ka pehra — WAQAI chala kar ─────────────────────── */

const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'bpa-lock-'));
process.env.ProgramData = sandbox;

const bpa = require(path.join(root, 'print-agent.js'));
const lock = bpa.lockPath();
fs.mkdirSync(path.dirname(lock), { recursive: true });

// Ek asli, zinda process - aur wo `node.exe` hai, yani wohi shakl jo agent
// ki hoti hai. Yehi cheez PID ke dobara istemal hone se bachati hai.
const alive = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)'],
    { stdio: 'ignore', windowsHide: true });

try {
    fs.writeFileSync(lock, JSON.stringify({ pid: alive.pid }));
    eq(bpa.anotherAgentIsRunning(), alive.pid,
        'zinda agent ki PID wali lock file par doosri copy ko ruk jana chahiye');

    // Apni hi PID par kabhi na ruko, warna agent khud ko hi rok de.
    fs.writeFileSync(lock, JSON.stringify({ pid: process.pid }));
    eq(bpa.anotherAgentIsRunning(), null, 'apni hi PID par agent na ruke');

    // Kachra lock file: na JSON, na PID.
    fs.writeFileSync(lock, 'koora');
    eq(bpa.anotherAgentIsRunning(), null, 'kharab lock file agent ko na roke');

    fs.unlinkSync(lock);
    eq(bpa.anotherAgentIsRunning(), null, 'lock file hi na ho to agent chale');
} finally {
    try { alive.kill(); } catch { /* chhor do */ }
}

// STALE LOCK — bijli jane par file reh jati hai. Agar pehra sirf "file mojood
// hai" dekhta to agent us ke baad KABHI na chalta, aur wo is masle se bara
// masla hota jo ye pehra hal karta hai.
const deadPid = alive.pid;
const waitDead = Date.now() + 10000;
while (Date.now() < waitDead) {
    try {
        const out = execFileSync('tasklist', ['/FI', `PID eq ${deadPid}`, '/FO', 'CSV', '/NH'],
            { encoding: 'utf8', windowsHide: true });
        if (!out.toLowerCase().includes('node.exe')) { break; }
    } catch { break; }
}
fs.writeFileSync(lock, JSON.stringify({ pid: deadPid }));
eq(bpa.anotherAgentIsRunning(), null,
    'mare hue process ki lock file ko nazarandaz karna chahiye - warna ek baar '
    + 'bijli jane ke baad agent kabhi chalta hi nahi');

try { fs.rmSync(sandbox, { recursive: true, force: true }); } catch { /* chhor do */ }

console.log(`\n✅ ALL PASSED (${passed} assertions) · autostart + single instance\n`);
