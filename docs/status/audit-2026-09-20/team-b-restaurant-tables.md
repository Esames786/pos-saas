# TEAM B — Restaurant / table operations parity audit (read-only)

Repo `D:\laragon2\www\pos-saas-edge` @ 4affc67 (feat/edge-config-refresh-v1). Installed artifact `…\runtime\versions\0.6.0-edge` (623f887): every file in my scope is byte-identical to source (`diff -q`: routes/edge_runtime.php, resources/views/edge/**, EdgeLocalPosController, EdgeLocalPosService, EdgeTableReservationService, KotCancellationService, ManagerApprovalService, SalesService, PrintJobService, EdgeLocalAuthService, config/edge.php, edge middleware). So below **EDGE_INSTALLED_* == EDGE_SOURCE_*** unless stated. Important nuance: the artifact physically SHIPS `routes/tenant.php` plus `Tenant/RestaurantTableSessionController|RestaurantTableController|RestaurantFloorController|RestaurantWaiterController|HeldSaleController|SplitBillController|ManagerApprovalController` and `resources/views/tenant/restaurant/**`, but on a branch_server every route not on `config/edge.php` `route_allowlist` (L199-268) is refused 404 by `EnsureEdgeRuntimeRouteAllowed` (L22-36). Where I write "tenant route: shipped-but-refused" that is what is meant.

Common Edge gating (applies to every Edge record): `edge.auth` (`EnsureEdgeAuthenticated` L33-67: fresh active + Edge-eligible + branch-authorized user with current-epoch credential) + `edge.branch` (`EnsureEdgeBranchBound` L26-43) + `EdgeLocalPosService::requireAuthorizedPrincipal` (L455-467). There is NO per-action permission check on Edge table routes; Online gates by route-name permission (`EnsureRoutePermission` L62-71, names seeded `TenantProvisioner` L533-570) and by `@can` in views. Only `tenant.pos.store` (settle, `EdgeLocalPosController` L1118-1125), `tenant.pos.void-kot-item` (cashier + approving manager, `KotCancellationService` L273-281, `EdgeLocalPosService` L1035-1044), and `allowsOrderType('dine_in')` (`EdgeLocalPosService` L606, L695) are enforced on Edge.

Browser-proof baseline: the ONLY rendered-control assertions on the Edge page in table scope are `assertSee('View Tables')` (`EdgeCashierReservationHttpMySqlTest` L100; `EdgeCashierScreenRendersHttpMySqlTest` L95) and `assertSee('Hidden Later Karahi')` on the page payload (`EdgeCashierDineInHttpMySqlTest` L212). Every table/held control on Edge is JS-rendered into `#modal` from JSON (`resources/views/edge/pos/index.blade.php` L792-884), so no test can (or does) assert those controls. `HeldSaleDeadSessionMySqlTest` and `HeldSaleReattachTableMySqlTest` are Cloud-route tests (`postJson('http://'.$this->host.'/held-sales…')` L280 / L327) — they prove nothing about Edge.

---
## RECORDS

### R1 — View Tables entry point + Table Board (layout/navigation)
ONLINE_ROUTE= GET /pos (`#view-tables-btn`), GET /api/pos/table-board (routes/tenant.php:581), GET /restaurant/board (:533)
ONLINE_VIEW= tenant/pos/index.blade.php:428 (button), :1019-1114 (`#tableWorkspaceModal`, modal-xl, toolbar + Manage Floors/Tables + sections board/open/held/move/split/manage), partials/table-board.blade.php:1-139 (floor tabs L11-18, tiles L34-132); standalone restaurant/board.blade.php:99-218
ONLINE_CONTROLLER_OR_SERVICE= POSController@tableBoard L518-542 (re-renders partial), RestaurantTableSessionController@board L19-50
EDGE_INSTALLED_ROUTE= GET /edge/local/pos (edge.local.pos.screen), GET /edge/local/pos/restaurant/board (edge_runtime.php:78) — JSON only; tenant routes shipped-but-refused
EDGE_INSTALLED_VIEW= edge/pos/index.blade.php:114 (`#view-tables-btn`), viewTables() L792-808 + tableActions() L810-845 (one generic `#modal`, `<h3>` per floor, `.tbl` tiles, action panel under the grid)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= Bootstrap modal "Table Workspace" with server-rendered tiles (table_no, seats, status chip, session no, waiter, running total), floor pill tabs, sub-views (Back button), Manage Floors/Tables iframes for permitted users, board refresh in place without reload
EDGE_BEHAVIOUR= Fetches `/restaurant/board` JSON (EdgeLocalPosController L528-563), renders dark-theme tiles (table_no, status text, waiter, reservation name), click a tile → action panel (`#table-actions`) with open-check rows / New check / Close table (empty) / Open / Reserve… / Cancel reservation; whole modal re-fetched after each action
VISUAL_DIFFERENCE= YES — no seats/capacity, no session no, no running check total on tiles; no status chip colours per state (only warn/accent borders L82-83); no floor tabs (floors as headings); no Back/sub-view navigation; no Manage buttons; theme entirely different
NAVIGATION_DIFFERENCE= YES — Online: tile buttons act directly (Continue/Open/Reserve/Move/Split/Held Orders/Close); Edge: two clicks (select tile → action panel); Edge modal closes and re-opens on each mutation
WORKFLOW_DIFFERENCE= Move/Split/Held Orders/Bill Preview entry points absent on Edge tiles (see R8, R12, R14, R32)
VALIDATION_DIFFERENCE= Online board scoped by `UserDataScope::branchesForPos` + `assertDineInAllowed`; Edge board scoped by bound branch only, no dine_in permission check on the read (L528-531)
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= NOT MATCHED — Online button shown only if `dine_in` in allowedOrderTypes (:427) and board needs `tenant.restaurant.board`; Edge button always rendered (L114) and board readable by any Edge session
ACTUAL_BROWSER_PROOF= `assertSee('View Tables')` only (ReservationHttp L100, ScreenRenders L95); board contents JS-rendered — none
AUTOMATED_TEST_PROOF= JSON: EdgeCashierDineInHttpMySqlTest L150-160, EdgeLocalRestaurantHttpMySqlTest L120-124
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= listed above
REQUIRED_ACTION= Add tile data (capacity, session_no, open-check total) to board JSON + tiles; per-state colours; floor filter; single-click tile actions; gate button on dine_in allowance; add a rendered-control proof (e.g. serve board HTML or assert JSON→DOM via a JS-less contract test)

### R2 — Table tile status states
ONLINE_ROUTE= as R1
ONLINE_VIEW= partials/table-board.blade.php:29-40 (effectiveStatus: occupied/bill_requested from session, else table.status), restaurant/board.blade.php:9-15 (6 colours)
ONLINE_CONTROLLER_OR_SERVICE= POSController@loadBoardFloors
EDGE_INSTALLED_ROUTE= /restaurant/board
EDGE_INSTALLED_VIEW= index.blade.php:81-84 CSS (.occupied/.bill_requested warn, .reserved accent), L800 tile
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= shows available/occupied/bill_requested/reserved/cleaning/inactive distinctly
EDGE_BEHAVIOUR= status computed L549 (session→occupied/bill_requested, active Edge reservation→reserved, else table.status); inactive tables excluded (L533); `bill_requested` class exists but unreachable (no route sets it, R11)
VISUAL_DIFFERENCE= YES (no per-state colours for available/cleaning; occupied and bill_requested identical)
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= bill_requested never occurs on Edge
VALIDATION_DIFFERENCE= none
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= JSON status asserted: DineIn L151,158; ReservationHttp L111,125,143,147
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= per-state styling; depends on R11 for bill_requested

### R3 — Open Table form (guests, notes, terminal binding)
ONLINE_ROUTE= POST /restaurant/tables/{t}/open (:534), perm `tenant.restaurant.table-sessions.open`
ONLINE_VIEW= pos/index.blade.php:1055-1097 (`#open-table-form`: hidden terminal_id, waiter roster, guests default 1 required, `#table_notes`), JS L6520-6578 (refuses without POS terminal; AJAX; lands in session bar)
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableSessionController@open L52-181 (validate terminal required, waiter branch check, guest_count required 1-100, notes ≤255; shift lock; reservation consumed L118-138)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/restaurant/tables/{table}/open (edge_runtime.php:79)
EDGE_INSTALLED_VIEW= index.blade.php:836-838 (waiter `<select>`, guests default `t.capacity||2`), openTable() L860-871 (posts waiter+guests only)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= Open Table sub-view; terminal from POS `#terminal_id`; notes captured; success → session bar + board refresh
EDGE_BEHAVIOUR= EdgeLocalPosController@openTable L566-588 → EdgeLocalPosService::openTableSession L600-672 (terminal = session-selected terminal via selectedTerminal() L1127-1142; shift lock first; locking read for open session; ULID session_no; seats reservation); UI: check-chip "Table X · new check", order type locked to dine_in
VISUAL_DIFFERENCE= YES (dropdown vs roster; no Notes field; guests default differs)
NAVIGATION_DIFFERENCE= Edge: inline in tile action panel; Online: dedicated sub-view with Cancel/Back
WORKFLOW_DIFFERENCE= Notes not enterable on Edge (API accepts `notes` ≤500, L570); Edge does not refuse client-side when no terminal (server 422 'Select a terminal first.')
VALIDATION_DIFFERENCE= guest_count optional on Edge (defaults 1, L618) vs required Online; notes 500 vs 255; Edge requires waiter `status=active` (L611-616), Online only checks branch mismatch (L71-79); session_no format differs (`TS-{branch}-{ULID}` vs `TS-YmdHis-nnn`)
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= PARTIAL — both enforce `allowsOrderType('dine_in')`; Online additionally `tenant.restaurant.table-sessions.open` + `assertPosSelection`; Edge none
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L136-137, L299, L322; LocalRestaurantHttp L127-134 (duplicate open 422); Race L130-147 (one winner)
STATUS= PARTIALLY_IMPLEMENTED
EVIDENCE= above
REQUIRED_ACTION= add Notes input; make guests explicit/required; add permission gate parity

### R4 — Waiter selection (open table / inherited by check)
ONLINE_ROUTE= as R3; waiters list from RestaurantTableSessionController@board L41-43 / POSController
ONLINE_VIEW= pos/index.blade.php:1063-1083 roster (`.waiter-choice` buttons, initials), selectWaiterChoice L6501-6516
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@store L689-691/731-733 (dine-in inherits session waiter)
EDGE_INSTALLED_ROUTE= screen bootstrap (EdgeLocalPosController L148-150: active, null-or-bound-branch waiters)
EDGE_INSTALLED_VIEW= index.blade.php:836 `#ta-waiter` select; held inherits L791 / L938
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= roster buttons; check inherits session waiter
EDGE_BEHAVIOUR= dropdown; check inherits session waiter (EdgeLocalPosService L791, L938-939); waiter shown on tile/chip
VISUAL_DIFFERENCE= YES (control type)
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= none
VALIDATION_DIFFERENCE= see R3
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L159,165 (waiter_name on board + held list)
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= cosmetic; optional roster

### R5 — Terminal selection as it affects tables
ONLINE_ROUTE= POST open with `terminal_id` (:534)
ONLINE_VIEW= pos/index.blade.php:1060 hidden, L6529-6535 guard; restaurant/board.blade.php:53-96 board-terminal select
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableSessionController@open L85-95, L105 (`lockOpenShiftForTerminal`)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/terminal/select (:40) then open/hold/kot/settle use session terminal
EDGE_INSTALLED_VIEW= index.blade.php:120 header `#terminal`, renderTerminals L208-221
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= table binds to POS-selected terminal's open shift; refused without terminal/shift
EDGE_BEHAVIOUR= identical rule server-side (openTableSession L624-626, selectedTerminal L1127-1142); pinned operators see only their default terminal (L73-79)
VISUAL_DIFFERENCE= minor (header select)
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= none
VALIDATION_DIFFERENCE= none material
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= MATCHED (`CHANGE_TERMINAL_PERMISSION` L74)
ACTUAL_BROWSER_PROOF= `assertStringContainsString('Counter One')` ScreenRenders L98 (terminal option rendered)
AUTOMATED_TEST_PROOF= DineIn L114-115, L128-131, L195 (recall keeps operator terminal)
STATUS= FUNCTIONAL_BUT_NOT_BROWSER_PROVEN
EVIDENCE= above
REQUIRED_ACTION= none

### R6 — Continue Table / open-orders choice / New check on an open session
ONLINE_ROUTE= GET /api/pos/table-sessions/{s}/open-orders (:589)
ONLINE_VIEW= partials/table-board.blade.php:49-56 (Continue Table), pos/index.blade.php:2271-2301 continueTableSession, 4830-4860 showOpenOrdersChoice (Swal: pick order / Continue Latest)
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@tableSessionOpenOrders L160-184
EDGE_INSTALLED_ROUTE= /restaurant/board (held_orders per session L540-541, L557), GET /held-sales/{sale} (:87)
EDGE_INSTALLED_VIEW= index.blade.php:812-817 (open-check rows → loadHeld), `#ta-new` New check only when no held (L817), startCheckOnSession L872-876
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= Continue → if no orders: session bar with empty cart; else choice modal; one open check per session enforced (409 TABLE_HAS_OPEN_ORDERS, HeldSaleController L492-511)
EDGE_BEHAVIOUR= tile panel lists open checks (recall) or offers New check; server refuses second new check 422 (EdgeLocalPosService L735-737)
VISUAL_DIFFERENCE= YES (list rows in panel vs Swal choice)
NAVIGATION_DIFFERENCE= minor
WORKFLOW_DIFFERENCE= none material
VALIDATION_DIFFERENCE= 409 vs 422 code; same rule
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= Online `assertPosSelection`; Edge bound-branch only
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L157-172; LocalRestaurantHttp L153-157
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= none blocking

### R7 — Held Orders per table sub-view
ONLINE_ROUTE= as R6
ONLINE_VIEW= partials/table-board.blade.php:89-90, pos/index.blade.php:6016-6029 (`#table-workspace-held`, cards with Recall / Continue)
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@tableSessionOpenOrders
EDGE_INSTALLED_ROUTE= board JSON held_orders
EDGE_INSTALLED_VIEW= index.blade.php:816 rows
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= dedicated list with items count + updated time
EDGE_BEHAVIOUR= inline rows with sale_no + total only
VISUAL_DIFFERENCE= YES (no items count/time)
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= none
VALIDATION_DIFFERENCE= none
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= see R6
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L160
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= cosmetic

### R8 — Close empty table (status closed)
ONLINE_ROUTE= POST /restaurant/table-sessions/{s}/close (:536), perm `tenant.restaurant.table-sessions.close`
ONLINE_VIEW= partials/table-board.blade.php:75-82 (button only when session has NO order of any status), pos/index.blade.php:6135-6158 (Swal confirm)
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableSessionController@close L211-273 (lock, refuses draft/held)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/restaurant/table-sessions/{session}/close (:84)
EDGE_INSTALLED_VIEW= index.blade.php:817 `#ta-close` shown when no HELD orders; closeEmptyTable L877-884 (no confirm)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= confirm → close → board refresh
EDGE_BEHAVIOUR= EdgeLocalPosService::closeTableSession L1417-1445 (lock, refuses draft/held, never resurrects tombstoned table L1437-1441) → toast → board re-fetched
VISUAL_DIFFERENCE= minor
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= no confirmation on Edge; Edge shows Close when only PAID orders exist (Online hides)
VALIDATION_DIFFERENCE= none (both refuse over open orders)
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= NOT MATCHED — Edge has no `tenant.restaurant.table-sessions.close` gate
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L319-332; LocalRestaurantHttp L433-436; Race L221-254
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= add confirm; add permission gate

### R9 — Cancel session (status cancelled) from board
ONLINE_ROUTE= same close route with `status=cancelled`
ONLINE_VIEW= restaurant/board.blade.php:153-158 (Cancel button on standalone board only)
ONLINE_CONTROLLER_OR_SERVICE= @close L218-219
EDGE_INSTALLED_ROUTE= :84 accepts `status in:closed,cancelled` (L593)
EDGE_INSTALLED_VIEW= UI sends only `closed` (L879)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= cancelled session, table available
EDGE_BEHAVIOUR= API capable, screen never offers it
VISUAL_DIFFERENCE= YES (control absent)
NAVIGATION_DIFFERENCE= n/a
WORKFLOW_DIFFERENCE= cannot record "cancelled" vs "closed" outcome
VALIDATION_DIFFERENCE= none
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= as R8
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none for cancelled status on Edge
STATUS= PARTIALLY_IMPLEMENTED
EVIDENCE= above
REQUIRED_ACTION= low priority; add option or accept

### R10 — Session bar / check context (Online pos-session-bar vs Edge check-chip)
ONLINE_ROUTE= n/a (JS)
ONLINE_VIEW= pos/index.blade.php:446-470 (Table, session no, waiter, guests, Open check total, Bill Preview, Request Bill), applyTableSession L2212-2245
ONLINE_CONTROLLER_OR_SERVICE= n/a
EDGE_INSTALLED_ROUTE= n/a
EDGE_INSTALLED_VIEW= index.blade.php:310-321 renderChips (Held/DRAFT + Table + sale_no tail + waiter; or "Table X · new check")
EDGE_SOURCE_ROUTE= n/a
ONLINE_BEHAVIOUR= persistent bar with session totals + actions
EDGE_BEHAVIOUR= one chip; guests/session no/open-check total not shown; actions in `#actions` grid
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES (no Bill Preview/Request Bill on context)
WORKFLOW_DIFFERENCE= see R11, R14
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= n/a
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= show guests/session/open-check total

### R11 — Request Bill (bill_requested)
ONLINE_ROUTE= POST /restaurant/table-sessions/{s}/bill-requested (:535), perm `tenant.restaurant.table-sessions.bill-requested`
ONLINE_VIEW= pos/index.blade.php:461-469 + L5997-6008 (AJAX), restaurant/board.blade.php:130-138, table-sessions/bill-preview.blade.php:271-283
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableSessionController@billRequested L183-209 (session+table → bill_requested)
EDGE_INSTALLED_ROUTE= NONE (tenant route shipped-but-refused)
EDGE_INSTALLED_VIEW= none (only CSS class L82)
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= marks table "Bill Requested" for the floor/cashier; move/merge preserve it
EDGE_BEHAVIOUR= absent
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= floor cannot signal bill request offline
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none
STATUS= MISSING_IN_EDGE
EVIDENCE= above; parity register has no row for it
REQUIRED_ACTION= implement route+service+UI (lock session; status transition; board reflects) + HTTP proof; register row

### R12 — Move table (session to another table)
ONLINE_ROUTE= POST /restaurant/table-sessions/{s}/move (:539), perm `tenant.restaurant.table-sessions.move`
ONLINE_VIEW= partials/table-board.blade.php:92-97, pos/index.blade.php:6043-6051 (`#table-workspace-move`, target = available tables), restaurant/board.blade.php:160-175, bill-preview.blade.php:227-248
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableSessionController@move L327-439 (locks session+both tables, moves sales, source available, target occupied/bill_requested)
EDGE_INSTALLED_ROUTE= NONE
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= as above, in-place board update
EDGE_BEHAVIOUR= absent — offline workaround is cancel (needs manager if sent) + re-punch (re-KOTs food)
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= YES (documented gap #5 in docs/status/edge-cashier-architecture-clarification-2026-09-20.md; register L76)
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a (Online move does not reprint)
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none
STATUS= MISSING_IN_EDGE
EVIDENCE= above
REQUIRED_ACTION= implement with canonical lock order (shift→session→sale) + race proof; UI in tile panel

### R13 — Merge table sessions
ONLINE_ROUTE= POST /restaurant/table-sessions/{s}/merge (:540), perm `tenant.restaurant.table-sessions.merge`
ONLINE_VIEW= table-sessions/bill-preview.blade.php:250-269 (only on the standalone bill-preview page; NOT in the POS Table Workspace)
ONLINE_CONTROLLER_OR_SERVICE= @merge L441-569 (moves held/draft sales to target session; paid history stays; source cancelled)
EDGE_INSTALLED_ROUTE= NONE
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= as above
EDGE_BEHAVIOUR= absent
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES (Online reaches it via bill-preview page; POS modal has no merge either)
WORKFLOW_DIFFERENCE= YES — not in register/clarification doc (new unregistered gap)
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none
STATUS= MISSING_IN_EDGE
EVIDENCE= above
REQUIRED_ACTION= register row; decide implement or ACCEPT (owner decision — do not self-exclude)

### R14 — Per-table Bill Preview document (session bar / tile / print)
ONLINE_ROUTE= GET /restaurant/table-sessions/{s}/bill-preview (:538, JSON html or page), perm `tenant.restaurant.table-sessions.bill-preview`
ONLINE_VIEW= pos/index.blade.php:457-460, 5831-5839 showTableBillPreview, `#billPreviewModal` L1117-1138 (Send to network L1127, Print here L1131, L5878-5888); partials/table-bill-preview.blade.php:1-126 (receipt-layout styled: header/footer flags, per held sale lines/modifiers/subtotals, OPEN CHECK total, Previously paid, paid history); tile button hidden `d-none` L57-65
ONLINE_CONTROLLER_OR_SERVICE= @billPreview L290-325 (loads held+paid sales, ReceiptLayoutSetting)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/preview-bill (:50) — cart/current check totals only
EDGE_INSTALLED_VIEW= index.blade.php:369-387 previewBill/rowsHtml (Subtotal/Discount/Tax/Service/Delivery/Grand total; no lines, no per-round, no paid history, no print)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= receipt-looking table bill for the WHOLE session (all open checks + previously paid), printable here / to network
EDGE_BEHAVIOUR= totals of the loaded check only; "Running bill — no payment, stock, KOT, or receipt is created."
VISUAL_DIFFERENCE= YES (no line items, no receipt layout)
NAVIGATION_DIFFERENCE= YES (no entry from table tile/session context)
WORKFLOW_DIFFERENCE= YES (cannot present a guest bill document offline before payment; split checks not aggregated)
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= YES — Online prints preview (browser/network); Edge none
PERMISSION_PARITY= Edge unguarded
ACTUAL_BROWSER_PROOF= `assertStringContainsString('Preview Bill')` ScreenRenders L96 (label string in page)
AUTOMATED_TEST_PROOF= preview totals proven elsewhere (out of my scope); no per-table proof
STATUS= PARTIALLY_IMPLEMENTED (plus canonical drift: TABLE-BILL-PREVIEW-PARITY-1 / BILL-PREVIEW-WRONG-PRINT-1 of 14 Sep not in Edge — register L94 calls it UX_DRIFT; owner rule says visible differences are findings)
EVIDENCE= above
REQUIRED_ACTION= Edge route rendering the real receipt document for a session (rounds + previously paid) through print-jobs document path; tile + check-context entry; proof with assertSee on document

### R15 — Reserve table
ONLINE_ROUTE= POST /restaurant/tables/{t}/reserve (:523), gate in-controller on `tenant.restaurant.table-sessions.open` (EnsureRoutePermission L43-45)
ONLINE_VIEW= `#reserveTableModal` pos/index.blade.php:1921-1953 (customer-book search + Attached chip, Name, Phone, datetime, Note), JS L6213-6228; restaurant/board.blade.php:269-301
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableController@reserve L24-59 (refuses open session; snapshots customer name/phone; writes restaurant_tables.reserved_*)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/restaurant/tables/{table}/reserve (:82)
EDGE_INSTALLED_VIEW= index.blade.php:830-835 (`#ta-reserve-form`: Name, Phone, Reserved for, Note; toggled by "Reserve…"), reserveTable L847-855
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= attach existing customer or type walk-in
EDGE_BEHAVIOUR= EdgeTableReservationService::reserve L45-81 (Edge-owned `edge_local_table_reservations`; refuses inactive table, open session, existing active reservation; accepts customer_id L147-158) — UI never sends customer_id (no book search here, although `/customers` search exists for the cart L426-437)
VISUAL_DIFFERENCE= YES (inline form under tile; no customer picker)
NAVIGATION_DIFFERENCE= extra toggle click
WORKFLOW_DIFFERENCE= cannot attach a book customer to a reservation from the screen
VALIDATION_DIFFERENCE= Edge refuses double active reservation (L58-60), Online overwrites; Edge stores in own table + handback projection
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= NOT MATCHED (Edge: EdgeUserAuthz only, L139-141; no dine_in/open permission)
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= ReservationHttp L104-119, L142; ReservationRace L104-150; Handback L65-146
STATUS= PARTIALLY_IMPLEMENTED
EVIDENCE= above
REQUIRED_ACTION= add customer search/attach to reserve form; permission parity

### R16 — Reservation details
ONLINE_ROUTE= GET /restaurant/tables/{t}/reservation (:522)
ONLINE_VIEW= `#reservationDetailsModal` L1954-1964, showReservationDetails L6167-6178 (Name, Phone, Reserved for, Note, Reserved by, Marked at)
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableController@reservation L79-92
EDGE_INSTALLED_ROUTE= GET /edge/local/pos/restaurant/tables/{table}/reservation (:81) — UI does not call it; details come with board JSON (L550)
EDGE_INSTALLED_VIEW= index.blade.php:823-825 (Reserved chip, name, phone, when, note)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= Details button → modal incl. who reserved and when marked
EDGE_BEHAVIOUR= inline; no reserved_by / reserved_at
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= none (fewer clicks)
WORKFLOW_DIFFERENCE= missing audit fields on screen
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= NOT MATCHED (Edge read unguarded)
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= ReservationHttp L115-116, L148
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= expose reserved_by/at in reservationView + panel

### R17 — Cancel reservation
ONLINE_ROUTE= POST /restaurant/tables/{t}/unreserve (:524)
ONLINE_VIEW= L6160-6165 (confirm())
ONLINE_CONTROLLER_OR_SERVICE= @unreserve L62-76
EDGE_INSTALLED_ROUTE= POST …/unreserve (:83)
EDGE_INSTALLED_VIEW= L856-859 (no confirm)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= confirm → available
EDGE_BEHAVIOUR= EdgeTableReservationService::cancel L93-107 → available; refuses when none (422)
VISUAL_DIFFERENCE= minor
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= no confirmation step
VALIDATION_DIFFERENCE= none material
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= NOT MATCHED (as R15)
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= ReservationHttp L139-153; ReservationRace L135-150
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= add confirm

### R18 — Open reserved table → customer carried onto the check
ONLINE_ROUTE= open (:534) + hold
ONLINE_VIEW= applyTableSession L2240-2245 pre-attaches customer hidden fields; partials/table-board.blade.php:107-116
ONLINE_CONTROLLER_OR_SERVICE= @open L118-138 (session.customer_*; clears table reserved_*)
EDGE_INSTALLED_ROUTE= open (:79) + held.store (:91)
EDGE_INSTALLED_VIEW= openTable L868-870 (sets customer-name text from reservation), resolveHeldSaleCustomer (service L473-497)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= customer id/name/phone on session → first order
EDGE_BEHAVIOUR= reservation seated (L661-668) → held sale inherits customer unless request overrides
VISUAL_DIFFERENCE= minor
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= none
VALIDATION_DIFFERENCE= none
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= as R3
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= ReservationHttp L122-136
STATUS= FUNCTIONAL_BUT_NOT_BROWSER_PROVEN
EVIDENCE= above
REQUIRED_ACTION= none

### R19 — Online-made reservation visibility on Edge board
ONLINE_ROUTE= n/a (data path)
ONLINE_VIEW= n/a
ONLINE_CONTROLLER_OR_SERVICE= restaurant_tables.reserved_* columns
EDGE_INSTALLED_ROUTE= bootstrap/config sync
EDGE_INSTALLED_VIEW= board L549-550 (`reservation` only from Edge table; status may be 'reserved' from synced column)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= reserved card shows who/when
EDGE_BEHAVIOUR= EdgeBootstrapService L728 copies restaurant_tables `status` but NOT `reserved_*`; a table reserved Online before handover shows 'reserved' with `reservation:null` → panel shows plain open form + "Reserve…", no guest details, customer not carried on open
VISUAL_DIFFERENCE= YES (details lost)
NAVIGATION_DIFFERENCE= n/a
WORKFLOW_DIFFERENCE= possible; not proven
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none
STATUS= NOT_VERIFIED
EVIDENCE= EdgeBootstrapService.php:728; EdgeLocalConfigRefreshApplier.php:27,80; EdgeLocalPosController.php:549-550
REQUIRED_ACTION= write a proof; decide whether bootstrap should carry reserved_* into edge_local_table_reservations

### R20 — Session detail page (/restaurant/table-sessions/{s})
ONLINE_ROUTE= GET :537, perm `tenant.restaurant.table-sessions.show`
ONLINE_VIEW= restaurant/sessions/show.blade.php:1-85 (info card + orders table incl. paid)
ONLINE_CONTROLLER_OR_SERVICE= @show L275-288
EDGE_INSTALLED_ROUTE= NONE (shipped-but-refused)
EDGE_INSTALLED_VIEW= partial coverage: tile panel lists HELD checks only (L557)
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= admin/supervisor view of session (opened by/at, notes, all orders)
EDGE_BEHAVIOUR= absent; paid history of the session not visible offline
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= supervisor cannot inspect a session's paid rounds offline
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none
STATUS= MISSING_IN_EDGE
EVIDENCE= above
REQUIRED_ACTION= fold into R14 document (session with paid rounds) or accept explicitly

### R21 — Change Order Details modal (table session re-target / auto-session on hold)
ONLINE_ROUTE= GET /api/pos/table-sessions (:576); hold with `restaurant_table_id` auto-creates session (HeldSaleController L444-470)
ONLINE_VIEW= `#changeOrderModal` L1388-1440 (`#co-table-session`), coLoadTableSessions L5271-5296, apply L5321-5391
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@ajaxTableSessions L129-157
EDGE_INSTALLED_ROUTE= none equivalent; held.store requires an OPEN `restaurant_table_session_id` (service L714-725)
EDGE_INSTALLED_VIEW= order-type `<select>` L118 locked when a check/session is loaded (lockOrderType L227)
EDGE_SOURCE_ROUTE= n/a
ONLINE_BEHAVIOUR= switch order type / pick a table (opens session implicitly on hold) / change terminal for the current cart
EDGE_BEHAVIOUR= cart→table only via View Tables → Open table / New check first; no implicit session creation; terminal changed in header
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES (extra step)
WORKFLOW_DIFFERENCE= cannot assign an already-built cart to a table without leaving/rebuilding? — `openTable()` L866 clears the cart (`state.cart = []`), so a punched cart is LOST when opening a table afterwards
VALIDATION_DIFFERENCE= Edge stricter (explicit session required)
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= LocalRestaurantHttp L137-140 (dine_in without session 422)
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= keep cart when opening a table / offer "attach cart to table"; document the explicit-open rule

### R22 — Hold on table (Round 1) + one-open-check rule
ONLINE_ROUTE= POST /held-sales (:568)
ONLINE_VIEW= hold-sale-btn, response handling L4794-4802 (kot_sent from savedLine)
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@store L262-835 (dead-session refusal L426-441, TABLE_HAS_OPEN_ORDERS L492-511)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/held-sales (:91)
EDGE_INSTALLED_VIEW= holdSale L552-563 ("Hold (send later)" / "Draft" when session; toast "send the KOT when ready")
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= hold → KOT auto/prompt (R24)
EDGE_BEHAVIOUR= holdOrReviseSale L682-803 → loadHeld (server truth), KOT separate
VISUAL_DIFFERENCE= YES (labels)
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= see R24
VALIDATION_DIFFERENCE= Edge server-prices lines (no submitted unit_price) — stricter; same session-status rule
PRINTING_DIFFERENCE= see R24
PERMISSION_PARITY= both `allowsOrderType`; Online also `assertPosSelection`
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L138-145; LocalRestaurantHttp L143-160; Race L221-254
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= none blocking

### R23 — Add Round / "Saved round" (revise held sale, captured price, sent-state carry)
ONLINE_ROUTE= POST /held-sales with held_sale_id (:568)
ONLINE_VIEW= `#start-fresh-btn` label "Add Round" L4178-4187, L5228-5249 (focus search; hold again), KOT pool L607-645
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@store L517-692 (POOL-1/POOL-2; submitted prices trusted)
EDGE_INSTALLED_ROUTE= :91
EDGE_INSTALLED_VIEW= saveRound L613-624 ("Save round"/"Saved" button L346)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= pool-based sent carry-over keyed by product/variant/kind/combo
EDGE_BEHAVIOUR= reviseHeldSale L806-953: named-line carry by id only, captured price from stored row, identity check L845-855, void detection L875-910, `createSaleLines` L963-989; unnamed duplicate of a sent product starts at 0 sent (no pool)
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= Online: same Hold button; Edge: explicit "Save round"
WORKFLOW_DIFFERENCE= equivalent outcome; Edge refuses same line id twice / identity change (stricter)
VALIDATION_DIFFERENCE= Edge stricter (L845-855, L829-832)
PRINTING_DIFFERENCE= none
PERMISSION_PARITY= as R22
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L185-209; LocalRestaurantHttp L186-206, L365-407; Race L183-214
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= none

### R24 — KOT send after hold / sent-state handling in cart
ONLINE_ROUTE= POST /printing/jobs/kot/{sale} (:644)
ONLINE_VIEW= handleKotAfterSale L4365-4386 (auto-KOT per terminal setting or "Print Kitchen Order?" prompt), fireKotSilently L4279-4320 (updates kot_sent per line_quantities; fallback opens preview), kotPending L4264-4277, cart: "Cancel kitchen item" warning icon for sent lines L3329-3339, remove → void flow L3425-3444
ONLINE_CONTROLLER_OR_SERVICE= PrintJobController@queueKot → PrintJobService::queueKot L98
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/held-sales/{sale}/kot (:92)
EDGE_INSTALLED_VIEW= "KOT" button L347, sendKot L626-634 (saves dirty round first; toast "KOT #n sent · k line(s)"), cart sub-label "kitchen has N" L296, changeQty refusal toast L285; deal header rows loaded with `kot_sent_quantity: 0` L595
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= KOT fires automatically (or prompts) right after Hold; drafts skip KOT client-side
EDGE_BEHAVIOUR= KOT is a separate manual button; queueKotEvents L1003-1032 (draft refused server-side L1015-1017; bookkeeping via applyKotSentBookkeeping); no auto/prompt after Hold
VISUAL_DIFFERENCE= YES (no warning icon; text label instead)
NAVIGATION_DIFFERENCE= YES (extra explicit step; risk of forgetting KOT)
WORKFLOW_DIFFERENCE= YES — auto-KOT/prompt missing; deal (combo) rows are NOT client-locked when sent (header kot_sent_quantity forced 0) so reducing a sent deal only fails at save (server 422)
VALIDATION_DIFFERENCE= server rules equivalent (delta only; draft skip)
PRINTING_DIFFERENCE= Online prints via Print Agent/browser fallback; Edge queues print-job intents (Team A scope)
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L174-209, L297-317; LocalRestaurantHttp L162-183, L202-206; Race L151-181
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= auto-KOT/prompt after Hold honouring terminal setting; lock sent deal rows client-side

### R25 — Line void of a kitchen-sent item (reason + line-mode manager approval)
ONLINE_ROUTE= POST /held-sales (void_items[]) (:568); approval via /api/manager-approvals/verify (:710)
ONLINE_VIEW= showVoidReasonModal L3990-4033 (reason list; "Manager code required" badge; PIN modal), upsertVoidItem L3176-3189, remove/reduce hooks L3425-3444
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@store L542-575 → KotCancellationService::recordLineCancellations L127-225 (single `void_kot_item` L158-170)
EDGE_INSTALLED_ROUTE= :91 accepts `void_items.*` (EdgeLocalPosController L633-637); :97 verify supports `void_kot_item`
EDGE_INSTALLED_VIEW= NONE — `void_items` never built by the page (grep: 0 refs); changeQty L285 only toasts "reducing needs a void with a reason"
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= cashier picks reason (+PIN if branch line mode ≠ auto), item reduced, cancel KOT + reminder printed on next hold
EDGE_BEHAVIOUR= API fully capable and proven; screen offers no path → a sent line cannot be reduced/removed offline except by cancelling the whole order
VISUAL_DIFFERENCE= YES (control absent)
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= YES (blocking for dine-in operations)
VALIDATION_DIFFERENCE= Edge requires reason_id non-empty (L892); same qty rule
PRINTING_DIFFERENCE= cancel-KOT event recorded server-side (LocalRestaurantHttp L294-298); reminder jobs unasserted
PERMISSION_PARITY= same `tenant.pos.void-kot-item` for cashier; manager see R28
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= LocalRestaurantHttp L241-315 (JSON, single line, manager_required)
STATUS= PARTIALLY_IMPLEMENTED
EVIDENCE= above; not listed in register/clarification gaps
REQUIRED_ACTION= build reason picker + approval prompt (askManagerApprovalFor('void_kot_item')) + void_items submission on Save round; proof

### R26 — Combo/deal sent-quantity void (grouped approval) + MANAGER-APPROVAL-COMBO-VOID-1
ONLINE_ROUTE= as R25 (action `void_kot_items`)
ONLINE_VIEW= requestComboQuantity L3207-3265 (always grouped approval for a deal)
ONLINE_CONTROLLER_OR_SERVICE= KotCancellationService L157-205 in HEAD = PRE-fix (single vs grouped decided by `count($resolved)`); canonical 243e01d (branch feat/14d-2-plan-upgrade-requests only; `git merge-base --is-ancestor` = NO) decides by the approval's own `action_type` and accepts `void_kot_items` with one line
EDGE_INSTALLED_ROUTE= :91 + :97 (`void_kot_items` in MANAGER_ACTION_PERMISSIONS L1040)
EDGE_INSTALLED_VIEW= none (R25) — deals not lockable client-side (R24)
EDGE_SOURCE_ROUTE= same; installed KotCancellationService identical to HEAD (pre-fix)
ONLINE_BEHAVIOUR= (canonical, live Cloud) one-line combo void with grouped approval succeeds
EDGE_BEHAVIOUR= would be refused "Manager approval does not authorize this action" once a UI exists; today unreachable from screen
VISUAL_DIFFERENCE= YES (absent)
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= YES
VALIDATION_DIFFERENCE= YES (pre-fix service)
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= as R25
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= none on Edge; canonical adds tests/MySql/ManagerApprovalComboVoidMySqlTest.php (not in this tree)
STATUS= CANONICAL_DRIFT_NOT_RECONCILED
EVIDENCE= `git show --stat 243e01d` (KotCancellationService +94/-24); register L79
REQUIRED_ACTION= reconcile 243e01d into Edge branch + include its test; then build R25 UI

### R27 — Cancel whole held order / table check (reason + branch-mode approval; frees table)
ONLINE_ROUTE= POST /held-sales/{sale}/cancel (:569), perm `tenant.held-sales.cancel`; approval :710
ONLINE_VIEW= `#cancel-order-btn` L839, requestOrderCancellationDetails L4873-4902 (reason select → PIN modal when mode ≠ auto_approve), Held Orders row Cancel L5036 + cancelHeldSaleFromModal L5197-5210
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@cancel L1043-1079 → KotCancellationService::cancelHeldOrder L24-78 (approval consumed for sent food; cancel KOT + reminders; `releaseTableIfNothingLeft` L94-122)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/held-sales/{sale}/cancel (:94)
EDGE_INSTALLED_VIEW= "Cancel order" L350 (only on the LOADED check), cancelOrder L774-789: reason select → POST with reason_id only; on 422 shows message; NO manager prompt; Recall list has no per-row Cancel
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= cashier can complete a manager-required cancel via PIN
EDGE_BEHAVIOUR= EdgeLocalPosService::cancelHeldSale L1228-1242 (shared service; current-counter terminal); with `held_kot_cancellation_approval_mode=manager_required` and any sent food the screen dead-ends on "Manager approval is required for this branch." — no way to obtain/submit approval_id from the UI even though :97 and the service support it
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES (no cancel from list)
WORKFLOW_DIFFERENCE= YES (blocking in manager_required branches — the Kashif Food setting per DineIn fixture L80)
VALIDATION_DIFFERENCE= none (shared service)
PRINTING_DIFFERENCE= cancel KOT job recorded at current counter (DineIn L289-294); reminder printing unasserted
PERMISSION_PARITY= PARTIAL — both need `tenant.pos.void-kot-item` (service); Online also `tenant.held-sales.cancel` route perm + `deniesSale` scope; Edge none
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L267-295 and LocalRestaurantHttp L409-438 (JSON, WITH approval_id supplied directly)
STATUS= PARTIALLY_IMPLEMENTED
EVIDENCE= above
REQUIRED_ACTION= on 422 'Manager approval' → askManagerApprovalFor('cancel_held_order', {sales_order_id}) and retry with manager_approval_id; add Cancel to Recall list; proof

### R28 — Manager approval verification flow
ONLINE_ROUTE= POST /api/manager-approvals/verify (:710-712)
ONLINE_VIEW= showManagerPinModal L4037-4072 (single PIN input "Manager code")
ONLINE_CONTROLLER_OR_SERVICE= ManagerApprovalController@verify L14-56 (payload keys whitelisted) → ManagerApprovalService::verifyPin L18-34 (any active manager PIN; branch access L59-65; no permission check on the manager) → consume L77-107 (10 min, single-use, same cashier, payload match)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/manager-approvals/verify (:97)
EDGE_INSTALLED_VIEW= askManagerApproval L438-452 (manual_discount), askManagerApprovalFor L455-468 (used ONLY for sales_return L532) — employee code + Edge credential
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= PIN; actions void_kot_item / void_kot_items / cancel_held_order / manual_discount / sales_return
EDGE_BEHAVIOUR= EdgeLocalPosService::verifyManagerApproval L1055-1069 → EdgeLocalAuthService::verifyManager L58-68 (Argon2 credential, lockout, audit, requires `tenant.pos.void-kot-item` for ALL five actions L1035-1044; unknown action fails closed); same shared creator/consume; `payload` validated only as array (L753)
VISUAL_DIFFERENCE= YES (two fields vs one PIN)
NAVIGATION_DIFFERENCE= Edge prompt exists only for discount and return — not for cancel/void (R25, R27)
WORKFLOW_DIFFERENCE= manager must hold an Edge credential + permission; PINs never ship
VALIDATION_DIFFERENCE= Edge requires manager permission (Online does not); Edge payload keys not whitelisted
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= DIFFERENT BY DESIGN (documented in register/clarification)
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= LocalRestaurantHttp L262-300, L318-362 (refusal matrix, single-use); DineIn L277-282
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= wire prompt to cancel/void actions (R25/R27); consider payload key validation parity

### R29 — Split Bill
ONLINE_ROUTE= GET/POST /sales-orders/{sale}/split-bill (:562-563), perms `tenant.sales-orders.split-bill(.store)`
ONLINE_VIEW= `#splitBillModal` L1140-1150 → iframe of tenant/sales-orders/split-bill.blade.php (per-line qty inputs L55-68, notes L95, confirm L99); entries: tile "Split Bill" L84-88 (+ multi-order picker L6053-6062), cart `#split-bill-link` L842
ONLINE_CONTROLLER_OR_SERVICE= SplitBillController@create/store L17-120+
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/held-sales/{sale}/split (:96)
EDGE_INSTALLED_VIEW= splitBill L746-771 (modal, per-line qty, deals move with components; no notes input) — entry only from the LOADED check actions (L348), none on table tile
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= page-form in iframe; back to POS; each check paid separately
EDGE_BEHAVIOUR= EdgeLocalPosService::splitHeldSale L1255-1393 (canonical math, sent-state carry, recalc L1400-1414) → loads parent or child
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES (no tile entry; no multi-order picker)
WORKFLOW_DIFFERENCE= notes not enterable (API accepts, L765)
VALIDATION_DIFFERENCE= equivalent
PRINTING_DIFFERENCE= none (no re-KOT both sides)
PERMISSION_PARITY= NOT MATCHED — Edge has no split permission gate
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= EdgeCashierDealsDiscountsHttpMySqlTest L306-345 (JSON)
STATUS= FUNCTIONAL_BUT_UI_DIFFERENT
EVIDENCE= above
REQUIRED_ACTION= tile entry; notes; permission gate

### R30 — Settle held check → table closes ("Close & Pay Table Bill")
ONLINE_ROUTE= POST /pos (:476) with held_sale_id
ONLINE_VIEW= setCompleteSaleLabel L2198-2201 ("Close & Pay Table Bill")
ONLINE_CONTROLLER_OR_SERVICE= SalesService::closeRestaurantTableSession L286-316 (closes when no other held)
EDGE_INSTALLED_ROUTE= POST /edge/local/pos/held-sales/{sale}/settle (:93)
EDGE_INSTALLED_VIEW= Review & Pay / Complete Sale L637-696 (no table-specific label)
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= pay → session closed → table available
EDGE_BEHAVIOUR= settleHeldSale L1077-1220 (lock order shift→session→sale; shared closeRestaurantTableSession L1200; one outbox row)
VISUAL_DIFFERENCE= label only
NAVIGATION_DIFFERENCE= none
WORKFLOW_DIFFERENCE= none
VALIDATION_DIFFERENCE= Edge cash-only (accepted ONLINE_REQUIRED for card)
PRINTING_DIFFERENCE= receipt auto-queue (Team A)
PERMISSION_PARITY= MATCHED (`tenant.pos.store`)
ACTUAL_BROWSER_PROOF= `assertStringContainsString('Review &amp; Pay')` ScreenRenders L96 (label string)
AUTOMATED_TEST_PROOF= DineIn L216-228; LocalRestaurantHttp L211-238; Race L257-314; DealsDiscounts L340-345
STATUS= FUNCTIONAL_BUT_NOT_BROWSER_PROVEN
EVIDENCE= above
REQUIRED_ACTION= optional label parity

### R31 — Dead-session recovery / reattach-table (HELD-SALE-DEAD-SESSION-1)
ONLINE_ROUTE= POST /held-sales/{sale}/reattach-table (:574), Owner-only permission by deploy (comment :570-573)
ONLINE_VIEW= `#deadSessionModal` L1216-1288 (+JS L1290-1352; `#dead-table-pick` free tables; Reopen)
ONLINE_CONTROLLER_OR_SERVICE= POSController@index L83-106 detection; HeldSaleController@reattachTable L919-1041; prevention L426-441
EDGE_INSTALLED_ROUTE= NONE (shipped-but-refused)
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= recall of a bill whose session is closed → popup → reopen same table or move to a free one
EDGE_BEHAVIOUR= prevention present (hold refused on non-open session L714-720; close/settle/cancel never orphan a held bill); no recovery path; Edge bootstrap does not import Online held sales (EdgeBootstrapService grep: none), so an Online-orphaned bill cannot appear offline
VISUAL_DIFFERENCE= YES (absent)
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= recovery absent; risk low by construction
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= HeldSaleDeadSession/ReattachTable tests are Cloud-only (L280/L327 hit tenant host); Edge prevention proven LocalRestaurantHttp L137-140, Race L221-254
STATUS= MISSING_IN_EDGE
EVIDENCE= above; register L86-91 marks "CONSISTENT, no action"
REQUIRED_ACTION= accept explicitly (owner) or add a repair path; note Owner-only permission means it is a supervisor tool

### R32 — Held Sales admin pages (/held-sales index, /held-sales/create)
ONLINE_ROUTE= GET :566, :567, POST :568 (admin form)
ONLINE_VIEW= tenant/held-sales/index.blade.php:1-94 (Recall/Split/Bill/Cancel), create.blade.php
ONLINE_CONTROLLER_OR_SERVICE= HeldSaleController@index L29-43, @create L243-260
EDGE_INSTALLED_ROUTE= operator equivalent: GET /edge/local/pos/held-sales (:86) Recall list
EDGE_INSTALLED_VIEW= recallList L574-589
EDGE_SOURCE_ROUTE= same
ONLINE_BEHAVIOUR= back-office listing/creation
EDGE_BEHAVIOUR= Recall covers the operator need; no back-office listing
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= none for cashier
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= DineIn L162-167
STATUS= ACCEPTED_ONLINE_REQUIRED (Cloud admin page). Outage need: NO — Recall list is the operator surface
EVIDENCE= above
REQUIRED_ACTION= none

### R33 — Floors admin CRUD
ONLINE_ROUTE= :510-513, perms `tenant.restaurant.floors.*`
ONLINE_VIEW= tenant/restaurant/floors/index.blade.php (+ embedded via Table Workspace "Manage Floors" L1035-1039)
ONLINE_CONTROLLER_OR_SERVICE= RestaurantFloorController L12-60
EDGE_INSTALLED_ROUTE= NONE (config arrives via bootstrap L727 / config refresh with tombstones)
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= create/edit/delete floors
EDGE_BEHAVIOUR= read-only synced config
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= Manage Floors button absent
WORKFLOW_DIFFERENCE= cannot add a floor during outage
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= n/a
STATUS= ACCEPTED_ONLINE_REQUIRED (Cloud configuration page). Outage need: NO for normal service; only if the branch reconfigures its floor plan mid-outage (rare; changes apply after reconnection via config refresh)
EVIDENCE= above
REQUIRED_ACTION= none

### R34 — Tables admin CRUD
ONLINE_ROUTE= :516-519, perms `tenant.restaurant.tables.*`
ONLINE_VIEW= tenant/restaurant/tables/index.blade.php (filters, add row L62-116, edit modal L193+, delete), embedded "Manage Tables" L1040-1045
ONLINE_CONTROLLER_OR_SERVICE= RestaurantTableController L94-182
EDGE_INSTALLED_ROUTE= NONE
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= CRUD incl. manual status set (available/occupied/reserved/bill_requested/cleaning/inactive)
EDGE_BEHAVIOUR= synced config; occupancy status owned locally (EdgeLocalConfigRefreshApplier L27)
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= cannot add/rename a table or set "cleaning" offline
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= n/a
STATUS= ACCEPTED_ONLINE_REQUIRED (Cloud configuration). Outage need: NO for service; YES only for the edge case of a manager wanting a new/renamed table or a manual "cleaning" state mid-outage
EVIDENCE= above
REQUIRED_ACTION= none (owner may want a local "cleaning" toggle — not present)

### R35 — Waiters admin CRUD
ONLINE_ROUTE= :527-530, perms `tenant.restaurant.waiters.*`
ONLINE_VIEW= tenant/restaurant/waiters/index.blade.php
ONLINE_CONTROLLER_OR_SERVICE= RestaurantWaiterController L12-71
EDGE_INSTALLED_ROUTE= NONE (synced L729)
EDGE_INSTALLED_VIEW= none
EDGE_SOURCE_ROUTE= NONE
ONLINE_BEHAVIOUR= CRUD
EDGE_BEHAVIOUR= read-only synced list (active, null-or-branch)
VISUAL_DIFFERENCE= YES
NAVIGATION_DIFFERENCE= YES
WORKFLOW_DIFFERENCE= a new waiter hired during an outage cannot be selected
VALIDATION_DIFFERENCE= n/a
PRINTING_DIFFERENCE= n/a
PERMISSION_PARITY= n/a
ACTUAL_BROWSER_PROOF= none in this pass
AUTOMATED_TEST_PROOF= n/a
STATUS= ACCEPTED_ONLINE_REQUIRED (Cloud configuration). Outage need: NO normally; YES if staff roster changes during outage
EVIDENCE= above
REQUIRED_ACTION= none

---
## (1) Online table-board/workspace vs Edge View Tables modal
Online: Bootstrap `modal-xl` "Table Workspace" with toolbar (Back, Manage Floors/Tables), floor pill tabs, server-rendered tiles carrying table_no, seats, colour status chip, session no, waiter, running total, and direct buttons (Continue Table, Close Table [empty], Split Bill, Held Orders, Move, Details, Open Table, Reserve, Cancel Reservation); sub-views for Open form (waiter roster, guests, notes), Held Orders, Move, Split, Manage; after actions the board re-renders in place and the POS session bar (table, session no, waiter, guests, open check, Bill Preview, Request Bill) takes over (pos/index.blade.php:446-470, 1019-1114; partials/table-board.blade.php). Edge: one generic dark `#modal` (index.blade.php:75-84, 792-845) with floor headings and minimal tiles (table_no, status text, waiter, reservation name); selecting a tile reveals an action panel (open checks list / New check / Close table (empty) / Waiter dropdown + Guests + Open / Reserve… inline form / Cancel reservation); no floor tabs, no Back, no Manage, no Move/Split/Held Orders/Bill Preview/Request Bill on tiles; context afterwards is a single chip (L310-321). Navigation on Edge is two-step (select → act) and the modal is fully re-fetched after each action.

## (2) Online table actions with no Edge counterpart
- Request Bill / bill_requested (R11) — MISSING
- Move table (R12) — MISSING (registered gap)
- Merge sessions (R13) — MISSING (unregistered)
- Per-table Bill Preview document with print-here/network (R14) — only cart-level totals on Edge (partial + canonical drift 14 Sep)
- Reattach-table / dead-session popup (R31) — MISSING (prevention present)
- Session detail page (R20) — MISSING
- Close session as "cancelled" (R9) — API only
- Cancel held order from the Held Orders list row (R27) — Edge only on loaded check
- Split Bill entry from tile / multi-order picker / notes (R29) — partial
- Reserve with book-customer attach (R15), reservation details reserved_by/at (R16) — partial
- Open Table notes field (R3) — missing on screen
- Line void of sent item + combo grouped void (R25/R26) — API only, no UI; combo path additionally pre-fix
- Whole-order cancel in manager_required mode (R27) — UI dead-ends without approval prompt
- Change Order → table re-target / implicit session on hold (R21) — different path; opening a table clears the cart (L866)
- Manage Floors/Tables from workspace (R33/R34) — Cloud config (accepted)

## (3) KOT sent-state and Add Round differences
Online: Hold → `handleKotAfterSale` auto-fires KOT per terminal setting or prompts "Print Kitchen Order?" (L4365-4386); cart marks sent lines (warning "Cancel kitchen item" icon), refuses silent reduction and routes into void reason (+PIN); sent quantities updated from hold response / KOT job `line_quantities`; server uses the KOT-SENT-POOL keyed by product/variant/kind/combo (HeldSaleController L607-645) and trusts submitted prices. Edge: Hold/"Save round" then a SEPARATE manual "KOT" button (L347, L626-634; toast "send the KOT when ready" L561); sent lines show "kitchen has N" and reduction is refused by toast with NO void path (L285); deal header rows are loaded with `kot_sent_quantity: 0` (L595) so sent deals are not locked client-side (server refuses at save); server carries sent state strictly by named line id with captured price and identity check (EdgeLocalPosService L806-953) — stricter than Online's pool, but an unnamed duplicate of a sent product starts unsent (same intent as POOL-1). Draft KOT skip is server-enforced on Edge (L1015-1017) vs client-side Online. Deltas proven identical (DineIn L174-209; LocalRestaurantHttp L162-206).

## (4) Manager-approval flow differences
Identity: Online = manager PIN (`manager_pins` Hash::check, any active manager with branch access, no permission required) via a single "Manager code" field; Edge = manager's employee code + Edge-local credential (Argon2, lockout, audit) AND the manager must hold `tenant.pos.void-kot-item` for every action type incl. manual_discount/sales_return (EdgeLocalPosService L1035-1044; EdgeLocalAuthService L58-68). Approval semantics (approval_no/uuid, 10-min expiry, single-use, cashier binding, payload canonical match) are shared (`ManagerApprovalService::createApprovalForAuthenticatedManager` / `consume`). Single vs grouped: both services accept `void_kot_item` (one line) and `void_kot_items` (grouped, one approval); HEAD/Edge still decide by `count($resolved)` (pre-fix) so a combo single-line void approved as grouped is refused — canonical 243e01d (not an ancestor of HEAD; only on feat/14d-2-plan-upgrade-requests) decides by the approval's `action_type`. UI coverage: Online prompts for void_kot_item, void_kot_items, cancel_held_order, manual_discount, sales_return; Edge prompts only for manual_discount (L438-452) and sales_return (L532) — cancel_held_order and void flows have NO prompt (R25/R27). Edge controller validates `payload` only as an array (no per-key whitelist as Online L16-38).

## (5) Not verified and why
- No rendered-control proof exists for ANY table/held/split/cancel control on the Edge page: all are JS-built from JSON, and the suite asserts only 'View Tables' / label strings (ScreenRenders L94-96; ReservationHttp L100). I did not run tests (read-only).
- R19 (Online-made reservation shown/carried on Edge after handover) — code path suggests details are lost (bootstrap copies `status` only, L728); no test; not executed.
- Cancellation REMINDER print jobs on Edge cancel (`queueCancellationReminders` with terminal, PrintJobService L411-451) — recorded by the shared service but no Edge test asserts reminder jobs; Team A printing scope.
- Actual behaviour of the installed appliance in a browser (LAB) was not exercised — installed files were compared byte-wise only.
- Whether `void_kot_items` (grouped) succeeds end-to-end on Edge for a multi-line void — no Edge test; only single `void_kot_item` proven (LocalRestaurantHttp L241-315).
- Edge board vs Online `loadBoardFloors` inclusion rules (e.g., inactive floors/tables, cleaning) — Online helper not read in this pass; Edge excludes inactive tables (L533).
- Permission behaviour for an operator lacking `tenant.restaurant.table-sessions.open/close` etc. on Edge — inferred from code (no gate); no test asserts a 403 on Edge table routes.