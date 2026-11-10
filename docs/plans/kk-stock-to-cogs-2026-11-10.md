# KK-STOCK-TO-COGS-1 + PNL-DETAIL-STOCK-EXPENSE-1 — Kashif Kitchen bills out of 1400 into Cost of Sales (10 Nov 2026)

Source: the business developer's notes (Claude docs) `YRp9CTLhaqLQSsNfM2Fq6Q` (KK-STOCK-TO-COGS-1) and `HNSGUrrfbcqE5WjBxXS5G5`
Part C. Owner, 10 Nov: "now start this". Client decision (9 Nov): no Kashif Kitchen item counts stock; past bills move to
their cost head on their own bill dates (same job as Khatri, 21/22 Oct and 9 Nov).

## Code: PNL-DETAIL-STOCK-EXPENSE-1 (the only code)

`inventory:expense-purchase-stock` posts `grn_line_stock_expense` journals (source = the GRN line), Dr category cost head /
Cr 1400. `TrialBalancePartyService` did not know that source:

- On a head that also has bills, the moved money showed as **"Other"**. Khatri 1–9 Nov: 5100-1 showed
  "Shahbaz cold drink 66,660 · Other 15,150".
- A head whose only bill-side lines are these moves was not split at all, because `$expenseTyped` looks only at
  BILL_SOURCES. Kashif Kitchen's run would have shown about 90 lakh with no supplier.

Fix: `grn_line_stock_expense` (+ `_reversal`) in `BILL_SOURCES`, plus one batched lookup: goods_receipt_lines → goods_receipts
→ supplier_id. Read side only; no table written.

Test `InventoryExpensePurchaseStockMySqlTest::test_the_pnl_detail_names_the_supplier_of_the_moved_cost`. September has a
head with only the move. October has the move next to another supplier's non-stock bill on the same head.

- Fixed: Sep `Kitchen 1,000`; Oct `Kitchen 600 · Kitchen Two 300`.
- Sabotage 1 (whole fix out): RED.
- Sabotage 2 (lookup off): RED with exactly the live symptom, Sep `[]` and Oct `Kitchen Two 300 · Other 600`.

## Data (kashifkitchen only), in order

1. Laptop backup (gzip over ssh, sha256, then the prod copy deleted).
2. **Voucher lines moved in place.** These are the client's OK'd rows only. The line keeps the account its category had
   when it was saved, and the categories were re-linked later. Per voucher, in one transaction: `expense_voucher_lines.account_id`
   and the paired debit `journal_lines.account_id`. The pair is matched by id order and checked by amount and account; a line
   with no account posted to 6800. Entry date, amounts, the credit line and cash are untouched.

   | category | lines | amount | from → to |
   |---|---|---|---|
   | #14 5200-5 Vegetables/Fruits | 5 (4 vouchers, 1–7 Oct) | 23,200 | 6810 → 5200 |
   | #53 6000-17 Beverages | 2 (15, 18 Oct) | 4,842 | 6000 → 5000-24 |
   | #54 5000-18 Koila | 1 (15 Oct) | 400 | 6000 → 5000-80 |

   Waiting for the client's answer, not touched: LPG 27,010 (→ 5000-6?), staff tea 4,550 (→ 6200?), petrol 2,000 (→ 6830?).
3. **Stock to cost:** `inventory:expense-purchase-stock kashifkitchen --all-purchased --yes`. Dry run 10 Nov: 26 products ready,
   39 skipped, 1400 10,242,624.13 → 945,945.00, tb_diff 0. The note's figure plus 2,23,300 of beef bills entered since.
4. **Stock counting off on the never-bought products:** 1,842 tracked products with no receipt, no stock ledger row, no
   non-zero balance and no batch. They get `is_stock_tracked = 0` and `stock_item → none`, the command's own rule. Otherwise
   their first purchase lands in 1400 again.

## Not done here (needs the client)

- 39 skipped products. 33 have no cost head on their category (Catalog → Categories → "Cost of Sales account"). 5 sit on
  4 unbilled Haji Mehfooz GRNs (#88–#91). 5 have purchase returns.
- **Vegetable Mix #1316** (2,30,100). Its category CURRIES has no head and also holds bought dishes. It is POS-visible and is
  used by 1 catering estimate line, a cost block and a catering profile, so its category is not moved without the client.
  No category points at 5000-7 yet.
- **Purchase returns** (AGRA SHAHE, CHARGES %, MAKHAN 1KG, Roti Milky (KG), TANDOORI BAKRA). Each return credited 1400 at
  the return value (Dr 2100 / Cr 1400, JE#1105, #1210, #1287). Three of them also have no cost head. Second run after the
  client's homework.
- New products: the v2 form's "count stock?" still opens at Yes for ingredient, packing and item cards. The owner's rule
  (9 Nov) is no switch: the client unticks it. A slip is repaired with the same command.

## Impact (system map)

- **Code:** `app/Services/Finance/TrialBalancePartyService.php` (read). Screens: P&L and P&L detail, Trial Balance party
  detail, their PDFs and CSVs, all tenants. Only grn_line_stock_expense money changes label, from "Other" or no detail to
  the supplier. Totals unchanged. Today that is Khatri (5100-1 15,150, 5100-4 13,440) and, after the run, Kashif Kitchen.
- **Data:** `journal_entries`/`journal_lines` (new grn_line_stock_expense entries; 8 voucher debit lines re-headed),
  `expense_voucher_lines` (8 rows), `stock_ledgers`/`stock_balances`/`inventory_batches` (write-out `expensed_to_cost`),
  `products.is_stock_tracked` (26 + 1,842). Screens whose numbers change: P&L, TB, GL, Balance Sheet (1400), stock reports.
- **Tests:** `InventoryExpensePurchaseStockMySqlTest` (11), `ProfitLossDetailMySqlTest`, `PurchaseRevisionMySqlTest`,
  `TrialBalanceParty/Period/SectionHeadsMySqlTest`, `GrnNonStockMySqlTest`, `PayablesFinanceGateMySqlTest`.
- After the run, a bill on the 26 products cannot be edited or voided in place. Kashif Kitchen has `purchase_edit` off, so a
  billed receipt could not be edited or voided there before either.
