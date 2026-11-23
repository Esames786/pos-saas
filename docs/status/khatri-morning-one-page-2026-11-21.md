# Khatri — morning one-page (21 Nov 2026) · survey + install readiness

Kit: `BingooEdge-0.8.3-edge\` (signed, untouched) · `mysql\` (8.0.30 + README) · `site-survey\Khatri-NetSurvey.ps1` · `docs\` · `KIT-CONTENTS.txt`.
Rule: every ☐ marked **GO** needs the owner's word on site. Nothing here touches the live Khatri tenant, its printers' routing, the running Online Print Agent or any other tenant.

## A. Pre-flight (read-only, safe now)
☐ `powershell -NoProfile -ExecutionPolicy Bypass -File Desktop\Khatri-NetSurvey.ps1 -Phase online` → `DONE.` (folder `Desktop\khatri-netsurvey\online-*`)
☐ Printers connect-only (nothing prints): `Test-NetConnection 192.168.100.206 -Port 9100` · `.69` · `.218` → `TcpTestSucceeded : True`, tracert 1 hop
☐ Cloud: `Test-NetConnection khatribiryani.bingoopos.com -Port 443` → True; browser login page loads with a valid padlock
☐ PC: Windows 10/11 64-bit · admin account · ≥ 10 GB free · AC sleep = never · gateway `192.168.100.x` (main gate, NOT a `.1.x` extender)
☐ Existing MySQL? `Get-Service | ? Name -like '*mysql*'` → none (use kit) or version/port noted (never stop it)
☐ Online Print Agent: `Get-Process node` → running/not; version noted; LEFT RUNNING
☐ Cashier devices: IP, gateway, Wi-Fi name per device → table (`.100.x` same LAN / `.1.x` behind extender)
☐ WAN-pull LAN proof (owner pulls the cable): `…NetSurvey.ps1 -Phase offline` → `INTERNET = False`, printers still `reachable`, IP kept → plug back

## B. PC prerequisites (GO each; Khatri PC only)
☐ **Static LAN IP**: DHCP reservation for the PC's MAC on the main gate → `ipconfig /release; ipconfig /renew` → reserved `192.168.100.___`
☐ **MySQL 8.0.30** (`mysql\README-MYSQL.md` 1–4; service step elevated): extract `C:\BingooEdge\mysql`, `my.ini` from template, `--initialize-insecure`, service `BingooEdgeMySQL` on `127.0.0.1:3306`, DB `bingoo_edge_local`, user `bingoo_edge`, password in `C:\BingooEdge\secrets\edge.pass` (owner-written, never typed on a command line)
☐ **TLS CA + cert** (`scripts\New-EdgeBranchCA.ps1 -BranchName Khatri-01 -OutDir C:\BingooEdge\ca` → `scripts\New-EdgeServerCertificate.ps1 -Hostname bingoo-edge.local -ReservedIp 192.168.100.___ -CaThumbprint <thumb> -OutDir C:\BingooEdge\certs -ExportPfx -PfxPasswordFile C:\BingooEdge\secrets\pfx.pass`) → `.pfx` + public `khatri-01-ca.crt`
☐ **Network profile** Private (Settings → Network → connection → Private)
☐ **Firewall** (elevated, after install): `New-NetFirewallRule -DisplayName "Bingoo Edge gateway" -Direction Inbound -Action Allow -Protocol TCP -LocalPort 8443,80 -Program "C:\Program Files\Bingoo Edge\gateway\nginx.exe" -Profile Private,Public` — never open 3306/8090/8091
☐ **Hosts line on every cashier device**: `192.168.100.___  bingoo-edge.local` + trust `khatri-01-ca.crt` (Trusted Root, current user) · check `https://bingoo-edge.local:8443/edge/local/health`
☐ **Service tasks**: created by the installer under `NT AUTHORITY\LOCAL SERVICE` (gateway, web ×2, print worker, sync sender, authority worker, hourly backup) → `Get-ScheduledTask | ? TaskName -like 'Bingoo*'`

## C. Install (GO; needs internet + the deployed Cloud + Khatri `offline_edge` enabled + pairing code)
`scripts\Install-EdgeAppliance.ps1 -PackageRoot <USB>\BingooEdge-0.8.3-edge -DbHost 127.0.0.1 -DbPort 3306 -DbName bingoo_edge_local -DbUser bingoo_edge -DbPasswordFile C:\BingooEdge\secrets\edge.pass -CloudUrl https://bingoopos.com -PairingCodeFile C:\BingooEdge\secrets\pairing.code -DeviceName KHATRI-BRANCH-SERVER-1 -EnrollmentAssertionFile C:\BingooEdge\secrets\enrol.jwt -RecoveryKeyFile C:\BingooEdge\secrets\recovery.key -LanHostname bingoo-edge.local -LanIp 192.168.100.___ -CertPfx C:\BingooEdge\certs\<file>.pfx -CertPfxPasswordFile C:\BingooEdge\secrets\pfx.pass -HttpsPort 8443 -HttpPort 80`
`-CloudUrl https://bingoopos.com` = **central domain; the pairing code carries the tenant** (the tenant domain `https://khatribiryani.bingoopos.com` answers `PAIR_REFUSED: HTTP 404 The route api/edge/pair could not be found` — found at Khatri 21 Nov 2026; `routes/edge.php` registers the Edge API under the central domain). The cashier/operator URLs stay on the tenant domain.
PASS = `scripts\Get-EdgeHealth.ps1` → `STANDBY_READY`, authority `standby`, lease holder `cloud`, problems none, printers `reachable`; Cloud Settings → Offline Edge shows the device with a fresh heartbeat.

## D. Blocked until the production Cloud carries the Edge endpoints (whatever the GO)
Pairing / bootstrap / enrolment / first heartbeat / STANDBY_READY (today's production has pair + bootstrap only); `offline_edge` on the Khatri plan + `EDGE_FEATURE_ENABLED` (owner's separate GO); any takeover / Local Mode / cashier sale on Edge (LAB acceptance D-D first; operators named by the owner).

## E. Printing facts (proven, nothing sent to Khatri)
Edge prints straight to LAN TCP 9100 (network printers only; `windows`/`usb` jobs are never claimed; browser prints from the cashier browser). Mappings (printer, category→printer, terminal receipt/KOT) replicate from the Cloud — seen on an appliance copy. Ladder 5/15/30/60/120 s, 6th failure terminal → operator Retry/Dismiss. No duplicate after sync (Cloud creates no print job for an ingested Edge sale). Online Agent may stay on the same PC (no shared port/file/DB; a keep-awake poke only causes one Edge retry). Never press the Cloud "Test print" during the visit.

## F. Evidence before leaving
Survey folders (online + offline) → USB · printer label/IP photos · cashier device table · router list · PC facts (name, MAC, IP, gateway, Windows, disk, MySQL yes/no, agent version) · owner decisions (reserved IP, hostname, which MySQL, operators' names) · the internet window for install day.
