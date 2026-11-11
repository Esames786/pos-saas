/**
 * Bingoo POS Local Print Agent
 *
 * Runs inside the shop LAN. Polls the cloud app for queued print jobs and
 * sends raw ESC/POS text to LAN printers via TCP socket on port 9100.
 *
 * Commands:
 *   node print-agent.js setup    — interactive pairing (Server URL + 6-digit code)
 *   node print-agent.js run      — start the agent (default when config exists)
 *   node print-agent.js status   — show config source + one heartbeat check
 *   node print-agent.js --help
 *
 * Config resolution order:
 *   1. Env vars (developer/manual mode — unchanged from v1):
 *      POS_BASE_URL, POS_PRINT_AGENT_CODE, POS_PRINT_AGENT_TOKEN
 *   2. %LOCALAPPDATA%\BingooPrintAgent\config.json  (Windows, 2.7.0+ per-user install — no admin)
 *   3. %ProgramData%\BingooPrintAgent\config.json   (Windows, 2.6.x machine-wide install — copied to 2 once)
 *   4. ~/.bingoo-print-agent/config.json            (macOS/Linux)
 *   5. ./.agent-config.json                          (local dev fallback)
 *
 * Requires Node.js 18+
 */

const net      = require('net');
const os       = require('os');
const fs       = require('fs');
const path     = require('path');
const readline = require('readline');
const http     = require('http');
const https    = require('https');
const { URL }  = require('url');

const AGENT_VERSION = '2.7.0';

/**
 * A sleeping printer does not answer a connect at all, so discovering that must be CHEAP: fail in
 * 4s and retry, rather than wait 15s. Once connected the printer is awake and a long ticket is
 * allowed to spool.
 */
const CONNECT_TIMEOUT_MS  = 4000;
const TRANSFER_TIMEOUT_MS = 15000;

/**
 * Hold every known printer awake. Thermal printers drop their network interface on idle, and the
 * first ticket afterwards pays the wake-up — measured at Khatri as a 17s and a 34s KOT while a
 * receipt to the same printer moments later took 3s. A cheap connect every 20s keeps them from
 * ever going to sleep, so the kitchen slip is not the thing that wakes them.
 */
const KEEP_AWAKE_MS = 20000;
const knownPrinters = new Map();   // "ip:port" -> { ip, port }
let keepAwakeRunning = false;

/* ── HTTP helper (http/https module — stable on every Node incl. the bundled
 *    runtime, unlike Node 18's experimental global fetch) ────────────────── */

function httpJson(method, urlString, { headers = {}, body = null } = {}) {
    return new Promise((resolve, reject) => {
        let url;
        try { url = new URL(urlString); } catch (e) { reject(new Error(`Bad URL: ${urlString}`)); return; }

        const payload = body === null ? null : (typeof body === 'string' ? body : JSON.stringify(body));
        const lib = url.protocol === 'https:' ? https : http;

        const req = lib.request({
            method,
            hostname: url.hostname,
            port:     url.port || (url.protocol === 'https:' ? 443 : 80),
            path:     url.pathname + url.search,
            headers:  Object.assign({ 'Accept': 'application/json' },
                          payload !== null ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {},
                          headers),
            timeout:  15000,
        }, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                let json = null;
                try { json = data ? JSON.parse(data) : null; } catch (_) { /* non-JSON body */ }
                resolve({ status: res.statusCode, ok: res.statusCode >= 200 && res.statusCode < 300, json, text: data });
            });
        });

        req.on('error',   (err) => reject(err));
        req.on('timeout', () => { req.destroy(); reject(new Error('Request timed out.')); });

        if (payload !== null) req.write(payload);
        req.end();
    });
}

/* ── Config locations ─────────────────────────────────────────────────── */

// Real folder the program lives in. Under pkg, __dirname is a virtual snapshot
// path, so sibling files (SERVER.txt) must be read next to the .exe instead.
function exeDir() {
    return process.pkg ? path.dirname(process.execPath) : __dirname;
}

function configCandidates() {
    const candidates = [];
    // AGENT-UPGRADE-1 (2.7.0, B2): per-user install — config usi user ke folder me, admin ki zaroorat nahi.
    if (process.platform === 'win32' && process.env.LOCALAPPDATA) {
        candidates.push(path.join(process.env.LOCALAPPDATA, 'BingooPrintAgent', 'config.json'));
    }
    // 2.6.x ka machine-wide config — sirf PARHNE ke liye; pehli dafa upar wali jagah copy ho jata hai (pairing bachi).
    if (process.platform === 'win32' && process.env.ProgramData) {
        candidates.push(legacyConfigPath());
    }
    candidates.push(path.join(os.homedir(), '.bingoo-print-agent', 'config.json'));
    candidates.push(path.join(exeDir(), '.agent-config.json'));
    return candidates;
}

function preferredConfigPath() {
    return configCandidates()[0];
}

/** 2.6.x (machine-wide, admin se install) ka config — purani pairing yahin hai. */
function legacyConfigPath() {
    return path.join(process.env.ProgramData || 'C:\\ProgramData', 'BingooPrintAgent', 'config.json');
}

function lockPath() {
    return path.join(path.dirname(preferredConfigPath()), 'agent.lock');
}

/* ── Ek waqt me ek hi agent ─────────────────────────────────────────────
 *
 * AGENT-SINGLE-INSTANCE-1 (16 Oct). Pehle ye pehra tha hi nahi, aur do
 * copies ka chalna aasan tha: task khud agent chalata hai, aur user ko
 * nazar na aaye to wo exe par dobara click kar deta hai.
 *
 * Do copies ka nuqsan khamosh hai. Dono EK hi agent ke credentials se
 * server se baat karti hain, is liye server unhe alag nahi kar sakta —
 * ek hi parchi dono ko mil sakti hai aur DO BAAR chhap sakti hai.
 *
 * Pehra ek lock file se hai jis me chalne wale ki PID likhi hoti hai.
 * Sirf "file mojood hai" kaafi nahi: bijli jane par file reh jati hai aur
 * phir agent kabhi chalta hi nahi. Is liye PID ko parkha jata hai — aur
 * Windows par uska NAAM bhi, kyun ki PID dobara kisi aur program ko mil
 * sakti hai aur tab ye pehra bila wajah agent ko rok deta.
 */
function processLooksLikeAgent(pid) {
    if (process.platform !== 'win32') {
        try { process.kill(pid, 0); return true; } catch (err) { return err.code === 'EPERM'; }
    }
    try {
        const out = require('child_process').execFileSync(
            'tasklist', ['/FI', `PID eq ${pid}`, '/FO', 'CSV', '/NH'],
            { encoding: 'utf8', timeout: 10000, windowsHide: true },
        );
        const name = (out.split(',')[0] || '').replace(/"/g, '').toLowerCase();
        return name.includes('bingooprintagent') || name === 'node.exe';
    } catch {
        // tasklist hi na chale to pehre ko shak ka faida mat do: chalne do.
        return false;
    }
}

function anotherAgentIsRunning() {
    // AGENT-UPGRADE-1 — apna lock AUR 2.6.x ka (`ProgramData`) lock dono. Purana agent naye ka lock nahi dekhta, is
    // liye naya hi rukta hai: do agent ek saath = ek parchi do dafa.
    for (const file of [lockPath(), path.join(path.dirname(legacyConfigPath()), 'agent.lock')]) {
        let raw;
        try { raw = fs.readFileSync(file, 'utf8'); } catch { continue; }

        let pid = 0;
        try { pid = Number(JSON.parse(raw).pid) || 0; } catch { continue; }
        if (!pid || pid === process.pid) { continue; }

        if (processLooksLikeAgent(pid)) { return pid; }
    }

    return otherAgentProcess();
}

/**
 * Packaged exe ho to `BingooPrintAgent.exe` ka koi AUR process (2.6.5 aur pehle wale lock likhte hi nahi the).
 * Dev me (`node print-agent.js`) ye jaanch nahi — wahan har node process agent nahi.
 */
function otherAgentProcess() {
    if (process.platform !== 'win32' || !process.pkg) { return null; }
    // SIRF exe tests ke liye: developer ke PC par aksar ek asli agent pehle se chal raha hota hai (11 Nov: is laptop
    // par demo tenant ka service agent). Installer / chowkidar ye kabhi set nahi karte — client PC par pehra poora.
    if (process.env.BINGOO_AGENT_ALLOW_OTHER === '1') { return null; }
    try {
        const out = require('child_process').execFileSync(
            'tasklist', ['/FI', 'IMAGENAME eq BingooPrintAgent.exe', '/FO', 'CSV', '/NH'],
            { encoding: 'utf8', timeout: 10000, windowsHide: true },
        );
        for (const line of out.split(/\r?\n/)) {
            const cols = line.split('","').map((c) => c.replace(/"/g, ''));
            const pid = Number(cols[1]);
            if (pid && pid !== process.pid && /bingooprintagent/i.test(cols[0] || '')) { return pid; }
        }
    } catch { /* tasklist na mile to lock wali jaanch kaafi */ }

    return null;
}

function claimSingleInstanceLock() {
    const file = lockPath();
    try { fs.mkdirSync(path.dirname(file), { recursive: true }); } catch { /* chhor do */ }
    try {
        fs.writeFileSync(file, JSON.stringify({
            pid: process.pid, version: AGENT_VERSION, startedAt: new Date().toISOString(),
        }));
    } catch { return; }

    const drop = () => { try { fs.unlinkSync(file); } catch { /* chhor do */ } };
    process.on('exit', drop);
    for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
        process.on(sig, () => { drop(); process.exit(0); });
    }
}

function logPath() {
    return path.join(path.dirname(preferredConfigPath()), 'agent.log');
}

function loadConfig() {
    // 1. Env vars win (legacy/manual mode — do not break existing agents).
    if (process.env.POS_BASE_URL && process.env.POS_PRINT_AGENT_CODE && process.env.POS_PRINT_AGENT_TOKEN) {
        return {
            source:    'env',
            baseUrl:   process.env.POS_BASE_URL,
            agentCode: process.env.POS_PRINT_AGENT_CODE,
            token:     process.env.POS_PRINT_AGENT_TOKEN,
            pollMs:    Number(process.env.POS_PRINT_POLL_MS || 3000),
        };
    }

    for (const file of configCandidates()) {
        try {
            if (fs.existsSync(file)) {
                const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
                if (raw.baseUrl && raw.agentCode && raw.token) {
                    adoptLegacyConfig(file, raw);
                    return { source: file, pollMs: 3000, ...raw };
                }
            }
        } catch (_) { /* unreadable/corrupt file → try next */ }
    }

    return null;
}

/**
 * AGENT-UPGRADE-1 — purani pairing naye ghar me. 2.6.x ka config `ProgramData` me tha (admin se likha hua); 2.7.0
 * per-user hai. Pehli dafa wohi token / agent code `LOCALAPPDATA` me copy ho jata hai — na naya pairing code, na naya
 * agent record (Kashif Food ka "401 wala doosra agent" isi nayi pairing ka natija tha). Copy na ho sake to bhi purana
 * file parh kar agent chalta rehta hai.
 */
function adoptLegacyConfig(foundAt, raw) {
    const preferred = preferredConfigPath();
    if (path.resolve(foundAt) === path.resolve(preferred) || fs.existsSync(preferred)) {
        return;
    }
    try {
        fs.mkdirSync(path.dirname(preferred), { recursive: true });
        fs.writeFileSync(preferred, JSON.stringify({ ...raw, migratedFrom: foundAt, migratedAt: new Date().toISOString() }, null, 2), { mode: 0o600 });
    } catch (_) { /* purana file kaafi hai */ }
}

function saveConfig(config) {
    const file = preferredConfigPath();
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(config, null, 2), { mode: 0o600 });
    return file;
}

function log(line) {
    const stamped = `[${new Date().toISOString()}] ${line}`;
    console.log(stamped);
    try {
        fs.mkdirSync(path.dirname(logPath()), { recursive: true });
        fs.appendFileSync(logPath(), stamped + '\n');
    } catch (_) { /* logging must never crash the agent */ }
}

/* ── Pairing (setup command) ──────────────────────────────────────────── */

function ask(question, fallback) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise((resolve) => {
        rl.question(question, (answer) => {
            rl.close();
            resolve((answer || '').trim() || fallback || '');
        });
    });
}

function argValue(flag) {
    const idx = process.argv.indexOf(flag);
    return idx !== -1 ? (process.argv[idx + 1] || '') : '';
}

async function setup() {
    console.log('=== Bingoo Print Agent — Setup ===');

    // Non-interactive mode (installer silent flow / automation):
    //   print-agent setup --server https://... --code 123456
    const argServer = argValue('--server');
    const argCode   = argValue('--code');
    if (argServer && argCode) {
        // --no-start: pair then exit (used by the installer; the auto-start
        // service launches `run` separately). Without it, pairing also starts.
        return pairAndStart(argServer.replace(/\/+$/, ''), argCode, !process.argv.includes('--no-start'));
    }

    console.log('You need the Server URL and the 6-digit pairing code from');
    console.log('Bingoo POS → Printing → Print Agents.\n');

    // SERVER.txt is dropped next to the exe by the download bundle.
    let defaultServer = '';
    try {
        const serverTxt = path.join(exeDir(), 'SERVER.txt');
        if (fs.existsSync(serverTxt)) {
            const match = fs.readFileSync(serverTxt, 'utf8').match(/https?:\/\/\S+/);
            if (match) defaultServer = match[0];
        }
    } catch (_) { /* optional helper only */ }

    const baseUrl = (await ask(`Server URL${defaultServer ? ` [${defaultServer}]` : ''}: `, defaultServer)).replace(/\/+$/, '');
    const code    = await ask('Pairing code (6 digits): ');

    return pairAndStart(baseUrl, code, true);
}

async function pairAndStart(baseUrl, code, start = true) {
    if (!baseUrl || !code) {
        console.error('Server URL and pairing code are both required.');
        process.exit(1);
    }

    const res = await httpJson('POST', `${baseUrl}/api/print-agent/pair`, {
        body: {
            pairing_code:    code,
            device_name:     os.hostname(),
            device_platform: `${os.platform()} ${os.release()}`,
            agent_version:   AGENT_VERSION,
        },
    }).catch((err) => ({ ok: false, json: { message: err.message } }));

    const body = res.json || {};

    if (!res.ok || !body.ok) {
        console.error(`\nPairing failed: ${body.message || `HTTP ${res.status}`}`);
        process.exit(1);
    }

    const file = saveConfig({
        baseUrl,
        agentCode: body.agent_code,
        token:     body.token,
        pollMs:    (body.poll_interval_seconds || 3) * 1000,
        pairedAt:  new Date().toISOString(),
    });

    console.log(`\nPaired successfully as ${body.agent_code}.`);
    console.log(`Config saved: ${file}`);

    if (!start) {
        console.log('Pairing complete. The auto-start service will keep the agent running.');
        process.exit(0);
    }

    console.log('Starting the agent now... (Ctrl+C to stop; the Windows service keeps it running after install)');
    run(loadConfig());
}

/* ── Runtime (unchanged printing behavior) ────────────────────────────── */

let CONFIG = null;

function headers() {
    return {
        'X-Print-Agent-Code':  CONFIG.agentCode,
        'X-Print-Agent-Token': CONFIG.token,
        // CATERING-SEND-TO-PRINTER-1 — ye agent A4/A5 document bhi chhap sakta
        // hai. Server SIRF usi agent ko aisi job deta hai jo ye keh chuke; jo
        // na kahe us ka purana kaam bilkul waisa hi chalta rehta hai. Isi liye
        // restaurant ke chalte hue agents (v2.5.0) mehfooz hain — unhe kuch
        // install kiye baghair ye job pahunchti hi nahi.
        // PRINT-PREPARE-1: `prepare` = screen khulte hi PDF tayyar rakh sakta hai (2.7.0+). Server sirf isi par tayyari deta hai.
        'X-Print-Agent-Caps':  'document,prepare',
        'Accept':              'application/json',
        'Content-Type':        'application/json',
    };
}

async function heartbeat() {
    const res = await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/heartbeat`, {
        headers: headers(),
        body:    {
            device_name: os.hostname(),
            device_os:   `${os.platform()} ${os.release()}`,
            local_ip:    localIp(),
            // AGENT-UPGRADE-1 — Print Agents screen par har PC ka version (pehle sirf pairing par jata tha).
            agent_version: AGENT_VERSION,
            // Live printer health from the keep-awake pokes, so the Printers screen can show each
            // printer Online/Offline + latency. Only printers we have an id for.
            printers_status: [...lastPokeStatus.values()]
                .filter((s) => s.id)
                .map((s) => ({ id: s.id, reachable: !!s.reachable, latency_ms: s.latencyMs })),
        },
    });

    if (!res.ok) {
        throw new Error(`Heartbeat failed: HTTP ${res.status} — ${(res.text || '').slice(0, 300)}`);
    }

    // Learn the printers from the server so they can be kept awake from startup — not only after
    // a ticket has already been delayed waking one up.
    //
    // Only when the server actually SENT a list. Coercing a missing key to [] would let one
    // response without it silently empty the list, and keep-awake would then quietly do nothing
    // for the rest of the day with no error anywhere.
    if (res.json && Array.isArray(res.json.printers)) {
        syncKnownPrinters(res.json.printers);
    }

    // AGENT-UPGRADE-1 — server ka poll waqt (purana server ye key nahi bhejta → jo chal raha hai wohi).
    if (res.json && res.json.poll_ms) {
        schedulePoll(res.json.poll_ms);
    }
}

function rememberPrinter(ip, port) {
    const key = `${ip}:${port || 9100}`;
    if (!knownPrinters.has(key)) {
        knownPrinters.set(key, { ip, port: port || 9100 });
    }
}

function syncKnownPrinters(printers) {
    const current = new Map();
    for (const p of printers) {
        if (!p || !p.ip) continue;
        const port = Number(p.port || 9100);
        // Keep the printer id so keep-awake pokes can be reported back per printer (live status).
        current.set(`${p.ip}:${port}`, { id: p.id ? Number(p.id) : null, ip: p.ip, port });
    }

    knownPrinters.clear();
    for (const [key, printer] of current) knownPrinters.set(key, printer);
}

// Last keep-awake poke result per printer — reported in the heartbeat so the Printers screen shows a
// live Online/Offline + latency status without the operator touching a PC. Key = "ip:port".
const lastPokeStatus = new Map();

/**
 * A poke must never make a ticket wait.
 *
 * A print tick now defers while keep-awake is running, so every millisecond spent poking is a
 * millisecond a queued KOT sits still. The printer's own connect timeout is far too generous for
 * this: on a LAN a printer that is going to answer answers in milliseconds, and one that does not
 * answer within a second is asleep or absent — either way the connect attempt has already done the
 * waking, so waiting out the full timeout buys nothing and costs the kitchen its ticket.
 */
const POKE_TIMEOUT_MS = 1200;

/** Open and immediately close a connection, purely so the printer never idles into sleep. Also
 *  returns whether the printer answered and how quickly, so keep-awake doubles as a health probe. */
function pokePrinter(ip, port) {
    return new Promise((resolve) => {
        const socket = new net.Socket();
        const started = Date.now();
        socket.setTimeout(POKE_TIMEOUT_MS);
        let settled = false;
        const finish = (reachable) => {
            if (settled) return;
            settled = true;
            socket.destroy();
            resolve({ reachable, latencyMs: reachable ? (Date.now() - started) : null });
        };
        socket.connect(port, ip, () => { socket.end(); finish(true); });
        socket.on('error',   () => finish(false));
        socket.on('timeout', () => finish(false));
    });
}

async function keepPrintersAwake() {
    // Never while printing — a poke would take the single connection the ticket needs.
    if (ticking || keepAwakeRunning || knownPrinters.size === 0) {
        return;
    }
    keepAwakeRunning = true;
    try {
        // In PARALLEL, not one after another: poking serially made the block the SUM of every
        // printer's timeout, so a second station that was switched off delayed the tickets of the
        // one that was on. Each poke's result is recorded for the heartbeat's live status.
        await Promise.all(
            [...knownPrinters.values()].map(async ({ id, ip, port }) => {
                const key = `${ip}:${port}`;
                // Under the per-printer lock so a poke never steals the socket from a live ticket or a
                // remote command; different printers still poke in parallel.
                const r = await withPrinterLock(key, () => pokePrinter(ip, port));
                lastPokeStatus.set(key, { id, reachable: r.reachable, latencyMs: r.latencyMs, at: Date.now() });
            })
        );
    } finally {
        keepAwakeRunning = false;
    }
}

async function getPendingJobs() {
    const res = await httpJson('GET', `${CONFIG.baseUrl}/api/print-agent/pending`, {
        headers: headers(),
    });

    if (!res.ok) {
        throw new Error(`Pending jobs fetch failed: HTTP ${res.status} — ${(res.text || '').slice(0, 300)}`);
    }

    return res.json || {};
}

function sendToNetworkPrinter(ip, port, payload) {
    return new Promise((resolve, reject) => {
        if (!ip) {
            reject(new Error('Printer IP address is missing.'));
            return;
        }

        const socket = new net.Socket();
        // TWO timeouts, because they guard different things.
        //
        // CONNECT is short on purpose. A sleeping printer does not answer at all, and waiting 15s
        // to discover that made every first ticket of a quiet period 15s late — the retry then
        // connected in under a second. Failing fast and retrying is far quicker than waiting.
        //
        // TRANSFER is generous: once the printer has accepted the connection it is awake, and a
        // long ticket genuinely takes time to spool.
        socket.setTimeout(CONNECT_TIMEOUT_MS);

        // Whether the ticket already went down the wire. A failure BEFORE this is safe to retry;
        // after it the printer may well have printed, and retrying would hand the customer a
        // second copy of the same bill.
        let payloadSent = false;

        socket.connect(port || 9100, ip, () => {
            // Connected — the printer is awake, so allow the slower transfer budget.
            socket.setTimeout(TRANSFER_TIMEOUT_MS);
            socket.write(payload || '');
            socket.write('\n\n\n');
            payloadSent = true;
            socket.end();
        });

        socket.on('close',   resolve);
        socket.on('error',   (err) => {
            err.safeToRetry = !payloadSent;
            reject(err);
        });
        socket.on('timeout', () => {
            socket.destroy();
            const err = new Error(payloadSent
                ? 'Printer stopped responding after the ticket was sent.'
                : 'Printer connection timed out.');
            err.safeToRetry = !payloadSent;
            reject(err);
        });
    });
}

/* ─────────────────────────────────────────────────────────────────────────────
 * CATERING-SEND-TO-PRINTER-1 — A4/A5 DOCUMENT, WINDOWS KE PRINTER PAR.
 *
 * Malik: "client confuse ho raha hai, bar bar A4 / A5 select karna parta hai."
 * Server ab kagaz ka size job ke saath bhejta hai; yahan us par amal hota hai.
 *
 * Ye hissa THERMAL WALE RAASTE SE BILKUL ALAG hai, aur jaan-boojh kar: upar wala
 * `sendToNetworkPrinter()` ek harf nahi badla. Restaurant ke chalte hue agents
 * wohi purana code chalate rehte hain, aur agar kal koi un par ye nayi version
 * laga bhi de to un ke KOT ka raasta waisa ka waisa hai.
 *
 * ── TEEN QADAM ───────────────────────────────────────────────────────────────
 *
 * 1. Job ka `raw_payload` POORA HTML hota hai (server ne qatar me lagate waqt
 *    jama kar diya tha), wo ek asthai .html file me likha jata hai.
 *
 * 2. CHROME se PDF. Yehi is poore design ki jaan hai: wohi Chrome jo is PC par
 *    aaj bhi chhapta hai. Server par PDF banane ki koshish Urdu tod deti hai
 *    (dompdf me complex-script shaping hai hi nahi), is liye render WAHIN hota
 *    hai jahan wo pehle se durust hai.
 *
 *    `--print-background` LAZMI hai: us ke baghair kitchen sheet par PARTY/OWN
 *    ka kala dabba safed chhapta hai aur bawarchi ko maal ka malik pata hi nahi
 *    chalta.
 *
 * 3. PDF ko NAAM WALE Windows printer par bhejna. Windows me is ka koi built-in
 *    tareeqa nahi jo kisi KHAAS printer par chup chaap bhej sake — "print" verb
 *    sirf default printer par jata hai. Is liye neeche teen maaruf utilities me
 *    se jo mile wo chalti hai, aur koi na mile to SAAF inkaar — kyunke khamoshi
 *    se kuch na chhapna sab se bura natija hai.
 * ───────────────────────────────────────────────────────────────────────────*/

const { execFile } = require('child_process');

/**
 * Har wo browser jo is PC par mojood hai — tarteeb se, saare ke saare.
 *
 * Pehle sirf PEHLA mila browser lauta kar bas kar dia jata tha. 9 Oct ko Kashif
 * Kitchen par us ki qeemat chukani pari: wahan ka Chrome har command ko pehle se
 * khuli Chrome ko thama kar khud nikal jata tha ("Opening in existing browser
 * session"), aur USI PC par Edge bhi mojood tha jo kaam kar sakta tha — magar
 * agent wahan tak pahunchta hi nahi tha, kyunke "Chrome mil gaya" tha.
 *
 * Ab sab lautte hain aur bari bari aazmaye jate hain. `BINGOO_CHROME` phir bhi
 * sab se pehle: jahan malik ne jaan bujh kar koi browser chuna ho, wahan hamara
 * andaza us par bhaari na pare.
 */
function findBrowsers() {
    const candidates = [
        process.env.BINGOO_CHROME,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ].filter(Boolean);

    const seen = new Set();
    const found = [];
    for (const p of candidates) {
        const key = String(p).toLowerCase();
        if (seen.has(key)) { continue; }
        seen.add(key);
        try { if (fs.existsSync(p)) { found.push(p); } } catch { /* chhor do */ }
    }

    return found;
}

/**
 * Browser ka version — FILE SYSTEM se, command se nahi.
 *
 * `chrome.exe --version` Windows par bharosemand nahi: Chrome GUI app hai,
 * console se judta hi nahi. 9 Oct ko us ne version ke bajaye "Opening in
 * existing browser session." lauta diya — wo jumla error me chhap kar hi asal
 * wajah tak le gaya, magar version phir bhi na mila.
 *
 * Chrome aur Edge dono apne Application folder me version ke naam ka folder
 * rakhte hain (jaise `155.0.8059.40`). Wohi sach hai, aur wo kisi chalte hue
 * process par mun'hasir nahi.
 */
function browserVersion(exe) {
    try {
        const v = fs.readdirSync(path.dirname(exe))
            .filter((n) => /^\d+\.\d+\.\d+\.\d+$/.test(n))
            .sort()
            .pop();
        if (v) { return v; }
    } catch { /* chhor do */ }

    return 'version maloom nahi';
}

/**
 * Kya ye agent ADMINISTRATOR ke taur par chal raha hai?
 *
 * ── 9 OCTOBER, POORA DIN ─────────────────────────────────────────────────
 *
 * Kashif Kitchen par catering ke document chhap hi nahi rahe thay. Chrome
 * `--headless=new` par ek second me `exit 0` de kar nikal jata tha, bina PDF
 * banaye aur bina kisi shikayat ke. Edge ne bhi bilkul wohi kiya. Browser naye
 * thay (Chrome 154, Edge 155), koi enterprise policy nahi thi, `--user-data-dir`
 * bhi diya ja raha tha.
 *
 * Asal wajah: agent shuru se ADMINISTRATOR par chal raha tha — pehle "Administrator:
 * Windows PowerShell" me, phir scheduled task `RunLevel Highest` par. Chromium
 * elevated process se chalne par kaam karne se inkar kar deta hai.
 *
 * Sabit aise hua: WOHI command, WOHI PC, browser khule hue, sirf aam (non-admin)
 * PowerShell me — aur PDF foran ban gayi (12,409 bytes).
 *
 * Ye jaanch is liye hai ke agli baar ye poora din dobara na lage. Elevated hone
 * par agent shuru me hi saaf keh dega, aur document ki nakami par ise sab se
 * pehla mumkina sabab bana kar pesh karega.
 *
 * ── IS JAANCH KI HADD, SAAF SAAF ─────────────────────────────────────────
 *
 * Likhte waqt ye Medium (`S-1-16-8192`) par aazmai gayi aur us ne theek `false`
 * kaha — aur PowerShell ke apne `IsInRole(Administrator)` ne bhi wohi kaha. High
 * (`S-1-16-12288`) wali shakh asal machine par nahi aazmai ja saki, kyunke us ke
 * liye khud elevated hona parta. Wo SID Windows ka tay-shuda adad hai, magar
 * yahan likha ja raha hai ke wo shakh JAANCHI NAHI GAYI.
 *
 * Isi liye ye kabhi kisi kaam ko ROKTI nahi — sirf batati hai. Galat "haan" se
 * bhi koi parchi nahi rukegi.
 */
function isElevated() {
    try {
        // Poora raasta jaan bujh kar: kisi PC ke PATH par koi aur `whoami` ho
        // sakta hai (mere apne machine par Git ka Unix wala saamne aa gaya tha,
        // aur jaanch chup chaap "pata nahi" dene lagi thi).
        const exe = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'whoami.exe');
        const out = require('child_process')
            .execFileSync(exe, ['/groups', '/fo', 'csv'], { timeout: 10000, windowsHide: true })
            .toString();

        if (out.includes('S-1-16-12288')) { return true; }   // High   = elevated
        if (out.includes('S-1-16-16384')) { return true; }   // System = elevated
        if (out.includes('S-1-16-8192'))  { return false; }  // Medium = aam user
    } catch { /* chhor do */ }

    return null;   // pata nahi chala — aur "pata nahi" ko "haan" nahi banaya jata
}
/**
 * PDF ko kisi KHAAS printer par bhejne wali utility.
 *
 * Tarteeb soch kar hai: SumatraPDF sab se halka aur bharosemand hai aur agent
 * ke saath rakha ja sakta hai; PDFtoPrinter doosra aam hal hai; Acrobat aakhir
 * me, kyunke wo dheema hai aur kabhi kabhi window khol deta hai.
 */
/**
 * Job ka kagaz SumatraPDF ki zubaan me — aur ye satar hi wo cheez thi jo kam thi.
 *
 * ── 10/11 OCTOBER ─────────────────────────────────────────────────────────
 *
 * Kitchen sheet A5 par bheji ja rahi thi aur A4 par nikal rahi thi. Malik ne
 * theek pakra. Jaanch par hamari taraf sab durust nikla: job me `a5_portrait`
 * tha aur HTML me `@page { size: A5 portrait }` — yani PDF waqai A5 ki banti
 * thi.
 *
 * Kami aage thi: SumatraPDF ko printer ke liye kagaz ka naap bataya hi nahi
 * jata tha, is liye Windows printer ki APNI default (A4) lag jati thi. Natija
 * — A5 ka naqsha A4 ke kaghaz par.
 *
 * `-print-settings paper=A5` DEVMODE me `DM_PAPERSIZE` set karta hai, yani
 * printer ko kehta hai ke kaun sa kaghaz uthana hai. `noscale` is liye ke
 * content ko sheet par fit karne ke chakkar me khincha na jaye — naap wohi rahe
 * jo document ka hai.
 *
 * Qeematein andaze se nahi li gayin: SumatraPDF 3.5.2 ki binary me paper ki
 * fehrist dekhi gayi (A3/A5/letter/legal wagaira null-terminated table me) aur
 * `-print-settings` ke chunao bhi (noscale, portrait, landscape, bin=, paper=).
 *
 * Na-pehchana kagaz ho to `null` — yani koi setting nahi jati aur purana
 * rawaiyya chalta hai. Ek na-samjhi ki wajah se parchi rokna us masle se bara
 * masla hai jo wo batati.
 */
function printSettingsFor(job) {
    const paper = String(((job || {}).payload || {}).paper || '').toLowerCase();
    const m = paper.match(/^(a3|a4|a5|a6|letter|legal)(?:[_-](portrait|landscape))?$/);
    if (!m) { return null; }

    const parts = ['paper=' + (m[1].length === 2 ? m[1].toUpperCase() : m[1])];
    if (m[2]) { parts.push(m[2]); }
    parts.push('noscale');

    return parts.join(',');
}
function findPdfPrinter() {
    const sumatraArgs = (f, p, settings) => (settings
        ? ['-print-to', p, '-print-settings', settings, '-silent', '-exit-when-done', f]
        : ['-print-to', p, '-silent', '-exit-when-done', f]);

    const options = [
        { exe: process.env.BINGOO_PDF_PRINTER, args: (f, p) => ['-print-to', p, '-silent', f] },
        // `-print-settings` SIRF SumatraPDF samajhta hai. PDFtoPrinter aur
        // Acrobat ko ye dena un ko tor deta, is liye wo satrein waisi hi hain.
        { exe: path.join(exeDir(), 'SumatraPDF.exe'), args: sumatraArgs },
        { exe: 'C:\\Program Files\\SumatraPDF\\SumatraPDF.exe', args: sumatraArgs },
        { exe: path.join(exeDir(), 'PDFtoPrinter.exe'), args: (f, p) => [f, p] },
        { exe: 'C:\\Program Files\\Adobe\\Acrobat DC\\Acrobat\\Acrobat.exe', args: (f, p) => ['/t', f, p] },
        { exe: 'C:\\Program Files (x86)\\Adobe\\Acrobat Reader DC\\Reader\\AcroRd32.exe', args: (f, p) => ['/t', f, p] },
    ].filter((o) => o.exe);

    return options.find((o) => { try { return fs.existsSync(o.exe); } catch { return false; } }) || null;
}

function runExe(exe, args, timeoutMs) {
    return new Promise((resolve, reject) => {
        execFile(exe, args, { timeout: timeoutMs, windowsHide: true }, (err, _stdout, stderr) => {
            if (err) {
                reject(new Error(
                    `${path.basename(exe)}: ${err.message}${stderr ? ' — ' + String(stderr).slice(0, 200) : ''}`
                ));

                return;
            }
            resolve();
        });
    });
}

/**
 * Ek A4/A5 document chhapo. Kagaz ka size server se aata hai — operator ko
 * kabhi chunna nahi parta, aur yehi is poore kaam ki wajah thi.
 */
async function printDocumentOnWindows(job) {
    const printer    = job.printer || {};
    const targetName = printer.windows_printer_name || (job.payload || {}).windows_printer_name;

    if (!targetName) {
        throw new Error('Is printer par Windows wala naam likha hi nahi — agent usi naam se printer pehchanta hai.');
    }

    const browsers = findBrowsers();
    if (browsers.length === 0) {
        throw new Error('Chrome ya Edge is PC par nahi mila — document ka PDF usi se banta hai.');
    }

    // Printer ka wajood PEHLE — chhapne ki koshish se bhi pehle, aur PDF banane
    // se bhi pehle. Ghalat naam par 60 second Chrome chalana aur phir nakam hona
    // bekaar hai, aur 10 Oct tak us ka natija "chhap gaya" nikalta tha.
    const known = windowsPrinterExists(targetName);
    if (known && ! known.found) {
        throw new Error(
            'Is PC par "' + targetName + '" naam ka koi printer nahi hai. '
            + 'Mojood printer: ' + known.list.join(', ') + '. '
            + 'Printers screen par bilkul wohi naam likhein jo yahan hai.'
        );
    }

    const tool = findPdfPrinter();

    // PRINT-PREPARE-1 — screen khulte hi bani hui PDF mojood hai (wohi fingerprint, 10 min se kam purani)? To Chrome
    // ka kaam ho chuka: seedha printer. Wohi PDF abhi ban rahi ho to us ka intezar — dobara Chrome nahi.
    const readyPdf = await readyPdfFor((job.payload || {}).fingerprint);
    if (readyPdf) {
        log(`[READY] ${job.job_no || job.id} — tayyar PDF se, Chrome nahi chala`);
        await sendPdfToPrinter(tool, readyPdf, targetName, job);
        return;
    }

    const stamp    = `${job.job_no || job.id}-${Date.now()}`.replace(/[^A-Za-z0-9._-]/g, '-');
    const pdfPath  = path.join(os.tmpdir(), `bingoo-${stamp}.pdf`);

    try {
        await renderPdf(browsers, job.raw_payload || '', pdfPath, stamp);
        await sendPdfToPrinter(tool, pdfPath, targetName, job);
    } finally {
        // Send wali PDF har soorat me jati hai — kamyabi par bhi, nakami par bhi.
        try { if (fs.existsSync(pdfPath)) fs.unlinkSync(pdfPath); } catch { /* chhor do */ }
    }
}

/**
 * HTML → PDF, Chrome/Edge se (har browser, har headless soorat — neeche dekho). Send aur tayyari (PRINT-PREPARE-1)
 * dono yehi chalate hain, is liye kaghaz bilkul ek jaisa banta hai. PDF `pdfPath` par; HTML aur Chrome profile yahin
 * mitte hain, PDF ka faisla bulane wala karta hai.
 */
async function renderPdf(browsers, html, pdfPath, stamp) {
    const htmlPath = path.join(os.tmpdir(), `bingoo-${stamp}.html`);
    const profileDir = path.join(os.tmpdir(), `bingoo-chrome-${stamp}`);

    try {
        fs.writeFileSync(htmlPath, html, 'utf8');

        // Kagaz ka size HTML ke apne `@page` me pehle se hai aur Chrome usi ko
        // maanta hai. Yahan dobara likhna do jagah do jawab bana deta.
        // HAR BROWSER, HAR HEADLESS SOORAT — jab tak PDF na ban jaye.
        //
        // Do alag cheezein yahan ikattha hui hain, aur dono ki qeemat chukai ja
        // chuki hai:
        //
        //   1. Do headless soortein. `--headless=new` sirf Chrome 109+ samajhta
        //      hai; us se purane par wo flag bemani hai aur PDF banti hi nahi.
        //
        //   2. Har browser. 9 Oct, Kashif Kitchen: Chrome ne `--headless=new`
        //      par chup chaap 0 lauta diya aur kuch na banaya, aur purane
        //      `--headless` par non-zero de kar mar gaya — bina kisi shikayat
        //      ke. Wajah error me khud nikli: `--version` ne version ke bajaye
        //      "Opening in existing browser session." kaha, yani us PC ka
        //      chrome.exe har command pehle se khuli Chrome ko thama kar nikal
        //      jata tha. USI PC par Edge bhi mojood tha — magar agent kabhi us
        //      tak pahunchta hi nahi tha.
        //
        // Us se pehle ek aur andaza — default profile ka taala — ALAG se jaanch
        // kar GHALAT nikla tha: 19 Chrome process chalte hue bhi PDF ban jati
        // hai. Is liye `--user-data-dir` yahan "hal" ke taur par nahi, sirf is
        // liye hai ke nuqsan nahi deta aur service mode me lazmi hoga.
        const chromeArgs = (headlessFlag, prof) => [
            headlessFlag,
            '--disable-gpu',
            '--no-sandbox',
            '--user-data-dir=' + prof,
            '--disable-extensions',
            '--disable-background-networking',
            '--no-first-run',
            '--no-pdf-header-footer',
            '--print-background',          // is ke baghair parcha jhoot bolta hai
            '--print-to-pdf=' + pdfPath,
            'file:///' + htmlPath.replace(/\\/g, '/'),
        ];

        const madePdf = () => fs.existsSync(pdfPath) && fs.statSync(pdfPath).size > 0;
        const tried = [];
        let attempt = 0;

        outer:
        for (const browser of browsers) {
            for (const flag of ['--headless=new', '--headless']) {
                attempt += 1;

                // Har koshish ki apni profile — warna Chrome aur Edge ek hi
                // folder par larein ge.
                const prof  = path.join(profileDir, String(attempt));
                const label = path.basename(browser) + ' ' + flag;

                // Pehli koshish ko poora waqt, baad walon ko aadha: chaar
                // koshishein mil kar ek nakaam parchi ko chaar minute ka na
                // bana dein.
                try {
                    await runExe(browser, chromeArgs(flag, prof), attempt === 1 ? 60000 : 30000);
                } catch (err) {
                    tried.push(label + ': ' + err.message);
                }
                if (madePdf()) { break outer; }
                tried.push(label + ': PDF nahi bani');
                try { if (fs.existsSync(pdfPath)) { fs.unlinkSync(pdfPath); } } catch { /* chhor do */ }
            }
        }

        if (!madePdf()) {
            // Har browser ka naam aur version saath jaata hai. Akela "PDF nahi
            // bani" ek poora chakkar khaa gaya tha, kyunke us se ye pata hi
            // nahi chalta tha ke kyun.
            const seen = browsers.map((b) => b + ' (' + browserVersion(b) + ')');

            // Elevation pehle, kyunke 9 Oct ko yehi wajah thi aur yehi aakhir me
            // pata chali. Jab tak ye satar yahan nahi thi, error ki har baat
            // sach thi magar kisi ne asal sabab ki taraf ishara nahi kiya.
            const elevated = isElevated() === true
                ? 'AGENT ADMINISTRATOR PAR CHAL RAHA HAI - Chrome aise kaam karne se inkar karta hai. '
                  + 'Scheduled task ko RunLevel Limited par daalein (Highest par nahi). '
                : '';

            throw new Error(
                elevated
                + 'Kisi bhi browser se PDF nahi bani. Mojood: ' + seen.join(', ') + '. '
                + 'Koshishein: ' + tried.join(' | ')
            );
        }
    } finally {
        // Asthai HTML har soorat me jati hai — kamyabi par bhi, nakami par bhi.
        try { if (fs.existsSync(htmlPath)) fs.unlinkSync(htmlPath); } catch { /* chhor do */ }
        // Aur wo asthai Chrome profile bhi. Ye chhorte jana ek khamosh kharabi
        // hoti: har parche par kuch MB `temp` me jama hote rehte aur mahinon
        // baad disk bhar jati, bina kisi ko wajah bataye.
        try { fs.rmSync(profileDir, { recursive: true, force: true }); } catch { /* chhor do */ }
    }
}

/** Bani hui PDF ko Windows printer tak — SumatraPDF (kaghaz ke naap ke saath), warna Windows ka "PrintTo". */
async function sendPdfToPrinter(tool, pdfPath, targetName, job) {
    if (tool) {
        // Kagaz ka naap printer tak pahunchana — warna wo apni default
        // (aam tor par A4) uthata hai aur A5 ka parcha A4 par nikalta hai.
        await runExe(tool.exe, tool.args(pdfPath, targetName, printSettingsFor(job)), 120000);
    } else {
        // Koi maaruf utility nahi mili — Windows ka apna "PrintTo" aazmao.
        //
        // Ye is liye hai ke malik ko us PC par kuch install na karna pare.
        // Jahan koi bhi PDF handler `printto` darj karta ho (Acrobat, aur
        // aksar Edge), wahan ye bina kisi nayi cheez ke chal jata hai.
        //
        // Bharosa iss par poora NAHI kiya ja sakta: har Windows par wo verb
        // darj nahi hota. Is liye ye aakhri koshish hai, pehli nahi — aur
        // nakami par neeche saaf likha hai ke karna kya hai.
        await printViaWindowsVerb(pdfPath, targetName);
    }
}

/* ── PRINT-PREPARE-1 — screen khulte hi PDF tayyar ─────────────────────────
 *
 * Owner: "user sirf screen khole … agent saara kaam kar le, PDF bana kar rakh le … send to network dabaye to foran
 * print". Server (`pending` ki `prepares`) sirf tab deta hai jab koi asli parcha na ho, aur sirf `prepare` kehne wale
 * agent ko. PDF `ready\<docKey>__<fingerprint>.pdf` me — Send ka parcha isi fingerprint se milta hai, is liye badla
 * hua order kabhi purani PDF se nahi chhapta. Kaghaz par "chhapne ka waqt" PDF ke ANDAR hai, is liye Send sirf
 * 10 min se taaza PDF leta hai (server 9 min par khud nayi banwata hai).
 *
 * Owner ki shart "data PC pe jama na ho": ek document ki nayi PDF bante hi us ki purani mitti hai; `cleanupFiles()`
 * 30 din se purani aur 500 MB se upar wali mitata hai, aur `%TEMP%` ki bachi hui `bingoo-*` cheezen bhi.
 */
const READY_FRESH_MS   = 10 * 60 * 1000;
const READY_KEEP_MS    = 30 * 24 * 60 * 60 * 1000;
const READY_MAX_BYTES  = 500 * 1024 * 1024;
const TEMP_KEEP_MS     = 24 * 60 * 60 * 1000;
const CLEANUP_EVERY_MS = 6 * 60 * 60 * 1000;

const inFlightPrepares = new Map();   // fingerprint -> Promise (kabhi reject nahi)
let prepareChain = Promise.resolve(); // ek waqt me ek tayyari — Chrome ek ek kar ke

function readyDir() {
    return path.join(path.dirname(preferredConfigPath()), 'ready');
}

function isFingerprint(fp) {
    return typeof fp === 'string' && /^[a-f0-9]{64}$/.test(fp);
}

function safeDocKey(docKey) {
    return String(docKey || 'doc').replace(/[^A-Za-z0-9_-]/g, '-').slice(0, 80);
}

function readyFileFor(fingerprint) {
    try {
        const name = fs.readdirSync(readyDir()).find((n) => n.endsWith(`__${fingerprint}.pdf`));
        return name ? path.join(readyDir(), name) : null;
    } catch {
        return null;
    }
}

/** Send ke liye tayyar PDF — sirf wohi fingerprint, aur 10 min se taaza (kaghaz ka waqt). Ban rahi ho to intezar. */
async function readyPdfFor(fingerprint) {
    if (!isFingerprint(fingerprint)) { return null; }

    const inFlight = inFlightPrepares.get(fingerprint);
    if (inFlight) { await inFlight; }

    const file = readyFileFor(fingerprint);
    if (!file) { return null; }
    try {
        const st = fs.statSync(file);
        if (st.size > 0 && Date.now() - st.mtimeMs <= READY_FRESH_MS) { return file; }
    } catch { /* gayab ho gayi — Chrome se bana lo */ }

    return null;
}

function enqueuePrepares(list) {
    for (const p of list) {
        if (!p || !isFingerprint(p.fingerprint) || inFlightPrepares.has(p.fingerprint)) { continue; }
        const settled = prepareChain.then(() => preparePdf(p)).catch(() => {});
        inFlightPrepares.set(p.fingerprint, settled);
        prepareChain = settled.then(() => { inFlightPrepares.delete(p.fingerprint); });
    }
}

async function preparePdf(p) {
    const started = Date.now();
    const key = safeDocKey(p.doc_key);
    const dir = readyDir();
    const finalPath = path.join(dir, `${key}__${p.fingerprint}.pdf`);
    const stamp = `prep-${p.id}-${Date.now()}`;
    const tmpPath = path.join(dir, `.${stamp}.pdf`);

    try {
        const browsers = findBrowsers();
        if (browsers.length === 0) {
            throw new Error('Chrome ya Edge is PC par nahi mila — document ka PDF usi se banta hai.');
        }
        fs.mkdirSync(dir, { recursive: true });
        await renderPdf(browsers, p.html || '', tmpPath, stamp);

        // Isi document (zubaan samet) ki purani PDF — order badla ya waqt purana hua — ab bekaar.
        for (const n of fs.readdirSync(dir)) {
            if (n.startsWith(`${key}__`) && n !== path.basename(finalPath)) {
                try { fs.unlinkSync(path.join(dir, n)); } catch { /* chhor do */ }
            }
        }
        fs.renameSync(tmpPath, finalPath);

        await postPrepareResult(p.id, true);
        log(`[PREP]  ${p.doc_key} tayyar (${Date.now() - started} ms)`);
    } catch (err) {
        try { if (fs.existsSync(tmpPath)) fs.unlinkSync(tmpPath); } catch { /* chhor do */ }
        log(`[PREP]  ${p.doc_key} nahi bani: ${err.message}`);
        try { await postPrepareResult(p.id, false, err.message); } catch { /* server ko na bata sake — Send phir bhi chalega */ }
    }
}

async function postPrepareResult(id, ok, message) {
    const res = await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/prepares/${id}/${ok ? 'ready' : 'failed'}`, {
        headers: headers(),
        body:    ok ? {} : { error_message: String(message || 'failed').slice(0, 1900) },
    });
    if (!res.ok) {
        throw new Error(`prepare ${ok ? 'ready' : 'failed'}: HTTP ${res.status}`);
    }
}

/** PC par kuch jama na ho: `ready\` 30 din / 500 MB, `.tmp` aur `%TEMP%\bingoo-*` 1 din. */
function cleanupFiles() {
    try {
        const dir = readyDir();
        if (fs.existsSync(dir)) {
            const now = Date.now();
            const files = fs.readdirSync(dir).map((n) => {
                const f = path.join(dir, n);
                try {
                    const st = fs.statSync(f);
                    return st.isFile() ? { f, n, mtime: st.mtimeMs, size: st.size } : null;
                } catch { return null; }
            }).filter(Boolean);

            let removed = 0;
            const left = [];
            for (const x of files) {
                const keep = x.n.startsWith('.') ? TEMP_KEEP_MS : READY_KEEP_MS;
                if (now - x.mtime > keep) {
                    try { fs.unlinkSync(x.f); removed++; } catch { left.push(x); }
                } else {
                    left.push(x);
                }
            }
            left.sort((a, b) => a.mtime - b.mtime);
            let total = left.reduce((s, x) => s + x.size, 0);
            while (total > READY_MAX_BYTES && left.length > 0) {
                const x = left.shift();
                try { fs.unlinkSync(x.f); total -= x.size; removed++; } catch { /* chhor do */ }
            }
            if (removed > 0) { log(`[CLEAN] ready: ${removed} purani file(s) hata di`); }
        }

        let temp = 0;
        const tmp = os.tmpdir();
        for (const n of fs.readdirSync(tmp)) {
            if (!n.startsWith('bingoo-')) { continue; }
            const f = path.join(tmp, n);
            try {
                if (Date.now() - fs.statSync(f).mtimeMs > TEMP_KEEP_MS) {
                    fs.rmSync(f, { recursive: true, force: true });
                    temp++;
                }
            } catch { /* chhor do */ }
        }
        if (temp > 0) { log(`[CLEAN] temp: ${temp} purani cheez(en) hata di`); }
    } catch (err) {
        log(`[CLEAN] ${err.message}`);
    }
}

/**
 * Windows ka apna "PrintTo" — jo bhi app PDF ka handler hai, usi se.
 *
 * `-Wait` lazmi hai: us ke baghair PowerShell foran laut aata hai aur hum
 * neeche wali `finally` me PDF mita dete hain — spool hone se PEHLE. Natija
 * aadha chhapa hua kaghaz, ya kuch bhi nahi.
 *
 * Us ke baad bhi thora thehrna parta hai, kyunke kuch handler spooler ko kaam
 * dene ke baad apne aap band ho jate hain. Teen second us ka sasta ilaj hai.
 */
function printViaWindowsVerb(pdfFile, printerName) {
    // `exit 1` JAAN BUJH KAR, aur yehi is function ki sab se ahem satar hai.
    //
    // 10 Oct: end-to-end test ne pakra ke agent ek aise printer par "chhap gaya"
    // keh raha tha jo mojood hi nahi tha. Wajah yahan thi: `Start-Process -Verb
    // PrintTo` THROW karta hai ("No application is associated with the specified
    // file for this operation") magar `powershell.exe -Command` us ke bawajood
    // EXIT 0 deta hai. `runExe` exit code dekhta hai, is liye nakami kamyabi ban
    // kar lauti — job DB me "printed" lag jati aur kisi ko kabhi pata na chalta
    // ke kaghaz nikla hi nahi.
    //
    // Chup-chaap jhooti kamyabi us nakami se BADTAR hai jo saaf nazar aaye.
    const q = (v) => String(v).replace(/'/g, "''");
    const ps = [
        '-NoProfile', '-NonInteractive', '-Command',
        "$ErrorActionPreference = 'Stop'; "
        + 'try { '
        + `$p = Start-Process -FilePath '${q(pdfFile)}' -Verb PrintTo `
        + `-ArgumentList '"${q(printerName)}"' -PassThru -ErrorAction Stop; `
        + '$p | Wait-Process -Timeout 90 -ErrorAction SilentlyContinue; '
        + 'Start-Sleep -Seconds 3; '
        + '} catch { Write-Error $_.Exception.Message; exit 1 }',
    ];

    return runExe('powershell.exe', ps, 120000).catch((err) => {
        throw new Error(
            'PDF ban gayi magar printer tak nahi pahunchi. Is PC par PDF chhapne wala koi program '
            + 'nahi hai: SumatraPDF install karein, ya SumatraPDF.exe agent ke folder me rakh dein. '
            + 'Asli wajah: ' + err.message
        );
    });
}

/**
 * Printer WAQAI mojood hai? Chhapne se PEHLE, har baar.
 *
 * 10 Oct, end-to-end test: agent ne ek aise printer par "chhap gaya" kaha jo
 * kisi machine par hai hi nahi. Us waqt tak chhapne se pehle koi jaanch thi hi
 * nahi — naam seedha Windows ko thama diya jata tha.
 *
 * Ye jaanch sab se sasti aur sab se zyada kaam ki hai: printer ka naam galat
 * likha hona sab se aam ghalti hai (`HP LaserJet P2055dn` ki jagah `HP LaserJet
 * P2055`), aur us ki nishani aur koi nahi hoti. Ab error me mojood printers ki
 * fehrist bhi jati hai, taake malik ko dhoondna na pare.
 *
 * Jaanch KHUD na chal sake to raasta rokti NAHI — `null` lauta kar aage jane
 * deti hai. Ek na-chalne wali jaanch ki wajah se parchi rokna us masle se bara
 * masla hai jo wo batati.
 */
function windowsPrinterExists(name) {
    try {
        const out = require('child_process').execFileSync('powershell.exe', [
            '-NoProfile', '-NonInteractive', '-Command',
            '(Get-Printer -ErrorAction Stop | Select-Object -ExpandProperty Name) -join "`n"',
        ], { timeout: 20000, windowsHide: true }).toString();

        const list = out.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
        if (list.length === 0) { return null; }

        return {
            found: list.some((p) => p.toLowerCase() === String(name).toLowerCase()),
            list,
        };
    } catch {
        return null;
    }
}

async function markPrinted(jobId) {
    await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/jobs/${jobId}/printed`, {
        headers: headers(),
        body:    {},
    });
}

async function markFailed(jobId, message) {
    await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/jobs/${jobId}/failed`, {
        headers: headers(),
        body:    { error_message: message },
    });
}

/**
 * Print one job. The return value tells the caller's per-printer lane whether the FAILURE (if any)
 * was the printer being unreachable — that, and only that, arms the circuit breaker. A skip
 * (browser/manual) or a config error (no IP) is not a transport failure and must never break the
 * lane for an otherwise-fine printer.
 *
 *   { printed: true,  connectionFailure: false }  — spooled to the printer
 *   { printed: false, connectionFailure: true  }  — printer unreachable after retries
 *   { printed: false, connectionFailure: false }  — skipped / misconfigured (no retry helps)
 */
async function processJob(job) {
    const printer = job.printer || {};

    if (!printer.id) {
        log(`[SKIP]  ${job.job_no}: browser/manual fallback job`);
        return { printed: false, connectionFailure: false };
    }

    // CATERING-SEND-TO-PRINTER-1 — A4/A5 document ka raasta, THERMAL WALE
    // GUARD SE PEHLE. Yahan rakhne ka matlab ye hai ke neeche ka poora thermal
    // hissa chhua hi nahi jata: wo apni shart par, apne retry par, apne lane
    // par waisa ka waisa chalta rehta hai.
    //
    // Yahan retry NAHI hai, aur ye jaan-boojh kar: thermal ka retry is liye
    // tha ke soya hua printer pehla connect kha jata hai. Yahan kuch connect
    // nahi hota — Chrome aur spooler dono isi machine par hain. Jo nakami
    // hoti hai wo asli hoti hai (printer ka naam ghalat, Chrome nahi mila),
    // aur usay teen baar dohrana sirf der karta hai.
    if (printer.printer_type === 'windows') {
        try {
            await printDocumentOnWindows(job);
            await markPrinted(job.id);
            log(`[OK]    ${job.job_no} -> ${printer.windows_printer_name} (${(job.payload || {}).paper || 'document'})`);

            return { printed: true, connectionFailure: false };
        } catch (err) {
            await markFailed(job.id, err.message);
            log(`[FAIL]  ${job.job_no}: ${err.message}`);

            return { printed: false, connectionFailure: false };
        }
    }

    if (printer.printer_type !== 'network') {
        log(`[SKIP]  ${job.job_no}: non-network printer (${printer.printer_type})`);
        return { printed: false, connectionFailure: false };
    }
    if (!printer.ip_address) {
        await markFailed(job.id, 'No IP address configured for this printer.');
        log(`[FAIL]  ${job.job_no}: No IP address configured for this printer.`);
        return { printed: false, connectionFailure: false };
    }

    // A single wifi hiccup used to kill a ticket permanently (attempts=1, no retry) — the
    // kitchen simply never got it and nobody noticed until the dashboard banner. Retry a
    // couple of times, but ONLY when the payload provably never reached the printer.
    const MAX_ATTEMPTS = 3;
    // Short waits: the retry after a wake-up connects in well under a second.
    const BACKOFF_MS = [400, 1200];
    let lastError = null;

    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        try {
            await sendToNetworkPrinter(
                printer.ip_address,
                Number(printer.port || 9100),
                job.raw_payload || ''
            );
            lastError = null;
            break;
        } catch (err) {
            lastError = err;
            if (!err.safeToRetry || attempt === MAX_ATTEMPTS) {
                break;
            }
            log(`[RETRY] ${job.job_no}: ${err.message} — attempt ${attempt + 1}/${MAX_ATTEMPTS}`);
            await new Promise((r) => setTimeout(r, BACKOFF_MS[attempt - 1] || 3000));
        }
    }

    if (lastError) {
        // Unreachable after every retry. Do NOT mark it failed — the lane PARKS (defers) it instead,
        // so a ticket for a printer that is merely switched off is never permanently failed and
        // reprints the moment the printer answers again. (A hard config error above is the only
        // markFailed.) The retries already ran, so this is a genuinely-down printer, not a blip.
        log(`[MISS]  ${job.job_no}: ${lastError.message}`);
        return { printed: false, connectionFailure: true };
    }

    await markPrinted(job.id);
    log(`[OK]    ${job.job_no} → ${printer.name || printer.ip_address}`);
    return { printed: true, connectionFailure: false };
}

/* ── Per-printer isolation + circuit breaker ──────────────────────────────
 * Jobs used to run strictly one after another (`for job of jobs: await …`).
 * Port 9100 accepts a SINGLE connection at a time, so serial-per-printer is
 * required — but running serially across DIFFERENT printers meant one offline
 * station held up every other station's tickets. A dead printer costs ~13s
 * (three 4s connect timeouts + backoff), and the whole queue waited behind it.
 * That is exactly the "one printer offline delayed all prints" complaint.
 *
 * Now every printer gets its OWN lane: lanes run in PARALLEL, jobs inside a
 * lane stay serial. One offline printer only ever delays its own tickets —
 * receipt, KOT and reminder for every other printer keep flowing.
 *
 * A circuit breaker keeps a persistently-dead printer from slowing the tick
 * cadence for the healthy ones. After CB_FAILURE_THRESHOLD failing ticks the
 * lane is skipped for CB_COOLDOWN_MS; its jobs are fast-failed (no ~13s wait
 * each) so they leave the server queue instead of starving the 10-oldest fetch,
 * then the printer is probed once when the cooldown lapses. The moment it
 * answers again the lane closes and prints normally. */
const CB_FAILURE_THRESHOLD = 2;
const CB_COOLDOWN_MS       = 30000;
const LOCAL_LANE           = '__local__';   // browser / non-network / unconfigured — never breaks
const printerHealth = new Map();            // lane key -> { fails, cooldownUntil }

/* ── Per-printer lock ──────────────────────────────────────────────────────
 * Port 9100 is a single socket, and a printer must never be touched by two
 * things at once: a Test/Reboot command opening a second connection — or
 * power-cycling the printer — WHILE a KOT is spooling would corrupt or lose the
 * ticket. Printing, keep-awake pokes and remote commands all funnel through this
 * lock, keyed on the physical printer (ip:port). Same printer → serial; DIFFERENT
 * printers → still fully parallel, so the isolation the lanes give is preserved. */
const printerLocks = new Map();   // key -> tail promise of that printer's queue

function withPrinterLock(key, fn) {
    const prev = printerLocks.get(key) || Promise.resolve();
    const run  = prev.then(() => fn());        // wait our turn, then act
    const tail = run.then(() => {}, () => {}); // errors must not poison the next in line
    printerLocks.set(key, tail);
    tail.then(() => { if (printerLocks.get(key) === tail) printerLocks.delete(key); });
    return run;
}

/** The failure domain is the physical printer (ip:port), not the terminal/order-type. */
function laneKeyOf(job) {
    const p = job.printer || {};
    if (p.id && p.printer_type === 'network' && p.ip_address) {
        return `${p.ip_address}:${Number(p.port || 9100)}`;
    }
    // CATERING-SEND-TO-PRINTER-1 — har Windows printer ki apni lane.
    //
    // Sab ko `LOCAL_LANE` me daal dena aasan tha aur ghalat hota: ek lane ek
    // waqt me ek hi kaam karti hai, is liye A5 kitchen sheet aur A4 quotation
    // ek doosre ka intezar karte — chahe wo do alag printers par ja rahe hon.
    if (p.id && p.printer_type === 'windows') {
        return `win:${p.windows_printer_name || p.id}`;
    }

    return LOCAL_LANE;
}

function healthFor(key) {
    let h = printerHealth.get(key);
    if (!h) { h = { fails: 0, cooldownUntil: 0 }; printerHealth.set(key, h); }
    return h;
}

/**
 * A ticket for a printer that is cooling in the circuit breaker is PARKED, not failed. The server
 * re-queues it (deferred a short while) so it reprints the instant the printer answers again — a
 * kitchen slip is never marked permanently failed just because a printer was off for a minute. The
 * ~13s connect wait is skipped: we already know this printer is down this cycle.
 */
async function deferJob(job, message, cooldownSec) {
    try {
        await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/jobs/${job.id}/defer`, {
            headers: headers(),
            body:    { reason: message, cooldown_seconds: cooldownSec },
        });
    } catch (_) {
        // Defer POST lost — the job stays claimed, but the server re-offers a claim after 2 minutes,
        // so the ticket is never lost; it just waits a little longer.
    }
    log(`[DEFER] ${job.job_no}: ${message}`);
}

/**
 * Process every job for ONE printer, serially (port 9100 = one socket at a time), honouring the
 * circuit breaker. Never throws — a lane failure must not sink the sibling lanes running alongside.
 */
async function processLane(key, jobs) {
    const h = healthFor(key);

    // Known-dead printer, still cooling: don't spend ~13s per ticket re-proving it. Fast-fail so
    // the jobs clear the server queue (and stop starving other printers' newer jobs from the fetch).
    if (key !== LOCAL_LANE && h.fails >= CB_FAILURE_THRESHOLD && Date.now() < h.cooldownUntil) {
        for (const job of jobs) {
            await deferJob(job, 'Printer unreachable — cooling down; will retry when it is back.', CB_COOLDOWN_MS / 1000);
        }
        log(`[COOL]  ${jobs.length} job(s) held — ${key} unreachable`);
        return;
    }

    let laneDown = false;
    for (const job of jobs) {
        if (laneDown) {
            // The rest target the SAME dead printer — park them rather than burn ~13s each; they
            // reprint the moment the printer answers, and drop out of the fetch meanwhile.
            await deferJob(job, 'Printer unreachable — will retry when it is back.', CB_COOLDOWN_MS / 1000);
            continue;
        }
        let result;
        try {
            result = await withPrinterLock(key, () => processJob(job));
        } catch (err) {
            // markPrinted/defer talking to the server threw — treat as non-fatal, move on.
            log(`[ERR]   ${job.job_no}: ${err.message}`);
            continue;
        }
        if (result.connectionFailure) {
            // First unreachable ticket on this printer: park it too, and stop trying the rest this
            // cycle — they target the same dead printer.
            await deferJob(job, 'Printer unreachable — will retry when it is back.', CB_COOLDOWN_MS / 1000);
            laneDown = true;
        }
    }

    if (key === LOCAL_LANE) {
        return;
    }
    if (laneDown) {
        h.fails += 1;
        if (h.fails >= CB_FAILURE_THRESHOLD) {
            h.cooldownUntil = Date.now() + CB_COOLDOWN_MS;
            log(`[COOL]  ${key} marked down — skipping for ${CB_COOLDOWN_MS / 1000}s`);
        }
    } else {
        h.fails = 0;
        h.cooldownUntil = 0;
    }
}

let idleCounter = 0;

// Only ONE tick may be in flight. setInterval fires every 3s while a print can take longer, so
// ticks used to overlap and open a SECOND socket to the same printer — and port 9100 accepts one
// connection at a time. The agent was therefore colliding with itself: the second connection could
// not open, hit the timeout, and the ticket was abandoned. This is the collision the counter saw
// as "sometimes it prints, sometimes it doesn't".
let ticking = false;
const HEARTBEAT_EVERY_MS = 3000;
let lastHeartbeatAt = 0;

async function tick() {
    if (ticking || keepAwakeRunning) {
        return;
    }
    ticking = true;
    try {
        // AGENT-UPGRADE-1 — poll 1 s ho sakta hai, magar heartbeat (printers ki sehat, version, poll waqt) pehle jaisa
        // ~3 s par: server par har second do guna bojh bekaar. `pending` khud bhi "last seen" likhta hai.
        if (Date.now() - lastHeartbeatAt >= HEARTBEAT_EVERY_MS) {
            lastHeartbeatAt = Date.now();
            await heartbeat();
        }

        const data = await getPendingJobs();
        const jobs = data.jobs || [];

        // PRINT-PREPARE-1 — server tayyari sirf tab deta hai jab asli parcha na ho. Ye kaam ALAG chalta hai (tick ko
        // nahi rokta), taake tayyari ke beech aaya asli parcha intezar na kare.
        if (jobs.length === 0 && Array.isArray(data.prepares) && data.prepares.length > 0) {
            enqueuePrepares(data.prepares);
        }

        if (jobs.length > 0) {
            idleCounter = 0;
            log(`[POLL]  ${jobs.length} job(s) to process`);

            // Split the batch into one lane per printer, then run the lanes in PARALLEL. Jobs inside
            // a lane stay serial (port 9100 = one socket), but an offline printer's lane can no
            // longer hold up the receipt/KOT/reminder heading to every other printer.
            const lanes = new Map();
            for (const job of jobs) {
                const key = laneKeyOf(job);
                if (!lanes.has(key)) lanes.set(key, []);
                lanes.get(key).push(job);
            }
            await Promise.all(
                [...lanes.entries()].map(([key, laneJobs]) => processLane(key, laneJobs))
            );

            // A ticket the server just re-queued should not wait a full poll interval — a kitchen
            // slip arriving seconds late is the whole complaint. Look again straight away.
            setTimeout(() => { tick().catch(() => {}); }, 250);
            return;
        }

        idleCounter++;
        if (idleCounter === 1 || idleCounter % 20 === 0) {
            log('[IDLE]  No pending network print jobs');
        }
    } catch (err) {
        log(`[ERR]   ${err.message}`);
    } finally {
        // Release in finally, never on the happy path only — one thrown heartbeat would otherwise
        // wedge the agent silently forever.
        ticking = false;
    }
}

function localIp() {
    for (const nets of Object.values(os.networkInterfaces())) {
        for (const iface of nets) {
            if (iface.family === 'IPv4' && !iface.internal) {
                return iface.address;
            }
        }
    }
    return null;
}

/* ── Remote commands from the Printers screen (Test / Reboot) ──────────────
 * The operator presses Test or Reboot in the browser; the server queues a command; the agent picks
 * it up here and acts on the LAN printer it can reach — then reports the result back for the screen.
 * (Soft reset is not here: it rides the normal print path as an ESC @ job.) */
async function getCommands() {
    const res = await httpJson('GET', `${CONFIG.baseUrl}/api/print-agent/commands`, { headers: headers() });
    if (!res.ok) {
        return [];
    }

    return (res.json && res.json.commands) || [];
}

async function postCommandResult(id, payload) {
    // Retry a few times — a lost result leaves the command stuck until the server's lease expires it,
    // so it is worth insisting. After that the lease (90s) is the backstop.
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            const res = await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/commands/${id}/result`, {
                headers: headers(),
                body:    payload,
            });
            if (res.ok) return;
        } catch (_) { /* retry below */ }
        await new Promise((r) => setTimeout(r, 500 * attempt));
    }
    log(`[WARN]  command ${id}: result not acknowledged after retries — server lease will expire it`);
}

/** TCP‑connect probe for the "Test" button — is the printer answering on its print port, how fast. */
function probePrinter(ip, port) {
    return new Promise((resolve) => {
        if (!ip) { resolve({ reachable: false, latencyMs: null, error: 'no IP configured' }); return; }
        const socket = new net.Socket();
        const started = Date.now();
        socket.setTimeout(CONNECT_TIMEOUT_MS);
        let settled = false;
        const finish = (reachable, err) => {
            if (settled) return;
            settled = true;
            socket.destroy();
            resolve({ reachable, latencyMs: reachable ? (Date.now() - started) : null, error: err || null });
        };
        socket.connect(port || 9100, ip, () => { socket.end(); finish(true); });
        socket.on('error',   (e) => finish(false, e.message));
        socket.on('timeout', () => finish(false, 'timed out'));
    });
}

/** Best‑effort network reboot via the printer's built‑in web module (the "Ethernet WebConfig" page).
 *  Tries POST then GET on /reboot. Only an EXPLICIT 2xx counts as success — a 404 (no such endpoint
 *  on this model) or any 4xx/5xx is a failure, never a false "reboot sent". The exact path/port is
 *  model-specific and must be confirmed on-site before this is relied on for a given printer. */
function rebootPrinter(ip, webPort = 80) {
    return new Promise((resolve) => {
        if (!ip) { resolve({ ok: false, detail: 'no IP configured' }); return; }
        const attempt = (method, done) => {
            const req = http.request({ method, hostname: ip, port: webPort, path: '/reboot', timeout: 4000 },
                (res) => { res.resume(); done(res.statusCode >= 200 && res.statusCode < 300, res.statusCode); });
            req.on('error',   () => done(false));
            req.on('timeout', () => { req.destroy(); done(false); });
            req.end();
        };
        attempt('POST', (ok, code) => ok
            ? resolve({ ok: true, detail: `HTTP ${code}` })
            : attempt('GET', (ok2, code2) => resolve(ok2
                ? { ok: true, detail: `HTTP ${code2}` }
                : { ok: false, detail: code2 ? `HTTP ${code2}` : 'no answer' })));
    });
}

async function runCommand(cmd) {
    const p   = cmd.printer || {};
    const key = `${p.ip}:${Number(p.port || 9100)}`;   // same lock a print/poke for this printer uses
    try {
        if (cmd.type === 'ping') {
            // Under the lock: probing opens a connection to the print port, which must not collide
            // with a KOT spooling to the same printer (and would misread as "unreachable").
            const r = await withPrinterLock(key, () => probePrinter(p.ip, Number(p.port || 9100)));
            await postCommandResult(cmd.id, r.reachable
                ? { status: 'done',   result: 'reachable', latency_ms: r.latencyMs }
                : { status: 'failed', result: r.error || 'unreachable' });
        } else if (cmd.type === 'reboot') {
            // Under the lock so the printer is NEVER power-cycled mid-ticket.
            const r = await withPrinterLock(key, () => rebootPrinter(p.ip));
            await postCommandResult(cmd.id, r.ok
                ? { status: 'done',   result: `reboot sent (${r.detail})` }
                : { status: 'failed', result: `no reboot endpoint answered (${r.detail}) — verify the printer's web page for this model` });
        } else {
            await postCommandResult(cmd.id, { status: 'failed', result: `unknown command '${cmd.type}'` });
        }
    } catch (err) {
        await postCommandResult(cmd.id, { status: 'failed', result: err.message });
    }
}

let commandTicking = false;
async function commandTick() {
    if (commandTicking) {
        return;
    }
    commandTicking = true;
    try {
        for (const cmd of await getCommands()) {
            log(`[CMD]   ${cmd.type} → ${cmd.printer?.name || cmd.printer?.ip}`);
            await runCommand(cmd);
        }
    } catch (err) {
        log(`[ERR]   command poll: ${err.message}`);
    } finally {
        commandTicking = false;
    }
}

function run(config) {
    // Pehle ye dekho ke ek agent pehle se chal to nahi raha — dekho
    // `anotherAgentIsRunning` ka note. Yahan exit 0 hai, nakami nahi:
    // kaam ho raha hai, bas is copy ki zaroorat nahi.
    const other = anotherAgentIsRunning();
    if (other) {
        console.log(`Bingoo Print Agent pehle se chal raha hai (PID ${other}). Ye copy band ki ja rahi hai.`);
        console.log('Halat dekhne ko:  BingooPrintAgent.exe status');
        process.exit(0);
    }
    claimSingleInstanceLock();

    CONFIG = config;
    log('Bingoo POS Local Print Agent started.');
    log(`Version: ${AGENT_VERSION}`);
    log(`Server:  ${CONFIG.baseUrl}`);
    log(`Agent:   ${CONFIG.agentCode}`);
    log(`Config:  ${CONFIG.source || 'file'}`);

    // Shuru me hi keh do. 9 Oct ko Kashif Kitchen par A4/A5 document is liye
    // nahi chhap rahe thay ke agent ADMINISTRATOR par chal raha tha — Chromium
    // elevated process se PDF banane se inkar kar deta hai. Us din ye teen
    // satrein mojood hoti to ghanton ki talash pehli nazar me khatam ho jati.
    //
    // Sirf ittila hai — kisi kaam ko rokti nahi. Thermal parchi elevated par
    // bhi theek chhapti hai, aur ek ghalat warning par parchi rok dena us
    // masle se bara masla ban jata jo ye batati hai.
    if (isElevated() === true) {
        log('WARNING: ye agent ADMINISTRATOR par chal raha hai. Thermal parchi to chhapegi,');
        log('         magar A4/A5 document NAHI - Chrome elevated par PDF nahi banata.');
        log('         Scheduled task ko RunLevel Limited par daalein (Highest par nahi).');
    }

    schedulePoll(CONFIG.pollMs);
    // Hold the printers awake so a kitchen ticket is never the thing that wakes one.
    setInterval(() => { keepPrintersAwake().catch(() => {}); }, KEEP_AWAKE_MS);
    // Poll for Test/Reboot commands from the Printers screen (own cadence — never blocks a ticket). 3 s rehta hai:
    // tickets ka poll tez hone se is ka tez hona zaroori nahi (server par do guna bojh hota).
    setInterval(() => { commandTick().catch(() => {}); }, Math.max(CONFIG.pollMs, 3000));
    // PRINT-PREPARE-1 — PC par purani PDF / temp files jama na hon (owner: "data kisi PC pe jama na ho").
    cleanupFiles();
    setInterval(cleanupFiles, CLEANUP_EVERY_MS);
    tick();
}

/* ── AGENT-UPGRADE-1 — poll ka waqt server tay kare ──────────────────────
 *
 * Pehle `pollMs` pairing ke waqt config me jam jata tha (3 s) aur badalne ke liye PC par jana parta tha. Ab heartbeat
 * ka jawab `poll_ms` deta hai; Master switch ON = 1 s. Had 500 ms – 10 s, taake ghalat qeemat server ko na dubo de.
 */
let pollTimer = null;
let currentPollMs = 0;

function schedulePoll(ms) {
    const next = Math.min(10000, Math.max(500, Number(ms) || 3000));
    if (next === currentPollMs && pollTimer) { return; }
    if (pollTimer) { clearInterval(pollTimer); }
    currentPollMs = next;
    pollTimer = setInterval(tick, next);
    if (CONFIG) { log(`Polling: every ${next}ms`); }
}

async function status(config) {
    console.log('=== Bingoo Print Agent — Status ===');
    if (!config) {
        console.log('Not configured. Run: print-agent setup');
        process.exit(1);
    }
    console.log(`Config source: ${config.source}`);
    console.log(`Server:        ${config.baseUrl}`);
    console.log(`Agent code:    ${config.agentCode}`);
    CONFIG = config;
    try {
        await heartbeat();
        console.log('Heartbeat:     OK — server reachable, credentials valid.');
    } catch (err) {
        console.log(`Heartbeat:     FAILED — ${err.message}`);
        process.exit(1);
    }
}

/* ── Entrypoint ───────────────────────────────────────────────────────── */

// Only run the CLI when invoked directly (node print-agent.js / the packaged exe). When required by
// a test harness this stays dormant so the tests can drive the pieces in isolation.
if (require.main === module) {
    const command = (process.argv[2] || '').toLowerCase();

    if (command === '--help' || command === 'help') {
        console.log('Usage: print-agent [setup|run|status]');
        console.log('  setup   Pair this PC with Bingoo POS using a pairing code');
        console.log('  run     Start printing (default when already configured)');
        console.log('  status  Show configuration and check the server connection');
        process.exit(0);
    } else if (command === 'setup') {
        setup();
    } else if (command === 'status') {
        status(loadConfig());
    } else {
        const config = loadConfig();
        if (!config && command === 'run') {
            // AGENT-UPGRADE-1 — chupe hue (auto-start / chowkidar) chalne par koi sawaal nahi pooch sakta: pehle ye
            // chhupi window me hamesha intezar karta rehta aur installer use "chal raha hai" samajh leta. Saaf band
            // ho, wajah log me, aur exit 4 — chowkidar (run-hidden.vbs) 4 par dobara nahi uthata.
            log('Ye PC abhi pair nahi hua — Bingoo › Printing › Print Agents se pairing code le kar setup chalayein.');
            process.exit(4);
        } else if (!config) {
            console.log('No configuration found — starting first-time setup.\n');
            setup();
        } else {
            run(config);
        }
    }
}

/* ── Test surface (no effect on the CLI/packaged exe) ──────────────────── */
module.exports = {
    AGENT_VERSION,
    laneKeyOf,
    withPrinterLock,
    deferJob,
    processJob,
    processLane,
    tick,
    getPendingJobs,
    // v2.5.0 — health + remote commands
    heartbeat,
    keepPrintersAwake,
    syncKnownPrinters,
    probePrinter,
    rebootPrinter,
    runCommand,
    commandTick,
    getCommands,
    _lastPokeStatus: lastPokeStatus,
    _knownPrinters: knownPrinters,
    _printerHealth: printerHealth,
    _setConfig: (cfg) => { CONFIG = cfg; },
    _resetHealth: () => printerHealth.clear(),
    // AGENT-SINGLE-INSTANCE-1 — pehra test se chalaya ja sake.
    lockPath,
    anotherAgentIsRunning,
    claimSingleInstanceLock,
    processLooksLikeAgent,
    CB_FAILURE_THRESHOLD,
    CB_COOLDOWN_MS,
    // AGENT-UPGRADE-1 + PRINT-PREPARE-1 — test se chalaye ja saken.
    configCandidates,
    preferredConfigPath,
    legacyConfigPath,
    loadConfig,
    schedulePoll,
    _currentPollMs: () => currentPollMs,
    _stopPoll: () => { if (pollTimer) { clearInterval(pollTimer); } pollTimer = null; currentPollMs = 0; },
    readyDir,
    readyPdfFor,
    enqueuePrepares,
    preparePdf,
    cleanupFiles,
    _inFlightPrepares: inFlightPrepares,
    _waitPrepares: () => prepareChain,
    READY_FRESH_MS,
    READY_KEEP_MS,
};
