# W4 — Team 4 report: shifts · permissions · finance screens (Offline Edge cashier parity)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1` (base 63b88d8 + later coordinator/team commits).
Charter: `docs/status/edge-parity-team-charter.md`. No git operation, no LAB/production touch, no new financial event, no
change to F1/F2/F3 envelopes or Cloud ingestion. Tests ran on the team-4 databases only (`pos_test_*_edgewt_t4`).

## Files changed / added

| File | Change |
|---|---|
| `app/Http/Controllers/Edge/EdgeLocalShiftController.php` | rewritten (same routes): badge keys added to `GET /shift`; open = Opening Cash **required** + Opening Notes; summary adds terminal/branch names, opening notes, denomination book, permissions, history URL; close = denomination count (CashCountLine, Online semantics) OR typed total + closing notes, one transaction, figures masked in the answer for a blind count, shortage message without a voucher; NEW `historyScreen` (R1.8) + `showScreen` (R1.9) |
| `app/Http/Controllers/Edge/EdgeLocalReturnController.php` | search answer adds `can_view_list`/`list_url`; store answer adds `detail_url`; NEW `listScreen` / `detailScreen` (R3.6) |
| `app/Services/Edge/EdgeLocalReturnService.php` | UserDataScope now also fences `returnable()` + `processReturn()` (hand-typed sale id); NEW `listReturns()` / `returnDetail()` + business sync label |
| `app/Http/Controllers/Edge/EdgeLocalManagerApprovalController.php` | Online `ManagerApprovalController@verify` payload rules mirrored (R4.1); identity model unchanged |
| `app/Http/Controllers/Edge/EdgeQuickReportController.php` | options add `items` + `business_name`; NEW `settings` / `saveSettings` (local `pos_quick_report_settings`); header name = tenant business name when bound, else branch |
| `app/Http/Controllers/Edge/EdgeLocalSupplierFinanceController.php` + `app/Services/Edge/EdgeLocalSupplierFinanceService.php` | NEW `paymentsIndex/paymentShow/journalsIndex/journalShow` + `listEvents/eventOfType/supplierBook` (R8.4, R8.6) |
| `app/Http/Controllers/Edge/EdgeLocalPurchaseReturnController.php` + `app/Services/Edge/EdgeLocalPurchaseReturnService.php` | NEW `listScreen/detailScreen` + `listReturns/returnDetail/supplierBook` (R9.4) |
| `resources/views/edge/pos/js/shift.blade.php` | `openShiftDialog()` (+ `shiftAction` alias, `shiftClosedSummary`) |
| `resources/views/edge/pos/js/returns.blade.php` | `openReturns()` (+ `returnsFlow` alias), Online create-screen structure |
| `resources/views/edge/pos/js/reports.blade.php` | `openQuickReport()` (+ `quickReport` alias), Online modal ids and filters |
| `resources/views/edge/finance/layout.blade.php` (new) | shared self-contained shell for the list/detail screens |
| `resources/views/edge/finance/{shifts-index,shifts-show,sales-returns-index,sales-returns-show,supplier-payments-index,supplier-payments-show,manual-journals-index,manual-journals-show,purchase-returns-index,purchase-returns-show}.blade.php` (new) | the Edge-local screens |
| `resources/views/edge/finance/{suppliers,journal,purchase-returns}.blade.php` | header links to the new lists (permission-gated) |
| `routes/edge_runtime.php` W4 block | 12 routes (below) |
| `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` W4 block | the 12 URIs |
| `config/edge.php` | `capabilities` (+5 names) **and** a W4 block in `route_allowlist` (see note) |
| tests | NEW `EdgeCashierShiftParityHttpMySqlTest`, `EdgeCashierReturnParityHttpMySqlTest`, `EdgeCashierQuickReportParityHttpMySqlTest`, `EdgeSupplierFinanceHttpListsMySqlTest`, `EdgePurchaseReturnHttpListsMySqlTest`; `EdgeCashierReturnHttpMySqlTest` needle updated to `#pos-return-btn` (W1 adopted the Online id) |

**config/edge.php note:** the charter gives Team 4 only `capabilities`, but a route whose name is not on `route_allowlist`
is refused on a branch server at runtime. Teams 3 and 5 already appended their own blocks there; I appended a W4 block
with exactly my 12 names (nothing else touched). Coordinator: please confirm/keep.

W4 routes (all `edge.auth`+`edge.branch`, GET unless noted): `/edge/local/pos/shifts`, `/shifts/{shift}`, `/sales-returns`,
`/sales-returns/{salesReturn}`, `/supplier-payments`, `/supplier-payments/{event}`, `/finance/manual-journals`,
`/finance/manual-journals/{event}`, `/purchase-return-list`, `/purchase-return-list/{event}` (distinct prefix because the
existing `/purchase-returns/{event}` would capture a `/purchase-returns/…` sub-path), `/quick-report/settings`,
POST `/quick-report/save-settings`.

## Records

### R1.1 — shift badge endpoint (Team 1 draws the badge)
- ONLINE_BEHAVIOUR: `ShiftController@posStatus` (ShiftController.php:185-219) — has_terminal/open/business_date/timezone/opened_at/server_epoch_ms, no amounts.
- EDGE_IMPLEMENTATION: `EdgeLocalShiftController@shiftStatus` — every pre-existing key kept; added `terminal_name, has_terminal, open, business_date, timezone, opened_at_display, server_epoch_ms`.
- PERMISSION_AND_VALIDATION: auth only (Online allow-prefix); terminal via `selectedTerminal()`; amounts via AmountVisibility (W0b).
- EXECUTABLE_TEST: `EdgeCashierShiftParityHttpMySqlTest::test_open_shift_requires_opening_cash_and_keeps_the_opening_notes_and_the_badge_stays_backward_compatible`; `EdgeCashierRouteGatesHttpMySqlTest::test_shift_status_strips_amounts_for_a_blind_count_operator` (unchanged, still green).
- BROWSER_ACCEPTANCE_STEP: owned by Team 1 (`#pos-shift-badge` polls `GET /edge/local/pos/shift`).
- CENSUS_FLIPS: none by Team 4 (pos-shift-badge/detail are header ids — Team 1).
- REMAINING_DIFFERENCE: badge rendering is Team 1's. STATUS: FUNCTIONAL (API) — badge UI with Team 1.

### R1.2 — open-shift dialog
- ONLINE_BEHAVIOUR: `tenant/shifts/open.blade.php` (Opening Cash :35 required, Opening Notes :74); `ShiftController@store` :119-178 (`opening_cash` required numeric ≥0, `opening_notes`).
- EDGE_IMPLEMENTATION: `openShiftDialog()` in js/shift — title "Open Shift", branch + **terminal shown** (`#sh-terminal-name`), `#opening_cash`, `#opening_notes`, `#sh-open`; `EdgeLocalShiftController@openShift` passes notes to shared `ShiftService::open($notes)`.
- PERMISSION_AND_VALIDATION: `tenant.shifts.store` (W0b) + terminal authority; `opening_cash` now **required** numeric ≥0 (was nullable→0), `opening_notes` ≤1000.
- EXECUTABLE_TEST: `EdgeCashierShiftParityHttpMySqlTest::test_open_shift_requires_opening_cash_and_keeps_the_opening_notes_and_the_badge_stays_backward_compatible`.
- BROWSER_ACCEPTANCE_STEP: POS → Shift (`#shift-btn`/`#pos-shift-open-link`) with no open shift → `#opening_cash`, `#opening_notes`, `#sh-open`; the terminal name is in the dialog.
- CENSUS_FLIPS: `pos-shift-open-link` stays Team 1's header control (partial → present when Team 1 wires it to `openShiftDialog`).
- REMAINING_DIFFERENCE: branch-wide multi-terminal open + per-terminal override cash = Online **manager** page (`/shifts/open` with all terminals) — not the cashier surface; not built (each counter opens its own). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R1.3 — close-shift parity
- ONLINE_BEHAVIOUR: `tenant/shifts/close.blade.php` (summary :27-35, cash-count grid `tenant/partials/cash-count.blade.php`, Manual Counted Cash :56, live diff script, Closing Notes :72, shortage note); `ShiftController@close` :262-326 + `calculateCashCount` :582-620; CASH-SHORTAGE-1 voucher :308-323.
- EDGE_IMPLEMENTATION: dialog "Close Shift": breakup, `#sh-denominations` grid (`.cash-denomination`, `#denomination_{id}`, `#cash-count-total` live), `#counted_cash`, `#counted-diff` live Short/Over/Exact (only when amounts visible — blind count keeps the instruction, like Online withholding `data-expected`), `#closing_notes`, `#sh-close`; post-close summary `#shift-closed-title` with `#shift-closed-detail-link`. Server: denominations win over the typed total, CashCountLine rows (`source_type=shift`), an untouched grid or quantities for unknown denominations are **no count**; lines + close in ONE transaction (Online writes lines before the close and can leave orphans on a refused close — Edge does not).
- PERMISSION_AND_VALIDATION: `tenant.shifts.close` (W0b); `denominations.*` integer ≥0, `counted_cash` numeric ≥0, `closing_notes` ≤1000; shared `ShiftService::closeShift` (blank count refused unless zero drawer).
- EXECUTABLE_TEST: `EdgeCashierShiftParityHttpMySqlTest::test_close_with_denominations_records_the_count_lines_and_the_notes_and_raises_no_voucher`.
- BROWSER_ACCEPTANCE_STEP: dev instance as DEVMGR1 (sees amounts): Shift → type denominations → `#cash-count-total` and `#counted-diff` update → notes → Close Shift → summary. As DEVCASH1 (hide-amounts): no expected, `#counted-diff` keeps "Count the drawer…". Note: the grid appears only when the appliance has a default currency with denominations (see CONTRACT_REQUIREMENTS C-3); otherwise `#sh-no-denominations` is shown.
- CENSUS_FLIPS: none (close form is not in the Online POS view).
- REMAINING_DIFFERENCE: **CASH-SHORTAGE draft voucher NOT created** (contract boundary — OWNER_DECISIONS O-1); the close answer and the detail page state the shortage and that the branch server does not raise the finance entry. Denominations are not synced to the appliance today (C-3). STATUS: PARTIALLY_IMPLEMENTED (voucher owner-dependent).

### R1.4 / R1.5 / R1.6 — blind count, zero drawer, operating business date
- ONLINE_BEHAVIOUR: AmountVisibility (`app/Support/AmountVisibility.php:26-33`), close.blade.php :27-35/:58; ZERO-DRAWER-1 in `ShiftService::closeShift` :235-242; OPERATING-DATE-1 `TenantClock::operatingBusinessDate`.
- EDGE_IMPLEMENTATION: summary/close/history/detail all strip figures when `may_see_amounts=false`; the **close answer** no longer echoes expected/counted/variance (it did before — leak closed); zero drawer via shared service (`count_source=zero_drawer`); operating date = open shift's business_date.
- EXECUTABLE_TEST: `EdgeCashierShiftParityHttpMySqlTest::test_blind_count_close_carries_no_figure_and_zero_drawer_and_operating_date_are_executable` (+ existing `EdgeCashierShiftAndNetworkDownHttpMySqlTest`).
- BROWSER_ACCEPTANCE_STEP: DEVCASH1 (hide-amounts) → Shift → `#sh-blind-note`, `*****` figures; close → "Amounts are hidden for your role".
- REMAINING_DIFFERENCE: Online's Cash Count Breakdown shows line amounts unmasked; Edge masks them for a blind count (conservative). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R1.7 — tender breakup + cancellations/voids
- ONLINE_BEHAVIOUR: `ShiftController@closeBranchForm` :355-401 (SHIFT-CANCELLATIONS-1), index cash detail.
- EDGE_IMPLEMENTATION: `breakup()` (opening, sales, cash, card, bank, cheque, refunds, expected, counted, variance, cancelled bills/amount, voided lines/units) for the **current terminal** in the dialog (as the Online cashier sees it) and per shift on the detail page; history rows have the Online "Cash" expander.
- EXECUTABLE_TEST: `EdgeCashierShiftAndNetworkDownHttpMySqlTest::test_shift_summary_breakup_…`; detail page in `EdgeCashierShiftParityHttpMySqlTest::test_shift_history_and_detail_screens_…`.
- REMAINING_DIFFERENCE: branch-wide per-terminal breakup lives on Close Branch (R1.10, owner). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R1.8 — shift history (Edge-local screen)
- ONLINE_BEHAVIOUR: `ShiftController@index` :19-98, `tenant/shifts/index.blade.php` (status/date filters, Today/Yesterday, branch group row, cash detail, View).
- EDGE_IMPLEMENTATION: `GET /edge/local/pos/shifts` → `edge/finance/shifts-index.blade.php`: `#status-filter`, `#date-from`, `#date-to` (frozen business_date), `#shift-filter-today/yesterday` (operating date), group row with page expected/difference, columns #/Terminal/Opened By/Business date/Opened At/Closed At/Opening Cash/Status/Action, `[data-cash-toggle]` expander, `#shift-view-{id}`, `#close-branch-note`. Local shifts of the bound branch only.
- PERMISSION_AND_VALIDATION: `tenant.shifts.index`; View button only with `tenant.shifts.show`; HIDE-AMOUNTS-2 masking.
- EXECUTABLE_TEST: `EdgeCashierShiftParityHttpMySqlTest::test_shift_history_and_detail_screens_follow_the_online_permissions_branch_and_masking`.
- BROWSER_ACCEPTANCE_STEP: Shift dialog → `#sh-history-link` (or `/edge/local/pos/shifts`) as DEVMGR1.
- REMAINING_DIFFERENCE: no branch filter (one bound branch); Cloud (Online) shifts are not on the appliance. Header entry point = Team 1 request. STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R1.9 — shift detail / post-close summary
- ONLINE_BEHAVIOUR: `ShiftController@show` :221-233, `tenant/shifts/show.blade.php`.
- EDGE_IMPLEMENTATION: `GET /edge/local/pos/shifts/{shift}` → `shifts-show.blade.php` (Shift Summary incl. opening/closing notes, Cash Summary, cancelled/voided, `#shift-shortage-note`, Cash Count Breakdown `#shift-cash-count`, "Close Shift (POS)" link); post-close summary modal in the POS.
- PERMISSION_AND_VALIDATION: `tenant.shifts.show`, bound branch (another branch's shift → 404), masking.
- EXECUTABLE_TEST: same test as R1.8.
- REMAINING_DIFFERENCE: Close happens in the POS dialog (needs the selected terminal), not on the detail page; no Z-slip print (Online has none either). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R1.10 — Close Branch / Daily Closing — OWNER-DEPENDENT, not implemented
See OWNER_DECISIONS O-2. The history page carries `#close-branch-note` (each counter closes its own shift).

### R1.11 — does an Edge shift close reach Cloud shift reports? (fact, no change)
- `EdgeSaleEnvelopeBuilder.php:96-101` carries `shift.{shift_uuid,business_date,opened_at,terminal_id,opened_by_user_id}` on every sale envelope.
- `EdgeInboundSaleIngestionService::projectSale` (:267-316) creates the official SalesOrder **without `shift_id`** (no shift lookup/creation anywhere in ingestion).
- There is no shift-open / shift-close event or envelope (no `shift` outbox schema). Returns ingestion calls the shared `SalesReturnService::processReturn`, whose shift bucket update (:335-363) is skipped because the projected sale has no `shift_id`.
- **Fact:** Edge shifts (opening cash, counted cash, variance, closing notes, count lines) never reach the Cloud. Cloud `/shifts`, `/reports/shifts` and Daily Closings do not see Edge shifts or Edge-taken cash per shift; Cloud sales reports DO include Edge sales by `business_date`. Team 6 confirms. STATUS: VERIFIED (gap = O-1).

### R3.1 — returns entry gating
- ONLINE_BEHAVIOUR: `tenant/pos/index.blade.php:511-521` `@can('tenant.sales-returns.create')`; route permission `tenant.sales-returns.store`.
- EDGE_IMPLEMENTATION: `openReturns()` refuses early when `DATA.canSalesReturn === false`; Team 1 hides `#pos-return-btn` (`data-denied`); server refuses search/sale/store/show without `tenant.sales-returns.store`.
- EXECUTABLE_TEST: `EdgeCashierReturnParityHttpMySqlTest::test_return_routes_refuse_without_the_permission_and_the_page_hides_the_entry`; `EdgeCashierReturnHttpMySqlTest::test_a_cashier_without_the_return_permission_is_refused`.
- CENSUS_FLIPS: `pos-return-btn` partial → **present** (`#pos-return-btn`, Team 1's header + gating).
- REMAINING_DIFFERENCE: Online hides with `.create`, Edge flag uses `.store` (the key that gates the action). STATUS: MATCHED (server) / UI by Team 1.

### R3.2 (hardening carried with R3.3) — UserDataScope on the return screen + post
- Online `SalesReturnController@create` :64-95 and `@store` :174-180 scope the sale; Edge had scope on search only (W0b). Now `loadSale()` applies `UserDataScope::applyToSales` for `returnable()` and `processReturn()`.
- EXECUTABLE_TEST: `EdgeCashierReturnParityHttpMySqlTest::test_user_data_scope_fences_the_return_screen_and_post_and_the_returns_list`. STATUS: MATCHED_AND_TESTED.

### R3.3 — return screen parity details
- ONLINE_BEHAVIOUR: `tenant/sales-returns/create.blade.php` — per-line Sold/Returned/Returnable, unit-aware stepper, line refund, delivery row `#delivery-refund-row`, `#suggested-refund`, `#refund_method` (placeholder "Select refund method", default = original tender), `#refund_amount` readonly "Calculated Refund Amount", `#reason` textarea, "Post Return" + Swal "Post this sales return?" (:355-370), PIN modal when required.
- EDGE_IMPLEMENTATION: `returnScreen()` now uses those ids and labels: per-line `.line-refund`, `#delivery-refund-row`, `#suggested-refund`, `#refund_method` (non-offline methods disabled "— needs the Online POS"), `#refund_amount` readonly, `#reason` textarea, button "Post Return" (`#rt-post`) → confirm `#rt-confirm-title` ("Returned stock goes back into the branch. Refund: X") → manager approval where required → "Return posted" with `#rt-posted-view` (detail screen). Quantities/method/reason survive a cancelled confirm or approval.
- PERMISSION_AND_VALIDATION: unchanged server rules (canonical `computeReturn`, refund must equal computed, cash only offline).
- EXECUTABLE_TEST: `EdgeCashierReturnHttpMySqlTest::*` (page needles + posting), `EdgeCashierReturnParityHttpMySqlTest::*`.
- BROWSER_ACCEPTANCE_STEP: POS → Return → search → pick sale → stepper → `#suggested-refund` / `#refund_amount` update → Post Return → confirm → posted → View return.
- CENSUS_FLIPS: `pos-return-frame` stays equivalent (`#rt-q` kept). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (card/bank refunds: R3.4 owner/accepted).

### R3.5 — return with manager approval over HTTP
- ONLINE_BEHAVIOUR: create.blade.php :372-432 + `SalesReturnController@store` :198-241 (binding sale/branch/method/amount, single use).
- EDGE_IMPLEMENTATION: unchanged authority (`EdgeLocalReturnService::processReturn` consume with identical binding); now proven.
- EXECUTABLE_TEST: `EdgeCashierReturnParityHttpMySqlTest::test_return_with_manager_approval_over_http_is_bound_and_single_use` (refused without approval with the Online message; a 50-approval does not authorise a 100-refund; bound approval posts; `approval.approved_by_user_id` rides the return envelope; reuse refused).
- REMAINING_DIFFERENCE: manager identity = own Edge credential + `tenant.pos.void-kot-item` (E-07, unchanged by design). STATUS: MATCHED_AND_TESTED (HTTP).

### R3.6 — returns list + detail screens
- ONLINE_BEHAVIOUR: `SalesReturnController@index` :15-45 (UserDataScope on the order, return-date range + Today/Yesterday), `@show` :259; views `tenant/sales-returns/{index,show}`.
- EDGE_IMPLEMENTATION: `/edge/local/pos/sales-returns` (`#sales-return-table`, `#date_from`, `#date_to`, `#sales-return-today/yesterday`, columns Return No/Sale No/Branch/Return Date/Grand Total/Refund Method/Status/**Sync**/Action, `#sales-return-view-{id}`) and `/sales-returns/{id}` (Return Details, original charges note, Return Lines). Rows = returns posted here (sync state) + Online returns mirrored for the warm window (labelled Online). Entry: `#pos-return-newtab` in the return dialog (shown when `can_view_list`).
- PERMISSION_AND_VALIDATION: `tenant.sales-returns.index` / `.show`; UserDataScope; bound branch.
- EXECUTABLE_TEST: `EdgeCashierReturnParityHttpMySqlTest::test_user_data_scope_fences_the_return_screen_and_post_and_the_returns_list`.
- CENSUS_FLIPS: `pos-return-newtab` planned → **equivalent** (`#pos-return-newtab`; Online opens the returns page in a new tab, Edge links the Edge list).
- REMAINING_DIFFERENCE: dates shown in the branch business timezone (Online: user display timezone); Online-origin returns older than the warm window are Cloud-only. STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R4.1 — manager-approval payload validation
- ONLINE_BEHAVIOUR: `ManagerApprovalController@verify` :14-56 rules for `payload.{sales_order_id, sales_order_line_id, branch_id(exists), client_uuid, discount_type(fixed|percent), discount_value>0, discount_amount>0, quantity>0, refund_method, refund_amount≥0, cancellations[].line_id/quantity>0}`.
- EDGE_IMPLEMENTATION: identical rule set in `EdgeLocalManagerApprovalController` (branch exists on the local `tenant` connection), validated before any credential check / approval row; the stored payload is the request payload (unchanged binding behaviour for every existing Edge caller).
- EXECUTABLE_TEST: `EdgeCashierReturnParityHttpMySqlTest::test_manager_approval_payload_is_validated_like_online_before_any_credential_check`; `EdgeCashierRouteGatesHttpMySqlTest` (permission gate) and the manual-discount approval in `EdgeCashierDealsDiscountsHttpMySqlTest` still use the same endpoint.
- REMAINING_DIFFERENCE: identity model (E-07) by design. STATUS: MATCHED_AND_TESTED.

### R5.1 — quick report gating
- Server `guard()` on every action incl. the new settings routes; `openQuickReport()` returns early on `DATA.canQuickReport === false`; Team 1 hides `#pos-quick-report-btn`.
- EXECUTABLE_TEST: `EdgeCashierQuickReportHttpMySqlTest::test_quick_report_requires_the_synced_online_permission`; settings 403 in `EdgeCashierQuickReportParityHttpMySqlTest::test_saved_selection_…`.
- CENSUS_FLIPS: `pos-quick-report-btn` partial → present when Team 1 renders it. STATUS: MATCHED (server).

### R5.2 — filters + saved selection
- ONLINE_BEHAVIOUR: `tenant/pos/index.blade.php:1531-1674` (+ JS :1680-1830); `PosQuickReportController@saveSettings/settings` :233-267, table `pos_quick_report_settings` (per user).
- EDGE_IMPLEMENTATION: dialog with `#quickReportModalLabel`, `#qr-date` (date input), `#qr-branch` (bound branch, disabled), `#qr-save`, sections `.qr-section` `#qr-sec-*` with `data-panel`, `#qr-panel-categories` (checkbox tree `.qr-category`, container `#qr-cats`), `#qr-panel-items` + `#qr-all-items` + `#qr-item-picker` + `#qr-item-search` + `#qr-item-suggest` + `#qr-item-chips`, `#qr-panel-waiters` (`.qr-waiter`), `#qr-panel-order_types` (`.qr-ordertype`), `#qr-printer`, `#qr-toast`; prefill from saved settings on open; save on toggle and before each action. Settings are stored in the appliance's **local** `pos_quick_report_settings` (same model, same payload shape). **Not synced**: the table is not part of the config bootstrap/refresh, so the Online and Edge selections are independent (Team 6 confirms: local only, no contract change).
- EXECUTABLE_TEST: `EdgeCashierQuickReportParityHttpMySqlTest::test_modal_books_filters_and_business_name_on_the_canonical_engine` (item selection narrows, All-items ignores stale picks, order-type and waiter filters narrow) and `::test_saved_selection_round_trips_per_user_on_the_branch_server_and_is_permission_gated`.
- BROWSER_ACCEPTANCE_STEP: DEVMGR1 → Quick Report → untick All items → search "kar" → chip → tick a waiter/order type → Save my selection → close → reopen: selection restored.
- CENSUS_FLIPS (planned → present, same Online id): `qr-save`, `qr-panel-items`, `qr-all-items`, `qr-item-picker`, `qr-item-search`, `qr-item-suggest`, `qr-item-chips`, `qr-panel-waiters`, `qr-panel-order_types`; `qr-branch` equivalent → present (`#qr-branch`); `qr-panel-categories` equivalent → present (`#qr-panel-categories`, `#qr-cats` kept); `quickReportModalLabel` → present; `qr-toast` (W1-planned) → present (`#qr-toast`, used as the in-dialog notice).
- REMAINING_DIFFERENCE: no "All my branches" (appliance is bound to one branch); saved selection per appliance. STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R5.3 — auto-print + header business name
- ONLINE_BEHAVIOUR: "Print here" opens `/pos/quick-report/print` and calls `w.print()` on load (index.blade.php:1797-1802); header = `app('tenant')->business_name` (PosQuickReportController.php:142-145).
- EDGE_IMPLEMENTATION: `#qr-print` "Print here" opens the canonical thermal view and prints on load; `#qr-view` opens without printing. Header/network meta use `businessName()`: tenant business name when a tenant is bound, else the bound branch name.
- EXECUTABLE_TEST: `EdgeCashierQuickReportParityHttpMySqlTest::test_modal_books_filters_and_business_name_on_the_canonical_engine` (header + network bytes carry the same name; page carries `w.print()`).
- CENSUS_FLIPS: `qr-print` equivalent → **present** (`#qr-print`; `#qr-view` kept).
- REMAINING_DIFFERENCE: on a real appliance no tenant is bound, and the bootstrap's `tenant.business_name` is informational and **not persisted** → header shows the branch name until C-4 lands. STATUS: PARTIALLY_IMPLEMENTED (name source).

### R5.4 — send to network (test)
- EXECUTABLE_TEST: existing `EdgeCashierQuickReportHttpMySqlTest::test_network_queues_…` + new filter/name assertions in `EdgeCashierQuickReportParityHttpMySqlTest` (job type `report`, ESC/POS header name). Network now also sends waiter/order-type/item filters. STATUS: MATCHED_AND_TESTED (HTTP).

### R5.5 — e-mail stays ONLINE_REQUIRED
- Truthful 422 `internet_required` unchanged, asserted again. STATUS: ACCEPTED_ONLINE_REQUIRED.

### R7.2 — customer master pages — OWNER-DEPENDENT, not implemented (O-5).

### R8.4 — supplier payments list/detail
- ONLINE_BEHAVIOUR: `SupplierPaymentController@index` :23 / `@show` :126; views `tenant/supplier-payments/{index,show}` (supplier filter `#pay-supplier`; columns Payment No/Supplier/Bill/Date/Method/Amount/Action; detail fields).
- EDGE_IMPLEMENTATION: `/edge/local/pos/supplier-payments` (`#supplier-payment-table`, `#pay-supplier`, date range, page total, sync status, `#supplier-payment-view-{uuid}`) and `/{event}` (all Online detail fields + Pay From + sync label). Rows = payments recorded on this branch server; the Payment No is the Cloud official reference once acknowledged, else `EDGE-…`.
- PERMISSION_AND_VALIDATION: `tenant.supplier-payments.index` / `.show`.
- EXECUTABLE_TEST: `EdgeSupplierFinanceHttpListsMySqlTest::test_supplier_payments_and_manual_journals_list_and_detail_screens`.
- BROWSER_ACCEPTANCE_STEP: DEVMGR1 → Suppliers → `#supplier-payments-link`.
- REMAINING_DIFFERENCE: Cloud-official payments made Online are visible only as official ledger rows (Supplier Ledger), not in this list. STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### R8.6 — manual journal list/show (reverse = owner)
- ONLINE_BEHAVIOUR: `ManualJournalController@index` :36 (date/q), `@show` :84; reverse :101 (owner item).
- EDGE_IMPLEMENTATION: `/edge/local/pos/finance/manual-journals` (`#manual-journal-table`, `#mj-date-from`, `#mj-date-to`, `#mj-q`) and `/{event}` (header, Journal Lines with totals, provisional effects, `#manual-journal-reverse-note`, **no reverse action**).
- PERMISSION_AND_VALIDATION: `tenant.finance.manual-journals.index` / `.show`.
- EXECUTABLE_TEST: `EdgeSupplierFinanceHttpListsMySqlTest::test_supplier_payments_and_manual_journals_list_and_detail_screens` (asserts no `/reverse`).
- REMAINING_DIFFERENCE: Edge journals are AP-dimension journals only (F2 scope); reversal O-3. STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (list/show) / reverse owner-dependent.

### R9.4 — purchase-return list/detail (drafts = owner)
- ONLINE_BEHAVIOUR: `PurchaseReturnController@index` :24 / `@show` :92; views `tenant/purchase-returns/{index,show}`.
- EDGE_IMPLEMENTATION: `/edge/local/pos/purchase-return-list` (`#purchase-return-table`, `#supplier_id`, date range; Return No/Date/Branch/Supplier/Source GRN/Lines/Total/Status/Posted) and `/{event}` (details + Lines with Source GRN Line/Qty/Unit Cost/Line Total/Reason). No Edit/Cancel Draft.
- PERMISSION_AND_VALIDATION: `tenant.purchase-returns.index` / `.show`.
- EXECUTABLE_TEST: `EdgePurchaseReturnHttpListsMySqlTest::test_purchase_return_list_and_detail_screens`.
- REMAINING_DIFFERENCE: status is the sync state (no draft/cancelled on Edge — O-4); no discount/tax columns (Edge returns carry receipt unit cost only). STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### E-05 leftovers — every endpoint in the Team 4 controllers
| Endpoint | Gate |
|---|---|
| GET /shift, /shift/summary | auth only = Online `/api/pos/shift-status` allow-prefix; terminal via `selectedTerminal()`; amounts via AmountVisibility |
| POST /shift/open, /shift/close | `tenant.shifts.store`, `tenant.shifts.close` + terminal authority |
| GET /shifts, /shifts/{id} | `tenant.shifts.index`, `tenant.shifts.show` |
| returns search/sale/store/show | `tenant.sales-returns.store` + UserDataScope (search, screen, post) + `mayOperateBranch` |
| GET /sales-returns, /{id} | `tenant.sales-returns.index`, `.show` + UserDataScope |
| POST /manager-approvals/verify | `tenant.api.manager-approvals.verify` + Online payload rules |
| quick-report/* (options, view, network, email, settings, save-settings) | `tenant.pos.quick-report-send` + branch scope |
| suppliers screen/options/ledger | `tenant.suppliers.ledger` OR `tenant.supplier-payments.store` (pre-existing F2 rule) |
| POST suppliers/payments | `tenant.supplier-payments.store` |
| finance/journal screen/options/store | `tenant.finance.manual-journals.store` |
| GET supplier-payments(/…), finance/manual-journals(/…) | Online `.index` / `.show` |
| purchase-returns screen/options/grn/show | `.store` OR `.post`; POST needs both (pre-existing F3 rule, stricter than Online) |
| GET purchase-return-list(/…) | `tenant.purchase-returns.index` / `.show` |
No endpoint in the Team 4 controllers is reachable with only `edge.auth`+`edge.branch` except the shift status reads, which match Online.

### Build manifest capabilities
`config/edge.php` `capabilities` now also declares `sales_returns_cash`, `supplier_finance_events`, `purchase_returns_grn`,
`quick_report`, `shift_denomination_count`. Deliberately NOT the Cloud classifier's broader words (`returns`, `purchasing`),
whose `EdgeCompatibilityContractTest` asserts them unavailable offline — see coordinator request Q-3.

## OWNER_DECISIONS (documented, not built)

**O-1 Shift close → Cloud + CASH-SHORTAGE draft voucher (R1.3, R1.11).** Online: `ShiftController@close` :308-323 calls
`CashShortageExpenseService::recordShortage(branch, business_date, short, 'shift', shift_id, user, note)` when counted <
expected — a DRAFT expense voucher under "Cash Shortage" (Finance → Expenses) that hits the books only when finance posts it;
Close Branch raises one per short drawer or per branch total (:535-577). Edge today: the shortage is recorded on the local
shift only; no shift reaches the Cloud (R1.11). Required: a new immutable **`edge_shift_closed` event** (Team 6 contract) carrying
shift_uuid, terminal, business_date, opened/closed by + at, opening/expected/counted cash, variance, tender totals, refunds,
closing notes, count lines; Cloud ingestion creates/links the Cloud Shift row, links projected sales (`shift_id` by shift_uuid)
and — only on owner approval — calls the same `CashShortageExpenseService` exactly once (idempotent on shift_uuid).
Financial implication: a draft voucher is not a GL posting, but it creates a finance work item and an expense record; linking
sales to shifts changes Cloud shift reports/Daily Closing figures. Proposed handling: owner approves the event; Team 6 builds
ingestion with exactly-once semantics; until then the Edge detail page states the shortage and that finance settles it from
the shift record.

**O-2 Close Branch / Daily Closing (R1.10).** Online `closeBranchForm/closeBranch` (ShiftController.php:333-580): pick a branch,
lock all open shifts the operator may operate, mode `per_terminal` (every drawer counted; blank refused unless zero drawer)
or `branch_total` (each terminal closes at expected; one branch count recorded on `DailyClosing::updateOrCreate` by business
date), shared closing notes, shortage vouchers. Edge would need: a branch-close screen over the local open shifts (UI-only
part is feasible), but a Daily Closing is a Cloud record → it needs the O-1 event family plus a `daily_closing` payload and a
Cloud ingestion rule for conflicts (a Daily Closing already made on the Cloud for that date). Financial implication: branch
variance + shortage voucher per branch; Daily Closing figures feed Cloud reports. Also note `EdgeHandbackOrchestrator` blocks
handback while any Edge shift is open. Proposed: owner decides whether branch-wide close is required offline; if yes,
build after O-1.

**O-3 Manual-journal reversal offline (R8.6).** Online `ManualJournalController@reverse` :101-122 posts an opposite journal
linked to the original. Edge would need a new F2 event type (`supplier_ap_journal_reversal`) referencing the original event
(pending) or the official entry (synced), with ingestion that refuses double reversal. Financial: GL + supplier sub-ledger
movement. Proposed: keep Online-only until owner approves.

**O-4 Purchase-return drafts (R9.2).** Online create → draft → edit/update/cancel → post (`PurchaseReturnController`,
show.blade.php "Edit Draft"/"Cancel Draft"/"Post Return"). Edge posts at once (F3). Drafts would need local draft state
(no stock/payable effect until post) and a sync rule for drafts vs Cloud drafts. Financial: none until post. Proposed:
owner decides; UI-only local drafts are possible without a contract change if drafts never sync.

**O-5 Customer master pages (R7.2).** Online `/customers` CRUD + ledger (CustomerController). Edge has the synced customer
book read-only. Creating/editing customers offline needs a customer upsert event + Cloud merge rules (phone reuse). Owner:
admin (ONLINE_REQUIRED) vs operator (gap).

## CONTRACT_REQUIREMENTS for Team 6

- **C-1** `edge_shift_closed` (and optionally `edge_shift_opened`) event + Cloud ingestion — fields in O-1; link projected sales
  by `shift.shift_uuid` already on every sale envelope; shortage voucher only on owner approval.
- **C-2** (with O-2) `daily_closing` payload if Close Branch is approved.
- **C-3** Config bootstrap/refresh: add `currencies` (default only is enough: id, code, name, symbol, decimal_places,
  is_default, is_active) and `currency_denominations` (id, currency_id, denomination_value, denomination_type, is_active)
  sections so the close grid has the tenant's denominations (tables already exist in the local schema; config only, no
  financial event). Until then the dialog falls back to the typed total (`#sh-no-denominations`).
- **C-4** Persist the bootstrap's informational `tenant.business_name` on the appliance (e.g. `edge_local_meta.tenant_business_name`
  via the importer/refresh applier) so the Quick Report header matches Online; `EdgeQuickReportController::businessName()`
  is the single place to read it.
- No change requested to F1/F2/F3 envelopes. CashCountLine rows and `pos_quick_report_settings` stay local.

## Requests to other teams / coordinator

- **Team 1:** wire `#pos-shift-open-link`/`#shift-btn` → `openShiftDialog`, `#pos-return-btn` → `openReturns`,
  `#pos-quick-report-btn` → `openQuickReport` (defined); optionally define `refreshShiftBadge()` (the shift dialog calls it
  after open/close when present); header/sidebar entry points for Shifts (`tenant.shifts.index`), Sales Returns
  (`tenant.sales-returns.index`), Supplier Payments, Manual Journals, Purchase Returns list — view-model flags needed (Team 2
  owns `EdgeLocalPosController@screen`).
- **Team 2:** add vm flags `canShiftHistory` (`tenant.shifts.index`), `canSalesReturnList` (`tenant.sales-returns.index`),
  `canSupplierPaymentList`, `canManualJournalList`, `canPurchaseReturnList` for Team 1's links.
- **Coordinator:** (Q-1) apply the census flips listed per record; (Q-2) keep the W4 block in `config/edge.php`
  `route_allowlist`; (Q-3) `EdgeCompatibilityService::CLOUD_OFFLINE_FEATURES` still lists `returns`/`purchasing` as offline-
  unavailable — map them to the new capability names (and update `EdgeCompatibilityContractTest`), Cloud-side file not owned
  by Team 4; (Q-4) extract Online `ShiftController::calculateCashCount` into a shared service used by both controllers (Edge
  currently mirrors its semantics in `EdgeLocalShiftController::recordDenominationCount` — same rule, not shared code).

## Test commands and results (25 Sep 2026, team-4 databases)

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_t4 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t4 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t4
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierShift|EdgeCashierReturn|EdgeCashierQuickReport|EdgeSupplierFinanceHttp|EdgePurchaseReturnHttp'
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierQuickReportParity|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierPermissionHttpMySqlTest|EdgeLocalPosHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest|EdgeCashierDealsDiscounts'
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest
vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php tests/Feature/Edge/EdgeCompatibilityContractTest.php tests/Feature/Edge/EdgeBuildInfoTest.php
```

| Run | Result |
|---|---|
| W4 suites (shift ×2, return ×2, quick report ×2, supplier finance HTTP ×2, purchase return HTTP ×2) | 27 tests / 626 assertions: 26 green, 1 failure = a needle in my new QR test (`'qr-branch'` quoted-string form; the page carries `id="qr-branch"`) — fixed; the fixed class re-ran green in the next row |
| Regression: QR parity + RouteGates + Permission + LocalPosHttp + ScreenRenders + DealsDiscounts (manager-approval path after R4.1) | **OK — 28 tests / 367 assertions** |
| Feature: route census + artifact + compatibility contract + build info | **OK — 25 tests / 31 316 assertions** (the new capability names do not disturb the Cloud classifier test) |
| Control census (node --check included) | 4 tests: composed script parses (node), inventory pinned OK, deferrals OK; `test_every_census_row_matches_the_rendered_edge_page` FAILS with 26 mismatches — **25 are other teams' renames** (Team 2 commercial panel `#cm-*`, `#category-tabs`; customer modal) and **1 is W4-related: `pos-return-btn`** whose fixture selector `#returns-btn` was replaced by Team 1's `#pos-return-btn` → flip to present `#pos-return-btn` (listed under R3.1). No planned W4 row fails (the flipped planned rows carry no selector in the fixture). |

Earlier (pre-fix) run of the original suites showed one failure in `EdgeCashierReturnHttpMySqlTest` (needle `id="returns-btn"`,
removed by Team 1) — the test now asserts `id="pos-return-btn"` and is green.

## Census flips to apply (coordinator)

| Online id | from → to | Edge selector |
|---|---|---|
| pos-return-btn | partial → present | `#pos-return-btn` (Team 1 header, gated) |
| pos-return-newtab | planned → equivalent | `#pos-return-newtab` (link to the Edge Sales Returns list) |
| quickReportModalLabel | → present | `#quickReportModalLabel` |
| qr-save | planned → present | `#qr-save` |
| qr-panel-items, qr-all-items, qr-item-picker, qr-item-search, qr-item-suggest, qr-item-chips | planned → present | same ids |
| qr-panel-waiters, qr-panel-order_types | planned → present | same ids |
| qr-panel-categories | equivalent → present | `#qr-panel-categories` (`#qr-cats` kept) |
| qr-branch | equivalent → present | `#qr-branch` |
| qr-print | equivalent → present | `#qr-print` (`#qr-view` kept) |
| qr-toast | planned (W1) → present | `#qr-toast` |

## Browser proof
Not run in this session (the dev instance serves the working tree; read-only proof is the coordinator's step after
integration). Every BROWSER_ACCEPTANCE_STEP above names the ids to click/inspect.
