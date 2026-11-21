# OFFLINE EDGE — P: BRANCH AUTHORITY LEASE (P0 single writer)

Status: **implemented and proven in executable tests; NOT a certified automatic failover.**
Operating model: `docs/design/EDGE_OPERATING_MODEL.md` (locked 849ba80). This document records what P delivers,
what the tests prove, and what stays open for Q (connection state machine) and the physical pilot.

Local Mode activation is **not** certified by this work. Lease mode is off unless `edge.authority.heartbeat_url`
is configured on an appliance; without it the existing manual Local Mode switch governs, unchanged.

## What P adds

Cloud (tenant database, Cloud clock)

- `edge_branch_authority_leases` — one row per branch: `holder` (cloud|edge), `edge_state`
  (standby|local_active|handing_back), monotonic `heartbeat_seq`, `last_heartbeat_at`, `lease_ttl_seconds`,
  `expires_at`, `fenced_at`, `released_at` + `release_reason`.
- `POST /api/edge/authority/heartbeat` and `POST /api/edge/authority/handback`, device-authenticated
  (`edge.device.auth`). The branch comes from the authenticated device, never from the body.
- `EdgeAuthorityLeaseService`: every accepted heartbeat extends the lease by the TTL; a stale or replayed
  sequence is refused (`STALE_HEARTBEAT`); `edge_state = local_active` moves the holder to EDGE; a resumed
  heartbeat while the holder is EDGE never bounces authority; handback is accepted only with `outbox_pending = 0`
  and `failed_permanent = 0` (`HANDBACK_NOT_CLEAN`) and only from the holder (`HANDBACK_NOT_HOLDER`).
- The fence. `BranchOperatingModeService::cloudSaleMutationBlocked` (POS sale mutations) and
  `assertOfficialStockMutationAllowed` (official stock) now also refuse when the lease says so: holder EDGE, or a
  heartbeated lease that has expired and was not released. Per branch, never per tenant.
- `edge:authority:release {tenant} {branch} --reason --by` — Cloud-only, audited operator release for a dead
  appliance. Not allow-listed on a Branch Server.
- TTL floor: 15 seconds (config `edge.authority.ttl_seconds`, default 120).

Appliance (edge_local_meta, appliance clock)

- Columns `authority_state`, `authority_last_ack_at`, `authority_lease_ttl_seconds`, `authority_heartbeat_seq`,
  `authority_last_failure_at`, `authority_takeover_at`, `authority_state_reason`.
- `EdgeAuthorityService`: heartbeat over the real transport (`EdgeAuthorityLeaseClient`); a failed request is
  recorded, never acted on. The Cloud lease counts as lapsed locally only when
  `now ≥ last_ack + TTL + skew_margin` on the appliance's own clock (default margin 30 s).
- Eight readiness gates: `LOCAL_DB_HEALTHY`, `CONFIG_COMPATIBLE`, `SCHEMA_COMPATIBLE`, `BRANCH_BINDING_VALID`,
  `LOCAL_USERS_READY`, `STOCK_AUTHORITY_READY`, `ENTITLEMENT_VALID`, `AUTHORITY_TAKEOVER_SAFE`.
- Takeover requires every gate AND a supervisor confirmation (`edge.authority.require_confirmation`, default
  true). Commands: `edge:local:authority-heartbeat`, `edge:local:authority-status`,
  `edge:local:authority-takeover --confirm --by`, `edge:local:authority-handback --by`.
- The local fence: `EdgeAuthorityService::assertLocalMutationAllowed` is called by the Edge POS authority
  (terminal, cancel, close session, sales) and by table reservations. Without lease mode it is a no-op.
- Handback flips the appliance to `handing_back` first (fenced on both sides), and to `standby` only on a Cloud
  200 with `holder = cloud`. A failed handback call stays `handing_back`.
- Cashier chip labels from `cashierState()`: ONLINE · INTERNET CONNECTION LOST · PREPARING LOCAL MODE ·
  LOCAL MODE ACTIVE · RETURNING TO ONLINE (exposed in the sync summary as `connection`).

## What the tests prove (MySQL, real HTTP, independent processes)

`tests/MySql/EdgeAuthorityLeaseHttpMySqlTest.php` — Cloud endpoints over real HTTP with device auth

- 401 without or with a wrong device secret.
- First heartbeat → holder cloud, Cloud writes allowed on both branches.
- Replayed sequence → 409 `STALE_HEARTBEAT`.
- `local_active` → holder edge; the Cloud POS for that branch is refused; the other branch continues.
- Resumed heartbeats (`local_active`, then `standby`) → holder stays edge.
- Handback with pending work → 422 `HANDBACK_NOT_CLEAN`; clean handback → holder cloud, fence lifted;
  a second handback → 422 `HANDBACK_NOT_HOLDER`.
- Heartbeats stop → at TTL + 1 s (Cloud clock) the Cloud fences itself and audits `fenced_at`; the other branch
  is untouched; a resumed standby heartbeat re-grants the Cloud.
- Dead appliance → lapse, then operator release → Cloud writes again.
- A heartbeat body naming another branch cannot create or move that branch's lease.

`tests/MySql/EdgeAuthorityPartitionTest.php` — two databases, two OS processes, wall clock (TTL 20 s, margin 8 s)

1. Cloud healthy: Cloud writes; the appliance refuses local mutation; a confirmed takeover with a live lease
   fails closed; seven of eight gates are green on the box, `AUTHORITY_TAKEOVER_SAFE` is not.
2. Partition begins: the appliance's real heartbeat to an unreachable Cloud records a failure and changes nothing;
   the Cloud still writes inside the lease; the appliance still refuses.
3. Lease expires on the Cloud clock: the Cloud fences the branch by itself, not before TTL − 1 s; the appliance,
   still inside its skew margin, refuses to write and refuses a confirmed takeover. Fenced on both sides, never an
   overlap.
4. Only after TTL + margin on the appliance clock: gates pass; takeover without confirmation refused; confirmed
   takeover → `local_active`; local mutation allowed; chip reads LOCAL MODE ACTIVE.
5. A mobile or other Internet client on the Cloud POS for the same branch is refused; a different branch of the
   same tenant continues.
6. Network flaps: resumed heartbeats (`local_active`, then `standby`) keep the holder edge; a replayed sequence is
   refused; the Cloud stays fenced; the appliance keeps writing.
7. Handback: the appliance's own handback over the dead wire fails and leaves it `handing_back` (no local writes,
   Cloud still fenced); Cloud handback with pending work refused; clean handback → Cloud writes; the acknowledged
   appliance returns to standby and refuses local mutation again; chip reads ONLINE.

Result: 3 tests, 97 assertions, green. The partition test found and fixed one defect on the way: the appliance
meta model lacked datetime casts for the authority timestamps, so the cashier connection label threw once a
failure and a later ack both existed.

Authoritative full MySQL suite at 615ff20 (P + reconcile 5 + the two findings below), run alone: 1432 tests,
1430 passed, 2 red = the known canonical Dompdf/A4-PDF debt (PosQuickReport email, ReportSchedule), byte-identical
to canonical. New Edge regressions: 0. Two findings the full suite surfaced were fixed in 615ff20: the new lease
table had to be classified (KEPT) in the tenant transaction reset, and DELIVERY-CHARGE-1's "Edge refuses a delivery
charge" pin was superseded by the Phase A delivery parity (a non-delivery offline sale ignores the field as Online
does). Fast Feature+Unit suite: the two stale canonical SQLite unit tests (EscPosReportPayloadTest two-decimal money,
DeliveryRiderReassignmentRegressionTest text-grep) remain red, as before, and are canonical debt.

## Release-blocker status after P

| Blocker | Status |
|---|---|
| BRANCH_AUTHORITY_LEASE | implemented; proven in tests |
| NO_SPLIT_BRAIN | proven in the two-process partition test; physical pilot pending |
| CLOUD_LEASE_EXPIRY_FENCE | proven (HTTP test + partition test) |
| EDGE_SAFE_TAKEOVER | proven: gates + TTL + skew margin + confirmation |
| MULTI_BRANCH_ISOLATION | proven (other branch continues in both tests) |
| NETWORK_FLAP_SAFETY | proven: no bounce, stale beats refused |
| CONTROLLED_HANDBACK | protocol proven (Cloud + appliance); operator flow and UI belong to Q |
| WARM_STANDBY_FRESHNESS | not in P (Q) |
| WAN_UNPLUG_LOCAL_SALE | not certified (physical pilot) |
| LAN_PRINTING_WITH_WAN_DOWN | Edge print authority exists; physical pilot pending |
| RECONNECT_EXACTLY_ONCE | sync engine idempotency proven earlier; reconnect under lease belongs to Q |

## Not done, on purpose

- No automatic Local Mode activation. The connection state machine (Q) and the reconnect/handing-back operator
  flow (R) are not started.
- The appliance heartbeat loop is a command; scheduling it on the appliance (every `edge.authority.interval_seconds`)
  is Q work together with the state machine.
- The cashier page shows the lease-mode connection label but has no takeover button; takeover is a supervised
  CLI action.
- Physical Windows certification: no. Production mutated: no.
