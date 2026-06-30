# POS SaaS — Comprehensive Bug & Gap Audit Report
> Generated: June 29, 2026 | Covers last ~40 commits + full codebase review

---

## Priority Legend
- 🔴 CRITICAL — data loss, financial corruption, or system crash in production
- 🟠 HIGH — incorrect behavior affecting real transactions or inventory
- 🟡 MEDIUM — edge-case failures, UX-breaking, or latent risk
- 🟢 LOW — polish, consistency, or deferred design decisions

---

## SECTION 1: FINANCIAL / ACCOUNTING BUGS 🔴


### BUG-001 🔴 COGS double-posted for recipe products on sale
**File:** `app/Services/Sales/SalesService.php` → `finalizePaidSale()`
**File:** `app/Services/Finance/JournalPostingService.php` → `postPaidSale()`

`finalizePaidSale()` calls `RecipeConsumptionService::consumeForSalesOrderLine()` which issues ingredient stock via `postOutFefo` (each ledger movement reduces `stock_balances.average_cost × qty`). The cost is then set on `line.cost_total`. Then `postPaidSale()` reads `$sale->lines->sum('cost_total')` and posts `Dr 5100 COGS / Cr 1400 Inventory` for the full amount.

**The problem:** `postOutFefo` already moved stock VALUE out of `1400 Inventory` via the stock-ledger side. Then `postPaidSale` ALSO credits `1400` for the same amount — double-reducing inventory on the GL. The stock balance is correct (InventoryService tracks it independently) but the GL journal has a duplicate `Cr 1400` for recipe items.

**Impact:** Trial balance will show `1400 Inventory` understated for every restaurant sale. P&L COGS is correctly overstated once but inventory balance is wrong.

**Fix location:** `JournalPostingService::postPaidSale()` — recipe-based lines (`inventory_consumption_method = 'recipe'`) must credit `5200 Recipe/Ingredient COGS` (already in CoA) instead of `1400`, or exclude their `cost_total` from the `1400` credit line entirely since the stock movement already handled the inventory side.

---

### BUG-002 🔴 Cash/bank balance race condition — no transaction wrapping
**File:** `app/Services/Finance/JournalPostingService.php` → `postSalesCashBankMovement()`

The method does: `lockForUpdate()` on the `CashBankAccount`, creates a `CashBankAccountTransaction`, then calls `cash->update(['current_balance' => $newBalance])`. However, this is NOT inside a `DB::connection('tenant')->transaction()`. The `lockForUpdate()` only holds within the same transaction. Two concurrent POS sales with the same payment method will both read the same `current_balance` and produce incorrect running balances.

**Impact:** Cash drawer balance will be wrong under concurrent load.

**Fix:** Wrap the entire `foreach ($sale->payments as $payment)` block in `DB::connection('tenant')->transaction()`.

---

### BUG-003 🔴 Promotion usage incremented outside the sale transaction
**File:** `app/Http/Controllers/Tenant/SalesOrderController.php` line ~200

```php
if ($totals['promotion_id']) {
    \App\Models\Tenant\Promotion::where('id', $totals['promotion_id'])->increment('used_count');
}
```
This `increment()` runs AFTER the outer `DB::connection('tenant')->transaction()` closure completes (it's inside the closure but after `finalizePaidSale` which commits). If the sale commits but the increment throws, the promo code effectively gets a free use. More critically, if two users apply the same promo code concurrently, both can pass the `used_count < usage_limit` check before either increments.

**Fix:** Use `SELECT ... FOR UPDATE` or an atomic `increment` with a `where used_count < usage_limit` guard.

---

### BUG-004 🟠 Sales return COGS reversal uses wrong account for recipe products
**File:** `app/Services/Finance/JournalPostingService.php` → `postSalesReturn()`

Returns credit `5100 COGS` and debit `1400 Inventory`. For recipe products this is incorrect — the ingredient stock was consumed at individual-ingredient level, never posted to `1400`. Reversing a recipe-product return will incorrectly inflate `1400` by the recipe cost, making the inventory GL value wrong.

**Fix:** Detect recipe-based lines in the return and skip the COGS/Inventory reversal pair for them (the ingredient stock is already back in `1400` via `postIn` in `SalesReturnController`).

---

### BUG-005 🟠 Modifier stock consumption COGS not posted to GL
**File:** `app/Services/Sales/SalesService.php` → `consumeLineModifiers()`

Modifier stock consumption deducts stock via `postOutFefo` and adds the cost to `line.cost_total`. `postPaidSale()` then posts Dr 5100 / Cr 1400 for the full `cost_total` including modifier costs. This is actually correct for the GL ONLY IF the modifier-linked products' stock value is tracked in `1400`. Currently it is (they use the same inventory system). However, if the modifier cost is rolled into the main line's `cost_total`, the per-line COGS number becomes misleading (modifier ingredient cost mixed with main product cost). Not a balance error but an accuracy issue.

**Severity:** 🟡 MEDIUM — GL stays balanced, but line-level COGS is imprecise.


---

## SECTION 2: INVENTORY / STOCK BUGS 🔴

### BUG-006 🔴 InventoryService::postOutFefo runs nested DB::transaction — deadlock risk
**File:** `app/Services/Inventory/InventoryService.php` → `postOutFefo()` and `postMovement()`

`postOutFefo` wraps itself in `DB::transaction()`. `postMovement()` (called inside) ALSO wraps itself in `DB::transaction()`. In MySQL/InnoDB with the default driver, nested `DB::transaction()` calls use Laravel's transaction stack — the inner one doesn't actually commit, which is fine. BUT when `postOutFefo` is called from within an existing `DB::connection('tenant')->transaction()` in `SalesService`, the `DB::transaction()` inside `postOutFefo` uses the DEFAULT connection (not `tenant`). This means the inventory write happens on a DIFFERENT connection than the sale transaction.

**Impact:** If the sale transaction rolls back after inventory has already been committed, inventory will be deducted but no sale will exist. Stock goes negative with no matching sale record.

**Fix:** All `DB::transaction()` calls inside `InventoryService` must use `DB::connection('tenant')->transaction()`. Verify `SalesService` and `ConsumptionPostingService` call sites use the same connection.

---

### BUG-007 🔴 Stock balance race condition — no row-level lock in postMovement
**File:** `app/Services/Inventory/InventoryService.php` → `postMovement()`

```php
$balance = StockBalance::firstOrCreate(['balance_key' => $balanceKey], [...]);
$currentQty = (float) $balance->quantity_on_hand;
// ... compute newQty ...
$balance->update(['quantity_on_hand' => $newQty, 'average_cost' => $newAverageCost]);
```
There is no `lockForUpdate()` on the `StockBalance` row. Two concurrent sales of the same product will both read the same `quantity_on_hand`, both pass the `>= quantity` check, and both issue. Total deducted = 2×, but only 1× stock existed. Result: negative balance silently, `balance_after` in the ledger will be wrong.

**Fix:** Add `->lockForUpdate()->first()` before computing new quantities, and ensure it's inside a transaction.

---

### BUG-008 🟠 Modifier linked_unit_id stored but never used at consumption time
**File:** `app/Services/Sales/SalesService.php` → `consumeLineModifiers()`

```php
$consumeQty = $perModifierQty * $lineQty;
$this->inventoryService->postOutFefo(... quantity: $consumeQty ...);
```
`$perModifierQty` is `modifier.linked_quantity` which is in `modifier.linked_unit_id` units. The linked product's stock is tracked in its own `unit_id`. If they differ (e.g. modifier says "50 G" but product is stocked in KG), the deduction is 50× too large.

**Impact:** All modifier stock deductions are wrong when linked_unit differs from stock unit. Currently demo data uses PCS→PCS, so it's hidden.

**Fix:** Apply `UnitConversionService::convert()` between `modifier.linked_unit_id` and `linked_product.unit_id` before passing to `postOutFefo`.

---

### BUG-009 🟠 RecipeConsumptionService silent wrong-quantity fallback
**File:** `app/Services/Kitchen/RecipeConsumptionService.php`

```php
try {
    $requiredQty = $this->unitConversionService->convert(...);
} catch (RuntimeException) {
    // Skip conversion if no path found; use as-is
}
```
If a recipe ingredient is specified in grams but the product's base unit is KG and no `UnitConversion` record exists for G→KG, the deduction is `0.200 KG` treated as `200 g consumed` — 1000× overshoot. This silently consumes all stock for a recipe product.

**Fix:** Log a warning and either block the sale or trigger an alert rather than silently using the unconverted value.

---

### BUG-010 🟡 StockBalance firstOrCreate without lock can create duplicate rows
**File:** `app/Services/Inventory/InventoryService.php` → `postMovement()`

`StockBalance::firstOrCreate(['balance_key' => $balanceKey])` is not atomic without a transaction + lock. Two concurrent `postIn` calls for a new product will both attempt to `INSERT` and one will fail with a unique constraint violation — if `balance_key` has a unique index — or both succeed, creating two rows for the same key.

**Fix:** Use `DB::connection('tenant')->transaction()` with `lockForUpdate()` on the balance row, or use `updateOrCreate` with an explicit insert-or-update.


---

## SECTION 3: SALES & POS LOGIC BUGS 🟠

### BUG-011 🟠 Sales return allows returning MORE than originally sold
**File:** `app/Services/Sales/SalesReturnService.php` → `processReturn()`

```php
$qty = min((float) $lineData['quantity'], (float) $orderLine->quantity);
```
The guard caps against `orderLine->quantity` (original qty), but NOT against the already-returned qty. If 3 were sold and 2 were already returned, a second return can return up to 3 again. The `returned_quantity` column is incremented but never checked before allowing another return.

**Fix:** Change guard to: `min($requested, $orderLine->quantity - $orderLine->returned_quantity)` and throw if the result ≤ 0.

---

### BUG-012 🟠 SalesOrderController::cancel() allows cancelling already-paid sales with inventory_posted = false edge case
**File:** `app/Http/Controllers/Tenant/SalesOrderController.php` → `cancel()`

```php
if ($salesOrder->status === 'paid' || $salesOrder->inventory_posted) {
    return back()->withErrors(...);
}
$salesOrder->update(['status' => 'cancelled']);
```
A sale can theoretically be in `status = 'draft'` with `inventory_posted = false` but with payments already created (e.g. if `finalizePaidSale` threw mid-execution after payment rows were created but before status flip). Cancelling it leaves the payment rows orphaned with no inventory reversal and no refund. The `sale_payments` rows still exist pointing to a cancelled sale.

**Fix:** In `cancel()`, also delete any associated `sale_payments` and reverse any partial inventory movements before cancelling.

---

### BUG-013 🟠 Promo code bypasses "requires_code" check when code is empty string
**File:** `app/Services/Sales/PromotionService.php` → `findApplicablePromotion()`

```php
if ($promoCode) {
    $query->where('code', $promoCode)->where('requires_code', true);
}
```
When `$promoCode` is null or empty, the `requires_code` filter is skipped entirely and ALL active promotions are evaluated — including ones with `requires_code = true`. This means a promo that requires an explicit code will be auto-applied to any matching order without the user entering the code.

**Fix:** Add a condition to the broader query: `->where('requires_code', false)` when no promo code is submitted, and only relax it to find code-required promos when a code is provided.

---

### BUG-014 🟡 HeldSaleController: KOT sent-quantity tracking broken for combo lines
**File:** `app/Http/Controllers/Tenant/HeldSaleController.php` → `store()`

```php
$kotKey = $line['product_id'] . ':' . (($line['product_variant_id'] ?? null) ?? 0);
$sentQty = $kotSentKeys[$kotKey] ?? 0;
```
The KOT-sent key uses only `product_id:variant_id`. If the same product appears as both a standalone line AND as a combo component, they share the same KOT key. The combo component line will incorrectly be marked `kot_sent = true` if the standalone product was already sent, or vice versa.

**Fix:** Key should also include `line_kind` or `combo_id` to distinguish standalone from combo-component lines.

---

### BUG-015 🟡 SalesOrderController: `resolveSellingPrice` accepts $0 submitted price as "use catalog price"
**File:** `app/Http/Controllers/Tenant/SalesOrderController.php` → `resolveSellingPrice()`

```php
if ($submittedPrice !== null && $submittedPrice > 0) {
    return $submittedPrice;
}
```
A legitimate free item (price = 0, e.g. a complimentary dish) cannot be saved — the server will override it with the catalog price. A cashier trying to add a free item will always have the price changed on them.

**Fix:** The zero-price case should be preserved when explicitly submitted. Consider adding a separate `is_price_override` boolean flag on the line rather than using price=0 as a signal.

---

### BUG-016 🟡 `sales_orders.balance_due` column missing from original migration
**File:** `database/migrations/tenant/0001_01_01_000008_create_sales_tables.php`

The original `sales_orders` table migration does NOT include `balance_due`, `payment_status`, or `due_date` columns. These are referenced in `SalesOrder::$fillable` and `casts()`, and are used in `JournalPostingService::postPaidSale()` (`$sale->balance_due`, `$sale->payment_status`). These must have been added via a later undocumented migration (`2026_06_15_000009`) for receivables. If a fresh install runs migrations in the wrong order (unlikely but possible with parallel dev branches), these fields could be missing.

**Severity:** 🟡 MEDIUM for fresh-install integrity.


---

## SECTION 4: KITCHEN / RECIPE BUGS 🟠

### BUG-017 🟠 KitchenProductionService posts finished product to stock even when it's a recipe-based POS item
**File:** `app/Services/Kitchen/KitchenProductionService.php` → `complete()`

```php
if ($finishedProduct && $finishedProduct->is_stock_tracked) {
    $this->inventoryService->postIn(...movementType: 'kitchen_production'...);
}
```
If the finished product is `inventory_consumption_method = 'recipe'` (like KARAHI-C), completing a kitchen production will add it to `stock_balances`. Then when the POS sells it, `SalesService` will also try to call `RecipeConsumptionService` (because `consumption_method = 'recipe'`). Now both the kitchen production AND the sale try to consume ingredients — ingredients are consumed twice: once at production time, once at sale time.

This is a fundamental design conflict: kitchen production is meant to pre-batch produce finished goods, but the POS recipe consumption path assumes ingredients are consumed at point-of-sale, not before.

**Fix:** Either (a) block `postIn` for `is_stock_tracked = false` recipe products (most restaurant dishes), or (b) at sale time, if the product has pre-produced stock available, consume from stock (FEFO) instead of consuming recipe ingredients. The correct path depends on the business model. Document the expected flow explicitly and enforce it with a guard.

---

### BUG-018 🟠 RecipeCostService::calculateCost() uses stock balance average_cost but breakdown() uses purchase price — inconsistency
**File:** `app/Services/Kitchen/RecipeCostService.php`

`calculateCost()` resolves cost from `StockBalance::avg_cost` (live stock value). `breakdown()` uses `product.default_purchase_price ÷ purchase_pack_size` (static catalog price). These two methods return different numbers for the same recipe. The `estimatedCost` shown on the recipe show page uses `calculateCost()`, but the full cost report table uses `breakdown()`. Users see two different "costs" on the same page with no explanation.

**Fix:** Unify on one source of truth or clearly label both values (e.g. "Live Cost" vs "Standard Cost"). The current naming hides the discrepancy.

---

### BUG-019 🟡 Recipe order-type filter silently excludes packing lines from ALL order types calculation
**File:** `app/Services/Kitchen/RecipeConsumptionService.php`

Packing lines tagged `['takeaway', 'delivery']` are correctly excluded for dine-in orders. But the `RecipeCostService::breakdown()` for `$orderType = null` (all-lines mode) DOES include packing lines, while `calculateCost()` always includes ALL lines regardless of order type. A production cost report with no filter includes packing cost; `calculateCost` also includes it. This is consistent — but for recipe consumption at POS, only packing-scoped lines are consumed for takeaway/delivery, not for dine-in. This is correct. No code bug, but the `calculateCost` method has no order-type awareness so it will always overstate cost for dine-in sales when packing lines exist.

**Severity:** 🟡 MEDIUM — costing accuracy issue, not a crash.


---

## SECTION 5: SCHEMA / MIGRATION GAPS 🟠

### BUG-020 🟠 `sales_order_lines.modifiers` column stored as JSON but cast to 'array' — null vs [] ambiguity
**File:** `database/migrations/tenant/2026_06_25_000002_add_modifiers_to_sales_order_lines.php`
**File:** `app/Models/Tenant/SalesOrderLine.php`

The column is `json()->nullable()` in the migration but cast as `'array'` in the model. When the column is NULL, Laravel's array cast returns `null`, not `[]`. Code in `SalesService::consumeLineModifiers()` does:

```php
$modifiers = $line->modifiers ?? [];
if (empty($modifiers) || ! is_array($modifiers)) { return 0.0; }
```

The `?? []` fallback handles null. But in HeldSaleController's `ajaxList()`:
```php
'modifiers' => $l->modifiers ?? [],
```
If `modifiers` is NULL in DB (not `[]`), the cast returns `null`, and the JS receives `null` for that field instead of an empty array — which can cause `modifiers.forEach is not a function` on the frontend when recalling a held sale with no modifiers.

**Fix:** Either default the column to `'[]'` in the migration, or handle null gracefully in the model with a `getModifiersAttribute()` accessor returning `[]`.

---

### BUG-021 🟠 `modifiers.linked_unit_id` has no foreign key constraint
**File:** `database/migrations/tenant/2026_06_29_000001_add_modifier_stock_consumption.php`

```php
$t->unsignedBigInteger('linked_unit_id')->nullable()->after('linked_quantity');
```
No `->constrained('units')`. A linked unit can be deleted without nullifying this reference, leaving a dangling FK. `modifier->linkedUnit` relationship will return null silently, causing `stockConsumptionLabel()` to show a blank unit.

**Fix:** Add `->nullable()->constrained('units')->nullOnDelete()` or add a separate `addForeign` call.

---

### BUG-022 🟡 `sales_ledgers.entry_type` enum missing 'service_charge' and 'tip' values in base migration
**File:** `database/migrations/tenant/0001_01_01_000008_create_sales_tables.php`

The original migration defines:
```php
$table->enum('entry_type', ['sale_total','sale_payment','sale_discount','sale_tax','sale_return','refund']);
```
The `2026_06_05_000001_create_sales_controls_tables.php` migration extended it, but the PROMPT_12 summary says: *"SalesService::postSalesLedger() — Ledger entries for service_charge and tip are not yet created."* The enum extension exists but the actual `postSalesLedger()` in `SalesService` DOES now write `service_charge` and `tip` entries. However, the MySQL enum was extended by the migration — this is fine on existing DBs. But the original migration still has the old values. If a tenant DB is freshly created and the `2026_06_05_*` migration runs, the enum extension happens. Ordering is correct. Low risk.

**Severity:** 🟢 LOW — ordering dependent, currently works.

---

### BUG-023 🟡 `products` table missing `inventory_consumption_method` and `item_kind` columns in base migration
**File:** `database/migrations/tenant/0001_01_01_000005_create_catalog_tables.php`

The base products migration does NOT include `inventory_consumption_method`, `item_kind`, `product_kind`, `is_pos_visible`, `purchase_unit_id`, `purchase_pack_size`, or any of the newer PRODUCT-BOUNDARY-2 fields. These were added by subsequent migrations. The `Product` model `$fillable` lists all of them. If a migration is somehow skipped or reordered, the model will fail silently (Eloquent ignores unknown fillable keys) but the data is lost.

**Severity:** 🟡 MEDIUM — migration chain integrity dependency.

---

### BUG-024 🟡 No unique index on `stock_balances.balance_key`
**File:** `database/migrations/tenant/0001_01_01_000006_create_inventory_tables.php` (inferred)

`InventoryService::postMovement()` uses `StockBalance::firstOrCreate(['balance_key' => $balanceKey])`. Without a `UNIQUE` index on `balance_key`, `firstOrCreate` is not atomic — two concurrent inserts will both succeed, creating duplicate balance rows for the same (branch, product, variant, batch). This compounds BUG-010.

**Fix:** Add `$table->unique('balance_key')` to the stock_balances migration.


---

## SECTION 6: SECURITY & PERMISSION GAPS 🟠

### BUG-025 🟠 EnsureRoutePermission whitelist allows unauthenticated access to sensitive APIs
**File:** `app/Http/Middleware/EnsureRoutePermission.php`

The following route prefixes are whitelisted and bypass all permission checks:
```
'tenant.api.pos'       → includes totals/quote, table-session open-orders
'tenant.api.catalog'   → includes barcode lookup
'tenant.printing.jobs' → includes mark-printed, retry, queue-receipt, queue-KOT
```
These routes are under `auth:tenant` middleware, so they require login — but any authenticated tenant user (even a cashier) can access `tenant.printing.jobs.retry` and `tenant.printing.jobs.queue-receipt` without the `tenant.printing.jobs.*` permissions. The permission check is bypassed by the whitelist.

`tenant.api.manager-approvals.verify` is also whitelisted in the prefix check indirectly through `TenantProvisioner` assigning it as a permission, but the middleware whitelist for `tenant.api.pos` would also catch it. The manager PIN verify endpoint can be called by any authenticated user with no permission gate.

**Fix:** Move `tenant.printing.jobs.*` out of the whitelist and keep only the truly public/operational endpoints (`tenant.api.print-agent`, `tenant.api.pos.totals.quote`, `tenant.api.kitchen-display`). The `tenant.printing.jobs.*` routes already have permissions in `TenantProvisioner` — they should use them.

---

### BUG-026 🟠 PreventDemoMutation allows writing to `tenant.printing.*` store/update routes
**File:** `app/Http/Middleware/PreventDemoMutation.php`

`ALLOW_PREFIXES` includes `tenant.restaurant.` and `tenant.sales-orders.` but does NOT list `tenant.printing.`. The BLOCK_KEYWORDS list doesn't contain `printing`. This means a demo user can create/update printers, print agents, receipt layouts, and category printer mappings in the demo tenant — exactly the kind of configuration that should be locked down in a shared demo.

**Fix:** Add `'tenant.printing.printers.'`, `'tenant.printing.category-mappings.'`, `'tenant.printing.layouts.'` and `'tenant.print-agents.'` to either BLOCK_KEYWORDS or add a per-route guard.

---

### BUG-027 🟡 SalesReturnController validates `refund_method` as nullable but SalesReturnService tries to `postSalesReturnCashBankMovement()` which matches on 'CASH-MAIN' / 'BANK-MAIN' hardcoded codes
**File:** `app/Services/Finance/JournalPostingService.php` → `postSalesReturnCashBankMovement()`

```php
$code = match ($return->refund_method) {
    'cash'          => 'CASH-MAIN',
    'bank_transfer' => 'BANK-MAIN',
    default         => null,
};
```
This hardcodes `CASH-MAIN` and `BANK-MAIN` as the cash/bank account codes for ALL tenants. A real tenant that names their cash account `CASH-BRANCH1` or `MAIN-CASH` will never have their return refund posted to the operational cash/bank balance. The GL journal will also use an incorrect hardcoded lookup.

**Fix:** The refund cash/bank account should come from the sale's original payment method → cash_bank_account (same as `postSalesCashBankMovement` does via `payment->method->cashBankAccount`). Not from a hardcoded code string.

---

### BUG-028 🟡 `tenant.users.manager-pin` permission missing from new-tenant provisioning
**File:** `app/Services/Tenancy/TenantProvisioner.php`

Looking at the provisioner permissions list — `tenant.users.manager-pin` and `tenant.users.manager-pin.store` ARE present (confirmed at the bottom of the list). However, `tenant.api.pos.table-sessions.open-orders` is listed as a permission but the actual route name from `HeldSaleController::tableSessionOpenOrders` is `tenant.api.pos.table-sessions.{restaurantTableSession}.open-orders` (with a parameter). The EnsureRoutePermission middleware does an exact `$user->can($routeName)` check. Route names with parameters will not match any permission.

**Fix:** The `tenant.api.pos.*` prefix is in the whitelist so this route is skipped by the permission check — so it currently works. But the permission in the provisioner (`tenant.api.pos.table-sessions.open-orders`) is essentially dead and never checked.


---

## SECTION 7: MANUFACTURING GAPS (TRACKING vs. REALITY) 🟡

### BUG-029 🟡 KitchenProduction and ManufacturingConsumption both deduct ingredients — double-consumption if both used for same batch
**File:** `app/Services/Kitchen/KitchenProductionService.php`
**File:** `app/Services/Manufacturing/ConsumptionPostingService.php`

These are two separate systems that do the same thing: consume raw material stock. A Kitchen Production completion (`kitchen_production` movement type) deducts ingredients from stock. A Manufacturing Consumption posting (`manufacturing_material_issue` movement type) also deducts the same stock. If a kitchen manager uses Kitchen Productions for batch-cooking AND an admin also posts a Manufacturing Consumption for the same batch of ingredients, the stock will be deducted twice.

There is no cross-system guard or warning. Users can arrive at this double-consumption without any error.

**Fix:** Add a business rule / UI warning: "If you have completed a Kitchen Production for this recipe batch, do not also post a Manufacturing Consumption for the same materials." Or enforce exclusive use of one system via a product/branch-level setting.

---

### BUG-030 🟡 ManufacturingConsumptionPostingController has no reverse button guard against active WIP jobs
**File:** `app/Http/Controllers/Tenant/Manufacturing/ManufacturingConsumptionPostingController.php` (inferred from ConsumptionPostingService)

`ConsumptionPostingService::reverse()` unwinds WIP accumulated cost with `max(0, ...)`. If the WIP job has already had Finished Goods posted based on the accumulated cost (even though FG posting isn't live yet), reversing the consumption will silently set WIP accumulated_cost to 0, making the WIP job's unit cost history inaccurate when FG posting is eventually built.

**Severity:** 🟢 LOW now (FG posting not live), 🟠 HIGH when FG phase is built.

---

## SECTION 8: DESIGN / ARCHITECTURAL GAPS 🟡

### BUG-031 🟡 `SalesTotalsService`: service charge is calculated AFTER manual discount but tax is aggregated from individual lines BEFORE any order-level discount
**File:** `app/Services/Sales/SalesTotalsService.php`

Tax is summed from `line.tax_amount` (pre-calculated on each line at catalog price). The order-level discount and promotion discount reduce the total but do NOT retroactively reduce the per-line tax amounts. This means:
- Subtotal: 1000, Order discount: 100, Net: 900
- Tax on line: 170 (calculated at 1000 base)
- Grand total: 900 + 170 = 1070

But the correct tax on a 900-base sale should be lower (e.g. 153). The order discount does not reduce the taxable base.

**Impact:** Customers are slightly over-taxed when an order-level discount is applied. Regulators may flag this.

**Fix:** Either apply discount pro-rata to line totals before calculating tax, or recalculate tax after discount application.

---

### BUG-032 🟡 Shift totals updated inside SalesService transaction via `updateShiftTotals()` — no lock
**File:** `app/Services/Sales/SalesService.php` → `updateShiftTotals()`

```php
$shift->update([
    'total_sales' => (float) $shift->total_sales + (float) $sale->grand_total,
    ...
]);
```
This reads `$shift->total_sales` and writes it back. No `lockForUpdate()` — same race condition as the cash/bank balance issue (BUG-002). Two concurrent sales on the same shift will produce an incorrect `total_sales`.

**Fix:** Use `Shift::lockForUpdate()->find($sale->shift_id)` and wrap in a transaction, or use `increment()`.

---

### BUG-033 🟡 `POSController::index()` loads ALL products, ALL stock balances, ALL combos in a single page request — no pagination
**File:** `app/Http/Controllers/Tenant/POSController.php` → `index()`

With eager loads for `activeRecipe.ingredients.product.unit`, modifier groups with modifiers, branch prices, barcodes, variants — for a catalog of 200+ products this is a massive N+1 risk and could generate hundreds of queries. The `$stockByProduct` groupBy loop runs PHP-side on all stock rows with three nested loops.

**Impact:** POS page load will degrade significantly as catalog grows. With 500 products + 3 branches, this could easily take 5-10 seconds.

**Fix:** Move stock payload to an AJAX call on branch change. Lazy-load modifier groups. Consider a dedicated `/api/pos/products` endpoint with proper SQL aggregation.

---

### BUG-034 🟡 `JournalService::nextEntryNo()` has a race condition
**File:** `app/Services/Finance/JournalService.php` → `nextEntryNo()`

```php
$last = JournalEntry::where('entry_no', 'like', $prefix . '%')->orderByDesc('entry_no')->value('entry_no');
$seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
```
Two concurrent journal posts on the same date will both read the same `$last`, compute the same `$seq`, and attempt to insert with the same `entry_no`. If `entry_no` has a unique index, one will fail with a DB exception. If it doesn't, both succeed with the same entry number, violating accounting document uniqueness.

**Fix:** Use `DB::table('journal_entries')->lockForUpdate()` inside the transaction, or use an auto-increment sequence table.

---

### BUG-035 🟢 `SalesReturnService::processReturn()` calls GL posting OUTSIDE the main transaction
**File:** `app/Services/Sales/SalesReturnService.php`

```php
$salesReturn = DB::connection('tenant')->transaction(function () { ... });
$this->journalPosting->postSalesReturn($salesReturn, $userId);   // outside
$this->journalPosting->postSalesReturnCashBankMovement(...);     // outside
```
This is the same pattern as `SalesService::finalizePaidSale()` — GL is intentionally outside the transaction so a GL failure doesn't roll back the inventory/operational flow. This is by design per `JournalPostingService` contract ("safe: catches/reports"). Flagged for awareness, not a bug.

**Severity:** 🟢 LOW — intentional design, documented.


---

## SECTION 9: SEEDER / DEMO DATA GAPS 🟡

### BUG-036 🟡 [FIXED IN THIS SESSION] Opening stock guard tripped by modifier opening adjustment
**Status:** ✅ FIXED — `postOpeningForBranch()` now scopes guard to `ADJ-OPEN-{BRANCH}-%`.
See commit context. Listed here for traceability.

---

### BUG-037 🟡 `seedRecipes()` deletes and recreates ingredients on every re-seed — loses any `applicable_order_types` customisation
**File:** `database/seeders/TenantDemoSeeder.php` → `seedRecipes()`

```php
$recipe1->ingredients()->delete();  // wipes any user changes
```
For the three simple recipes (Karahi, Biryani, Burger), ingredients are deleted and recreated on every re-seed (e.g. `demo:reset`). Any `applicable_order_types` or `cost_override` set by a demo user is lost. The Technosys Karahi (`seedTechnosysKarahi`) also does this. This is intentional for demo reset but worth documenting — production tenants should not run demo seeders.

**Severity:** 🟢 LOW for demo, but dangerous if run on production accidentally.

---

### BUG-038 🟡 Demo seeder creates 3 separate `opening` StockAdjustments on Main branch — confuses stock adjustment list
**File:** `database/seeders/TenantDemoSeeder.php`

After the current fix, Main branch has:
- `ADJ-MOD-OPEN-MAIN` — modifier stock products (from `seedModifierStockProducts`)
- `ADJ-OPEN-MAIN-*` — catalog opening stock (from `postOpeningForBranch`)
- `ADJ-KRH-OPEN-MAIN` — Karahi recipe ingredients (from `seedTechnosysKarahi`)

Three separate `adjustment_type = 'opening'` adjustments on Main, all shown in the Stock Adjustments list. This is not wrong but looks confusing in the demo. Low priority.

---

## SECTION 10: INCOMPLETE / DEFERRED FEATURES WITH LIVE HOOKS 🟠

### BUG-039 🟠 PromotionService: product/category promotions are evaluated but `promotion_targets` is empty in default seeder
**File:** `app/Services/Sales/PromotionService.php` → `qualifyingSubtotal()`

The `BURGER15` and `FASTFOOD5` promotions added in `seedSalesControls()` DO have targets created (via `$burgerDeal->targets()->delete(); ... $burgerDeal->targets()->create(...)`. BUT: the original `PROMPT_12_IMPLEMENTATION_SUMMARY.md` notes *"promotion_targets | 0 | (awaits product mapping)"* — implying an earlier version had no targets. If a tenant was provisioned before the seeder was updated, they have `BURGER15` and `FASTFOOD5` with zero targets, meaning `qualifyingSubtotal()` returns 0 for all sales, and these promos silently never apply.

**Fix:** The `syncSystemRoutes` + re-seed approach handles this for demo. For real tenants, document that product-scoped promotions require adding targets via the UI.

---

### BUG-040 🟠 `sales_orders.service_charge_amount` and `tip_amount` are calculated and stored but NOT posted to SalesLedger
**File:** `app/Services/Sales/SalesService.php` → `postSalesLedger()`

`postSalesLedger()` DOES write `service_charge` and `tip` ledger entries (these are in the code). But the `sales_ledgers.entry_type` enum was extended in `2026_06_05_000001`. However, looking at the PROMPT_12 summary, it explicitly states: *"SalesService::postSalesLedger() — Ledger entries for service_charge and tip are not yet created."*

Reading the actual `SalesService.php` code confirms the ledger entries for `service_charge` and `tip` ARE written (the code has the `if ($sale->service_charge_amount > 0)` block). So this was completed later. Not a bug — but the PROMPT_12 document is out of date and misleading.

**Severity:** 🟢 LOW — documentation lag, code is correct.

---

### BUG-041 🟠 Manager PIN verification endpoint has no rate limiting
**File:** `routes/tenant.php` → `tenant.api.manager-approvals.verify`

The PIN verify endpoint (`POST /api/manager-approvals/verify`) has no throttle middleware. A brute-force attack on 4-digit PINs (max 10,000 combinations) is trivially feasible. The endpoint is protected by `auth:tenant` but a logged-in cashier could brute-force a manager's PIN to approve large discounts.

**Fix:** Add `->middleware('throttle:5,1')` to this route (max 5 attempts per minute, same as password reset).

---

## SUMMARY TABLE — FIX PRIORITY ORDER

| # | Severity | Area | One-line description |
|---|---|---|---|
| 001 | 🔴 CRITICAL | Finance/GL | Recipe sale COGS double-credits Inventory 1400 |
| 002 | 🔴 CRITICAL | Finance | Cash/bank balance update not transaction-wrapped — race condition |
| 006 | 🔴 CRITICAL | Inventory | InventoryService uses wrong DB connection — sale can rollback after stock deducted |
| 007 | 🔴 CRITICAL | Inventory | StockBalance read-modify-write has no row lock — concurrent oversell |
| 003 | 🔴 CRITICAL | Sales | Promo usage_limit increment outside transaction — concurrent abuse |
| 011 | 🟠 HIGH | Sales | Sales return allows returning more qty than was sold |
| 008 | 🟠 HIGH | Inventory | Modifier linked_unit_id ignored — wrong deduction qty if units differ |
| 009 | 🟠 HIGH | Inventory | Recipe unit conversion failure silently uses wrong quantity |
| 017 | 🟠 HIGH | Kitchen | Kitchen Production + recipe-based POS sale double-consume ingredients |
| 004 | 🟠 HIGH | Finance/GL | Sales return COGS reversal credits wrong account for recipe products |
| 027 | 🟠 HIGH | Finance | Hardcoded CASH-MAIN/BANK-MAIN for return refund GL posting |
| 013 | 🟠 HIGH | Sales | Promo `requires_code` bypassed when no code submitted |
| 025 | 🟠 HIGH | Security | EnsureRoutePermission whitelist bypasses permission on printing.jobs |
| 032 | 🟡 MEDIUM | Sales | Shift totals updated without row lock — concurrent race condition |
| 034 | 🟡 MEDIUM | Finance | JournalEntry nextEntryNo() race condition — duplicate entry numbers possible |
| 010 | 🟡 MEDIUM | Inventory | StockBalance firstOrCreate not atomic — duplicate rows possible |
| 031 | 🟡 MEDIUM | Sales | Order-level discount does not reduce tax base — customers over-taxed |
| 041 | 🟠 HIGH | Security | Manager PIN endpoint has no rate limiting — brute-forceable |
| 033 | 🟡 MEDIUM | POS | POSController loads entire catalog in one request — will degrade at scale |
| 012 | 🟡 MEDIUM | Sales | Cancel allows orphaned payment rows on mid-execution crash |
| 021 | 🟡 MEDIUM | Schema | modifiers.linked_unit_id missing FK constraint |
| 020 | 🟡 MEDIUM | Schema | SalesOrderLine.modifiers null vs [] — frontend JS crash on recall |
| 018 | 🟡 MEDIUM | Kitchen | calculateCost() vs breakdown() use different cost sources — UI shows two different numbers |
| 026 | 🟡 MEDIUM | Security | PreventDemoMutation allows printer config writes in demo |
| 024 | 🟡 MEDIUM | Schema | stock_balances.balance_key likely missing UNIQUE constraint |
| 014 | 🟡 MEDIUM | POS | KOT sent-qty key collision between standalone and combo lines |
| 015 | 🟡 MEDIUM | Sales | Zero-price legitimate free items overridden by catalog price |
| 029 | 🟡 MEDIUM | Mfg | Kitchen Production + Mfg Consumption can double-deduct same stock |
| 016 | 🟡 MEDIUM | Schema | balance_due/payment_status missing from base sales migration |
| 023 | 🟡 MEDIUM | Schema | Products base migration missing newer columns (migration chain risk) |
| 005 | 🟡 MEDIUM | Finance | Modifier COGS mixed into main line cost_total — imprecise reporting |
| 019 | 🟡 MEDIUM | Kitchen | calculateCost() has no order-type awareness — overstates dine-in cost |
| 039 | 🟡 MEDIUM | Sales | Product/category promos silently don't apply if targets missing |
| 040 | 🟢 LOW | Docs | PROMPT_12 doc says ledger entries not created — code is correct |
| 037 | 🟢 LOW | Seeder | Recipe re-seed wipes user customisations |
| 038 | 🟢 LOW | Seeder | 3 separate opening adjustments on Main branch looks confusing |
| 028 | 🟢 LOW | Perms | Dead permission `tenant.api.pos.table-sessions.open-orders` in provisioner |
| 035 | 🟢 LOW | Design | GL posting outside transaction — intentional by design |
| 030 | 🟢 LOW now | Mfg | WIP reversal will corrupt FG cost when FG posting is built |
| 022 | 🟢 LOW | Schema | sales_ledger enum extension — ordering correct, low risk |

---

## IMMEDIATE ACTION PLAN (fix in this order)

**Sprint 1 — Stop data corruption (this week):**
1. BUG-006: Fix `DB::transaction()` → `DB::connection('tenant')->transaction()` in `InventoryService`
2. BUG-007: Add `lockForUpdate()` to `StockBalance` read in `postMovement()`
3. BUG-002: Wrap cash/bank balance update in `DB::connection('tenant')->transaction()`
4. BUG-001: Fix recipe COGS GL posting — don't double-credit 1400 for recipe lines
5. BUG-003: Make promo increment atomic with a `where used_count < usage_limit` guard

**Sprint 2 — Fix incorrect business logic (next week):**
6. BUG-011: Cap return qty against remaining (qty - returned_qty)
7. BUG-013: Fix promo `requires_code` bypass
8. BUG-041: Add rate limiting to manager PIN endpoint
9. BUG-008: Apply unit conversion in `consumeLineModifiers()`
10. BUG-027: Fix hardcoded CASH-MAIN/BANK-MAIN in return refund GL

**Sprint 3 — Schema hardening:**
11. BUG-020: Fix SalesOrderLine.modifiers null handling
12. BUG-021: Add FK constraint on modifiers.linked_unit_id
13. BUG-024: Verify/add UNIQUE on stock_balances.balance_key
14. BUG-034: Fix JournalEntry nextEntryNo() race condition

**Sprint 4 — Design improvements:**
15. BUG-017: Document/enforce Kitchen Production vs recipe-POS-sale separation
16. BUG-032: Add lockForUpdate to shift total updates
17. BUG-033: Move POS product payload to AJAX / paginate
18. BUG-031: Recalculate tax after order-level discount

---

# PART 2 — Extended Audit (100-commit sweep)
> Additional issues found after reading purchasing, finance, billing, subscription, split-bill, stock-count, and report services.

---

## SECTION 11: PURCHASING / SUPPLIER BUGS 🟠

### BUG-042 🔴 PurchasingService::postSupplierLedger() updates supplier balance without a row lock
**File:** `app/Services/Purchasing/PurchasingService.php` → `postSupplierLedger()`

```php
$balance = $direction === 'debit'
    ? $supplier->current_balance + $amount
    : $supplier->current_balance - $amount;
$supplier->update(['current_balance' => $balance]);
```
No `lockForUpdate()` on the supplier row. Two concurrent GRN postings for the same supplier will both read the same `current_balance` and produce wrong totals. Identical pattern to BUG-002 and BUG-032.

**Fix:** `Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail()` before computing and updating the balance, inside a `DB::connection('tenant')->transaction()`.

---

### BUG-043 🟠 GoodsReceiptController::show() uses fully-qualified class name as reference_type — will never match
**File:** `app/Http/Controllers/Tenant/GoodsReceiptController.php` → `show()`

```php
$ledgers = \App\Models\Tenant\StockLedger::where('reference_type', GoodsReceipt::class)
    ->where('reference_id', $goodsReceipt->id)...
```
`GoodsReceipt::class` resolves to `"App\Models\Tenant\GoodsReceipt"`. But `PurchasingService::postGrn()` posts stock with:
```php
$this->inventoryService->postIn(..., GoodsReceipt::class, $grn->id, $grn->grn_no, ...)
```
So both use the full class name. However the `StockLedger` `reference_type` column in the DB will contain `"App\Models\Tenant\GoodsReceipt"` as a string — which is valid. This works, but it means `reference_type` is an inconsistent mix of class names (GRN) vs short strings (`'sales_order'`, `'stock_adjustment'`). The GoodsReceipt model's own `ledgers()` relation also uses `self::class`.

**Impact:** No runtime crash, but inconsistency means you can't query across all reference types uniformly. If any code queries `reference_type = 'goods_receipt'` (the short-form), it will return zero results.

**Fix:** Standardise all `reference_type` values to short lowercase strings (e.g. `'goods_receipt'`). Add a migration to update existing rows. Affects `PurchasingService::postGrn()`, `GoodsReceipt::ledgers()` relation, and the show controller.

---

### BUG-044 🟠 PurchaseBillController::store() calls `$this->purchasingService->postBill()` inside DB::transaction but postBill() calls JournalPostingService which is NOT safe inside nested transactions
**File:** `app/Http/Controllers/Tenant/PurchaseBillController.php` → `store()`

```php
DB::connection('tenant')->transaction(function () use (...) {
    ...
    $this->purchasingService->postBill($purchaseBill, $userId); // ← calls JournalPostingService inside
});
```
`PurchasingService::postBill()` calls `app(JournalPostingService::class)->postPurchaseBill($bill, $userId)`. `JournalPostingService::postPurchaseBill()` calls `$this->journal->post()` which runs its own `DB::connection('tenant')->transaction()`. Laravel nests these safely (savepoints), BUT: if the outer transaction rolls back after the inner journal transaction "committed" (as a savepoint release), the journal entry will be rolled back too — which is correct. However `JournalPostingService` catches all `Throwable` and returns `null` silently. So if the journal throws INSIDE the outer transaction because of a balance violation, the outer transaction continues without knowing the GL failed.

**Fix:** `postBill()` should call `JournalPostingService` OUTSIDE the `DB::transaction()` (same pattern `SalesService` uses). Move it after the `});` closing brace.

---

### BUG-045 🟡 SupplierPayableService::recordPayment() — supplier balance race condition (same as BUG-042)
**File:** `app/Services/Purchasing/PurchasingService.php` → `postPayment()`

The payment call path is: `SupplierPayableService::recordPayment()` → `PurchasingService::postPayment()` → `postSupplierLedger()`. Same no-lock pattern. See BUG-042.

---

### BUG-046 🟡 CustomerReceivableService::nextPaymentNo() has the same race condition as JournalService::nextEntryNo()
**File:** `app/Services/Finance/CustomerReceivableService.php`

```php
$last = CustomerPayment::where('payment_no', 'like', $prefix . '%')->orderByDesc('payment_no')->value('payment_no');
$seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
```
Two concurrent payments on the same day will read the same `$last` and produce duplicate `payment_no`. No unique index enforcement check shown.

---

## SECTION 12: SPLIT BILL BUGS 🟠

### BUG-047 🟠 SplitBillController: modifiers not copied to the new split sale's lines
**File:** `app/Http/Controllers/Tenant/SplitBillController.php` → `store()`

When creating the split sale's lines:
```php
$newSale->lines()->create([
    'product_id'    => $heldLine->product_id,
    ...
    // 'modifiers' not included
]);
```
The `modifiers` JSON column is never copied from `$heldLine` to the new split line. So any modifier add-ons (Extra Cheese, Extra Patty) selected by the customer disappear from the split bill. When `finalizePaidSale()` runs on the new sale, `consumeLineModifiers()` finds no modifiers on the new lines and skips modifier stock deduction — the linked stock is never consumed for split sales.

**Fix:** Add `'modifiers' => $heldLine->modifiers ?? []` to the `lines()->create()` call.

---

### BUG-048 🟠 SplitBillController: service_charge and tip are not recalculated for the split portion
**File:** `app/Http/Controllers/Tenant/SplitBillController.php`

The new split sale is created with `service_charge_amount = 0` (not set in `SalesOrder::create()`). The original held sale's service charge is not pro-rated. So a 5% dine-in service charge on a 1000 table bill is lost entirely when paying via split. The remaining held sale also keeps its full original `service_charge_amount` even though some items were removed.

**Fix:** Use `SalesTotalsService::calculate()` on the split lines (same as `HeldSaleController` and `SalesOrderController` do) to correctly compute service charge and tip for the split portion.

---

### BUG-049 🟡 SplitBillController: the remaining held sale's `promo_code` and `promotion_id` are preserved even after items qualifying for the promo are removed
**File:** `app/Http/Controllers/Tenant/SplitBillController.php` → `recalculateHeldSale()`

`recalculateHeldSale()` recalculates subtotals and line totals from remaining lines but never re-evaluates the promotion. If the promo was order-level and was applied to the full 1000 order but now only 300 remains, the promo discount is also not re-applied or recalculated — it's just lost because `discount_amount` is summed from line-level discounts only (which are 0 for order-level promos).

---

## SECTION 13: STOCK COUNT BUGS 🟡

### BUG-050 🟡 StockCountController: system_quantity snapshot taken at addLine time, not at post time — stale data
**File:** `app/Http/Controllers/Tenant/StockCountController.php` → `addLine()` and `post()`

When a product is added to a stock count, `system_quantity` is snapshotted from `StockBalance` at that moment. But stock movements (sales, purchases, adjustments) can happen between when the line was added and when the count is posted. The variance is calculated as `counted_quantity - system_quantity` — but `system_quantity` is the stale snapshot, not the current live balance.

**Impact:** If 10 Pepsi were in stock when the line was added, then 5 were sold before posting, the system shows 10 expected but actual live stock is 5. The count records 5 as the physical count. Variance = 5 - 10 = -5 (a loss), when in reality there's no discrepancy.

**Fix:** Either (a) re-snapshot `system_quantity` at post time from live `StockBalance`, or (b) document clearly that the count should be conducted and posted in one session without any intervening stock movements (which is the standard physical inventory practice).

---

### BUG-051 🟡 StockCountController::stockSnapshot() only reads a single StockBalance row (no SUM across batches)
**File:** `app/Http/Controllers/Tenant/StockCountController.php` → `stockSnapshot()`

```php
$balance = $query->first(); // ← takes only ONE row
'quantity' => $balance ? (float) $balance->quantity_on_hand : 0.0,
```
A product with multiple expiry batches will have multiple `StockBalance` rows (one per batch). `first()` returns only the first batch row. The system_quantity on the count line will be just one batch's stock, not the total. The count person physically counts all batches together. Variance will be wrong.

**Fix:** Use `sum('quantity_on_hand')` grouped by product/variant (without batch), or sum all matching rows.

---

## SECTION 14: FINANCE STATEMENT BUGS 🟠

### BUG-052 🟠 ProfitLossService: manufacturing expense accounts (5300 Production Variance, 5310 Manufactured COGS, 6210 Direct Labour, etc.) are categorised as "COGS" because they start with '5' or are type=expense — correct by convention, but 6900/6910/6920 Scrap/Rework/Inventory Adjustment are also type=expense and will appear in "Operating Expenses" not COGS
**File:** `app/Services/Finance/ProfitLossService.php`

The P&L buckets expenses as COGS if `str_starts_with(code, '5')` else Operating Expense. The manufacturing accounts `6900 Scrap/Waste`, `6910 Rework`, `6920 Inventory Adjustment` are `type=expense` and code starts with `6`, so they land in Operating Expenses — which may be correct for standard accounting. But `5300 Production Variance` starts with `5` so lands in COGS. This is intentional but worth documenting: variance is treated as part of COGS, not separately disclosed. No bug per se, but any accountant reviewing the P&L expecting a separate Variance section will be confused.

**Severity:** 🟢 LOW — accounting convention issue, not a calculation error.

---

### BUG-053 🟠 BalanceSheetService and ProfitLossService: branch filter is applied to journal_lines.branch_id but GL journals posted without branch_id (null lines) are excluded from filtered views
**File:** `app/Services/Finance/BalanceSheetService.php`
**File:** `app/Services/Finance/ProfitLossService.php`

Both services filter with:
```php
->when($branchIds, fn ($q) => $q->whereIn('journal_lines.branch_id', $branchIds))
```
Journal lines that have `branch_id = NULL` (e.g. some system accounts, equity postings, or any line created without specifying a branch) will be excluded when a branch filter is applied. This means branch-filtered P&L and Balance Sheet can understate totals.

**Fix:** Change to: `->when($branchIds, fn ($q) => $q->where(fn ($q2) => $q2->whereIn('journal_lines.branch_id', $branchIds)->orWhereNull('journal_lines.branch_id')))` — or ensure all journal lines always carry a branch_id.

---

### BUG-054 🟡 OpeningBalanceBatch::nextBatchNo() has the same sequential-read race condition as JournalService::nextEntryNo()
**File:** `app/Services/Finance/OpeningBalanceService.php` → `nextBatchNo()`

Same pattern: reads max batch_no, increments, inserts. Two concurrent draft creations on the same date will collide. Low probability in practice since opening balance is a rare admin action. **Severity:** 🟢 LOW.

---

### BUG-055 🟡 FinancialExportService::generalLedgerLines() has a hard limit of 5000 rows with no pagination or warning
**File:** `app/Services/Finance/FinancialExportService.php`

```php
->limit($limit)  // default 5000
```
A busy tenant with many transactions will silently receive a truncated GL export. There is no "truncated" warning returned to the UI. An accountant downloading what they believe is the complete GL will get incomplete data without knowing.

**Fix:** Either paginate, or return a `truncated: true` flag alongside the data so the UI can warn the user.

---

## SECTION 15: SUBSCRIPTION / SAAS BUGS 🟠

### BUG-056 🟠 TenantSubscriptionAccessService: trial_ends_at check is inconsistent — subscriptionIsUsable() uses subscription.trial_ends_at but subscriptionStatus() uses both subscription and tenant.trial_ends_at
**File:** `app/Services/Saas/TenantSubscriptionAccessService.php`

`subscriptionIsUsable()` checks `$subscription->trial_ends_at`. But `subscriptionStatus()` computes the trial banner message from `$subscription->trial_ends_at`. The `Tenant` model also has its own `trial_ends_at` (set in `TenantProvisioner`). If these two values drift (e.g. subscription extended but tenant row not updated), the middleware gate and the UI banner will show different expiry dates. A tenant could be blocked from accessing the app while the banner says they have days left (or vice versa).

**Fix:** Canonicalise trial expiry on ONE field — either always `subscription.trial_ends_at` or always `tenant.trial_ends_at`, and update both atomically when extending.

---

### BUG-057 🟠 TenantSubscriptionAccessService::currentTenantUsage() counts ALL users including soft-deleted ones if SoftDeletes is used
**File:** `app/Services/Saas/TenantSubscriptionAccessService.php`

```php
'users' => User::query()->count(),
```
If `User` uses `SoftDeletes` (and it does in many Laravel apps), `User::query()->count()` counts only non-deleted users, which is correct. But if the model does NOT use `SoftDeletes` and users are hard-deleted, deleted users are not counted. The inconsistency is that `Branch` and `Terminal` are also counted with `::query()->count()` — without confirming whether they use `SoftDeletes`.

**More importantly:** the count should match what the admin sees (active resources). Inactive users, branches, and terminals are counted — e.g. a branch with `status = 'inactive'` still counts toward the branch limit. A tenant could be blocked from creating a branch because of an old inactive branch.

**Fix:** Filter counts by active status: `Branch::where('status', 'active')->count()`, `User::where('status', 'active')->count()`, `Terminal::where('status', 'active')->count()`.

---

### BUG-058 🟡 SubscriptionBillingService::nextInvoiceNo() uses random_int in a retry loop — theoretically infinite loop
**File:** `app/Services/Saas/SubscriptionBillingService.php`

```php
do {
    $seq = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    $candidate = $prefix . $seq;
} while (SubscriptionInvoice::where('invoice_no', $candidate)->exists());
```
With only 9,999 possible values per day, if a tenant generates 9,999 invoices in one day (unlikely but possible for a high-volume automated system), this loop never terminates. Additionally the `exists()` query runs outside any transaction, so two concurrent calls can still collide.

**Fix:** Use a sequential counter (same as `nextBatchNo()` pattern) or a UUID-based suffix.

---

### BUG-059 🟡 EnsureTenantSubscriptionAccess: subscriptionIsUsable() blocks access the moment current_period_ends_at passes, even before the daily expiry sweep runs
**File:** `app/Services/Saas/TenantSubscriptionAccessService.php` → `subscriptionIsUsable()`

```php
return !$subscription->current_period_ends_at
    || Carbon::parse($subscription->current_period_ends_at)->isFuture();
```
This is a "defense-in-depth" check that blocks access at midnight on the expiry date. But it means tenants who have renewed (paid a new invoice) but whose `current_period_ends_at` hasn't been updated yet (admin hasn't verified the payment) will be locked out immediately at period end even if they've already paid. The `activateSubscriptionFromPaidInvoice()` updates `current_period_ends_at` only when a payment is marked verified — there's no grace period.

**Impact:** Tenants can be locked out of their live POS on the day of renewal if the admin hasn't verified their payment yet. This is a customer experience/SLA risk.

**Fix:** Add a configurable grace period (e.g. 3 days) before blocking access for `status = 'active'` subscriptions past their period end.

---

## SECTION 16: REMAINING CROSS-CUTTING BUGS 🟡

### BUG-060 🟠 DashboardController: all queries run independently — 8 separate DB queries with no caching
**File:** `app/Http/Controllers/Tenant/DashboardController.php`

The dashboard executes 8+ separate queries: `todayStats`, `cashToday`, `cardToday`, `openShifts`, `failedPrints`, `lowStockCount`, `expiryCount`, `topProducts`, `last7Days`. Each is an independent DB call. The `SalesOrder` table is queried 7 times with different `SELECT` clauses when a single query with multiple aggregates would suffice.

**Impact:** Dashboard load will be slow as data grows. On a production server with 100k orders this will visibly lag.

**Fix:** Combine the sales stats into one `selectRaw` query with multiple aggregates. Cache the dashboard data for 5 minutes using Redis/Laravel cache.

---

### BUG-061 🟡 SalesReportService::items() groups by product_name string — two products with the same name merge into one row
**File:** `app/Services/Reports/SalesReportService.php` → `items()`

```php
->groupBy('sales_order_lines.product_name', 'sales_order_lines.variant_name', 'categories.name')
```
Grouping by the stored `product_name` string (not `product_id`) means two different products that happen to have the same name will be merged into a single report row with combined qty/revenue. This is especially risky when product names are changed after sales are posted (the old name is stored on the line).

**Fix:** Group by `product_id` and `product_variant_id` (which are also on `sales_order_lines`) then use `product_name` for display.

---

### BUG-062 🟡 GoodsReceiptController: PO status updated to 'received' even on partial GRN receipt
**File:** `app/Http/Controllers/Tenant/GoodsReceiptController.php` → `store()`

```php
if ($po && $po->status === 'approved') {
    $po->update(['status' => 'received']);
}
```
Any GRN against an approved PO marks it `received`, even if only 10 of 100 ordered items were received. There is no check of whether all PO lines are fully received. A PO for 100 units with a GRN of 10 units will show as `received` in the PO list, hiding the fact that 90 units are still pending.

**Fix:** Compare total `quantity_ordered` vs total `quantity_received` across all GRNs for the PO. Set `status = 'partially_received'` for partials, `'received'` only when all lines are fully received.

---

### BUG-063 🟡 OpeningBalanceService: GL posting called INSIDE the transaction but void GL reversal is called OUTSIDE
**File:** `app/Services/Finance/OpeningBalanceService.php`

In `post()`:
```php
return DB::transaction(function () {
    ...
    $entry = $this->journal->post(...);  // ← inside transaction
    $this->syncCashBankOnPost(...);
    ...
});
```

In `void()`:
```php
$batch = DB::transaction(function () {
    $this->syncCashBankOnVoid(...);  // ← cash/bank inside
    ...
});
// GL reversal OUTSIDE:
$this->journalPosting->reverseForSource(...);
```

The `post()` method calls `$this->journal->post()` inside the transaction (unlike `SalesService` which intentionally does GL outside). This means if the transaction rolls back after the journal is committed (as a savepoint), the journal is also rolled back — which is correct. But the asymmetry with `void()` (cash/bank inside, GL outside) is inconsistent. If the void DB transaction succeeds but `reverseForSource` throws, the cash/bank is restored but the GL is not reversed — batch is `void` status but GL still shows the original debit.

**Fix:** Move `reverseForSource` call inside the void transaction, same pattern as `post()`.

---

### BUG-064 🟡 CustomerReceivableService::recordCreditSale() — customer balance can go negative if called multiple times for the same sale
**File:** `app/Services/Finance/CustomerReceivableService.php`

The idempotency guard checks for an existing `entry_type = 'sale'` ledger row before creating one. But `$sale->balance_due` and `$sale->payment_status` are updated unconditionally every time. If this method is called twice (e.g. a retry after a failure after the `lockForUpdate` but before the ledger was written), the sale's `balance_due` gets set again, and the customer `current_balance` gets incremented again even though the first call's ledger write already ran.

More critically: there's a window between `lockForUpdate` on the sale and `lockForUpdate` on the customer. Two concurrent credit sale recordings for different sales of the same customer can read the same customer `current_balance` and both write conflicting values.

**Fix:** Lock the customer row BEFORE locking the sale row (consistent lock ordering to prevent deadlocks), and ensure the entire balance update is inside one atomic block.

---

## UPDATED SUMMARY TABLE (new bugs from 100-commit sweep)

| # | Severity | Area | One-line description |
|---|---|---|---|
| 042 | 🔴 CRITICAL | Purchasing | Supplier balance update has no row lock — concurrent GRNs corrupt balance |
| 047 | 🟠 HIGH | Split Bill | Modifiers not copied to split lines — modifier stock never consumed |
| 043 | 🟠 HIGH | Purchasing | GoodsReceipt show uses class name as reference_type — inconsistent with rest of system |
| 044 | 🟠 HIGH | Purchasing | postBill() calls JournalPostingService inside DB transaction — GL errors silently swallowed |
| 048 | 🟠 HIGH | Split Bill | Service charge not recalculated on split — lost for split bill payments |
| 056 | 🟠 HIGH | SaaS | Trial expiry stored in two places — middleware and banner can show different dates |
| 057 | 🟠 HIGH | SaaS | Plan limit counts include inactive branches/users/terminals |
| 062 | 🟠 HIGH | Purchasing | PO marked 'received' on partial GRN — remaining outstanding items hidden |
| 059 | 🟡 MEDIUM | SaaS | No grace period after period end — tenants locked out same day renewal payment is pending |
| 053 | 🟡 MEDIUM | Finance | Branch-filtered P&L/Balance Sheet excludes null-branch journal lines |
| 050 | 🟡 MEDIUM | Inventory | Stock count system_quantity is stale snapshot — variance wrong if sales happen during count |
| 051 | 🟡 MEDIUM | Inventory | Stock snapshot uses first() not sum() — multi-batch products show wrong system qty |
| 063 | 🟡 MEDIUM | Finance | Opening balance void: cash/bank reversed inside tx but GL reversal outside — mismatch on failure |
| 064 | 🟡 MEDIUM | Finance | Customer balance double-increment possible on concurrent credit sales |
| 045 | 🟡 MEDIUM | Purchasing | SupplierPayableService supplier balance race (same as BUG-042) |
| 049 | 🟡 MEDIUM | Split Bill | Promotion not re-evaluated on remaining held sale after split |
| 055 | 🟡 MEDIUM | Finance | GL export silently truncated at 5000 rows with no warning |
| 061 | 🟡 MEDIUM | Reports | Sales items report groups by product_name — products with same name merge |
| 060 | 🟡 MEDIUM | Performance | Dashboard runs 8+ independent queries — no caching |
| 046 | 🟡 MEDIUM | Finance | CustomerPaymentNo race condition — duplicate payment numbers possible |
| 058 | 🟢 LOW | SaaS | Invoice number generator uses random_int retry loop — theoretically infinite |
| 054 | 🟢 LOW | Finance | OpeningBalanceBatch nextBatchNo() race condition |
| 052 | 🟢 LOW | Finance | Manufacturing variance accounts treated as COGS in P&L — convention issue |

---

## FINAL CONSOLIDATED FIX PRIORITY (All 64 bugs ranked)

### 🔴 Fix immediately (data integrity / financial corruption)
1. **BUG-006** — InventoryService wrong DB connection (stock deducted, sale can rollback)
2. **BUG-007** — StockBalance no row lock (concurrent oversell)
3. **BUG-002** — Cash/bank balance no transaction (concurrent wrong totals)
4. **BUG-042** — Supplier balance no row lock (concurrent GRN corruption)
5. **BUG-001** — Recipe COGS double-credits GL account 1400
6. **BUG-003** — Promo usage_limit not atomic (concurrent bypass)

### 🟠 Fix this sprint (incorrect business logic, security)
7. **BUG-047** — Modifier stock not consumed on split bill
8. **BUG-011** — Sales return allows returning more than sold
9. **BUG-041** — Manager PIN endpoint has no rate limiting
10. **BUG-013** — Promo requires_code bypassed with no code submitted
11. **BUG-008** — Modifier linked_unit ignored in stock deduction
12. **BUG-027** — Return refund hardcoded CASH-MAIN/BANK-MAIN
13. **BUG-009** — Recipe unit conversion failure silently uses wrong quantity
14. **BUG-025** — EnsureRoutePermission whitelist lets any user call printing jobs
15. **BUG-044** — postBill() GL call inside DB transaction
16. **BUG-017** — Kitchen Production + POS recipe double-consume ingredients
17. **BUG-004** — Sales return COGS reversal wrong account for recipe items
18. **BUG-048** — Split bill loses service charge
19. **BUG-056** — Trial expiry inconsistency between two fields
20. **BUG-057** — Plan limits count inactive resources
21. **BUG-062** — PO marked received on partial GRN

### 🟡 Fix next sprint (edge cases, accuracy)
22-41: BUG-032, 034, 010, 031, 053, 050, 051, 063, 064, 045, 049, 055, 061, 060, 046, 012, 021, 020, 024, 014
