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

## Results

(filled in below after the runs)
