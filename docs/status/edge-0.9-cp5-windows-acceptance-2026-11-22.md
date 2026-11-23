# Edge 0.9 — CP5 Windows-native acceptance (Windows Sandbox), 22 Nov 2026

Checkpoint CP5 of the Edge 0.9 program: install, update, automatic update, rollback, counter self-service, optional automatic
takeover and shift/order continuity on a real Windows install. This was proven in disposable Windows Sandbox VMs,
driven by the real WPF wizard through UI Automation, not only by backend commands.

Nothing here touched production, Khatri or the real LAB data. The LAB was stopped for the reboot that enabled the Sandbox,
then restored (section 5).

## 1. Environment

- **Host:** Windows 11 Pro 26200, 31.7 GB RAM.
- **Windows Sandbox:** feature `Containers-DisposableClientVM`, enabled by the owner with one elevated prompt and a restart
  on 22 Nov 13:33 PKT.
- **Each sandbox:** Windows 11 Enterprise 26100, 4–6 GB, its own disposable Cloud (MySQL 3407 and `php -S` on 9831) inside
  the VM.
- **Kit and evidence:** `D:\BingooEdgeLab-e09\cp5`, with evidence in `D:\BingooEdgeLab-e09\cp5\evidence\run1…run13`.
- **Packages:** DEV-signed (key id `68cbf5ba8376acc8`), built from edge09/b-wizard with a copied vendor closure.
  - VC++ 14.44.35211.0 comes from aka.ms (Microsoft signature valid). It is installed by `Install-EdgePrereqs.ps1`.
  - The MySQL profile is slim (89 MB).
- **Starting point:** 0.8.5-edge, installed with the documented Khatri workaround for its own `[TimeSpan]::MaxValue` task bug.
  The bug also occurs on Windows 11 26100.

## 2. Results (final packages)

The auto chain ran on packages b-8f4591a5: runs 8 and 11. The fresh-install chain ran on packages 0821c78d: run 14.
Neither later fix (D-3, D-5) touches the update path: D-3 is the fresh-only my.ini write, and D-5 is install step 12.

| Scenario | What it proves | Result | Key numbers |
|---|---|---|---|
| prereqs | VC++ runtime on a clean PC | PASS | check exit 2 → installed by Install-EdgePrereqs.ps1 (exit 0) |
| cloud | disposable Cloud in the VM | PASS | HTTP 200 |
| a | 0.8.5 baseline install | PASS | PIN logins |
| **w-update** (real UI) | wizard updates 0.8.5 → 0.9.0 | PASS | Welcome → Check this PC → Update plan → Update → Finish. "Updated 0.8.5-edge -> 0.9.0-dev. Health: STANDBY_READY". Screenshots `ui-update-01..07` |
| b | same device after the update | PASS | K5 200 with the CA; tasks and the LOCAL SERVICE grant; PIN logins |
| **c** | automatic update 0.9.0 → 0.9.1 by the LOCAL SERVICE task | PASS | 191 s, result 0. Max heartbeat gap 70 s vs TTL 120. Min lease remaining 51 s. `fenced_at` null. Holder Cloud throughout |
| **d** | automatic update to a broken 0.9.2 → automatic rollback | PASS | `AUTO_UPDATE_HEALTH_FAILED` → rollback `reverted_runtime`, pointer 0.9.2 → 0.9.1. STANDBY_READY after, 3/3 PIN logins. Cloud install_phase `rolled_back`. Max gap 33 s. `fenced_at` null |
| e | update ledger and audit | PASS | |
| — | downgrade | refused | dry-run `UPDATE_DOWNGRADE_REFUSED` for 0.9.0 over 0.9.1 (run 9) |
| **s** | cashier self-service counter setup | PASS (run 11) | 20/20 verdicts; negatives in §2.1 |
| **h** | shift/order continuity (mirror → adopt → merge) | PASS | 27/27 invariants. Exactly one shift per counter. No duplicate order, payment, cash or KOT. Expected cash 2450 → 4700 (expected 4700). A stale mirror fails closed. Handback to standby |
| **g1** | auto-takeover default OFF | PASS | decision disabled (`CLOUD_SETTING_OFF` + `APPLIANCE_NOT_ALLOWED`); stayed standby |
| **g2** | auto-takeover ON, sustained Cloud loss | PASS | last ack 12:50:41 → takeover_at 12:53:41 = 180 s, against the required max(TTL+30, 180). Lease expires_at ≤ takeover_at. Cloud back 120 s → holder stayed Edge (no automatic handback). Supervised handback took 1 attempt → standby, holder Cloud |
| **g3** | flapping Cloud | PASS | only "wait" decisions (longest loss 89 s of 180); stayed standby |
| **g4** | clock skew | PASS | skew ~43–45 s → `CLOCK_SKEW_TOO_LARGE`; stayed standby |
| **w-fresh** (real UI) | wizard FRESH install with a setup code | PASS (run 14) | Welcome → Check this PC → Setup code → Passwords → Install → Users and PINs → Finish. 3/3 "PIN set", 3 desktop links. See §3 |
| **f** | fresh-install post-checks | PASS (run 14) | STANDBY_READY, no problems. Authority task Running. CA on the Cloud. Gateway PIN logins over full TLS |

`secret_hits_redacted` = 0 in every run. Passwords and PINs travel only through files. Signed download-URL capabilities
from the disposable Cloud (already expired) are stripped from the evidence and counted as `signed_url_params_redacted`.

### 2.1 Self-service negatives (s8)

| Attempt | Response |
|---|---|
| user without the permission | page, setup and my-enrollment all 403 |
| cashier of another branch | 409 "No Branch Server is set up for CP5 Branch Two yet"; no script served |
| other tenant | 404 |
| assertion for another user | refused; the owner tool returns 403 |
| throttle | 5 × 200, then 429 (keyed by user) |
| Edge enroll: replay, wrong tenant, wrong branch, forged signature | all 422; throttle 429 |

## 3. Fresh install (runs 12–13)

- **Run 12:** FAIL, on a product defect (D-3 below).
  - The wizard stopped at "installing" and rolled back on its own: "Nothing is selling from this PC; the shop keeps using
    the Online POS".
  - Screenshots `run12-…\ui-fresh-01..07, 99`.
- **Run 13** (packages f94bf211): prereqs, cloud, w-fresh and f all PASS (`secret_hits_redacted` 0). This was the first
  sandbox execution of every fresh-only step, and each one worked:
  - MySQL init, the `BingooEdgeMySQL` service and the Edge-account TCP check
  - the 0.9 branch CA and server certificate
  - `Install-EdgeAppliance` steps 1–13 on an empty DB: pair, bootstrap, recovery key, gateway cert, service plan, tasks,
    health
  - the firewall rule (8443/80 to the gateway only)
  - the hosts line
  - the Users and PINs page (3/3 "PIN set")
  - the desktop shortcuts
  - Finish: "installed as a WARM STANDBY"
- **Problem behind the PASS:** health was DEGRADED with "Authority/heartbeat worker is not running" — defect D-5.
- **Screenshots (run 13):** `run13-…\ui-fresh-01..10`.
- **Run 14** (packages 0821c78d, D-5 fix): prereqs, cloud, w-fresh and f all PASS. `secret_hits_redacted` 0.
  - **Race:** it happened again, and the fix handled it. The installer logged "authority task BingooEdgeAuthorityWorker
    started again (the warm-sync ticks held the one worker slot)" → "Running", before step 13.
  - **Install-time health:** the only problem listed is "No enrolled local users". That is expected: the wizard enrolls
    users on the next page.
  - **Finish:** "installed as a WARM STANDBY. Status: Ready as warm standby — Cloud is serving this branch".
  - **f:** **STANDBY_READY**, no problems, authority task Running. The branch CA reached the Cloud. PIN logins through
    the gateway pass with full TLS.
  - **Evidence:** `run14-fresh-20261122-185454-wfresh-f-PASS-authority-Running-STANDBY_READY` (`ui-fresh-01..10`).
- **Not exercised by design:** the wizard's Fix buttons (sleep, network profile, clock). CP5 never presses them, and in the
  sandbox those checks were warnings only.

## 4. Defects found and fixed in CP5

| # | Where | Defect | Fix |
|---|---|---|---|
| D-1 | `scripts/edge/Register-EdgeServices.ps1` (product) | `-Action Stop` aborted the wizard's update. `nginx -s quit` from an administrator cannot open the quit event of a master started by LOCAL SERVICE ("OpenEvent … Access is denied"), and PS 5.1 under `$ErrorActionPreference='Stop'` turns that stderr line into a terminating error | 8f4591a5: the cooperative step is never fatal; the tasks are stopped; leftover nginx is swept by exact executable path |
| D-2 | `EdgeUpdateInstaller` (product, found at CP4) | the workers were stopped before staging (~7 min without heartbeats → the Cloud fenced the branch) | 7af48aec: the workers stop only right before the atomic switch |
| D-3 | `scripts/edge/wizard/BingooEdgeWizard.psm1:600` (product) | `New-Object Text.Encoding.ASCIIEncoding` (the type does not exist) in the fresh-install my.ini write | f94bf211: `Text.ASCIIEncoding`. Guard: `tests/Unit/Edge/EdgeScriptsTypeNamesTest.php` resolves every namespace-qualified type under `scripts/` on PS 5.1 |
| D-5 | `scripts/edge/Install-EdgeAppliance.ps1` (product, present since P4) | Step 11 starts the tasks. Step 12 then runs a one-off `authority-worker --max-ticks=2` in the same second. When the one-off claims the worker slot first, the task's worker exits cleanly (one-worker topology, exit 0), and Task Scheduler does not restart a successful exit. A fresh install is then left with no heartbeat worker until a reboot. Khatri escaped only because its Register step ran later | 0821c78d: after the warm-sync ticks, start ONLY the plan's authority task if it is not Running, and wait until it stays Running (step numbers unchanged; worker command and authority engine untouched). Guard: `EdgeInstallerAuthorityTaskTest` (fails 2/3 without the fix). f asserts it |
| D-4 | `TenantResetTransactionsCommand` (product) | the 0.9 mirror tables were unclassified → fail-closed reset test red | 2c951be4: added to KNOWN_KEPT |
| — | CP5 kit and harness | s8 Accept header / XSRF token; g2 log read on an open file; cmd comma-splitting of `-Only`; evidence JSON runaway; resumed-session handling | B: 93110521, edc9b55e, f79f144d, 0749bee0, dc967bc7, 97272c4f, 7a1cff65, 7030c13d, 92889852 |

## 5. LAB after the tests

- **Order:** restored with `D:\BingooEdgeLab-e09\lab-reboot\Restore-Lab.ps1` in the recorded order: 3317 → LAB Cloud
  (`-Browsable -Worktree cloud\src-ac5b168d`) → FakePrinter → `Start-EdgeLabWorkers.ps1 -Action Start`.
- **Stale pid record:** the pre-reboot record was renamed and kept, never used to stop anything.
- **Health:** `edge:local:health` returns **STANDBY_READY, "No problems"**:
  - 0.8.2-edge
  - authority standby, online, failures 0
  - outbox pending 0, acknowledged 5 (unchanged)
  - both web backends answering, gateway :8443
  - hourly backup ran
  - auto failover "no"
- **TLS:** verified (LAB CA, no `-k`) on `https://DESKTOP-0024EPM.local:8443/edge/local/health` → 200.
- **Data:** 445/445 tables. Before vs after the reboot, the fingerprint was identical. After the restart, only six tables
  changed, all with the same row counts and all worker/heartbeat bookkeeping:
  - `edge_local_meta`
  - `edge_local_authority_worker_state`
  - `edge_local_print_worker_state`
  - `edge_local_backups`
  - `edge_devices`
  - `edge_branch_authority_leases`

## 6. Integration and gates

- **edge09/integ:**
  - a945c0de: A gap 3 + B's CP5 branch.
  - Then the merges of f94bf211 (D-3) and 0821c78d (D-5).
- **Gates:**
  - MySQL, Edge return/refund/shift/ingestion classes: 75/75 (1172 assertions).
  - MySQL, A's continuity classes: 29/29.
  - Fast suites Unit/Edge + Feature/Edge: 472 (1 skipped by design).
- **System map:** rebuilt — 288 tables / 340 inferred links / 762 screens. Since CP4 the only map-relevant changes are
  three service files:
  - `DeviceProfileService`
  - `EdgeInboundReturnIngestionService`
  - `EdgeReturnEnvelopeBuilder`

## 7. Still open (not CP5)

- **Gap 2 (owner decision):** a held order with a partial online payment. Is the payment posted when paid, or at finalize?
  0.9 keeps such tables Cloud-only (`PAID_IN_PART_ONLINE`, safe).
- **CP6:** release-signed 0.9.0 from the final integ (lead, custody keystore) → LAB acceptance on the owner's GO. The real
  Khatri update comes only after LAB acceptance and a separate owner GO.
- **Code-signing certificate:** still absent (SmartScreen warning on the wizard/installer).
- **0.8.5 task bug:** the `[TimeSpan]::MaxValue` bug in the installed 0.8.5 is fixed in 0.9's Register-EdgeServices. Khatri
  runs the patched copy.
