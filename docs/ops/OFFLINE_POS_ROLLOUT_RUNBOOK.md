# Offline POS (Branch Edge) — Rollout Runbook

Operational runbook for piloting and running a branch in **Local POS Mode** (one
Branch Server on the branch LAN; terminals are browsers). Architecture:
`docs/audits/offline-pos-architecture-2026-07.md`. **Design stage — nothing here
is live yet; this is the intended procedure.**

## Pre-requisites (per branch)

- Branch Server box: a small always-on PC/mini-PC on the branch LAN, wired
  Ethernet, on a UPS, static LAN IP (e.g. `192.168.10.5`), local hostname
  `bingoo.branch.local`.
- Bundled Branch Server installer (PHP + MySQL/MariaDB + app + local print bridge
  + auto-start service + local backup) — from BRANCH-SERVER-PACKAGING-1.
- Thermal printers on the LAN (real IPs, port 9100 — **not** 127.0.0.1).
- Terminals: any modern browser pointed at `http://bingoo.branch.local`.

## Enter Local POS Mode (owner + support)

1. Cloud: **Branches → Edit → Sales Operating Mode → Local POS Mode** (this alone
   does not switch until pairing completes).
2. Install the Branch Server on the box; pair it (6-digit code from cloud, like
   the print agent).
3. Bootstrap snapshot pulls: branch, terminals, users+PIN hashes+roles, products,
   prices, tax, payment methods, printers, KOT routing, **invoice range reserved**.
4. Verify: open the branch URL from two terminals, add printers' real LAN IPs,
   **Test Print** succeeds on each printer.
5. Cloud confirms mode active → **cloud sale creation for this branch is locked**
   (split-brain guard). From here, every sale runs on the Branch Server.

## Daily operation

- Cashiers use terminals (browser) exactly like the cloud POS. Status strip shows
  Online/Offline (server↔cloud), last sync, pending/exception counts, shift.
- Branch Server syncs completed sales to cloud continuously when internet is up;
  queues them when down and drains automatically on reconnect.
- **End of each shift:** cashier closes shift → system requires pending sync = 0
  before finalizing cash reconciliation (or flags remaining as "pending upload").
- **Daily reconciliation report** (cloud): synced sales vs reserved invoice
  sequence — any gap or exception is investigated same day.

## Internet-down behavior (expected, not an incident)

- Terminals keep working against the Branch Server (LAN only — no internet needed).
- Sales queue locally; receipts/KOT print locally; status strip shows Offline.
- On reconnect: queue drains, official `sale_no` + stock/journals post in the
  cloud via `finalizePaidSale`, sale marked `synced`. No cashier action needed.

## Branch Server failure (SPOF) — recovery

1. **Box dead mid-service:** switch to paper tickets immediately (kept at each
   counter). Do not start cloud sales for this branch unless mode is formally
   closed (see below) — else split-brain.
2. Restore: bring up the spare box, install Branch Server, pair to the **same**
   branch → bootstrap re-pulls catalog; restore the latest local DB backup
   (hourly) to recover un-synced sales. Target < 30 min.
3. Enter paper tickets into the restored server; verify invoice sequence continuity.

## Exit Local POS Mode → Cloud Mode (controlled, never a raw toggle)

1. Stop taking new sales on the branch.
2. `POST /api/branch/mode/close-request`. Gate checks: **pending sync = 0**,
   **exceptions = 0**, all **shifts reconciled**, **cash confirmed**, **invoice
   sequence verified** (no gaps).
3. Owner approves mode closure → cloud **unlocks** direct sales for the branch →
   Branch Server goes read-only/decommissioned.
4. If any gate fails: resolve exceptions in the Sync Exception Dashboard first.

## Pilot plan (first release)

- Scope: **demo tenant → one branch → cash-only → few users** behind a feature
  flag. No credit/returns/purchasing/manufacturing offline.
- Monitor: heartbeat (last_seen, versions, pending/exception counts), daily
  reconciliation, `tb_diff=0` after each sync batch.
- Success criteria: a full offline period (internet pulled) then reconnect with
  100% sales synced, official numbers assigned, stock/journals posted once,
  `tb_diff=0`, zero unexplained exceptions.

## Rollback

- Feature is per-branch and mode-gated: set the branch back to Cloud Mode via the
  controlled exit above. The cloud app itself is unchanged for cloud-mode branches,
  so there is no app rollback — only mode closure for the piloted branch.

## Hard rules

- Cloud remains the **only** official inventory/accounting source of truth.
- Local sales post **only** through `SalesService::finalizePaidSale` at sync —
  the Branch Server never writes cloud journals/stock independently.
- A `local_edge` branch's sales **never** run on the cloud instance (guard 403).
- No manager/admin-less deletion of sync exceptions.
