# Edge 0.9 — lead decisions: optional Automatic Offline Takeover + self-service Offline Counter (21 Nov 2026)

Owner requirement (21 Nov, evening): develop an OPTIONAL, default-OFF "Automatic Offline Takeover" branch setting and a
cashier self-service "Install Offline Counter" from the Online POS. Development only: never enabled on LAB, Khatri or production
without a separate owner GO.

## 1. Can the existing lease/fencing contract support automatic takeover? — YES, with three added guards

Verified in code (`app/Services/Edge/EdgeAuthorityLeaseService.php`, `BranchOperatingModeService::leaseBlocksCloud`,
`EdgeAuthorityService::cloudLeaseLapsedLocally`):

- The Cloud holds the branch writer lease; every accepted heartbeat sets `expires_at = cloud_now + TTL(120 s)`.
- When heartbeats stop reaching the Cloud, `blocksCloud()` returns true at `expires_at` on the CLOUD clock: the Cloud refuses the
  branch's sale / stock / shift / table / held / split / return mutations by itself (records `fenced_at`). Internet loss never
  hands authority to anyone; it only makes the Cloud stop.
- The appliance may take over only when `edge_now >= last_ack + TTL + skew_margin(30 s)` (gate AUTHORITY_TAKEOVER_SAFE) and every
  other gate passes. `last_ack` is when the appliance RECEIVED the Cloud's ack, so it is never earlier than the Cloud's renewal.
- After takeover the appliance heartbeats `local_active` → holder EDGE; resumed heartbeats never bounce authority; only the
  sync-clean, acknowledged handback returns it (`EdgeHandbackOrchestrator`). Replayed/out-of-order beats are refused (seq).

Therefore an automatic trigger that calls the SAME `EdgeAuthorityService::takeOver(true, 'auto-takeover', false, null)` under the
SAME lock is exactly as safe as the button, provided:

1. **Clock guard** (new gate input, auto only): the appliance records the Cloud `server_time` skew at every ack; auto-takeover
   refuses when the last measured |skew| > 10 s or is unknown (Khatri's clock was 12 h wrong on 21 Nov — the 30 s margin assumes
   synced clocks).
2. **Sustained-loss guard**: connection lost continuously for `after_seconds` (default 300, minimum 180 = TTL + margin + 30) with
   ≥ N consecutive failed heartbeats; any successful ack resets the timer (rapid flapping never accumulates).
3. **Anti-flap / rate guard**: one auto attempt per 60 s, audited; no auto-takeover within 15 min after a handback; refusal reasons
   recorded verbatim (`[edge-auto-takeover]` audit line + status page "last automatic decision").

Partition cases (must be tests): (a) Edge cut off, Cloud up, cashiers online → Cloud fences itself at expires_at, Edge takes over
after TTL + margin + sustained window — no overlap; (b) cashiers cut off, Edge still acks → lease stays valid → no takeover
(manual also refused) — availability gap, not a safety gap; (c) flapping → timer resets; (d) concurrent Cloud write at the boundary
→ refused after expires_at by the fence; (e) two auto/manual attempts → the existing lock + idempotent `already` result;
(f) reconnect after auto-takeover → holder stays EDGE, NO automatic handback, owner presses Return to Online.

## 2. Where the setting lives

- **Cloud (stream C)**: branch columns `edge_auto_takeover_enabled` (bool, default false) and `edge_auto_takeover_after_seconds`
  (int, default 300, min 180, max 3600), editable on the Offline Edge page only by a user with `tenant.offline-edge.security`, with
  a warning text and audit; delivered to the appliance in the existing config refresh / bootstrap `branch` section (new key
  `auto_takeover: {enabled, after_seconds, set_by, set_at}`).
- **Appliance (stream B)**: `App\Services\Edge\AutoTakeover\AutoTakeoverEvaluator::evaluate(): array` (pure decision + reasons)
  and `::evaluateAndAct()` (calls the existing takeOver under the existing `edge-local-authority-control` lock). Stream B does NOT
  edit `EdgeAuthorityTick`; the lead adds ONE guarded call in the standby branch of the tick at integration. Skew capture: stream B
  adds the measured skew to edge-local meta from the heartbeat ack (`EdgeAuthorityLeaseClient` response handling — tell the lead the
  exact hook; the lead applies it). Health: `auto_failover` becomes the effective state (enabled/after/last decision); status page
  shows "Automatic Offline Takeover: OFF / ON after N s" in the Selling authority block (B owns that line).
- **Kill switch**: appliance config `edge.authority.auto_takeover_allowed` (env `EDGE_AUTO_TAKEOVER_ALLOWED`, default **false** in
  0.9 packages) — effective = Cloud branch setting AND appliance allowed. Enabling at a real site therefore needs the owner's GO
  twice (Cloud setting + appliance env), which matches "not authorization to enable it on LAB, Khatri or production".

## 3. Live operational fact to keep visible (Khatri today)

Because the Cloud fences itself when heartbeats stop, **the Online POS of a paired branch refuses sales ~2 minutes after its Branch
Server PC stops heartbeating** (PC off, rebooting, its own network down), and recovers within a minute of the next heartbeat.
Khatri at 12:14 UTC: heartbeat 15 s old, never fenced. Operator rule until 0.9: keep the Branch Server PC on and online during
business hours. A permanently dead appliance needs the audited Cloud release (`edge:authority:release`). 0.9 must surface this
on the Online POS (clear message "Branch Server not reachable — the Online POS is paused for safety") — stream C.

## 4. Self-service "Install Offline Counter"

- **Cloud (C)**: new permission `tenant.offline-edge.counter-install` (cashier + manager groups in `PosPermissionCatalog`); entry
  in the Online POS device menu and on the Offline Edge page; route returns a per-user, per-branch counter setup (K7 renderer) only
  for the user's own branch with an active paired device and a LAN profile; optional self-enrolment: the logged-in user may request
  an enrollment assertion **for themselves only** (`POST /pos/offline-counter/my-enrollment`, throttled, audited), embedded in the
  download (15 min) so the counter wizard can set the user's own Edge PIN typed locally; a terminal binding hint (the user's
  default terminal) included. No user can issue assertions for others, provision a Branch Server, or grant authority permissions.
- **Counter wizard (D)**: Windows UI (WPF) generated from the template: shows branch + Edge server + CA fingerprint and asks
  **explicit confirmation** before installing the root CA (no silent root install); hosts line scoped to `bingoo-edge.local`;
  shortcuts (Online vs Offline clearly named + icon); validates HTTPS by name and IP with full verification; optional "set my Edge
  PIN" page (if the download carries the user's own assertion) that enrols through the Edge (new appliance endpoint needed — see §5);
  verifies Edge login and the counter's terminal binding; final "Offline Counter Ready" or an actionable error; Repair and Uninstall
  modes (remove shortcuts, hosts line, and — after confirmation — the CA); works without WAN after setup (LAN only).
- **Signing (E)**: no Authenticode code-signing certificate exists → SmartScreen/AllSigned cannot be satisfied today → BLOCKER for
  "signed Windows setup"; meanwhile publish the SHA-256 on the download page and sign the payload JSON (LAN profile) with the Cloud
  enrollment key so the counter wizard verifies it was produced by the Cloud.

## 5. Appliance endpoint needed for counter self-enrolment (stream B, lead wires the allowlist)

`POST /edge/local/enroll` (LAN, rate-limited 5/min/IP, no session): body = the Cloud assertion + the PIN typed in the counter
wizard over the verified TLS channel → `EdgeEnrollmentConsumer::consume()` (existing: signature, expiry, replay, tenant/branch/device
binding, PIN strength) → 201 or a reason. Never logs the PIN. Optional and off by default (`edge.local_enroll_endpoint.enabled`).
