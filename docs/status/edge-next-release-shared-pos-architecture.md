# Next Edge release — EXACT Online POS layout via ONE shared cashier view + offline add-customer
## Phase 1 architecture report (dependency inventory + plan) — for owner approval BEFORE implementation

Date: 27 Sep 2026. Branch `feat/edge-config-refresh-v1` at `61b1c80`. Read-only phase: no code, LAB, Cloud or production change.
Owner decision (final): the Edge cashier POS must use the EXACT Online operator-facing layout; "equivalent / similar / separately styled copy"
is not parity. Root cause confirmed: two cashier pages. This report is the plan to end that.

Inventory sources (five read-only sweeps, all cited by file:line in the appendix summaries): the Online view and its layout chain; the
Online↔Edge endpoint contract; the current Edge page; theme assets and the artifact boundary; customer/permission/version contracts.

---

## 0. Executive summary

| Finding | Consequence for the design |
|---|---|
| The Online theme is **prebuilt static files** in `public/assets` (Bootstrap 5.3.8, Tabler icons, SweetAlert2, `style.css` 1.36 MB, jQuery + template `script.js`), linked with `asset()` from `layouts/app.blade.php`; no Vite, no `public/build`. **All of `public/` already ships in the appliance artifact**, and the allowlisted `edge.local.assets` route already serves `public/assets`. | The theme can be rendered on Edge **without any new packaging**, only by pointing the layout's asset URLs at the local route. |
| Exactly **one Internet dependency**: `public/assets/css/style.css:3-4` `@import`s Google Fonts (Nunito, Poppins). Poppins is unused. | Vendor Nunito woff2 locally + local `@font-face`; drop the two imports. Cloud gains the same offline-safety. |
| The Online page has **0 `route()` calls and 58 literal `url('/…')` endpoints** (~40 distinct paths), 19 Bootstrap modals, 26 SweetAlert dialogs, `csrf_token()` inlined 32×. | The page needs a runtime endpoint map (adapter), not a copy. |
| Visual identity comes from the **theme + layout**, not the page: gold `.btn-primary`, `.btn` sizing, `.form-control`, the **red round `.modal .btn-close`** (`style.css:11154-11177`), Nunito body/headings, modal paddings, `.page-wrapper` offsets; chrome hiding is keyed on `request()->is('pos')` (`layouts/app.blade.php:23,55`). | The Edge copy could never match because it does not load `style.css` and uses its own class vocabulary (`.primary/.sm/.tile`, one generic `#modal`). |
| Rendering `tenant/pos/index.blade.php` inside `layouts.app` on the appliance is **not possible as-is**: the layout includes the Cloud header/sidebar (`app('tenant')->subscription`, 135 Cloud links, central-admin fallback when tenant is unbound) and a view composer importing an excluded Cloud service. | A shared **POS layout** (same theme assets, no Cloud chrome) used by BOTH modes; Online keeps its (hidden) chrome through a slot. |
| Endpoint contracts differ in shape in ~15 places (HTML board vs JSON board, iframe split/returns/shift pages vs JSON dialogs, discount at pay vs at hold, `pin` vs employee code + credential, error codes). | One canonical JSON contract; Online gains JSON twins where it only has HTML/iframes; Edge aligns keys. Documented per endpoint (§3). |
| `customers.customer_uuid` (ULID, unique) exists on both sides and the envelope already sends it; Cloud refuses unknown UUIDs terminally; the refresh matches customers by **numeric id** and would tombstone/overwrite offline-created rows. | Add-customer = envelope kind `new_customer` + Cloud create-or-match by phone + refresh matching by UUID + capability gating (§8). |
| Roles are already folded into per-user `permissions[]` on export; the LAB gap was a **route-derived** permission set. `TenantProvisioner` creates no cashier role. | Permission catalogue derived from runtime checks + a provisioned cashier role template + tests (§9). |
| Heartbeat carries only `{seq, edge_state}`; version/schema are frozen at pairing. | Add an optional `build` block to the heartbeat, written on change (§10). |

Estimated shape of the work: 7 workstreams, 5 phases, ~70 % of the Edge cashier page (3,773 lines) retired, the Online page changed only in a
mechanical way (URL literals → runtime map, layout switch, asset helper), plus Online JSON twins for 4 flows.

---

## 1. Complete dependency inventory (classified)

Legend: **SAI** SHARE_AS_IS · **PL** PACKAGE_LOCALLY · **ERA** EDGE_RUNTIME_ADAPTER · **COC** CLOUD_ONLY_CAPABILITY · **RSC** REFACTOR_TO_SHARED_COMPONENT · **RED** REMOVE_EXTERNAL_DEPENDENCY

### 1.1 Layout chain

| Dependency | Where | Class | Plan |
|---|---|---|---|
| `@extends('layouts.app')` | index:1 | RSC | Page extends a new shared `layouts.pos` (§2). |
| `partials.header`, `partials.sidebar` (Cloud chrome, hidden on /pos; `app('tenant')->subscription`, central-admin fallback, EdgeDevice queries, 147 `@can`) | layouts/app:67-68; header:1-5,73-77; sidebar:1-35 | COC (Cloud chrome) | Rendered on Online only via a layout slot; never on Edge. |
| View composer `$tenantSubscriptionStatus` (imports `App\Services\Saas\TenantSubscriptionAccessService`, excluded from the artifact) | AppServiceProvider:67-83 | COC | Bound to `layouts.app` only; `layouts.pos` has no composer. |
| POS-only CSS keyed on `request()->is('pos')` (hide header/sidebar/banner, `.page-wrapper` 0 offsets, content padding 12px) | layouts/app:23-32,54-57 | RSC | Moves into `layouts.pos` unconditionally (both modes). |
| `#global-loader` overlay removed by jQuery `script.js:164` | layouts/app:62-64 | SAI | Same on both; `script.js` ships already. |
| Session flash toast, sidebar scroll memory, wheel guard | layouts/app:115-153 | SAI | Kept in `layouts.pos`. |
| Header clock (`window.__setPosClock`, 1 s tick, `/api/server-time` every 5 min) | header:143-227 | ERA | Clock widget becomes a shared partial fed by `runtime.routes.serverTime` (Edge: `server_epoch_ms` from `/pos/shift` or `/health`). |
| Embedded-mode (`?embed=1`) CSS/JS | layouts/app:37-50,60 | SAI | Kept. |

### 1.2 Assets (all local under `public/assets` unless noted; all already inside the artifact)

| Asset | Loaded by | Class | Plan |
|---|---|---|---|
| `css/bootstrap.min.css` (5.3.8), `js/bootstrap.bundle.min.js` | app:13,107 | SAI | Served on Edge via `edge.local.assets` (already allowlisted). |
| `css/style.css` (theme, 1.36 MB) | app:21 | SAI + RED | Ship as is **minus** the two Google `@import` lines (style.css:3-4). |
| Google Fonts Nunito 300–700 (`@import`) | style.css:3 | RED → PL | Vendor latin woff2 (OFL) into `public/assets/fonts/nunito/`, add `css/fonts-local.css` with `@font-face`, linked before `style.css`. |
| Google Fonts Poppins | style.css:4 | RED | Unused by any rule; drop. |
| `plugins/tabler-icons/*` (+ woff2/woff/ttf) | app:18 | SAI | Already served on Edge. |
| `plugins/sweetalert/sweetalert2.all.min.js` | app:111 | SAI | Already served on Edge; 26 `Swal.fire` calls depend on it. |
| `js/jquery-3.7.1.min.js`, `js/script.js`, `js/theme-script.js`, `js/feather.min.js`, `js/jquery.slimscroll.min.js`, `js/moment.min.js`, select2, daterangepicker, animate, datetimepicker CSS/JS, `css/a11y-custom.css` | app:9,14-17,22,104-110,112 | SAI | Load the identical list in the identical order (theme behaviour: loader fade, `.required::after`, focus ring). Unused-by-POS libraries stay for byte-identical theme behaviour. |
| `plugins/fontawesome/css/*` (webfonts dir missing on Cloud too) | app:19-20 | SAI | Unused by POS; keep for parity; note the missing webfonts as a Cloud defect. |
| Header logos `public/images/bingoo_new/*.webp`, flags/avatar under `assets/img` | header:11-17,91-119 | COC | Cloud chrome only (not rendered on Edge). |
| Product photos `asset('storage/…')` (`public/storage` symlink absent) | POSController:322 | ERA | Edge asset helper maps to a local `storage/app/public` route; Cloud unchanged. |
| `@vite` / `public/build` | only `welcome.blade.php` | — | Not used by the POS; nothing to package. |

### 1.3 Blade includes and server-rendered fragments

| Dependency | Where | Class | Plan |
|---|---|---|---|
| `tenant.pos.partials.table-board` (+ re-rendered by `GET /api/pos/table-board` → `{html}`) | index:1060; POSController:518-541 | RSC | Same partial rendered by an Edge endpoint from local data (`{ok, html}`), keeping the Online contract; Edge's JSON board stays for its own tooling. |
| Bill/table bill preview `receipt.blade.php` injected as iframe `srcdoc` | index:5837,5877 | SAI | Edge already renders the same document (`bill-preview/document`). |
| Print-here iframe `/printing/documents/{job}/preview` | index:1879 | ERA | `runtime.routes.printDocument`. |
| `partials/table-bill-preview.blade.php` (orphan) | — | — | Delete (dead). |

### 1.4 Server-side variables the view consumes

| Variable(s) | Online source | Class | Edge source in the shared view |
|---|---|---|---|
| `branches`, `selectedBranchId` | POSController:419-491 | ERA | Single bound branch; branch select rendered disabled (same markup). |
| `terminals`, `allowedOrderTypes`, `activeMode`, `categories`, `pillCategoryIds`, `contentCategoryIds`, `hasUncategorizedCombos`, `productsPayload`, `combosPayload`, `paymentMethods`, `floors`, `waiters`, `deliveryChannels`, `deliveryRiders`, `tableSession`, `heldSale`, `deadSession`, `terminalPrintConfig`, `receiptLayouts` | same | ERA | `EdgeLocalPosController@screen` rebuilt as a **PosPageData** provider producing the SAME variable set from the local DB (reuse `menuPayload()`); non-cash payment methods marked display-only. |
| `quickReportBranches`, `quickReportPrinters` | POSController:455-465 | ERA | Edge: bound branch + local printers. |
| In-view queries: `VoidReason::where(...)` (index:3990), `app(TenantClock)` (512,534,1539), `auth('tenant')` (2473), `User::ORDER_TYPES` | view | SAI | Tenant models/services ship already; `TenantClock` must be verified in the dependency-closure test (Blade references are invisible to it today). |
| `csrf_token()` ×32, `@csrf` ×3, session flash/`$errors` | view | SAI | Edge `edge.auth` group has a session; JSON routes accept the same token header. |
| `@can` ×12 in page + 6 in table-board | view | SAI | Local permission tables + `tenant` guard already work on Edge (proven by W0b). |

### 1.5 Endpoints (58 `url()` literals → runtime map). Full per-endpoint mapping in §3.

### 1.6 Global JS

| Dependency | Class | Plan |
|---|---|---|
| `bootstrap.Modal` API (19 modals), `Swal` (26 dialogs + toast mixin), `crypto.randomUUID`, `history.replaceState`, keyboard shortcuts, localStorage keys (`pos_terminal_{branch}`, `pos_last_sale_*`, `pos_auto_kot/receipt`), sessionStorage sale-uuid key scoped by host | SAI | Identical on both. |
| Polling: shift-status 5 min, print-job polls 4/9/15 s, totals quote 250 ms debounce, header clock | ERA | Routes come from the map; Edge already has equivalents. |
| Native `prompt/confirm/alert` fallbacks | SAI | — |

### 1.7 Cloud-only controls inside the page

| Control | Where | Class |
|---|---|---|
| Report (`#posReportModal`, iframe `/reports/center?embed=1`) | index:513-518,1468 | COC (accepted Cloud-only reporting scope) |
| Quick Report **e-mail** (`/pos/quick-report/email`) | index:1790 | COC (Edge: disabled by capability, same button position) |
| Manage Floors / Manage Tables iframes | index:1045,1051 | COC (admin) |
| Change rider (`/sales-orders/{id}` new tab) | index:5692 | COC for now (gap noted §14) |
| Branch selector (multi-branch) | index:642-653 | ERA (rendered, disabled, single bound branch) |
| `/shifts/open` page link | index:579 | RSC (shared shift page, §6) |

---

## 2. Shared-view architecture (same view, not a copy)

```
resources/views/layouts/pos.blade.php        ← NEW shared POS layout: identical theme asset list/order as layouts/app,
                                                POS chrome rules unconditional, NO header/sidebar/composer,
                                                @yield('chrome') slot, injects window.POS_RUNTIME = @json($posRuntime)
resources/views/tenant/pos/index.blade.php   ← THE page, unchanged in structure; @extends('layouts.pos');
                                                url('/…') literals → POS.route('key', params); asset() → $posRuntime->asset()
resources/views/tenant/pos/partials/*        ← shared partials (table-board, NEW: chrome-cloud, banner-edge, clock)
app/Support/Pos/PosRuntime.php               ← value object: mode, routes[], capabilities[], authority{}, identity{}, assets{}
app/Support/Pos/CloudPosRuntimeFactory.php   ← Cloud adapter (tenant paths, all capabilities, chrome = Online header/sidebar)
app/Services/Edge/EdgePosRuntimeFactory.php  ← Edge adapter (edge.local.* paths, capability gates, authority banner, local assets)
app/Http/Controllers/Tenant/POSController.php  index() builds PosPageData + Cloud runtime → view('tenant.pos.index')
app/Http/Controllers/Edge/EdgeLocalPosController.php  screen() builds PosPageData (local) + Edge runtime → view('tenant.pos.index')
resources/views/tenant/pos/js/pos-runtime.blade.php  ← small shared JS: POS.route(), POS.api() (JSON transport, CSRF, 401/403 handling)
```

Rules:
1. One Blade file renders both modes. Branching inside it happens only through `$posRuntime` (capability flags, labels) — never through `config('app.role')` checks scattered in the view.
2. Cloud-only controls keep their DOM position; on Edge they render **disabled with a tooltip** (capability) rather than removed, except iframes of Cloud pages, which are replaced by the same-size modal showing the capability message. Every such difference is listed in §7 and is the only allowed visual diff.
3. Edge-specific UI (authority banner, network banner, pending-sync chip, Status link, local logout, terminal chooser) lives in `partials/pos/chrome-edge.blade.php` rendered through the layout's `chrome` slot; Online renders `chrome-cloud` (its existing header/sidebar includes, hidden as today). The slot sits outside the page markup, so the page itself is byte-identical.
4. `layouts.app` is untouched for every other Cloud screen; only `/pos` moves to `layouts.pos`. This is not a fork of the layout: it is the POS-specific chrome rule set that `layouts.app` already applied only on `/pos`, now made explicit and role-independent.

Why not keep `layouts.app` on Edge: it renders the Cloud header/sidebar (central-admin fallback when tenant is unbound, 135 Cloud links, subscription lookups) and binds a composer that imports an excluded Cloud service. Making it safe on Edge would require sprinkling role conditionals through Cloud chrome — the opposite of "no second UI".

**OWNER_DECISION_REQUIRED (A1):** approve `layouts.pos` as the shared POS layout (Online `/pos` switches to it; visual result on Online is identical because the chrome was already hidden there).

---

## 3. Runtime adapter contract

### 3.1 `PosRuntime` (PHP value object → `window.POS_RUNTIME`)

```
mode:            'cloud' | 'edge'
identity:        { branch_id, branch_name, branch_selectable(bool), terminal_selection: 'per_request'|'session' }
authority:       { state: 'cloud'|'standby'|'local_active'|..., label, can_mutate(bool), pending_sync(int), banner_text|null }
capabilities:    { reports, quickReportEmail, quickReportNetwork, manageFloorsTables, nonCashTender, customerCreate,
                   customerAddressCreate, changeRider, splitBill, salesReturn, shiftOpenPage, promotions, tips, kitchenNotes,
                   tableMerge, reservations, deadSessionRecovery }   // bool each; source of the disabled/hidden state
routes:          { saleStore, saleHeldSettle, printingRetry, customerSearch, customerQuickStore, customerAddressStore,
                   quickReportSettings, quickReportSave, quickReportPrint, quickReportEmail, quickReportNetwork, quickReportOptions,
                   tableBoardHtml, tableSessions, tableSessionOpenOrders, shiftStatus, shiftOpen, shiftClose, serverTime,
                   totalsQuote, promoQuote, billPreview, heldList, heldShow, heldStore, heldCancel, heldReattach, recentSales,
                   printJobsForSale, printJobsRecent, kotQueue, receiptQueue, reminderConfirm, reminderReprint, printRetry,
                   printDocument, managerVerify, tableOpen, tableBillRequested, tableClose, tableBillPreview, tableMove, tableMerge,
                   reservation, reserve, unreserve, splitBill, salesReturnCreate, salesReturnStore, salesReturnSearch, reportsCenter,
                   manageFloors, manageTables, salesOrderShow, logout, status }
                 // each value: a URL template ('/edge/local/pos/held-sales/{sale}/cancel'); null when the capability is off
assets:          { base: '/assets' | '/edge/local/assets', storage: '/storage' | '/edge/local/storage' }
transport:       { csrf_header: 'X-CSRF-TOKEN', body: 'json', unauthenticated_redirect: '/login' | '/edge/local/login' }
managerApproval: { credential: 'pin' | 'employee_code_and_credential' }
labels:          { modeChip, connection }   // Edge Q-state labels; Cloud: null
```

### 3.2 Canonical JSON contract (both backends conform)

The adapter translates URLs only; **response shapes are made identical** so the page has one code path. Resolution per divergence
(Online endpoint → decision):

| Divergence | Decision |
|---|---|
| Table board: Online `{ok, html}`, Edge JSON | Edge adds `GET …/restaurant/board/html` rendering the shared partial → `{ok, html}` (Edge JSON stays). |
| Held list: Online embeds `lines[]`; Edge needs a second call | Edge `held.index` includes `lines[]` (cheap; same data). Keys aligned to Online (`sales`, `total`, `items`). |
| Open-orders per table session: shapes differ | Edge `session.show` returns Online's `orders[]` shape (lines + `recall_url` → null on Edge, page uses runtime route). |
| Totals/promo quote: Online two endpoints, Edge one preview | Edge exposes `totals/quote` and `promotions/quote` twins over `EdgeLocalPosService::previewBill` (flat keys as Online). |
| Pay a held order: Online `POST /pos` + `held_sale_id`; Edge `settle` | Adapter route `saleHeldSettle`; page already branches on `held_sale_id` → send the same payload; Edge `settle` accepts the full payload and ignores what it cannot apply, **except** discount (see next row). |
| Manual discount timing: Online at pay (refused at hold), Edge at hold | **OWNER_DECISION_REQUIRED (A2):** Edge adopts the Online rule (discount + approval consumed at settle/complete; hold refuses a discount). This is a product-flow alignment inside `EdgeLocalPosService`; the W2 "approval at Apply" behaviour is kept since the approval id is carried to settle. |
| Manager approval credential: `pin` vs employee code + Edge credential | Page renders the prompt from `managerApproval.credential`; same modal markup, one or two fields. Edge adds `throttle:10,1` to match Online. Edge response gains `ok:true` and status 200. |
| Error codes: Online `TABLE_HAS_OPEN_ORDERS` 409, `NO_OPEN_SHIFT`, `INVALID_TERMINAL`; Edge plain 422 | Edge returns the same `code` values and statuses (`EdgeLocalHeldSalesController`, `EdgeLocalRestaurantController`). |
| 403 shape: Edge `{message, permission}`; Online `{message}`; Edge returns/quick-report use bare `abort(403)` | Both return `{message, permission}`; Online `EnsureRoutePermission` adds `permission`; Edge returns/quick-report use `denyUnlessCan`. |
| Print jobs: `job_id` vs `id`, `preview_url`, timestamps | Edge print views emit `job_id` (alias) and `created_at_human`; both keep `preview_url` absolute-relative. |
| Reservation fields (`reserved_*` vs `customer_*`), `{ok}` vs 201 view | Edge accepts both field sets, returns `{ok:true, reservation}`; Online returns the reservation view too. |
| Split bill: Online iframe page; Edge JSON | See §6 (shared split-bill page rendered on both). |
| Sales return: Online iframe page; Edge JSON dialog | See §6 (shared return page). |
| Shift open/close: Online pages; Edge in-page JSON | See §6 (shared shift pages + JSON). |
| Quick report: Online embeds pickers; Edge `options` endpoint | Both provide `options` (Online adds a JSON twin); the page fetches it lazily. |
| Recent sales `time`/`ago` | Edge emits both (`time` formatted like Online, `ago` humanised). |
| Customer search: `legacy_address`, `email`, `customer_uuid` | Both return `{id, customer_uuid, name, phone, email, addresses[], legacy_address}`. |

### 3.3 Transport
`POS.api(routeKey, params, body)`: JSON body on both sides (Online controllers already validate JSON; `lines.*.modifiers` becomes an array on both — Online `validateSale` accepts array or string), CSRF header from the layout meta tag (added to `layouts.pos`), 401/419 → `transport.unauthenticated_redirect`, 403 → toast with `permission`. FormData `<form>` posts in the page (sale form, table open form, bill-requested form) are kept as forms whose `action` comes from the runtime map.

---

## 4. Asset / offline packaging plan

1. `layouts.pos` loads the **same 22 asset files in the same order** as `layouts.app`, through `$posRuntime->asset('assets/…')` (Cloud → `asset()`, Edge → `EdgeLocalAssetController::url()`).
2. Fonts: add `public/assets/fonts/nunito/nunito-{300,400,500,600,700}-latin.woff2` (Google Fonts OFL files, vendored once), `public/assets/css/fonts-local.css` (`@font-face` ×5, `font-display: swap`), linked before `style.css`; remove `style.css:3-4`. **OWNER_DECISION_REQUIRED (A3):** editing the vendor theme file `style.css` (2 lines) — alternative is an override file plus a runtime CSS filter on Edge, which is fragile.
3. `EdgeLocalAssetController`: add `webp` and `jpg` to the allowlist only if a shared partial needs them (the POS page needs neither); add a second root for `storage/app/public` product images behind `edge.local.storage` (allowlisted, read-only, same hardening).
4. Artifact boundary: `public/` and `resources/views` already ship; no new top-level include. Add to `EdgeApplianceArtifactBoundaryTest`: `layouts/pos.blade.php` present, `partials/header|sidebar` may ship but are never rendered by an Edge route (route-level test), no `@import` or `http(s)://` in any CSS linked by the Edge POS (extend the existing no-external-URL test to fetch and scan linked CSS).
5. `EdgeBladeCompileGateTest` widened to `resources/views/tenant/pos/**` and `layouts/pos.blade.php`; `EdgeApplianceDependencyClosureTest` extended with a Blade scan (`app(`, `\App\` static calls in the shared views) so `TenantClock`, `VoidReason`, `UserDataScope` are provably in the plan.
6. Release builder: add `--untracked-check` that refuses when untracked files exist under `public/` or `resources/` (today untracked files ship silently).

---

## 5. Existing Edge files that become OBSOLETE

`resources/views/edge/pos/index.blade.php`; `partials/{styles,header,banners,grid,cart,shell}.blade.php`; `js/{core,context,catalog,cart,actions,commercial,returns,held,payment,printing,tables,reports,shift,sync,boot}.blade.php` (3,773 lines); the Blade view-model part of `EdgeLocalPosController::screen()` (replaced by `PosPageData`); `tests/Fixtures/edge/online-pos-control-census.json` + `EdgeCashierControlCensusHttpMySqlTest` (replaced by the shared-view slot/capability tests + paired screenshots); `tools/edge-browser-proof/edge-pos-proof.mjs` selector aliases (Edge-only ids disappear; the harness becomes the paired-screenshot runner).
Kept unchanged: every `EdgeLocal*Controller`, `EdgeQuickReportController`, `EdgeLocalAssetController`, `app/Services/Edge/*`, `routes/edge_runtime.php` (extended), `edge/auth/login`, `edge/health`, `edge/finance/*` (separate pages; their dark theme is a separate, later parity item).

Edge behaviours that must be re-homed into the shared view before the old page is deleted (from the Edge inventory §4): authority/connection chip and network banner (chrome-edge), stock-baseline banner, Status link, local logout, terminal chooser (session), in-page shift dialog (→ shared shift page), cash-only tender hints, tips-not-syncable hint, returns "online_required_refund" state, reservation "reserved on the Online POS" hint, dead-session recovery on recall, KOT reminder confirm, print-jobs handling, "Manage on the Online POS" for floors/tables, unsupported-order-type refusal. Each maps to a capability flag or a runtime label in §3.1.

---

## 6. Online code refactored into shared components (instead of copied)

| Online piece | Today | Shared form |
|---|---|---|
| Table board partial + `tableBoard()` HTML endpoint | Cloud-only endpoint | Same partial; Edge endpoint renders it from local data. |
| Split bill (`SplitBillController@create/store`, iframe, top-location breakout) | Cloud page | Shared page `tenant/sales-orders/split-bill.blade.php` rendered on Edge by `EdgeLocalHeldSalesController@splitPage` (same view, runtime routes); JSON `store` on both; the iframe breakout replaced by a `postMessage` the page handles identically. |
| Sales return create page (`SalesReturnController@create`, iframe) | Cloud page | Shared page rendered on Edge by `EdgeLocalReturnController@createPage`; Edge's JSON dialog retired. Cash-only refund on Edge shown as disabled non-cash methods (capability). |
| Shift open/close pages (`ShiftController@create/close`) | Cloud pages (branch-wide) | Shared pages rendered on Edge for the selected terminal; Edge JSON open/close kept for the pages' forms. **OWNER_DECISION_REQUIRED (A4):** branch-wide multi-terminal open stays Cloud-only; Edge page shows only the bound terminal. |
| Manager-approval prompt (Swal with `pin`) | Page code | Shared prompt driven by `managerApproval.credential`. |
| Quick Report modal (pickers embedded server-side) | Page + POSController | Both modes fetch `options`; e-mail button capability-disabled on Edge. |
| Customer modal (search + quick-add + address) | Page | Shared; quick-add/address enabled by `capabilities.customerCreate/customerAddressCreate` (Edge on once §8 ships). |
| Header clock | `partials/header` | `partials/pos/clock.blade.php` fed by `routes.serverTime`. |

---

## 7. Cloud-only capability matrix (the ONLY allowed visual differences)

| Control (position kept) | Online | Edge | Rendering on Edge |
|---|---|---|---|
| Report button + `#posReportModal` | Reports Centre iframe | Cloud-only reporting scope (accepted) | Button disabled, tooltip "Reports run on the Online POS" |
| Quick Report → E-mail | sends A4 PDF | needs Internet | Button disabled in the same modal position |
| Manage Floors / Manage Tables (table workspace) | CRUD iframes | admin | Buttons disabled, tooltip |
| Branch selector (context modal) | selectable | bound branch | Same select, disabled, one option |
| Non-cash payment methods (payment modal) | selectable | display-only (owner-dependent) | Options present, disabled with the Online hint text |
| Tip on paid sale / bank-cheque fields | available | refused offline | Fields present, disabled |
| Change rider on a completed delivery (`/sales-orders/{id}`) | link | no Edge route (gap) | Link disabled (or shared page later) |
| Shift open: multi-terminal branch-wide | page | single terminal | Shared page shows the bound terminal only |
| Add customer / address | enabled | enabled after §8 (capability-gated by Cloud advertisement) | Same form; disabled until the Cloud advertises `customer_create` |
| Chrome slot | Cloud header/sidebar (hidden) | Edge status strip (authority chip, network banner, Status, Logout) | Outside the page markup; documented strip height (0 px when standby-synced? no: always rendered, 36 px) — **the one intentional layout difference**, recorded in the screenshot mask |

**OWNER_DECISION_REQUIRED (A5):** the Edge status strip height (fixed 36 px above the page) is the single accepted layout delta.

---

## 8. Offline add-customer contract

Design (facts: `customer_uuid` ULID unique on both sides; envelope already carries `{kind:'customer', customer_uuid, name, phone}`; Cloud
`resolveCustomerId` refuses unknown UUIDs terminally; refresh matches customers by numeric id; phone is not unique; the only normaliser is
`CustomerDirectory::normalizePhone` = digits only).

**Edge local customer** (`EdgeLocalPosController@quickStoreCustomer`, permission `tenant.pos.customers.quick-store`, Local Mode only):
- new columns on the appliance `customers`: `edge_origin` enum('cloud','local') default 'cloud', `edge_created_at`, `phone_normalized` (edge migration; additive);
  `customer_addresses`: `address_uuid` char(26) nullable unique (tenant migration, additive — Cloud gets it too).
- create: `customer_uuid` = ULID (model already does it), `code` null, `phone_normalized` = `CustomerDirectory::normalizePhone`, `status` active, `edge_origin` local;
  local **find-by-phone first** against the synced book (reuse `CustomerDirectory::findByPhone`) → reuse instead of create (mirrors Online `reused:true`).
- immediately attachable; search endpoint returns `customer_uuid`.

**Sale envelope** (v1 additive, only when the Cloud advertised `capabilities.customer_create` in the heartbeat; otherwise the button is capability-off):
`customer: { kind:'new_customer', customer_uuid, name, phone, phone_normalized, email|null, address:{ address_uuid, label, address }|null }`. `content_hash` covers it. Old Cloud → `CUSTOMER_INVALID` can no longer happen because the appliance never emits the kind without the advertisement.

**Cloud ingestion** (`EdgeInboundSaleIngestionService::resolveCustomerId`, inside the posting transaction):
1. `customer_uuid` known → use it.
2. else `customer_uuid_aliases` (new tenant table: `alias_uuid` PK, `customer_id`, `source_device_uuid`, `created_at`) known → use the mapped customer.
3. else `phone_normalized` non-empty and `CustomerDirectory::findByPhone` (row lock) finds a customer → bind the sale to it, **insert alias(uuid → id)**, fill empty name/email only (never rename). Conflict rule: existing name kept; the sale row keeps its own `customer_name/phone` snapshot.
4. else create the customer **with the envelope's UUID** (`customer_uuid` is settable by the ingestion path only), `phone_normalized`, address with `address_uuid`.
Idempotency: replay with the same `sale_uuid` + hash → `already_applied` (unchanged); a retry after a refused/exception row re-runs steps 1–4 and finds the UUID or alias → no duplicate. Two envelopes for the same offline customer (two sales) → step 1 or 2 hits after the first. Subsequent Online creation of the same phone → Online quick-store already reuses by phone; the customer editor gets a duplicate-phone warning (Cloud UI change, small).

**Refresh applier** (`EdgeLocalConfigRefreshApplier`): customers and addresses matched by **UUID**, not id (`customer_uuid`, `address_uuid`); a local row whose UUID is absent from the Cloud set is left untouched while `edge_origin='local'` and the sale that created it is not yet acknowledged; once acknowledged, the Cloud row (same UUID, Cloud id) replaces the local id **only if no local FK references it** — to avoid id rewrites, the Cloud export includes aliases so the appliance maps alias→Cloud customer and the local row is marked `edge_origin='cloud'` with its UUID kept. Bootstrap export adds `customer_uuid_aliases` (schema bump to `edge-bootstrap-v8`; appliance v7 keeps working with the applier fix from 7b8f886).

**Schema/event/API changes:** tenant migrations: `customers.phone_normalized` (+ index), `customer_addresses.address_uuid`, table `customer_uuid_aliases`; edge migration: `customers.edge_origin/edge_created_at`; heartbeat response advertises `capabilities.customer_create`; envelope v1 gains the `new_customer` kind (additive, gated); Edge routes `pos.customers.quick-store`, `pos.customers.addresses.store` (+ allowlist + census); Online `CustomerController::quickStore` also stores `phone_normalized`.
Tests: Cloud ingestion (uuid known / alias / phone match / create / replay / conflict), Edge quick-store (Local Mode only, reuse-by-phone), refresh applier (UUID matching, local rows survive, alias mapping), end-to-end MySql (offline sale with new customer → handback → one Cloud customer).

---

## 9. Permission model correction

- New `App\Support\Pos\PosPermissionCatalog`: the deduplicated list of every permission Edge checks at runtime (30 today, from the inventory), grouped by workflow (cashier / manager / finance) with the Online route that owns each.
- Feature test `EdgePermissionCatalogTest`: greps `denyUnlessCan(`, `->can(`, `abort_unless(...can`, `PERM_*` across Edge controllers/services/views and fails when a checked permission is not in the catalogue (prevents the LAB drift).
- `TenantProvisioner` gains a **`Cashier (Counter)` role template** = the catalogue's cashier group ∩ Online cashier reality (void-kot-item, table move/merge, returns, quick-store, held-sales.*, shifts.*, table-sessions.*) — **OWNER_DECISION_REQUIRED (A6)** (changes provisioning for new tenants; existing tenants unaffected; a `permissions:audit-cashier-roles` command reports real roles missing catalogue permissions instead of granting anything).
- `EdgeLocalRuntimeFixture::onlinePosParityPermissions()` is replaced by the template; the LAB seed uses it.
- MySql regression tests per workflow with a cashier holding exactly the template: void KOT item (with approver), table move, table merge, returns, customer quick-store, and a table-driven test over every catalogue permission that the matching Edge endpoint returns 403 `{permission}` without it and 2xx with it.
- Never "grant all": the audit command is read-only; production roles are edited by the tenant.

---

## 10. Device compatibility / version reporting

- Appliance: `EdgeAuthorityLeaseClient::heartbeat()` adds an optional `build` block from `EdgeBuildInfoService::info()`: `edge_app_version`, `git_commit`, `bootstrap_schema`, `edge_schema_version` (shipped), `applied_edge_schema_version` (from `edge_local_meta`), `config_schema`, `envelope_versions` (`['edge-sale-envelope-v1','edge-sale-envelope-v2']` from the builder constants), `capabilities`. Sent every beat (20 s) — cheap; the Cloud ignores it when unchanged.
- Cloud: `EdgeAuthorityApiController` validates `build.*` as nullable; `EdgeAuthorityLeaseService` writes `edge_devices.app_version/schema_version/compatibility_manifest/compatibility_reported_at` **only when the hash of the block changed**, outside the tenant lease transaction (a version write can never fail a heartbeat); columns widened to 64. Heartbeat response gains `capabilities: {customer_create: true, …}` (the advertisement §8 needs).
- `EdgeCompatibilityService::classify` reused to show "update required" on the Cloud Offline Edge page; `compatibility/report` endpoint kept and also called once after `edge:local:update` succeeds (belt and braces).
- Tests: heartbeat with/without `build`; write-on-change only; old Cloud ignores the block; Cloud page shows the current version after a simulated update.

---

## 11. Implementation workstreams (non-overlapping file ownership)

| WS | Owner | Files owned | Depends on |
|---|---|---|---|
| **W-A Shared layout + runtime adapter (Cloud side)** | Team A | `resources/views/layouts/pos.blade.php` (new), `resources/views/tenant/pos/index.blade.php` (URL literals → `POS.route`, `@extends`, asset helper; no visual change), `resources/views/tenant/pos/js/pos-runtime.blade.php` (new), `resources/views/tenant/pos/partials/{clock,chrome-cloud}.blade.php`, `app/Support/Pos/{PosRuntime,CloudPosRuntimeFactory,PosPageData}.php`, `app/Http/Controllers/Tenant/POSController.php` (index only) | A1 |
| **W-B Edge adapter + contract alignment** | Team B | `app/Services/Edge/EdgePosRuntimeFactory.php` (new), `app/Http/Controllers/Edge/EdgeLocalPosController.php` (screen → shared view; JSON twins totals/promo), `EdgeLocalHeldSalesController.php` (lines in list, codes, split page), `EdgeLocalRestaurantController.php` (board html, session.show shape, reservation fields), `EdgeLocalPrintJobController.php` (job_id alias), `EdgeLocalManagerApprovalController.php` (throttle, `ok`), `EdgeLocalReturnController.php` (shared page, denyUnlessCan), `EdgeLocalShiftController.php` (shared pages), `EdgeLocalPosService.php` (discount timing A2), `routes/edge_runtime.php`, `config/edge.php` allowlist, `resources/views/tenant/pos/partials/chrome-edge.blade.php` | W-A contract (§3), A2, A4 |
| **W-C Assets, fonts, boundary gates** | Team C | `public/assets/fonts/nunito/*`, `public/assets/css/fonts-local.css`, `public/assets/css/style.css` (2 lines), `app/Http/Controllers/Edge/EdgeLocalAssetController.php` (storage root), `tests/Feature/Edge/{EdgeApplianceArtifactBoundaryTest,EdgeBladeCompileGateTest,EdgeApplianceDependencyClosureTest,EdgeLocalAssetRouteTest}.php`, `tests/MySql/EdgeCashierShellHttpMySqlTest.php` (CSS scan), `app/Console/Commands/EdgeBuildPackageCommand.php` (untracked check) | A3 |
| **W-D Offline add-customer** | Team D | `app/Services/Edge/{EdgeSaleEnvelopeBuilder,EdgeInboundSaleIngestionService,EdgeLocalConfigRefreshApplier,EdgeBootstrapService,EdgeStandbyAdvertiser}.php`, `app/Services/Tenant/CustomerDirectory.php`, `app/Http/Controllers/Tenant/CustomerController.php` (phone_normalized), new `EdgeLocalCustomerController.php`, migrations (3 tenant + 1 edge), `app/Models/Tenant/CustomerUuidAlias.php`, tests | §8 contract, heartbeat capability (W-F) |
| **W-E Permission model** | Team E | `app/Support/Pos/PosPermissionCatalog.php`, `app/Services/Tenancy/TenantProvisioner.php` (cashier template), `app/Console/Commands/PermissionsAuditCashierRolesCommand.php`, `tests/Feature/Edge/EdgePermissionCatalogTest.php`, `tests/MySql/Support/EdgeLocalRuntimeFixture.php`, `tests/MySql/EdgeCashierPermissionMatrixMySqlTest.php` | A6 |
| **W-F Version reporting** | Team F | `app/Services/Edge/{EdgeAuthorityLeaseClient,EdgeAuthorityLeaseService,EdgeAuthorityService}.php`, `app/Http/Controllers/Edge/EdgeAuthorityApiController.php`, migration widening `edge_devices` columns, Cloud Offline Edge page view, tests | — |
| **W-G Paired-screenshot acceptance + census retirement** | coordinator | `tools/edge-browser-proof/*` (paired runner + pixelmatch), `tests/MySql/EdgeSharedPosViewMySqlTest.php` (both modes render the same view; capability slots; no Cloud URL on Edge), deletion of `resources/views/edge/pos/*` and the census (last), docs | all |

Shared-file rule: `EdgeLocalPosController.php` is owned by W-B only; `index.blade.php` by W-A only (W-B requests changes through the contract doc); `config/edge.php` allowlist edits by W-B only; migrations by W-D/W-F only.

Phases: **1** this report → approval. **2** W-A + W-C + W-F (Cloud renders `/pos` from `layouts.pos` pixel-identical; fonts local; heartbeat build block). **3** W-B (Edge renders the shared view; every existing Edge workflow re-proven; old page deleted last) + W-E. **4** W-D. **5** W-G paired acceptance on the second laptop → new signed release → owner-approved LAB update (with the 7b8f886 updater fix now in the build).

---

## 12. Test strategy

- Unit/Feature: `PosRuntime` factories (both modes, every route key resolves, capability defaults), catalogue test, artifact/boundary/closure gates widened, Blade compile gate on the shared view, no-external-URL incl. linked CSS.
- MySql/HTTP: `EdgeSharedPosViewMySqlTest` — `GET /edge/local/pos` and Cloud `GET /pos` render `tenant.pos.index` with the same modal ids and DOM order (structural diff of the page markup, not just ids), Edge page contains no `/pos`, `/api/pos`, `/printing`, `/restaurant` literal (only runtime-map values), all `POS_RUNTIME.routes` resolve to allowlisted routes; contract tests per endpoint pair asserting identical response keys (fixture-driven, both backends); existing W1–W6 Edge suites re-run unchanged against the shared view (they test JSON endpoints, not markup, except the census which is retired).
- Regression protection against drift: the Online page is the only page; a test asserts `resources/views/edge/pos` no longer exists; `EdgePermissionCatalogTest`; paired screenshot baseline in CI (dev instance vs Cloud dev, same seed).
- Add-customer, permissions, version reporting tests as in §8–10.

## 13. Pixel-level paired screenshot acceptance

Harness (`tools/edge-browser-proof/paired-pos-proof.mjs`, Playwright msedge, no download): same seeded dataset on the LAB Cloud tenant and, via refresh, the appliance; same user (cashier with the template role); viewports 1366×768 and 1024×768 (+ the cashier laptop's native size); for each of ~30 states (main POS; category/grid; cart with 3 lines; customer dialog; variants; modifiers; weighted qty; Hold/Draft/Recall lists; table board; table workspace; Add Round; Request Bill; Bill Preview; move/merge; manager approval; Review & Pay; shift open/close; returns; Recent Prints; print-here) capture Online and Edge, run pixelmatch with a 0.5 % threshold and an explicit mask for the §7 differences (status strip band, disabled Cloud-only controls). Output: side-by-side PNG + diff PNG + JSON report; any unmasked diff fails. Final acceptance repeats the run on the second laptop with real interaction for the repaired workflows (owner), plus the LAB Local Mode window for sale flows, as done for 0.7.0.

## 14. Migration / release risks

| Risk | Mitigation |
|---|---|
| Online `/pos` regression while switching to `layouts.pos` | Phase 2 ships to Cloud first with the paired screenshot baseline Online-before vs Online-after (must be pixel-identical). |
| The 7,142-line page's JS depends on 58 literals; a missed literal silently 404s on Edge | Test asserts no literal Cloud path remains in the rendered Edge page; `POS.route` throws on unknown keys. |
| Discount-timing change (A2) alters Edge sale flow | Covered by existing W2/W6 envelope tests + new settle tests; owner decision. |
| Split/returns/shift shared pages pull Cloud-only services into the artifact | Dependency-closure test extended to Blade; boundary test lists allowed controllers. |
| Add-customer refresh matching by UUID touches the tombstone engine (data safety) | Applier tests with local rows + alias mapping; bootstrap v8 gated; capability advertisement prevents old-Cloud rejections. |
| Font vendoring/licensing | Nunito is SIL OFL 1.1; files committed with the licence text. |
| Package size (public/assets 68 MB already ships) | Unchanged; optional later trim of unused plugins is out of scope. |
| Change-rider and multi-terminal shift remain Cloud-only | Listed in §7; owner may extend later. |

## 15. Restricted-artifact impact

- No new top-level directory ships. New files land in `public/assets` (fonts, css), `resources/views/layouts`, `resources/views/tenant/pos`, `app/Support/Pos`, `app/Services/Edge` — all already inside the include list.
- No Cloud-only controller is routed on Edge: the shared pages (split, return, shift) get **Edge controllers** rendering the shared views; the Cloud controllers stay excluded/unrouted as today.
- `EdgeRouteManifest` allowlist gains: `pos.customers.quick-store`, `pos.customers.addresses.store`, `pos.restaurant.board.html`, `pos.totals.quote`, `pos.promotions.quote`, `pos.split.page`, `pos.returns.create-page`, `pos.shift.open-page`, `pos.shift.close-page`, `storage` — each added to the URI census deliberately.
- The Edge CLI boundary, authority fencing, outbox, printing and finance paths are untouched.

---

## Per-file change statements

```
FILE=resources/views/layouts/pos.blade.php (NEW)
CURRENT_PURPOSE=—
PROPOSED_CHANGE=shared POS layout: identical asset list/order as layouts/app via $posRuntime->asset(); POS chrome CSS unconditional; csrf meta; @yield('chrome'); window.POS_RUNTIME
CLOUD_IMPACT=/pos renders through it (pixel-identical; chrome hidden as today)   EDGE_IMPACT=renders the same layout with local assets
OFFLINE_SAFE=yes (no external URL)   OWNER_DECISION_REQUIRED=A1

FILE=resources/views/tenant/pos/index.blade.php
CURRENT_PURPOSE=the Online cashier POS (7,142 lines)
PROPOSED_CHANGE=@extends('layouts.pos'); 58 url('/…') → POS.route(...); asset()/storage → $posRuntime->asset(); capability attributes on Cloud-only controls; manager prompt from managerApproval.credential; no layout/markup reshuffle
CLOUD_IMPACT=behaviour identical   EDGE_IMPACT=becomes THE Edge cashier page   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=resources/views/tenant/pos/js/pos-runtime.blade.php (NEW)
CURRENT_PURPOSE=—   PROPOSED_CHANGE=POS.route()/POS.api() transport, CSRF, 401/403 handling
CLOUD_IMPACT=replaces inline fetch boilerplate   EDGE_IMPACT=same   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=app/Support/Pos/PosRuntime.php, CloudPosRuntimeFactory.php, PosPageData.php (NEW)
CURRENT_PURPOSE=—   PROPOSED_CHANGE=runtime contract §3.1 + page-data provider interface
CLOUD_IMPACT=POSController::index builds them   EDGE_IMPACT=Edge factory implements the same   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=app/Http/Controllers/Tenant/POSController.php
CURRENT_PURPOSE=Online POS page + quote/board/preview endpoints
PROPOSED_CHANGE=index(): PosPageData + Cloud runtime; JSON twins: quick-report options; unchanged endpoints
CLOUD_IMPACT=small   EDGE_IMPACT=none (not shipped as a route)   OFFLINE_SAFE=n/a   OWNER_DECISION_REQUIRED=no

FILE=app/Services/Edge/EdgePosRuntimeFactory.php (NEW) + app/Http/Controllers/Edge/EdgeLocalPosController.php
CURRENT_PURPOSE=Edge page VM + JSON endpoints
PROPOSED_CHANGE=screen() renders tenant.pos.index with local PosPageData + Edge runtime; add totals/promo quote twins; customer search returns customer_uuid/email
CLOUD_IMPACT=none   EDGE_IMPACT=new cashier page   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php, EdgeLocalRestaurantController.php, EdgeLocalPrintJobController.php, EdgeLocalManagerApprovalController.php, EdgeLocalReturnController.php, EdgeLocalShiftController.php
CURRENT_PURPOSE=Edge JSON endpoints
PROPOSED_CHANGE=response keys/status/error codes aligned to the canonical contract (§3.2); shared split/return/shift pages; board html; throttle on manager verify
CLOUD_IMPACT=none   EDGE_IMPACT=contract parity   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=A4 (shift page scope)

FILE=app/Services/Edge/EdgeLocalPosService.php
CURRENT_PURPOSE=Edge sale/hold/settle authority
PROPOSED_CHANGE=manual discount + approval consumed at settle/complete (Online rule); hold refuses discount
CLOUD_IMPACT=none   EDGE_IMPACT=flow alignment   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=A2

FILE=routes/edge_runtime.php, config/edge.php (route_allowlist), tests/Feature/Edge/EdgeBranchServerRegistrationTest.php
CURRENT_PURPOSE=Edge route surface + default-deny allowlist + URI census
PROPOSED_CHANGE=new routes listed in §15   CLOUD_IMPACT=none   EDGE_IMPACT=surface extended deliberately   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=public/assets/css/style.css (2 lines), public/assets/css/fonts-local.css (NEW), public/assets/fonts/nunito/* (NEW)
CURRENT_PURPOSE=theme; Google Fonts import
PROPOSED_CHANGE=remove @import lines; local @font-face; vendored OFL woff2
CLOUD_IMPACT=Cloud no longer calls Google Fonts (same rendering)   EDGE_IMPACT=Nunito renders offline   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=A3

FILE=app/Http/Controllers/Edge/EdgeLocalAssetController.php
CURRENT_PURPOSE=hardened static server for public/assets
PROPOSED_CHANGE=second root for storage/app/public (product images), same hardening; no new types unless needed
CLOUD_IMPACT=none   EDGE_IMPACT=product photos offline   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=tests/Feature/Edge/{EdgeApplianceArtifactBoundaryTest,EdgeBladeCompileGateTest,EdgeApplianceDependencyClosureTest,EdgeLocalAssetRouteTest}.php, tests/MySql/EdgeCashierShellHttpMySqlTest.php
CURRENT_PURPOSE=artifact/boundary gates
PROPOSED_CHANGE=cover the shared view, linked-CSS scan, Blade class scan, served-asset set
CLOUD_IMPACT=none   EDGE_IMPACT=drift protection   OFFLINE_SAFE=n/a   OWNER_DECISION_REQUIRED=no

FILE=app/Console/Commands/EdgeBuildPackageCommand.php
CURRENT_PURPOSE=release builder   PROPOSED_CHANGE=refuse untracked files under public/ and resources/
CLOUD_IMPACT=none   EDGE_IMPACT=safer releases   OFFLINE_SAFE=n/a   OWNER_DECISION_REQUIRED=no

FILE=app/Services/Edge/EdgeSaleEnvelopeBuilder.php, EdgeInboundSaleIngestionService.php, EdgeLocalConfigRefreshApplier.php, EdgeBootstrapService.php, EdgeStandbyAdvertiser.php; app/Services/Tenant/CustomerDirectory.php; app/Http/Controllers/Tenant/CustomerController.php; NEW EdgeLocalCustomerController.php; migrations (tenant: phone_normalized, address_uuid, customer_uuid_aliases; edge: edge_origin)
CURRENT_PURPOSE=sync contract + customers
PROPOSED_CHANGE=§8 contract (new_customer kind, create-or-match, aliases, UUID refresh matching, capability gate)
CLOUD_IMPACT=ingestion + customer editor duplicate warning; bootstrap v8   EDGE_IMPACT=add-customer at the till   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=approved (feature); bootstrap v8 bump = yes

FILE=app/Support/Pos/PosPermissionCatalog.php (NEW), app/Services/Tenancy/TenantProvisioner.php, app/Console/Commands/PermissionsAuditCashierRolesCommand.php (NEW), tests/MySql/Support/EdgeLocalRuntimeFixture.php, tests (catalogue + matrix)
CURRENT_PURPOSE=permissions   PROPOSED_CHANGE=§9   CLOUD_IMPACT=new tenants get a cashier template; audit command   EDGE_IMPACT=complete cashier set by construction   OFFLINE_SAFE=n/a   OWNER_DECISION_REQUIRED=A6

FILE=app/Services/Edge/{EdgeAuthorityLeaseClient,EdgeAuthorityLeaseService,EdgeAuthorityService}.php, app/Http/Controllers/Edge/EdgeAuthorityApiController.php, migration (edge_devices columns 64), Cloud Offline Edge page
CURRENT_PURPOSE=lease heartbeat   PROPOSED_CHANGE=§10 build block + capabilities advertisement
CLOUD_IMPACT=device record stays current   EDGE_IMPACT=20 s beat carries build info   OFFLINE_SAFE=yes   OWNER_DECISION_REQUIRED=no

FILE=resources/views/edge/pos/** (22 files), tests/Fixtures/edge/online-pos-control-census.json, tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php
CURRENT_PURPOSE=the parallel Edge cashier page + id census
PROPOSED_CHANGE=DELETE in Phase 3 (last step, after every workflow is re-proven on the shared view)
CLOUD_IMPACT=none   EDGE_IMPACT=one cashier page   OFFLINE_SAFE=n/a   OWNER_DECISION_REQUIRED=no (consequence of the final decision)
```

## Owner decisions requested before Phase 2

A1 shared `layouts.pos` · A2 discount timing aligned to Online · A3 editing `style.css` (2 lines) + vendoring Nunito · A4 shift pages scope (bound terminal only on Edge) · A5 the Edge status strip as the single accepted layout delta · A6 cashier role template in the provisioner. Everything else follows from the final product decision.
