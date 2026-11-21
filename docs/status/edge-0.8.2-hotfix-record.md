# Bingoo Edge 0.8.2-edge — F-FB5-1 busy-aware web-backend supervision (owner decision "F-FB5-1 / 0.8.2-EDGE HOTFIX", 29–30 Oct 2026)

Base: 0.8.1-edge = `5f728d4ff1520aa78a3b7b7d6fb2be217e0d77ae`. Scope: F-FB5-1 only — no canonical merge; authority fencing, Cloud leases, stock ownership,
sales ingestion, customer identity, outbox, refund accounting and automatic failover untouched (`git diff --stat` in §1). Evidence:
`C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.2-edge\` (`win\`, `build\`, `labcopy\`). Real LAB: 0.8.1-edge, STANDBY_READY, NOT modified by this directive.

## 1. Root cause and fix

```
ROOT_CAUSE_CONFIRMED=YES — 0.8.1's EdgeWebBackendSupervisor declared a backend hung after 3 consecutive probes that did not answer within 3 s. php -S is
  single-threaded: while it renders a legitimate page (login 1–10.6 s on the LAB laptop at 86–100 % CPU), the probe waits and times out; three such windows
  in 15 s terminated a child that was serving the whole time (its request log kept advancing). 28 false restarts + two nginx 502s on the real LAB (29 Oct).
```

Fix (commit a6113772, pushed) — `App\Services\Edge\EdgeWebBackendSupervisor`:

| element | 0.8.1 | 0.8.2 |
|---|---|---|
| probe read timeout | 3 s | **10 s** (connect 2 s, interval 5 s, startup grace 15 s unchanged; probes are synchronous — never overlap, each bounded) |
| misses to restart | 3 | **5 COUNTED misses** |
| busy awareness | none | a miss accompanied by child PROGRESS (its stderr request log grew since the previous probe — `php -S` logs Accepted/Closing per connection; non-Windows: bytes relayed from its pipes) while the last 200 is at most **BUSY_GRACE 120 s** old = `BUSY_OR_SLOW`, NOT counted (a silent miss — no request completed since the previous probe — is counted at once) |
| bound | — | log advancement never grants indefinite health: once the last 200 is older than the grace, every miss counts; `HANG_DETECTION_BOUND_SECONDS = 120 + 5 × (5 + 10 + 2) = 205 s` from the last successful answer to the termination of a child that never answers again (typical, silent hang: ~60–70 s) |
| classification | starting / healthy / probe-failing / hung / restarting / failing | `STARTING` → `HEALTHY` → `BUSY_OR_SLOW` → `SUSPECTED_HUNG` (counted) → `CONFIRMED_HUNG` → `RESTARTING` / `FAILING` (+ stopped / exited / duplicate); every transition logged with the probe outcome (ok / refused / timeout / bad_status), probe ms and seconds since the last 200 |
| status file | state, pid, restarts… | + `classification`, `last_ok_at`, `seconds_since_last_ok`, `busy_ticks`, `consecutive_probe_failures`, `last_probe_outcome/ms`, `progress_marker`, `policy{…}` |
| back-off, failing, isolation | 1→30 s capped, reset after a healthy minute; ≥10 restarts/10 min = failing; taskkill /T of the OWN child only | unchanged |
| health (`EdgeApplianceHealthService`, `edge:local:health`) | "listening but not answering HTTP (hung)" whenever its own 10 s probe missed | mirrors the supervisor: `availability` = responding / **busy** (supervisor BUSY_OR_SLOW/HEALTHY/STARTING or a 200 within the grace) / unavailable; problems only for not-listening, unavailable, confirmed_hung/restarting, failing — a busy backend on a loaded host is reported as busy, not hung; FAILING and restarts still degrade the tier (authority/stock/sync health never makes the web tier healthy) |

Tests: `tests/Feature/Edge/EdgeWebBackendSupervisorTest.php` — 14 cases on scripted seams (policy constants + bound formula; genuine hang without progress →
5 counted misses → restart; three misses alone no longer restart; busy backend with advancing log never restarted inside the grace; log advancement alone
never grants indefinite health — the bound holds; exited child restart; once mode; duplicate port; failing + capped back-off; stop) and the Windows-native
proofs of §2.

## 2. Windows-native reproduction and regression (disposable: supervisors on 8190/8191 via `sup-driver.php`, real app under `APP_ENV=edgestress`, a disposable nginx on 8281 with the LAB gateway's upstream rules, a router adding `/__slow?s=N` and `/__hang`; every pid recorded, only recorded pids terminated)

Harness: `scratchpad\win082\` (`win-082.php` orchestrator, `sup-driver.php` = the REAL `EdgeWebBackendSupervisor` class around a real `php -S` child, `slow-router.php`
= the production `public/index.php` plus `/__slow?s=N` (a legitimate N-second render) and `/__hang` (a genuine deadlock: the single-threaded server never answers
again), `load-client.php` sequential requesters, a disposable nginx on 127.0.0.1:8281 with the LAB gateway's upstream rules — `least_conn`, `max_fails=3
fail_timeout=5s`, `proxy_next_upstream error timeout http_502 http_503`). Reports: `…\evidence\release-0.8.2-edge\win\*-report.json` (final code, 29 Oct
18:45–19:18 UTC) and `…\win\grace60-run1\` (the first run at a 60 s grace, see 2.4). Every process the harness starts is recorded in `state.json` and only
those pids are ever terminated (`taskkill /PID <recorded> /T /F` or `Stop-Process -Id`); the stop phase checks that the LAB ports 8090/8091/8443 are untouched.

### 2.1 NORMAL_LOAD_RESULTS (one sequential mixed client per backend, 500 requests each, both backends in parallel; host shared with other sessions' suites)

```
BACKEND_1_REQUESTS=500 sent / 500 answered (200 ×332, deliberate 404 ×166, 2 client timeouts at 20 s), p50 593 ms, p95 6.8 s, p99 12.2 s, child pid 17268 unchanged, restarts 0
BACKEND_2_REQUESTS=500 sent / 500 answered (200 ×333, 404 ×166, 1 client timeout), p50 579 ms, p99 13.6 s, child pid 345808 unchanged, restarts 0
  (the earlier run of the same phase on the 60 s-grace code, identical for this scenario: 500/500 and 500/500, 0 timeouts, p50 640 ms, p99 1.8 s — the second run's tail latencies are
  this laptop's external load, not the backends: first-50 p50 4.1 s vs last-50 p50 0.5 s; no progressive slowdown, both supervisors HEALTHY throughout, no restart)
```

### 2.2 SATURATED_LOAD_RESULTS (12 CPU-burner processes + 4 parallel mixed clients per backend + 1 gateway client, 150 s)

```
SATURATED_LOAD_RESULTS=backend 1: 88 requests, 200 ×60 / 404 ×28, 0 timeouts, 0 errors, client p50 6.2–6.5 s, worst p99 16.6 s; backend 2: 88 requests, 0 timeouts, p50 6.3–6.5 s, worst p99 20.0 s;
  gateway: 21/21 HTTP 200 (p50 6.6 s, p99 15.7 s); FALSE_POSITIVE_502=0; UNNECESSARY_RESTARTS=0; supervisors: HEALTHY → one BUSY_OR_SLOW tick each at ~130–136 s (probe
  timeout with the request log advancing) → HEALTHY again by 153–155 s; busy_ticks reset to 0 after the next 200. (The 0.8.1 policy would have restarted both: every request took > 3 s.)
```

### 2.3 SLOW_REQUEST_RESULTS (legitimate 12 s and 15 s renders back to back on both backends, plus a health client per backend and a gateway client, 130 s)

```
SLOW_REQUEST_RESULTS=slow clients 18/18 and 18/18 HTTP 200 (p50 3.3–3.5 s, max 17.1–17.3 s = a 15 s render queued behind another request); health clients 18/18 and 18/18 (max 16.9 s);
  gateway 17/17, FALSE_POSITIVE_502=0; UNNECESSARY_RESTARTS=0 (restarts 0 → 0 on both); classification alternated BUSY_OR_SLOW ↔ HEALTHY 6–7 times per backend
  (every probe miss coincided with log advancement = the child completing requests; no SUSPECTED tick); after the load: health 200 in 647 / 689 ms, both HEALTHY
```

### 2.4 Why the grace is 120 s (first SLOW run at 60 s — `grace60-run1\slow-report-run1-grace60.json`, `supervisor-2-run1-grace60.log`)

With BUSY_GRACE 60 s the same scenario restarted backend 2 ONCE: its back-to-back 12/15 s renders kept it from answering any probe within 10 s for 79 s while it
kept completing requests ("hung: 5 liveness probes failed (progress continued but no answer for 79s — beyond the 60s busy grace)"); backend 1, with gaps between
renders, was never restarted; the gateway served 0 × 502 (failover). A backend that keeps completing requests is serving, so the progress-backed grace was
raised to 120 s and the suites/Windows phases were re-run on that code. The grace is still a hard cap (2.6: a child that never answers again is recycled within
205 s even if its log keeps moving), and a SILENT child (no completions) is still confirmed in ~70 s — the fast path did not change.

### 2.5 GENUINE_HANG_RESULTS (`/__hang` on backend 1, three rounds; backend 2 and the gateway polled every ~1.5 s during each round)

```
GENUINE_HANG_RECOVERY=detected and recovered ×3 — classification path per round: HEALTHY → SUSPECTED_HUNG at +27–28 s (first counted miss: probe timeout, no progress)
  → CONFIRMED_HUNG + terminate at +70 s ("hung: 5 liveness probes failed (no progress, no answer for 70s)") → RESTARTING (back-off 1 s / 2 s / 4 s) → STARTING → HEALTHY
  at +87.7 s / +90.6 s / +91.7 s (includes the 15 s startup grace); new child pid each time (17268 → 355172 → 160716 → 352812)
GENUINE_HANG_DETECTION_BOUND=205 s documented worst case (120 s progress-backed grace + 5 × (5 + 10 + 2) s); measured silent hang: terminate at 70 s, serving again at 88–92 s
OTHER_BACKEND_DURING_HANG=12/12, 12/12, 14/14 HTTP 200 on backend 2 — never touched
GATEWAY_DURING_HANG=10/10, 10/10, 12/12 HTTP 200, 0 × 502 (nginx failed the hung upstream over to backend 2) — failure-injection phase, reported separately from 2.1–2.3
```

### 2.6 Restart back-off, failing escalation, rotation, cleanup

```
RESTART_BACKOFF=kill loop on backend 2 (child killed by its RECORDED pid 10 times, each after the replacement was up): restart delays 1, 2, 4, 8, 16, 30, 30, 30, 30, 30 s
  (log lines "restart #n in Xs"); restarts_last_10min 1 → 10; the 10th restart is logged "— FAILING (… 10 restarts in 10 min)" and the status file carried state
  FAILING for edge:local:health to surface; the backend still came back (final state healthy, backoff 30 s); after the hang rounds a healthy minute had reset
  the back-off (the rotation kill restarted in 1 s)
LOG_ROTATION=web-backend-1.err.log grown to 7,269,383 B → at the next (re)start rotated to web-backend-1.err.1.log (7,269,383 B), live file 89 B
ORPHAN_PROCESS_CHECK=PASS — stop terminated only the two recorded driver trees and the disposable nginx: ports 8190/8191/8281 free, recorded child pids 422700/402076 gone,
  driver pids 406236/406592 gone; LAB ports 8090/8091/8443 still listening (untouched)
WINDOWS_NATIVE_TESTS=PASS (normal, saturated, slow, genuine hang ×3, kill loop + failing, rotation, stop) on commit a6113772
```

Harness note: an earlier attempt of this chain stalled because the orchestrator spawned the drivers through PowerShell with output redirection (the child
inherited the pipe, so the orchestrator never saw EOF); the stale disposable processes were identified by command line and stopped by explicit pid, the driver now
writes its own log, and the chain was re-run from `start`. No LAB process was involved at any point.

## 3. Gates

All on the release commit **a6113772** (`…\evidence\release-0.8.2-edge\gates\`).

```
FEATURE_EDGE=206 tests / 41,833 assertions PASS (EdgeApplianceArtifactBoundaryTest, EdgeApplianceDependencyClosureTest, EdgeRuntimeCacheIsolationTest, EdgeWebBackendSupervisorTest ×14 included)
FEATURE_POS=31 tests / 490 assertions PASS
UNIT=116/119 — the same three canonical reds as on 5f728d4f and ac5b168d (CateringClientFeedbackUiRegressionTest, EscPosReportPayloadTest, DeliveryRiderReassignmentRegressionTest), unrelated to Edge
FULL_EDGE_GATE=726/726 — the 55-minute run (08:07–09:02 UTC, 9,887 assertions) finished 723/726 while two other agents copied ~1 GB of appliance/vendor trees on the same laptop;
  the three failures were timing budgets only (EdgeAuthorityPartitionTest "healthy-partition checks ran well inside the lease" 19.5 s > 15 s; EdgeConnectionPartitionTest pacing 20.4 s > 17 s;
  EdgeCleanMachineInstallMySqlTest "updated web backend did not listen on 127.0.0.1:9884 within 30s" — the clean-install's updated backend took longer than 30 s to come up under that I/O load);
  each was re-run alone on the quiet host (isolated DB set, 09:03–09:19 UTC): clean-machine PASS (160 assertions: fresh install from a package, bind, serve, backup, update, uninstall, include-probe),
  authority partition PASS (51), connection partition PASS (104). No product assertion failed in either run.
KNOWN_ENVIRONMENTAL_FAILURES=the three Unit canonical reds (pre-existing, unrelated) + the three timing budgets above (green in isolation) — none represented as fixed by this hotfix
ARTIFACT_BOUNDARY=PASS (EdgeApplianceArtifactBoundaryTest + the package audit's boundary block: forbidden_hits [], cloud_only_present [], 193 tenant migrations audited, 0 problems)
UPDATER=EdgeUpdaterMySqlTest + EdgeUpdatePlanHashMySqlTest PASS inside the gate; clean-machine install PASS (above)
BROWSER_REGRESSION=reused — the 29 Oct FB5 evidence on 5f728d4f (Online 17/17, Edge 18/18, W15 pair, W18 both, pixels = 087278e9 pattern, geometry NONE 998 boxes) applies unchanged:
  a6113772 touches no view, route, controller, POS JS, capability key, authority/lease, ingestion, customer-identity or refund code (git diff --stat 5f728d4f..a6113772 = the supervisor, the health
  service/command, the supervisor test); the cashier-tier behaviour is covered by the Windows-native proofs of §2 instead
```

## 4. Build 0.8.2-edge, rehearsal 0.8.1 → 0.8.2 → 0.8.1 on the disposable copy

Build (`…\evidence\release-0.8.2-edge\build\`): same P5B procedure as 0.8.0/0.8.1 — `git archive a6113772` export (`releases\src-a6113772`), `edge:build-package`
RELEASE from the clean committed worktree with the 0.8.0 no-dev vendor closure (`composer.lock` unchanged since 0.8.0, verified byte-identical), bundled php-8.3.16 +
nginx-1.22.0, signed from the custody keystore v1 (passphrase from its file, never on argv) → `edge:audit-package` → byte-level source proof → identity check with
the product's own crypto (`identity.json`). The 0.8.1 package stays untouched (audited pristine on 29 Oct; not rebuilt, not re-signed).

```
SIGNED_RELEASE_VERSION=0.8.2-edge
SIGNED_SOURCE_COMMIT=a6113772de642f4f562646138805adcd85472938 (0.8.1 + the F-FB5-1 fix; no canonical merge)
PACKAGE_HASH=1455cf1a65d4b2c6c9a4e6abbdfc3537d2921e4948558774758ec60b872fe808   (artifact_manifest_hash 7771b1ab40fe08bfa10d6852d1be152bfe52689dfa4d672e969a0e7258bf9946, file_count 8999, built 2026-10-30T09:26:27Z, release, source_dirty false)
SIGNATURE=valid against the release public key (key id a2eaf8a3d3e66a48 = sha256 fingerprint of the key), a foreign key refuses it; payload version 0.8.2-edge, source_revision a6113772…, target branch_server, schema edge-config-v1
PACKAGE_AUDIT=ok=true after signing (missing 0, unlisted 0, tampered 0, package_hash_ok, boundary ok) and ok=true AGAIN after the pre-install dry-run through the copy's 0.8.1 launcher (unlisted 0)
SOURCE_PROOF=PASS — 2,268 non-vendor files byte-identical to the export, 0 mismatches, 7 only-in-package (6 .gitkeep + edge-build-manifest.json, as in 0.8.0/0.8.1), 1,396 export files excluded by the boundary
PACKAGE_CACHE_ISOLATION=PASS — the dry-run booted the signed 0.8.2 package in place for the plan: package bootstrap\cache still only .gitkeep; its caches went to labcopy-081\data\BingooEdge\logs\runtime-cache\0bc446667491\
MIGRATION_PLAN=EMPTY — dry-run through the 0.8.1 launcher (01-dry-run-082.json): from 0.8.1-edge to 0.8.2-edge, manifest edge-tenant-migration-manifest@2026-10-22.1, entries [] / run [] / skip [] / blockers [], verdict OK, plan_hash sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95 (identical to the 0.8.1 plan hash: same manifest, same schema state, nothing pending)
```

Rehearsal on the disposable copy (`C:\Users\Dell\BingooEdgeLab\labcopy-081`: the LAB install root copy with the 0.8.1 runtime active, DB `bingoo_edge_dryrun_lab_081` = a fresh
dump of the REAL post-0.8.1 appliance DB taken 29 Oct 16:3x UTC, dead Cloud URL, invalid device secret, ports 8290/8291; evidence `…\labcopy\01–08`):

```
UPGRADE_081_TO_082=PASS — `labcopy artisan edge:local:update <pkg>\update\edge-update-0.8.2-edge.json <pkg>\app --expected-plan-hash=sha256:20a8aad4… --json` (09:29:45 → 09:35:55 UTC, 6 min):
  result applied, pre_update_backup edge-backup-20261030_092949-shzaku.enc, schema_after edge-config-v1, migrations_run [], migrations_skipped []; pointer 0.8.1-edge → 0.8.2-edge
  (versions 0.6.0 / 0.7.0 / 0.8.0 / 0.8.1 / 0.8.2); migrations 218 → 218; edge_local_updates #6 = 0.8.1 → 0.8.2 applied; staged versions\0.8.2-edge = the signed manifest (8,898 app files, 0 differing);
  the ACTIVE 0.8.2 runtime keeps its caches in its own bootstrap\cache; health on 0.8.2: a6113772 / release / manifest 7771b1ab…, schema_compatible true, update.last applied, bootstrap v8;
  schema-upgrade --dry-run on 0.8.2: run [], blockers [], same plan hash
  DATA (vs the 29 Oct LAB baseline MD5s, rows ordered by id): sales_orders 9bbd2ad2… = identical, sales_order_lines cc04a3e4… = identical, outbox c9c08b1d… = identical (5 acknowledged),
  customers e42077b9… = identical, stock movements 17 = 17, authority_state standby, config revision 9 = 9, edge_schema_version unchanged
  WEB TIER on the packaged 0.8.2 (pointer 0.8.2, `labcopy artisan edge:local:serve --worker=1`): backend 8290 HEALTHY, 40/40 probes 200 (probe ok 536 ms), status file carries classification
  HEALTHY + policy {read 10 s, interval 5 s, busy grace 120 s, 5 misses, bound 205 s, back-off 1–30 s, failing 10/600 s}; edge:local:health → web#1 availability=responding, supervisor healthy;
  stop by the supervisor's own pid tree → child gone, port 8290 free, no orphan
ROLLBACK_082_TO_081=PASS — documented pointer revert (empty plan, schema unchanged): current ← 0.8.1-edge; health 0.8.1-edge / 5f728d4f, schema_compatible true, DB copy unchanged
  (migrations 218, sales 6, rowsets identical), schema-upgrade --dry-run on 0.8.1 empty; the 0.7.0 process was not used. Copy left on 0.8.1 with 0.8.2 staged beside it.
```

## 5. Version metadata hygiene (owner §8) — read-only finding

`appliance.json` (install root, non-secret) carries `edge_app_version`. Readers in the whole tree: `scripts/edge/Install-EdgeAppliance.ps1:170` WRITES it at
install; `scripts/edge/Update-EdgeAppliance.ps1:111` WRITES it after a successful update through that wrapper (`$layout.edge_app_version = $current`);
`scripts/edge/appliance/edge-launcher.php` and `App\Support\EdgeApplianceLayout` READ only `data_root`, `runtime_root`, `php`, `gateway`. Nothing reads
`edge_app_version`: not the launcher, not `EdgeUpdateVerifier` (version transition from `config('edge.app_version')` of the RUNNING runtime = the artifact's
own build manifest), not `EdgeApplianceHealthService` (reports `runtime.edge_app_version` from the runtime), not the schema/compatibility checks (bootstrap
and config schema come from the runtime and the local meta row). The LAB value reads `0.8.0-edge` because the 0.8.1 update ran `edge:local:update` directly
(the owner-approved path) instead of the `Update-EdgeAppliance.ps1` wrapper that rewrites the field.

```
VERSION_METADATA_FINDING=informational only — no update decision, health report or compatibility check reads appliance.json's edge_app_version; not a blocker.
  Recommended (not done, out of scope): make the artisan updater refresh the field after a successful switch, or run the wrapper's one-line update when the owner next touches the LAB.
```

## 6. Real LAB (read-only throughout this directive)

```
REAL_LAB_VERSION=0.8.1-edge (5f728d4f), pointer 0.8.1-edge, no 0.8.2 staged
REAL_LAB_STANDBY=STANDBY_READY — authority standby / online (acks 164 at 09:38 UTC), AUTO_FAILOVER false, problems [], Cloud sales 5 / appliance sales 6 / outbox 5 acknowledged
REAL_LAB_MUTATED=NO — nothing installed, stopped, restarted or reconfigured; no Local Mode, takeover, handback, WAN test, admin, PIN or Cloud change
```

Observation that supports the 0.8.2 install decision: the 0.8.1 supervisors on the real LAB kept producing F-FB5-1 false restarts under this laptop's background
load while the agents and suites ran — backend #1 restarts 34 → 76 and #2 29 → 73 between 29 Oct 16:40 and 30 Oct 09:38 UTC (all "hung: 3 liveness probes failed";
the children always serving; STANDBY_READY and the data untouched). 0.8.2 removes exactly that behaviour (§2).

## 7. Parallel workstreams (owner Phase 3/4, 30 Oct) — scopes and resource isolation

Three agents were started at 08:25 UTC on 30 Oct while the 0.8.2 gate ran, each with a brief that carries the owner's permanent rules (no real LAB, no production,
no secrets printed, no name/wildcard kills, recorded pids only, release the clone lease) and a private set of resources:

| stream | scope (planning + DISPOSABLE validation only) | worktree / code | databases | ports | dirs / evidence | deliverable |
|---|---|---|---|---|---|---|
| AGENT A — LAB acceptance | browsable LAB Cloud (corrected plan: CENTRAL_DOMAIN stays 127.0.0.1, TENANT_BASE_DOMAIN=localhost, file sessions) proven on a disposable clone; disposable admin + manager-PIN mechanism proven on the clone (five permissions reviewed one by one, Create-/Disable-LabAdmin.php mirroring the approved approver script); second-laptop difference documented; real-LAB procedure written, NOT executed | reads D:\…\pos-saas-edge (frozen); new scripts under BingooEdgeLab\scripts\ | `pos_devonline_*` (the disposable clone) only | **9705** (new disposable Cloud, loopback) | BingooEdgeLab\evidence\agent-a\, docs/status/edge-agent-a-lab-acceptance-prep.md | LAB_CLOUD_DISPOSABLE_CONFIG_TEST, LAB_ADMIN_PROVISIONING_REVIEW |
| AGENT B — canonical integration | pin CANONICAL_BASE 59787ff6 and the canonical HEAD at pin time; delta inventory; conflict/dependency matrix; migration classification; shared-POS/authority/financial risk list; reviewed chunks applied ONLY in an isolated rehearsal worktree; SQLite Feature suites after the gate; MySQL suite recorded as pending; promotion procedure written, NOT executed | **D:\laragon2\www\pos-saas-integ-082** (new worktree, branch integ/canonical-rehearsal-082 from a6113772); D:\…\pos-saas READ-ONLY | none (SQLite Feature tests only; no MySQL DB) | none | docs/status/edge-agent-b-canonical-rehearsal.md | CANONICAL_* fields, CONFLICT_MATRIX, INTEGRATION_READINESS |
| AGENT C — printer + service reliability | Scheduled-Task supervision design (+ Register-EdgeTasks.ps1 print-only dry run), reboot recovery sequence, service ownership / restart-isolation rules (+ Get-EdgeProcessTree.ps1 scoped to an install root), diagnostics bundle script, print-worker failure/retry behaviour observed against a dead printer address on the copy, physical-printer certification plan, P6 readiness checklist | reads D:\…\pos-saas-edge (frozen); own appliance copy **BingooEdgeLab\labcopy-c\** | `bingoo_edge_agentc_local` (copy of the 0.8.1 rehearsal dump) | **8390/8391/8743/8381** | BingooEdgeLab\evidence\agent-c\, docs/status/edge-agent-c-service-reliability.md | AGENT_C report, P6 checklist |
| this session — release closure | 0.8.2 gate → build/sign → audit → empty plan → rehearsal 0.8.1→0.8.2→0.8.1 → readiness report | D:\…\pos-saas-edge (a6113772) | `pos_test_*edgewt*` (gate), `bingoo_edge_dryrun_lab_081` (rehearsal copy) | 8290/8291 (labcopy-081), 8190/8191/8281 (Windows harness, now stopped) | evidence\release-0.8.2-edge\ | this record |

```
RESOURCE_ISOLATION_PLAN=separate worktree for the only code-mutating stream (B); disposable DB per stream (A: pos_devonline_*, C: bingoo_edge_agentc_local, me: pos_test_* + dryrun_lab_081); disjoint loopback ports
  (A 9705; C 8390/8391/8743/8381; me 8290/8291; shared/untouchable: LAB 8081/8090/8091/8443/9701/9100, dev 9704/8095/8096); private evidence and log dirs; every spawned pid recorded in a pids.json
  per stream and stopped only by pid; no MySQL suite outside this session (B's MySQL gate deferred); no browser/stress runs before the 0.8.2 gate ends (09:35 UTC); clone lease released after any worker run.
  Serialisation applied where isolation is not provable: the full MySQL gate runs alone; B's MySQL suite is deferred to a later directive.
```
