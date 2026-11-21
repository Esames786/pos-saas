# Offline Edge 0.7.0-edge — LAB update execution record (25–26 Sep 2026)

For: the owner. Companion to `edge-0.7.0-pre-install-report.md` (approval basis, commit f7f210a) and
`edge-0.7.0-lab-acceptance-checklist.md` (laptop-side steps). Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.7.0-edge\`.

## Outcome in one line

The LAB appliance runs **0.7.0-edge (7b8f886)**, bootstrap **v7** at Cloud config revision 3, **STANDBY_READY**, authority standby,
outbox 0/0/0/0, AUTO_FAILOVER_ENABLED=no — reached after one owner-approved STOP: the signed update itself did **not** apply the
two migrations (updater defect, now fixed in code and regression-tested; the fix is **not** in the LAB build, by owner decision).

## Sequence A–L as executed (local times PKT; DB timestamps in the evidence are UTC)

| Step | What happened | Evidence |
|---|---|---|
| A | FakePrinter started; LAB Cloud started from a clean `git archive 30105df` export (v6) via `Start-LabCloud.ps1 -Worktree` (parameter added, default unchanged, `.bak` kept); Edge workers started from the installed 0.6.0 plan → **STANDBY_READY**, 7/7 workers, gateway 0.0.0.0:8443 | `A1-health-first-reading.json` |
| B | Baseline: outbox 0/0/0/0; shifts/orders/sessions/reservations/print jobs/KOT = 0; pointer 0.6.0-edge; meta v6 rev 1; Cloud revision 1; heartbeat seq 5227 acked | `B-baseline.txt`, `B-sync-status-baseline.json` |
| C | Fresh backup #37 `01M3CTZHD2QTYZJPB9TVYM1E23` (41,179 B, completed, read-back + checksum verified by the service) | `C-pre-update-backup*.{json,txt}` |
| D | mysqldump of `pos_lab_master_edge`, `pos_lab_tenant_edge`, `bingoo_edge_lab_local` | `db-snapshots-pre-update/` |
| E | Edge workers + gateway stopped 22:50; v6 LAB Cloud stopped | shell output in this record |
| F1 | Lab Cashier (id 1) granted the documented Online cashier set → 13 permissions (`Grant-LabParityPermissions.php`) | `F1-permission-grant.txt` |
| F2 | Tenant migration `2026_09_17_…catering_settings` applied on `pos_lab_tenant_edge` only, from the 7b8f886 export (171→172); master: nothing. **Note:** the plan's `tenants:migrate` command does not exist in this codebase; `Migrate-LabTenant.php` runs the same `migrate --database=tenant --path=database/migrations/tenant` the LAB seed uses | `F2-lab-tenant-migration.txt` |
| G | LAB Cloud started from the `git archive 7b8f886` export on :9701 (Edge workers stopped) | shell output |
| H | Package identity re-checked = report (hashes, commit, version, signature); `Update-EdgeAppliance.ps1 -NoServices` → verified, installer backup #38, staged, pointer switched, **"applied"**, UPDATE COMPLETE | `H0-*`, `H-update-run.log`, `H1-post-update-verification.txt` |
| **STOP** | Owner rule "migration list differs": appliance DB unchanged (192 rows, no new columns), `edge_schema_version` recorded as the 0.6.0 build's newest migration. Root cause below. Rollback boundary not crossed; owner chose **Option 1** | `H2-STOP-schema-upgrade-dry-run.txt`, `H3-STOP-note.txt` |
| S1 | `edge:local:schema-upgrade --dry-run` via the launcher (now 0.7.0) listed **exactly** the two; applied: 192→194 rows, both columns present, protected counts unchanged, outbox 0, authority standby | `S1-*` |
| I | Workers started 03:36:32 from the re-rendered plan (7/7, gateway up) | `J2-*` |
| J | Recorded before the first refresh (workers stopped): runtime 0.7.0-edge / 7b8f886, SCHEMA_COMPATIBLE=false, outbox 0/0/0/0, standby, no takeover | `J1-*`, `S1-health-after-schema.json` |
| K | First v7 refresh performed **automatically by the freshness worker 17 s after start** (Cloud minted revision 2) | `L-proof-db.txt` |
| L | bootstrap_schema v7; SCHEMA_COMPATIBLE=true; STANDBY_READY; standby; heartbeat acks resumed (770 consecutive); outbox 0/0/0/0; AUTO_FAILOVER=no; tenant_business_name "Edge Home Lab (disposable)"; 13 cashier permissions imported; modifiers/currencies/denominations imported as empty = the LAB tenant's actual source (0/0/0); payment methods CASH only = source | `L-*`, `JKL-summary.txt` |
| M | LAB approver created (owner item 4): user id 2 "Lab Approver", employee code `LABMF2DE`, permission `tenant.pos.void-kot-item` only (the one permission every offline approval action demands); synced by revision 3; assertion issued (`lab-cloud.php assertion <file> 2`); enrolled with `edge:local:enroll --credential-file` — credential kept only in `secrets\approver.pass` | `M1-*`, `M2-*` |
| N | Same-machine read-only Playwright proof against the LAB gateway: 97/222 present rows seen; bill preview refused 422 "Local Mode is not active" (correct standby behaviour) | `N-browser-proof-readonly/`, `MN-summary.txt` |

Rollback boundary **crossed at 22:36:49 UTC (25 Sep)** with the first v7 refresh. From then on: `Restore-EdgeAppliance.ps1` from backup #37/#38 +
LAB Cloud on `30105df`; no pointer rollback.

## Updater defect (confirmed) and fix — mandatory before the next release, NOT in the LAB build

`EdgeUpdateInstaller::applySchemaUpgrade()` ran `EdgeLocalSchemaUpgrader` **in the process that performs the update**. That process is started
through the appliance launcher, which resolved the **old** runtime before the pointer switch, so `database_path()` pointed at the old version's
migration files: pending = none, yet the update recorded "applied". Invisible to `EdgeCleanMachineInstallMySqlTest` because its packages A and B
shared migration files.

Fix (this branch, after 7b8f886): the schema upgrade runs as a **child process of the new runtime's own artisan**
(`<versions>/<to>/artisan edge:local:schema-upgrade`) after the switch, fail-closed (non-zero exit → the existing reverted_runtime /
restore_required path); the installer now prepares the staged runtime (writable dirs; a dev package's vendor junction re-linked before the
schema step instead of after the update). Regression test: package **B ships a migration package A does not have** (fixture planted only for the
B build; `EdgeProofMigrationNeverShipsTest` fails the tree if it is ever left behind); after the A→B signed update the migration must be recorded,
its table must exist and `edge_schema_version` must name it. Results: `EdgeUpdaterMySqlTest` 10/35; clean-machine dev mode **OK 160 assertions**
(the first run correctly rolled back — `reverted_runtime` — when the staged runtime could not boot, which is what led to the staging fix);
release-mode run (real no-dev closure vendor, `EDGE_PROOF_VENDOR_FROM`) **OK 165 assertions** (22 min, 26 Sep 04:05–04:27) — the B-only migration is applied by the new runtime in release shape as well. The 0.7.0 updater path stays **not certified** (the LAB build predates the fix); the next release carries the fix.

## LAB tooling added/changed (LAB only, no product code)

`Start-LabCloud.ps1` (+`-Worktree`), `Invoke-LabCloudArtisan.ps1`, `Grant-LabParityPermissions.php`, `Migrate-LabTenant.php`, `Create-LabApprover.php`,
`lab-cloud.php` (`assertion` takes an optional user id). Originals kept as `.bak-2026-09-2x`.

## Open owner decisions (acceptance)

1. **Local Mode for the acceptance window.** In warm standby the appliance refuses every branch mutation (sale completion, bill preview, KOT,
   tables, reservations, returns, held-order completion). Real cashier interaction for those on the second laptop needs a supervised, LAB-only
   Local Mode takeover with handback afterwards — currently excluded by the directive. Without it, laptop evidence for those rows is
   dialog/layout-level only and the functional proof stays with the automated MySQL/HTTP suites and the Online LAB POS.
2. **LAB menu seed.** The LAB tenant has 3 plain products, cash only, no tables/floors/variants/modifiers/weighted item/denominations. Variants,
   modifiers, barcode/SKU, weighted quantity, table board, move/merge and denominations cannot be exercised without a disposable menu seed on the
   LAB tenant (config data only; synced by the normal refresh). Requesting approval.
3. **Cloud device record.** The Cloud still shows the LAB device as 0.6.0-edge / v6 because the appliance reports its version only at pairing —
   a follow-up item (periodic compatibility re-report), not a failure.

## Supervised Local Mode acceptance + controlled handback (26–27 Sep 2026) — DONE

Owner approvals: disposable LAB menu seed; supervised LAB-only Local Mode; stopping the LAB Cloud process to let the lease lapse.
Evidence: `SEED-*`, `T0`–`T5`, `PERM-*`, `H1`/`H2`, `P1`–`P3`, `HB0`–`HB7`, laptop screenshots.

| Step | Result |
|---|---|
| LAB menu seed | 13 products (1 per-kg), 4 variants, 5 barcodes, 2 modifier groups / 6 modifiers, 2 deals (branch-scoped — the export requires `combos.branch_id`), 2 floors / 10 tables, 3 waiters, 3 void reasons, PKR + 10 denominations, CASH + 2 display-only methods, KOT routing, 2 customers, 15 stock balances; synced by revisions 4–5, stock baseline accepted |
| Takeover | `Start-LabCloud.ps1 -Stop` 18:10:58 local; lease lapsed naturally (online → unstable → lost → preparing_local at 13:13:32 UTC); all 9 gates + zero work proven; `edge:local:authority-takeover --confirm --by="Mohsin, …"` → LOCAL MODE ACTIVATED 13:14:08 UTC, fresh=true, no mutation from entering Local Mode |
| Cashier acceptance (second laptop, real interaction) | login/TLS, POS layout, categories, variants, modifiers, weighted qty, barcode, cart edit, Hold/Draft/Recall, table open, Add Round, Request Bill, Bill Preview, move (G1→G4), merge test, sent-line void + manager approval (`LABMF2DE`), whole-order cancel + approval, Review & Pay, cash sales (5 paid: 250 / 1,000 / 1,450 / 180 / 80), Direct Pay KOT, normal/addition/cancellation KOT, KOT reminder (DUPLICATE KOT), receipt + reprint, Recent Prints, shift open/close, denied login (approver lacks `tenant.pos.index`), delivery mode with rider. **Not exercised:** sales return; shift close without denomination count |
| Permissions found missing (LAB cashier set was route-derived, not the Online cashier role) | `tenant.pos.void-kot-item` (shared `KotCancellationService` checks the requesting cashier before the approval), `tenant.restaurant.table-sessions.move/merge`, `tenant.sales-returns.*`, `tenant.pos.customers.quick-store` — granted by the owner on both LAB databases (42 rows); the LAB Cloud grant re-synced on reconnect (revision 6). Lesson: derive Edge cashier sets from the provisioned Online cashier role |
| Printing (FakePrinter only, PHYSICAL_PRINT_CERTIFIED=no) | 33 print jobs ↔ documents on the FakePrinter, one delivery each, no duplicate logical key or delivery; KOT #1 per category, ADDITION #2, CANCEL #3/#4 (approval), DUPLICATE KOT (reminder), receipts + reprint; printer 1 / terminal 1 routing |
| Handback | LAB Cloud restarted from the 7b8f886 export; outbox 5/5 acknowledged (sale #1 on attempt 1,951), Cloud ingestions 5 = 5 distinct sale UUIDs, all applied (2,960.00); state machine local_active → connection_restored → reconciling → handing_back → online/standby; `edge:local:authority-handback --by=…` accepted; after: STANDBY_READY, standby, LOCAL_ACTIVE=no, 22 consecutive acks, lease holder cloud, outbox 0 pending / 0 failed, all freshness ok, AUTO_FAILOVER=no |

Open findings carried into the next release: exact Online layout via one shared cashier view; offline add-customer; Edge cashier permission set = Online cashier role; Cloud device record reports version only at pairing.