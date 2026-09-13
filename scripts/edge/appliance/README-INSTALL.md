# Bingoo Edge — Branch Server appliance (Windows): install, operate, update, recover

This package is the **restricted Edge runtime** of Bingoo POS packaged for a Windows Branch Server. It runs the
branch's POS **without Internet** as a **warm standby** of the Cloud and hands authority back when the WAN returns.
The Cloud stays the official financial truth; the appliance never posts finance locally.

**Operating model (locked):** Edge = WARM STANDBY · Cloud writes while online · WAN failure → after the lease
expires and readiness passes → **SUPERVISED** local takeover (a person confirms; `AUTO_FAILOVER_ENABLED=no`) ·
WAN restored → the appliance REMAINS the writer → sync/reconcile → controlled handback.

## Package contents

| Path | What |
|---|---|
| `package-manifest.json` | identity, every file's SHA-256, boundary audit result (`edge:audit-package` verifies) |
| `app/` | the restricted artifact (no Cloud finance authorities, no ingestion, no Catering, no tests, no secrets) |
| `php/` | PHP runtime (when bundled) — else pass `-PhpPath` |
| `gateway/nginx.exe` | the TLS gateway (when bundled) — else pass `-GatewayPath` |
| `scripts/` | the reviewed PowerShell scripts (below) |
| `templates/` | `appliance.env.template` (keys only), `edge-launcher.php`, `mime.types`, this file |
| `update/edge-update-<v>.json` | the **signed** update package for this same artifact |

## Building a RELEASE package (build host, never an appliance)

1. Export the accepted commit and build the no-dev vendor closure beside its lock file:
   `git archive --format=tar <commit> | tar -x -C D:\build\edge-src` then, in that directory,
   `composer install --no-dev --prefer-dist --optimize-autoloader`.
2. Mint (once) the update-signing keypair on the build host: `php artisan edge:update:keygen --private-out=D:\secure\edge-update-signing.key`.
   The private key goes into release-signing custody (offline/HSM-backed store or the CI secret store) — never git,
   never an appliance. The printed PUBLIC key is every appliance's `EDGE_UPDATE_PUBLIC_KEY` (`-UpdatePublicKey`).
3. From the clean, committed worktree: `php artisan edge:build-package D:\out\BingooEdge-<v> --vendor-from=D:\build\edge-src\vendor
   --php-runtime=<php dir> --gateway=<nginx.exe> --signing-key-file=D:\secure\edge-update-signing.key`.
   A release build refuses a dirty tree, a missing `--vendor-from`, a closure whose `composer.lock` differs, or a closure
   installed with dev packages. `edge:audit-package` verifies the result; the manifest records `vendor_source`.

## Certifying a lab appliance (Administrator)

`Invoke-EdgeCertification.ps1 -Phase Preflight|Tasks|Crash|PreReboot|PostReboot|Lan|Security|Health|Report -InstallRoot … -EvidenceDir …`
collects the certification evidence (task definitions, supervisor restarts, reboot auto-start timings, LAN-without-WAN
reachability, secret/ACL inspection, health) into JSON; `Test-EdgeCashierTrust.ps1` runs on the cashier PC and proves
the branch-CA trust chain with full certificate validation. `scripts/edge/appliance/include-probe.php` (auto-prepended
via `PHP_INI_SCAN_DIR`) proves a runtime executes only files from the installed package. See
`docs/status/edge-p5-physical-certification.md`.

## Windows service model

Every process is a **Windows Scheduled Task**: boot start, restart 999× every minute, a restricted service account
(`NT AUTHORITY\LOCAL SERVICE` by default — never SYSTEM), non-elevated, no secret on any command line. Every task
but one runs `php <InstallRoot>\artisan <edge:command>` (the launcher resolves the active runtime version and the
appliance configuration directory).

| Task | Process | Responsibility | Singleton |
|---|---|---|---|
| `BingooEdgeWeb1..N` | `edge:local:serve --worker=N` | loopback PHP web backends (127.0.0.1:8090+) | the listen port |
| `BingooEdgeGateway` | `nginx.exe` | **the LAN listener**: HTTPS 443 with the branch-CA certificate → backends; 80 redirects | the listen port |
| `BingooEdgePrintWorker` | `edge:local:print-worker` | prints to **network printers directly** (TCP 9100) in Local Mode | DB heartbeat row |
| `BingooEdgeAuthorityWorker` | `edge:local:authority-worker` | heartbeat, connection state machine, warm-standby freshness, outbox drain + reconciliation while local | DB singleton |
| `BingooEdgeSyncSender` | `edge:local:sync-send` (every 2 min) | drains the outbox to the Cloud | outbox lease |
| `BingooEdgeBackup` | `edge:local:backup` (hourly) | encrypted local backup | file lock |

Stop is cooperative (`Register-EdgeServices.ps1 -Action Stop`): workers finish the in-flight job/tick; nginx quits
gracefully. A crash restart preserves the database and the outbox (transactions roll back; leases expire).

## Configuration storage

| Item | Where |
|---|---|
| tenant/branch binding, device identity, activation epoch | local DB `edge_local_meta` (public identifiers) |
| device secret, DB credentials, `EDGE_LOCAL_APP_KEY`, recovery key, trust public keys | `<DataRoot>\config\appliance.env` — the ONLY secrets file; ACL: SYSTEM + Administrators full, service account read |
| Cloud endpoints, ports, LAN hostname/IP | `appliance.env` (non-secret keys) |
| TLS certificate + key | `<DataRoot>\certs\server.crt` / `server.key` (key ACL-restricted) |
| gateway config + service plan | `<DataRoot>\gateway\nginx.conf`, `service-plan.json` |
| logs | `<DataRoot>\logs\edge-YYYY-MM-DD.log` (30 days) |
| backups | `<DataRoot>\backups\*.enc` |
| runtime versions + active pointer | `<InstallRoot>\runtime\versions\<v>`, `<InstallRoot>\runtime\current` |
| layout (paths only) | `<InstallRoot>\appliance.json` |

No secret is ever hard-coded, printed, logged, or passed on a command line. Secrets enter through **files that are
read once and deleted** (`-DbPasswordFile`, `-RecoveryKeyFile`, `-PairingCodeFile`, `-EnrollmentCredentialFile`,
`-CertPfxPasswordFile`) or hidden prompts.

## First install

Prerequisites: Windows 10/11 Pro or Server, local MySQL 8 / MariaDB 10.4+ on 127.0.0.1 with a dedicated user and a
database named `bingoo_edge_*`, a DHCP-reserved IP, the branch CA (`New-EdgeBranchCA.ps1`) and a server certificate
exported as PFX (`New-EdgeServerCertificate.ps1 -ExportPfx -PfxPasswordFile …`). From the Cloud Offline Edge page:
a one-time **pairing code** for the branch and an **enrollment assertion** for the first local user.

```powershell
.\scripts\Install-EdgeAppliance.ps1 -PackageRoot D:\pkg\BingooEdge-0.1.0 -CloudUrl https://pos.example.com `
  -DbUser bingoo_edge -DbPasswordFile C:\secure\dbpw.txt -DbName bingoo_edge_gulberg `
  -PairingCodeFile C:\secure\code.txt -EnrollmentAssertionFile C:\secure\assertion.json -EnrollmentCredentialFile C:\secure\cred.txt `
  -EnrollmentPublicKey <base64> -UpdatePublicKey <base64> -RecoveryKeyFile C:\secure\recovery.key `
  -LanHostname bingoo-edge.local -LanIp 192.168.1.50 -CertPfx C:\secure\server.pfx -CertPfxPasswordFile C:\secure\pfx.txt
```

The installer: verifies the package → lays out install + data roots → writes `appliance.env` → `db-init` →
`pair` → `bootstrap-pull` → `enroll` → `gateway-cert` → `service-plan` → registers/starts the tasks → two warm
ticks → `health`. **Success = READY AS WARM STANDBY.** The appliance is never LOCAL_ACTIVE during install.

Then distribute the branch CA public certificate to the terminals and point them at `https://bingoo-edge.local`
(hosts-file or router DNS entry for the reserved IP).

## Operate

- **Health** — `.\scripts\Get-EdgeHealth.ps1 [-Json] [-Services]` or the **Status** link in the cashier header.
  One report: services, Cloud connection, authority state, binding, warm-standby freshness (config / stock /
  returns / supplier finance / purchase returns), outbox pending + permanent failures, last sync, DB, printers,
  backup recency, updater/version, gateway certificate. Never a secret.
- **Services** — `Register-EdgeServices.ps1 -Action Status|Start|Stop|Register|Unregister -PlanFile <DataRoot>\gateway\service-plan.json`.
- **Takeover / handback** — supervised only: `php <InstallRoot>\artisan edge:local:authority-status`,
  `edge:local:authority-takeover --confirm`, `edge:local:authority-handback` (blocked while shifts/tables/outbox are open).
- **Backup** — hourly automatically; `Backup-EdgeAppliance.ps1` on demand. Keep the recovery key **outside** the box.

## Update (signed)

```powershell
.\scripts\Update-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -PackageRoot D:\pkg\BingooEdge-0.2.0
```

Verifies the package hashes → refuses while LOCAL_ACTIVE (unless `-AllowLocalActive`) → cooperative stop →
`edge:local:update`: Ed25519 signature against the appliance's public key, tamper check of the staged bytes,
product/version/schema/target checks (no downgrade unless `EDGE_UPDATE_ALLOW_DOWNGRADE=true`; a package pinned to
another branch/device is refused), **pre-update backup**, atomic stage + `current` switch, forward-only schema
upgrade, pointer rollback on failure. The outbox and every local table are preserved. Then services start and
health prints.

## Recover on a fresh machine

Install the package on the new machine with the **same** `appliance.env` (device identity, `EDGE_BACKUP_RECOVERY_KEY` /
`_ID`), let `db-init` create the fresh database, then restore with `-PullConfig` (the Cloud configuration — products,
users, printers — must be present before the local state comes back):

```powershell
.\scripts\Restore-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge" -BackupFile D:\recover\<backup>.enc -Branch <id> -PullConfig
```

The restore refuses a backup of another branch (`RESTORE_WRONG_IDENTITY`), an incompatible schema, or unresolved
references; pending outbox events are preserved and sync after reconnect.

## Uninstall (safe by default)

```powershell
.\scripts\Uninstall-EdgeAppliance.ps1 -InstallRoot "C:\Program Files\Bingoo Edge"
```

Removes the runtime and the Windows tasks only. The local database, outbox, backups, configuration and
certificates are **preserved** and listed. Data removal is explicit:
`-DropDatabase -RemoveData -ConfirmPhrase 'REMOVE ALL BRANCH DATA'` — and the database drop **refuses while
unsynced events exist** unless `-ForceLosePending` is also typed.

## Printing (architecture lock)

- **Network (LAN) printers:** Online = the Cloud print path; Local Mode = the appliance's print worker prints
  **directly to the printer IP** (TCP 9100). There is **no second Edge agent** for a network printer and never two
  agents on one printer. Offline sales print KOT/receipt once locally; Cloud ingestion **never prints again**.
- **USB printers:** served by the ONE Bingoo Print Agent per host, which is Cloud-only today → **ONLINE_REQUIRED**
  for the pilot (choose pilot branches with network printers) until the dual-mode agent exists.

## Network requirements

- Branch Server on a **DHCP-reserved / fixed LAN IP**; terminals resolve `bingoo-edge.local` via hosts file or router DNS.
- HTTPS 443 (and 80 → redirect) open on the LAN to the Branch Server; 9100/TCP from the Branch Server to each network printer.
- Outbound HTTPS from the Branch Server to the Cloud (heartbeat, sync, refresh).
- Local MySQL/MariaDB on 127.0.0.1 only. A **UPS** for the Branch Server, the switch and the printers is strongly
  recommended: the appliance solves **WAN loss**, not LAN or power loss.
