# Print Agent Installer — Ops Runbook

Client-friendly pairing flow (PRINT-AGENT-INSTALLER-1). Legacy env-var/token
agents keep working unchanged — nothing to migrate.

## Install (Windows — one-click)

1. Bingoo POS → **Printing → Print Agents → Create Agent** (pick branch/terminal).
2. The screen shows a **6-digit pairing code** (valid 15 min, single use) + the
   Server URL.
3. On the shop PC: **Download Windows Agent** → run **BingooPrintAgent-Setup.exe**
   (Node.js is bundled — nothing else to install).
4. In the wizard, paste the **Server URL** + **pairing code** → Next. It installs,
   pairs, and registers an auto-start task. Admin screen shows **Online** in ~10 s.
5. SmartScreen on unsigned build → *More info → Run anyway* (code-signing is on the
   ops backlog).

Fallback (script mode, needs Node.js): if the Setup.exe is unavailable the
download serves a ZIP — `node print-agent.js setup` + `install-service.ps1`.

## Pair / Re-pair

- New device or replaced PC → agent row → **Re-pair** → new code → run
  `setup` on the new PC. After the new device pairs, the old device's token is
  invalid (single token per agent).
- Codes: 6 digits, hashed at rest (HMAC-SHA256 with app key), 15-min expiry,
  single use, 5 attempts/IP rate limit on `/api/print-agent/pair`.
- The permanent token is delivered ONLY to the agent during pairing — it is
  never displayed in the browser.

## Test print

Agent row → **Test Print** → queues a test page through the normal
`print_jobs` pipeline to the first active network printer for that branch.
"No printer is mapped yet" → add a printer under Printing → Printers first.

## Service management (Windows)

- Restart: Task Scheduler → `BingooPrintAgent` → End + Run, or
  `Stop-ScheduledTask/Start-ScheduledTask -TaskName BingooPrintAgent`.
- Logs: `C:\ProgramData\BingooPrintAgent\agent.log`
- Config: `C:\ProgramData\BingooPrintAgent\config.json` (contains the permanent
  token — treat like a password; file is created 0600).
- Status check on the PC: `node print-agent.js status` (config source + live
  heartbeat check).

## Uninstall

`installer\windows\uninstall-service.ps1` (add `-Purge` to remove config/logs).

## Troubleshooting

| Symptom | Fix |
|---|---|
| Agent shows Offline | PC on? Agent task running? `node print-agent.js status`; check agent.log; firewall must allow OUTBOUND https (no inbound needed) |
| "Invalid pairing code" | Typo, expired (15 min), already used, or 5-attempt lockout — generate a new code |
| Test print queued but nothing prints | Printer row: Type=network, correct IP, port 9100, is_active; ping the printer IP from the shop PC |
| Port 9100 blocked | Printer/firewall settings; test with `Test-NetConnection <printer-ip> -Port 9100` |
| "Invalid print token" after re-pair | Expected on the OLD device — only the newest paired device holds a valid token |
| Windows SmartScreen on installer | Build is unsigned until the code-signing cert exists — More info → Run anyway; signing is on the ops backlog |

## Audit

Pairing lifecycle events are structured log lines (`[print-agent-audit] print_agent.created|pairing_code_generated|paired|token_regenerated|deactivated|test_print_sent`)
in the Laravel log. A central audit table is future work.
