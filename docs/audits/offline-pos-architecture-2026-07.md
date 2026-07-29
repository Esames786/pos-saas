# OFFLINE POS — Branch Edge (Local Server) Architecture (2026-07)

> Design/architecture document only. **No code changed.** Audited at `05ee2b5`
> (branch `feat/14d-2-plan-upgrade-requests`; release line `v0.9.2-pilot`).
> Supersedes the per-device PWA direction in
> `docs/audits/offline-pos-strategy-2026-07.md` for full-POS offline.

---

## 1. Executive summary

Offline POS will be delivered as a **Branch Edge / Local POS Mode**: one small
**Branch Server** box on the branch LAN runs the *existing* Bingoo Laravel app +
a local MySQL/MariaDB, provisioned as that branch's single tenant. Every counter
terminal is a **plain browser** opening the branch server's LAN URL
(`http://bingoo.branch.local`) — no per-terminal install, no per-device database.

When a branch is in Local POS Mode, **all** of its sale operations always go
through the Branch Server (internet up or down); the cloud is **locked** from
directly creating/mutating that branch's sales. This single-authority rule
eliminates split-brain (two terminals writing the same table/hold to different
places). The Branch Server continuously syncs completed sales up to the cloud —
which remains the **only** official inventory/accounting source of truth — by
replaying each sale idempotently through the existing
`SalesService::finalizePaidSale` pipeline.

**Why this model (vs the earlier per-device PWA / Electron ideas):** the app is
already a browser-based, env-configurable, `deploy.sh`-deployable Laravel + MySQL
system. Running it on a local box and pointing `TENANT_DB_*` at local MySQL reuses
~100% of the POS/restaurant code and needs **no query rewrite** — unlike SQLite,
which would force adapting MySQL-specific SQL. The remaining work is therefore
**packaging + sync + mode-control**, not a rebuilt app.

Honest cost: this ships a self-hosted appliance to each Local-Mode branch — real
packaging/support burden and a **single point of failure** (box dies → branch
stops) that must be mitigated with backups + a spare-box restore runbook.

## 2. Current code findings (grounded)

| Area | Finding |
|---|---|
| Sale submit | `SalesOrderController::store` → builds lines/payments → `SalesService::finalizePaidSale($sale)` inside a `DB::connection('tenant')->transaction`; resolves prices, FEFO stock-out (`InventoryService::postOutFefo`), posts journals + COGS. **This is the one pipeline sync must reuse.** |
| Totals | Server-authoritative: `SalesTotalsService::calculate` via `POST /api/pos/totals/quote` on every cart change (promotions, service charge, tax). |
| Sale numbering | `SalesService::nextSaleNo()` = `SO-{YmdHis}-{rand}` — timestamp + random, **not a gapless sequence**. `sales_orders.sale_no` is `unique`. Invoice-range reservation (below) needs a new scheme. |
| Idempotency | **No `client_uuid` / no sale-level idempotency today** — only journals are idempotent by `(source_type, source_id)`. A client idempotency key must be added for safe sync replay. |
| Tenancy | `TenancyManager::activate()` sets `DB::setDefaultConnection('tenant')`; master DB holds tenants/plans/permission catalog/module entitlement. DB host/port fully **env-driven** (`TENANT_DB_HOST`, `DB_HOST`, …) → a local box just re-points env. `TenantProvisioner` does `CREATE DATABASE` per tenant. |
| Negative stock | `branches.allow_negative_stock` (default OFF); sale-family-only `allowNegative` on `postOutFefo`/`postMovement`; cost-fallback chain. Reused as-is at sync. |
| Held sales | Server-side (`HeldSaleController`) with restaurant table sessions — becomes Branch-Server-local operational state in Local Mode. |
| Printing | Print agent polls the **cloud** for `print_jobs` and prints to LAN TCP 9100. In Local Mode the *Branch Server* is on the LAN, so printing becomes local (agent polls the branch server, or the server prints directly). |
| Offline infra today | None beyond POS `localStorage` (print toggles + POS-UX-2 terminal-remember). No service worker/IndexedDB — and **not needed** in this model (terminals are thin browsers on a live LAN server). |

## 3. Architecture decision

**Bingoo Branch Server** (one box per Local-Mode branch):

```
Branch LAN
  ├── Terminal 1 (browser) ┐
  ├── Terminal 2 (browser) ┤→  http://bingoo.branch.local  (Branch Server)
  ├── Terminal N (browser) ┘         │
  │                                  ├── Bingoo Laravel app (single-tenant)
  │                                  ├── Local MySQL/MariaDB (this branch only)
  │                                  ├── Local print bridge (LAN 9100)
  │                                  └── Sync service  ⇅  Cloud (when online)
  └── Thermal printers (LAN 9100)
                                     Cloud Laravel/MySQL  = official truth
```

- **DB decision: MySQL/MariaDB on the Branch Server** (not SQLite). Reason:
  maximum reuse — same migrations/queries/reports run unchanged; connections are
  already env-driven. SQLite is rejected here because the existing SQL is
  MySQL-flavored and adapting it buys nothing when a real local box can run MySQL.
- **Electron: launcher/kiosk wrapper only** (optional) — a thin shortcut that
  opens the branch URL fullscreen + bundles the print bridge. **No POS UI is
  duplicated in Electron.** The product is the Branch Server, not a desktop app.
- **App reuse:** the local install is the same `deploy.sh` artifact, provisioned
  as ONE tenant (that branch), `TENANT_DB_*` → local MySQL. Master-DB concerns
  (entitlement/permissions) run locally in a single-tenant, always-entitled
  configuration (a paid on-prem deployment; module gating is satisfied locally).

## 4. Branch operating mode + cloud sale-lock (5-state lifecycle)

Per-branch: `branches.sales_operating_mode` = `cloud` | `local_edge`, plus a
lifecycle `branches.local_edge_status` = `inactive | pending | active | closing |
suspended`. **A raw two-state toggle is unsafe** (accidental `active` before the
Branch Server is ready would lock the branch out of sales). Behavior:

| Status | Cloud sale mutation | Meaning |
|---|---|---|
| `inactive` (cloud) | **allowed** | Today's behavior, unchanged (default for all branches) |
| `pending` | **allowed** | Branch Server install/pair/bootstrap in progress — cloud sales must NOT be interrupted |
| `active` | **BLOCKED** | Branch Server is the authority; cloud refuses direct sale create/mutate |
| `closing` | **BLOCKED** | Controlled exit; no new mutations while pending sync/reconciliation drains |
| `suspended` | **BLOCKED** | Emergency/security hold |

Cloud sale-lock applies **only** to `active`/`closing` (implemented via
`Branch::isLocalEdgeActive()`). The cloud still shows last-synced data read-only.

Enforcement (BRANCH-OPERATING-MODE-1 + HARDEN-1): the centralized
`App\Services\Edge\BranchOperatingModeService::assertSaleMutationAllowed($branch)`
is called in every sale-mutating controller (POS/manual sale store, sale cancel,
held-sale store/cancel, sales-return store, restaurant table-session
open/bill-requested/close/move/merge, split-bill, **and shift open/close**). It
throws a self-rendering `BranchLocalEdgeException`.

**Exact response behavior:** JSON/AJAX requests get **HTTP 409** with a structured
`{message, code}`; normal browser form POSTs get a friendly `redirect-back` with a
validation message (not a 409). Never a 500.

Two identical codebases are distinguished by `config('app.role')` =
`cloud | branch_server` (env-only, never from a request). A `branch_server` may
only mutate its hard-bound **tenant AND branch** — both `EDGE_TENANT_CODE` (must
equal the active tenant) and `EDGE_BRANCH_ID` are enforced (reason codes
`BRANCH_SERVER_TENANT_NOT_BOUND` / `BRANCH_SERVER_BRANCH_NOT_BOUND`).

**Code-enforced lifecycle matrix** (`BranchOperatingModeService::canTransition`,
not just UI): `inactive→pending`; `pending→active|suspended|inactive`;
`active→closing|suspended`; `closing→inactive|suspended`; `suspended→inactive`.
Everything else (e.g. `inactive→active`, `pending→closing`, `closing→active`,
`suspended→active`, unknown/same-state) is rejected with
`BRANCH_LOCAL_EDGE_INVALID_TRANSITION`. Returning from suspended uses this
controlled action, never a raw field update.

**Feature flag:** the Local POS setup journey (pairing/bootstrap/sync) is
incomplete, so its UI **and** request actions are gated behind
`config('app.edge_feature_enabled')` (`EDGE_FEATURE_ENABLED`, default **false**).
With the flag off there is no Setup button and the setup/return actions 409 on
direct HTTP (`BRANCH_LOCAL_EDGE_FEATURE_DISABLED`) — the status badge stays
visible read-only. Existing cloud POS is never affected by this flag. UI can only
request setup (→ `pending`) or return to cloud; it can **never** set `active`.

## 5. Offline MVP scope (allowed / blocked)

Because a Local-Mode branch is a **single authority with a live local server**,
far more works than a per-device queue — dine-in included.

| Feature | Verdict | Note |
|---|---|---|
| Quick sale / takeaway / delivery (cash) | **Phase 1** | Core |
| Dine-in table board, open/close/move table | **Phase 1** | Local server owns sessions → safe across terminals |
| Held orders / recall | **Phase 1** | Local state |
| Restaurant products, modifiers, KOT | **Phase 1** | Consumption posts at sync |
| Barcode scan, product/category search | **Phase 1** | Local catalog |
| Cash payments, split cash, tips, service charge, line discounts | **Phase 1** | All computed by the local app (same code) |
| Negative-inventory rule | **Phase 1** | Reused at sync |
| Cashier shifts, local receipt/KOT + reprint | **Phase 1** | Local |
| Cached basic customers (name/phone/address) | **Phase 1** | Read-only snapshot |
| Simple promotions (fixed/%/branch/time) | **Phase 2** | Local-clock guarded |
| **Global usage-limited promotions** | **Blocked** | Cross-branch atomic counter (BUG-003) — sync exception if attempted |
| Card/online gateway payments | **Blocked** | Needs live gateway |
| External aggregator live API (Foodpanda pull/confirm/push) | **Blocked** | Needs internet; manual/cash delivery order is fine |
| Credit sales / customer ledger settlement | **Blocked (Phase 3)** | Receivables must not fork |
| Returns / refunds | **Blocked (Phase 3)** | Against official state |
| Purchase/GRN, stock adjustment/transfer, supplier payment | **Blocked** | Direct official-stock/AP mutations |
| Manufacturing posting | **Blocked** | Extreme-end track |
| Cloud-only manager approvals | **Blocked** | Server-authoritative PIN checks |

## 6. Local data — reuse the tenant schema (no separate SQLite model)

The Branch Server uses the **same tenant migrations** (MySQL). No parallel
"cached_*" schema is invented; operational tables (sales_orders, lines, payments,
restaurant_table_sessions, shifts, print_jobs, products, prices, …) already exist.
Two additions support sync:

- `sales_orders.client_uuid` (uuid, unique) — idempotency key set at capture.
- `sales_orders.sync_state` (enum: `local` | `synced` | `exception`, default
  `local` on the Branch Server; `synced` on cloud-native sales) + `synced_at`,
  `cloud_sale_id` nullable.
- New `offline_sync_exceptions` (cloud) — see §9.

Catalog/price/user/settings on the Branch Server are refreshed from the cloud
snapshot (§7) and are **read-mostly locally** (cloud owns them).

## 7. Cloud ⇄ Branch Server sync API

Two-way, with clear precedence: **cloud owns catalog/prices/users/settings;
Branch Server owns operational sales/tables/shifts.**

| Endpoint | Direction | Purpose |
|---|---|---|
| `POST /api/branch/pair` | up | Pair a Branch Server (extends the print-agent pairing model → device code + token, branch-scoped) |
| `GET /api/branch/bootstrap` | down | Full one-time snapshot: branch, terminals, users-lite (+ role/perm snapshot + PIN hashes), products, variants, barcodes, categories, prices, tax, payment methods, printers, KOT routing, invoice range |
| `GET /api/branch/changes?since=cursor` | down | Incremental catalog/price/user/settings deltas |
| `POST /api/branch/sales/sync` | up | Batch of completed local sales (each with `client_uuid`, local `sale_no`, lines, payments, timestamps) → idempotent replay |
| `GET /api/branch/sales/{client_uuid}/status` | up | Per-sale sync status |
| `POST /api/branch/heartbeat` | up | last_seen, app/catalog version, pending/exception counts |
| `POST /api/branch/mode/close-request` | up | Begin controlled exit from Local Mode (§reconciliation) |

Auth: **Branch Device pairing** (reuse `PrintAgentPairingService` pattern —
6-digit code, HMAC digest, single-use, rotating token). **No user password is
stored on the box**; local cashier auth uses snapshotted PIN hashes with a role
snapshot expiry.

Sync posting rule (the linchpin): `/sales/sync` **must** call the existing
`SalesService::finalizePaidSale` per sale — it never writes journals/stock
independently. Idempotent on `(tenant, client_uuid)`: a re-sent sale returns the
existing `cloud_sale_id` + official `sale_no`, no double post.

## 8. Invoice numbering — range reservation

On entering Local Mode, the cloud reserves an invoice range per branch, e.g.
`KHI01-OF-000001 … KHI01-OF-010000`, stored on the branch record. The Branch
Server assigns sequential numbers from this range locally — so a receipt number is
available instantly, duplicates are impossible across terminals, and no rename is
needed at sync. Every sale still carries `client_uuid` for idempotency.
(Replaces `nextSaleNo()`'s random scheme for Local-Mode sales.)

**Sequential ≠ absolute-gapless.** A crash, cancelled checkout, or void can leave
a reserved number unused. Numbers are **never reused**; instead every reserved
number is traceable to a status: `issued`, `synced`, `voided`, `abandoned`, or
`missing/unexplained`. **Only an *unexplained* gap** is a reconciliation/fraud
alert — voided/abandoned gaps are expected and accounted for.

### Provisional (local) vs official (cloud) accounting

The Branch Server reuses the existing POS pipeline locally, so it DOES create
local `stock`, `COGS`, `journal`, table/session and payment rows — these are
**operational/provisional branch records**, the branch's own working copy. They
are **never copied to the cloud.** At sync the Branch Server sends only the
**canonical sale payload** (`client_uuid`, reserved `sale_no`, branch/terminal/
user, lines/modifiers, printed prices/taxes/discounts, payments, `captured_at`,
local totals). The cloud then **independently** posts official stock, FEFO, COGS
and journals via `SalesService::finalizePaidSale`. Cloud is the only official
inventory/accounting record; the local ledger is provisional and disposable.

## 9. Sync Exception Dashboard (cloud)

`Reports → POS → Offline Sync Exceptions`. Columns: branch, terminal/device,
local invoice no, cashier, captured-at, synced-at, reason, payload summary,
attempts, last error. Reasons: insufficient stock on OFF branch, deleted/disabled
product, price/tax drift beyond threshold, invalid user, closed terminal, payment
mismatch, duplicate event, clock drift. Actions (permissioned): Retry, Approve
negative posting (if branch policy + permission), Convert to held, Cancel w/
reason, Download payload, View diff. **No delete without manager/admin permission.**

## 10. Conflict rules

- **Stock:** OFF branch + insufficient at sync → **exception** (never silent).
  ON branch (`allow_negative_stock`) → posts via existing negative-stock family.
- **Price/tax drift:** if cloud-recomputed grand_total differs from the printed
  local total beyond a threshold (recommend `> 1.00` or `> 0.5%`, configurable) →
  exception; within threshold → post (the customer already paid the printed amount;
  server keeps the sale, records the delta for reporting).
- **Deleted/disabled product after snapshot** → exception.
- **Dates:** `sale_date` = **captured (local) timestamp**; journal `entry_date`
  follows `sale_date` (consistent with existing backdated-document behavior). Sync
  also stores server-receipt time; large client-vs-server drift → flagged.
- **Receipt:** local/pending receipts watermarked "OFFLINE — PENDING SYNC";
  official reprint (with real `sale_no`) available after sync via existing reprint.

## 11. Offline UX

Reuse the current POS screen. Add a persistent status strip: Online/Offline
(server↔cloud link), last sync time, catalog freshness, pending count, exception
count, shift indicator. Autosave cart + crash recovery (already have cart persist
from POS-UX-2). Shortcuts to standardize: F2 search, F4 hold, F6 recall, F8
discount, F9 cash checkout, Ctrl+P reprint, Ctrl+S sync-now, Esc close, Enter add.
Offline must feel intentional, not broken.

## 12. Security model

Branch Device pairing + rotating token in OS-protected local store; snapshot only
what POS needs (no full customer ledger, no supplier data, no full GL, no admin
credentials); cashier PIN hashes + role-snapshot expiry; device revoke + remote
"lock/wipe pending" flag honored at next heartbeat; audit trail of pairing/mode
changes/exception actions; clock-drift capture; enforce minimum app/catalog
version before sync.

## 13. Phased roadmap

```
1. OFFLINE-POS-ARCHITECTURE-1        this doc
2. BRANCH-OPERATING-MODE-1           branches.sales_operating_mode + cloud sale-lock guard + APP_ROLE
3. SALE-IDEMPOTENCY-1 (+HARDEN-1)    client_uuid + payload-hash; replay/conflict/unverifiable/pending;
                                     real parallel-HTTP race proven (one row, no 500); strict null-hash;
                                     ensure-once receipt + KOT-delta print recovery. THE sync foundation.
4. BRANCH-DEVICE-PAIRING-1           pair API + token (extends print-agent pairing)
5. BRANCH-BOOTSTRAP-SNAPSHOT-1       bootstrap + changes(since) APIs
6. BRANCH-SERVER-PACKAGING-1         one-box installer (bundled PHP+MySQL+app+print bridge+auto-start+local backup)
7. OFFLINE-SYNC-ENGINE-1             /sales/sync idempotent replay + invoice-range reservation
8. LOCAL-PRINT-LAN-1                 print bridge on branch LAN
9. SYNC-EXCEPTION-DASHBOARD-1
10. MODE-RECONCILIATION-1            safe enter/exit + shift/cash/sequence reconciliation
11. POS-SHORTCUTS-POLISH-1 + pilot
```

## 14. Timeline (honest)

- Server-side foundation (modes 2,3,4,5,7,9,10 — all cloud/Laravel, testable via
  curl, no box): **~5-7 weeks**.
- Branch Server packaging + local print bridge (6,8): **~3-4 weeks** (the ops-heavy
  part — bundling a portable PHP+MySQL stack + installer + auto-start + backup).
- Pilot hardening on one branch: **~2-3 weeks**.
- **Marketable single-branch Local POS: ~3-4 months** for something trustworthy
  with real money. Riskiest: packaging/support of the local box, two-way sync
  correctness, and the mode enter/exit reconciliation.

## 15. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Branch Server SPOF** (box dies → branch stops) | Automated hourly local DB backup + nightly cloud backup; documented spare-box restore (< 30 min); paper fallback in runbook; optional read-only cloud-fallback for terminals |
| Data loss on box | Backups above; sales sync frequently → cloud holds most data |
| Duplicate sale | `client_uuid` idempotency; invoice-range gap detection |
| Stock/price/tax conflict | §10 rules + exception queue + threshold |
| Cashier fraud (un-synced/void) | Gapless reserved sequence + gap detection; mandatory sync before shift close; daily reconciliation report |
| Device theft | Revoke + lock/wipe-pending flag; minimal local data; PIN + role expiry |
| Clock tampering | Store client + server time; large drift flagged/exception |
| App/catalog version mismatch | Enforce min version at sync; bootstrap version stamp |
| Multi-terminal conflict | Solved by single Branch Server authority |
| Support burden | Packaging quality + monitoring heartbeat + rollout runbook |

## 16. QA plan (implementation-time)

Cloud-testable without a box: mode-lock 403s on `local_edge` branch; `/sales/sync`
idempotent (replay same `client_uuid` → no second journal, `tb_diff=0`); OFF-branch
oversell → exception (not posted); ON-branch → negative-stock post; price-drift
threshold → exception vs post; invoice-range gap detection; bootstrap/changes
snapshots. Branch-server: full POS incl. dine-in on a LAN box, offline period then
reconnect → all sales sync, official numbers assigned, stock/journals post once,
`tb_diff=0`. Every phase ends with the branch-aware finance smoke.

## 17. Rollout — see `docs/ops/OFFLINE_POS_ROLLOUT_RUNBOOK.md`

demo tenant → one branch → cash-first → pilot restaurant → feature-flagged →
sync monitoring + daily reconciliation → documented rollback (mode close → cloud).

## 18. Implementation progress

- ✅ **BRANCH-OPERATING-MODE-1** (done locally): 5-state lifecycle columns,
  `config('app.role')` + `EDGE_*` (env-only), `BranchOperatingModeService` +
  self-rendering `BranchLocalEdgeException`, guard in 9 sale-mutating paths, admin
  UI, audit logs. Cloud lock on `active`/`closing`/`suspended`; `pending` keeps
  cloud sales.
- ✅ **BRANCH-OPERATING-MODE-HARDEN-1** (done locally): code-enforced transition
  matrix (invalid jumps rejected); `EDGE_TENANT_CODE`+`EDGE_BRANCH_ID` both
  enforced; **shift open/close guarded** (cash-reconciliation split-brain); Local
  POS setup UI+actions behind `EDGE_FEATURE_ENABLED` (default OFF).
- **Next: SALE-IDEMPOTENCY-1** — `sales_orders.client_uuid` (unique) +
  `sync_state`/`synced_at`/`cloud_sale_id`; make sale posting idempotent-by-uuid.
  Then BRANCH-DEVICE-PAIRING-1 → BRANCH-BOOTSTRAP-SNAPSHOT-1 → OFFLINE-SYNC-ENGINE-1
  → BRANCH-SERVER-PACKAGING-1. Non-goals throughout: Electron, manufacturing.
