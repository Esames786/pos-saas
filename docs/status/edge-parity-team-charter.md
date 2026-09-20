# Offline Edge — cashier parity implementation: TEAM CHARTER (binding for every implementation agent)

Owner directive 20 Sep 2026 ("FULL CASHIER PARITY IMPLEMENTATION"), after the 149-record audit (commit b678568).
Baseline you build from: `docs/status/edge-online-vs-edge-screen-audit-2026-09-20.md` + `docs/status/audit-2026-09-20/team-*.md`
(your team's appendix is your backlog), the execution board `docs/status/edge-parity-execution-board.md`, and the control census
`tests/Fixtures/edge/online-pos-control-census.json`.

## 0. Non-negotiable rules (from the owner)

1. **The Online POS is the UI and functional reference.** `resources/views/tenant/pos/index.blade.php` (+ the tenant controllers and
   services it calls) define WHAT and HOW IT LOOKS/BEHAVES. Do not reinterpret "parity" as a reduced screen. Where Online has a
   control, modal, validation, permission or printing outcome, Edge gets the same operator experience — through Edge-local routes.
2. **Edge architecture stays:** local routes under `/edge/local/*` (`routes/edge_runtime.php`), local auth (`edge.auth`+`edge.branch`),
   Edge authority/lease, local JSON APIs. Never call a Cloud endpoint from the page. Never add an external asset (CDN/font/script);
   everything is inline or under `public/` (already packaged). Do not point the Online frontend at Cloud endpoints.
3. **Shared business rules are shared services** (`SalesTotalsService`, `PrintRoutingService`, `ShiftService`, `KotCancellationService`,
   `ManagerApprovalService`, `PrintJobService`, …). Reuse them. Never fork a Cloud rule into an Edge-only copy.
4. **Do not touch:** the outbox/sync engine, the authority lease / state machine, F1/F2/F3 financial event semantics, the Cloud
   ingestion contract (`EdgeSaleEnvelopeBuilder`, `EdgeInboundSaleIngestionService`) — Team 6 designs contract changes; you STOP and
   write the needed field(s) in your report instead. Do not create new financial event types.
5. **Owner-dependent items are NOT started** (isolate them in your report): offline bank-transfer/cheque/other tenders & refunds,
   Close Branch / Daily Closing posting, manual-journal reversal, purchase-return drafts, customer/printer administration on Edge,
   changing the Cloud-only reporting scope. Do not invent an ONLINE_REQUIRED exclusion; do not silently omit a feature.
6. **LAB & production safety:** never touch `C:\Users\Dell\BingooEdgeLab\*`, any `pos_tenant_*`/`pos_saas_*` database, Kashif Food or any
   live tenant, deployment or configuration. No reboot, no elevation, no cable, no router, no Local Mode, no P6.
7. **No git operations.** Do not commit, push, stash, checkout, reset, or create worktrees. The coordinator integrates and commits.
   Do not run `composer`/`npm` installs. Do not delete files you do not own.

## 1. File ownership (edit ONLY what your team owns; read anything)

| Team | Owns (write) | Shared files you may APPEND to, inside YOUR marked block only | Never edit (report a request instead) |
|---|---|---|---|
| **1 — shell / navigation / states / responsive (W1)** | `resources/views/edge/pos/partials/{styles,header,banners,shell}.blade.php`, `resources/views/edge/pos/js/{core,context,sync,boot}.blade.php`, `resources/views/edge/auth/login.blade.php`, `resources/views/edge/health.blade.php` (look only), new `tests/MySql/EdgeCashierShellHttpMySqlTest.php` | `routes/edge_runtime.php` W1 block, `EdgeBranchServerRegistrationTest` W1 block | other teams' fragments/controllers |
| **2 — real menu & sale workflows (W2)** | `partials/{grid,cart}.blade.php`, `js/{catalog,cart,commercial,payment}.blade.php`, `app/Http/Controllers/Edge/EdgeLocalPosController.php`, `app/Services/Edge/EdgeLocalPosService.php` (line resolution, validation, sale/preview — coordinate with Team 3 for held/table methods: you own the file, Team 3 requests), new tests `tests/MySql/EdgeCashierMenu*HttpMySqlTest.php` | W2 blocks | envelope builder / Cloud ingestion (Team 6), non-cash tenders (owner) |
| **3 — tables & order lifecycle (W3)** | `js/{held,tables,actions}.blade.php`, `app/Http/Controllers/Edge/EdgeLocalRestaurantController.php`, `EdgeLocalHeldSalesController.php`, `app/Services/Edge/EdgeTableReservationService.php`, NEW services `app/Services/Edge/EdgeLocalTable*Service.php` / `EdgeLocalOrderLifecycleService.php`, new tests `tests/MySql/EdgeCashierTables*HttpMySqlTest.php`, `EdgeCashierOrderLifecycle*` | W3 blocks | `EdgeLocalPosService` (Team 2 — send a precise request), KOT cancellation service (Team 6 reconcile) |
| **4 — shifts / permissions / finance (W4)** | `js/{shift,returns,reports}.blade.php`, `EdgeLocalShiftController.php`, `EdgeLocalReturnController.php`, `EdgeLocalManagerApprovalController.php`, `EdgeQuickReportController.php`, `app/Services/Edge/EdgeLocalReturnService.php`, `EdgeQuickReport*`, `resources/views/edge/finance/*.blade.php` + `EdgeLocalSupplierFinanceController/Service`, `EdgeLocalPurchaseReturnController/Service`, `config/edge.php` manifest `capabilities` ONLY, tests `EdgeCashierShift*`, `EdgeCashierReturn*`, `EdgeCashierQuickReport*`, `EdgeSupplierFinance*`, `EdgePurchaseReturn*` | W4 blocks | owner-dependent finance items |
| **5 — printing & documents (W5)** | `js/printing.blade.php`, `EdgeLocalPrintJobController.php`, `app/Services/Edge/EdgeLocalPrint*Service.php`, `app/Console/Commands/EdgeLocalPrintWorkerCommand.php`, KOT reminder path on Edge, `tests/MySql/EdgeCashierPrinting*`, `EdgeLocalPrint*` | W5 blocks; `app/Services/Printing/PrintJobService.php` + `PrintRoutingService.php` ONLY with an additive change that keeps every Cloud test green (run `tests/Feature` printing tests) | a second print agent (forbidden), printer CRUD/health pages (owner) |
| **6 — Cloud/Edge contract, reconcile, release (W6)** | `docs/status/edge-w6-contract-and-reconcile-plan.md` (design + reconcile assessment); NO code in wave 1 | — | everything else until the coordinator schedules the reconcile |
| **Coordinator (W0/W7)** | `resources/views/edge/pos/index.blade.php`, `tests/Fixtures/edge/online-pos-control-census.json`, `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php`, `tests/MySql/Support/*`, `tools/*`, `docs/status/edge-parity-execution-board.md` | — | — |

Census fixture rows you implement: DO NOT edit the fixture. List every Online id your work flips (planned → present/equivalent/partial,
or partial → present) in your report with the Edge selector; the coordinator flips them and re-runs the census gate.

## 2. Definition of done for ONE record (audit A#/R#/D-#/E-#)

Your report must give, per record you touched: `RECORD`, `ONLINE_BEHAVIOUR` (file:line of the Online view/controller/service),
`EDGE_IMPLEMENTATION` (files), `PERMISSION_AND_VALIDATION` (the Online route permission / rule you mirrored), `EXECUTABLE_TEST`
(test class::method, over the real `/edge/local/*` route), `BROWSER_ACCEPTANCE_STEP` (what the coordinator clicks on the dev instance
to see it; the `#id`s you added), `CENSUS_FLIPS` (Online id → state → Edge selector), `REMAINING_DIFFERENCE` (honest), `STATUS`
(one of the audit statuses). No percentage. No "done" from a label or HTML string alone.

## 3. Tests — how to run without colliding with other teams

The MySQL harness truncates tables, so each team uses ITS OWN databases (auto-created, migrated on first run ≈ 1 min):

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_tN EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_tN EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_tN   # N = your team number
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'YourTestClass|EdgeCashierScreenRendersHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest'
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest   # must stay green; if a planned row now exists it FAILS on purpose → list the flip in your report
vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php        # route census + artifact boundary
```
Never run `./test-mysql.sh` (shared DB names). Never run the browser proof with `--allow-mutations` (the coordinator does); read-only
`node tools/edge-browser-proof/edge-pos-proof.mjs --base-url http://127.0.0.1:8095 --user DEVCASH1` with `EDGE_PROOF_PASS_ENV=EDGE_DEV_CASHIER_PASS`
and `EDGE_DEV_CASHIER_PASS=CashierPass1` is allowed (dev instance; `tools/edge-dev-instance/README.md`). Blade fragments: no `{{`/`@word(`
inside JS except intended Blade; the composed script must pass `node --check` (the census test does this).

## 4. Style & behaviour rules for the page

- Keep the ONE IIFE model: your fragment's `function`s are visible to every other fragment; do not introduce ES modules or globals
  beyond `window.EdgePOS`. New dialogs render into the shared `#modal` (Team 1 may evolve the shell; keep `openModal/closeModal/toast`).
- Every new control gets a stable `id` mirroring the Online id where one exists (`#hold-sale-btn`, `#qtyEntryModal`…); the census
  and the browser proof target ids.
- Server-side: every new endpoint enforces the Online route permission through `denyUnlessCan()` (trait `ResolvesEdgePosContext`),
  re-validates the terminal with `selectedTerminal()`, returns business messages (422), never a 500 for an operational refusal.
- Every new route: append to YOUR block in `routes/edge_runtime.php` AND YOUR block in `EdgeBranchServerRegistrationTest::$approved`
  (the census fails otherwise). Re-read both files after editing to make sure your block is intact (other teams edit other blocks).
- Held sales are local until settled; reservations are Edge-owned; anything that changes what reaches the Cloud is Team 6's contract.
