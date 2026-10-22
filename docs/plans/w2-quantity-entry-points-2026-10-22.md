# W2 quantity rules — where they apply and where they do not yet (22 Oct 2026)

The W2-B rules (`App\Support\LineQuantity`) are: a quantity is made exact to 3 decimals once, a real fourth decimal
is refused, counted items are whole numbers, and returns never exceed what is left. **They apply on these paths only:**

- POS paid sale (`SalesOrderController::validateSale`);
- hold, including add-round, table orders and edits of a held order (`HeldSaleController::store`);
- split bill (`SplitBillController::store`);
- bill-linked customer return (`SalesReturnService::processReturn`).

The no-bill return keeps its own older rule: it rounds to 3 decimals silently and requires counted items to be whole.

**This is not "one quantity rule everywhere".** The read-only map of every other entry point follows. Lines come
from the code map in `pos-saas-w2b`; nothing below is changed by this candidate.

## Not covered yet — need the same checks before a wider rollout

| Entry point | Today | Moves |
|---|---|---|
| Catering estimate lines (`CateringEstimateService:127`) | 4th decimal rounded silently; fractions allowed | money |
| Catering direct store issue (`CateringMaterialIssueService:258`) | rounded silently; fractions on counted items | stock |
| Purchase order, old screen (`PurchaseOrderController:83`) | header total uses the raw quantity, line stored rounded | commitment |
| PO / GRN v2 (`PackLines:178,192`) | whole packs enforced; part units rounded silently | stock, money |
| GRN, old screen (`GoodsReceiptController:144`) | DB-rounded; fractions on counted items | stock, money |
| Supplier return, old screen and the update path (`PurchaseReturnController:231-236,301`) | fractions on counted items | stock, supplier money |
| Supplier return v2 (`ReturnLines:113`) | whole packs; part units rounded silently | stock, supplier money |
| Stock adjustment / opening stock (`StockAdjustmentController:60,91`) | fractions on counted items | stock, value |
| Stock count (`StockCountController:158`) | rounded silently | stock |
| Stock transfer (`StockTransferController:55,86,96`) | fractions on counted items | stock |
| Department transfer / count (`DepartmentStockTransferController:205`, `DepartmentCountService:124`) | fractions; raw | department stock |
| Kitchen wastage / production (`KitchenWastageController:54`, `KitchenProductionController:48,78`) | **4 decimals stored** (12,4) while stock keeps 3 | stock |
| Manufacturing consumption / finished goods (`ManufacturingConsumptionController:237`, `FinishedGoodReceiptController:226`) | 4 decimals | stock, value |

**Central gap.** `InventoryService::postMovement` never rounds. It stores `total_cost = quantity × unit cost` from the
raw quantity, while the ledger quantity and balance keep 3 decimals. A caller passing more than 3 decimals leaves cost
and quantity disagreeing. Making the quantity exact once there would give every stock path the precision rule. The
whole-number rule would still be per screen. This is the recommended next step for the rules.

**Residual gap on the POS.** Combo component and modifier lines are skipped by `prepareSaleLines`. The server trusts
the component quantity and price the client sends (`SalesOrderController:234`). Working component quantities out on
the server from `combo_components` would close it.

## Covered or not applicable

- **Website checkout** — `integer|min:1|max:50`. Stock and money move when the cashier settles on `/pos`, where the
  rules apply. Side note: kg items can only be ordered online in whole kg.
- **KOT cancellations** — the cancelled quantity is "sent − new". The new quantity is already made exact, so the
  cancellation follows from it.
- **Purchase bill** — inherits the GRN lines.
- **Catering material issue from a release** — derived from requirements.
- **Setup quantities** — recipe ingredients (12,4), modifier linked quantity (18,4), BOM, requisition, WIP. Four
  decimals are legitimate in setup; what they consume goes through `postMovement` (central gap above).
- **Combo components (setup)** — a counted fraction can be real (¼ chicken). Owner's decision; refuse more than 3
  decimals only.
- **Edge local POS** — production-disabled. **API** — no `routes/api.php`; no tenant `/api/*` route writes quantities.
- **Bulk import** — no stock quantity; opening stock goes through stock adjustment.

## POS screen (browser) behaviour

- **Counted vs parts.** The screen decides with `allow_decimal_qty` (= `unit_type !== 'quantity'`), the same set as
  the server.
- **Cart quantity box.** It is `type=number` with step and min 1 for counted items. A typed 1.5 is rounded by the
  screen to 2 (`Math.round`), so a part of a counted item never reaches the server from the normal cart. 0.4 becomes 0
  and goes to the remove / KOT-cancel flow.
- **When the server refuses.**
  - Pay: an alert "Cannot complete sale" shows the server's English message. The cart and the payment window stay.
  - Hold: a toast shows the message and the cart stays.
  - Only the first message is shown, not one per line.
- **Legacy held lines.** A recalled held line keeps its stored quantity but shows it rounded. A legacy 0.999 counted
  line would show "1" and then be refused on hold or pay. Production census: no such held line exists on a live
  tenant.
