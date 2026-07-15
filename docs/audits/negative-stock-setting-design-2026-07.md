# NEGATIVE-STOCK-SETTING-1A — Design & Risk Audit (2026-07)

> Planning document only. **No code was changed for this audit.**
> Do not implement negative inventory except per this design.
> Audited at branch `feat/14d-2-plan-upgrade-requests`, HEAD `e5b3040`.

---

## Executive summary

Goal: a per-branch setting `branches.allow_negative_stock` (default **OFF**) that, when ON, lets the branch sell stock-out items — official `stock_balances.quantity_on_hand` may go below zero — while every movement stays on `stock_ledgers` and COGS stays approximately correct. When OFF (the default, and the state of every existing branch), behavior is byte-for-byte today's behavior.

Key audit findings:

1. There are exactly **two backend enforcement points**, both inside `InventoryService` — the intended single choke point holds.
2. POS JS has **four advisory gates** (tile badge, add-to-cart block, quantity-increment cap, combo gate); the backend gate is authoritative.
3. **Twelve flows** call the stock-out path. Only the **sale family** (POS sale / manual sale / recipe consumption / modifier consumption) should honor the setting in v1; all others keep today's block.
4. The dangerous part is **COGS on the negative leg**: a fresh/empty balance has `average_cost = 0`, which would post **zero COGS** and overstate profit. The design fixes this with a documented cost-fallback chain.
5. `stock_ledgers.balance_after` already exists → negative-crossing audit needs **no ledger migration**; the only new column is `branches.allow_negative_stock`.

---

## 1. Current stock-out enforcement map (backend)

File: `app/Services/Inventory/InventoryService.php` (341 lines)

| # | Location | Behavior today |
|---|---|---|
| E1 | `postOutFefo()` L82-121 | Selects only balances with `quantity_on_hand > 0`, consumes FEFO (earliest expiry first, batch-less last via `9999-12-31` sort key). After the loop: `if ($remaining > 0.0001) throw RuntimeException('Insufficient stock for {product}')` |
| E2 | `postMovement()` L286-288 (private) | `if ($direction === 'out' && $currentQty < $quantity) throw RuntimeException('Insufficient stock …')`. Row-level `lockForUpdate` on `stock_balances` (BUG-007 fix) protects concurrent sales. |

Notes:
- E2 is redundant for FEFO callers (each leg consumes `min(balance, remaining)`) but protects any direct/future `postMovement` use. Both must honor the new flag consistently.
- `postMovement` **already creates a zero-qty balance row** if none exists (L269-281) — so "no balance exists" is not a blocker; the mechanism for negative balances already half-exists.
- `balance_after` is already written on every ledger row (L315) → free audit trail.
- `stock_ledgers.movement_type` is a MySQL ENUM — **no new movement type is needed** (negative legs are still `sale` / `recipe_consumption` / etc.).

## 2. Frontend/POS block map (advisory only — backend is authoritative)

File: `resources/views/tenant/pos/index.blade.php`

| # | Location | Behavior today |
|---|---|---|
| F1 | `availableQty()` ~L1382 | Reads `product.stock_by_branch[branch]` (from POSController payload) or `makeable_by_branch` for recipe products; `null` = untracked service (unlimited) |
| F2 | Tile render ~L1525 | `stockClass = qty <= 0 ? 'stock-out' : qty <= 5 ? 'stock-low' : 'stock-ok'`, text `Out` — tile still clickable but add is blocked by F3 |
| F3 | `addToCart()` ~L1825 | `if (stockQty !== null && stockQty <= 0) blockAlert(...)` and increment cap `newQty > stockQty + 0.0001 → blockAlert` |
| F4 | Combo gate ~L1729 | `makeable <= 0 → blockAlert`, increment cap at ~L1740 |
| F5 | Recipe makeable | Server-side `POSController::recipeAvailability()` floors at `max(0, …)` — recipe tiles show `Makes N` |

Manual sales order (`/sales-orders/create`) has no JS stock gate — it relies entirely on backend E1/E2.

## 3. Caller inventory — who posts stock OUT

| Flow | Call site | Movement type |
|---|---|---|
| POS / manual sale (stock_item) | `SalesService:75` | `sale` |
| Modifier consumption | `SalesService:226` (`consumeLineModifiers`) | `sale` (linked product) |
| Recipe consumption on sale | `RecipeConsumptionService:90` | `recipe_consumption` |
| Kitchen wastage | `KitchenWastageService:33` | `wastage` |
| Kitchen production (ingredients) | `KitchenProductionService:73` | `recipe_consumption` |
| Stock adjustment decrease | `StockAdjustmentController:131` | `adjustment_out` |
| Stock count variance loss | `StockCountController:373` | `adjustment_out` |
| Branch transfer out | `StockTransferController:96` → `transfer()` | `transfer_out` |
| Purchase return | `PurchaseReturnService:208` | `purchase_return` |
| Manufacturing consumption | `ConsumptionPostingService:99` (gated by `manufacturing_posting_settings`) | `manufacturing_material_issue` |
| Manufacturing FG reversal | `FinishedGoodPostingService:205` | (reversal path) |
| Department shadow consumption | `DepartmentConsumptionService` — **does NOT use InventoryService**; parallel sub-ledger, already tolerates shortage via `department_consumption_exceptions` rows | n/a |

## 4. Setting location decision — **Option A: branch-level only**

`branches.allow_negative_stock` BOOLEAN NOT NULL DEFAULT FALSE.

Why: negative inventory is an operational decision per location (a kiosk that sells ahead of GRN entry vs. a warehouse that must stay exact). There is no existing tenant-level settings table that makes Option B cheap (branch flags like `is_tax_enabled` already live on `branches` — precedent). A tenant-level default can be layered later without schema conflict.

`branches` today has no flags beyond `business_type`/`status`/tax fields; the Branch model fillable already carries feature flags (`is_tax_enabled`, …) — same pattern.

## 5. Flow policy matrix (v1)

| Flow | Allow negative when branch flag ON? | Reason | Risk | UI warning |
|---|---|---|---|---|
| POS sale | **YES** | The business ask: sell before stock entry / backorder | COGS approximation | Amber `Backorder` badge + checkout Swal warning |
| Manual sales order | **YES** | Same code path (`SalesService`), same business case | Same | Insufficient-stock note on screen |
| Recipe consumption (sale) | **YES** | Otherwise recipe menu items stay blocked → feature half-works for restaurants | Ingredient WAC drift | Same checkout warning |
| Modifier consumption | **YES** | Fires inside the same sale; blocking would fail the sale mid-transaction | Same | Covered by sale warning |
| Kitchen wastage | **NO** (v1) | Wasting stock you don't have is a data error, not a backorder scenario | Garbage-in reporting | Keep today's block |
| Kitchen production ingredients | **NO** (v1) | Batch cooking should consume real stock; revisit with kitchen team feedback | WIP-style distortion | Keep block |
| Stock adjustment decrease | **NO** | Adjustments are the correction tool — they must reflect reality | Breaks the reconciliation tool itself | Keep block |
| Stock count variance loss | **NO** | Variance = system − counted is mathematically bounded by system stock | n/a | Keep block |
| Branch transfer out | **NO** | Would create negative at source AND phantom positive at destination | Double distortion | Keep block |
| Purchase return | **NO** | Cannot return goods to a supplier that you do not hold | Supplier/AP dispute | Keep block |
| Manufacturing material issue | **NO** (v1) | Manufacturing posting has its own settings table; coordinate in MFG-FIN track later | WIP costing corruption | Keep block |
| Sales return restock / GRN / production in | n/a | Inbound — never blocked by stock-out logic | — | — |
| Department shadow consumption | unaffected | Separate service, already shortage-tolerant via exception rows | — | — |

Implementation consequence: the flag must be **opt-in per call site** (an `allowNegative` parameter defaulted `false`), NOT read from the branch inside `InventoryService` — otherwise all 12 flows would silently change behavior.

## 6. Accounting / COGS risk analysis (from actual code)

COGS chain today: each FEFO leg posts `unit_cost = balance.average_cost` → ledger `total_cost` → summed into `sales_order_lines.cost_total` (`SalesService:88-94`) → `JournalPostingService::postPaidSale` posts **Dr 5100 Product COGS / Cr 1400 Inventory Asset** for that amount (recipe lines reclass Dr 5200/Cr 5100).

| Question | Actual behavior found |
|---|---|
| A. Cost used when stock is negative? | The negative remainder has **no FEFO leg today** (loop only sees positive balances). If posted naively via `postMovement` on an auto-created balance, `average_cost = 0` → **COGS = 0** |
| B. Does InventoryService require a balance to exist? | No — `postMovement` auto-creates a zero-qty/zero-cost balance (L269-281) |
| C. Can it create a negative balance? | Mechanically yes (the update L300 has no floor); only the E1/E2 guards prevent it |
| D. If `average_cost` is 0, is COGS 0? | **Yes** — ledger `total_cost = qty × 0`, line `cost_total` 0, journal posts 0 → profit overstated, Inventory Asset not relieved |
| E. Later GRN effect on WAC while negative? | `postIn` formula L292: with `currentQty = -5 @ cost 100` and `+10 @ 120`: `newQty = 5`, WAC = `(-500 + 1200)/5 = 140` — **inflated**. If still negative after receipt (`newQty <= 0` branch), WAC snaps to the incoming `unitCost`. Both are standard negative-stock WAC distortions and must be disclosed |
| F. Required disclaimer | "COGS for items sold while stock was negative is an estimate at the last known average cost. Receive purchases promptly; margins for backorder periods are approximate." — show on Negative Stock report + Branch form help text |

**Cost fallback chain for the negative leg** (design requirement, in order):
1. `average_cost` of the last positive balance consumed in the same `postOutFefo` call;
2. else `average_cost` of the batch-less balance row if > 0;
3. else max `average_cost` across this product/variant's balances at the branch;
4. else `product_variants.purchase_price` / `products.default_purchase_price` (both exist in catalog schema);
5. else 0 — and the ledger row's `notes` records `negative-stock leg, no known cost` for the audit report.

This keeps Dr 5100 / Cr 1400 ≈ economic reality instead of silently posting zero.

## 7. Implementation design (for 1B)

### 7.1 Migration (single column)

```php
// tenant migration, idempotent hasColumn guard (project pattern)
$table->boolean('allow_negative_stock')->default(false)->after('business_type');
```

- **No `stock_ledgers` change**: negative-crossing rows are exactly `direction='out' AND balance_after < 0` — derivable, already indexed by the existing table.
- Existing branches: default FALSE applies automatically — zero behavior change on deploy.

### 7.2 InventoryService (the only service change)

- `postOutFefo(..., bool $allowNegative = false)`:
  - FEFO loop unchanged;
  - after the loop, if `remaining > 0.0001`:
    - `!$allowNegative` → throw exactly today's exception (string unchanged — callers/tests match on it);
    - `$allowNegative` → post ONE extra `postMovement` leg for `remaining` against the **batch-less balance key** (`batch: null`) with `unitCost` from the §6 fallback chain, `allowNegative: true`.
- `postMovement(..., bool $allowNegative = false)`: skip the E2 guard only when `$allowNegative && $direction === 'out'`. WAC line for 'out' already keeps `average_cost` unchanged — negative balances retain last known cost (correct).
- `postIn`, `transfer`, `findOrCreateBatch`: **untouched**.

### 7.3 Callers changed (only the sale family)

| File | Change |
|---|---|
| `SalesService` (both postOutFefo sites) | pass `allowNegative: (bool) $sale->branch->allow_negative_stock` |
| `RecipeConsumptionService:90` | same, from the sale's branch |
| All other callers | **no change** — default `false` keeps today's block |

### 7.4 Branch UI

`resources/views/tenant/branches/form.blade.php` + `BranchController::validateBranch` + `Branch` fillable/casts:

- Checkbox **"Allow Negative Inventory"**, default unchecked.
- Help text: *"When enabled, this branch can sell/post stock-out items and official stock may go below zero. Use only when you accept backorder or delayed stock entry workflows. COGS for backorder sales is estimated at last known cost."*
- Gated by the existing `tenant.branches.update`/`store` permissions (Owner/Admin only today); no new permission needed for v1.

### 7.5 POS UI

- POSController payload: add `allow_negative_by_branch` map (or extend branches payload).
- When ON for the selected branch:
  - F3/F4 blocks and increment caps become **warnings** (toast "Backorder — stock will go negative"), not blocks;
  - F2 tile: new `stock-backorder` amber class, text `Backorder` when `qty <= 0`;
  - F5 recipe `makeable`: keep the number visible but allow add (`Makes 0 · backorder`);
  - checkout (`submitPaidSale` path): if any cart line exceeds available qty, one Swal confirm listing the shortfall lines before POST.
- When OFF: all five gates byte-identical to today.

### 7.6 Reports

New **Negative Stock Report** `/reports/inventory/negative-stock` (perm `tenant.reports.inventory.negative-stock`), following the existing inventory report controller/view pattern:

- Section 1 — current negatives: `stock_balances WHERE quantity_on_hand < 0` → Branch / Product / Variant / Batch / Current Qty / Average Cost / Negative Value (qty × avg cost) / Last movement (type, reference, date from latest ledger row of that balance key).
- Section 2 — crossing audit: `stock_ledgers WHERE direction='out' AND balance_after < 0` in date range → who/when/what took it negative (`created_by_user_id`, reference).
- CSV export, `branch_ids[]` multi-select filter (standard traits).
- Disclaimer line from §6.F.

### 7.7 Ops guardrail change (IMPORTANT)

Deploy/QA smoke currently asserts `official_negative=0` **for every tenant**. Once this feature is used legitimately that assertion breaks. Update the smoke definition to:

```
official_negative rows must be 0 for branches with allow_negative_stock = OFF;
rows on flag-ON branches are reported (count + value) but are not failures.
```

## 8. Risk controls

- Default OFF for every existing and new branch (migration default; provisioner untouched).
- Setting editable only via Branch form (Owner/Admin gated by existing perms).
- Warning text on Branch form; amber Backorder UX in POS; checkout confirm.
- Negative Stock report + crossing audit (no migration needed).
- COGS disclaimer in report + help text.
- Exception message string unchanged for the OFF path (no downstream matcher breaks).
- Full regression required before deploy (see §9); deploy is normal `deploy.sh` (one tenant migration).

## 9. QA plan for the implementation sprint (do NOT run in 1A)

On demo tenant, rollback-transaction harness where possible; flag toggled on ONE test branch only:

| # | Case | Expected |
|---|---|---|
| 1 | Flag OFF (default), POS sale of stock-out item | Blocked — today's `Insufficient stock` message, no sale, no ledger |
| 2 | Flag ON, POS sale qty > on-hand | Sale completes (`paid`) |
| 3 | Balance after case 2 | `quantity_on_hand < 0`, `average_cost` unchanged from pre-sale |
| 4 | Ledgers after case 2 | FEFO legs + one batch-less negative leg; `balance_after < 0`; unit_cost per fallback chain (non-zero when a cost exists) |
| 5 | Journal after case 2 | Dr 5100 / Cr 1400 = line `cost_total` > 0; entry balanced |
| 6 | `tb_diff` | 0 |
| 7 | Negative Stock report | Shows the item in both sections; CSV works |
| 8 | Flag back OFF, repeat stock-out sale | Blocked again |
| 9 | Policy holds: wastage / adjustment-decrease / transfer / purchase-return on the flag-ON branch with insufficient stock | ALL still blocked |
| 10 | Recipe + modifier sale over stock on flag-ON branch | Completes; ingredient balances negative; COGS non-zero |
| 11 | GRN received while negative | Balance rises through zero; WAC per §6.E documented behavior; no exception |
| 12 | Department shadow on flag-ON branch stock-out sale | Exception row (existing behavior), never blocks |
| 13 | Held sale hold→recall→pay over stock | Same as case 2 |
| 14 | Full-tenant smoke | `tb_diff=0` all tenants; `official_negative=0` on all flag-OFF branches (i.e., every branch except the test one, which is reset before finishing) |

Regression sweep: normal in-stock POS sale, purchase→GRN→bill→payment, purchase return (positive stock), sales return, transfer, adjustment, stock count — all unchanged.

## 10. Risk / gap register

| Risk | Severity | Mitigation |
|---|---|---|
| Zero-cost COGS on fresh negative balances | HIGH | §6 fallback chain — the core of the sprint |
| WAC distortion on receipt into negative | MED | Documented; disclaimer; encourage prompt GRN entry |
| Smoke scripts treating any negative as failure | MED | §7.7 smoke redefinition in the same sprint |
| POS JS caches stock counts client-side (stale after backorder sales) | LOW | Existing behavior; badge is advisory; backend authoritative |
| Batch-tracked products: negative leg is batch-less | LOW | Correct by design (you can't owe a specific batch); document in report |
| Future new stock-out callers forgetting the param | LOW | Default `false` = safe by default |

## 11. Recommended implementation prompt (1B outline)

1. Migration `branches.allow_negative_stock` (idempotent).
2. `InventoryService` param plumbing + negative leg + cost fallback (with the exception string untouched for OFF).
3. Branch model/controller/form field.
4. Sale-family callers pass the flag.
5. POS payload + JS gates→warnings + Backorder badge + checkout confirm.
6. Negative Stock report + perm + sidebar + provisioner/MasterSeeder wiring + routes-sync.
7. QA §9 (cases 1-14) with rollback; reset test branch flag OFF at the end.
8. Update deploy smoke definition per §7.7; docs/ROADMAP E2 tick; memory update.
9. Explicit non-goals: no transfer/wastage/adjustment/purchase-return/manufacturing negative support; no tenant-level default; no COGS snapshotting.
