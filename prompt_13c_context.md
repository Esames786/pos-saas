# Prompt 13C — Measurable / Weighted Item Support
## GPT Review & Implementation Context File
### Generated: 2026-06-09 | Branch: main | Last commit: 3fb2490

---

## Project Summary

Multi-tenant SaaS POS (Laravel 12 / PHP 8.3).
Master DB: `pos_saas_master`. Tenant DB per client (e.g. `pos_tenant_demo`).
Tenant routes domain-scoped: `{subdomain}.pos-saas.test`.
All tenant Blade links use `url('/path')`, never `route('tenant.*')`.
Auth guard for tenants: `tenant`.

---

## What Was Already Built (Prompts 1–13B)

```
Prompt 1–10:  Core POS, inventory, purchasing, restaurant, printing
Prompt 11:    KOT + Receipt printing (ESC/POS via LAN agent)
Prompt 11C:   KOT delta logic, payload-based preview, print retry
Prompt 12B:   Promotions, service charge, tips, void reasons, manager approvals
Prompt 13A:   Business dashboard, 20 report views (sales/inventory/restaurant/kitchen/audit/print)
Prompt 13B:   Restaurant POS table state hotfix:
              - Mode switch clears stale table_session_id (URL reload)
              - Selected table card: highlighted + "Selected / Continue" button
              - Duplicate held sale guard: 409 TABLE_HAS_OPEN_ORDERS + SweetAlert choice modal
              - billRequested: back() instead of hard board redirect
              - Service charge: refreshServerTotals() debounced quote on every cart change
              - Promo quote bug fixed (wrong element IDs)
              - Receipt + thermal printer: service_charge + tip rows added
              - New routes: POST /api/pos/totals/quote, GET /api/pos/table-sessions/{id}/open-orders
```

---

## What Prompt 13C Must Deliver

**Measurable / weighted / decimal-quantity item support for mart/grocery/fabric stores.**

Examples of mart items:
- 1.4 kg tomatoes (price per kg)
- 0.75 kg meat (price per kg)
- 2.5 L oil (price per litre)
- 0.250 kg dry fruit (price per 100g or kg)
- 3.25 m fabric (price per meter)
- 500 g loose rice / sugar / flour

Current system works only for "click = add 1 unit" style products.
Measurable products need a quantity-entry modal when added to cart.

---

## CURRENT STATE — What Already Exists

### 1. `units` table (EXISTS, COMPLETE)
```sql
units:
  id, code, name,
  unit_type ENUM('quantity','weight','volume','length'),
  base_factor DECIMAL(14,6),
  is_base BOOLEAN,
  is_active BOOLEAN
```
Unit types already cover weight/volume/length. Base factor supports conversions (e.g. 1000g = 1kg).

### 2. `products` table (EXISTS — MISSING measurable flags)
```sql
products:
  id, category_id,
  unit_id FK→units (EXISTS — linked to base selling unit)
  sku, name, slug,
  product_type ENUM('simple','recipe','hybrid','service'),
  is_sellable, is_purchasable, is_stock_tracked,
  has_variants, has_expiry, requires_batch,
  default_purchase_price, default_selling_price DECIMAL(14,2),
  is_taxable, tax_rate_percent,
  description, image_path,
  status ENUM('active','inactive')
```

**MISSING columns (need migration):**
```sql
allow_decimal_quantity BOOLEAN DEFAULT false
quantity_step          DECIMAL(14,3) DEFAULT 1.000   -- e.g. 0.001 for gram-level
selling_unit_label     VARCHAR(20) NULLABLE           -- display label: 'kg', 'litre', 'metre'
price_per_unit_label   VARCHAR(20) NULLABLE           -- e.g. 'per kg', 'per 100g'
```

Alternatively use the existing `unit_id` + `unit.unit_type` to derive behaviour:
- `unit_type = 'quantity'` → piece item (add by 1)
- `unit_type IN ('weight','volume','length')` → measurable item (open qty modal)

This avoids a new boolean column and reuses existing data.

### 3. `product_variants` table (EXISTS)
```sql
product_variants:
  id, product_id,
  sku, name, barcode,
  purchase_price, selling_price DECIMAL(14,2),
  reorder_level, reorder_quantity DECIMAL(14,3),   -- ← already decimal!
  is_default, is_active
```
Variants already store decimal reorder quantities.

### 4. Inventory (EXISTS — already decimal)
```sql
stock_balances: quantity_on_hand DECIMAL(14,3)
inventory_movements: quantity DECIMAL(14,3)
inventory_batches: quantity_received, quantity_on_hand DECIMAL(14,3)
```
`InventoryService::postOutFefo()` and `postIn()` both accept `float $quantity` — no changes needed.

### 5. SalesOrderLine (EXISTS — already decimal)
```sql
sales_order_lines:
  quantity DECIMAL(14,3)   -- ← already supports 1.400
  unit_price DECIMAL(14,4)
  line_total DECIMAL(14,2)
```

### 6. SalesOrderController validation (EXISTS — already allows decimal)
```php
'lines.*.quantity' => ['nullable', 'numeric', 'min:0.001']
```
Already allows decimal. No change needed.

### 7. HeldSaleController validation (EXISTS — already allows decimal)
```php
'lines.*.quantity' => 'nullable|numeric|min:0.001'
```
Already allows decimal. No change needed.

### 8. GoodsReceiptController (EXISTS — already allows decimal)
```php
'lines.*.quantity_received' => 'required|numeric|min:0.001'
```
Already allows decimal. No change needed.

### 9. POS addToCart function (CURRENT — only increments by 1)
```js
// app/resources/views/tenant/pos/index.blade.php ~ line 972
function addToCart(product, variant) {
    const key      = product.id + ':' + (variant ? variant.id : 0);
    const existing = cart.find(function (item) { return item.key === key; });

    if (existing) {
        existing.quantity += 1;    // ← PROBLEM: always adds 1
    } else {
        cart.push({
            ...
            quantity: 1,           // ← PROBLEM: always starts at 1
        });
    }

    renderCart();
}
```

**No unit_type, no quantity_step, no modal trigger exists in POS payload.**

### 10. POS product payload (CURRENT — missing unit fields)
In `POSController::index()` ~ line 151, the productsPayload map has:
```php
return [
    'id', 'name', 'sku', 'category_id', 'category_name',
    'price', 'is_stock_tracked', 'is_taxable', 'tax_rate_percent',
    'barcodes', 'branch_prices', 'variants', 'stock_by_branch'
    // MISSING: unit_type, allow_decimal_quantity, quantity_step,
    //          unit_name, selling_unit_label
];
```

### 11. EscPosPayloadService receipt (CURRENT — line quantity as decimal already)
```php
// app/Services/Printing/EscPosPayloadService.php ~ line 63
$qty   = number_format((float) $line->quantity, 3);
```
Already renders decimal quantities on thermal receipt. ✓

### 12. SalesReportService items (CURRENT — check decimal display)
```php
// Already uses: COALESCE(SUM(sales_order_lines.quantity), 0) as qty_sold
```
Aggregates are decimal-safe. View needs to display with 3 decimal places.

---

## WHAT NEEDS TO BE DONE FOR PROMPT 13C

### Task 1 — Product Schema: Add allow_decimal_quantity flag

**Option A (Recommended): Use existing unit_type**
No migration needed. In POS payload, include `unit_type` from `product->unit`.
- `unit_type = 'quantity'` → piece item
- `unit_type IN ('weight','volume','length')` → open qty modal

**Option B: Add explicit column**
```php
// New migration:
$table->boolean('allow_decimal_quantity')->default(false);
$table->decimal('quantity_step', 14, 3)->default(1.000);
$table->string('selling_unit_label', 20)->nullable(); // 'kg', 'L', 'm'
```

Use Option A unless product setup UI needs explicit override.

### Task 2 — POS Product Payload: Include unit fields

In `POSController::index()`, add to productsPayload:
```php
'unit_id'               => $product->unit_id,
'unit_name'             => $product->unit?->name,        // 'Kilogram', 'Litre'
'unit_code'             => $product->unit?->code,        // 'kg', 'L'
'unit_type'             => $product->unit?->unit_type,   // 'quantity','weight','volume','length'
'allow_decimal_qty'     => $product->unit?->unit_type !== 'quantity',
'quantity_step'         => $product->unit?->unit_type === 'quantity' ? 1 : 0.001,
```

### Task 3 — POS addToCart: Quantity Modal for Measurable Items

Replace hardcoded `quantity: 1` / `existing.quantity += 1` with:

```js
function addToCart(product, variant, forceQty) {
    const key        = product.id + ':' + (variant ? variant.id : 0);
    const existing   = cart.find(item => item.key === key);
    const isMeasurable = product.allow_decimal_qty || product.unit_type !== 'quantity';

    if (forceQty !== undefined) {
        // Called from qty modal with explicit qty
        const qty = parseFloat(forceQty) || 0;
        if (qty <= 0) return;

        if (existing) {
            existing.quantity = parseFloat((existing.quantity + qty).toFixed(3));
        } else {
            const price = productPrice(product, variant);
            cart.push({
                key, product_id: product.id,
                product_variant_id: variant ? variant.id : null,
                name: product.name,
                variant_name: variant ? variant.name : null,
                unit_code: product.unit_code || '',
                quantity: qty,
                unit_price: price,
                discount_amount: 0,
                tax_amount: lineTax(product, qty, price, 0),
                product, variant: variant || null,
            });
        }

        renderCart();
        return;
    }

    if (isMeasurable) {
        openQtyModal(product, variant);
        return;
    }

    // Piece item: original increment-by-1 behavior
    if (existing) {
        existing.quantity += 1;
    } else {
        const price = productPrice(product, variant);
        cart.push({
            key, product_id: product.id,
            product_variant_id: variant ? variant.id : null,
            name: product.name, variant_name: variant ? variant.name : null,
            unit_code: product.unit_code || '',
            quantity: 1,
            unit_price: price,
            discount_amount: 0,
            tax_amount: lineTax(product, 1, price, 0),
            product, variant: variant || null,
        });
    }

    renderCart();
}
```

### Task 4 — Quantity Entry Modal HTML

Add to pos/index.blade.php (near other modals):

```html
<!-- Quantity Entry Modal (measurable items) -->
<div class="modal fade" id="qtyEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="qty-modal-title">Enter Quantity</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">
                        Quantity <span id="qty-modal-unit" class="text-muted"></span>
                    </label>
                    <input type="number" id="qty-modal-input"
                           class="form-control form-control-lg text-end"
                           step="0.001" min="0.001" placeholder="0.000">
                </div>
                <div class="mb-3 d-none" id="qty-modal-amount-row">
                    <label class="form-label text-muted small">
                        Or enter amount → calculate qty
                    </label>
                    <input type="number" id="qty-modal-amount-input"
                           class="form-control form-control-sm text-end"
                           step="0.01" min="0" placeholder="Amount (Rs)">
                    <div class="form-text" id="qty-modal-amount-hint"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="qty-modal-confirm">Add to Cart</button>
            </div>
        </div>
    </div>
</div>
```

### Task 5 — Quantity Modal JS

```js
var _qtyModalProduct = null;
var _qtyModalVariant  = null;
var _qtyModal         = null;

function openQtyModal(product, variant) {
    _qtyModalProduct = product;
    _qtyModalVariant  = variant;

    document.getElementById('qty-modal-title').textContent  = product.name;
    document.getElementById('qty-modal-unit').textContent   = product.unit_code ? '(' + product.unit_code + ')' : '';

    var pricePerUnit = productPrice(product, variant);
    var amountRow    = document.getElementById('qty-modal-amount-row');
    var amountHint   = document.getElementById('qty-modal-amount-hint');

    if (pricePerUnit > 0) {
        amountRow.classList.remove('d-none');
        amountHint.textContent = 'Price: ' + money(pricePerUnit) + ' per ' + (product.unit_code || 'unit');
    } else {
        amountRow.classList.add('d-none');
    }

    var qtyInput = document.getElementById('qty-modal-input');
    qtyInput.value = '';
    document.getElementById('qty-modal-amount-input').value = '';

    if (!_qtyModal) _qtyModal = new bootstrap.Modal(document.getElementById('qtyEntryModal'));
    _qtyModal.show();
    setTimeout(function () { qtyInput.focus(); }, 300);
}

// Amount → qty calculation
document.getElementById('qty-modal-amount-input').addEventListener('input', function () {
    if (!_qtyModalProduct) return;
    var amount = parseFloat(this.value) || 0;
    var price  = productPrice(_qtyModalProduct, _qtyModalVariant);
    if (price > 0 && amount > 0) {
        document.getElementById('qty-modal-input').value = (amount / price).toFixed(3);
    }
});

document.getElementById('qty-modal-confirm').addEventListener('click', function () {
    var qty = parseFloat(document.getElementById('qty-modal-input').value) || 0;
    if (qty <= 0) {
        document.getElementById('qty-modal-input').focus();
        return;
    }
    _qtyModal.hide();
    addToCart(_qtyModalProduct, _qtyModalVariant, qty);
});

// Enter key in qty modal
document.getElementById('qty-modal-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('qty-modal-confirm').click();
});
```

### Task 6 — Cart Render: Show Unit in Cart Lines

In `renderCart()`, show `item.unit_code` next to qty:

```js
// Currently renders: qty input + × price
// Should render: qty input + unit_code + × price
// Example: "1.400 kg × 500.00 = 700.00"
```

Update the cart line HTML to include unit label.

### Task 7 — Cart Line Qty Input: Allow Decimal Step

In renderCart, the qty input should use `step="0.001"` for measurable items vs `step="1"` for piece items:

```js
var step = (item.product && item.product.allow_decimal_qty) ? '0.001' : '1';
qtyInput.setAttribute('step', step);
qtyInput.setAttribute('min', step);
```

### Task 8 — Receipt Blade: Show qty with 3 decimals + unit

In `resources/views/tenant/printing/documents/receipt.blade.php`:

```php
// Currently:
{{ number_format($line->quantity, 2) }}

// Should be:
{{ number_format($line->quantity, 3) }} {{ $line->unit_code ?? '' }}
```

Note: `sales_order_lines` needs a `unit_code` column or derive from product.
Simplest approach: store `unit_code` on the line at save time.

### Task 9 — SalesOrderLine: Store unit_code

Add to `sales_order_lines` migration or alter table:
```sql
unit_code VARCHAR(20) NULLABLE
```

Populate in `SalesOrderController::store()` and `HeldSaleController::store()`:
```php
'unit_code' => $product?->unit?->code,
```

### Task 10 — Sales Items Report: Display decimal qty

In `resources/views/tenant/reports/sales/items.blade.php`, change qty display:
```php
// Change:
{{ number_format($row->qty_sold, 2) }}
// To:
{{ number_format($row->qty_sold, 3) }}
```

### Task 11 — GRN / Purchase Bill View: Confirm decimal input

`resources/views/tenant/goods-receipts/create.blade.php` — verify qty inputs use:
```html
<input type="number" step="0.001" min="0.001">
```

### Task 12 — Product Edit Form: Expose unit_type

In the product create/edit form, allow selecting unit and show unit_type.
User should be able to set a product to weight/volume/length so POS knows
to open the quantity modal.

---

## FILES TO READ BEFORE IMPLEMENTING

```
1. app/Http/Controllers/Tenant/POSController.php          (productsPayload map)
2. resources/views/tenant/pos/index.blade.php              (addToCart, renderCart, JS ~ line 972)
3. app/Models/Tenant/Product.php                           (fillable, relations, casts)
4. app/Models/Tenant/Unit.php                              (unit_type enum)
5. database/migrations/tenant/0001_01_01_000005_create_catalog_tables.php  (products + units schema)
6. app/Http/Controllers/Tenant/SalesOrderController.php   (store, validateSale, line resolution)
7. app/Http/Controllers/Tenant/HeldSaleController.php     (store, line creation)
8. app/Services/Inventory/InventoryService.php             (postOutFefo, postIn)
9. resources/views/tenant/printing/documents/receipt.blade.php
10. app/Services/Printing/EscPosPayloadService.php          (receipt method, line qty)
11. resources/views/tenant/reports/sales/items.blade.php    (qty_sold display)
12. resources/views/tenant/goods-receipts/create.blade.php  (qty inputs)
13. app/Http/Controllers/Tenant/GoodsReceiptController.php  (validation)
14. resources/views/tenant/products/create.blade.php        (product form, unit selector)
```

---

## LOCKED RULES

```
1. Do not touch Prompt 11C printing/KOT logic.
2. Do not touch SalesOrderController's existing payment/promo/service-charge logic.
3. Do not start Prompt 14A-1 (SaaS billing) yet.
4. Keep Blade tenant links using url('/path'), never route('tenant.*').
5. All changes must be backward-compatible:
   - Existing piece items (unit_type = 'quantity') must continue to work as before
   - addToCart click = +1 for piece items (no modal)
   - Modal only for weight/volume/length unit_type
6. KOT still only applies to dine_in / restaurant items — not affected.
7. Do not break existing receipt layout.
8. Quantity validation already allows decimal (min:0.001) — do not change.
9. InventoryService already handles decimal deduction — do not change.
```

---

## CURRENT GAP SUMMARY

| Layer | Status | Gap |
|-------|--------|-----|
| `units` table & model | ✅ Complete | None |
| `products.unit_id` FK | ✅ Exists | Not exposed in POS payload |
| `unit_type` enum | ✅ Exists | Not used to trigger modal |
| `allow_decimal_quantity` flag | ❌ Missing | Need column or use unit_type |
| `quantity_step` on product | ❌ Missing | Optional (can default 0.001) |
| `selling_unit_label` | ❌ Missing | Optional (use unit.code) |
| POS addToCart qty modal | ❌ Missing | Full implementation needed |
| POS product payload unit fields | ❌ Missing | 5 fields to add |
| Cart line unit display | ❌ Missing | Show `1.400 kg × 500` |
| Cart qty input step=0.001 | ❌ Missing | Conditional step attr |
| Receipt `unit_code` per line | ❌ Missing | Store on line or derive |
| `sales_order_lines.unit_code` | ❌ Missing | Optional column |
| ESC/POS thermal qty decimal | ✅ Already `number_format(qty, 3)` | None |
| SalesOrder validation decimal | ✅ Already `min:0.001` | None |
| HeldSale validation decimal | ✅ Already `min:0.001` | None |
| GRN validation decimal | ✅ Already `min:0.001` | None |
| InventoryService decimal | ✅ Already `float $quantity` | None |
| Sales items report qty | ⚠️ Shows 2dp | Change to 3dp |
| GRN qty input `step` attr | ⚠️ Unverified | Check HTML input step |
| Product form unit selector | ⚠️ Unverified | Confirm unit_type visible |

---

## MIGRATION NEEDED

```php
// database/migrations/tenant/XXXX_add_measurable_fields_to_products.php
Schema::connection('tenant')->table('products', function (Blueprint $table) {
    $table->boolean('allow_decimal_quantity')->default(false)->after('has_variants');
    $table->decimal('quantity_step', 14, 3)->default(1.000)->after('allow_decimal_quantity');
    $table->string('selling_unit_label', 20)->nullable()->after('quantity_step');
});

Schema::connection('tenant')->table('sales_order_lines', function (Blueprint $table) {
    $table->string('unit_code', 20)->nullable()->after('variant_name');
});
```

OR skip the migration and derive from `unit_type`:
- `product.unit?.unit_type !== 'quantity'` → measurable (use unit.code as label)
- This avoids migration but requires eager-loading `unit` on products

**Recommendation: Skip new product columns. Use `unit.unit_type` as the trigger. Add `unit_code` to `sales_order_lines` only.**

---

## SCALE BARCODE SUPPORT (Deferred — note for future)

Scale barcode formats (EAN-13 weighted):
- Prefix 20–23: embedded weight or price
- Structure: `PP PPPPP WWWWW C` (product code + weight + check)

This is **Prompt 13D scope**, not 13C. Do not implement scale barcode in 13C.
Just ensure the `addToCart(product, variant, forceQty)` signature supports being
called with an explicit quantity so scale barcode handler can call it later.

---

## IMPLEMENTATION SEQUENCE FOR PROMPT 13C

```
Step 1: Read all 14 files listed above
Step 2: Add unit fields to POS product payload (POSController)
Step 3: Add qty modal HTML to pos/index.blade.php
Step 4: Add openQtyModal() + qty modal JS to pos/index.blade.php
Step 5: Update addToCart() to check allow_decimal_qty / unit_type
Step 6: Update renderCart() to show unit_code + 3dp qty + conditional step
Step 7: Migration: add unit_code to sales_order_lines
Step 8: Populate unit_code in SalesOrderController::store() line creation
Step 9: Populate unit_code in HeldSaleController::store() line creation
Step 10: Update receipt.blade.php to show qty with 3dp + unit_code
Step 11: Update EscPosPayloadService if line unit_code needs showing
Step 12: Update sales items report view: 3dp qty display
Step 13: Verify GRN create view has step="0.001" on qty inputs
Step 14: Verify product create/edit form shows unit type
Step 15: php artisan optimize:clear
Step 16: Manual test: 1.4kg sale → hold → receipt → inventory deduction
Step 17: Regression: piece item still increments by 1, no modal
Step 18: git commit -m "Prompt 13C: measurable/weighted item support"
```

---

## COMMIT HISTORY (recent)

```
3fb2490 fix(13B): post-validation corrections
6dfcfe1 Prompt 13B: stabilize restaurant POS table state and service charge display
81616fe fix: remove double page-wrapper from all report, dashboard, and 12B views
2e747bc fix: replace layouts.tenant with layouts.app across all report and sales control views
9efb22b Prompt 13A Phase 2B: complete report views sidebar and regression
1865d7d Prompt 13A Phase 2: secondary reports infrastructure
91d350c Prompt 13A Phase 1: business dashboard, sales reports, shift report, stock valuation
c3bf59b Prompt 12B: complete sales controls integration
```

---

## SAFETY NOTES

1. `addToCart` is called from 3 places in POS JS:
   - product tile click (line ~961)
   - barcode scan match (line ~2382)
   - KOT delta / recalled sale (indirectly via recallSale loading cart lines)
   The `forceQty` parameter must default to `undefined` and be backward-compatible.

2. `buildInputs()` already handles decimal qty via `item.quantity` float — no change needed.

3. `lineTax(product, qty, price, 0)` already receives float qty — no change needed.

4. `refreshServerTotals()` (added in 13B) already sends line quantities as floats to quote endpoint — no change needed.

5. KOT `kot_sent_quantity` is already stored as float — no change needed.

6. The `quantity_step` on the cart input should NOT be forced to 0.001 for piece items — leave as 1 to prevent cashier typing 1.5 for a piece item accidentally.
