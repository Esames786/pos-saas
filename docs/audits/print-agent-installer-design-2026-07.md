# PRINT-AGENT-INSTALLER — One-Click Installer Design (2026-07)

> Planning document only. No code changed. Audited at `a668974` (`v0.9.2-pilot`).

---

## 1. Executive summary

The Print Agent works in production but is **developer-grade to install**: Node.js,
env vars, a token pasted from the browser, a `.bat` to keep it running. This design
turns that into a client-grade flow — download an installer, type a 6-digit pairing
code, see a green "Connected" state, hit Test Print. **Recommendation: generic
signed installer + pairing-code exchange (Option 2)**, built with the existing
`pkg`-style single-binary approach already proven by `tools/print-agent/dist/pos-test-agent.exe`.

## 2. What the Print Agent is (client-language explanation)

- **What:** a tiny program on one shop PC that connects your cloud POS to the
  thermal printers on the shop's local network.
- **Why the browser can't do it:** web pages are sandboxed — they may only open
  the browser's print dialog. They cannot silently push raw ESC/POS bytes to a
  kitchen printer's IP, cannot print without a popup, and cannot reach LAN
  devices from a cloud page. Every serious cloud POS (Square, Loyverse, Foodics)
  ships a local bridge for exactly this reason.
- **How it works today:** POS queues each receipt/KOT as a row in `print_jobs`
  → the agent polls the cloud every 3 s (`/api/print-agent/pending`) → sends the
  raw ESC/POS payload over TCP port 9100 to the mapped LAN printer → reports
  `printed`/`failed` back. Category→printer routing (`PrintRoutingService`)
  decides which kitchen printer gets which KOT lines.

## 3. Current state found in code

| Piece | State |
|---|---|
| Cloud queue | `print_jobs` + `PrintJobService`/`PrintRoutingService` — solid |
| Agent API | `/api/print-agent/heartbeat|pending|jobs/{id}/printed|failed`, header auth `X-Print-Agent-Code` + `X-Print-Agent-Token`, token stored as `token_hash` (Hash::check), `last_seen_at`/`last_error` tracked |
| Agent admin UI | Printing → Print Agents: create (token shown once), regenerate-token, deactivate |
| Agent runtime | `tools/print-agent/print-agent.js` (Node 18+, env-var config, 3 s poll, TCP 9100) + `.bat` start/stop/debug wrappers + `fake-printer.js` for testing + a `pkg`-packaged `pos-test-agent.exe` proof-of-concept |
| Installer | **None** — manual Node install + env vars + copy-paste 64-char token |

### Pain points today
1. Client needs Node.js + terminal skills (unrealistic for restaurants).
2. 64-char token copy-paste into env vars — error-prone, insecure on paper/WhatsApp.
3. No service/auto-start: PC reboot silently kills printing until someone reruns a `.bat`.
4. No visible status: staff can't tell "agent down" from "printer jammed".
5. macOS completely unsupported in practice.

## 4. Recommended architecture — Option 2: generic installer + pairing code

Option 1 (per-agent personalized installer with embedded token) was rejected:
building/signing a unique binary per agent needs server-side build infrastructure,
breaks installer caching/AV reputation, and a leaked installer file IS the
credential. Option 2 keeps ONE signed artifact per OS and moves the secret into a
short-lived pairing exchange.

### Pairing flow

```
Admin: Printing → Print Agents → Create Agent → chooses OS → downloads installer
       Screen shows a 6-digit pairing code (e.g. 483-921), valid 15 minutes
Client PC: runs installer → tray app opens → asks Server URL* + pairing code
Agent → POST /api/print-agent/pair {code, device info}
Cloud: validates code (unexpired, unused) → issues permanent 64-char token
       (returned ONCE over TLS, stored hashed server-side as today)
Agent: stores token in OS-protected local config → starts heartbeat loop → UI turns green
```

*Server URL pre-fillable via the download link (`?server=demo.bingoopos.com`)
so most users never type it.

### Backend additions (small)

- `print_agents` += `pairing_code_hash`, `pairing_expires_at`, `paired_at`
  (nullable; agent row is created in "waiting to pair" state).
- New unauthenticated-but-rate-limited route `POST /api/print-agent/pair`
  (tenant-scoped by subdomain; code hashed; single-use; expiry enforced).
- Existing token auth, regenerate, deactivate: unchanged — regenerate now also
  supports "re-pair" (issue a new pairing code instead of showing a raw token).
- Audit rows for pair/regenerate/deactivate (who, when, device) — reuse the
  existing manager-approval/audit-log table pattern.

### Windows deliverable

- Single `.exe` (Node runtime bundled via `pkg`, same as the existing PoC) wrapped
  in an Inno Setup/MSI installer.
- Installs a **Windows service** (via `node-windows` or `sc.exe` wrapper) —
  auto-start on boot, restart on crash.
- **Tray app** (status: green connected / amber reconnecting / red error; buttons:
  Test Print, View Log, Re-pair; log file at `%ProgramData%\BingooPrintAgent\agent.log`).
- Reconnect loop with exponential backoff; job polling unchanged (3 s).

### macOS deliverable

- `.pkg` installing a **LaunchAgent** (auto-start at login, KeepAlive) + a menu-bar
  status app; logs under `~/Library/Logs/BingooPrintAgent/`.
- First-run guide for the local-network permission prompt (macOS 15+ asks before
  allowing LAN connections).

### Both platforms

Config = server URL + agent code + permanent token, stored locally
(Windows: DPAPI-protected file or registry; macOS: Keychain). Health = heartbeat
already updates `last_seen_at`; admin screen shows Online/Offline chips (exists) +
new "Send Test Page" action that queues a test `print_job`. Uninstall guide in
each installer.

## 5. Security model

- Pairing code: 6 digits, hashed at rest, 15-min expiry, single use, 5 attempts →
  code invalidated; rate-limit the pair endpoint (reuse login RateLimiter pattern).
- Permanent token: generated server-side, shown to NOBODY (goes straight to the
  agent over TLS), stored hashed (`token_hash`, unchanged).
- Regenerate/deactivate invalidates immediately (existing behavior).
- Agent stays tenant-scoped via subdomain + optionally branch-scoped (existing
  `branch_id` on agents/printers).
- Audit log for pairing, regeneration, deactivation.
- Never re-display any token after pairing — the "token shown once" copy screen
  disappears entirely in the new flow.

## 6. UX flow (admin screen)

```
Printing → Print Agents → [Create Agent]
  Step 1  Name + branch → [Download for Windows] [Download for macOS]
  Step 2  Big pairing code: 483-921  (Copy) — expires in 15:00
  Step 3  Live progress checklist (polls agent state):
          ✔ Installer downloaded
          ✔ Agent paired            (fires when /pair succeeds)
          ✔ Printer connected       (first successful heartbeat + printer mapped)
          ✔ Test print successful   (test job reported printed)
```

Friendly errors: expired code → "Generate a new code"; agent offline > 2 min →
amber banner with "Is the shop PC on?" hints.

## 7. Implementation phases

| Phase | Scope |
|---|---|
| P1 | Pairing backend (columns, /pair endpoint, rate-limit, audit) + admin UI new flow + Test Page action |
| P2 | Windows installer (pkg binary + service + tray + Inno Setup), signed |
| P3 | macOS pkg + LaunchAgent + menu-bar app |
| P4 | Progress checklist polish + re-pair flow + docs/ops runbook |

## 8. Risks

| Risk | Mitigation |
|---|---|
| Unsigned installer triggers SmartScreen/AV | Code-sign cert (EV for Windows ideally); until then document the SmartScreen bypass in the guide |
| Pair endpoint abuse (code guessing) | 6-digit + 15-min + 5 attempts + per-IP rate limit → brute-force infeasible |
| Service fights corporate AV/firewall | Outbound-only HTTPS (no inbound ports) — the agent already only polls |
| PC sleep kills printing | Service + installer disables sleep hint in guide; heartbeat gap alerts admin |
| Old env-var agents in the field | Keep header-auth API unchanged — old agents keep working; migrate at leisure |

## 9. QA plan

Pair happy-path (Win+mac) · expired/used/wrong code · re-pair invalidates old
token · reboot auto-start · network drop/reconnect (kill Wi-Fi 5 min) · test page
to real 9100 printer + `fake-printer.js` · regenerate while agent online (agent
gets 401 → shows re-pair UI) · tenant isolation (agent code from tenant A with
tenant B subdomain → 401).

## 10. Production rollout

Ship P1 backend first (harmless, additive) → beta the Windows installer on the
demo tenant with `fake-printer.js` → one pilot restaurant → docs/ops runbook +
public download page. Old flow stays available until P4 removes the raw-token UI.

## 11. Implementation prompt (next sprint)

**PRINT-AGENT-INSTALLER-1**: implement Phase P1 (pairing backend + admin UI) +
P2 (Windows installer). macOS = follow-up sprint. Non-goals: offline POS, printer
auto-discovery, USB printers.
