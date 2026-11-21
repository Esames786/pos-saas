# Khatri Branch Server — installation worksheet (kit khatri-20261121)

Read with `khatri-morning-execution-sheet-2026-11-21.md` (what is allowed today) and the package `scripts\README.md`.
Rule for every step: the owner says GO immediately before it; no step touches the live Khatri tenant or any other tenant.
The pairing/bootstrap steps (§6) cannot run until the production Cloud carries the Edge endpoints (deployment pending).

## 0. Kit contents (verify on the USB)

| folder | what | check |
|---|---|---|
| `BingooEdge-0.8.3-edge\` | signed release package, commit 37731e16, unchanged | `package-manifest.json` sha256 `e62976f17b5098103c0d23724a02b3a87c895b1d6a6366b21d77088465537b13`; 9093 files |
| `BingooEdge-0.8.3-edge\scripts\` | `Install-EdgeAppliance.ps1`, `Register-EdgeServices.ps1`, `Install-EdgePrintWorkerTask.ps1`, `Install-EdgeSyncSenderTask.ps1`, `Install-EdgeBackupTask.ps1`, `Backup-/Restore-EdgeAppliance.ps1`, `New-EdgeBranchCA.ps1`, `New-EdgeServerCertificate.ps1`, `Test-EdgeCashierTrust.ps1`, `Get-EdgeHealth.ps1`, `Update-EdgeAppliance.ps1`, `Uninstall-*.ps1`, `README.md` | present |
| `mysql\` | MySQL 8.0.30 binaries + `my.ini.template` + `README-MYSQL.md` | `mysqld.exe --version` = 8.0.30 |
| `site-survey\Khatri-NetSurvey.ps1` | read-only LAN / printer / PC survey | runs with `-Phase online|offline` |
| `docs\` | this worksheet, morning sheet, operator instructions, printer pre-flight, survey prompts, pilot plan | present |

NOT in the kit, by rule: the update signing keystore/passphrase, any password, any Cloud credential, any tenant data. The appliance
verifies the package signature with the embedded public key; private material never leaves the owner's custody.

## 1. Values to decide and write here (owner)

| value | decision |
|---|---|
| Branch Server PC (name / MAC) | |
| Reserved LAN IP on the `.100` main gate (DHCP reservation preferred) | `192.168.100.___` |
| LAN hostname (installer `-LanHostname`) | `bingoo-edge.local` (default) |
| InstallRoot / DataRoot | `C:\Program Files\Bingoo Edge` / `C:\ProgramData\BingooEdge` (defaults) |
| MySQL: kit instance on 3306, or an existing 8.x | |
| Gateway ports | HTTPS `8443`, HTTP `80` (redirects) — or 443 if nothing else uses it |
| Device name on the Cloud (`-DeviceName`) | `KHATRI-BRANCH-SERVER-1` |
| Primary / backup operator (names only; enrolment later) | |

## 2. PC prerequisites (pass/fail)

Windows 10/11 64-bit · local admin for the service steps · ≥ 10 GB free · AC sleep = never, hibernate off · time zone + clock correct ·
ports 80/8443/8090/8091 free (3306 free or the kit instance uses 3307) · antivirus exclusion for InstallRoot/DataRoot (owner's call) ·
Online Print Agent may stay (see §8).

## 3. MySQL (GO, elevated for the service) — `mysql\README-MYSQL.md` steps 1–4

Result: service `BingooEdgeMySQL` running on `127.0.0.1:3306`, database `bingoo_edge_local`, user `bingoo_edge`, password in
`C:\BingooEdge\secrets\edge.pass` (the installer deletes it after reading).

## 4. Network (GO: router + firewall)

1. DHCP reservation for the PC's MAC on the main gate → the PC gets the reserved IP after `ipconfig /release; ipconfig /renew`.
2. Firewall rule (elevated), once the gateway path exists after install:
   `New-NetFirewallRule -DisplayName "Bingoo Edge gateway" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443,80 -Program "C:\Program Files\Bingoo Edge\gateway\nginx.exe" -Profile Private,Public`
3. Set the LAN connection profile to Private (Settings → Network → the Ethernet/Wi-Fi connection → Private).
4. Never open 3306, 8090 or 8091 on the LAN (loopback only).

## 5. Certificates (GO, on the PC, before the install)

```powershell
cd <USB>\BingooEdge-0.8.3-edge\scripts
.\New-EdgeBranchCA.ps1 -BranchName "Khatri-01" -OutDir C:\BingooEdge\ca          # prints the CA thumbprint; exports khatri-01-ca.crt (public)
# owner writes C:\BingooEdge\secrets\pfx.pass (one line) first:
.\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.100.___ -CaThumbprint <thumbprint> -OutDir C:\BingooEdge\certs -ExportPfx -PfxPasswordFile C:\BingooEdge\secrets\pfx.pass
```
Output: `C:\BingooEdge\certs\*.pfx` (installer `-CertPfx` + `-CertPfxPasswordFile`) and the public CA `.crt` for the cashier devices.
Fallback if the CA scripts cannot run: installer `-SelfSignedCert` (cashier devices then trust that self-signed certificate instead).

## 6. Appliance install (GO; needs internet AND the deployed Cloud — NOT before the production deployment)

Owner-written private files first (one line each, never typed on a command line): `C:\BingooEdge\secrets\edge.pass` (MySQL),
`C:\BingooEdge\secrets\pairing.code` (from Cloud `/settings/offline-edge`), `C:\BingooEdge\secrets\recovery.key` (owner-chosen recovery key,
escrowed on the Cloud by the installer), `C:\BingooEdge\secrets\enrol.jwt` (enrollment assertion from the Cloud). Then, elevated:

```powershell
cd <USB>\BingooEdge-0.8.3-edge\scripts
.\Install-EdgeAppliance.ps1 -PackageRoot <USB>\BingooEdge-0.8.3-edge `
  -DbHost 127.0.0.1 -DbPort 3306 -DbName bingoo_edge_local -DbUser bingoo_edge -DbPasswordFile C:\BingooEdge\secrets\edge.pass `
  -CloudUrl https://bingoopos.com -PairingCodeFile C:\BingooEdge\secrets\pairing.code -DeviceName KHATRI-BRANCH-SERVER-1 `
  -EnrollmentAssertionFile C:\BingooEdge\secrets\enrol.jwt -RecoveryKeyFile C:\BingooEdge\secrets\recovery.key `
  -LanHostname bingoo-edge.local -LanIp 192.168.100.___ -CertPfx C:\BingooEdge\certs\<file>.pfx -CertPfxPasswordFile C:\BingooEdge\secrets\pfx.pass `
  -HttpsPort 8443 -HttpPort 80
```
`-CloudUrl https://bingoopos.com` = **central domain; the pairing code carries the tenant** (the tenant domain `https://khatribiryani.bingoopos.com` answers `PAIR_REFUSED: HTTP 404 The route api/edge/pair could not be found` — found at Khatri 21 Nov 2026; `routes/edge.php` registers the Edge API under the central domain). The cashier/operator URLs stay on the tenant domain.
The installer: verifies the package signature → lays out InstallRoot/DataRoot → writes `appliance.env` (the only secrets file, restricted ACL)
→ `db-init` → `pair` → `bootstrap-pull` → gateway cert → service plan → registers the Scheduled Tasks under `NT AUTHORITY\LOCAL SERVICE`
(gateway, web workers, print worker, sync sender, authority worker, hourly backup) → `health`.
PASS = `Get-EdgeHealth.ps1` / `edge:local:health` reports `STANDBY_READY`, authority `standby`, lease holder `cloud`, problems none.

## 7. Cashier devices (after §6)

1. Trust the CA: double-click `khatri-01-ca.crt` → Install → Current User → Trusted Root Certification Authorities (or machine-wide, elevated).
2. Hosts line (elevated Notepad on `C:\Windows\System32\drivers\etc\hosts`): `192.168.100.___  bingoo-edge.local`.
3. Check: `scripts\Test-EdgeCashierTrust.ps1 -Hostname bingoo-edge.local -Port 8443` → green; browser `https://bingoo-edge.local:8443/edge/local/login` shows a valid padlock.
Devices on a `192.168.1.x` extender need the same hosts line (mDNS does not cross that router).

## 8. Online Print Agent coexistence (same PC allowed)

Edge prints directly to the LAN printers on TCP 9100 (network printers only); the Online Agent keeps serving the Online POS from the Cloud.
They share no port, file or database. The agent's keep-awake pokes can collide with an Edge send (one connection at a time on a thermal
printer) → Edge records a temporary failure and retries on its ladder (5/15/30/60/120 s). Acceptable; never run both against a printer
during a certification print. In Local Mode the Cloud creates no print job for Edge sales, so the agent never prints an Edge sale twice.
Do not stop the live agent during service hours for the install.

## 9. Backup and recovery (after §6)

- Hourly automatic dump by the backup task (`Install-EdgeBackupTask.ps1`, 60 min) into `<DataRoot>\backups` — check `Get-ChildItem` after the first hour.
- Manual: `scripts\Backup-EdgeAppliance.ps1` (owner runs it before any change); `scripts\Restore-EdgeAppliance.ps1` on a replacement PC with the recovery key
  (the owner keeps the recovery key off the PC; the Cloud escrows the branch material: `php artisan edge:recovery-key status --tenant=khatribiryani --branch=<id>` on the Cloud, ids only).
- Rollback of a failed install: `scripts\Uninstall-EdgeAppliance.ps1` (removes tasks + roots; the MySQL service stays unless removed per `README-MYSQL.md`); the Cloud device is revoked at `/settings/offline-edge` by the owner.

## 10. Install-day evidence

Installer transcript (it prints no secrets), `Get-EdgeHealth.ps1` output, the device id shown by pairing, the bootstrap snapshot id + ack,
`Get-ScheduledTask | Where TaskName -like 'Bingoo*'`, the firewall rule listing, a photo of the health page from a cashier device.
