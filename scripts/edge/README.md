# Bingoo Edge — Branch Server LAN TLS foundation

`EDGE-RUNTIME-BOUNDARY-1 (J/K)` — **foundation/skeleton only.** These scripts establish the
branch-local TLS trust model for the future Windows Branch Server appliance. They are safe,
syntactically verified, and **do not deploy anything**. Full certificate binding + terminal
provisioning is completed by the appliance installer in a later sprint.

## Pilot contract (locked)

- **First offline pilot = managed Windows POS terminals** on a branch LAN.
- **Branch Server** runs on a **fixed / DHCP-reserved LAN IP**.
- **TLS** = a **branch-local CA** + a **Branch Server certificate** issued by that CA. Terminals
  trust the CA public certificate; the server presents its cert. **No plain HTTP as the normal mode;
  no disabling of certificate verification.**

## Hostname / IP contract (K)

`config('edge.lan')` holds the contract: `hostname` (default `bingoo-edge.local`), `reserved_ip`,
and `name_mechanism`.

> **Windows practicality:** mDNS `.local` resolution is **not reliable on every Windows LAN** (it
> depends on the Bonjour/Apple mDNS responder being present, and some routers/AV block it). The
> **pilot mechanism is therefore a DHCP-reserved IP + a `hosts` file entry (or router DNS)** on each
> managed terminal, not mDNS. The server certificate SAN covers **both** the hostname and the
> reserved IP so terminals can connect by either without a certificate warning.

Recommended per-branch values (set in the Branch Server `.env`):

```
EDGE_LAN_HOSTNAME=bingoo-edge.local
EDGE_LAN_IP=192.168.1.50
EDGE_LAN_NAME_MECHANISM=hosts_file   # hosts_file | router_dns | mdns
```

## Scripts

1. **`New-EdgeBranchCA.ps1`** — creates the per-branch root CA. The private key is **non-exportable**
   by default and stays on the Branch Server; only the **public** CA `.crt` is exported for
   distribution to terminals.
   ```powershell
   .\New-EdgeBranchCA.ps1 -BranchName "Gulberg-01" -OutDir C:\bingoo-edge\ca
   ```
2. **`New-EdgeServerCertificate.ps1`** — issues the Branch Server TLS certificate from that CA, with a
   SAN of `DNS=<hostname>` + `IP=<reserved_ip>`.
   ```powershell
   .\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.1.50 `
       -CaThumbprint <ca-thumbprint> -OutDir C:\bingoo-edge\certs
   ```

Both support `-WhatIf` (dry run) and validate their inputs (IP format, validity range, CA presence).

## Renewal

- **Server certificate** (default 730 days, max 825 - iOS/macOS refuse longer server certificates even from a trusted CA; `-ValidDays`): re-run `New-EdgeServerCertificate.ps1` with the same CA
  before expiry and re-bind it to the web listener. Terminals need no change (same CA).
- **CA** (default 5 yr): re-run `New-EdgeBranchCA.ps1`, re-issue the server cert, and **re-distribute
  the new public CA cert** to terminals. Plan CA rotation well before expiry.

## Hostname / IP change procedure

If the branch LAN hostname or reserved IP changes: update `.env` (`EDGE_LAN_*`), re-run
`New-EdgeServerCertificate.ps1` with the new SAN values, re-bind, and update each terminal's `hosts`
entry (or router DNS). The CA is unaffected.

## P4 — the Windows appliance scripts (12 Sep 2026)

The installer work listed as "later" is now here (see `appliance/README-INSTALL.md` for the operator guide):

| Script | Purpose |
|---|---|
| `Install-EdgeAppliance.ps1` | first install from a built package: verify → layout → `appliance.env` → db-init → pair → bootstrap-pull → enroll → gateway cert → service plan → services → warm sync → health |
| `Register-EdgeServices.ps1` | Register / Start / Stop (cooperative) / Unregister / Status of every Scheduled Task from the plan JSON (`edge:local:service-plan`) |
| `Update-EdgeAppliance.ps1` | signed update: verify → refuse while LOCAL_ACTIVE → stop → `edge:local:update` → start → health |
| `Uninstall-EdgeAppliance.ps1` | runtime + tasks only by default; data removal needs the typed phrase; refuses while events are unsynced |
| `Backup-EdgeAppliance.ps1` / `Restore-EdgeAppliance.ps1` | on-demand encrypted backup / guarded restore (fresh machine) |
| `Get-EdgeHealth.ps1` | the ONE non-secret health report (+ `-Services` task states) |
| `New-EdgeServerCertificate.ps1 -ExportPfx` | issues the server cert EXPORTABLE and writes a PFX for `edge:local:gateway-cert` (PEM files for the nginx gateway) |
| `appliance/edge-launcher.php` | installed as `<InstallRoot>\artisan`: resolves the active runtime version + the appliance env dir |
| `appliance/appliance.env.template` | keys only — never a value |
| `Invoke-EdgeCertification.ps1` (P5) | lab certification evidence kit (admin): tasks, crash restarts, reboot auto-start, LAN-without-WAN, security/ACL inspection, health, report |
| `Test-EdgeCashierTrust.ps1` (P5) | cashier-PC branch-CA trust proof over HTTPS with full validation |
| `appliance/include-probe.php` (P5) | auto-prepend probe: which files a runtime process executed (release = only the installed package) |

The web listener choice is made: **nginx TLS gateway → loopback PHP backends** (`edge:local:serve`). Still physical
certification (not provable on a non-elevated dev box): task registration under the service account, reboot
auto-start, crash restart by the Task Scheduler, terminal CA distribution at a real branch.

**Never commit generated certificates or private keys.** These scripts write outputs to an
operator-chosen directory outside the repository.

## P5B — release custody + backup recovery authority (14 Sep 2026)

- `edge:update:keygen --keystore --passphrase-file` mints the release-signing key into an ENCRYPTED keystore on the release
  authority; `edge:build-package --signing-keystore --signing-passphrase-file` signs a release from it (plaintext key files are
  dev/test only). Keystore and passphrase files are forbidden in every artifact/package.
- Installer step 7 now runs `edge:local:recovery-key`: the branch backup recovery key is issued + escrowed by the Cloud recovery
  authority and pulled by the paired device (never typed in, never the appliance's secret alone). `Restore-EdgeAppliance.ps1
  -PullConfig` (or `-PullRecoveryKey`) pulls it on a replacement machine; `edge:local:restore --pull-recovery-key` is the CLI form.
- Cloud admins: `php artisan edge:recovery-key status|rotate --tenant=<code> --branch=<id> --reason=...` (ids + audit, never material).

## Edge 0.9 - packaging contract (stream E, 21 Nov 2026)

| Item | Contract |
|---|---|
| `Setup-BingooEdge.cmd` | shipped at the package ROOT (copy of `scripts/edge/Setup-BingooEdge.cmd`); the wizard ships in `app/scripts/edge/wizard/` (+ `scripts/wizard/`). Required in a 0.9+ release package. |
| `php/php.ini` | written by the builder: `extension_dir = "ext"`, `include_path = "."`, no build-host path, `curl.cainfo` / `openssl.cafile` = `"__BINGOO_PHP_DIR__\cacert.txt"`; CA bundle = `php/cacert.txt`. |
| `appliance/Set-EdgePhpIniPaths.ps1` | run by the installer/updater after copying `php\` to its final folder: `-PhpDir "<InstallRoot>\php"` replaces the token, checks `cacert.txt` + `ext\`, and proves `php.exe` trusts the bundle (exit 0 ready / 1 refused). Idempotent. |
| `mysql/` | optional MySQL 8.0 server runtime (`--mysql-runtime`, profile `server` by default): `mysqld`, `mysql`, `mysqladmin`, `mysqldump` + the DLLs they import, `share/`, licence (~85 MB); `--mysql-profile=full` keeps every `bin/`/`lib/` file. Never `my.ini`, data, debug, docs, tests. |
| `prereqs/VC_redist.x64.exe` | the official Microsoft VC++ 2015-2022 x64 runtime installer (`--vc-redist`; Authenticode Valid + Microsoft signer + COMPLETE Burn bundle - the Package Cache copy is a stub and is refused). Required in a 0.9+ release when `php/` or `mysql/` is bundled. |
| `Install-EdgePrereqs.ps1` | run FIRST by the wizard (install and update): detects `HKLM\...\VC\Runtimes\x64` Installed=1 and version >= the bundled installer, else verifies and silently installs it (`/install /quiet /norestart`). Exit 0 ok, 3010 ok + reboot later, 2 `-CheckOnly` missing, 1 refused/failed. |
| Task durations | no `[TimeSpan]::MaxValue` and no day count above 3650 in any packaged script (Windows 10 Task Scheduler); the build fails otherwise. |
| `-CloudUrl` | the central domain `https://bingoopos.com` in defaults and examples (a 0.9+ release build refuses anything else). |
