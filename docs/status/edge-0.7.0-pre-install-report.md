# Offline Edge — 0.7.0-edge pre-install report (LAB update approval request)

Date: 25 Sep 2026. Author: coordinator (Edge parity programme). Audience: the owner, before the LAB appliance update.
Directive §8: *"Before installing it, report: changed files and release commit, restricted-artifact audit result, signed-update
verification result, database migration and rollback requirements, existing LAB outbox/backup state, any operation needing
Administrator permission, exact update/rollback procedure. Request owner approval for the actual LAB update."*

Nothing in this report touched the LAB appliance, the LAB Cloud, or any production tenant. All LAB facts below are read-only
observations of the LAB databases on this machine (the LAB stack itself has been down since the 22 Sep reboot).

## 0. Decision summary

| Item | State |
|---|---|
| Release commit | **`7b8f886`** on `feat/edge-config-refresh-v1` (= origin). App code = `0eb55c4` (all W0–W6 work) **+ one release fix** (§4) |
| Package | `C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.7.0-edge` — RELEASE mode, keystore-signed (key id `a2eaf8a3d3e66a48`) — §2 |
| Restricted-artifact audit | §2 — **PACKAGE OK** (8,752 files; boundary audit clean; source proof 0 mismatches) |
| Signed-update verification | §3 — **VERIFIED_OK** against the appliance public key; tampered / foreign-key / downgrade / wrong-schema all refused |
| Migrations | 2 additive (1 tenant, 1 edge), forward-only, dry-run applied on a copy of the dev appliance DB — §4 |
| Rollback | pointer rollback viable only BEFORE the first v7 refresh; after it, restore the pre-update backup — §4 (proven on the dry-run) |
| LAB state | outbox 0 / no open work / 24 verified backups (last 21 Sep 19:17) / stack DOWN — §5 |
| Administrator steps | none expected — §6 |
| Approvals requested | §8 — seven specific actions, none executed |

## 1. Changed files and release commit

- Installed LAB build: **0.6.0-edge** from `623f887` (pilot release, 14 Sep 2026).
- Release commit: **`7b8f886`** — `git diff --shortstat 623f887..7b8f886` = **222 files changed, 32,204 insertions, 2,204 deletions**. By area: resources/views 61 (cashier page decomposition + Edge partials/js), tests/MySql 50, app/Services 29, app/Http 24, docs/status 21, tools 7, tests/Feature 7, docs/plans 7, routes 2, database/migrations 2, app/Exceptions 2, app/Support 1, app/Models 1, app/Jobs 1, app/Console 1, config 1, tests/Unit 1, tests/Fixtures 1, docs/reference 1, .gitignore 1, .env.edgedev.example 1. Docs, tests and tools are excluded from the artifact by the packaging rules. The only app-code difference between the commit the full suites ran on (`191a376`) and the release commit is the release fix: `app/Services/Edge/EdgeLocalConfigRefreshApplier.php` (+13/−1) and its test (`tests/MySql/EdgeBootstrapV7MySqlTest.php`, +39)..
- Commit ladder since 0.6.0: audit `b678568` → W0–W0e foundation → Teams 1–5 (`efbd239`, `2c7ef88`, `068398c`, `91a9b23`, `845bb02`) → shared surfaces `30105df`
  → canonical reconcile `1baa34c` (canonical `b529c95`, 0 conflicts) + `b6b11ea` → W6 contract `06bb53b` → coordinator `0eb55c4` → docs `191a376`
  → **release fix `7b8f886`** (§4.3).
- Workstream reports: `docs/status/edge-w1-team1-report.md` … `edge-w6-team6-report.md`; board `docs/status/edge-parity-execution-board.md`;
  release plan `docs/status/edge-w6-contract-and-reconcile-plan.md` Part C.
- Contract changes carried by this release (Cloud + Edge halves land together): envelope v1 accepts tips, line-only discounts, `notes`,
  modifier ids/names/deltas; envelope v2 only for stock-consuming modifiers; outbox `SCHEMA_UNSUPPORTED` retryable with bounded backoff
  (coordinator-approved — owner confirmation requested, §8.7); **bootstrap `edge-bootstrap-v7`** (a v6 appliance is refused by a v7 Cloud and
  reported `software_update_required` — sequencing in §7).

## 2. Restricted-artifact audit (release package)

| Fact | Value |
|---|---|
| package dir | `C:\Users\Dell\.bingoo-edge-releaseeleases\BingooEdge-0.7.0-edge` (RELEASE mode; built 25 Sep 14:53–15:05 from the clean worktree at `7b8f886`) |
| `edge_app_version` / `git_commit` | `0.7.0-edge` / `7b8f886279db61432ef6b842c726f446d412b435` |
| `package_hash` | `4fb33780e3d049f0bed056e7b8589082537198380759bb6b00f839778bfa4e42` |
| `artifact_manifest_hash` (app component) | `e40dc2281957da4b4029fe1b58ebca5d3bf6263abd919a94b675421ec70a9e47` |
| files | 8,752 (app + php-8.3.16 runtime + nginx-1.22.0 gateway + scripts + update manifest) |
| `edge:audit-package` | **PACKAGE OK** — `boundary_audit.ok = true`, `forbidden_hits []`, `cloud_only_present []`, `edge_runtime_missing []`, `artifact_marker_branch_server = true` |
| source proof | package `app/` vs `git archive 7b8f886`: **2,040 files checked, 0 mismatches**, 6 only-in-package (`.gitkeep` placeholders of empty storage/cache dirs) |
| signing | custody keystore v1, key id `a2eaf8a3d3e66a48` (Ed25519); passphrase read from its file; keystore, passphrase and private key never left the custody directory |
| superseded build | the earlier `191a376` package (hash `542bb3f0…`) was set aside as `BingooEdge-0.7.0-edge.superseded-191a376` and must not be installed |

Build procedure (P5B shape): `git archive 7b8f886` export → `composer install --no-dev` closure (composer.lock unchanged since 0.6.0) →
`edge:build-package` RELEASE mode from the clean committed worktree, `--vendor-from=<closure>`, bundled php-8.3.16 + nginx-1.22.0, signed
from the custody keystore v1 (passphrase read from its file; never on argv, never printed) → `edge:audit-package` → byte-level source proof of
the package `app/` against the export. `app/Jobs/Catering`, `app/Support/Catering`, `app/Services/Catering`, `EdgeInbound*` are on the
exclude list and physically absent (Feature gate `EdgeApplianceArtifactBoundaryTest`).

## 3. Signed-update verification

Signed manifest `update/edge-update-0.7.0-edge.json`: format `edge-update-v1`, target `branch_server`, `edge_app_version 0.7.0-edge`, `source_revision 7b8f886…`, `schema_generation edge-config-v1`, `min_previous_edge_version 0.0.0`, Ed25519 signature (88 chars). Verified with the REAL `EdgeUpdateVerifier` (the class `edge:local:update` runs on the appliance) against the appliance-registered public key `8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ=` (public fact) and the package `app/` bytes on disk (`recomputeManifestHash`); log `scratchpad/verify-0.7.0.log`, script `verify-0.7.0.php` (read-only).

| Case (installed → package) | Result |
|---|---|
| real package, installed 0.6.0-edge / edge-config-v1 | **VERIFIED_OK** |
| payload edited (version field) | REFUSED `UPDATE_SIGNATURE_INVALID` |
| payload edited (artifact manifest hash) | REFUSED `UPDATE_SIGNATURE_INVALID` |
| foreign verification key | REFUSED `UPDATE_SIGNATURE_INVALID` |
| downgrade (installed 0.8.0-edge) | REFUSED `UPDATE_DOWNGRADE_REFUSED` |
| backwards schema (installed edge-config-v2) | REFUSED `UPDATE_SCHEMA_INCOMPATIBLE` |

On-disk tampering of the staged artifact (`UPDATE_ARTIFACT_TAMPERED`) and the A(0.6.0-shaped) → B(0.7.0) update path are proven by `EdgeUpdaterMySqlTest` / `EdgeCleanMachineInstallMySqlTest` (release mode, §9). The appliance also checks `min_php` / `min_db` and, for a pinned package, the tenant/branch/device binding (this package is generic, not pinned).

## 4. Database migrations, dry-run and rollback

### 4.1 Migrations since 0.6.0 (`git diff --name-only 623f887..7b8f886 -- database/migrations`)

| Migration | Runs where | Nature |
|---|---|---|
| `tenant/2026_09_17_000001_add_customer_email_switch_to_catering_settings` | appliance DB at update (tenant path) **and** the LAB Cloud tenant DB `pos_lab_tenant_edge` via `tenants:migrate` (owner-gated, §8.4) | canonical reconcile; additive boolean, `hasColumn` guard |
| `edge/2026_09_25_000001_add_tenant_business_name_to_edge_local_meta` | appliance DB only (edge path, after the tenant path) | W6 bootstrap v7; additive nullable column, `hasColumn` guard |

Cloud master: none. LAB Cloud tenant currently has 171 migrations and no `catering_settings.customer_email*` column (checked read-only).

### 4.2 Update dry-run (copy of the DEV appliance DB `bingoo_edge_devtest_local` → scratch `bingoo_edge_dryrun_local`; the LAB DB was not touched)

Log: `C:\Users\Dell\BingooEdgeLab\evidence\dev-instance\0.7.0-update-rollback-dryrun-2026-09-25.log`.

1. `edge:local:schema-upgrade --dry-run` listed exactly the two migrations; `edge:local:schema-upgrade` applied them (40 ms + 45 ms), the
   protected-table row-count audit passed, `edge_local_meta.edge_schema_version` = `edge-local-schema@2026_09_25_000001_…`, both columns present.
2. **True 0.6.0 code** (`git archive 623f887` with its own no-dev vendor; provenance checked: `SCHEMA_VERSION = edge-bootstrap-v6`) against
   the upgraded copy with the meta still recording v6: health OK, `SCHEMA_COMPATIBLE = true`, `edge:local:schema-upgrade --dry-run` "up to date",
   `edge:local:sync-status` reads the meta row with the extra column. **A runtime-pointer rollback to 0.6.0 is viable as long as the first v7 refresh
   has not been applied.**
3. Same 0.6.0 code after simulating the first v7 refresh (meta `bootstrap_schema = edge-bootstrap-v7`): `SCHEMA_COMPATIBLE = false` → 0.6.0 would not
   return to STANDBY_READY. **After the first v7 refresh, rollback = `Restore-EdgeAppliance.ps1` from the verified pre-update backup** (which
   carries the v6 record) with the LAB Cloud back on a v6 commit (`30105df`).
4. The dev instance DB itself was then upgraded with the same command (dev-only) so the dev instance runs the release schema.

### 4.3 Finding fixed before release (`7b8f886`)

The 0.7.0 code on the upgraded copy with the v6 record showed `SCHEMA_COMPATIBLE = false` — expected right after the update — but nothing would
ever flip it: `EdgeLocalConfigRefreshApplier` never wrote `bootstrap_schema` (only the initial import does), and a re-bootstrap is refused on a
bootstrapped device. An updated appliance would therefore have stayed out of STANDBY_READY for ever. Fix: the applier now asserts the package
schema (`SCHEMA_UNSUPPORTED`, refused whole — the standby freshness worker reaches the applier without the importer) and records
`bootstrap_schema` with the applied revision. Regression test in `EdgeBootstrapV7MySqlTest` (v6 record → gate false; first applied v7 revision →
meta v7, gate true; foreign-generation package refused, nothing applied). The fix is 13 lines in one service; the full suites had already run on
`191a376` (identical app code otherwise) — re-gated on `7b8f886` per §9.

### 4.4 Installer semantics (`EdgeUpdateInstaller`)

Verify (signature / tamper / downgrade / schema / target) → **pre-update backup, verified** (refused if it cannot) → stage → atomic pointer switch →
forward schema upgrade → service-plan re-render → health. Failure before the switch: previous runtime stays. Failure at the schema upgrade: pointer
reverted (`reverted_runtime`) or `restore_required`. No down-migration ever runs; after a successful update there is no rollback command — the
manual paths are §4.2 items 2–3.

## 5. Existing LAB state (read-only, 25 Sep 2026)

| LAB appliance `bingoo_edge_lab_local` | value |
|---|---|
| installed version / bootstrap / config | 0.6.0-edge (`623f887`) / `edge-bootstrap-v6` / `edge-config-v1`, applied config revision 1, `edge_schema_version` NULL (0.6.0 never upgraded) |
| runtime / authority / connection | bootstrapped / standby / online since 21 Sep 06:26 (heartbeats acknowledged) — the stack has been DOWN since the 22 Sep 12:26 machine reboot |
| outbox `edge_sync_outbox` | 0 rows (pending 0 / leased 0 / failed 0) |
| open work | shifts open 0, table sessions open 0, sales orders 0, reservations 0, print jobs 0, `edge_local_updates` 0 |
| backups `edge_local_backups` | 24 × `completed` (20 Sep 07:17 → **21 Sep 19:17:58**, #35, 0.6.0-edge, edge-config-v1, 41,179 bytes); none since the reboot → a fresh one is a pre-update step (C6.4) |

| LAB Cloud (`pos_lab_master_edge` / `pos_lab_tenant_edge`) | value |
|---|---|
| device | `home-lab-laptop`, status ready, app 0.6.0-edge, schema v6, paired 19 Sep 18:58, last authenticated 21 Sep 14:14 |
| config revisions | 1 (19 Sep 18:58); 1 bootstrap snapshot |
| tenant DB | 171 migrations; catering column absent (→ §8.4) |
| LAB cashier (LAB2C5D, user id 1 "Lab Cashier") | permissions `tenant.pos.hold / recall / store / view` only, no role (seed `scripts\lab-cloud.php:101-104`) → cannot open the 0.7.0 POS until §8.3 |

## 6. Operations needing Administrator permission

**None expected.** The LAB install is user-owned (`C:\Users\Dell\BingooEdgeLab`), the workers are hidden user processes started by
`Start-EdgeLabWorkers.ps1` (no Scheduled Tasks / services), `Update-EdgeAppliance.ps1 -NoServices` stages only the `app` payload, and the gateway
binary path the firewall rules reference (`install\BingooEdge\gateway\nginx.exe`) is unchanged. Per the standing rule, nothing is rebooted,
elevated, unplugged or reconfigured without asking immediately before that exact operation.

## 7. Exact update procedure (LAB only, after approval) and rollback

Sequencing constraint: the LAB Cloud serves this worktree's code. From `06bb53b` on it exports **bootstrap v7**, which a 0.6.0 appliance refuses
(`SCHEMA_UNSUPPORTED` / `software_update_required`) — so the LAB Cloud must not be started on the release code until the update window.

1. **Owner go-ahead to restart the LAB stack** with the existing scripts (`Start-LabCloud.ps1`, `Start-EdgeLabWorkers.ps1`, `Start-FakePrinter.ps1`;
   no reboot, no elevation). Recommended: run the LAB Cloud from a `git archive 7b8f886` export for the whole acceptance window (C6.6).
2. LAB Cloud tenant `edgehomelab`: grant the Lab Cashier the Online cashier set = `tenant.pos.index`, `tenant.pos.store`, `tenant.held-sales.store`,
   `tenant.held-sales.cancel`, `tenant.sales-orders.split-bill.store`, `tenant.restaurant.table-sessions.open`, `tenant.restaurant.table-sessions.close`,
   `tenant.shifts.store`, `tenant.shifts.close`, `tenant.api.manager-approvals.verify` (+ `tenant.pos.void-kot-item` for the approver). It rides the
   `users[].permissions` export into the first v7 refresh.
3. LAB Cloud tenant DB: `tenants:migrate` (catering column).
4. C6 read-only pre-checks on the appliance: `edge:local:health --json` STANDBY_READY + authority standby; `edge:local:sync-status --json` 0/0/0; no open
   shift / held check / table; `edge:local:backup --json` fresh verified backup; record version, manifest hash, runtime pointer, LAB Cloud head; snapshot
   the LAB Cloud DBs into the evidence folder.
5. Copy the verified package to the LAB; **ask, then stop the LAB workers**; `.\Update-EdgeAppliance.ps1 -InstallRoot C:\Users\Dell\BingooEdgeLab\install\BingooEdge
   -PackageRoot <package> -NoServices` (hash check → authority guard → `edge:local:update` → pre-update backup → stage → switch → schema upgrade → plan re-render → health).
6. Start the workers from the re-rendered plan; health shows `edge_app_version 0.7.0-edge`, commit `7b8f886`; `SCHEMA_COMPATIBLE` is **false until step 7** (expected).
7. First config refresh (worker or `edge:local:bootstrap-pull`) → new revision with `bootstrap_schema = edge-bootstrap-v7` recorded by the fix → STANDBY_READY.
   Verify imported: `product_modifier_group`, global modifier groups, currencies + denominations, non-cash payment methods (display-only),
   `edge_local_meta.tenant_business_name`, the cashier's new permissions.
8. Heartbeat acks resume; outbox stays 0/0/0; the backup worker produces a new 0.7.0 backup.

**Rollback.** Steps 5–6 fail → the installer already reverted (or reports `restore_required` → `Restore-EdgeAppliance.ps1` from the pre-update backup).
Failure after step 6 but before step 7 → stop workers, switch `runtime\current` to `0.6.0-edge`, start, health (proven viable, §4.2.2). Failure after
step 7 → `Restore-EdgeAppliance.ps1` from the pre-update backup + LAB Cloud back on `30105df` (§4.2.3). Cloud-escrowed recovery key per P5B.

## 8. Approvals requested (each one is a specific action; none has been executed)

1. Restart the LAB stack with the existing start scripts (no reboot / elevation).
2. LAB Cloud code for the window: export of `7b8f886` (recommended) — or the live worktree — started only inside the update window.
3. Grant the LAB cashier (and approver) the permission set in §7.2 on the LAB Cloud tenant (DB mutation on the LAB tenant).
4. `tenants:migrate` on `pos_lab_tenant_edge` (adds the catering column).
5. Stop the LAB workers and run `Update-EdgeAppliance.ps1` with the package in §2 (includes the installer's pre-update backup).
6. First v7 config refresh + the acceptance run from the second laptop (paired Online/Edge screenshots; browser proof `--allow-mutations` on LAB data only).
7. Confirm (or reverse) the coordinator-approved outbox change: `SCHEMA_UNSUPPORTED` is retryable with bounded backoff instead of `failed_permanent`.

Unchanged: no Local Mode / takeover, no cable pull, `AUTO_FAILOVER_ENABLED=no`, `P6_STARTED=no`, no live-tenant transactions, and the §7
owner-dependent items (non-cash tenders/refunds offline, Close Branch / Daily Closing, journal reversal, purchase-return drafts, customer/printer
administration on Edge, Cloud-only reporting scope) remain NOT implemented.

## 9. Test evidence

| Gate | Commit | Result |
|---|---|---|
| Full Feature/Unit suite (`vendor/bin/phpunit`, SQLite) | `191a376` (app code = release minus the applier fix) | 260 tests, **3 failures — all pre-existing on canonical**: `CateringClientFeedbackUiRegressionTest` (nosidebar needle in the catering event Blade), `EscPosReportPayloadTest` (two-decimal money in a report payload), `DeliveryRiderReassignmentRegressionTest` (`'rider'` in `POSController`). Reproduced identically on a clean `git archive` export of canonical `b529c95`; every file they inspect is byte-identical to canonical. Not Edge regressions. |
| Full MySQL suite (`phpunit.mysql.xml`, 1,827 tests) | `191a376` | 1,827 tests / 12,343 assertions, **9 errors + 1 failure, all one cause**: `Class "Dompdf\Options" not found` in the Cloud-side PDF tests (`CateringDocumentA4Fit`, `CateringDocumentMeta`, `CateringDocumentPdf`, `PosQuickReport` A4 e-mail, `ReportSchedule` daily A4 → status `failed`). Root cause: the worktree's DEVELOPMENT vendor never had the locked `dompdf/dompdf v3.1.6` (+5 dependencies) installed — the release closure and the package DO contain it (`composer install --dry-run` listed exactly those 6 installs, 0 updates, 0 removals). After `composer install` synced the dev vendor to the unchanged lock, **all 39 tests of those five classes pass (911 assertions)**. Test files and PDF code identical to canonical. **Edge tests: 0 errors, 0 failures.** Runtime 1 h 47 min. |
| Targeted MySQL (BootstrapV7, ConfigRefresh, StandbyFreshness, SyncRace, LocalImport, Compatibility) | `7b8f886` | **OK 37 tests / 304 assertions** (isolated `_t6` databases) — includes the new schema-follows-refresh test |
| Feature Edge (route census, artifact boundary, dependency closure, Blade gate incl. `node --check`, compatibility v6/v7, log hygiene) | `7b8f886` | **OK 137 tests / 32,883 assertions** |
| Edge MySQL regression (590 tests: `Edge*` + canonical POS/print/approval classes) | `7b8f886` | **590 tests / 6,044 assertions, 2 failures, both load-induced and green when re-run alone** (the run overlapped the full suite and two clean-machine installs for 2 h): `EdgeConnectionPartitionTest` (real-process ticks overshot a lease window: tick 3 read `preparing_local` instead of `connection_unstable`) → **OK 105 assertions alone (5 min 21 s)**; `EdgeCleanMachineInstallMySqlTest` in dev mode (the installer's pairing call to the test's single-threaded Cloud dev server timed out after 20 s) → **OK 155 assertions alone (7 min 04 s)**. The same class passed twice in release mode (next row). No other Edge test failed. |
| Release-mode clean-machine install proof (`EdgeCleanMachineInstallMySqlTest` with `EDGE_PROOF_VENDOR_FROM=<no-dev closure>`; real installer, pairing, bootstrap-pull, A→B signed update over HTTP) | `7b8f886` | **OK twice**: isolated `_t5` databases — 1 test / **160 assertions** (1 h 40 min under load); shared databases, chained after the full suite — 1 test / **160 assertions** (1 h 19 min). Both runs use the real no-dev closure vendor of the release, install from a built package with the real PowerShell installer, pair and bootstrap-pull over HTTP, back up, apply a signed A→B update and uninstall. |
| Update/rollback dry run | `7b8f886` vs `623f887` | §4.2 — both migrations applied; 0.6.0 tolerates the schema; rollback windows established |
| Signed-update verification | package | §3 — VERIFIED_OK + five refusals |
| Browser proof (Playwright on the dev instance, `tools/edge-browser-proof/edge-pos-proof.mjs`) | pre-release | 202 of 222 present census rows exercised; the remaining rows need a paid sale / reservation under branch authority → LAB acceptance run (§8.6) |

Census (Online POS control inventory vs the Edge cashier page, `tests/Fixtures/edge/online-pos-control-census.json`): **278 controls = present 222 / equivalent 39 / online_required 17 / partial 0 / planned 0**, pinned to the Online view hash and enforced by `EdgeCashierControlCensusHttpMySqlTest`.

