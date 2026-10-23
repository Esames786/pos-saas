# Bingoo Edge 0.8.0-edge — PRE-INSTALL HOLD: evidence reconciliation (owner, 23 Oct 2026)

Read-only on the real LAB throughout (SELECT / mysqldump only). Nothing installed, no Cloud update, no permission change, no PIN, no mapping, no Local Mode,
no takeover, no rebuild. Companion to `edge-0.8.0-pre-install-report.md`.

## 1. Owner decisions recorded

- **Category correction ACCEPTED**: `categories.updated_by` = EDGE_SCHEMA_SAFE (the explicit refresh export whitelist never sends it); migration shipped and run
  for schema parity; schema-equivalence coverage kept; NO generic DB-column filtering in 0.8.0. Future WAN-pilot hardening item
  **EXPLICIT_REFRESH_SECTION_SCHEMA_CONTRACT** (generation advertisement + required-field fail-closed semantics).
- **H1 scope ACCEPTED for 0.8.0**: a cash refund belongs to the OPEN shift that pays it; Edge persists/sends `processing_shift_uuid`, the processing terminal and
  the processing business_date; the sale's shift is never the refund cash shift merely because it exists; Cloud `shift_id` may stay NULL without a Cloud row for
  that Edge shift uuid. Future roadmap item **EDGE_SHIFT_MIRROR_TO_CLOUD** (a canonical Cloud row for every Edge writer shift so `processing_shift_uuid` resolves
  to `shift_id` after sync) — not in 0.8.0.

## 2. Hard evidence reconciliation — "6 rows" vs "stayed 5"

**No contradiction: two different databases.** The safety statement counts the LAB CLOUD tenant database; the dry-run copies the LAB APPLIANCE's own local database.

```
REAL_LAB_SALES_COUNT=5 in pos_lab_tenant_edge.sales_orders (the LAB Cloud tenant DB — the number the safety statements track)
                     6 in bingoo_edge_lab_local.sales_orders (the LAB APPLIANCE local DB — what the dry-run copies)
SCRATCH_DB_NAME=bingoo_edge_dryrun_lab_local (first copy, 22 Oct) and bingoo_edge_dryrun_lab_fresh (fresh copy, 23 Oct)
SCRATCH_SALES_COUNT=6 (both copies)
SCRATCH_CREATED_FROM=mysqldump --single-transaction of bingoo_edge_lab_local (read-only; the dump header names the source DB)
SCRATCH_DUMP_TIMESTAMP=first copy 2026-10-22 22:28:57 local (588,362 bytes); fresh copy 2026-10-23T11:10:00Z–11:10:08Z (588,362 bytes, byte-identical size)
SCRATCH_PRE_MIGRATION_COUNT=6
SCRATCH_POST_MIGRATION_COUNT=6
```

The six appliance-local sales (stable identity = `sales_orders.sale_uuid`, all `order_source pos`, terminal 1, local shift 1, activation epoch 1, created on the
26 Sep 2026 supervised Local-Mode acceptance):

| id | sale_uuid | sale_no (local) | status | created_at | origin → Cloud |
|---|---|---|---|---|---|
| 1 | 01M3FBQZY6Z87HWRTMPFP086AR | SO-1-1-01M3FBQZY6Z87HWRTMPFP086AR | paid | 2026-09-26 17:20:59 | outbox 1 acknowledged → Cloud ingestion 1 → official **SO-20260926194834-901** (Cloud id 1) |
| 2 | 01M3FDMF4CZHAFPH50BXMS0A9J | SO-1-1-01M3FDMF4CZHAFPH50BXMS0A9J | paid | 2026-09-26 17:53:59 | outbox 2 → ingestion 2 → **SO-20260926194835-709** (Cloud id 2) |
| 3 | 01M3FEEYZ25PXXHHT73G02M336 | SO-1-1-01M3FEEYZ25PXXHHT73G02M336 | paid | 2026-09-26 18:08:27 | outbox 5 → ingestion 5 → **SO-20260926194836-600** (Cloud id 5) |
| 4 | 01M3FEF9RYE4AGYJYVKQQ0C4X8 | SO-1-1-01M3FEF9RYE4AGYJYVKQQ0C4X8 | **cancelled** | 2026-09-26 18:08:38 | never left the till: a cancelled local sale is not an outbox event and has no Cloud row (by design) |
| 5 | 01M3FFWEDXCEZXZS3NRRY9FPP7 | SO-1-1-01M3FFWEDXCEZXZS3NRRY9FPP7 | paid | 2026-09-26 18:33:18 | outbox 4 → ingestion 4 → **SO-20260926194836-179** (Cloud id 4) |
| 6 | 01M3FHPCSHANN8D6FHQX02JCYX | SO-1-1-01M3FHPCSHANN8D6FHQX02JCYX | paid | 2026-09-26 19:04:57 | outbox 3 → ingestion 3 → **SO-20260926194836-925** (Cloud id 3) |

Real LAB Cloud list (read-only): ids 1–5, exactly the five uuids above, all `applied` in `edge_inbound_sale_ingestions`, all five appliance outbox rows `acknowledged`
on 2026-09-26 19:48 with their Cloud ingestion uuids. So 5 (Cloud) = 6 (appliance) − 1 cancelled local sale. The appliance's `sales_orders.edge_sync_state`
column still reads `pending` on the 0.7.0 schema while the outbox rows are acknowledged — the 0.7.0 column is informational (the outbox is the authority); noted,
not a defect, unchanged by the migrations.

## 3. Fresh LAB-copy reproduction (23 Oct)

```
FRESH_COPY_BEFORE_SALES=6   (== REAL_LAB_APPLIANCE_SALES 6 — asserted before anything was applied; REAL_LAB_CLOUD_TENANT_SALES stays 5)
FRESH_COPY_AFTER_SALES=6
SALES_ROWSET_IDENTICAL=YES  (MD5 over every sales_orders row [id|sale_uuid|sale_no|status|grand_total|created_at|updated_at] 01818f1a82a641458a50db8c8a122385 before and after; sales_order_lines rowset dac5b484e8e076a4f443be64641a487f before and after)
PLAN_HASH=sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d  (installer dry-run of the signed package against the fresh copy — identical to the 22 Oct copy)
RUN_COUNT=24   SKIP_COUNT=0   BLOCKERS=0   VERDICT=OK
IDENTITY_BACKFILL_ROWS=2 of 2 customers (phone_normalized); 3 address rows untouched (address_uuid stays NULL on the appliance)
WEBSITE_ORDER_ALTER_MS=672.49 (2026_10_15_000002, sales_orders 6 rows, STANDBY copy)
RETURN_SCHEMA_ALTER_MS=210.29 (2026_10_20_000007, D7-6: nullability only, 0 rows touched)
migrations 194 → 218; the real LAB appliance DB afterwards: still 6 sales, still 194 migrations (untouched)
```

## 4. Signed package identity (reconfirmed from the package manifests, no rebuild)

```
VERSION=0.8.0-edge
SIGNED_SOURCE_COMMIT=ac5b168d25d5ca6277e0bc583d476c1d3f2bd175
PACKAGE_HASH=91b96c5b7977a8201fe95364c4f1565ec6e2e70253696ffd8082c314327d18f8
ARTIFACT_MANIFEST_HASH=6e7895addd8cc5a37c0b139ea53baf2683282065c664a5f46c43789c9215488f (package manifest == signed update payload)
SIGNING_KEY_ID=a2eaf8a3d3e66a48 (custody keystore v1; signature present in update/edge-update-0.8.0-edge.json)
SOURCE_DIRTY=false   BUILD_MODE=release   FILES=8998   BUILT_AT=2026-10-22T17:27:21Z
PLAN_HASH=sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d
```

Ancestry of `ac5b168d` (`git merge-base --is-ancestor`, all YES): Phase 2 shared POS `0726272`…`885fae8` · Phase 3 cutover `80d04a6` (Stage A) + `180af6d` (Stage B) ·
Phase 4 customer work `7999a50` + `4b0de4f` (+ the identity migration in the package) · D7 manifest `63748f8` · D2/D3/D4/D6 `768ac71` · canonical merges
`4366b47c` (ccc67fa1) + `087278e9` (025728d8) · O1 `b3648abd` · H1 `c3093168` · category parity + D7-6 marking `ac5b168d` (= HEAD of the build) · updater fix
`e45b8b7`. The package `app/database/migrations/tenant/` carries `2026_10_20_000007_return_without_bill_schema` (D7-6), `2026_10_21_000020_add_updated_by_to_categories`
and `2026_10_22_000001_add_processing_shift_uuid_to_sales_returns`; its manifest holds the 26 OWNER_REVIEW_REQUIRED entries (21 baseline + 4 post-baseline + D7-6).
Source proof: 2,273 files, 0 mismatches. No packaging problem found → no rebuild.

## 5. LAB data corrections — DRY-RUN PLAN ONLY (nothing executed)

LAB tenant `pos_lab_tenant_edge` today (read-only): users 1 `LAB2C5D` Lab Cashier, 2 `LABMF2DE` Lab Approver; NO roles (direct grants only); `manager_pins` table
exists with **0 rows**; permission rows present: `tenant.pos.store`(1), `tenant.held-sales.cancel`(7), `tenant.pos.void-kot-item`(14), `tenant.pos.customers.quick-store`(15),
`tenant.sales-returns.store`(28); **absent rows**: `tenant.shifts.create`, `tenant.shifts.close-form`, `tenant.sales-orders.split-bill`, `tenant.pos.pause-reminders`.
Printer 1 "LAB FakePrinter (TCP 9100)" (network, print_role both, supports_reminder=1, active); mappings 1–3 = KOT for categories 2/5/7, no reminder mapping; terminal 1 `LAB-T1`.

| Requirement | User | BEFORE (today) | AFTER (planned) | Planned change |
|---|---|---|---|---|
| manager PIN | 2 LABMF2DE | none (0 rows) | one active PIN | set through the Online UI `/users/2/manager-pin` on the 0.8.0 LAB Cloud (the PIN value chosen by the owner, never written here) |
| tenant.pos.store | 2 | not held | held (direct) | `givePermissionTo` (product path), tenant guard |
| tenant.held-sales.cancel | 2 | not held | held (direct) | same |
| tenant.sales-returns.store | 2 | not held | held (direct) | same |
| tenant.pos.void-kot-item | 2 | **held** (direct) | held | no change |
| tenant.shifts.create | 1 LAB2C5D | row absent | row created + held (direct) | create the permission row (tenant guard), then grant |
| tenant.shifts.close-form | 1 | row absent | row created + held | same |
| tenant.sales-orders.split-bill | 1 | row absent | row created + held | same |
| tenant.pos.customers.quick-store | 1 | **held** (direct) | held | no change |
| tenant.pos.pause-reminders | 1 | row absent | row created + held (D3: explicit LAB grant) | same |
| Reminder mapping (W18) | — | none | exactly ONE `category_printer_mappings` row: branch 1, terminal 1, category NULL (all), printer 1 (supports_reminder=1), print_role `reminder`, order_type `all`, active | through the product model / Online UI, LAB only |
| anything else | — | — | unchanged | no broad grants, no "all permissions", user 1 keeps its 5 existing direct grants, user 2 keeps void-kot-item |

Verification before/after: `permissions:audit-cashier-roles --tenant=<lab tenant>` (read-only) + the SELECTs of this document. Order: after step 7 (LAB Cloud on
0.8.0 — the `manager_pins` route and `tenant.pos.pause-reminders` exist on that code), before step 10 (appliance update), then one config refresh carries the
users/permissions and the mapping to the appliance (`users updated 2`, `category_printer_mappings inserted 1`).

## 6. Cloud-first compatibility (release code `ac5b168d` + LAB Cloud DBs read-only)

| Needed BEFORE the appliance upgrade | On the 0.8.0 LAB Cloud | Evidence |
|---|---|---|
| bootstrap v8 | yes | `EdgeBootstrapService::SCHEMA_VERSION = edge-bootstrap-v8`, config schema `edge-config-v1` (unchanged) |
| `customer_create` capability advertisement | yes | `EdgeCloudCapabilities::CUSTOMER_CREATE` advertised in the heartbeat ACK (Phase 4) |
| envelope v3 ingestion | yes | `EdgeInboundSaleIngestionService` ENVELOPE_SCHEMA_V1/V3; return ingestion accepts v2 + v1 |
| customer UUID alias support | yes | `customer_uuid_aliases` (tenant migration 2026_10_06_000001_edge_phase4_offline_customer_identity — pending on the LAB tenant today) |
| manager approval eligibility | yes | bootstrap v8 users carry `may_approve_pos`; Cloud verifyPin refuses self-approval and requires the action permission |
| Reminder Pause Cloud contract | yes | `POST /api/pos/reminder-pause` (routes/tenant.php:654), PosRuntime `reminderPause`; `2026_10_05_000002_add_reminder_pause_to_shifts` pending on the LAB tenant |
| migration / config revisions the appliance needs | yes, by migrating the LAB Cloud DBs | LAB Cloud tenant `pos_lab_tenant_edge`: 172 applied, **45 pending** on ac5b168d (0 applied-but-unknown); LAB master `pos_lab_master_edge`: 27 applied, **23 pending** (0 unknown) — run as part of step 7 (`migrate` on both, Cloud side only); the first v8 bootstrap/refresh then serves the appliance after step 10 |

```
CLOUD_FIRST_COMPATIBILITY=PASS (provided step 7 migrates the LAB Cloud master + tenant DBs on ac5b168d before step 10)
```

## 7. Install readiness

```
REAL_LAB_SALES_COUNT=5 (LAB Cloud tenant) / 6 (LAB appliance local DB — the copied one)
FRESH_COPY_BEFORE_SALES=6
FRESH_COPY_AFTER_SALES=6
SALES_ROWSET_IDENTICAL=YES
COUNT_MISMATCH_EXPLANATION=different databases: the safety figure is the LAB Cloud tenant's 5 official sales; the dry-run copies the appliance's local DB, which holds the 6 Local-Mode sales of the 26 Sep acceptance — 5 ingested as those Cloud sales (uuids match, outbox acknowledged) + 1 cancelled local sale that never produces a Cloud row
SIGNED_RELEASE_VERSION=0.8.0-edge
SIGNED_SOURCE_COMMIT=ac5b168d25d5ca6277e0bc583d476c1d3f2bd175
PACKAGE_HASH=91b96c5b7977a8201fe95364c4f1565ec6e2e70253696ffd8082c314327d18f8
PLAN_HASH=sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d
PACKAGE_AUDIT=PACKAGE OK (boundary ok, 193 tenant migrations audited, 0 problems; source proof 0 mismatches)
CLOUD_FIRST_COMPATIBILITY=PASS
LAB_DATA_CORRECTIONS_READY=YES (plan + before/after matrix above; nothing executed)
H1_STABLE_IDENTITY_CONFIRMED=YES (processing_shift_uuid + terminal + business_date persisted on both runtimes, carried in envelope v2, kept by ingestion and replay, shift-close totals on the processing shift; cash without an open shift refused — FB3 + EdgeRefundShiftH1MySqlTest)
EDGE_SHIFT_MIRROR_FUTURE_ITEM=RECORDED (EDGE_SHIFT_MIRROR_TO_CLOUD — not in 0.8.0)
READY_FOR_LAB_INSTALL=YES — pending the owner's explicit approval (14-step path, step 6)
OPEN_BLOCKERS=none technical; owner approvals: install (steps 7–10), the §5 data changes incl. the PIN value, the first v8 refresh (after which rollback = backup restore)
```
