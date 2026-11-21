# Khatri Branch Server — onsite installation steps for the signed 0.8.4-edge package (21 Nov 2026)

Package: `<USB>\BingooEdge-0.8.4-edge-CANDIDATE` (commit a23d1f26, `package-manifest.json` sha256 `3e3b88cb…`, `signing_key_id a2eaf8a3d3e66a48`).
NOT `BingooEdge-0.8.3-edge`. Every command below uses the parameter names of the scripts in that folder (`scripts\*.ps1`, read 21 Nov).
Who: **OWNER-ELEV** = owner, PowerShell "Run as administrator" · **OWNER-PS** = owner, normal PowerShell · **OWNER-BROWSER** · **OWNER-FILE** = the
owner writes a one-line file with Notepad (a secret is never typed on a command line, never pasted into chat). `<USB>` = the kit drive letter;
`192.168.100.___` = the reserved IP decided in step 1.2. The appliance ends the day as a WARM STANDBY; the counters keep selling on the Online POS.

## 0. Before leaving (Cloud side — the lead confirms each line; none of this is claimed done here)

| | lead confirms | why it blocks |
|---|---|---|
| 0.1 | ☐ Production Cloud carries the Edge server half (deployment runbook phase 1 done, `/edge/api/authority/heartbeat` answers 401, `migrate:status` 0 pending) | pair/bootstrap/heartbeat/STANDBY need it |
| 0.2 | ☐ `EDGE_FEATURE_ENABLED=true` on production and `offline_edge` attached to plan `khatri_restaurant` only (runbook phase 2.1–2.3) | the Offline Edge page and pairing code do not exist otherwise |
| 0.3 | ☐ The production Cloud has an Edge **enrollment signing key** (`EDGE_ENROLLMENT_SIGNING_KEY`, generated on "ENABLE KHATRI" per `khatri-offline-edge-enablement-procedure-2026-11-21.md` → "Enrollment signing key") and the lead hands the owner its **public** value for `-EnrollmentPublicKey`: `YAxK/qnFLShsMTiTd7PckNmteVrMJE3umljW9Zh9NJc=` (generated on production 21 Nov 11:40 PKT on "ENABLE KHATRI"; sha256 fingerprint 816a3ceeceb93f15; public, not a secret) | `edge:local:enroll` fails closed: "Edge enrollment public key/crypto not configured" — no Khatri doc or the deployment plan mentions this key |
| 0.4 | ☐ `-UpdatePublicKey` value = `8BBZRe6+XEhZFVXJPrM4D+kTOi7iv/AV1RztyMZZ9EQ=` (release signing PUBLIC key, sha256 fingerprint `a2eaf8a3d3e66a48` = `signing_key_id` in the 0.8.3 and 0.8.4 manifests; verified 21 Nov) | without it health reports "Update verification key not configured" and no signed update can ever be applied |
| 0.5 | ☐ Owner's separate installation approval for 0.8.4 + LAB certification decision (`<USB>\README-0.8.4-CANDIDATE.md` at the kit root — moved out of the package folder so the package audit stays clean: "NOT the held release") | the kit's own rule |
| 0.6 | ☐ Owner has: Khatri owner login, the Khatri `user_id` (`/users/<id>`), employee code set on that user, branch_id = 1 | enrolment assertion (`docs/operator-khatri/ENROLLMENT-KHATRI.md` §A) |

## 1. PC + network prerequisites (OWNER-PS unless marked; nothing here touches printers or the agent)

| # | who | command | expected | STOP if |
|---|---|---|---|---|
| 1.1 | OWNER | PC cabled to the main gate (`192.168.100.1`) LAN, never behind a `192.168.1.x` extender; DHCP reservation for the PC's MAC on the main router (owner's router UI) | — | PC can only reach a `.1.x` gateway → move the cable first |
| 1.2 | OWNER-PS | `ipconfig /release; ipconfig /renew; Get-NetIPConfiguration \| Select InterfaceAlias,IPv4Address,IPv4DefaultGateway` then `tracert -d 8.8.8.8` | IPv4 = the reserved `192.168.100.___`, gateway `192.168.100.1`; tracert: exactly ONE private hop before the first public hop | gateway `192.168.1.x` or two private hops before the PC's own gateway → the PC is behind an extender (double NAT) |
| 1.3 | OWNER-PS | `Test-NetConnection 192.168.100.206 -Port 9100`; same for `192.168.100.69` and `192.168.100.218` (connect only, nothing prints); `tracert -d 192.168.100.206` | `TcpTestSucceeded : True`, 1 hop each | any `False` → cabling/VLAN/isolation; Edge cannot print |
| 1.4 | OWNER-PS | `Test-NetConnection khatribiryani.bingoopos.com -Port 443`; browser: `https://khatribiryani.bingoopos.com/login` | `True`; padlock valid, login page loads | no Cloud → pairing impossible today |
| 1.5 | OWNER-PS | `systeminfo \| findstr /B /C:"OS Name" /C:"System Type"`; `Get-PSDrive C \| Select Free`; `Get-NetTCPConnection -State Listen -LocalPort 80,8443,8090,8091,3306 -ErrorAction SilentlyContinue` | Windows 10/11, x64; Free ≥ 10 GB; the port query prints nothing | a listener on 80/8443/8090/8091/3306 → identify the owning process (`OwningProcess`), owner decides; never stop the Online Print Agent |
| 1.6 | OWNER-ELEV | `powercfg /change standby-timeout-ac 0; powercfg /change hibernate-timeout-ac 0; powercfg /query SCHEME_CURRENT SUB_SLEEP STANDBYIDLE` | AC sleep = 0 (never) | — |
| 1.7 | OWNER-ELEV | `Get-NetConnectionProfile`; if `NetworkCategory : Public`: `Set-NetConnectionProfile -InterfaceIndex <InterfaceIndex from the same output> -NetworkCategory Private` | `Private` on the LAN adapter | — |
| 1.8 | OWNER-ELEV | hosts line on the PC itself (so its own browser can open the name): elevated Notepad → `C:\Windows\System32\drivers\etc\hosts` → add `192.168.100.___  bingoo-edge.local` | `ping -n 1 bingoo-edge.local` resolves to the reserved IP | — |
| 1.9 | OWNER-PS | `Get-Process node -ErrorAction SilentlyContinue \| Select Name,Id,Path` | the Online Print Agent (2.7.1) is running — LEAVE IT RUNNING all day | — |

## 2. MySQL 8.0.30 from the kit (`<USB>\mysql\README-MYSQL.md` steps 1–4, exactly as written there)

| # | who | command | expected | STOP if |
|---|---|---|---|---|
| 2.1 | OWNER-PS | `New-Item -ItemType Directory -Force C:\BingooEdge,C:\BingooEdge\secrets \| Out-Null; Copy-Item -Recurse <USB>\mysql\mysql-8.0.30-winx64 C:\BingooEdge\mysql; Copy-Item <USB>\mysql\my.ini.template C:\BingooEdge\mysql\my.ini; & C:\BingooEdge\mysql\bin\mysqld.exe --version` | `8.0.30` | an existing MySQL on 3306 (1.5) → owner decides: reuse it, or set `port=3307` in `my.ini` and later `-DbPort 3307` |
| 2.2 | OWNER-PS | `& C:\BingooEdge\mysql\bin\mysqld.exe --defaults-file=C:\BingooEdge\mysql\my.ini --initialize-insecure --console` | ends without `[ERROR]`; `C:\BingooEdge\mysql-data` created | any `[ERROR]` |
| 2.3 | OWNER-ELEV | `& C:\BingooEdge\mysql\bin\mysqld.exe --install BingooEdgeMySQL --defaults-file=C:\BingooEdge\mysql\my.ini; Set-Service BingooEdgeMySQL -StartupType Automatic; Start-Service BingooEdgeMySQL; Get-Service BingooEdgeMySQL; Get-NetTCPConnection -State Listen -LocalPort 3306` | `Running`; listener `127.0.0.1:3306` only | listener on `0.0.0.0` → `bind-address` wrong in `my.ini` |
| 2.4 | OWNER-FILE | write `C:\BingooEdge\secrets\root.pass` and `C:\BingooEdge\secrets\edge.pass` (one line each; also in the owner's password manager) | files exist | — |
| 2.5 | OWNER-PS | run README-MYSQL.md **step 4** verbatim (the `$sql` block piped to `mysql.exe -uroot -h127.0.0.1`, then `Remove-Variable`); check: `& C:\BingooEdge\mysql\bin\mysql.exe -u bingoo_edge -h127.0.0.1 --password="$((Get-Content C:\BingooEdge\secrets\edge.pass -Raw).Trim())" -e "SELECT 1"` then close that window | `1`; database `bingoo_edge_local`, user `bingoo_edge` | `Access denied` → repeat step 4 (root still passwordless only until it ran once) |

## 3. Certificates (OWNER-ELEV, from `<USB>\BingooEdge-0.8.4-edge-CANDIDATE\scripts`; needs the reserved IP)

```powershell
cd <USB>\BingooEdge-0.8.4-edge-CANDIDATE\scripts
.\New-EdgeBranchCA.ps1 -BranchName "Khatri-01" -OutDir C:\BingooEdge\ca
#  -> prints "CA thumbprint : <40 hex>"; writes C:\BingooEdge\ca\bingoo-edge-branch-ca.crt (PUBLIC; this file goes to the cashier devices)
#  OWNER-FILE now: C:\BingooEdge\secrets\pfx.pass (one line). The next script READS AND DELETES it.
.\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.100.___ -CaThumbprint <thumbprint from above> -OutDir C:\BingooEdge\certs -ExportPfx -PfxPasswordFile C:\BingooEdge\secrets\pfx.pass
#  -> "SAN : DNS=bingoo-edge.local, IP=192.168.100.___"; writes C:\BingooEdge\certs\bingoo-edge-server.pfx (+ bingoo-edge-server.crt)
```
STOP if: "Branch CA with thumbprint … not found" (wrong thumbprint) or "ReservedIp … is not a valid IP address". Fallback only if the CA scripts cannot run: installer `-SelfSignedCert` (LAB-only per the installer's own warning; owner decision).

## 4. Secret files the owner writes by hand (OWNER-FILE, Notepad, one line each, no trailing spaces, never on a command line)

| file | content | consumed by |
|---|---|---|
| `C:\BingooEdge\secrets\edge.pass` | MySQL password of `bingoo_edge` (already written in 2.4) | installer `-DbPasswordFile` — **deleted after reading** |
| `C:\BingooEdge\secrets\pfx.pass` | the PFX password — **write it AGAIN**: step 3 deleted the first copy | installer `-CertPfxPasswordFile` → `edge:local:gateway-cert --password-file` — deleted after reading |
| `C:\BingooEdge\secrets\recovery.key` | optional owner-chosen key (also in the password manager). The installer then warns verbatim: "A LOCAL -RecoveryKeyFile was given: that key is NOT escrowed by the Cloud. When pairing succeeds the Cloud-issued key becomes current (the local key is kept as a retired key)." Omit the parameter to skip this | installer `-RecoveryKeyFile` — deleted after reading |
| `C:\BingooEdge\secrets\pairing.code` | the one-time code from Cloud → Settings → Offline Edge → branch 1 → **Generate pairing code** (valid 15 min). Write it only when the installer prints "waiting up to 900 s for the one-time pairing code file" (step 5.2) | `edge:local:pair --code-file` |

Do NOT write the enrolment assertion yet (step 5.3 takes it, 15-minute validity).

## 5. Install-EdgeAppliance with the 0.8.4 folder (OWNER-ELEV, internet ON, ≈ 15 min)

```powershell
Start-Transcript -Path <USB>\evidence\install-transcript-0.8.4.txt        # the installer prints no secret
cd <USB>\BingooEdge-0.8.4-edge-CANDIDATE\scripts
.\Install-EdgeAppliance.ps1 -PackageRoot <USB>\BingooEdge-0.8.4-edge-CANDIDATE `
  -DbHost 127.0.0.1 -DbPort 3306 -DbName bingoo_edge_local -DbUser bingoo_edge -DbPasswordFile C:\BingooEdge\secrets\edge.pass `
  -CloudUrl https://khatribiryani.bingoopos.com -PairingCodeFile C:\BingooEdge\secrets\pairing.code -DeviceName KHATRI-BRANCH-SERVER-1 `
  -EnrollmentPublicKey <public value from 0.3> -UpdatePublicKey <public value from 0.4> `
  -RecoveryKeyFile C:\BingooEdge\secrets\recovery.key `
  -LanHostname bingoo-edge.local -LanIp 192.168.100.___ `
  -CertPfx C:\BingooEdge\certs\bingoo-edge-server.pfx -CertPfxPasswordFile C:\BingooEdge\secrets\pfx.pass `
  -HttpsPort 8443 -HttpPort 80
```
(Defaults kept: `-InstallRoot 'C:\Program Files\Bingoo Edge'`, `-DataRoot 'C:\ProgramData\BingooEdge'`, `-ServiceAccount 'NT AUTHORITY\LOCAL SERVICE'`, `-WebWorkers 2`, `-WebPortBase 8090`, `-PairingCodeWaitSeconds 900`, `-AppEnv production`. Not used today: `-NoServices`, `-NoGateway`, `-CurrentUserServices`, `-AllowHttpCloud`, `-SkipWarmSync`, `-SelfSignedCert`, `-EnrollmentAssertionFile` / `-EnrollmentCredentialFile` — see 5.3.)

| # | who | what you see | expected | STOP if |
|---|---|---|---|---|
| 5.1 | — | `[ 1] Verifying package` … `package OK: Bingoo Edge 0.8.4-edge (… build release, commit a23d1f26…)`; `[ 2]`–`[ 4]` layout, `appliance.env written`; `[ 5] edge:local:db-init` | no `TAMPERED`/`MISSING`; no "DEV package" warning; db-init completes (232 migrations) | `INSTALL FAILED` at any step — read the line, change nothing, call the lead |
| 5.2 | OWNER-BROWSER + OWNER-FILE | `[ 6] edge:local:pair` prints "waiting up to 900 s for the one-time pairing code file" → NOW generate the code on the Cloud page and write `pairing.code` | `Paired.` + `device_id : …`, `tenant : khatribiryani`, `branch_id : 1`; `[ 7]` "Bootstrap applied and acknowledged" with `snapshot …`, `config revision 1`, `ack : ok`; recovery-key provisioned; `[ 8] edge:local:enroll skipped (no assertion)`; `[ 9]` cert imported; `[10]` plan written; `[11]` tasks registered; `[12]` warm sync; `[13]` health | `pairing failed` (code expired/typo → generate a new code, re-run needs fresh secret files, see 11) · `bootstrap pull failed` · `the appliance is not bound` |
| 5.3 | OWNER-BROWSER → OWNER-FILE → OWNER-PS | first local user = the owner (`docs/operator-khatri/ENROLLMENT-KHATRI.md` §B–C): obtain the assertion JSON for the owner's `user_id`, branch 1 → save as `C:\BingooEdge\secrets\owner.json` → within 15 min: `& "C:\Program Files\Bingoo Edge\php\php.exe" "C:\Program Files\Bingoo Edge\artisan" edge:local:enroll C:\BingooEdge\secrets\owner.json --type=pin` → type the Edge PIN at the hidden prompt (≥ 6 digits, not all the same, not 123456) → delete `owner.json` | `Edge credential set. User #<id> may now log in locally (version 1).` | "public key/crypto not configured" → 0.3 missing; "bad_signature"/expired → fresh assertion |
| 5.4 | OWNER-PS | `Stop-Transcript` | transcript on the USB | — |

Why 5.3 is a separate step: the installer runs `edge:local:enroll … --no-interaction` with the default `--type=password`; without `-EnrollmentCredentialFile` the hidden prompt returns empty and the install fails at step 8 ("An Edge password must be at least 8 characters"). The interactive PIN enrolment after install is the documented operator path.

## 6. Health, firewall, scheduled tasks, Cloud device page

| # | who | command | expected | STOP if |
|---|---|---|---|---|
| 6.1 | OWNER-ELEV | `& "C:\Program Files\Bingoo Edge\scripts\Backup-EdgeAppliance.ps1" -InstallRoot "C:\Program Files\Bingoo Edge"` (first backup now; the hourly task repeats it) | a backup row; no error | error → report, continue (not a sale blocker) |
| 6.2 | OWNER-ELEV | `& "C:\Program Files\Bingoo Edge\scripts\Get-EdgeHealth.ps1" -InstallRoot "C:\Program Files\Bingoo Edge" -Services` | `[STANDBY_READY]  Ready as warm standby — Cloud is serving this branch`; `Runtime Edge 0.8.4-edge … packaged`; `Binding tenant khatribiryani · branch #1 · users 1`; `Authority standby · connection …`; `Gateway nginx :8443 · listening · cert present`; `Backup key ok`; `Update … verify key ok`; `Auto failover no`; `No problems.`; task states Ready/Running | `DEGRADED` with any problem line other than a printer momentarily busy → send the output to the lead, change nothing; `NOT_BOUND` → 11 |
| 6.3 | OWNER-ELEV | `New-NetFirewallRule -DisplayName "Bingoo Edge gateway" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443,80 -Program "C:\Program Files\Bingoo Edge\gateway\nginx.exe" -Profile Private,Public`; `Get-NetFirewallRule -DisplayName "Bingoo Edge*"` | rule listed, Enabled True (3306/8090/8091 stay loopback — never opened) | — |
| 6.4 | OWNER-PS | `Get-ScheduledTask \| Where-Object TaskName -like 'Bingoo*' \| Select TaskName,State` | gateway, web ×2, print worker, sync sender, authority worker, backup — Ready/Running, principal `LOCAL SERVICE` | a task Disabled/missing → `Get-EdgeHealth.ps1 -Services` output to the lead |
| 6.5 | OWNER-BROWSER | Cloud → Settings → Offline Edge → devices | `KHATRI-BRANCH-SERVER-1`, last heartbeat < 1 min, lease holder cloud | no heartbeat after 2 min → health output to the lead |

## 7. Cashier devices: trust + health URL (each Windows counter; phones by IP per `docs/operator-khatri/LAN-REACH-CHECK.md`)

| # | who | command | expected |
|---|---|---|---|
| 7.1 | OWNER-ELEV on the device | hosts line `192.168.100.___  bingoo-edge.local` (elevated Notepad, `C:\Windows\System32\drivers\etc\hosts`) | `ping -n 1 bingoo-edge.local` resolves |
| 7.2 | OWNER-ELEV on the device | `& <USB>\BingooEdge-0.8.4-edge-CANDIDATE\scripts\Test-EdgeCashierTrust.ps1 -EdgeHost bingoo-edge.local -EdgeIp 192.168.100.___ -CaCertPath C:\BingooEdge\ca\bingoo-edge-branch-ca.crt -HttpsPort 8443 -EvidenceDir <USB>\evidence\cashier-<device-name>` (copy the `.crt` to the USB first; elevated = CA into LocalMachine\Root, non-elevated = CurrentUser\Root) | `health(https by name)=200 login=200 pos(guest)=302 cert-warning-free=True`, exit 0; `cashier-trust.json` written. `CASHIER TRUST FAILED` → hosts/CA/firewall, never bypass |
| 7.3 | OWNER-BROWSER on the device | `https://bingoo-edge.local:8443/edge/local/health` | padlock valid, JSON `"status": "STANDBY_READY"` |
| 7.4 | OWNER-PS on the device | from `docs/operator-khatri`: `powershell -NoProfile -ExecutionPolicy Bypass -File .\Install-Shortcuts.ps1` | three desktop shortcuts (BINGOO Online / BINGOO Offline / Branch Server Status) |

## 8. Owner-control status page — LOOK ONLY (no takeover today)

| # | who | do | expected | STOP if |
|---|---|---|---|---|
| 8.1 | OWNER-BROWSER | `https://bingoo-edge.local:8443/edge/local/login` → employee code + the Edge PIN from 5.3 | lands on the status page | "Permission denied" → user not enrolled / no `tenant.offline-edge.security` (owner role has it) |
| 8.2 | OWNER-BROWSER | `https://bingoo-edge.local:8443/edge/local/pos/health` | the **Selling authority** block: Internet/Cloud `online`, Who sells `Cloud`, pill **STANDBY READY**, takeover gates table, freshness, sync 0/0/0, printers reachable; the two buttons **Switch to Offline** (enabled) and **Return to Online** (disabled) are visible | buttons missing → permission (ENROLLMENT-KHATRI.md A.4); pill DEGRADED → lead |
| 8.3 | — | **Do not press either button.** Log out. | — | — |

## 9. Evidence to USB (`<USB>\evidence\`)

`install-transcript-0.8.4.txt` (5) · `& "C:\Program Files\Bingoo Edge\scripts\Get-EdgeHealth.ps1" -Json > <USB>\evidence\health-0.8.4.json` · device id + bootstrap snapshot id (from the transcript) · `Get-ScheduledTask | Where TaskName -like 'Bingoo*' | Select TaskName,State > <USB>\evidence\tasks.txt` · `Get-NetFirewallRule -DisplayName "Bingoo Edge*" > <USB>\evidence\firewall.txt` · each `cashier-<device>\cashier-trust.json` (7.2) · photo of the status page (8.2) and of the Cloud device row (6.5) · the survey folders · the owner's written decisions (reserved IP, MySQL choice, operators' names).

## 10. What does NOT happen today

No **Switch to Offline** / `edge:local:authority-takeover`, no Local Mode, no cashier sale on Edge, no WAN cable pulled, no **Return to Online** / handback. No change to Khatri printers, routing, terminals, menu, the Online Print Agent 2.7.1 (stays running on its PC) or any other tenant. `AUTO_FAILOVER_ENABLED=no` stays (health prints `Auto failover: no (supervised takeover only)`). Never press the Cloud "Test print". If the shop internet drops tonight the counters are exactly where they were yesterday.

## 11. Rollback (OWNER-ELEV; the counters never stopped selling online)

```powershell
& "C:\Program Files\Bingoo Edge\scripts\Uninstall-EdgeAppliance.ps1" -InstallRoot "C:\Program Files\Bingoo Edge"
#  stops + unregisters the Bingoo* tasks and removes the runtime; PRESERVES C:\ProgramData\BingooEdge (config, certs, backups) and the local DB.
#  Only if the owner wants everything gone (typed phrase is mandatory; refuses while events are unsynced unless -ForceLosePending):
& "C:\Program Files\Bingoo Edge\scripts\Uninstall-EdgeAppliance.ps1" -InstallRoot "C:\Program Files\Bingoo Edge" -DropDatabase -RemoveData -ConfirmPhrase 'REMOVE ALL BRANCH DATA'
```
Then OWNER-BROWSER: Cloud → Settings → Offline Edge → device `KHATRI-BRANCH-SERVER-1` → **Revoke**. MySQL removal only if the install is abandoned: `Stop-Service BingooEdgeMySQL; & C:\BingooEdge\mysql\bin\mysqld.exe --remove BingooEdgeMySQL`. A re-run of step 5 after a failed install needs: the runtime version dir and `config\appliance.env` gone (uninstall above), and FRESH secret files (the installer deleted `edge.pass`, `pfx.pass`, `recovery.key`; a new pairing code). Firewall rule: `Remove-NetFirewallRule -DisplayName "Bingoo Edge gateway"`.
