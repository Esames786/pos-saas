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

const AGENT_VERSION = '2.0.1';

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
        },
    });

    if (!res.ok) {
        throw new Error(`Heartbeat failed: HTTP ${res.status} — ${(res.text || '').slice(0, 300)}`);
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
        socket.setTimeout(8000);

        socket.connect(port || 9100, ip, () => {
            socket.write(payload || '');
            socket.write('\n\n\n');
            socket.end();
        });

        socket.on('close',   resolve);
        socket.on('error',   reject);
        socket.on('timeout', () => {
            socket.destroy();
            reject(new Error('Printer connection timed out.'));
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

async function processJob(job) {
    const printer = job.printer || {};
    try {
        if (!printer.id) {
            log(`[SKIP]  ${job.job_no}: browser/manual fallback job`);
            return;
        }
        if (printer.printer_type !== 'network') {
            log(`[SKIP]  ${job.job_no}: non-network printer (${printer.printer_type})`);
            return;
        }
        if (!printer.ip_address) {
            throw new Error('No IP address configured for this printer.');
        }

        await sendToNetworkPrinter(
            printer.ip_address,
            Number(printer.port || 9100),
            job.raw_payload || ''
        );

        await markPrinted(job.id);
        log(`[OK]    ${job.job_no} → ${printer.name || printer.ip_address}`);

    } catch (err) {
        await markFailed(job.id, err.message);
        log(`[FAIL]  ${job.job_no}: ${err.message}`);
    }
}

let idleCounter = 0;

async function tick() {
    try {
        await heartbeat();

        const data = await getPendingJobs();
        const jobs = data.jobs || [];

        if (jobs.length > 0) {
            idleCounter = 0;
            log(`[POLL]  ${jobs.length} job(s) to process`);
            for (const job of jobs) {
                await processJob(job);
            }
            return;
        }

        idleCounter++;
        if (idleCounter === 1 || idleCounter % 20 === 0) {
            log('[IDLE]  No pending network print jobs');
        }
    } catch (err) {
        log(`[ERR]   ${err.message}`);
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

function run(config) {
    CONFIG = config;
    log('Bingoo POS Local Print Agent started.');
    log(`Version: ${AGENT_VERSION}`);
    log(`Server:  ${CONFIG.baseUrl}`);
    log(`Agent:   ${CONFIG.agentCode}`);
    log(`Config:  ${CONFIG.source || 'file'}`);
    log(`Polling: every ${CONFIG.pollMs}ms`);

    setInterval(tick, CONFIG.pollMs);
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
