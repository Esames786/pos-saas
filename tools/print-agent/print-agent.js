/**
 * POS SaaS Local Print Agent
 *
 * Runs inside the restaurant LAN. Polls the cloud app for queued print jobs
 * and sends raw text to LAN printers via TCP socket on port 9100.
 *
 * Usage:
 *   POS_BASE_URL="https://demo.yourdomain.com" \
 *   POS_PRINT_AGENT_CODE="AG-xxxx" \
 *   POS_PRINT_AGENT_TOKEN="your-64-char-token" \
 *   node print-agent.js
 *
 * Requires Node.js 18+
 */

const net = require('net');
const os  = require('os');

const CONFIG = {
    baseUrl:   process.env.POS_BASE_URL             || 'http://demo.pos-saas.test',
    agentCode: process.env.POS_PRINT_AGENT_CODE     || 'AG-CHANGE-ME',
    token:     process.env.POS_PRINT_AGENT_TOKEN    || 'TOKEN-CHANGE-ME',
    pollMs:    Number(process.env.POS_PRINT_POLL_MS || 3000),
};

function headers() {
    return {
        'X-Print-Agent-Code':  CONFIG.agentCode,
        'X-Print-Agent-Token': CONFIG.token,
        'Accept':              'application/json',
        'Content-Type':        'application/json',
    };
}

async function heartbeat() {
    const res = await fetch(`${CONFIG.baseUrl}/api/print-agent/heartbeat`, {
        method:  'POST',
        headers: headers(),
        body:    JSON.stringify({
            device_name: os.hostname(),
            device_os:   `${os.platform()} ${os.release()}`,
            local_ip:    localIp(),
        }),
    });

    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`Heartbeat failed: HTTP ${res.status} — ${body.slice(0, 300)}`);
    }
}

async function getPendingJobs() {
    const res = await fetch(`${CONFIG.baseUrl}/api/print-agent/pending`, {
        method:  'GET',
        headers: headers(),
    });

    if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`Pending jobs fetch failed: HTTP ${res.status} — ${body.slice(0, 300)}`);
    }

    return await res.json();
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
    await fetch(`${CONFIG.baseUrl}/api/print-agent/jobs/${jobId}/printed`, {
        method:  'POST',
        headers: headers(),
        body:    JSON.stringify({}),
    });
}

async function markFailed(jobId, message) {
    await fetch(`${CONFIG.baseUrl}/api/print-agent/jobs/${jobId}/failed`, {
        method:  'POST',
        headers: headers(),
        body:    JSON.stringify({ error_message: message }),
    });
}

async function processJob(job) {
    const printer = job.printer || {};
    try {
        if (!printer.id) {
            console.log(`[SKIP]  ${job.job_no}: browser/manual fallback job`);
            return;
        }
        if (printer.printer_type !== 'network') {
            console.log(`[SKIP]  ${job.job_no}: non-network printer (${printer.printer_type})`);
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
        console.log(`[OK]    ${job.job_no} → ${printer.name || printer.ip_address}`);

    } catch (err) {
        await markFailed(job.id, err.message);
        console.error(`[FAIL]  ${job.job_no}: ${err.message}`);
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
            console.log(`[POLL]  ${jobs.length} job(s) to process`);
            for (const job of jobs) {
                await processJob(job);
            }
            return;
        }

        idleCounter++;
        if (idleCounter === 1 || idleCounter % 20 === 0) {
            console.log('[IDLE]  No pending network print jobs');
        }
    } catch (err) {
        console.error(`[ERR]   ${err.message}`);
    }
}

function localIp() {
    for (const nets of Object.values(os.networkInterfaces())) {
        for (const net of nets) {
            if (net.family === 'IPv4' && !net.internal) {
                return net.address;
            }
        }
    }
    return null;
}

console.log('POS SaaS Local Print Agent started.');
console.log(`Server:  ${CONFIG.baseUrl}`);
console.log(`Agent:   ${CONFIG.agentCode}`);
console.log(`Polling: every ${CONFIG.pollMs}ms`);
console.log('');

setInterval(tick, CONFIG.pollMs);
tick();
