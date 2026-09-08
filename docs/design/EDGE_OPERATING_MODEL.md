# Offline Edge — Final Product Operating Model (SCOPE LOCK)

**Status: permanent product requirement** (owner directive, 8 Sep 2026). Every future Edge tranche is measured
against this document. It does not replace the parity rule (*current Online POS is the functional specification*);
it defines the **operating model** around it.

> The client should experience an Internet outage as a change in **connection state**, not as a change to a
> different POS product.

```
Cloud healthy : Cloud writes, Edge stays warm/fresh.
WAN fails     : safe authority timeout/fence → Edge becomes branch writer.
Offline       : cashier + LAN + printers continue locally.
WAN restores  : Edge remains writer → sync/reconcile everything → controlled handback.
Only then     : Cloud POS becomes writer again.
```

---

## The locked requirements, and where the code stands today

Legend — **BUILT** (executable-proven on this branch) · **PARTIAL** (foundation exists, contract incomplete) · **MISSING**.

### 1. Edge is a WARM STANDBY, not a cold backup — `PARTIAL`
While Internet is healthy the appliance stays connected in the background and keeps its local operating state fresh
enough to take over **at the moment** of WAN failure. Never "Internet fails → start downloading everything".

Required fresh set: products, prices, categories, deals/combos, customers needed locally, users, permissions,
terminals, order types, tables, waiters, reservations, printer mappings/config, current business/operating date,
compatible schema/config revision, authoritative operational stock baseline/state, branch metadata.

| Piece | Today |
|---|---|
| Config/catalog/users/permissions/terminals/tables/waiters/printers refresh | BUILT as a **revisioned, non-destructive pull** (`EdgeLocalConfigRefreshApplier`, EDGE-CONFIG-REFRESH-1, tombstone-safe) — but **pull-on-demand**; no scheduled standby refresh task exists (`scripts/edge` ships sync-sender, backup, print-worker tasks only). |
| Operational stock authority | BUILT as **baseline issuance + controlled cutover** (`EdgeBaselineIssuanceService`, `EdgeBaselineCutoverService`, Gate 0) — a cutover-time snapshot, **not continuous** (§17 freshness model MISSING). |
| Reservations | BUILT both ways: Edge-owned while local, projected back at handback (`EdgeReservationHandbackService`). Standby-time freshness of *Cloud* reservations into Edge: MISSING (would ride the config refresh). |
| Operating/business date | BUILT (`TenantClock::operatingBusinessDate`, shift-frozen dates). |

### 2. Online is primary while connected — `BUILT (as a status), PARTIAL (as a protocol)`
`branches.sales_operating_mode` = `cloud` | `local_edge`; `local_edge_status` = pending → active | suspended |
inactive | closing. Cloud owns mutation while `cloud`. Single-writer intent exists; the **protocol** that decides
who writes during a partition (§5–6) is the gap.

### 3. Internet-failure detection — `MISSING`
Required explicit state machine with bounded heartbeat/failure checks:
`ONLINE → CONNECTION_UNSTABLE → CONNECTION_LOST → PREPARING_LOCAL → LOCAL_ACTIVE`.
Today: no connectivity state machine; Local Mode is switched by an operator/CLI status change. Cashier-facing
state must be business-friendly only (no leases / hashes / baseline UUID / activation epoch — that rule is already
enforced on the cashier page and sync summary, `EdgeCashierShiftAndNetworkDownHttpMySqlTest`).

### 4. Automatic / safe local takeover — `PARTIAL (readiness), MISSING (takeover)`
Readiness signals exist and fail closed today: `EdgeLocalReadiness` (local DB, binding, config revision/schema,
crypto, local auth, operational stock, `activation_ready`), `EdgeCompatibilityService`, `EdgeLocalSchemaUpgrader`.
Missing: the takeover decision that consumes them as a gate:
```
LOCAL_DB_HEALTHY · CONFIG_COMPATIBLE · SCHEMA_COMPATIBLE · BRANCH_BINDING_VALID · LOCAL_USERS_READY
STOCK_AUTHORITY_READY · ENTITLEMENT/LEASE_VALID · AUTHORITY_TAKEOVER_SAFE   → all yes, else FAIL CLOSED
```
Pilot posture: supervisor confirmation retained; automatic failover only after authority fencing is physically certified.

### 5. Single-writer branch authority — `BUILT (fence), P0 protocol pending (§6)`
When Edge owns Branch A, Cloud must refuse transactional mutations for Branch A only (tenant stays up; Branch B
keeps using Cloud). **Fence BUILT**: `BranchOperatingModeService::assertSaleMutationAllowed` (+ official-stock
guard) is called from Sales/Held/Split/Table/TableSession/Reservation/Shift/Return/PrintJob/Branch controllers;
Edge refuses Cloud-authority mutations symmetrically. What decides *when* the fence engages during a partition is §6.

### 6. Partition / authority lease — `MISSING (P0)`
Edge cannot ask Cloud to disable the branch when the WAN is already gone. Required: a **branch-authority lease with
heartbeat and expiry** — Cloud stops accepting Branch A mutations when its authority can no longer be safely renewed;
Edge assumes local authority only after the takeover condition is safe. No split brain, no last-write-wins.
**Must be executable-tested (two independent processes, partition simulated) before pilot.** This is the "P
entitlement/authority lease" tranche; `OfflineEdgeEntitlementService` today is tenant entitlement only (a lease
sprint was explicitly deferred in its own docblock).

### 7. Offline cashier experience — `BUILT`
LOCAL_ACTIVE cashier surface = the Online POS: Takeaway, Quick Sale, Dine-In, Table Board, Reservations, Hold, Draft,
Recall, Add Round, KOT, Review & Pay, Preview Bill, cash payment, receipt, Recent Prints, Quick Report, shift
workflow — 32 workflows FULL_OFFLINE_PARITY, real-HTTP proven (`docs/status/edge-online-pos-parity-register.md`).
Remaining Edge build items (not Internet-bound): deal selling, discounts/promo, split bill, delivery/address.

### 8. Offline sale behaviour — `BUILT`
Commit locally → Edge operational stock → local payment → local print → immutable outbox → success **without
waiting for Internet** (`EdgeLocalPosService`, sync 1B outbox); cashier sees "Pending sync: N" only.

### 9. Reconnect ≠ immediate Cloud switch — `PARTIAL`
Required: `LOCAL_ACTIVE → CONNECTION_RESTORED → SYNCING → RECONCILING → HANDING_BACK → ONLINE`, Edge remaining
writer and Cloud fenced throughout SYNCING. Pieces BUILT: sync sender/ACK (1D), reconciliation (1E), `closing`
status, reservation handback. MISSING: the state machine that sequences them and holds the fence until §10 is clean.

### 10. Sync offline transactions — `BUILT`
Exactly-once Edge sync authority: same sale UUID/content identity, authenticated transport, Cloud official
stock/COGS/finance application, verified ACK, lost-ACK reconciliation; no duplicate sale/stock/COGS/GL/cash
(sync 1B–1E, `EdgeInboundSaleIngestionService`, `EdgeSyncReconciliationService`). Gate before handback:
`pending = 0 · failed_permanent = 0 · reconciliation clean` — signals exist (`EdgeSyncStatusService`), the gate
that consumes them is part of §9/§11.

### 11. Hand back to Cloud — `PARTIAL`
Correct order: freeze local mutations → verify local state → finish sync/reconciliation → hand back reservations →
resolve active state → obtain/verify current Cloud baseline → transfer authority → enable Cloud writes. Never Cloud
first, reconcile later. BUILT: `closing` freeze status, reservation handback (fail-closed on occupied/conflict),
baseline round trip. MISSING: handback of **active tables / held / draft / open shifts** (drain-or-handback decision),
and the orchestrator that runs the sequence and only then flips authority.

### 12. LAN keeps running without Internet — `BUILT (assumption), deployment guidance`
WAN failure ≠ LAN failure. Cashier PCs, Edge server, kitchen/receipt printers stay on the local switch. Recommend:
wired Edge server, dedicated router/switch/AP, DHCP reservations / static IPs, stable printer IPs, UPS. If the only
ISP modem/router dies, LAN may die too — a physical network issue, not an Edge assumption.
(`config/edge.php lan.*`: hostname, reserved IP, name mechanism.)

### 13. Network printing offline — `BUILT`
Edge POS → Edge local print queue → Edge local print worker → LAN printer IP (`EdgeLocalPrintDeliveryService`,
`EdgeNetworkPrinterTransport`, per-printer isolation, lease + retry, exact stored bytes). Never through Cloud.

### 14. No second print agent for LAN printers — `BUILT`
Edge sends directly to printer IPs; the Cloud Print Agent backs off while it cannot poll Cloud.

### 15. USB / workstation printers — `MISSING`
One Bingoo Print Agent per printer host with two authorities: ONLINE (poll Cloud jobs) and LOCAL (accept
authenticated Edge-local jobs over LAN/localhost). Today Local Mode falls back to **Print Here** (browser document)
for non-network printers; the dual-authority agent is the "R print" tranche.

### 16. Never double-print after reconnect — `BUILT`
Cloud ingestion (`EdgeInboundSaleIngestionService`) creates **no** print job; printing intent (Edge, exactly-once
`logical_key`) is separate from Cloud sale ingestion (exactly-once registry). Add an explicit regression guard in
the P/Q tranche: "ingesting an Edge sale queues zero print jobs".

### 17. Standby data freshness — `MISSING (P0 for automatic failover)`
Cloud stock 100 → 95 → 92, WAN fails: Edge must start near **92**, not the morning's 100; sells 4 → 88; reconnect →
4 sales sync exactly once → Cloud ends at 88. Today the operational baseline is issued at cutover; a **continuous,
safely-synchronised standby baseline/state** (bounded staleness, revisioned, fenced against split brain) is the
freshness model still to be built. It is what makes §4 automatic.

### 18. Cashier-facing states — `PARTIAL`
`ONLINE · INTERNET CONNECTION LOST · PREPARING LOCAL MODE · LOCAL MODE ACTIVE · CONNECTION RESTORED ·
SYNCHRONIZING TRANSACTIONS · RETURNING TO ONLINE · ONLINE`. The cashier page already speaks business-friendly
(Synced / Pending sync / needs attention); the connection-state labels follow the §3/§9 state machine.

### 19. Physical pilot certification — `NOT YET RUN`
Real branch-style test: Cloud online + Edge standby → verify Edge current → physically disconnect WAN → LAN alive →
Local Mode → real cashier login → Takeaway → Dine-In/KOT → network printer → receipt → several offline sales →
outbox → reconnect → sync all → **zero duplicate** sale/stock/COGS/GL/cash/print → controlled handback → Cloud POS
resumes. (`PHYSICAL_WINDOWS_CERTIFIED=no`, `PHYSICAL_WINDOWS_UPDATE_CERTIFIED=no` today.)

### 20. Release blockers for automatic failover

| Blocker | Today |
|---|---|
| `BRANCH_AUTHORITY_LEASE` | **PENDING** (§6 — P0) |
| `NO_SPLIT_BRAIN` | **PENDING** (fence BUILT; partition protocol + executable two-process proof pending) |
| `WARM_STANDBY_FRESHNESS` | **PENDING** (§17) |
| `WAN_UNPLUG_LOCAL_SALE` | software-proven (master + Cloud endpoint unreachable → sale completes); **physical unplug PENDING** |
| `LAN_PRINTING_WITH_WAN_DOWN` | software-proven (Edge print authority, fake printer over TCP); **physical PENDING** |
| `RECONNECT_EXACTLY_ONCE` | software-proven (sync 1B–1E, lost-ACK cross-DB recovery); **physical PENDING** |
| `CONTROLLED_HANDBACK` | **PARTIAL** (reservations + baseline + closing; active-state handback + orchestrator pending) |

Automatic failover is **not** production-ready until every row is PASS. Until then Local Mode stays a supervised,
fail-closed manual switch.

---

## Build order implied by this lock (after the remaining cashier items)

1. **P — Branch authority lease** (§5–6, P0): Cloud lease with heartbeat/expiry per branch; Cloud fence engages on
   lease loss; Edge takeover only when safe; two-process partition proof; no last-write-wins.
2. **Q — Connection/health state machine** (§3, §9, §18): ONLINE → … → LOCAL_ACTIVE and back, bounded checks,
   business-friendly states on the cashier page; readiness gate (§4) fail-closed; supervisor confirmation for pilot.
3. **Warm-standby freshness** (§1, §17): scheduled standby refresh + continuous safely-synchronised operational
   baseline/state with bounded staleness; reservations included.
4. **Controlled handback orchestrator** (§11): sync-clean gate → reservation + active-state handback (drain or explicit)
   → Cloud baseline verify → authority transfer → Cloud writes.
5. **R — Print** (§15): one dual-authority Print Agent for USB/workstation printers; "ingestion queues zero prints" guard.
6. **Installer → physical pilot certification** (§19) → release-blocker matrix all PASS.

Security/authority invariants carried from the foundation stay in force: fail closed, never invent business truth,
never fake card/email, restricted artifact physically clean, production untouched until the owner's go.
