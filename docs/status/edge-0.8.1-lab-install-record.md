# Bingoo Edge 0.8.1-edge — CONTROLLED LAB INSTALLATION record (owner authorization "CONTROLLED 0.8.1-EDGE LAB INSTALLATION", 29 Oct 2026)

Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.1-edge\lab-install\` (01–10). Package: `C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.1-edge`
(unchanged since the build of 29 Oct 08:26 UTC; not rebuilt, not re-signed). Hotfix content and proofs: `edge-0.8.1-hotfix-record.md`. Scope: appliance-only hotfix
install + standby-only checks. Not done, by the owner's boundaries: Local Mode, takeover, handback, Cloud redeploy/migration, admin account, manager PIN, WAN tests, P6.

## 1. Package identity (before any mutation) — `01-package-identity.json`, `01-audit-package.json`

Verified with the product's own crypto (`EdgeEnrollmentCrypto::verifySignature`) and manifest-hash recomputation (`EdgeUpdatePackageService::recomputeManifestHash`):

| check | result |
|---|---|
| signature of `update\edge-update-0.8.1-edge.json` against the release public key `8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ=` | valid; a freshly minted foreign key refuses it |
| key id (sha256 of the public key, first 16 hex = `EdgeSigningKeyStore::keyId`) | `a2eaf8a3d3e66a48` = pinned |
| payload | version `0.8.1-edge`, `source_revision 5f728d4ff1520aa78a3b7b7d6fb2be217e0d77ae`, `artifact_manifest_hash 319c40e7…95d85`, target `branch_server`, schema `edge-config-v1`, min_previous `0.0.0` |
| package-manifest.json | version 0.8.1-edge, git_commit 5f728d4f…, build_mode release, source_dirty false, `package_hash b44efaa306204eaff346f5863fe2ab06268229b37bfdb150986da5b2aa74ec22`, file_count 8999 |
| artifact hash recomputed from the files on disk | `319c40e7…95d85` = the signed payload |
| `edge:audit-package` | ok=true: missing 0, unlisted 0, tampered 0, package_hash_ok, boundary ok (forbidden_hits [], cloud_only_present [], 193 tenant migrations audited, 0 problems) |

IDENTITY=MATCH (version, commit, package hash, key id all equal the pinned values).

## 2. Process-isolation preflight — `02-process-preflight.txt`

Live tree before the update (CIM query by COMMAND LINE containing the real install root `C:\Users\Dell\BingooEdgeLab\install\BingooEdge` or the disposable
copy `…\labcopy-081`): **REAL_LAB 10 processes, DISPOSABLE_LABCOPY 0**. The service plan's pid record (`data\lab-workers.json`) matched the live tree
exactly — all 7 recorded pids alive and owned by the real install root: Gateway 200428 (nginx master; worker child 194284 listening 0.0.0.0:8443/8081),
Web1 236028 (child php -S 238316 on 127.0.0.1:8090), Web2 224084 (child 240992 on 8091), PrintWorker 201168, SyncSender 184524 (powershell Run-Periodic
loop), AuthorityWorker 199896, Backup 201024 (loop); the other children are conhost. Approved stop/start = `scripts\Start-EdgeLabWorkers.ps1 -Action Stop|Start`:
Stop sends `nginx -s quit` by the plan's executable path, then `taskkill /PID <recorded pid> /T /F` for each RECORDED pid (never a name or wildcard), and
removes the record; Start launches each plan entry hidden and records the new pids. No disposable stress or kill test ran during this directive.

## 3. Baseline — `03-baseline-health.json`, `03-baseline-snapshot.txt`

```
APPLIANCE_VERSION=0.8.0-edge (ac5b168d, release)   APPLIANCE_BOOTSTRAP=edge-bootstrap-v8   AUTHORITY=STANDBY_READY (standby / online, consecutive_acks 118)
CLOUD_SALES=5   APPLIANCE_SALES=6 (lines 25)   OUTBOX: total 5, acknowledged 5, pending 0, leased 0, failed 0 (state: acknowledged ×5)
MIGRATIONS=218 (appliance)   Cloud tenant 217 / master 49   AUTO_FAILOVER_ENABLED=false
Cloud: /up 200; lease holder cloud / standby / heartbeat_seq 47816 / last 12:55:09 / expires 12:57:09 / fenced_at NULL / released_at NULL
customers 2 / aliases 0 (both sides), open shifts 0 (both), stock movements 17, workers 7/7, backends 8090/8091 listening, gateway 8443 ok, problems []
rowset MD5s (rows ordered by id): appliance sales_orders 9bbd2ad24c0884518c745ebc9bb97d39 · sales_order_lines cc04a3e402c28b504cba71e1f08e4076 ·
  outbox c9c08b1d74a7c806fa78f79d608a23e2 · customers e42077b9b220fdf9d3650c4203b9f7ff · Cloud sales_orders 0dac7dbb85d26e0f71db67519cbe251c ·
  Cloud sales_order_lines 49bb4ea13933207a1fcaedda8d43361e · Cloud customers e39a8d18f40625ad27b327053bef5288
CHECKSUM TABLE EXTENDED: appliance sales_orders 839775129, lines 1464477681, outbox 3133542103, customers 1186056055, edge_local_meta 3512719334;
  Cloud sales_orders 2750908413, lines 3532618865, customers 1793700069, edge_inbound_sale_ingestions 2861132549
```

## 4. Pre-update backup — `data\backups-pre-0.8.1-20261029T130008Z\`

`db\`: mysqldump `--single-transaction --routines --triggers --hex-blob` of `bingoo_edge_lab_local` (614,217 B), `pos_lab_tenant_edge` (431,020 B),
`pos_lab_master_edge` (132,717 B), all with empty .err. `state\`: `current` (0.8.0-edge), `appliance.json`, `service-plan.json`, `lab-workers.json`, `lab.env`,
`config\appliance.env`, `gateway-dir\`. Restore-verified: the appliance dump restored into the scratch DB `bingoo_edge_lab_verify_pre081` → 218 migrations,
6 sales, rowset MD5s identical to the baseline. The earlier sets (`backups-pre-0.8.0-20261023T125858Z`, sealed #365, the hourly `edge-backup-*.enc`) are untouched.
Recovery commands: `mysql bingoo_edge_lab_local < db\bingoo_edge_lab_local.sql` (after stopping the workers), `copy state\current install\BingooEdge\runtime\current`,
`copy state\config\appliance.env data\BingooEdge\config\`, then `Start-EdgeLabWorkers.ps1 -Action Start`. The Cloud was not migrated or redeployed.

## 5. Upgrade — `05a-dry-run.json`, `05b-audit-after-dry-run.json`, `05c-stop-workers.txt`, `05d-apply.json`

Pre-apply checks on the INSTALLED 0.8.0 launcher (ac5b168d): `edge:local:update --help` lists `--dry-run`, `--json`, `--expected-plan-hash` (no flag invented);
its `EdgeUpdateInstaller` carries EDGE-UPDATE-SCHEMA-IN-NEW-RUNTIME-1 / `prepareStagedRuntime` (the schema step runs in the NEW runtime's process — the 0.7.0
old-runtime problem is not reintroduced); recovery = §4 backups + the documented pointer revert.

| step (UTC) | result |
|---|---|
| 13:03:21–13:04:12 dry-run through the REAL launcher (`install\BingooEdge\artisan edge:local:update <pkg>\update\edge-update-0.8.1-edge.json <pkg>\app --dry-run --json`) | `result dry_run`, from 0.8.0-edge to 0.8.1-edge, schema_before edge-config-v1, plan entries [] / run [] / skip [] / blockers [], verdict OK, **plan_hash sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95 = approved** |
| package after the dry-run | `app\bootstrap\cache` still only `.gitkeep`; the booted package wrote its caches to `data\BingooEdge\logs\runtime-cache\573979080fea\` (PACKAGING-DRYRUN-IMMUTABLE-1 working on the real path); `edge:audit-package` ok=true, unlisted 0, missing 0, tampered 0 |
| 13:05:36 stop (`Start-EdgeLabWorkers.ps1 -Action Stop`) | nginx quit; Web1 236028, Web2 224084, PrintWorker 201168, SyncSender 184524, AuthorityWorker 199896, Backup 201024 stopped by recorded pid (+ tree); remaining install-root processes 0; ports 8443/8081/8090/8091 closed; pid record removed; the 9 other php processes on the host (LAB Cloud 9701, dev clones, fake printer) untouched |
| 13:07:35–13:20:33 apply (`… --expected-plan-hash=sha256:20a8aad4… --json`) | **`result applied`**, from 0.8.0-edge to 0.8.1-edge, active_version 0.8.1-edge, installer's sealed pre-update backup `data\BingooEdge\backups\edge-backup-20261029_130821-dvltpo.enc`, schema_after edge-config-v1, migrations_run [], migrations_skipped []; 13 min (staging ≈ 9k files) |
| after apply | `runtime\current` = **0.8.1-edge**; versions 0.6.0 / 0.7.0 / 0.8.0 / 0.8.1; `edge_local_updates` #4 = 0.8.0-edge → 0.8.1-edge, applied, failure_code NULL, rollback none, 13:20:32; **migrations 218 → 218**, sales 6; staged `versions\0.8.1-edge` vs the signed manifest: 8,898 app files, 0 differing; the ACTIVE 0.8.1 runtime keeps its own bootstrap caches (packages.php, services.php in its bootstrap\cache); the signed package stays pristine. `appliance.json` still says `edge_app_version 0.8.0-edge` — the updater does not rewrite that informational field (the launcher reads only the paths from it; health reports the real version from the runtime); left as is, not silently edited |
| 13:21 health on the new runtime before workers | 0.8.1-edge (5f728d4f, release, manifest 319c40e7…), bootstrap v8, `schema_compatible true`, pointer 0.8.1-edge, `update.last applied`; problems = only the stopped workers/backends (the new web-tier check names them) |
| 13:21:15 start (`-Action Start`) | 7/7 started and recorded: Gateway 400800, Web1 414556, Web2 364668, PrintWorker 412556, SyncSender 405264, AuthorityWorker 366572, Backup 363064 |
| after start | gateway 0.0.0.0:8443 listening (TLS, `edge/local/health` 200 via `https://127.0.0.1:8443` and via the LAN name `https://desktop-0024epm.local:8443`, ~0.5 s / ~1.1 s); backends 8090/8091 listening and answering; authority worker heartbeating (Cloud lease holder cloud / standby, seq 47816 → 47854 by 13:33, `fenced_at` NULL again after the stop window, `released_at` NULL); health **STANDBY_READY** at 13:46 (0.8.1-edge, acks 56, problems []) |

```
UPDATER_PLAN_HASH=sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95 (dry-run = apply = approved)
MIGRATIONS_BEFORE_AFTER=218 → 218 (ran 0, skipped 0)
UPGRADE_RESULT=applied (0.8.0-edge → 0.8.1-edge), rollback none, pre-update backup sealed
APPLIANCE_RUNTIME_POINTER=0.8.1-edge
```

## 6. Real Windows cashier acceptance (standby-only HTTP) — `06-*`

Host conditions during this section (material to the result): this LAB laptop is shared; another session's Playwright suite (`D:\laragon2\www\property_handle`,
4 workers + headless Chromium + vite previews, re-spawning repeatedly, e.g. 13:53) plus editors/Slack kept the 12-logical-CPU host at **86–100 %** from ~13:20
to ~14:00 UTC (53 % at 14:04). The 25 Oct Windows-native stress proof ran on the same laptop without that load (p99 1.3 s).

### 6.1 What happened on the web tier (`05f/05g`, `06-*`, `worker-logs\BingooEdgeWeb{1,2}.out.log`, `data\BingooEdge\logs\web-backend-*`)

The new supervisors started both backends at 13:21:15 and **restarted them repeatedly**: backend #1 15 times and #2 13 times between 13:21:46 and 13:57:39,
every one with reason `hung: 3 liveness probes failed` (the 5 s probe to `GET /edge/local/health`, read timeout 3 s, three misses). The children were never
wedged: their stderr logs (`web-backend-N.err.log`, 60,576 B / 52,537 B, under the DATA root, 0 B inside the runtime) show `Accepted … Closing` request lines
flowing right up to each termination, and a single direct `GET /edge/local/health` answered 200 in 0.7–0.9 s whenever measured. What missed the probe was
latency: php -S serves one request at a time, so while a cashier page render (e.g. `/edge/local/login` 1–10.6 s on this loaded host; `/pos` ≫ that) or a
concurrent `edge:local:health` run (which probes the same endpoint) occupies the backend, the supervisor's 3 s read window expires; three such windows in
15 s → "hung" → terminate → restart in 1–30 s (back-off reset after a healthy minute, so the `failing` state — ≥10 restarts in 10 min — was never reached;
peak `restarts_last_10min` 8). Restart windows: 13:21–13:36 (while my post-start health/status loops and the external load ran), 13:39–13:44 (during the
first 150-request mixed run: health p50 2.1 s, p99 10.0 s, 2 timeouts, 5 connection errors on #1 = the restart moments), 13:56–13:57 (during the §10 W01 page
loads + a concurrent health/snapshot). **Idle, the tier is stable:** 13:46:32–13:48:32 and 14:04:33–14:05:33 observed every 20 s: both `healthy`, 0 probe
failures, pids unchanged; health STANDBY_READY with `responding true` on both.

### 6.2 The modest regression the owner asked for (`06-http-modest-regression.json`, 13:50–13:53, cpu 92 → 86 %)

```
CASHIER_BACKEND_1_HEALTH=70/70 HTTP 200 (60 × /edge/local/health + 10 × /edge/local/login), health p50 764 ms / p95 1,348 ms / max 1,651 ms, login page p50 1,088 ms / max 10,625 ms, 0 timeouts, 0 errors, child pid 190008 unchanged, restarts 13 → 13, state healthy
CASHIER_BACKEND_2_HEALTH=70/70 HTTP 200, health p50 717 ms / p95 1,520 ms / max 2,807 ms, login page p50 789 ms / max 2,018 ms, 0 timeouts, 0 errors, child pid 414628 unchanged, restarts 11 → 11, state healthy
GATEWAY=https://127.0.0.1:8443/edge/local/health 200 (0.5–0.7 s) and https://desktop-0024epm.local:8443/… 200 (0.8–1.2 s) via curl (PowerShell 5.1's own TLS defaults cannot speak to the gateway — a client artefact, 20 × code 0 in the first attempt)
WEB_SUPERVISOR_LIVENESS=status files web-backend-{1,2}.status.json: state healthy, consecutive_probe_failures 0, pid, restarts, last_restart_reason/at, backoff, log paths — read by edge:local:health ("responding", supervisor block) and shown as problems when a backend is listening-but-not-answering
WEB_LOG_LOCATION=C:\Users\Dell\BingooEdgeLab\data\BingooEdge\logs\web-backend-{1,2}.{out,err}.log (+ .status.json) — the appliance DATA root; nothing under install\BingooEdge\runtime\versions\0.8.1-edge\storage\logs; supervisor task logs under data\worker-logs\BingooEdgeWeb{1,2}.out.log
```

### 6.3 Verdict on the owner's §6 criteria

| criterion | result |
|---|---|
| both backends respond successfully | YES whenever probed directly (70/70 and 70/70; 150-request run 95 × 200 + 48 × expected 404 on #1 before its restarts) |
| no request hang | YES — no indefinite hang; the F-FB4-1 wedge did not occur in ~500 requests + two browser sessions (0.8.0 wedged at ~37) |
| no progressive slowdown | YES on the modest run (first/last-50 p50 within the same range); latency tracks host CPU, not request count |
| no backend unexpectedly exits | **NO** — 28 supervisor-initiated restarts (15 + 13) in 36 min, all false "hung" verdicts under host load; 0 crashes/exits of the children themselves |
| supervisor liveness healthy | YES between the restart windows; the policy itself is the problem (3 s read / 3 misses is too strict for a single-threaded backend serving multi-second page renders) |
| gateway healthy | YES (nginx 8443 up throughout; it answered **502** for the seconds a backend was being restarted — the §10 W01 run hit two of them) |
| no restart loop | **NO during load** (restarts every 1–3 min while pages were being served or the host was saturated); YES idle (0 restarts 13:57:39 → 14:05+, including the 5-minute geometry capture 13:58–14:03) |
| logs outside the immutable runtime | YES |
| signed package audit clean | YES (ok=true after signing, after the rehearsal dry-run, after the real dry-run) |

```
REAL_LAB_HTTP_ACCEPTANCE=PARTIAL — the hotfix removes the wedge (no hang in ~500 requests + browser sessions, self-recovery works, logs/status/health as designed), but the liveness policy produced 28 false-positive restarts and two 502s under this host's load → defect F-FB5-1 (supervisor liveness too strict for php -S page renders on a loaded host). Standby, authority and data unaffected.
```

**F-FB5-1 (new, for the owner):** `EdgeWebBackendSupervisor` should treat a backend as hung only when it is NOT making progress — e.g. probe read timeout 10 s,
5 consecutive misses, and skip/forgive a miss when the child's stderr log advanced (a request was accepted/closed) since the previous probe; keep the current
behaviour for a truly silent child. Code change → 0.8.2, same gates. Until then the LAB is usable in standby (idle backends stable; a cashier session may see a
1–30 s restart gap when the host is saturated — on this shared laptop only; a dedicated appliance was never measured under such load).

## 7. Data and authority after the upgrade — `07-*`

The §3 snapshot re-taken at 13:33 and again at 13:56 UTC (`07-after-snapshot.txt`) against the baseline: **every counted and hashed rowset identical** —
appliance sales 6 / lines 25 / outbox 5 (acknowledged 5, pending 0, leased 0, failed 0) / customers 2 / aliases 0 / open shifts 0 / stock movements 17 /
migrations 218; Cloud sales 5 / lines 20 / inbound sale ingestions 5 / customers 2 / aliases 0 / open shifts 0 / tenant 217 / master 49; rowset MD5s
(appliance sales_orders 9bbd2ad2…, lines cc04a3e4…, outbox c9c08b1d…, customers e42077b9…; Cloud sales_orders 0dac7dbb…, lines 49bb4ea1…, customers
e39a8d18…) and the CHECKSUM TABLE values all equal the baseline. The ONE differing checksum is `edge_local_meta` (3512719334 → 2344869111 → 4255099336):
the single meta row that every authority tick rewrites (`authority_heartbeat_seq`, `authority_last_ack_at`, `heartbeat_consecutive_acks`, refresh timestamps)
— expected, not data. No duplicate ingestion (inbound 5 = sales 5), customer identity and the (empty) alias table intact.

Authority: Cloud lease holder cloud / edge_state standby throughout (the stop window lapsed the lease for ~16 min — the Cloud re-gained it on the first
heartbeat of the restarted worker: `fenced_at` NULL, `released_at` NULL, seq 47816 → 47906 by 13:56); appliance `standby / online`, STANDBY_READY when the
web tier is answering (see §6 for the web-tier states), freshness config revision 9 = Cloud 9, stock ok; AUTO_FAILOVER_ENABLED=false; no takeover, no
Local Mode, no handback.

```
CLOUD_SALES_BEFORE_AFTER=5 → 5        APPLIANCE_SALES_BEFORE_AFTER=6 → 6        SALES_ROWSETS_IDENTICAL=YES (orders + lines, both sides)
OUTBOX_UNCHANGED=YES (5 acknowledged / 0 pending / 0 leased / 0 failed, rowset MD5 identical)        AUTO_FAILOVER=false
```

## 8. Rollback

Not performed. Preconditions for the documented pointer rollback are all TRUE and recorded here in case the owner decides on it after §6: no migration
ran (218 → 218), schema `edge-config-v1` unchanged and `schema_compatible true` on both runtimes (rehearsed on the disposable copy), no persistent state
changed except the tick-written `edge_local_meta` row, pre-update backups available (§4 set + the installer's sealed `edge-backup-20261029_130821-dvltpo.enc`).
Procedure if ordered: `Start-EdgeLabWorkers.ps1 -Action Stop` → write `0.8.0-edge` into `install\BingooEdge\runtime\current` (temp file + rename) →
`-Action Start` → health shows 0.8.0-edge / ac5b168d. Never to 0.7.0; Cloud authority and ingested data are not touched to repair anything.
Trade-off the owner should weigh: 0.8.0's cashier tier has the F-FB4-1 wedge (both backends hang permanently after ~37 requests, no recovery); 0.8.1's
tier recovers itself but, on THIS loaded shared laptop, restarts when page loads make the liveness probe miss (§6).

## 9. Plan reviews (prepared for review — nothing executed)

### 9A. Browsable LAB Cloud — review of the `Start-LabCloud.ps1 -Browsable` proposal

Inspected: `scripts\Start-LabCloud.ps1` (lines 20–29), `routes\tenant.php:111`, `routes\edge.php:13`, `config\tenancy.php`, `config\session.php`,
`app\Http\Middleware\CentralOnly.php`, the LAB appliance's URL values and the dev clone's (`.env.edgeclone`).

**Finding that changes the plan.** The device API is mounted on `Route::domain(config('tenancy.central_domain'))`, and the REAL appliance's URLs are
`http://127.0.0.1:9701/api/edge/…` (Host `127.0.0.1`). The dev clone works with `CENTRAL_DOMAIN=localhost` only because its paired appliance's URLs are
`http://localhost:9704/…` (Host `localhost`). Switching the LAB Cloud to `CENTRAL_DOMAIN=localhost` as the 24 Oct plan proposed would make every appliance
heartbeat/sync call a 404 (route domain mismatch), the lease would lapse and the appliance would show `connection_lost`. The tenant web routes use a DIFFERENT
config value (`TENANT_BASE_DOMAIN`), so the corrected proposal keeps the central domain exactly as it is and only makes the tenant host resolvable:

| item | today (`Start-LabCloud.ps1`) | proposed `-Browsable` | why |
|---|---|---|---|
| tenant host routing | `TENANT_BASE_DOMAIN=127.0.0.1` → tenant host `edgehomelab.127.0.0.1` (unresolvable; POS unreachable) | `TENANT_BASE_DOMAIN=localhost` → `http://edgehomelab.localhost:9701` (Chromium/Edge resolve `*.localhost` to loopback, RFC 6761, no hosts entry) | the only change that makes `/pos` reachable |
| central domain (device API, `api/edge/*`) | `CENTRAL_DOMAIN=127.0.0.1` | **unchanged** `127.0.0.1` | the appliance's Host is `127.0.0.1`; `routes/edge.php` binds to this value |
| session storage | `SESSION_DRIVER=array` (nothing persists → login cannot survive a request, 419 on POST) | `SESSION_DRIVER=file` (files under the export's `storage/framework/sessions`, LAB-only) | required for any browser login; no shared store |
| cookie scope | n/a (array) | host-only cookie: `SESSION_DOMAIN` unset, `SESSION_SAME_SITE=lax` (config default), `SESSION_HTTP_ONLY=true` (default), `SESSION_SECURE_COOKIE` unset (plain http on loopback) | cookie bound to `edgehomelab.localhost`, never sent to another host |
| CSRF | app default (`ValidateCsrfToken`, token in the session) — inert today because the session is array | unchanged mechanism, now effective (token persists in the file session); `api/edge/*` stays excluded as in `bootstrap/app.php` | — |
| authentication | tenant e-mail login (`lab.cashier@edgehomelab.test`), password in `secrets\cashier.pass` | unchanged; permissions as granted on 24 Oct | — |
| listener binding | `php -S 127.0.0.1:9701` | **unchanged** `127.0.0.1:9701` — private loopback only, never `0.0.0.0` | no LAN exposure; the second laptop does NOT reach the LAB Cloud (by design) |
| Cloud→Edge / Edge→Cloud endpoints | appliance `EDGE_*_URL = http://127.0.0.1:9701/…` | **unchanged** (appliance.env not touched) | — |
| heartbeat compatibility | acks every 20 s | unaffected because the central domain is unchanged; to be PROVEN after the restart by watching `last_heartbeat_at` advance and the authority worker tick `current` within 60 s | — |
| `APP_URL` | `http://127.0.0.1:9701` | `http://edgehomelab.localhost:9701` (generated tenant links); central links are not used in the LAB | — |
| rollback | — | `Start-LabCloud.ps1 -Stop` then start WITHOUT `-Browsable` from the same export; delete the file sessions directory contents | one restart |

Residual risk to verify on the dev clone first (one run, disposable): start a dev Cloud with `CENTRAL_DOMAIN=127.0.0.1` + `TENANT_BASE_DOMAIN=localhost` and
confirm (a) the tenant login/POS at `edgehomelab.localhost` and (b) a paired appliance heartbeat with Host `127.0.0.1` both work in the same process. If the
app anywhere derives one domain from the other, that run shows it before the LAB is touched. Restarting the LAB Cloud interrupts heartbeats for the restart
seconds only (the lease TTL is 120 s). Not executed in this directive.

### 9B. Temporary LAB admin + manager PIN — review of `Create-LabAdmin.php` / `Disable-LabAdmin.php` (not yet written as files; the plan mirrors the owner-approved `Create-LabApprover.php`)

| item | proposal |
|---|---|
| the five route permissions (exact names from `routes/tenant.php`) | `tenant.dashboard`, `tenant.users.index`, `tenant.users.show`, `tenant.users.manager-pin` (GET `/users/{user}/manager-pin`, line 223), `tenant.users.manager-pin.store` (POST, line 224) — enforced by `route.permission` (`EnsureRoutePermission`: a route is gated by its own name unless an explicit alias maps it; no alias exists for these) |
| provisioning mechanism | the same LAB-only PDO path as `Create-LabApprover.php`: refuses any database whose name lacks "lab"; inserts the `users` row with `password_hash(…, PASSWORD_BCRYPT)` (= Laravel `Hash::make`), guard `tenant` `permissions` rows and `model_has_permissions` grants; prints ids/codes/permission names only. Authorization for running it = this owner directive (operator shell on the LAB host); there is no Cloud super-admin UI for the LAB tenant |
| account scope | tenant `edgehomelab` only (tenant DB `pos_lab_tenant_edge`); `default_branch_id 1`; employee code `LABADM1`; NO POS, user-edit, role or settings permissions; production tenants (Khatri/Kashif/Tawakal) live in other databases on other hosts — the LAB tenant DB cannot reach them |
| expiry | manual: `Disable-LabAdmin.php` immediately after the PIN is set (status `inactive` + all `model_has_permissions` rows removed); the row stays for audit. Recommended addition: the script writes `expires_at` note into `users.remarks`-equivalent is NOT available → keep the disable step in the acceptance checklist as a hard step |
| password | read from an OWNER-written `secrets\admin.pass` (≥ 12 chars enforced by the script), bcrypt-hashed, never printed, never on a command line |
| PIN | set by the owner privately through the normal UI `/users/{approver}/manager-pin`: `TenantUserController::managerPinStore` validates `pin` (string 4–64, confirmed), `Hash::make` (bcrypt), `ManagerPin::updateOrCreate(user_id, pin_hash, is_active)`; the LAB approver (user 2) then satisfies W07/W08 approvals |
| audit | the `manager_pins` row (`updated_at`), the tenant `users` row history, the LAB tenant's request log of the browsable Cloud; the provisioning script prints what it changed (ids only) and that output is saved under evidence |
| privilege removal | `Disable-LabAdmin.php`: `UPDATE users SET status='inactive'` + `DELETE FROM model_has_permissions WHERE model_id = <admin>`; verification query of zero remaining grants |
| production isolation | the scripts hard-refuse non-"lab" database names; the LAB Cloud process only knows the LAB master/tenant DBs (`lab.env`); no production credentials exist on this host's LAB configuration |

Both tracks remain NOT executed; neither blocks the completed 0.8.1 appliance installation.

## 10. Second-laptop standby-safe checks

The physical second laptop could not be driven from this session (the owner's hands; this host is on Wi-Fi 172.40.1.17 with no 192.168.x neighbours
reachable). The checks were run from this host over the SAME path a LAN cashier device uses — the TLS gateway — by LAN name (`https://desktop-0024epm.local:8443`,
curl 200 ×4, 0.8–1.2 s, certificate SAN includes that name) and, for the browser harness (which refuses non-loopback hosts by design), by `https://127.0.0.1:8443`
with the LAB cashier `LAB2C5D` / approver `LABMF2DE` (passwords from `secrets\cashier.pass` / `approver.pass` into env, never printed). `10-standby-gateway\`,
`10-geometry-lab\`, `10-geometry-lab-vs-online.json`, 13:55–14:03 UTC. No cash sale, return or shift mutation was attempted (W02/W16 excluded).

| check | result |
|---|---|
| login + terminal selection | login OK (employee code), terminal `Lab Counter 1`; `/edge/local/pos` reached |
| shared POS layout | W01 FAIL **only** because two gateway responses were **502** (`/edge/local/pos/shift?terminal_id=1`, `/edge/local/pos/shared`) at 13:56:25–13:56:40 = the moment both backends were being restarted (§6); the layout checks did not complete in that run |
| capability display | from the W18/W15 steps of the same session: `reminderPause true` (route `/edge/local/pos/reminder-pause`), `customerCreate true` (quick-add shown, Save enabled) — rendered by the server exactly as on 0.8.0 |
| cashier backend availability | available except during the restart seconds (502 from nginx), see §6 |
| geometry (comparable: layout only, dataset-independent) | **EDGE_VS_ONLINE_GEOMETRY=NONE** — LAB appliance (0.8.1, LAB dataset) vs today's Online clone report: 998 boxes, max deviation 0 px, geometry 0, presence 0, data 10 (content-driven heights: different dataset), skipped 2 (07-recent-prints both) |
| expected standby write refusals | W18 → 422 `STANDBY` "Local Mode is not active — the Cloud POS is the active writer for this branch (standby)."; W15 quick-store → the same 422 STANDBY; both EXPECTED-DIFFERENCE, nothing written |
| unexpected 409 / 500 | 0 fenced 409s, 0 × 500; the two 502s above are the restart artefact |

```
SECOND_LAPTOP_STANDBY_CHECK=PARTIAL — login/terminal/capabilities/standby refusals/geometry PASS over the gateway; the layout walk hit two 502s from a supervisor restart (F-FB5-1); physical second-laptop run still to be done by the owner
```

Final state (14:05 UTC): health **STANDBY_READY**, 0.8.1-edge, authority standby/online (acks 97), both backends healthy/responding, restarts 15/13 and none
since 13:57:39, AUTO_FAILOVER false, lease holder cloud. Prior disposable W15/W18 evidence (`…\release-0.8.1-edge\fb5\`) preserved.
