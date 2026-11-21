# Offline Edge — FULL Online-vs-Edge screen & workflow audit (owner-directed, parallel review, 20 Sep 2026)

Audit only. Nothing built, merged, activated, punched or pulled: `YELLOW_CABLE=connected · WAN_TEST=paused · LOCAL_MODE=inactive ·
LIVE_TENANT_MUTATIONS=none · P6=not_started`. The P5C LAB appliance stayed in warm standby throughout.

Method: five independent READ-ONLY review teams with non-overlapping scope (A main cashier POS · B restaurant/tables · C finance/shift/
reports · D printing/documents · E cross-cutting), each producing evidence-backed records in the owner's field format, plus one
coordinator who re-grounded the baselines, built the screen inventory, merged the results and challenged every "FULL PARITY" claim.
The five team reports are archived verbatim under `docs/status/audit-2026-09-20/` (team-a … team-e). Nothing in the old parity register
was taken as proof; it was used only as the list of claims to test.

---

## A. Executive summary

**What Online provides (canonical 243e01d):** one 7 060-line cashier page (`tenant/pos/index.blade.php`) with 19 Bootstrap modals and
~50 identified panels/modals, backed by ~320 operator-relevant tenant routes; plus separate operator pages for shifts (open/close/close-
branch/history/detail), sales returns (list/create/show), customers (CRUD + ledger), supplier payments, manual journals (incl. reverse),
purchase returns (draft → post lifecycle), printing (jobs/printers/mappings/layouts/agents) and reports.

**What the installed Edge 0.6.0-edge provides (commit 623f887):** one 994-line self-contained cashier page (`edge/pos/index.blade.php`,
one generic modal, dark theme, no responsive rules) plus three finance pages, a health page and a login page, over 61 `edge/local/*`
routes. Every cashier-facing file (views, routes, controllers, middleware, config) is **byte-identical between the installed artifact and
source HEAD 4affc67** — so no screen finding below is "fixed in source but not installed".

**What Edge source adds over the installed artifact:** only the P5C heartbeat lost-ACK fix (`EdgeAuthorityLeaseClient/Service`,
`EdgeAuthorityService`, controller, two exceptions). The appliance half is `PRESENT_IN_SOURCE_NOT_INSTALLED`; the Cloud half is live on the
LAB Cloud (it serves the worktree) and the LAB appliance recovered through it.

**Headline result.** Across 149 records (A 44 · B 35 · C 45 · D 25 · E 15 — granularity differs per team, so the counts are per record,
not per feature) only **~9 are MATCHED_AND_PROVEN** (Complete-Sale permission gate; Quick Report view/network; network print transport;
document layouts/ESC-POS bytes; offline asset loading; TLS/session; local route targeting; route census). The bulk is
**FUNCTIONAL_BUT_UI_DIFFERENT (~60)** — the same workflow reachable through a visibly different, smaller surface — and
**MISSING_IN_EDGE (~35) / PARTIALLY_IMPLEMENTED (~22)** — workflows the live POS has that the Edge cashier cannot run or can only half run.
**~8 are NOT_VERIFIED / FUNCTIONAL_BUT_NOT_BROWSER_PROVEN** and **~13 ACCEPTED_ONLINE_REQUIRED**. Two records are
`CANONICAL_DRIFT_NOT_RECONCILED` (combo single-line void fix; 14-Sep bill-preview/table-bill changes) and one
`PRESENT_IN_SOURCE_NOT_INSTALLED` (heartbeat resync).

**The owner's observation is confirmed by the code.** The Edge page is not a re-skin of the Online POS: it is a smaller surface that
omits whole modals (modifiers, variants/qty entry, calculator, completed orders, change-order, dead-session recovery, print preferences,
per-table bill preview print), omits secondary workflows (request bill, move/merge, reminders, tips, line discounts, kitchen notes,
barcode, non-cash tenders, shift history/close-branch, journal reverse, purchase-return drafts), enforces fewer permission gates than
Online, and has no responsive layout. The earlier "26 FULL / 0 gaps" register measured workflow *API* parity for a curated list and never
inventoried the screen; §9 explains exactly how.

**What remains unverified:** every Edge control is JS-rendered, and no existing test drives a browser, so no record has paired
Online/Edge screenshot proof from this pass; only the LAB login/status/POS renders on the cashier laptop were observed (J).

---

## 3. Baselines (re-grounded 20 Sep 2026)

```
A. CANONICAL_ONLINE_SOURCE   origin/feat/14d-2-plan-upgrade-requests @ 243e01d (19 Sep, MANAGER-APPROVAL-COMBO-VOID-1); clean
B. EDGE_SOURCE               feat/edge-config-refresh-v1 @ 4affc67 (= origin, worktree clean)
C. INSTALLED_EDGE_ARTIFACT   0.6.0-edge, built from 623f887 (release mode, 8592 artifact files) — C:\Users\Dell\BingooEdgeLab\install\BingooEdge\runtime\versions\0.6.0-edge
D. LAST_VERIFIED_PRODUCTION  acf33a8 (18 Sep) — verified on the production box by the canonical session on 19 Sep (its memory/worklog);
                             NOT re-verified from this Edge session (no production access by rule). Canonical HEAD is one docs-only commit
                             (be07a5a) + MANAGER-APPROVAL-COMBO-VOID-1 (8d11bfb/62e65c9/243e01d) ahead of production.
```

## B. Screen inventory (built fresh, not from the register)

**Online (canonical):** operator route families in `routes/tenant.php` — POS (475-491: index, store, printing retry, customer quick-store
+ addresses, quick-report settings/save/print/email/send-to-network), shifts (240-248), sales returns (505-508), restaurant (511-541:
floors, tables, reservation/reserve/unreserve, waiters, board, sessions open/bill-requested/close/show/bill-preview/move/merge), held
sales (566-574 incl. cancel, reattach-table), customers (449-456), supplier payments (422-428), manual journals (812-816), purchase
returns (432-439), printing (620-666: printers CRUD/status/ping/reset/reboot, terminal settings, category mappings, layouts, jobs
receipt/kot/reminder-confirm/reminder-reprint/mark-printed/retry/dismiss, documents receipt/kot/reminder/preview, print agents), plus
`/api/pos/*` (held-sales, recent-sales, print-jobs, bill-preview, totals quote, promotions quote, shift-status, table-sessions),
`/api/manager-approvals/verify`, `/ajax/customers|sales`. Views: `tenant/pos/index.blade.php` + 2 partials (table-board, table-bill-
preview); `tenant/restaurant/**`; `tenant/shifts/{index,open,show,close,close-branch}`; `tenant/sales-returns/{index,create,show}`;
`tenant/customers/{index,form,show,ledger}`; `tenant/supplier-payments/{index,create,show}`; `tenant/finance/manual-journals/
{index,form,show}`; `tenant/purchase-returns/{index,create,edit,show}`; `tenant/printing/{agents,category-mappings,documents,jobs,
layouts,printers}`; `tenant/reports/center/{index,print}`. POS page modals (element ids): billPreview, changeOrder, completedOrders,
customer, deadSession, heldSales, lastPrint, modifierEntry, payment, posContext, posReport, posReturn, printHere, qtyEntry, quickReport,
reservationDetails, reserveTable, splitBill, tableWorkspace (with board/held/split/move/manage/open sections) + panels calculator,
delivery, manual-discount, print-pref, cust-selected, qr-panel-*. Navigation: the POS page hides the app chrome; the sidebar (hidden on
POS) carries Dashboard, Operations (Branches/Terminals/Shifts/Daily Closing), Sales (POS/Orders/Returns/Ledger/Customers/Payment
Methods/Delivery Channels/Riders), Restaurant (Table Board/Kitchen Display/Floors/Tables/Waiters/Held Sales), Printing, Sales
Controls, Reports, Administration; permission gating by route name (`EnsureRoutePermission`) + `@can` in views.

**Edge (installed = source):** `routes/edge_runtime.php` — 61 routes under `/edge/local/*` (health/ready/build-info, login/logout/status,
pos screen, terminals/select, shift status/summary/open/close, sync summary, sales, preview-bill, returns search/sale/store/show,
suppliers screen/options/ledger/payments, finance events/journal screen/options/store, purchase-returns screen/options/grn/store/show,
restaurant board/open/reservation/reserve/unreserve/session close, held-sales index/show/store/kot/settle/cancel/split, void-reasons,
customers search, manager-approvals verify, sales receipt/kot-reprint, print-jobs index/document/printed/retry, quick-report
options/view/network/email, health view). Views: `edge/pos/index.blade.php`, `edge/finance/{suppliers,journal,purchase-returns}`,
`edge/health.blade.php`, `edge/auth/login.blade.php`. Navigation: header (POS · View Tables · branch·user · Order select · Terminal
select · Shift · Returns · Suppliers/Journal/Purchase Returns (permission-conditional) · Status · sync chip · Logout) + action grid
(Hold/Draft/Recall/Preview Bill/Review & Pay; Save round/KOT/Split Bill/Cancel order/Leave check on a held check; Quick Report; Recent
Prints); one generic modal for every dialog. Gating: `edge.auth` + `edge.branch` middleware, then per-controller checks (see E-04/E-05).

Coverage statement: every Online operator route family above was assigned to a team and every Edge route/view was assigned; the
149 records in the appendices cover them. Not inventoried (out of scope by the accepted contract and by the owner's directive):
Cloud administration (users/roles/billing/system reset), Catering, Kitchen Inventory, Manufacturing, Reports Center internals.

## C. Paired comparison — consolidated matrix (one line per record; full fields in the appendices)

Legend: M = MATCHED_AND_PROVEN · U = FUNCTIONAL_BUT_UI_DIFFERENT · N = FUNCTIONAL_BUT_NOT_BROWSER_PROVEN · P = PARTIALLY_IMPLEMENTED ·
X = MISSING_IN_EDGE · S = PRESENT_IN_SOURCE_NOT_INSTALLED · D = CANONICAL_DRIFT_NOT_RECONCILED · O = ACCEPTED_ONLINE_REQUIRED · V = NOT_VERIFIED.

**Team A — main cashier POS** (`audit-2026-09-20/team-a-main-cashier-pos.md`)
| # | Screen element | Status |
|---|---|---|
| A1 | Header / title / navigation (Online: no app chrome, sidebar toggle; Edge: header nav with Logout/Status/finance links) | U |
| A2 | Order-type tabs (#mode-tabs-wrapper) vs Edge `<select>` | U |
| A3 | Category pills (parent + child strip, Deals pill) — Edge flat pills, no child strip | P |
| A4 | Product tiles (image/sku/stock/unavailable/backorder badges) — Edge name+price only | P |
| A5 | Search + barcode/scanner handling — Edge filters by name only, placeholder misleading | X |
| A6 | qtyEntryModal (measurable/weighted items, amount→qty) | X |
| A7 | modifierEntryModal (groups, min/max, price delta, edit line) — data synced, server accepts `lines.*.modifiers`, no UI | X |
| A8 | Variants (tile per variant / picker / barcode → variant) — server resolves, no UI | X |
| A9 | Combos / deals presentation | U |
| A10 | Cart line editing (qty ±, direct qty, remove, modifiers edit, notes) — Edge qty ± / remove only | P |
| A11 | Void-reason modal + manager approval for reducing a KOT-sent line (`void_items`) — server path only, no UI | X |
| A12 | customerModal — search & attach + saved address pick — Edge picker inside the commercial panel | U |
| A13 | Quick-create customer / new address at the till | O |
| A14 | delivery-panel (channel/aggregator/rider/address/charge) — folded into Review & Pay | U |
| A15 | manual-discount-panel (+ shortfall shortcut, remove) | U |
| A16 | Promo code | U |
| A17 | Tips (#pos-tip-amount) — `storeSale` has no tip | X |
| A18 | Service charge / tax / totals rows | U |
| A19 | Charge bar / Review & Pay entry | U |
| A20 | paymentModal (method, tendered, quick cash, reference, change, short tender) — Edge cash tendered only | P |
| A21 | Non-cash payment methods — card/provider O; bank transfer / cheque / other X | O + X |
| A22 | print-pref-panel (auto KOT/receipt toggles) + Direct-Pay KOT intent prompt | X |
| A23 | Complete Sale permission gate (`tenant.pos.store`) | **M** |
| A24 | Hold Sale / Save Order | U |
| A25 | Draft | U |
| A26 | heldSalesModal — Held Orders / Recall (Edge: dine-in checks list; no cancel from list) | P |
| A27 | completedOrdersModal — Recent Orders (reprint receipt/KOT, resume printing, view order/rider) | X |
| A28 | changeOrderModal — Edit Order Details (type / table session / terminal / branch) | X |
| A29 | deadSessionModal — held bill on a closed session (reopen/move) — prevention exists, recovery UI missing | X |
| A30 | billPreviewModal + Print here / Send to network — Edge totals only, no print | P |
| A31 | lastPrintModal / printHereModal entry points (Recent Prints) | U |
| A32 | calculator-panel (Ctrl+M keypad) | X |
| A33 | posContextModal — Branch & Terminal change | U |
| A34 | Keyboard shortcuts (Ctrl+F/H/L/P/Enter/M) | X |
| A35 | Touch behaviour / target sizes (148 px tiles, 44 px pills, 42 px buttons vs 74/… /34 px) | U |
| A36 | Confirmations / toasts / error & loading states (SweetAlert2 + spinners vs one plain toast, no spinners) | U |
| A37 | Cancel Order / Clear cart / New Order / New Sale | P |
| A38 | Recalled-order bar / control locking | U |
| A39 | Quick Sale vehicle + waiter | U |
| A40 | Manager approval modal (PIN vs employee code + credential) | U |
| A41 | Shift status badge / Open shift link | U |
| A42 | Split Bill entry | U |
| A43 | Page-load deep links (?held_sale_id, ?table_session_id, ?mode, ?branch_id, ?customer_id) | X |
| A44 | Report window (Sales Report Center iframe) / Quick Report / Return entry buttons | O (Report Center) / U |

Team A also documents (i) the page-wide structural difference (Bootstrap 5 light shell, 500 px sticky cart, 170 px tiles, responsive
breakpoints vs a fixed 100vh dark grid with a 380 px cart, 130 px tiles, **no `@media` rule**), (ii) 11 Online modals/panels with no Edge
counterpart, (iii) Edge-only controls (free-text customer name, sync chip/offline banner, finance/status/logout header links), and
(iv) validation diffs: Online requires `kot_print_intent`/`receipt_print_intent`, `exists:` rules, `order_type in:`; Edge lacks them and
adds "Line discounts are not yet available" and "exactly one cash payment"; Edge accepts `notes` on held sales but **never persists it**;
delivery rider required Online for own channel, optional on Edge.

**Team B — restaurant / table operations** (`team-b-restaurant-tables.md`)
| # | Screen element | Status |
|---|---|---|
| R1 | View Tables entry + Table Board layout (workspace modal with board/held/split/move/manage vs generic modal board) | U |
| R2 | Table tile status states | U |
| R3 | Open Table form (guests, notes, terminal binding) — notes field missing on screen | P |
| R4 | Waiter selection | U |
| R5 | Terminal selection as it affects tables | N |
| R6 | Continue table / open-orders choice / new check | U |
| R7 | Held Orders per table sub-view | U |
| R8 | Close empty table | U |
| R9 | Cancel session (status cancelled) from board — API only | P |
| R10 | Session bar / check context | U |
| R11 | Request Bill (bill_requested) | X |
| R12 | Move table | X |
| R13 | Merge table sessions | X |
| R14 | Per-table Bill Preview document (rounds, previously paid, print) — Edge cart-level totals only; also canonical 14-Sep drift | P (+D) |
| R15 | Reserve table (with book-customer attach) | P |
| R16 | Reservation details (reserved_by/at) | U |
| R17 | Cancel reservation | U |
| R18 | Open reserved table → customer carried onto the check | N |
| R19 | Online-made reservation visible on the Edge board after handover (bootstrap copies `status` only) | V |
| R20 | Session detail page (`/restaurant/table-sessions/{s}`) | X |
| R21 | Change Order Details → table re-target / implicit session on hold (opening a table clears the cart on Edge) | U |
| R22 | Hold on table (round 1) + one-open-check rule | U |
| R23 | Add Round / Saved round (captured price, sent-state carry) | U |
| R24 | KOT send after hold / sent-state handling in cart | U |
| R25 | Line void of a kitchen-sent item (reason + line-mode approval) — API only, no UI | P |
| R26 | Combo/deal sent-quantity void (grouped approval) + MANAGER-APPROVAL-COMBO-VOID-1 (Edge runs the pre-fix service) | **D** |
| R27 | Cancel whole held order / table check (reason + branch-mode approval) — UI dead-ends in manager_required mode | P |
| R28 | Manager approval verification flow | U |
| R29 | Split Bill (tile entry, multi-order picker, notes) | U |
| R30 | Settle held check → table closes | N |
| R31 | Dead-session recovery / reattach-table (HELD-SALE-DEAD-SESSION-1) — prevention present, recovery missing | X |
| R32–R35 | Held Sales admin pages, Floors/Tables/Waiters CRUD (Cloud configuration; not needed during an outage) | O |

Team B's Edge gating note: table/held/split/cancel routes are protected by `edge.auth`+`edge.branch` only; Online gates each by route
permission (`tenant.restaurant.*`, `tenant.held-sales.*`, `tenant.sales-orders.split-bill.store`). Only `tenant.pos.store`,
`tenant.pos.void-kot-item` and `allowsOrderType('dine_in')` are enforced on Edge table paths.

**Team C — shifts / payments / returns / approvals / Quick Report / customers / supplier finance / purchase returns** (`team-c-finance-shift-reports.md`)
| # | Screen element | Status |
|---|---|---|
| R1.1 | Shift status badge / open-shift gate on the POS page (Edge: no badge, no poll; `GET /shift` returns amounts unmasked) | U |
| R1.2 | Open shift (branch-wide multi-terminal + notes vs one field for the selected terminal; **no permission check on Edge**) | U |
| R1.3 | Close shift (denomination grid, live diff, closing notes, CASH-SHORTAGE draft voucher vs typed total only; no permission check) | P |
| R1.4 | Hide amounts / blind count (same class; `GET /shift` leaks amounts) | N |
| R1.5 | Zero drawer | N |
| R1.6 | Operating business date | N |
| R1.7 | Tender breakup + cancellations/voids (current terminal only) | U |
| R1.8 | Shift history list | X |
| R1.9 | Shift detail page / post-close summary | X |
| R1.10 | Close Branch (all terminals) + Daily Closing | X |
| R1.11 | Edge shift totals reaching Cloud shift / daily-closing reports | V |
| R2.1 | Tender at Review & Pay — card/wallet O; bank transfer / cheque / other X; tip X | O + X |
| R2.2 | Short tender → "Discount balance" shortcut | P |
| R2.3 | Payment-method administration | O |
| R3.1 | Returns entry point (button not permission-gated on Edge) | U |
| R3.2 | Find the returnable sale (Edge ignores `UserDataScope`) | U |
| R3.3 | Return screen, arithmetic, posting | U |
| R3.4 | Refund method — card O; bank transfer / other X | O + X |
| R3.5 | RETURN-MANAGER-APPROVAL path over HTTP on Edge | V |
| R3.6 | Returns list / detail pages | X (list) / U |
| R3.7 | Sale outside the warm-cache window / stale cache | O |
| R4.1 | Approval prompt & verification (PIN vs credential; payload unvalidated on Edge) | U |
| R5.1 | Quick Report entry (button not permission-gated on Edge) | U |
| R5.2 | Quick Report filters (waiters/order types/items) + per-user saved selection | P (+X) |
| R5.3 | Quick Report view / Print here (no auto-print; header shows branch not business name) | U |
| R5.4 | Send to network | N |
| R5.5 | E-mail | O |
| R5.6 | "Report" → Sales Report Center window | O (owner to confirm) |
| R7.1 | Search / attach customer (no header button) / new customer | U / O |
| R7.2 | Customer master pages (CRUD, ledger) | X — owner ruling |
| R8.1 | Supplier finance navigation | U |
| R8.2 | Supplier ledger | U |
| R8.3 | Record supplier payment (card to supplier O) | U |
| R8.4 | Supplier payments list / detail | P |
| R8.5 | Manual journal create | U |
| R8.6 | Manual journal list / show (P) / reverse (X) | P / X |
| R9.1 | Purchase return against a GRN (one-step post vs draft→post) | U |
| R9.2 | Purchase-return draft lifecycle (edit/update/cancel) | X |
| R9.3 | Return without a source receipt | O |
| R9.4 | Purchase returns list / detail | P |
Side finding: `edge-build-manifest.json` `capabilities` omits returns, supplier finance, purchase returns and quick report (manifest drift).

**Team D — printing / documents** (`team-d-printing-documents.md`)
| # | Document / flow | Status |
|---|---|---|
| D-01 | KOT generation (first round) + station routing — same `PrintRoutingService` on synced mappings; multi-station unproven in LAB | U |
| D-02 | New rounds — ADDITION KOT deltas | U |
| D-03 | KOT reprint — DUPLICATE KOT #n | U |
| D-04 | KOT REMINDER (document_type reminder, print_role reminder, confirm/reprint) — nothing wired on Edge | X |
| D-05 | Cancellation KOT — whole-order cancel (CANCEL KOT #n at the current counter) | U |
| D-06 | Cancellation KOT — line void (server only, routed on the sale's terminal, no correction reminder) | P |
| D-07 | Customer receipt after payment (ensure-once) + auto-print preference | U |
| D-08 | Direct Pay KOT (quick sale / takeaway / delivery without Hold) | P |
| D-09 | Receipt reprint (fresh job; copy identity) | U |
| D-10 | Bill preview print — Print here / Send to network | X |
| D-11 | Print Here document (browser fallback) + Mark Printed | U |
| D-12 | Recent Prints list (branch-wide) | U |
| D-13 | Retry a failed job | N |
| D-14 | Dismiss a job | X |
| D-15 | Printing → Jobs admin index | U |
| D-16 | Printer configuration management (printers CRUD, terminal settings, mappings, layouts) — Cloud configuration | X — owner ruling (admin config) |
| D-17 | Printer health — status / ping / reset / reboot | X — owner ruling |
| D-18 | Print Agents | O |
| D-19 | Quick Report view/print here/network (+ e-mail O) | **M** |
| D-20 | Physical network transport & delivery semantics (raw TCP 9100, "\n\n\n" trailer, lease/retry) | **M** |
| D-21 | USB printers | O |
| D-22 | Document layouts & ESC/POS payloads (same Blade + bytes) | **M** |
| D-23 | Printing permissions / data scope (no `UserDataScope` on Edge print endpoints) | P |
| D-24 | Terminal auto-print preferences + Printing panel | X |
| D-25 | Canonical drift affecting printing (243e01d not merged) | **D** |

**Team E — cross-cutting** (`team-e-cross-cutting.md`)
| # | Area | Status |
|---|---|---|
| E-01 | Navigation model (Online POS has no chrome; Edge header carries Logout/Status/finance) | U |
| E-02 | Online sidebar entries with no Edge equivalent (Dashboard, Sales Orders, Sales Ledger, Shifts list/Daily Closing, Kitchen Display, Printing mgmt, Void Reasons, Reports, Users/Roles/Change Password, Change Order Details, Request Bill, shortcuts/calculator) | X per entry — owner to classify required-offline vs Cloud-only |
| E-03 | Gating model (route-name default-deny vs middleware + per-controller checks) | U |
| E-04 | Gates Edge enforces like Online (`tenant.pos.store` proven; returns/QR/supplier/purchase-return keys; order-type authority; hide amounts; manager permission) | M / N |
| E-05 | Gates Edge does NOT enforce: `tenant.pos.index`, `held-sales.store/cancel`, `restaurant.board`, `table-sessions.open/close`, `split-bill.store`, `shifts.create/store/close` + terminal binding, `manager-approvals.verify`, `pos.change-terminal` pin (UI-only), print-job scope | P (gap) |
| E-06 | Terminal / branch restrictions (no branch selector, no "No Terminal" mode, pin not server-enforced) | U |
| E-07 | Manager re-auth identity (PIN vs credential) | U |
| E-08 | Empty/loading/error states & dialogs (SweetAlert2 + spinners + redirect on 401 vs plain toast, no spinners, no redirect) | U |
| E-09 | Responsive layout (Bootstrap breakpoints vs none; 1366×768 and tablet behaviour differ; smaller touch targets) | U |
| E-10 | Asset loading without Internet (Edge inline only; Online only Google Fonts import external) | **M** |
| E-11 | TLS / session (Edge stricter: throttle + lockout + secure cookie; proven on the cashier laptop) | **M** |
| E-12 | Local route/API usage (every Edge call → `edge.local.*`; no Cloud link) | **M** |
| E-13 | Edge source vs installed artifact (heartbeat resync only) | **S** |
| E-14 | Route census test proves the URI surface only | M (routes) |
| E-15 | Screen-render test proves 12 strings of server HTML, none of the JS-rendered controls | N |

## D. Exact source/artifact drift

`git diff --stat 623f887..4affc67 -- app routes resources config` = 6 files, +73/−6, all from the P5C heartbeat fix (99b3afa):
`EdgeAuthorityLeaseClient.php`, `EdgeAuthorityLeaseService.php`, `EdgeAuthorityService.php`, `EdgeAuthorityApiController.php`, new
`EdgeAuthorityRefusedException.php`, `EdgeStaleHeartbeatException.php`. `diff -rq` of the installed runtime: exactly those three
services differ, the two exceptions are absent; `resources/views/edge/**`, `routes/edge_runtime.php`, every Edge controller/middleware,
`config/edge.php`, `config/session.php`, `bootstrap/app.php` and `public/assets` are identical (Team E-13, A, B, C, D each re-verified
their own files with `diff -q`/`cmp`). Installed behaviour differs only in the heartbeat lost-ACK path (installed appliance cannot
resync its sequence; the LAB Cloud's idempotent re-ack heals it). Other "only in source" files are Cloud-only build exclusions, not drift.

## E. Missing and partial functionality — the complete list (not just the eight)

Grouped by what an operator loses offline. (A/R/D/E numbers refer to the matrix.)

1. **Selling the actual menu:** modifiers (A7), variants (A8), measurable/weighted qty entry (A6), barcode/SKU search (A5), tile stock /
   unavailable / backorder / image / sku indications (A4), child-category strip (A3), per-line kitchen notes and direct qty edit (A10),
   **line-level discounts** (Edge refuses: "Line discounts are not yet available on the Branch Server" — Online accepts
   `lines.*.discount_amount`), tips (A17), held-sale `notes` accepted but never persisted (A validation diff).
2. **Tender & payment:** bank transfer / cheque / other tenders (A21/R2.1 — only card/provider is an accepted exclusion), reference /
   quick-cash / short-tender shortcut (A20/R2.2), auto-print toggles and KOT-intent prompt (A22/D-24), bank/other refunds (R3.4).
3. **Order lifecycle:** completed/recent orders with reprint & resume printing (A27), change order details (A28), dead-session recovery
   (A29/R31), cancel/clear/new-sale parity (A37), deep links (A43), void-reason modal for reducing a sent line (A11/R25), combo grouped
   void (R26 — plus the unreconciled canonical fix), cancel with approval in manager_required mode (R27).
4. **Tables:** request bill (R11), move (R12), merge (R13), per-table bill preview document with print (R14/A30/D-10), session detail (R20),
   cancel session from the board (R9), open-table notes (R3), reservation-with-customer attach and details (R15/R16), Online-made
   reservations after handover (R19 unverified).
5. **Kitchen printing:** KOT reminders (D-04), line-void cancellation KOT + correction reminder (D-06), Direct-Pay KOT parity (D-08),
   bill-preview printing (D-10), dismiss (D-14), auto-print preferences (D-24), print-job data scope (D-23), multi-station routing
   unproven in the LAB (D-01).
6. **Shifts & cash:** shift badge/poll (R1.1), permission gates on open/close (R1.2/R1.3), denomination count, closing notes, CASH-SHORTAGE
   draft voucher (R1.3), amounts leak on `GET /shift` (R1.1/R1.4), shift history/detail (R1.8/R1.9), Close Branch + Daily Closing (R1.10),
   Edge shift totals in Cloud shift reports (R1.11 unverified).
7. **Returns / reports / finance lists:** returns button gating and `UserDataScope` (R3.1/R3.2), returns list (R3.6), return-with-approval
   proof (R3.5), Quick Report filters and saved selection (R5.2), auto-print and header name (R5.3), Quick Report button gating (R5.1),
   supplier payment list (R8.4), journal list/show/reverse (R8.6), purchase-return drafts (R9.2), lists (R9.4), customer master pages
   (R7.2 — owner ruling), manifest `capabilities` drift.
8. **Cross-cutting:** header/navigation model (E-01), missing sidebar destinations (E-02), unenforced route permissions (E-05), terminal
   pin not server-enforced (E-06), loading/error/severity/redirect states (E-08), no responsive layout and smaller touch targets (E-09/A35),
   keyboard shortcuts and calculator (A32/A34), page-wide look (Team A §1).

## F. Canonical changes still awaiting Edge reconciliation (38 commits since 5dc13d3; 9 touch shipped files)

| Shipped file changed on canonical | Commit(s) | Edge executes it? | Effect on Edge today |
|---|---|---|---|
| `app/Services/Sales/KotCancellationService.php` | MANAGER-APPROVAL-COMBO-VOID-1 (8d11bfb, 19 Sep) | **yes** (`EdgeLocalPosService` cancel/void) | one-line combo void with approval refused on Edge; fixed on Cloud → **CANONICAL_DRIFT_NOT_RECONCILED** (R26, D-25) |
| `RestaurantTableSessionController.php` | TABLE-BILL-PREVIEW-PARITY-1, BILL-PREVIEW-WRONG-PRINT-1 (14 Sep) | no (Cloud table workspace) | Online spec moved: table bill = real receipt document with rounds/previously-paid + print target = held ids; Edge has cart totals only (R14) |
| `tenant/pos/index.blade.php`, `pos/partials/table-board.blade.php` | BILL-PREVIEW-UNHIDE-1, TABLE-WORKSPACE-WIDTH-1, BILL-PREVIEW-WRONG-PRINT-1 | no (Edge serves its own page) | Online surface moved; Edge unchanged |
| `printing/documents/receipt.blade.php` | TABLE-BILL-PREVIEW-PARITY-1 (`@isset($tableBill)`) | yes for receipts; block dormant on Edge | receipts byte-identical; rounds/previously-paid presentation absent (D-25) |
| `DashboardController`, `dashboard.blade.php`, `routes/tenant.php`, `SalesReportService.php` | DASHBOARD-8DAY-1, SALES-ANALYTICS-1, ORDER-TYPE-PERCENT-1 | no | none |
| Catering files | CATERING-* | excluded from the artifact | none |

`git merge-tree` = 0 conflicts. Reconciling changes the source head → a new release build + signed update is needed to reach an appliance.

## G. Accepted ONLINE_REQUIRED exclusions (with the decision record)

| Exclusion | Decided / reported in | Accepted in |
|---|---|---|
| Card / provider payment; card, bank, provider refunds; card payment to a supplier | `edge-online-financial-parity-gap.md` (CARD_PARITY_STATUS=ONLINE_REQUIRED, "never fake an approval"), `edge-f1-sales-returns.md`, `edge-f2-supplier-finance.md` | F1/F2 (a5061ab, cfba4cc) |
| Sales older than the returnable warm-cache window; purchase return without a source receipt; receipts older than the window | `edge-f1-sales-returns.md`, `edge-f3-purchase-returns.md` | F1/F3 |
| Quick Report e-mail | parity register; `EdgeQuickReportController@email` truthful 422 | Q / cashier milestones (8 Sep) |
| Creating a NEW customer / new address at the till | parity register ("not inherent — next Edge build item") | Q — flagged as a build item, not permanent |
| USB printers (pilot) | `edge-p4-windows-appliance.md` USB_STATUS_FOR_PILOT, `config/edge.php` print_architecture | P4 (17b1333) |
| Cloud admin: scheduled reports, tenant backups, agent shelf; Reports Center; dashboards; Catering | parity register; `config/edge.php` excludes | P4/P5 |
| Customer credit / settlement | `edge-online-financial-parity-gap.md` "REQUIRES_CLOUD_CONNECTIVITY (likely) … later event" | never formally accepted (deferral) |

Not in this table and therefore **gaps, not exclusions**: variants/modifiers ("later milestone", `EdgeLocalPosController:101`), line
discounts (`EdgeLocalPosService:356`), bank/cheque/other tenders and refunds (Team C found Edge's "Cash only" narrower than the accepted
card-only exclusion), reminders, tips, table move/merge/request-bill, kitchen notes, barcode, shift history/close-branch, journal reverse,
purchase-return drafts, customer master pages, printer configuration/health (the last three need an owner ruling: admin configuration
during an outage vs operator workflow).

## 9. Audit of the old audit — why "zero normal POS gaps" was reported

| Cause (evidence) | What it hid | Missing regression gate |
|---|---|---|
| **Backend/API parity was what the proofs measured.** The 12 cashier HTTP suites contain 238 `postJson/getJson` calls and 92 `assertJson*` but only **5 `assertSee`** and a handful of `assertStringContainsString` on rendered HTML; `EdgeCashierScreenRendersHttpMySqlTest` asserts 12 strings — and two of them ("Review &amp; Pay", "Preview Bill") match Blade comment/JS source text, not a rendered button (E-15). No test executes JavaScript, and every Edge control is JS-rendered. | a page can pass every proof while missing whole modals | a **UI control census gate**: derive every Online `id=`/modal/action and assert the Edge page (or its documented equivalent) exposes each; fail on any unregistered absence; add a JS-executing browser proof for the FULL rows |
| **Rows came from a workflow list, not a screen inventory.** The register says so ("this matrix regroups to the owner's list"); it has no rows for modifiers, variants, tips, reminders, move/merge, kitchen notes, barcode, line discounts, request bill, reattach-table, completed orders, change-order, calculator, print preferences, shift history, close-branch, journal reverse, PR drafts. | everything outside the list was invisible | build the inventory automatically from routes + controllers + view element ids (this audit's §B) and diff it against the register on every reconcile |
| **Deferrals lived in code comments and validation strings.** `EdgeLocalPosController:101` ("variants/modifiers land in a later milestone"), `EdgeLocalPosService:356` ("Line discounts are not yet available"), `:126/:693` ("Order type … not yet available") | "not yet" items were treated as done-later without a tracked gap | grep gate: every `not yet available|later milestone|needs the Online POS` string must map to a register row with a non-FULL status |
| **Synthetic proof data never exercised the missing shapes.** No cashier proof creates `modifier_groups`/`modifiers`, sells a line with modifiers, or picks a non-default variant (only `EdgeLocalPosMySqlTest` touches variants at service level); the LAB tenant has 3 plain products; STEAK-SIDE-MODIFIER-1 shows a live client menu WITH modifiers and Kashif Food plans show reminders and 3-station routing in daily use. | production menu shapes were never on a proof or LAB screen | seed proofs and the LAB tenant from a read-only **production menu census** (modifier groups, variants, combos, reminder/category mappings, multi-station routing, tips/service charge, hide-amounts roles) |
| **Canonical drift after the matrix date.** The matrix is dated 8 Sep at ebaf8b2; since then HELD-SALE-DEAD-SESSION-1 (reconciled), the 14-Sep bill-preview family (assessed, not merged) and MANAGER-APPROVAL-COMBO-VOID-1 (Edge-executed, not merged) moved the Online spec; only drift *notes* were added. | the reference moved under the register | every reconcile re-runs the inventory diff and re-classifies touched rows before the head is accepted |
| **Installed package vs source** — not a cause: views/routes identical between 623f887 and 4affc67. | — | keep the artifact `diff -rq` check |
| **Passing suites became a completeness claim.** Status docs quoted "26 FULL / NORMAL_BRANCH_POS_GAPS = 0" straight from the register; the P5C LAB cashier screenshot was accepted as "POS page OK" without comparing it with the live screen. | green tests read as complete coverage | never publish a gap count without the inventory it was measured against ("X gaps against N inventoried controls, inventory hash …"); no percentage |

## J. Browser proof: observed vs inferred

- **Observed (owner screenshots, this session):** Edge login page (both laptops), `/edge/local/status` JSON, Edge POS main page on the
  cashier laptop (header POS · View Tables · Home Lab Branch · Lab Cashier · Dine In · Lab Counter 1 · Shift · Returns · Status · Synced ·
  Logout; All/Lab Menu pills; search box; 3 tiles; Walk-in / Customer (optional); empty cart; Items/Total; Hold · Draft · Recall · Preview
  Bill · Review & Pay · Quick Report · Recent Prints). The owner viewed the live Kashif Food POS and judged it substantially different and
  much larger; no Online screenshot reached this session.
- **Not captured:** paired Online/Edge screenshots at one viewport. No authorized read-only Online browser session exists in this Edge
  session (production forbidden; local dev-tenant credentials not held here) and no browser-automation harness exists in the worktree. All
  Online statements are from canonical source `243e01d`, labelled as such. Edge modals (View Tables, Review & Pay, Returns, Shift, Quick
  Report, Recent Prints), the finance pages and the health page were not opened in a browser in this pass; the LAB never punched or paid.
- Therefore no record carries paired screenshot proof; the MATCHED_AND_PROVEN rows rest on executable HTTP tests plus the LAB
  observations named in E-10/E-11/E-12.

## H. Proposed parity repair workstreams (no coding yet — for owner review)

Guard-rails for every workstream: Cloud/Edge business rules stay shared (reuse `SalesTotalsService`, `PrintRoutingService`, `ShiftService`,
`ManagerApprovalService`, `KotCancellationService`…); local authority + outbox exactly-once unchanged; F1/F2/F3 financial behaviour
unchanged; the restricted artifact boundary and dependency-closure gates stay green; the P5C LAB installation and evidence are preserved
(a new signed release updates it, never a reinstall); zero production mutation.

**W0 — Page decomposition (prerequisite for parallel UI work).** Split `edge/pos/index.blade.php` (994 lines, one JS blob) into partials
+ one JS module per modal so W1–W5 can edit without conflicts; add a control-census fixture. Files: `resources/views/edge/pos/**`,
`EdgeLocalPosController@screen` view-model. Tests: `EdgeCashierScreenRendersHttpMySqlTest` extended; new `EdgeCashierControlCensusTest`.
Sequential; ~small; unblocks everything else.

**W1 — Shell, navigation, states, responsiveness (UI-only, no sync impact).** Adopt the Online shell semantics (light Bootstrap-equivalent
theme shipped locally — Bootstrap 5.3.8 assets already exist in the artifact's `public/assets`), 500 px cart, tile/pill/button sizes,
breakpoints, SweetAlert-class dialogs, spinners, severity toasts, 401/419 redirect, keyboard shortcuts, calculator, deep links, shift
badge/poll, permission-gated buttons (returns/QR/shift/finance), header customer button, posContext-style terminal/branch panel. Files:
Edge views; view-model flags. Parallel with W2–W5 once W0 lands.

**W2 — Sell the real menu (server + UI + contract).** Variants picker/tiles, modifiers modal (groups, min/max, price delta, linked-product
stock consumption via the shared services), measurable qty entry, barcode/SKU search (synced `product_barcodes`), tile stock/unavailable
badges, child-category strip, per-line kitchen notes + direct qty edit, line discounts, tips, non-cash tenders (bank/cheque/other — owner
decision) with references, held-sale notes persistence. Server: `EdgeLocalPosService` line resolution/validation parity with
`SalesOrderController@validateSale`; `EdgeSaleEnvelopeBuilder` + Cloud `EdgeInboundSaleIngestionService` must carry modifiers/tips/line
discounts/notes (**contract change → W6**). Tests: HTTP proofs on a production-shaped menu. Depends on W6 for the envelope.

**W3 — Tables & order lifecycle.** Request bill, move, merge, per-table bill preview document + print, session detail, cancel session,
open-table notes, reservation details/customer attach, dead-session recovery, change-order details, completed/recent orders with
reprint/resume, cancel/clear/new-sale parity, void-reason UI for sent-line reduction, grouped combo void (needs the canonical reconcile in
W6), cancel-with-approval prompt. Server: new `edge.local.pos.*` routes/services on local state (held sales are local until settled, so
most need no new sync event; reservations already have handback). Depends on W0; on W6 only for the reconcile.

**W4 — Shifts, permissions, finance lists.** Enforce the Online route permissions server-side on Edge (`tenant.pos.index`, held-sales,
table sessions, split, shifts + terminal binding, manager verify, change-terminal pin, print-job scope, `UserDataScope` on returns),
mask amounts on `GET /shift`, denomination count + closing notes, CASH-SHORTAGE voucher (Cloud posting on sync → W6), shift history/
detail, Close Branch/Daily Closing (owner decision), returns list, Quick Report filters + saved selection, supplier/journal/PR lists,
journal reverse and PR drafts (owner decisions; new event types → W6), manifest `capabilities`. Depends on W0 (UI) and W6 (events).

**W5 — Kitchen printing.** KOT reminder jobs (mappings already synced): request path, confirm/reprint routes, print-worker handling;
line-void cancellation KOT + correction reminder; Direct-Pay KOT parity; bill-preview print here/network; dismiss; auto-print
preferences; print-job scoping; LAB proof with a 3-station mapping on the FakePrinter set (three listeners). Printer configuration/health
pages: owner ruling (admin vs operator). Depends on W0; independent of W6.

**W6 — Cloud/Edge contract, reconcile, release.** Reconcile canonical 243e01d (clean); extend the sale envelope + Cloud ingestion
(modifiers, tips, line discounts, kitchen notes, shift-close/shortage events, any new finance events) with exactly-once semantics and
ingestion tests; keep F1/F2/F3 envelopes unchanged; run artifact boundary + closure gates; build 0.7.0-edge from the accepted commit with the
custody keystore; signed update on the LAB appliance (this also proves the physical update path). Gates the contract-changing parts of
W2/W4; everything else can start first.

**W7 — Verification (runs alongside, blocks acceptance).** Control-census gate, production-shaped LAB seed, executable HTTP proofs per
row, and **paired browser screenshots on the second Windows cashier laptop** for every FULL row; register regenerated from the inventory
with denominators. Acceptance = browser workflows on the cashier laptop, not backend green alone.

**Parallelism:** W0 first (sequential, small). Then W1, W3, W5 in parallel (disjoint partials after W0); W2 and W4 can start their server
and UI work in parallel but their synced parts wait for W6's envelope contract; W6 runs in parallel from day one (reconcile + contract
design) and lands last as the release. **Owner decisions needed before W2/W4 start:** offline bank/cheque/other tenders and refunds;
Close Branch/Daily Closing offline; journal reversal offline; purchase-return drafts offline; customer master pages, printer
configuration/health and Reports Center as admin-only (ACCEPTED_ONLINE_REQUIRED) or operator (gap); whether Edge should mirror Online's
"no chrome" POS header.

## I. Regression strategy

1. **Inventory-driven register** generated from routes/controllers/view ids (checked-in JSON); a test fails when an inventory item has no row.
2. **Control census gate** on the rendered Edge page for every inventory item (or its truthful ONLINE_REQUIRED state) — plus a
   JS-executing browser proof (Playwright/Chrome headless) run on the LAB for FULL rows; `EdgeBranchServerRegistrationTest` already does
   this for URIs, the same must exist for controls.
3. **Deferral grep gate** (`not yet available|later milestone|needs the Online POS` → register row with non-FULL status).
4. **Production-shaped proof data** from a read-only menu census of the live tenants.
5. **Reconcile gate**: shipped-file delta → rows marked NEEDS_RECHECK until re-proven.
6. **Paired screenshots** from the second cashier laptop stored under the LAB evidence folder for every FULL row; otherwise the row stays
   FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.
7. **Publish counts with denominators**, never a bare zero or percentage.


## 11. Parallel implementation plan (NO coding yet — owner review required first)

Lanes derived from §H; each lane owns disjoint files after W0 so independent implementers can work without a shared worktree.

| Lane | Workstreams | Owns (after W0) | Waits on | Proof of done |
|---|---|---|---|---|
| L0 (sequential, first) | W0 page decomposition + control-census fixture | `resources/views/edge/pos/**`, `EdgeLocalPosController@screen` | — | census test lists every Online control id with an Edge state |
| L1 | W1 shell / navigation / states / responsiveness | Edge Blade partials + inline CSS/JS modules, view-model flags | L0 | paired screenshots at 1366×768 and tablet on the cashier laptop |
| L2 | W2 sell the real menu (UI + `EdgeLocalPosService` validation parity) | menu partials, `EdgeLocalPosService` line resolution, `EdgeLocalPosController@sale` | L0; L6 for envelope fields | HTTP proofs on a production-shaped menu; browser sale with modifier + variant + weighted line on the LAB |
| L3 | W3 tables & order lifecycle | `EdgeLocalRestaurantController`, `EdgeLocalHeldSalesController`, table/held partials | L0; L6 only for the combo-void reconcile | request-bill / move / merge / dead-session recovery run from the cashier laptop against the FakePrinter |
| L4 | W4 shifts, permissions, finance lists | `EdgeLocalShiftController`, `EdgeLocalReturnsController`, `EdgeLocalQuickReportController`, finance controllers/views, `edge-build-manifest.json` capabilities | L0; L6 for new events | permission matrix test (Online route names × Edge endpoints) green; denomination close proven |
| L5 | W5 kitchen printing | `EdgeLocalPrintJobsController`, print worker, KOT/reminder services | L0 | 3-station FakePrinter routing + reminder + line-void cancellation KOT observed in LAB printer logs |
| L6 | W6 contract, canonical reconcile, release | `EdgeSaleEnvelopeBuilder`, Cloud `EdgeInboundSaleIngestionService`, reconcile merge, release build | — (starts day one) | ingestion tests exactly-once; artifact boundary + closure gates; 0.7.0-edge signed update applied on the LAB appliance |
| L7 (continuous) | W7 verification | `tests/MySql/EdgeCashier*`, LAB seed, evidence folder | every lane | register regenerated from the inventory with denominators; browser workflows on the SECOND Windows cashier laptop |

Invariants every lane must keep: Cloud and Edge share the same rule services (no Edge-only business rules); the branch-authority lease,
outbox exactly-once and `AUTO_FAILOVER_ENABLED=no` are untouched; F1/F2/F3 envelopes and Cloud ingestion behaviour unchanged unless L6
extends them with tests; the restricted artifact boundary stays green; the P5C LAB installation is updated by a signed release, never
reinstalled; zero production/live-tenant mutation; no P6 (WAN-unplug business test) until the owner approves it separately.

Owner decisions required before L2/L4 start (all others can begin after review of this report): offline bank/cheque/other tenders and
refunds; Close Branch / Daily Closing offline; journal reversal offline; purchase-return drafts offline; customer master pages,
printer configuration/health and Reports Center as ACCEPTED_ONLINE_REQUIRED (admin) or operator gaps; Edge header chrome vs Online's
chrome-less POS.

## Stop state

```
YELLOW_CABLE=connected   WAN_TEST=paused   LOCAL_MODE=inactive   LIVE_TENANT_MUTATIONS=none   P6=not_started
CODE_CHANGED=no   LAB_APPLIANCE=unchanged (STANDBY_READY)   RECORDS=149 across 5 teams   MATCHED_AND_PROVEN≈9   FUNCTIONAL_BUT_UI_DIFFERENT≈60
MISSING_IN_EDGE≈35   PARTIALLY_IMPLEMENTED≈22   NOT_VERIFIED/NOT_BROWSER_PROVEN≈8   ACCEPTED_ONLINE_REQUIRED≈13   CANONICAL_DRIFT=2   SOURCE_NOT_INSTALLED=1
```
STOP — audit complete; implementation plan awaits owner review.
