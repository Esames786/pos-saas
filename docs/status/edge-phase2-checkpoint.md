# Next Edge release — Phase 2 checkpoint (shared Online POS view)

Date: 27 Sep 2026. Branch `feat/edge-config-refresh-v1`, HEAD **`885fae8`** (Phase 1 report was `599c5d0`). For the owner, before broad workflow migration.
Safety: source + isolated automated tests only. The installed 0.7.0 LAB appliance, the LAB Cloud (:9701), production and P6 were not touched;
no Local Mode, no takeover, no deploy, no new artifact.

```
SHARED_LAYOUT_STATUS=DONE — resources/views/layouts/pos.blade.php: the same 22 theme assets in the same order as layouts/app (via
                     $posRuntime->asset()), POS chrome rules unconditional, csrf meta, chrome slot (Cloud: existing header/sidebar
                     includes; Edge: hidden data island only), window.POS_RUNTIME + POS.route()/POS.api(); fonts-local.css linked before
                     style.css in layouts/pos, app and auth.
ONLINE_SAME_VIEW_STATUS=DONE — resources/views/tenant/pos/index.blade.php is THE cashier view for both runtimes: 0 url('/…') literals
                     left (58 replaced, 65 runtime-route call sites), asset() gone, capability-driven disabled state in place, manager
                     prompt credential type from the runtime, ONE shared status slot (260×28 in the title row, both modes), ONE shared overlay.
ONLINE_PIXEL_REGRESSION=NONE — Online before (clean export of 599c5d0, Google font allowed, steady state) vs Online after (this tree, local
                     Nunito): 1366×768 and 1024×768, 12 states each (main, category, customer, context, held, recent, quick report, table
                     workspace, cart, review & pay, qty entry, modifier entry): 0.000 % changed pixels outside the status-slot mask on 23 of
                     24 pairs, 0.076 % on one (modifier entry at 1024, sub-pixel), threshold 0.5 %. Measured boxes identical to 0.1 px.
POS_RUNTIME_STATUS=DONE — app/Support/Pos/PosRuntime.php (contract: 63 route keys, 20 capability keys, identity, authority, assets,
                     transport, managerCredential, labels, chromeView), CloudPosRuntimeFactory, EdgePosRuntimeFactory (every key defined
                     in both, proven by test), PosPageData (closed 23-key view contract produced by both controllers).
EXTERNAL_NETWORK_DEPENDENCIES=NONE — style.css no longer imports Google Fonts (A3); Nunito vendored (one variable woff2, OFL 1.1, licence
                     + README in public/assets/fonts/nunito); Poppins dropped (unused). Gates: no @import/remote url() in any linked CSS
                     (Feature + MySQL), served-asset set covers the whole layout list, Edge page contains no http(s):// asset URL.
EDGE_SHARED_VIEW_RENDER_STATUS=RENDERS — GET /edge/local/pos/shared (new route beside the old page, which stays as fallback) returns 200
                     rendering tenant.pos.index with POS_RUNTIME.mode=edge on the dev instance; screenshots
                     evidence\phase2\cp-edge-shared\{1366x768,1024x768} (24 states) show the Online layout/theme/geometry with the slot
                     reading the Edge authority label. Old Edge page NOT deleted (Phase 3 cutover).
CLOUD_ENDPOINT_LEAKS_IN_EDGE=NONE — EdgeSharedPosViewMySqlTest: the Edge-rendered page contains no /pos, /api/pos, /printing, /restaurant,
                     /held-sales, /shifts, /sales-returns Cloud path; every non-null POS_RUNTIME route resolves to an allowlisted
                     edge.local.* route; new routes added to the default-deny allowlist and the URI census deliberately (18 names).
ARTIFACT_BOUNDARY_STATUS=GREEN — EdgeApplianceArtifactBoundaryTest (new): fonts in the plan, style.css clean, fonts-local link order,
                     no edge.local.* route renders partials.header/sidebar (follows the isEdge() ternary @extends), untracked-file
                     refusal in the release builder; dependency-closure test now scans the shared views (TenantClock, VoidReason, User,
                     UserDataScope, CloudPosRuntimeFactory — all in the plan); Blade compile gate covers tenant/pos/** + layouts/pos;
                     the Cloud-only subscription composer is not registered on a Branch Server.
TEST_STATUS=Feature: tests/Feature/Edge 162/36,113 green; tests/Feature/Pos 19/390 green. MySQL (isolated per-team DBs): W-B filter
                     170 tests green after the contract-expectation updates; EdgeDiscountFlowMySqlTest 8/175; EdgeSharedPosView +
                     EdgeSharedPosContract 10 green; EdgeCashierPermissionMatrixMySqlTest + clean-machine 4/347 (clean-machine alone
                     160 assertions, 5:13); EdgeHeartbeatBuildReportMySqlTest 6/87; EdgeCashierControlCensus re-pinned + green (two
                     Online ids retired). FULL Edge MySQL gate on the integrated tree (729 tests, shared DBs): **OK — 729 tests / 7,977 assertions, 1 skipped, 0 failures, 0 errors
                     (31 min 17 s, 27 Sep 05:45–06:16; includes the load-sensitive partition tests, green in this run).** Known load-sensitive tests (Authority/Connection partition) re-run alone if
                     they fail under load, as in the 0.7.0 gate.
FILES_CHANGED=86 files, +9,028 / −285 vs 599c5d0 (resources/views 18, tests/MySql 17, tests/Feature 10, app/Http 10, app/Services 7,
                     public/assets 5, docs 5, app/Support 4, tools 3, app/Console 2, routes 1, config 1, database/migrations 1,
                     app/Providers 1, tests/Fixtures 1).
COMMIT=885fae8 (sets: 0726272 tools, 278648a pixel-diff, e623ae7 W-C, 72f1b0b W-F, 1a46ee5 W-E, 3bc4c97 W-A, 885fae8 W-B); pushed.
```

## Paired screenshots (same viewport 1366×768; 1024×768 alongside)

| # | Pair | Location |
|---|---|---|
| 1 | Online POS BEFORE refactor (clean export of 599c5d0 on the LAB tenant, Google font allowed, steady state) | `C:\Users\Dell\BingooEdgeLab\evidence\phase2\cp-online-before\1366x768\01-main.png` (+ 11 states) |
| 2 | Online POS AFTER shared `layouts.pos` (this tree, local Nunito) | `…\phase2\cp-online-after\1366x768\01-main.png` (+ 11 states); diffs `…\phase2\cp-diff-online-1366\*.diff.png` (red = changed, blue = status-slot mask) |
| 3 | FIRST Edge render of the SAME cashier view (dev instance, `/edge/local/pos/shared`, DEVCASH1) | `…\phase2\cp-edge-shared\1366x768\01-main.png` (+ 11 states) |

Pair 1↔2 matches except the approved shared status slot ("ONLINE · CLOUD" is new text in a box that exists in both modes). Pair 2↔3
is geometrically identical (same title row, tabs, buttons, cart panel, modal sizes); content differs only by dataset (dev seed vs LAB seed)
and slot text ("LOCAL MODE · MANUAL SWITCH" on the dev instance). A same-dataset pixel pairing is the Phase 5 gate.

## Decisions applied

A1 shared `layouts.pos` ✔ · A2 discount at payment (hold refuses; approval consumed at settle/complete; 8 regression tests) ✔ ·
A3 local fonts ✔ · A4 same shift pages, Edge scoped to the bound terminal (`tenant/shifts/*` rendered by Edge controllers with
`?embed=1`, gated on the Online names `tenant.shifts.create` / `close-form`) ✔ · A5 modified: shared status slot, identical geometry in
both modes, no Edge-only delta (measured) ✔ · A6 catalogue + template + audit command ✔ · extra requirement: separate screens
replicated from the same views (shift open/close/index/show, sales-returns create/index/show, split-bill), embedded by the POS exactly
as Online does (iframe of the Edge route) ✔.

## Open items carried into workflow migration (Phase 2 step 7 / Phase 3)

1. **SECURITY (owner decision before any template reaches a tenant):** on Edge `tenant.pos.void-kot-item` is both the cashier's void
   permission (the shared `KotCancellationService` checks the requester) and the approver marker (`MANAGER_ACTION_PERMISSIONS`), and
   `ManagerApprovalService` has no self-approval guard — a Cashier (Counter) user could approve their own discount/void/cancel/return
   with their own Edge credential. Online is protected by the separate manager PIN. Proposed fix: export a `has_manager_pin` flag per user
   in the bootstrap (mirrors "any manager-PIN holder approves"), require it in `EdgeLocalAuthService::verifyManager`, and refuse approver
   = requester. Note: a real tenant cashier who already holds void-kot-item on the installed 0.7.0 LAB build has the same exposure today.
2. `heldIndexPage` route key for the split-bill page's "Held Sales" link (both factories); quick-report `options` JSON twin on the Cloud.
3. Print intents on held settle; recipe "makeable" preview on tiles (Edge); quick-report 403 shape (`EdgeQuickReportController`).
4. `EdgeLocalPosController` still renders the OLD page on `edge.local.pos.screen`; cutover (route swap + deletion of
   `resources/views/edge/pos/**` + census retirement) is the last Phase 3 step after paired browser evidence.
5. Census caveat: its id inventory misses `id` attributes that are not the first attribute (e.g. `pos-shift-open-link`); it is retired at cutover.

## Dev-render infrastructure (LAB machine, disposable)

`Start-DevCloud.ps1 -Port 9702` (live worktree) / `-Port 9703 -Worktree cloud\src-599c5d0` (pre-change export), `.localhost` tenant domain
`edgehomelab.localhost` (row added to the LAB master), MasterSeeder plans/modules + an active `standard` subscription for the LAB tenant,
static router `tools/edge-dev-instance/router.php`, capture `tools/edge-browser-proof/pos-reference-shots.mjs` (blocks non-loopback hosts;
`--allow-fonts` only for the pre-change reference), compare `tools/edge-browser-proof/pixel-diff.php` (GD).
