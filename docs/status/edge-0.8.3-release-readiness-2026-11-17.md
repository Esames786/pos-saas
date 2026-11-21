# Edge 0.8.3-edge — release readiness (built, signed, rehearsed; NOT installed) — 17 Nov 2026

Owner decision "FINAL OFFLINE RELEASE PREPARATION" (17 Nov 2026): authorize the signed 0.8.3-edge build under P5B custody
from 37731e16; verify signature and integrity; audit the migration plan; rehearse upgrade and rollback on a disposable LAB
copy; verify Windows startup reliability and cashier health; keep the package ready; do NOT install on the real LAB.

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.3-edge\` (`build\`, `labcopy\`). Package:
`C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.3-edge`. Real LAB: 0.8.2-edge STANDBY_READY, not modified.

## 1. Build (P5B, same procedure as 0.8.0 / 0.8.1 / 0.8.2)

`git archive 37731e16` export (`releases\src-37731e16`); `edge:build-package` RELEASE from the clean committed worktree
(`git status --porcelain --untracked-files=no` empty), the 0.8.0 no-dev vendor closure (`composer.lock` unchanged since 0.8.0 —
`git diff a6113772..37731e16 -- composer.lock composer.json` empty), bundled php-8.3.16 + nginx-1.22.0, signed from the custody
keystore v1 (passphrase read from its file by the builder; never on argv, never printed) → `edge:audit-package` → byte-level
source proof → identity check with the product's own crypto.

```
SIGNED_RELEASE_VERSION=0.8.3-edge
SIGNED_SOURCE_COMMIT=37731e16013b77f32b05026df25ba0ef37474f14 (accepted; baseline tag edge-baseline-release-prep-20261116 = 5bb43b7c unchanged)
PACKAGE_HASH=684ecf127235a1cfd8601116589ed4a076a91cf191154c17b18ce72f5677a187   (artifact_manifest_hash e864e3a5a069d0c8d6517bcb7edd6010122db944e2c1ed188bd33ec870a51f75, file_count 9092, built 2026-11-17T14:39:26Z, release, source_dirty false)
SIGNATURE=valid against the release public key (key id a2eaf8a3d3e66a48 = the pinned custody key v1); a freshly generated foreign key refuses it;
          payload version 0.8.3-edge, source_revision 37731e16…, target branch_server, schema edge-config-v1; recomputed artifact hash from disk = the signed hash
PACKAGE_AUDIT=ok=true after signing (missing 0, unlisted 0, tampered 0, package_hash_ok) and ok=true AGAIN after the dry-run + apply on the copy
              (unlisted 0; package bootstrap\cache still only .gitkeep — cache isolation kept)
BOUNDARY_AUDIT=ok (forbidden 0, cloud_only_present 0, edge_runtime_missing 0, 206 tenant migrations audited, 0 migration problems — the
               fail-closed OWNER_APPROVAL gate passed inside the real build, no test grant)
SOURCE_PROOF=PASS — 2,360 non-vendor files byte-identical to the export, 0 mismatches, 7 only-in-package (6 .gitkeep + edge-build-manifest.json,
             as in 0.8.0–0.8.2), 1,596 export files excluded by the artifact boundary
```

## 2. Migration plan (audited through the copy's REAL 0.8.2 launcher, `labcopy\02-dry-run-083.json`)

From 0.8.2-edge to 0.8.3-edge, manifest `edge-tenant-migration-manifest@2026-11-16.2`, verdict **OK**,
`plan_hash sha256:f93da9399a068ee2c62b38a3240e94876042e8f4f39a2980ef67e5fd4eb2ba8c`:

| decision | migrations |
|---|---|
| RUN (12 tenant) | `2026_10_21_000001_add_submission_key_to_sales_returns`, `2026_10_21_000002_add_submission_key_to_purchase_returns`, `2026_10_21_000010_add_expensed_to_cost_movement_type`, `2026_10_23_000001_decimal_pack_sizes` (R-1, approved), `2026_10_23_000002_sale_line_entered_unit`, `2026_11_02_000001_add_order_note_toggle`, `2026_11_03_000001_add_entry_date_to_supplier_ledgers`, `2026_11_03_000002_create_goods_receipt_revisions`, `2026_11_03_000003_let_a_purchase_bill_be_voided` (approved, forward-only), `2026_11_08_000001_add_deal_split_report_columns`, `2026_11_11_000001_add_deal_heading_toggle`, `2026_11_14_000001_add_customer_font_size_to_receipt_layouts` |
| RUN (1 edge) | `2026_10_30_000001_add_process_identity_to_edge_worker_state` (rel/0.8.3 heartbeat identity) |
| RUN_EDGE_SUBSTITUTE (1) | `2026_11_02_000002_order_note_toggle_default_off` via `App\Services\Edge\MigrationSubstitutes\OrderNoteToggleDefaultOnly` (approved substitute) |
| SKIP / blockers | none / none (CLOUD_ONLY files are not in the package: `delivery_place_sightings`, `print_prepares`, and the older excluded set) |

## 3. Rehearsal 0.8.2 → 0.8.3 → 0.8.2 on the disposable copy (`C:\Users\Dell\BingooEdgeLab\labcopy-082`)

Copy = robocopy of the real LAB install root (673 MB; launcher, php, gateway, runtime versions 0.6.0 … 0.8.2, pointer 0.8.2-edge),
its own data root (the LAB `appliance.env` with every path moved to the copy, `EDGE_UPDATE_INSTALL_ROOT` = `<copy>\install\BingooEdge\runtime`
exactly like the LAB, dead Cloud/sync URLs on 127.0.0.1:9, an invalid device secret, ports 8290/8291/8292), DB
`bingoo_edge_dryrun_lab_082` = a fresh read-only `mysqldump --single-transaction` of the REAL post-0.8.2 appliance DB taken
17 Nov 14:32 UTC (218 migrations, 6 sales, 5 acknowledged outbox rows, 2 customers, 0 receipt layouts), plus one probe layout row
inserted on the copy only. The copy's health on 0.8.2 before the update: version 0.8.2-edge / a6113772, DB = the dryrun copy,
pointer 0.8.2-edge (`labcopy\01-*`). A first attempt with the update root one level too high was discarded (`labcopy\attempt1-wrong-update-root\`:
it staged under `<copy>\install\BingooEdge\versions` and the launcher kept serving 0.8.2) — the copy was reset from the same dump and the
rehearsal redone with the LAB's exact layout.

```
UPGRADE_082_TO_083=PASS — `labcopy artisan edge:local:update <pkg>\update\edge-update-0.8.3-edge.json <pkg>\app --expected-plan-hash=sha256:f93da939… --json`
  (14:50:27 → 14:56:23 UTC, 6 min): result applied, pre_update_backup edge-backup-20261117_145029-4cyagh.enc, schema_after edge-config-v1,
  13 migrations run + the substitute executed; pointer 0.8.2-edge → 0.8.3-edge (runtime\versions 0.6.0 / 0.7.0 / 0.8.0 / 0.8.1 / 0.8.2 / 0.8.3);
  migrations 218 → 232 (218 + 13 + the substitute's canonical name, batch 35); edge_local_updates: 0.8.2 → 0.8.3 applied, plan hash as predicted,
  the stored plan carries RUN_EDGE_SUBSTITUTE + OrderNoteToggleDefaultOnly; staged versions\0.8.3-edge recomputed manifest hash = the signed hash;
  health on 0.8.3: 37731e16 / release / manifest e864e3a5…, bootstrap v8, DB = the copy, update.last applied; schema-upgrade --dry-run on 0.8.3: run [], blockers [], nothing pending
SCHEMA_AFTER=product_pack_units.factor decimal(15,4); purchase_bills.status enum(draft,posted,paid,partial,voided); receipt_layout_settings.show_order_note DEFAULT 0;
  edge_local_print_worker_state has worker_pid / worker_process_token / worker_host; edge_schema_version = …2026_10_30_000001_add_process_identity_to_edge_worker_state
ORDER_NOTE_ON_THE_COPY=the probe layout row that existed before the upgrade reads 1 (it received the column's initial default from 2026_11_02_000001, the
  substitute did not rewrite it); a layout created after the upgrade reads 0 (new default). On a real 0.8.2 appliance the column is born in this same
  upgrade, so any pre-existing layout rows (the real LAB has none) read ON until the next Cloud config refresh re-upserts them — Cloud refresh is authoritative.
DATA (base-column rowsets, ordered by id, vs a second restore of the same dump): sales_orders, sales_order_lines, edge_sync_outbox, customers, customer_addresses,
  shifts, print_jobs, products, edge_local_meta (minus the schema-version fields) = IDENTICAL; receipt_layout_settings differs only by the two probe rows;
  authority_state standby, outbox 5 acknowledged rows unchanged
WEB TIER on the packaged 0.8.3 (`labcopy artisan edge:local:serve --worker=1`): backend 127.0.0.1:8290 HEALTHY (status file, restarts 0, docroot runtime\versions\0.8.3-edge\public),
  40/40 GET /edge/local/health = 200 reporting edge_app_version 0.8.3-edge (11.3 s), /edge/local/login 200 (cashier login markup), /edge/local/pos → 302 to login
  (unauthenticated, as designed); stopped by its recorded supervisor + backend pids → port free, no orphan
WINDOWS_STARTUP_RELIABILITY (0.8.3 code on the copy): `edge:local:wait-db` → "Edge-local database READY at 127.0.0.1:3306/bingoo_edge_dryrun_lab_082 (3 ms, 1 attempt(s))" exit 0;
  `edge:local:worker-status` → print_worker / authority_worker slots reported stale with "a start takes over" (the copy runs no workers — the slot-takeover path
  of F-G10 is what a reboot needs); the reboot / database-loss / Modern-Standby behaviours themselves are the merged Agent F proofs (not repeated — owner: no repeats)
ROLLBACK_083_TO_082=PASS — documented pointer revert: runtime\current ← 0.8.2-edge; health 0.8.2-edge / a6113772 on the forward schema (schema_present true, DB copy
  unchanged); schema-upgrade --dry-run on the 0.8.2 runtime: manifest @2026-10-22.1, run [], blockers [], nothing pending (the 0.8.2 runtime does not know the newer
  files and never touches them); 0.8.2 web tier on the forward schema: 20/20 health 200 serving 0.8.2-edge, login page 200; stopped by recorded pids.
  Schema rollback itself = restore the pre-update backup (edge-backup-…-4cyagh.enc, written before the first migration) through the recovery procedure proven in
  B.10 / the clean-machine proof — not rehearsed on this copy (its Cloud is dead by design; the recovery key provider is the Cloud recovery authority). purchase_bills
  ENUM and decimal_pack_sizes are forward-only by owner decision (backup-based recovery).
Copy left on 0.8.2-edge with 0.8.3-edge staged beside it (mirrors the LAB just before an install).
```

## 4. Observations (no code change; the release commit stays 37731e16)

- `edge:local:update` prints `migrations_run` from the plan's RUN list, so the substitute's canonical name does not appear in that CLI summary line
  (it is in the ledger, in the stored plan entries as RUN_EDGE_SUBSTITUTE, and in the dry-run JSON `substitute` list). On the real LAB the update is executed by the
  installed 0.8.2 installer code, which also does not store the new `substitute` / `blocker_reasons` audit keys — the entries with the decision are stored. Cosmetic;
  logged for the next release.
- `appliance.json` `edge_app_version` on the LAB still reads 0.8.0-edge (known since 0.8.2 §5; nothing reads it).

## 5. Readiness verdict

READY_FOR_083_INSTALL = **YES (package ready, held)** — signed package audited pristine, migration plan audited (hash `sha256:f93da939…`), upgrade and rollback
rehearsed on a faithful disposable copy with the LAB's data preserved, cashier health and the 0.8.3 startup-reliability commands verified on the packaged code.
NOT installed on the real LAB (owner order): LAB 0.8.2-edge STANDBY_READY, pointer 0.8.2-edge, 218 migrations, health 200, 3317 idle, `secrets\admin.pass` absent.
Install procedure when the owner opens it: the 0.8.2 record's Phase A (identity → process preflight → baseline → pre-update backup → dry-run through the REAL
0.8.2 launcher with `--expected-plan-hash=sha256:f93da939…` → stop/apply/start → health → bounded acceptance). Expected LAB plan = §2 (13 RUN + 1 substitute);
if the real dry-run hash differs from `f93da939…`, stop and report.

Disposable leftovers (delete on the owner's word): `labcopy-082\` (~0.7 GB), DBs `bingoo_edge_dryrun_lab_082` + `bingoo_edge_dryrun_lab_082_base`,
`evidence\release-0.8.3-edge\labcopy\lab-appliance-db-copy-082-20261117T143256Z.sql` (plaintext LAB data), plus the 16 Nov migrev leftovers.
