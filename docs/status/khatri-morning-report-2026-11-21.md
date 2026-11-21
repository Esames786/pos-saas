# Khatri morning report (21 Nov 2026, written ~05:00 PKT; final suite line added 06:30 PKT — the clean run ended 06:24)

Everything below was done without touching production, the real LAB, Khatri, other tenants or the held 0.8.3 package.

```
OWNER_UI=DONE on the Edge branch (a23d1f26) and in the integration candidate (35684723): the existing status page
  https://bingoo-edge.local:8443/edge/local/pos/health (after /edge/local/login) gained a "Selling authority" block — Internet/Cloud
  connection, who sells now (Cloud / this Branch Server), STANDBY READY / LOCAL ACTIVE pill, offline readiness with the blocked gates
  named, return readiness with the handback blockers named, config/stock freshness, sync pending/in-flight/needs-attention, printers —
  and two buttons: "Switch to Offline" and "Return to Online". No new dashboard, no engine change.
SECURE_TAKEOVER=button → POST /edge/local/pos/authority/switch-offline → EdgeAuthorityService::takeOver(confirmed=true, by=<session user>,
  acceptStale=FALSE, reason=null): enrolled local session + bound branch (edge.auth, edge.branch) + explicit permission
  tenant.offline-edge.security + browser confirm + confirm=yes field + CSRF (web group) + one appliance-wide lock + idempotent
  (already Offline → notice) + audit log [edge-authority-control]. The engine's refusals (Cloud lease still valid =
  AUTHORITY_TAKEOVER_SAFE, any gate, standby not fresh) are shown verbatim; no stale override exists on this surface; no automatic
  failover. Proven by EdgeLocalAuthorityControlHttpMySqlTest (7 tests) incl. a real-engine refusal in standby and a smuggled
  accept_stale field being ignored.
SECURE_HANDBACK=button → POST /edge/local/pos/authority/return-online → EdgeHandbackOrchestrator::run(by) — the SAME orchestrated
  handback the CLI uses (connectivity stable, Cloud fence, outbox clean, reservations, Cloud acknowledgement); blockers shown by code
  and detail (proven real: CONNECTION_NOT_STABLE with 0 acknowledged heartbeats), authority never moved on a blocked handback, idempotent
  when the Cloud already sells. CLI commands untouched as recovery tools.
CLOUD_BRANCH_FENCING=existing contract, verified by the green suites: BranchOperatingModeService (leaseBlocksCloud /
  shouldBlockCloudSaleMutation / assertSaleMutationAllowed / assertOfficialStockMutationAllowed) is called by SalesOrder, Shift,
  HeldSale, SplitBill, SalesReturn, RestaurantTable(+Session) and Branch controllers; tests EdgeAuthorityLeaseHttpMySqlTest
  (lifecycle heartbeat → takeover → flap → handback → expiry; the appliance reports only its own branch), EdgeCloudReservationFenceMySqlTest
  (Cloud reserve/unreserve refused in Local Mode, allowed otherwise), EdgeBranchServerFencingMySqlTest (the appliance refuses GL/stock/
  finance writes), EdgeHandbackOrchestratorHttpMySqlTest. The Cloud never resumes writing merely because the Internet returns: the lease
  holder flips to cloud only through the acknowledged handback.
PRODUCTION_INTEGRATION=candidate integ/khatri-cloud-edge-20261121 @ 35684723 (worktree D:/laragon2/www/pos-saas-integ-khatri, pushed):
  production be3693e4 + Edge a23d1f26; only docs/system-map conflicted (rebuilt); shared-view contract fix for PRINT-OFFLINE-HERE-1
  (runtime-map keys printHealth/printHereTake, no url() literals, identical skeleton on both runtimes). NOT merged into the production
  branch. Plan: docs/plans/khatri-cloud-edge-production-deployment-plan-2026-11-21.md; day runbook:
  docs/status/khatri-deployment-day-runbook-2026-11-21.md; GO/NO-GO: docs/status/khatri-cloud-edge-go-no-go-2026-11-21.md.
  CLEAN FULL-SUITE RUN on the 35684723 tree FINISHED 06:24 PKT: full MySQL suite 2794 passed / 1 failed / 0 errors (the failure =
  the pre-existing catering print-time debt); Feature+Unit 426 tests → 6 failures = the 6 pre-existing unit regressions that fail
  identically on the untouched Edge branch. No red outside the expected list; EdgeLocalAuthorityControlHttpMySqlTest 7/7 inside the
  run. GO/NO-GO verdict: GO for owner review; nothing deployed.
ALL_TENANT_MIGRATION_GATE=11 Cloud migrations (4 master on edge_* tables with 0 rows; 7 per tenant: 5 CREATE new edge_* tables,
  customers/customer_addresses ADD columns + batched backfill of derived phone_normalized/address_uuid, sales_returns ADD COLUMN
  INSTANT + ADD INDEX INPLACE LOCK=NONE with lock_wait_timeout 10). Rehearsed on disposable copies built from production's own migration
  set: master 0.4 s, tenant 0.9 s, idempotent, rollback --step 7/4 + re-migrate clean. 481 changed PHP files lint clean on PHP 8.2
  (the server runs 8.2.33). Production pre-flight (read-only): HEAD be3693e4, tree clean except 6 untracked .env backups, 13 tenants,
  150 GB free, baseline 20 ERROR lines today (19× pre-existing "pos_tenant_kashifkitchen.jobs doesn't exist" + 1 cURL). Backups:
  tenants:backup + master mysqldump in the runbook step 1.3, verified before any migration. Note for the owner: deploy.sh step 5 grants
  every tenant's Owner role the new tenant.offline-edge.* permissions as it does for every release (routes stay hidden/403 without the
  module); the customer backfill writes derived columns only.
ONLINE_PRINTING_REGRESSION=none found: PrintOfflineHereMySqlTest, ThermalSlipEndMySqlTest, EdgeCashierPrinting*, KotPerCategory,
  PrintRouting*, PrinterHealthLifecycle, CloudPrintAgentContract green on the candidate; the print agent 2.7.1 is unchanged; the counter's
  print-here only takes EXISTING queued/failed Cloud jobs under a row lock and Edge ingestion creates no print job and no KOT batch
  (EdgeNoDuplicatePrintAfterSyncMySqlTest: two payloads for the whole lifecycle) → no duplicate receipt/KOT on ingestion or handback.
EDGE_PACKAGE=0.8.3-edge HELD and immutable (manifest e62976f1…, package_hash 684ecf12…, 9093 files, verified in the kit by Agent D).
  NEW: 0.8.4-edge built + signed from a23d1f26 (C:\Users\Dell\.bingoo-edge-release\releases\BingooEdge-0.8.4-edge, package_hash
  497cf87e196cff92c22b744ce4e8d2a159169647c420a175fa9f9f3d49ab459f, edge:audit-package ok, source proof PASS 2361 files 0 mismatches,
  signing key a2eaf8a3d3e66a48; evidence C:\Users\Dell\BingooEdgeLab\evidence\release-0.8.4-edge\build\). Kit copy
  BingooEdge-0.8.4-edge-CANDIDATE\ (separate folder + README-CANDIDATE.md). Labcopy rehearsal PASS (Agent D, evidence
  evidence\release-0.8.4-edge\labcopy\): dry-run through the copy's real launcher verdict OK, plan hash
  sha256:20a8aad4febae276d8a5319caf0c717b77b2356c0cad17c6c7c6d0c105c12a95, 0 migrations; apply 0.8.2→0.8.4 in 5 min, ledger applied,
  artifact d26b8357… = signed; health 0.8.4; pointer rollback clean; unauthenticated probes on the staged runtime: /edge/local/health 200
  (0.8.4-edge), login 200, POST switch-offline without CSRF → 419, with a session token but no login → 302 to login, unknown → 404, never
  500, authority still standby. Real LAB untouched.
SIGNED_RELEASE_NEEDED=YES for the buttons at Khatri — the owner-control UI lives in the appliance code; the held 0.8.3 has no buttons
  (CLI only). Either install 0.8.3 (held, LAB-rehearsed) or 0.8.4 (buttons; needs the owner's separate installation approval and the
  D-D LAB certification on the same package).
PRODUCTION_DEPLOYMENT=DONE 21 Nov 05:33–05:35 PKT on the owner's "OWNER AUTHORIZATION: DEPLOY NOW" (limited to candidate 35684723;
  gates re-verified green, migration plan matched the 11 reviewed files before launch — fail-closed script). Merge dc0babbf
  ("Merge KHATRI-CLOUD-EDGE: …", candidate code 35684723 + docs 9a6c79e8, SAME file set as the candidate) pushed to
  feat/14d-2-plan-upgrade-requests; server fast-forwarded be3693e4 → dc0babbf via deploy.sh (detached, log
  /root/deploy-khatri-edge-2026-11-21-0533.log, copy in evidence\khatri-cloud-deploy-20261121\). Backups BEFORE: tenants:backup
  storage/app/private/backups/20261121_044508/ (master + 13 tenant .sql + storage.tar.gz, 1004 MB, manifest) + master mysqldump
  /var/backups/khatri-edge-master-2026-11-21-0457.sql.gz (gzip -t ok). Migrations: master 4 DONE (edge_branch_config_revisions,
  edge_devices compat columns, edge_backup_recovery tables, edge_devices widen), each of the 13 tenants holds the 7 new rows
  (verified in every tenant DB); "tenants ok=13 skipped=0"; migrate:status 0 pending; DEPLOY COMPLETE. Rollback remains
  `git reset --hard be3693e4 && bash deploy.sh` (tables stay inert).
POST_DEPLOY_HEALTH=GREEN at 05:38: 13/13 tenant /login 200; Khatri /pos 302 (auth), /settings/offline-edge 302 (auth, gate off);
  Edge API on bingoopos.com/api/edge: authority/heartbeat 401, compatibility/report 401 (device auth), pair 422 on an empty body;
  ERROR lines today 12 = the pre-deploy baseline (kashifkitchen jobs table, pre-existing); php-fpm + nginx active, queue:work running;
  EDGE_FEATURE_ENABLED=false; offline_edge pivot: khatri_restaurant is_enabled=0 (unchanged since Aug), the two plans that had it
  enabled since Aug unchanged, 204 pivot rows before and after (MasterSeeder re-touched updated_at on 4 unrelated disabled rows, values
  unchanged); master modules/plans/permissions/roles/users/tenants/subscriptions: 0 rows created or updated. Khatri tenant: 0 rows
  created/updated in permissions, roles, users, sales_orders, customers, customer_addresses, sales_returns (the 7 tenant.offline-edge.*
  permissions already existed; Owner role holds them, no other role); customers.phone_normalized backfilled 1727/1731 (derived column,
  updated_at untouched). Not verifiable at 05:38 (shop closed): a live sale and a live print — last sale 20 Nov 20:43, last print job
  20:52, print agent "dinein" last seen 21:44; the owner does runbook 1.7 at opening.
KHATRI_USB_KIT=C:\Users\Dell\.bingoo-edge-release\kits\khatri-20261121\ — BingooEdge-0.8.3-edge\ (held, unchanged), BingooEdge-0.8.4-edge-
  CANDIDATE\, mysql\ (8.0.30 binaries + my.ini.template + README-MYSQL.md), site-survey\Khatri-NetSurvey.ps1, docs\ (morning sheet,
  one-page, install worksheet, operator instructions, enablement procedure, deployment-day runbook, pilot plan, printer pre-flight,
  survey prompts), operator\ (BINGOO Online.url, BINGOO Offline.url, Branch Server Status.url, Install-Shortcuts.ps1, bilingual
  OPERATOR-GUIDE.md, CASHIER-CARD.md, ENROLLMENT-KHATRI.md, LAN-REACH-CHECK.md), KIT-CONTENTS.txt (SHA256s). No keystore, passphrase,
  credentials or tenant data (scanned).
KHATRI_SITE_CHECKLIST=docs\KHATRI-MORNING-ONE-PAGE.md (kit) = docs/status/khatri-morning-one-page-2026-11-21.md; details in
  khatri-morning-execution-sheet-2026-11-21.md (§1 safe now, §2 GO needed, §3 blocked until the Cloud deployment, §4 pass/fail, §5 evidence).
MORNING_INSTALL_READY=SURVEY + PC PREP: yes (read-only survey, printers 9100 connect-only, Cloud 443, MySQL 8 from the kit, reserved IP,
  CA/cert, firewall, cashier trust — each host change on your GO). APPLIANCE INSTALL/PAIRING: NO until the Cloud deployment (production
  has only pair + bootstrap endpoints today) and the Khatri offline_edge enablement (D-B, your GO).
BLOCKERS=1) DONE — deployed (see PRODUCTION_DEPLOYMENT); 2) Khatri offline_edge enablement + EDGE_FEATURE_ENABLED (D-B, your GO); 3) which package goes to Khatri
  (0.8.3 held vs 0.8.4 buttons) + the D-D LAB certification before any real takeover; 4) PC host changes on site (MySQL, IP, cert,
  firewall) on your GO; 5) operators named by you (none assigned).
NEXT_OWNER_ACTION=say "ENABLE KHATRI" → runbook 2.1 (EDGE_FEATURE_ENABLED=true) + the enrollment signing key (enablement procedure →
  "Enrollment signing key"; you receive the PUBLIC value) + 2.2 (offline_edge on khatri_restaurant only) + 2.3; then at opening do
  runbook 1.7 (POS + one real print); at the shop follow docs/status/khatri-onsite-install-steps-084-2026-11-21.md (0.8.4, real
  installer parameters) — phases 3–5 of the runbook are superseded by that file where they differ. No takeover today.
```

## Closing state, 21 Nov 14:00 PKT (on site, shop open, no offline use)

```
KHATRI_BRANCH_SERVER=INSTALLED 13:15 PKT — 0.8.5-edge on DESKTOP-B9MO2QG (static 192.168.100.52, https://bingoo-edge.local:8443), device
  a7d57f56-360c-42c1-b0e8-926b58289173, paired through https://bingoopos.com (central domain), bootstrap acknowledged, recovery key escrowed,
  7 services running, firewall rule, branch CA trusted on the PC, desktop shortcuts; STANDBY (DEGRADED only until the stock cache fills);
  6 users enrolled (OWNER1 own PIN; DELIVERY/DINEIN/TAKEAWAY/QUICKSALE/NOMAN = one shared PIN chosen by the owner). Nobody pressed Switch to Offline.
  The PC also runs the Online Print Agent (untouched). Evidence: C:\BingooEdge\evidence\ (shop PC), C:\Users\Dell\BingooEdgeLab\evidence\khatri-install-20261121\.
PACKAGE_USED=0.8.5-edge (0.8.4 failed on step [2]: php.ini carried laptop-absolute paths → portable runtime, see edge-package-portable-php-runtime-2026-11-21.md)
ON_SITE_DEFECTS=tenant-domain CloudUrl in docs (404 on pair; central domain is right) · Windows 10 rejects [TimeSpan]::MaxValue in Register-EdgeServices
  (fixed 29926f71; kit copy needs the workaround in onsite steps §5.5) · Notepad .txt pitfall · hidden PIN prompt · Blade printers row (fixed eacf489f)
STILL_OPEN=other counter devices: trust the branch CA + hosts line (or use the IP) · evidence to USB · LAB acceptance of 0.8.5 (D-D) · supervised
  takeover/handback drill with the owner present (off hours) · AUTO_FAILOVER_ENABLED=no stays · kit mysql README + template updated 21 Nov
```

Agent outputs: C (operator readiness) → docs/operator-khatri/ + kit operator\; D (package/printer/site) → evidence
C:\Users\Dell\BingooEdgeLab\evidence\khatri-readiness-20261121\ (0.8.3 immutable; TCP 9100 receipt delivered by the real print worker on
labcopy-082 to the FakePrinter on 9611, spool captured; mappings replicated on the appliance DB; no Local Mode, no takeover), then the
0.8.4 labcopy rehearsal (evidence\release-0.8.4-edge\labcopy\).
