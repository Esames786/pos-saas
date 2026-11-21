# Offline Edge — P5C HOME LAB certification (owner-approved initial home lab, 19–20 Sep 2026)

Owner approval (19 Sep): use the existing Windows development laptop as the INITIAL HOME LAB Edge server, the existing home
network (StormFiber + Wi-Fi extender) and the accepted TCP FakePrinter. **Not** approval to deploy, create live sales, activate a
live restaurant or begin P6. No reboot, no elevation, no cable pull, no router change without asking immediately beforehand.

Phase boundary: P5C is an initial home-lab proof — **not** final physical Windows certification, **not** the P6 business pilot.
Cloud writes while online; Edge is warm standby; supervised takeover only; `AUTO_FAILOVER_ENABLED=no`; no production tenancy.

## §0 Re-ground

```
EDGE_START_HEAD=57535a9 (= origin, WORKTREE_CLEAN=yes)   CURRENT_CANONICAL=243e01d (moved from be07a5a: 4 commits, 19 Sep)
```

| Canonical delta be07a5a → 243e01d | Edge relevance | Action |
|---|---|---|
| MANAGER-APPROVAL-COMBO-VOID-1: `app/Services/Sales/KotCancellationService.php` — cancelling ONE line of a combo no longer refuses the manager approval (server decides by the approval's shape, not by the resolved line count) | **Edge-relevant shared runtime**: `EdgeLocalPosService` executes this service for void/cancel with manager approval | **not merged in P5C** (would move the head off 623f887, the source of the accepted pilot release 0.6.0-edge). Reconcile + rebuild before the next release: `git merge-tree` = 0 conflicts. |
| docs, `ManagerApprovalComboVoidMySqlTest`, month work-log | excluded from the artifact | — |

## §1/§3 Home network — observed, read-only (uplink CONNECTED)

| Fact | Observation (19 Sep) |
|---|---|
| Active interface | Wi-Fi (Intel AX201), SSID **SMS5G**, 5 GHz ch 153, 802.11ax, signal 91 % |
| IPv4 / gateway / DNS / DHCP | **192.168.1.6/24**, gw 192.168.1.1, DNS 192.168.1.1, DHCP server 192.168.1.1 (lease 24 h) |
| 192.168.1.1 | StormFiber ONT/router (HTTP title "welcome"); its Wi-Fi is off (owner) |
| 192.168.1.2 | the extender's LAN address while uplinked (HTTP-only device, MAC 4c-d0-dd-c3-dc-9f; 9100/631/515/443/23 closed) — the two SSIDs belong to it |
| Alive LAN hosts | 3: router .1, extender .2, laptop .6 — **no second computer, no raw-9100 device** |
| Path to Internet | 192.168.1.1 → 202.163.100.245 (StormFiber) → … ; 8.8.8.8 reachable; DNS via 192.168.1.1 works |
| Name resolution | `DESKTOP-0024EPM` and `DESKTOP-0024EPM.local` resolve locally (mDNS/LLMNR); the router DNS does NOT know the hostname; `bingoo-edge.local` unresolvable without a hosts entry |
| Owner-observed OFFLINE state | uplink pulled → **192.168.2.15/24**, gw 192.168.2.254, no Internet: the extender stops bridging and hands out its own DHCP |
| Ports on the laptop | Laragon Apache holds **0.0.0.0:80 and :443** (owner's dev sites) → the LAB gateway uses **8443/8081**; 8090/8091 backends; 9100 FakePrinter; 9701 LAB Cloud |
| Firewall | Wi-Fi profile **Public**, inbound default block; allow rules exist only for Laragon Apache/nginx paths (`D:\laragon2\bin\nginx\nginx-1.22.0\nginx.exe`) and PhpStorm. The packaged nginx is byte-identical (sha256 `e007e5fee4fbf986…`) but runs from the InstallRoot path → **no inbound rule** yet |

**Compatibility of the 192.168.1.x → 192.168.2.x transition with the appliance contract:**

- Binding / identity / database: unaffected — the appliance binds to the branch and device UUID, never to an IP; backends listen on loopback; nginx listens on `0.0.0.0:8443`, so it serves whatever address the laptop holds. **No identity or DB rewrite is needed for a DHCP change.**
- Certificate / hostname: the pilot contract is "reserved IP + stable hostname". Here the IP is not stable across the uplink flip, so the **stable LAB endpoint is the mDNS name `DESKTOP-0024EPM.local`** (both states keep laptop and cashier on the same L2 behind the extender). The LAB certificate (LAB branch CA) carries SAN `DNS:DESKTOP-0024EPM.local, DNS:desktop-0024epm.local, DNS:DESKTOP-0024EPM, DNS:bingoo-edge.local, IP:192.168.1.6, IP:192.168.2.15, IP:127.0.0.1` — the two IPs are **LAB-only observations, not permanent addresses**.
- Cashier URL contract: `https://DESKTOP-0024EPM.local:8443` (non-443 because Apache owns 443 on this laptop; the built-in 80→443 redirect drops the port — a known non-443 LAB deviation, not fixed in P5C).
- Risk to test at the cable pull: both devices must re-lease into 192.168.2.x; during the transition one side may still hold a 192.168.1.x lease → a temporary LAN outage that is a **network** symptom, not an Edge defect. mDNS resolution on the cashier device (Windows 10+/iOS yes; Android partial) decides whether the hostname or the IP path is used.
- `NETWORK_TOPOLOGY_BLOCKED=no` (nothing on the router/extender was changed); the residual unknown is the cashier device's mDNS support.

## §5 FakePrinter

The accepted harness `tests/MySql/Support/fake_printer.php` runs as a persistent hidden listener on **127.0.0.1:9100** (same laptop as
Edge) → **application/transport receiver only**; it proves nothing about a LAN printer during WAN loss. The LAB tenant's printer
"LAB FakePrinter (TCP 9100)" (network, role both, `edge_capable`) points at it; the appliance reaches it (installed PHP raw connect
OK, 20 ms). `PHYSICAL_PRINT_CERTIFIED=no`. No second Print Agent; USB out of scope. Print jobs only arise from local sales, which
P5C forbids (no Local Mode), so the KOT/receipt/identity/no-duplicate proof is the accepted executable gate below, not a LAB print.

## §6/§8/§9 Release-shaped install on isolated LAB authority — DONE

| Item | Evidence |
|---|---|
| Package | `C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.6.0-edge` (commit 623f887, package_hash 681238fe…), re-audited: **PACKAGE OK — 8700 files**; bundled PHP 8.3.16 + nginx 1.22.0; real `--no-dev` vendor; keystore-signed update manifest (key id a2eaf8a3d3e66a48) |
| LAB Cloud | `php -S 127.0.0.1:9701` over **pos_lab_master_edge** (41 tables) + **pos_lab_tenant_edge** (172 tables) — created and migrated for the LAB; disposable tenant `edgehomelab`, branch 1, cashier user 1, terminal LAB-T1, 3 products with stock, cash method/till, printer mapping; accepted test authority (`APP_ENV=testing`, `EDGE_TESTING_ASSUME_ENTITLED`). The developer databases (`pos_saas_master_edge`, `pos_tenant_*`, `pos_test_*`) were not touched. `LAB_CLOUD_DB_ISOLATED=yes` |
| LAB Edge DB | **bingoo_edge_lab_local**, fresh, created by `edge:local:db-init`. `LAB_EDGE_DB_ISOLATED=yes` |
| Install dirs | InstallRoot `C:\Users\Dell\BingooEdgeLab\install\BingooEdge` (launcher, appliance.json, php\, gateway\, runtime\versions\0.6.0-edge + current), DataRoot `C:\Users\Dell\BingooEdgeLab\data\BingooEdge` (config\appliance.env = the only secrets file, certs\, gateway\, logs\, backups\) |
| Installer | `Install-EdgeAppliance.ps1` from the package, non-elevated, `-NoServices`: steps 1–8 completed (verify, layout, appliance.env, **db-init**, **pair** → device `210c73f8-9602-4c88-b304-1de0ae0a494b`, **bootstrap-pull** snapshot edge-bootstrap-v6 · 35 sections · config revision 1 · ack ok, **Cloud-escrowed recovery key** `brk_01m2xgk5n4wqc6yezzpabk9n0x`). Step 9 failed on the LAB PFX (see defects) → steps 9–13 re-driven with the installed launcher using the installer's own commands: `gateway-cert <pfx>`, `service-plan --write-gateway-config`, `authority-worker --max-ticks=2`, `health`. `FIRST_INSTALL=installer 1–8 + launcher 9–13 (one LAB PFX retry)` |
| Enrolment | Cloud-signed assertion (`EdgeEnrollmentIssuer`) → `edge:local:enroll` → 1 active local credential; credential file consumed |
| Backup | first encrypted backup `edge-backup-20260919_190156-ksrpwf.enc` (41 KB) under the Cloud-escrowed key |
| Workers | the plan's 7 entries (gateway, web 1–2, print worker, sync sender 2 min, authority worker, backup 60 min) run as **hidden user processes** started from the plan JSON with the plan's executables/arguments/working dirs (`Start-EdgeLabWorkers.ps1`). Why not Scheduled Tasks: non-elevated current-user tasks are `LogonType=Interactive` and would pop one console window per worker onto the owner's desktop; S4U is denied; `LOCAL SERVICE` needs an elevated shell. **Supervision / restart / AtStartup are not claimed** (`ADMIN_INSTALL=no`, `REBOOT_PERFORMED=no`). |
| Health | **`STANDBY_READY` — "Ready as warm standby — Cloud is serving this branch"**; authority `standby` / connection `online`, 38 consecutive heartbeat acks, `takeover_at=null`; gates LOCAL_DB_HEALTHY, CONFIG_COMPATIBLE, SCHEMA_COMPATIBLE, BRANCH_BINDING_VALID, LOCAL_USERS_READY, STOCK_AUTHORITY_READY, ENTITLEMENT_VALID, STANDBY_FRESH_ENOUGH all true, AUTHORITY_TAKEOVER_SAFE=false (Cloud holds the lease — correct); freshness config/stock/returnable/supplier_finance/purchase_return all `current`; outbox 0/0/0; print worker running, 1 edge-direct network printer; backup present; gateway https listening with the LAB certificate; runtime `packaged_artifact=true`, artifact commit 623f887, build_mode release; `auto_failover_enabled=false`; problems `[]`. `WARM_STANDBY_READY=yes`, `LOCAL_MODE_ACTIVATED=no` |
| LAB Cloud view | device `ready` slot 1 app 0.6.0-edge; 1 bootstrap snapshot; 1 active recovery key + 2 audits; authority lease holder `cloud`, edge_state `standby`, TTL 120 s; 0 sales, 0 print jobs |
| TLS | served chain = leaf + LAB CA; `openssl s_client … -CAfile lab-ca.crt` → **Verify return code 0**; curl with the LAB CA → 200 by `DESKTOP-0024EPM.local`, `desktop-0024epm.local` and `192.168.1.6` (Git's Schannel curl additionally needs `--ssl-no-revoke` because the LAB CA publishes no CRL — a client artifact); without the CA → refused (exit 60); .NET client without the CA → "Could not establish trust relationship" (validation is on). 8081 → 301 to https (port dropped, see deviation) |
| Hygiene | 23 process command lines (12 Edge) and 27 log files (157 KB: appliance logs, worker logs, installer transcript, LAB Cloud log) contain **0** occurrences of the device secret, local APP_KEY, recovery key or retired keys; task/plan arguments carry no secret shape; appliance holds only `EDGE_UPDATE_PUBLIC_KEY` (no signing key, keystore or passphrase under InstallRoot/DataRoot); `appliance.env`, `server.key`, backups: inherited user-profile ACL (SYSTEM, Administrators, Dell) — the LOCAL SERVICE-restricted ACL of the real install needs elevation; LAB secrets dir: Dell only |

### Defects found while building the LAB (all LAB tooling, none in the release)

1. **PFX unreadable** — OpenSSL 1.1.1 (Git) exports PKCS#12 with RC2-40; PHP 8.3 / OpenSSL 3 (`openssl_pkcs12_read`) refuses it → installer step 9 "Could not read the PFX". Fix: export with `-keypbe/-certpbe AES-256-CBC -macalg sha256`. The installer's own error message was correct; `New-EdgeServerCertificate.ps1` (Windows cert store) is unaffected.
2. **LAB CA with a duplicate Basic Constraints** — `openssl req -x509 -addext basicConstraints=…` ALSO emits the default config's basicConstraints → every verifier rejects the CA (error 20). Fix: CA from an explicit `-config` with `x509_extensions`.
3. LAB env file: Windows backslash paths do not survive `source` (nor sed's `\U`); the LAB keeps `C:/forward/slash` paths.
4. `Install-EdgeAppliance.ps1` consumes `-DbPasswordFile`, `-CertPfxPasswordFile`, `-RecoveryKeyFile` and the pairing code file (deletes after reading) — correct behaviour; re-runs need fresh files.

## §14 Gates run

| Gate | Result |
|---|---|
| Home network baseline | captured read-only (table above); `ONLINE_LAPTOP_IP=192.168.1.6`, `OFFLINE_LAPTOP_IP=192.168.2.15 (owner-observed, to be re-measured at the pull)` |
| LAN endpoint continuity | analysed: stable endpoint = mDNS hostname + multi-SAN LAB cert; to be exercised at the pull |
| Release package boot | health `runtime.packaged_artifact=true`, commit 623f887, release; CLI + web backends + gateway serve from the installed package |
| Restricted artifact boundary | `edge:audit-package` PACKAGE OK (boundary audit ok) |
| LAB Cloud / Edge DB isolation | yes / yes (dedicated `pos_lab_*` + `bingoo_edge_lab_local`; no developer/production DB referenced) |
| Warm standby health | STANDBY_READY, LOCAL_ACTIVE never |
| Cashier LAN access | **pending the cashier device** (no second device exists on the LAN) |
| FakePrinter TCP transport | green — 24 MySQL tests: EdgeNoDuplicatePrintAfterSyncMySqlTest 1 (offline sale prints KOT + receipt once locally; Cloud ingestion/ACK/handback enqueue no second job — APPLICATION duplicates = 0), EdgeLocalPrintDeliveryMySqlTest 11 (claim/lease/complete/retry/failure over the FakePrinter; job identity preserved), EdgeLocalPrintWorkerLifecycleMySqlTest 9, EdgeCashierPrintingHttpMySqlTest 3 — on the isolated test databases; raw-TCP send/ack uncertainty after a worker crash remains TRANSPORT_UNCERTAINTY (same KOT #/receipt no, never a new identity) |
| WAN-down / LAN-up, WAN restoration, no accidental takeover | **not run** — needs the owner's cable pull (§11/§12/§13) |
| Secret / log hygiene | 0 secret values in processes and logs; only the public update key on the appliance |

## Owner decisions needed before §10–§13

1. **Cashier device (§4):** which device will act as the cashier — a second Windows laptop/PC, or a phone browser for the preliminary
   LAN/HTTPS reachability test? A phone does not replace the second Windows PC for final P5 certification.
2. **Cashier trust:** install `C:\Users\Dell\BingooEdgeLab\evidence\lab-ca.crt` (public LAB CA, no key) as a trusted root on that
   device; the URL is `https://DESKTOP-0024EPM.local:8443/edge/local/login` (fallback by IP `https://192.168.1.6:8443` while online).
3. **Inbound firewall (elevated, one rule):** the Wi-Fi profile is Public and the packaged gateway has no allow rule, so a second device
   cannot reach 8443 yet. Exact operation for an Administrator PowerShell:
   `New-NetFirewallRule -DisplayName "Bingoo Edge LAB gateway" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443,8081 -Program "C:\Users\Dell\BingooEdgeLab\install\BingooEdge\gateway\nginx.exe" -Profile Public,Private`
   Alternative without elevation: run the gateway from the already-allowed, byte-identical `D:\laragon2\bin\nginx\nginx-1.22.0\nginx.exe`
   (LAB deviation — the gateway binary would not be the InstallRoot copy).
4. **Known limitation for §12:** the LAB Cloud runs on this laptop's loopback, so pulling the yellow cable never makes the Edge process
   see Cloud loss (its heartbeats stay acknowledged). §12 is therefore a connectivity-only proof (real Internet unreachable, LAN alive,
   cashier→Edge, Edge→FakePrinter, TLS still valid). Making Edge observe Cloud loss would need the LAB Cloud on a host wired to the
   StormFiber side (a second computer) or an owner-approved stop of the LAB Cloud process — neither is P6.

The yellow-cable disconnect request (§11) will be made only after 1–3 are in place and cashier access is proven. STOP.

## FINAL REPORT (P5C, interim — cashier and WAN steps pending)

```
EDGE_START_HEAD=57535a9   CURRENT_CANONICAL=243e01d (assessed; KotCancellationService fix pending reconcile)   FINAL_EDGE_HEAD=this docs commit   ORIGIN_EDGE_HEAD=this docs commit
HOME_LAB_APPROVED=yes   PHYSICAL_MACHINE=owner development laptop DESKTOP-0024EPM   ADMIN_INSTALL=no (non-elevated user install; no tasks)   REBOOT_PERFORMED=no
MAIN_ROUTER_WIFI=off   EXTENDER_SSIDS=2.4GHz+5GHz (Mohsin Cyber_Net + SMS5G)   EXTENDER_UPLINK=yellow Ethernet
ONLINE_LAPTOP_IP=192.168.1.6/24 gw 192.168.1.1   OFFLINE_LAPTOP_IP=192.168.2.15/24 gw 192.168.2.254 (owner-observed; not yet re-measured)
NETWORK_TOPOLOGY=StormFiber ONT (DHCP/DNS/NAT) -> yellow Ethernet -> extender (bridge while uplinked; own DHCP 192.168.2.x when not) -> SMS5G -> laptop
STABLE_EDGE_LAN_ENDPOINT=https://DESKTOP-0024EPM.local:8443 (mDNS name; multi-SAN LAB cert incl. both observed IPs)   NETWORK_TOPOLOGY_BLOCKED=no
CASHIER_DEVICE=pending owner   CASHIER_EDGE_ACCESS=pending (no second device; inbound firewall rule needed)
FAKE_PRINTER=running (accepted harness)   FAKE_PRINTER_LOCATION=same laptop, 127.0.0.1:9100   TCP_9100_TRANSPORT=reachable from the appliance; accepted harness gate 24 green (application duplicates 0)   PHYSICAL_PRINT_CERTIFIED=no
LAB_CLOUD_DB_ISOLATED=yes   LAB_EDGE_DB_ISOLATED=yes   PRODUCTION_TENANT_USED=no
RELEASE_PACKAGE=BingooEdge-0.6.0-edge (623f887, 681238fe…, PACKAGE OK)   FIRST_INSTALL=done (installer 1–8, launcher 9–13)   WARM_STANDBY_READY=yes (STANDBY_READY)
WAN_DISCONNECT_OWNER_APPROVED=not yet requested   WAN_DOWN_CLOUD_UNREACHABLE=not run   WAN_DOWN_LAN_ALIVE=not run   WAN_DOWN_CASHIER_TO_EDGE=not run   WAN_DOWN_EDGE_TO_FAKE_PRINTER=not run
WAN_RECONNECT=not run   STANDBY_RECOVERED=n/a
AUTO_FAILOVER_ENABLED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no   P6_STARTED=no
P0_OPEN=0   P1_OPEN=0
HOME_LAB_BLOCKERS=cashier device + LAB CA trust on it; inbound firewall rule (elevated) or approved gateway-path deviation; LAB Cloud on loopback (Cloud-loss not observable by Edge at the cable pull)
PHYSICAL_CERTIFICATION_REMAINING=admin install + LOCAL SERVICE tasks + ACLs, reboot, crash restarts, second Windows cashier PC, real raw-9100 thermal printer, WAN-down with Edge observing Cloud loss, signed update on the LAB box, replacement restore
READY_FOR_FULL_PHYSICAL_CERTIFICATION=no   READY_FOR_P6=no
```

---

## P5C — CASHIER LAN + TLS phase (owner directive 20 Sep 2026; CASHIER_DEVICE=second Windows laptop, firewall approved in principle)

### §1 Re-ground of the running LAB (no reinstall, no rebuild, no reset, no re-pairing)

7/7 plan workers alive (gateway, web 1–2, print worker, sync sender, authority worker, backup); LAB Cloud answering (`/up` 200,
unauthenticated heartbeat 401 in ~1 s); FakePrinter listening 127.0.0.1:9100; LAB Edge DB healthy; binding `edgehomelab` #1,
device `210c73f8…`; sync outbox 0/0/0. Nothing was restarted. Edge head `f9ff9d0` at the start of this phase.

### DEFECT FOUND BY THE HOME LAB — heartbeat lost-ACK desync (genuine, fixed)

| | |
|---|---|
| Symptom | at 20:47 UTC the appliance began missing every heartbeat although the LAB Cloud stayed healthy; after 4 misses the connection state machine walked ONLINE → CONNECTION_UNSTABLE → CONNECTION_LOST → **PREPARING_LOCAL** ("Cloud lease lapsed on the appliance clock — readiness gates decide; supervisor confirmation required") and stayed there for 30 minutes (73 consecutive misses). `authority_state` stayed **standby**, `takeover_at` null, `auto_failover=false` — the supervised gate held, **no Local Mode**. |
| Root cause | appliance `authority_heartbeat_seq=297`, Cloud lease `heartbeat_seq=298`: the Cloud applied beat 298 but its answer never reached the appliance (the single-threaded LAB `php -S` under load exceeded the 20 s client timeout — a WAN blip does the same). The appliance only advances its sequence on an ack, so it re-sent **298 forever**; `EdgeAuthorityLeaseService::heartbeat()` refused `seq <= current` as `STALE_HEARTBEAT` (409). Reproduced with the exact request: `{"sent_seq":298,"http":409,"failure_code":"STALE_HEARTBEAT"}`. The same class hits a replacement/restored appliance whose sequence comes from an older backup. |
| Consequence in production | a healthy Cloud reported as lost and the supervisor invited to a needless takeover; if approved, the lease protocol still fences the Cloud (no split brain), but the branch would run offline for no reason and every standby freshness signal would stop. |
| Fix (this tranche, small) | **Cloud** — `EdgeAuthorityLeaseService`: a beat with the SAME sequence as the lease is re-acknowledged idempotently (same view; lease row byte-identical: nothing extended, nothing moved); a LOWER sequence is refused as before, now via `EdgeStaleHeartbeatException`, and the 409 body carries the Cloud's `seq`. **Appliance** — `EdgeAuthorityLeaseClient` throws the typed `EdgeAuthorityRefusedException` (status + body); `EdgeAuthorityService::heartbeat()` adopts the Cloud's sequence on a `STALE_HEARTBEAT` that names one, so the next beat is acceptable (the refused beat still counts as a failure — the counters, not this code, drive the state machine). |
| Proof | `EdgeAuthorityLeaseHttpMySqlTest` (equal-seq replay → 200 with an identical lease row; lower seq → 409 carrying `seq`), `EdgeConnectionStateMachineMySqlTest::test_a_stale_heartbeat_refusal_resyncs_the_sequence_from_the_cloud_and_the_next_beat_acks`, plus the existing partition / worker-lifecycle / cashier-connection-state suites (all green: lease HTTP 2, partition 1, worker lifecycle 3, cashier connection-state 2, connection state machine 6 incl. the new resync test; fast Edge suite 130 tests green). **Live LAB proof:** the LAB Cloud serves the worktree, so the Cloud half was live immediately — the appliance (still the untouched 0.6.0-edge package, not restarted) acked at tick 385, connection state returned to **online** at 21:19:16 UTC, health `STANDBY_READY`, both sides at sequence 300. |
| Release impact | the appliance half rides only in the NEXT release build (0.6.0-edge is unchanged); the Cloud half heals old appliances on its own. |

### §2/§3 Cashier laptop baseline (owner-measured) and Edge gateway baseline

| | Cashier laptop (Windows 10 19045) | Edge laptop |
|---|---|---|
| SSID | SMS5G (owner) | SMS5G, profile Public |
| IPv4 / mask / gateway | **192.168.1.18** / 255.255.255.0 / 192.168.1.1 | **192.168.1.6** / 24 / 192.168.1.1 (re-measured, unchanged) |
| Same LAN | yes — 192.168.1.0/24 behind the extender; the Edge laptop holds an ARP entry for .18 (a4-c3-f0-77-61-90) |
| `ping 192.168.1.6` from the cashier | **timed out — expected**: the Edge laptop's inbound "Echo Request (ICMPv4-In)" rules are disabled on the Public profile, so ping is not a valid reachability test here (`Test-NetConnection` / `Resolve-DnsName` were also typed into CMD, not PowerShell) |
| Gateway | `nginx.exe` from **InstallRoot** (`…\install\BingooEdge\gateway\nginx.exe`) listening **0.0.0.0:8443** and :8081 |
| Second-device access | **PROVEN by the owner's screenshot**: the cashier laptop opened `https://desktop-0024epm.local:8443/edge/local/login` — mDNS resolved the name, TCP 8443 reached the InstallRoot nginx, the Bingoo Edge login page rendered. The browser shows "Not secure" only because the LAB CA is not yet trusted on that PC (step §5). |

### §4 Firewall — no new rule needed

Windows already holds inbound allow rules for the LAB gateway path: `nginx.exe` (TCP, any port, Public) and `nginx.exe` (UDP, any
port, Public) for `C:\users\dell\bingooedgelab\install\bingooedge\gateway\nginx.exe`. They were created by Windows' own
"Security Alert" prompt when the packaged nginx first listened — an owner consent on the desktop, not an agent action. Per the
directive: reachable → **no additional rule was created**, nothing elevated was run by this session. The auto-created rules are
broader than the LAB needs; an OPTIONAL narrowing (the owner's elevated decision, not required for P5C):

```
# narrow: keep only TCP 8443 from the local subnet on the LAB gateway path; disable the UDP twin
Get-NetFirewallRule -DisplayName "nginx.exe" | Where-Object { ($_ | Get-NetFirewallApplicationFilter).Program -like "*BingooEdgeLab*" -and ($_ | Get-NetFirewallPortFilter).Protocol -eq "TCP" } | Set-NetFirewallRule -LocalPort 8443 -RemoteAddress LocalSubnet -Profile Public
Get-NetFirewallRule -DisplayName "nginx.exe" | Where-Object { ($_ | Get-NetFirewallApplicationFilter).Program -like "*BingooEdgeLab*" -and ($_ | Get-NetFirewallPortFilter).Protocol -eq "UDP" } | Disable-NetFirewallRule
# rollback (remove both; Windows prompts again on the next listen)
Get-NetFirewallRule -DisplayName "nginx.exe" | Where-Object { ($_ | Get-NetFirewallApplicationFilter).Program -like "*BingooEdgeLab*" } | Remove-NetFirewallRule
```
`RemoteAddress LocalSubnet` follows the laptop's current subnet, so it survives the 192.168.1.x → 192.168.2.x flip at the cable pull.

### §5 Cashier TLS trust — prepared, awaiting the owner's approval to trust the LAB CA on the cashier PC

Transfer ONLY `C:\Users\Dell\BingooEdgeLab\evidence\lab-ca.crt` (public CA certificate, 1266 bytes). Never the CA key, server key,
PFX password, device secret, APP_KEY, recovery key or cashier credential file.

```
file sha256      : ada029a6283b7458eac4b005d2f96a9645b92e76babef0f8276e07c9401e595a
cert SHA-256     : 8C:49:0C:63:37:B4:99:63:02:DB:B7:F3:4C:20:C7:77:17:2F:8B:E2:53:86:7A:86:A6:92:5E:86:8E:DE:C4:48
cert SHA-1 ("Thumbprint" in Windows) : F7D3946939F77EBA74780AEDA3C9E3CE52F38A01
subject          : CN=Bingoo Edge HOME LAB CA, O=Bingoo Edge LAB (not production)   valid 2026-09-19 → 2028-12-22
served chain     : the gateway serves leaf + this CA (openssl verify against it = 0)
```
On the cashier PC (PowerShell): verify, then install per-user (no admin; Windows asks for confirmation) — or machine-wide from an
elevated shell:
```
Get-FileHash .\lab-ca.crt -Algorithm SHA256                      # must print ADA029A6…E595A
(New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 ".\lab-ca.crt").Thumbprint   # F7D3946939F77EBA74780AEDA3C9E3CE52F38A01
Import-Certificate -FilePath .\lab-ca.crt -CertStoreLocation Cert:\CurrentUser\Root      # per-user (or Cert:\LocalMachine\Root elevated)
# rollback after the LAB:
Get-ChildItem Cert:\CurrentUser\Root | Where-Object Thumbprint -eq "F7D3946939F77EBA74780AEDA3C9E3CE52F38A01" | Remove-Item
```
Kit alternative (import + hostname/IP HTTPS checks with FULL validation + evidence JSON): copy `scripts\Test-EdgeCashierTrust.ps1`
from the package and run
`.\Test-EdgeCashierTrust.ps1 -EdgeHost DESKTOP-0024EPM.local -EdgeIp 192.168.1.6 -CaCertPath .\lab-ca.crt -HttpsPort 8443 -EvidenceDir C:\EdgeLabEvidence`.
Then `https://DESKTOP-0024EPM.local:8443/edge/local/login` must show a valid padlock; log in with employee code `LAB2C5D` and the LAB
cashier credential kept in `C:\Users\Dell\BingooEdgeLab\secrets\cashier.pass` on the Edge laptop (never printed here); the POS page
must load. No TLS verification is disabled anywhere.

### §7 Yellow cable stays CONNECTED — no WAN disconnect requested in this phase.

```
LAB_CLOUD=running (127.0.0.1:9701, /up 200)   EDGE_GATEWAY=InstallRoot nginx 0.0.0.0:8443 + :8081   EDGE_WORKERS=7/7 alive (hidden user processes)
WARM_STANDBY=STANDBY_READY, authority standby/online (recovered 21:19:16 UTC after the heartbeat fix)
EDGE_ONLINE_IP=192.168.1.6   CASHIER_IP=192.168.1.18/24 gw 192.168.1.1   CASHIER_SSID=SMS5G (owner)   SAME_LAN=yes (192.168.1.0/24)
GATEWAY_8443_LISTENING=yes (0.0.0.0)   CASHIER_TCP_8443=yes (login page rendered on the cashier laptop; ICMP ping blocked by design)
FIREWALL_RULE_REQUIRED=no (Windows-prompt rules for the LAB gateway path already present)   EXACT_FIREWALL_COMMAND=none required (optional narrowing above)
ROLLBACK_COMMAND=Remove-NetFirewallRule (above)   FIREWALL_OWNER_APPROVED=in principle (not needed)   FIREWALL_RULE_APPLIED=none by this session
LAB_CA_FINGERPRINT_MATCH=yes — verified on the cashier PC (20 Sep 02:35 local): lab-ca.crt 1266 bytes, SHA-256 ADA029A6…E595A, thumbprint F7D3946939F77EBA74780AEDA3C9E3CE52F38A01 all equal to the Edge laptop copy
CASHIER_CA_TRUST=yes — owner imported lab-ca.crt into Cert:CurrentUserRoot on the cashier laptop (20 Sep 02:3x local; thumbprint F7D3…8A01, subject CN=Bingoo Edge HOME LAB CA); rollback recorded   CASHIER_HOSTNAME_RESOLUTION=yes (mDNS: desktop-0024epm.local → login page)   CASHIER_HTTPS_VALID=yes — after the CA import Chrome on the cashier laptop shows a clean connection indicator for desktop-0024epm.local:8443 (no "Not secure", no interstitial)
CASHIER_LOGIN=yes — /edge/local/status from the cashier laptop: authenticated=true, runtime_mode=branch_server, user LAB2C5D "Lab Cashier", branch 1, epoch 1, 4 permissions, order types dine_in/takeaway/quick_sale/delivery   CASHIER_POS_PAGE=yes — https://desktop-0024epm.local:8443/edge/local/pos rendered on the cashier laptop: "Bingoo Edge — Cashier POS", Home Lab Branch · Lab Cashier, Dine In, Lab Counter 1, Shift / Returns / Status / Synced / Logout, Lab Burger 100 · Lab Cola 30 · Lab Fries 50, Hold / Draft / Recall / Preview Bill / Review & Pay / Quick Report / Recent Prints (nothing punched or paid)
YELLOW_CABLE=connected   WAN_DISCONNECT_PERFORMED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no   P6_STARTED=no
```

### §6 result — cashier access proven end-to-end (20 Sep 2026, ~02:35–02:50 local)

1. LAB CA verified on the cashier laptop (length 1266, SHA-256 `ADA029A6…E595A`, thumbprint `F7D3…8A01`) and imported by the owner
   into `Cert:\CurrentUser\Root` (Windows Security Warning accepted = the owner's approval of this LAB-only trust).
2. Chrome on the cashier laptop: `https://desktop-0024epm.local:8443` shows a clean connection indicator — no "Not secure", no
   interstitial; TLS verification was never disabled anywhere.
3. `/edge/local/status` from the cashier laptop after login: `authenticated=true`, `runtime_mode=branch_server`, user `LAB2C5D`
   "Lab Cashier", branch 1, activation epoch 1, 4 permissions, order types dine_in / takeaway / quick_sale / delivery.
4. `/edge/local/pos` rendered the actual Bingoo Edge cashier screen (Home Lab Branch · Lab Cashier, Lab Counter 1, the three LAB
   products, Hold / Draft / Recall / Preview Bill / Review & Pay, Quick Report, Recent Prints, "Synced" badge). **Nothing was
   punched, held or paid** — the LAB stays in warm standby with the Cloud as writer.

### Online LAN baseline (recorded before any cable step) — `C:\Users\Dell\BingooEdgeLab\evidence\online-lan-baseline.json`

```
yellow cable      : connected            internet (8.8.8.8) : reachable from the Edge laptop
Edge laptop       : Wi-Fi SMS5G, 192.168.1.6/24, gw 192.168.1.1, DNS 192.168.1.1, profile Public, gateway listening 0.0.0.0:8443
cashier laptop    : Wi-Fi SMS5G, 192.168.1.18/24, gw 192.168.1.1; LAB CA trusted (CurrentUser\Root F7D3…8A01)
cashier -> Edge   : https://DESKTOP-0024EPM.local:8443 by mDNS name — valid TLS, login OK, POS page OK
appliance health  : STANDBY_READY; authority standby / connection online; 61 consecutive acks; takeover_at null; outbox 0/0/0;
                    print worker running; 2 web backends; problems []
Cloud lease       : holder cloud, edge_state standby, heartbeat_seq 384 (appliance and Cloud in step after the heartbeat fix)
```

### FINAL REPORT — CASHIER LAN + TLS phase

```
EDGE_HEAD=99b3afa (code: heartbeat fix) / this docs commit   LAB_CLOUD=running   EDGE_GATEWAY=InstallRoot nginx 0.0.0.0:8443 + :8081
EDGE_WORKERS=7/7 alive   WARM_STANDBY=STANDBY_READY (standby/online, 61 acks)
EDGE_ONLINE_IP=192.168.1.6   CASHIER_IP=192.168.1.18   CASHIER_SSID=SMS5G   SAME_LAN=yes
GATEWAY_8443_LISTENING=yes   CASHIER_TCP_8443=yes
FIREWALL_RULE_REQUIRED=no   EXACT_FIREWALL_COMMAND=none required (optional narrowing recorded)   ROLLBACK_COMMAND=recorded   FIREWALL_OWNER_APPROVED=in principle (unused)   FIREWALL_RULE_APPLIED=none by this session
LAB_CA_FINGERPRINT_MATCH=yes   CASHIER_CA_TRUST=yes (CurrentUser\Root, rollback recorded)   CASHIER_HOSTNAME_RESOLUTION=yes (mDNS)   CASHIER_HTTPS_VALID=yes
CASHIER_LOGIN=yes   CASHIER_POS_PAGE=yes
YELLOW_CABLE=connected   WAN_DISCONNECT_PERFORMED=no   LOCAL_MODE_ACTIVATED=no   PRODUCTION_MUTATED=no   P6_STARTED=no
```

STOP. The yellow-cable disconnect is NOT requested here: it needs the owner's separate approval to enter the WAN-disconnect
phase (connectivity tests only — no Local Mode, no offline sales). Known limitation for that phase: the LAB Cloud sits on the Edge
laptop's loopback, so the Edge process will keep acking heartbeats while the cable is out; "Cloud unreachable" can only be shown
against the real Internet, not against the LAB Cloud, unless the owner separately approves stopping the LAB Cloud process.
