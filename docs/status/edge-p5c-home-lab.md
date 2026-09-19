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
