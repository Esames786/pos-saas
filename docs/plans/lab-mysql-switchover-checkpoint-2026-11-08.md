# LAB MySQL switchover — FINAL CHECKPOINT for the attended window (owner decision B.15, 8 Nov 2026: **3317 selected**)

Status: **PREPARED, NOT EXECUTED.** Nothing is frozen, nothing is switched. The owner opens the attended maintenance window
with an explicit "WINDOW OPEN — Step 1 go" while present; every later step has its own go. Procedure of record:
[lab-mysql-switchover-2026-11-07.md](lab-mysql-switchover-2026-11-07.md) (reviewed, unchanged). This page is the
pre-flight + the exact commands + the stop/rollback points, so the window itself is reading, not deciding.

Why 3317: the shared Laragon instance (3306, `D:\laragon2\data\mysql-8\mysqld.log`) still carries unresolved InnoDB
errors — 30 Oct 13:50 UTC `[FATAL] Error 100 … writing to the redo log file` (the crash) and 4 Nov 09:48 UTC
`Tablespace 'innodb_system' / 'innodb_undo_001' … log sequence number … is in the future! … Your database may be corrupt`
(×7). It is also shared with every test suite on this host (at the 17:15 UTC baseline: two foreign MySQL suites migrating
`pos_test_tenant_dsf/dsm`, a golden-restore `INSERT` on `pos_golden_khatri_prod`, Threads_running 6, host CPU 100 %).
The owner forbids demo financial transactions on it. 3306 itself is **never modified, rebuilt or stopped** by this work.

## 1. Baseline refreshed 8 Nov 17:11–17:20 UTC (read-only; re-run as the pre-flight — §3)

| check | value at baseline | pre-flight requirement |
|---|---|---|
| LAB health (verified TLS, `scripts\labhttp.php`) | `200 {"status":"ok","edge_app_version":"0.8.2-edge",…}` | 200 |
| authority (`bingoo_edge_lab_local.edge_local_meta`) | `standby`, failures 0, acks 119 → advancing, last ack 17:11:09 → 17:18:02 UTC | `standby`, last ack < 90 s old |
| outbox (`edge_sync_outbox.state`) | acknowledged 5, pending/leased/failed 0 | pending + leased = 0 |
| open shifts (`shifts.status='open' OR closed_at IS NULL`) | 0 | 0 |
| sales on the appliance / on the LAB tenant | 6 (last 26 Sep 2026) / 5 | unchanged from pre-flight to T_sw0 |
| hourly backup (`data\BingooEdge\backups`) | `edge-backup-20261108_162247-goyryn.enc` 16:22 UTC, 371 723 B (same size as 15:48, 14:48) | newest ≤ 65 min old |
| 3306 connections to the 3 LAB schemas | 2 (root, `bingoo_edge_lab_local`) — the appliance | only the appliance; 0 after the freeze |
| workers (`Start-EdgeLabWorkers.ps1 -Action Status`) | 7/7 alive: gateway master 31556 (worker 5168 since the F-2 reload), Web1 28292 → php -S 43024, Web2 10400 → php -S 36532, Print 11784, Sync 21072, Authority 21028, Backup 11880; LAB Cloud 21180 (:9701); FakePrinter 45676 | 7/7 alive, pid file matches |
| **OBS-2** web backends | both php -S children were recycled once at 14:16 UTC — supervisor reason `hung: 5 liveness probes failed (progress continued but no answer for 144/145 s)`; `last_probe_ms` 4 495–5 003; health via gateway 6.7–7.3 s, direct 1.7–9 s **while the host sat at 100 % CPU from other sessions' Playwright + MySQL suites**. Appliance logic unaffected; it is a host-load symptom and one more reason not to open the window under foreign load. | health via gateway < 2 s, host CPU < 50 % for 2 min |
| 3317 (`mysql-lab\log\lab-3317.pid` 36388 = monitor, server 41388, since 6 Nov 22:20 UTC) | up 154 359 s, 0 `[ERROR]`; schemas `pos_lab_master_edge` 51 tables, `pos_lab_tenant_edge` 185, `bingoo_edge_lab_local` 209 (the 6 Nov copy — **stale by design**, replaced in Step 3); accounts `root@localhost`, `bingoo_lab@127.0.0.1`/`@localhost` | reachable with the secret-file password, 0 new `[ERROR]` |
| 3306 | up 372 390 s, LAB schemas intact | no `[ERROR]` newer than 4 Nov in `mysqld.log` |

Secrets used by the window: `secrets\mysql-lab-root.pass`, `secrets\mysql-lab-app.pass` — read into `MYSQL_PWD` / the
edit script from the files, never on argv, never printed. The owner never needs to type either.

## 2. Window shape (≈ 30 min; the laptop cannot use the Branch Server during it)

| step | go | wall time | writes | stop point |
|---|---|---|---|---|
| 0 pre-flight (§3) | — (read-only) | 3 min | none | any row ≠ requirement → do not open |
| **1 FREEZE** (§4) | **owner** | 5 min | stops processes only | **STOP, report §4.5, wait** |
| 2 final dumps + T_sw0 from 3306 | owner | 5 min | files under `mysql-lab\dumps\final\` | any dump error → unfreeze (§6) |
| 3 load into 3317 + verify | owner | 8 min | 3317 only | any differing line → unfreeze (§6), 3306 untouched |
| 4 repoint appliance.env | owner | 2 min | `data\BingooEdge\config\appliance.env` (backup kept) | `edge:local:health` must see the local DB |
| 5 repoint LAB Cloud (lab.env keys + script) | owner | 3 min | `lab.env`, `Start-LabCloud.ps1` (committed with the record) | — |
| 6 relaunch in order + verify | owner | 5 min | — | any failure → rollback (§7) |

## 3. Pre-flight (read-only, ≤ 3 min before "Step 1 go")

```powershell
# host + LAB (run from any shell; nothing here writes)
& 'D:\laragon2\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' C:\Users\Dell\BingooEdgeLab\scripts\labhttp.php GET https://DESKTOP-0024EPM.local:8443/edge/local/health
& C:\Users\Dell\BingooEdgeLab\scripts\Start-EdgeLabWorkers.ps1 -Action Status
(Get-Counter '\Processor(_Total)\% Processor Time' -SampleInterval 5 -MaxSamples 3).CounterSamples.CookedValue
Get-ChildItem C:\Users\Dell\BingooEdgeLab\data\BingooEdge\backups | Sort-Object LastWriteTime -Descending | Select-Object -First 1 Name,Length,LastWriteTime
```
```bash
M=D:/laragon2/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe
"$M" -uroot -h127.0.0.1 -P3306 -N -e "SELECT authority_state,heartbeat_consecutive_failures,heartbeat_consecutive_acks,authority_last_ack_at,UTC_TIMESTAMP() FROM bingoo_edge_lab_local.edge_local_meta;
SELECT state,COUNT(*) FROM bingoo_edge_lab_local.edge_sync_outbox GROUP BY state;
SELECT COUNT(*) FROM bingoo_edge_lab_local.shifts WHERE status='open' OR closed_at IS NULL;
SELECT COUNT(*),MAX(created_at) FROM bingoo_edge_lab_local.sales_orders; SELECT COUNT(*) FROM pos_lab_tenant_edge.sales_orders;
SELECT db,user,COUNT(*) FROM information_schema.processlist WHERE db IN ('pos_lab_master_edge','pos_lab_tenant_edge','bingoo_edge_lab_local') GROUP BY db,user;
SELECT COUNT(*) FROM information_schema.processlist WHERE command<>'Sleep' AND db NOT IN ('pos_lab_master_edge','pos_lab_tenant_edge','bingoo_edge_lab_local') AND info NOT LIKE '%processlist%'"
export MYSQL_PWD="$(tr -d '\r\n' < C:/Users/Dell/BingooEdgeLab/secrets/mysql-lab-root.pass)"; "$M" -uroot -h127.0.0.1 -P3317 -N -e "SHOW GLOBAL STATUS LIKE 'Uptime'"; unset MYSQL_PWD
grep -c '\[ERROR\]' C:/Users/Dell/BingooEdgeLab/mysql-lab/log/mysqld-lab.err   # expect 0
```
Open the window only if every row meets the requirement column of §1 **and** the last query (foreign active statements on
3306) is 0 for two consecutive samples 60 s apart. Otherwise report "pre-flight red: <row>" and do not freeze.

## 4. Step 1 — FREEZE (owner go: "WINDOW OPEN — Step 1 go")

1. `T_pre` — rowset snapshot of the three schemas from 3306 (the `mysql-lab\verify\check.sql` method of 6 Nov: per-table
   counts + MD5 of the sales / payments / outbox / identity tables, `edge_local_meta` excluded) → `mysql-lab\verify\final\T_pre.txt`.
2. Stop, in this order, only by recorded pids (each script kills its own recorded pid tree, nothing by name):
   `Start-EdgeLabWorkers.ps1 -Action Stop` (7 workers; the gateway closes :8443 → the laptop sees "connection refused", expected),
   `Start-FakePrinter.ps1 -Stop`, `Start-LabCloud.ps1 -Stop`.
3. Verify the freeze: `Start-EdgeLabWorkers.ps1 -Action Status` → 0 alive, `:8443/:8090/:8091/:9701` CLOSED; the 3306
   processlist shows **0** connections to the three LAB schemas; `Get-Process -Id <the 7 recorded pids>` → none.
4. `T_sw0` — the same snapshot again; **must equal `T_pre`** (nothing wrote after the stop). `edge_local_meta` is copied
   verbatim (authority state, lease facts, heartbeat counters — preserved in the dump of Step 2).
5. **STOP.** Report: `FROZEN_AT=<UTC>`, `T_sw0=<sha256 of the snapshot file>`, `PIDS_STOPPED=<list>`, `LAB_CONN_3306=0`,
   `3306_UNCHANGED=yes`. Wait for "Step 2 go". If the owner cannot stay, or anything in 4.3–4.4 is off → §6 unfreeze.

Nothing in Step 1 touches a database. The LAB is unavailable from the freeze until Step 6 completes (or §6 unfreezes).

## 5. Steps 2–6 (each its own go; commands as in the procedure of record)

- **2** `mysqldump --single-transaction --routines --triggers --events --hex-blob` of the three schemas from 3306 →
  `mysql-lab\dumps\final\<schema>.sql` + `SHA256SUMS` + `DUMPED_AT`; stderr files must be empty. Snapshot `T_sw0` is the
  reference for every later comparison.
- **3** on 3317: `DROP DATABASE` + `CREATE DATABASE` for the three LAB schemas (they hold the stale 6 Nov copy), load the final
  dumps, `CHECK TABLE` all (expect 445/445 OK), snapshot on 3317 == `T_sw0`, invariants (`check.sql`) identical to 3306.
  **Any differing line → stop, report the objects, §6 unfreeze; 3306 is never written.**
- **4** `appliance.env` → copy to `appliance.env.pre-3317`; change only `EDGE_DB_PORT=3317`, `EDGE_DB_USERNAME=bingoo_lab`,
  `EDGE_DB_PASSWORD=<read from secrets\mysql-lab-app.pass by the edit script>`; `EDGE_DB_HOST` stays `127.0.0.1`. Then, with
  the workers still stopped, the runtime CLI `edge:local:health --json` must report the local DB reachable on 3317.
- **5** `lab.env` gains `LAB_DB_PORT=3317`, `LAB_DB_USER=bingoo_lab`, `LAB_DB_PASS_FILE=secrets\mysql-lab-app.pass`;
  `Start-LabCloud.ps1` lines 22–23 read them (defaults 3306 / root / dbpw.txt). Script + record committed to the worktree.
- **6** relaunch: LAB Cloud (`Start-LabCloud.ps1 -Browsable -Worktree <the export used on 5 Nov>`) → `/up` 200; FakePrinter;
  `Start-EdgeLabWorkers.ps1 -Action Start`. Expect within 3 min: health 200 via the gateway, `STANDBY_READY`, authority
  `standby` with the heartbeat advancing on **3317**, outbox pending 0, snapshot on 3317 == `T_sw0` (+ `edge_local_meta`
  heartbeat columns), laptop `https://DESKTOP-0024EPM.local:8443` logs in, next hourly backup exit 0.

## 6. Unfreeze (Step 1 cannot safely remain, or a failure in Steps 2–3)

Relaunch against the untouched 3306 in order: `Start-LabCloud.ps1 -Browsable -Worktree …` → `/up` 200; `Start-FakePrinter.ps1`;
`Start-EdgeLabWorkers.ps1 -Action Start`; verify health 200, `STANDBY_READY`, snapshot == `T_sw0`. Report `UNFROZEN_AT`, reason.

## 7. Rollback (a failure in Steps 4–6)

Stop what was started (recorded pids), restore `appliance.env.pre-3317` and the `lab.env` defaults, relaunch as §6. The 3306
copies were never written during the window, so the rollback is a relaunch, not a restore. After a **successful** cut-over the
3306 copies are a read-only pre-switch archive from the first write on 3317; they are dropped only on a later owner decision.

## 8. Not in this window

No Windows service for 3317 (needs separate elevated approval; after a host reboot 3317 is started by hand with
`mysqld.exe --defaults-file=C:\Users\Dell\BingooEdgeLab\mysql-lab\conf\my-lab.ini`), no Local Mode / takeover / handback, no
release install, no change to 3306, no production.
