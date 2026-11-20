# Production deployment plan — Edge server half for Khatri (candidate `integ/khatri-cloud-edge-20261121`)

Prepared 21 Nov 2026 under owner decision D-A (integration preparation approved; merge into the real production branch,
migrations on production and the deployment itself each need a SEPARATE explicit GO). Nothing in this plan has been executed
against production.

## 1. Pinned commits

| what | commit |
|---|---|
| production branch `feat/14d-2-plan-upgrade-requests` (checkout `D:/laragon2/www/pos-saas`, = live HEAD per the 20 Nov deployment record) | `be3693e4` |
| Edge branch `feat/edge-config-refresh-v1` | `d15c1320` (code identical to the signed 0.8.3-edge appliance source `37731e16` + docs) |
| merge-base | `5c74107f` |
| **integration candidate** `integ/khatri-cloud-edge-20261121` (worktree `D:/laragon2/www/pos-saas-integ-khatri`) | `4c4894d1` merge (no-ff) → `c4c3e610` shared-view guard → this doc |
| held appliance package | `BingooEdge-0.8.3-edge`, package hash `684ecf12…`, plan hash `sha256:f93da939…` (unchanged) |

Merge result: 30 production files over Edge (SLIP-END-LINES-1, PRINT-OFFLINE-HERE-1), code auto-merged; the only conflict was
`docs/system-map/*` (regenerated from the candidate). One shared-view resolution (the only code change on top of the merge):
PRINT-OFFLINE-HERE-1 had put three `url('/…')` literals into the ONE shared cashier blade `tenant.pos.index`, which the Edge
runtime renders too (`sharedScreen`) — the shared-view contract tests (`SharedPosViewRenderTest`, `EdgeSharedPosRegressionStaticGateTest`)
refuse any URL literal and any runtime branch. Resolution: two new runtime-map route keys `printHealth` (Cloud `/api/pos/print-health`,
Edge `null`) and `printHereTake` (Cloud `/printing/jobs/{job}/print-here`, Edge `null`), the preview through the existing `printDocument`
key, the agent-health pill rendered only where `printHealth` is non-null, the poller exits when the route is null. Cloud behaviour is
byte-for-byte the same endpoints; on the Branch Server (which has no print agent) nothing is rendered or polled. 24 contract tests green.

## 2. What production receives

- **Routes** (`routes/edge.php`, central domain, `edge.device.auth` + throttle): compatibility report, sync sales / reconcile / baseline,
  authority heartbeat / handback, config refresh, returnable / supplier-finance / purchase-return refresh + ingestion, backup recovery keys.
  Tenant settings: Offline Edge security page, pairing-code cancel, device revoke, enrollment assertions. Cloud POS: nothing new beyond production's own tranches.
- **Cloud migrations: 11** (the other 20 files under `database/migrations/edge/` + the manifest are APPLIANCE-only and never run on the Cloud):

| DB | migration | statements | lock / data risk |
|---|---|---|---|
| master | `2026_08_13_000001_create_edge_branch_config_revisions_table` | CREATE `edge_branch_config_revisions`; ADD COLUMN on `edge_bootstrap_snapshots` | small Edge table (0 rows on production) |
| master | `2026_08_13_000002_add_compatibility_report_to_edge_devices` | ADD 2 columns `edge_devices` | 0 rows |
| master | `2026_09_14_000001_create_edge_backup_recovery_tables` | CREATE 2 tables | — |
| master | `2026_09_27_000001_widen_edge_devices_version_columns` | MODIFY 2 columns + ADD 2 columns `edge_devices` (guarded `hasColumn`) | 0 rows |
| tenant ×9 | `create_edge_inbound_sale_ingestions`, `create_edge_branch_authority_leases` (guarded `hasTable`), `create_edge_inbound_return_ingestions`, `…_supplier_finance_ingestions`, `…_purchase_return_ingestions` | CREATE new tables only | no existing table touched |
| tenant ×9 | `2026_10_06_000001_edge_phase4_offline_customer_identity` | ADD columns on `customers` / `customer_addresses` + backfill `phone_normalized` and `address_uuid` in batches of 1000 | the only data migration: row updates on customers; Khatri ≈ hundreds of rows, largest tenants a few thousand → seconds; InnoDB row locks only |
| tenant ×9 | `2026_10_22_000001_add_processing_shift_uuid_to_sales_returns` | `ALTER … ADD COLUMN … ALGORITHM=INSTANT`; `ADD INDEX … ALGORITHM=INPLACE, LOCK=NONE`; `SET SESSION lock_wait_timeout=10` | online DDL; fails fast instead of queueing behind a long transaction |

Rehearsal on disposable copies built from production's own migration set (`be3693e4`: master 49 + tenant 230 → candidate 53 + 237),
log `scratchpad\prodschema.log`: candidate master 4 migrations **0.4 s**, tenant 7 migrations **0.9 s**; second run "Nothing to migrate"
on both; `migrate:rollback --step=7` (tenant) and `--step=4` (master) clean; re-migrate clean. Expected production time: well under one
minute per tenant including the customer backfill; no table rebuild on any sales table.

- **Config**: `EDGE_FEATURE_ENABLED` stays unset/false at deployment (gate 2 off) → the Offline Edge menu stays hidden for every tenant;
  the new Edge API answers 401/403 to anything without a paired device. No tenant behaviour changes until D-B.
- **No seeder change, no queue change, no cron change** for Edge (sync is device-driven). `system:routes-sync` registers the new tenant
  routes (settings/offline-edge.*) into `route_catalogs` as it does today for every deployment.

## 3. Gates (candidate) — see the GO/NO-GO report for results

Critical: `PrintOfflineHereMySqlTest`, `EdgeNoDuplicatePrintAfterSyncMySqlTest`, `ThermalSlipEndMySqlTest`, `EdgeLocalPrintDeliveryMySqlTest`,
`EdgeSyncIngestionHttpMySqlTest`, `EdgeAuthorityLeaseHttpMySqlTest`, `EdgeCompatibilityReportHttpMySqlTest`, `EdgeTenantMigration*`,
`EdgeCleanMachine*`, then the full MySQL suite and the SQLite Feature/Unit suites. Tenant isolation / permissions on the Edge endpoints:
`EdgeLocalAuthMySqlTest`, `EdgeRecoveryAuthorityBoundaryTest`, `EdgeRuntimeCacheIsolationTest`, the device-auth paths in the HTTP tests
(unauthenticated / wrong-device 401, cross-branch refusal) — all part of the suites above.

## 4. Deployment procedure (separate GO; one quiet window, ≈ 20 min)

0. Preconditions: GO/NO-GO report accepted; candidate merged into `feat/14d-2-plan-upgrade-requests` with `Merge KHATRI-CLOUD-EDGE: …`
   (separate GO) and pushed; no other deployment in flight; owner reachable; Khatri not in rush hour.
1. **Backup, verified** (on the server, as today's deployments do): master + all 9 tenant DBs `mysqldump --single-transaction` to
   `/var/backups/<date>/`, sha256 + row counts of `sales`, `sales_returns`, `customers`, `print_jobs`; restore-check of one tenant dump into
   a scratch schema (drop afterwards). STOP if any dump fails.
2. **Pre-flight** (read-only): `git fetch` → the remote head equals the merged commit; `php artisan migrate:status` shows exactly 4 pending on
   master; per tenant `migrate:status --database=tenant --path=database/migrations/tenant` shows exactly 7 pending; `SHOW PROCESSLIST` has no
   long-running transaction on any tenant (the INSTANT/INPLACE DDL waits at most 10 s).
3. **deploy.sh** (unchanged procedure: git pull --ff-only → composer install --no-dev → `migrate --force` (master) → MasterSeeder →
   route:clear + `system:routes-sync` → per tenant migrate + CoA seeder + Owner permissions → permission caches → optimize:clear →
   config/route/view cache → queue:restart → ownership → PHP-FPM reload). Capture the full output. Expected: `tenants ok=9 skipped=0`.
4. **Post-deploy checks** (read-only, 5 min): every tenant login page 200; Khatri POS page 200 with the agent-health pill working
   (PRINT-OFFLINE-HERE-1 unchanged); a Cloud print job on Khatri reaches the agent and prints once; `GET /edge/api/device/me` without a
   device → 401; `POST /edge/api/authority/heartbeat` without a device → 401; Offline Edge menu absent for every tenant (gate 2 off);
   `storage/logs` ERROR/CRITICAL count since deploy = 0; Khatri sales/journals counters still rising.
5. **Record**: deployment record doc (head, migrations run per tenant, timings, checks), system map rebuilt.

## 5. Rollback

- **Code** (any time, 2 min): `git checkout be3693e4` (or `git revert -m 1 <merge>` on the branch) → composer install → caches rebuilt →
  PHP-FPM reload. The 11 new tables/columns are inert under the old code (nothing reads them).
- **Schema** (only if the owner wants the DDL gone): per tenant `migrate:rollback --database=tenant --path=database/migrations/tenant
  --step=7 --force`, master `migrate:rollback --step=4 --force` — rehearsed clean on the copies. The customer backfill columns are
  dropped by their `down()`.
- **Data**: the deployment writes no business rows; the verified backups of step 1 are the last resort (restore one tenant at a time).

## 6. Not part of this deployment

Khatri `offline_edge` enablement, `EDGE_FEATURE_ENABLED=true`, pairing, enrolment (D-B, separate GO after the deployment); the appliance
install (worksheet §6); the LAB acceptance on the 0.8.3 package (D-D); operator assignment (D-E).
