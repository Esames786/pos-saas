# Phase 3 Stage A — the route swap: `/edge/local/pos` IS the shared cashier view

Date: 4 Oct 2026. Branch `feat/edge-config-refresh-v1` (HEAD `fd8f495` + uncommitted team work). Team C.
Owner directive (Phase 3 §3 + §4 Stage A + §6A): *"change edge.local.pos.screen so the real Edge POS route renders
resources/views/tenant/pos/index.blade.php through the shared POS layout/runtime contract. The old Edge POS page must no
longer be the runtime path. Require the Edge settle printing intents now that the shared frontend always sends them,
matching Online. Do not change authority, stock, sync, printing or financial ownership rules. Do NOT delete
resources/views/edge/pos/** in the same first route-swap commit."*

Safety: source + isolated automated tests only (`_edgewt_t12` databases). No commit, no LAB, no production, no dev-server
database. The dev Edge instance on 127.0.0.1:8095 serves this worktree live: `GET /edge/local/pos` and
`GET /edge/local/pos/shared` both answer 302 → `/edge/local/login` (unauthenticated), `/edge/local/health` 200.

```
ROUTE_SWAP=edge.local.pos.screen (GET /edge/local/pos) → EdgeLocalPosController::sharedScreen (tenant.pos.index + layouts.pos + EdgePosRuntimeFactory)
OLD_PAGE_RUNTIME_PATH=NONE (no route action, no file under app/ or routes/ renders edge.pos.*; EdgeLocalPosController::screen() = delegate to sharedScreen, 0 callers)
OLD_VIEWS_DELETED=NO (Stage B, owner directive) — resources/views/edge/pos/** (22 files) still on disk, unreachable
SHARED_ALIAS=/edge/local/pos/shared KEPT answering (same action; Team R's render gate GETs it) — Stage B decides keep / redirect / drop
HELD_SETTLE_PRINT_INTENTS=REQUIRED (both; Online's 422 {message, errors.printing} verbatim)
STRICT_GATES=GREEN — EDGE_POS_CUTOVER_STRICT=1: Feature/Edge 167 tests / 36,536 assertions, Feature/Pos 26 / 456 (incl. EdgeSharedPosRegressionStaticGateTest strict); render half EdgeSharedPosRegressionGateMySqlTest green in the _t12 filter
FEATURE_GATES=GREEN — normal mode: Feature/Edge 167 / 36,536, Feature/Pos 26 / 456 (6 Oct 2026, coordinator re-run after the session cut)
MYSQL_FILTER=GREEN — the team filter on _t12: 237 tests / 5,025 assertions, 4 skipped (guarded seed + census pointer), 3 migrated assertions repaired by the coordinator afterwards (Collection vs array payload compare ×2; data-gated ids waiter-roster / deadSessionModal; return page needs ?sales_order_id= and a manager-required branch for the approval needles) → 16 / 392 green on re-run
ASSERTION_MIGRATION=21 classes: 145 re-targeted / 214 kept / 90 retired (reasons per row in §2)
```

## 1. What changed (source)

| File | Change |
|---|---|
| `routes/edge_runtime.php` | `Route::get('/', [EdgeLocalPosController::class, 'sharedScreen'])->name('screen')` — the route NAME `edge.local.pos.screen` is unchanged (login landing, status/health page, allowlist, proof tools, URI census all use it). `GET /shared` keeps the same action as a Phase 2 alias (comment updated). |
| `app/Http/Controllers/Edge/EdgeLocalPosController.php` | `screen()` is now a 4-line delegate to `sharedScreen()`; the 200-line old view-model (`edge.pos.index`, `#edge-pos-data`, `vm` keys) is removed — it was the only `view('edge.pos.…')` under `app/`, which Team R's strict gate forbids. Every other method stays (see §4: `menuPayload()` is the shared menu's source, not old-page-only). |
| `app/Services/Edge/EdgePosRuntimeFactory.php` | `SHARED_PAGE = '/edge/local/pos'` (was `/edge/local/pos/shared`) → `POS_RUNTIME.routes.posIndex`, the split-bill page's top-window breakout, every `POS.route('posIndex')` deep link now land on the canonical page. |
| `app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php` | `settleHeldSale`: after `validate()` (`kot_print_intent` / `receipt_print_intent` stay `nullable|in:print,skip` so an INVALID value still answers `errors.kot_print_intent` like Online's `validateSale`), a settle missing either intent throws `ValidationException::withMessages(['printing' => 'Choose the Direct Pay KOT and Receipt intent before completing the sale.'])` — byte-identical to `SalesOrderController::store` on `tenant.pos.store` (422 `{message, errors.printing}`), BEFORE terminal/authority work. No authority, stock, sync, printing or finance rule changed. |
| `config/edge.php` | allowlist unchanged in content; comments on `edge.local.pos.screen` (THE page) and `edge.local.pos.shared` (Phase 2 alias) updated. |
| `app/Support/Pos/PosPermissionCatalog.php` | the `tenant.pos.index` Edge enforcement note names `EdgeLocalPosController@sharedScreen`. |
| `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` | URI census unchanged in content (`edge/local/pos`, `edge/local/pos/shared` both stay approved); comments updated. |
| `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php` | `markTestSkipped` in `setUp` (the smaller option): after the swap it would compare the Online view with itself; the message points at `EdgeSharedPosRegressionGateMySqlTest` + `EdgeSharedPosRegressionStaticGateTest` + `docs/status/edge-phase3-census-replacement.md`. Class + fixture are deleted in Stage B; the fixture is still READ by Team R's gate (category b). |
| `tests/MySql/EdgeCashierShellHttpMySqlTest.php` | rewritten against the shared view (§2). |
| 14 other MySQL tests | assertion migration (§2) + print intents on every held settle (§3). |
| `docs/status/edge-phase3-stage-a-route-swap.md` | this report. |

Not changed on purpose: `EdgeLocalAuthController::landingUrl` (already `/edge/local/pos`), `resources/views/edge/health.blade.php` and the
finance pages (already link `url('/edge/local/pos')`), `EdgeStandbyAdvertiser` (carries no POS URL), the shared views (Team M), Team R's
gate tests and the proof tools (Team P2). `EdgeLocalPosController::storeSale` (Edge Direct Pay) still accepts absent intents — the
directive named the settle; Online's `tenant.pos.store` requires them, so the coordinator may want the same rule there (one `if`).

## 2. Assertion migration — the 20 MySQL classes that GET `/edge/local/pos`

Legend: **R** re-targeted to the shared view's equivalent · **K** kept untouched (HTTP/JSON or already Online markup) · **X** retired
(proved something that existed only on the old Edge page — reason given). Counts are assertion statements in the affected tests.

| File | R | K | X | What moved / why retired |
|---|---|---|---|---|
| EdgeCashierShellHttpMySqlTest (9 tests) | 44 | 31 | 23 | **R**: 29 Online ids (+ `#pos-runtime-slot/-state/-sub/-pending`, `#pos-edge-chrome/-data`, `#pos-edge-logout-form`), `<h1 class="h3 mb-0">Restaurant POS</h1>`, bound branch = disabled `#branch_id` + `LABELS['branchSelect']` title + hidden `branch_id` carrier + selected option "Shell Branch", `POS_RUNTIME` identity/transport/capabilities, mode tabs `class="mode-tab active" data-mode-tab=…`, hidden `#order_type`, `applyModeTab` confirm text, `pos-controls-locked`, terminal list scoped by `UserDataScope` (pinned → only Counter One; change-terminal → both), Return / Quick Report `@can` (absent without `tenant.sales-returns.create` / `tenant.pos.quick-report-send`; present + not disabled + `data-return-url=/edge/local/pos/sales-returns/create?embed=1` with), shift badge `fetch(POS.route('shiftStatus')…)`, `'No open shift'`/`'Shift open'`, 5-min resync, `#pos-shift-open-link → /edge/local/pos/shifts/open`, 401/419 → `transport.unauthenticated_redirect`, calculator ids + 17 keys, shortcuts `event.key === 'f'/'h'/'l'/'p'/'Enter'/'m'`, deep links (`?mode=takeaway` active tab, `held_sale_id` carrier, recall island), Online stylesheet set + favicon through `/edge/local/assets`, assets linked through the allowlisted route. **K**: every JSON/HTTP step (GET /shift 422/200/401, terminal select, shift open, redirect to login, every linked stylesheet fetched + no `@import`/remote `url()`, Nunito woff2 served, login page assets/"Branch Server"), foreign-URL scan, `@import`, `fonts.googleapis`. **X**: old wrappers `#pos-header`/`#pos-title-row`; the Edge nav strip `#edge-nav`/`#edge-nav-links`/`#sync-chip`/`#health-link`/`#logout-btn`/`#shift-btn` and its position checks (owner A5: zero Edge-only layout — status lives in the shared slot, logout in the hidden CSRF form, shift open via the shared link); `#suppliers-link`/`#journal-link` absence (no finance nav on the shared view, see §5); `<span class="pos-title-pre">Restaurant</span>`; `#pos-context-change-btn` + `"canChangeTerminal"` (the shared view gates the terminal LIST, not a button; server gate proven in EdgeCashierPermissionMatrixMySqlTest); `"canSalesReturn"` + `w1Wire(...)`; old W1 script contract (`api('GET','/shift'…)`, `toast/confirmDialog/showSpinner/setButtonBusy/showInlineError`, `#toast/#edge-loading/#edge-confirm`, `LOGIN_URL`, `k === 'Escape'`, `q.get(...)`, `loadHeld/startCheckOnSession`); 11 hand-written CSS rules (`grid-template-columns:minmax(0,1fr) 500px`, breakpoints, `--bg/--primary`) — the shared view links Online's own `style.css`; geometry equality is the gate's skeleton + `geometry-compare.mjs`; data-URI icon (`<link rel="icon" href="data:image/svg+xml,`) — the shared layout links Online's favicon locally. |
| EdgeCashierPrintingParityHttpMySqlTest | 23 | 12 | 8 | **R**: the 17 W5 ids are Online ids (unchanged); old JS names → shared functions (`processDirectPayPrinting`, `promptPrintHere`, `openRecentPrints`, `reprintSale`, `billPreview`, `refreshPrintPanel`, `handleReminderPlan`), `terminalPrintConfig`/`receiptLayouts` islands, `POS.route('kotQueue'/'receiptQueue'/'reminderConfirm'/'reminderReprint'/'printRetry'/'printDocument'/'printJobsForSale'/'billPreview'/'printingRetry')`, runtime routes `printPreferences`/`reminderConfirm`. **X**: `printPrefsHtml/readPrintPrefs/printBillPreview(payload)/openPrintHere(job)/openLastPrint(saleId/openKotReminder`, `'/print-preferences'`, `'/reminders/confirm'` literals (old page script). |
| EdgeCashierScreenRendersHttpMySqlTest (3 tests) | 6 | 9 | 3 | **R**: `assertViewIs('tenant.pos.index')`, `<h1 class="h3 mb-0">Restaurant POS</h1>`, `window.POS_RUNTIME = {"mode":"edge"`, default terminal `var userDefault = String(<id> \|\| '')`, baseline stock `"stock_by_branch":{"<branch>":10}`, pinned operator `"terminal_selection":"session"`. **K**: View Tables / Review &amp; Pay / Preview Bill / Counter One / Chicken Tikka / Family Deal / Counter Two absent / 302 to login / 200. **X**: `edge-pos-data`, `"defaultTerminalId"`, `"operationalStockReady"`, `"canChangeTerminal"` (old island keys). |
| EdgeCashierQuickReportParityHttpMySqlTest | 4 | 13 | 2 | **R**: `POS.route('quickReportSave'/'quickReportSettings')` + their runtime route templates. **K**: 11 Online ids/needles (`quickReportModalLabel`, `'qr-save'`, `qr-panel-*`, `'qr-print'`, `w.print()`), every JSON step. **X**: raw `/quick-report/save-settings`, `/quick-report/settings` path literals (the island JSON-escapes slashes). |
| EdgeSupplierFinanceHttpMySqlTest | 3 | 6 | 4 | **R**: POS page = `tenant.pos.index` carrying no finance path; the Suppliers page links back to `url('/edge/local/pos')` (`>POS</a>`); the cashier still gets the POS. **K**: every finance-page needle + every 403. **X**: `#suppliers-link`/`#journal-link` present/absent by permission — the shared view has no Edge nav strip (§5); the permission is still proven by the 403s. |
| EdgeCashierReturnParityHttpMySqlTest | 2 | 6 | 2 | **R**: `#pos-return-btn` present → absent after revoking `tenant.sales-returns.store` + `tenant.sales-returns.create` (the Online `@can`). **X**: `"canSalesReturn":true/false` page flag. |
| EdgeCashierReturnHttpMySqlTest | 12 | 1 | 9 | **R**: `#pos-return-btn` + `data-return-url=/edge/local/pos/sales-returns/create?embed=1` + `#pos-return-frame` + runtime `salesReturnSearch`; the Edge return page (`tenant.sales-returns.create`) carries `#refund_method`, `#refund_amount`, `'Manager approval'`, `action_type: 'sales_return'`, form action `/edge/local/pos/sales-returns`, the search + manager-verify runtime routes and `LABELS['refundOnlineRequired']`. **X**: old inline return UX (`/returns/search`, `/returns/sales/`, `'/returns'`, `rt-step`, `qty_step`, `outstanding_delivery`, `needs_manager_approval`, `sales_return`, `needs the Online POS`) — the Return now opens the SAME tenant page Online opens. |
| EdgeCashierMenuHttpMySqlTest | 1 helper + 1 | 24 | 1 | **R**: `vm()` reads the Online page-data contract (`viewData('productsPayload'/'combosPayload'/'categories'/'pillCategoryIds'/'contentCategoryIds'/'hasUncategorizedCombos'/'branches')`), mapping `stock` = `stock_by_branch[branch]`, `stock_kind` from `is_stock_tracked`, variant `price`/`stock` from `selling_price`/`stock_by_branch` — every A3–A9 assertion kept verbatim; 26 Online control ids kept. **X**: `default_variant_id` (not an Online payload key; the tile price = default variant price is still asserted). |
| EdgeCashierPaymentHttpMySqlTest | 9 | 27 | 5 | **R**: cash first via `viewData('paymentMethods')`; card / bank options rendered `disabled` in the Online select (capability `nonCashTender` off), `"nonCashTender":false` + the runtime label, `"tips":true` + `"tipOnPaidSale":false`. **K**: 24 ids, every 422/201 + DB check. **X**: old `tenderMethods[].offline/hint`, `tipsSyncable` island keys. |
| EdgeCashierConnectionStateHttpMySqlTest | 8 | — | 4 | **R**: shared slot ids, `data-sync-url=/edge/local/pos/sync/summary`, the `authority` block shape (state/label/sub_label/can_mutate/tone/pending_sync/needs_attention/connection/connection_label), `setAuthority(a)`, no lease identifiers. **X**: `#sync-chip`, `s.connection`, `'INTERNET CONNECTION LOST'`/`'LOCAL MODE ACTIVE'` script literals (the words now come from `EdgePosRuntimeFactory::authorityBlock` server-side; the sync-summary JSON tests in the same class are untouched). |
| EdgeCashierDineInHttpMySqlTest | 2 | 2 | — | **R**: `"hidden":true/false` → Online key `"pos_grid_visible":false/true`. **K**: `assertSee('Hidden Later Karahi')`, `"name":"Hidden Later Karahi"`. |
| EdgeCashierPermissionHttpMySqlTest | 2 | 1 | 2 | **R**: `#complete-sale-btn` absent/present (Online `@can('tenant.pos.store')`). **K**: `a counter will close the bill` hint. **X**: `"canCompleteSale":false/true` flag. |
| EdgeCashierOrderLifecycleHttpMySqlTest | 23 | 68 | 20 | **R**: old W3 script names → shared functions (`openTableWorkspace`, `loadHeldSales`, `loadRecentSales`, `coLoadTableSessions`, `clearCart`, `updateStartFreshLabel`, `showVoidReasonModal`, `applyTableSession`, `postTableOperation`, `showTableMove`, `refreshTableBoard`, `recallHeldSale`, `fireKotSilently`, `handleKotAfterSale`, `openRecentPrints`), `POS.route('recentSales'/'tableBillRequested'/'heldCancel'/'tableMove'/'tableSessions')`, `data-board-url`, runtime `heldReattach`/`tableMerge`. **K**: the 68 W3 Online ids. **X**: `openHeldOrders/openCompletedOrders/openChangeOrder/newSale/voidSentLine/renderSessionBar/requestBill/moveTable/mergeTables/viewTables/recallList/fireKot/kotAfterHold/handlePrintJobs/openLastPrint/tablePayload` and the `'/recent-sales'`/`'/bill-requested'`/`'/reattach-table'` literals. |
| EdgePurchaseReturnHttpMySqlTest | 4 | 6 | 3 | **R**: POS page carries no purchase-return path; the screen links back to `url('/edge/local/pos')`; cashier still gets the POS; store-only user reaches the screen but `can_post=false`. **X**: `#purchase-returns-link` present/absent ×3 (no Edge nav strip; permissions still proven by the 403s / `can_post`). |
| EdgeCashierDealsDiscountsHttpMySqlTest | — | 1 | — | `"name":"Family Deal"` (Online `@json` of combosPayload) — unchanged. |
| EdgeCashierReservationHttpMySqlTest | — | 1 | — | `assertSee('View Tables')` — unchanged. |
| EdgeCashierRouteGatesHttpMySqlTest | — | 2 | — | 403 without `tenant.pos.index` / 200 with — `sharedScreen` has the same `abort_unless`. |
| EdgeCashierPermissionMatrixMySqlTest | — | 2 | — | `assertWorkflowOk(GET /edge/local/pos)` + the `tenant.pos.index` 403 probe. |
| EdgeCashierShiftAndNetworkDownHttpMySqlTest | — | 1 | — | the page GET (200) before the sale. |
| EdgeCleanMachineInstallMySqlTest | 2 | 1 | — | `'Cashier POS'` → `'Restaurant POS'`; `#health-link` → `#pos-runtime-slot` (clean-install test; nginx/PHP-CGI harness — NOT run by this team, needs the appliance toolchain). |
| EdgeCashierControlCensusHttpMySqlTest | — | — | 4 tests skipped | superseded (§1); deleted in Stage B. |

Totals: __MIGRATION_TOTALS__. No workflow, permission or authority assertion was weakened: every 403/422/409/201, every DB check and every
JSON step is untouched; permission-gated UI is asserted on the Online `@can` elements of the shared view instead of old page flags.

### 2.1 Deliberate inversions (documented, not weakenings)

- `#branch_id`: the old test asserted it is ABSENT ("bound appliance — no branch selector"); on the shared view it is PRESENT, `disabled`,
  with `LABELS['branchSelect']` and a hidden `branch_id` carrier (owner A5: identical geometry, Cloud-only control disabled in place).
- `#pos-return-btn` / `#pos-quick-report-btn`: old page = rendered `hidden data-denied="1"` without the permission; shared view = NOT
  rendered (Online `@can`). The gating permission for Return is `tenant.sales-returns.create` (the Online button), so the tests revoke it
  together with `tenant.sales-returns.store`.

## 3. Held settle: print intents REQUIRED (owner §6A)

Online `SalesOrderController::store` on `tenant.pos.store` refuses a Direct Pay without BOTH intents with
`ValidationException::withMessages(['printing' => 'Choose the Direct Pay KOT and Receipt intent before completing the sale.'])`
→ 422 `{"message": "...", "errors": {"printing": ["..."]}}`. `EdgeLocalHeldSalesController::settleHeldSale` now does exactly that after
the field validation (so `maybe` still answers `errors.kot_print_intent`, as before), before any terminal/authority work.

Tests updated (every held settle now sends `kot_print_intent`/`receipt_print_intent` = `skip`, the Edge-local parity of what the shared
page sends; the printing test keeps its print/print, skip/skip and replay cases):
`EdgeHeldSettlePrintingMySqlTest` ("intents absent" → three refusal cases `[null,null]`, `['print',null]`, `[null,'skip']` asserting
`message` + `errors.printing.0`, the sale stays `held`, no state, no job; method renamed `…absent_intents_are_refused_like_online…`),
`EdgeDiscountFlowMySqlTest` (helper adds the pair), `EdgeCashierDealsDiscountsHttpMySqlTest` (3), `EdgeCashierDineInHttpMySqlTest` (2),
`EdgeCashierPermissionHttpMySqlTest` (2), `EdgeCashierPermissionMatrixMySqlTest` (3), `EdgeCashierTablesWorkspaceHttpMySqlTest` (6),
`EdgeLocalRestaurantHttpMySqlTest` (3), `EdgeManagerApprovalEligibilityHttpMySqlTest` (shared `$body`),
`EdgeSharedPosViewMySqlTest` (1 + the split-bill breakout now asserts `/edge/local/pos?held_sale_id=`), `EdgeW6ContractEnvelopeHttpMySqlTest` (1).
`EdgeSharedPosContractMySqlTest` already sent both.

## 4. Stage B deletion list (after the owner accepts the replacement gate)

| Item | Callers after Stage A | Note |
|---|---|---|
| `resources/views/edge/pos/index.blade.php` | 0 routes / 0 `view()` | + `partials/` (banners, cart, grid, header, shell, styles) + `js/` (actions, boot, cart, catalog, commercial, context, core, held, payment, printing, reports, returns, shift, sync, tables) — 22 files |
| `EdgeLocalPosController::screen()` | 0 (delegate) | the route points at `sharedScreen`; the method exists only so the old name still renders the shared view |
| `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php` + `tests/Fixtures/edge/online-pos-control-census.json` | skipped / fixture read by Team R's gate (category b) | delete together once the gate no longer reads the fixture |
| `tests/Feature/Edge/EdgeBladeCompileGateTest::test_the_cashier_page_script_parses_as_javascript` | lists `views/edge/pos/index.blade.php` + resolves `@include('edge.pos.js.*')` | drop that entry (the `views/edge/**` glob keeps the finance pages) |
| `tests/Feature/Edge/EdgeArtifactTest` manifest | lists `resources/views/edge/pos/index.blade.php` | replace by `resources/views/tenant/pos/index.blade.php` + `layouts/pos.blade.php` + partials/js (the artifact must ship the shared view) |
| `Gate::OLD_EDGE_ONLY_IDS` (Team R) | frozen list | stays as the only reference once the folder is gone (the static gate already handles the absent folder) |
| route `edge.local.pos.shared` (`GET /edge/local/pos/shared`) + allowlist + census row | Team R's render gate GETs it; `POS_RUNTIME` no longer points at it | keep / 301 to `/edge/local/pos` / drop — Stage B decision; `shared/shifts…`, `shared/sales-returns…` index/show pages replace the old Edge finance screens at `shifts`, `shifts/{shift}`, `sales-returns`, `sales-returns/{salesReturn}` (W-B report §2) |
| Edge POS routes no `POS_RUNTIME` route template references | — | candidates to review, NOT to delete blindly: `/preview-bill` (old page's zero-mutation preview; the shared view quotes through `/totals/quote`), `/restaurant/board` (JSON board; the shared view uses `/restaurant/board/html`), `/restaurant/table-sessions/{session}` (JSON detail), `/held-sales/{sale}/split` (JSON split; the shared view uses the split-bill page), `/sales/{sale}/kot-reprint`, `/returns`, `/returns/sales/{sale}`, `/returns/{return}` (old inline return flow; the shared view uses the tenant return page + `/returns/search`), `/quick-report/email` (capability off → `null`), `/shifts`, `/shifts/{shift}`, `/sales-returns/{salesReturn}` (old Edge finance list/detail screens, superseded by the `shared/…` tenant views), the supplier-finance / purchase-return / manual-journal screens (their own pages, reached by URL — see §5). Each still has HTTP tests; none is a runtime path of the cashier page any more. |

`menuPayload()`, `boardFloors()`, `recipeStockLookup()`, `safeCollection()`, `previewBill()` etc. are NOT old-page-only — `sharedMenu()` /
`sharedPageData()` / the restaurant controller use them.

## 5. Gaps surfaced by the swap (for the coordinator — not changed here)

1. **No visible entry from the POS page to the Edge-only screens.** The old page's Edge nav strip (`#health-link`, `#suppliers-link`,
   `#journal-link`, `#purchase-returns-link`, `#logout-btn`, `#sync-chip`) is gone by design (owner A5). On the shared view the Edge
   chrome is hidden (`#pos-edge-chrome`: data island + CSRF logout form, no button); `#pos-sidebar-toggle` reveals only the zero-geometry
   theme hook. `POS_RUNTIME.routes` carries `status` (health) and `logout`, but no supplier-finance / journal / purchase-return key. The
   finance screens remain reachable by URL and from each other's nav (each links back to `/edge/local/pos`). Owner decision needed:
   where the Branch Server's health / logout / finance entry points live on the shared view (e.g. a runtime-driven menu behind
   `#pos-sidebar-toggle`, or the status slot overlay).
2. `EdgeLocalPosController::storeSale` (Edge Direct Pay) still accepts absent print intents (`nullable`); Online `tenant.pos.store`
   requires them. Same one-`if` rule as the settle if the owner wants it.
3. `EdgeCleanMachineInstallMySqlTest` needs the appliance toolchain (nginx/PHP-CGI); its two page needles were updated but not run here.

## 6. Gate results

__RESULTS__

## 7. Commands

```
export PATH=/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH
# strict cutover (forced) — static half + render half
EDGE_POS_CUTOVER_STRICT=1 php vendor/bin/phpunit tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php
MSYS_NO_PATHCONV=1 EDGE_POS_CUTOVER_STRICT=1 DB_DATABASE=pos_test_master_edgewt_t12 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t12 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t12 \
  php vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeSharedPosRegressionGateMySqlTest
# feature suites (normal + strict)
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe php vendor/bin/phpunit tests/Feature/Edge
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe php vendor/bin/phpunit tests/Feature/Pos
EDGE_POS_CUTOVER_STRICT=1 … (same two)
# the team's MySQL filter on the isolated databases
MSYS_NO_PATHCONV=1 DB_DATABASE=pos_test_master_edgewt_t12 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t12 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t12 \
  php vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashier|EdgeSharedPos|EdgeHeld|EdgeDiscountFlow|EdgeLocalPos|EdgeLocalRestaurant|EdgeSupplierFinance|EdgePurchaseReturn|EdgeReturn|EdgeShift|EdgePrint|EdgeManagerApproval|EdgeQuickReport|EdgeW6ContractEnvelope'
```
