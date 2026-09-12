# OFFLINE EDGE — P4: Windows appliance, print authority, installer prep (12 Sep 2026)

**Status: DELIVERED (non-elevated dev-box proof).** Physical certification items (Scheduled Task registration under the
service account, reboot auto-start, crash restart by the Task Scheduler, a real branch-CA certificate on a LAN, a
physical network printer) remain on the certification checklist — see `READY_FOR_PHYSICAL_CERTIFICATION` below.

Accepted head at start `cfba4cc` (F3 code `ac5e919`); canonical `origin/feat/14d-2-plan-upgrade-requests` at start
`8740f51` — reconciled into the Edge branch (merge `910ecdf`, tag `edge-pre-reconcile9-cfba4cc`, two Cloud files, no
Edge contract change). Locked rules unchanged: Online defines WHAT, Edge defines HOW; Cloud = official financial truth;
warm standby + supervised takeover; `AUTO_FAILOVER_ENABLED=no`; nothing deployed, no production/Khatri/Kashif/Tawakal
mutation, no production Local Mode, no WAN-unplug pilot.

## 1. Mission

A **repeatable Windows Branch Server appliance**: install → configure → start → stop → restart → update → backup →
recover, by an operator, without a developer. **No new business feature** — every P4 change is packaging, supervision,
first-boot binding, configuration storage, health, logging and safety.

## 2. Windows service model (`EdgeSupervisionPlan`, one source of truth)

All processes are **Windows Scheduled Tasks**: boot start (`-AtStartup`, StartWhenAvailable), restart 999× at 1-minute
intervals, principal `NT AUTHORITY\LOCAL SERVICE` (never SYSTEM, RunLevel Limited), `MultipleInstances IgnoreNew`, and
**no secret on any command line** (`php <InstallRoot>\artisan <edge:command>` only). `Register-EdgeServices.ps1`
renders exactly what `edge:local:service-plan --json` emits.

| Task | Process | Responsibility | One-instance guarantee | Stop |
|---|---|---|---|---|
| `BingooEdgeWeb1..N` (N=`EDGE_WEB_WORKERS`, default 2) | `edge:local:serve --worker=N` → PHP built-in server | loopback web backends `127.0.0.1:8090+` | the listen port | task stop (stateless; MySQL rolls back) |
| `BingooEdgeGateway` | `nginx.exe -p <DataRoot>\gateway -c nginx.conf` | **the LAN listener**: HTTPS 443 (branch-CA cert) → backends (least_conn); 80 → 301 https | the listen port | `nginx -s quit` |
| `BingooEdgePrintWorker` | `edge:local:print-worker` | LAN printers, TCP 9100, lease/backoff | DB singleton heartbeat | cooperative `--stop` |
| `BingooEdgeAuthorityWorker` | `edge:local:authority-worker` | heartbeat, connection state machine, standby freshness, drain + reconcile while local | DB singleton heartbeat | cooperative `--stop` |
| `BingooEdgeSyncSender` | `edge:local:sync-send` every 2 min | outbox → Cloud | outbox SKIP-LOCKED lease | task stop |
| `BingooEdgeBackup` | `edge:local:backup` hourly | encrypted local backup | file lock | task stop |

Crash recovery: the DB and outbox are never mid-write across a kill (transactions), print/authority leases expire and
are re-claimed, the sender lease is SKIP LOCKED. A duplicate task start exits cleanly (heartbeat/lease/port).

Web runtime decision: PHP's built-in server on loopback behind nginx TLS. Windows has no PHP-FPM; N supervised
backends give concurrency without a process manager; the gateway is the only thing on the LAN. **No plain HTTP as a
normal mode**: `edge:local:serve` refuses a non-loopback bind unless the lab override is set.

## 3. Print architecture lock

```
NETWORK_PRINTER_EDGE_DIRECT            = supported   (Local Mode: appliance print worker → printer IP:9100; Online: Cloud print path)
SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER  = no          (never two agents on one printer)
USB_DUAL_MODE_AGENT                    = not_built   (ONE Bingoo Print Agent per host; Cloud-only today)
USB_STATUS_FOR_PILOT                   = ONLINE_REQUIRED (pilot branches use network printers; USB printing needs the Online POS)
```

Locked in `config/edge.php` (`print_architecture`) and asserted by `EdgeNoDuplicatePrintAfterSyncMySqlTest`; shown
to the operator on the health page ("Network printers print directly from this Branch Server in Local Mode. USB
printers need the Online POS.").

## 4. No duplicate print after sync — PROVEN

`EdgeNoDuplicatePrintAfterSyncMySqlTest` (two real databases, a real TCP FakePrinter): an offline sale sends ONE
KOT and ONE receipt through the appliance print worker to the network printer (exactly two payloads captured); a
double-click on "Print bill" reuses the ensure-once job; the WAN returns, the Cloud ingests the OFFICIAL sale and
creates **no print job and no KOT batch** — not on ingestion, not on an ACK replay (`already_applied`), not on
lost-ACK recovery, not on handback; the local worker finds nothing new to print after the sync. Cloud print jobs are
created only by the Online controller flows (`DirectPayPrintOrchestrator` / `PrintJobController`), never by
`EdgeInboundSaleIngestionService`.

## 5. Package (`edge:build-package`, `edge:audit-package`, `EdgePackageBuilder`)

`edge-package-v1`: `package-manifest.json` (identity, every file's sha256, boundary audit) · `app/` (restricted
artifact) · `php/` (bundled runtime when given) · `gateway/nginx.exe` (when given) · `scripts/` · `templates/`
(`appliance.env.template` keys only, launcher, mime.types, README-INSTALL) · `update/edge-update-<v>.json`
(Ed25519-signed). Release builds refuse a dirty tree and require a signing key; the **boundary gate** walks the whole
tree and refuses `.env`/`appliance.env`/keys/certs/dumps/tests/`.git`/`node_modules`, any Cloud-only sentinel
(Catering, ManualJournalService, every ingestion service, Supplier controllers) and a missing Edge runtime sentinel;
a failed build leaves nothing behind. P4 also physically excludes the F1 Cloud-only pieces that still shipped
(return ingestion + returnable projection + heartbeat API + standby advertiser).

## 6. First-install flow (`Install-EdgeAppliance.ps1`)

verify package → install root (`php\`, `gateway\`, `scripts\`, `artisan` launcher, `runtime\versions\<v>` +
`current`) → data root (`config\`, `certs\`, `logs\`, `backups\`, `gateway\`; ACLs when elevated) → `appliance.env`
(new machine-local app key; secrets from read-once files or hidden prompts) → `edge:local:db-init` → `edge:local:pair`
(device secret generated locally; Cloud gets its sha256; identity persisted) → `edge:local:bootstrap-pull` (sections
hash-verified; acknowledge) → `edge:local:enroll` → `edge:local:gateway-cert` → `edge:local:service-plan
--write-gateway-config` → `Register-EdgeServices.ps1 -Action Register` → two warm ticks → `edge:local:health`.
**Never LOCAL_ACTIVE during install; success = READY AS WARM STANDBY.**

## 7. Configuration storage

| Item | Where |
|---|---|
| binding (tenant/branch/device public id/epoch) | local DB `edge_local_meta` |
| device secret, DB credentials, `EDGE_LOCAL_APP_KEY`, backup recovery key, public trust keys, endpoints | `<DataRoot>\config\appliance.env` — the ONLY secrets file (ACL: SYSTEM + Administrators full; service account read) |
| TLS cert/key | `<DataRoot>\certs\server.crt` / `server.key` |
| gateway config + service plan | `<DataRoot>\gateway\nginx.conf`, `service-plan.json` |
| logs / backups | `<DataRoot>\logs\edge-*.log` / `<DataRoot>\backups\*.enc` |
| runtime versions + pointer | `<InstallRoot>\runtime\versions\<v>`, `runtime\current` |
| layout (paths only) | `<InstallRoot>\appliance.json` |

`bootstrap/app.php` loads `appliance.env` from `BINGOO_EDGE_ENV_DIR` (exported by the launcher and by `serve` for its
children); a Cloud host never sets it. No secret is hard-coded, printed, logged or on argv (`EdgeLogHygieneTest`,
`EdgeSupervisionMySqlTest`, `EdgePackageBuilderTest`).

## 8. DB bootstrap · 9. Update · 10. Backup/recovery · 15. Uninstall

- `edge:local:db-init` creates the loopback `bingoo_edge_*` database and runs tenant + edge migrations; it refuses a
  bound appliance (no `migrate:fresh`, no destructive reset).
- `Update-EdgeAppliance.ps1` → hash verification → refuses while LOCAL_ACTIVE → cooperative stop →
  `edge:local:update` (signature, tamper, product/version/schema, **pinned target tenant/branch/device**, pre-update
  backup, atomic stage + pointer switch, forward-only schema upgrade, rollback) → start → health. The installed runtime
  reports the version of the artifact it runs (`edge-build-manifest.json`), never a stale constant.
- Backups are sealed with a per-backup key wrapped by `EDGE_BACKUP_RECOVERY_KEY` (portable; kept off the box);
  `Restore-EdgeAppliance.ps1` refuses another branch's backup and preserves pending events.
- `Uninstall-EdgeAppliance.ps1` removes the runtime + tasks only; DB/outbox/backups/config/certs are preserved and
  listed. `-DropDatabase`/`-RemoveData` need `-ConfirmPhrase 'REMOVE ALL BRANCH DATA'`, and `edge:local:uninstall-data`
  **refuses while events are unsynced** unless `--force-lose-pending`.

## 11. Health · 12. Logging · 13. Network · 14. Authority UX

- **ONE report** (`EdgeApplianceHealthService`) behind `edge:local:health`, `Get-EdgeHealth.ps1` and the cashier's
  **Status** page (`/edge/local/pos/health`, server-rendered, no scripts): services, Cloud connection, authority state,
  binding, freshness (config / stock / returns / supplier finance / purchase returns), outbox + permanent failures, last
  sync, DB, printers, backup recency, updater/version, gateway certificate. Non-secret by construction.
- `LOG_CHANNEL=edge` → daily files under the data root (30 days). Existing tags cover authority transitions, heartbeat,
  takeover/handback, sync family + uuid, terminal refusals, lost-ACK, print jobs, backup/update; pairing/bootstrap/
  uninstall audits added. `EdgeLogHygieneTest` fails on any secret-shaped log context.
- Network requirements documented in `README-INSTALL.md`: reserved IP + hosts/router DNS, 443/80 on the LAN, 9100 to
  printers, outbound HTTPS to Cloud, loopback DB, UPS recommended; the appliance solves WAN loss, not LAN/power loss.
- Authority UX unchanged: ONLINE → UNSTABLE → LOST → PREPARING_LOCAL → LOCAL_ACTIVE → CONNECTION_RESTORED →
  HANDING_BACK → ONLINE, supervisor confirmation required, the health page shows plain words (never hashes/epochs/payloads).

## 16. Clean-machine install proof (`EdgeCleanMachineInstallMySqlTest`)

Real processes, real databases, from a real package: a `php -S` Cloud over the master + tenant test DBs; the package
installed by `Install-EdgeAppliance.ps1` into a fresh install root + data root + fresh local DB through the launcher;
pairing and bootstrap over HTTP; self-signed lab gateway certificate; two warm ticks; enrolment from a Cloud-signed
assertion; the installed runtime serving on loopback and through the nginx TLS gateway (cashier login, POS page,
Status page over HTTPS; port 80 redirects); encrypted backup; restore into a fresh DB-B (wrong branch refused,
pending event preserved); tampered update refused then the signed 0.2.0 update applied (pre-update backup, pointer
switch, outbox kept, runtime reports 0.2.0); uninstall preserves data by default; data removal refuses while an event
is unsynced; explicit removal works. `-NoServices`: `Register-ScheduledTask` is denied to the non-admin user on this
box, so task registration / reboot / auto-start / crash-restart-by-supervisor are **physical certification** items.

## Bugs the proofs caught (fixed in P4)

- **Cloud first-boot blocker:** `EdgeBootstrapSnapshot` never mass-assigned `config_revision`, so every REAL snapshot minted
  through `POST /api/edge/bootstrap/snapshots` carried `config_revision = NULL` and the appliance importer refused it with
  `CONFIG_REVISION_MISSING`. Fixed (+ `EdgeBootstrapSnapshotContractTest`). No in-process fixture had ever exercised the API path.
- **Appliance commands without a database:** a real Branch Server has `tenant.database = null`; `edge:local:backup / restore /
  update / sync-send / sync-status / authority-*` ran on the unbound connection ("No database selected"). Every DB-touching
  `edge:local:*` command now maps `tenant := edge_local` first (the convention db-init/status/print-worker already followed).
- **Updater vs. junctions:** `EdgeUpdateInstaller::stage()` tried to `copy()` a directory junction (PHP reports a junction as
  neither link nor dir); it now skips reparse points — a release artifact ships real files only.
- **Runtime identity:** `config('edge.app_version')` was a constant; the installed runtime now reports the version of the
  artifact it runs (`edge-build-manifest.json` via `base_path()`), so an updated appliance never claims the old version.
- **nginx 1.22:** `http2 on;` is a 1.25+ directive — HTTP/2 is enabled on the `listen` line; `mime.types` ships beside the
  rendered config.
- **Fresh-machine restore contract:** the Cloud config must be present before the local state is restored
  (`Restore-EdgeAppliance.ps1 -PullConfig`); the restore precheck otherwise refuses dangling references (correct behaviour).

Dev-package caveat (stated, not hidden): a dev/test package junctions `app/vendor` to the shared closure, and PHP resolves
junctions in `__DIR__`, so Composer roots `App\` at the shared tree — the installed dev runtime runs this tree's classes with the
ARTIFACT's bootstrap/config/routes/public/launcher/manifest. A release package carries a real vendor and runs only its own files.

## Gates

| Gate | Result |
|---|---|
| Focused packaging / install (`EdgeSupervisionMySqlTest` 10, `EdgeApplianceHealthMySqlTest` 4, `EdgeUpdaterMySqlTest` 10 incl. pinned-target, `EdgeNoDuplicatePrintAfterSyncMySqlTest` 1/44 assertions, `EdgeCleanMachineInstallMySqlTest` 1/127 assertions in 3m19s) | green |
| Feature Edge gates (`tests/Feature/Edge`: registration census, Blade compile + php -l + node --check, artifact plan/build/boot + physical exclusion, package builder 6, log hygiene 3, snapshot contract 1) | 119 green |
| FAST (`--testsuite Feature,Unit`) | 236 tests, 2 failures = the 2 stale canonical SQLite unit tests (EscPosReportPayloadTest, DeliveryRiderReassignmentRegressionTest — known debt) |
| One authoritative full MySQL run (`./test-mysql.sh --debug`, 12 Sep 2026, P4 code) | 1616 tests: 1611 passed, 1 failed, 4 errored = exactly the 5 Dompdf/A4-PDF canonical debt items (3× CateringDocumentPdfMySqlTest, PosQuickReportMySqlTest email A4 PDF, ReportScheduleMySqlTest daily A4) → **NEW_EDGE_REGRESSIONS = 0** |
| `php -l` over every changed/new PHP file · `git diff --check` · PowerShell `Parser::ParseFile` over every script · `nginx -t` on a rendered gateway config | clean |

## Classifications

```
NETWORK_PRINTER_EDGE_DIRECT=supported   SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER=no   USB_DUAL_MODE_AGENT=not_built
USB_STATUS_FOR_PILOT=ONLINE_REQUIRED    NO_DUPLICATE_PRINT_AFTER_SYNC=proven         AUTO_FAILOVER_ENABLED=no
LOCAL_MODE_ACTIVATED=no                 PRODUCTION_MUTATED=no                        SECRETS_IN_CMDLINE=no   SECRETS_IN_LOGS=no
```

## Physical certification checklist (not provable on this non-elevated dev box)

1. Elevated `Install-EdgeAppliance.ps1` on a clean Windows 11 Pro machine: tasks registered under LOCAL SERVICE, ACLs applied.
2. Reboot → every task auto-starts; kill a worker → the Task Scheduler restarts it within a minute; kill nginx → restarted.
3. Branch-CA server certificate via `New-EdgeServerCertificate.ps1 -ExportPfx` → `edge:local:gateway-cert`; terminals trust the CA.
4. A physical network printer on the branch LAN prints the offline KOT/receipt once (the accepted printer test harness).
5. Supervised WAN-unplug pilot at a chosen branch — **explicitly out of scope for P4; not started.**

## FINAL REPORT (P4)

```
START_EDGE_HEAD=cfba4cc (F3 code ac5e919)      CURRENT_CANONICAL=8740f51 (unchanged during P4)      CANONICAL_RECONCILED=yes (merge 910ecdf, tag edge-pre-reconcile9-cfba4cc)
CANONICAL_AT_START=8740f51   CANONICAL_AT_PACKAGE_GATE=8740f51
FINAL_EDGE_HEAD=<code commit>   ORIGIN_EDGE_HEAD=<pushed head>       (see git log)
WINDOWS_PACKAGE=built (edge:build-package: edge-package-v1, manifest + boundary audit + signed update; PHP/nginx bundled when given)
CLEAN_MACHINE_INSTALL=yes — non-elevated equivalent: fresh install root + data root + fresh DB from the package, real php -S Cloud over HTTP, launcher-driven commands, TLS gateway + cashier login (EdgeCleanMachineInstallMySqlTest, 127 assertions, 3m19s)
EDGE_WEB_SERVICE=BingooEdgeWeb1..N (edge:local:serve, loopback) + BingooEdgeGateway (nginx TLS)   EDGE_SYNC_SERVICE=BingooEdgeSyncSender (+ drain inside the authority worker while local)
EDGE_HEARTBEAT_SERVICE=BingooEdgeAuthorityWorker   EDGE_PRINT_WORKER=BingooEdgePrintWorker (network printers, TCP 9100)
SERVICE_AUTO_START=defined (AtStartup + StartWhenAvailable in Register-EdgeServices.ps1) — registration/reboot NOT provable here (ADMIN=no) → physical certification
CRASH_RESTART=defined (RestartCount 999 / 1 min; leases/heartbeats make duplicate starts safe) — Task Scheduler restart NOT provable here → physical certification
NETWORK_PRINTER_EDGE_DIRECT=supported   SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER=no   USB_DUAL_MODE_AGENT=not_built   USB_STATUS_FOR_PILOT=ONLINE_REQUIRED
NO_DUPLICATE_PRINT_AFTER_SYNC=proven (EdgeNoDuplicatePrintAfterSyncMySqlTest: ONE KOT + ONE receipt locally; 0 Cloud print jobs / KOT batches after ingestion, replay, lost-ACK recovery, handback)
FIRST_BOOT_BINDING=yes (edge:local:pair + edge:local:bootstrap-pull over real HTTP; device READY, snapshot acknowledged)
FIRST_WARM_SYNC=yes (two authority ticks: heartbeat acknowledged, config + stock caches current)      STANDBY_READY=yes (bound, standby, never LOCAL_ACTIVE; DEGRADED only for the unregistered services on the non-admin box)
SECRETS_STORAGE=<DataRoot>\config\appliance.env (ONLY secrets file; ACL SYSTEM+Administrators full / service account read when elevated) + local DB for public identifiers
SECRETS_IN_CMDLINE=no   SECRETS_IN_LOGS=no (EdgeLogHygieneTest, EdgeSupervisionMySqlTest, EdgeApplianceHealthMySqlTest)
LOCAL_DB_BOOTSTRAP=yes (edge:local:db-init on a fresh loopback DB; refuses a bound appliance; no migrate:fresh)
SAFE_UPGRADE=yes (config/DB/outbox preserved; previous runtime kept; pointer rollback)   SIGNED_UPDATE=yes (Ed25519; pinned target refused)   TAMPERED_UPDATE_REFUSED=yes
BACKUP=yes (encrypted; hourly task + on demand)   FRESH_MACHINE_RESTORE=yes (DB-A → fresh DB-B; wrong branch refused; pending event preserved; config pulled first)
HEALTH_CHECK=yes (edge:local:health + Get-EdgeHealth.ps1 + cashier Status page; one non-secret report)
LOGGING=yes (LOG_CHANNEL=edge daily files under the data root; audits for pairing/bootstrap/uninstall added; hygiene test)
ARTIFACT_BOUNDARY=yes (package boundary gate + artifact physical exclusion; F1 Cloud-only pieces now excluded too)
FOCUSED_PACKAGING=green (supervision 10, health 4, updater 10, print 1, clean-machine 1)   FULL_MYSQL=1616 / 1611 passed / 5 red = Dompdf debt   FAST=236 (2 stale unit tests = debt)
NEW_EDGE_REGRESSIONS=0   KNOWN_CANONICAL_DEBT=5 Dompdf/A4-PDF MySQL tests + 2 stale SQLite unit tests (unchanged)
P0_OPEN=0   P1_OPEN=0
P2_RELEASE_BLOCKERS=physical certification only: elevated task registration under LOCAL SERVICE + ACLs, reboot auto-start, Task-Scheduler crash restart, branch-CA certificate on a LAN + terminal trust, physical network printer, real signing/recovery key custody (REAL_SIGNING_KEY_REQUIRED / REAL_RECOVERY_KEY_PROVIDER_REQUIRED), release package with composer --no-dev vendor
READY_FOR_PHYSICAL_CERTIFICATION=yes   AUTO_FAILOVER_ENABLED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no
```