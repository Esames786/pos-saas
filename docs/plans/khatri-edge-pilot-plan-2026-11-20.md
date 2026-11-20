# Khatri — Edge offline pilot plan and go-live readiness (20 Nov 2026)

Owner, 20 Nov: "finalize whatever setup is needed to turn offline on at Khatri now; from tomorrow we just go live."
This document answers that honestly from the code and the production checkout, finalizes everything that can be finalized
without touching production, the real LAB or Khatri, and lists the decisions only the owner can take. Nothing below was
executed against production, the LAB or the Khatri branch.

## 1. Verdict

**Edge cannot go live at Khatri tomorrow.** The production Cloud does not carry the server half of Edge that the 0.8.3-edge
appliance talks to, and the Khatri plan has the `offline_edge` module disabled. Tomorrow's visit is the site survey and the PC
preparation (`docs/status/khatri-network-survey-prompts-2026-11-21.md`). The earliest realistic go-live is after the Cloud
deployment in §3 step 1, and that deployment is a change to all tenants, so it is the owner's decision, not a "setup".

## 2. Blockers found (facts, read-only)

| # | blocker | evidence | who resolves |
|---|---|---|---|
| B1 | **Production Cloud lacks the Edge API** the appliance needs: it has only `/edge/api/pair`, `/device/me` and the bootstrap snapshot routes. Missing: `/compatibility/report`, `/sync/sales`, `/sync/reconcile`, `/sync/baseline`, `/authority/heartbeat`, `/authority/handback`, `/config/refresh`, `/returnable/refresh`, `/sync/returns`, supplier-finance and purchase-return refresh/ingestion, `/backup/recovery-keys`. Without heartbeat and sync the appliance never reaches STANDBY_READY and can never sync a sale. | production checkout `D:/laragon2/www/pos-saas` (branch `feat/14d-2-plan-upgrade-requests`, head be3693e4) `routes/edge.php` vs this branch; `git diff --stat` = 159 Edge Cloud-side files / 31,704 lines; 31 Cloud migrations (central + every tenant DB) not in production; 271 Edge commits since the merge-base 5c74107f; production has 12 newer commits (SLIP-END-LINES-1, PRINT-OFFLINE-HERE-1). `git merge-tree` production ↔ Edge: conflicts only in `docs/system-map/*` (regenerated), code auto-merges, but `PrintJobService` / `receipt.blade` / `kot.blade` / `pos/index.blade` are touched by both sides and need a review + the MySQL suite (PRINT-OFFLINE-HERE-1 makes the counter print its own slips when the agent is silent — must be re-proven against the Edge "no duplicate print after sync" contract). | owner decision (deploy to production = all 9 tenants) → then engineering (merge, suite, system map, backup, `deploy.sh`) |
| B2 | **Khatri entitlement**: the `offline_edge` module is DISABLED on plan `khatri_restaurant` (onboarding record 9 Aug: "offline_edge DISABLED, entitlement authority BLOCKED proof"). Pairing codes and enrollment assertions are issued from `/settings/offline-edge/*`, which that entitlement gates. | `docs/onboarding/khatri-biryani-2026-08.md`; `OfflineEdgeEntitlementService::MODULE_KEY = 'offline_edge'`; `routes/tenant.php` lines 187–199 | owner (plan / subscription change on production) |
| B3 | **The Khatri PC needs what the package does not bundle**: a MySQL 8 server (`Install-EdgeAppliance.ps1 -DbHost/-DbPort/-DbUser/-DbPasswordFile`; package contains app, gateway nginx, php, scripts, templates, update — no MySQL), an elevated install for the service account `NT AUTHORITY\LOCAL SERVICE` scheduled tasks and the inbound firewall rule for the gateway (8443/80), a fixed LAN IP on the `.100` main LAN, a branch CA + server certificate (`New-EdgeBranchCA.ps1`, `New-EdgeServerCertificate.ps1`, SAN = hostname + reserved IP), and on every cashier device: the CA trusted + a hosts line. | package `scripts/README.md` and installer param block; LAB record (P5C) firewall + trust notes | owner (host changes on the Khatri PC, each one asked immediately before) |
| B4 | **Pairing, bootstrap, enrolment and the first lease need the internet AND the deployed Cloud** (B1). An appliance that was never paired can never enter Local Mode offline (fail-closed `AUTHORITY_TAKEOVER_SAFE`). | `EdgeAuthorityService::gates()`, `EdgeLocalPairCommand` | sequencing |
| B5 | **The owner's own acceptance items 1–9 were never executed on the real LAB** (Phase C PIN, 3317 switchover, real second-laptop Local Mode sale, FakePrinter KOT, reconnection exactly-once, reconciliation, handback, 0.8.3 LAB install, reboot + physical printer). The 0.8.3 package has run only on the disposable `labcopy-082`. | `docs/status/edge-interactive-acceptance-runbook-2026-11-17.md` | owner: do them first, or accept the pilot without them in writing |
| B6 | **Manual failover policy**: `AUTO_FAILOVER_ENABLED=no` is a permanent rule. When Khatri's internet drops, a person must run `edge:local:authority-takeover --confirm --by=<name>` on the appliance (and the handback afterwards). Who at Khatri does that, from which screen, with which training — undecided. | `edge-operating-model-scope-lock` | owner |

## 3. The shortest honest path

| step | what | owner gate | rough effort |
|---|---|---|---|
| 0 | **21 Nov visit**: site survey (prompt pack), PC inventory, printers 9100, cashier-device subnets, WAN-pull LAN test. No install. | none (read-only) | half a day on site |
| 1 | **Cloud deployment of the Edge server half**: merge production (be3693e4) into the Edge branch, review the four shared printing/POS files, full MySQL suite + fast suites, rebuild the system map, then merge back to the production branch; verified backup; `deploy.sh` (31 migrations across central + 9 tenant DBs); post-deploy checks on every tenant; Khatri's live counter must keep printing (PRINT-OFFLINE-HERE-1 unchanged). | **GO** for a production deployment that touches all tenants | 1–2 working days incl. a full suite run; deployment itself in a quiet window |
| 2 | **Khatri entitlement**: enable `offline_edge` on the Khatri plan/subscription on production; confirm `/settings/offline-edge/security` opens; issue the pairing code and the cashier/approver enrollment assertions there (owner's browser; the code file is written by the owner, never shown). | **GO** (production tenant mutation) | 30 min |
| 3 | **Khatri PC preparation** (on site, elevated, each change asked immediately before): MySQL 8 server + `bingoo_edge` DB user (password in a file), fixed IP / DHCP reservation on the `.100` main LAN, branch CA + server cert, firewall rule for the gateway, cashier devices trust the CA + hosts line. | **GO** per host change | half a day on site |
| 4 | **Install 0.8.3-edge from the kit** (`Install-EdgeAppliance.ps1 -PackageRoot <kit>\BingooEdge-0.8.3-edge -DbUser … -DbPasswordFile … -CloudUrl https://khatribiryani.bingoopos.com -PairingCodeFile … -DeviceName … -EnrollmentAssertionFile … -RecoveryKeyFile … -LanHostname bingoo-edge.local -LanIp <reserved ip> -CertPfx … -CertPfxPasswordFile …`), with internet: pair → bootstrap v8 → enrol → first heartbeat → stock baseline (Khatri is 100 % service-based, so the stock baseline is trivial) → STANDBY_READY. The branch keeps selling on the Cloud as today; Edge is a warm standby. | **GO** for the install | 1–2 h on site with internet |
| 5 | **Supervised first Local Mode at Khatri, off-hours**: owner present, Cloud reachable; simulate WAN loss by stopping the uplink at the owner's hand → takeover → one cash sale → receipt on PRINTER-1, KOT on PRINTER-2 → reconnect → sync exactly-once → handback. This is acceptance items 3–7 on the real site instead of the LAB (B5, owner's call). | **GO** before the WAN pull, before takeover, before handback | 1–2 h off-hours |
| 6 | **Go-live = standby armed** + the failover policy (B6) written and trained: who runs takeover and handback, the phone number to call, the "red light" the cashier sees. | owner policy | — |

## 4. Finalized today (no production, LAB or Khatri state moved)

- **USB install kit staged**: `C:\Users\Dell\.bingoo-edge-release\kits\khatri-20261121\` = the signed, unchanged 0.8.3-edge package
  (copy of `releases\BingooEdge-0.8.3-edge`, manifest hash verified against the held original after the copy) + `site-survey\Khatri-NetSurvey.ps1`.
  The signing keystore and passphrase are NOT in the kit (custody rule). The appliance verifies the package signature itself at install.
- **Survey prompt pack + script** (`docs/status/khatri-network-survey-prompts-2026-11-21.md`, `tools/site-survey/Khatri-NetSurvey.ps1`).
- **Printer / offline pre-flight answers** (`docs/status/khatri-offline-printer-preflight-2026-11-20.md`).
- **This plan**, with the installer command skeleton (§3 step 4) and the Cloud merge probe (B1) done read-only.

Not done, on purpose: no merge of production into the Edge branch (feature freeze + "no canonical catch-up" still stand until the
owner takes decision D-A), no production deployment, no entitlement change, no package rebuild, nothing on the real LAB.

## 5. Decisions the owner must take (answer each with yes / no / when)

- **D-A** Deploy the Edge server half to production (all tenants, 31 migrations) — this is the real gate for any Khatri offline mode.
- **D-B** Enable `offline_edge` on the Khatri plan and issue pairing + enrollment on production.
- **D-C** Host changes on the Khatri PC: MySQL 8 install, elevated appliance install, firewall rule, fixed IP, CA trust on cashier devices.
- **D-D** Run the LAB acceptance items 1–9 first, or accept the Khatri off-hours supervised exercise (§3 step 5) as the acceptance.
- **D-E** Failover policy with `AUTO_FAILOVER_ENABLED=no`: who runs takeover / handback at Khatri, and how they are reached.
