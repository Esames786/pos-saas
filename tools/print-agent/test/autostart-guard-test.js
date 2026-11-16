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
const iss = fs.readFileSync(path.join(win, 'BingooPrintAgent.iss'), 'utf8');
const vbsPath = path.join(win, 'run-hidden.vbs');
const cmdPath = path.join(win, 'remove-everything.cmd');

let passed = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); passed++; };
const eq = (a, b, msg) => { assert.strictEqual(a, b, msg); passed++; };

/* AGENT-UPGRADE-1 (2.7.0, B2) — owner: "bas ek click pe sab; zero se setup ya old agent remove kar ke new dalna —
 * dono easy; admin / super admin ka masla na aaye", aur "thermal install ke baad tum script se haath se agent on karwa
 * rahe the". Purane (2.6.6, admin + scheduled task) pehre isi file me the; 2.7.0 per-user hai, is liye pehre wohi
 * MAQSAD ab naye raaste par parakhte hain. */

/* ── 1. Admin kabhi nahi — per-user ─────────────────────────────────────── */

ok(/^PrivilegesRequired=lowest\s*$/m.test(iss) && !/PrivilegesRequired=admin/.test(iss),
    'installer per-user (lowest) hona chahiye - admin ka popup kabhi nahi');
ok(/^DefaultDirName=\{localappdata\}\\Programs\\BingooPrintAgent\s*$/m.test(iss),
    'install apne user ke folder me (Program Files admin maangta hai)');
ok(!/RunLevel Highest/.test(iss) && !/install-service\.ps1/.test(iss),
    'koi elevated / SYSTEM task nahi - Highest par Chrome PDF banana band kar deta hai (9 Oct)');

/* ── 2. Logon par khud chale, bina window, bina admin ──────────────────── */

ok(/Root: HKCU; Subkey: "Software\\Microsoft\\Windows\\CurrentVersion\\Run"/.test(iss)
   && /ValueData: """\{sys\}\\wscript\.exe"" \/\/B \/\/Nologo ""\{app\}\\run-hidden\.vbs"""/.test(iss),
    'auto-start apne user ka Run, aur wo VBS chalaye - exe ko seedha nahi (warna kaali window)');
ok(/Source: "run-hidden\.vbs"/.test(iss), 'installer run-hidden.vbs ship kare');

ok(fs.existsSync(vbsPath), 'run-hidden.vbs mojood honi chahiye');
const vbs = fs.readFileSync(vbsPath, 'utf8');
ok(/sh\.Run\("""" & exe & """ run", 0, True\)/.test(vbs),
    'window-style 0 (bilkul na dikhao) aur True (agent ke saath zinda - chowkidar)');
ok(/Do\b[\s\S]*WScript\.Sleep 5000[\s\S]*Loop/.test(vbs), 'agent gire to 5 s baad dobara');
ok(/If rc = 0 Or rc = 4 Then Exit Do/.test(vbs),
    'saaf band (0 - doosra agent chal raha) ya pair hi nahi (4) par dobara nahi - warna har 5 s bekaar');
ok(/stop\.flag/.test(vbs) && /stop\.flag/.test(iss), 'upgrade/uninstall stop.flag se chowkidar rokta hai');

/* ── 3. Install ke baad JAANCH, sirf "ho gaya" nahi ────────────────────── */

ok(/function AgentRunning\(\)/.test(iss) && /AgentStarted := AgentRunning\(\)/.test(iss),
    'installer khud dekhe ke agent chal para - aur DOBARA, kyunke pair na hua agent ek lamhe chal kar band hota hai');
ok(/PAIRING NAHI HUI/.test(iss) && /agent\.log/.test(iss), 'nakami par aakhri safha saaf wajah bataye');

/* ── 4. Pairing bachi rahe; do agent kabhi nahi ────────────────────────── */

ok(/Result := HasPrefill\(\) or HasConfig\(\)/.test(iss),
    'pehle se paired PC par code nahi maangna - naya code = naya agent record (KF ka 401 wala bhoot)');
ok(/FileCopy\(LegacyConfigPath\(\), NewConfigPath\(\), False\)/.test(iss)
   && iss.indexOf('FileCopy(LegacyConfigPath()') < iss.indexOf("ShellExec('runas'"),
    'purani pairing naye ghar me copy - purana hatane se PEHLE');
ok(/Do agent ek saath nahi chal sakte/.test(iss), 'purana na hate to install ruke - parchi do dafa nahi');

/* ── 5. Shipped scripts PURE ASCII ─────────────────────────────────────── */

for (const [name, text] of [['run-hidden.vbs', vbs], ['remove-everything.cmd', fs.readFileSync(cmdPath, 'utf8')]]) {
    const bad = [...text].filter((c) => c.charCodeAt(0) > 127);
    eq(bad.length, 0,
        `${name} me ${bad.length} non-ASCII character hain. Windows script host / cmd BOM ke baghair `
        + 'file ko ANSI parhte hain - ek em dash poori script tor deta hai.');
}

/* ── 6. Single instance ka pehra — WAQAI chala kar ─────────────────────── */

const sandbox = fs.mkdtempSync(path.join(os.tmpdir(), 'bpa-lock-'));
process.env.ProgramData = path.join(sandbox, 'ProgramData');
process.env.LOCALAPPDATA = path.join(sandbox, 'Local');

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

    // AGENT-UPGRADE-1 — 2.6.x ka agent `ProgramData` wala lock likhta hai aur naye ka lock nahi dekhta. Naya hi
    // ruke, warna dono chalein aur parchi do dafa chhape.
    const legacyLock = path.join(path.dirname(bpa.legacyConfigPath()), 'agent.lock');
    fs.mkdirSync(path.dirname(legacyLock), { recursive: true });
    fs.writeFileSync(legacyLock, JSON.stringify({ pid: alive.pid }));
    eq(bpa.anotherAgentIsRunning(), alive.pid, 'purana (2.6.x) agent zinda ho to naya ruke');
    fs.unlinkSync(legacyLock);
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
