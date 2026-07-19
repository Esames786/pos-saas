# Bingoo POS Local Print Agent

Runs inside the restaurant LAN network. Polls the cloud POS for queued print jobs and sends raw text to LAN printers via TCP (port 9100).

## Quick start (v2 pairing flow — recommended)

```bash
node print-agent.js setup    # asks Server URL + 6-digit pairing code from Printing → Print Agents
node print-agent.js run      # start printing (auto-runs after setup)
node print-agent.js status   # config + live server check
```

Config is stored at `%ProgramData%\BingooPrintAgent\config.json` (Windows) or
`~/.bingoo-print-agent/config.json`. No env vars or token copy-paste needed.
Windows auto-start + installer: see `installer/windows/README.md`.

The sections below describe the LEGACY manual mode — still fully supported for
existing agents.

## Architecture

```
Cloud Laravel SaaS
  → print_jobs table (status: queued)
  → Local Print Agent (this script, polls every 3s)
  → LAN Printer IP:9100
  → job marked printed / failed
```

## Requirements

- Node.js 18 or newer
- Printer supporting raw TCP printing on port 9100 (most ESC/POS thermal printers)
- PC/server on the same LAN as the printers

## Setup

### 1. Create a Print Agent in POS

**Printing → Print Agents → Create Agent & Get Token**

Copy:
- Agent Code (e.g. `AG-20241210120000-123`)
- Token (64 characters — shown only once)

### 2. Run the agent

```bash
cd tools/print-agent

POS_BASE_URL="https://demo.yourdomain.com" \
POS_PRINT_AGENT_CODE="AG-xxxx" \
POS_PRINT_AGENT_TOKEN="your-64-char-token" \
node print-agent.js
```

Windows (PowerShell):

```powershell
$env:POS_BASE_URL = "https://demo.yourdomain.com"
$env:POS_PRINT_AGENT_CODE = "AG-xxxx"
$env:POS_PRINT_AGENT_TOKEN = "your-64-char-token"
node print-agent.js
```

### 3. Configure printers in POS

**Printing → Printers → Add Printer**

| Field | Example |
|---|---|
| Name | Kitchen Printer |
| Type | Network (IP/Port) |
| Role | KOT |
| IP | 192.168.1.50 |
| Port | 9100 |
| Paper | 80mm |

### 4. Set up KOT routing

**Printing → KOT Routing → Add Mapping**

| Field | Example |
|---|---|
| Branch | Main Branch |
| Category | Food |
| Printer | Kitchen Printer |
| Role | KOT |

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `POS_BASE_URL` | `http://demo.pos-saas.test` | Cloud POS URL |
| `POS_PRINT_AGENT_CODE` | `AG-CHANGE-ME` | Agent code from POS |
| `POS_PRINT_AGENT_TOKEN` | `TOKEN-CHANGE-ME` | Token from POS |
| `POS_PRINT_POLL_MS` | `3000` | Poll interval in milliseconds |

## Run as a service (Windows)

Use [NSSM](https://nssm.cc/) or PM2:

```bash
npm install -g pm2
pm2 start print-agent.js --name pos-print-agent \
  --env POS_BASE_URL=https://demo.yourdomain.com \
  --env POS_PRINT_AGENT_CODE=AG-xxxx \
  --env POS_PRINT_AGENT_TOKEN=your-token
pm2 save
pm2 startup
```

## Monitoring

Agent status is visible in POS under **Printing → Print Agents**:
- **Online**: last heartbeat < 2 minutes ago
- **Offline**: last heartbeat > 2 minutes ago
- **Never Connected**: no heartbeat received yet

Failed jobs appear in **Printing → Print Jobs** with the error message. Use the **Retry** button to re-queue.
