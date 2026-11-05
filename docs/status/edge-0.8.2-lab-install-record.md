# Bingoo Edge 0.8.2-edge — CONTROLLED LAB INSTALLATION + ACCEPTANCE PREREQUISITES record (owner authorization of 30 Oct 2026, Phases A–D)

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.2-edge\lab-install\`. Package `C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.2-edge` (built 30 Oct
09:26 UTC from a6113772; reused unchanged). Hotfix content and proofs: `edge-0.8.2-hotfix-record.md`. Separate execution gates: a failure in one phase never triggers a
mutation in another. Boundaries kept: no Local Mode, no takeover/handback, no WAN test, no production, no Khatri/Kashif/Tawakal, no LAB reboot, no P6.

## Phase A — install 0.8.2 on the real LAB

### A.1 Package identity (before any mutation) — `01-package-identity.json`, `01-audit-package.json`

Signature valid against the release public key (foreign key refused), key id `a2eaf8a3d3e66a48` = pinned; payload 0.8.2-edge / `source_revision a6113772de642f4f562646138805adcd85472938`
/ artifact hash `7771b1ab…9946` recomputed from disk = signed; package-manifest.json version 0.8.2-edge, git_commit a6113772…, release, source_dirty false,
`package_hash 1455cf1a65d4b2c6c9a4e6abbdfc3537d2921e4948558774758ec60b872fe808`, file_count 8999; `edge:audit-package` ok=true (missing 0, unlisted 0, tampered 0,
package_hash_ok, boundary ok). IDENTITY=MATCH.

### A.2 Process-isolation preflight — `02-process-preflight.txt`

REAL_LAB 10 processes / DISPOSABLE 0; pid record exact (Gateway 400800 (+nginx worker 356300), Web1 414556 (child php -S 400840 :8090), Web2 364668 (child 372536 :8091),
PrintWorker 412556, SyncSender 405264, AuthorityWorker 366572, Backup 363064; the rest conhost). Stop/start = `Start-EdgeLabWorkers.ps1 -Action Stop|Start` (nginx quit by plan
path, `taskkill /PID <recorded> /T /F`, record removed/rewritten). Host quietness gate: at 11:08 UTC another project's 8-worker paratest run (seera, not ours) held the CPU at
97–100 % — the LAB mutation was held until that run ended (waiter `bo2wwg4rw`: no paratest/phpunit/Playwright/stress process and CPU < 60 % for a full minute).

### A.3 Baseline — `03-baseline-snapshot.txt`, `03-baseline-health.json`, `03-baseline-lease.txt` (11:07 UTC)

```
0.8.1-edge (5f728d4f), bootstrap v8, STANDBY_READY, standby/online, acks 75, config rev 9 = 9, stock ok, AUTO_FAILOVER false, problems []
CLOUD_SALES=5 (lines 20, ingestions 5)   APPLIANCE_SALES=6 (lines 25)   OUTBOX 5 acknowledged / 0 pending / 0 leased / 0 failed   MIGRATIONS=218 (Cloud 217/49)
customers 2/0 aliases, open shifts 0, stock movements 17; Cloud /up 200; lease holder cloud / standby / seq 49108 / not fenced / not released
rowset MD5s: appliance sales 9bbd2ad24c0884518c745ebc9bb97d39 · lines cc04a3e402c28b504cba71e1f08e4076 · outbox c9c08b1d74a7c806fa78f79d608a23e2 · customers e42077b9b220fdf9d3650c4203b9f7ff ·
  Cloud sales 0dac7dbb85d26e0f71db67519cbe251c · Cloud lines 49bb4ea13933207a1fcaedda8d43361e · Cloud customers e39a8d18f40625ad27b327053bef5288  (all identical to the 29 Oct baseline)
0.8.1 supervisors on the LAB: restarts 81 / 77 (F-FB5-1 false restarts under host load, children always serving)
```

### A.4 Pre-update backup — `data\backups-pre-0.8.2-20261030T110818Z\`

Dumps `bingoo_edge_lab_local` (618,097 B), `pos_lab_tenant_edge` (431,020 B), `pos_lab_master_edge` (132,717 B), all with empty .err; state: `current` (0.8.1-edge), appliance.json,
service-plan.json, lab-workers.json, lab.env, config\appliance.env, Start-LabCloud.ps1 (pre-Phase-B original), gateway dir. Restore-verified into `bingoo_edge_lab_verify_pre082`:
218 migrations, 6 sales, sales MD5 9bbd2ad2… and outbox MD5 c9c08b1d… identical. Earlier sets untouched.

### A.5 Dry-run through the REAL 0.8.1 launcher — `05a-dry-run.json`, `05b-audit-after-dry-run.json` (11:10:47–11:11:05 UTC)

The installed launcher offers `--dry-run` and `--expected-plan-hash`. Result: from 0.8.1-edge to 0.8.2-edge, plan entries [] / run [] / skip [] / blockers [], verdict OK,
**plan_hash sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95 = approved**; the package's bootstrap\cache still holds only .gitkeep (PACKAGING-DRYRUN-IMMUTABLE-1);
re-audit ok=true, unlisted 0.

### A.6 Stop, apply, start — `05c-*`, `05d-apply.json`, `05e/05h-health-*.json`, `05f-*`, `05i-staged-runtime-integrity.txt`

| step (UTC) | result |
|---|---|
| 11:57 host state | CPU 85 %, one external single-process PHPUnit (seera) still running — not a browser/stress suite; the 8-worker paratest that pinned the CPU at 100 % from 11:08 had ended; no Playwright, no load clients |
| 11:57:47 stop (`Start-EdgeLabWorkers.ps1 -Action Stop`) | nginx quit (gateway 400800 gone); the script aborted on that already-gone pid exactly as on 23 Oct, so the six remaining RECORDED roots and their in-scope children were stopped by pid (`finish-stop-082.ps1`: Web1 414556 + child 400840, Web2 364668 + 372536, Print 412556, Sync 405264, Authority 366572, Backup 363064, their conhosts) → 0 install-root processes, 8443/8081/8090/8091 closed, pid record moved to evidence, LAB Cloud 9701 untouched |
| 12:00:07–12:08:11 apply (`install\BingooEdge\artisan edge:local:update <pkg>\update\edge-update-0.8.2-edge.json <pkg>\app --expected-plan-hash=sha256:20a8aad4… --json`) | **applied**, from 0.8.1-edge to 0.8.2-edge, pre_update_backup `edge-backup-20261030_120015-tohn93.enc`, schema_after edge-config-v1, migrations_run [], migrations_skipped []; pointer → **0.8.2-edge** (versions 0.6.0 / 0.7.0 / 0.8.0 / 0.8.1 / 0.8.2); migrations **218 → 218**; `edge_local_updates` #6 = 0.8.1 → 0.8.2 applied, rollback none; staged `versions\0.8.2-edge` vs the signed manifest 8,898 app files, 0 differing; the package stayed pristine (.gitkeep only), the ACTIVE runtime keeps its caches in its own bootstrap\cache |
| 12:09:19–12:09:24 start (`-Action Start`) | 7/7 recorded: Gateway 432188 (worker 343204, 0.0.0.0:8443/8081), Web1 432812 (child 430124 :8090), Web2 432372 (child 412188 :8091), PrintWorker 241672, SyncSender 411352, AuthorityWorker 414892, Backup 432908 (the session's own verification script was interrupted by a session break and re-done at 13:02 read-only) |
| 13:02 health | **STANDBY_READY**, 0.8.2-edge (a6113772, release), bootstrap v8, schema_compatible true, update.last 0.8.1→0.8.2 applied, authority standby/online (acks 352, heartbeats resumed on the first tick after start), config 9/9, stock ok, print running, authority worker running, gateway https listening, AUTO_FAILOVER false, problems []; web#1/#2 listening + responding, availability **responding**, supervisor HEALTHY, restarts **0**, since_ok 0 |
| status files | both carry the new policy block: probe read 10 s, interval 5 s, busy grace 120 s, 5 misses, hang bound 205 s, back-off 1–30 s, failing 10/600 s |

### A.7 Bounded real-LAB HTTP acceptance — `06-http-modest-regression-082.json`, `07-after-snapshot.txt`, `07-after-lease.txt` (13:03–13:06 UTC, host CPU 97 → 61 %)

```
CASHIER_BACKEND_1_HEALTH=70/70 HTTP 200 (60 health + 10 login-page renders), health p50 751 ms / p95 1,449 ms / max 1,985 ms, login page p50 791 ms / max 1,583 ms, 0 timeouts, child pid 430124 unchanged, restarts 0 → 0, HEALTHY
CASHIER_BACKEND_2_HEALTH=70/70 HTTP 200, health p50 719 ms / p95 1,374 ms, login page p50 799 ms / max 1,014 ms, 0 timeouts, pid 412188 unchanged, restarts 0 → 0, HEALTHY
GATEWAY=https://127.0.0.1:8443 20/20 HTTP 200 (curl), LAN name 200; nginx access log: 0 × 502 since the 12:09 start (the 52 historical 502s in the log are the 0.8.1 era)
FALSE_SUPERVISOR_RESTARTS=0 — zero restarts on both backends from 12:09 to 13:06 UTC (57 min) although the host sat at 85–97 % CPU for most of it; supervisor task logs contain no "restart #" line (0.8.1 had logged 81/77 by then)
UNEXPECTED_GATEWAY_502=0
DATA=identical to the baseline: every counted and hashed rowset (sales, lines, outbox, customers, Cloud sales/lines/customers/ingestions) unchanged; only the tick-written edge_local_meta checksum moved
AUTHORITY=Cloud lease holder cloud / standby, seq 49108 → 49396, fenced_at NULL, released_at NULL; appliance standby/online; AUTO_FAILOVER false
```

```
PHASE A CHECKPOINT
LAB_VERSION=0.8.2-edge (a6113772)        BACKUP_VERIFIED=YES (backups-pre-0.8.2-20261030T110818Z restore-verified + installer backup edge-backup-20261030_120015-tohn93.enc)
PACKAGE_IDENTITY=MATCH (signature, key a2eaf8a3d3e66a48, commit, hash 1455cf1a…, audit ok ×3)     MIGRATIONS=0 run / 0 skipped / 218 after
WEB_BACKENDS=2/2 HEALTHY, responding, policy visible   FALSE_RESTARTS=0   GATEWAY_ERRORS=0   SALES_UNCHANGED=YES   OUTBOX_UNCHANGED=YES   STANDBY_READY=YES
```
Phase A green → Phase B proceeds.

## Phase B — browsable LAB Cloud (executed 30 Oct 13:09 UTC; verified 20:09 UTC) — `08a-*`, `08b-*`, `10-*`

Pre-change facts (read-only): the real master `pos_lab_master_edge.tenant_domains` ALREADY holds `id 1, tenant 1 (edgehomelab), edgehomelab.localhost, kind app, is_primary 1,
status active` (unique index on domain) — Agent A's suspicion that the row was missing was wrong; **no insert was made** (TENANT_DOMAIN_ROW=already present, unchanged).
Configuration backup: the original `Start-LabCloud.ps1` and `lab.env` are in `backups-pre-0.8.2-20261030T110818Z\state\`. The change itself: `Start-LabCloud.ps1` gained a
`-Browsable` switch (default behaviour unchanged, parse-checked); with it the SAME export `cloud\src-ac5b168d` is served on the SAME loopback listener 127.0.0.1:9701 with exactly
four differences — `TENANT_BASE_DOMAIN=localhost`, `APP_URL=http://edgehomelab.localhost:9701`, `SESSION_DRIVER=file` (sessions under the export's storage/framework/sessions),
router `tools/edge-dev-instance/router.php` (static assets) — and `CENTRAL_DOMAIN=127.0.0.1` kept. Switch: `-Stop` (pid 194088) → `-Browsable -Worktree <export>` → pid 386856.

| check (20:09 UTC, after the §B.5 outage) | result |
|---|---|
| Cloud `/up` | 200 |
| tenant host `http://edgehomelab.localhost:9701/login` | 200 (LAB tenant resolved); `/pos` as guest → 302 to login |
| unknown tenant `other.localhost` | 404 |
| central `127.0.0.1/login` | 200 (central login page, as before); `localhost/login` → 404 (neither tenant nor central) |
| device API `/api/edge/authority/heartbeat`, `/sync/sales`, `/config/refresh`, `/authority/handback` | **401 with Host 127.0.0.1** (route resolved, device auth enforced) and **404 with Host localhost or the tenant host** → the appliance's existing URLs keep working, nothing else reaches the device API |
| appliance heartbeat after the switch | resumed on the next tick: lease seq 49108 (11:07) → 49396 (13:06) → 49516 (13:49) → 49520 (20:12), holder cloud, fenced_at NULL, released_at NULL |
| CSRF | POST /login without token → 419 |
| sessions | file sessions written under the export (123 files), login persists across requests (harness) |
| static assets via the router | 200 |
| production tenants | none reachable: the LAB master knows one tenant (edgehomelab); other hosts → 404 |
| Edge | STANDBY_READY throughout (except §B.5) |

```
LAB_CLOUD_BROWSABLE=YES (loopback only; CENTRAL_DOMAIN unchanged)   TENANT_DOMAIN_ROW=already present, no change   CLOUD_API_COMPATIBILITY=PASS   SESSION_AND_CSRF=PASS
```
Revert procedure (not needed): `Start-LabCloud.ps1 -Stop` then `Start-LabCloud.ps1 -Worktree <export>` without `-Browsable`; delete the file sessions.

### B.5 Incident — shared MySQL server died (not a LAB configuration change)

Between 13:50 and 16:23 UTC on 30 Oct the Laragon `mysqld` (a child process of laragon.exe, not a Windows service — Agent C's finding) stopped without writing a
shutdown line to its error log (last log entry before that: its 14 Oct start). Cause unknown (nothing in this session or its agents stops processes by name; the host
also runs other sessions' suites). Effects: the LAB Cloud and every appliance DB call failed; the appliance's **print worker and authority worker exited** (their
"DB unreachable" loop — exactly Agent F's finding #1), the Cloud lease lapsed at 13:49:54; the two 0.8.2 web backends and their supervisors **stayed up with 0 restarts**
(health route served, busy-aware probes unaffected). Recovery (shared infrastructure, no LAB config touched): `mysqld --defaults-file=<Laragon my.ini>` restarted at
16:01 UTC → InnoDB init 3 min + XA crash recovery → ready 16:23:47 (pid 421200, recorded in `09-mysqld-restart-pid.txt`); LAB databases intact (every rowset MD5 = baseline);
at 20:12 UTC the two dead workers were restarted with the plan's own executable/arguments (`10-restart-dead-workers.txt`: print 310936, authority 421644; the five alive
tasks untouched; pid record rewritten) → heartbeat resumed on tick 1, lease re-gained (holder cloud, fenced_at NULL, seq 49520), health **STANDBY_READY**, problems [].
Disclosure: the sync-sender and backup loops kept running through the outage (periodic PowerShell loops); the hourly backup task will have logged failures for the window.

## Phase C — temporary LAB admin + manager PIN — PREPARED, WAITING FOR THE OWNER'S PRIVATE CREDENTIALS

Pre-provisioning checks (read-only, 30 Oct 13:1x UTC): no `lab.admin@edgehomelab.test` / `LABADM1` row exists in `pos_lab_tenant_edge.users`; the approver (user 2) has
**no manager PIN** (`manager_pins` 0 rows); the reviewed mechanism is `C:\Users\Dell\BingooEdgeLab\scripts\Create-LabAdmin.php` / `Disable-LabAdmin.php` (Agent A, validated
end to end on the disposable clone: LAB-only PDO, bcrypt via `password_hash(PASSWORD_BCRYPT)`, guard `tenant`, exactly the five route permissions `tenant.dashboard`,
`tenant.users.index`, `tenant.users.show`, `tenant.users.manager-pin`, `tenant.users.manager-pin.store`; 403 on every other route; the disable step rotates the password to
a random discarded hash AND removes the grants, because `AuthController::login` does not consult `users.status`). Session revocation: the LAB Cloud now uses FILE sessions
(Phase B) — the disable procedure deletes that user's session files under the export's `storage/framework/sessions` (each file names the authenticated user id) and the
rotated password refuses any new login; this is demonstrable on the real LAB only after the account exists.

Gate not met: the owner's instruction "The owner must provide credentials privately" — the password file `C:\Users\Dell\BingooEdgeLab\secrets\admin.pass` is ABSENT
(only the clone's `devclone-admin.pass` exists). No password was invented. The exact real-LAB procedure once the file exists (owner at the LAB host):
1. `php scripts\Create-LabAdmin.php pos_lab_tenant_edge` (refuses non-"lab" DBs; prints ids/codes/permission names only) → one user, five grants.
2. In the LAB host's browser: `http://edgehomelab.localhost:9701/login` as `lab.admin@edgehomelab.test` → `/users/2/manager-pin` → enter the approver's PIN twice (the owner's value, never shared) → "Manager PIN set successfully".
3. Verify (non-secret): `manager_pins` row for user 2 exists with `is_active 1` and a `$2y$` hash; approver eligibility = `may_approve_pos` + the PIN (health/capability census); the admin gets 403 on `/users/create`, `/pos`, `/settings/...`.
4. `php scripts\Disable-LabAdmin.php pos_lab_tenant_edge` → status inactive, 0 grants, password rotated, session files removed → verify login refused and the old session cookie redirected to login.

```
TEMP_ADMIN_CREATED=NO (waiting for secrets\admin.pass)   APPROVER_PIN_READY=NO   TEMP_ADMIN_DISABLED=n/a   ADMIN_SESSIONS_REVOKED=n/a
```

## Phase D — standby acceptance (first pass 30 Oct 20:13–20:51 UTC; `phase-d\`)

Run with the harness unchanged, secrets via env from the files. **Concurrency disclosure:** during this pass the two preparation agents were running their heavy gates
(full MySQL suites + Playwright pairs) on the same laptop — 64 test/browser processes, CPU 97–100 % — which violates the directive's "no competing heavy suites during
acceptance"; the standby-safe checks below are valid where they passed, and the web-tier observations are interpreted against that load. A quiet re-run of D2 follows once
the agents finish (§D.3).

| check | result |
|---|---|
| D1 Online POS on the LAB host (browsable Cloud, `http://edgehomelab.localhost:9701`, cashier) | **W01 PASS** — login, terminal selection, shared POS layout rendered; the only http error is the known `/dashboard` 403 (the LAB cashier has no `tenant.dashboard`) |
| D2 Edge POS through the LAB gateway (`https://127.0.0.1:8443`, LAB2C5D) | **W01 PASS** (slot `STANDBY · CLOUD AUTHORITY`, terminal, capabilities rendered); **W18 EXPECTED-DIFFERENCE** (reminder-pause → 422 STANDBY "Local Mode is not active — the Cloud POS is the active writer…"); **W15 inconclusive** — the quick-add panel did not appear within the harness timeout while the backend was saturated (capability `customerCreate true` was rendered); on 0.8.1 the same check gave the expected 422 STANDBY; re-run in §D.3 |
| D3/D4 pixels, Online (LAB Cloud) vs Edge (LAB gateway) — SAME dataset for the first time | 1366: 9/12 states **0.000 %** identical, 08-quick-report 0.279 % / 11-review-pay 0.084 % STATE (capability-driven controls), **06-recent-orders 15.99 % DATA** (the appliance's recent list = its 6 local sales incl. the cancelled one vs the Cloud's 5); 1024: 8/12 identical, 08 0.374 % / 11 0.112 % / 13 0.164 % STATE, 06 16.30 % DATA — no UNINTENTIONAL drift |
| D5 geometry | **EDGE_VS_ONLINE_GEOMETRY=NONE** — 998 boxes, max deviation 0 px, geometry 0, presence 0, data 5 (recent-orders list height at 1366; customer-modal content/body height at 1024 — content-driven buckets), skipped 2 (07 both) |
| W07/W08 approval UI | NOT exercised: needs an open shift and the approver PIN (Phase C) — no dummy transactions were created to mark it green |
| web tier during the pass | backend #1 restarted once at 20:47:21 ("progress continued but no answer for 166s — beyond the 120s busy grace") while the geometry capture drove it continuously; **gateway 0 × 502** in D1–D5 (nginx failed over to backend 2); health STANDBY_READY throughout |
| W15 re-run at 21:00 (host at 100 % CPU, 64 agent processes) | W01 FAIL (page.goto 90 s timeout), W15 FAIL, one 502: both backends were recycled within 20 s of each other at 20:57 (165 s / 167 s without a probe answer) → inconclusive under that load; counts afterwards: backend #1 restarts 2, #2 restarts 1, both HEALTHY, STANDBY_READY |

Interpretation of the three restarts: all three carry the bounded reason "progress continued but no answer for 165–167 s" — the 0.8.2 policy working as designed (a backend that
cannot answer its own health probe within 10 s for more than 2 minutes is recycled; the children were serving the whole time; 0.8.1 would have restarted them every
15 s). They happened only while a Playwright session hammered a single-threaded backend on a laptop saturated by two concurrent test programmes. They are reported, not
hidden: FALSE_RESTARTS for the Phase A acceptance window was 0 (12:09–13:06 UTC, 57 min, host 85–97 %).

### D.3 Quiet re-run of D2 (31 Oct 07:01–07:04 UTC; `phase-d\edge-gateway-quiet-rerun\`, summary `phase-d\quiet-rerun-summary.txt`)

Run after both preparation agents had stopped (no phpunit / Playwright of theirs alive; CPU 72 % at the start from the owner's browsers and a different
project's test run on this host, 30 % at the end). Same harness, same arguments (`--only W01,W18,W15`), secrets read from `secrets\cashier.pass` /
`secrets\approver.pass` into the environment only.

| check | result |
|---|---|
| gateway `GET /edge/local/health` × 20 before / × 20 after | **40 × 200**, slowest 1.29 s / 1.10 s, **0 × 502** |
| W01 login + POS renders (gateway, LAB2C5D) | **PASS** — POST 302 → `/edge/local/pos` in 4.9 s, `POS_RUNTIME` mode edge, 15 tiles, slot `STANDBY · CLOUD AUTHORITY`, `0 pending sync`, terminal `Lab Counter 1 — Home Lab Branch` |
| W18 reminder-pause | **EXPECTED-DIFFERENCE** — shift-status carries the reminder block, capability `reminderPause` ON + route, control rendered ENABLED by the server, pause answers **422 `STANDBY`** "Local Mode is not active — the Cloud POS is the active writer for this branch (standby)." — no write |
| W15 customer quick-add | **EXPECTED-DIFFERENCE** — quick-add panel appears for an unmatched name (save enabled, capability `customerCreate` true), quick-store answers **422 `STANDBY`** (same message) — no customer created. This is the check that was inconclusive under the agents' load; on a quiet host it behaves exactly as on 0.8.1 |
| supervisor counters before → after | backend #1 restarts **2 → 2**, backend #2 **1 → 1** (the three bounded restarts of the saturated pass remain the only ones since the install); both HEALTHY, probe ok 3.6 s / 4.6 s, `busy_ticks` 0 |
| health | `STANDBY_READY`, 0.8.2-edge, authority standby/online, acks 204 → 211, failures 0, `auto_failover_enabled` false, problems `[]` |
| lease | holder **cloud**, state standby, seq 51327 → 51332, `fenced_at` null |
| rowsets | appliance 6 sales / 25 lines / outbox 5 acked / 2 customers / 17 movements / 0 open shifts; Cloud 5 sales / 20 lines / 5 ingestions / 2 customers / 0 open shifts; all seven MD5s identical to the pre-install baseline (`9bbd2ad2…`, `cc04a3e4…`, `c9c08b1d…`, `e42077b9…`, `0dac7dbb…`, `49bb4ea1…`, `e39a8d18…`); the only table whose CHECKSUM changed between before and after is `edge_local_meta` (heartbeat metadata) |
| harness output | 0 lines matching 50x / timeout; exit 0 |

Phase D verdict: **STANDBY_BROWSER_ACCEPTANCE = PASS for the standby-safe checks** (W01 both runtimes, W18 + W15 standby refusals, pixels, geometry);
W07/W08 approval UI and the physical second-laptop check remain NOT exercised (Phase C credentials, no dummy transactions, owner's hardware).
```
STANDBY_BROWSER_ACCEPTANCE=PASS (W01 Online + Edge, W18/W15 = 422 STANDBY, 0 x 502, 0 new restarts, rowsets identical)   FALSE_RESTARTS_QUIET_RERUN=0   GATEWAY_502_QUIET_RERUN=0
EDGE_ONLINE_GEOMETRY=NONE (D5, 998 boxes)   REAL_SECOND_LAPTOP_CHECK=NOT_RUN (owner hardware)   W07_W08_APPROVAL_UI=NOT_EXERCISED (Phase C)
```

## Incident B.6 — shared Laragon mysqld CRASHED 31 Oct 08:22:16 UTC (second outage in two days; host resource exhaustion)

| fact | evidence |
|---|---|
| crash | `D:\laragon2\data\mysql-8\DESKTOP-0024EPM.err`: 08:22:16 `[InnoDB] Resource not available to create threads for parallel scan` → `[FATAL] Error 100 encountered when writing to the redo log file .\#innodb_redo\#ib_redo346678` → `Assertion failure: log0write.cc:2117` → `mysqld got exception 0x16` (full stack in the log). Yesterday's death (13:50 UTC) left no trace; this one did |
| host state at 09:30 UTC (read-only census) | commit **58.9 of 62.9 GB**, RAM free 4.9 of 31.7 GB, **C: 1.7 GB free** (the page file cannot grow), total handles **10.8 million**: `Dell.TechHub.Instrumentation.SubAgent` pid 8744 **10,174,487 handles / 5,062 MB**, `RtkAudUService64` ×3 (202k / 101k / 99k handles, 2,273 / 1,491 / 796 MB), `WavesSysSvc64` 3,599 MB. These are elevated vendor services: not ours to stop (owner rule: no elevation) |
| effect on the LAB | print worker (pid 310936) and authority worker (pid 421644) exited again, exactly as on 30 Oct; gateway, both web backends, sync-sender and backup task alive; the appliance's `edge:local:health` reported the local database unreachable; no data change possible (no writer) |
| effect on the agents | Agent E's gate 2 (27 canonical classes) and Agent F's full Edge MySQL suite were in flight → declared INVALID (not red); both agents paused by message at 09:33 UTC and replied with the list of runs that had already ended with clean END lines (nothing green is re-run) |
| recovery | mysqld restarted 09:35:16 UTC with the Laragon `my.ini` (process 503036); InnoDB initialization 09:35:19–09:42:50 (7.5 min); XA/redo recovery until **10:14:03 UTC** (`ready for connections`; 39 min in total); dead LAB workers restarted from the plan definition by `restart-dead-workers.ps1` at **13:54:10 UTC** (the coordinating session itself was down 10:00–13:53 UTC, which is why the workers stayed down that long; evidence `10b-restart-dead-workers-b6.txt`) (only the two dead entries; everything alive untouched; pid record rewritten); afterwards health 13:55 UTC: `STANDBY_READY`, 0.8.2-edge, authority standby/online, print worker running (heartbeat 3 s), authority worker running, lease holder cloud seq 51535, all seven rowset MD5s identical to the baseline (`10c-health-after-b6.json`, `10d-snapshot-after-b6.txt`); the FakePrinter listener (pid 22824) had also vanished in the crash window → restarted by `Start-FakePrinter.ps1` as pid 471212. **Web tier during the outage:** both backends were recycled once at 08:33:40 / 08:33:44 UTC ("progress continued but no answer for 121 s / 132 s — beyond the 120 s busy grace": a backend whose database is gone cannot answer its own health probe; the bounded policy recycled it, nginx kept answering) → counters now 3 / 2; no restarts since |
| owner actions (elevated, outside my authority) | (1) free ≥ 10 GB on C: or move the page file to D:; (2) restart or disable the leaking `Dell TechHub Instrumentation` service and the Realtek audio service (or reboot the host — ask-before rule); (3) give the LAB MySQL its own Windows service with automatic restart (Agent F's MYSQL_STARTUP_PLAN, record §1). Until (1)–(2) are done every long test run on this laptop risks killing MySQL again |

## Fast track (owner priority 31 Oct) — second laptop, FakePrinter scope, supervised Local Mode procedure

### F.1 Second Windows cashier laptop — exact URL and minimal setup (standby-only testing approved)

| item | value |
|---|---|
| URL on the cashier laptop | **`https://DESKTOP-0024EPM.local:8443/edge/local/login`** (same as the 0.7.0 walk; the LAB CA `lab-ca.crt` was trusted on that laptop in P5C — if the padlock is red, import `C:\Users\Dell\BingooEdgeLab\secrets\lab-ca.crt` into *Trusted Root Certification Authorities* of the current user, no admin needed) |
| Edge laptop LAN address now | **192.168.1.9** on `Wi-Fi 2` (network "SMS5G 3", profile Public). The server certificate's SANs are `DESKTOP-0024EPM.local`, `bingoo-edge.local`, `192.168.1.6`, `192.168.2.15`, `127.0.0.1` — the IP changed since the certificate was issued, so by-IP access shows a name warning; use the name. If `.local` does not resolve on the laptop (mDNS off), add one hosts line `192.168.1.9  DESKTOP-0024EPM.local` |
| gateway listening on the LAN | verified from the Edge laptop itself: `https://192.168.1.9:8443/edge/local/health → 200` (nginx `listen 8443 ssl http2`, all interfaces); Windows Firewall has inbound-allow rules for `C:\users\dell\bingooedgelab\install\bingooedge\gateway\nginx.exe` on the **Public** profile, which is the profile of the current Wi-Fi |
| real risk to check first | the Wi-Fi "SMS5G 3" looks like a hotspot-type network; if the access point isolates clients, laptop→laptop traffic is blocked and nothing above helps. Test from the cashier laptop: `ping 192.168.1.9`, then the URL. If it fails, put both laptops on the same router LAN (or hotspot with client isolation off) |
| credentials | cashier `LAB2C5D` (password from `secrets\cashier.pass`, typed by the owner — never in chat); approver `LABMF2DE` (`secrets\approver.pass`) |
| what to walk in standby (0.7.0 checklist rows that need no Local Mode) | 01 padlock + login page · 02 login → POS (not status page), `STANDBY · CLOUD AUTHORITY` slot · 03 categories/search/tiles · 06 cart editing · 07/08/16/18 dialogs open · 17 shift open/close pages · 19 approver `LABMF2DE` → 403 on `/pos`, status page shown · 20 responsive 1366×768 + native. Mutations (hold, bill, KOT, pay, void, return, quick-add) answer the 422 STANDBY toast — expected, screenshot one |
| evidence | screenshots `NN-<step>.png` into `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.2-edge\laptop\` |

### F.2 LAB FakePrinter / test-printer testing (approved) — what is possible in which state

The LAB FakePrinter is `tests/MySql/Support/fake_printer.php` on TCP 9100 (recorded pid 22824; printer record `LAB-FAKE-9100`, 127.0.0.1:9100). Edge printing paths and their fence on 0.8.2:

| path | standby | Local Mode |
|---|---|---|
| receipt print / **reprint** of an existing paid appliance sale (`POST /edge/local/sales/{sale}/receipt`, `reprint=1`) — not behind the writer fence; 6 paid sales exist locally | **yes** | yes |
| Recent Prints panel, print-jobs list, document view, **retry** / **dismiss** of a failed job, print-status, health DEGRADED line | **yes** | yes |
| failed-job ladder (5/15/30/60/120 s, 6th terminal) — by stopping the FakePrinter pid 22824 and restarting it from the plan | **yes** (with the reprint above as the job source) | yes |
| **KOT** (normal / addition / cancellation), **reminder**, reminder reprint, new receipts from new sales | no — `EdgeLocalPrintKotService`: "a standby appliance never creates kitchen events" | **yes** — part of the supervised exercise (F.3 step 4) |

Plan: receipts / reprints / retries / failed jobs / dismiss run as a scripted standby pass on the real LAB (harness W10 + W11 through the gateway + a FakePrinter stop/start by recorded pid) once MySQL is stable; KOT + reminders are taken during F.3. Outbox and Cloud rowsets are snapshotted before/after (print jobs are appliance-local; nothing may reach the outbox). Agent F's 0.8.3 health/reachability additions are NOT on the LAB (0.8.2) — the LAB pass certifies 0.8.2's behaviour.

### F.3 Supervised Local Mode exercise on the real LAB — exact procedure (NOT executed; each marked step needs the owner's explicit go at that moment)

Pre-conditions (all checked read-only right before): `STANDBY_READY`; outbox 0 pending / 0 failed; freshness config + stock ok; LAB Cloud browsable and healthy; mysqld up for ≥ 30 min with no new error-log lines; owner present at both laptops; snapshot `lab-snapshot.sql` → `T0`. Approver eligibility: `LABMF2DE` must carry `may_approve_pos` in the applied Edge config — that flag follows an **active Cloud manager PIN** (`EdgeLocalAuthService::verifyManager` step 3; `manager_pins` on the LAB is empty) → **Phase C is a hard prerequisite for W07/W08 in this exercise**, not only for the Online side.

| # | step | command / action (Edge laptop unless said) | proof captured | owner go? |
|---|---|---|---|---|
| 1 | WAN-loss simulation (no cable, no router change) | `powershell -File C:\Users\Dell\BingooEdgeLab\scripts\Start-LabCloud.ps1 -Stop` → lease lapses naturally: `online → unstable → lost → preparing_local` (watch `edge:local:authority-status --json` every 30 s; ~3 min on 26 Sep) | `T1-status-*.json`, Cloud stop output | **YES** |
| 2 | Takeover | `install\BingooEdge\php\php.exe install\BingooEdge\artisan edge:local:authority-takeover --confirm --by="<owner name>"` — fails closed on any of the 9 gates; freshness override only with an audited `--reason` | `T2-takeover.txt`, health `LOCAL_ACTIVE`, lease row `holder=edge` | **YES** |
| 3 | Offline shift | cashier laptop: open shift (denominations) as `LAB2C5D` | screenshots `L03-*` | no |
| 4 | Offline sales + printing | cash sale → receipt on the FakePrinter; dine-in table → KOT → addition → reminder → sent-line void with approval `LABMF2DE` (**W07**) → manual discount with approval (**W08**) → Review & Pay; one return; one held order recalled; Recent Prints reprint | `L04-*`, FakePrinter spool files, `edge:local:print-status --json`, `edge:local:sync-status --queue=20` showing envelopes **pending** (no Cloud) | no |
| 5 | Close shift | blind count → close | `L05-*` | no |
| 6 | Reconnect | `Start-LabCloud.ps1 -Browsable -Worktree C:\Users\Dell\BingooEdgeLab\cloud\src-ac5b168d` → `connection_restored`; the sync-sender drains the outbox; exactly-once = Cloud `sale_ingestions` distinct `sale_uuid` count == appliance new sales, each applied once, no duplicate on a forced re-send (`edge:local:sync-send --max=50` twice) | `T6-sync-status.json`, `T6-snapshot.txt` (lab-snapshot.sql), Cloud ingestions query | no (restarting our own Cloud process) |
| 7 | Reconciliation | appliance sales/lines/returns vs Cloud ingestions and totals (same query set as T0); shift totals vs Cloud shift; `reconciling` state visible | `T7-reconcile.txt` | no |
| 8 | Handback | `… artisan edge:local:authority-handback --by="<owner name>"` — assesses first; `HANDBACK_BLOCKED` lists blockers and discards nothing | `T8-handback.txt`; after: `STANDBY_READY`, authority standby/online, lease holder **cloud**, `AUTO_FAILOVER_ENABLED=false`, outbox 0 pending | **YES** |
| 9 | Post | 70-request bounded gateway check, supervisor counters, final snapshot `T9`, record + commit | `T9-*` | no |

Rollback inside the exercise: if step 2 refuses → stay standby, restart the Cloud (step 6) — nothing changed. If sync in step 6 leaves envelopes failed → handback stays BLOCKED by design; resolve on the appliance (retry / inspect) before any handback; never discard.

```
LOCAL_MODE_TEST_READY=YES on the appliance side (all four commands exist on 0.8.2 and ran end-to-end on 26 Sep on 0.7.0; 0.8.2 gates green) — pending Phase C (approver flag) and a stable host (B.6)
```

## Incident B.7 — mysqld down a THIRD time, 31 Oct ~15:34 UTC: the C: drive is full (0.04 GB free of 238 GB)

No mysqld process, no 3306 listener, nothing written to the error log this time (abrupt death). Cause as read from the host: **C: 0.04 GB free**,
page file on C: (allocated 32,000 MB, cannot grow), commit 56.7 of 62.9 GB; the vendor-service leak of B.6 is unchanged. The LAB print and
authority workers (pids 476152 / 473880) exited with the database again; gateway, web backends, sync-sender and backup task alive; the appliance
stays in standby, the Cloud is the writer, no sale is affected. **Decision: mysqld is NOT restarted by me this time** — with no free space on
the system drive a restart would most likely die again and a third crash while InnoDB is recovering risks the redo log itself. Owner actions
first: free C: (the largest removable items I can see from this account: `%TEMP%\claude` 1.4 GB — other sessions' tool stores, mine is 21 MB — plus
`vscode-stable-user-x64` 240 MB, `seera-zatca-lock-test-*` 217 MB, `Diagnostics` 100 MB; a real fix needs ≥ 10 GB), then restart or disable the
leaking `Dell TechHub Instrumentation` / Realtek audio services (or reboot — ask-before rule), then `mysqld --defaults-file=D:\laragon2\bin\mysql\mysql-8.0.30-winx64\my.ini`,
then `restart-dead-workers-b6.ps1` for the two dead LAB workers (the FakePrinter pid 471212 should be checked too). Agent F's final gates finished
before the crash (full Edge MySQL suite 730 passed / 1 skipped / 0 failed); Agent E's browser programme was paused; both agents told not to launch.

```
HOST_BLOCKER=C: FULL + vendor-service handle/commit leak -> three mysqld deaths in 26 h; every further gate, the Phase C work, the second-laptop walk and the Local Mode exercise wait for the owner's host cleanup
```

## Incident B.8 — the third death explained, and a FOURTH death during an outside restart (31 Oct 15:35 and 17:54 UTC): commit exhaustion, not disk

Read at 17:55 UTC after the owner freed space (C: **7.4 GB** free, up from 0.04 GB). The error log and the Windows System log complete the picture of B.7 and add a fourth death:

| time (UTC) | event | source |
|---|---|---|
| 15:34:21 | Windows `Resource-Exhaustion-Detector` 2004 "low virtual memory": `Dell.TechHub.Instrumentation.SubAgent` 5.31 GB, `WavesSysSvc64` 3.78 GB, `RtkAudUService64` 1.76 GB | System log |
| 15:35:15 | mysqld `exception 0xc0000005` inside `jemalloc.dll!je_malloc_usable_size` (crash #3; B.7 read it as "unlogged" because the log had no timestamped line) | `DESKTOP-0024EPM.err` |
| 17:27:06 | second exhaustion event (same three programs) | System log |
| 17:40:14 | mysqld started as process 482640 **by someone outside this session** (Laragon GUI pid 8116 or the owner; no restart was issued here); InnoDB initialization 17:40:14–17:43:35 | error log |
| 17:54:01 | third exhaustion event **in the same second** mysqld died: `exception 0x80000003` after an uncaught C++ exception (`_CxxThrowException` → `__CxxFrameHandler4` → RaiseException) while still in XA/redo recovery — it never reached "ready for connections" (crash #4); WER APPCRASH 1001 at 17:54:20 | System + Application logs, error log |

Same-day exhaustion events also exist at 08:20:27 (→ crash #2 at 08:22) and 09:51:28. **Every mysqld death today sits within 60 s of a Windows low-virtual-memory event.** The disk was a symptom (page file could not grow); the cause is commit charge.

Host at 17:55–18:00 UTC (read-only): commit **57.8 / 62.9 GB**, RAM free 5.6 GB, page file C: 32,000 MB allocated / 6.3 GB in use, total handles **10.9 million** (`Dell.TechHub.Instrumentation.SubAgent` pid 8744 **10,253,549 handles**, commit 5.1 GB; `WavesSysSvc64` 3.6 GB; `RtkAudUService64` ×3 1.7 + 1.2 + 0.8 GB). The next largest holders are another project's Vite dev servers (`propertyos-d2`, three node processes ≈ 3.7 GB commit, started 15:33 UTC) and browser/IDE processes — none of them this session's, none touched.

LAB state: gateway (432188 + worker 343204 on :8443), web backends 432812 / 432372, sync-sender 411352, backup 432908, FakePrinter 471212 (:9100), browsable LAB Cloud 386856 (:9701) and Agent E's Cloud clone 504524 alive; print worker 476152 and authority worker 473880 **dead** (they exit without the database — restarting them before MySQL is back is pointless). Appliance in standby by construction (no database → no writer), Cloud is the writer, no sale affected.

**Decision: mysqld stays down.** Starting it a fifth time under 5 GB of commit headroom with the leak still growing would reproduce 17:54 — and each crash during recovery is a new chance to damage the redo log. Nothing this session may stop would relieve the pressure (the holders are elevated vendor services and other people's projects).

Owner actions (elevated, outside my authority), in order: (1) stop or restart the `Dell TechHub Instrumentation` service tree and `Waves Audio` service, and the Realtek audio service (`services.msc`, or `Restart-Service` from an elevated shell) — this alone returns ≈ 12 GB of commit; a reboot does the same (ask-before rule: say so, I stop nothing — the LAB workers are relaunched from the plan definition afterwards); (2) keep ≥ 10 GB free on C: so the page file can grow; (3) optionally disable TechHub Instrumentation's autostart until Dell fixes the leak. Then say "restart MySQL": I start mysqld with the Laragon `my.ini`, wait for "ready for connections", restart the two dead LAB workers by plan definition, verify `STANDBY_READY` + rowset MD5s against `T0`, and resume Agents E/F (one MySQL run at a time).

```
HOST_BLOCKER=COMMIT EXHAUSTION (vendor-service leak: TechHub 10.25 M handles / 5.1 GB, Waves 3.6 GB, Realtek 3.7 GB) -> four mysqld deaths in 34 h, each within 60 s of a Windows low-virtual-memory event; C: now 7.4 GB free (owner freed it) but commit 57.8/62.9 GB; mysqld NOT restarted by the session
MYSQLD=DOWN since 17:54:01Z (crash #4 during recovery, started 17:40 by someone outside this session)
LAB=STANDBY by construction, Cloud writer, print+authority workers dead (expected), gateway/web/sync/backup/FakePrinter/LAB Cloud alive
```

### B.8 recovery — MySQL back (started 21:28 UTC outside this session), LAB verified read-only, dead workers restarted; agents stay paused (owner decision "HOST RECOVERY FIRST", 31 Oct evening)

Owner order: no blind restarts, no force-recovery, no redo-log deletion, keep Agents E/F and every DB-dependent test paused, recheck the host, verify integrity and rowsets once MySQL is ready, restart only dead workers by their recorded plan identity, confirm Cloud authority and `STANDBY_READY` before any resume.

| step | result (UTC) | evidence |
|---|---|---|
| host recheck 21:29 | C: 6.1 GB free; commit 54.6/62.9 (headroom 8.3, Waves restarted by the owner → new pid 391832, 124 MB); TechHub pid 8744 **still 10.56 M handles / 5.2 GB**; Realtek ×3 3.7 GB; exhaustion events continued at 21:20:16 and 21:42:41 | System log |
| mysqld | started **21:28:28 by someone outside this session** (monitor 243252 + server 375108, Laragon `my.ini`) — not issued, not stopped here; InnoDB init 21:28:29–21:34:53; XA crash recovery 21:52:24 (finished in 8 ms); **ready for connections 21:52:26**; the 21:42 exhaustion event fell inside recovery and was survived | error log, watcher |
| integrity | 0 ERROR/corruption lines in the recovery segment; `CHECK TABLE … QUICK` on all **445** base tables of `pos_lab_master_edge`, `pos_lab_tenant_edge`, `bingoo_edge_lab_local` → 445 OK, 0 non-OK | `11a-check-tables-after-b8.txt` |
| rowsets | all seven MD5s identical to T0 (`9bbd2ad2… cc04a3e4… c9c08b1d… e42077b9… 0dac7dbb… 49bb4ea1… e39a8d18…`); appliance 6 sales / 25 lines, Cloud 5 sales / 5 ingestions; outbox 5 acked, **0 pending, 0 failed** | `11b-snapshot-after-b8.txt`, `11f-snapshot-after-workers.txt` |
| dead workers | `restart-dead-workers-b8.ps1` (the b6 copy with the `$e`/`$E` shadow fixed): print worker 476152 → **501708**, authority worker 473880 → **368740**, five alive entries untouched, pid record rewritten (7 entries) | `11d-restart-dead-workers-b8.txt` |
| authority | lease holder **cloud**, edge_state standby, heartbeat advancing again (seq 51814 @ 15:35 → 51825 @ 22:02:19), fenced_at null, acks 11 / failures 0, `AUTO_FAILOVER_ENABLED=false`; web backends healthy (restart counters unchanged 3/2) | `11e-health-after-b8.json` |
| health | `DEGRADED` with a single problem: "Last backup is older than 2 hours" — the hourly backup loop (pid 432908, alive) deferred at 19:20, 20:25 and 21:30 UTC with "local database not ready"; it clears itself at its next tick (~22:35 UTC); no action | `worker-logs\BingooEdgeBackup.out.log` |

```
MYSQL=READY 21:52:26Z (not started by the session); INTEGRITY=445/445 OK, 0 recovery errors; ROWSETS=IDENTICAL_TO_T0; OUTBOX=0 pending / 0 failed
LAB=STANDBY, lease cloud, heartbeat advancing, print+authority workers restarted by plan identity; health DEGRADED only until the next hourly backup
HOST=NOT STABLE: TechHub 10.56 M handles / 5.2 GB and Realtek 3.7 GB untouched, exhaustion events still logged (21:20, 21:42); Waves fixed; commit 50.4/61.6
AGENTS=E/F remain PAUSED (owner item 1) until the owner confirms the TechHub/Realtek fix
```

## B.9 — host REBOOTED by the owner (4 Nov 09:42 UTC): leak gone, host stable; LAB relaunched by plan identity on 5 Nov 08:55 UTC; one MySQL finding for the owner

Owner decision 31 Oct late ("MYSQL RECOVERY ACCEPTED"): agents paused until Windows memory is stable; owner handles the vendor services; after the intervention verify eight points and report only `HOST_STABLE` and blockers.

| check | result | evidence |
|---|---|---|
| 1 TechHub leak | gone: `Dell.TechHub.Instrumentation.SubAgent` (new pid 6308) **2,374 handles / 155 MB** (was 10.57 M / 5.3 GB); Realtek ×3 ≈ 5 MB each; Waves 76 MB; total handles 175 K (was 11.2 M). Intervention = **reboot** at 2026-11-04T09:42:55Z | process census 5 Nov 08:46 UTC |
| 2 commit headroom | **29.0 / 55.1 GB, headroom 26.1 GB** (was 5.1–8.3) | same |
| 3 C: free | **56.8 GB** | same |
| 4 stability | host up 23 h since the reboot before these checks, 0 low-memory events | System log |
| 5 low-memory events | **0** since 31 Oct 22:00 UTC | Resource-Exhaustion-Detector query |
| 6 MySQL | Laragon autostarted mysqld at 09:46:10 (pids 12840 → 12796, `--log-error=D:\laragon2\data\mysql-8\mysqld.log`, same basedir `my.ini`); ready 09:48:45; **finding below** | `mysqld.log` 146348–146369 |
| 7 hourly backup | 545 × exit=0 up to the reboot (last 4 Nov ≈09:19 UTC); loop dead after the reboot; relaunched 08:55 UTC — first run: ` checksum 8fa5e0da5a104c2d… size 359079 bytes [2026-11-04T13:42:44] exit=0 Backup written: C:\Users\Dell\BingooEdgeLab\data\BingooEdge\backups\edge-backup-20261104_084244-lcjufy.enc checksum 45728da7f5e2aed0… size` (newest file `edge-backup-20261105_085556-6fkyw2.enc`) | `worker-logs\BingooEdgeBackup.out.log` |
| 8 Edge | all ten LAB pids were dead after the reboot (gateway, two supervisors, print, sync, authority, backup, FakePrinter, LAB Cloud, Agent E's Cloud clone); data untouched: rowset MD5s identical to T0 (12d, 13d), outbox 0/0, lease holder cloud. Relaunch 5 Nov: LAB Cloud (`Start-LabCloud.ps1 -Browsable -Worktree …\src-ac5b168d` → pid 21180, first request 23 s cold, then 200), FakePrinter (pid 31968, :9100), then the seven plan entries by recorded identity (`restart-workers-after-reboot.ps1`, the b8 script with a new evidence name): gateway 31556, web1 28292, web2 10400, print 11784, sync 21072, authority 21028, backup 11880. **STANDBY_READY 27 s later**, acks 409 / failures 0, heartbeat seq 60633, gateway 10/10 × 200, supervisor counters 0/0 | `13b-restart-workers-after-reboot.txt`, `13c-health-after-reboot.json`, `13d-snapshot-after-reboot.txt` |

**MySQL finding (owner decision needed, nothing done):** the reboot killed the 31 Oct mysqld instance without a clean shutdown (the old error log ends at 21:52 31 Oct). At the 4 Nov start InnoDB logged five `[ERROR] MY-011971` "log sequence number is in the future" pages — `innodb_system` page 5 and `innodb_undo_001` pages 123/124/281/319 (page LSN ≈ 1,165,745,928,486 vs redo end 1,165,745,927,534, ≈ 950 bytes of redo tail lost) — each followed by `MY-011972` "your database may be corrupt or you may have copied the InnoDB tablespace but not the redo log files". Recovery then completed (XA recovery clean, ready for connections). No LAB tablespace is named; `CHECK TABLE … QUICK` on all 445 LAB tables = OK twice (31 Oct and after the reboot) and every rowset MD5 equals T0. Risk: the transaction-system / undo metadata is ahead of the redo log; InnoDB's own advice for this message is a logical dump and reload. Options for the owner: (a) accept and watch (LAB only; hourly encrypted restore-verified backups continue); (b) rebuild the shared Laragon instance from logical dumps — affects every project on this laptop; (c) give the LAB MySQL its own service and datadir (Agent F's MYSQL_STARTUP_PLAN) and load the LAB DBs there from a dump. Per the owner's rules I used no force-recovery and touched no redo/undo files.

**Supervisor finding (for Agent F, 0.8.3):** after a 9-hour Modern Standby (22:04 3 Nov → 07:01 4 Nov) both supervisors computed "no answer for 30,217 s", declared the backends hung and restarted them (restart #4 each, 07:02:27 UTC) — the hang clock is wall-clock `microtime()`, so sleep time counts as silence. Bounded, data-safe, but a false restart. A monotonic clock plus a resume-gap reset belongs in 0.8.3. (The 3 Nov "frozen status file" scare was a misread: the status file is rewritten only on state transitions; the supervisors were alive and probing every 5 s.)

```
HOST_STABLE=YES (reboot 4 Nov 09:42 UTC; TechHub 2,374 handles; commit 29.0/55.1 GB; C: 56.8 GB; 0 low-memory events since 31 Oct 22:00)
LAB=STANDBY_READY again at 2026-11-05T08:56:12Z after a full relaunch by plan identity; rowsets identical to T0; outbox 0/0; lease cloud
REMAINING_BLOCKERS=(1) owner decision on the InnoDB "LSN in the future" finding (LAB tables verify clean); (2) secrets\admin.pass for Phase C; (3) owner's explicit "resume agents"
```

## B.10 — isolated MySQL restore PROOF for the three LAB databases (owner decision 5 Nov, items 2–4) + LAB-only isolation/switchover PLAN (item 10, not executed)

**Disposable instance** (never the shared Laragon one): `C:\Users\Dell\BingooEdgeLab\mysql-isolated\` — `conf\my-isolated.ini` (same 8.0.30 binaries, **port 3316, bind 127.0.0.1, mysqlx off**, own datadir/log/pid, `innodb_flush_log_at_trx_commit=1`, doublewrite on, buffer pool 256 MB), initialised with `--initialize-insecure` (loopback-only root, empty password — disposable), started hidden with `--defaults-file`; **server pid 15316** (recorded in `log\isolated.pid`); stop with `mysqladmin -uroot -h127.0.0.1 -P3316 shutdown`. One restart happened during setup (my own `skip-name-resolve` line blocked root@localhost over 127.0.0.1; the empty instance was stopped by its recorded pid, the line removed, restarted).

**Verified logical backups**: the LAB's hourly `.enc` backups cover only the appliance DB and `edge:local:restore` targets the appliance's configured connection, so the three schemas were dumped from the shared instance (read-only, `mysqldump --single-transaction --routines --triggers --events --hex-blob`) at 2026-11-05 19:5x UTC into `mysql-isolated\dumps\` with `SHA256SUMS` and an exact per-table row-count manifest (`source-rowcounts.txt`, 445 tables, 1,971 rows): `pos_lab_master_edge.sql` 133,036 B (66030ca4…), `pos_lab_tenant_edge.sql` 431,339 B (21c31cd3…), `bingoo_edge_lab_local.sql` 622,928 B (71353c96…). Plaintext copies of LAB data inside the owner's profile, like the datadir itself — delete `dumps\` and `mysql-isolated\` when the owner no longer needs the proof.

| item 3 check | result (identical on 3306 and 3316 unless stated) | evidence (`mysql-isolated\verify\`) |
|---|---|---|
| starts without InnoDB warnings | isolated error log: **0 `[ERROR]`, 0 `MY-01197x`** at initialise, start, restart and after loading all three dumps (only the usual deprecation/ICU/self-signed warnings) | `log\mysqld-isolated.err` |
| full-table readability | `CHECK TABLE` (full, not QUICK) on all **445** base tables → 445 OK, 0 non-OK; every table read by `SELECT COUNT(*)` | `check-tables-3316.txt` |
| row counts | **445/445 tables identical, 1,971 rows, 0 diff lines** vs the source manifest | `rowcounts-3316.txt` |
| migrations | appliance 218, tenant 217, master 49 | `snapshot-3316.txt` |
| sales / outbox / customers | the baseline script (`lab-snapshot.sql`) run on both ports in the same minute: **identical except the heartbeat row of `edge_local_meta`**; all seven T0 MD5s present on 3316 (`9bbd2ad2… cc04a3e4… c9c08b1d… e42077b9… 0dac7dbb… 49bb4ea1… e39a8d18…`); appliance 6 sales / 25 lines / outbox 5 acked, 0 pending, 0 failed / 2 customers; Cloud 5 sales / 5 ingestions / 2 customers | `snapshot-3306.txt`, `snapshot-3316.txt` |
| financial invariants | I1 sale arithmetic (subtotal − discount + tax + service + delivery + tip = grand_total) 0 violations both sides; I2 Σ non-void line_total = subtotal 0/0; I3 paid sales paid_amount ≥ grand_total 0/0; **I11 Σ sale_payments.amount = paid_amount 0/0**; I7 appliance vs Cloud grand_total per synced sale 0 | `invariants-3306.txt`, `invariants-3316.txt`, `invariants-extra-*.txt` |
| sync / identity invariants | I4 acked envelopes whose sale is missing on the Cloud 0 (of 5); I5 duplicate `sale_uuid` among Cloud ingestions 0 (5 distinct) — exactly-once holds; I6 edge-origin Cloud sales without an ingestion 0; I8 appliance customers missing on the Cloud 0 (of 2); I9 open shifts 0; I10 appliance sales without an envelope **1 = sale 4 `SO-1-1-01M3FEF9…`, status `cancelled`, 26 Sep 2026** — cancelled sales never enter the outbox (by design), the known 6-vs-5 baseline | same |
| item 4 (differences) | **none** — no object differs between the shared instance and the isolated restore | — |

```
ISOLATED_MYSQL_RESTORE=PASS (3 schemas, 445 tables, 1,971 rows, 0 load errors, pid 15316 on 127.0.0.1:3316)
INNODB_INTEGRITY=CLEAN on the isolated instance (0 errors, 0 LSN-in-the-future); the SHARED instance still carries the 4 Nov MY-011971/MY-011972 start-up errors (system page 5 + undo_001 pages 123/124/281/319) — unchanged, untouched
LAB_DATA_MATCH=IDENTICAL (row counts, T0 MD5s, 13 invariants, 0 differing objects)
```

### LAB-only MySQL isolation / switchover plan (concise; every step below needs the owner's separate approval; nothing executed)

Goal: move the real LAB (appliance DB + LAB Cloud master/tenant DBs) off the shared Laragon instance onto a LAB-owned instance, without a Windows service and without touching the shared instance.

1. **Freeze** — LAB in `STANDBY_READY`, outbox 0 pending / 0 failed; stop the LAB Cloud (`Start-LabCloud.ps1 -Stop`) and the seven plan workers by recorded pid (`Start-EdgeLabWorkers.ps1 -Action Stop`); FakePrinter may stay. Snapshot `T_sw0` (lab-snapshot.sql on 3306).
2. **Final dump** — the same mysqldump trio as above from 3306 (quiesced, so no heartbeat drift) + SHA256 + row-count manifest.
3. **Target instance** — reuse the proven layout (`mysql-isolated\`) under a LAB name, e.g. `mysql-lab\` on **port 3316**, `--initialize` with a real root password written only to `secrets\mysql-lab-root.pass`; create the appliance user from `appliance.env` (`EDGE_DB_USERNAME`/`EDGE_DB_PASSWORD`, read from the file, never echoed) with grants on `bingoo_edge_lab_local` only, and the LAB Cloud user likewise; load the dumps; run the full verification table above (expect identical).
4. **Repoint (two files, both backed up first)** — `data\BingooEdge\config\appliance.env`: `EDGE_DB_PORT=3316`; the LAB Cloud env used by `Start-LabCloud.ps1` (`lab.env` / `.env.lab`): `DB_PORT=3316` for master + tenant. No code change, no new connection names.
5. **Relaunch** — LAB Cloud, then the seven plan entries (`Start-EdgeLabWorkers.ps1 -Action Start` or the dead-entry script); expect `STANDBY_READY`, lease holder cloud, heartbeat advancing, gateway 200, snapshot on 3316 == `T_sw0`; hourly backup exit=0 on the new port.
6. **Rollback** — stop workers + Cloud, restore the two env files from their backups, relaunch: the shared instance's copies are untouched by steps 1–5. The 3306 copies become stale the moment the LAB writes on 3316; drop them only on a later owner decision.
7. **Later, elevated, separate approval** — a Windows service (Agent F's MYSQL_STARTUP_PLAN) with automatic restart and a recovery action, so the LAB survives a host reboot without a session relaunch.
Risk notes: port 3316 must stay loopback-only; the appliance's `EDGE_DB_HOST=127.0.0.1` rule (`EdgeLocalDatabase`) is satisfied; two mysqld processes on one laptop cost ≈ 300 MB more commit (fine after the reboot).

## B.11 — three disposable databases "vanished" (Agent E, 6 Nov): explained by the binary log, not by InnoDB

Agent E found `pos_test_master_integ`, `pos_test_master_integ_cloud` and `pos_test_tenant_integ_cloud` absent after the reboot and rebuilt them from read-only dumps (no in-place workaround). The shared instance's binary log (`binlog.000098`, read with `mysqlbinlog`, read-only) shows one statement block at **2026-11-04 01:44:09 local (= 20:44:09 UTC, 3 Nov), thread_id 31111: `DROP DATABASE IF EXISTS` for exactly those three** — inside a bulk cleanup that dropped **543 `pos_test_%` databases on 4 Nov** (31 on 1 Nov, 74 on 3 Nov; consistent with the 1 Nov `my.ini` note "575 test DBs / ~100k .ibd files" → now 334 schemas / 49,433 .ibd). Issued from outside this session (another session or the owner tidying test databases). No LAB schema is a `pos_test_%` name; none was touched (B.10 proof). No InnoDB involvement. Owner note: the cleanup also removed Agent E's rehearsal Cloud pair; the cross-session "do-not-collide" list should name the `*_integ*` and `edge_relf` sets while the agents run.

Also surfaced by Agent E (product-hardening note, no code changed): `edge:local:enroll --credential-file` trims only `\n` (EdgeLocalEnrollCommand.php:60), so a CRLF-terminated credential file enrols a credential ending in `\r` that can never be typed — the LAB2C5D clone-login refusal of 31 Oct. Clone fixed by enrolling from CR-free copies; the real LAB's enrolments were made on 26 Sep from files that worked. Decision for the owner: harden to `rtrim("\r\n")` in the next release.
