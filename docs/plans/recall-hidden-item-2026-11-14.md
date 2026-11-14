# RECALL-HIDDEN-ITEM-1 — a website order comes back to the POS with every item (14 Nov 2026)

## What happened

A Kashif Food website order, HS-20261114170043-892, was made up of:

| Item | Amount |
|---|---|
| Singaporean Rice (Khass) | 2,900 |
| Chicken Red Sauce Pasta | 1,250 |
| Delivery | 350 |
| **Total** | **4,500** |

When the order was recalled on the POS, the cart showed only the pasta and a total of 1,600. The order in the
database was intact and was checked read-only on prod: held, 2 lines, 4,500.

The cause:

- **The rice is hidden from the POS.** Product #161 has `is_pos_visible = 0`.
- **The POS loads its items once, when the page opens.** It carries:
  - the visible items;
  - combo parts;
  - items on bills that were open at that moment (HIDDEN-PRODUCT-HELD-BILL-1).
- **A website order arrives later.** So the rice was not on the page.
- **Recall drops a missing item silently.** `recallHeldSale` did `if (!product) return;`, so the line was left out
  without a word.
- **F5 fixed it,** because a fresh page counts the bill as open.

Safety at Pay held:

- Both lines had already been sent to the kitchen, so Hold/Pay would have refused ("a reason is required").
- A bill or preview printed from that cart would still have said 1,600.

## Owner's decision

The owner chose option 3 plus a safety guard ("best option pe development kardo"):

- Keep the hidden items on the page, but do not show them.
- Never open half a bill.

## The change

1. **`POSController@index`.** A second list, `$recallOnlyProducts`, holds every active, sellable item hidden from the
   POS that is not already in the POS list. It is built by the same payload builder, so it carries the same fields.
   - It reaches the page as `const recallProducts`.
   - The main `products` list is not changed at all, so the grid, search, barcode / pack scan, Quick Report
     picker, medical counter and combos see exactly what they saw before. A hidden item still cannot be sold from
     the counter.
2. **`recallHeldSale` and the page-load recall (`?held_sale_id=`).** Both look up an item in `products` first and
   then in `recallProducts` (`productForHeldLine`).
3. **The guard (`heldBillMissingItems`).** If an item is in neither list (switched off, not for sale, or deleted):
   - the bill is **not** opened and the cart is left as it was;
   - a message names the missing items in English plus one Roman Urdu line;
   - the page-load path then goes to a fresh `/pos`.
   - A line with no product at all keeps today's behaviour. Prod has 0 such lines on open bills and 0 in 90 days.

No Master switch, no migration, no new route or permission.

## Impact (system map)

- **Tables:** `products` (read), `sales_orders` / `sales_order_lines` (read). No writes.
- **Screens:**
  - `tenant.pos.index` (`POSController@index` + `tenant/pos/index.blade.php`).
  - Every recall path goes through `recallHeldSale`: the Held Orders list, the website orders window, the
    medical bill-code scan, and the page-load recall.
- **Unchanged:** `tenant.api.pos.held-sales`, Hold / Pay (`HeldSaleController@store`, `SalesOrderController@store`).
- **Page size:** active, sellable, hidden items that are not combo parts, per tenant on prod (14 Nov, read-only):

  | Tenant | Items |
  |---|---|
  | Kashif Food | 4 |
  | finance demo | 3 |
  | demo | 1 |
  | every other tenant | 0 |

- **Tests:**
  - new `tests/MySql/RecallHiddenItemMySqlTest.php`;
  - re-run every MySQL test that loads the POS screen (list in Results).

## Results (code commit `69536d3a`)

Every run used the `_rhi` database set (`pos_test_master_rhi` / `pos_test_tenant_rhi` / `pos_test_edge_local_rhi`).

- **New test:** `RecallHiddenItemMySqlTest` passed 7 tests with 75 assertions. The recall tests:
  - render `/pos` over HTTP first;
  - save the website order AFTER the page loads;
  - read it back through `/api/pos/held-sales`;
  - run the page's own `recallHeldSale()` in node, using the page's own two lists.
- **Related MySQL tests:** 19 files, 129 tests, 1,672 assertions, exit 0. These are every MySQL test that loads the
  POS screen:
  - RecallHiddenItem
  - BillPreviewPrintTarget
  - CategoryBranchScope
  - CategoryParentGuard
  - CounterPacks
  - DealSplitReport
  - GramEntry
  - MedicalCounter
  - MoveTableCart
  - OnlineOrderingCheckout
  - OnlineOrderingInbox
  - OnlineOrderingWebsiteDomain
  - PackUnits
  - PharmacyDemo
  - PosScreenRendersHttp
  - QuantityRules
  - UnitsAndPacksJourney
  - W2Combined
  - PosPayloadResilience
- **Blade:** `view:cache` compiled every view, and `php -l` on the compiled POS view was clean. The inline scripts
  of the rendered POS were parse-checked with `node --check` inside the new test.

### Sabotage proofs (each one red, then restored with `git checkout --`)

| # | Sabotage | Result |
|---|----------|--------|
| 1 | Recall looks only in `products` (the hidden-items lookup is removed) | RED: the website-order test (the guard fired, because the rice was not found) |
| 2 | Guard removed (recall builds what it can, as before) | RED: the switched-off test (the cart was replaced by the pasta alone) |
| 3 | Server sends an empty hidden-items list | RED: the list test and the website-order test |
| 4 | Page-load recall keeps its old `products.find` lookup | RED: the address-recall test |

The system map was rebuilt from the `_rhi` databases: 239 tables, 441 foreign keys, 230 inferred links, 749 screens.
There is no schema change.

## Done on prod (14 Nov 2026, ~19:40 UTC = 15 Nov 00:40 PKT; owner: "Teekh hai deploy kardo")

**Code**
- Merged as `7a987c2d` (merge of `dc51990f`). `git diff` between the tested commit and the merge is empty.
- Pushed, and origin equals HEAD.
- `bash deploy.sh`: DEPLOY_EXIT=0, all 13 tenants ok, nothing to migrate, prod `055a4ccb` → `7a987c2d`.

**Checks before vs after the deploy**
- **Permissions:** OK. No role lost anything and no user lost a role (all 14 connections unchanged).
- **Accounts:** OK. Every tenant's chart of accounts is exactly as before.
- **Smoke:** all 13 tenants show tb_diff=0.00, neg_on_disallowed=0, department_negative=0, before and after.
- **Log errors today:** 27 before and 27 after, with none after 19:30 UTC.

**Live render proof** (the real `POSController@index` plus the view, built in tinker, read-only)
- **Kashif Food, as the Delivery Desk user (#2):**
  - the page renders, 209 items in `products`;
  - `recallProducts` = 3: Garlic Rice With Shashlik Bar-B-Que, French Fries (Steak Side), Vegetable Rice (Steak Side);
  - the guard is on the page.
  - Singaporean Rice (Khass) #161 is in `products` right now, because one website bill that carries it is still open
    (the open-bill escape hatch). So it is not listed twice. Once that bill closes it moves to `recallProducts`.
- **Khatri:** the page renders, 108 items, `recallProducts` = 0 (its hidden items are all combo parts), and the guard is
  on the page.
- **A real cashier's POS** loaded after the deploy: nginx shows 1 × `GET /pos` 200.
