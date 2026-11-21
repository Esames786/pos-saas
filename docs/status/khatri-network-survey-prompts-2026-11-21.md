# Khatri — network survey prompt pack for the 21 Nov 2026 visit (read-only)

Owner update 20 Nov: internet at Khatri is back. Tomorrow the owner runs Claude Code on the Khatri PC and will pull the WAN
cable himself for the offline check. Topology as described: the main gate is the `192.168.100.x` router (where the printers
are: `.206`, `.69`, planned `.218`); several extender / extra routers hand out `192.168.1.x`.

Companion to `docs/status/khatri-offline-printer-preflight-2026-11-20.md` (answers 1–8 + shop checklist). Nothing here installs,
pairs, reconfigures, prints, stops the live Online Print Agent or touches production.

## Why the two subnets matter for Edge

1. **Edge prints directly to LAN TCP 9100.** The Edge PC must reach `192.168.100.206/.69/.218` with ONE hop (same LAN as the
   main gate). From behind a `192.168.1.x` extender router it usually still reaches them (outbound through that router's NAT), but
   every print then crosses a consumer router: slower, and gone when that extender reboots.
2. **Cashier devices must reach the Edge PC inbound** (`https://<name>:8443`). A device on `192.168.1.x` behind a NAT router can open
   a connection to a PC on `192.168.100.x` (outbound), but a device on `192.168.100.x` can NOT reach a PC hidden behind a
   `192.168.1.x` router. So: **the Edge PC belongs on the main `.100` LAN**, never behind an extender in router mode.
3. **Names do not cross routers.** The LAB contract is `EDGE_LAN_NAME_MECHANISM=hosts_file` (`<pc-name>.local` via a hosts line on
   each cashier device, certificate SANs = name + the PC's IP at issue time). mDNS discovery stops at a NAT router, so cashier
   devices on `.1.x` need the hosts line (or the IP in the SAN). A fixed IP / DHCP reservation for the Edge PC is therefore required.
4. **Extenders in router mode = double NAT** (`tracert 8.8.8.8` shows two private hops). Harmless for Cloud sync (outbound only),
   but every "AP/bridge mode" change on those extenders is a router configuration change — the owner's decision, not tomorrow's survey.

## Claude Code needs the internet — plan the offline phase accordingly

When the owner pulls the WAN cable, a Claude Code session running on the Khatri PC stops answering (it talks to Anthropic over
the internet). So the offline phase is ONE command the owner runs by hand; Claude reads the report after the cable is back.
(Alternative: a phone hotspot for the Claude laptop only, with the Khatri PC on the shop LAN — more moving parts; the manual
command is simpler.)

## Getting the script onto the PC

Copy `tools/site-survey/Khatri-NetSurvey.ps1` (this repo, commit of 20 Nov) to the Khatri PC desktop, e.g. by USB or by pasting
it into Notepad as `C:\Users\<user>\Desktop\Khatri-NetSurvey.ps1`. It is read-only: connect-and-close probes (zero bytes to any
printer), `ipconfig`, `route`, `arp`, `tracert`, `Test-Connection`, `Get-NetTCPConnection`, `Get-Process` (name/pid/path only),
firewall rule listing, `powercfg` queries. It writes `Desktop\khatri-netsurvey\<phase>-<utc>\report.txt` + `summary.txt`.
Self-tested on the LAB host 20 Nov (every section ran, no ERROR lines).

## Prompts to paste into Claude Code on the Khatri PC (in this order)

### PROMPT 0 — session rules (paste first, before anything else)

```text
You are on a restaurant's live Windows PC (Khatri). This is a READ-ONLY site survey for a future "Bingoo Edge" branch server install.
Hard rules for this whole session:
- Do not install anything, do not change network, firewall, printer, service, scheduled-task or power settings, do not create or edit
  files outside the folder Desktop\khatri-netsurvey, do not stop or restart any process (an Online Print Agent "node" process may be
  running: leave it alone).
- Never send a byte to any printer: no test pages, no raw 9100 writes, only TCP connect-and-close probes.
- Never read, print or log tokens, passwords, agent codes, config files or .env files. Show process names/pids/paths only.
- If a command would change state, stop and ask me first. If a permission prompt is denied, stop and report.
- The owner will unplug the internet cable himself; never touch cables, routers, Wi-Fi or adapters.
Confirm these rules in one line, then wait for the next prompt.
```

### PROMPT 1 — online survey

```text
Run the read-only survey script on the Desktop (copied from the Bingoo repo, tools/site-survey/Khatri-NetSurvey.ps1):
  powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Desktop\Khatri-NetSurvey.ps1" -Phase online
If the file is missing, tell me — do not write your own probes.
Then open the newest Desktop\khatri-netsurvey\online-*\report.txt and summary.txt and answer, briefly, with evidence lines:
1. Which IPv4 address and gateway does this PC have? Is the gateway 192.168.100.x (main gate) or 192.168.1.x (extender router)?
2. tracert to 192.168.100.1: one hop or more? tracert to 8.8.8.8: how many private (192.168.*/10.*/172.16-31.*) hops before the first
   public hop? (two or more private hops = double NAT)
3. Printers 192.168.100.206, .69, .218 on TCP 9100: reachable / refused / timeout, ping, round-trip, and how many hops each.
4. INTERNET = true? DNS working?
5. Any listening ports among 80, 443, 8443, 3306, 8090, 8091 and which process owns them?
6. Is a "node" process (Online Print Agent) running? Name/pid/path only.
7. Network profile Public or Private; inbound ICMPv4 rules; disk free; sleep-after setting; Windows version and architecture.
Finish with a 10-line block: PC_IPV4, PC_GATEWAY, PC_SUBNET_ROLE (main-gate | behind-extender), DOUBLE_NAT (yes/no), PRINTER_206,
PRINTER_69, PRINTER_218 (reachable/refused/timeout + hops), INTERNET, PORT_CONFLICTS, AGENT_RUNNING.
```

### PROMPT 2 — cashier devices (owner does this on the phone / cashier laptop, Claude records it)

```text
I will now check the devices that cashiers will use. For each one I will tell you its Wi-Fi/LAN details from its own settings screen
(IP address, gateway/router, which Wi-Fi name it is on). Record them in a table: device, IP, gateway, subnet (.100.x or .1.x), same
LAN as this PC (yes/no), and the consequence for Edge:
- same .100 LAN as this PC → reaches the Edge PC directly; name resolution via a hosts line or mDNS.
- behind a 192.168.1.x extender router while this PC is on .100 → can reach the Edge PC (outbound through the extender) but ONLY by
  IP or a hosts line; mDNS ".local" will not work; the extender must stay up during service.
- this PC behind a .1.x router while a cashier device is on .100 → that device can NOT reach the Edge PC; the PC must move to the main LAN.
Do not run anything for this; just build the table from what I dictate.
```

### PROMPT 3 — offline phase (owner pulls the WAN cable; Claude is NOT used while offline)

Tell Claude first:

```text
I am about to unplug the internet cable at the main gate. You will lose connectivity; do nothing until I say the cable is back.
```

Then, with the cable out, the owner runs by hand (Win+R → `powershell`, paste):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\Desktop\Khatri-NetSurvey.ps1" -Phase offline
```

Wait for `DONE.` (about 1–2 minutes). Optional while offline, from a cashier device: open its Wi-Fi details again and note whether
it still has an IP and gateway (the extender's DHCP must keep working without WAN). Plug the cable back in.

### PROMPT 4 — compare and verdict (after the cable is back)

```text
The internet cable is back. Open the newest Desktop\khatri-netsurvey\offline-*\report.txt and compare it with the online one:
1. Did the offline run prove it was offline? (INTERNET must be false, DNS example.com must fail). If INTERNET was still true, say so:
   the cable pulled was not the WAN uplink.
2. While offline: were the printers still reachable on 9100 with the same hop count? Was the gateway still answering? Did this PC keep its
   IPv4 address and lease (compare ipconfig)? Did hostname / hostname.local still resolve (section 5b)?
3. Any difference in routes, gateway, ARP or port listeners between the two runs?
Then produce the final block:
LAN_SURVIVES_WAN_LOSS = yes/no (+ one line of evidence)
PRINTERS_OFFLINE = per printer reachable/refused/timeout
PC_PLACEMENT = main-gate LAN | behind extender (must move to the main LAN before install)
CASHIER_REACH = per device from the PROMPT 2 table
NAME_RESOLUTION = hosts-line required for: <devices>
EDGE_PC_REQUIREMENTS_MET = yes/no for: Windows 64-bit, disk ≥ 10 GB free, no port conflicts, sleep disabled or acceptable, Private network profile
BLOCKERS = list (anything that must change before a first install), or none
Keep it under 25 lines. Do not propose changes to routers, firewall or printers; just list findings.
```

### PROMPT 5 — close

```text
Zip nothing, change nothing. List the report folders you created under Desktop\khatri-netsurvey so I can copy them to USB, and confirm
in one line that you did not modify anything else on this PC.
```

## Reading the result (for the owner)

| finding | meaning for the install |
|---|---|
| PC gateway `192.168.100.1`, printers 1 hop, cashier devices on `.100` | ideal: install the Edge PC here; hosts line per cashier device or mDNS; fixed IP / DHCP reservation on the main gate |
| PC on `.100`, some cashier devices on `.1.x` behind extenders | workable: cashier devices reach Edge by IP / hosts line only; each extender must stay powered during service; consider AP/bridge mode later (owner's router decision) |
| PC gateway `192.168.1.1`, printers 2+ hops | **move the PC** (cable to the main gate / its switch) before install; cashier devices on `.100` cannot reach it where it is |
| printers `timeout` from the PC while online | wiring / VLAN / client isolation on the path: fix before install, Edge cannot print |
| `LAN_SURVIVES_WAN_LOSS = no` (gateway or DHCP dies when the WAN is out) | the router model is unsuitable for offline service; the owner decides on a replacement — Edge cannot compensate for a LAN that disappears |
| port conflicts on 80/443/8443/3306 | another server (Laragon, XAMPP, MySQL, IIS) on the PC: must be stopped/removed before install, owner's decision |
| `INTERNET = true` in the offline run | the pulled cable was not the uplink (an extender or a second WAN); repeat with the right cable |

Nothing in this pack changes the standing program state: the signed 0.8.3-edge package stays held, the real LAB stays on 0.8.2
STANDBY_READY, the first Khatri install still needs its own window with internet (pairing, bootstrap, enrolment, first heartbeat).
