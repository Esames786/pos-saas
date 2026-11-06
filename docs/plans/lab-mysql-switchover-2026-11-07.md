# LAB-only MySQL switchover — dedicated instance 127.0.0.1:3317 (owner "OPTION C", 7 Nov 2026)

Status: **PREPARED, NOT EXECUTED.** Steps 4–7 each need the owner's separate go. No Windows service is installed; the
shared Laragon instance (3306) and the disposable proof instance (3316) are untouched; nothing here is production.

## What exists now (built 6 Nov 22:19–22:24 UTC, record B.13)

| item | value |
|---|---|
| instance | MySQL 8.0.30 (`D:\laragon2\bin\mysql\mysql-8.0.30-winx64`), `C:\Users\Dell\BingooEdgeLab\mysql-lab\conf\my-lab.ini`, **port 3317, bind 127.0.0.1, mysqlx off**, binlog `lab-binlog` (14 d), pid file `mysql-lab\log\lab-3317.pid` (pid 36388 this session) |
| datadir | `mysql-lab\data` — initialised clean (`--initialize-insecure`, 3 benign warnings, 0 errors) |
| data | the three LAB schemas restored from **fresh** dumps taken 22:21 UTC from 3306 (`mysql-lab\dumps\*.sql`, SHA256SUMS verified, `DUMPED_AT`): `pos_lab_master_edge`, `pos_lab_tenant_edge`, `bingoo_edge_lab_local` |
| proof | `CHECK TABLE` 445/445 OK; rowset snapshot (counts + MD5 of sales/outbox/identity tables) identical to the 3306 source at dump time (0 differing lines, `edge_local_meta` excluded as always); 13 financial/sync/identity invariants identical (`mysql-lab\verify\`); 0 `[ERROR]` in `mysqld-lab.err` |
| accounts | `root@localhost` — password in `secrets\mysql-lab-root.pass` (0600, generated, never printed; empty password refused); `bingoo_lab@127.0.0.1` / `@localhost` — password in `secrets\mysql-lab-app.pass`, ALL on the three LAB schemas only |

The clean restore is **recovery evidence** (the logical backups are complete and loadable), not proof that the 3306
instance's InnoDB files are corruption-free — the owner's reading stands.

## Switchover procedure (each numbered step = one owner go; nothing is skipped or merged)

Preconditions: LAB `STANDBY_READY`, authority holder = cloud, outbox drained (`edge:local:sync-status` pending = 0),
no shift open on the appliance, host stable, 3317 running with the proof above.

1. **Freeze** — stop the seven LAB workers by their recorded pids (`Start-EdgeLabWorkers.ps1 -Action Stop`), the FakePrinter
   (`Start-FakePrinter.ps1 -Stop`) and the LAB Cloud (`Start-LabCloud.ps1 -Stop`). The appliance stops writing; nothing
   else writes the LAB schemas (checked with `SHOW PROCESSLIST` on 3306: no connections to the three schemas).
2. **Final backup + consistency** — fresh dumps of the three schemas from 3306 into `mysql-lab\dumps\final\` with SHA256SUMS;
   rowset snapshot `T_sw0` from 3306. Records preserved by construction: every row of `sales_orders`, `sale_payments`,
   `edge_outbox*`, `edge_local_meta`/authority lease, customers and `edge_local_user_credentials` is in the dump
   (`--single-transaction --routines --triggers --events --hex-blob`).
3. **Load into 3317** — `DROP`+re-`CREATE` the three schemas on 3317 (they hold the 22:21 copy, now stale), load the final
   dumps, `CHECK TABLE` all, snapshot on 3317 == `T_sw0`, invariants identical. **Stop here if any line differs** — report the
   differing objects; do not touch 3306.
4. **Repoint the appliance** — back up `data\BingooEdge\config\appliance.env` to `…\appliance.env.pre-3317` and change only
   `EDGE_DB_PORT=3317`, `EDGE_DB_USERNAME=bingoo_lab`, `EDGE_DB_PASSWORD=<from secrets\mysql-lab-app.pass>` (edited by a script
   that reads the secret file; the value is never shown). `EDGE_DB_HOST` stays `127.0.0.1` (the `EdgeLocalDatabase`
   loopback rule). `edge:local:health --json` from the CLI must report the local DB reachable **before** any worker starts.
5. **Repoint the LAB Cloud** — `Start-LabCloud.ps1` hard-codes `DB_PORT`/`TENANT_DB_PORT` = 3306 and `root` + `secrets\dbpw.txt`
   (lines 22–23). Add `LAB_DB_PORT`, `LAB_DB_USER`, `LAB_DB_PASS_FILE` keys to `lab.env` (defaults 3306 / root / dbpw.txt so
   nothing changes until step 5 sets 3317 / bingoo_lab / mysql-lab-app.pass) and make the script read them — a LAB script
   change, committed with the record.
6. **Relaunch in order** — (a) 3317 already up; (b) LAB Cloud (`Start-LabCloud.ps1 -Browsable -Worktree …src-ac5b168d`), wait
   for `/up` 200; (c) FakePrinter; (d) workers (`Start-EdgeLabWorkers.ps1 -Action Start`: gateway → web backends → print →
   sync → authority → backup). Expect: `STANDBY_READY`, lease holder cloud, heartbeat advancing, gateway 200, snapshot on
   3317 == `T_sw0` (+ `edge_local_meta` heartbeat), hourly backup exit 0 on the new port.
7. **Rollback (any failure in 4–6)** — stop what was started, restore `appliance.env.pre-3317` and the `lab.env` defaults, relaunch
   against 3306: the 3306 copies were never written during the procedure. After a successful cut-over the 3306 copies become
   stale the moment the LAB writes on 3317 — keep them read-only as the pre-switch archive; drop only on a later owner decision.

Later, separate elevated approval: a Windows service for 3317 (Agent F's `MYSQL_STARTUP_PLAN`) so the LAB survives a host
reboot without a session relaunch. Until then, after a reboot the instance is started by hand with the `my-lab.ini` line above.

Risk notes: two mysqld processes on the laptop ≈ +300 MB commit (fine after the 4 Nov reboot); port 3317 loopback-only;
the 3316 proof instance is stopped and left as evidence only — never promoted.
