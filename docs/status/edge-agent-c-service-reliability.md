# AGENT C — printer and service reliability (owner directive 30 Oct 2026: planning + DISPOSABLE validation only)

Scope: Scheduled-Task supervision design, Windows reboot recovery, service ownership / restart isolation, operator diagnostics, print-worker
failure and retry behaviour, physical-printer certification plan, P6 readiness checklist. Code frozen at `a6113772` (0.8.2 hotfix on
0.8.1 = `5f728d4f`); nothing under `app/`, `routes/`, `resources/`, `tests/` was touched. The real LAB (install root
`C:\Users\Dell\BingooEdgeLab\install\BingooEdge`, data root `…\data\BingooEdge`, ports 8443/8081/8090/8091, LAB Cloud 9701, fake printer
9100, DBs `pos_lab_*` / `bingoo_edge_lab_local`) was only LISTED (read-only process/file queries); production and the live tenants were
never referenced. **REAL_LAB_MUTATED=NO.**

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\agent-c\` (files `01`–`10`, `pids.json`, one diagnostics bundle). New scripts (new names only):
`C:\Users\Dell\BingooEdgeLab\scripts\Register-EdgeTasks.ps1`, `Get-EdgeProcessTree.ps1`, `Collect-EdgeDiagnostics.ps1` (all three parse clean
under Windows PowerShell 5.1; none registers, stops or kills anything unless the owner passes `-Apply`, which was never run).

## 0. The disposable appliance copy `labcopy-c` (built, used, left in place)

| item | value |
|---|---|
| install root | `C:\Users\Dell\BingooEdgeLab\labcopy-c\install\BingooEdge` — robocopy `/E /XJ` of the LAB install root (source read-only): 35,962 files / 553.78 MB in 7:45, exit 1 = all copied, 0 failed (`01-robocopy-install-root.log`). The only difference afterwards: 51 live `storage/framework/sessions` files the RUNNING LAB runtime wrote during the copy. Runtime versions 0.6.0 / 0.7.0 / 0.8.0 / 0.8.1, `runtime\current` = **0.8.1-edge** (the LAB's active runtime; 0.8.2 is not installed anywhere yet) |
| `appliance.json` | copy paths (php, gateway, runtime_root under `labcopy-c\install`, data_root `labcopy-c/data/BingooEdge`), written by the scratchpad helper `labcopy-c-env.php` (same pattern as the 0.8.1 rehearsal helper); re-written once because robocopy had overwritten it with the LAB's |
| data root | `labcopy-c\data\BingooEdge\{config,logs,backups,gateway,certs}`; `config\appliance.env` built from the LAB's with 23 keys changed: every path → the copy, `EDGE_DB_DATABASE=bingoo_edge_agentc_local`, every `*_URL` (except APP_URL) → `http://127.0.0.1:9` (dead), `EDGE_SYNC_DEVICE_SECRET=agentc-disabled`, `EDGE_WEB_PORT_BASE=8390`, `EDGE_GATEWAY_HTTPS_PORT=8743`, `EDGE_GATEWAY_HTTP_PORT=8381`, `APP_URL=https://labcopy-c.invalid:8743`. 60 keys; no value still names the real LAB roots; never printed |
| database | `bingoo_edge_agentc_local` = mysqldump (`--single-transaction --routines --triggers --hex-blob`, 634,953 B, empty .err) of `bingoo_edge_dryrun_lab_081` imported; 218 migrations, 6 sales, 33 printed print jobs, printer #1 "LAB FakePrinter (TCP 9100)" 127.0.0.1:9100 — the LAB DB was never read by the copy |
| service plan | rendered by the copy's own launcher: `edge:local:service-plan --php/--app-root/--data-root/--gateway=<copy> --write-gateway-config --json` → `labcopy-c\data\BingooEdge\gateway\service-plan.json` (+ `nginx.conf`, temp dirs): 6 tasks + gateway, backends 127.0.0.1:8390/8391, gateway 0.0.0.0:8743, **0 occurrences of the LAB install root** in the plan |
| health through the copy launcher (`03-health-initial.json`) | `edge:local:health --json`: DB **bingoo_edge_agentc_local** reachable, pointer **0.8.1-edge** (5f728d4f, release), data_root/env file = the copy's, status DEGRADED with the expected problems of a copy whose workers are not running (print worker stale, authority worker not running, backends 8390/8391 not listening, last backup > 2 h) |

Host conditions: shared laptop, the 0.8.2 MySQL gate running (CPU 76–100 %) — only one light process (the copy's print worker, sleeping 3 s between claims) was started, at 08:39 UTC, and stopped at 08:47 UTC.

## 1. Scheduled-Task supervision design

One source of truth stays the plan JSON (`EdgeSupervisionPlan` → `edge:local:service-plan`); `Register-EdgeTasks.ps1` renders it. What the
packaged `Register-EdgeServices.ps1` already does (AtStartup, 999 restarts / 1 min, StartWhenAvailable, IgnoreNew, ServiceAccount logon,
RunLevel Limited, SYSTEM refused, secret-shape check of every command line) is kept; three things it lacks are added: **`-Hidden`**, task
stdout/stderr **redirected to files outside the runtime**, and a per-task **ExecutionTimeLimit** (none for continuous tasks, 30 min for
the periodic ones — the packaged script sets 3650 days for all).

| task (folder `\BingooEdge\`) | action (`cmd.exe /d /c "<exe> <args> 1>><out> 2>><err>"`) | trigger | restart on failure | working dir | singleton | stop |
|---|---|---|---|---|---|---|
| BingooEdgeGateway | `<InstallRoot>\gateway\nginx.exe -p "<DataRoot>\gateway" -c "<DataRoot>\gateway\nginx.conf"` | AtStartup | 999 × 1 min | `<DataRoot>\gateway` | listen port (0.0.0.0:443 / LAB 8443) | `nginx … -s quit`, then Stop-ScheduledTask |
| BingooEdgeWeb1..N | `php.exe "<InstallRoot>\artisan" edge:local:serve --worker=N` (the 0.8.1+ supervisor of one `php -S` child) | AtStartup | 999 × 1 min | `<InstallRoot>` | listen port 127.0.0.1:8090+N-1 | Stop-ScheduledTask (the supervisor's shutdown handler ends its own child) |
| BingooEdgePrintWorker | `… edge:local:print-worker` | AtStartup | 999 × 1 min | `<InstallRoot>` | DB heartbeat row (stale after 90 s) | `edge:local:print-worker --stop --stop-wait=150` (> one 120 s job lease), then Stop-ScheduledTask |
| BingooEdgeSyncSender | `… edge:local:sync-send` | AtStartup + repeat every 2 min, indefinitely | 999 × 1 min | `<InstallRoot>` | outbox SKIP-LOCKED lease | periodic: Stop/Disable-ScheduledTask |
| BingooEdgeAuthorityWorker | `… edge:local:authority-worker` | AtStartup | 999 × 1 min | `<InstallRoot>` | DB singleton + heartbeat (stale after 90 s) | `edge:local:authority-worker --stop --stop-wait=60`, then Stop-ScheduledTask |
| BingooEdgeBackup | `… edge:local:backup` | AtStartup + repeat every 60 min | 999 × 5 min (the packaged Install-EdgeBackupTask value) | `<InstallRoot>` | file lock | periodic |

Common settings (every task): principal `NT AUTHORITY\LOCAL SERVICE` (or an installer-managed `BingooEdgeSvc` local account, no interactive
logon, no admin group; **never SYSTEM, never RunLevel Highest**), LogonType ServiceAccount, RunLevel Limited, StartWhenAvailable,
AllowStartIfOnBatteries + DontStopIfGoingOnBatteries, MultipleInstances IgnoreNew, Hidden, no wake, no network condition (the loopback DB is
local; "Cloud unreachable" is a normal appliance state). Logs: `<DataRoot>\task-logs\<Task>.out.log` / `.err.log` (the web-backend CHILD logs
stay where the 0.8.1 supervisor writes them: `<DataRoot>\logs\web-backend-N.{out,err}.log` + `.status.json`; nothing under
`<InstallRoot>\runtime`). Why `cmd.exe`: Task Scheduler cannot redirect output; the wrapper carries the same non-secret arguments (the
launcher reads `appliance.env` itself), and the plan's secret-shape rule is re-checked on the wrapped line.

**Updater window.** `Update-EdgeAppliance.ps1` step 3 (drain/stop) … step 5 (start) must *disable* every task, not only stop it: with
restart-on-failure and AtStartup active, a crash restart or a reboot in the middle of `edge:local:update` would relaunch the OLD runtime
against a half-staged pointer. Sequence: cooperative stops (print worker, authority worker, `nginx -s quit`) → `Stop-ScheduledTask` all →
`Disable-ScheduledTask` all → `edge:local:update` → re-render the plan (`edge:local:service-plan …` — arguments may change with the
version) → re-register from the new plan (`Register-EdgeTasks.ps1 -Apply`, idempotent `-Force`) → `Enable-` + `Start-ScheduledTask` in the
§2 order → health. The packaged `Register-EdgeServices.ps1 -Action Stop` already disables; `-Action Start` re-enables — the design keeps that.

**Elevation requirement (owner at the keyboard).** `Register-ScheduledTask` with a service-account principal, `icacls` grants to that
principal and the firewall rule need an elevated shell; on this laptop the account is UAC-filtered (`EnableLUA=1`, consent prompt), so no
agent session can do it (P5B §2 finding). The dry run prints the exact `icacls` lines (config/certs: SYSTEM + Administrators full, service
account RX; logs/backups/gateway/task-logs: modify; install root RX; active runtime `bootstrap\cache` + `storage`: modify).

**Validated on labcopy-c (print mode only) — `07-register-edgetasks-dry-run.txt` (268 lines).** Plan validation 7/7 OK (exe + workdir
exist, no secret shape, principal not SYSTEM); per task: the settings table, the `schtasks /Create … /SC ONSTART /RU "NT AUTHORITY\LOCAL
SERVICE" /RL LIMITED /NP /TR "cmd.exe /d /c …"` line (with `/RI 2 /DU 9999:59` resp. `/RI 60` for the periodic ones; schtasks cannot express
restart-on-failure / StartWhenAvailable / Hidden / IgnoreNew — the PowerShell block does), the equivalent `New-ScheduledTaskAction /
-Trigger / -SettingsSet / -Principal / Register-ScheduledTask -TaskPath '\BingooEdge\' … -Force` block, the start order, cooperative stop,
updater-window disable/enable, status and unregister commands, and the §4 prerequisites. After the run: `Get-ScheduledTask | ? TaskName
-like 'BingooEdge*'` = **0** (nothing registered). `-Apply` additionally refuses a non-elevated shell, a SYSTEM principal and any validation
problem; it registers WITHOUT starting.

## 2. Windows startup / reboot recovery

**Today (P5C, real LAB):** nothing restarts. The 7 plan entries run as hidden user processes started by `Start-EdgeLabWorkers.ps1` (pid
record `data\lab-workers.json`); the periodic entries are `Run-Periodic.ps1` PowerShell loops; MySQL 8.0.30 is a Laragon child process
(`mysqld` pid 21512 under 19304, **not a Windows service** — `Get-Service` lists no mysql/maria service), the LAB Cloud is a `php -S` on
9701, the fake printer a `php` on 9100. After a reboot: no MySQL, no Cloud, no printer, no appliance process; the Cloud lease lapses (TTL
120 s) and the appliance reports nothing until the owner runs the start scripts by hand. `REBOOT_AUTO_START` has been "not certified" since P5.

**Target:** MySQL as a Windows service (StartType Automatic, Recovery = restart; on the LAB: a MySQL service pointing at the Laragon data
dir, or the Laragon "Run as service" option — owner decision), the six tasks + gateway registered per §1 (AtStartup, StartWhenAvailable so a
boot that happened while the task was disabled still runs it), `startup_db_retry` in every worker (`waitForDatabase`: 20 × 3 s = 60 s, then
exit 1 → task restart after 1 min → effectively unbounded), firewall rule for `<InstallRoot>\gateway\nginx.exe`.

**Ordered recovery sequence with health checks** (Task Scheduler starts all AtStartup tasks in parallel; the dependencies are enforced by
the workers' own retries, the order below is what the operator VERIFIES, and what a manual start follows):

| step | what | check (non-elevated) | expected by |
|---|---|---|---|
| 0 | Windows boots; MySQL service up | `Test-NetConnection 127.0.0.1 -Port 3306` true; `Get-Service mysql*` Running | T+60 s |
| 1 | **Gateway** (no DB needed) | `Get-NetTCPConnection -State Listen -LocalPort 8443` → nginx pid; `gateway\logs\gateway-error.log` no `bind()` error | T+70 s |
| 2 | **Web backends** (supervisors spawn `php -S`, 15 s startup grace, then HTTP probes) | `<DataRoot>\logs\web-backend-{1,2}.status.json` state `healthy`; `curl https://<host>:8443/edge/local/health` 200 via the gateway (502 until a backend answers — expected for ≤ 30 s) | T+100 s |
| 3 | **Print worker** acquires the singleton row | `edge:local:print-status`: worker `running`, heartbeat age < 10 s; queue counts unchanged (leases that were live at the crash expire after ≤ 120 s and are re-claimed — at-least-once) | T+120 s |
| 4 | **Sync sender** (first periodic run at startup, then every 2 min) | `edge:local:sync-status --json`: outbox pending drains once the Cloud answers | first run T+60–120 s |
| 5 | **Authority worker** acquires its row; first heartbeat | `edge:local:health`: `workers.authority_worker.running` true, `authority.last_heartbeat_ack_at` advances, connection `online`, state `standby`; the Cloud lease returns to `holder cloud` (it lapsed during the reboot — expected, no takeover: AUTO_FAILOVER=false) | T+150 s |
| 6 | **Backup** (startup run, then hourly) | `edge:local:health`: `backup.last.created_at` within the hour | T+5 min |
| 7 | Overall | `edge:local:health --fail-on-problems` exit 0, status **STANDBY_READY** | T+5 min |

**Duplicate-exit trap after a FAST reboot (design gap G10, affects print worker and authority worker).** Both singleton workers treat a
`running` row with a heartbeat younger than 90 s as a live other worker and **exit 0** ("another worker is already RUNNING — exiting
cleanly"). If the machine reboots in under 90 s from the last heartbeat (an SSD laptop does), the AtStartup task completes successfully
and Task Scheduler's restart-on-FAILURE does not fire: the worker stays down until the next boot or a manual `Start-ScheduledTask`. The
recovery sequence therefore includes the explicit re-check at T+120 s (steps 3/5: heartbeat age < 10 s; if the task's `LastTaskResult` is 0
and the worker is `stale`, `Start-ScheduledTask` it again). Proper fix for 0.8.3 (not now, code frozen): the duplicate path returns a
non-zero code or waits out the stale window before deciding; the certification drill below must observe it either way.

**Reboot drill procedure (owner, not executed):** (1) `Invoke-EdgeCertification.ps1 -Phase PreReboot -InstallRoot … -EvidenceDir …`
(health marker); record `Get-EdgeProcessTree.ps1 -InstallRoot … -Json before.json`. (2) Cooperative stop is NOT run — a drill simulates a
power loss: `Restart-Computer` from an elevated shell. (3) After logon: do NOT start anything by hand; run the step table above every 30 s
for 5 min (`Get-EdgeProcessTree.ps1`, status files, `edge:local:print-status`, `edge:local:health`); then `-Phase PostReboot` (tasks running,
ports, seconds-after-boot per task from `Get-ScheduledTaskInfo.LastRunTime` vs `LastBootUpTime`, heartbeat resumed, standby). (4) Repeat
once with a reboot started < 60 s after a fresh heartbeat to exercise G10. Pass: every task Running within 5 min, STANDBY_READY, outbox
and print queue counts equal to the marker, no second worker row, authority never `local_active`, 0 orphaned processes
(`Get-EdgeProcessTree.ps1` shows exactly the plan's tree).

## 3. Service ownership and restart isolation

| process | real LAB today (P5C) — how started / pid record / who may stop / how | target (Scheduled Tasks) |
|---|---|---|
| nginx master + worker (0.0.0.0:8443/8081) | `Start-EdgeLabWorkers.ps1 -Action Start` (hidden, by plan path); `data\lab-workers.json` BingooEdgeGateway; listed 30 Oct: master 400800, worker 356300 | task BingooEdgeGateway as LOCAL SERVICE |
| web backend supervisors + `php -S` children (127.0.0.1:8090/8091) | same runner; record BingooEdgeWeb1 414556 (child 276036), BingooEdgeWeb2 364668 (child 427840) — the children are NOT in the record (the supervisor owns them; it `taskkill /PID <own child> /T /F`s on hang/stop) | tasks BingooEdgeWeb1/2 |
| print worker | record BingooEdgePrintWorker 412556 | task BingooEdgePrintWorker |
| sync sender | `powershell.exe Run-Periodic.ps1` loop 405264 spawning `php … sync-send` every 2 min | task BingooEdgeSyncSender (repetition trigger, no loop process) |
| authority worker | record BingooEdgeAuthorityWorker 366572 | task BingooEdgeAuthorityWorker |
| backup | PowerShell loop 363064, hourly `php … backup` | task BingooEdgeBackup |
| conhost.exe ×7 | Windows console hosts of the hidden processes (descendants) | none (tasks run without a console) |
| who may stop | ONLY `Start-EdgeLabWorkers.ps1 -Action Stop` (nginx `-s quit` by the plan path, then `taskkill /PID <recorded pid> /T /F` per recorded pid, record removed) — or an owner directive naming the pid | cooperative flags (§1) then `Stop-ScheduledTask`; emergency: `Stop-Process -Id <pid from Get-EdgeProcessTree.ps1>` — never by name |
| MySQL, LAB Cloud (9701), fake printer (9100), dev clones (9704/8095/8096), other agents' harnesses (8190/8191/8281/8290/8291/9705) | NOT appliance processes; never in the record; untouchable from any Edge script | unchanged |

Rule set that prevents the 25 Oct cross-environment kill (every agent brief carries it; the scripts enforce it):

1. **Scope by directory, never by name.** A process belongs to an environment only when its command line names that environment's install
   root (or data root); `php.exe`, `nginx.exe`, `powershell.exe` name nothing. `Get-Process -Name`, `taskkill /IM`, `Stop-Process -Name` and
   wildcards are forbidden in any Edge script or session.
2. **Record before you start, stop only what you recorded.** Every spawned process goes into a pid record (`lab-workers.json`,
   `evidence\<agent>\pids.json`) with name, executable and start time; a stop names the recorded pid (`taskkill /PID … /T` for a tree) and
   writes the stop time; a record is never reused after a stop.
3. **Verify identity before a kill**: pid alive AND its command line still names the expected root AND (for a tree) the parent chain starts
   at a recorded pid — pid reuse after a crash otherwise kills a stranger. `Get-EdgeProcessTree.ps1 -PidRecord` prints `recorded_as` per
   live pid and the `missing` list for exactly this check.
4. **One supervisor per singleton.** The OS port (backends, gateway), the DB heartbeat row (print, authority), the outbox lease and the
   backup lock make a duplicate start harmless; nothing else may "clean up" a duplicate by killing — it exits by itself.
5. **Cooperative before forced**: `--stop` flags / `nginx -s quit` first, forced termination only after the wait (150 s print, 60 s
   authority) and only on the recorded pid.
6. **Disposable copies get their own roots, DB names, ports and pid records** (labcopy-081: 8290/8291/8643/8281; labcopy-c:
   8390/8391/8743/8381) so a scoped query can never match two environments.

`Get-EdgeProcessTree.ps1` (read-only; parameter `-InstallRoot`, auto-reads `appliance.json` for the data root, `-PidRecord`, `-Json`,
`-NoDescendants`; redacts any command line that matches a secret shape):
- against labcopy-c before anything ran: **0 processes** (`04-process-tree-labcopy-c-before.*`); after the print-worker run and stop:
  **0 processes** (`09-process-tree-labcopy-c-after.json`);
- against the REAL LAB root, listing only (`04-process-tree-real-lab-readonly.*`): **17 processes = 10 by command line + 7 conhost
  descendants**; pid record `data\lab-workers.json`: 7 entries, **7 alive under this root, 0 missing**; listeners 0.0.0.0:8443/8081 (nginx
  worker 356300), 127.0.0.1:8090 (php -S 276036, started 08:38:16Z), 127.0.0.1:8091 (427840, 08:38:13Z). Nothing was stopped.

Read-only observation for the owner (from `data\BingooEdge\logs\web-backend-1.status.json` at 08:29 UTC and the child start times above):
the real LAB's 0.8.1 web tier is still being restarted by the 0.8.1 liveness policy under the gate's host load — backend #1 `restarts
69`, `last_restart_reason "hung: 3 liveness probes failed"`, both children re-spawned at 08:38 UTC. This is F-FB5-1 as recorded on 29 Oct;
it supports installing 0.8.2 on the LAB when the owner decides. Not touched.

## 4. Diagnostics — what to collect when the cashier tier is slow or hung

Collect in this order (none of it needs elevation; none of it contains a secret):

| # | item | real LAB path (read-only) |
|---|---|---|
| 1 | supervisor status files (classification, pid, restarts, last probe outcome/ms, seconds since last 200, busy ticks, policy — 0.8.2 fields) | `C:\Users\Dell\BingooEdgeLab\data\BingooEdge\logs\web-backend-1.status.json`, `web-backend-2.status.json` |
| 2 | backend child logs (`php -S` request log = the progress signal) | `…\data\BingooEdge\logs\web-backend-{1,2}.err.log` (404/398 KB on 30 Oct), `.out.log`, rotated `.1.log` |
| 3 | supervisor task output (every transition: starting/healthy/busy/suspected/confirmed/restarting/failing) | P5C: `C:\Users\Dell\BingooEdgeLab\data\worker-logs\BingooEdgeWeb{1,2}.out.log`; target: `<DataRoot>\task-logs\BingooEdgeWeb{1,2}.out.log` |
| 4 | gateway logs (502s = restart moments; upstream timeouts) | `…\data\BingooEdge\gateway\logs\gateway-access.log`, `gateway-error.log`, `error.log`, `gateway.pid` |
| 5 | application log (daily) | `…\data\BingooEdge\logs\edge-YYYY-MM-DD.log` |
| 6 | health JSON (web_backends: listening / responding / availability responding·busy·unavailable + supervisor block; authority; sync; print) | `php …\install\BingooEdge\artisan edge:local:health --json` (or `scripts\Get-EdgeHealth.ps1 -InstallRoot … -Json -Services`) |
| 7 | print and sync status | `… edge:local:print-status --json`, `… edge:local:sync-status --json` |
| 8 | process tree scoped to the install root + pid record + listening ports | `Get-EdgeProcessTree.ps1 -InstallRoot …\install\BingooEdge -PidRecord …\data\lab-workers.json` |
| 9 | host load (the 29 Oct and 30 Oct restarts correlate with 86–100 % CPU from other sessions) | `Get-CimInstance Win32_Processor` LoadPercentage, memory, uptime, disk |
| 10 | plan + gateway config + runtime pointer (what SHOULD be running) | `…\data\BingooEdge\gateway\service-plan.json`, `nginx.conf`, `…\install\BingooEdge\runtime\current`, `appliance.json` |

`Collect-EdgeDiagnostics.ps1 -InstallRoot <root> -OutputPath <dir> [-PidRecord …] [-WorkerLogDir …]` copies all of the above into
`<dir>\edge-diag-<host>-<UTC stamp>\{layout,gateway,logs,worker-logs,processes,reports,host.json,manifest.json}` (full copy ≤ 4 MB, else the
last 3,000 lines; sha256 per file in the manifest). It never copies `appliance.env`, `*.key`, `*.pfx`, `*.enc`, the LAB password files, and
removes any collected text file that matches a secret shape. **Validated against labcopy-c only** (bundle
`evidence\agent-c\edge-diag-DESKTOP-0024EPM-20261030T084014Z`, 9 manifest files + host/manifest: layout, current pointer, service plan,
nginx.conf, listening ports, scoped process tree (showed the running copy print worker, recorded_as = the pids.json entry), health,
print-status, sync-status; 0 secret-material files; the copy had no web-backend/gateway logs yet, so those sections were empty by design).
For the real LAB the same command with `-PidRecord C:\Users\Dell\BingooEdgeLab\data\lab-workers.json -WorkerLogDir
C:\Users\Dell\BingooEdgeLab\data\worker-logs` is read-only — not run in this directive.

## 5. Print worker — failure and retry behaviour

### 5.1 From the code (0.8.1 = 0.8.2 for printing; `EdgeLocalPrintWorkerCommand`, `EdgeLocalPrintWorkerSupervisor`, `EdgeLocalPrintDeliveryService`, `EdgeNetworkPrinterTransport`, shared `PrintJobService`)

| aspect | behaviour |
|---|---|
| process | `edge:local:print-worker [--once] [--max-jobs=N] [--idle-sleep=3] [--stop --stop-wait=30]`; branch_server only; maps the tenant connection to the Edge DB; waits ≤ 60 s for the DB (20 × 3 s), then **exit 1** (supervisor restarts); claims the singleton row (`edge_local_print_worker_state`): refuses when another worker's heartbeat is < 90 s old → **exit 0** ("duplicate"); heartbeat every loop iteration; cooperative stop = DB flag checked between jobs; no pcntl on Windows |
| job states (shared `print_jobs.print_status`) | `queued` → `printed` (markPrinted, idempotent, sale counters) / `failed` (markFailed, once, at the terminal failure) / `cancelled` (operator dismiss) |
| delivery states (Edge-only `edge_local_print_deliveries`) | `waiting` → `leased` (64-hex lease token, 120 s) → `delivered` \| `retry_wait` (next_attempt_at) → … → `terminal_failed`; a lease token is the ONLY completion authority; an expired lease is stale even if nobody re-claimed |
| claim rules | queued, printer_id not null, printer active + `network` + has ip, branch NULL or bound; no live lease, no pending backoff, not delivered/terminal; **per-printer FIFO** (a newer job never overtakes an older leased/backoff job on the same printer; a terminal_failed older job does not block); 3 deadlock retries |
| transport | TCP connect timeout **8 s**, write timeout **8 s**, payload = stored `raw_payload` + `"\n\n\n"` (Cloud agent parity); socket success ≠ paper |
| retry ladder | temporary failure N → `retry_wait` with backoff **5, 15, 30, 60, 120 s** (N = 1..5); failure **#6 is terminal**: delivery `terminal_failed`, shared `markFailed` runs once → `print_status=failed`, `attempts` +1, `failed_at`, `error_message` |
| printer unreachable (wrong IP / powered off / cable out) | connect times out after 8 s per attempt → 6 attempts over ≈ 4 min 40 s → failed; worker keeps running and serves other printers |
| printer refuses (port closed, wrong port, printer rebooting) | immediate refusal (WSAECONNREFUSED 10061) → same ladder, ≈ 4 min 6 s to failed (**observed below**) |
| printer busy (raw 9100 accepts one connection; KOT + receipt seconds apart) | refusal or timeout → retry after 5 s (first slot), FIFO keeps the kitchen order; the Cloud agent needed `MAX_AUTO_REQUEUE` for the same collision — on Edge the ladder covers it |
| paper out / cover open / offline-but-listening | **invisible to the code**: the printer's TCP stack accepts the bytes into its buffer, the write succeeds → `printed` (at-least-once contract, no ESC/POS status query) |
| worker crash mid-write | lease expires after 120 s → re-claimed by the restarted worker → possible duplicate paper (documented at-least-once); nothing rewrites leases on start/stop |
| operator retry / dismiss (cashier UI "Recent Prints", routes `edge.local.pos.print-jobs.retry` / `.dismiss`) | retry only a `terminal_failed` delivery or a `cancelled` job (shared `requeueFailed`: status queued, error/failed_at/claim cleared) + delivery reset to waiting/failure_count 0; dismiss refuses a job with a live lease ("being sent right now") |
| surfaced in health (`edge:local:health`) | `workers.print_worker` {state running\|stale\|stopped\|not_installed\|unavailable, heartbeat age, last_error} → problem "Local print worker is <state>" when not running; `print.printers` (edge_capable), `print.jobs_by_status` counts, `print.network_printers_edge_direct`; USB printers → problem; **no problem entry for failed jobs, no printer reachability probe** |
| surfaced in print-status (`edge:local:print-status --json`) | worker block, printers (ip/port/role), queue: queued_printer_jobs, waiting_never_attempted, leased, retry_wait, delivered, terminal_failed, unresolved_null_printer_intents, oldest_queued_at |

### 5.2 Disposable observation on labcopy-c (printer row of the COPY DB set to 127.0.0.1:**9**; one synthetic queued job #34 inserted; no real print job, nothing on 9100, the LAB DB printer row verified unchanged at 9100 before and after)

| UTC | event | evidence |
|---|---|---|
| 08:39:27 | worker started hidden via the copy launcher (`php.exe "<copy>\artisan" edge:local:print-worker --idle-sleep=3`), **pid 418180** recorded in `pids.json` | `05-print-worker.out.log`: "print-worker 644472e3… started (lease 120s, heartbeat stale after 90s)"; it took over the dumped LAB worker row (heartbeat from 29 Oct = stale) |
| 08:39:33 | claim #1, connect refused in < 1 s: "printer connect failed [127.0.0.1:9]: No connection could be made because the target machine actively refused it (10061)" → delivery `retry_wait`, failure_count 1, next attempt +5 s | delivery row `created_at 08:39:33` |
| 08:39:41 … 08:41:36 | attempts #2–#5 after 15 / 30 / 60 / 120 s backoff (each refused at once) — `print_status` stays **queued** the whole time (temporary failures never touch the shared status) | snapshot t6 (08:41:34): queued 1, **leased 1**; t12 (08:43:14): queued 1, **retry_wait 1**, terminal 0 |
| 08:43:37–08:43:39 | attempt #6 = terminal: delivery **terminal_failed**, failure_count **6**, lease cleared, last_error kept; shared markFailed → `print_jobs` **failed**, `attempts 1`, `failed_at 08:43:39`, `error_message` = the transport error. 246 s from first to sixth attempt = the 230 s ladder + six claim cycles ≤ 3 s idle sleep | `06-job34-final-rows.txt`; t18 (08:44:55): queued 0, **terminal_failed 1**; `06-print-status-final.json` |
| 08:45:51 | health while the worker runs: `workers.print_worker` running (heartbeat age 1 s), `print.jobs_by_status {printed 33, failed 1}`, printer port 9 shown, **problems list does not mention the failed job** (only the copy's stopped workers/backends/backup age) | `06-health-during-worker.json` |
| 08:47:26 → 08:47:34 | cooperative stop `edge:local:print-worker --stop --stop-wait=30` → "local print worker stopped gracefully"; worker stdout "cooperative stop requested — exiting after the current cycle. … stopped after 6 job(s)"; pid 418180 gone by itself (no Stop-Process needed); row `stopped`, `last_graceful_stop_at 08:47:30`, `last_error NULL` | `08-cooperative-stop.txt`, `10-worker-state-after-stop.txt`, `pids.json` (stopped_utc, stop_method) |
| 08:47:40 | `Get-EdgeProcessTree.ps1` on the copy root: 0 processes | `09-process-tree-labcopy-c-after.json` |

The worker's stdout carried every failure line; **the application log channel got nothing** (`labcopy-c\data\BingooEdge\logs` stayed
empty; the runtime's `storage\logs` only `.gitkeep`). The 15-s DB observation loop's own query lines came back empty under PowerShell
(a quoting artefact of the loop, not of the appliance — the same query returned rows from bash), so the timeline above is reconstructed from
the DB rows, the three print-status snapshots and the worker stdout. The synthetic row's `print_jobs.created_at` reads 13:38:57 (the
mysql client session's local time; the appliance writes UTC) — cosmetic, disposable DB only.

### 5.3 Gaps (no code change in this directive; candidates for 0.8.3)

- **G1 failed jobs are silent in health**: a `terminal_failed` delivery / `failed` job is only a count (`jobs_by_status`, `queue.terminal_failed`); an operator sees it in the cashier "Recent Prints" dialog or `print-status`. Proposal: problem line "N print job(s) failed on printer <name> — retry or dismiss from Recent Prints".
- **G2 no printer reachability signal**: health lists the printer ip/port but never probes TCP 9100; the first evidence of a dead printer is a job sitting in retry_wait. Proposal: a bounded (1 s) connect probe per edge-capable printer in health/print-status, reported as reachable/unreachable (never as a job).
- **G3 delivery failures are not logged**: only the worker's stdout; without the §1 redirection a Scheduled-Task worker loses them. Proposal: `Log::warning` per failure and per terminal failure (non-secret: job id, printer id, error).
- **G4 latent queued-but-unclaimable state**: Edge calls the shared `markFailed`, which auto-requeues (status back to `queued`, attempts < 3) when the error text matches the Cloud agent's RETRYABLE list (`econnrefused`, `etimedout`, …). Windows PHP error texts ("actively refused", "did not properly respond") never match — observed `attempts 1 / failed` — but any platform or future transport whose message matches would leave `print_status=queued` with delivery `terminal_failed`: the claim query skips it, and the UI retry refuses it ("Only failed or cancelled jobs can be retried"). Proposal: Edge terminal path marks failed WITHOUT the auto-requeue branch (or the claim query treats `queued + terminal_failed` as waiting).
- **G5 paper-out / cover-open / offline-with-buffer = printed** (at-least-once by design; no ESC/POS `DLE EOT` status query). Accept for the pilot with the cashier reprint path; a status query is a transport extension for later.
- **G6 the print worker has no liveness supervisor**: Task Scheduler restarts only an EXITED process; a worker alive but wedged (DB hang) just goes `stale` in health. The supervised web tier got one in 0.8.1; the print/authority workers rely on `stale` being seen by an operator. Proposal: the task wrapper exits non-zero when its own heartbeat row is stale > 180 s (or the 0.8.3 duplicate/stale handling, G10).
- **G10 duplicate-exit after a fast reboot** (see §2): exit 0 within 90 s of the previous heartbeat leaves the worker down until the next boot.
- **G7 `attempts` semantic**: an Edge terminal failure shows `attempts 1` (Cloud semantic "markFailed transitions"), while the Edge ladder made 6 attempts (`failure_count`). Documentation note for support, not a defect.

## 6. Physical-printer certification plan (P5 kit; plan only — nothing executed, the physical LAB printer untouched)

Prerequisites (owner): a thermal LAN printer with raw TCP 9100 enabled, DHCP-reserved IP on the Edge laptop's LAN (the 192.168.1.x segment
behind the extender), UPS/same power strip as the switch; the LAB tenant's printer row (Cloud `printers`, tenant `edgehomelab`) pointing at
that IP:9100, `is_active`, `printer_type network`, `print_role both`, branch 1 — then `edge:local:bootstrap-pull` (or an authority tick's
config refresh) so the appliance's `printers` row carries the same IP (the appliance never takes a printer address from anywhere else);
`Start-FakePrinter.ps1` stopped for the duration (so no job can land on 9100 of the laptop by mistake); the P5C LAB in STANDBY_READY; the
owner's **separate approval of a supervised LAB-only Local Mode takeover window** — a real KOT/receipt only arises from a local sale, and
the warm standby refuses sales (422 STANDBY). Without that window steps 4–9 cannot run; steps 1–3 and 10 can.

| step | action (kit / command) | evidence to capture | pass |
|---|---|---|---|
| 1 | `Invoke-EdgeCertification.ps1 -Phase Preflight -InstallRoot <LAB root> -EvidenceDir <dir> -PrinterIp <ip>` | `Preflight-preflight.json`: `printer_9100 true`; `Test-NetConnection <ip> -Port 9100` from the Edge laptop | reachable |
| 2 | `edge:local:health --json`, `edge:local:print-status --json` before any print | printer row id/ip/port/role, `edge_capable true`, worker running, queue all zero | matches the Cloud row |
| 3 | `-Phase Lan -PrinterIp <ip> -CashierIp <cashier> -CloudHost <cloud>` with the WAN cable OUT (owner pulls it) | `Lan-lan.json`: edge_to_printer_9100 true while cloud/internet false | printer reachable without WAN |
| 4 | supervised takeover (`edge:local:authority-takeover --confirm`, owner) → cashier laptop: dine-in order with 2 lines → fire KOT | photo of the KOT; `print_jobs` row (document_type kot, printed, printed_at), delivery `delivered`, `failure_count 0`, worker stdout "job N: delivered" | ONE KOT, correct lines, ≤ 10 s |
| 5 | addition round then a cancellation KOT for one line | two more tickets in order; the kitchen order = job id order (FIFO) | no overtaking, no duplicate |
| 6 | Review & Pay (cash) → receipt | receipt paper; `print_jobs` receipt printed; `logical_key receipt:final:sale-N` unique | ONE receipt |
| 7 | reprint from Recent Prints | a second receipt with the SAME receipt number; a new job row (copy_no 2), never a new sale identity | identity preserved |
| 8 | **busy collision**: KOT and receipt within 2 s (Direct Pay) | both printed; delivery rows may show `failure_count 1` with a 5 s retry on the second | both on paper, FIFO |
| 9a | **printer powered OFF**, fire a KOT | `retry_wait` ladder visible in `print-status` every 30 s (leased→retry_wait, failure_count climbing), connect timeout ≈ 8 s per attempt; after ≈ 4 min 40 s `terminal_failed` + `failed`; health shows the worker still running (other printers unaffected) | failed within 5 min, worker alive |
| 9b | power the printer ON → Recent Prints → Retry | job queued → delivered, ONE ticket | retry prints once |
| 9c | **paper out** (open cover), fire a KOT, then close the cover | document what happens: expected `printed` while the printer buffers (G5); the ticket prints when paper returns; if the printer refuses the connection instead, the 9a ladder applies | behaviour recorded, no duplicate when paper returns |
| 9d | printer rebooted while a job is leased | lease expiry / retry → ONE ticket after the printer is back | ≤ 1 duplicate acknowledged as at-least-once, recorded |
| 10 | handback (`edge:local:authority-handback`, owner) and Cloud ingestion | Cloud `print_jobs` for the ingested sale: **no new job** (EdgeNoDuplicatePrintAfterSync contract); FakePrinter not restarted until the window is closed | 0 Cloud-side prints |
| 11 | `-Phase Health`, `-Phase Security`, `-Phase Report` | `certification-report.json`; worker stdout/task log; `Get-EdgeProcessTree.ps1` before/after | no secret shape, process tree = plan |

Pass/fail: every row's "pass" column met; `PHYSICAL_KOT_PRINT`, `PHYSICAL_RECEIPT_PRINT`, `NO_DUPLICATE_PHYSICAL_PRINT` (steps 4–8, 10),
`PRINTER_OFFLINE_RECOVERY` (9a/9b), `PAPER_OUT_BEHAVIOUR_RECORDED` (9c) and `EDGE_TO_PRINTER_LAN_WITHOUT_WAN` (3) become `yes`; any
duplicate paper in 4–8/10 or a stuck `queued` job (G4) is a FAIL. Rollback after the window: handback, FakePrinter restarted, printer row
in the Cloud left as is (a LAB tenant), evidence under `evidence\release-<v>\physical-printer\`.

## 7. P6 pilot readiness checklist (status 30 Oct 2026)

| area | item | status |
|---|---|---|
| hardware | dedicated Branch Server (not the shared dev laptop), UPS for server + switch + printer | **missing** (LAB = owner laptop, no UPS record) |
| hardware | thermal LAN printer with raw 9100 at the pilot branch; USB printers = ONLINE_REQUIRED | **missing** (no raw-9100 device on any LAN so far; P5 §9, P5B §11) |
| Windows | Windows 10/11 Pro, Administrator at install, UAC consent available at the keyboard | evidence: this laptop is UAC-filtered — a pilot box needs an admin session (**missing**) |
| Windows | MySQL/MariaDB as a Windows service, Automatic, loopback only, dedicated `bingoo_edge_*` DB + user | **missing** (LAB MySQL is a Laragon child process) |
| Windows | DHCP-reserved IP + hosts/router DNS for the LAN hostname; firewall allow for `<InstallRoot>\gateway\nginx.exe` only | partial: LAB uses mDNS `DESKTOP-0024EPM.local`, Windows-prompt rules exist (P5C §4); pilot router config **missing** |
| install | signed release package + custody (keystore a2eaf8a3d3e66a48), `Install-EdgeAppliance.ps1` elevated end-to-end, ACLs applied | package: done (0.8.1 b44efaa3…, 0.8.2 build in progress by the release session); elevated install + ACLs **not yet run on any box** |
| pairing | one-time pairing code, bootstrap-pull, enrollment of local users, Cloud-escrowed recovery key | done on the LAB (device 210c73f8…, key brk_01m2…); pilot tenant/branch pairing **pending the owner's tenant decision** |
| supervision | Scheduled Tasks as LOCAL SERVICE per §1 (`Register-EdgeTasks.ps1` dry run validated), `-Phase Tasks` + `-Phase Crash` evidence | design done; registration/crash **owner-only (elevation)**; G10 fast-reboot trap documented |
| reboot | §2 sequence + drill, `-Phase PreReboot/PostReboot` | **not executed** (reboot = owner) |
| 0.8.2 | busy-aware web supervision on the LAB | built/tested on the disposable copy (0.8.2 record); LAB still on 0.8.1 and restarting under load (§3 observation) — **owner decision pending** |
| backups | hourly encrypted backups, recovery key escrowed, replacement-machine restore | done on the LAB (hourly `edge-backup-*.enc`, restore proven in P5/P5B); off-box copy of backups (NAS/USB) **missing** |
| printers | §6 certification | **missing** (needs the printer + Local Mode window) |
| network | WAN-down with Edge observing Cloud loss, LAN alive, cashier→Edge, Edge→printer | **not run** (LAB Cloud sits on the laptop loopback; needs the owner's cable pull / second host) |
| acceptance | shared POS parity on the second laptop (0.7.0 checklist 01–20), geometry NONE | partial (0.8.1 record §10: PARTIAL, two 502s = F-FB5-1); full laptop walk **pending** |
| rollback | pointer rollback + backup restore procedures | documented and rehearsed on disposable copies (0.8.1 record §8); pilot runbook copy **missing** |
| monitoring | health JSON + status files; alerting on DEGRADED / FAILING / print worker stale / failed jobs | health exists; **no alerting**; G1/G2 gaps |
| diagnostics | `Collect-EdgeDiagnostics.ps1`, `Get-EdgeProcessTree.ps1` | done (validated on labcopy-c) |
| operator runbook | start/stop/update/rollback/diagnose/printer-failure/reboot one-page runbook for the branch | **missing** (§1–§6 of this document are the source) |
| ownership rules | §3 rule set in every script and brief | done |

```
AGENT_C_SCOPE=planning + disposable validation only
LABCOPY_C=built (install root copy 35,962 files, data root, appliance.env 23 keys changed, DB bingoo_edge_agentc_local from the 0.8.1 rehearsal dump, plan rendered, health = copy DB + pointer 0.8.1-edge)
SCRIPTS_WRITTEN=Register-EdgeTasks.ps1 (dry-run validated, 0 tasks registered) · Get-EdgeProcessTree.ps1 (labcopy-c 0/0, real LAB 17 listed read-only, 7/7 recorded pids alive) · Collect-EdgeDiagnostics.ps1 (labcopy-c bundle, 0 secret files)
PRINT_WORKER_DEAD_PRINTER=observed on labcopy-c: 6 attempts over 246 s (5/15/30/60/120 s ladder) -> delivery terminal_failed, print_jobs failed/attempts 1; worker stayed running, graceful --stop in 8 s; pid 418180 started 08:39:27Z, exited 08:47:34Z
PIDS_STARTED=418180 (labcopy-c print worker)   PIDS_STOPPED=418180 (cooperative, exited by itself)   OTHER_PROCESSES_TOUCHED=none
OWNER_ONLY=task registration (-Apply, elevation), icacls, firewall, MySQL service, reboot drill, 0.8.2 LAB install decision, physical printer + Local Mode window, WAN pull
REAL_LAB_MUTATED=NO   PRODUCTION_MUTATED=NO   LAB_PRINTER_9100_TOUCHED=NO   REAL_PRINT_JOBS=0
```
