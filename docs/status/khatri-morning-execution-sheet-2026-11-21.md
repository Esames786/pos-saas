# Khatri — morning execution sheet (21 Nov 2026, owner on site)

One page. Everything in §1 is read-only and safe. Everything in §2 needs the owner's explicit GO on site and is a change to the
Khatri PC only (never to production). Everything in §3 is impossible today regardless of GO, because the production Cloud does
not yet carry the Edge endpoints. Nothing today touches the live Khatri tenant, its printers' routing, the running Online Print
Agent or any other tenant.

USB kit: `C:\Users\Dell\.bingoo-edge-release\kits\khatri-20261121\` (copy the whole folder to the USB stick). Contents:
`BingooEdge-0.8.3-edge\` (signed package, unchanged), `mysql\` (MySQL 8.0.30 binaries + `my.ini.template` + `README-MYSQL.md`),
`site-survey\Khatri-NetSurvey.ps1`, `docs\` (this sheet, install worksheet, operator instructions, printer pre-flight, survey prompts).
No keystore, passphrase, credentials or tenant secrets are in the kit (verified by a scan before copying).

## 1. Safe immediately (no approval needed)

| # | do | pass |
|---|---|---|
| 1 | Copy `site-survey\Khatri-NetSurvey.ps1` to the PC desktop; run `powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Desktop\Khatri-NetSurvey.ps1" -Phase online` | ends with `DONE.`; folder `Desktop\khatri-netsurvey\online-*` |
| 2 | Printers: `Test-NetConnection 192.168.100.206 -Port 9100`, same for `.69` and `.218` (connect only, nothing prints) | `TcpTestSucceeded : True`; tracert = 1 hop |
| 3 | Internet + Cloud: `Test-NetConnection khatribiryani.bingoopos.com -Port 443`; open `https://khatribiryani.bingoopos.com/login` in the browser | `TcpTestSucceeded : True`; padlock valid; login page loads |
| 4 | PC inventory from the report: Windows version/64-bit, free disk, sleep setting, IPv4/gateway, network profile | Windows 10/11 64-bit; ≥ 10 GB free; gateway `192.168.100.x` |
| 5 | Existing MySQL? `Get-Service | Where-Object Name -like '*mysql*'`; `Get-NetTCPConnection -State Listen -LocalPort 3306` | note: none (install from kit later) or version + port (decide later; never stop it) |
| 6 | Online Print Agent inspection: `Get-Process node`; find the START-PRINTING / STOP-PRINTING folder; note the version shown in its window title or config folder name (never open or copy its config) | running or not; version noted; left running |
| 7 | Cashier devices: from each device's Wi-Fi/LAN details note IP, gateway, Wi-Fi name; `ping <edge-pc-ip>` from the device (a fail is not conclusive, Windows may block ping) | table: device, IP, subnet `.100` or `.1.x`, gateway |
| 8 | Offline LAN proof: owner pulls the WAN cable at the main gate → run the script with `-Phase offline` → wait for `DONE.` → plug back | offline report: `INTERNET = False`, printers still `reachable`, PC kept its IP |
| 9 | Record the routers: main gate model + LAN IP, each extender model + whether it hands out `.1.x` (router mode) | written list |

## 2. Needs the owner's explicit GO on site (Khatri PC only)

| # | change | pass | note |
|---|---|---|---|
| 10 | MySQL 8.0.30 from the kit: extract to `C:\BingooEdge\mysql`, `my.ini` from the template, `mysqld --initialize-insecure`, register as a Windows service (elevated), set the root password and create the `bingoo_edge` user from SQL files the owner writes (never typed on a command line) | `Get-Service BingooEdgeMySQL` Running; `mysql -u bingoo_edge -p` from a password file connects on `127.0.0.1:3306` | skip if the PC already runs a MySQL 8 the owner wants to share (then only the user/DB) |
| 11 | Fixed LAN IP: DHCP reservation on the main gate for the PC's MAC (preferred) or a static IP outside the DHCP range | PC keeps the same `.100.x` after a reboot | router change = owner's own hand |
| 12 | Firewall rule for the Edge gateway, inbound TCP 8443 + 80 for `<InstallRoot>\gateway\nginx.exe` (elevated) | rule listed by `Get-NetFirewallRule -DisplayName "Bingoo Edge*"` | can wait until the install day |
| 13 | Branch CA + server certificate on the PC (`scripts\New-EdgeBranchCA.ps1`, `scripts\New-EdgeServerCertificate.ps1`, SAN = `bingoo-edge.local` + the reserved IP) | `.crt` of the CA exported for the cashier devices | can wait until the install day; needs the reserved IP from item 11 |
| 14 | Cashier devices: trust the CA `.crt`, add a hosts line `<reserved ip>  bingoo-edge.local` | `https://bingoo-edge.local:8443` shows a green padlock once the gateway runs | after item 13 |

Allowed today per the owner's D-C: nothing in this section runs without a separate GO, and none of it touches production.

## 3. Impossible today, whatever the GO (blocked by the Cloud deployment)

| what | why |
|---|---|
| `Install-EdgeAppliance.ps1` pairing step, bootstrap-pull, enrolment, first heartbeat, STANDBY_READY | production has only pair + bootstrap endpoints; heartbeat / sync / config-refresh / authority are not deployed → the appliance would pair and then never reach standby, and could never sync |
| enabling `offline_edge` on the Khatri plan, issuing a pairing code or enrollment assertions | production tenant mutation, D-B says prepare only |
| any Local Mode, takeover, cashier sale on Edge | nothing to take over; forbidden anyway without the LAB acceptance (D-D) |

## 4. Exact pass/fail checks

| area | check | PASS | FAIL → consequence |
|---|---|---|---|
| printers | TCP 9100 connect from the PC, each printer | `reachable`, 1 hop, ping < 10 ms | `timeout` → cabling / VLAN / isolation to fix before any install |
| internet | DNS + TCP 443 to the Cloud, browser padlock | both OK | no Cloud → pairing impossible on install day; note when internet is reliable |
| PC | 64-bit Windows 10/11, admin account, ≥ 10 GB free, AC sleep = never, ports 80/443/8443/8090/8091 free | all yes | sleep on → set to never (GO); port in use → identify the owner process |
| MySQL | none, or an existing 8.x whose owner is known | decided which to use | 5.x / MariaDB / unknown owner → install the kit instance (GO) |
| certificate | hostname `bingoo-edge.local` + reserved IP decided | values written on the worksheet | no reserved IP → item 11 first |
| pairing / bootstrap | NOT TODAY. When run: pairing returns a device id, bootstrap manifest ack ok, `edge:local:health` = `STANDBY_READY` | — | blocked by §3 |
| LAN without WAN | offline report: INTERNET False, printers reachable, IP kept, gateway answering | all yes | DHCP or gateway dies without WAN → router unsuitable for offline service |

## 5. Evidence to capture before leaving

1. The two report folders `Desktop\khatri-netsurvey\online-*` and `offline-*` copied to the USB stick.
2. Photos: each printer's label and IP sticker, the main gate router label, each extender.
3. The cashier device table (item 7) and the router list (item 9).
4. PC facts: name, MAC, IPv4, gateway, Windows edition, free disk, existing MySQL yes/no, agent version.
5. Owner decisions written on the worksheet: PC placement (main LAN), reserved IP, hostname `bingoo-edge.local`, which MySQL, who is primary / backup operator (names only, no privileges assigned).
6. The internet window for the install day (pairing, bootstrap, enrolment need it).

## What the owner can do today, in one line

Survey, printers, internet, PC, agent, cashier devices and the WAN-pull LAN proof (§1); with GO: MySQL from the kit, reserved IP,
firewall rule, CA/cert, cashier trust (§2). Nothing Edge goes live, nothing pairs, nothing on production moves (§3).
