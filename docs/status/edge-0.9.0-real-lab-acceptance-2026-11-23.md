# Edge 0.9.0 r2: REAL LAB acceptance (0.8.2 → 0.9.0), 23 Nov 2026

Owner GO "EDGE 0.9.0 R2 REAL LAB ACCEPTANCE" (23 Nov): controlled real-LAB 0.8.2 → 0.9.0 upgrade only, approved source `3328afb8`,
package `1cbc7d6c…5415`, procedure `LAB-UPDATE-PROCEDURE-R2.txt`.
No takeover, handback, Local Mode or sale was performed. Khatri and production were not touched.
Evidence folder: `D:\BingooEdgeLab-e09\cp6\reallab\` (`evidence\`, `scripts\`, `dumps\`). Times are PKT (UTC+5) unless marked UTC.

## Result

| field | value |
|---|---|
| REAL_LAB_090 | **PASS.** Real LAB on 0.9.0-edge (`3328afb81a10`), STANDBY_READY, "No problems" |
| LAB_HEARTBEAT | Appliance update + worker restart: longest gap **32 s** (lease TTL 120 s). Whole window incl. the Cloud switch: **66 s**. Lease never expired, `fenced_at` never set (324 SQL samples). Online POS gate **open in 372/372** samples (every 3 s, 22:06–22:24 UTC, incl. the update, the restart and the restore-bound measurements on the shared MySQL; 164/164 up to 22:14). Final health 22:24 UTC: STANDBY_READY "No problems" |
| LAB_DATA_INTEGRITY | 445 pre-existing tables compared row by row with the pre-change backup: **432 identical, 0 missing, 13 changed**. All 13 are bookkeeping or approved deploy effects (§5). Business digests identical before and after. 0 duplicates on every natural key, both DBs. 469 tables after (445 + 24 new from the approved migrations) |
| LAB_OUTBOX | Unchanged: acknowledged 5, pending 0, leased 0, permanent failures 0; outbox digest identical |
| LAB_CLOUD_HEALTH | LAB Cloud on 0.9 (`3328afb8` export). 28 migrations (7 master + 21 tenant, the same list as the rehearsed copy), 0 failures, 31.6 s. `/up` 200. Device reports `0.9.0-edge`. Cashier Online POS page 200 |
| LAB_BACKUP_RECOVERY | 3 dumps before any change (sha256 in `A3-dumps.txt`) + pre-update dump + the installer's sealed backup. All three dumps restored into scratch DBs (appliance DB 9.4 s). Rollback script prepared; it was **not needed** |

## 1. Pre-flight (all passed)

| check | result |
|---|---|
| A1 quiet host | no test suite, no Sandbox (`wsb list` empty); 10.7 GB free RAM; C: 49 GB / D: 90 GB free. The disposable LAB copy was **stopped** first (cooperative stops + recorded pids; copy Cloud 1824, FakePrinter 668) |
| A2 health | 0.8.2 STANDBY_READY "No problems"; authority standby/online, failures 0; outbox 0 pending / 0 permanent / 5 acknowledged |
| A3 backups | `mysqldump --single-transaction` of `pos_lab_master_edge` 132,717 B / `pos_lab_tenant_edge` 431,021 B / `bingoo_edge_lab_local` 632,182 B (no `USE`/`CREATE DATABASE` lines). Restored into scratch DBs `pos_reallab_base_*` / `bingoo_edge_reallab_base_local`: 1.9 s / 8.5 s / 9.4 s, table counts equal. 445-table fingerprint. Hourly appliance backups present |
| A4 runtime | pointer `0.8.2-edge`; `runtime\versions` holds 0.6.0…0.8.2, no 0.9.0 folder |
| A5 package | zip sha256 `85bbadad…c5d5` (release-r2 copy = custody copy); manifest git `3328afb8`, package_hash `1cbc7d6c…5415`, artifact `2fd9ff47…`, 9232 files, build audit ok. The LAB's trusted update key fingerprint = `a2eaf8a3d3e66a48` (the release key) |
| Cloud scope | read-only `migrate:status` + `--pretend` with the 0.9 export against the LAB DBs: master +7, tenant +21. Identical to the rehearsed copy. The two tables with non-additive bodies hold 0 rows on the LAB (`receipt_layout_settings`, `product_pack_units`) |

## 2. Cloud first (B2–B5): 02:57:17–02:57:52

- The LAB Cloud stopped at 02:57:17 (pid 29332, code `src-ac5b168d`).
- `lab-cloud-first.php apply` ran deploy.sh steps 3–5 against the LAB DBs only (guarded by DB name):
  - master migrate +7, MasterSeeder, route:clear + routes-sync (862);
  - tenant migrate +21, DefaultChartOfAccountsSeeder, 699 tenant route permissions (no Owner role on the LAB tenant, so no grants);
  - 31.6 s, rc 0 everywhere.
- The LAB Cloud restarted on `cloud\src-3328afb8` (git archive + copied no-dev vendor, composer.lock identical, `installed.json dev=false`,
  0 reparse points); `/up` 200 at 02:57:52.
- The unchanged 0.8.2 appliance recovered at the first tick: one refused beat, then acks from 21:58:00 UTC. Longest gap 66 s; lease never expired.
  The config refresh moved revision 9 → 10 with a stock cutover; health STANDBY_READY "No problems" (`B5-health-082-on-cloud-090.txt`).

## 3. Appliance update (C) and worker restart (D)

- **C1 dry run** (0.8.2 launcher):
  - verdict OK; plan_hash `sha256:8ef6ca9e…1b21` = the reviewed hash; blockers [];
  - plan section byte-identical to the rehearsed copy's r2 dry run.
- **C3 apply** 03:07:30 → 03:12:48 (5 m 18 s):
  - result `applied`, plan hash equal, 19 migrations run + the approved order-note substitute (218 → 238), skipped 0;
  - installer's sealed pre-update backup `edge-backup-20261122_220732-8kknsl.enc`; extra pre-update dump `pre-update-…-20261122T220728Z.sql`
    (sha256 `c6c8afd2…`).
  - The 0.8.2 workers kept heartbeating through the update.
- **D** 03:13:06 → 03:13:40 (34 s):
  - print-worker and authority-worker stopped gracefully;
  - recorded pids stopped (the LAB stop script aborted on an already-exiting nginx child, as known; the remaining recorded pids were stopped
    by taskkill /PID; 0 left alive);
  - `.082` copies of the plan and nginx.conf kept;
  - plan re-rendered by 0.9: 7 tasks incl. `BingooEdgeUpdater` every 15 min;
  - `nginx -t` ok; 8 workers started; ports 8443/8081/8090/8091 listening.
- **Two run-time incidents (harness only, no LAB effect):**
  1. The first launch stopped at its second log line, because a log watcher held the timeline file open. Only the pre-update dump had been taken;
     the LAB was verified unchanged and the update relaunched.
  2. After `applied`, the script misread the result: the 0.8.2 `--json` output ends with a human summary line, so PowerShell's JSON parse failed,
     and the script stopped before D. D was started by hand 18 s later. This put 0.8.2 workers on the migrated schema for 18 s (additive, harmless, as
     on the copy); the heartbeat gap stayed 32 s.
- **F-2 note:** the 0.9 gateway renderer still writes `return 301 https://$host$request_uri;` (drops the non-443 port). The LAB's hand fix F-2
  (`https://$host:8443`) was re-applied after the render, as the runbook says; HTTP 8081 now redirects to `https://…:8443/…` (verified).
  Product follow-up for 0.9.1: `EdgeSupervisionPlan` should include the HTTPS port when it is not 443. This affects any branch on :8443
  (Khatri too: its counters use the https :8443 links, so it is not blocking).

## 4. Verification (E)

| check | result |
|---|---|
| version / health | `edge:local:health`: Edge 0.9.0-edge · `3328afb81a10`; STANDBY_READY "No problems"; authority standby/online, epoch 1, failures 0; Update "active 0.9.0-edge · verify key ok · last applied"; Auto failover "no" |
| TLS | `https://DESKTOP-0024EPM.local:8443/edge/local/health` 200, `ssl_verify=0` (LAB CA, verified, never -k). HTTP :8081 → 301 `https://desktop-0024epm.local:8443/edge/local/login` |
| counter link + cashier login | login page 200 with CSRF; LAB2C5D login 302 → `/edge/local/pos`; `/edge/local/status` 200 (employee_code LAB2C5D); POS screen 200 (576 KB); logout 302. Credential read from the secrets file into env, never printed |
| Cloud POS | LAB Cloud cashier login → `/pos` 200 (Online POS, Branch Server banner); the Online POS write gate open in every sample. `/settings/offline-edge` answers 403 "Module Not Available" for the LAB cashier (no admin credential on the LAB). The approver's stored Cloud password was refused once (not retried). The wizard UI itself was proven at CP5/CP6 in the Sandbox |
| wizard / update controls (appliance) | 0.9 plan includes the updater task. It ran at 03:13:40: "up_to_date [0.9.0-edge]". The LAB Cloud has **0** published releases, so auto-update stays on hold. Self-enrol endpoint off (env flag absent, default false) |
| auto-takeover | Cloud `branches.edge_auto_takeover_enabled = 0` (never set); appliance env has no `EDGE_AUTO_TAKEOVER_ALLOWED` (default false); every authority tick: decision `disabled` (CLOUD_SETTING_OFF, APPLIANCE_NOT_ALLOWED) |
| printers / authority config | `printers`, `category_printer_mappings`, `terminal_printer_settings`, `terminals` identical on both DBs; `edge_local_print_deliveries` identical; `edge_local_meta` authority state, epoch, device and holder unchanged (only heartbeat/refresh stamps moved); Cloud lease row: only seq/heartbeat/expiry moved |
| financial records | no sale, payment, shift, order, print job or KOT created on either DB. Counts and digests identical before and after (`reallab-integrity-1/2`) |
| mirror tables | `edge_mirror_*` present (meta 1 row; shifts/orders/held/tables 0: the LAB Cloud has no open shift) |

## 5. The 13 changed tables (445-table comparison, `E4-all-tables-vs-baseline.txt`)

| table | change | why |
|---|---|---|
| master `edge_devices` | app_version → 0.9.0-edge, compatibility/build report, last_authenticated_at (+ new NULL columns) | device telemetry |
| master `languages`, `payment_gateways` | created_at/updated_at only | MasterSeeder re-touch (deploy step 3b) |
| master `plan_modules` | +9 rows (new `pharmacy_demo` plan); `customer_payments` disabled on 4 plans incl. `standard` (the LAB tenant's plan) | MasterSeeder aligns the LAB master with the code's plan definition. Production already has these 4 rows disabled (21 Nov deploy: "values unchanged") |
| master `role_has_permissions` | +17 | MasterSeeder central permissions (+17 `permissions`, +1 `plans`, +4 `plan_features`, +862 `route_catalogs`, +660 tenant `permissions` definitions with **no new grants**) |
| tenant `edge_branch_authority_leases` | seq / heartbeat / expiry | heartbeats |
| edge `edge_local_authority_worker_state`, `edge_local_print_worker_state` | worker restart (+ new process-identity columns) | D step |
| edge `edge_local_meta` | config revision 9 → 10, refresh stamps, schema version, heartbeat counters | Cloud refresh + update |
| edge `edge_local_backups` | 2 oldest ledger rows rotated out, 2 new (24 kept) | the appliance's own hourly retention |
| edge `edge_local_user_credentials` | last_authenticated_at of the cashier | my login check |
| edge `edge_operational_stock_balances` / `_baselines` | 15 rows re-keyed to baseline 8, previous baseline superseded | stock cutover at the config refresh. Quantities identical: same digest of (branch, product, variant, qty), total 732.000 |

## 6. KHATRI_RESTORE_TIME: conservative bound (measured, nothing on Khatri touched)

The 0.9 rollback (K6) restores the appliance DB with `EdgeDatabaseSnapshot::restore()`: it decrypts gzip+JSON batches and re-inserts them
through Laravel, with every table dropped and re-created. It is **not** a mysql import. Measured with that exact class (0.9 code, throwaway key) on scratch
copies of the LAB appliance DB (209 tables), inflated to stated volumes (`khatri-restore-bound.php`, `K-restore-bound-*.txt`):

| scale | content | rows in snapshot | snapshot file | snapshot | **restore** |
|---|---|---|---|---|---|
| smoke | LAB data + 200 mirrored sales | 4,330 | 0.6 MB | 0.7 s | **11.0 s** |
| high | 7,000 mirrored returnable sales × 6 lines (+ maps, payments), 1,000 products, 10,000 customers | 116,930 | 8.3 MB | 3.9 s | **39.2 s** |
| pessimistic | 21,000 sales × 6 lines (+ maps, payments), 3,000 products, 30,000 customers | 348,930 | 24.0 MB | 10.5 s | **54.8 s** |

On this laptop, about 10 s is fixed (209 tables of DDL) and the rest grows with rows: 39 s at 117k rows, 55 s at 349k.

The Sandbox rollback gap was 80 s with a ~9 s restore, so about 71 s of the gap is fixed. Against the 120 s lease, the restore budget is therefore
**≈ 45 s**.

**What decides Khatri:** the appliance mirrors the Cloud's returnable sales for **14 days** (`EDGE_RETURNS_CACHE_WINDOW_DAYS`). Most of its rows
are those orders and lines, plus the catalogue and customers.
- Up to ~500 orders/day (7,000 mirrored sales): restore 39 s, rollback gap ≈ 110 s. Within the lease.
- At a pessimistic 1,500 orders/day: restore 55 s, rollback gap ≈ 126 s. That is 6 s over the lease, so the **rollback path only** could pause
  Online POS for a few seconds.
- A slower PC than this laptop scales these figures up.
- The successful update path keeps heartbeating throughout (LAB: 32 s gap). The restore runs only when an update fails its health check.

**Conservative bound: Khatri restore ≤ ~55 s at this laptop's speed for up to 1,500 orders/day (rollback-path gap ≤ ~126 s).**

**Closing the bound needs two read-only numbers, no mutation:**
1. Khatri's Cloud sales count for the last 14 days (owner: Sales report on the Cloud).
2. The size of the newest file in Khatri's appliance backups folder (Explorer).

**Mitigation in any case:** run the Khatri update in a closed window, so a rollback pause cannot cost sales.

## 7. State left

- **Real LAB:** 0.9.0-edge STANDBY_READY, 8 workers (pid record `data\lab-workers.json`; the 0.8.2 record kept as
  `lab-workers.082-before-090-20261122T221306Z.json`).
- **LAB Cloud:** on `src-3328afb8` (pid 29308). Gateway `.082` copies kept.
- **Disposable LAB copy:** stopped.
- **Scratch DBs kept:**
  - `pos_reallab_base_*`, `bingoo_edge_reallab_base_local` (the baseline);
  - `bingoo_edge_khatribound_{smoke,high,pessimistic}_local`.
  - All of them are disposable.
- **Rollback (not needed):** `scripts\Invoke-LabRollback.ps1` is ready, with source `dumps\PRE-UPDATE-DUMP.txt`.
