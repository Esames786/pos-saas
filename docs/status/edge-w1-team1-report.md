# W1 — Team 1 report: cashier shell, navigation, states, responsiveness

Charter: `docs/status/edge-parity-team-charter.md`. Online reference: `resources/views/tenant/pos/index.blade.php` (**O:**),
`resources/views/layouts/app.blade.php` (**L:**), `app/Http/Controllers/Tenant/POSController.php` (**POSC:**). No git operation,
no composer/npm, no LAB/production touch, no Cloud call, no new business rule, no outbox/authority/contract/finance edit.

## Files (all Team-1-owned)

| File | Change |
|---|---|
| `app/Http/Controllers/Edge/EdgeLocalAssetController.php` | NEW — whitelisted `public/assets` streamer (css/js/woff/woff2/ttf/svg/png/ico), `available()` / `url()` helpers for Blade |
| `routes/edge_runtime.php` | W1 block: `GET /edge/local/assets/{path}` → `edge.local.assets` (outside the `edge.auth` group — see note), no session started |
| `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` | W1 block: `edge/local/assets/{path}` approved |
| `config/edge.php` | W1 block in `route_allowlist`: `edge.local.assets` (added on the coordinator's instruction of 25 Sep; own marked block only) |
| `tests/Feature/Edge/EdgeLocalAssetRouteTest.php` | NEW (6 tests) |
| `tests/MySql/EdgeCashierShellHttpMySqlTest.php` | NEW (9 tests) |
| `resources/views/edge/pos/partials/styles.blade.php` | rewritten — Online light shell, sizes, breakpoints, inline icon, optional local Bootstrap/Tabler links |
| `resources/views/edge/pos/partials/header.blade.php` | rewritten — Online header layout (title row, session bar, mode tabs + customer slot, context row), Edge strip |
| `resources/views/edge/pos/partials/banners.blade.php` | Online alert look, `role=alert` |
| `resources/views/edge/pos/partials/shell.blade.php` | `#modal`, severity `#toast`, `#edge-loading`, `#posContextModal` (+`#terminal`), `#edge-confirm`, `#calculator-panel`, optional local SweetAlert2 + Bootstrap bundle |
| `resources/views/edge/pos/js/core.blade.php` | busy buttons, loading bar, 401/419 → login, severity `toast()`, `toastError()`, `confirmDialog()`, `showSpinner/hideSpinner`, `showInlineError/showInlineToast`, `openDialog/closeDialog`; the old contract (`DATA/CSRF/BASE/money/esc/state/api/toast/uuid/openModal/closeModal/$`) unchanged |
| `resources/views/edge/pos/js/context.blade.php` | terminal (assigned → remembered → first), context summary, shift badge poll, order-type TABS + fresh-order switch, `lockOrderType/unlockOrderType` (same names), customer chip |
| `resources/views/edge/pos/js/sync.blade.php` | quiet poll + severity colours (labels unchanged) |
| `resources/views/edge/pos/js/boot.blade.php` | header wiring (typeof-gated), headings, calculator, shortcuts, Esc, nav toggle, deep links, polls |
| `resources/views/edge/auth/login.blade.php` | Online light look, inline icon, busy submit (text "Branch Server" kept) |
| `resources/views/edge/health.blade.php` | look only: inline icon line (no `/favicon.ico` 404) |

**Route placement note.** The W1 block inside the `pos` group is behind `edge.auth`; the asset route must answer the
unauthenticated login page, so it is a second clearly-marked W1 block directly after the `pos` group (still inside
`edge/local`). The in-group W1 marker carries a pointer comment.

## Records

### A1 / E-01 — Header / title / navigation
- ONLINE_BEHAVIOUR: O:419-438 (sidebar toggle `#pos-sidebar-toggle`, "Restaurant POS" h1, View Tables only when dine-in allowed O:427), L:23-32 (no app chrome on /pos), O:2496-2506 (toggle).
- EDGE_IMPLEMENTATION: `partials/header.blade.php` (title row), `partials/styles.blade.php`, `js/boot.blade.php` (navToggle, View Tables gate). Edge-only entry points (sync chip, Status, Suppliers, Journal, Purchase Returns, user, Logout) live in `#edge-nav` at the right of the title row; `#pos-sidebar-toggle` collapses/expands `#edge-nav-links` (default collapsed < 1200 px, remembered per browser) — the Online controls are never displaced. `<h1>POS</h1>` kept (render test) with a `Restaurant` prefix span → reads "Restaurant POS".
- PERMISSION_AND_VALIDATION: View Tables hidden unless `dine_in` ∈ `DATA.orderTypes` (Online O:427, JS-hidden so the id stays for other tests); finance links unchanged (`@if` on their permissions).
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_header_follows_the_online_pos_layout_with_edge_links_in_a_secondary_strip`.
- BROWSER_ACCEPTANCE_STEP: load `/edge/local/pos` at 1366×768 — title row "☰ Restaurant POS [View Tables] … Synced · Status · Ayesha Cashier · Logout"; click `#pos-sidebar-toggle` → strip links hide/show.
- CENSUS_FLIPS: `pos-sidebar-toggle` planned → **present** `#pos-sidebar-toggle` (A1; Edge semantics = toggles the Edge navigation strip).
- REMAINING_DIFFERENCE: Online toggles the whole app sidebar (Dashboard/Operations/…); Edge has no such sidebar (E-02 destinations are owner-classified), the toggle collapses the Edge strip instead. Flash-message chips of O:431-437 have no Edge counterpart (no redirects with flash on the till).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN → browser screenshots taken read-only (see Tests); coordinator acceptance pending.

### A2 — Order-type TABS
- ONLINE_BEHAVIOUR: O:481-490 (`#mode-tabs-wrapper` `.mode-tab[data-mode-tab]` of the allowed types), O:6359-6428 `applyModeTab` (Swal "Start a fresh … order?" when order state exists, clears cart/customer/delivery/vehicle/tender, `?mode=` via replaceState), hidden `#order_type` O:607-614, CSS lock while recalled O:4220-4230.
- EDGE_IMPLEMENTATION: `partials/header.blade.php` (server-rendered tabs in `User::ORDER_TYPES` order, active = default; hidden `<select id="order-type">`), `js/context.blade.php` (`applyModeTab`, `resetOrderForModeSwitch`, `highlightModeTab`, `lockOrderType/unlockOrderType` add/remove `.pos-controls-locked`); after a switch it calls W2's `onOrderTypeChanged()`/`scheduleQuote()` when present and dispatches `edge:order-type-changed`.
- PERMISSION_AND_VALIDATION: tabs = `DATA.orderTypes` (the user's effective allowed set ∩ Edge types, EdgeLocalPosController:84-89); server still refuses a disallowed type (unchanged).
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_order_type_is_a_tab_row_of_the_users_allowed_types`.
- BROWSER_ACCEPTANCE_STEP: add a tile, click `[data-mode-tab="takeaway"]` → confirm "Start a fresh Takeaway order?" (`#edge-confirm` or Swal); Cancel keeps Dine In; OK clears the cart and the URL gets `?mode=takeaway`. Recall a check → tabs dimmed/locked.
- CENSUS_FLIPS: `mode-tabs-wrapper` equivalent `#order-type` → **present** `#mode-tabs-wrapper`; `order_type` stays **equivalent** `#order-type` (hidden select, Online also hides it).
- REMAINING_DIFFERENCE: Online also clears the tender field (inside its payment modal) — Edge's tender lives in W2's Review & Pay modal, which is rebuilt every time it opens.
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (switch/confirm executed in the read-only browser run on the dev instance, no mutation).

### A33 / E-06 — Branch & Terminal context
- ONLINE_BEHAVIOUR: O:141-163 context row (`#ctx-branch-name` · `#ctx-terminal-name`, Change `@can(change-terminal)`), O:204-239 `#posContextModal` (branch select + terminal select incl. "No Terminal"), O:2452-2482 autoSelectTerminal (assigned → localStorage → first), O:7027-7040 updateContextSummary, O:2400-2404 `#no-terminal-warning`.
- EDGE_IMPLEMENTATION: header `#order-controls-row` (branch · terminal, `#pos-context-change-btn` only with `$canChangeTerminal`, `#no-terminal-warning`), shell `#posContextModal` with the bound-branch panel ("This Branch Server is bound to one branch — the branch cannot be changed here.") and `<select id="terminal">`; `js/context` `renderTerminals` (assigned → remembered `pos_terminal_<branch>` → first), `selectTerminal` (POST `/terminal/select`, then shift poll), `updateContextSummary`.
- PERMISSION_AND_VALIDATION: Change button = `UserDataScope::CHANGE_TERMINAL_PERMISSION` (Online `@can`); the server pin + assignment checks of W0b are unchanged.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_branch_and_terminal_context_dialog_and_change_gate` (+ W0b `EdgeCashierRouteGatesHttpMySqlTest`).
- BROWSER_ACCEPTANCE_STEP: as DEVMGR1 click `#pos-context-change-btn` → dialog "Branch & Terminal", branch read-only, pick Counter 2 → `#ctx-terminal-name` updates, badge re-polls; as DEVCASH1 (pinned) no Change button.
- CENSUS_FLIPS: `posContextModal` partial `#terminal` → **present** `#posContextModal`; `ctx-terminal-name` equivalent `#terminal` → **present** `#ctx-terminal-name`; `order-controls-row` equivalent `#terminal` → **present** `#order-controls-row`; `no-terminal-warning` planned → **present** `#no-terminal-warning`; `branch_id` stays equivalent (`js:branchId`, bound branch by design), `terminal_id` stays equivalent `#terminal`.
- REMAINING_DIFFERENCE: no "No Terminal" option — on Edge a sale/shift without a terminal is refused server-side ("Select a terminal first."), so the warning says selling is blocked (Online: only auto-print is off). No branch selector by design (EnsureEdgeBranchBound).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (dialog screenshot needs a change-terminal user; DEVCASH1 is pinned).

### A35 — Touch targets
- ONLINE_BEHAVIOUR: tiles `min-height:148px` in `minmax(170px,1fr)` (O:124-132,165-181), pills `min-height:44px` (O:53-64), mode tabs (O:30-38), action buttons `min-height:42px` (O:286), qty buttons 32 px (O:290-297), keypad (O:327-340).
- EDGE_IMPLEMENTATION: `partials/styles.blade.php` (`.tiles`, `.tile`, `.pill`, `.mode-tab`, `.actions button`, `.line .ln-ctl button` 32×32, `.keypad button`).
- PERMISSION_AND_VALIDATION: n/a.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_online_sizes_breakpoints_and_offline_assets`; measured in the browser run at 1366×768: mode tab 44 px, action button 42 px, cart column 500 px.
- BROWSER_ACCEPTANCE_STEP: 1366×768 — tiles ≥148 px tall, category pills ≥40 px (W2's `.w2-strip .pill{min-height:40px}` overrides the 44 px shell rule — see requests), action buttons 42 px.
- CENSUS_FLIPS: none (no ids).
- REMAINING_DIFFERENCE: W2's grid partial sets its own pill height (40 px); I did not override another team's rule.
- STATUS: FUNCTIONAL_BUT_UI_DIFFERENT until W2 aligns the pill height (request below); otherwise matched.

### A36 / E-08 — Confirmations, severity toasts, spinners, inline errors, 401/419
- ONLINE_BEHAVIOUR: Swal toasts top-end 3 s (O:3784-3796), Swal confirms (mode switch O:6369-6381 etc.), `setButtonBusy` spinner+label (O:2123-2160), global loader (L:62-64), inline alerts, redirect to /login on 401 (bootstrap/app.php `redirectGuestsTo`).
- EDGE_IMPLEMENTATION: `js/core.blade.php` — `api()` marks the clicked button busy (spinner + "Please wait", restored in `finally`) and shows `#edge-loading` after 250 ms (background polls pass `{quiet:true}`); 401 (`edge.auth`) / 419 (CSRF) → error toast + redirect to `/edge/local/login` once; `toast(msg[, level])` severity-aware (API refusal → error, guidance → warning, else success; Swal toast when SweetAlert2 is loaded, else the in-page top-end `#toast`; `#toast` text is always set for the proof tool); `toastError()`; `confirmDialog({title,text,confirmText,icon,danger}) → Promise<boolean>` (Swal, else `#edge-confirm`); `showSpinner/hideSpinner`; `showInlineError(target,msg)` / `showInlineToast(target,msg,level)` (+ `.edge-inline-error` / `.edge-inline-toast` styles) for the other teams' dialogs; Esc closes the top dialog.
- PERMISSION_AND_VALIDATION: n/a (display); the 401 is the `EnsureEdgeAuthenticated` JSON refusal.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_states_and_session_expiry_contract` (page contract + real 401 JSON for `/edge/local/pos/shift` after logout + 302 for the page).
- BROWSER_ACCEPTANCE_STEP: click Recall → the button shows a spinner while the (slow) dev server answers; switch mode with items → confirm; log out in another tab, then click Recall → red toast "You are signed out…" and the page returns to `/edge/local/login`.
- CENSUS_FLIPS: none directly. `qr-toast`, `reserve-toast`, `open-table-error` stay **planned** — they are ids inside W4/W3 dialogs; the helpers exist for those teams (`showInlineToast('qr-toast', …)` etc.).
- REMAINING_DIFFERENCE: SweetAlert2 loads through the local asset route (allowlisted); if it ever fails to load, the in-page fallbacks render the same messages; busy state applies to buttons whose handler calls `api()` synchronously (every current handler does).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (401 redirect not executed in the browser — it needs a session kill).

### A41 / R1.1 — Shift status badge, detail, open-shift link
- ONLINE_BEHAVIOUR: O:148-152 (`#pos-shift-status` badge/detail/`#pos-shift-open-link`), O:2409-2447 `refreshShiftStatus` on terminal change + every 5 min (O:2451).
- EDGE_IMPLEMENTATION: header `#pos-shift-status` (+ always-visible `#shift-btn`), `js/context` `refreshShiftStatus()` → `GET /edge/local/pos/shift` (quiet): open → green "Shift open · Business date … · opened HH:MM"; none → red "No open shift · POS operations are blocked on this terminal." + `#pos-shift-open-link`; polled after each terminal select, 400 ms after any dialog closes (a shift dialog may have opened/closed the shift) and every 300 s. `#shift-btn` and `#pos-shift-open-link` call W4's `openShiftDialog()` (falls back to `shiftAction()`).
- PERMISSION_AND_VALIDATION: amounts are never read by the badge; `GET /shift` already strips them (W0b AmountVisibility); terminal from the session (422 without one → badge hidden).
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_shift_badge_endpoint_and_page_contract` (422 → null shift → open → business date/opened_at).
- BROWSER_ACCEPTANCE_STEP: load the page; after the terminal select the badge shows "Shift open · Business date 2026-09-20 · opened 14:24" (dev DB has an open shift on Counter 1); click `#shift-btn` → W4 dialog.
- CENSUS_FLIPS: `pos-shift-status` partial `#shift-btn` → **present** `#pos-shift-status`; `pos-shift-badge` planned → **present** `#pos-shift-badge`; `pos-shift-detail` planned → **present** `#pos-shift-detail`; `pos-shift-open-link` partial `#shift-btn` → **present** `#pos-shift-open-link` (opens the in-page shift dialog instead of `/shifts/open`).
- REMAINING_DIFFERENCE: Online's link navigates to the Open Shift page; Edge opens the in-page dialog. Online also retunes the page clock from the poll (`__setPosClock`) — Edge has no header clock.
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (badge request/response observed in the browser network log; screenshot pending a responsive dev server).

### A32 — Calculator
- ONLINE_BEHAVIOUR: O:317 `#toggle-calc-btn`, O:354-363 `#calculator-panel` / `#calculator_heading` / `#calc-display` / keypad, O:6582-6606 handler, Ctrl+M.
- EDGE_IMPLEMENTATION: `partials/shell.blade.php` (panel + 16 keys + "="), `js/boot` docks it in the cart column above `#actions`, adds `#toggle-calc-btn` to the injected cart heading row, keypad handler (C clears, = evaluates arithmetic only — input restricted to `[0-9+-*/.() ]`).
- PERMISSION_AND_VALIDATION: n/a.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_calculator_shortcuts_and_deep_links_are_on_the_page`; browser: Ctrl+M, 7 * 6 = → `42`.
- BROWSER_ACCEPTANCE_STEP: press Ctrl+M (or `#toggle-calc-btn`) → "Touch Keypad / Calculator" opens in the cart column; 7 × 6 = shows 42; Ctrl+M closes.
- CENSUS_FLIPS: `toggle-calc-btn`, `calculator-panel`, `calculator_heading`, `calc-display` planned → **present** (same ids).
- REMAINING_DIFFERENCE: none known. (`public/assets/js/calculator.js` targets another markup (`.input`), so the Online POS's own inline handler was mirrored instead.)
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN → executed in the read-only browser run (screenshot `pos-calc.png`).

### A34 — Keyboard shortcuts
- ONLINE_BEHAVIOUR: O:6608-6616 — Ctrl+F search, Ctrl+H hold, Ctrl+L held orders, Ctrl+P payment, Ctrl+Enter complete, Ctrl+M calculator.
- EDGE_IMPLEMENTATION: `js/boot` keydown: Ctrl+F → `#pos_search` (W2's visible search; falls back to `#search`) focus+select; Ctrl+H → click `#hold-sale-btn` else `#save-round-btn` (the contextual W3 button, so the current rules apply); Ctrl+L → `openHeldOrders()` else `recallList()`; Ctrl+P → `#review-pay-btn` (inside the pay dialog: focus `#rp-tendered`); Ctrl+Enter → `#rp-complete` else `#review-pay-btn`; Ctrl+M → calculator; Esc closes confirm / context dialog / `#modal`. Browser defaults (find, history, address bar, print) are prevented.
- PERMISSION_AND_VALIDATION: only visible, enabled buttons are clicked — the server gates stay the only authority.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_calculator_shortcuts_and_deep_links_are_on_the_page`; browser: Ctrl+F focused `pos_search`, Ctrl+L opened "Held Orders", Esc closed it.
- BROWSER_ACCEPTANCE_STEP: Ctrl+F → cursor in search; Ctrl+L → Held Orders dialog; Esc closes; add item, Ctrl+H → held (mutation — coordinator only).
- CENSUS_FLIPS: none (no ids).
- REMAINING_DIFFERENCE: Online Ctrl+P focuses the payment method select (Online's payment is a modal too); Edge opens Review & Pay first. Ctrl+Enter on Online submits directly; Edge clicks the Complete button of the open pay dialog (or opens it).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (F/L/M/Esc executed read-only; H/Enter mutate → coordinator).

### A43 — Page-load deep links
- ONLINE_BEHAVIOUR: POSC:37-116 (`held_sale_id`, `table_session_id`, `mode`, `branch_id`, `customer_id`), O:6621-6690 preload.
- EDGE_IMPLEMENTATION: `js/boot` `deepLinks()`: `?mode=` (allowed only) → tab; `?held_sale_id=` → `loadHeld(id)` (W3); `?table_session_id=` → board lookup, `startCheckOnSession(table)` (W3); `?customer_id=` → `GET /customers?id=` and attach if the row returns.
- PERMISSION_AND_VALIDATION: every load goes through the existing gated endpoints (held show, board); `branch_id` ignored by design (bound branch).
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_calculator_shortcuts_and_deep_links_are_on_the_page` (contract). Executed load needs a held sale (mutation).
- BROWSER_ACCEPTANCE_STEP: `/edge/local/pos?mode=takeaway` → Takeaway tab active; `/edge/local/pos?held_sale_id=<id of a held check>` → check recalled.
- CENSUS_FLIPS: none (no ids).
- REMAINING_DIFFERENCE: **`?customer_id=` is BLOCKED on W2**: `GET /edge/local/pos/customers` only searches `q` (≥ 2 chars); it needs an `id` lookup (request below). The page code is ready and degrades silently.
- STATUS: PARTIALLY_IMPLEMENTED (customer_id blocked on W2).

### E-09 — Responsive layout
- ONLINE_BEHAVIOUR: O:7-12 `.pos-shell` `minmax(0,1fr) 500px`, O:261-268 sticky cart, O:342-346 `@media (max-width:1199px)` single column + static cart, O:348-370 `@media (min-width:1200px) and (min-height:720px)` fixed shell, modals `modal-fullscreen-lg-down`.
- EDGE_IMPLEMENTATION: `partials/styles.blade.php` — default ≥1200: flowing page, sticky 500 px cart (`calc(100vh - 24px)`), grid `max-height:calc(100vh - 300px)`; ≥1200 & ≥720 tall: fixed shell, columns scroll inside (cart `overflow-y:auto` so actions stay reachable with the calculator open); ≤1199: one column, cart under products; ≤991.98: `#modal` full-screen; ≤800: tighter chrome, mode tabs scroll sideways.
- PERMISSION_AND_VALIDATION: n/a.
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_online_sizes_breakpoints_and_offline_assets`; browser: no horizontal scroll at 1366×768, 1024×768, 800×600.
- BROWSER_ACCEPTANCE_STEP: screenshots at the three viewports (`pos-1366x768.png`, `pos-1024x768.png` cart below products, `pos-800x600.png`).
- CENSUS_FLIPS: none.
- REMAINING_DIFFERENCE: none known in the shell; W2/W3 injected CSS (grid partial, tables `<style>`) may still carry dark-theme colours (e.g. `rgba(180,83,9,.15)` recalled bar, `#93c5fd` note) — owners to align.
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN → screenshots taken read-only.

### E-10 — Offline assets + favicon
- ONLINE_BEHAVIOUR: L:9-22,104-112 local `asset()` Bootstrap 5.3.8 / SweetAlert2 / Tabler; `style.css` imports Google Fonts (the only external URL).
- EDGE_IMPLEMENTATION: `EdgeLocalAssetController` + route; pages link the packaged files ONLY when `EdgeLocalAssetController::available()` (route registered and on the branch_server allowlist) — never a link that 404s; inline sheet is self-sufficient; no font import (Nunito/Segoe UI system stack); inline `data:` SVG icon on POS, login and health pages.
- PERMISSION_AND_VALIDATION: route unauthenticated by design, whitelisted types only, no `..`/dot-file/absolute/drive/backslash/NUL, no symlink on the path, real path inside `public/assets`, `Cache-Control: public, max-age=86400`, ETag + 304, `nosniff`, no session started.
- EXECUTABLE_TEST: `EdgeLocalAssetRouteTest` (6: bootstrap 200 + headers + 304; every referenced asset type; missing 404; 19 traversal/foreign-type forms 404; no login + no session cookie; allowlist check against the shipped config) · `EdgeCashierShellHttpMySqlTest::test_local_assets_are_linked_only_when_the_asset_route_is_allowed` · `::test_online_sizes_breakpoints_and_offline_assets` (no `src/href` to `http(s)://` or `//`, no `@import`).
- BROWSER_ACCEPTANCE_STEP: after the allowlist line lands: devtools network → `/edge/local/assets/css/bootstrap.min.css` 200 from the appliance, SweetAlert toasts top-right; before: no asset request at all, no 404 on `/edge/local/pos` (the only remaining favicon 404 is on the JSON `/edge/local/status` page the login redirects to — not a Team-1 file).
- CENSUS_FLIPS: none.
- REMAINING_DIFFERENCE: none in W1 scope. `edge.local.assets` is now in the W1 block of `config/edge.php` `route_allowlist`; the dev instance serves `/edge/local/assets/css/bootstrap.min.css` 200 `text/css` and the page shows Tabler icons + SweetAlert2 dialogs. Without the allowlist entry the pages fall back to the inline sheet + in-page dialogs (never a 404 link).
- STATUS: MATCHED_AND_PROVEN for asset loading (route tests + browser network 200); page render FUNCTIONAL_BUT_NOT_BROWSER_PROVEN until the coordinator's paired screenshots.

### Page-wide look (Team A §1)
- ONLINE_BEHAVIOUR: O:6-418 + `public/assets/css/style.css` tokens (#F7F7F7 page, white `.pos-card`, #111827 active pills, gold `#CAA23F` primary, `#FFCA18` warning, navy `#1B2850` dark, Nunito 14 px).
- EDGE_IMPLEMENTATION: `partials/styles.blade.php` — the whole page is re-themed through the shared class names other teams use (`.primary/.ok/.warn/.danger/.ghost/.sm`, `.field`, `.err`, `.row` (Bootstrap-grid neutralised), `.list-row`, `.chip`, `.board/.tbl` with Online's status left-borders, `.modal .box` Bootstrap modal look).
- EXECUTABLE_TEST: `EdgeCashierShellHttpMySqlTest::test_online_sizes_breakpoints_and_offline_assets`.
- BROWSER_ACCEPTANCE_STEP: compare `pos-1366x768.png` with the Online POS at the same viewport.
- REMAINING_DIFFERENCE: font is the system stack (Online requests Nunito from Google Fonts, which never loads offline either); W2/W3 injected styles still carry a few dark-theme colours (requests below).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (read-only screenshots taken; paired Online/Edge screenshots = coordinator).

### Census rows owned by W1 (not covered above)
| Online id | New state | Edge selector | Note |
|---|---|---|---|
| `products_heading` | present | `#products_heading` | injected by js/boot above `#product-grid` unless W2 renders it |
| `cart_heading` | present | `#cart_heading` | injected cart heading row ("Cart" / "Table Cart") unless W2 renders it |
| `pos-customer-slot` | present | `#pos-customer-slot` | header |
| `pos-customer-chip` | present | `#pos-customer-chip` | header chip mirrors `state.customer` / typed name |
| `chip-cust-name` | present | `#chip-cust-name` | |
| `chip-cust-phone` | present | `#chip-cust-phone` | was planned |
| `chip-cust-address` | present | `#chip-cust-address` | was planned; delivery address when type = delivery |
| `chip-cust-clear` | present | `#chip-cust-clear` | clears the attached customer (not on a recalled check) |
| `pos-customer-btn` | present | `#pos-customer-btn` | wired to W2 `openCustomerModal()` (present in the tree today) |
| `pos-return-btn` | present | `#pos-return-btn` | **the old `#returns-btn` selector is gone** (renamed to the Online id; W4's test already asserts `#pos-return-btn`); hidden + never wired without `canSalesReturn` |
| `pos-quick-report-btn` | present | `#pos-quick-report-btn` | hidden without `canQuickReport` |
| `held-orders-btn` | present | `#held-orders-btn` | → W3 `openHeldOrders()` |
| `completed-orders-btn` | present | `#completed-orders-btn` | header copy → W3 `openCompletedOrders()` (W3 skips its own duplicate) |
| `last-print-btn` | present | `#last-print-btn` | → W5 `openLastPrint(lastPrintSale…)` |
| `pos-session-bar` + `pos-session-details/-table-no/-no/-waiter/-guests/-open-check/-actions/-bill-preview/-request-bill-form` | present (content W3) | same ids | markup in the header (Online position); W3 `renderSessionBar()` fills/wires it |
| `edit-order-btn` | — | — | NOT in the header: it belongs to W3's `#recalled-order-bar` (Online O:366-380); a header copy would duplicate the id |

Census rows whose selector W1 removed/changed (the census FAILS on purpose until flipped): `pos-return-btn` (`#returns-btn` → `#pos-return-btn`); `mode-tabs-wrapper`, `posContextModal`, `ctx-terminal-name`, `order-controls-row`, `pos-shift-status`, `pos-shift-open-link` (old selectors `#order-type`/`#terminal`/`#shift-btn` still exist, so these only need the upgrade, they do not fail).

## Requests

**Coordinator**
1. (done by Team 1 on your instruction) `config/edge.php` `route_allowlist` W1 block: `edge.local.assets`.
2. Census fixture flips listed above.
3. `tools/edge-browser-proof/edge-pos-proof.mjs`: `#returns-btn` → `#pos-return-btn`; order-type switching via `[data-mode-tab=…]` (the `#order-type` select is hidden now — Playwright's `selectOption` needs a visible element); `#tiles .tile` → `#product-grid .tile` (W2 renamed the grid).
4. No line needed in `index.blade.php`.
5. (optional) `EdgeLocalAuthController::login` redirects to the JSON `/edge/local/status`; that page has no icon, so the browser asks `/favicon.ico` → the last 404. Redirecting a cashier to `/edge/local/pos` would remove it (not a Team-1 file).

**Team 2**: `GET /edge/local/pos/customers?id=N` (exact id lookup) for the `?customer_id=` deep link; `.w2-strip .pill{min-height:40px}` → 44 px (Online O:59); rely on the `edge:order-type-changed` event (or keep the tab-click listener — both work); if you render `#products_heading` / `#cart_heading` yourself, the shell stops injecting them automatically.
**Team 3**: the header now hosts `#pos-session-bar` with your ids (details + `#pos-session-bill-preview`, `#pos-session-request-bill-form`, `#pos-session-move-btn`, `#pos-session-merge-btn`, `#pos-session-status`) — any new session-bar control id must be added to `partials/header` (ask Team 1) or appended into `#pos-session-actions`; the tables `<style>` for `#pos-session-bar` / `#recalled-order-bar` still uses dark colours (`rgba(180,83,9,.15)`), please use the light tokens (`var(--line)`, `#fff3cd`).
**Team 5**: `recentPrints()` renders a second `id="last-print-btn"` inside the Recent Prints dialog; `$('last-print-btn')` then returns the header button (first in DOM) and the in-dialog "Last sale" button is dead. Please rename the dialog button (e.g. `#recent-prints-last-sale-btn`).
**Team 4**: finance pages (`resources/views/edge/finance/*`) still request `/favicon.ico` → add the same inline `<link rel="icon" …>` line as `partials/styles`.

## Tests run (team-1 databases `pos_test_*_edgewt_t1`)

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_t1 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t1 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t1
```
| Command | Result (25 Sep 2026, after the allowlist line) |
|---|---|
| `vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierShellHttpMySqlTest\|EdgeCashierScreenRendersHttpMySqlTest\|EdgeCashierRouteGatesHttpMySqlTest\|EdgeCashierPermissionHttpMySqlTest\|EdgeCashierConnectionStateHttpMySqlTest\|EdgeCashierReturnHttpMySqlTest'` | **OK — 22 tests, 332 assertions** (shell 9/9) |
| `EDGE_NODE_BIN=… vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest` | 3/4 pass (inventory pinned, deferrals registered, **composed script passes `node --check`**); `test_every_census_row_matches_the_rendered_edge_page` fails with 25 mismatches: 23 are W2 selectors that W2 moved (`#cm-*`, `#category-tabs`, customer modal ids); the 2 W1-group rows are `pos-customer-btn` (`#cm-cust-q`) and `chip-cust-clear` (`#cm-cust-clear`) → flip both to **present** `#pos-customer-btn` / `#chip-cust-clear` (header). No W1 selector is missing. |
| `vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php tests/Feature/Edge/EdgeLocalAssetRouteTest.php` | **OK — 23 tests, 31 294 assertions** |

Read-only browser checks on the dev instance (127.0.0.1:8095, DEVCASH1, Microsoft Edge headless via the proof tool's Playwright; script in my scratchpad, no mutation — nothing held/paid/printed; one tile added to the in-page cart only):
no horizontal scroll at 1366×768 / 1024×768 / 800×600; tile 148 px, mode tab 44 px, action button 42 px, cart column 500 px; badge
"Shift open · Business date 2026-09-20 · opened 02:24 PM"; sync chip "Synced"; `/edge/local/assets/css/bootstrap.min.css` 200 `text/css`; Tabler icons
and SweetAlert2 confirm rendered; Ctrl+M calculator 7*6= → 42; Ctrl+F → `#pos_search`; Ctrl+L → "Held Orders"; Esc closes; switching to
Takeaway with an item → "Start a fresh Takeaway order?" (Cancel keeps Dine In; Start Fresh → Delivery tab, cart cleared, URL `?mode=delivery`);
nav toggle collapses the strip. Only console error: the `/favicon.ico` 404 of the JSON `/edge/local/status` page the login redirects to.
Screenshots: `C:\Users\Dell\AppData\Local\Temp\claude\d--laragon2-www-pos-saas-edge\a8cd7183-85e0-43ea-97aa-bc628fc088e0\scratchpad\shots4\` (scratch, not evidence).

`tools/edge-browser-proof/edge-pos-proof.mjs` (read-only run) stops at its first wait: `#tiles .tile` no longer exists because W2 renamed the grid
to `#product-grid` — coordinator tool update (request 3). The dev server is single-threaded and shared by every team: 3–8 s per request today.

## Follow-up (25 Sep 2026): Team 2's hand-back asks

| Ask | Done in `js/boot.blade.php` |
|---|---|
| (1) wire `#pos_search` | Ctrl+F targets `#pos_search` (falls back to `#search`, null-guarded). The input listener is NOT added a second time: `js/catalog` `wireSearch()` already binds `#pos_search` input to barcode scan + `renderTiles()`, so a boot listener would run `renderTiles()` twice per keystroke. The hidden `#search` is bound only when `#pos_search` is absent, so W2 can remove it safely. |
| (2) products heading on `#product-grid` | `productsHeading()` anchors on `#product-grid` (legacy `#tiles` fallback) and never duplicates an existing `#products_heading`. |
| (3) Ctrl+P / Ctrl+Enter on the Online ids | Ctrl+P: if the pay dialog is open → focus + select `#tendered_amount` (legacy `#rp-tendered` fallback), else click `#review-pay-btn`. Ctrl+Enter: click `#complete-sale-btn` → `#rp-complete` → `#review-pay-btn`, whichever is visible and enabled first. |

Tests (t1 databases):
- `--filter 'EdgeCashierShellHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierPermissionHttpMySqlTest'`: **OK, 17 tests / 252 assertions**.
- `EdgeCashierControlCensusHttpMySqlTest`: 3/4. Row matching passes and the composed script passes `node --check`. The one failure is `test_every_edge_deferral_string_is_registered`: an unregistered W2 string at `app/Http/Controllers/Edge/EdgeLocalPosController.php:213` ("Awaiting owner decision — offline …"). It is not a W1 file; Team 2 or the coordinator needs to register it in the census `deferrals`.
