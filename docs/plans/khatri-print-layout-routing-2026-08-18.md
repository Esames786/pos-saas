# Khatri — Print Layout + POS Fields + KOT Routing Overhaul (LOCKED SPEC)

Date: 2026-08-18
Tenant: khatribiryani (LIVE). Branch id = 1.
Status: **Requirements locked by owner. Not yet built.**

Single source of truth. Five phases; each ships on its own.

---

## Guiding rules (apply to every phase)

1. **Every font-size change must be a Layout Setting, never a hardcoded number.** If a row or the TIME line gets smaller, that size must be editable from *Edit Layout* so the owner can retune it without a code change.
2. **Preview must match the printed receipt.** The on-screen Bill Preview and the thermal receipt render the *same* content (same fields, same table info, same row size). Today they diverge — fix it.
3. **A POS order field must be handled in ALL write paths** — `POSController::billPreview`, `HeldSaleController::store` (Hold), `SalesOrderController` (Review & Pay/finalize) — **and recall** (`ajaxList` + `recallHeldSale`). The earlier dropped-delivery-charge bug was exactly one forgotten write path. Edge (`EdgeLocalPosService`) mirrors the same rules.
4. **Do not break Delivery or Dine-In.** Both are happy (single terminal, single order type). Every change must be byte-for-byte identical for them on the morning shift.
5. Khatri is LIVE: green gates → commit → backup → deploy → owner verifies with a physical print. No destructive ops without a fresh backup.

---

## PHASE 1 — Layout changes (Receipt, Bill Preview, KOT, Reminder) — ✅ BUILT (commit 468b9ef, NOT deployed)

Applies to all four documents unless noted. Scope = only what the owner marked on the screenshots.

**Status:** code-complete + green (unit `LayoutColumnDividerTest`, MySQL `LayoutRowDividerMySqlTest` covering normal+variant+combo+modifier; print-related MySQL regressions pass). All four settings default to current behavior — no tenant output changes until a value is set in Edit Layout. **Pending before it's visible at Khatri:** full MySQL suite → deploy → set Khatri's kot/receipt layout values (dividers on, category off, item/time fonts) which the owner can now tune live.

### 1.1 Reduce item-row font size
- Rows too big; long names wrap onto 2–3 lines ("Chicken Biryani (1/2 kg)" over 3 lines).
- **Dynamic:** driven by existing per-document fields — `font_size` (receipt/reminder), `kot_font_size` (KOT). Lowering shrinks rows; already editable in *Edit Layout*.
- **Bill Preview must read the SAME `font_size`** as the thermal receipt so preview matches (today the preview uses its own large font → the wraps the owner circled).

### 1.2 Add vertical column-divider lines
- `│` separators between item-table columns:
  - Receipt / Preview: `Item │ Qty │ Rate │ Amount`
  - KOT / Reminder: `QTY │ ITEM` (kitchen tickets carry no money)
- Thermal (ESC/POS): `|` glyph at column boundaries. Preview (HTML): CSS vertical borders.
- **Dynamic toggle:** new field `show_column_dividers` (bool, default **true**).

### 1.3 Remove the category / sub-category header line
- Remove the `[ BEVERAGES ]/[ DESSERTS ]` line from the **KOT** **and the Reminder**.
- **Dynamic toggle:** new field `show_category_header` (bool, default **false** = removed).

### 1.4 Shrink the TIME line (KOT + Reminder)
- `TIME: 09:18 PM` + the date under it print at the big row scale — make smaller than the item rows.
- **Dynamic:** new field `time_font_size` (nullable int); null → one band below the document font.

### 1.5 Preview ↔ Receipt parity (table info)
- `show_table_info` is **ON** for Khatri. The **printed receipt correctly shows** `Table: {table_no}`; the **Bill Preview does NOT** (the preview endpoint nulls the table relation; POS JS never sends the table id).
- Fix: `billPreview()` JS sends `restaurant_table_id` + `restaurant_table_session_id`; `POSController::billPreview()` validates + hydrates the relations instead of nulling. Blade already renders the line once present.

### New Layout Settings fields (additive migration on `receipt_layout_settings`)
| Column | Type | Default | Applies to |
|---|---|---|---|
| `show_column_dividers` | boolean | true | receipt, kot, reminder (+ preview reads receipt row) |
| `show_category_header` | boolean | false | kot, reminder |
| `time_font_size` | small int, nullable | null | kot, reminder |

Row font reuses existing `font_size` / `kot_font_size` (already dynamic).

### Phase-1 files
- `app/Services/Printing/EscPosPayloadService.php` — `receipt()` ~L361, `kot()` ~L550, `buildReminder()` ~L227: dividers, category header gated, TIME uses `time_font_size`.
- `resources/views/tenant/printing/documents/receipt.blade.php` — divider borders + row font bound to `font_size`.
- `resources/views/tenant/pos/index.blade.php` — `billPreview()` JS sends table ids.
- `app/Http/Controllers/Tenant/POSController.php` — `billPreview()` hydrates table relations.
- Layout controller + *Edit Layout* blade — surface the 3 new fields.
- Migration + provisioner/seeder defaults for the 3 columns.
- Tests: dividers present, category header absent, TIME smaller, preview shows Dine-In table.

---

## PHASE 2 — POS order-type field rules (vehicle / waiter + list display)

### §2.1–2.5 STATUS: ✅ BUILT (cloud path) — NOT deployed. Takeaway drops vehicle everywhere (billPreview/Hold/Finalize gates → quick_sale-only); Quick Sale requires vehicle + waiter (server `required_if:order_type,quick_sale` in all 3 write paths + a JS `requireQuickSaleFields()` guard at the 3 submit sites); a Quick-Sale waiter picker (inline select reusing `$waiters`, posts `restaurant_waiter_id` on the sale form) shows only for quick_sale; Held + Recent list payloads + rows now show waiter/vehicle/table; recall re-hydrates the waiter; finalize/close clears it. Tests: `QuickSaleFieldRulesMySqlTest` + updated HeldSaleFieldPersistence/SaleCashSemantics/ShiftPosIntegration/EdgeIdentityFlow (all green). **DEFERRED: Edge parity** — `EdgeLocalPosService` already excludes takeaway vehicle (quick_sale-only ✓), but standalone Quick-Sale *waiter* capture on the Edge (`EdgeLocalPosController::storeHeldSale`/checkout) is NOT added; Edge is dormant/not-deployed, so the cloud path (what Khatri runs) is complete and this is a follow-up.


Field names confirmed in code: `sales_orders.vehicle_number` (string ≤50) and `sales_orders.restaurant_waiter_id` (→ `restaurantWaiter`). Vehicle today is captured for **both** `quick_sale` and `takeaway` (HeldSaleController L262/L572/L605); waiter today is populated **only** from a dine-in table session (L628).

### 2.1 Takeaway — remove vehicle number ENTIRELY
- Client confirmed takeaway does not use a vehicle number.
- **Frontend:** remove the vehicle input for takeaway (earlier temporary hide → make permanent).
- **Backend:** narrow vehicle capture from `['quick_sale','takeaway']` to **`quick_sale` only** in every write path.
- **Prints:** ensure the vehicle line is order-type-guarded so takeaway never prints it.

### 2.2 Quick Sale — vehicle number REQUIRED
- `vehicle_number` mandatory for `quick_sale`. **Block Hold and Review & Pay** when empty.
- Frontend: inline validation before Hold and before Review & Pay.
- Backend: `required` when `order_type = quick_sale` in ALL write paths (billPreview, HeldSaleController::store, SalesOrderController).

### 2.3 Quick Sale — waiter selection REQUIRED
- Add a **waiter picker** to Quick Sale, attached the same way the customer picker works (Add/Search style).
- Since Quick Sale has no table session, the picker writes `restaurant_waiter_id` directly (today it only comes from a dine-in session).
- **Mandatory** before Hold / Review & Pay (frontend + backend, all write paths).
- **Customer attach:** reuse the existing Add/Search Customer mechanism for Quick Sale. **Customer is NOT mandatory** — only **vehicle + waiter** are required for Quick Sale.

### 2.4 Held Orders + Recent Orders — show waiter, vehicle, table
- The list endpoint **already returns** `vehicle_number`, `restaurantWaiter`, `restaurantTable` (HeldSaleController::ajaxList L31/L81) — the frontend just doesn't render them.
- Show contextually in **both** the Held Orders and Recent Orders modals:
  - Dine In → **Table** + **Waiter**
  - Quick Sale → **Vehicle** + **Waiter**
  - Delivery → Rider (already shown)
  - Takeaway → Customer

### 2.5 Recall re-hydration + fresh reset
- On **recall of a held order**, the **waiter must be re-attached to the order the same way `vehicle_number` and the customer are re-fetched today** — the POS form repopulates the waiter picker from the held sale's `restaurant_waiter_id` so the operator sees who was attached. (Recall path: `recallHeldSale` + `ajaxList`, then the JS that rebuilds the form.)
- After **Review & Pay (finalize)** OR **Close Order**, the POS starts **fresh** — waiter, vehicle, customer and table selections all clear, so the next order begins clean.

### 2.6 Scope EVERY sales surface to the operator's terminals + order types
Audit result (2026-08-18) — most surfaces are ALREADY scoped via `UserDataScope` (terminals + order types):
| Surface | Status |
|---|---|
| Sales Orders index (`SalesOrderController::index:43`) | ✅ scoped both |
| Sales Returns LIST (`SalesReturnController::index:25`) | ✅ scoped both |
| Dashboard (`DashboardController`) | ✅ scoped both |
| POS Recent Orders (`POSController::recentSales:715`) | ✅ scoped both |
| POS Held Orders (`HeldSaleController::ajaxList:46`) | ✅ scoped both |
| **Sales Return CREATE search** (`Ajax\SaleLookupController:29`) | ❌ **branch-only — FIX** |

**The one gap (image 3):** the "Find Sale" picker on `/sales-returns/create` hits `GET /ajax/sales` → `SaleLookupController`, which filters by `status in (paid, partially_returned)` + branch only — **no terminal, no order type**. A terminal/order-type-restricted operator therefore sees every paid sale in his branch. Fix = apply the same guard the list uses: `if ($scope->isScoped($user)) $scope->applyToSales($query, $user);` (no table alias → empty prefix). One-line-class change + a scope test.

### 2.7 Sales Orders "Held" status filter (image 2)
`held` is already a valid `sales_orders.status` value and the index query already passes `status` through + already scopes rows. The filter dropdown (`sales-orders/index.blade.php:38-45`) just lacks the option. Add `<option value="held">Held</option>`. No controller change.

### 2.8 Sales Return "Create" UX fixes
- **Refund method defaults to the original pay method (image — "cash to return type default cash").** Today `refund_method` (`create.blade.php:174`) loads blank ("Select refund method"); the sale's original payment is loaded for display only. Map the sale's original payment `method_type` → the refund enum (cash→cash, card→card, bank_transfer→bank_transfer, else other) and pre-select it (`old('refund_method', $default)`). Cashier can still override.
- **The "qty 0.1" error.** The form posts **every** line row, and each is rendered `value="0"`; the server rule is `lines.*.quantity → min:0.001` (`SalesReturnController:128`), so the items you leave at 0 are rejected ("must be at least 0.001"). The SERVICE already skips zero/over lines (`SalesReturnService:87-92`) — only the request validation is too strict. Fix: (a) drop rows with qty ≤ 0 client-side before submit, AND (b) relax the rule to `min:0` with a guard that at least one line is > 0 (so an all-zero submit still errors cleanly). Optionally add a `max` rule per line (today over-return is silently clamped, not rejected).
- **Return one item, come back for another — ALREADY CORRECT.** `sales_order_lines.returned_quantity` is tracked; on the next return the line shows **"Already Returned" + "Returnable = 0"**, the input is **disabled ("Fully returned")**, and the service hard-caps over-returns under a row lock. So a returned item cannot be returned again. **DECISION (owner, 2026-08-18): keep fully-returned lines VISIBLE-BUT-DISABLED — no change.**

### §2.6–2.8 STATUS: ✅ BUILT commit `acb1ee3` (NOT deployed). SaleLookupController scoped + create/store forged-id defence; refund method defaults to original tender; qty rule relaxed min:0.001→min:0 + at-least-one guard; Held status option. Tests: `SalesReturnCreateUxMySqlTest`.

### Phase-2 files
- `resources/views/tenant/pos/index.blade.php` — vehicle input (remove takeaway, require quick_sale), new Quick-Sale waiter picker, Hold/Review&Pay validation, held/recent list rendering, **recall re-hydration of waiter (mirroring vehicle/customer), and full form reset on finalize/close**.
- `app/Http/Controllers/Tenant/Ajax/SaleLookupController.php` — apply `UserDataScope` (terminals + order types) to the return-create sale search (§2.6).
- `resources/views/tenant/sales-orders/index.blade.php` — add the `Held` status option (§2.7).
- `resources/views/tenant/sales-returns/create.blade.php` + `app/Http/Controllers/Tenant/SalesReturnController.php` — refund-method default from original payment, drop zero lines client-side, relax `min:0.001` → `min:0` + "at least one line" guard (§2.8).
- `app/Services/Sales/SalesReturnService.php` — already skips zero/over lines; no change expected (verify).
- `app/Http/Controllers/Tenant/HeldSaleController.php` — validation + persistence (vehicle quick_sale-only + required; waiter required for quick_sale).
- `app/Http/Controllers/Tenant/POSController.php` — billPreview validation/persistence.
- `app/Http/Controllers/Tenant/SalesOrderController.php` — finalize validation/persistence.
- `app/Services/Printing/EscPosPayloadService.php` — vehicle line order-type guard (takeaway off).
- Edge parity: `EdgeLocalPosService` / `EdgeLocalPosController`.
- Tests: quick_sale blocked on Hold + finalize when vehicle OR waiter missing; takeaway never captures/prints vehicle; held/recent lists show the fields; **return-create sale search scoped to terminals+order types; return submit with untouched (0-qty) lines succeeds and returns only the filled line; refund method defaults to the original pay method; a fully-returned line cannot be returned again.**

---

## PHASE 3 — KOT routing re-key: `branch + terminal + order_type + category`

### Why
KOT routing keys on **order_type + category** (`category_printer_mappings`); receipt routing keys on **terminal**. When one terminal carries two order types (Takeaway user = Takeaway + Quick Sale on T#2), a Quick-Sale order sends the **receipt to .212 (terminal)** but the **KOT to .214 (order_type)** — split across two counters. KOT must follow the **physical terminal**, like the receipt.

### The change
- Add `terminal_id` (nullable) to `category_printer_mappings`; rebuild the unique index to include it.
- Resolver `PrintRoutingService::kotRoutesForSale()` + `kotRoutesForQuantities()` (+ `reminderRoutesForSale()`): match `terminal_id = sale.terminal_id OR terminal_id IS NULL`, with **override precedence** — a terminal-specific row wins over the NULL-wildcard for the same category (no double-print). Null-terminal sale falls back to global rows.
- Config screen `CategoryPrinterMappingController` + `category-mappings/index.blade.php`: add a **terminal** picker.
- Edge bootstrap snapshot must include the new column.

### Phase-3 files
- New migration (`terminal_id` + reindex).
- `app/Models/Tenant/CategoryPrinterMapping.php` — fillable + `terminal()`.
- `app/Services/Printing/PrintRoutingService.php` — both KOT queries + reminder, precedence, null fallback.
- `app/Http/Controllers/Tenant/CategoryPrinterMappingController.php` — validation, dedupe, wildcard.
- `resources/views/tenant/printing/category-mappings/index.blade.php` — terminal field.
- Seeders / `OnboardKhatriBiryaniCommand` / reset command — supply `terminal_id`.
- Edge: `EdgeBootstrapService` / importer / readiness.
- Tests: `PrintRoutingFoundationTest`, `PrintRoutingMySqlTest`, `KotPerCategoryMySqlTest`, `ComboModifierKotIntegrityMySqlTest`.

Receipt routing already terminal-keyed → **no change**.

---

## PHASE 4 — Khatri data update (60-mapping rewrite + user binding)

Run once after Phase 3. **Fresh backup first.** Zero downtime for Delivery/Dine-In.

### Terminals → printers
| Terminal | Counter printer | Overflow |
|---|---|---|
| T#1 Delivery | `.206` | Bev/Dessert/Extra → `.69` |
| T#2 Takeaway | `.212` | → `.69` |
| T#3 Dine In | `.215` | → `.69` |
| T#4 Quick Sale | `.214` | → `.69` |

### Mapping rewrite (60 rows: order-type-keyed → terminal-keyed, `order_type='all'`, category→printer unchanged)
| Existing order_type | → terminal | order_type after |
|---|---|---|
| dine_in (→.215) | T#3 Dine In | all |
| takeaway (→.212) | T#2 Takeaway | all |
| delivery (→.206) | T#1 Delivery | all |
| quick_sale (→.214) | T#4 Quick Sale | all |

Category groups (15): Food → counter printer (Beef Khatri Biryani, Beef Khatri, Khatri Sadi, Matka, Beef Changezi Pulao, Beef Changezi, Changezi Saadi, Chicken Biryani, Singaporean Rice, Haleem, Biryani Chicken, Biryani Saadi); Overflow → `.69` (Desserts, Beverages, Extras).

**Result:** each terminal gets 15 terminal-keyed rows. Delivery & Dine-In identical; Takeaway (T#2) & Quick Sale (T#4) route every order type's food KOT to their own counter, overflow to XPrinter — receipt + KOT reunited.

### User binding (single terminal, multiple order types)
| User | Terminal (default) | Order types |
|---|---|---|
| delivery_kb | T#1 Delivery | [delivery] |
| dinein_kb | T#3 Dine In | [dine_in] |
| takeaway_kb | **T#2 Takeaway only** | [takeaway, quick_sale] |
| quicksale_kb | **T#4 Quick Sale only** | [quick_sale, takeaway] |

Ensure takeaway_kb / quicksale_kb are bound to their **one** terminal with both order types; receipt + KOT auto-print stay ON.

---

## PHASE 5 (DEFERRED) — Report Center "Send to network"

Not a quick change: no ESC/POS report builder exists (only receipt/KOT/reminder). Needs a new thermal-report builder, a printer picker (a report has no single sale), a `report` document type + `build()` branch, and a "Send to network" button (CSRF/fetch) on `reports/center/print.blade.php`. Tracked, not scheduled.

---

## Build / ship order
1. **Phase 1** (layout) — smallest, most visible; 2 core files + migration. Ship → owner verifies prints.
2. **Phase 2** (POS field rules) — vehicle/waiter validation + list display. Ship → owner tests Hold/Review&Pay.
3. **Phase 3** (routing re-key) — code + migration, backward-compatible. Ship.
4. **Phase 4** (data rewrite + user binding) — backup → run once → verify a KOT+receipt per terminal.
5. **Phase 5** — later.

## Open confirmations
- Phase 1 columns: Receipt/Preview `Item │ Qty │ Rate │ Amount`; KOT/Reminder `QTY │ ITEM`.
- Phase 1 KOT row band (now 18 = double w/h, 21 chars): drop to 15–17 (single width, tall, 42 chars) so wraps stop — final px tuned in *Edit Layout* after ship.
- Phase 2.3: RESOLVED — Quick Sale requires **vehicle + waiter** only; customer NOT mandatory.
