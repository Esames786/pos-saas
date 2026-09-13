# OFFLINE EDGE — P5: physical Windows certification (LAB) — status 13 Sep 2026

**Status: PARTIALLY CERTIFIED — BLOCKED on the physical lab items.** Everything a non-elevated developer laptop can
prove was proven with the RELEASE-SHAPED package (real `composer install --no-dev` vendor closure, no junction, the
installed runtime executing only its own files). Everything that needs Administrator rights, a reboot, a second
cashier machine, a raw-TCP network printer or router control is **not certified** here and is delivered as an
executable certification kit for the lab machine. `READY_FOR_WAN_UNPLUG_PILOT=no` (see the FINAL REPORT).

Accepted head at start `17b1333` (P4 code `67d5b73`); canonical moved `8740f51 → 5dc13d3` (HELD-SALE-DEAD-SESSION-1,
STEAK-SIDE-MODIFIER-1 guards) and was reconciled (merge `c84f8cd`, tag `edge-pre-reconcile10-17b1333`, no conflicts).

## 0. Canonical delta assessment

| Canonical change | Edge relevance | Action |
|---|---|---|
| `RestaurantTable::openSession` — the status filter moves INSIDE `ofMany()` (a newer CLOSED session no longer hides the open one on the board) | **shared runtime** — the Edge Table Board uses the same relation | merged (no Edge code change needed) |
| `HeldSaleController` / `POSController` / `SalesOrderController` — dead-session guard on Pay + the "orphan bill" recovery popup, `deadSession` view model | Cloud controllers (Online recovery UI for a Cloud-side hold defect) | merged; **Edge already guards it**: `EdgeLocalPosService` only accepts an explicit `restaurant_table_session_id` whose status is `open`/`bill_requested` (lock-first) and settle re-checks the session — no orphan bill can be created offline. Recorded in the parity register as CONSISTENT (no Edge action). |
| `SteakSideModifierMySqlTest`, `HeldSale*MySqlTest` | canonical tests only | merged |
| SALES-ANALYTICS-1 (`8e78b4d`, landed while P5 ran): Owner-only sales/growth graphs page (`Tenant/Reports/SalesAnalyticsController`, `routes/tenant.php`, dashboard + analytics views) | Cloud-only — the Reports controllers and tenant routes are physically excluded from the artifact; no Branch POS / finance / printing / Edge contract change | assessed, not merged (reconcile at the next Edge tranche) |

## 1. What this box could and could not do

| Requirement | This box (Dell Precision 3560, Windows 11 Pro 10.0.26200, non-admin) | Result |
|---|---|---|
| Administrator rights | no (`Register-ScheduledTask` → Access is denied; ACLs cannot be applied) | **blocked** |
| Clean machine / VM | no Hyper-V / VirtualBox / VMware; WSL cannot run Windows | equivalent: fresh install root + data root + fresh DB from the package |
| Reboot | not possible without killing the operator's session; never rebooted a user's machine | **blocked → kit** |
| Second cashier Windows client | none | **blocked → kit** (`Test-EdgeCashierTrust.ps1`) |
| Raw-TCP (9100) network printer | LAN scan: no host answers 9100/631/515 (the WSD-only HP MFP does not expose raw printing) | **blocked** — needs a thermal LAN printer or raw 9100 enabled |
| Router / WAN control | no | **blocked → kit** (`-Phase Lan`) |
| Real signing-key custody / recovery-key provider | no vault, HSM or Cloud recovery authority exists yet | **blocked (honest)** — see §3 |

## 2. Release-shaped package (proven here)

- `composer install --no-dev --prefer-dist --optimize-autoloader` in an isolated `git archive HEAD` export → 83 packages,
  6,607 files, `installed.json dev=false`, no phpunit / mockery / faker.
- `edge:build-package --vendor-from=<that closure>`: the builder now takes `vendor/*` from the separate closure
  (`EdgeArtifactBuilder` `vendor_source`); it refuses a closure whose `composer.lock` differs from the tree's or that was
  installed with dev packages; the artifact manifest records `vendor_source=separate_no_dev_closure` and hashes every
  vendor file (signed through the update manifest). A **release** build (`--allow-dirty` absent) now REQUIRES `--vendor-from`.
- **Executes only package files:** `scripts/edge/appliance/include-probe.php` (auto-prepended through `PHP_INI_SCAN_DIR`)
  records `get_included_files()` for the launcher-driven CLI, for every web-backend request (login, POS page, health page
  through the TLS gateway) and for the updated 0.2.0 runtime. `EdgeCleanMachineInstallMySqlTest` in release mode asserts
  every included file lies under `runtime\versions\<v>\` — RELEASE_VENDOR_REAL_FILES=yes, DEV_VENDOR_JUNCTION_USED=no.
- `EdgeUpdateInstaller::stage()` copies with robocopy on Windows (`/E /XJ`, PHP loop fallback): a 10k-file release stage
  takes seconds instead of ~8 minutes of PHP `copy()`; the signed-manifest check still verifies every listed byte.

### Release-shape defect the P5 build caught (fixed)

The first release-shaped runtime could not boot: `Illuminate\Database\Console\WipeCommand.php` was missing. The artifact
exclude list prunes Cloud modules by **basename globs** (`'Catering*'`, `'Wip*'`, `'Purchase*'`, `'Supplier*'`,
`'Subscription*'` …) and, with a real vendor closure, `Wip*` also matched the framework's `WipeCommand.php`. The dev
junction never exercised vendor pruning, so P4's proofs could not see it. Fix: a module basename glob (one that starts
with a class-name letter) never applies under `vendor/`; extension and dot-file globs (`*.pem`, `.env.*`, `id_rsa.*`)
still do. A read-only audit of the no-dev closure after the fix: 6,607 files, 3 dropped, all intended (test/doc/secret
patterns), zero PHP code. Guarded by `EdgePackageBuilderTest` (a `WipeCommand.php` / `PurchaseGateway.php` look-alike
must ship; `secret.pem` must not).

**Defect #2 (same root cause):** the canonical migration `2026_08_24_000002_create_catering_service_time_presets` RUNS
`Database\Seeders\Tenant\CateringServiceTimePresetSeeder`, but the artifact shipped `database/migrations` only (and the
`Catering*` glob would have pruned the seeder anyway) — `db-init` on a fresh release appliance died mid-migration. Fix: an explicit
`keep` list in the artifact config ships EXACTLY that seeder and the model it loads (`CateringServiceTimePreset` — a
preset table model, no runtime logic); no seeders directory, no master/demo seeders. `EdgeArtifactTest` now derives the
list from the migrations themselves and fails the gate when a new migration references a seeder (or a model it uses)
that is not shipped. Catering runtime code (`app/Services/Catering`, controllers, views) stays
physically excluded.

**Defect #3 (the serious one):** on the real release runtime `edge:local:bootstrap-pull` — and, by the same chain, every
offline SALE, RETURN, supplier-finance and purchase-return envelope — died loading
`App\Services\Saas\TenantSubscriptionAccessService`. The appliance envelope builders and the bootstrap importer injected
the Cloud's `EdgeBootstrapService` only for `canonicalJson()` / `computeManifestHash()`; its constructor pulls the Cloud
entitlement service, whose constructor pulls the excluded SaaS subscription service. With the dev junction Composer
resolved that class from the developer tree, so P4's offline proofs (sales, returns, sync, handback) all passed on code
a release appliance could never run. Fix: the dependency-free `EdgeCanonicalJson` (byte-identical output, the Cloud
service delegates to it); the builders and the importer no longer depend on any Cloud service. **New gate:**
`EdgeApplianceDependencyClosureTest` walks the container-resolved dependency closure (constructor + command `handle()`
parameters, interfaces through their binding) from every appliance entry point and fails when a reachable `App\` class
is not in the artifact plan or belongs to a Cloud-only namespace — it would have caught this defect in seconds.

## 3. Key custody contract (defined; custody NOT established)

| Item | Contract | State |
|---|---|---|
| Update-signing private key | minted on the build host with `edge:update:keygen --private-out=<file>` (refuses on a Branch Server; never printed); moves into release-signing custody (offline/HSM-backed store or the CI secret store); never in git, never on an appliance; rotation = new pair, public key shipped through an update signed by the old key | **tooling + procedure delivered; no vault/HSM in place → REAL_SIGNING_KEY_CUSTODY=procedure_defined_custody_not_established** |
| Update-signing public key | `EDGE_UPDATE_PUBLIC_KEY` in `appliance.env` (installer `-UpdatePublicKey`); fingerprint printed by keygen | delivered |
| Backup recovery key | per-branch 32-byte key wrapped into every backup; must be provisioned/escrowed by a Cloud recovery authority (KMS/vault) so a replacement machine recovers it independently of the dead appliance | **provider not built → REAL_RECOVERY_KEY_PROVIDER=not_available (ConfigEdgeBackupKeyProvider = env file only)** |

P5 therefore stays **BLOCKED** on custody until a real vault/provider exists. Nothing was faked.

## 4–7. Admin install, task registration, reboot, crash restart — certification kit

`scripts/edge/Invoke-EdgeCertification.ps1` (Administrator, LAB): `Preflight` → `Tasks` (Get-ScheduledTask evidence:
principal never SYSTEM, RunLevel Limited, AtStartup, StartWhenAvailable, RestartCount/Interval, MultipleInstances,
working directory, command line secret-shape scan, last run result) → `Crash` (LAB process kill per family: web backend,
gateway, authority worker, sync sender, print worker; observes the supervisor restart; re-checks one print-worker
heartbeat row, one authority-worker row, outbox intact, authority never LOCAL_ACTIVE) → `PreReboot` (marker) → operator
reboot → `PostReboot` (tasks running, ports listening, seconds-after-boot per task, heartbeat resumed, standby) → `Lan`
→ `Security` → `Health` → `Report` (`certification-report.json`). The kit never activates Local Mode and never reboots
a machine itself.

## 8. TLS / branch CA / cashier trust — kit

`Test-EdgeCashierTrust.ps1` (on the cashier PC): imports the branch CA public certificate into the client's Trusted Root
store (the ONLY trust change), resolves the hostname (hosts/router DNS contract), then HTTPS GET of the health, login and
POS routes by hostname and by IP with **full certificate validation** (a certificate error is a FAIL; verification is
never disabled). Server side: `New-EdgeServerCertificate.ps1 -ExportPfx` → `edge:local:gateway-cert`.

## 9. Network printer

Architecture unchanged and locked: `NETWORK_PRINTER_EDGE_DIRECT=supported`, `SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER=no`,
USB `ONLINE_REQUIRED`. No raw-9100 printer was reachable on this LAN, so the physical KOT/receipt print is **not
certified here**; the lab needs a thermal LAN printer (or raw TCP/IP printing enabled on the MFP) and then runs the
accepted printer harness (`EdgeNoDuplicatePrintAfterSyncMySqlTest` shape with the real IP, or the local print worker
against a lab sale). The no-duplicate contract is already proven with the TCP FakePrinter.

## 10–11. Release update and replacement-machine restore (proven here, release-shaped)

Tampered package refused (hash mismatch, pointer unchanged) → signed 0.2.0 applied (pre-update backup, pointer switch,
outbox preserved, binding preserved, the 0.2.0 runtime reports 0.2.0 and executes only its own files) — plus the
updater unit gates: wrong signature, wrong product, wrong target tenant/branch/device, downgrade, incompatible schema.
Backup → fresh DB-B → `bootstrap-pull` (same device identity) → restore: wrong branch refused, pending event preserved
byte-for-byte, local users restored.

## 12–13. LAN-without-WAN and topology — kit + template

`Invoke-EdgeCertification.ps1 -Phase Lan -PrinterIp … -CashierIp … -CloudHost …` after the operator disables the
Internet at the router: Edge→printer 9100, Edge→cashier, Cloud unreachable, plus `Test-EdgeCashierTrust.ps1` for the
cashier→Edge leg. Topology template (fill at the lab): router/switch model, Edge reserved IP, cashier PC IP, printer
reserved IP, DNS/hosts mechanism, gateway certificate CN/SAN/thumbprint, UPS for server / switch / printer. The
appliance solves WAN loss, not LAN or power loss.

## 14–15. Security inspection and health (this box)

Process command lines, task plan command lines, logs, the installer transcript and the env file were inspected on the
release-shaped install here: no device secret, DB password, app key, recovery key or signing key appears in any command
line or log; the private signing key exists only in the build host's key file. File ACLs could not be restricted
(non-admin) — the installer applies `icacls` only when elevated and says so. The health report captured after install
and after the update is non-secret (see the proof and the P4 status doc).

## Gates run for P5

| Gate | Result |
|---|---|
| Release-shaped clean-machine proof (`EdgeCleanMachineInstallMySqlTest`, `EDGE_PROOF_VENDOR_FROM=<no-dev closure>`) | green — 1 test, 146 assertions (release vendor, CLI + web + 0.2.0 include probes, signed update with robocopy staging, fresh-DB restore, safe uninstall); the dev-junction mode stays green too (141 assertions) |
| Feature Edge gates (registration census, Blade compile + lint, artifact plan/build/boot + physical exclusion + the new migration-seeder gate, package builder incl. `vendor_source`, log hygiene, snapshot contract) | 122 green (incl. `EdgeApplianceDependencyClosureTest`, the migration-seeder gate and the `vendor_source` package tests) |
| MySQL smoke: F1/F2/F3 sync (`EdgeReturnSyncHttp`, `EdgeSupplierFinanceSyncHttp`, `EdgePurchaseReturnSyncHttp`), reliability (`EdgeTerminalRefusalIdentity`, `EdgeSyncSender`), packaging (`EdgeSupervision`, `EdgeUpdater`, `EdgeApplianceHealth`, `EdgeNoDuplicatePrintAfterSync`), the canonical held-sale / steak-side tests brought in by the reconcile | 95 MySQL tests green (F1/F2/F3 sync + reliability + packaging + `EdgeUpdaterMySqlTest` 10 with robocopy staging + the three canonical tests from the reconcile) |
| `php -l` changed files · `git diff --check` · PowerShell parse of every script | clean |

## FINAL REPORT (P5)

```
START_EDGE_HEAD=17b1333   CURRENT_CANONICAL=8e78b4d (8740f51 → 5dc13d3 reconciled as c84f8cd, tag edge-pre-reconcile10-17b1333; 5dc13d3 → 8e78b4d = SALES-ANALYTICS-1, an Owner-only Cloud reports page under app/Http/Controllers/Tenant/Reports + routes/tenant.php + views — physically excluded from the artifact, no Edge contract → assessed, NOT merged)
FINAL_EDGE_HEAD=a159700 (P5 code) / 4eef6a4 (docs) + this heads follow-up   ORIGIN_EDGE_HEAD=the pushed head of feat/edge-config-refresh-v1 (see git log)

PHYSICAL_MACHINE=developer laptop Dell Precision 3560 (non-admin) — NOT a clean lab machine
WINDOWS_VERSION=Windows 11 Pro 10.0.26200
ADMIN_CERTIFICATION=no

RELEASE_PACKAGE=built (edge:build-package --vendor-from <composer install --no-dev closure of git archive HEAD>; 83 packages, 6,607 vendor files, dev=false; signed update)
RELEASE_VENDOR_REAL_FILES=yes   DEV_VENDOR_JUNCTION_USED=no (release mode; the include probe proved every executed file lies under runtime\versions\<v>)

REAL_SIGNING_KEY_CUSTODY=procedure_defined_custody_not_established (edge:update:keygen on the build host; no vault/HSM/CI secret store yet)
REAL_RECOVERY_KEY_PROVIDER=not_available (ConfigEdgeBackupKeyProvider = env-file key only; no Cloud recovery authority)

INSTALL_AS_ADMIN=no (non-admin equivalent proven: fresh install root + data root + fresh DB from the release package through the launcher)
TASKS_REGISTERED=no — Register-ScheduledTask denied; plan JSON + Register-EdgeServices.ps1 + Invoke-EdgeCertification.ps1 -Phase Tasks ready for the lab
SERVICE_ACCOUNT=NT AUTHORITY\LOCAL SERVICE (planned)   SYSTEM_ACCOUNT_USED=no

REBOOT_AUTO_START=not certified (kit: -Phase PreReboot / PostReboot)   REBOOT_STANDBY_RECOVERY=not certified (kit)

WEB_CRASH_RESTART=not certified (kit -Phase Crash)   GATEWAY_CRASH_RESTART=not certified (kit)
AUTHORITY_CRASH_RESTART=not certified (kit; lease/single-writer semantics proven in-process by EdgeAuthorityWorkerLifecycleMySqlTest)
SYNC_CRASH_RESTART=not certified (kit)   PRINT_CRASH_RESTART=not certified (kit)

TLS_GATEWAY=yes on this box (nginx TLS → loopback backend; cashier login, POS page, health page over HTTPS with a LAB self-signed cert)
CASHIER_CERT_TRUST=not certified (needs a second Windows client; Test-EdgeCashierTrust.ps1 ready)   REAL_CASHIER_EDGE_PAGE=served over HTTPS on this box; second-machine load not certified

REAL_NETWORK_PRINTER=not certified — no raw-TCP 9100 printer reachable on this LAN (WSD-only HP MFP)
NETWORK_PRINTER_EDGE_DIRECT=yes (architecture; proven with the TCP FakePrinter)   SECOND_EDGE_AGENT_FOR_NETWORK_PRINTER=no
PHYSICAL_KOT_PRINT=not certified   PHYSICAL_RECEIPT_PRINT=not certified   NO_DUPLICATE_PHYSICAL_PRINT=proven against the TCP printer harness only

SIGNED_PHYSICAL_UPDATE=yes on this box (release-shaped 0.1.0 → 0.2.0; robocopy staging)   TAMPERED_UPDATE_REFUSED=yes
WRONG_TARGET_UPDATE_REFUSED=yes (pinned tenant/branch/device, downgrade, wrong signature/product/schema — updater gate)   STATE_PRESERVED_AFTER_UPDATE=yes (binding, DB, outbox)

PHYSICAL_BACKUP=yes on this box (encrypted)   REPLACEMENT_RESTORE=yes (fresh DB-B: config pulled with the same device identity, then restore; wrong branch refused)   PENDING_EVENT_PRESERVED=yes

LAN_WITH_WAN_DISABLED=not certified (router not under control; kit -Phase Lan)   CASHIER_TO_EDGE_LAN=not certified   EDGE_TO_PRINTER_LAN=not certified

SECRETS_IN_PROCESS_LIST=no (this box: launcher/artisan/php -S/nginx command lines carry no secret)   SECRETS_IN_TASK_COMMANDS=no (plan)   SECRETS_IN_LOGS=no
SECRET_FILE_ACLS=not applied (non-admin; the installer restricts appliance.env/certs with icacls only when elevated) → lab item

PHYSICAL_HEALTH=captured on this box after install and after update (bound, standby, heartbeat acknowledged, config/stock fresh, outbox 0, no secret)

AUTO_FAILOVER_ENABLED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no

P0_OPEN=0   P1_OPEN=0
P2_RELEASE_BLOCKERS=admin lab certification (tasks under LOCAL SERVICE + ACLs, reboot auto-start, supervisor crash restarts, cashier-client CA trust, raw-9100 network printer, LAN-without-WAN), signing-key custody (vault/HSM), recovery-key provider (Cloud recovery authority), release package with the bundled PHP runtime signed by the custody key

READY_FOR_WAN_UNPLUG_PILOT=no
```

The P6 WAN-unplug business pilot was NOT started.