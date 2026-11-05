# AGENT F — service and printer reliability, DEVELOPMENT + DISPOSABLE validation (owner Phase F, 30 Oct 2026)

Continuation of Agent C (`edge-agent-c-service-reliability.md`, findings G1–G10). Scope: resolve or disposition, before P6, (1) the MySQL
startup dependency after a Windows reboot, (2) the Scheduled-Task workers exiting as "duplicate" when the previous heartbeat is < 90 s
old (G10), (3) print-worker terminal failures absent from overall health (G1), (4) no printer reachability detection (G2), (5) scoped
supervisor/service recovery diagnostics. Authority fencing (lease / takeover / handback) is NOT touched — only WHICH PROCESS runs a loop.

Code: NEW worktree `D:\laragon2\www\pos-saas-rel-083`, branch **`rel/0.8.3-service-reliability`** from `a6113772` (the signed 0.8.2 commit;
the release worktree `pos-saas-edge` stays frozen). Vendor robocopied from the Edge worktree (composer.lock byte-identical; no composer
install). Commits: `a161bc1c` (code + tests + system map + this record with placeholders) and the follow-up docs commit that filled the
gate results below. Nothing pushed; production checkout untouched.

The real LAB (install root `C:\Users\Dell\BingooEdgeLab\install`, data root `…\data`, ports 8081/8090/8091/8443/9701/9100, DBs `pos_lab_*` /
`bingoo_edge_lab_*`) was never read, started, stopped or written. Every run below happened on the disposable copy **labcopy-c**
(`C:\Users\Dell\BingooEdgeLab\labcopy-c`, DB `bingoo_edge_agentc_local`, ports 8390/8391/8743/8381, dead Cloud URL, invalid device secret,
printer row 127.0.0.1:**9**) or on the isolated MySQL test set `pos_test_*_relf`. Nothing was ever sent to port 9100. **REAL_LAB_MUTATED=NO.**

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\agent-f\` (files `00`–`10`, `pids.json`, `task-logs\`).

## 0. What changed in the code (rel/0.8.3-service-reliability, on top of a6113772)

| area | file(s) | change |
|---|---|---|
| F-1 MySQL readiness | `app/Services/Edge/EdgeLocalDbReadiness.php` (new), `app/Console/Commands/EdgeLocalWaitDbCommand.php` (new, `edge:local:wait-db`), `config/edge.php` (`edge.workers.db_startup_wait_seconds` = `EDGE_WORKER_DB_STARTUP_WAIT` default **180 s**, interval 3 s; allowlist), `EdgeSupervisionPlan` task fields `startup_db_wait_seconds` + `startup_db_wait_command`, both worker commands | ONE classified probe (raw PDO, `ATTR_TIMEOUT` 5 s, `SELECT 1`, `SHOW TABLES LIKE 'edge_local_meta'`): `ready` / `refused` / `timeout` / `auth_failed` (1045/1044) / `unknown_database` (1049) / `not_initialised` / `not_configured` / `error`. `await()` repeats at a fixed interval until ready, until a NON-retryable class, or until the budget is spent. The workers replaced their 20×3 s "any exception" loop with it (exit 1 + named cause; the task restarts). `wait-db` exits 0 ready / 1 retryable / 2 configuration defect. Non-secret (host, port, db name, class, errno; never user/password). |
| F-G10 heartbeat fix | `app/Support/EdgeProcessLiveness.php` (new), `app/Services/Edge/EdgeWorkerSlotPolicy.php` (new), `EdgeLocalPrintWorkerSupervisor`, `EdgeLocalAuthorityWorkerSupervisor`, `EdgeLocalPrintWorkerState` model, migration `database/migrations/edge/2026_10_30_000001_add_process_identity_to_edge_worker_state.php` (adds nullable `worker_pid`, `worker_process_token`, `worker_host` to BOTH singleton tables), both worker commands | the worker records pid + process START TIME (unix seconds, `unix:<n>`) + host next to its heartbeat; the slot decision asks the OS whether THAT process still exists (Windows: `Get-Process -Id` with a `Win32_Process.CreationDate` fallback through one bounded PowerShell call; Linux: `/proc/<pid>/stat`). Matrix in §2. Lease/authority semantics untouched. |
| F-3 printer health | `app/Services/Edge/EdgePrinterReachabilityProbe.php` (new), `EdgePrinterTransportException.php` (new), `EdgeNetworkPrinterTransport` (classified exceptions), `EdgeApplianceHealthService::print()` (failed jobs per printer + reachability → problems), `EdgeLocalPrintStatusCommand` (reachability + `failed_jobs`), `EdgeLocalPrintDeliveryService` (`printer_id` in the claim, `isTerminalFailed()`, terminal path → `markFailedTerminal`), `PrintJobService::markFailedTerminal()` (new, no auto-requeue = G4 fix), print worker (G3 `Log::warning` per failure / `Log::error` per terminal failure), `config/edge.php` `edge.print.reachability_probe` / `reachability_timeout_seconds` (default **3.0 s**, floor 2.5 s) | §3 |
| F-4 scoped control | `scripts/edge/Invoke-EdgeScopedControl.ps1` (new; copy at `C:\Users\Dell\BingooEdgeLab\scripts\`), `app/Console/Commands/EdgeLocalWorkerStatusCommand.php` (new, `edge:local:worker-status [--probe] [--json]`; allowlisted) | §4 |
| tests | `tests/Unit/Edge/{EdgeProcessLivenessTest, EdgeWorkerSlotPolicyTest, EdgePrinterReachabilityProbeTest, EdgeLocalDbReadinessTest}.php` (new, SQLite/no DB: 22 tests), `tests/MySql/EdgeLocalPrintWorkerLifecycleMySqlTest.php` (+2: dead-pid takeover + live real worker refused + worker-status; wait-db ready/refused/unknown-db + worker exit 1 on a dead port), `tests/MySql/EdgeAuthorityWorkerLifecycleMySqlTest.php` (+1: dead-pid takeover, live duplicate refused, authority state unchanged), `tests/MySql/EdgeApplianceHealthMySqlTest.php` (+1: failed jobs + reachability problems, probe off, printer back), `tests/MySql/Support/edge_pos_sale_worker.php` (the synthetic first-boot racer now LINGERS 6 s after winning — see §2 lesson) | gates in §7 |

Release facts for 0.8.3: **MIGRATION_PLAN is NOT empty** (one EDGE_OWNED migration, `RUN`; plan_hash on the copy
`sha256:506d1fdf0286ee536542d07efdfb7e88b5f8ce9fa14ee0eafaa4a9455276df43`, 24 CLOUD_ONLY skips, 0 blockers). Two new allowlisted read/wait
commands. No route, view, model-relationship or authority change. The 0.8.2 busy-aware web supervisor is unchanged.

## 1. MYSQL_STARTUP_PLAN — how the appliance depends on MySQL at boot

**Today (P5C LAB):** MySQL 8.0.30 is a Laragon CHILD PROCESS (not a service): after a reboot nothing starts it; the appliance tasks (if
they were registered) would wait and give up in a loop. **Pilot target (owner-only, elevated):**

1. **MySQL/MariaDB as a Windows service** — `StartType = Automatic` (NOT Delayed: Delayed start adds ~2 min after the last Automatic
   service and gains nothing — the appliance tasks already wait), Recovery = restart the service after 1 min / 1 min / 1 min, reset the
   failure count after 1 day, loopback bind (`bind-address=127.0.0.1`), a dedicated `bingoo_edge_*` database + user. On the LAB laptop
   this means either `mysqld --install BingooEdgeMySQL --defaults-file=<laragon my.ini>` (Laragon's data dir, exactly one instance:
   Laragon's own auto-start of mysqld must then be OFF) or a dedicated MySQL install — owner decision; the pilot box gets a clean
   dedicated install. Verification after a reboot: `Get-Service <name>` Running; `edge:local:wait-db --timeout=0` → READY.
2. **No Task-Scheduler dependency chain is needed**: Task Scheduler cannot express "after service X". The dependency is enforced by the
   workers themselves: every AtStartup task's worker runs `EdgeLocalDbReadiness::await(180 s, 3 s)` BEFORE touching its singleton slot
   (default budget `EDGE_WORKER_DB_STARTUP_WAIT=180`, in `appliance.env`); a retryable class (refused / timeout / error) → exit 1 → the
   task's restart-on-failure (999 × 1 min) re-runs it → effectively unbounded without a spin; a NON-retryable class (wrong credentials,
   unknown database, schema not initialised, not configured) ends the wait at once with a NAMED cause in the task log and the
   application log (`edge print-worker: database not ready {status, retryable, waited_seconds, attempts}`), so an operator reads
   "auth_failed" instead of a 60-s silence followed by a generic exit.
3. **`edge:local:wait-db`** is the explicit, scriptable form of the same probe for: the updater (`Update-EdgeAppliance.ps1` step 5 may
   call it before `Start`), the reboot drill checks (§5), `Register-EdgeTasks.ps1`'s `cmd.exe` wrapper if the owner prefers an explicit
   pre-step (`… edge:local:wait-db --timeout=180 --quiet-wait && … edge:local:print-worker`; the plan now carries
   `startup_db_wait_seconds` / `startup_db_wait_command` so the renderer can emit it without inventing values), and operators.
4. The web backends (`edge:local:serve`) need no DB to start; a request that hits the DB while MySQL is still booting returns the app's
   normal "Local database unreachable" health problem — nothing to add.

**Shown working (labcopy-c, 0.8.3-dev runtime through the copy's launcher; `05a`–`05c`):**

| case | command | result |
|---|---|---|
| ready | `edge:local:wait-db --timeout=5` | `READY at 127.0.0.1:3306/bingoo_edge_agentc_local (8 ms, 1 attempt, 0 s)`, exit **0** |
| dead port (MySQL not listening) | `EDGE_DB_PORT=9 … wait-db --timeout=4 --interval=1` (a real env var overrides appliance.env) | `attempt 1: refused - nothing listens on 127.0.0.1:9 (connect refused, errno 2002) - MySQL still starting or its service is down`, attempt 2 same, `NOT READY … after 5 s / 2 attempt(s) … (retryable)`, exit **1** |
| unknown database | `EDGE_DB_DATABASE=bingoo_edge_relf_missing … wait-db --timeout=30` | `attempt 1: unknown_database - database [bingoo_edge_relf_missing] does not exist on 127.0.0.1:3306 (errno 1049)` → `NOT USABLE … (configuration problem; waiting will not help)`, exit **2** after ONE attempt (the 30 s budget was not spent) |

MySQL-class proof (`EdgeLocalPrintWorkerLifecycleMySqlTest::test_wait_db_reports_ready_and_a_dead_port_as_refused_within_its_budget`,
real processes through `artisan`): ready → exit 0; `EDGE_DB_PORT=9` → exit 1 `refused`, ≥ 2 attempts inside the 3 s budget; unknown DB →
exit 2 after exactly 1 attempt; the print worker itself with `EDGE_WORKER_DB_STARTUP_WAIT=2` on a dead port → exit 1, output names
`refused` and `supervisor will restart`. Unit matrix (`EdgeLocalDbReadinessTest`): 1045/1044 → auth_failed (not retryable), 1049 →
unknown_database, 2002 refused vs timed-out by wording/elapsed, 2003 refused, odd errors retryable; `await()` stops after ONE probe on a
non-retryable class, spends its budget on refused, `--timeout=0` probes exactly once; the report never carries the DB user or password.

Finding (kept out of the wait on purpose): `EdgeLocalDatabase::unsafeReason()` (loopback-only, dedicated name) must NOT gate the
readiness wait — the workers never gated on it and every Edge MySQL suite points `edge_local` at a tenant-named test DB; a first draft that
included it failed 10/11 lifecycle tests with `unsafe_target`. Target safety stays with the schema-work commands.

## 2. SCHEDULED_TASK_HEARTBEAT_FIX — stale heartbeat vs genuinely live worker (G10)

**Defect:** both singleton workers treated `state=running AND heartbeat_at > now()-90 s` as "another worker is live" and exited 0. After a
fast reboot (< 90 s) or a crash followed by the task's 1-min restart the previous PROCESS is gone but its heartbeat is still fresh → the
new worker exits "duplicate", Task Scheduler sees success, and the appliance has no print/authority worker until the next boot.

**Fix:** the worker records its process identity next to the heartbeat (`worker_pid`, `worker_process_token` = process start time in
unix seconds, `worker_host`), and `EdgeWorkerSlotPolicy::decide()` — the ONE rule for both workers, made under the row lock — consults
`EdgeProcessLiveness::check(pid, token, host)`:

| row | OS liveness of the recorded pid | decision | reason code |
|---|---|---|---|
| not running / no uuid / same uuid / no heartbeat | (not consulted) | take over | `slot_free` |
| other uuid, heartbeat FRESH | **alive** (pid runs, start time matches ±2 s) | **refuse** (exit 0, nothing claimed) | `live_worker_holds_slot` |
| other uuid, heartbeat FRESH | **dead** (pid gone, or pid reused: start time differs) | **take over** | `previous_process_gone` ← the G10 trap |
| other uuid, heartbeat STALE | alive (wedged: runs but does not beat) | take over (the old one exits on its next `beat()`) | `stale_heartbeat_process_alive` |
| other uuid, heartbeat STALE | dead | take over | `previous_process_gone` |
| other uuid, FRESH / STALE | **unknown** (pre-0.8.3 row without identity, another host, probe failed/timed out 15 s) | refuse / take over exactly as before | `fresh_heartbeat_liveness_unknown` / `stale_heartbeat` |

`unknown` never makes anything worse than 0.8.2. The duplicate exit stays **0** when the other worker is VERIFIED alive (a restart loop
against a live worker would only be noise); a dead process never produces a duplicate exit any more, so Agent C's "return non-zero on
duplicate" workaround is unnecessary. Lease tokens / outbox lease / authority state are never touched by a takeover. The OS probe is ONE
bounded PowerShell call (`Get-Process -Id`, `CreationDate` fallback for a process of another user; measured 0.6–1.1 s on this loaded
host; hard bound 15 s → `unknown`). `health()` reports the recorded identity cheaply and probes the OS only for a STALE row, yielding the
operator verdicts `stale_process_alive` (wedged) / `stale_process_gone` (crashed, not restarted) in the health problem line.

**Proof — unit (no DB):** `EdgeWorkerSlotPolicyTest` (7 tests: the whole matrix with injected liveness; free slot never probes; the real
check returns `unknown` for a row without identity and for another host); `EdgeProcessLivenessTest` (5 tests against REAL processes: this
process alive with its own token; an exited `php -r` child dead even with a fresh token; a sleeping sibling alive, then DEAD when the
recorded token is 1 h off = pid reuse; `unknown` when nothing is recorded / the probe is broken; ±2 s tolerance).

**Proof — MySQL, real processes (`EdgeLocalPrintWorkerLifecycleMySqlTest`, `EdgeAuthorityWorkerLifecycleMySqlTest`, relf DB set):**
a running row with heartbeat 5 s old and the pid of an exited process → the in-process start prints `previous_process_gone`, claims and
prints the queued job, records ITS pid/token/host; a REAL spawned idle worker holding the slot → a second start prints `already RUNNING …
live_worker_holds_slot`, claims nothing; `edge:local:worker-status --probe` says `running / alive / HELD by a live process`; cooperative
`--stop` ends the real worker. Authority: same two cases, `authority_state` stays `standby`, the refused duplicate never ticks.
Result: print lifecycle 11/11 (runs 2+3, see §7); authority lifecycle **4/4 green, 38 assertions**.

**Proof — disposable appliance (labcopy-c on the staged 0.8.3-dev runtime, `06`–`07g`):**

| UTC | step | evidence |
|---|---|---|
| 11:33 | copy DB row rewritten to the G10 shape: `state=running`, `worker_uuid=drill-rebooted-worker`, `heartbeat_at=now`, `worker_pid=410168` (a `php -r` that had exited), token `unix:<now-300>`, host `DESKTOP-0024EPM` | `07a` |
| 11:33 | `edge:local:worker-status --probe` → `print_worker running heartbeat 3s ago pid 410168 dead -> free (fresh heartbeat but the process is gone - a start takes over)` | `07b` |
| 11:34:07 | `Invoke-EdgeScopedControl.ps1 -Action Start -Task BingooEdgePrintWorker` → pid **369696** recorded (`start_token 1793360042`); worker stdout: `started (pid 369696 …) — taking over the worker slot [previous_process_gone] previous worker drill-rebooted-worker heartbeat 38s old; pid 410168 is not running.` — the pre-0.8.3 worker would have exited 0 here | `07c`, `pids.json`, `task-logs\BingooEdgePrintWorker.*.out.log` |
| 11:34 | `worker-status --probe` → `running heartbeat 2s ago pid 369696 alive -> HELD by a live process - a start exits as duplicate` | `07d` |
| 11:35 | second scoped Start → **START REFUSED** (exit 3): "pid record already has a LIVE BingooEdgePrintWorker under this root: pid(s) 369696" + "DB slot is HELD by a live process (pid 369696)" — no process spawned | `07e` |
| 11:35:59 | direct second instance through the launcher (`edge:local:print-worker --once`) → `another local print worker is already RUNNING … refusing to start [live_worker_holds_slot] previous worker 22629945… heartbeat 1s old; pid 369696 is running with the recorded start time`, exit 0; app log `edge print-worker duplicate start refused {reason, heartbeat_age_seconds:1, liveness:"alive", pid}` | `07f`, `data\BingooEdge\logs\edge-2026-10-30.log` |
| 11:36 | health while running: `workers.print_worker.state=running`, `process {pid 369696, host, identity_recorded true}` | `08a` |
| 11:36 | `Get-EdgeProcessTree.ps1 -PidRecord agent-f\pids.json`: 2 processes (php 369696 `recorded_as BingooEdgePrintWorker` + its conhost), 1/1 recorded alive, 0 missing | `07g` |

| 11:42:43 | after the scoped Stop (§4) the row reads `stopped`, `last_graceful_stop_at 11:42:43`, `last_error NULL`, identity of the stopped worker kept (pid 369696, `unix:1793360042`, `DESKTOP-0024EPM`) | `07k` |
| 11:44 | **authority worker, same trap:** row rewritten to `running`, `worker_uuid drill-rebooted-authority`, heartbeat now, `worker_pid 157728` (exited); `worker-status --probe` → `authority_worker running heartbeat 2s ago pid 157728 dead -> free (… a start takes over)`; `edge:local:authority-worker --once` through the launcher → `taking over the worker slot [previous_process_gone] previous worker drill-rebooted-authority heartbeat 9s old; pid 157728 is not running` → `started (pid 429544, interval 20s)` → `tick 1: miss preparing_local` (the copy's Cloud URL is dead by design) → stopped; row now owned by the new uuid with pid 429544/host; **`authority_state` stayed `standby`** (connection_state `preparing_local` is the copy's pre-existing state-machine position from Agent C's dump, unchanged by the slot takeover) | `07j` |

Lesson: the synthetic first-boot racer (`edge_pos_sale_worker.php print_worker_acquire`) exits right after winning, so under the new rule
the loser (correctly) found the winner's pid gone and ALSO won → the racer now lingers 6 s after winning, as a real worker does.

## 3. PRINTER_HEALTH_PLAN — failed jobs, reachability, retry classification

Design (implemented):

- **Failed jobs are problems (G1).** `edge:local:health` groups `print_jobs.print_status='failed'` of the bound branch by printer and adds
  one problem line per printer: `N print job(s) FAILED on <printer name> — retry or dismiss from Recent Prints (last error: …)` →
  status **DEGRADED**. The report also carries `print.failed_jobs_by_printer[]` and `print.terminal_failed_deliveries`;
  `edge:local:print-status` gets `failed_jobs`.
- **Reachability probe (G2).** `EdgePrinterReachabilityProbe`: ONE bounded TCP connect per Edge-capable (network, has IP) active printer,
  never a byte written, classified `reachable | refused | timeout | unreachable | unresolved | error` from errno (WSA 10061/10060/
  10065/10051/11001 and POSIX 111/110/113/101/-2) with wording/elapsed-time fallbacks. Health adds `Printer <name> (<ip>:<port>) is not
  reachable: <class> — <what to check>`; at most 4 printers per report (bounded report time); `EDGE_PRINT_REACHABILITY_PROBE=false`
  disables it for diagnostics (failed jobs still surface). **Windows finding:** a REFUSED connect is reported only after the stack's SYN
  retransmit — measured **2.02–2.05 s** for every closed loopback port (4/4 samples, errno 10061) — so a 1 s probe misreports "refused"
  (host up, port closed) as "timeout" (host down). Default bound therefore **3.0 s**, floor 2.5 s (Linux answers RST at once).
- **Retry classification (worker).** `EdgeNetworkPrinterTransport` throws `EdgePrinterTransportException` whose `category()` is the SAME
  word the probe uses for the connect phase, or `write_timeout` / `write_failed` after the connection was accepted; the message starts
  with `[<category>]` and lands verbatim in `edge_local_print_deliveries.last_error`, the worker stdout, the application log and the
  cashier "Recent Prints" error. **Bounded retry/backoff (unchanged, now documented per class):** every category runs the same ladder —
  attempt 1 fails → `retry_wait` 5 s → 15 → 30 → 60 → 120 s → attempt **6 is terminal** (`terminal_failed` + shared `print_status=failed`,
  `attempts` +1, `failed_at`, `error_message`); refused ≈ 4 min 06 s to terminal (+ ≤ 3 s idle sleep per claim cycle), timeout ≈ 4 min 40 s
  (8 s connect bound per attempt); the worker keeps running and serves other printers; a terminal job waits for the operator's Retry
  (one more ladder) or Dismiss. The classification tells the operator WHAT to fix; it does not shorten or lengthen the ladder (a refused
  port and a powered-off printer recover on the same time scale).
- **G3 logging.** Every delivery failure → `Log::warning('edge print job delivery failed (will retry)', {job_id, printer_id, printer,
  category, error, recorded})`; the terminal one → `Log::error('edge print job TERMINAL failure — operator retry/dismiss required', …)`.
  Slot takeovers and duplicate refusals are logged too. A Scheduled-Task worker without stdout redirection now leaves evidence in
  `<DataRoot>\logs\edge-YYYY-MM-DD.log`.
- **G4 fix.** The Edge terminal path calls the new `PrintJobService::markFailedTerminal()` (same printed-guard and `attempts` semantic,
  NEVER the Cloud agent's auto-requeue), so a `terminal_failed` delivery can no longer sit next to a `queued` job that nothing can claim
  or retry. A unit guard asserts the Edge error text never matches the Cloud agent's RETRYABLE needles either.
- G5 (paper-out = printed, at-least-once) and G7 (`attempts` semantic) stay as documented by Agent C — not defects for the pilot.

**Proof — unit:** `EdgePrinterReachabilityProbeTest` (6 tests: a loopback listener is `reachable` and receives 0 bytes; 127.0.0.1:9 is
`refused` within the default bound; the errno matrix; the transport exception `[refused] printer connect failed [127.0.0.1:9]` with the
G4 needle guard; TEST-NET-1 192.0.2.1 → `timeout`/`unreachable`, never `refused`; the fake seam).

**Proof — MySQL (`EdgeApplianceHealthMySqlTest::test_failed_print_jobs_and_printer_reachability_surface_as_problems`):** two failed jobs
+ one printed on "Kitchen LAN" re-pointed at 127.0.0.1:9 → DEGRADED, `2 print job(s) FAILED on Kitchen LAN … Recent Prints … [refused]`,
`Printer Kitchen LAN (127.0.0.1:9) is not reachable: refused`, `failed_jobs_by_printer[0] {count 2, printer_id}`, USB printer never
probed, `probed 1`; probe off → no reachability line, failed line stays; printer back (loopback listener) → `reachable`, line gone.
Result: **5/5 green, 83 assertions** (the three pre-existing health tests still pass with the new problem lines present).

**Proof — disposable appliance (labcopy-c, dead printer 127.0.0.1:9, nothing on 9100; `03`, `08a`, `09a`–`09f`):**

| UTC | event | evidence |
|---|---|---|
| 11:32 | first health on 0.8.3-dev (no worker running): DEGRADED; NEW lines `1 print job(s) FAILED on LAB FakePrinter (TCP 9100) — retry or dismiss from Recent Prints (last error: printer connect failed [127.0.0.1:9]: …refused… (10061))` (Agent C's job #34) and `Printer LAB FakePrinter (TCP 9100) (127.0.0.1:9) is not reachable: refused — connection refused - the host is up but nothing accepts on this port … (errno 10061)`; `print.printers[0].reachability {status refused, latency_ms 2037, errno 10061}` — the 0.8.1 health of the same DB had neither line (Agent C `06-health-during-worker.json`) | `03` |
| 11:36:46 | synthetic job **#35** (clone of #34: receipt, printer 1, branch 1, `queued`, payload "AGENT F DRILL - dead printer … never printed") inserted into the COPY DB | `09a` |
| 11:36:48 / :56 | worker claims; attempts 1 and 2 refused in 2062 / 2033 ms; delivery `retry_wait`, `failure_count 2`, `last_error "[refused] printer connect failed [127.0.0.1:9] after 2033 ms: …"`, `print_status` stays `queued`; app log: two `edge print job delivery failed (will retry) {"job_id":35,"printer_id":1,"printer":"127.0.0.1:9","category":"refused",…}` | `09b`, `edge-2026-10-30.log` |
| 11:37:45 … 11:38:47 | attempts 3–5 after the 15 / 30 / 60 s backoffs (each refused in ~2.0 s); the 10-s timeline shows `retry_wait 3 → 4 → leased 4 → retry_wait 5`; `print_status` stays `queued` throughout | `09c` |
| **11:40:50** | attempt 6 = terminal (244 s after the insert, after the 120 s backoff): delivery `terminal_failed`, `failure_count 6`, `last_error "[refused] printer connect failed [127.0.0.1:9] after 2050 ms: …"`; `print_jobs` **failed**, `attempts 1`, `failed_at 11:40:50`, `error_message "[refused] …"` — stayed `failed` (no auto-requeue: G4); app log `production.ERROR: edge print job TERMINAL failure — operator retry/dismiss required {"job_id":35,…,"category":"refused",…}` | `09d`, `09e` |
| 11:41 | health: `2 print job(s) FAILED on LAB FakePrinter (TCP 9100) — retry or dismiss from Recent Prints …`, `failed_jobs_by_printer [{printer_id 1, count 2, last_failed_at 11:40:50}]`, `terminal_failed_deliveries 2`, reachability line still `refused`, status DEGRADED | `09f` |

## 4. Scoped process-tree shutdown and no-duplicate launch (F-4)

`Invoke-EdgeScopedControl.ps1 -InstallRoot <root> -PidRecord <pids.json> -Action Status|Start|Stop [-Task <plan task>]` (PS 5.1, pure
ASCII, parse-checked; never registers a task, never elevates, never acts outside the given roots):

- **Scope** = a pid whose COMMAND LINE names the install root or data root (both slash forms); executable names are never a criterion.
- **Start** reads the plan JSON for the task's executable/arguments/working directory (the launcher's php when `appliance.json` names
  it), re-checks the secret-shape rule, and **refuses (exit 3)** when (a) the task's listen port is held by ANY process (naming whether
  the holder is in this root — a holder of another environment is reported and never touched), (b) the pid record already has a LIVE
  entry for the task whose process still names this root with the recorded creation time, or (c) `edge:local:worker-status --probe`
  says the DB slot is HELD by a live process. It then starts hidden with stdout/stderr redirected to `-LogDir` and appends
  `{name, pid, executable, arguments, working_directory, started_utc, start_token (= Win32_Process CreationDate as unix seconds),
  install_root, stdout, stderr}` to the record.
- **Stop** acts ONLY on recorded, live, in-scope pids whose creation time matches the record (±2 s; pid reuse → "NOT terminating"):
  cooperative first (`edge:local:print-worker --stop --stop-wait=N`, `edge:local:authority-worker --stop`, `nginx -s quit`), waits up to
  30 s for the pid to leave by itself, then — and only then — `taskkill /PID <pid> /T /F` on that recorded pid (the tree: the worker's
  conhost / a supervisor's `php -S` child). The record gets `stopped_utc` + `stop_method`; an entry is never reused.
- **Status** lists every plan task with listen-port owner (this root / NOT this root), DB slot state + liveness + verdict, recorded live pids.
- **Singleton guards that make a duplicate harmless even if a start slips through** (unchanged): OS port (backends, gateway), DB slot
  with process identity (print, authority — now immune to the G10 false-duplicate), outbox SKIP-LOCKED lease (sync), backup file lock.

Design note for `Register-EdgeServices.ps1 -Action Stop` / `Register-EdgeTasks.ps1` (owner-run, elevated): keep cooperative stop →
`Stop-ScheduledTask` → `Disable-ScheduledTask`, and add the same identity check before any `Stop-Process`: the task's running pid comes
from `Get-ScheduledTaskInfo`/`Get-CimInstance Win32_Process` filtered by the install-root command line — never `Get-Process -Name`.

**Proof (labcopy-c, §2 table + `07h`/`07i`/`07k`):** Start took over the dead-pid slot and recorded pid 369696 with its start token; the
second Start was refused for BOTH reasons (record + slot) without spawning; `Get-EdgeProcessTree.ps1` showed exactly the recorded pid +
its conhost; **Stop (11:42:52 UTC)**: `cooperative stop: edge:local:print-worker --stop --stop-wait=40` → `local print worker stopped
gracefully.` → `pid 369696 exited by itself` → NO taskkill was needed or issued → record entry gets `stopped_utc` + `stop_method
"cooperative (exited by itself)"`; the worker row is `stopped`, `last_graceful_stop_at` set, `last_error NULL`, pid/token/host of the
stopped worker kept for forensics; process tree afterwards: **0 processes** under the copy root, record `1 entries, 0 alive, 1 missing`
(the stopped one, as expected). Worker lifetime 11:34:07 → 11:42:52; it processed job #35's six attempts and nothing else.

## 5. REBOOT_DRILL_PLAN — owner-run procedure (NOT executed; needs an elevated session, MySQL service, registered tasks)

Prerequisites: 0.8.3 installed on the box under test (LAB only after the owner's decision; never a pilot box first), MySQL as a service
(§1.1), tasks registered from the 0.8.3 plan (`Register-EdgeTasks.ps1 -Apply`, elevated), `Invoke-EdgeCertification.ps1` at hand, a second
shell for observation, the keep-awake guard running, no other agent session loading the host.

1. **Pre-reboot snapshot** (T−5 min): `Invoke-EdgeCertification.ps1 -Phase PreReboot …`; `edge:local:health --json > pre.json` (expect
   STANDBY_READY or the known DEGRADED lines only); `edge:local:worker-status --probe --json > pre-workers.json` (both slots
   `running/alive`, note pids + tokens); `edge:local:print-status --json`, `edge:local:sync-status --json` (queue + outbox counts);
   `Get-EdgeProcessTree.ps1 -Json pre-tree.json`; `Get-ScheduledTask -TaskPath '\BingooEdge\' | Get-ScheduledTaskInfo` (LastRunTime,
   LastTaskResult); `Get-Service <mysql>`; `Get-CimInstance Win32_OperatingSystem | select LastBootUpTime`.
2. **Reboot** — a drill simulates power loss: NO cooperative stop, `Restart-Computer -Force` from the elevated shell. Variant B (G10):
   note the heartbeat time from step 1 and reboot within 60 s of it.
3. **Hands off after logon** — start nothing by hand. Every 30 s for 5 minutes (one PowerShell loop writing to `post-timeline.txt`):
   `Test-NetConnection 127.0.0.1 -Port 3306`; `… edge:local:wait-db --timeout=0 --json` (class per tick: expect `refused` while MySQL
   starts, then `ready`); `Get-ScheduledTask | Get-ScheduledTaskInfo` (State Running, LastTaskResult 267009 = running);
   `Get-NetTCPConnection -State Listen -LocalPort 443,8090,8091`; `web-backend-{1,2}.status.json` state; `edge:local:worker-status
   --probe --json`; `edge:local:health --json`.
4. **Expected timings (SSD laptop; from LastBootUpTime):** MySQL service listening ≤ T+60 s (`wait-db` flips refused → ready); gateway
   listening ≤ T+70 s; both web backends healthy ≤ T+100 s (502 through the gateway until then is expected); print worker slot
   `running/alive` with a NEW pid ≤ T+120 s — in variant B the new worker's task log must show `taking over the worker slot
   [previous_process_gone]` (pre-0.8.3 it would have exited 0 as a duplicate); authority worker `running/alive` ≤ T+150 s, Cloud lease
   back to `holder cloud` (it lapsed during the reboot — expected, AUTO_FAILOVER=false, no takeover); first sync-send T+60–120 s; backup
   startup run ≤ T+5 min; `edge:local:health --fail-on-problems` exit 0 / STANDBY_READY ≤ T+5 min. Worker task logs show the classified
   wait lines (`waiting for the Edge-local database (attempt N): refused - …`) and then `started`.
5. **Post-reboot checks:** `-Phase PostReboot`; `health` equals `pre.json` apart from timestamps; print queue / outbox counts equal the
   snapshot (a lease live at the crash expires ≤ 120 s and is re-claimed — at-least-once, note any duplicate); exactly ONE row per
   singleton table (`worker-status`), `authority_state` never `local_active`; `Get-EdgeProcessTree.ps1` = the plan's tree (no orphan,
   no second worker); every task `LastTaskResult` 267009 and `NumberOfMissedRuns` 0; each task's seconds-after-boot recorded.
6. **Pass:** step 4 timings met in BOTH variants, no manual start, no duplicate worker row, no `fresh_heartbeat_liveness_unknown`
   refusal in any task log (would mean the OS probe failed under the task principal — then check that LOCAL SERVICE may read
   `Win32_Process` of its own tasks; `Get-Process` works within the same account), no secret in any log.
7. **Rollback:** this drill changes no data; if a worker does not come up, `Invoke-EdgeScopedControl.ps1 -Action Status` names which guard
   blocks it (port holder / slot / record) and `-Action Start -Task …` starts it by the plan; if 0.8.3 itself misbehaves,
   `Update-EdgeAppliance.ps1` pointer rollback to 0.8.2 (the 0.8.3 migration is additive and nullable — 0.8.2 code ignores the three
   columns; no data rewrite needed) and `Register-EdgeTasks.ps1` re-rendered from the 0.8.2 plan.

## 6. P6_RELIABILITY_BLOCKERS — each resolved or dispositioned

| # | blocker (Agent C ref) | status | evidence |
|---|---|---|---|
| 1 | MySQL startup dependency after reboot | **RESOLVED in code** (classified bounded wait in both workers, `edge:local:wait-db`, plan fields); **OWNER ACTION remains**: MySQL as a Windows service (§1.1) and the registered tasks — neither can be done from an agent session (elevation) | §1 table, `05a`–`05c`, lifecycle MySQL test |
| 2 | duplicate-instance exit within 90 s of the last heartbeat (G10) | **RESOLVED**: pid + start time + host recorded; dead process → immediate takeover; live process → genuine duplicate; unknown → unchanged rule | §2, `07a`–`07g`, unit + MySQL tests |
| 3 | print-worker terminal failures absent from health (G1) | **RESOLVED**: per-printer FAILED problem lines → DEGRADED; `failed_jobs_by_printer`, `terminal_failed_deliveries`, print-status `failed_jobs` | §3, `03`, `09f`, health MySQL test |
| 4 | no printer reachability detection (G2) | **RESOLVED**: bounded classified probe in health + print-status; Windows 2 s refused latency measured and bounded | §3, `03`, unit test |
| 4b | delivery failures not logged (G3) | **RESOLVED**: warning per failure, error per terminal, info/warning per duplicate/takeover | `edge-2026-10-30.log` on the copy |
| 4c | latent queued-but-unclaimable after terminal (G4) | **RESOLVED**: `markFailedTerminal` (no auto-requeue) + needle guard | unit test, `09d` |
| 5 | scoped supervisor/service recovery diagnostics | **RESOLVED (disposable)**: `edge:local:worker-status [--probe]`, `Invoke-EdgeScopedControl.ps1` (start refuses on port/slot/record, stop by recorded pid + scope + creation time), health verdicts wedged/gone | §4, `07c`–`07i` |
| 6 | print worker without a liveness supervisor (G6) | **DISPOSITIONED**: a wedged worker is now VISIBLE (`stale_process_alive` in health/worker-status) and a supervised restart takes the slot over; an automatic self-kill is deferred (a wedge in a DB call is the one case; the 0.8.2 web supervisor pattern can be applied later) | §2 |
| 7 | paper-out invisible (G5), `attempts` semantic (G7) | **ACCEPTED for the pilot** as Agent C documented | — |
| 8 | reboot drill, task registration, elevated install, physical printer | **OWNER-ONLY**, procedure in §5 (and Agent C §6) | — |

## 7. Gates run (time-gated: no full suites, no browser, no stress before GO) and what remains

| gate | result |
|---|---|
| `php -l` every changed/new PHP file; PS 5.1 parser on the new script (0 errors, pure ASCII) | green |
| `vendor/bin/phpunit tests/Unit/Edge tests/Feature/Edge/EdgeLocalRuntimeBoundaryTest.php` (SQLite / no DB; 4 new classes = 22 tests + the CLI census/allowlist test) | **OK 34 tests, 158 assertions** (36 s). Two earlier reds were test-side and are recorded as findings: the 1 s probe bound (Windows refused = 2 s) and a DB-name/user substring collision in an assertion |
| MySQL, relf set, ONE class at a time: `EdgeLocalPrintWorkerLifecycleMySqlTest` (11 tests incl. 2 new) | run 1: 10 reds = the `unsafe_target` draft gate (§1 finding) + the racer linger (§2 lesson) → fixed; run 2: **10/11 green, 126 assertions**, the one error was the new test's own cleanup (`DELETE print_jobs` blocked by the deliveries FK after the live worker claimed the extra job) → the extra job removed; run 3 (that test alone): **OK 1 test, 21 assertions** → 11/11 across runs 2+3 (`10-…run2.log`, `10-…run3-g10-test.log`) |
| `EdgeAuthorityWorkerLifecycleMySqlTest` (4 incl. 1 new; real processes) | **OK 4 tests, 38 assertions** (`11-mysql-EdgeAuthorityWorkerLifecycleMySqlTest.log`) |
| `EdgeApplianceHealthMySqlTest` (5 incl. 1 new) | **OK 5 tests, 83 assertions** |
| `EdgeSupervisionMySqlTest` (plan shape, allowlist, no secret on a command line, bounded DB wait, generated installers) | **OK 10 tests, 162 assertions** (the new plan fields and the two new allowlisted commands break no invariant) |
| system map (`php tools/system-map/build.php --tenant-db=pos_test_tenant_relf --master-db=pos_test_master_relf`, read-only, run after the last MySQL class) | rebuilt and committed with this work: 234 tables / 425 FKs / 238 inferred links / 730 screens from the relf TEST DBs (the LAB-shaped test DB of the 0.8.1 record had 265 tables — the relf tenant DB only carries what these test classes migrated; rebuild from a full test run before release). Artifact link not republished from this session |

**Remaining heavy runs (after GO, on the rel-083 worktree with the relf DB set):** the full Edge MySQL suite (`./test-mysql.sh` equivalent
with the relf names; the 0.8.2 baseline was 726/726), `tests/Feature,Unit` SQLite suite, `EdgeTenantMigrationUpgradeMySqlTest` /
`EdgeUpdatePlanHashMySqlTest` / `EdgeUpdaterMySqlTest` for the new edge migration, `EdgeCleanMachineInstallMySqlTest` (packaged
supervisor + the new wait path), an upgrade rehearsal 0.8.2 → 0.8.3 → 0.8.2 on a disposable copy of the LAB install root (the
migration is additive; rollback leaves three nullable columns), the browser pair (print status in the cashier status page shows the new
problem lines), and a saturated-host repeat of the labcopy-c drills (the OS probe under 86–100 % CPU: expect ≤ 5 s, never `unknown`).

## 8. Process custody

- labcopy-c runtime pointer: `0.8.1-edge` → **`0.8.3-dev`** (staged copy of the rel-083 working tree + its vendor under
  `runtime\versions\0.8.3-dev`, manifest `edge_app_version 0.8.3-dev`, `vendor_source dev_tree_copy_agent_f`; NOT a signed package —
  health reports `packaged_artifact false`); left on 0.8.3-dev for the next agent; `00-pointer-before.txt` records the old value.
  Copy DB `bingoo_edge_agentc_local`: schema-upgraded (+1 edge migration), worker row + jobs #35 added; the LAB DB untouched.
- Processes this agent started on the host, all recorded in `evidence\agent-f\pids.json` or transient children of its own commands:
  **369696** (labcopy-c print worker, 11:34:07 → 11:42:52 UTC, cooperative stop, exited by itself — the only long-lived process);
  transient `--once` runs through the copy launcher (print worker 430680 duplicate-refused; authority worker 429544 one tick), two
  `php -r "echo getmypid();"` children used as dead pids (410168, 157728), the PowerShell liveness probes, the phpunit runs on the relf DBs
  and their real-process workers (all exited by themselves). Nothing was killed; `taskkill` was never issued. No process of any other
  environment was touched. Ports used: none listened on by this agent except the unit test's ephemeral loopback listener (port 0).
- Databases created: `pos_test_master_relf`, `pos_test_tenant_relf`, `pos_test_edge_local_relf` (test harness); modified: `bingoo_edge_agentc_local`
  (the copy). Not touched: `pos_lab_*`, `bingoo_edge_lab_*`, production.

## 9. GO phase (coordinator GO received after the real LAB update was accepted) — heavy runs, one suite at a time

Rules kept: relf DB set only; ports 8390/8391/8743/8381 + 9810–9819 only (the clean-machine test's four port ranges were made
env-overridable — `EDGE_TEST_{CLOUD,WEB,HTTPS,HTTP}_PORT_BASE/_SPAN`, defaults = the historical ranges — and pinned to cloud 9815–9819,
web 9810–9813(+1), https 8743, http 8381 for this run, clear of Agent E's 9801–9809 / 8491–8499 and of the LAB's 9701); keep-awake guard
(`keepawake-agentf.ps1`, flag file `evidence\agent-f\keepawake.flag`, pid in `pids-aux.txt`); no artifact republish.

### 9.1 Saturated-host repeat of the labcopy-c drills (`30a`–`30d`; host at 57–94 % CPU: the full Edge MySQL suite + Agent E's gates)

| UTC | step | measured |
|---|---|---|
| 13:09:45 | row rewritten to the G10 shape (fresh heartbeat, dead pid 435748); first `worker-status --probe` → `pid 435748 dead -> free (… a start takes over)` | **36.3 s wall** for the whole command — a cold PowerShell/.NET + CIM start while the suite was bootstrapping (57 % CPU); the verdict was still `dead`, not `unknown` (the 15 s probe bound was not hit — the bulk was process start-up of the probe + PHP under load) |
| 13:10:52 | attribution: `worker-status` without probe 1.28 s; raw PowerShell probe of the dead pid 0.77 s; `worker-status --probe` (2nd) **3.16 s** | the 36 s was a one-off cold start; steady state 3 s |
| 13:11:33 | scoped Start → pid **365388** recorded; worker stdout `taking over the worker slot [previous_process_gone] previous worker sat-drill-rebooted heartbeat 108s old; pid 435748 is not running` | Start script wall 9.1 s. (By then the heartbeat had aged past 90 s — the DEAD verdict wins before the age is considered, so the reason is still `previous_process_gone`; the strictly-fresh case is the 11:34 quiet run.) |
| 13:11:48 | direct duplicate `--once` through the launcher at **94 % CPU** → `refusing to start [live_worker_holds_slot] … pid 365388 is running with the recorded start time`, exit 0 | **2.8 s wall** |
| 13:12:28 | scoped Stop → cooperative `--stop` → `stopped gracefully` → `pid 365388 exited by itself` → record updated; tree afterwards 0 processes, record 2 entries / 0 alive / 2 missing (both the stopped ones) | 15.6 s wall (cooperative wait under load) |

Verdict for the reboot drill expectation: the OS probe stays well inside its 15 s bound in steady state (≈ 1 s raw, ≈ 3 s per command)
and the decision was never `unknown`; a cold start on a saturated host can cost tens of seconds once — the worker's 180 s DB budget and
the task restart policy absorb it. `pids.json`: 369696 and 365388, both stopped cooperatively, nothing killed.

### 9.2 Pointer rehearsal on labcopy-c (`40-rehearsal-083dev-081-083dev-081.txt`) — 0.8.2 is NOT staged in labcopy-c (the copy carries
0.6.0/0.7.0/0.8.0/0.8.1 + my 0.8.3-dev staging), so the rehearsal ran 0.8.3-dev → **0.8.1-edge** → 0.8.3-dev → 0.8.1-edge, no worker running:

| step | pointer | observed |
|---|---|---|
| A | 0.8.3-dev | rowset: print_jobs 35, deliveries 35, migrations 219, meta 1; CHECKSUM print_jobs 4018818690 / deliveries 3995194591 / meta 2464159377 / printers 2271863478 / sales_orders 839775129 |
| 1 | → 0.8.1-edge | health through the 0.8.1 runtime: DEGRADED, DB reachable, 5 problems (the 0.8.1 set); 0.8.1 `print-worker --once` runs against the upgraded DB (3 extra nullable columns ignored) → `started … stopped after 0 job(s)`; `edge:local:worker-status` "not defined" on 0.8.1 (expected) |
| B | 0.8.1-edge | **all five checksums identical to A**; worker row now uuid 63306d22…, `runtime_version 0.8.1-edge`, `worker_pid` still **365388** (0.8.1 does not write identity — see finding F-2b) |
| 2 | → 0.8.3-dev | `schema-upgrade --dry-run`: run 0 / skip 24 / blockers 0 / OK (nothing pending — the migration row survived the downgrade); health DEGRADED 7 problems (the 0.8.3 set: + failed jobs + reachability); `worker-status --probe` both slots `free (stopped)`; 0.8.3 `print-worker --once` → started pid 433396, stopped |
| C | 0.8.3-dev | all five checksums identical to A |
| 3 / D | → **0.8.1-edge (final)** | health DEGRADED 5 problems; checksums identical to A; the copy is left on `0.8.1-edge` as recorded in `00-pointer-before.txt` (the 0.8.3-dev directory stays under `runtime\versions` for the next agent) |

**Finding F-2b (from step B, fixed in this branch):** a pre-0.8.3 runtime that takes the slot rewrites `worker_uuid` / `started_at` /
`heartbeat_at` but leaves the 0.8.3 identity columns untouched, so a 0.8.3 worker started WHILE a 0.8.1 worker is live would probe the
stale pid (dead) and take over a live worker. Only possible with two runtimes running at once (the updater stops the workers first), but
the rule is now closed: the process token is bound to the worker uuid (`unix:<s>@<worker_uuid>`); an identity whose token is not bound
to the row's CURRENT uuid counts as `unknown` → the pre-0.8.3 heartbeat rule applies (§9.4; unit + authority MySQL proof in §9.5).

### 9.2b The requested 0.8.2 → 0.8.3-dev → 0.8.2 rehearsal (31 Oct, `70`–`72`) — 0.8.2 staged by the REAL updater from the signed package

| step | what | observed |
|---|---|---|
| dry-run | copy launcher (0.8.1 runtime): `edge:local:update <custody>\releases\BingooEdge-0.8.2-edge\update\edge-update-0.8.2-edge.json <…>\app --dry-run --json` | signature/verification OK, `from 0.8.1-edge → to 0.8.2-edge`, plan `entries [] run [] skip [] blockers []`, **plan_hash sha256:20a8aad4…** (= the 0.8.2 release record), verdict OK — on a DB that already carries the 0.8.3 migration row (an extra applied row the package does not know is tolerated) |
| apply | same command with `--expected-plan-hash=sha256:20a8aad4…` | `applied`, 948 s, pre-update backup `labcopy-c\data\BingooEdge\backups\edge-backup-20261031_071941-t2sjyv.enc`, ran 0 / skipped 0, pointer **0.8.2-edge**, versions dir now 0.6.0/0.7.0/0.8.0/0.8.1/0.8.2/0.8.3-dev; checksums print_jobs/deliveries/printers/sales_orders unchanged, `edge_local_meta` changed (the updater records the active version — expected) |
| A | 0.8.2-edge | health through the packaged 0.8.2: DEGRADED (4 problems: the 0.8.2 set), `edge_app_version 0.8.2-edge`, `packaged_artifact true` |
| 1 | → 0.8.3-dev | `schema-upgrade --dry-run` run 0 / skip 24 / OK (nothing pending); health DEGRADED 6 problems (+ failed jobs + reachability); `worker-status --probe` both slots free (stopped); 0.8.3 `print-worker --once` pid 334212 started/stopped |
| B | 0.8.3-dev | all five checksums identical to A (meta included) |
| 2 | → 0.8.2-edge | health through 0.8.2: DEGRADED 4 problems; 0.8.2 `print-worker --once` runs against the 0.8.3-upgraded DB (three extra nullable columns ignored; its row keeps the 0.8.3 worker's pid 334212 — the F-2b case, now neutralised by the uuid binding); 0.8.2 updater dry-run again on this DB: `dry_run`, verdict OK, plan_hash sha256:20a8aad4…, from 0.8.2-edge |
| C | 0.8.2-edge | all five checksums identical to A; **final pointer 0.8.2-edge** (= the real LAB's current version; the 0.8.3-dev directory stays staged for the next agent) |

Rollback facts for the 0.8.3 release: forward = 1 EDGE_OWNED migration (additive, nullable); backward (pointer to 0.8.2) needs no data
change — 0.8.2 code ignores the three columns and its updater/plan still verifies OK against the upgraded DB.

### 9.3 The shared-MySQL outage (30 Oct 13:50–16:23 UTC) and what it proved

The first full-suite run (`20-mysql-edge-full.log`, START 13:09:15Z) ran into the unlogged death of the shared Laragon MySQL server:
337 passed, then 390 errored + 2 failed (`EdgeLocalDbInitMySqlTest`, `EdgeSupervisionMySqlTest::test_bounded_db_startup_wait…` —
both DB-connection dependent) until the server was back at 16:22:55Z → **ENVIRONMENTAL**, re-run below. The labcopy-c drills (§9.1,
§9.2: 13:09–13:14) completed before the outage and stand.

The coordinator's observation is the F-1 scenario in production shape: **the real LAB's 0.8.1 print and authority workers crashed when
the database vanished and stayed down until a manual restart at 20:12 UTC; the 0.8.2 web backends (whose supervisor does not touch
the DB) survived.** Two causes, both now addressed:

1. **No restart policy on the LAB** (P5C hidden processes) — the Scheduled-Task design (Agent C §1, restart-on-failure 999 × 1 min)
   is the first half; owner-only.
2. **Workers crashed out on a lost connection** — new `app/Services/Edge/EdgeWorkerDatabaseLoss.php` (**F-1b**): both workers now
   treat a CONNECTION-level failure in their loop (codes 2002/2003/2006/2013/1040/1053/1927/10054/10061 or the "gone away / lost
   connection / refused / server shutdown / too many connections" wordings — never a SQL error) as the boot race: `DB::purge`, then the
   same classified bounded wait (`EDGE_WORKER_DB_STARTUP_WAIT`, 180 s), then CONTINUE with the SAME worker uuid (ownership is
   re-verified by the next `beat()`); only a spent budget ends the process with exit 1 for the task restart. The authority worker's
   tick and `recordTick` are wrapped the same way; a `markStopped` that itself fails (DB still gone) is logged, the row keeps
   `running` + this pid, and the next start resolves it through the OS check (F-G10) — the two fixes compose. Stdout + application
   log carry `database connection lost (…) — waiting up to 180s` / `database back after Ns`.

**Proof:** new real-process MySQL test `EdgeLocalPrintWorkerLifecycleMySqlTest::test_a_real_worker_survives_a_killed_database_connection_and_keeps_its_slot`
(the worker's server-side session is `KILL`ed → the worker reports the loss, waits, reconnects, keeps its uuid/pid and heartbeats
on; cooperative stop still works) — result in §9.5; unit `EdgeWorkerDatabaseLossTest` (loss matrix incl. the Laravel-wrapped
QueryException; SQL errors are not losses; recover() continues when the DB answers again, gives up after the budget, never waits
on a configuration defect) **OK 2 tests, 22 assertions**.

labcopy-c drill (`60`–`62`, 0.8.3-dev runtime, 20:24 UTC): scoped Start → pid 442324; its ONE MySQL session (id 647 on the copy DB)
`KILL`ed at 20:24:40Z → 6 s later the row is still `running`, same uuid e7dfc4a2…, same pid, heartbeat 20:24:45 (age 1 s); 12 s later
heartbeat 20:24:52 — the worker survived with NO visible recovery line: a single killed session is absorbed by the framework's own
one-shot reconnect (`DetectsLostConnections`) before the F-1b path is reached. The F-1b path is the fallback for a server that stays
down (what happened on the LAB), proven with the readiness seam in the unit test; it cannot be exercised on this host without stopping
the shared MySQL that other agents use. Scoped Stop 20:26:48Z → exited by itself; tree 0 processes; pointer restored to **0.8.1-edge**.
Worth noting for the 0.8.1 crash: the framework reconnect helps only when the server answers again within the retried statement —
a server that is down for minutes throws through, which is exactly where the 0.8.1 workers died and where F-1b now waits.

### 9.4 Finding F-2b closed — identity bound to the worker uuid

`worker_process_token` is now `unix:<start seconds>@<worker_uuid>`; `EdgeWorkerSlotPolicy::livenessFor()` returns `unknown` (→ the
pre-0.8.3 heartbeat rule) whenever the token is not bound to the row's CURRENT uuid, without touching the OS. New unit test
(unbound identity never reaches the probe; a fresh unbound row is refused), the authority MySQL test gained the three-way case
(bound live twin → `live_worker_holds_slot`; same pid rebound to an "older-runtime" uuid + fresh → `fresh_heartbeat_liveness_unknown`,
no takeover; stale → `stale_heartbeat` takeover). `health`/`worker-status` report `identity_bound`.

### 9.5a First re-run (chain 1, 30 Oct 20:23–22:35 UTC, `50`–`54`) — informative, superseded by §9.5

Ran unattended after the outage while the 5-hour API limit had stopped this agent (22:26 UTC): print lifecycle 11/12 (the one red = the
KILL test's original assertion that the F-1b lines must appear — a single killed session is absorbed by the framework reconnect, see
§9.3; assertion corrected), authority 4/4 (44 assertions), **full Edge suite 731 tests: 696 passed / 7 failed / 27 errored**, SQLite
Feature+Unit 396 tests: 3 failed, system map 265 tables / 429 FKs / 302 links / 730 screens. Classification of the reds:

- **HARNESS (DB NAME), 30 of 34 MySQL reds:** `EdgeTenantMigrationUpgradeMySqlTest` ×22, `EdgeTenantMigrationGateMySqlTest` ×4,
  `EdgeLocalSchemaUpgradeMySqlTest` ×4 (one a tearDown cascade), `EdgeCustomerIdentityMigrationMySqlTest` ×1 — every one is
  `Refusing Edge-local database operation: database [pos_test_tenant_relf] is not a dedicated Edge-local database name`:
  `EdgeLocalDatabase::isEdgeDatabaseName()` accepts `bingoo_edge_*`, `edge_local_*`, or a name containing BOTH "edge" and "test"; the
  isolated set I was given (`pos_test_*_relf`) contains "test" but not "edge", so every schema-work command under test fails closed —
  exactly the product's safety rule doing its job against a mis-named target (the 0.8.2 baseline set `pos_test_*_edgewt` passes it).
  Resolution: the gate re-run (§9.5) uses `pos_test_master_edge_relf` / `pos_test_tenant_edge_relf` / `pos_test_edge_local_edge_relf`.
  Recorded for the toolchain notes: an Edge test DB set MUST contain "edge" in every name.
- **PACING under saturation (Agent E's full gate + my suite on one laptop), 4 reds:** `EdgeAuthorityPartitionTest` (18.04 s > 15 s
  budget), `EdgeConnectionPartitionTest` (tick 2 state), `EdgeSyncRaceMySqlTest` ×2 (in-flight pause not reached) — the known
  wall-clock-paced classes (toolchain notes: re-run alone before calling them red); and `EdgeLocalPrintWorkerLifecycleMySqlTest::
  test_real_supervised_start_delivers_with_master_dead` (payload string mismatch once; green in every single-class run) → re-run.
- **SQLite 3 reds = pre-existing debt, not this branch:** `CateringClientFeedbackUiRegressionTest`, `EscPosReportPayloadTest`,
  `DeliveryRiderReassignmentRegressionTest` fail identically on the frozen release worktree `pos-saas-edge` (HEAD 5e8a6324, same test
  files, none touched here: 3/3 red there too). The 5 Edge unit classes and the whole Feature suite are green.

### 9.5 Suites (re-run after the outage, one at a time, edge_relf DB set, Agent F ports; SQLite first per the coordinator's ordering)

| suite | result |
|---|---|
| `EdgeLocalPrintWorkerLifecycleMySqlTest` (12 incl. the F-1b KILL test) / `EdgeAuthorityWorkerLifecycleMySqlTest` (4) — run first on the final code, edge_relf set, 31 Oct (`91-*.log`) | **OK 12 tests, 136 assertions** (end 07:38:48Z) / **OK 4 tests, 44 assertions** (end 07:44:01Z) |
| full Edge MySQL suite (`--filter Edge --debug`, 731 tests = 0.8.2's 726 + 5 new), edge_relf set, the ONLY phpunit on the host, 31 Oct 14:39:32–15:21:28Z (`95-mysql-edge-full.log`, `95-chain3-summary.txt`) | **CLEAN: 731 filtered → 730 passed, 1 skipped (`EdgeDevInstanceSeedMySqlTest`, by design), 0 failed, 0 errored, exit 0** (42 min). The pacing classes (`EdgeAuthorityPartitionTest`, `EdgeConnectionPartitionTest`, `EdgeSyncRaceMySqlTest`) and `test_real_supervised_start_delivers_with_master_dead` all green in this run. History: the 07:44Z attempt was killed by the 08:22:16Z mysqld crash (second real outage; host handle exhaustion, owner-level) — I stopped my own chain bash 497744 and phpunit 384244 by verified pid (`pids-aux.txt`), run discarded as environmental; chain 1 (§9.5a) had 696/731 with every red classified |
| SQLite `tests/Feature` + `tests/Unit` (incl. the 5 Edge unit classes: 23 tests + `EdgeWorkerDatabaseLossTest` 2) — run FIRST, alone, 31 Oct 07:10:48–07:30:35Z (`80-sqlite-feature-unit.log`) | **396 tests, 43,160 assertions, 4 skipped, 3 failures = the pre-existing canonical debt of §9.5a (identical reds on the frozen worktree 5e8a6324; no file of theirs touched here)** → 393/393 of everything else green, incl. every Edge Feature/Unit test |
| migration/update/clean-machine classes, each alone after the suite (`96-mysql-<class>.log`) | `EdgeTenantMigrationUpgradeMySqlTest` **OK 21/397**; `EdgeTenantMigrationGateMySqlTest` **OK 5/56**; `EdgeUpdatePlanHashMySqlTest` **OK 7/49**; `EdgeUpdaterMySqlTest` **OK 10/35**; `EdgeLocalSchemaUpgradeMySqlTest` **OK 5/78** (the new edge migration runs/plans/hashes correctly on an older appliance and a clean one); `EdgeCleanMachineInstallMySqlTest` **NOT GREEN ON THIS HOST — ENVIRONMENTAL, three attempts:** (a) 15:29Z in the chain, 101 assertions in: the installed package's cashier page `GET /edge/local/pos` answered **500** after install, pairing, bootstrap and login had passed (C: full — writes inside the installed appliance failing); (b) 15:31Z re-run alone: `Package build FAILED: copy(): … errno=28 No space left on device` (`98-*.log`); (c) 15:34Z re-run alone with `TMP`/`TEMP` on D:: `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it` while migrating the fresh install DB — **the shared mysqld was refusing connections again** (third MySQL outage of the programme; `99-*.log`) → per the coordinator's rule I stopped launching and did not touch mysqld. The class was green on this branch's base (0.8.2 record: 160 assertions) and nothing in rel/0.8.3 touches its path except the env-overridable ports. **Re-run after the owner's host reboot (6 Nov, 20:0x–20:16:41Z, scratch on D:, edge_relf set, pinned ports, the only phpunit on the host; `99b-mysql-EdgeCleanMachineInstallMySqlTest-after-reboot.log`): OK 1 test, 160 assertions, exit 0** — install from the package, pairing, bootstrap, cashier login + POS page, backup, signed update with the planted migration, uninstall all green; the three earlier reds were environmental as recorded. → all six classes green |
| full-run system map rebuild (`97-system-map-build.log`, from the suite-migrated edge_relf DBs, no MySQL run active) | **265 tables / 429 FKs / 302 inferred links / 730 screens** — identical to the chain-1 build and to the 0.8.1-record baseline (265); committed with this work; artifact not republished |

**Host finding during the final gates (31 Oct 15:3x UTC): the C: drive is FULL** — 0.15 GB free of 238 GB when the clean-machine
re-run died with `copy(): … errno=28 No space left on device` while building its package in `%TEMP%` (`98-…rerun-alone.log`); the
15:29 red (`/edge/local/pos` → 500 after a complete install/pair/bootstrap/login) is the same cause seen from inside the installed
appliance (sessions/cache/log writes failing). Owner-level (the 2.2 GB `%TEMP%` belongs to other tools/sessions — 1.4 GB Claude tool
output store, 240 MB vscode, 217 MB `seera-zatca-lock-test-*` — none of it mine to delete). I freed only my own disposable items:
the 0.8.3-dev staging in labcopy-c (152 MB; reproducible from the branch — the copy's pointer is 0.8.2-edge and its versions dir is
back to 0.6.0/0.7.0/0.8.0/0.8.1/0.8.2) and the dead scratch `edge-clean-g6n5zw` → C: 306 MB free; the clean-machine class was then
re-run with `TMP`/`TEMP` on D: (`D:\laragon2\tmp\agentf-clean`, 78 GB free) so it needs no C: space. Recommendation for the toolchain
notes: run the clean-machine proof with `TMP`/`TEMP` on D: on this host, and treat C: free space < 2 GB as a gate blocker.

**Handover state (31 Oct, after the final gates):** code and tests final on `rel/0.8.3-service-reliability` (commits below). labcopy-c:
pointer **0.8.2-edge**, no worker running, lease released, 0.8.3-dev staging removed (see above). Keep-awake guard pid 349192 ended by
deleting `evidence\agent-f\keepawake.flag` at hand-back; no other process of this agent left running.

## 10. Resume after the owner's host reboot (4 Nov 09:42 UTC; agents resumed 5 Nov, Agent F light work 6 Nov)

### 10.1 PRODUCTION-READINESS ITEM PR-1 — 0.8.2 EdgeWebBackendSupervisor false restart after Modern Standby (owner's words: a readiness item, NOT 0.8.3 development; no product code written)

**Facts (real LAB, 0.8.2-edge, both web backends):** host in Modern Standby 2026-11-03T22:04:52Z → 2026-11-04T07:01:24Z (8 h 56 m). At
07:01:32Z both supervisors logged `probe timeout, progress but beyond the busy grace (last 200 30172s ago) — suspected hung (1/5)`;
at 07:02:17Z `hung: 5 liveness probes failed (no progress, no answer for 30217s) … terminating the hung backend`; restart #4 on each at
07:02:27Z; both backends healthy again within 10 s.

**Cause (code, `app/Services/Edge/EdgeWebBackendSupervisor.php`, unchanged in 0.8.3):** every clock in the supervisor is wall-clock —
`now()` returns `microtime(true)`; `lastOkAt`, `lastProbeAt`, `startedAt`, `healthySince` and `secondsSinceLastOk()` (= `now() − lastOkAt`)
are all wall-clock differences. Across a sleep the wall clock jumps by the sleep length while NO probe could have run, so on resume the
first probe sees "last 200 30,172 s ago": it is already beyond the 120 s `BUSY_GRACE_SECONDS`, the busy classification is unavailable
(`$progressed && $sinceOk <= grace` is false), the first miss is COUNTED, and — because the child itself needs a few seconds to answer
after resume (its own sockets/timers are waking) — the next four probes (5 s apart, 10 s read timeout) miss too → 5/5 at ~45 s →
`taskkill /PID … /T /F` of a backend that was never hung. The 0.8.2 hotfix made this bounded (5 counted misses, not 3) but the clock
jump defeats the busy grace entirely.

**Impact:** a bounded false restart of BOTH backends after every laptop sleep/resume (and after any wall-clock jump forward: NTP step,
hibernate): a brief 502 window at the gateway (both children killed within the same ~10 s, back within ~10 s), one `restarts` increment
per backend, `last_restart_reason "hung: 5 liveness probes failed …"` in the status files. Data-safe: the backends are stateless
`php -S` request servers; no sale, lease, outbox row or print job is touched; the print/authority workers are not supervised this way.
A pilot Branch Server is not supposed to sleep (desktop power plan, no lid) — but a laptop-class box or a mis-set power plan will show
this as "the POS was unreachable for ~20 s after we woke the machine". Severity: low (bounded, self-healing), visible in evidence as
false restarts that an operator would wrongly read as instability.

**Detection (today, no code):** `web-backend-N.status.json` restart counter advancing with `last_restart_reason` naming a
`no answer for <tens of thousands>s` while the host's event log shows a sleep/resume (Kernel-Power 42/107) in the same minute;
`Get-CimInstance Win32_OperatingSystem LastBootUpTime` unchanged (not a reboot); `seconds_since_last_ok` in the status file far larger
than `hang_detection_bound_seconds` (205 s) immediately after resume. Agent C's `Collect-EdgeDiagnostics.ps1` bundle already carries
both status files; add the Kernel-Power events to its host section when this item is worked.

**Proposed fix (for the next release that touches the supervisor; design only here):**
1. **Monotonic clock for every liveness interval:** `now()` → `hrtime(true) / 1e9` (PHP ≥ 7.3; unaffected by sleep? — NOTE: on
   Windows `hrtime` is `QueryPerformanceCounter`, which does NOT advance during Modern Standby/S3, so intervals measured with it
   exclude the sleep automatically; wall-clock `microtime` stays only for the human-readable `last_ok_at`/`started_at` fields in
   the status file).
2. **Resume-gap detection as a belt and braces (covers NTP steps and any clock that DOES advance):** remember the loop's own last tick
   (`lastTickAt`, wall-clock); when a tick finds `now − lastTickAt > N × PROBE_INTERVAL_SECONDS` (N = 6 → 30 s; the loop never sleeps
   longer than a probe interval), log `clock gap of Xs detected (sleep/resume or clock step) — re-baselining liveness`, then reset
   `lastOkAt = now`, `lastProbeAt = now`, `consecutiveProbeFailures = 0`, `busyTicks = 0`, and treat the first probe after the gap as
   a fresh baseline: a miss there is classified `STARTING`-like (not counted) once, exactly as the 15 s `STARTUP_GRACE_SECONDS` treats
   a freshly spawned child — i.e. the backend gets the full `BUSY_GRACE_SECONDS + PROBE_FAILURES_TO_RESTART` cycles budget after a
   resume, the same `HANG_DETECTION_BOUND_SECONDS` (205 s) bound as for a genuine hang that begins after resume.
3. **Status/health:** add `clock_gaps` (count + last gap seconds + when) to the status file and to `edge:local:health`
   `workers.web_backends[].supervisor` so a resume is distinguishable from a restart in evidence.
4. Nothing else moves: back-off, FAILING escalation, process isolation, the busy classification and the bound stay exactly as the 0.8.2
   record states.

**Test to add (`tests/Feature/Edge/EdgeWebBackendSupervisorBusyAwareTest.php` scripted-seam style, as the 14 F-FB5-1 cases):**
`test_a_clock_jump_across_a_sleep_is_a_resume_not_a_hang` — drive the supervisor with the fake clock: healthy probes, then advance the
fake clock by 30,000 s in ONE tick with the child "answering again after 3 ticks" (first two probes miss, third answers) → assert NO
restart, classification goes `HEALTHY → (gap logged, re-baselined) → STARTING/HEALTHY`, `restarts` unchanged, status carries
`clock_gaps 1 / last_gap_seconds 30000`; and the control case `test_a_genuine_hang_after_resume_is_still_detected_within_the_bound`
— same 30,000 s jump, the child never answers → restart exactly after `HANG_DETECTION_BOUND_SECONDS` counted from the re-baseline
(not immediately). Plus one Windows-native disposable proof on labcopy-c: run two supervised backends, put the laptop to sleep for ≥ 5 min
by hand (owner at the keyboard), resume, and read both status files: 0 restarts, 1 clock gap each.

### 10.2 Prepared (NOT run) — the owed `EdgeCleanMachineInstallMySqlTest` re-run, after "F: go heavy"

Preconditions re-checked 6 Nov: C: 55.3 GB free (was 0.15 GB), my D: scratch dir absent (recreated by the command), no process of
mine alive (every pre-reboot pid is dead by the reboot), labcopy-c untouched by the test (it builds its own scratch install), Edge
test DB names contain "edge". Exactly one phpunit on the host (coordinator-serialised). Expected duration: 3–7 min idle (the 0.8.1
record: ~3–7 min idle, up to 1 h 20 under load); the result lands in `evidence\agent-f\99b-mysql-EdgeCleanMachineInstallMySqlTest-after-reboot.log`.

```
cd /d/laragon2/www/pos-saas-rel-083 && export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH" \
 && mkdir -p /d/laragon2/tmp/agentf-clean \
 && TMP='D:\laragon2\tmp\agentf-clean' TEMP='D:\laragon2\tmp\agentf-clean' \
    DB_DATABASE=pos_test_master_edge_relf EDGE_TEST_TENANT_DB=pos_test_tenant_edge_relf EDGE_TEST_LOCAL_DB=pos_test_edge_local_edge_relf \
    EDGE_TEST_CLOUD_PORT_BASE=9815 EDGE_TEST_CLOUD_PORT_SPAN=4 EDGE_TEST_WEB_PORT_BASE=9810 EDGE_TEST_WEB_PORT_SPAN=3 \
    EDGE_TEST_HTTPS_PORT_BASE=8743 EDGE_TEST_HTTPS_PORT_SPAN=0 EDGE_TEST_HTTP_PORT_BASE=8381 EDGE_TEST_HTTP_PORT_SPAN=0 \
    vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCleanMachineInstallMySqlTest \
    > /c/Users/Dell/BingooEdgeLab/evidence/agent-f/99b-mysql-EdgeCleanMachineInstallMySqlTest-after-reboot.log 2>&1; echo EXIT=$?
```
Pass = `OK (1 test, 160 assertions)` (the 0.8.1-record count; 0.8.3 adds none). If MySQL refuses: stop, report, never restart mysqld.
Afterwards: `rm -rf /d/laragon2/tmp/agentf-clean`, fill the §9.5 cell, commit (no push), copy the record.

### 10.3 What the reboot invalidated in this record (re-read 6 Nov)

- Every pid in `pids.json` / `pids-aux.txt` is dead by the reboot (they were all already stopped by me); the keep-awake guard is gone
  (flag absent); the "no process of mine left" statement holds trivially now.
- labcopy-c: pointer still **0.8.2-edge**, versions 0.6.0/0.7.0/0.8.0/0.8.1/0.8.2 (my 0.8.3-dev staging had been removed to free C:);
  its DB `bingoo_edge_agentc_local` lives on the shared :3306 server that logged InnoDB "LSN in the future" at its unclean restart —
  the copy's rows were last checksummed at 07:46Z on 31 Oct (§9.2b); treat the copy as "verify checksums before the next use", not as
  known-good. The owner's isolated instance :3316 is NOT for agent use.
- `pos_test_*_edge_relf` DBs: same caveat; the test harness drops/recreates what it needs, so the owed clean-machine run is unaffected
  (it creates its own `…_install`/`…_install2` local DBs).
- All gate results in §9.5 remain valid as recorded (they completed before the crash that preceded the reboot); nothing green is
  re-run. The only owed item stays the clean-machine class (§10.2).
- The two mysqld outages of 30/31 Oct are now joined by the 4 Nov unclean kill/reboot as the third real sample for F-1/F-1b: a
  0.8.3 worker would have waited its 180 s budget per cycle and exited 1 for the task restart instead of staying down.

```
AGENT_F_SCOPE=development on rel/0.8.3-service-reliability + disposable validation (labcopy-c, relf test DBs)
MYSQL_STARTUP_PLAN=classified bounded wait (EdgeLocalDbReadiness) in both workers + edge:local:wait-db (0/1/2) + plan fields; MySQL-as-service = owner (elevated)
SCHEDULED_TASK_HEARTBEAT_FIX=pid + start token + host next to the heartbeat; EdgeWorkerSlotPolicy; dead process -> takeover (proven: unit, MySQL, labcopy-c pid 369696 took over the dead-pid slot; live duplicate refused at script, launcher and DB level)
PRINTER_HEALTH_PLAN=failed jobs per printer -> DEGRADED; reachability probe (3 s, Windows refused=2.0 s finding); [category] in last_error/log; markFailedTerminal (G4); G3 logging
SCOPED_CONTROL=Invoke-EdgeScopedControl.ps1 (start refuses on port/slot/record; stop by recorded pid + scope + creation time) + edge:local:worker-status --probe
REBOOT_DRILL_PLAN=section 5 (owner, not executed)
MIGRATION_PLAN_0_8_3=NOT EMPTY (1 EDGE_OWNED run, labcopy-c plan_hash sha256:506d1fdf...; rollback to 0.8.2 needs no data change)
GATES_FINAL=SQLite Feature+Unit 396 (3 pre-existing canonical debt reds, all Edge green) | full Edge MySQL suite 731: 730 passed + 1 skipped by design, 0 failed, 0 errored (31 Oct 14:39-15:21Z, edge_relf set) | migration/update classes 21/397, 5/56, 7/49, 10/35, 5/78 green | clean-machine OK 1/160 (6 Nov after the host reboot; the three earlier reds were environmental) | print lifecycle 12/136, authority 4/44, health 5/83, supervision 10/162 green | system map 265/429/302/730 committed
HEAVY_RUNS_REMAINING=none for Agent F; browser pair (Agent E's programme); the owner-run reboot drill; PR-1 (0.8.2 supervisor false restart after sleep) is a readiness item for the next supervisor release
HOST_FINDINGS=C: drive full (0.15 GB free at 15:3xZ; my own 152 MB freed); shared mysqld refused connections again at 15:34Z (third outage) - not restarted by me
REAL_LAB_MUTATED=NO   PRODUCTION_MUTATED=NO   LAB_PRINTER_9100_TOUCHED=NO   REAL_PRINT_JOBS=0   PUSHED=NO
```
