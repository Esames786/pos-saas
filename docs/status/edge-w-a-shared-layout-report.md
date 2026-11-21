# W-A — shared POS layout + Cloud runtime adapter (Phase 2 steps 1–3) — report

Date: 27 Sep 2026. Branch `feat/edge-config-refresh-v1` (worktree `D:\laragon2\www\pos-saas-edge`), on top of `599c5d0`. No commit, no push.
Scope: render the EXISTING Online POS (`tenant/pos/index.blade.php`) through a new shared `layouts.pos`, driven by `PosRuntime`,
with no visual change on Online (owner decisions A1, A5-modified, A3 font link).

## 1. Files

| File | Change |
|---|---|
| `resources/views/layouts/pos.blade.php` | **new**: shared POS layout (details in §2) |
| `resources/views/tenant/pos/partials/pos-chrome-cloud.blade.php` | **new**: `@include('partials.header')` + `@include('partials.sidebar')`, exactly what layouts.app rendered |
| `resources/views/tenant/pos/partials/pos-clock.blade.php` | **new**: the header clock logic (header.blade.php 143-227), markup-free, stands down when the Cloud header already started the clock; resync URL falls back to `POS.route('serverTime')`; accepts `epoch_ms` (Cloud) or `server_epoch_ms` (Edge) |
| `resources/views/tenant/pos/partials/pos-status-slot.blade.php` | **new**: the A5 runtime-status slot (§4) |
| `resources/views/tenant/pos/js/pos-runtime.blade.php` | **new**: `window.POS` = `route`, `api`, `can`, `hint`, `managerCredentialFieldsHtml`, `managerCredentialFromPrompt`, `setAuthority`, `overlay.show/hide` |
| `resources/views/tenant/pos/index.blade.php` | `@extends('layouts.pos')`; 58 `url('/…')` → runtime keys (§3); capability attributes (§5); manager prompt via `POS.*` (§6); status-slot include. No markup/CSS/logic restructuring |
| `resources/views/tenant/pos/partials/table-board.blade.php` | its one `url()` → `($posRuntime ?? Cloud factory)->route('posIndex')` (the Cloud `GET /api/pos/table-board` endpoint renders the partial without a runtime — POSController::tableBoard() is outside W-A's ownership, so the partial falls back to the Cloud runtime there) |
| `app/Support/Pos/CloudPosRuntimeFactory.php` | **new**: the Online runtime (§3.1) |
| `app/Support/Pos/PosPageData.php` | **new**: closed page-data contract, 23 keys (missing/extra key → exception); `toViewData($runtime)` |
| `app/Support/Pos/PosRuntime.php` | additive only: optional trailing ctor arg `?string $chromeView = null` (server-side, NOT serialised) + helper `capabilityHint($cap)`. Public shape otherwise unchanged; `jsonSerialize()` unchanged |
| `app/Http/Controllers/Tenant/POSController.php` | `index()` only: builds the Cloud runtime, `image_url` via `$posRuntime->asset('storage/…')`, returns `view('tenant.pos.index', PosPageData::fromArray([...])->toViewData($posRuntime))`. The quick-report JSON twin was NOT added (needs a route in `routes/`, outside W-A) → `quickReportOptions` is `null` on the Cloud |
| `tests/Feature/Pos/PosRuntimeTest.php`, `tests/Feature/Pos/SharedPosViewRenderTest.php`, `tests/Feature/Pos/SharedSecondaryScreensTest.php` | **new** (§8, §10) |
| `resources/views/tenant/{shifts/open,close,index,show; sales-returns/create,index,show; sales-orders/split-bill}.blade.php` | bodies converted to runtime keys (coordinator addendum, §10) |

## 2. `layouts.pos`

Same `<head>`/`<body>` skeleton as `layouts.app`; the SAME 22 theme files in the SAME order, each through `$posRuntime->asset()`,
plus `fonts-local.css` immediately before `style.css` (A3 / Team C gate). `<meta name="csrf-token">`. The POS chrome rules that
layouts.app emitted only on `/pos` are unconditional (`body.pos-workspace nosidebar`, header/sidebar/banner hidden, `.page-wrapper`
offsets 0, content padding 12px). Embedded mode (`?embed=1` + iframe detection) copied verbatim. Chrome slot:
`@include($posRuntime->chromeView)` (Cloud: `tenant.pos.partials.pos-chrome-cloud`). Global loader, session-status alert + toast,
sidebar scroll memory, wheel guard kept. `window.POS_RUNTIME = @json($posRuntime)` and `pos-runtime` are emitted at the END of
`<head>` because the page's inline scripts run during parse. The shared overlay `#pos-runtime-overlay` (fixed, `d-none`) sits
after `.main-wrapper`. Not carried over: the tenant-subscription banner (it was `display:none` on /pos) and its view composer
(bound to `layouts.app` only).
Secondary screens (Team B: shift/returns/split pages extending `layouts.pos` on Edge): proven by a test — a plain
`@section('content')` page with `@push('styles')`/`@push('scripts')` renders, `?embed=1` adds `embedded-workspace`. The layout
requires `$posRuntime` in the view data.

## 3. Literal → route-key table (all 58 in index + 1 in table-board)

Line numbers are those of `index.blade.php` at `599c5d0`. Params use `{sale} {session} {table} {job} {customer} {shift}`.

| # | line | literal | key (usage) |
|---|---|---|---|
| 1 | 456 | `data-session-base="url('/restaurant/table-sessions')"` | attribute removed; JS uses `POS.route('tableBillRequested', {session})` |
| 2 | 472 | `url('/restaurant/table-sessions/'.id.'/bill-requested')` (form action) | `$posRuntime->route('tableBillRequested', ['session'=>id])` |
| 3 | 515 | `url('/reports/center')` | `reportsCenter` |
| 4 | 526 | `url('/sales-returns/create')` | `salesReturnCreatePage` |
| 5 | 550 | `url('/pos')` (sale form action) | `saleStore` |
| 6 | 579 | `url('/shifts/open')` | `shiftOpenPage` |
| 7 | 1045 | `url('/restaurant/floors?embed=1')` | `manageFloors` + `?embed=1` |
| 8 | 1051 | `url('/restaurant/tables?branch_id=…&embed=1')` | `manageTables` + same query |
| 9 | 1059 | `url('/api/pos/table-board')` | `tableBoardHtml` |
| 10 | 1317 | `url('/held-sales')/{id}/reattach-table` | `heldReattach` |
| 11 | 1338 | `url('/pos')?held_sale_id=` | `posIndex` + query |
| 12 | 1689 | `@json(url('/pos/quick-report'))` base (5 uses) | `quickReportSave`, `quickReportEmail`, `quickReportNetwork`, `quickReportPrint`, `quickReportSettings` |
| 13 | 1879 | `url('/printing/documents')/{job}/preview` | `printDocument` |
| 14 | 1907 | `url('/api/pos/print-jobs')/{sale}` | `printJobsForSale` |
| 15 | 2173 | `url('/pos')` (buildPosUrl) | `posIndex` |
| 16 | 2281 | `url('/api/pos/table-sessions')/{id}/open-orders` | `tableSessionOpenOrders` |
| 17 | 2429 | `url('/api/pos/shift-status')` | `shiftStatus` |
| 18 | 3624 | `url('/api/pos/totals/quote')` | `totalsQuote` |
| 19 | 3818 | `url('/api/pos/promotions/quote')` | `promoQuote` |
| 20 | 4057 | `url('/api/manager-approvals/verify')` | `managerVerify` |
| 21 | 4290 | `url('/printing/jobs/kot')/{sale}` | `kotQueue` |
| 22 | 4348 | `url('/printing/jobs/reminder')/{sale}/confirm` | `reminderConfirm` |
| 23 | 4401 | `url('/printing/jobs/receipt')/{sale}` | `receiptQueue` |
| 24 | 4584 | `url('/pos')/{sale}/printing/retry` | `printingRetry` |
| 25 | 4681 | `url('/pos')` (sale submit) | `saleHeldSettle` when `held_sale_id` is set, else `saleStore` (both `/pos` on the Cloud) |
| 26 | 4774 | `url('/held-sales')` | `heldStore` |
| 27 | 4918 | `url('/held-sales')/{sale}/cancel` | `heldCancel` |
| 28 | 5018 | `url('/api/pos/held-sales')` | `heldList` |
| 29 | 5234 | `url('/pos')` (new sale) | `posIndex` |
| 30 | 5283 | `url('/api/pos/table-sessions')` | `tableSessions` |
| 31 | 5359 | `url('/pos')?branch_id=` | `posIndex` + query |
| 32 | 5418 | `url('/api/pos/print-jobs')/{lastSale}` | `printJobsForSale` |
| 33 | 5585 | `url('/printing/jobs')/{job}/reminder-reprint` | `reminderReprint` |
| 34 | 5599 | `url('/printing/jobs/kot')/{lastSale}` | `kotQueue` |
| 35 | 5611 | `url('/printing/jobs/receipt')/{lastSale}` | `receiptQueue` |
| 36 | 5626 | `url('/printing/jobs')/{job}/retry` | `printRetry` |
| 37 | 5678 | `url('/api/pos/recent-sales')` | `recentSales` |
| 38 | 5692 | `url('/sales-orders')/{id}` (Rider link) | `salesOrderShow` + capability `changeRider` (disabled link when off) |
| 39 | 5693 | `url('/sales-orders')/{id}` (view link) | `salesOrderShow` (disabled link when the route is null) |
| 40 | 5731 | `url('/pos')/{sale}/printing/retry` | `printingRetry` |
| 41 | 5758 | `url('/printing/jobs/kot')/{sale}` | `kotQueue` |
| 42 | 5761 | `url('/printing/jobs/receipt')/{sale}` | `receiptQueue` |
| 43 | 5829 | `url('/api/pos/bill-preview')` | `billPreview` |
| 44 | 5862 | `url('/restaurant/table-sessions')/{s}/bill-preview` | `tableBillPreview` |
| 45 | 5894 | `url('/printing/jobs/receipt')/{id}` | `receiptQueue` |
| 46 | 6006 | `url('/printing/jobs/kot')/{sale}` | `kotQueue` |
| 47 | 6024 | `url('/printing/jobs/receipt')/{sale}` | `receiptQueue` |
| 48 | 6093 | `url('/api/pos/table-sessions')/{id}/open-orders` | `tableSessionOpenOrders` |
| 49 | 6131 | `url('/restaurant/table-sessions')/{s}/move` | `tableMove` |
| 50 | 6147 | `url('/sales-orders')/{id}/split-bill` (iframe) | `splitBillPage` |
| 51 | 6165 | `url('/restaurant/tables')/{t}/open` (form action) | `tableOpen` |
| 52 | 6198 | `url('/restaurant/tables')` base (3 uses) | `unreserve`, `reservation`, `reserve` |
| 53 | 6199 | `url('/ajax/customers')` (reserve search) | `customerSearch` |
| 54 | 6226 | `url('/restaurant/table-sessions')/{s}/close` | `tableClose` |
| 55 | 6380 | `url('/pos')?branch_id=` | `posIndex` + query |
| 56 | 6912 | `url('/pos/customers')/{c}/addresses` | `customerAddressStore` via `POS.api` (JSON) |
| 57 | 6973 | `url('/ajax/customers')` | `customerSearch` |
| 58 | 7050 | `url('/pos/customers/quick-store')` | `customerQuickStore` via `POS.api` (JSON) |
| tb | table-board:54 | `url('/pos?table_session_id=…')` | `posIndex` + query |

Result: `grep "url(" index.blade.php` = 0, `asset(` = 0; the page resolves 51 distinct keys, all in `PosRuntime::ROUTE_KEYS` (test).

### 3.1 Cloud runtime (CloudPosRuntimeFactory)
Routes = the literal tenant paths (root-relative, prefixed by the app base path if served from a sub-directory); every template is
matched against a registered route by a test. `null` on the Cloud (no separate endpoint): `status`, `quickReportOptions`,
`heldShow` (lines are embedded in the held list; recall = `posIndex?held_sale_id=`), `printJobsRecent`. Capabilities: all true
(`@can` gates kept unchanged in the view). identity `{branch_id, branch_name, branch_selectable:true, terminal_selection:'per_request'}`;
authority `{state:'cloud', label:'ONLINE', sub_label:'CLOUD', can_mutate:true, pending_sync:0, tone:'ok'}`; assets `/assets`,
`/storage` (or `asset()` when an ASSET_URL/CDN is configured); transport `X-CSRF-TOKEN` / json / `/login`; managerCredential `pin`;
chromeView `tenant.pos.partials.pos-chrome-cloud`.
Note: endpoint URLs in the rendered page are now root-relative (`/pos`) instead of `url()`'s absolute `http://host/pos` — same
resource, no visual effect.

## 4. Status slot (A5) — placement and measured geometry

Placement: right end of the existing title row (`d-flex align-items-center flex-wrap gap-2 mb-3`, after the inline flash spans),
`ms-auto`, a fixed box `height:28px; width:260px; overflow:hidden; white-space:nowrap`, theme badges only:
`#pos-runtime-state` (badge, tone ok/warn/danger → bg-success / bg-warning text-dark / bg-danger), `#pos-runtime-sub` (`· CLOUD`),
`#pos-runtime-pending` (`N pending sync`, `d-none` at 0). Online shows **ONLINE · CLOUD**. Repaint at runtime: `POS.setAuthority()`.
Urgent warnings: `POS.overlay.show()` → the fixed `#pos-runtime-overlay` (no reflow).

Measured in headless Edge after fonts loaded (Playwright `getBoundingClientRect`, before = clean `599c5d0` on :9703, after = this
worktree on :9702):

| box | 1366×768 before | 1366×768 after | 1024×768 before | 1024×768 after |
|---|---|---|---|---|
| title row | 12,12 1342×38 | 12,12 1342×38 | 12,12 1000×38 | 12,12 1000×38 |
| toggle / h1 / View Tables | identical | identical | identical | identical |
| status slot | — | 1094,17 260×28 | — | 752,17 260×28 |
| mode tabs, customer button, Review & Pay, `.pos-shell` | identical | identical | identical | identical |
| document height | 768 | 768 | 1547 | 1547 |

Row height is unchanged (38 px, set by the sidebar-toggle button); no control moved.

## 5. Capability attributes (Online: all on → no attribute emitted)

Report button (`reports`), Return button (`salesReturn`), Quick Report button (`quickReport`), Quick Report "Send to network"
(`quickReportNetwork`) and "Email to owner" (`quickReportEmail`), Manage Floors / Manage Tables (`manageFloorsTables`), branch
select (`branchSelect`; when off, a hidden `branch_id` input carries the bound branch because a disabled select does not post),
non-cash tender `<option>`s (`nonCashTender`), the 4 tip buttons (`tipOnPaidSale`), Rider link (`changeRider`, JS). Off renders
`disabled` + `title` = `PosRuntime::capabilityHint()` / `POS.hint()` (runtime labels `capability.<key>` or `capabilityOff`), same DOM
position and size.

## 6. Manager approval
`showManagerPinModal` renders `POS.managerCredentialFieldsHtml()` and posts `Object.assign(POS.managerCredentialFromPrompt(), {action_type, payload})`.
Cloud: the identical PIN input and body `{pin, action_type, payload}` (same key order). Edge (`employee_code_and_credential`): employee
code + credential side by side in ONE row with the single input's margins (same Swal height), body
`{manager_employee_code, manager_credential, action_type, payload}`.

## 7. Transport: POS.api vs kept fetch/FormData

`POS.api` (JSON, CSRF from the meta tag, same-origin; 401/419 → `transport.unauthenticated_redirect`; 403 → toast with
`permission`; non-2xx → Error with `.status/.body`) is used where the Online controller validates JSON identically:
- `customerQuickStore` (CustomerController::quickStore — `validate()`, `expectsJson()`), `customerAddressStore` (storeAddress —
  `validate()`). The page's existing success/refusal handling is preserved (an HTTP refusal still resolves to its body).

Kept as `fetch` with the URL from `POS.route` (documented exceptions):
| call | why kept |
|---|---|
| sale submit (`saleStore`/`saleHeldSettle`), held store (`heldStore`) | `new FormData(form)` built from the sale form + dynamic inputs (`lines[i][…]`, modifiers as JSON string) — multipart semantics |
| quick report save / e-mail / network | FormData with `sections[]`… arrays; not re-validated per field in W-A |
| table open (`form.action` = `tableOpen`), request bill (`form.action` = `tableBillRequested`), move (`postTableOperation`), reserve | form / FormData posts (report §3.3 keeps form posts as forms) |
| JSON calls already JSON (reattach, totals quote, promo quote, manager verify, reminder confirm, held cancel, bill preview, table close) | response handling is written against `Response` (`res.ok` + body); converting would change error paths — URL swap only |
| body-less POSTs (KOT/receipt queue, retries, reprints, unreserve) and GETs (lists, board, shift status, reservation, customer search, print jobs) | URL swap only; identical requests |

## 8. Tests

- `vendor/bin/phpunit tests/Feature/Pos` → **OK, 19 tests, 390 assertions** (+`SharedSecondaryScreensTest`, 5 tests: runtime
  keys + Cloud fallback + no `url(` in the 8 secondary views, compile + `php -l`, shared manager prompt on the return page, Cloud
  values of the new keys, Edge factory defines every key). `PosRuntimeTest` (7): every route/capability key,
  literal Online paths, every template is a registered route, resolution/encoding/assets/serialisation (chromeView not serialised),
  incomplete map refused, PosPageData closed contract, POSController passes exactly the PosPageData keys.
  `SharedPosViewRenderTest` (7, SQLite): no `url(`/`asset(` in the page or board partial; every `POS.route/api`/`->route`/`can` key is
  a contract key; the page renders through layouts.pos with a Cloud runtime stub + PosPageData stub, `window.POS_RUNTIME.routes` has
  every key, POS defined before the page scripts, no absolute `url()` endpoint left, Online controls enabled; the 22 theme files in
  layouts.app order (+ fonts-local right before style.css); Edge-shaped stub renders capability-off controls disabled in place, the
  hidden branch input, STANDBY · CLOUD AUTHORITY + "3 pending sync", employee credential mode; secondary page + `?embed=1`; every
  inline script of the rendered page passes `node --check`.
- `vendor/bin/phpunit tests/Feature/Edge` → run 1 (before the fonts-local link): 162 tests OK, 1 incomplete (Team C's A3 font gate
  waiting for layouts.pos). Run 2 (final layout): **162 tests, 1 failure** —
  `EdgeApplianceArtifactBoundaryTest::test_cloud_chrome_views_may_ship_but_no_edge_local_route_renders_them`:
  `partials.header|sidebar <- layouts.app <- tenant.shifts.open <- EdgeLocalShiftController`. Not caused by W-A files: Team B's
  `tenant/shifts/open.blade.php` now uses `@extends(isset($posRuntime) && $posRuntime->isEdge() ? 'layouts.pos' : 'layouts.app')` and
  the gate's static walk (Team C) still reads `layouts.app`; before `layouts/pos.blade.php` existed the same chain was reported as
  "incomplete". Owner of the fix: Team B/Team C (the gate must understand the conditional). The A3 font gate now passes for
  layouts.pos. **Run 3 (final code, after the secondary-screen conversion and the walker fix Team B proposed): OK, 162 tests,
  36,113 assertions. No incomplete, no failure.**
- Not run by W-A: the MySQL suites. Team B reports that `EdgeCashierControlCensusHttpMySqlTest` pins the sha1 of `index.blade.php`,
  which W-A changed by design. The census is due to be retired (§5/W-G); until then its owner must re-pin the sha1.

## 8b. Open items
- `quickReportOptions` JSON twin on the Cloud (needs a route in `routes/`, outside W-A) → the key is `null` on the Cloud.
- `heldIndexPage` key (split-bill "Held Sales" link, §10).
- `POSController::tableBoard()` renders the board partial without a runtime; the partial falls back to the Cloud factory. Passing
  `$posRuntime` there is a one-liner for whoever owns that method.
- The Online paired baseline must be re-shot with the web font applied (§9).

## 9. Screenshot comparison (Online before `599c5d0` on :9703 vs Online after = this worktree on :9702)

**Verdict: no Online layout regression. Once the web font has been applied on both sides, the ONLY stable pixel difference is
the new status badge (1,273 px at both viewports).** Honest detail, because the raw reference shots do differ:

1. `pos-reference-shots.mjs` (400 ms settle) vs `online-before-599c5d0-styled`, before the font link: 01-main differs by exactly
   the badge (1,274 px); the other states differ by 0.1–1.6 %, from hover/focus transitions and a Bootstrap fade caught
   mid-way (after-run-1 05-held-orders, after-run-2 12-qty-entry). Before-vs-before re-shoots of the SAME server show the same
   kind of noise (up to 18k px), so these are capture-timing artefacts.
2. Once `fonts-local.css` is linked (A3), every raw shot differs by 40–75k px. Cause: **the before page renders the Arial fallback
   in the reference shots** (Arial Black on the 800-weight mode tabs), because its Nunito arrives late from Google Fonts through
   `@import` inside style.css (`font-display: swap`; the faces are registered late, so even waiting for `document.fonts` did not
   help). The after page serves the same Nunito file locally and swaps immediately. With a load, an 8 s settle and the pointer
   parked (`scratchpad/steady-states.mjs`, same flow on both servers), the before page ALSO renders Nunito, and:

| state (steady-state paired capture) | 1366×768 changed px | 1024×768 changed px | what differs |
|---|---|---|---|
| 01-main | 1,273 | 1,273 | status badge only |
| 03-customer-modal | 1,273 | 1,273 | status badge only |
| 09-table-workspace | 573 | 539 | status badge (dimmed under the modal backdrop) |
| 10-cart-two-lines | 1,481 | 1,273 | badge + sub-pixel noise on the Total card shadow (1366) |
| 11-review-pay | 662 | 1,164 | badge under backdrop + focus/selection ring of the tendered input |

3. Geometry (Playwright boxes, §4): title row, toggle, h1, View Tables, mode tabs, customer button, Review & Pay, `.pos-shell` and
   document height are identical to 0.1 px at both viewports. Status slot 260×28, inside the 38 px row.

Consequence for W-G / the paired baseline: the `online-before-599c5d0-styled` reference was captured in the fallback typeface, so it is
not a valid font baseline after A3. Re-shoot the Online baseline with fonts applied (or with the A3 build on both sides) before
running pixelmatch against Edge. Evidence: `C:\Users\Dell\BingooEdgeLab\evidence\phase2\online-after-wa\` (official after run,
final code), and in my scratchpad `ss-before/`, `ss-after/`, `d-ss/` (steady-state pairs + diff PNGs), `measure-*.json` (boxes).

## 10. Coordinator addendum — W-B route keys and the secondary shared screens

**`PosRuntime::ROUTE_KEYS` +18** (Team B §2): `terminals, terminalSelect, syncSummary, shiftSummary, shiftIndexPage, shiftShowPage,
shiftOpenStore, shiftCloseStore, shiftCloseBranchPage, salesReturnShowPage, salesReturnSearch, salesReturnStore, splitBillStore,
voidReasons, printPreferences, printMarkPrinted, printDismiss, heldKot`. Cloud values: `/shifts`, `/shifts/{shift}`, `/shifts/open`,
`/shifts/{shift}/close`, `/shifts-close-branch`, `/sales-returns/{salesReturn}`, `/ajax/sales`, `/sales-returns`,
`/sales-orders/{sale}/split-bill`, `/printing/jobs/{job}/mark-printed`, `/printing/jobs/{job}/dismiss`; `null` (no separate Cloud
endpoint, the data is embedded in the page): `terminals, terminalSelect, syncSummary, shiftSummary, voidReasons, printPreferences,
heldKot`. Constructor validation kept; a test reads `EdgePosRuntimeFactory::routes()` and proves it defines every key.

**8 secondary views** (Team B changed line 1 only; W-A converted the bodies). Each gets
`@php $posRuntime = $posRuntime ?? app(CloudPosRuntimeFactory)->make(); @endphp` after the `@extends`, so Online controllers that pass no
runtime keep working with identical (root-relative) paths.

| View | Literals converted |
|---|---|
| shifts/open | Back + Cancel `url('/shifts')` → `shiftIndexPage`; form → `shiftOpenStore`; intro copy → `labels.shiftBranchWide` when the runtime has it (Online text unchanged) |
| shifts/close | Back + Cancel → `shiftIndexPage`; form → `shiftCloseStore`; CASH-SHORTAGE draft-voucher notice → `labels.shortageVoucher` when set (the Cloud-only `CashShortageExpenseService` constant is only evaluated in the Online branch) |
| shifts/index | Close Branch (header + per-branch row) → `shiftCloseBranchPage` (null → same button, disabled, hint); Open Shift → `shiftOpenPage`; filter form + Reset → `shiftIndexPage`; View → `shiftShowPage` |
| shifts/show | Close Shift → `shiftClosePage`; Back → `shiftIndexPage` |
| sales-returns/create | Back + Cancel → `salesReturnIndexPage`; form → `salesReturnStore`; select2 `/ajax/sales` → `salesReturnSearch`; reload → `salesReturnCreatePage`; manager approval → shared prompt (`POS.managerCredentialFieldsHtml/FromPrompt/Focus` with the page's own Online field: id `return-manager-pin`, "Manager PIN", numeric) + `managerVerify`; non-cash refund options disabled with `labels.refundOnlineRequired` when `nonCashTender` is off. On Online the page includes the shared transport itself (layouts.app does not carry it) |
| sales-returns/index | New Return → `salesReturnCreatePage`; date-range filter action → `salesReturnIndexPage`; sale link → `salesOrderShow` (null → plain sale number, as without permission); View → `salesReturnShowPage` |
| sales-returns/show | Back → `salesReturnIndexPage`; sale link → `salesOrderShow` (same rule) |
| sales-orders/split-bill | form → `splitBillStore`; Table Bill → `tableBillPreview` on Online, disabled in place on Edge (Edge answers that key with JSON) |

**Could not convert:** `split-bill` "Held Sales" on Online. `url('/held-sales')` (the Held Sales PAGE) has no runtime key; adding one
would break Team B's factory until they define it. The Cloud branch keeps the literal, and the Edge branch uses `posIndex`. Suggested
follow-up: add `heldIndexPage` to both factories. Not touched: the `@can('tenant.shifts.create')` / `close-form` gates (Team E
catalogue item).

Live Online check on :9702 (LAB cashier): `/pos`, `/pos?embed=1`, `/shifts`, `/sales-returns`, `/sales-returns/create` → 200, 0 JS
errors, root-relative runtime hrefs; `/shifts/open` → 403 (the LAB cashier lacks `tenant.shifts.create`, which is permission data).
