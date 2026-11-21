# Khatri — offline installation / printer pre-flight (read-only answers, 20 Nov 2026)

Owner visit 21 Nov 2026: internet down at the branch, LAN working. Nothing here installs, reconfigures or mutates the live
Khatri branch, its printers, routing or production. Code inspected: `EdgeLocalPrintDeliveryService`, `EdgeNetworkPrinterTransport`,
`EdgeLocalPrintKotService`, `EdgePrinterReachabilityProbe` (0.8.3), `EdgeAuthorityService::gates()`, `EdgeLocalPairCommand`,
`tools/print-agent/print-agent.js`, `routes/edge.php`, `EdgeNoDuplicatePrintAfterSyncMySqlTest`; Khatri facts from
`docs/onboarding/khatri-biryani-2026-08.md` (PRINTER-1 BlackCopper BC97AC `192.168.100.206:9100` role both, PRINTER-2 XPrinter
`192.168.100.69:9100` role kot, agent `KHATRI BIRYANI DELIVERY COUNTER-2`; later plan: BBQ KOT `192.168.100.218:9100`).

## Answers

1. **Same PC: yes, safely.** They share nothing: Edge = php + nginx (loopback 8090-91 backends, gateway 8443/80) + two
   Scheduled-Task workers + its own MySQL database; the Online Print Agent = a Node process that only polls the Cloud and opens
   outbound TCP to printers. No port, file or database overlap. One interaction to know: the agent "keep-awake" pokes printers
   (connect/close) and a thermal printer accepts ONE connection at a time, so an Edge send can meet a poke → Edge records a
   temporary failure and retries on its ladder (5/15/30/60/120 s). Harmless, but on the demo PC stop the agent (STOP-PRINTING.bat)
   while Edge prints, or accept the occasional retry.
2. **Edge prints directly to LAN TCP 9100 — no agent.** `EdgeNetworkPrinterTransport` opens the socket itself; the delivery
   worker claims only jobs whose printer is `printer_type = network` with an IP (`edge_capable`). Printers of type `windows`
   (A4 document), `usb` and `browser` have no Edge transport: `browser` prints from the cashier's own browser (works offline),
   `windows`/`usb` jobs are never claimed by Edge.
3. **No internet → the Online Agent does nothing for Edge, and cannot.** It polls `<baseUrl>/api/print-agent/pending` on the
   Cloud tenant domain; with the WAN down its heartbeat and polls fail and it idles. The Edge appliance exposes no agent API
   (`routes/edge.php` has none) and needs none. Required configuration = exactly what Khatri already has: thermal printers as
   `network` with their LAN IP/port, mapped on the Cloud (category → printer, terminal printer settings); the Edge bootstrap
   replicates `printers`, `category_printer_mappings`, `terminal_printer_settings` to the appliance. No integration work.
4. **Local Mode printing:** mappings come from the replicated Cloud tables (`PrintRoutingService`, category → printer with
   terminal override, one KOT per printer AND category); receipts print on a paid local sale (network printers) or the browser
   dialog; KOT normal / addition / cancellation and reminders are created by `EdgeLocalPrintKotService` — ONLY in Local Mode
   ("a standby appliance never creates kitchen events"); in standby only reprints of existing paid sales run. Delivery is
   lease-safe and at-least-once: bounded ladder 5/15/30/60/120 s, 6th failure = terminal (shown in health, operator retry /
   dismiss from Recent Prints), stale workers never mutate, a printed job can never be re-sent by a late report.
5. **No duplicate print when the Cloud returns:** the Cloud creates NO print job and NO KOT batch for an ingested Edge sale —
   not on ingestion, not on ACK replay, not on lost-ACK recovery, not on handback (`EdgeNoDuplicatePrintAfterSyncMySqlTest`:
   the printer receives exactly two payloads for the whole lifecycle). Reminder and cancellation slips carry logical keys
   (`reminder:<cancel-event-uuid>:<printer>`) so repeats never print twice; the cashier's receipt request is ensure-once.
6. **Installing Edge while the WAN is already down is blocked at pairing**, not at the installer: `db-init` is local, but
   `edge:local:pair --cloud-url=https://…` exchanges the one-time code with the Cloud over HTTPS, `bootstrap-pull` fetches the
   signed bootstrap snapshot from the Cloud, and the authority lease needs an acknowledged heartbeat. Takeover gates
   (`gates()`): LOCAL_DB_HEALTHY, CONFIG_COMPATIBLE (≥ 1 applied config revision), SCHEMA_COMPATIBLE, BRANCH_BINDING_VALID
   (bootstrapped), LOCAL_USERS_READY (enrolled credentials), STOCK_AUTHORITY_READY (accepted baseline), ENTITLEMENT_VALID
   (device id + a last acknowledged lease), AUTHORITY_TAKEOVER_SAFE (a lease that EXISTED and lapsed: "never granted → no
   takeover, fail closed"), STANDBY_FRESH_ENOUGH (config revision + stock position equal the Cloud's last advertised, or an
   `--accept-stale --reason` override of THIS gate only). So a never-paired appliance can never enter Local Mode offline.
   Practical consequence: the first install needs one window WITH internet (pair, bootstrap, enrol cashier/approver, first
   heartbeat, stock baseline); after that it rides out WAN loss by design.
7. **Read-only printer tests from the proposed Edge PC (connect only, zero bytes, nothing prints):**
   ```powershell
   Test-NetConnection -ComputerName 192.168.100.206 -Port 9100          # TcpTestSucceeded: True = printer answers on 9100
   Test-NetConnection -ComputerName 192.168.100.69  -Port 9100
   Test-NetConnection -ComputerName 192.168.100.218 -Port 9100          # if the BBQ KOT printer is installed
   ping -n 2 192.168.100.206; arp -a | findstr 192.168.100.             # name/MAC sanity, no printer interaction
   # PowerShell raw connect with a 3 s timeout (what Edge's own probe does), closes without writing:
   $c = New-Object Net.Sockets.TcpClient; $r = $c.BeginConnect('192.168.100.206', 9100, $null, $null)
   if ($r.AsyncWaitHandle.WaitOne(3000)) { $c.EndConnect($r); 'REACHABLE' } else { 'TIMEOUT' }; $c.Close()
   ```
   On an installed 0.8.3 appliance the same probe is built in: `edge:local:print-status` / `edge:local:health` report
   `reachable | refused | timeout | unreachable | unresolved` per network printer (never a byte written). Do NOT send a test
   page from the Cloud "Test print" button or the agent — that prints on the live counter.

## Shop checklist (tomorrow)

| # | check | safe without touching production? |
|---|---|---|
| 1 | From the proposed Edge PC: `Test-NetConnection` to .206 / .69 (/.218) port 9100 → True; `ping` each | yes (connect only) |
| 2 | Note each printer's label, IP, port, paper (80 mm / 42 chars) against the Cloud printer list (read it on the phone or from the onboarding doc) | yes (read) |
| 3 | Confirm the PC: Windows 10/11 64-bit, admin account available for the installer, static LAN IP or DHCP reservation, hostname, free disk ≥ 10 GB, sleep/Modern Standby settings noted | yes |
| 4 | Confirm the LAN: PC and printers on the same subnet (192.168.100.x), no client isolation (PC→printer connect works = item 1), router DHCP range vs printer IPs | yes |
| 5 | Check the agent PC: is it the same machine the owner wants for Edge? note the agent code shown in START-PRINTING / config (do not stop it on the live counter) | yes (read) |
| 6 | Ask which counters/terminals print where (terminal printer settings) and whether a "windows"/A4 or USB-only printer must print offline — Edge cannot drive those | yes (ask) |
| 7 | Write down the internet situation and when a window with internet exists: pairing + bootstrap need it once | yes |
| 8 | Do NOT: install Edge, pair a device, change printer IPs/routing, press Test print, stop the live agent, enter Local Mode | — |

Decision input for the owner: the Khatri first install can only happen in a window with internet (item 6 above); tomorrow's
visit is a site survey (items 1–7). The signed 0.8.3-edge package stays held; the LAB acceptance runbook remains the path.
