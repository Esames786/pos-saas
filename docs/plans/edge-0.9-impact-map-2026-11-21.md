# Edge 0.9 — impact map, stream ownership and cross-stream contracts (Checkpoint 1, 21 Nov 2026)

Baseline (frozen): tag `edge-0.9-baseline-20261121` = commit `7d7da307` = Edge `82b8966b` (brief + Khatri on-site fixes) merged with
production `dc0babbf` (clean merge). Brief: `docs/plans/edge-0.9-wizard-and-continuity-brief-2026-11-21.md`. Do not chase moving
canonical HEAD; the lead rebases streams only at integration points.

## 1. Impact matrix (from `docs/system-map/system-map.json` + code)

| component | tables (side) | routes / screens | existing tests that must stay green |
|---|---|---|---|
| Live-state mirror (Cloud read) | shifts (tenant, used_by 97), restaurant_table_sessions (45), sales_orders (171; status `held` = held bills, open dine-in orders), sales_order lines/payments, kot_batches (35) / kot_batch_lines, terminals (153) | NEW `GET /api/edge/live-state` (central domain, `edge.device.auth`) | EdgeBootstrap*, EdgeConfigRefresh*, EdgeBranchServerRegistrationTest (census) |
| Mirror store + adopt (appliance) | NEW edge-local `edge_mirror_*`; shifts, restaurant_table_sessions, sales_orders, kot_batches on the edge-local DB | `edge.local.pos.*` shift open/close, POS load, restaurant tables, held sales (EdgeLocalShiftController, EdgeLocalPosController, EdgeLocalRestaurantController, EdgeLocalHeldSalesController) | EdgeSharedPosRegressionGate(MySql), SharedPosViewRenderTest, EdgeLocalShift*, EdgeCashierPrinting*, EdgeNoDuplicatePrintAfterSync |
| Merge on handback (Cloud ingestion) | edge_inbound_sale_ingestions, shifts, sales_orders, payments, cash movements, kot_batches, print_jobs | `POST /api/edge/sync/sales` (EdgeInboundSaleIngestionService), EdgeHandbackOrchestrator | EdgeSyncIngestionHttp*, EdgeHandbackOrchestratorHttp*, EdgeAuthorityLeaseHttp*, EdgeCloudReservationFence*, EdgeNoDuplicatePrintAfterSync |
| Cloud setup / downloads / releases | edge_devices (master, used_by 7), edge_pairing_codes (7), NEW edge_setup_sessions, NEW edge_releases, NEW edge_devices columns | `/settings/offline-edge*` (OfflineEdgeController: index, download, pairing-code, security, revoke), NEW setup-code, counter-setup, phone page; NEW `/api/edge/setup/*`, `/api/edge/device/status`, `/api/edge/device/lan-profile`, `/api/edge/updates/*` | OfflineEdge* feature tests, EdgePairing*, EdgeEnrollment*, EdgePermissionCatalogTest |
| Server wizard + auto-update (appliance) | edge_local_updates (19 cols), edge_local_meta | scripts/edge (Install/Update/Register…), EdgeUpdateInstaller / EdgeUpdateVerifier / EdgeUpdatePackageService, `edge:local:update`, `edge:local:schema-upgrade`, EdgeSupervisionPlan | EdgeUpdate*, EdgeClean Machine*, EdgeTenantMigration*, labcopy rehearsal |
| Counter setup | none (Cloud renders from the device's LAN profile) | NEW counter-setup download + phone onboarding page | new renderer tests |
| Packaging | none | EdgePackageBuilder (`edge:build-package`, `edge:audit-package`), scripts/edge/*.ps1 | EdgePackage*, boundary audit |

Safety core that NO stream modifies (except the single guarded hook noted for A): EdgeAuthorityService, EdgeAuthorityLeaseService,
EdgeAuthorityLeaseClient, BranchOperatingModeService (Cloud fencing), EdgeAuthorityApiController, EdgeHandbackOrchestrator gates,
EdgeSyncOutboxService identity rules, EdgeLocalPrintWorker transport, `AUTO_FAILOVER_ENABLED=no`.

## 2. Streams (one worktree, one branch, one DB set, one port range, one log dir each)

| stream | worktree / branch | DB set (names contain `edge` where guarded) | ports | logs |
|---|---|---|---|---|
| A Continuity | `D:/laragon2/www/pos-saas-e09-a` / `edge09/a-continuity` | `pos_test_master_e09a`, `pos_test_tenant_e09a_edge`, `pos_test_edge_local_e09a` | 9811–9819 | `C:\Users\Dell\BingooEdgeLab\e09\a\logs` |
| B Wizard + auto-update | `…-e09-b` / `edge09/b-wizard` | `pos_test_master_e09b`, `pos_test_tenant_e09b_edge`, `pos_test_edge_local_e09b` (+ rehearsal `e09b_rehearsal_edge_local`) | 9821–9829 | `…\e09\b\logs` |
| C Cloud | `…-e09-c` / `edge09/c-cloud` | `pos_test_master_e09c`, `pos_test_tenant_e09c_edge`, `pos_test_edge_local_e09c` | 9831–9839 | `…\e09\c\logs` |
| D Counter setup | `…-e09-d` / `edge09/d-counter` | `pos_test_master_e09d`, `pos_test_tenant_e09d_edge`, `pos_test_edge_local_e09d` | 9841–9849 | `…\e09\d\logs` |
| E Packaging | `…-e09-e` / `edge09/e-packaging` | `pos_test_master_e09e`, `pos_test_tenant_e09e_edge`, `pos_test_edge_local_e09e` | 9851–9859 | `…\e09\e\logs` |
| Lead integration | `…-e09-integ` / `edge09/integ` | `pos_test_master_e09i`, `pos_test_tenant_e09i_edge`, `pos_test_edge_local_e09i` | 9801–9809 | `…\e09\integ\logs` |

### Exclusive file ownership (a stream edits only its own paths; anything else = message the lead)

- **A**: `database/migrations/edge/*mirror*`, `database/migrations/tenant/*continu*` + `*continues_cloud_shift*`, `app/Services/Edge/Continuity/**`
  (new), `app/Services/Edge/EdgeInboundSaleIngestionService.php` (merge only), `app/Services/Edge/EdgeSaleEnvelopeBuilder.php`,
  `app/Http/Controllers/Edge/EdgeLocalShiftController.php`, `EdgeLocalPosController.php`, `EdgeLocalRestaurantController.php`,
  `EdgeLocalHeldSalesController.php`, `app/Support/Pos/PosRuntime.php` + `CloudPosRuntimeFactory` + `EdgePosRuntimeFactory`
  (continuity keys only), shared POS Blade partials for the continuity banner/draft badge, ONE guarded call in
  `app/Services/Edge/EdgeAuthorityTick.php` (standby phase only, try/catch, never touches heartbeat/state), tests `tests/**/*Continuity*`, `*Mirror*`.
- **B**: `scripts/edge/wizard/**` (new), `scripts/edge/Setup-BingooEdge.cmd` (new), `scripts/edge/Install-EdgeAppliance.ps1`,
  `scripts/edge/Update-EdgeAppliance.ps1`, `scripts/edge/Register-EdgeServices.ps1`, `app/Services/Edge/EdgeSupervisionPlan.php`
  (new updater task), `app/Services/Edge/AutoUpdate/**` (new), `app/Console/Commands/EdgeLocalAutoUpdate*`, `EdgeLocalUsersCommand`
  (new `edge:local:users --json`), `EdgeLocalReport*Command` (new: status + LAN profile reporters), `EdgeUpdateInstaller` only if a
  hook is unavoidable (tell the lead), the status-page "Update" block in `resources/views/edge/health.blade.php` (update block only),
  `config/edge.php` keys under `edge.auto_update.*` + their allowlist lines, tests `*Wizard*`, `*AutoUpdate*`.
- **C**: `routes/edge.php` (new endpoints only), `routes/tenant.php` offline-edge lines, `app/Http/Controllers/Edge/EdgeLiveStateApiController.php`,
  `EdgeSetupApiController.php`, `EdgeDeviceStatusApiController.php`, `EdgeUpdatesApiController.php` (all new),
  `app/Services/Edge/Cloud/**` (new: LiveStateService, SetupSessionService, ReleaseRegistry, BulkEnrollment),
  `app/Http/Controllers/Tenant/OfflineEdgeController.php`, `resources/views/tenant/offline-edge/**` (except D's partial),
  master migrations `database/migrations/*edge_setup_sessions*`, `*edge_releases*`, `*edge_devices_install_status*`,
  `app/Models/Master/EdgeDevice.php`, `EdgeRelease`, `EdgeSetupSession` (new), `app/Console/Commands/EdgeReleasePublishCommand.php` (new),
  `app/Services/Edge/OfflineEdgeEntitlementService.php` (gate 3 from edge_releases), `PosPermissionCatalog` entries for new permissions, tests `*OfflineEdge*`, `*EdgeSetup*`, `*EdgeRelease*`, `*LiveState*`.
- **D**: `app/Services/Edge/EdgeCounterSetupRenderer.php` (new), `resources/edge/counter-setup/**` (new templates), 
  `resources/views/tenant/offline-edge/partials/phone-onboarding.blade.php` (new), `docs/operator-khatri/Setup-KhatriCounter.ps1`
  (reference only), tests `*CounterSetup*`.
- **E**: `app/Services/Edge/EdgePackageBuilder.php`, `app/Console/Commands/EdgeBuildPackageCommand.php`, `EdgeAuditPackageCommand.php`,
  `app/Services/Edge/Packaging/**` (new: PhpIniValidator etc.), `scripts/edge/appliance/**`, `scripts/edge/Install-Edge*Task.ps1`,
  `scripts/edge/New-Edge*.ps1`, `scripts/edge/Test-EdgeCashierTrust.ps1`, `scripts/edge/README.md`, tests `*Package*`, `*PhpIni*`.
- **Lead only**: `config/edge.php` `route_allowlist` + `EdgeBranchServerRegistrationTest` census (streams send the route names),
  `docs/system-map/**`, `docs/plans/**`, merges, MySQL full suites, signing with the release key.

## 3. Cross-stream contracts (build against these; fakes allowed until integration)

**K1 Live-state mirror** (C serves, A consumes). `GET /api/edge/live-state?since=<revision>` (device auth, branch from device).
Response 200:
```json
{"revision":"<monotonic string>","generated_at":"ISO8601","branch_id":1,
 "shifts":[{"shift_uuid":"…","terminal_id":4,"status":"open","opened_at":"…","opened_by_user_id":5,"opening_cash":"1500.00",
            "running":{"cash_sales":"…","card_sales":"…","refunds":"…","cash_in":"…","cash_out":"…","expected_cash":"…"}}],
 "table_sessions":[{"session_uuid":"…","table_id":12,"opened_at":"…","covers":4,"waiter_user_id":3,
            "orders":[{"order_uuid":"…","order_no":"…","status":"open","shift_uuid":"…","subtotal":"…","discount":"…","tax":"…","total":"…",
                       "lines":[{"line_uuid":"…","product_id":7,"variant_id":null,"qty":"2","unit_price":"…","notes":"…","modifiers":[],
                                 "kot_state":"fired","kot_batch_uuid":"…"}],"payments":[{"payment_uuid":"…","method":"cash","amount":"…"}]}]}],
 "held_sales":[{"order_uuid":"…","held_at":"…","terminal_id":4,"customer_id":null,"total":"…","lines":[…same line shape…]}]}
```
Rules: only state of the device's branch; money as decimal strings; no customer PII beyond ids; `revision` changes on any change;
`304`-style `{"unchanged":true,"revision":…}` when `since` equals the current revision. A stores rows read-only until adoption.

**K2 Setup session** (C serves, B consumes). Owner on the Cloud page clicks "Set up a Branch Server" → a **setup code** (10 chars,
60 min, single use, scoped tenant+branch, created by a user with `tenant.offline-edge.security`). Wizard: `POST /api/edge/setup/redeem`
`{"setup_code":"…","installation_uuid":"…","device_name":"…"}` (throttled) → `{"setup_token":"<opaque, 60 min>","cloud_url":"https://bingoopos.com",
"tenant_code":"…","branch_id":1,"branch_name":"…","pairing_code":"<fresh, 15 min>","enrollment_public_key":"<base64>",
"update_public_key_fingerprint":"a2eaf8a3d3e66a48","maintenance_window":{"start":"03:00","end":"05:00","tz":"Asia/Karachi"}}`.
The wizard then runs the EXISTING `edge:local:pair` with that pairing code (no pairing change). The owner's Cloud password never
reaches the appliance.

**K3 Bulk enrollment** (C serves, B consumes). `POST /api/edge/setup/enrollment-assertions` (device auth + header `X-Edge-Setup-Token`)
`{"user_ids":[1,3,4]}` or `{"all_active":true}` → `{"assertions":[{"user_id":1,"employee_code":"OWNER1","name":"…","assertion":{payload,signature}}],
"skipped":[{"user_id":7,"reason":"no_employee_code"}]}`. Uses `EdgeEnrollmentIssuer` per user; issuer = the setup session's owner;
one audit line per assertion. B enrolls each with `edge:local:enroll <file> --type=pin --credential-file=<tmp> --no-interaction`.
PIN policy in the wizard: individual PIN per user by default; a "same PIN for selected users" option exists only behind an explicit
warning checkbox; brute-force throttles on Edge login unchanged.

**K4 Device status** (C serves, B calls). `POST /api/edge/device/status` `{"phase":"installing|paired|bootstrapped|enrolled|ready|updating|updated|update_failed|rolled_back",
"version":"0.9.0-edge","ok":true,"message":"…","at":"ISO8601"}` → stored on `edge_devices` (new nullable columns) → shown on the Cloud page.

**K5 LAN profile** (C stores, B reports, D renders). `POST /api/edge/device/lan-profile` `{"lan_hostname":"bingoo-edge.local","lan_ip":"192.168.100.52",
"https_port":8443,"ca_certificate_pem":"-----BEGIN CERTIFICATE-----…","ca_sha256":"…"}` (public material only).

**K6 Releases + auto-update** (C serves, B consumes, E produces packages). `GET /api/edge/updates/latest` (device auth) →
`{"available":true,"version":"0.9.1-edge","manifest_sha256":"…","package_hash":"…","signing_key_id":"…","size_bytes":…,
"download_url":"<short-lived signed URL>","min_from_version":"0.9.0-edge","maintenance_window":{…}}` or `{"available":false}`.
Download streams the published zip. Publishing: `php artisan edge:release:publish <package-dir>` (C) verifies the package with the
existing verifier and registers it in `edge_releases`. Appliance (B): `edge:local:auto-update` (task `BingooEdgeUpdater`, every 15 min)
→ checks → downloads → verifies signature + manifest hash with `EdgeUpdateVerifier` → stages → applies ONLY when authority=standby,
outbox pending=0, inside the maintenance window, or on "Update now" (status page / Cloud flag) → health check → pointer rollback
when health fails AND the plan had no schema changes; with schema changes the update refuses unless a pre-update backup succeeded
(rollback = restore). Reports K4 phases.

**K7 Counter setup** (D renders, C serves). `EdgeCounterSetupRenderer::renderWindows(array $lanProfile, string $onlinePosUrl, string $branchLabel): string`
(PowerShell text, CA PEM embedded, hosts line, 3 shortcuts with the Bingoo icon, TLS-validated check, no bypass) and
`phoneOnboarding(array $lanProfile): array` (login URL by IP, CA download URL, per-OS steps) for D's partial. C adds routes
`GET /settings/offline-edge/branches/{branch}/counter-setup` (download) and `…/phone` (page with QR) on the Offline Edge screen.

**K8 Package layout** (E owns builder, B owns wizard files). The builder packages `app/scripts/edge/wizard/**` and
`scripts/Setup-BingooEdge.cmd` at the package root; `Install-EdgeAppliance.ps1` keeps its parameters (the wizard calls it);
default `-CloudUrl` = `https://bingoopos.com`; php.ini validated (relative `extension_dir`, no absolute host paths, `cacert.txt`).
Rehearsal packages are signed with a DEV key generated by E under `C:\Users\Dell\BingooEdgeLab\e09\dev-signing\`; the release key
is used only by the lead at Checkpoint 6.

## 4. Dependencies and critical path

- A needs K1 (fake until C lands) — A can build mirror store, adopt and merge fully against fixtures.
- B needs K2/K3/K4/K5/K6 (fake HTTP until C lands) and E's package layout (K8).
- D needs K5 data (fixture) — independent.
- C independent; first to integrate (unblocks B end-to-end and A live).
- E independent; second to integrate (unblocks B's packaged wizard and the update rehearsal).
- Critical path: A (adopt + merge financial invariants) and B (wizard + auto-update) → lead integration → 0.8.5→0.9 rehearsal on a
  disposable copy → LAB acceptance.

## 5. Rules for every stream (summary of the brief §6 + owner GO)

Separate worktree/branch/DB set/ports/logs; never touch another stream's files, production, Khatri, the real LAB (`C:\Users\Dell\BingooEdgeLab\lab*`,
ports 9701/3316/3317), the release keystore/passphrase, shared MySQL 3306 configuration, Windows scheduled tasks/services/firewall/cert
stores on this laptop. Tests: SQLite fast suites and TARGETED MySQL classes (own DB set, one run at a time); never the full MySQL suite
(lead only, serialized). Secrets never on argv/logs/git/manifests. Commit on the stream branch with clear messages and push it.
