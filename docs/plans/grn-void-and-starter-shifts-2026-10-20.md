# GRN void + starter plans' counter (20 Oct 2026)

Owner, 20 Oct: "okay continue next" — item 1 of the roadmap: two bugs that would hurt a real medical store / a new
signup. Both found and confirmed against live data (read-only).

## 1. GRN-VOID-OWN-BATCH-1 — voiding a receipt takes back exactly what it brought in

**Today** (`PurchasingService::voidGrn`):
1. It takes the quantity off **every** stock row of the item in the branch (every batch, every variant), while the
   ledger records it once. An item in 3 batches loses 3× the quantity.
2. It collects the receipt's stock entries **per line, by product** — a receipt with the same item on two lines
   (two batches of one medicine; a free/bonus line, which the new GRN screen writes as its own line) reverses each
   entry twice.
3. It never checks whether an entry was already reversed.

**Live:** khatribiryani voided 3 GRNs (5 Oct). Each item had one stock row, so (1) did no harm, but two stray
1-unit entries (#14, #19, item #60) had already been reversed on 2 Oct (#32, #33) and the void reversed them again
(#37, #42): item #60 is 2 units lower than it should be. Not corrected — owner's call. pharmacydemo has 6 items in
several batches and no voids yet; the first void there would go wrong.

**Fix.**
- `InventoryService::reverseReceiptEntry(StockLedger $in, …)`: locks the exact stock row the entry filled
  (branch + product + variant + batch), refuses if that row no longer holds the quantity ("… some was sold — use a
  Purchase Return or a Stock Adjustment"), takes it off that row only, writes one `adjustment_out` with
  `reversal_of_id`, the entry's own cost, and `balance_after` = that row (as every other movement). An entry that
  already has a reversal is skipped. The branch's official-stock fence applies (as postIn / postOut).
- `voidGrn` reverses every entry of the receipt once (one query for the whole receipt, not per line).

**Impact (system map):** tables `stock_balances`, `stock_ledgers`, `goods_receipts` (write); screen GRN → show
(Void). Tests: GrnVoidMySqlTest, GrnNonStockMySqlTest, PurchasingV2, new GrnVoidOwnBatchMySqlTest.

## 2. STARTER-PLAN-COUNTER-1 — a POS plan can make its counter and open a shift

**Today:** plans `retail_starter`, `inventory_store`, `restaurant_starter` (public signup plans) and `quick_sale`
have `pos` but not `multi_branch`. Branches, Terminals, Shifts and Day Closing belong to `multi_branch`, so a tenant
on those plans cannot make a counter, cannot open a shift — and the POS sells only on an open shift. retaildemo,
inventorydemo and restaurantdemo have 0 counters and 0 shifts (their sales come from the demo seeder). Yet the same
plans carry `branch_limit` 1–2 and `terminal_limit` 1–3: they were meant to have them.

**Fix:** `TenantSubscriptionAccessService::ROUTE_ANY_OF_MODULES` — `tenant.branches`, `tenant.terminals`,
`tenant.shifts`, `tenant.daily-closings` open with `multi_branch` **or** `pos`. Not a move: Kashif Kitchen has
`multi_branch` without `pos` and keeps them. Currencies stay `multi_branch` only. How many branches / counters a
plan gets stays the plan's limit (`checkLimit` on store, unchanged). The role editor (`PermissionCatalogService`)
files these permissions under whichever of the two modules the plan has, so a non-owner role can be given them.

**Live tenants:** all 4 have both modules — the same screens open, only the reason changes. No plan data changes.

**Impact (system map):** no table. Screens: Branches, Terminals, Shifts, Daily Closing (open on 4 more plans);
Permission Center. Tests: CateringEntitlementMySqlTest, PermissionCenter, new StarterPlanCounterMySqlTest.
