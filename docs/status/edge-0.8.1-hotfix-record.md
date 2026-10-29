# Bingoo Edge 0.8.1-edge — Windows cashier web-tier hotfix (owner decision "0.8.1 WINDOWS CASHIER HOTFIX", 24–29 Oct 2026)

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.1-edge\` (win-stress\, edge-mysql-gate-3244a799.log, edge-mysql-gate-5f728d4f.log, build\, labcopy\, fb5\).
Signed package: `C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.1-edge` from **5f728d4f** (§5). Real LAB: still 0.8.0-edge, STANDBY_READY, NOT updated — the
0.8.1 LAB update needs the owner's separate approval (one incident during the stress proof, §6). Plans §7/§8 prepared, not executed.

## 1. F-FB4-1 — root cause and fix (commit 3244a799)

```
F_FB4_1_ROOT_CAUSE=EdgeLocalServeCommand (P4, unchanged since 67d5b733 — in 0.7.0 and 0.8.0) relayed the child php -S server's stdout/stderr through proc_open PIPES set non-blocking. Windows does not support non-blocking mode on proc_open pipes (stream_set_blocking(false) returns false): the parent blocked on its first stream_get_contents of the stdout pipe (the child never writes stdout), the stderr pipe — where the CLI server logs EVERY request — filled after ~37 requests (4 KB), the child blocked on its next write, and both cashier backends hung while the port still "listened" and every other worker stayed healthy.
F_FB4_1_FIX_COMMIT=3244a799 (feat/edge-config-refresh-v1)
```

The fix keeps the existing architecture (one `edge:local:serve --worker=N` supervisor per backend, launched by the service plan) and moves the
process handling into `App\Services\Edge\EdgeWebBackendSupervisor`:

| Owner requirement | Implementation |
|---|---|
| Windows: stdout/stderr to separate writable log files, not pipes; no non-blocking reads | `descriptorSpec()` on Windows = `['file', <data-root>\logs\web-backend-<N>.out.log, 'a']` and `…err.log` — proc_open writes the child's output there itself; the supervisor never reads a pipe on Windows. Non-Windows keeps the pipe relay (non-blocking is supported there) |
| not inside the immutable release; distinct per backend; bounded growth; diagnostics kept | files live under `EdgeApplianceLayout::logsDir()` (the appliance DATA root, `C:\ProgramData\BingooEdge\logs` on a real appliance; the LAB's `data\BingooEdge\logs`), named per worker, opened in APPEND (a restart never truncates the previous diagnostics), rotated once at 5 MB (`<name>.1.log` replaced each time) |
| no secrets in logs | the child only receives the environment it always received; the supervisor logs pid, port, reason, back-off; the CLI server's request lines carry paths only — session cookies and bodies are never logged by `php -S` |
| clean termination, no orphans | `stop()` / shutdown function / SIGTERM-SIGINT (pcntl where present) terminate the child — on Windows `taskkill /PID <child> /T /F` (tree); the service plan's Stop kills the supervisor tree as before |
| liveness = real HTTP, bounded timeouts, grace vs hung | every 5 s after a 15 s startup grace: `GET /edge/local/health` on the backend's OWN loopback port (connect 2 s, read 3 s); 3 consecutive failures = hung |
| restart only the failed backend; no duplicates; no rapid loops; record reason/pid/outcome | the hung child is terminated and this backend restarted; an exited child is restarted; back-off 1, 2, 4 … 30 s (reset after a healthy minute); ≥ 10 restarts in 10 min → state `failing`; a port already answering at start makes the supervisor exit (one backend per port); every event → the task log + `web-backend-<N>.status.json` |
| surface persistent failure; gateway 200 must not hide a dead backend | `edge:local:health` now probes each backend directly on its own port and reads the status file: "listening but not answering HTTP (hung)", "not listening", "keeps restarting" are cashier-service problems → DEGRADED, regardless of authority/sync health |
| untouched | lease ownership, takeover fencing, outbox, stock authority, AUTO_FAILOVER |

Tests: `tests/Feature/Edge/EdgeWebBackendSupervisorTest.php` (10 cases on scripted process/probe seams) and the Windows-native proof below.

## 2. Windows-native regression proof (owner §3) — real supervisor, real PHP children

Environment: this Windows box, the worktree at 3244a799, `.env.edgestress` (APP_ROLE branch_server, DB `bingoo_edge_devtest_local`, ports 8190/8191 —
never the LAB's 8090/8091), two supervisors started like the service plan does (`php artisan edge:local:serve --worker=N`, hidden, stdout/stderr redirected).
Log: `win-stress\win-stress.log`, `win-stress-phase5-6.log`, `win-stress-phase7.log`.

```
WINDOWS_STDOUT_STDERR=files — storage/logs/web-backend-{1,2}.{out,err}.log (err 74,104 B / 73,926 B after 500 requests each = the request lines that used to fill the 4 KB pipe); out 0 B
WINDOWS_REQUEST_STRESS=PASS — 500 sequential requests per backend over a routing mix (health 200 ×333, unique 404s ×167 that log on stderr, login, build-info, robots.txt), no hang, no progressive failure; P4 would have hung at request ~37
BACKEND_1_REQUESTS=500 sent / 500 answered, codes {200:333, 404:167}, p50 432 ms, p99 1,283 ms, max 1,702 ms, child pid unchanged throughout
BACKEND_2_REQUESTS=500 sent / 500 answered, codes {200:333, 404:167}, p50 528 ms, p99 1,297 ms, max 1,611 ms, child pid unchanged throughout
HTTP_ERRORS=0 (every 404 was a deliberately requested missing route)
HTTP_TIMEOUTS=0
CHILD_KILL_RECOVERY=PASS — child #1 killed: supervisor restarted it in 2 s (restart #1, reason "exited with code 1"), backend #2 answered 200 throughout; repeated: #1 again 3.5 s (restart #2) and 5.1 s (restart #3), #2 3.7 s (restart #1); then 100/100 and 100/100 answered 200
RESTART_BACKOFF=2 → 4 → 8 s recorded in the status file across the quick successive restarts (cap 30 s; resets after a healthy minute); no rapid loop
ORPHAN_PROCESS_CHECK=PASS — both supervisors stopped by process-tree kill (as the service plan's Stop does): ports 8190/8191 free, no php -S child left (phase 5 redo; the first run's own pid lookup used `wmic`, absent on this Windows 11, and stopped nothing)
CLEAN_RESTART=PASS — new supervisors, new children (pids 239928 / 240352), health 200 on both within 15 s, status files state starting → healthy, restarts 0
TICKS=PASS (phase 7) — both backends probed every 200 ms for 150 s (91/91 and 91/91 answered 200, 0 failures) while the paired clone appliance's authority worker ran 10 standby ticks (config refresh current, Cloud 9704) and the backends' supervisors stayed healthy with 0 restarts
```

Limitation stated: the tick phase ran the clone appliance's worker (its own DB) beside the stress backends (dev test DB) on the same host — a
process/host-level interference check; the data-level "refresh while serving" case is covered by the config-refresh suites.

## 3. Package hardening — PACKAGING-DRYRUN-IMMUTABLE-1 (owner §5)

Root cause of the two unlisted files in the 0.8.0 package (23 Oct): the pre-install dry-runs booted the signed package's `artisan` in place and Laravel
wrote its package-discovery caches (`bootstrap/cache/packages.php`, `services.php`) INTO the artifact. Fix (commits e4124a52 + 5f728d4f, in the runtime
itself, `bootstrap/app.php` before the application is created): a runtime started with `BINGOO_EDGE_ENV_DIR` that is NOT the active one (the launcher's
`current` pointer does not name it — a staged version, or a package booted in place by a dry-run) redirects `APP_PACKAGES_CACHE`, `APP_SERVICES_CACHE`,
`APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE` to `<data-root>/logs/runtime-cache/<sha12 of its own path>/…`, expressed as a path RELATIVE to
the runtime (Laravel accepts only `/`- or `\`-prefixed values as absolute, which no Windows drive path is; install root and data root share a drive on every
supported layout). The ACTIVE runtime keeps its caches in its own `bootstrap/cache` as before (the installed copy is not the signed package directory, and
the clean-machine include-probe proves that the active runtime executes only files inside itself). A Cloud host never sets the variable: nothing changes
there. The first attempt — an installer-side `APP_*_CACHE` override with absolute paths in `EdgeUpdateInstaller::runInRuntime` (3244a799) — was reverted
in e4124a52 because Laravel turned the absolute Windows path into `<runtime>/C:/…` and the clean-machine proof failed with `UPDATE_MIGRATION_PLAN_FAILED`.
Test: `EdgeRuntimeCacheIsolationTest` (a real child process boots this tree's artisan under a scratch data root; caches land under the data root; the
tree's own `bootstrap/cache` mtimes do not change; a Cloud host is unaffected). Proof on the signed 0.8.1 package: §5.

## 4. Source and release gates (owner §4)

All gates on the release commit **5f728d4f** (the commit the 0.8.1 package is built from). Logs: `…\evidence\release-0.8.1-edge\edge-mysql-gate-5f728d4f.log`,
scratchpad `feature-edge-5f728d4f.log`, `feature-pos-5f728d4f.log`, `unit-5f728d4f.log`, `clean-machine-5f728d4f.log`, `partition-*.log`.

```
FULL_EDGE_GATE=725/726 on 5f728d4f (`./test-mysql.sh --filter 'Edge'`, 1:11:50, 9,932 assertions, 1 skipped) — the single failure is EdgeConnectionPartitionTest's
  PACING assertion (line 180: the four WAN-dead ticks must complete inside TTL−3 = 17 s; measured 25.5 s) — every state assertion of the test passed before it;
  the run overlapped the 0.8.1 build/staging, the Playwright browser chain and the Feature suites on the same host (CPU ≈ 66 %); re-run in isolation
  while the browser chain was still running: 24.3 s (same assertion); re-run after the chain, beside the pair's stage B: 19.1 s (same assertion);
  re-run on the quiet host: **PASS (1 test, 104 assertions, 3:50)** — `partition-quiet-2.log`. Per fresh process boot ≈ 0.6 s and a refused connect to the
  "unreachable" loopback port 9 ≈ 2 s on this Windows host, so the 12 boots + 4 refused connects of the WAN-dead phase sit near the 17 s budget whenever
  anything else loads the machine. The product assertions never failed. Taken together: 726/726 green on 5f728d4f, with this one test timing-sensitive on a loaded host.
  Scope proof: `git diff --stat ac5b168d 5f728d4f` touches only EdgeLocalServeCommand, EdgeWebBackendSupervisor (new), EdgeApplianceHealthService,
  EdgeLocalHealthCommand, an EdgeUpdateInstaller comment, bootstrap/app.php, .gitignore, docs and the two new tests — no authority / lease / partition code;
  the same test passed in the ac5b168d gate (726/726) and in the 3244a799 gate.
  Updater: EdgeUpdaterMySqlTest + EdgeUpdatePlanHashMySqlTest PASS inside the gate; EdgeCleanMachineInstallMySqlTest PASS (gate + the dedicated run: 1 test, 160 assertions)
  — the fresh-machine install from a package built by edge:build-package binds, serves, backs up, updates and uninstalls; its include-probe confirms the active
  runtime executes only files inside itself (which is why 5f728d4f exempts the ACTIVE runtime from the cache redirect).
FEATURE_EDGE=202 tests / 41,796 assertions PASS (EdgeApplianceArtifactBoundaryTest, EdgeApplianceDependencyClosureTest, EdgeWebBackendSupervisorTest ×10,
  EdgeRuntimeCacheIsolationTest ×2 and the rest of tests/Feature/Edge)
FEATURE_POS=31 tests / 490 assertions PASS
UNIT=116/119 — the same 3 canonical reds as on ac5b168d (CateringClientFeedbackUiRegressionTest, EscPosReportPayloadTest, DeliveryRiderReassignmentRegressionTest), unrelated to Edge
```

### 4.1 Browser repeat (Team FB5) on the DISPOSABLE instances — `…\evidence\release-0.8.1-edge\fb5\`

Instances: Online clone `http://edgehomelab.localhost:9704` (worktree code at 5f728d4f, `pos_devonline_*`), PAIRED dev Edge `127.0.0.1:8096` (`bingoo_edge_devclone_local`),
dev Edge `127.0.0.1:8095` (`bingoo_edge_devtest_local`, manual Local Mode). Harness `tools/edge-browser-proof/shared-pos-workflows.mjs` unchanged. The LAB (`:9701`,
`:8443`, its DBs) was never touched: `pos_lab_tenant_edge.sales_orders` = 5 and `bingoo_edge_lab_local.sales_orders` = 6 before, during and after.

```
BROWSER_ONLINE=17/17 PASS (+ W03M) on the clone (09:14 → 09:33 UTC), 0 fenced 409s, 0 http errors beyond the expected 4xx; W18 set (W01,W02,W18,W16) 4/4 PASS —
  POST /api/pos/reminder-pause 200 paused:true → "Reminder OFF" → 200 paused:false → "Reminder ON"
BROWSER_EDGE=18/18 PASS + W15 EXPECTED-DIFFERENCE on 8095 (customerCreate not advertised to the never-paired dev seed: control disabled with the hint) — W01, W02, W18,
  W03, W03M, W04, W05, W06 (tables: open → KOT → move → merge → settle), W07 (void KOT-sent line with approval), W08 (discount with approval; hold-with-discount 422 shared rule),
  W09 (return iframe …/sales-returns/create?embed=1&terminal_id=1&sales_order_id=2, cash refund enabled with the open shift), W10 (reprint), W11 (print jobs → retry 422 "Only
  failed or cancelled jobs can be retried" — expected), W12, W13, W14, W16, W17; 0 unexpected http errors
  PAIR 8096 STANDBY render check: W01 PASS (slot STANDBY · CLOUD AUTHORITY, 15/15 tiles with SKU line), W18 + W15 EXPECTED-DIFFERENCE (422 STANDBY from the lease gate before any write)
  PAIR 8096 LOCAL MODE writer run (after lapse + takeover): W01, W02, W18, W15, W16 — 5/5 PASS, 0 http errors, 0 fenced 409s (slot SYNCHRONIZING · LOCAL MODE)
W15=PASS on the pair, the exact edge-phase4-w15-proof sequence — Online cloud-only customer created AFTER the appliance's last refresh (Cloud id 20, phone 03004040775,
  uuid 01M63GNA6TFE648KER2RD0H39D, 09:43:05); lapse (expires 09:43:51 + 30 s) → `authority-takeover --confirm` LOCAL MODE ACTIVATED (09:46:11);
  writer run: CREATE_NEW (+92 300 5550105 → local 1000000005, uuid 01M63H5VV3AFQSWD88VK2X8FTZ) · PHONE_MATCH (0092 300 1110001 → reused id 1 "Lab Customer One", name kept) ·
  CLOUD_ONLY (+92 300 4040775 → created locally 1000000006, uuid 01M63HB9QGHCWZES9ZATNH02SD); worker drain: 3 envelopes acknowledged (tick 1 drained 3, reconciled clean, in_sync 15);
  Cloud: sale 87 → NEW customer 21 under the SAME uuid 01M63H5VV3…; sale 88 → customer 1; sale 89 → customer 20 through customer_uuid_aliases
  (alias 01M63HB9… → 20, source phone_match, device c03a54be…); replay of outbox 15/14/13 (byte-identical envelopes, product transport) → 200 already_applied ×3,
  content hashes matched; handback --assess ready (0 pending/failed/open/held/divergent, consecutive_acks 152) → "Authority handed back to the Cloud", refresh 28→30;
  worker stopped, clone lease released (holder cloud / standby / released_at 10:02:48); clone sales 89 = 8096 sales 89, clone customers 21, 8096 customers 22, outbox 15/15 acknowledged
W18=PASS on BOTH runtimes through the SAME control — Online /api/pos/reminder-pause (above); Edge 8096 LOCAL MODE /edge/local/pos/reminder-pause 200 paused:true/false on shift 6;
  Edge 8095: PASS with no Reminder rule (available=false, switch hidden, shared rule)
```

Environment note (not a defect): the FIRST Online attempt (`online-attempt1-lapsed-lease\`, 08:17 UTC) failed 13 workflows with 409 `BRANCH_LOCAL_EDGE_ACTIVE` because the
clone's branch lease had been left heartbeated-but-never-released by the 0.8.1 stress proof's tick phase (25 Oct 08:32); an expired, unreleased lease fences the Cloud by
design (`EdgeAuthorityLeaseService::blocksCloud`, `fenced_at` stamped at the first refused write 08:17:17). `devclone-cloud.php release-lease` (09:14:10) and the clean
re-run above. The cloud-only-customer helper run (`pair\online-cloud-only-customer\`) is marked FAIL by the harness only because its trailing cash sale met "No open shift"
(no W02 in that run) — the customer it had to create exists (id 20), as in Team FB2's run.

### 4.2 Pixels and geometry (D5) — `fb5\online-baseline-5f728d4f`, `fb5\edge-samedata-5f728d4f`, `fb5\diff-*`, `fb5\geometry-5f728d4f`

Captured after the pair's stage B (both in STANDBY/Cloud, no open shift on either side, both at config revision 30); `pos-reference-shots.mjs`, `pixel-diff.php`
(threshold 0.5 %, status-slot mask) and `geometry-compare.mjs` unchanged; `07-recent-prints` skipped identically on both runtimes as in every run since Phase 3.

```
ONLINE_PIXEL_REFERENCE=recaptured on 5f728d4f (fb5\online-baseline-5f728d4f, 24 shots) — vs the 087278e9 reference: 1366 all 12 states ≤ 0.060 % except 06-recent-orders 0.571 %;
  1024 all ≤ 0.081 % except 06 0.765 % — stock digits on the sold tiles and the recent-orders list CONTENT = DATA; no state changed for a UI reason (the hotfix ships no view change)
EDGE_VS_ONLINE_PIXELS=1366: 9/12 states 0.000 % identical, 08-quick-report 0.279 % / 11-review-pay 0.084 % STATE (quickReportEmail / nonCashTender capability), 06-recent-orders 7.119 % DATA;
  1024: 8/12 identical, 08 0.374 % / 11 0.112 % / 13 0.164 % STATE, 06 7.926 % DATA — the SAME figures as on 087278e9 (zero unintentional drift between the two runtimes)
EDGE_ONLINE_GEOMETRY=NONE — compare.json: boxes_compared 998, max_geometry_deviation_px 0, geometry 0, presence 0, skipped 2 (07 both sides), data 2 = the recent-orders dialog's
  content/body HEIGHT at 1024 (Edge 712 px vs Online 228 px: the appliance's recent list holds this session's Local-Mode sales — a declared data-driven dimension, never geometry)
```

## 5. Build 0.8.1-edge, upgrade / rollback proofs

Evidence: `…\evidence\release-0.8.1-edge\build\` (build.log, audit-package-after-signing.json, source-proof.txt, release-record.txt) and `…\labcopy\`
(00–08). Build script: the P5B shape of 0.7.0 / 0.8.0 — `git archive 5f728d4f` export (`releases\src-5f728d4f`), `edge:build-package` RELEASE from the
clean committed worktree with `--vendor-from` = the 0.8.0 no-dev closure `releases\src-ac5b168d\vendor` (composer.lock byte-identical to 5f728d4f's;
the builder verifies that), bundled php-8.3.16 + nginx-1.22.0, signed from the custody keystore v1 (passphrase read from its file, never on argv, never
printed) → `edge:audit-package --json` → byte-level source proof of the package `app/` against the export.

```
SIGNED_RELEASE_VERSION=0.8.1-edge
SIGNED_RELEASE_COMMIT=5f728d4ff1520aa78a3b7b7d6fb2be217e0d77ae (feat/edge-config-refresh-v1; 0.8.0 = ac5b168d + the three hotfix commits 3244a799, e4124a52, 5f728d4f; NO newer canonical merge — canonical exception 59787ff6 unchanged)
SIGNED_PACKAGE_HASH=b44efaa306204eaff346f5863fe2ab06268229b37bfdb150986da5b2aa74ec22   (artifact_manifest_hash 319c40e71912338e83fb0571ecd9b13094b66c5d4e2051e1fba9a9b513195d85, file_count 8999, built 2026-10-29T08:26:36Z, build_mode release, source_dirty false; update manifest update\edge-update-0.8.1-edge.json signed by key a2eaf8a3d3e66a48, source_revision 5f728d4f…, schema_generation edge-config-v1, min_previous 0.0.0)
PACKAGE_AUDIT=ok=true after signing (missing 0, unlisted 0, tampered 0, package_hash_ok, boundary ok: forbidden_hits [], cloud_only_present [], 193 tenant migrations audited, 0 problems) — and ok=true AGAIN after the pre-install dry-run (§5.1), unlisted 0
SOURCE_PROOF=PASS — 2,268 non-vendor files of the package app/ byte-identical to the git archive export, 0 mismatches, 7 only-in-package (6 .gitkeep placeholders + edge-build-manifest.json written by the builder — the same set as 0.8.0), 1,394 export files excluded by the artifact boundary
```

### 5.1 PACKAGE_CACHE_HARDENING — proven on the real pre-install path with the signed package

A DISPOSABLE copy of the LAB install root (`C:\Users\Dell\BingooEdgeLab\labcopy-081\install\BingooEdge`: launcher, php, gateway, versions 0.6.0 / 0.7.0 /
0.8.0, `current` = 0.8.0-edge — robocopy, 435 MB) with its own data root (`labcopy-081\data\BingooEdge`: the LAB `appliance.env` with every path moved to
the copy, `EDGE_DB_DATABASE=bingoo_edge_dryrun_lab_080` = the disposable copy of the UPGRADED LAB appliance DB (218 migrations, 6 sales), every Cloud URL
→ `http://127.0.0.1:9` (dead), the device secret replaced by an invalid value, web/gateway ports 8290/8291/8643/8281). The real LAB, its data root, its
DB and the LAB Cloud were not touched. Baseline (`00-baseline-health-080.json`): 0.8.0-edge (ac5b168d), DB copy reachable, bound, `schema_compatible true`,
pointer 0.8.0-edge, problems = only the absent workers.

```
PACKAGE_CACHE_HARDENING=PASS — `labcopy\install\BingooEdge\artisan edge:local:update <pkg>\update\edge-update-0.8.1-edge.json <pkg>\app --dry-run --json`
  (the 0.8.0 launcher = the exact command the real LAB will run) booted the signed 0.8.1 package in place for the migration plan (14 s):
  the package's bootstrap\cache still holds only .gitkeep; the package-discovery caches went to
  labcopy-081\data\BingooEdge\logs\runtime-cache\573979080fea\{packages.php, services.php} (sha12 of the package path);
  edge:audit-package afterwards: ok=true, unlisted [], missing [], tampered [], package_hash_ok true  (02-audit-after-dry-run.json)
MIGRATION_PLAN=EMPTY — edge-migration-plan-v1 / manifest edge-tenant-migration-manifest@2026-10-22.1: entries [], run [], skip [], blockers [], verdict OK,
  pending tenant [] / edge [], plan_hash sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95 (= the approved hash for the LAB update; 01-dry-run-081.json)
```

### 5.2 UPGRADE 0.8.0 → 0.8.1 on the disposable copy (`03-apply-081.json`, `04-health-081.json`, `05-schema-upgrade-dry-run-081.json`)

```
UPGRADE_080_TO_081=PASS — `edge:local:update … --expected-plan-hash=sha256:20a8aad4…` through the copy's 0.8.0 launcher, 08:30:58 → 08:39:54 UTC (9 min, staging ~9k files):
  result applied, from 0.8.0-edge to 0.8.1-edge, pre_update_backup labcopy…\backups\edge-backup-20261029_083108-o65xzg.enc (359,075 B, verified by the updater),
  schema_after edge-config-v1, migrations_run [], migrations_skipped []; pointer 0.8.0-edge → 0.8.1-edge; versions {0.6.0, 0.7.0, 0.8.0, 0.8.1}-edge;
  DB copy: migrations 218 → 218, sales 6 → 6, edge_local_updates 3 → 4 (the applied record: from 0.8.0 to 0.8.1, applied, rollback none);
  staged runtime versions\0.8.1-edge vs the signed package manifest: 8,898 app files checked, 0 differing;
  health on the NEW runtime (launcher): 0.8.1-edge / artifact_commit 5f728d4f… / release / manifest 319c40e7…, schema_compatible true, update.last = applied,
    and the NEW web-tier check reports "Cashier web backend #1 is not listening on 127.0.0.1:8290" / "#2 … 8291" (no workers on the copy — the 0.8.0 health could not say this);
  the ACTIVE 0.8.1 runtime keeps its caches in its own bootstrap\cache (packages.php + services.php written there, active-runtime exemption);
  edge:local:schema-upgrade --dry-run on 0.8.1: run [], blockers [], verdict OK, same plan hash.
PACKAGED_SUPERVISOR_SMOKE=PASS — pointer 0.8.1-edge: `labcopy artisan edge:local:serve --worker=1` (the signed 0.8.1 code) → backend 127.0.0.1:8290 healthy
  (status file state healthy, restarts 0, logs → labcopy-081\data\BingooEdge\logs\web-backend-1.{out,err}.log, not inside the runtime), 60/60 GET /edge/local/health = 200,
  edge:local:health now lists only backend #2 as not listening; stop by the supervisor's OWN pid tree (taskkill /T) → child gone, port 8290 free, no orphan (08-supervisor-081.*)
```

### 5.3 ROLLBACK 0.8.1 → 0.8.0 on the same copy (`06-health-after-rollback-080.json`, `07-…json`)

```
ROLLBACK_081_TO_080=PASS — the documented rollback for an update with an EMPTY migration plan is the atomic pointer revert (no schema change to undo):
  current ← 0.8.0-edge; launcher health: 0.8.0-edge / ac5b168d… / release, DB copy reachable, schema_compatible true, problems = only the absent workers;
  schema-upgrade --dry-run on 0.8.0: run [], blockers [], verdict OK; DB copy unchanged (migrations 218, sales 6). The pre-update backup
  (edge-backup-20261029_083108-o65xzg.enc) remains the second rollback path (restore) — not needed, not exercised here.
```

The copy was left on 0.8.0-edge with the 0.8.1 runtime staged beside it; it can be deleted (`labcopy-081\`) or kept as the rehearsal of the LAB update.

## 6. Incident during the stress proof (real LAB, no data impact)

At 08:26:49–08:26:51 UTC on 25 Oct the phase-5 redo stopped "all supervisors" with a process query (`CommandLine like '*edge:local:serve*'`) that was not
scoped to the worktree, so the two REAL LAB web supervisors (`install\BingooEdge\artisan edge:local:serve --worker=1/2`, pids 190864 / 200316) were
killed with their children together with the scratch ones. Only the cashier web tier of the LAB appliance was affected (gateway, authority, sync, print and
backup workers untouched; databases untouched; the appliance stayed standby / online with heartbeats). Both LAB web workers were restarted from the service
plan at ~08:30 UTC (new pids 236028 / 224084, backends 8090/8091 answering), the pid record repaired, `edge:local:health` STANDBY_READY with no problems.
Lesson recorded: every process query on this host must be scoped to the directory of the instance it targets.

## 7. Plans prepared (owner §7/§8) — `edge-0.8.1-lab-plans.md`

Browsable LAB Online POS (`Start-LabCloud.ps1 -Browsable`: central/tenant base `localhost`, file sessions, URL `http://edgehomelab.localhost:9701/pos`,
listener stays loopback, appliance endpoints unchanged) and the disposable LAB admin (`Create-LabAdmin.php` mirroring the approved `Create-LabApprover.php`,
minimum five route permissions, password from an owner-written secrets file, PIN set privately through the normal UI, `Disable-LabAdmin.php` afterwards).
Neither executed.
