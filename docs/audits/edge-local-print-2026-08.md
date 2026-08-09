# EDGE-LOCAL-PRINT-1 — Branch Server local print delivery (2026-08)

Sprint start: EDGE-LOCAL-POS-1 = GREEN + FROZEN + DEPLOYED DORMANT (prod `5b965b9`, cloud-only).
**Direction LOCKED by review**: the Branch Server itself is the ONE physical transport authority — a
local delivery loop over LAN TCP. The Cloud Windows print agent stays untouched and is NOT exposed
on/against the appliance (a future agent-compat mode would be mutually exclusive, later sprint).

## Locked invariants
- **KOT BUSINESS EVENT ≠ PHYSICAL PRINT COMPLETION.** The frozen POS layer creates kot_batches /
  kot_batch_lines / logical print_jobs and advances kot-sent bookkeeping; this sprint only DELIVERS
  existing queued intents. Delivery retries never regenerate business events.
- **Transport is payload-immutable**: `raw_payload` is materialized at job CREATION; delivery sends
  the exact stored bytes (+ the same `\n\n\n` trailing feed the Cloud agent appends — pinned) and
  NEVER calls EscPosPayloadService again. Proven: queue payload A → mutate the sale → FakePrinter
  receives payload A byte-for-byte.
- **Physical printing is AT-LEAST-ONCE** with logical dedupe. A completed socket write is not proof
  paper emerged; a lease expiring mid-print redelivers the same stored job (physical duplicate is
  possible BY DESIGN — proven with the printer receiving the same bytes twice).
- Shared Cloud domain untouched semantically: no synthetic print_agents row, `cancelled` NOT
  repurposed, `print_jobs.attempts` keeps its Cloud meaning (markFailed transitions only).

## Slice 1 — what was built
- **`edge_local_print_deliveries`** (edge migration; NEVER on Cloud tenants): per-job transport
  metadata — `delivery_state` (waiting|leased|retry_wait|delivered|terminal_failed), `worker_uuid`,
  **`lease_token`** (64-char random — the ONLY completion authority), lease timestamps,
  `failure_count`, `next_attempt_at`, `last_error`. FK RESTRICT to print_jobs. print_jobs.print_status
  stays the authoritative business status.
- **`EdgeLocalPrintDeliveryService`**: claim (branch_server + binding required; queued + printer-bearing
  + active NETWORK printer, branch-or-GLOBAL scope — the same predicate Cloud routing uses; backoff
  elapsed; no live lease; **NULL-printer intents are NEVER claimed** (§20) — historical browser
  intents stay diagnostics); completeSuccess/completeFailure verify the CURRENT token under row locks
  — **stale completion is impossible** (a dead worker's token is refused; a printed job additionally
  can never be demoted); temporary failures back off ([5,15,30,60,120]s, Edge-only bookkeeping,
  print_status stays queued) until the 5th failure runs the shared markFailed ONCE
  (`terminal_failed`); explicit local retry resets to queued/waiting with the exact Cloud admin-retry
  field contract.
- **`EdgeNetworkPrinterTransport`**: PHP TCP (8s connect/write timeouts), exact stored payload +
  pinned trailing feed. No ESC/POS init/cut in this slice (payload capability = later pass).
- **`edge:local:print-worker`** (CLI-allowlisted): `--once` deterministic cycle / loop with idle
  sleep / `--max-jobs`; fail-closed off branch_server or without a binding; printer IP/port only from
  bootstrapped trusted config; one job's failure never kills the loop; SIGTERM handled where the
  platform supports it (Windows service wiring = installer sprint).
- **§19 narrow shared guard** in `PrintJobService::markFailed`: a PRINTED job early-returns —
  protects Cloud too (an agent that already reported printed still passes the ownership check on its
  own duplicate failure report). Cloud behavior otherwise untouched.
- **§16 bootstrap ROUTING PARITY fix**: Cloud routing selects printers/mappings with
  `branch_id = branch OR NULL`, but the export only shipped `= branch` — global default printers
  silently never reached the appliance (Edge would browser-fallback where Cloud picks a device).
  Export now ships branch + GLOBAL printers/mappings; the importer accepts NULL-branch rows as
  GLOBAL (a row bound to a DIFFERENT branch is still CROSS_BRANCH-refused). Parity proven end-to-end:
  Cloud resolves global printer G for the branch → bootstrap → Edge resolves the SAME G; foreign-
  branch printers never leak; no dangling references.
- **§17 truthful `local_print` readiness**: not_configured | blocked (unresolved NULL-printer intents,
  dangling mapping/terminal printer refs) | ready — never an activation claim; `activation_ready`
  stays hard false.

## Proof inventory (all real MySQL; master DB unreachable inside every Edge worker)
- `CloudPrintAgentContractMySqlTest` (NEW, 4/19): pins the previously-untested Cloud contract —
  pending claims only queued/network/own-branch jobs, 2-min lease blocks then re-hands, wrong token
  401, foreign agent 403, printed idempotent, printed-never-demoted, failed/attempts, retry→claimable.
- `EdgeLocalPrintDeliveryMySqlTest` (7/64): E2E receipt + KOT through the REAL worker command and a
  REAL TCP FakePrinter — exact stored bytes incl. after sale mutation; business event untouched by
  delivery (1 batch, kot_print_count 1, sent advanced once); NULL-printer never claimed; backoff →
  terminal(markFailed once) → local retry → delivered; stale token refused (printed survives);
  fail-closed off branch_server; truthful readiness states.
- `EdgeLocalPrintRaceTest` (3/17, genuine OS processes): simultaneous claim → exactly one lease;
  delivered-then-died → lease expiry → SAME bytes redelivered (physical duplicate documented) → the
  dead worker's stale failure REFUSED, printed survives; died-before-send → live lease blocks a second
  claim, then exactly one delivery after expiry.
- `EdgeLocalImportMySqlTest` +1 (8/32): the §16 global-printer parity proof.

## Slice-1 closure — active-lease authority + printer FIFO (correction on `5cf6966`)
Review found 4 transport-correctness issues + 1 proof gap; all closed:
1. **Lease EXPIRY revokes authority**: `tokenIsCurrent` now also requires `lease_expires_at` in the
   future — an expired lease is stale even before any reclaim. Proven: A claims → lease expires with
   NO reclaim → A's completeSuccess AND completeFailure both refused with zero mutation (queued,
   attempts 0, failure_count 0) → B reclaims with a new token and completes.
2. **ONE lock order everywhere**: print_job → delivery (claimNext already led with the job row;
   completeSuccess/completeFailure/retryTerminalFailed now match — the inverted delivery→job order
   could deadlock a reclaim against a completion). Two-process boundary race proven: completion(with
   the boundary token) vs reclaim-after-expiry — no deadlock/SQL leakage, coherent final state either
   way (printed+delivered+no token, or queued with exactly ONE current token — the reclaimer's), and
   a failure can never be fabricated.
3. **Per-printer FIFO (head-of-line)**: a NEWER queued job for the SAME printer is blocked while an
   OLDER queued job is leased-live or waiting out retry backoff — the kitchen can never receive the
   Addition/CANCEL KOT before the original round. Different printers proceed independently; a
   `terminal_failed` older job does NOT block (it never auto-runs; an operator must explicitly
   resolve/retry — documented rule). Proven: A retry_wait + printer recovered → B refused until A's
   backoff elapses → captured FakePrinter stream is A-bytes strictly before B-bytes; plus a
   two-process same-printer race (A always first, byte order proven; both interleavings controlled).
4. **Exact backoff contract**: `MAX_FAILURES = 6` — temporary failures 1..5 schedule exactly
   [5,15,30,60,120]s (every configured slot reachable, each delay asserted), failure #6 is terminal.
5. **Shared requeue — no duplicated retry semantics**: new `PrintJobService::requeueFailed`
   (failed|cancelled only) is the ONE requeue; Cloud `PrintJobController::retry` delegates to it
   (REAL controller path now tested: failed → 200/queued → claimable via the real agent `pending()`;
   printed → 422) and Edge `retryTerminalFailed` delegates to the SAME operation (and refuses
   anything that is not an Edge `terminal_failed` delivery).
6. **Completion state consistency**: under the job lock, completeFailure refuses any non-queued job
   (a printed job never gains a failure counter or a printed+terminal_failed contradiction) and
   completeSuccess converges idempotently on printed. Proven with markPrinted racing an active lease.

## SLICE 1 VERDICT — GREEN + FROZEN + DEPLOYED DORMANT (2026-08-09)
`cc65339` accepted as final Slice-1 HEAD. **Production dormant deploy proven**: `5b965b9` →
`cc653397c8257fccff4eb9a4c6825b23b42a7292` (exact reviewed HEAD, single deploy.sh run, tracked tree
clean, `.env.bak-binaries` md5 identical pre/post). Cloud stays cloud: 0 `APP_ROLE`/`EDGE_*` env,
0 `edge/local` routes (HTTP 404), **`edge_local_print_deliveries` on ZERO Cloud tenants** (edge-path
migration never runs there), Print Agent API routes registered normally, `edge:local:print-worker`
FAIL-CLOSES on Cloud ("only runs on a Branch Server"), no worker process. Smoke identical to
baseline: tb_diff=0.00 / neg_on_disallowed=0 / dept_neg=0 ×7 tenants; site + demo logins 200; print
admin pages resolve (302→login); zero new log errors. Local Mode inactive, activation_ready=false.
WORDING RULE (accepted policy, keep in future docs): strict automatic FIFO holds only while work is
automatically deliverable/retrying — a `terminal_failed` job deliberately does NOT block later jobs,
so a manual operator retry afterwards does not recover historical physical order. "Addition can never
print before original" is therefore NOT unconditional; it holds up to terminal failure.

## Slice 2 — appliance worker lifecycle (supervision, health, safe stop)
INSPECT-FIRST grounding established: the repository's ONE native supervision mechanism is the
print-agent's **Scheduled Task** (`Register-ScheduledTask -AtStartup`, SYSTEM/Highest, RestartCount
999 / 1-min interval, unregister-first idempotent reinstall) — no NSSM/WinSW anywhere; no scheduler
or queue exists on the appliance (`routes/console.php` registers nothing on branch_server; neither
`schedule:run` nor `queue:work` is CLI-allowlisted), so the print worker is the appliance's FIRST
long-running process; `pcntl` is unavailable on Windows php-cli, so stop must be COOPERATIVE.
- **Supervision** (§6/§7): `scripts/edge/Install-EdgePrintWorkerTask.ps1` /
  `Stop-EdgePrintWorkerTask.ps1` / `Uninstall-EdgePrintWorkerTask.ps1` mirror the print-agent task
  policy verbatim (task `BingooEdgePrintWorker`). `scripts` is now on the artifact include allowlist
  (it previously never shipped). The worker itself now runs the REAL appliance DB path
  (`EdgeLocalDatabase::useAsTenantConnection()` — a genuine bug the real-process proof exposed: the
  Slice-1 worker only worked because in-process tests pre-mapped the connection), waits with bounded
  retries (20×3s) for MariaDB after a reboot, and one job's failure never kills the loop.
- **One-worker topology + heartbeat** (§9/§12, explicit): `edge_local_print_worker_state` (edge-only
  singleton_guard row) — running|stopped, worker_uuid, started/heartbeat/stop-request/graceful-stop
  timestamps, last_error. Liveness = heartbeat staleness (90s, below the 120s job lease); a duplicate
  daemon observes a FRESH-heartbeat running row and exits cleanly; a STALE row (crash) is taken over.
  NEVER a lease authority — job lease tokens remain the sole completion authority (a takeover found a
  MySQL affected-rows gotcha: a same-second guarded heartbeat UPDATE reports 0 rows → read-verify-save
  with the ≤1-iteration-overlap window documented).
- **Cooperative stop** (§7/§8): `--stop` sets the DB flag; the loop checks it BETWEEN jobs, finishes
  any in-flight delivery, records a graceful stop. `Stop-EdgePrintWorkerTask.ps1` = cooperative stop
  FIRST (150s > one lease), then task stop/disable. Leases are NEVER rewritten on start or stop; a
  hard kill falls back to lease-expiry recovery. **§AE flags for the update-contract owner** (recorded,
  not silently worked around): (a) §AE's maintenance-mode step is currently unexecutable on an
  appliance — `down`/`up` are not CLI-allowlisted; (b) §AE names no process-quiescing step — proposed
  addendum: `Stop-EdgePrintWorkerTask` → backup/update/forward-migrate → `Install/Start` again.
- **Readiness config ≠ process** (§10): `local_print` (config: not_configured|blocked|ready) is now
  joined by `local_print_worker` (process: running|stale|stopped|not_installed) — a configured
  printer with a dead worker can no longer look healthy. `activation_ready` stays hard false.
- **Diagnostics** (§14): read-only `edge:local:print-status` (allowlisted) — worker health, bound
  branch, routable printers, queue counts by delivery state, unresolved NULL-printer intents, oldest
  queued age; proven to mutate nothing and to leak no tokens/secrets.
- **Proofs** (`EdgeLocalPrintWorkerLifecycleMySqlTest`, 7/70): §13 REAL supervised start — the exact
  Scheduled-Task command line (`php artisan edge:local:print-worker --once`) spawned as a separate OS
  process with `DB_DATABASE` pointed at a nonexistent master → delivers to FakePrinter, exact bytes,
  graceful state row; duplicate-start refused (nothing claimed) then stale-takeover works; restart
  respects a live lease then resumes after expiry (B); restart preserves next_attempt_at (no
  hot-loop, D) and terminal_failed (never auto-reset, E); clean restart regenerates no KOT business
  events (F); cooperative stop of a REAL background looping worker (state row observed running →
  --stop → graceful exit code 0, last_graceful_stop_at set, zero leases touched); readiness split +
  read-only diagnostics. (§11 C — die-after-send redelivery — remains proven by
  EdgeLocalPrintRaceTest; documented physical duplicate.)
- **Physical certification** (§15): grounded procedure only → `docs/ops/EDGE_PRINTER_CERTIFICATION_PLAN.md`;
  FakePrinter ≠ certification; no ESC/POS changes until real-hardware requirements are recorded.

## Slice-2 supervision closure (correction on `86822a8`)
Review found 3 correctness/security defects + 2 certification gaps; all closed:
1. **Worker NEVER runs as SYSTEM/Highest.** The install script's default principal is now
   `NT AUTHORITY\LOCAL SERVICE`, LogonType ServiceAccount, RunLevel **Limited** (the grounded
   appliance contract: restricted service account, not admin at runtime; `-ServiceAccount
   BingooEdgeSvc` supported for installs where LOCAL SERVICE cannot be ACLed; passwords never in the
   artifact/repo; the installer elevates only to REGISTER). The script REFUSES `SYSTEM` outright
   (guard proven firing). Generated-object proof (un-elevated session): UserId=LOCAL SERVICE,
   LogonType=ServiceAccount, RunLevel=Limited, boot trigger, RestartCount 999/PT1M, correct
   quoted action + WorkingDirectory. ACL prerequisites documented in the script header.
2. **Zero-row first-start race fixed**: acquire() now does deterministic `INSERT IGNORE` of the
   singleton placeholder (the only unique key on the table) → lock the now-existing row → ONE
   ownership decision; errno-1213-only single retry lands on the same decision. GENUINE two-process
   empty-table race proven: exactly one winner, loser gets the controlled duplicate-start refusal,
   one row/one worker_uuid, no duplicate-key/deadlock/QueryException leakage.
3. **health() truthfulness**: `unavailable` added — a DB connection/query failure reports
   `database unavailable (<exception class>)` (sanitized, no DSN/credentials) and can NEVER
   masquerade as `not_installed` (matrix proven: absent-schema→not_installed, no-row→not_installed,
   running, stale, stopped, unreachable-DB→unavailable; readiness carries it through).
4. **Artifact surface narrowed** to `scripts/edge` (never the whole `scripts/` tree).
5. **Scheduled-Task physical registration = EXPLICIT RELEASE GATE (NOT certified here).** The dev
   session is un-elevated, so `Register-ScheduledTask` with a ServiceAccount principal cannot run;
   certified instead: AST-parse of ALL 5 edge scripts (zero syntax errors) + the generated
   action/trigger/settings/principal objects + the SYSTEM-refusal guard. Actual registration /
   boot-start / restart-policy execution belongs to the installer/pilot on real hardware —
   recorded, not fabricated.
Production re-proof (evidence hygiene, cc65339 unchanged): process census with a split pattern that
cannot self-match → **ZERO** real print-worker processes; `edge:local:print-worker --once` on Cloud →
authoritative branch_server-only refusal; logs actually written since the deploy: NO Laravel log file
at all, nginx = 18 lines all `access forbidden by rule` on scanner `.env` probes (the block rule
working; unrelated to the deploy), php-fpm = daily log-reopen NOTICE only.

## Out of scope (unchanged)
Local Mode activation (`activation_ready=false`), sync, Windows service/installer wiring, ESC/POS
capability upgrades, reroute-unresolved-intent operation (deliberate audited op, later), Cloud agent
redesign. Production remains `5b965b9`, cloud-only, Edge dormant — this WIP is NOT deployed.
