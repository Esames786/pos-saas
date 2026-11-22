# Edge 0.9.0 — CP6 release and LAB acceptance, 22 Nov 2026

The owner's GO on 22 Nov approved CP5. It accepted the five streams and integ `0cc1cb2a` as the release-candidate baseline,
subject to final source identity verification, and ordered CP6 in this sequence:

1. build and sign 0.9.0;
2. rehearse 0.8.2 → 0.9.0 on a disposable copy of the real LAB;
3. run the controlled real-LAB acceptance.

Khatri stays on 0.8.5. Khatri's upgrade needs this CP6 result plus a separate owner GO.

## 1. Owner decisions recorded

### 1.1 Gap 2 — partial payments (financial decision, 22 Nov 2026)

- Money received is recorded at the time of the actual payment and attributed to the shift/counter that actually received
  it.
- Finalising the bill never creates a second receipt.
- Existing revenue-recognition and accounting policies are preserved.
- Replay and handback must never duplicate a receipt or a cash movement.
- **0.9 scope is unchanged.** Partially paid Cloud orders stay Cloud-only and are not adopted by continuity. In code this is
  `App\Services\Edge\Continuity\LiveStateMirror`: a mirrored order with `paid > 0` gets the reason `PAID_IN_PART_ONLINE`
  and is never adopted (proven by CP5 scenario h). Partial-payment adoption is a later item, only once safe continuation is
  fully proven.

### 1.2 Package signature vs Windows code signing

- **The release signature** (custody key `a2eaf8a3d3e66a48`, signed `SHA256SUMS` + update manifest) proves to the
  appliance, the wizard and the auto-updater that the package is genuine and unmodified. Windows does not read it.
- **Windows SmartScreen** reads only an Authenticode signature from a trusted code-signing certificate. The release
  signature does **not** remove the "Windows protected your PC" prompt.
- **For controlled LAB tests:** verify the package provenance (signature, audit, SHA-256 of the zip), then run normally.
  - No SmartScreen bypass policy is used.
  - Windows security is not disabled.
  - No broad antivirus exclusion is added.
- **Distribution-readiness item (open):** obtain a trusted Windows code-signing certificate (OV/EV). Authenticode-sign the
  wizard launcher and the scripts or bundle the package ships. Timestamp the signatures. Keep the certificate's private key
  under the same custody rules as the release key.

## 2. Release identity

The lead builds with `tools/edge-release/Build-EdgeReleasePackage.ps1`, from one exact commit, never from a working tree.

**Final package: 0.9.0-edge build r2** (22 Nov, 22:27–22:36). It supersedes build 1 (§5.3 run 1).

| Field | Value |
|---|---|
| Version | `0.9.0-edge`, build mode `release`, strict layout |
| Source | `3328afb81a10b848e6736a99d6aa8450720f2ac7` (`source_dirty` false). It is `0cc1cb2a` plus the three test files of §3 plus the heartbeat fix of §5.3 (two appliance scripts and a guard test) |
| Source proof | 2425 files byte-identical to `git archive 3328afb8`, 0 mismatches, 7 generated, 1724 excluded by the boundary |
| Signing | Custody key `a2eaf8a3d3e66a48`, role `release`. Signature OK; `SHA256SUMS` signature OK |
| `package_hash` | `1cbc7d6c325d5927f6738288f72b2522a1512863c6e6edbb5183a324a3515415` |
| `artifact_manifest_hash` | `2fd9ff47dfac308ad9153a5280a8e2ad8d28bab3e28e96359473ff91e97ede81` |
| Zip | `BingooEdge-0.9.0-edge.zip`, SHA-256 `85bbadad0cd5413e253fe33fe55df7eb10b07f0ba57f1db6b821a4c9b474c5d5` (re-hashed independently) |
| Contents | 9232 files. The fixed `Update-EdgeAppliance.ps1` and `Register-EdgeServices.ps1` are shipped in both script folders (verified in the package) |
| Custody | `releases\BingooEdge-0.9.0-edge-r2\` |

**Build 1: superseded, never published, kept.**
- Source `da7b3202`; `package_hash b7ffe5793857e19f0934cf0e9f3887ea7b2e650d393c56e3a998ba24df1f4044`; zip
  `bf96fe4f…268f`.
- It stays in custody at `releases\BingooEdge-0.9.0-edge\` with `SUPERSEDED-NOT-PUBLISHED.txt`.
- The audit and secret-scan results below were recorded on build 1. Build r2 repeats them: audit OK, no missing,
  unlisted or tampered files, boundary clean, no key or env files.

**Strict audit (`edge:audit-package --require-signature`): OK.**
- No missing, unlisted or tampered files.
- Boundary: 0 forbidden hits, 0 Cloud-only files present, 0 Edge runtime files missing. 206 tenant migrations audited, 0
  problems.
- Bundled runtimes: the php.ini with its CA bundle, MySQL 8.0.30 (server profile, 63 files), and VC++ 14.44.35211.0
  (Authenticode Valid, Microsoft).
- The wizard is packaged (5 files plus the root `Setup-BingooEdge.cmd`).
- Script compatibility: 43 files, no `TimeSpan.MaxValue`.
- Central Cloud URL: `https://bingoopos.com`.

**Secret scan of the package:** no keystore, passphrase, `.env` or `appliance.env` file. The only match is the README's
`keygen` instruction, which names the custody files and contains no key material.

**Custody:**
- Both builds were copied, never moved, into `C:\Users\Dell\.bingoo-edge-release\releases\`:
  - `BingooEdge-0.9.0-edge\` holds build 1 (superseded);
  - `BingooEdge-0.9.0-edge-r2\` holds the final build.
- The signed 0.6.0 to 0.8.5 packages are untouched.
- Build evidence: `D:\BingooEdgeLab-e09\cp6\release\` (build 1) and `…\release-r2\` (r2), each with its summary, audit and
  logs.

## 3. Release gates (final source)

Both gates ran on `0cc1cb2a` (tree clean), one at a time, with nothing else heavy running. Evidence is in
`D:\BingooEdgeLab-e09\cp6\gates\`.

| Gate | Result |
|---|---|
| Fast suite (19:27–19:34) | 659 tests, 6 failures, 5 skipped. The 6 are exactly the recorded baseline unit reds |
| Full MySQL suite, one process, `memory_limit=3G` (19:27–21:16) | 3402 tests, **11 errors + 4 failures**. Only 1 is the recorded debt |

**Classification of the 15 MySQL reds.** None of them is a product defect. All eight files involved (the three tests below,
`VoidPurgeMySqlTest`, `SupplierCreditClarityMySqlTest`, `VoidPurgeService`, `PurgedNumbers`, `MySqlTenantTestCase`) are
unchanged since the 0.9 baseline.

1. **`CateringKitchenSheetLanguageMySqlTest::…print_time…` (1 failure): recorded canonical debt.** It fails identically on
   the untouched Edge branch `d15c1320` (khatri-cloud-edge-go-no-go-2026-11-21.md). It concerns the catering sheet, not
   Edge or Cloud.
2. **`VoidPurgeMySqlTest` ×10 and `SupplierCreditClarityMySqlTest` ×1 (11 errors): test-order pollution in the harness.**
   - The test `EdgeTenantMigrationUpgradeMySqlTest::test_purged_entries_tables_are_cloud_only…` drops `purged_entries` and
     `purged_numbers` to prove the appliance shape, and never put them back.
   - The schema is migrated only once per process, so every later void-purge test found the tables missing.
   - Earlier gates ran the suite in two processes, so the two tests never shared a schema.
   - **Reproduced quietly:** run in one process after the drop test, they are red (2 errors); run alone in a fresh process,
     26 of 26 are green.
   - **Fix (test only):** the drop test restores both tables in `finally`, using the migration's idempotent `up()`.
3. **`EdgeCashierPermissionMatrixMySqlTest` ×2 (failures): stale expectation after a deliberate 0.9 change.**
   - Stream C catalogued `tenant.offline-edge.counter-install` (owner, 21 Nov: self-service Install Offline Counter). It is
     in the new-tenant Cashier template and in `CASHIER_TEMPLATE_ADDITIONS`. It is never granted to an existing role.
   - The matrix now documents it as `NO_EDGE_ENDPOINT` (a Cloud-only Online POS entry; `PosRuntime` sets it null on Edge).
   - The read-only audit test now expects both recent additions.
   - The probe half of the test, which the stale assertion had cut short, now runs and passes.
4. **`EdgeCleanMachineInstallMySqlTest` (1 failure): stale expectation after a deliberate 0.9 change.**
   - Stream B's signed auto-updater is the sixth supervised task (`BingooEdgeUpdater`, LOCAL SERVICE, never SYSTEM).
   - The expected task list now includes it.
   - The rest of the clean-machine install, update and uninstall proof, which the failure had cut short, now passes (334 s).

**After the fix (`da7b3202`, test files only):** the affected classes were re-run in one process, in suite order (79 tests).
Only the catering debt failed. The matrix class then passed 3 of 3. `tests/` is not in the appliance artifact include list,
so the package content is the same as at `0cc1cb2a`; only the recorded commit differs.

**The final source `3328afb8` adds only the §5.3 heartbeat fix.** It touches two PowerShell appliance scripts and a guard
test, and no PHP application code. The full suites were therefore not run again. Re-run on `3328afb8`:
- the guard test plus every test that exercises the two scripts: 29 fast tests OK, and 20 MySQL tests OK (1 skipped, as
  before). The MySQL tests were `EdgeCleanMachineInstall`, `EdgeSupervision`, `EdgeUpdatePlanHash` and
  `EdgeWizardHeadlessRehearsal`;
- the PS 5.1 parser on both scripts: 0 errors;
- the Windows Sandbox and LAB-copy proofs of §5 on the built package.

## 4. Migration classification

- **Tenant migrations:** the 21 added since 0.8.2 (`a6113772`) are all classified.
  - EDGE_REQUIRED: 8
  - EDGE_SCHEMA_SAFE: 5
  - CLOUD_ONLY: 8
- **Pending owner approvals:** none. Manifest `edge-tenant-migration-manifest@2026-11-16.2` holds 0 `PENDING` entries. The
  review-required entries (decimal_pack_sizes, the purchase-bill void ENUM, the order-note default-off substitute) carry the
  owner approvals of 16 Nov.
- **Edge-local migrations:** 7 added since 0.8.2.
- **Cloud (LAB Cloud at ac5b168d → 0.9):** +7 master and +21 tenant migrations, applied Cloud-first.

## 5. 0.8.2 → 0.9.0 rehearsal on a disposable LAB copy

The copy lives in `D:\BingooEdgeLab-e09\cp6\labcopy`; evidence is numbered under `evidence\`, and the summary is
`00-PHASE1-SUMMARY.txt`.

- **Install root:** a robocopy of the real LAB install root (pointer 0.8.2-edge).
- **Data root:** copied without backups or logs.
- **DBs:** from read-only dumps (14:32:30Z): `pos_cp6_lab_master_edge`, `pos_cp6_lab_tenant_edge`, `bingoo_edge_cp6_lab_local`.
- **appliance.env:** 22 keys rewritten. Every Cloud URL now points to the copy Cloud on :9741; ports are 18090/18091/18443/18081;
  the FakePrinter is on :19100. Zero references to :9701 or to real LAB paths remain. The worker launcher refuses to start
  on either.
- **Copy-only fix:** the copied `edge_local_backups` ledger (24 rows) pointed at the real LAB backup folder, and backup
  retention prunes by path. It was re-pointed to the copy folder before any worker started.

### 5.1 Phase 1 (no package needed) — PASS

| Step | Result |
|---|---|
| (a) baseline: 0.8.2 copy on a copy Cloud at the LAB's current code (ac5b168d) | STANDBY_READY "No problems". Acks every ~21 s. Gateway 18443 200 with verified TLS. 445 tables |
| (b) Cloud-first: copy Cloud → clean export of 0cc1cb2a (copied no-dev vendor), deploy.sh steps 3–5 for the one tenant | 31 s. 28 migrations, 0 failures: master 49 → 56 (+7), tenant 217 → 238 (+21). MasterSeeder and route sync (862) OK |
| (c) backward compatibility: unchanged 0.8.2 appliance against the 0.9 Cloud | PASS. Cloud switch downtime 38 s (1 missed tick). Max heartbeat gap 44 s vs TTL 120. Lease never lapsed; `fenced_at` null throughout. 0.8.2 applied the 0.9 Cloud's config refresh 9 → 10 and a stock cutover cleanly. STANDBY_READY "No problems" |
| fencing measurement (copy only) | Authority worker stopped at 14:51:11; lease expired at 14:53:11. The first Online-POS fence check at 14:53:58 returned blocked and stamped `fenced_at`. The worker restarted at 14:54:03; the first heartbeat at 14:54:04 was acked (holder Cloud, fence cleared). The Online POS was unblocked again by 14:55:19 |

**Fencing consequence.** Any appliance outage longer than the 120 s lease pauses Online POS writes for that branch until
the next heartbeat. Causes include the PC off, the workers stopped, or an updater that stops the workers before a long
staging. Recovery is automatic on the first heartbeat. This is the designed split-brain guard; it is not a defect. Every
upgrade path must therefore keep the heartbeat gap under 120 s, or be scheduled as a maintenance window.

**Additive check of the Cloud migrations.** Four `up()` bodies are not purely additive. All four are already classified
and approved:
- the `stock_ledgers.movement_type` ENUM widening
- `decimal_pack_sizes` (R-1)
- the `purchase_bills.status` ENUM widening
- `order_note_toggle_default_off`: its Cloud body rewrites every `receipt_layout_settings` row to 0. The LAB has 0 rows.
  Production already carries this migration (it is in `dc0babbf`), so a later 0.9 Cloud deploy will not run it again.

**Production Cloud delta for 0.9** (0cc1cb2a vs production `dc0babbf`, recorded for the later Khatri decision): 5 master
migrations and 1 tenant migration, all additive.
- Master (all from 21 Nov):
  - `create_edge_setup_sessions_table`
  - `create_edge_releases_table`
  - `add_edge_devices_install_status_columns`
  - `add_approved_plan_hashes_to_edge_releases`
  - `add_pairing_codes_issued_to_edge_setup_sessions`
- Tenant: `add_edge_auto_takeover_to_branches`.

### 5.2 Phase 2 (release-signed package) — PASS

Evidence: `evidence\00-PHASE2-SUMMARY.txt` and `p2-*` (39 files). The copy ran on its installed 0.8.2 runtime, the same path
the real LAB uses: `edge:local:update --expected-plan-hash`, non-elevated.

| Step | Result |
|---|---|
| Package | The zip SHA-256 was recomputed and equals `bf96fe4f…`. The manifest shows 0.9.0-edge, release, `da7b3202`, not dirty, `package_hash b7ffe579…`. The 0.8.2 runtime verified the release signature against its own `EDGE_UPDATE_PUBLIC_KEY` |
| Plan (dry run) | `plan_hash sha256:8ef6ca9ef67585666295c988654ccc144ed8e303db61b0ada9f6e1eb16478b21`, verdict OK, no blockers. See the breakdown below |
| Baseline | Edge: orders 6, lines 25, payments 5, customers 2, print jobs 36, KOT 10, shift 1, outbox 5 acknowledged. Cloud: orders 5, lines 20, payments 5. Duplicates by natural key: 0. Pre-update dumps taken |
| Update | 21:54:20 → 21:59:50 (5 min 30 s), applied with an equal plan hash. Migrations 218 → 238. See the timeline below |
| Workers | The 0.8.2 updater has no worker stop/start hooks. The old workers kept heartbeating throughout. They were restarted on 0.9 with the launcher (22:00:50 → 22:01:11); the 0.9 plan adds `BingooEdgeUpdater` (15 min) |
| Lease / Online POS | 32 heartbeats acknowledged. Max gap 22 s during the update and 27 s at the worker restart (TTL 120 s). The lease never expired and `fenced_at` was never set. The Online POS gate (`cloudSaleMutationBlocked`) was open in every 3 s sample |
| Cloud sale during the update | 58 s into the update, pointer still 0.8.2: sale `SO-20261122165518-101`, HTTP 200, paid 100.00, through the real HTTP kernel. It needed a disposable open shift first; the first attempt got the Cloud's normal "no open shift" rule |
| After the update | 0.9.0-edge `da7b3202` STANDBY_READY "No problems". Outbox 0 pending, 0 permanent, 5 acknowledged (digest identical). Holder Cloud, epoch 1, auto-takeover off. Pre-existing rows are byte-identical on both DBs. See additions below |
| Duplicates | 0 on every natural key, on both DBs. Keys checked: sale_uuid, client_uuid, sale_no, line_uuid, payment_uuid, shift_uuid, customer_uuid, print job and KOT keys |
| Rollback (backup-based) | 22:07:31 → 22:08:05 (34 s). See the steps below |

**Plan breakdown.**
- Tenant migrations, 13 run:
  - EDGE_REQUIRED 8: 7 run as-is, plus the order-note Edge substitute.
  - EDGE_SCHEMA_SAFE 5: all run.
- Edge-local: 7 EDGE_OWNED, run.
- CLOUD_ONLY 8: excluded from the artifact, so they are never pending and never run. The manifest is the record.
- This matches the reviewed classification in §4.

**Update timeline.**
- The 0.8.2 process: verify, encrypted pre-update backup, stage (done by 21:54:52), re-verify the staged bytes (21:54:52 →
  21:59:46, I/O-bound, near-zero CPU), pointer switch (21:59:46/47).
- The 0.9 runtime: first boot 21:59:47; schema done 21:59:52.

**Additions after the update (all expected).**
- Cloud: the disposable shift and sale.
- Edge: the standby's `cloud_mirror` copy of that sale (same `sale_uuid`, not in the outbox).
- The 0.9 continuity mirror of the open Cloud shift.

**Rollback steps and result.**
- Steps: cooperative stops and the recorded pids, pointer back to 0.8.2, the 0.8.2 plan, DB restore (15.7 s), start.
- The Cloud heartbeat gap was 66 s. The lease never expired, was never fenced, and the Online POS was never blocked.
- The first beat from the restored DB was refused (its sequence was behind). 0.8.2 resynced, and the next beat was
  acknowledged.
- Result: 0.8.2 STANDBY_READY "No problems" against the still-0.9 Cloud. Pre-existing rows and the outbox are identical to
  the baseline. The Cloud sale re-mirrored. Duplicates: 0.

**Host load.** B's Windows Sandbox was running at the same time (9.1 GB RAM free, CPU 19%). No gap fell in the 90–150 s band.

**Re-run on the final package r2 (`1cbc7d6c…`): PASS.** Evidence: `evidence\00-PHASE2-R2-SUMMARY.txt` and `p2r2-*`. The
build-1 staging folder was moved to `labcopy\attic` (not deleted), so r2 staged into a clean folder.

| Check | Build 1 | r2 |
|---|---|---|
| Plan hash | `8ef6ca9e…` | `8ef6ca9e…` (equal to the reviewed plan; entries byte-identical) |
| Update | 5 min 30 s | 5 min 47 s (22:38:57 → 22:44:44); re-verify I/O-bound again |
| Worker restart on 0.9 (F1) | about 1 min after the update | 21 s, started the moment the update returned |
| Max heartbeat gap | 27 s | 28 s (22 s during the update) |
| Online POS gate blocked | 0 samples | 0 of 135 samples |
| Cloud sale mid-update | HTTP 200 | HTTP 200, `SO-20261122173930-662`, pointer still 0.8.2 |
| After the update | STANDBY_READY | 0.9.0-edge `3328afb81a10` STANDBY_READY "No problems". Outbox 0/0/5 (digest identical). Holder Cloud, epoch 1. Auto-takeover off (`CLOUD_SETTING_OFF`). Every LAB-origin row unchanged; duplicates 0 on both DBs |
| Rollback | 34 s, gap 66 s | 44 s, gap 63 s. The graceful authority stop took 20 s at about 51% host CPU. 0.8.2 STANDBY_READY "No problems"; duplicates 0 |

**Operator runbook for the real LAB:** `evidence\LAB-UPDATE-PROCEDURE-R2.txt`. It was written, **not executed**.
- Sections: A pre-flight and backups, B Cloud-first, C dry run and apply through the 0.8.2 launcher, D the worker restart
  (D1–D6), E verify, F rollback, and stop rules.
- Start D1 the moment "applied" prints.
- Keep the rollback within about 60 s.

**Procedure notes for the real LAB.**
- **F1.** The 0.8.2 updater never restarts the workers. Restart them right after `edge:local:update`: cooperative stops,
  then `Start-EdgeLabWorkers -Action Stop`, then re-render the plan with 0.9, then Start. Until then, the old 0.8.2 workers
  run on the migrated, additive schema; this was harmless in the rehearsal (about 1 minute).
- **F2.** Expect about 5 minutes of I/O-bound re-verify. It is harmless.
- **F3.** A Cloud sale needs an open shift on the terminal.

## 5.3 Windows Sandbox: 0.8.5 → 0.9.0 through the real wizard (the Khatri path)

### Run 1: package `b7ffe579` — defect found, package superseded

Agent B ran the release kit `BingooEdge-CP6-release-4g.wsb`. Evidence: `D:\BingooEdgeLab-e09\cp5-release\evidence\`
- `cp6-run1-release-b7ffe579-wupdate-PASS-gap136-PAUSED16s-v-mirrorcheck-FAIL`
- `cp6-run1b-…-v-rerun-PASS`

The kit trusts the release key as a public key only.

| Scenario | Result |
|---|---|
| prereqs | PASS, 256 s |
| cloud | PASS, 103 s |
| a: real 0.8.5 install | PASS, 382 s. The 0.8.5 `[TimeSpan]::MaxValue` task bug needed the documented Khatri workaround |
| w-update: real WPF wizard, UI Automation | PASS, 365 s, screenshots `ui-update-01..07` |
| b: same device, K5, tasks, PIN logins | PASS, 37 s |
| v: owner checks | PASS on re-run, 30 s. The first run's check wrongly assumed the appliance DB never changes (harness fix `e83a652d`) |

**Heartbeat: FAIL.**
- Max gap 136 s against the 120 s TTL (17:10:06 → 17:12:22 UTC).
- The lease lapsed: the minimum remaining was −15 s.
- The Online POS was paused for about 16 s (17:12:06–17:12:21). `fenced_at` stayed null only because no sale arrived in
  that window.
- Both test sales were accepted, because they came before the expiry:
  - `SO-…171009-161`, while the workers were being stopped;
  - `SO-…171113-748`, during the real update.
- The appliance and Cloud DBs show no new duplicates on any natural key. The deltas equal the 2 accepted sales; on the
  appliance they are their `cloud_mirror` rows. The final state was STANDBY_READY with no problems; the outbox had 0 pending
  and 0 failed before and after.

**Which runtime ran each step (local time):**
- The wizard and `Update-EdgeAppliance.ps1` came from the 0.9 package.
- The plan dry-runs ran on 0.8.5 (22:08:17, 22:08:53).
- The pre-update snapshot ran with 0.9 code, while 0.8.5 kept serving (22:09:24).
- The worker `--stop` ran on 0.8.5 (22:10:02/05).
- **The real `edge:local:update` ran on the 0.8.5 code** (22:11:10).
- Pointer switched to 0.9.0 at 22:12:01; the 0.9.0 workers started at 22:12:13.

**Root cause.**
- `Update-EdgeAppliance.ps1` step 3 stopped every service, the authority/heartbeat worker included.
- It did so before the dry-run, the pre-update backup, the staging and the schema upgrade.
- All of these run inside the old runtime's `edge:local:update`, and no version of that command stops workers itself.
- On a slow disk the 0.8.x staging re-verify alone took about 5 minutes (§5.2). At a real branch the pause would therefore
  last minutes.

**Fix `3328afb8` (lead), reviewed by Agent B.**
- Step 3 stops every service except the plan's authority task.
- `Stop-EdgeAuthorityWorker` (cooperative `--stop`, then the task) runs only after the update has applied and the new plan
  has been rendered, right before the new runtime's services start.
- The failure paths use `Restore-EdgeServices`.
- The cooperative stops in `Register-EdgeServices.ps1` now honour `-Exclude`.
- Safety:
  - §5.2 proved a full 0.8.2 → 0.9.0 schema upgrade with every old worker running.
  - The worker's stop mechanism is unchanged since 0.8.5.
- Tests:
  - Guard: `EdgeUpdateKeepsHeartbeatTest`.
  - Regressions: 29 fast tests OK, 20 MySQL tests OK (1 skipped as before).

**Not changed in 0.9.0 (open for 0.9.1).**
- The 0.9 auto-updater (`EdgeAutoUpdateServiceControl`) still stops the authority worker right before the pointer switch.
  Its gap is the schema upgrade plus the restart: CP5 measured 70 s.
- It runs only inside the owner's maintenance window, and only with an approved plan hash.
- Fix before the first auto-update that carries a heavy schema change.

### Run 2: final package r2 (`1cbc7d6c…`)

Evidence: `D:\BingooEdgeLab-e09\cp5-release\evidence\auto-20261122-223932`. Kit harness `56dedf35`, `edge09/b-wizard`.

| Scenario | Result |
|---|---|
| prereqs | PASS, 254 s |
| cloud | PASS, 83 s |
| a: real 0.8.5 install | PASS, 274 s |
| w-update: real WPF wizard, 0.8.5 → 0.9.0 r2 | PASS, 346 s |
| b: same device, K5, tasks, PIN logins | PASS, 36 s |
| v: owner checks | PASS, 32 s |

Evidence is filed as `cp6-r2-main-release-r2-ALL-PASS-gap38-paused0`.
- **Duplicates:** 0 new on both DBs.
  - Cloud: +3 orders and +3 payments, matching the 3 sales.
  - Appliance: +3 `cloud_mirror` orders (same `sale_uuid`, one each) and 3 payments.
  - Shifts, print jobs and KOT: +0 on both DBs.
- **Final state:** 0.9.0-edge STANDBY_READY "Ready as warm standby — Cloud is serving this branch", no problems.
- **Outbox:** 0 pending, 0 failed, 0 errors, before and after.
- `secret_hits_redacted` 0. The sandbox was stopped afterwards; `wsb list` was empty.

**Heartbeat: PASS.** The driver's verdict line reads "max gap 38 s (TTL 120 s), Online POS paused 0 s".
- 15 Cloud heartbeats. Max gap 38 s (17:53:52 → 17:54:30 UTC). The lease never lapsed (at least 83 s always remaining).
- `fenced_at` stayed null, the holder was Cloud throughout, and 0 samples were blocked.
- Pointer switch to 0.9.0-edge at 17:54:05 UTC.

**Authority worker timeline (local time).** The old worker stayed up through the whole update.

| Time | Event |
|---|---|
| 22:49:52 | Old worker (pid 4868) seen |
| 22:52:03 | `Update-EdgeAppliance.ps1` starts |
| 22:53:13 | Real `edge:local:update` (0.8.5 code) starts |
| 22:54:05 | Pointer switch |
| 22:54:10 | Authority stop requested |
| 22:54:13 | Old worker gone |
| 22:54:20 | New worker and services start (step 5) |
| 22:54:22 | Script ends |

**Online sales during the update: 3 of 3 accepted by the fence check.**

| When (UTC) | Moment | Sale |
|---|---|---|
| 17:52:35 | Old workers stopping | `SO-20261122175235-666` |
| 17:53:16 | Real update running | `SO-20261122175316-178` |
| 17:54:08 | Right after the pointer switch | `SO-20261122175409-443` |

### Run 3: the wizard's K6 rollback on the final package r2

This chain ran in a new sandbox. Evidence: `D:\BingooEdgeLab-e09\cp5-release\evidence\auto-20261122-225735`.

| Scenario | Result |
|---|---|
| prereqs | PASS, 255 s |
| cloud | PASS, 74 s |
| a: 0.8.5 install | PASS, 225 s |
| r: real r2 update, forced unhealthy, wizard K6 rollback | PASS, 549 s |
| b: headless re-update | PASS, 253 s. The plan gate refused the unapproved plan first (as designed); after test-owner approval on the sandbox Cloud, 0.9.0-edge, same device, STANDBY_READY |
| v: owner checks | PASS, 27 s. STANDBY_READY with no problems, outbox 0/0/0, 0 duplicates, all deltas 0 |

The evidence folder is filed as `cp6-r2-rollback-r-PASS-restored_snapshot-gap80-paused0-b-v-PASS`. All sandboxes were then
stopped, and `wsb list` was empty at 23:21.

Across every CP6 sandbox run:
- `secret_hits_redacted` 0; no signed URL parameters.
- No SmartScreen or UAC prompt appeared inside the sandbox. It runs as admin, and the mapped files carry no
  Mark-of-the-Web.
- Nothing was elevated on the host, and no keystore was used.

**How r forced the rollback.**
- Web backend #2's port was held as the fault (23:09:54–23:13:48).
- The wizard saw the new problem ("Cashier web backend #2 is being restarted by its supervisor") and rolled back by
  restoring its snapshot. It reported `rolled_back` / `restored_snapshot` and exited with 2.
- The pointer went 0.8.5 → 0.9.0 (18:10:35 UTC) → 0.8.5 (18:14:38).
- During the update half, the old authority worker stayed up until right before step 5, as in run 2.

**Heartbeat during the K6 snapshot restore: PASS, with a thin margin.**
- Max gap 80 s (18:13:50 → 18:15:10 UTC). The lease never lapsed (at least 40 s remaining). Online POS paused 0 s;
  `fenced_at` null; holder Cloud.

| Time | Event |
|---|---|
| 23:13:52 | K6 `Register-EdgeServices -Action Stop` (no `-Exclude`; the worker must be stopped for a restore) |
| 23:14:12 | Worker gone (the cooperative stop took 20 s) |
| 23:14:29–38 | Snapshot restore (9 s) |
| 23:14:38 | Pointer back to 0.8.5 |
| 23:14:41 | 0.8.5 worker starts |
| +29 s | First heartbeat |

**Caution.** The sandbox DB restores in 9 s. A real branch DB restores more slowly, and the gap grows about one-for-one
with restore time. A K6 rollback at a large branch can therefore pass 120 s and pause the Online POS until the first
heartbeat on the restored version. This happens only on the exceptional rollback path; the normal update path keeps the
heartbeat (run 2).

**After the rollback.**
- 0.8.5-edge STANDBY_READY with no problems, as before the update.
- PIN logins work for the owner and both counters.
- The Cloud device shows `install_phase rolled_back`, reported version 0.8.5-edge.
- Duplicates: 0 new. Shifts, orders, payments, print jobs and KOT deltas are 0 on both DBs.

## 6. Real LAB acceptance: not started, waiting for its own approval gate

On 22 Nov the owner ordered: "No real LAB installation or Khatri update until their separate approval gates."

The real LAB was only read. At the end of CP6 it showed:
- 0.8.2-edge `a6113772`, STANDBY_READY;
- authority standby/online, last acknowledgement 18:21:38 UTC, 0 failures;
- outbox 0 pending, 0 permanent failures, 5 acknowledged;
- both web backends healthy.

When the gate is given, the procedure is the rehearsed runbook `D:\BingooEdgeLab-e09\cp6\labcopy\evidence\LAB-UPDATE-PROCEDURE-R2.txt`:
1. Pre-flight and backups.
2. LAB Cloud first: the clean `3328afb8` export, then +7 master and +21 tenant migrations.
3. Dry run. The plan hash must equal `sha256:8ef6ca9e…`.
4. Apply through the 0.8.2 launcher.
5. Restart the workers on 0.9 immediately.
6. Verify.
7. If needed, roll back by restoring the backup.

No takeover, no financial transactions, no automatic failover and no handback.

## 7. CP6 result

| Field | Value |
|---|---|
| Final source | `3328afb81a10b848e6736a99d6aa8450720f2ac7` |
| Package | 0.9.0-edge r2. `package_hash 1cbc7d6c325d5927f6738288f72b2522a1512863c6e6edbb5183a324a3515415`, zip `85bbadad0cd5413e253fe33fe55df7eb10b07f0ba57f1db6b821a4c9b474c5d5`, release key `a2eaf8a3d3e66a48` |
| Package audit | OK: strict, signature required, boundary clean, source proof 2425 identical / 0 mismatched, no secrets |
| Migration plan | `sha256:8ef6ca9ef67585666295c988654ccc144ed8e303db61b0ada9f6e1eb16478b21`. 13 tenant (8 EDGE_REQUIRED, one through the approved substitute; 5 EDGE_SCHEMA_SAFE) + 7 edge-local. 8 CLOUD_ONLY are not shipped. 0 pending owner approvals |
| 0.8.2 → 0.9.0 (LAB copy) | PASS, twice (build 1 and r2). Max gap 28 s, Online POS never blocked, mid-update Cloud sale OK, data intact, 0 duplicates. Backup rollback 44 s (gap 63 s) |
| 0.8.5 → 0.9.0 (Sandbox, real wizard) | r2 PASS: max gap 38 s, paused 0 s, 3 of 3 sales accepted, 0 duplicates. K6 snapshot rollback PASS (gap 80 s, paused 0 s), then headless re-update PASS. Build 1 FAILED (136 s gap, ~16 s pause): fixed in `3328afb8` and superseded |
| LAB Cloud compatibility | PASS on the copy. Cloud-first 28 migrations in 31 s, 0 failures. An unchanged 0.8.2 against the 0.9 Cloud works (max gap 44 s) |
| Auto-takeover default | OFF (`CLOUD_SETTING_OFF`); `AUTO_FAILOVER_ENABLED=no` |
| SmartScreen | Package signature ≠ Authenticode (§1.2). A trusted code-signing certificate is an open distribution-readiness item |

**Open items, none blocking CP6.**
1. **Real LAB acceptance:** waits for its approval gate (§6).
2. **Khatri:** stays on 0.8.5 and needs a separate owner GO. Prerequisites:
   - the real LAB acceptance result;
   - the production Cloud deploy of the 0.9 delta (5 master + 1 tenant migrations, all additive; §5.1);
   - a measured restore time for Khatri's DB. A K6 rollback's gap is about 71 s plus the restore time, so a restore longer
     than about 45 s risks an Online POS pause on the rollback path only. Schedule the update in a quiet window.
3. **0.9.1:** the auto-updater (`EdgeAutoUpdateServiceControl`) still stops the authority worker before the schema upgrade
   (CP5: 70 s). It runs only in the maintenance window, with an approved plan hash.
4. **Stream A note:** mirrored payment rows on the appliance get their own `payment_uuid`s (still one row per Cloud payment).
5. **Code-signing certificate:** OV/EV for the wizard and scripts (§1.2).
6. **The 0.8.5 `[TimeSpan]::MaxValue` task bug:** it hit the 0.8.5 install in every sandbox run (documented workaround). The
   0.9 scripts carry the fix, and the update replaces the installed scripts.
