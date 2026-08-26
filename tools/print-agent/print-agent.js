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
 *   2. %ProgramData%\BingooPrintAgent\config.json   (Windows install)
 *   3. ~/.bingoo-print-agent/config.json            (macOS/Linux)
 *   4. ./.agent-config.json                          (local dev fallback)
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

const AGENT_VERSION = '2.5.0';

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
    if (process.platform === 'win32' && process.env.ProgramData) {
        candidates.push(path.join(process.env.ProgramData, 'BingooPrintAgent', 'config.json'));
    }
    candidates.push(path.join(os.homedir(), '.bingoo-print-agent', 'config.json'));
    candidates.push(path.join(exeDir(), '.agent-config.json'));
    return candidates;
}

function preferredConfigPath() {
    return configCandidates()[0];
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
                    return { source: file, pollMs: 3000, ...raw };
                }
            }
        } catch (_) { /* unreadable/corrupt file → try next */ }
    }

    return null;
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
                const r = await pokePrinter(ip, port);
                lastPokeStatus.set(`${ip}:${port}`, { id, reachable: r.reachable, latencyMs: r.latencyMs, at: Date.now() });
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
        await markFailed(job.id, lastError.message);
        log(`[FAIL]  ${job.job_no}: ${lastError.message}`);
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

/** The failure domain is the physical printer (ip:port), not the terminal/order-type. */
function laneKeyOf(job) {
    const p = job.printer || {};
    if (p.id && p.printer_type === 'network' && p.ip_address) {
        return `${p.ip_address}:${Number(p.port || 9100)}`;
    }
    return LOCAL_LANE;
}

function healthFor(key) {
    let h = printerHealth.get(key);
    if (!h) { h = { fails: 0, cooldownUntil: 0 }; printerHealth.set(key, h); }
    return h;
}

async function failFast(job, message) {
    try { await markFailed(job.id, message); } catch (_) { /* server hiccup — retried next poll */ }
    log(`[FAIL]  ${job.job_no}: ${message}`);
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
            await failFast(job, 'Printer unreachable — cooling down after repeated failures. Will retry automatically.');
        }
        log(`[COOL]  ${jobs.length} job(s) held — ${key} unreachable`);
        return;
    }

    let laneDown = false;
    for (const job of jobs) {
        if (laneDown) {
            // The rest target the SAME dead printer — fail them fast rather than burn ~13s each.
            await failFast(job, 'Printer unreachable. Will retry automatically.');
            continue;
        }
        let result;
        try {
            result = await processJob(job);
        } catch (err) {
            // markPrinted/markFailed talking to the server threw — treat as non-fatal, move on.
            log(`[ERR]   ${job.job_no}: ${err.message}`);
            continue;
        }
        if (result.connectionFailure) {
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

async function tick() {
    if (ticking || keepAwakeRunning) {
        return;
    }
    ticking = true;
    try {
        await heartbeat();

        const data = await getPendingJobs();
        const jobs = data.jobs || [];

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
    await httpJson('POST', `${CONFIG.baseUrl}/api/print-agent/commands/${id}/result`, {
        headers: headers(),
        body:    payload,
    }).catch(() => { /* result lost — the screen falls back to the live poke status */ });
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
 *  Tries POST then GET on /reboot; the exact path is confirmed on‑site per model. */
function rebootPrinter(ip) {
    return new Promise((resolve) => {
        if (!ip) { resolve({ ok: false, detail: 'no IP configured' }); return; }
        const attempt = (method, done) => {
            const req = http.request({ method, hostname: ip, port: 80, path: '/reboot', timeout: 4000 },
                (res) => { res.resume(); done(res.statusCode >= 200 && res.statusCode < 500); });
            req.on('error',   () => done(false));
            req.on('timeout', () => { req.destroy(); done(false); });
            req.end();
        };
        attempt('POST', (ok) => ok ? resolve({ ok: true }) : attempt('GET', (ok2) => resolve({ ok: ok2 })));
    });
}

async function runCommand(cmd) {
    const p = cmd.printer || {};
    try {
        if (cmd.type === 'ping') {
            const r = await probePrinter(p.ip, Number(p.port || 9100));
            await postCommandResult(cmd.id, r.reachable
                ? { status: 'done',   result: 'reachable', latency_ms: r.latencyMs }
                : { status: 'failed', result: r.error || 'unreachable' });
        } else if (cmd.type === 'reboot') {
            const r = await rebootPrinter(p.ip);
            await postCommandResult(cmd.id, r.ok
                ? { status: 'done',   result: 'reboot sent' }
                : { status: 'failed', result: 'no reboot endpoint answered — check the printer web page' });
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
    CONFIG = config;
    log('Bingoo POS Local Print Agent started.');
    log(`Version: ${AGENT_VERSION}`);
    log(`Server:  ${CONFIG.baseUrl}`);
    log(`Agent:   ${CONFIG.agentCode}`);
    log(`Config:  ${CONFIG.source || 'file'}`);
    log(`Polling: every ${CONFIG.pollMs}ms`);

    setInterval(tick, CONFIG.pollMs);
    // Hold the printers awake so a kitchen ticket is never the thing that wakes one.
    setInterval(() => { keepPrintersAwake().catch(() => {}); }, KEEP_AWAKE_MS);
    // Poll for Test/Reboot commands from the Printers screen (own cadence — never blocks a ticket).
    setInterval(() => { commandTick().catch(() => {}); }, CONFIG.pollMs);
    tick();
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
        if (!config) {
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
    CB_FAILURE_THRESHOLD,
    CB_COOLDOWN_MS,
};
