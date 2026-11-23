# Khatri Branch Server 0.8.5 → 0.9.0: update record, 24 Nov 2026

**Result: updated and verified.** The Khatri Branch Server (DESKTOP-B9MO2QG, device `a7d57f56-360c-42c1-b0e8-926b58289173`) runs **0.9.0-edge** (`3328afb8`) as a warm standby.
The Cloud stayed the branch writer throughout:
- no takeover, handback or Local Mode;
- auto-takeover off;
- no release published (`edge_releases` = 0);
- lease TTL unchanged at 120 s.

Readers: the owner and the engineers who run the next appliance window. Times are PKT; the Cloud logs in UTC (PKT − 5 h).

## Authority

- **Owner Khatri GO** (03:00 PKT, 24 Nov), given after both Cloud deployments passed (`docs/status/edge-0.9-production-cloud-deploy-2026-11-24.md`).
- **Operators:**
  - on site: the owner, through RustDesk on the shop PC (clicked the administrator prompt);
  - Cloud: Claude, in the owner's session (lease watch, guarded release on standby; not needed).
- **Runbook:** `docs/status/khatri-0.9-maintenance-runbook-2026-11-24.md` (branch `edge09/integ`).

## Prechecks (read-only, 01:55–03:00)

- **Health (0.8.5):**
  - standby, online, outbox 0/0, auto failover "no", 7 tasks Running or Ready.
  - DEGRADED only for the known stale warm caches (stock, returnable) and 3 printers that were off because the counters had closed.
- **Backups:** encrypted snapshots `edge-backup-*.enc` of 440,359 bytes, hourly at :58. That is far below the 8.3 MB bound, so a restore should take well under the measured 11–39 s.
- **Windows:**
  - no restart pending (`RebootRequired` and `RebootPending` both False); sleep set to never.
  - Active hours were 08:00–17:00 and were changed to **10:00–04:00** (owner, on site). See the incident note below.
- **Package:**
  - `BingooEdge-0.9.0-edge.zip`, sha256 `85bbadad0cd5413e253fe33fe55df7eb10b07f0ba57f1db6b821a4c9b474c5d5`, 161,193,495 bytes.
  - The manifest shows `package_hash 1cbc7d6c325d5927f6738288f72b2522a1512863c6e6edbb5183a324a3515415` and `git_commit 3328afb8…`.
  - A first copy came through WeTransfer with an internet mark (ZoneId=3). It was replaced by a RustDesk copy with no mark; no mark was removed by hand. 0 extracted files carry a mark.
  - Extracted to `C:\BingooEdge\kit\BingooEdge-0.9.0-edge\`.
- **Cloud:**
  - no sales in the last 15 min;
  - Khatri lease held by the Cloud, live;
  - `edge:authority:release khatribiryani 1 … --dry-run` answered **REFUSED LEASE_LIVE** (nothing written).

## Update (03:03–03:13:47)

- **Code:** the owner generated it on the Offline Edge page with **Run setup again**. It ran in update mode ("updates it in place … no re-pairing").
- **Launch:** `Setup-BingooEdge.cmd`, then the administrator prompt (Yes), then the wizard detected an UPDATE.
- **Wizard checks:**
  - administrator; Windows 10 Pro 19045; 75.7 GB free;
  - clock within 1 s of the Cloud; Cloud reachable;
  - sleep never; LAN 192.168.100.52 over Ethernet, Private profile;
  - ports in use by the installed Branch Server; MySQL reused; Visual C++ OK.
- **Migration plan:** `sha256:659f170d966cc87fdf95a4b15fb3871885bf8c02587d897931b5fab18ffc2ffb`, equal to the reviewed hash.
- **Pre-update snapshot:** `C:\ProgramData\BingooEdge\updates\snapshots\wizard-pre-0.9.0-edge-20261124030821.snap`.
- **Result:** "updated 0.8.5-edge → 0.9.0-edge, health OK (no new problem; status DEGRADED)". No rollback.
- **Operating note:** clicking inside the console window puts it into "Select" mode, which pauses the setup until Esc or Enter is pressed.

## Verification

- **Cloud lease** (3-second watch, 277 samples):
  - largest heartbeat age **45 s** (22:12:48 UTC, the worker switch);
  - never blocked, fenced or released; the Cloud was the holder throughout;
  - seq 3355 → 3379. Afterwards the 0.9 worker heartbeats about every 36 s.
- **Cloud device row:**
  - `app_version 0.9.0-edge`, status ready, `install_phase updated` at 22:13:47 UTC;
  - the LAN profile (host, IP, port) and the live-state revision are now reported, which is new in 0.9.
- **Cloud snapshot compare (Khatri tenant):**
  - identical: printers, users, payments, settings, roles, permissions, branches;
  - 0 migrations; auto-takeover 0; no business rows.
  - The only differences are the setup session row and the device's 0.9 report columns, as expected.
- **Appliance health (0.9.0):**
  - runtime 0.9.0-edge `3328afb81a10`; local DB OK; binding the same (users 6);
  - standby, online, 0 failures; outbox 0/0/0;
  - print worker with 6 network printers;
  - web backends healthy with 0 restarts; gateway :8443 certificate valid for 728 days;
  - backup taken 22:12:46 UTC; update "active 0.9.0-edge · verify key ok · last applied";
  - auto failover "no"; all 8 tasks Running or Ready (new: BingooEdgeUpdater).
  - Problems are the same kind as before:
    - warm caches stale (config 4048 vs advertised 4049, the Cloud's config churn; stock; returnable);
    - 3 printers off.

## Incident note (23 Nov, 5:51 PM block)

The Windows System log on this PC shows the cause.
- **5:51:33 PM, event 1074:** `MusNotificationUx.exe` (the Windows Update restart prompt) restarted the PC on behalf of user `a`.
- **5:57:01 PM:** a second restart by `TrustedInstaller.exe`.
- **5:58:09 PM:** the PC was back up.

Active hours were 08:00–17:00, so 5:51 PM counted as "off hours". They are now 10:00–04:00. Staff must never click "Restart now" on this PC during service.

## Still open

- **Heartbeat-only delays:** the 0.9 appliance keeps one worker loop (heartbeat, then refresh work, then the live-state mirror). The 120 s lease can still lapse on a slow tick. Fixes: the heartbeat on its own timer (C3) and less config and returnable churn (C1/C2).
- **Lease TTL 600 s:** on hold pending the owner's confirmation of its takeover and fencing implications.
- **Read-only follow-ups:** the 11:47 PKT pre-opening lease check and the 12:53 PKT post-opening sales check.
