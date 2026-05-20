/**
 * Fake Thermal Printer — for local testing without a real printer
 *
 * Listens on TCP port 9100 and prints received data to the console.
 * Use IP 127.0.0.1 in POS printer settings when testing locally.
 *
 * Run:
 *   node fake-printer.js
 */

const net = require('net');

const PORT = Number(process.env.FAKE_PRINTER_PORT || 9100);
const HOST = process.env.FAKE_PRINTER_HOST || '127.0.0.1';

const server = net.createServer((socket) => {
    const remote = `${socket.remoteAddress}:${socket.remotePort}`;
    console.log(`\n[CONN]  Client connected from ${remote}`);

    let buffer = '';

    socket.on('data', (data) => {
        buffer += data.toString('utf8');
    });

    socket.on('end', () => {
        console.log('\n' + '='.repeat(48));
        console.log('  FAKE PRINTER OUTPUT');
        console.log('='.repeat(48));
        console.log(buffer);
        console.log('='.repeat(48));
        console.log(`[DONE]  Job received at ${new Date().toLocaleTimeString()}`);
        buffer = '';
    });

    socket.on('error', (err) => {
        console.error(`[ERR]   Socket error: ${err.message}`);
    });
});

server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        console.error(`[ERR]   Port ${PORT} is already in use. Is another printer listening?`);
    } else {
        console.error(`[ERR]   ${err.message}`);
    }
    process.exit(1);
});

server.listen(PORT, HOST, () => {
    console.log(`Fake Printer listening on ${HOST}:${PORT}`);
    console.log('Waiting for print jobs...');
    console.log('(Press Ctrl+C to stop)\n');
});
