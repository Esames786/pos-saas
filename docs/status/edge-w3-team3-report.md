# W3 — Team 3 report: tables & order lifecycle (Offline Edge cashier parity)

Branch `feat/edge-config-refresh-v1` (base 63b88d8 → d82612f), worktree `D:\laragon2\www\pos-saas-edge`. No git operation, no LAB /
production touch, no envelope / outbox / authority / contract edit. Online reference = `resources/views/tenant/pos/index.blade.php`
(below **O:line**), `RestaurantTableSessionController` (**RTSC:line**, worktree = pre-243e01d; 243e01d diff read with `git diff`),
`HeldSaleController` (**HSC:line**), `POSController` (**POSC:line**), `RestaurantTableController` (**RTC:line**),
`KotCancellationService` (**KCS:line**), `tenant/pos/partials/table-board.blade.php` (**TB:line**).

## Files changed (Team 3 ownership)

| File | Change |
|---|---|
| `app/Services/Edge/EdgeLocalTableOperationsService.php` (NEW) | request bill, move, merge, reattach-table, dead-session facts, session detail, per-table bill preview (data + receipt document), Change-Order table picker, session payload |
| `app/Services/Edge/EdgeLocalOrderLifecycleService.php` (NEW) | Recent Orders (Online recentSales scoping + Edge print state), Held Orders query (type filter + UserDataScope) |
| `app/Http/Controllers/Edge/EdgeLocalRestaurantController.php` | board tile data (open check, order count, items/updated per check, `reservation_details_missing`), 6 new actions, reservation gate + reserved_by/at |
| `app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php` | held list type filter + UserDataScope, held detail `table_session` + `dead_session`, void-reasons approval modes, `recentSales`, `reattachTable` |
| `app/Services/Edge/EdgeTableReservationService.php` | book customer: unknown id refused (Online `exists:`), typed name/phone wins (Online snapshot rule) |
| `resources/views/edge/pos/js/{tables,held,actions}.blade.php` | Table Workspace, session bar, recalled bar, Held/Recent Orders, Change Order, dead session, voids, cancel-with-approval, clear/new order/new sale |
| `routes/edge_runtime.php` W3 block, `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` W3 block | 8 routes |
| `config/edge.php` `route_allowlist` — marked W3 block (8 names) | **not in the charter's ownership table**: without it every new route is a 404 on a branch_server (`EnsureEdgeRuntimeRouteAllowed`). Additive, clearly marked — coordinator please ratify |
| `tests/MySql/EdgeCashierTablesWorkspaceHttpMySqlTest.php` (NEW, 7 tests), `tests/MySql/EdgeCashierOrderLifecycleHttpMySqlTest.php` (NEW, 4 tests) | executable proofs over the real `/edge/local/pos/*` routes |

New routes (all under `edge.auth`+`edge.branch`; permission = the Online route permission):

| Route (name) | Online route / permission | Gate on Edge |
|---|---|---|
| `GET restaurant/table-sessions` (`restaurant.sessions.index`) | `/api/pos/table-sessions` (`tenant.api.pos.*`, permission-free) | none (mirrors Online) + dine-in allowance |
| `GET restaurant/table-sessions/{s}` (`restaurant.session.show`) | `tenant.restaurant.table-sessions.show` | denyUnlessCan |
| `GET restaurant/table-sessions/{s}/bill-preview` | `tenant.restaurant.table-sessions.bill-preview` | denyUnlessCan |
| `POST restaurant/table-sessions/{s}/bill-requested` | `tenant.restaurant.table-sessions.bill-requested` | denyUnlessCan |
| `POST restaurant/table-sessions/{s}/move` | `tenant.restaurant.table-sessions.move` | denyUnlessCan |
| `POST restaurant/table-sessions/{s}/merge` | `tenant.restaurant.table-sessions.merge` | denyUnlessCan |
| `POST held-sales/{sale}/reattach-table` | `tenant.held-sales.reattach-table` | denyUnlessCan + `selectedTerminal()` |
| `GET recent-sales` (`recent-sales`) | `/api/pos/recent-sales` (permission-free) | none (mirrors Online) + UserDataScope |

Lock orders (mirroring Online): move = session → both tables (id order) → locking read of target live sessions (+ Edge reservation row);
merge = both sessions (id order) → both tables (id order) → live sessions per table → source held/draft sales; reattach = shift
(`lockOpenShiftForTerminal`) → table → live sessions → sale; request bill = session → table. Every mutation also runs the P0
authority gate (`EdgeAuthorityService::assertLocalMutationAllowed`) and the principal/branch/dine-in checks.

## Records

### R1 — Table workspace layout
- ONLINE_BEHAVIOUR: O:1019-1114 `#tableWorkspaceModal` (Back, Manage Floors/Tables, sections board/open/held/move/split/manage), TB:1-139.
- EDGE_IMPLEMENTATION: `js/tables.blade.php` `openTableWorkspace(view,arg)` (alias `viewTables()`), `showTwView()`; sections `#table-workspace-{board,open,held,move,split,merge,detail,reserve,reservation,manage}`, `#table-board-body`, `#table-workspace-back`, floor tabs `#floor-tab-strip`, wide modal via scoped CSS (`.modal .box:has(.w3-wide)`), in-place refresh.
- PERMISSION_AND_VALIDATION: board read = Online `/api/pos/table-board` (permission-free); View Tables hidden without dine-in (Team 1 boot).
- EXECUTABLE_TEST: `EdgeCashierOrderLifecycleHttpMySqlTest::test_the_cashier_page_renders_the_w3_controls`; `EdgeCashierTablesWorkspaceHttpMySqlTest::test_open_table_notes_board_tile_data_and_request_bill`.
- BROWSER_ACCEPTANCE_STEP: View Tables → workspace with tiles → click a tile button → sub-view → `#table-workspace-back`. Manage → `#table-workspace-manage` Online hint.
- CENSUS_FLIPS: see §Census.
- REMAINING_DIFFERENCE: Manage Floors/Tables is an Online-required hint (R32–R35 accepted); the look is Team 1's dark theme.
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN → browser read-only walk done on dev (see §Browser), equivalent to Online.

### R2 — Table tile states
- ONLINE_BEHAVIOUR: TB:29-40 effectiveStatus; tile: table_no, seats, status chip, session no, waiter, total, direct buttons (Continue / Close [no order at all] / Split / Held Orders / Move; reserved: Details / Open / Cancel; free: Open / Reserve).
- EDGE_IMPLEMENTATION: `tileHtml()`; per-state colours (available / occupied / bill_requested / reserved / cleaning); board JSON adds `open_check`, `order_count`, per-check `items_count`/`updated_at`/`is_draft`, `waiter_id`, `notes`.
- EXECUTABLE_TEST: `test_open_table_notes_board_tile_data_and_request_bill` (capacity, waiter, open_check, order_count, items_count, bill_requested state).
- REMAINING_DIFFERENCE: Edge adds a "Details" (session detail) button on occupied tiles (Online hides tile Bill Preview by owner request, TB:57-65 — mirrored: no Bill Preview on the tile).
- STATUS: MATCHED (HTTP-proven data; browser-observed tiles).

### R3 — Open table notes
- ONLINE_BEHAVIOUR: O:1055-1097 (`#guest_count` default 1 required, `#table_notes`), RTSC:52-181.
- EDGE_IMPLEMENTATION: `renderOpenView()` `#open-table-form`, `#guest_count` (1–100, default 1), `#table_notes` (maxlength 255), `#open-table-submit`, `#open-table-no`; `openTable()` posts `notes`.
- PERMISSION_AND_VALIDATION: `tenant.restaurant.table-sessions.open` (existing gate); server notes ≤500 (Online 255 — the field caps at 255).
- EXECUTABLE_TEST: `test_open_table_notes_board_tile_data_and_request_bill` (notes persisted), session detail test (`session.notes`).
- REMAINING_DIFFERENCE: server `guest_count` still optional (defaults 1) — Team 2 request #2.
- STATUS: MATCHED.

### R4 — Waiter roster
- ONLINE_BEHAVIOUR: O:1063-1083 roster buttons with initials + hidden `#restaurant_waiter_id` ("No Waiter").
- EDGE_IMPLEMENTATION: `#waiter-roster` `.waiter-choice[data-waiter-choice]` toggling hidden `#restaurant_waiter_id`; empty-roster message.
- EXECUTABLE_TEST: render test; waiter persisted/inherited: move/merge tests (`waiter_name`, merged check follows target waiter).
- STATUS: MATCHED (browser: roster click sets the select).

### R7 — Held orders per table
- ONLINE_BEHAVIOUR: TB:89-90, O:6016-6029 (`#table-workspace-held`: items count + updated time, Recall).
- EDGE_IMPLEMENTATION: `renderHeldView()` into `#table-workspace-held-body`; Continue Table with several checks lands here (Online showOpenOrdersChoice).
- EXECUTABLE_TEST: merge test (two checks on one table on the board); tile data test (`items_count`).
- STATUS: MATCHED.

### R9 — Cancel session from the board
- ONLINE_BEHAVIOUR: RTSC:211-273 close with `status=cancelled` (standalone board).
- EDGE_IMPLEMENTATION: session detail "Cancel session" (only when the session has no order) → `closeEmptyTable(t,'cancelled')`; confirm dialog; response message "Session cancelled." / "Session closed as paid." (Online messages).
- EXECUTABLE_TEST: `test_cancel_empty_session_and_reservation_with_book_customer_and_details`.
- STATUS: MATCHED.

### R10 — Session bar
- ONLINE_BEHAVIOUR: O:446-470 (table, session no, waiter, guests, open check, Bill Preview, Request Bill form hidden unless `open`).
- EDGE_IMPLEMENTATION: `renderSessionBar()` (called from `renderActions()` on every cart render) fills Team 1's `#pos-session-bar` layout (creates it under the cart head only if absent); wires `#pos-session-bill-preview`, `#pos-session-request-bill-form`, `#pos-session-move-btn`, `#pos-session-merge-btn`; `#pos-session-status` chip when bill requested. Data = held detail `table_session` (Online sessionPayload shape), open-table response `session`, board session.
- EXECUTABLE_TEST: `test_open_table_notes_board_tile_data_and_request_bill` (`held_sale.table_session.*`).
- BROWSER_ACCEPTANCE_STEP: Continue Table on an occupied tile → bar shows Table/No/Waiter/Guests/Open check (observed on dev: `display:flex`, table G1).
- REMAINING_DIFFERENCE: `#check-chip` is Team 2's and still shows alongside.
- STATUS: MATCHED.

### R11 — Request Bill
- ONLINE_BEHAVIOUR: RTSC:183-209 (open|bill_requested → bill_requested on session + table), O:461-469.
- EDGE_IMPLEMENTATION: `EdgeLocalTableOperationsService::requestBill`; `requestBill(sessionId?)` from the session bar form, session detail and table bill preview; board shows `bill_requested`; move/merge preserve it.
- PERMISSION_AND_VALIDATION: `tenant.restaurant.table-sessions.bill-requested`; closed session → 422 "Session is not open.".
- EXECUTABLE_TEST: `test_open_table_notes_board_tile_data_and_request_bill` (403 without permission, state, Add Round still allowed, settle frees table, 422 after close).
- STATUS: MATCHED (HTTP); browser POST not executed (read-only rule).

### R12 — Move table
- ONLINE_BEHAVIOUR: RTSC:327-439, O:6043-6051.
- EDGE_IMPLEMENTATION: `moveSession()`; `renderMoveView()` lists available tables; `moveTable(t,target)` (no-arg call from bar opens the view).
- PERMISSION_AND_VALIDATION: `.move`; refuses same table / occupied / not available|cleaning / **Edge-reserved** target (stricter: Edge reservations are not on `restaurant_tables.status`).
- EXECUTABLE_TEST: `test_move_table_preserves_order_identity_and_sent_quantities_and_refuses_unavailable_targets` (sale_uuid, session id, kot_sent_quantity, no new kot_batches, source available, target bill_requested, next KOT = delta only).
- REMAINING_DIFFERENCE: Online re-points ALL sales of the session (incl. paid); Edge re-points only held/draft — a paid Edge sale is already frozen in its immutable outbox envelope, changing it locally would diverge from the Cloud (merge already uses "paid history stays").
- STATUS: MATCHED (HTTP).

### R13 — Merge sessions
- ONLINE_BEHAVIOUR: RTSC:441-569 (Online only on the standalone bill-preview page).
- EDGE_IMPLEMENTATION: `mergeSessions()`; `renderMergeView()` from session detail / session bar / Change Order.
- PERMISSION_AND_VALIDATION: `.merge`; all Online refusals (same session, non-mergeable state, source changed, no active held order).
- EXECUTABLE_TEST: `test_merge_moves_open_checks_keeps_paid_history_and_frees_the_source` (waiter follows target, paid child stays, source cancelled + note, bill_requested survives, two checks settle separately, table frees on the last).
- REMAINING_DIFFERENCE: after merge a session may carry two checks (same as Online); Edge still forbids creating a second NEW check on one session (one-check rule).
- STATUS: MATCHED (HTTP).

### R14 — Per-table Bill Preview document
- ONLINE_BEHAVIOUR: RTSC:290-325 + canonical 243e01d `renderTableBillReceipt` (receipt layout over held rounds, previously paid, `held_sale_ids` print target), O:1117-1138.
- EDGE_IMPLEMENTATION: `billPreview()` returns `session`, `rounds[]` (lines incl. deal components), `previously_paid[]`, `totals`, `previously_paid_total`, `held_sale_ids`, `html` = `tenant.printing.documents.receipt` over a transient unsaved sale numbered by the session no, 243e01d layout lookup (branch or global), `tableBill` passed (block dormant until the reconcile). `openTableBillPreview()` shows rounds / OPEN CHECK / Previously paid + the receipt iframe; Print here prints the iframe; Send to network targets the held ids (one → Team 5 `printBillPreview({sale_id})`; several → `/sales/{id}/receipt`).
- PERMISSION_AND_VALIDATION: `.bill-preview`; zero mutation (asserted: no print job).
- EXECUTABLE_TEST: `test_session_detail_bill_preview_document_and_table_sessions_picker`.
- REMAINING_DIFFERENCE: receipt.blade.php in this tree lacks the 243e01d `@isset($tableBill)` block — rounds/previously-paid appear in the modal, not yet inside the printed receipt → Team 6 reconcile.
- STATUS: PARTIALLY_IMPLEMENTED (document data + render MATCHED; printed rounds block waits on the reconcile).

### R15 — Reserve with a book customer
- ONLINE_BEHAVIOUR: O:1921-1953, RTC:24-59.
- EDGE_IMPLEMENTATION: `renderReserveView()` with Online ids `#reserveTableModal`, `#reserve-table-no/-id`, `#reserve-customer-search/-suggest/-chip/-name/-clear/-id`, `#reserve-name/-phone/-for/-note`, `#reserve-save-btn`; search = `GET /customers?q=`.
- PERMISSION_AND_VALIDATION: reserve / details / unreserve now gated on `tenant.restaurant.table-sessions.open` (Online RESERVE_PERMISSION); unknown customer id 422.
- EXECUTABLE_TEST: `test_cancel_empty_session_and_reservation_with_book_customer_and_details`.
- REMAINING_DIFFERENCE: Edge refuses a second active reservation (Online overwrites) — pre-existing, stricter.
- STATUS: MATCHED.

### R16 — Reservation details
- ONLINE_BEHAVIOUR: O:1954-1964, RTC:79-92 (reserved_by, reserved_at).
- EDGE_IMPLEMENTATION: `renderReservationView()` `#reservationDetailsModal` / `#reservation-details-body`; API adds `reserved_by`, `reserved_at`.
- EXECUTABLE_TEST: same test (`reservation.reserved_by`, `reserved_at`).
- STATUS: MATCHED.

### R18 — Open reserved → customer on the check
- EDGE_IMPLEMENTATION: server seat (pre-existing) + page now carries `customer_id` + name + phone into `state.customer`.
- EXECUTABLE_TEST: same test (`held_sale.customer_id`, phone); existing `EdgeCashierReservationHttpMySqlTest`.
- STATUS: MATCHED.

### R19 — Online-made reservation after handover (FACT)
- FACT: `EdgeBootstrapService.php:728` exports `restaurant_tables` columns `id, branch_id, restaurant_floor_id, table_no, name, capacity, status, sort_order` only — NOT `reserved_*`; `EdgeLocalConfigRefreshApplier` keeps local occupancy status. A table reserved Online arrives as `status=reserved` with no guest details and no customer to carry.
- EDGE_IMPLEMENTATION: board flag `reservation_details_missing`; tile + open form say "Reserved on the Online POS — guest details did not reach this Branch Server"; move refuses it.
- EXECUTABLE_TEST: `test_online_made_reservation_after_handover_reaches_the_board_as_status_only`.
- REMAINING_DIFFERENCE / REQUEST: Team 6 contract — bootstrap + config refresh must carry `reserved_customer_id→customer_uuid, reserved_name, reserved_phone, reserved_for, reservation_note, reserved_by_user_id, reserved_at` and import them into `edge_local_table_reservations` (status active). Not done here (bootstrap contract).
- STATUS: NOT_VERIFIED → now VERIFIED as a gap (details lost), UI honest; BLOCKED on Team 6.

### R20 — Session detail
- ONLINE_BEHAVIOUR: RTSC:275-288 + `restaurant/sessions/show`.
- EDGE_IMPLEMENTATION: `sessionDetail()`; `renderDetailView()` (session card + all orders incl. paid; actions Continue / Bill Preview / Request Bill / Move / Merge / Close / Cancel session). Without `.show` the view falls back to board data.
- EXECUTABLE_TEST: session detail test (403, opened_by, held + paid).
- STATUS: MATCHED.

### R21 / A28 — Change Order Details
- ONLINE_BEHAVIOUR: O:1388-1452, JS O:5252-5395, HSC:129-157.
- EDGE_IMPLEMENTATION: `openChangeOrder()` `#changeOrderModal`, `#co-type-btns`, `#co-order-type`, `#co-table-wrap`, `#co-table-session` (from `GET restaurant/table-sessions`), `#co-terminal` (→ `selectTerminal`, disabled when pinned), `#co-branch` (bound → disabled), `#co-apply-btn`; `#edit-order-btn` rendered in the recalled bar. Plain cart: order type change; dine-in → free table is opened and the cart is KEPT, an open table is attached, a table with a check → items become its next round (Online TABLE_HAS_OPEN_ORDERS / continue). Held check: table change = Move (free table) or Merge (occupied); order type fixed.
- EXECUTABLE_TEST: picker shape in `test_session_detail_bill_preview_document_and_table_sessions_picker`; move/merge tests for the held path.
- REMAINING_DIFFERENCE: changing the ORDER TYPE of a held check and re-targeting ONE check (not the whole session) need `reviseHeldSale` changes → Team 2 requests #3/#4. Online's picker filters `restaurant_tables.status='active'` (a value tables never have); Edge lists every non-inactive table.
- STATUS: PARTIALLY_IMPLEMENTED.

### create_separate_order (census, R21)
Online hard-codes `$createSeparateOrder = false` (HSC:~486): a table has one check; a second hold returns TABLE_HAS_OPEN_ORDERS. Edge: `holdSale()` catches the one-check refusal and offers `continueOpenCheckWithCart()` (items join the open check as the next round). No separate-check flag exists or is needed — census row can move to `equivalent` (`js:continueOpenCheckWithCart`).

### R22–R24 — Hold / Add Round / KOT (verify)
- EXECUTABLE_TEST: existing `EdgeCashierDineInHttpMySqlTest` (green), plus move test (Add Round + KOT delta after a move), merge test.
- CHANGE: deals now load LOCKED at the deals the kitchen already has (derived from component sent quantities) — R24 "deal rows not client-locked" fixed in `loadHeld`.
- REMAINING_DIFFERENCE: auto-KOT / "Print Kitchen Order?" prompt after Hold is not done (Team 5 print-intent + Team 2 hold flow); KOT stays a separate button.
- STATUS: FUNCTIONAL_BUT_UI_DIFFERENT (KOT prompt).

### R25 — Sent-line void with reason + manager approval
- ONLINE_BEHAVIOUR: O:3990-4033 showVoidReasonModal ("Manager code required"), KCS:127-225.
- EDGE_IMPLEMENTATION: `voidSentLine(line,newQty)` (called by Team 2 `changeQty` — wired) → reason list with the branch LINE mode (`/void-reasons` now returns `line_approval_mode`/`order_approval_mode`); stored in `state.voidItems`; `saveRound()` builds `void_items` = sent − new qty per line; manager_required → ONE approval: single line `void_kot_item {sales_order_id, sales_order_line_id, quantity}`, several lines `void_kot_items {sales_order_id, cancellations[]}`; retries once if the server asks. Deal lines (Team 6 note): header + component rows become void entries and always request `void_kot_items`.
- EXECUTABLE_TEST: `EdgeCashierOrderLifecycleHttpMySqlTest::test_sent_line_voids_single_and_grouped_approval_and_reason_book_modes`; existing `EdgeLocalRestaurantHttpMySqlTest::test_reducing_kitchen_sent_line_requires_void_and_real_manager_approval`.
- STATUS: MATCHED (non-deal); deal path see R26.

### R26 — Combo grouped void
Page side done (grouped `void_kot_items` for deals). A deal void that resolves to ONE sent row is refused by the pre-fix `KotCancellationService` until canonical MANAGER-APPROVAL-COMBO-VOID-1 is reconciled (Team 6). STATUS: CANONICAL_DRIFT_NOT_RECONCILED (no service change by Team 3).

### R27 — Cancel with approval in manager_required mode
- ONLINE_BEHAVIOUR: O:4873-4984 (reason → PIN when mode ≠ auto), HSC:1043-1079.
- EDGE_IMPLEMENTATION: `cancelHeldCheck()` — reason select; on 422 "Manager approval…" → `askManagerApprovalFor('cancel_held_order', …, {sales_order_id})` → retry with `manager_approval_id`; used by `#cancel-order-btn` and by the Held Orders row Cancel. Unsaved cart → "Clear the current unsaved cart?" (Online).
- EXECUTABLE_TEST: `test_held_orders_type_filter_and_cancel_from_the_list_with_manager_approval` (422 message, approval, table freed, no-approval when nothing sent); existing DineIn cancel test.
- STATUS: MATCHED (HTTP); approval prompt not browser-executed (mutation).

### R29 / A42 — Split parity
- EDGE_IMPLEMENTATION: tile "Split Bill" (one check → split dialog; several → `#table-workspace-split-body` picker, Online O:6053-6062); split dialog gains the Notes field (server already accepted `notes`).
- EXECUTABLE_TEST: merge + bill-preview tests split through the real route; existing `EdgeCashierDealsDiscountsHttpMySqlTest`.
- STATUS: MATCHED.

### R31 / A29 — Dead-session recovery
- ONLINE_BEHAVIOUR: POSC:77-106 detection, O:1216-1352 modal, HSC:919-1041.
- EDGE_IMPLEMENTATION: held detail `dead_session` (sale, table, session, closed_by/at, `can_reopen` = table free, not Edge-reserved, not inactive); `loadHeld()` opens `#deadSessionModal` (`#dead-reopen`, `#dead-table-pick` free tables, `#dead-move`); `reattachTable()` creates a NEW session (Edge ULID identity), never resurrects the dead one, no re-KOT.
- PERMISSION_AND_VALIDATION: `tenant.held-sales.reattach-table` (Owner-only on Online by deploy) + terminal via `selectedTerminal()`; refuses non-held, non-dine-in, live-session bills (use Move), occupied / Edge-reserved tables.
- EXECUTABLE_TEST: `test_dead_session_recovery_reattaches_a_held_bill_to_a_new_session`.
- REMAINING_DIFFERENCE: on Edge the state is prevented by construction; the recovery exists for completeness (restore/import edge cases).
- STATUS: MATCHED (HTTP).

### A26 — Held Orders modal
- ONLINE_BEHAVIOUR: O:1153-1180, O:5004-5062, O:5197-5210, HSC:46-127.
- EDGE_IMPLEMENTATION: `openHeldOrders()` (alias `recallList()`): `#heldSalesModal`, `#heldSalesModalLabel`, `#held-type-filters` (only when >1 allowed type), `#held-sales-modal-body` table (Sale No, Type + DRAFT, Customer + meta, Items, Total, Time, Recall + Cancel). Server: `?order_type=` narrows, UserDataScope applied, newest updated first.
- EXECUTABLE_TEST: `test_held_orders_type_filter_and_cancel_from_the_list_with_manager_approval`.
- STATUS: MATCHED.

### A27 — Recent / Completed Orders
- ONLINE_BEHAVIOUR: O:1183-1214, O:5665-5767, POSC:877-939.
- EDGE_IMPLEMENTATION: `EdgeLocalOrderLifecycleService::recentSales` (non-held, sale_no not null, allowed types, filter narrows, UserDataScope, 50, newest id first; `printing` from print_jobs); `openCompletedOrders()` `#completedOrdersModal`, `#recent-type-filters`, `#completed-orders-modal-body`; Receipt / KOT reprint and Resume → Team 5 `openLastPrint(saleId)` when defined (it is), else the existing reprint routes. `#completed-orders-btn` is in Team 1's header (wired by boot); `renderActions` renders it only if absent.
- EXECUTABLE_TEST: `test_recent_orders_list_non_held_sales_with_filters_and_print_state`.
- REMAINING_DIFFERENCE: Online "View order / Rider" links open Cloud pages — not offered.
- STATUS: MATCHED.

### A37 — Cancel Order / Clear cart / New Order / New Sale
- ONLINE_BEHAVIOUR: O:737-745, O:4114-4176, O:5218-5250, O:6277-6287.
- EDGE_IMPLEMENTATION: `clearCart(opts)` (resets cart, check, voids, commercial, customer unless `preserveTable`); `startFresh()` (`#start-fresh-btn` / `#start-fresh-label` "New Order" / "Add Round"); `newSale()` (`#new-sale-btn`, confirm, table check stays saved); `#clear-cart-btn` (confirm); Cancel order on a plain cart = Clear Cart?. Buttons render in `#actions` unless Team 1/2 already place the ids.
- EXECUTABLE_TEST: render test; behaviour is client-side (no endpoint).
- STATUS: FUNCTIONAL_BUT_NOT_BROWSER_PROVEN (ids observed in the browser; clicks not exercised).

### A38 — Recalled-order bar
- EDGE_IMPLEMENTATION: `renderRecalledBar()` → `#recalled-order-bar`, `#recalled-order-no`, `#pos-draft-badge`, `#edit-order-btn` (→ `openChangeOrder`), shown whenever a check is loaded.
- STATUS: MATCHED.

### R32–R35 — ONLINE_REQUIRED
Unchanged (accepted). The workspace shows the Online hint in `#table-workspace-manage`.

## Census flips (coordinator edits the fixture; I did not)

planned → present (same Online id now on the page): `pos-session-no`, `pos-session-guests`, `pos-session-actions`, `pos-session-request-bill-form`,
`new-sale-btn`, `clear-cart-btn`, `start-fresh-btn`, `start-fresh-label`, `table-workspace-back`, `waiter-roster`, `table_notes`,
`table-workspace-move`, `table-workspace-move-body`, `held-type-filters`, group `completed-orders` (`completedOrdersModal`,
`completedOrdersModalLabel`, `recent-type-filters`, `completed-orders-modal-body`, `completed-orders-btn`), group `dead-session`
(`deadSessionModal`, `deadSessionModalLabel`, `dead-table-pick`, `dead-move`, `dead-reopen`), group `change-order` (`changeOrderModal`,
`changeOrderModalLabel`, `edit-order-btn`, `co-type-btns`, `co-order-type`, `co-table-wrap`, `co-table-session`, `co-terminal`, `co-branch`,
`co-apply-btn`), `reserve-customer-id`, `reserve-customer-search`, `reserve-customer-suggest`, `reserve-customer-chip`, `reserve-customer-name`,
`reserve-customer-clear`.
planned → equivalent: `create_separate_order` → `js:continueOpenCheckWithCart`.
equivalent / partial → present (Online id now used; the OLD Edge selector is GONE, the row must be re-pointed):
`tableWorkspaceModal`, `tableWorkspaceModalLabel`, `table-workspace-board`, `table-board-body` (was `text:<div class="board">` — that text is still
present), `table-workspace-open`, `open-table-form`, `open-table-no`, `restaurant_waiter_id` (was `#ta-waiter`), `guest_count` (was `#ta-guests`),
`open-table-submit` (was `#ta-open`), `table-workspace-held`, `table-workspace-held-body`, `table-workspace-split`, `table-workspace-split-body`,
`pos-session-bar`, `pos-session-details`, `pos-session-table-no`, `pos-session-waiter`, `pos-session-open-check`, `pos-session-bill-preview`,
`recalled-order-bar`, `recalled-order-no`, `pos-draft-badge`, `heldSalesModal`, `heldSalesModalLabel`, `held-sales-modal-body` (was
`text:No open checks.` — still present), `reserveTableModal`, `reserve-table-no`, `reserve-table-id`, `reserve-name` (was `#rs-name`),
`reserve-phone` (`#rs-phone`), `reserve-for` (`#rs-when`), `reserve-note` (`#rs-note`), `reserve-save-btn` (`#ta-reserve`),
`reservationDetailsModal`, `reservation-details-body`, group `reserve` state edge `#ta-reserve-form` → `#reserveTableModal`.
Still on the page: `#ta-unreserve`, `text:Reserved`, `text:Open check`, `text:Open table`, `text:Recall`, `#recall-btn`, `#sb-ok`,
`#cancel-order-btn`, `#split-bill-btn`, `#preview-bill-btn`, `js:'/reserve'`. `cancel-order-btn` partial → present (approval prompt done).
`pos-session-bill-preview` partial → present (per-table document). `bill-preview` group stays partial (R14 print block = reconcile).

CENSUS_RUN: see §Tests.

## Requests

**Team 2 (EdgeLocalPosService — exact):**
1. `holdOrReviseSale` / `reviseHeldSale`: persist `notes` on the sale (validated, currently dropped) — Online HSC `'notes' => $data['notes']`.
2. `openTableSession`: make `guest_count` required 1..100 (Online RTSC:~64) and cap `notes` at 255 (Online) — or keep 500 and tell the coordinator.
3. `reviseHeldSale`: accept a DIFFERENT open `restaurant_table_session_id` for a held dine-in check (Online HSC:687-688 re-targets the check), locking shift → both sessions (id order) → sale, refusing a target that already has a held check unless the operator merges; this lets Change Order move ONE check instead of the whole session.
4. `reviseHeldSale`: allow an `order_type` change on a held non-dine-in check (Online Change Order), re-validating `allowsOrderType` and the delivery/quick-sale attribution rules; totals recalculated with the new type.
5. Expose `requireAuthorizedPrincipal()` / `requireLocalAuthority()` as a shared protected helper (trait) so W3 services stop duplicating the three-line checks.
6. View-model flags for button gating (Online `@can`): `canMoveTable`, `canMergeTables`, `canRequestBill`, `canTableBillPreview`, `canShowTableSession`, `canReattachTable`, `canCloseTable`, `canOpenTable`, `canSplitBill` — the page will hide buttons when a flag is `false` (today the server refuses with the permission name).

**Team 5:** `printBillPreview(payload)` accepts `{sale_id}` / cart body only. Please accept a table payload `{ table_session_id, held_sale_ids[] }` (print the table bill document from `GET restaurant/table-sessions/{s}/bill-preview`'s `html`, network = the held ids) so the table bill uses the same Print-here / Send-to-network flow. Until then `openTableBillPreview` prints its own iframe and sends each held check.

**Team 6 (contract / reconcile):**
- R19: bootstrap + config-refresh export of `restaurant_tables.reserved_*` (customer by `customer_uuid`) and import into `edge_local_table_reservations`.
- R14: reconcile 243e01d `receipt.blade.php` `@isset($tableBill)` block (the Edge already passes `tableBill`).
- R26: reconcile MANAGER-APPROVAL-COMBO-VOID-1 (`KotCancellationService`) — page already asks `void_kot_items` for deals.
- No contract change from W3: request bill / move / merge / reattach are local; the sale envelope's existing `table_session` / `restaurant_waiter_id` fields simply carry the final values at settle.

**Coordinator:** ratify the `config/edge.php` W3 allowlist block; no `index.blade.php` include change needed (same 3 fragments).

## Tests (own DBs `pos_test_*_edgewt_t3`, 25 Sep 2026)

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_t3 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t3 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t3
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierTables|EdgeCashierOrderLifecycle|EdgeCashierDineInHttpMySqlTest|EdgeCashierReservationHttpMySqlTest|EdgeLocalRestaurantHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest'
  → OK (31 tests, 711 assertions), 20m11s   [new: TablesWorkspace 7 tests, OrderLifecycle 4 tests]
EDGE_NODE_BIN=… vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest
  → 3/4 green (registration/pinning, deferral grep, node --check of the composed script); row gate FAILS as intended (below)
vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php
  → OK (17 tests, 31236 assertions)
```

Census row-gate failures caused by W3 (Online ids replaced the old Edge ids — re-point the rows, see §Census flips):
`table-workspace-open`, `open-table-form`, `open-table-submit` (was `#ta-open`), `restaurant_waiter_id` (`#ta-waiter`), `guest_count`
(`#ta-guests`), `reserveTableModal` (`#ta-reserve-form`), `reserve-name` (`#rs-name`), `reserve-phone` (`#rs-phone`), `reserve-for`
(`#rs-when`), `reserve-note` (`#rs-note`), `reserve-save-btn` (`#ta-reserve`) — 11 rows. The other 26 failing rows (`#cm-*`,
`#category-tabs`, `#returns-btn`) come from Teams 1/2/4 fragments, not W3.

## Browser proof (dev instance 127.0.0.1:8095, DEVCASH1, READ-ONLY — no POST issued; own script, not `--allow-mutations`)

Script: scratchpad `w3-readonly-proof.mjs` (clicks only sub-views / GETs; records every non-GET request — result: `posts: []`).
Observed (Microsoft Edge headless, 1366×768; PNGs read):
- main page: `#clear-cart-btn`, `#start-fresh-btn`, `#new-sale-btn`, `#completed-orders-btn`, `#cancel-order-btn`, `#recall-btn` present;
- View Tables → `#tableWorkspaceModal`: 10 tiles, floor tabs (All / Ground Floor / Family Hall), occupied tiles with session no, guests,
  Total, Continue Table / Close Table (empty session) / Split Bill / Held Orders / Move / Details; free tiles Open Table / Reserve;
- Open Table → `#open-table-form` with `#waiter-roster` (click selects → `#restaurant_waiter_id`=1), `#guest_count`=1, `#table_notes`,
  `#table-workspace-back` visible (roster contrast fixed after the screenshot);
- Reserve → `#reserveTableModal` with book search; Manage → Online-required hint; Move → available-table picker;
- Details → session card; DEVCASH1 lacks `tenant.restaurant.table-sessions.show` → the Online-worded 403 is shown and the view falls
  back to board data (correct gate behaviour);
- Continue Table (G1, empty session) → `#pos-session-bar` visible (`Table G1 · session · No waiter · 4 guests · Open check 0.00`,
  Bill Preview / Request Bill / Move / Merge), actions switch to Hold (send later) / Draft / Cancel order / Leave table / + Add Round;
- Recent Orders → `#completedOrdersModal` "No recent orders on this branch." (dev DB has no paid sale).
Not browser-executed (needs `--allow-mutations`, coordinator): request bill, move, merge, reattach, voids + approval, cancel with approval,
Change Order apply, recall of a held check (dead-session modal).

## Team 5 wiring (coordinator follow-up, 25 Sep 2026) — per `edge-w5-team5-report.md` "Requests → Team 3"

| Item | Where | What |
|---|---|---|
| T3-1 (D-01/D-02/D-04) | `js/held` `sendKot()`, new `kotAfterHold()`, `holdSale()`, `saveRound()` | `sendKot` → `await fireKot(saleId)` (Team 5: KOT at the current counter, Online bookkeeping, Print Here for browser tickets, Reminder question) then `loadHeld`; the old held-sales KOT route is only a fallback if `fireKot` is absent. After a successful Hold / Save round with unsent food (not a draft): `autoPrintEnabled('kot')` → `fireKot`, else confirm "Print Kitchen Order?" (Online `handleKotAfterSale`). Save-before-KOT and save-before-split pass `{noAutoKot:true}` (no double KOT). |
| T3-2 (D-05) | `EdgeLocalHeldSalesController::cancelHeldSale` + `js/held` `cancelHeldCheck()` | response now carries `jobs` (CANCEL KOT + reminder jobs; fields `id, document_type, print_status, printer_name, fallback, preview_url` = printJobView names); page calls `handlePrintJobs(r.jobs, 'CANCEL KOT')` → a fallback ticket opens Print Here. |
| T3-3 (D-06) | `EdgeLocalHeldSalesController::storeHeldSale` + `saveRound()` | when a revise carries `void_items`: `$before = max(kot_batches.id)` for the sale before `holdOrReviseSale`, then `EdgeLocalPrintKotService::queueLineVoidCorrectionReminders($sale, $terminal->id, $before)`; the jobs return as `void_print_jobs` and the page passes them to `handlePrintJobs(…, 'Reminder')`. |
| T3-4 (A27) | `js/held` `openCompletedOrders()` | every row has a "Prints" action → `openLastPrint(sale.id, sale.sale_no)`; Resume and the Receipt/KOT buttons also go through `openLastPrint`. |
| T3-5 (R14/D-10) | `js/tables` `openTableBillPreview()` | Print here → `printBillPreview({restaurant_table_session_id, held_sale_ids, target:'here'})`; Send to network → same with `target:'network'`; own iframe print / per-check loop remain only when `printBillPreview` is absent. |
| R26 (Team 6 note) | `js/held` `voidSentLine` / `approveVoids` | confirmed: a deal line always requests ONE grouped `void_kot_items` approval (header + component rows as void entries); a one-row deal void is accepted only after the canonical combo-void reconcile. |

Remaining difference: Team 2's Review & Pay (`js/payment`) calls `saveRound(state.held.is_draft)` before paying, so an unsent round asks
"Print Kitchen Order?" (or auto-sends) at that moment — the Online order of events (hold → KOT → pay). Team 2 may pass `{noAutoKot:true}`
if the owner prefers no prompt at pay.

Tests added: `EdgeCashierOrderLifecycleHttpMySqlTest::test_held_orders_type_filter_and_cancel_from_the_list_with_manager_approval` (cancel
returns the CANCEL KOT job with the printJobView fields, fallback + preview_url), `::test_sent_line_voids_single_and_grouped_approval_and_reason_book_modes`
(`void_print_jobs` returned after a void revise; one cancel batch), `::test_the_cashier_page_renders_the_w3_controls` (page calls `fireKot`,
`kotAfterHold`, `handlePrintJobs(r.jobs, 'CANCEL KOT')`, `openLastPrint(id, no)`, table `printBillPreview` payload).

Census flips from this follow-up: none new (the Prints/Resume actions and the table Print here/Send to network live inside W3/W5 dialogs;
`print-bill-preview-btn` / `bill-preview-frame` are rendered by Team 5's `showBillPreviewFrame` — Team 5 lists those flips).

Test run after the wiring (t3 DBs): `--filter 'EdgeCashierTables|EdgeCashierOrderLifecycle|EdgeCashierDineInHttpMySqlTest|EdgeCashierReservationHttpMySqlTest|EdgeLocalRestaurantHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeCashierScreenRendersHttpMySqlTest'`
→ **OK (31 tests, 728 assertions)**, 3m18s; composed script `node --check` OK; render test re-run after the light-theme CSS change → OK.

## Team 1 shell alignment (25 Sep 2026)
- `renderSessionBar()` fills Team 1's header markup (`#pos-session-bar`, `#pos-session-details`, `#pos-session-table-no`, `#pos-session-no`,
  `#pos-session-waiter`, `#pos-session-guests`, `#pos-session-open-check`, `#pos-session-actions`, `#pos-session-bill-preview`,
  `#pos-session-request-bill-form`, `#pos-session-move-btn`, `#pos-session-merge-btn`, `#pos-session-status`) and never creates a second copy —
  it only builds the bar when the id is absent (older shells). `#edit-order-btn` lives in W3's `#recalled-order-bar` (not the header).
- Light theme: the styles injected by `js/tables` now use the shared variables (`--ok`, `--primary`, `--primary-dark`, `--info`, `--navy`,
  `--muted`, `--danger`, `--line`, `--accent`); the recalled bar uses Online's alert colours (#fff3cd / #ffc107). No dark background remains in
  W3 CSS; `js/held` / `js/actions` inject no colours.

## Team 2 hand-back items + inline notices (25 Sep 2026)

- **Held-sale validation** (`EdgeLocalHeldSalesController::storeHeldSale`): now lets through `lines.*.kitchen_note` (nullable string ≤500), `lines.*.discount_amount` (nullable numeric ≥0) and `change_order_details` (boolean). `EdgeLocalPosService` (Team 2) validates them.
- **`heldSaleView()`**: each line now carries `modifiers`, `variant_name`, `unit_code`, `kitchen_note` and `discount_amount`, and the sale carries `notes`. `loadHeld()` copies variant id, options, kitchen note and line discount onto the carried cart line, so Recall and Add Round keep them.
- **Hold of a quick sale**: uses Team 2's inline fields (`#vehicle_number` / `#qs-waiter-select`, via `requireQuickSaleFields()` + `quickSaleAttribution()`) when they are on the page. The old prompt is only a fallback, and it no longer preselects the first waiter ("Select waiter…").
- **R21 Change Order on a HELD check** now uses Team 2's `change_order_details`:
  - Choosing another table moves THIS check there; a free table is opened first. This matches Online's Change Order + Hold. The session-wide Move / Merge stay on the Table Workspace.
  - The order-type buttons are enabled for a held check.
  - New helper: `retargetHeld(type, sessionId)`.
  - **R21 status is now MATCHED** (Team 2 requests #3/#4 are satisfied by their `change_order_details`).
- **Inline notices** (the last two planned census rows):
  - `#open-table-error` sits inside `#open-table-form`. Refusals show there through `showInlineError('open-table-error', msg)`, and the toast still fires.
  - `#reserve-toast` sits inside `#reserveTableModal`. Success and refusals show there through `showInlineToast('reserve-toast', msg, level)`. On success the board reloads 0.7 s later.
  - Census flips:
    - `open-table-error`: planned → present (`#open-table-error`)
    - `reserve-toast`: planned → present (`#reserve-toast`)
- **Tests**:
  - Added `EdgeCashierOrderLifecycleHttpMySqlTest::test_hold_keeps_kitchen_notes_and_change_order_details_retargets_one_check`.
  - The render test now also asserts `#open-table-error` and `#reserve-toast`.
  - Test filter on the t3 DBs → **OK (32 tests, 745 assertions)**.
  - The composed page script passes `node --check`.
