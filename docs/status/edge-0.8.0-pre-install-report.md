# Bingoo Edge 0.8.0-edge — PRE-INSTALL REPORT (build only; NOT installed on the LAB), 22 Oct 2026

Owner directive "FINAL BUILD UNBLOCK" §6: build/sign 0.8.0-edge, do not install on LAB, then `edge:audit-package`, clean-install dry-run,
0.7.0 → 0.8.0 upgrade dry-run, rollback dry-run, COPY-of-LAB-appliance-DB migration dry-run, pre-install report with the exact PLAN_HASH.
Installed LAB: **0.7.0-edge** from `7b8f886` (unchanged; LAB appliance, LAB Cloud, LAB data never touched; `pos_lab_tenant_edge.sales_orders` = 5).

## 0. Decision summary

```
RELEASE_VERSION=0.8.0-edge
RELEASE_COMMIT=ac5b168d25d5ca6277e0bc583d476c1d3f2bd175 (feat/edge-config-refresh-v1; code = canonical 025728d8 integrated + post-freeze exception categories.updated_by + H1 + O1 + D1–D7)
PACKAGE=C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.0-edge (RELEASE mode, source_dirty=false, built 2026-10-22T17:27:21Z)
PACKAGE_HASH=91b96c5b7977a8201fe95364c4f1565ec6e2e70253696ffd8082c314327d18f8
ARTIFACT_MANIFEST_HASH=6e7895addd8cc5a37c0b139ea53baf2683282065c664a5f46c43789c9215488f
FILES=8,998 (app + php-8.3.16 runtime + nginx-1.22.0 gateway + scripts + templates + signed update manifest)
SIGNED=yes — update/edge-update-0.8.0-edge.json, custody keystore v1, signing_key_id a2eaf8a3d3e66a48 (public key = the appliances' EDGE_UPDATE_PUBLIC_KEY); keystore, passphrase and private key never left C:\Users\Dell\.bingoo-edge-release
PACKAGE_AUDIT=PACKAGE OK — boundary_audit.ok=true, forbidden_hits [], cloud_only_present [], edge_runtime_missing [], artifact_marker_branch_server=true, tenant_migrations_audited 193 (= 217 manifest entries − 24 CLOUD_ONLY, which are physically absent), tenant_migration_problems []
SOURCE_PROOF=PASS — package app/ vs `git archive ac5b168d`: 2,273 non-vendor files, 0 mismatches, 6 only-in-package (.gitkeep placeholders of empty storage dirs); vendor = separate `composer install --no-dev` closure from the identical composer.lock
PLAN_HASH=sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d   (installer dry-run `edge:local:update … --dry-run` of THIS package against a COPY of the LAB appliance DB — the hash the LAB update must be given as -ExpectedPlanHash)
PLAN=24 RUN (21 tenant + 3 edge), 0 skip (no CLOUD_ONLY file is in the package), 0 blockers, verdict OK, manifest edge-tenant-migration-manifest@2026-10-22.1
UPGRADE_DRY_RUN=PASS on the LAB copy (apply + measurements §4)
ROLLBACK_DRY_RUN=PASS — pointer rollback to 0.7.0 viable BEFORE the first v8 refresh; after it, restore the pre-update backup (§4.4)
CLEAN_INSTALL_DRY_RUN=PASS — EdgeCleanMachineInstallMySqlTest in RELEASE mode (EDGE_PROOF_VENDOR_FROM = the 0.8.0 no-dev closure): 1 test / 165 assertions green (19 min; evidence\phase5\release-0.8.0-edge\clean-install-proof.log)
W09_AFTER_H1=PASS on Online (clone 9704), Edge 8095 and the paired 8096 writer run; cash-without-shift refused with the shared text on both runtimes (form 302 + alert on Online and on the Edge form; JSON 422 errors.shift on the Edge API); return envelope v2 ingested, replayed already_applied; 8096 D7 plan for the 2 newest migrations sha256:1a7d51454b7590c9b0fd9477acb912d3f7455937b70c71fbfe3b9346cd98cde6 (Team FB3, docs/status/edge-ac5b168d-w09-and-plan.md)
READY_FOR_PREINSTALL_REPORT=YES (this report) — installation needs the owner's explicit approval per the 14-step LAB path
```

## 1. What this release carries (since 0.7.0 / 7b8f886)

Updater fix (e45b8b7: schema upgrade in the new runtime's process) · the ONE shared Online/Edge cashier view through the PosRuntime adapter (Phase 2 + Phase 3
cutover; the old Edge POS page deleted) · manager-approval security contract (may_approve_pos, action permissions, self-approval refused) · bootstrap v8 ·
migration manifest + enforced plan hash (D7; 217 entries: 125 EDGE_REQUIRED / 68 EDGE_SCHEMA_SAFE / 24 CLOUD_ONLY, 0 unclassified) · Phase 4 offline customer
create + customer uuid alias / reconciliation (envelope v3) · Gap C Recent Orders shared rule · Reminder Pause Edge twin (D2/D3) · compatibility classifier
update (§4-3) · device build/version reporting · canonical 025728d8 integrated (REFUND-SHIFT-1, RETURN-RESTOCK-1, RETURN-NO-BILL-1 Cloud-only by capability,
VOID-ON-ORIGINAL-DATE-1 inside the D1 shared service, medical counter capability-OFF, purchasing V2 Cloud-only, report engine) · post-freeze exception
categories.updated_by (schema parity) · H1 refund-shift rule (the paying shift owns the refund; return envelope v2 with the processing shift identity; shared
refusal for a cash refund without an open shift) · O1 already_current normalisation.

Contract changes the Cloud and the appliance halves carry together: bootstrap **v8** (a v7 appliance is `software_update_required` on a v8 Cloud — Cloud FIRST),
sale envelope v3 (`new_customer`, gated by the Cloud-advertised `customer_create`), return envelope **v2** (`processing_shift`; v1 still accepted), heartbeat
`envelope_versions` lists both.

## 2. Gates on the release commit (ac5b168d)

| Gate | Result |
|---|---|
| Full Edge MySQL gate (`test-mysql.sh --filter Edge`) | **726 tests / 10,000 assertions, 0 failures, 1 skipped** (55 min; log `evidence\phase5\edge-mysql-gate-ac5b168d.log`) |
| Feature/Edge | 190 / 41,723 green (artifact boundary, dependency closure, route manifest, endpoint leak, no-external-network, shared POS static gate, manifest validator, compatibility contract, updater, build report) |
| Feature/Pos | 31 / 490 green |
| Unit | 116 / 119 green; 3 = canonical pre-existing static reds (documented since 768ac71) |
| Canonical MySQL suites for returns / shifts / cash count / merged areas (`RefundShift|ReturnRestock|GrnVoid|ManualJournal|CashBookVoid|StarterPlanCounter|MedicalCounter|PharmacyDemo|PosScreenRenders|QuickReport|SalesReturn|PackQty|SalesReport|BillBarcode|CancelFreesTable|TableClose|CustomerSearch|PrintAgent|ReminderPause|Shift|CashCount|Denomination`) on isolated `_t25` | **284 / 2,289 green** |
| H1 owner filter (`RefundShift|ReturnRestock|SalesReturn|EdgeReturn|EdgeRefundShiftH1|EdgeCashierReturnParity|EdgeTenantMigration|EdgeLocalReturn|ReturnNoBill|Shift`) on `_t26` | 160 / 1,627 green — the eight owner cases all PASS (EdgeRefundShiftH1MySqlTest + RefundShiftMySqlTest) |
| Exception gate (`EdgeTenantMigrationUpgrade|EdgeConfigRefresh|EdgeBootstrapV7`) on `_t27` | 36 green; CATEGORY_UPDATED_BY_REFRESH=PASS (a Cloud category row carrying updated_by applies on the update and insert paths; the export contract never carries it) |
| Browser on 087278e9 (Team FB2) + W09 repeat after H1 on ac5b168d (Team FB3) | Online 17/17, Edge 17/17 (+ paired 8096 writer/standby), W15 ×5, W18 both, pixel reference recaptured (zero unintentional drift), geometry NONE; W09: see the field block |

## 3. Restricted-artifact audit (release package)

`edge:audit-package` → PACKAGE OK (`evidence\phase5\release-0.8.0-edge\audit-package.json`). Catering / manufacturing / purchasing / suppliers / SaaS
billing / ONLINE-ORDERING-1 (website, inbox, delivery areas, WhatsApp) / Cloud AP posting / the 24 CLOUD_ONLY migrations are physically absent; Cloud chrome views
may ship but no `edge.local.*` route renders them; fonts and CSS self-hosted; no `.env`, keys, certs, keystore, passphrase, dumps, tests or `.git` in the package.

## 4. Database migrations, dry-runs and rollback (COPY of the LAB appliance DB — the LAB DB itself was only READ)

Copy: `mysqldump --single-transaction bingoo_edge_lab_local` (588,362 bytes; 203 tables, 194 migrations = the 0.7.0 schema, meta `edge-bootstrap-v7`, standby) →
scratch `bingoo_edge_dryrun_lab_local`; dry-run instance = the release code with `.env.edgedryrun` (APP_ROLE=branch_server, EDGE_DB_DATABASE=the copy,
EDGE_UPDATE_PUBLIC_KEY = the custody public key). Logs: `evidence\phase5\release-0.8.0-edge\{update-dry-run-lab-copy.json, upgrade-apply-lab-copy.log, rollback-dry-run-lab-copy.log}`.

### 4.1 Installer dry-run of the signed package (what the LAB update will compute)

`edge:local:update update/edge-update-0.8.0-edge.json app --dry-run` → result `dry_run`, to_version 0.8.0-edge, plan `edge-migration-plan-v1`:
RUN 24 = tenant `2026_09_26_000001_add_kot_heading_stars_toggle` … `2026_10_22_000001_add_processing_shift_uuid_to_sales_returns` (21) + edge
`2026_10_04_000001_add_may_approve_pos_to_users_on_edge`, `2026_10_06_000001_edge_phase4_offline_customer_origin`, `2026_10_19_000001_add_migration_plan_to_edge_local_updates`;
skip 0; blockers 0; **PLAN_HASH=sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d**. (A `schema-upgrade --dry-run` from a source tree that still
holds the 24 CLOUD_ONLY files lists them as SKIP_CLOUD_ONLY and therefore hashes differently — `sha256:e36c1c42…`; the package hash above is the one the appliance computes.)
After the apply (4.2) the same dry-run returns an EMPTY plan (run 0, skip 0, blockers 0, verdict OK).

### 4.2 Upgrade dry-run = apply on the copy (release code), with the owner's measurements

| Measurement (owner D7-1 / D7-4) | Value on the LAB copy |
|---|---|
| customer rows examined | 2 (both with a phone) |
| customer rows backfilled (`phone_normalized`) | 2 |
| address rows affected | 3 examined; `address_uuid` stays NULL for all 3 pre-existing rows (appliance rule F1 — the Cloud mints, the applier adopts) |
| `customer_uuid_aliases` creation | created, 0 rows |
| identity migration wall-clock | 276.52 ms |
| `sales_orders` row count | 6 |
| `add_website_order_fields_to_sales_orders` ALTER (ENUM widen + 2 unique indexes) wall-clock | 815.99 ms; appliance STANDBY; `-AllowLocalActive` not used |
| `return_without_bill_schema` (D7-6, non-additive) wall-clock | 272.68 ms; `sales_returns.sales_order_id` → nullable, 0 rows touched |
| slowest migration | `add_website_fields_to_catalog` 4 s; whole apply ≈ 12 s |
| after | migrations 194 → 218, tables 203 → 209, `edge_schema_version = edge-local-schema@2026_10_19_000001_add_migration_plan_to_edge_local_updates`, meta `last_error` NULL, protected-table counts unchanged (sales_orders 6, customers 2, addresses 3) |

### 4.3 Clean install

`EdgeCleanMachineInstallMySqlTest` (fresh machine installs from a package built by `edge:build-package`, binds to the Cloud, serves the cashier, backs up,
updates through a signed package and uninstalls safely; the include-probe proves the runtime executes ONLY package files) run in RELEASE mode with
`EDGE_PROOF_VENDOR_FROM=C:\Users\Dell\.bingoo-edge-release\releases\src-ac5b168d\vendor` (the same no-dev closure the 0.8.0 package carries) on the isolated
`_t27` databases: **1 test / 165 assertions green** (19 min). A clean install therefore reaches the release schema from scratch; the upgraded LAB copy (§4.2)
ends with the same column shape on every table the manifest entries name (asserted inside `EdgeTenantMigrationUpgradeMySqlTest::proveUpgrade` for all
post-baseline migrations, D7-6 included).

### 4.4 Rollback dry-run (installed 0.7.0 code = `cloud\src-7b8f886` export, against the UPGRADED copy)

| Step | Result |
|---|---|
| 0.7.0 code, meta still v7 (rollback BEFORE the first v8 refresh) | `schema-upgrade --dry-run`: tenant up to date, edge up to date; health gates `SCHEMA_COMPATIBLE true`, `CONFIG_COMPATIBLE true`, `STOCK_AUTHORITY_READY true` (status DEGRADED only for the dry-run environment: no device identity / workers / recovery key) → **a runtime-pointer rollback to 0.7.0 is viable while no v8 refresh has been applied** |
| simulate the first v8 refresh (meta `bootstrap_schema = edge-bootstrap-v8`), 0.7.0 code again | `SCHEMA_COMPATIBLE false` → 0.7.0 would not return to STANDBY_READY. **After the first v8 refresh, rollback = `Restore-EdgeAppliance.ps1` from the verified pre-update backup**, with the LAB Cloud back on the 7b8f886 export |
| installer rollback semantics | unchanged (EdgeUpdateInstaller: staged versioned runtime, pointer switch, previous version kept; `UPDATE_MIGRATION_PLAN_MISMATCH` stops before the switch when the plan differs from `-ExpectedPlanHash`) — EdgeUpdater / EdgeUpdatePlanHash suites green in the full gate |

## 5. LAB-only data corrections required (owner-approved items, NOT applied)

From `edge-next-lab-update-checklist.md` step 2: approver `LABMF2DE` manager PIN + the action permissions; cashier `LAB2C5D` the missing shift / split-bill /
quick-store permissions; §4-7 `tenant.pos.pause-reminders` + one synthetic Reminder printer mapping (print_role=reminder, LAB terminal 1 → LAB FakePrinter). Verify
with `permissions:audit-cashier-roles` before and after.

## 6. Exact update procedure (the owner's 14 steps) and the approvals requested

1 build ✔ · 2 audit ✔ · 3 LAB DB copy ✔ · 4 dry-run ✔ · 5 this report (PLAN_HASH above) · **6 owner review and explicit approval** · 7 LAB Cloud FIRST from the
`ac5b168d` export (`Start-LabCloud.ps1 -Stop`, then `-Worktree <export>`) · 8 LAB-only data corrections (§5) · 9 confirm the Cloud advertises bootstrap v8 /
`customer_create` · 10 `Update-EdgeAppliance.ps1 -PackageRoot …\BingooEdge-0.8.0-edge -ExpectedPlanHash sha256:0ad1e612caa5659b15593239a803d349a792795cc50cbe8b3d2a0b1c41b7bb6d`
(the installer recomputes the plan from the staged runtime and STOPS on any difference; the appliance must be STANDBY) · 11 second-laptop paired acceptance ·
12 controlled Local Mode exercise only when separately approved · 13 sync/reconcile · 14 controlled handback. Rollback rule: §4.4.

Approvals requested (none executed): A) install 0.8.0-edge on the LAB per steps 7–10; B) the §5 data corrections; C) D7-6 confirmation that the actual change
(§4.2 row) matches the approval; D) acknowledgement of the H1 scope note (the Cloud keeps the processing shift identity; a Cloud shift-mirror of Edge shifts is a
later release) and of the categories correction (the column is schema parity, not a refresh blocker).

## 7. Open items carried into the next release

DEFER_TO_NEXT_RELEASE (canonical after the freeze): UNIFIED-BUSINESS W1/W2 (returns posted once by submission key, standard units, StockUnitGuard, readiness in
Master), TB-SECTION-HEADS-1, KHATRI stock-to-P&L, CATEGORY-PARENT-GUARD-1 logic/screen · EXPLICIT_REFRESH_SECTION_SCHEMA_CONTRACT design item · Edge shift
mirror on the Cloud (H1 full) · medical counter / purchasing-V2 Edge twins · the official sale number at the till (§4-6).
