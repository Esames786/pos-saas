# Khatri Branch Server 0.8.5 → 0.9.0: maintenance runbook

**Status: PREPARED, NOT AUTHORIZED.** Written 23 Nov 2026 after the owner's "PRODUCTION CLOUD 0.9 AND AUTHORITY RELEASE FIX" decision.
Nothing in this document has been run on Khatri. Khatri stays on 0.8.5 until every item in §0 is true.

Readers: the owner, the on-site operator at the Khatri PC, and the Cloud recovery operator.

## 0. Preconditions (all required)

| # | Precondition | State on 23 Nov |
|---|---|---|
| P1 | Production Cloud on Edge 0.9 (`6be3d9a1`) and its post-deploy checks passed | scheduled 24 Nov 02:23 PKT, attended |
| P2 | AUTHORITY-RELEASE-FIX on production (`be57aeba`: tenant lookup + safe-release guard) and verified with its dry-run checks | candidate ready; the guard part needs owner approval |
| P3 | The owner's separate **Khatri GO** | not given |
| P4 | Named people: on-site operator, Cloud recovery operator (SSH to production), owner reachable | owner to name |
| P5 | Actual appliance backup size read on site (§3 A2) | not read yet |

## 1. Roles

- **Owner:** GO / NO-GO; generates the update setup code on the Cloud (Settings → Offline Edge); names the operators.
- **On-site operator** (at DESKTOP-B9MO2QG):
  - runs the wizard and reads the health screen;
  - asks the owner immediately before the UAC (administrator) prompt.
- **Cloud recovery operator:**
  - watches the Khatri lease from the Cloud;
  - is the only person who may run `edge:authority:release`, and only under §6 R3.
- **Lead:** Cloud-side prechecks and lease watch (every 3 s), evidence, report.

## 2. Numbers this plan rests on

| Item | Value | Source |
|---|---|---|
| Khatri sales, 9–22 Nov | 4,998 completed; average 357/day; maximum 464 (13 Nov) | production Cloud, read-only, 23 Nov |
| Closed hours | 02:00–11:00 PKT: 0 sales in 14 days. **Start 03:00 PKT, latest start 06:00 PKT** | same |
| Appliance DB | about 45,000 rows (14-day mirrored sales and lines, catalogue 151, customers 1,762) | estimate from the Cloud shape |
| Restore (K6 snapshot) | about 21 s on the lead's laptop; bound 39 s (measured: 117k rows = 39.2 s) | `edge-0.9.0-real-lab-acceptance-2026-11-23.md` §6 |
| Successful update | heartbeat continues. Sandbox 0.8.5 → 0.9.0 r2: maximum gap 38 s; real LAB: 32 s | CP6 / real LAB records |
| Failed update (wizard K6 rollback) | gap = about 71 s fixed + restore = **about 92 s (bound 110 s)** against the **120 s** lease | Sandbox: 80 s with a 9 s restore |
| Khatri PC speed | **unknown.** A PC about 1.3× slower than the laptop crosses 120 s on the rollback path only | — |

**Not assumed:** the successful-update timings say nothing about the rollback path. Rollback can exceed the lease (§6).

## 3. Before the window (read-only)

- **A1 Health (on site):**
  - Command: `& "C:\Program Files\Bingoo Edge\scripts\Get-EdgeHealth.ps1" -InstallRoot "C:\Program Files\Bingoo Edge" -Services`
  - Required:
    - 0.8.5-edge, STANDBY_READY "No problems";
    - authority standby, holder Cloud;
    - outbox pending 0, permanent 0;
    - Auto failover "no";
    - all Bingoo tasks Ready or Running.
- **A2 Actual backup size (on site; read-only, no secret):**
  - Command: `Get-ChildItem C:\ProgramData\BingooEdge -Recurse -File -Filter 'edge-backup-*' | Sort-Object LastWriteTime -Descending | Select-Object -First 3 FullName, Length, LastWriteTime`
  - Record the newest size.
  - Reference: the snapshot was 8.3 MB at 117k rows (restore 39 s) and 0.6 MB at 4k rows (restore 11 s).
  - **At or below 8.3 MB:** within the measured bound.
  - **Above 8.3 MB:** report it before the GO.
- **A3 Package (on site):**
  - Copy the custody copy `BingooEdge-0.9.0-edge.zip` from USB.
  - Verify `Get-FileHash -Algorithm SHA256` = `85bbadad0cd5413e253fe33fe55df7eb10b07f0ba57f1db6b821a4c9b474c5d5`.
  - `package-manifest.json` must show:
    - package_hash `1cbc7d6c325d5927f6738288f72b2522a1512863c6e6edbb5183a324a3515415`;
    - source `3328afb8`.
  - Never rebuilt or re-signed.
  - The superseded build 1 is never used.
- **A4 SmartScreen:** if Windows shows "Windows protected your PC" (or an unknown-publisher block), **STOP**.
  - No "Run anyway".
  - No policy change.
  - No antivirus exclusion.
  - The package has no Authenticode certificate yet: an open distribution item.
- **A5 Cloud (lead, read-only):** all must hold, or wait:
  - Khatri lease holder Cloud, last heartbeat under 60 s ago, `fenced_at` null, `released_at` null;
  - no Khatri sale touched in the last 15 min.
  - Start the 3-second lease watch.
- **A6 Release fix ready:** the Cloud recovery operator runs the dry run (writes nothing):
  - `sudo -u www-data php artisan edge:authority:release khatribiryani 1 --reason="pre-update check" --by="<name>" --dry-run`
  - Expect `DRY RUN: REFUSED LEASE_LIVE`. That proves the command works and the appliance is alive.

## 4. The update (03:00 PKT)

- **U1 Setup code (owner):** Cloud → Settings → Offline Edge → generate an **update** setup code for branch 1.
  - It creates a one-time setup session and changes nothing else.
  - No release is published; `edge_releases` stays empty.
- **U2 Start (on site):** double-click `Setup-BingooEdge.cmd` in the extracted package.
  - It asks for administrator rights (UAC): the owner is asked immediately before.
  - Choose **Update** and enter the code.
- **U3 Plan check:** the wizard shows the migration plan hash. Tick "approve" **only** if it equals the reviewed hash:
  - `sha256:659f170d966cc87fdf95a4b15fb3871885bf8c02587d897931b5fab18ffc2ffb`
  - Any other value: **STOP**.
  - The wizard enables "approve" only in standby with 0 pending.
- **U4 Apply:** expect about 5–6 min.
  - The 0.8.5 authority worker keeps heartbeating until just before the worker switch.
  - The lead watches the lease gap live.
- **U5 Result:**
  - `updated` with STANDBY_READY → §5.
  - `rolled_back` (wizard exit 2) → §6 R1.
  - Anything else → §6 R4.

## 5. Verify (about 15 min)

- **V1 Health:**
  - 0.9.0-edge (`3328afb8`), STANDBY_READY "No problems";
  - outbox 0 / 0;
  - Auto failover "no"; auto-takeover "disabled";
  - update "verify key ok".
- **V2 Cloud:**
  - lease seq advancing, holder Cloud, `fenced_at` null, `released_at` null;
  - largest gap under 120 s;
  - device row shows 0.9.0-edge.
- **V3 Counters:**
  - owner PIN login works;
  - the three counter shortcuts open `https://bingoo-edge.local:8443` with a valid certificate (never bypassed).
- **V4 Configuration:** printers, category→printer routing, terminals, users and PIN enrolments are unchanged.
- **V5 Data:** 0 duplicates on sale, line, payment, shift and print-job keys; outbox digest unchanged.
- **Known, non-blocking:** the 0.9 gateway drops `:8443` in the HTTP→HTTPS redirect (F-2). Khatri's shortcuts use the https `:8443` links directly.

## 6. Failure and recovery

- **R1 Automatic rollback (wizard K6):**
  - restores the pre-update snapshot and returns to 0.8.5;
  - expected gap about 92 s (bound 110 s at laptop speed);
  - a slower PC can pass 120 s.
- **R2 If the lease lapses (gap over 120 s):**
  - The Cloud pauses Khatri's Online POS for branch 1. This happens during closed hours, so it costs no sales.
  - It reopens **by itself** at the first heartbeat of the restored 0.8.5 (a standby heartbeat re-grants the Cloud).
  - Wait up to 10 min after the rollback ends.
- **R3 Branch Server does not come back** (no heartbeat 10 min after R1, the wizard crashed, or the PC is dead):
  1. On site, confirm the Branch Server is stopped or switched off and that **nobody took over locally** (auto-takeover is off).
  2. Cloud recovery operator, dry run first: `sudo -u www-data php artisan edge:authority:release khatribiryani 1 --reason="<what happened>" --by="<name>" --dry-run`
     - `WOULD be released` → run the same command without `--dry-run`. The Online POS works again immediately, logged with name and reason.
     - `REFUSED LEASE_LIVE` → the appliance is alive. Do **not** release; wait for R2.
     - `REFUSED EDGE_HOLDS_AUTHORITY` → the appliance took over. **STOP** and call the owner. Its offline sales exist only on it.
  3. The Branch Server is repaired later in a new window, with data preserved. Until then Khatri has no offline fallback; the Online POS is unaffected.
- **R4 Anything unexpected:** keep the state as it is, change nothing else, and call the lead. Do not improvise a manual restore.
- **Deadline:** if Khatri is not healthy by **09:30 PKT**, use R3 so the Online POS opens normally at 11:00.

**Never used as a planned recovery:** a raw tinker or one-line server call, a down-migration, raising the 120 s lease, or enabling automatic takeover.

## 7. Stop rules

Stop at any of the following:
- a sale in the last 15 min;
- outbox not 0 / 0 before the start;
- a plan hash mismatch;
- a SmartScreen prompt;
- `fenced_at` set on the successful path;
- health not STANDBY_READY after V1;
- any duplicate;
- any change in printers, PINs, counters or authority state.

Then keep everything as it is and report.

## 8. Out of scope

- No release publication.
- No automatic update: the 0.9 `BingooEdgeUpdater` task finds no published release.
- No takeover, handback or Local Mode.
- No automatic-takeover enablement.
- No lease TTL change.
- No rebuild or re-signing.
- No change to the real LAB, which stays on the accepted 0.9.0.
