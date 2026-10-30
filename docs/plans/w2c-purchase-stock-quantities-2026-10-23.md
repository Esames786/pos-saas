# W2-C — one quantity rule for purchasing and stock (plan, 23 Oct 2026)

Branch `feat/w2c-purchase-stock-qty-20261023`, off the closed integration candidate `d29487e2`. Nothing is built yet.
This is the next slice of the unified-business roadmap. It brings to purchasing and stock the rules the counter already
uses (W2-B, `App\Support\LineQuantity`), so a quantity the counter refuses can no longer come in through a GRN or a
stock adjustment.

## What users gain

- A kg, litre or metre item takes up to 3 decimals everywhere: 2.5 kg, 0.125 kg. A typed 4th decimal (0.1255 kg) is
  refused with a plain message naming the item, instead of being rounded without telling anyone.
- A supplier return says at once, while it is still a draft, how much can still go back: "only 4 Box can still be
  returned, not 6". Today that check runs only when the return is posted.
- Where the business turns it on, a counted item (piece, bottle, box) is taken in, moved and counted in whole numbers
  only, the same as at the counter.
- The value of every stock movement is the stored quantity × its unit cost. Today a movement's cost is worked out from
  the unrounded quantity.
- The old PO screen's total is the sum of its lines. Today it is worked out from the unrounded quantity.

## The rules

1. **Exact once, 3 decimals.** A typed quantity is made exact to 3 decimals once. A real 4th decimal is refused;
   floating-point noise is cleaned. This is `LineQuantity::normalize`, already used by the counter.
2. **Counted items whole** — only where the tenant's switch is on (see "Why a switch"). Counted = the unit is not
   weight, volume or length (`StandardUnits::allowsParts`, the same test as the counter and the POS screen).
3. **Packs.** A pack quantity follows the item's rule. The base quantity (pack × factor) must itself be exact to 3
   decimals. A counted item whose pack size has a fraction (12.5 per pack) must still come to a whole number.
4. **Returns never exceed what is left**, checked when the draft is saved and again when it is posted. Messages follow
   the counter's: "only N {unit} can still be returned, not M", "nothing is left to return".
5. **Money from the exact quantity.** Line totals, the PO header total and cost per base unit are worked out from
   the exact quantity, never from the raw one.
6. **Old records stay as they are.** Nothing is recalculated, relabelled or converted. Khatri's items keep their units.

## Where

| Entry point | Today (code survey, 23 Oct) | After |
|---|---|---|
| PO, old screen (`PurchaseOrderController` store) | `min:0.001`; header total from the raw quantity | rules 1–3, 5 |
| GRN, old screen (`GoodsReceiptController` store) | `min:0.001`; counted fractions accepted | rules 1–3 |
| PO / GRN v2 (`PackLines`) | whole packs for counted items; part items rounded silently; cost from the unrounded base | rules 1–3, 5 |
| Supplier return, old screen + update path (`PurchaseReturnController`) | `min:0`; no cap until post | rules 1–5 |
| Supplier return v2 (`ReturnLines`) | batch cap on the unrounded base; GRN-line cap only at post | rules 1–5 |
| Stock adjustment / opening stock (`StockAdjustmentController`) | `min:0.001`, raw into stock | rules 1–2 |
| Stock transfer (`StockTransferController`) | `min:0.001`, raw into stock | rules 1–2 |
| Stock count line (`StockCountController::updateLine`) | rounded to 3 silently | rules 1–2 |

**The central change:** `InventoryService` makes the quantity exact once, inside the service.
- `postMovement` rounds the quantity to 3 decimals and works out `total_cost` and the new average cost from that same
  number.
- `postOutFefo` never writes a leg of 0.000. Its shortfall tolerance is half a thousandth, the same as the whole-number
  test.
- Derived quantities (recipes and modifiers at 4 decimals × plates, manufacturing at 18,4) are **rounded, never
  refused**: four decimals are legitimate in setup.

This rounding is what the database already does to the quantity. The only change is that cost now follows the same
number.

## Why a switch for "counted items whole"

Production census, read-only, 23 Oct 2026, every active tenant (lines with a part quantity on a counted item / all
lines):

| Tenant | PO | GRN | Supplier return | Adjustment | Transfer | Count |
|---|---|---|---|---|---|---|
| khatribiryani | 0/0 | **29/385** — Singaporian Rice (EA) ×11, Khatri Biryani Beef (EA) ×8, Cream Cocktail (EA) ×3 … | 0/0 | 0/0 | 0/0 | 0/0 |
| every other tenant | 0 | 0 | 0 | 0 | 0 | 0 |

Khatri buys some items by the "each" in parts. The units-and-packs decision is that their units stay. Turning the
whole-number rule on everywhere would stop Khatri's GRN entry tomorrow. So:

- **Rules 1, 3–5 and the central change apply to every tenant.** No live line breaks them: the columns already hold 3
  decimals, and the census found no stock-ledger row whose cost differs from quantity × unit cost.
- **Rule 2 sits behind a per-tenant Master switch,** "Whole numbers for counted items in purchasing and stock". It is
  OFF by default and stays OFF for khatribiryani until its units are sorted out (owner's decision). Where it is off, a
  counted fraction is still made exact to 3 decimals.

Also from the census: 0 stock-ledger rows with quantity 0, and 0 rows whose `total_cost` ≠ quantity × unit cost (gap
over 0.00005).

## Impact (system map, 23 Oct)

| Screen (route) | Tables it writes |
|---|---|
| `tenant.purchase-orders.store` | purchase_orders, purchase_order_lines |
| `tenant.goods-receipts.store` | goods_receipts, goods_receipt_lines, stock_ledgers, stock_balances, inventory_batches, purchase_bills, supplier_ledgers |
| `tenant.purchase-returns.store` / `.update` / `.post` | purchase_returns, purchase_return_lines, stock_ledgers, stock_balances, supplier_credit_allocations |
| `tenant.stock-adjustments.store` | stock_adjustments, stock_adjustment_lines, stock_ledgers, stock_balances, inventory_batches |
| `tenant.stock-transfers.store` | stock_transfers, stock_transfer_lines, stock_ledgers, stock_balances |
| `tenant.stock-counts.lines.update` / `.post` | stock_count_lines, stock_adjustments, stock_adjustment_lines, stock_ledgers, stock_balances |

- **Every other caller of `InventoryService`** gets the central change only, with no new refusal: sales, returns, recipe
  consumption, kitchen production and wastage, manufacturing, catering issues, department stock, and the stock-to-P&L
  command.
- **No schema change.** One new key in `tenants.features` (the switch).
- **Screens, client side:**
  - the old PO / GRN lines partial (`purchasing/partials/product-lines`, `step="0.001"` for every item today) reads
    `allow_decimal` for its step, as the return, adjustment and transfer screens already do;
  - the v2 entry area refuses a 4th decimal before Add, as it already refuses a fraction on a counted item.

## Tests

New `PurchaseStockQuantityMySqlTest`, through the real routes, one test per entry point:
- accepted: 2.5 kg, 0.125 kg, 3 boxes of 12;
- refused, with nothing written: 0.1255 kg; a part of a counted item with the switch on; a 12.5 pack that does not come
  to a whole number;
- a counted fraction with the switch off is accepted and made exact;
- a return over what is left is refused at draft save, and the message names the unit and what is left;
- the ledger row's `total_cost` = stored quantity × unit cost, for a derived 4-decimal recipe consumption too;
- the PO header total = the sum of its lines.

These are the **first tests ever** for stock adjustment, transfer and count.

- **Sabotage:** one per rule, each turns the tests red.
- **Re-run:** PurchasingV2, Grn*, PurchaseReturn*, SupplierReturnMatrix, ReturnRestock, PackUnits, UnitsAndPacksJourney,
  W2Combined, the recipe, kitchen and manufacturing tests; then the full MySQL and default suites on a frozen detached
  worktree.

## Out of this slice (next, in this order)

1. Typed quantities on catering estimate and issue, department transfer and count, kitchen wastage and production,
   and manufacturing screens. They get the central change now; the typed-entry rules come next.
2. Combo components worked out on the server (the counter's residual gap).
3. Decimal packs (a 2.5 kg bag), gram entry against kg stock, pack scanning on the normal POS, reports that tell packs,
   base quantities and units apart.

**Gates:** push, release merge, deploy, the switch per tenant: each one is its own approval. No reset and no
tenant-data correction.
