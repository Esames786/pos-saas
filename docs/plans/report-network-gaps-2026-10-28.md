# REPORT-NETWORK-ITEMS-DEALS-1 + REPORT-NETWORK-GAPS-1 — the network report slip prints what the browser prints

Date: 28 Oct 2026. Follows REPORT-NETWORK-BRIDGE-1 (`docs/plans/report-network-bridge-2026-10-28.md`, live `bc9c973c`).

## Why

The Sales Report reaches paper down two roads: the browser print (`resources/views/tenant/reports/center/print.blade.php`)
and "Send to Network" (`EscPosPayloadService::buildReport()`, called by `SalesReportCenterController::sendToNetwork`
and `PosQuickReportController::sendToNetwork`). The owner asked for the network slip to match the browser print, and to
see both printed on live data before anything is deployed.

Live examples (prod, read only, nothing printed) were shown first: https://claude.ai/artifact/SqAz7mgrX7T85gfAWixTLH

| # | On the network slip | Live evidence |
|---|---|---|
| 1 | Big totals ran into the next column: `0133,480` (Ret 0 + Net 133,480), `403176,397` (Ret 403 + Net 176,397) | Real Dine In jobs on 25, 26 and 27 Oct |
| 2 | Deals ticked → heading only; Items carried the deals (the browser leaves them out) | Nobody had ticked Deals on the network in 30 days |
| 2b | Since `bc9c973c`: Items bridge printed `Plus Deals 119,425` and `Plus Other Charges -119,425` on a tenant that sells deals | Rebuilt from a live day; NET SALES still right |
| 3 | By Order Type ticked → heading only | Nobody had ticked it on the network in 30 days |

## What changed

- **2b** (`568aca5c`): the Items bridge names the deals only when the list it closes leaves them out
  (`$itemsHoldDeals`). Safe on its own; nothing else on the slip moves.
- **1**: column widths are measured from the CATEGORIES grand total too, and from the money-only sections
  (Order Types, Waiters) and By Order Type. A slip whose totals were not wider prints byte for byte as before.
- **2**: Report Center sends Items without the deals (`byItem($filters, 'net', true)`, as the browser and the
  POS Quick Report already did) and sends the Deals rows. **Owner's call before deploy**: a tenant that sells deals
  sees its deal entries leave the Items slip and a `Plus Deals` line under the Items total; the deals print in the
  Deals section when that is ticked.
- **3**: a BY ORDER TYPE layout on the slip, in the browser's order (every type's categories, then items, then
  waiters), each categories/items TOTAL closed on that type's NET SALES (the browser's `$otBridge`).
- POS Quick Report's network print now also gets Items by Category, Deals and By Order Type (it loaded them for the
  browser but never handed them to the slip).
- Blade untouched. No migration, no route, no permission.

## Impact (system map)

- Screens: Report Center → Send to Network; POS → Quick Report → Send to Network. Browser print / PDF / email unchanged.
- Tables: read only (`sales_orders`, `sales_order_lines`, `combos`, `categories`, …); writes only the existing `print_jobs` row.
- Tests: `tests/Unit/Printing/EscPosReportBridgeTest.php`, `tests/Unit/Printing/EscPosReportGapsTest.php` (new),
  `tests/MySql/ReportNetworkBridgeMySqlTest.php`, `tests/MySql/PosQuickReportMySqlTest.php`.

## Proof

Page: https://claude.ai/artifact/MYWerGeZLMgsbSqPpz9tbV — for each fix, the browser print of a live day beside the
bytes a test printer received from the print agent's own `sendToNetworkPrinter` (lifted from `print-agent.js`),
plus the line diff against the live code's slip.

- 1a/1b: `133,480 | 0 | 133,480`, `176,800 | 403 | 176,397`; with spaces removed all 129 lines equal the live slip.
- 2: closing lines equal the browser's (119,425 / 7,350 / -1,417 / 586,618); Deals section 22 deals, 119,425.
- 3: 12 headings for the four order types; every type's NET SALES equals the browser's.
- Five daily slips with nothing wrong (Categories, Items by Category, Overview, Partner Share) are byte-identical.
- Every line ≤ 42 characters (80mm) / 32 (58mm).

Tests: unit 139 (3 red, the same 3 red on the prod-based tree: catering sidebar, `EscPosReportPayloadTest`
"4,950.00", rider reassignment); MySQL 15 report files, 124 tests. New tests fail on the old code
(unit 3 of 4; the MySQL Items-deals test with exactly `Plus Deals 500 / Plus Other Charges -500`).

## Left alone

- The "Detailed" section has no network layout (one tenant ticked it once in 30 days).
