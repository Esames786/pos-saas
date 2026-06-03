/**
 * POS SaaS — Combined Test Agent
 *
 * Runs BOTH the fake thermal printer (TCP :9100) AND the print agent in one process.
 * Edit config.json in the same folder, then double-click pos-test-agent.exe.
 *
 * For production (real printer), use pos-print-agent.exe instead.
 */

const net  = require('net');
const os   = require('os');
const fs   = require('fs');
const path = require('path');

// ── Config ──────────────────────────────────────────────────────────────────
const configDir  = process.pkg ? path.dirname(process.execPath) : __dirname;
const configFile = path.join(configDir, 'config.json');

let fileCfg = {};
if (fs.existsSync(configFile)) {
    try {
        fileCfg = JSON.parse(fs.readFileSync(configFile, 'utf8'));
    } catch (e) {
        console.error('[WARN] Could not parse config.json: ' + e.message);
    }
}

const CFG = {
    baseUrl:   fileCfg.baseUrl   || process.env.POS_BASE_URL          || 'http://demo.pos-saas.test',
    agentCode: fileCfg.agentCode || process.env.POS_PRINT_AGENT_CODE  || 'AG-CHANGE-ME',
    token:     fileCfg.token     || process.env.POS_PRINT_AGENT_TOKEN || 'TOKEN-CHANGE-ME',
    pollMs:    Number(fileCfg.pollMs || process.env.POS_PRINT_POLL_MS || 3000),
};

console.log('='.repeat(52));
console.log('  POS SaaS Test Agent');
console.log('='.repeat(52));
console.log('Server :  ' + CFG.baseUrl);
console.log('Agent  :  ' + CFG.agentCode);
console.log('Polling:  every ' + CFG.pollMs + 'ms');
console.log('Printer:  Fake (output shown below)');
console.log('='.repeat(52));
console.log('');

// ── Fake Printer (TCP :9100) ────────────────────────────────────────────────
const fakePrinter = net.createServer((socket) => {
    let buffer = '';

    socket.on('data',  (d)   => { buffer += d.toString('utf8'); });
    socket.on('end',   ()    => {
        console.log('\n' + '='.repeat(48));
        console.log('  PRINT JOB OUTPUT');
        console.log('='.repeat(48));
        console.log(buffer);
        console.log('='.repeat(48));
        console.log('[DONE]  Printed at ' + new Date().toLocaleTimeString());
        buffer = '';
    });
    socket.on('error', (err) => console.error('[PRINTER ERR] ' + err.message));
});

fakePrinter.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        console.log('[INFO]  Port 9100 already in use — using existing printer on that port.');
    } else {
        console.error('[PRINTER ERR] ' + err.message);
    }
});

fakePrinter.listen(9100, '127.0.0.1', () => {
    console.log('[FAKE]  Fake printer listening on 127.0.0.1:9100');
});

// ── Print Agent ─────────────────────────────────────────────────────────────
function hdrs() {
    return {
        'X-Print-Agent-Code':  CFG.agentCode,
        'X-Print-Agent-Token': CFG.token,
        'Accept':              'application/json',
        'Content-Type':        'application/json',
    };
}

function localIp() {
    for (const ifaces of Object.values(os.networkInterfaces())) {
        for (const iface of ifaces) {
            if (iface.family === 'IPv4' && !iface.internal) return iface.address;
        }
    }
    return null;
}

async function heartbeat() {
    const res = await fetch(CFG.baseUrl + '/api/print-agent/heartbeat', {
        method:  'POST',
        headers: hdrs(),
        body:    JSON.stringify({ device_name: os.hostname(), device_os: os.platform() + ' ' + os.release(), local_ip: localIp() }),
    });
    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error('Heartbeat HTTP ' + res.status + ' — ' + body.slice(0, 200));
    }
}

async function fetchJobs() {
    const res = await fetch(CFG.baseUrl + '/api/print-agent/pending', { method: 'GET', headers: hdrs() });
    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error('Pending HTTP ' + res.status + ' — ' + body.slice(0, 200));
    }
    return res.json();
}

function tcpPrint(ip, port, data) {
    return new Promise((resolve, reject) => {
        const sock = new net.Socket();
        sock.setTimeout(8000);
        sock.connect(port || 9100, ip, () => { sock.write(data || ''); sock.write('\n\n\n'); sock.end(); });
        sock.on('close',   resolve);
        sock.on('error',   reject);
        sock.on('timeout', () => { sock.destroy(); reject(new Error('Printer timed out')); });
    });
}

async function markPrinted(id) {
    await fetch(CFG.baseUrl + '/api/print-agent/jobs/' + id + '/printed', { method: 'POST', headers: hdrs(), body: '{}' });
}
async function markFailed(id, msg) {
    await fetch(CFG.baseUrl + '/api/print-agent/jobs/' + id + '/failed',  { method: 'POST', headers: hdrs(), body: JSON.stringify({ error_message: msg }) });
}

async function processJob(job) {
    const printer = job.printer || {};
    try {
        if (!printer.id) {
            console.log('[SKIP]  ' + job.job_no + ': browser/manual fallback job');
            return;
        }
        if (printer.printer_type !== 'network') {
            console.log('[SKIP]  ' + job.job_no + ': non-network printer (' + printer.printer_type + ')');
            return;
        }
        if (!printer.ip_address) throw new Error('No IP address for this printer.');
        await tcpPrint(printer.ip_address, Number(printer.port || 9100), job.raw_payload || '');
        await markPrinted(job.id);
        console.log('[OK]    ' + job.job_no + ' → ' + (printer.name || printer.ip_address));
    } catch (err) {
        await markFailed(job.id, err.message);
        console.error('[FAIL]  ' + job.job_no + ': ' + err.message);
    }
}

let idleCounter = 0;

async function tick() {
    try {
        await heartbeat();
        const data = await fetchJobs();
        const jobs = data.jobs || [];

        if (jobs.length) {
            idleCounter = 0;
            console.log('[POLL]  ' + jobs.length + ' job(s)');
            for (const job of jobs) await processJob(job);
            return;
        }

        idleCounter++;
        if (idleCounter === 1 || idleCounter % 20 === 0) {
            console.log('[IDLE]  No pending network print jobs');
        }
    } catch (err) {
        console.error('[ERR]   ' + err.message);
    }
}

setTimeout(() => {
    console.log('[AGENT] Started. Polling every ' + CFG.pollMs + 'ms...\n');
    setInterval(tick, CFG.pollMs);
    tick();
}, 600);
