# Khatri: Trial Balance by heads + stuck inventory to the P&L (21 Oct 2026)

Two client requests from khatribiryani, built on `feat/khatri-tb-stock-pnl-20261021` (off production `70092751`).
Specs: TB-SECTION-HEADS-1 and KHATRI-STOCK-TO-PNL-1 (Claude docs shared by the business developer).

## 1. TB-SECTION-HEADS-1 — Trial Balance grouped by heads

Display only, every tenant. Same numbers, same grand totals, same difference.

- Sections: Assets, Liabilities, Equity, Revenue, Cost of Sales, Operating Expenses (an unknown type → Other).
- Inside a section the chart's tree: a parent is a bold head = its own postings + every shown descendant, shown even
  with no postings of its own; its own postings as "<name> (direct)"; a subtotal per section; grand totals unchanged.
- Bad tree (self-parent, parent under another head, loop, deeper than 10) → that account is a top-level row.
- One place: `TrialBalanceLayout` for screen, print and CSV. "Group by heads" on by default; `group=0` = the flat list
  and the old 7-column CSV, unchanged. Grouped CSV adds Section / Row / Level; a SUM over Row = Account is the total.
- Cost of Sales rule extracted once: `AccountSections::isCostOfSales()` (expense, code starting "5"), used by the P&L,
  the branch P&L and the TB.

**Impact (system map):** reads `accounts` (incl. `parent_id`), `journal_entries`, `journal_lines`; screens Trial
Balance (screen, print, CSV); P&L and branch P&L unchanged in output. No table written, no migration, no route, no
permission. Tests: `TrialBalanceSectionHeadsMySqlTest` (8), `TrialBalancePeriodMySqlTest` and `TrialBalancePartyMySqlTest`
(flat CSV path now passes `group=0`).

## 2. KHATRI-STOCK-TO-PNL-1 — bought-and-used items become purchase-only

`php artisan inventory:expense-purchase-stock {tenant} --products=…|--all-purchased [--except=…] [--yes]`.
Dry run by default; `--yes` applies, one transaction per product:

1. GL: for every posted bill line of the product, its share of what the bill put into 1400 (postPurchaseBill's own
   value split) → `Dr <category cost head> / Cr 1400`, **dated the bill date**; source `grn_line_stock_expense` + GRN
   line id, so a line is moved at most once. The 14 `adjustment_out` rows need no journal of their own: their value is
   inside the purchases being moved.
2. Stock: what is left on hand is written out through `InventoryService::postOutFefo`, movement `expensed_to_cost`
   (new enum value, migration `2026_10_21_000010`), at average cost. No row edited or deleted.
3. Flag: `is_stock_tracked = 0`; consumption `stock_item` → `none` (as the product form does). Later GRNs carry
   `affects_stock = false` and bills post straight to the cost head.

Refused, never guessed: category maps to no cost head (the 5100 fallback is NOT used — a person maps it on screen);
a receipt not billed yet (its bill would post 1400 again); stock moved other than purchase / adjustment in / out;
stock below zero; bills' 1400 share vs receipts' stock value differ by more than 1 rupee.

D4: the old product form now refuses unticking "Track Stock" while stock is on hand (the v2 screen already did).

**Impact (system map):** writes `journal_entries`, `journal_lines`, `stock_ledgers`, `stock_balances`, `products` — only
when the command is run with `--yes`. Reads `goods_receipts`, `goods_receipt_lines`, `purchase_bills`, `categories`,
`accounts`. Screens: P&L, Trial Balance, stock movements (new filter label), stock reports, old product form (guard).
Migration: adds one enum value at the end of `stock_ledgers.movement_type` (metadata only on MySQL 8).
Tests: `InventoryExpensePurchaseStockMySqlTest` (9).

## On production — only with the owner's approval

The owner's standing rule (21 Oct) is no repair or backfill of old data. This command is exactly that, so:

1. D1 on screen with the client's accountant: give BEEF CHUNGAIZI PULAOW, BIRYANI MASALA CHICKEN AALOO, SINGAPORIAN
   RICE and CHICKEN a category mapped to a cost head; move BEEF out of "Sauce". The command refuses them until then.
2. Packaging decision (Containers, Spoon, Foil, Cleaning ≈ 4.7 lakh): option A all expensed (default); option B keep
   them stock-tracked with `--except=…` (counting them into the GL needs a separate feature).
3. Back up Khatri's tenant DB. Run the dry run as www-data, send its output to the owner month by month.
4. Only after an explicit OK: `--yes`. Smoke: `tb_diff=0`, 1400 for these products = 0, P&L Sep / Oct show the heads.

Out of scope: count-to-GL for tenants that keep stock; sale-side COGS by category; Khatri's "Short Cash" drafts.
