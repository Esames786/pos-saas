# W-B — Edge runtime adapter + contract alignment + A2 + separate screens (Team B report)

Date: 27 Sep 2026 · branch `feat/edge-config-refresh-v1` (worktree `D:\laragon2\www\pos-saas-edge`, uncommitted — no commit/push by Team B).
Scope: architecture report §2/§3/§5/§6/§7/§11 W-B/§15 + owner decisions A2 (manual discount = settlement concern), A4 (same
shift pages, bound branch/terminal), A5-modified (no Edge-only layout delta; Edge status in the shared slot) + the extra owner
requirement (Online's SEPARATE screens rendered from the SAME tenant views by Edge controllers, embeddable with `?embed=1`).

## 1. Files

| File | Change |
|---|---|
| `app/Services/Edge/EdgePosRuntimeFactory.php` (NEW) | Builds `PosRuntime` (mode edge): every `ROUTE_KEYS` entry as an `/edge/local/…` template (null where the capability is off), §7 capability matrix, identity (bound branch, `branch_selectable=false`, `terminal_selection=session`, `selected_terminal_id`), authority block from the P0 lease / Q state machine (`state/label/sub_label/can_mutate/pending_sync/needs_attention/tone/connection`), assets `/edge/local/assets` + `/edge/local/storage`, transport (`X-CSRF-TOKEN`, json, `/edge/local/login`), `managerCredential=employee_code_and_credential`, `chromeView=tenant.pos.partials.pos-chrome-edge`, labels (`capability.<key>` hints + `capabilityOff`). Static contract helpers: `terminalOrCoded()` (422 → `code: INVALID_TERMINAL`), `adoptRequestedTerminal()` (request `terminal_id` → session selection, same checks as `/terminal/select`), `noOpenShift()` (`code: NO_OPEN_SHIFT`). |
| `app/Http/Controllers/Edge/EdgeLocalPosController.php` | `sharedScreen()` (route `edge.local.pos.shared`) renders `tenant.pos.index` via `PosPageData::fromArray(...)->toViewData($runtime)`; `sharedPageData()` = the exact Online variable set from the local DB; `sharedMenu()` (Online productsPayload/combosPayload shape over `menuPayload()` + Edge operational stock, local `/edge/local/storage` image URLs); `boardFloors()` (Online board shape + Edge reservations projected in memory); `serverTime()`, `totalsQuote()`, `promoQuote()` twins; customer search keys; terminal adoption/INVALID_TERMINAL. Old `screen()` untouched. |
| `EdgeLocalHeldSalesController.php` | settle accepts the full Online sale payload (+ discount/approval/promo/tip keys, A2); held list adds `sales[]` (Online ajaxList keys + `lines[]`); `TABLE_HAS_OPEN_ORDERS` 409; `NO_OPEN_SHIFT`; print job view `job_id/job_no/printer_id/printer_type/created_at_human`, path-only `preview_url`; recent sales `time` (Online format) + `ago` + `time_iso`; `openOrderView()`/`onlineLineView()` (O14 shape); split-bill page + page POST (top-window breakout like Online). |
| `EdgeLocalRestaurantController.php` | board HTML twin `{ok, html}` (shared partial, `posRuntime` passed); open-orders twin (O14); reservation accepts Online field names, answers `{ok:true, reservation}` (+ old flat keys), GET adds `ok` + Online `name/phone/*_display`; unreserve `ok`; open table `NO_OPEN_SHIFT`; INVALID_TERMINAL. |
| `EdgeLocalPrintJobController.php` | job views add `job_id`, `printer_id`, `created_at_human`; `preview_url` path-only; INVALID_TERMINAL. |
| `EdgeLocalManagerApprovalController.php` | 200 `{ok:true, approval_id, approval_no, approval_uuid}`; refusals `{ok:false, message[, errors]}`; route `throttle:10,1`. |
| `EdgeLocalReturnController.php` | `denyUnlessCan` 403 `{message, permission}` (no bare abort); search adds select2 `results/pagination`; separate screens create/index/show + create-form POST. |
| `EdgeLocalShiftController.php` | separate screens open/close/index/show + open-form / close-form POSTs (redirects like Online); shift badge: `?terminal_id=` empty → Online no-terminal answer; Online `posStatus` keys added; INVALID_TERMINAL. |
| `app/Services/Edge/EdgeLocalPosService.php` | A2 (sub-agent, see §4): hold refuses a manual order discount; revision writes none; settle applies discount + consumes approval; fixed ≤ bill validation (settle + Direct Pay). |
| `routes/edge_runtime.php` | W-B block (§2) + `throttle:10,1` on manager verify. |
| `config/edge.php` | 18 allowlist names (route_allowlist only). |
| `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` | 14 URIs added to the census (the task said tests/MySql/… — the census lives in tests/Feature/Edge). |
| `resources/views/tenant/pos/partials/pos-chrome-edge.blade.php` (NEW) | Edge chrome slot: hidden data island (authority + sync/status/logout/terminal routes) + hidden CSRF logout form; nothing that reflows. |
| 8 tenant views (coordinator-granted ONE line each) | `tenant/shifts/{open,close,index,show}`, `tenant/sales-returns/{create,index,show}`, `tenant/sales-orders/split-bill`: line 1 → `@extends(isset($posRuntime) && $posRuntime->isEdge() ? 'layouts.pos' : 'layouts.app')`. |
| `tools/edge-browser-proof/pos-reference-shots.mjs` | `--path` option (Edge default stays `/edge/local/pos`). |
| NEW tests | `tests/MySql/EdgeSharedPosViewMySqlTest.php` (5), `tests/MySql/EdgeSharedPosContractMySqlTest.php` (5), `tests/MySql/EdgeDiscountFlowMySqlTest.php` (8). |

## 2. New routes (all inside the `edge.auth` + `edge.branch` group; each allowlisted + in the URI census)

| Name (`edge.local.pos.*`) | Verb URI (`/edge/local/pos/…`) | Purpose |
|---|---|---|
| `shared` | GET `shared` | THE shared cashier page (Phase 2 beside the old `screen`) |
| `server-time` | GET `server-time` | Online `/api/server-time` twin `{epoch_ms}` |
| `totals.quote` | POST `totals/quote` | Online O16 twin (flat keys) |
| `promotions.quote` | POST `promotions/quote` | Online O17 twin |
| `restaurant.board.html` | GET `restaurant/board/html` | Online table-board `{ok, html}` twin |
| `restaurant.session.open-orders` | GET `restaurant/table-sessions/{session}/open-orders` | Online O14 twin |
| `shifts.create-page` / `shifts.store-page` | GET/POST `shifts/open` | tenant/shifts/open + its form |
| `shifts.close-page` / `shifts.close-store-page` | GET/POST `shifts/{shift}/close` | tenant/shifts/close + its form |
| `shifts.index-page` / `shifts.show-page` | GET `shared/shifts`, `shared/shifts/{shift}` | tenant/shifts/index, show (Phase 2 beside the old Edge finance screens at `shifts`, `shifts/{shift}`) |
| `sales-returns.create-page` / `sales-returns.store-page` | GET `sales-returns/create`, POST `sales-returns` | tenant/sales-returns/create + its form |
| `sales-returns.index-page` / `sales-returns.show-page` | GET `shared/sales-returns`, `shared/sales-returns/{salesReturn}` | tenant/sales-returns/index, show (beside the old screens) |
| `split-bill.page` / `split-bill.store-page` | GET/POST `held-sales/{sale}/split-bill` | tenant/sales-orders/split-bill + its form |

Naming note: the brief listed `edge.local.shifts.create-page`; every POS route lives in the `edge.local.pos.` name group (it carries
the auth/branch middleware), so the names are `edge.local.pos.shifts.create-page` etc. (§15 of the architecture used `pos.*` too).
At cutover the `shared/…` index/show pages replace the old Edge finance screens at `shifts`, `sales-returns` (and `shared` → `/`).

Runtime route keys beyond `PosRuntime::ROUTE_KEYS` (the constructor accepts extra keys; please add them to ROUTE_KEYS with Cloud
values): `terminals`, `terminalSelect`, `syncSummary`, `shiftSummary`, `shiftIndexPage`, `shiftShowPage`, `shiftOpenStore`,
`shiftCloseStore`, `shiftCloseBranchPage` (null on Edge), `salesReturnShowPage`, `salesReturnSearch`, `salesReturnStore`,
`splitBillStore`, `voidReasons`, `printPreferences`, `printMarkPrinted`, `printDismiss`, `heldKot`. The shared page already uses
`terminals`, `terminalSelect`, `syncSummary`.

## 3. Contract changes (§3.2) — all additive for the old page unless marked

| Endpoint | Change |
|---|---|
| `GET restaurant/board/html` (new) | `{ok, html}` = `tenant.pos.partials.table-board` from local data, `selected_session_id` highlight, reservations projected |
| `GET held-sales` | + `sales[]` in Online ajaxList shape with `lines[]` (`total` formatted, `items`, `customer`, `waiter`, `table`, …); `held_sales` kept |
| `GET restaurant/table-sessions/{s}/open-orders` (new) | Online O14 `{ok, table_session_id, branch_id, session, orders[] (lines, recall_url=null)}` |
| `POST totals/quote`, `promotions/quote` (new) | Online flat keys over `previewBill` (server prices; client unit_price ignored); invalid promo = 422 `{valid:false…}` |
| `POST held-sales/{sale}/settle` | accepts the full Online sale payload; A2 discount/approval/promo/tip applied (see §4) |
| `POST manager-approvals/verify` | **status 201 → 200** + `ok:true`; errors `{ok:false, message}`; `throttle:10,1` |
| `POST held-sales` | **422 → 409 `TABLE_HAS_OPEN_ORDERS`** (with `session`, `orders`) for a 2nd new check on a session; `NO_OPEN_SHIFT` code |
| `POST restaurant/tables/{t}/open` | `NO_OPEN_SHIFT` code |
| every terminal-bound endpoint | 422 `code: INVALID_TERMINAL`; a request `terminal_id` is ADOPTED into the session (Online per-request semantics) |
| `GET shift` | `?terminal_id=` empty → Online no-terminal answer; + `shift_id, shift_uuid, opened_at (display), open_url` |
| returns endpoints | 403 `{message, permission}` via `denyUnlessCan` (was bare `abort(403)`); search + select2 `results/pagination` |
| print job views (print/held controllers) | + `job_id`, `printer_id`, `printer_type`, `created_at_human`; `preview_url` now path-only (was absolute URL) |
| `GET recent-sales` | **`time` = Online `d M, h:i A` (was ISO)**, + `ago`, + `time_iso` (the old page's Completed Orders date cell reads `time` as ISO → shows blank in the fallback page) |
| reservations | accept `reserved_customer_id/reserved_name/reserved_phone/reservation_note`; reserve → `{ok:true, reservation}` + flat keys (201 kept); GET → `ok` + `name/phone/reserved_for_display/reserved_at_display`; unreserve → `ok` |
| `GET customers` | + `customer_uuid`, `email`, `legacy_address` (`address` kept) |
| quick-report 403 | NOT changed — `EdgeQuickReportController` is not in W-B ownership (still `abort(403)`); needs `denyUnlessCan` by its owner |

## 4. A2 — manual discount aligned to Online (EdgeLocalPosService, sub-agent under Team B)

- Hold (new + revision) refuses `discount_type ≠ none` / `discount_value > 0` with Online's exact message
  (`Manual discounts are applied with manager approval when taking payment, not while holding an order.`) before any write; a
  revision writes `none` (a legacy held check with a stored order discount loses it on its next revision; re-applied at payment).
  Per-line discounts + promo codes unchanged (W2).
- Settle: when the request carries `discount_type`, totals are recomputed over the LOCKED stored lines (captured prices), fixed ≤
  (subtotal − line discounts) enforced, the approval consumed inside the settle transaction bound to `{sales_order_id: held id,
  branch_id, client_uuid, discount_type, discount_value, discount_amount}` (Online's binding), the row repriced before payments /
  stock / settlement / outbox. `discount_type none` = remove discount. Keys absent (old page) = byte-identical old behaviour + hash.
- Idempotency hash includes the discount fields when present → same uuid + same payload = replay (approval consumed once); same
  uuid + different discount = 409.
- Direct Pay: fixed ≤ bill refusal added.
- `tests/MySql/EdgeDiscountFlowMySqlTest.php`: 8 tests / 175 assertions GREEN (hold never persists / apply at settle + envelope /
  manager approval binding + auto-approve / consumed at settle + Direct Pay / consumed approval cannot replay / percent-fixed
  validation / remove discount / retry-idempotency).
- Old page consequence: its "apply discount then Hold" flow is now refused (owner decision; old page is the fallback until cutover).

## 5. Separate screens (owner requirement)

| Screen | Edge route | Tenant view | Status |
|---|---|---|---|
| Shift open | `shifts.create-page` (+ `store-page`) | tenant/shifts/open | renders via layouts.pos; bound branch + operator's terminal(s) (A4); POST opens via ShiftService, redirects like Online |
| Shift close | `shifts.close-page` (+ `close-store-page`) | tenant/shifts/close | renders; HIDE-AMOUNTS stripped on the model; shared close + count lines in one txn; redirect to detail |
| Shift list / detail | `shifts.index-page` / `shifts.show-page` | tenant/shifts/index, show | render (bound branch; per-branch mask) |
| Sales return create | `sales-returns.create-page` (+ `store-page`) | tenant/sales-returns/create | renders (local + mirrored Online sales); POST → EdgeLocalReturnService, redirect to detail |
| Sales return list / detail | `sales-returns.index-page` / `show-page` | tenant/sales-returns/index, show | render |
| Split bill | `split-bill.page` (+ `store-page`) | tenant/sales-orders/split-bill | renders; POST → splitHeldSale + Online top-window breakout to `/edge/local/pos/shared?held_sale_id=…` |

All pages render with `?embed=1` (body `embedded-workspace`), receive `$posRuntime`, never render the Cloud header/sidebar
(proven in EdgeSharedPosViewMySqlTest). Permission gates: Online route permission of each page, except open/close pages gate on
`tenant.shifts.store` / `tenant.shifts.close` because `tenant.shifts.create` / `tenant.shifts.close-form` are not in
PosPermissionCatalog (Team E — add them for exact Online parity, then switch the two gates).

**Body literals that still point at Cloud paths (Team A / coordinator — the views' bodies were NOT edited):**

| View | Literal → runtime key |
|---|---|
| shifts/open | `url('/shifts')` (Back, Cancel) → `shiftIndexPage`; form `url('/shifts/open')` → `shiftOpenStore`; multi-branch picker copy ("Pick a branch…") → A4 single-terminal hint (`labels.shiftBranchWide`) |
| shifts/close | `url('/shifts')` → `shiftIndexPage`; form `url('/shifts/'.$id.'/close')` → `shiftCloseStore`; CASH-SHORTAGE draft-voucher notice is Cloud-only → show `labels.shortageVoucher` on Edge |
| shifts/index | `url('/shifts')`, `url('/shifts/'.$id)` → `shiftIndexPage`/`shiftShowPage`; `url('/shifts/open')` → `shiftOpenPage`; `url('/shifts-close-branch…')` → `shiftCloseBranchPage` (null on Edge → hide/disable) |
| shifts/show | `url('/shifts')` → `shiftIndexPage`; `url('/shifts/'.$id.'/close')` → `shiftClosePage`; `@can('tenant.shifts.close-form')` → not catalogued (Team E) |
| sales-returns/create | `url('/sales-returns')` (Back/Cancel) → `salesReturnIndexPage`; form action → `salesReturnStore`; select2 `url('/ajax/sales')` → `salesReturnSearch` (Edge answers `results/pagination`); `url('/sales-returns/create')` → `salesReturnCreatePage`; `url('/api/manager-approvals/verify')` + `{pin}` → `managerVerify` + `POS.managerCredentialFromPrompt()`; non-cash refund methods on Edge → cash-only policy hint (`labels.refundOnlineRequired`) |
| sales-returns/index | `url('/sales-returns…')` → index/create/show keys; `url('/sales-orders/'.$id)` → `salesOrderShow` (null on Edge); date-range-filter `action` → `salesReturnIndexPage` |
| sales-returns/show | `url('/sales-returns')` → `salesReturnIndexPage`; `url('/sales-orders/'.$id)` → `salesOrderShow` (null) |
| sales-orders/split-bill | form `url('/sales-orders/'.$id.'/split-bill')` → `splitBillStore`; `url('/held-sales')` → `posIndex`; `url('/restaurant/table-sessions/'.$sid.'/bill-preview')` (HTML page on Online, JSON on Edge) → hide on Edge or a Bill Preview modal |
| tenant/pos/partials/table-board | (Team A converted) |

Until those bodies are converted, clicking Back/Cancel or submitting the Online-literal forms inside these pages on Edge hits a
Cloud path (404 on an appliance). The Edge POST handlers exist and are proven over HTTP.

## 6. First shared-view render — verdict

- Dev instance `http://127.0.0.1:8095` (DB `bingoo_edge_devtest_local`), DEVCASH1: `GET /edge/local/pos/shared` = **200**, renders
  `tenant.pos.index` through W-A's `layouts.pos`, `POS_RUNTIME.mode = "edge"`, **0** Cloud literal paths, **0** `http(s)://`
  src/href, **0** `fonts.googleapis` (also asserted in EdgeSharedPosViewMySqlTest). Old page `GET /edge/local/pos` still 200.
- Screenshots: `C:\Users\Dell\BingooEdgeLab\evidence\phase2\edge-shared-first-render\{1366x768,1024x768}\*.png` (22 shots +
  `report.json`; `04-context-modal` skipped — DEVCASH1 is pinned without `tenant.pos.change-terminal` so the Change button is
  permission-hidden, as on Online; `07-recent-prints` skipped on Online too).
- Against `online-before-599c5d0-styled` / `online-after-wa` `01-main.png`: same header row, mode tabs, customer slot, context row,
  search, category pills, tile grid geometry, cart panel, Review & Pay, Hold/Draft/Bill/Recent/Cancel block — identical layout.
  Differences are data/permission/state, not layout: (a) status slot `LOCAL MODE · MANUAL SWITCH` vs `ONLINE · CLOUD` (the
  shared slot, A5); (b) **Return button hidden** — DEVCASH1 lacks `tenant.sales-returns.create` in the dev seed (the page gates on
  it; Team E's cashier template / dev seed); (c) `Shift open` (the dev Counter 1 has an open shift) vs `No Open Shift`; (d) menu
  data; (e) typeface: the Online LAB captures show the fallback font (Google Fonts blocked), the Edge dev instance now serves
  Nunito locally (W-C A3) — the paired baseline must be re-shot on Online after A3.

## 7. Tests

- `vendor/bin/phpunit tests/Feature/Edge`: see §8 (162 tests; census / allowlist / compile gate / permission catalogue green).
- MySQL (isolated `_t2` DBs): `EdgeSharedPosViewMySqlTest` 5/5, `EdgeSharedPosContractMySqlTest` 5/5, `EdgeDiscountFlowMySqlTest`
  8/8 green; broad regression filter results in §8.

## 8. Results and blockers

**Feature (`vendor/bin/phpunit tests/Feature/Edge`)**: 162 tests, 161 pass, **1 fail** —
`EdgeApplianceArtifactBoundaryTest::test_cloud_chrome_views_may_ship_but_no_edge_local_route_renders_them`. Cause: Team C's static
view walker takes EVERY string literal of an `@extends(...)`, so the coordinator-prescribed
`@extends(isset($posRuntime) && $posRuntime->isEdge() ? 'layouts.pos' : 'layouts.app')` is still read as reaching `layouts.app`.
Every Edge controller that renders those views passes an Edge runtime (proven over HTTP: the pages render through `layouts.pos`,
no Cloud header/sidebar). Fix for Team C (verified: the gate goes green with it, 648 assertions) — in `expandViews()`:

```php
foreach ($m[1] as $args) {
    // W-B runtime layout switch: every Edge controller passes an EDGE runtime → only the Edge branch is reachable.
    if (preg_match("/isEdge\(\)\s*\?\s*['\"]([A-Za-z0-9_\-.]+)['\"]\s*:/", $args, $edgeBranch)) {
        $args = "'" . $edgeBranch[1] . "'";
    }
    preg_match_all(/* unchanged */);
```

Census / allowlist / Blade compile gate / permission catalogue: green.

**MySQL (`_t2` DBs), the prescribed filter** (`EdgeSharedPos|EdgeDiscountFlow|EdgeCashier|EdgeLocalPos|EdgeHeld|EdgeRestaurant|
EdgeReturn|EdgeShift|EdgePrint|EdgeManagerApproval|EdgeW6ContractEnvelope`): **170 tests, 159 pass, 10 fail, 1 skipped**
(node parse check). New W-B classes all green: EdgeSharedPosView 5/5, EdgeSharedPosContract 5/5, EdgeDiscountFlow 8/8. Plus,
outside the filter, EdgeLocalRestaurantHttp + EdgeComboVoidReconcile: 8 tests, 5 fail (same causes).

Failures, by cause (no failure is an unintended regression):

1. Owner-directed contract changes (W-B) — the old tests pin the old status codes:
   - manager verify **201 → 200**: `EdgeCashierDealsDiscountsHttpMySqlTest:237`, `EdgeCashierOrderLifecycleHttpMySqlTest:109`
     (2 tests), `EdgeCashierReturnParityHttpMySqlTest:124,129,157` (2 tests), `EdgeComboVoidReconcileHttpMySqlTest:117` (2 tests),
     `EdgeLocalRestaurantHttpMySqlTest:283`;
   - second new check on a session **422 → 409 TABLE_HAS_OPEN_ORDERS**: `EdgeCashierDineInHttpMySqlTest:258`,
     `EdgeLocalRestaurantHttpMySqlTest:157`.
   A one-token patch per line (`assertStatus(201)`→`(200)`, `assertStatus(422)`→`(409)`, 8 hunks, `git apply --check` clean) turns
   all 10 of these green (verified on scratch copies: OK, 10 tests / 231 assertions). Patch file (Team B scratchpad):
   `C:\Users\Dell\AppData\Local\Temp\claude\d--laragon2-www-pos-saas-edge\a8cd7183-85e0-43ea-97aa-bc628fc088e0\scratchpad\wb-test-patch\wb-contract-test-expectations.patch`
   — the owners of those test files (or the coordinator) should apply it; Team B did not edit them (not in W-B ownership).
2. Team A — `EdgeCashierControlCensusHttpMySqlTest::test_every_online_pos_control_is_registered_and_the_reference_is_pinned`:
   `index.blade.php` changed (W-A), so the pinned `online_view_sha1` no longer matches (the census is retired by §5/W-G anyway).
3. Team E — the MySQL fixture now seeds `PosPermissionCatalog::cashier()` (includes `tenant.pos.change-terminal`,
   `tenant.sales-returns.store`, `tenant.pos.void-kot-item`) on EVERY seeded user, so "restricted operator" tests no longer are:
   `EdgeCashierReturnHttpMySqlTest:121`, `EdgeCashierRouteGatesHttpMySqlTest:176`,
   `EdgeCashierScreenRendersHttpMySqlTest::test_pinned_operator_is_offered_only_his_assigned_terminal`,
   `EdgeLocalRestaurantHttpMySqlTest:340` (the NOPERM manager). Those tests need `revokeEdgePermission(...)` after seeding.

**Blocked / handed over**

- Team A: convert the body literals listed in §5 (8 separate-screen views) to runtime keys; add the extra route keys (§2) to
  `PosRuntime::ROUTE_KEYS` with Cloud values; the old-page fallback (`edge.local.pos.screen`) is untouched.
- Team C: the boundary-walker fix above.
- Team E: add `tenant.shifts.create` / `tenant.shifts.close-form` to PosPermissionCatalog (then W-B switches the open/close page
  gates to them); DEVCASH1 dev seed lacks `tenant.sales-returns.create` / `tenant.pos.change-terminal` (Return / Change buttons
  hidden on the dev instance — permission data, not layout).
- `EdgeQuickReportController` (quick-report 403 → `denyUnlessCan`) is outside W-B ownership.
- Not done in W-B: Direct Pay print intents on held settle (Online POST /pos + held_sale_id prints; Edge settle does not print —
  the page's print endpoints still work); recipe "makeable" preview (Edge ships `is_recipe=false`, the sale still refuses on the
  operational balances); the old page's Completed Orders date cell shows blank because `time` is now Online's display string.
- Old page: its "apply discount, then Hold" flow is refused under A2 (owner decision; fallback until cutover).
