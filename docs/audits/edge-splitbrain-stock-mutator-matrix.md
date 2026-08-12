# EDGE-SPLITBRAIN-STOCK-1 — Mutator Authority Matrix (read-only grounding)

**Status: CENSUS ONLY — no production code changed.** Prepared during KHATRI-STABILITY-CLOSURE-1
so the fence can be built from a complete map, not just the sale path.

## The exposure in one line

`BranchOperatingModeService::assertSaleMutationAllowed()` fences **only the sale family**. Every
other stock mutator, and any **direct service call** that bypasses a controller, can change a
branch's official stock while that branch is in Local Mode → split-brain. `InventoryService` — the
single choke point every path funnels through — currently carries **no** operating-mode guard.

## The choke point

All official stock movement lands in `App\Services\Inventory\InventoryService`:
`postIn()` (receipts/returns-in), `postOutFefo()` (sales/consumption/returns-out), `transfer()`,
via private `postMovement()` writing `stock_ledgers` + `stock_balances`, keyed on `branch_id`.
Because it is the universal sink, it is the **correct place for one reusable fence** — a guard here
covers controller paths *and* direct service invocations, closing the bypass the per-controller
sale guards leave open.

## Authority matrix

Guard = does the path today call `assertSaleMutationAllowed` (or any operating-mode check)?

| # | Mutator | Entry (controller / command) | Service method | Stock write | Operating-mode guard today |
|---|---|---|---|---|---|
| 1 | Sale settlement | `SalesOrderController` (+Held, +TableSession) | `SaleOperationalSettlementService` → `InventoryService::postOutFefo` | ledger+balance out | ✅ controller-level |
| 2 | Sales return | `SalesReturnController` | `SalesReturnService` → `InventoryService::postIn` | in | ✅ controller-level |
| 3 | Stock adjustment | `StockAdjustmentController` | `InventoryService::postIn/postOutFefo` | in/out | ❌ **none** |
| 4 | Stock transfer | `StockTransferController` | `InventoryService::transfer` | out+in (2 branches) | ❌ **none** |
| 5 | Stock count / recount | `StockCountController` | `InventoryService` adjust to counted | in/out | ❌ **none** |
| 6 | Goods receipt (GRN) | `GoodsReceiptController` | `PurchasingService` → `postIn` | in | ❌ **none** |
| 7 | Purchase return | (purchasing) | `PurchaseReturnService` → `postOutFefo` | out | ❌ **none** |
| 8 | Kitchen wastage | `KitchenWastageController` | `KitchenWastageService` → `postOutFefo` | out | ❌ **none** |
| 9 | Kitchen production | `KitchenProductionController` | `KitchenProductionService` (+`RecipeConsumptionService`) | out (ingredients) + in (output) | ❌ **none** |
| 10 | Recipe/component consumption | (rides sale/production) | `RecipeConsumptionService` → `postOutFefo` | out | ❌ **none** (inherits caller) |
| 11 | Dept stock transfer | `DepartmentStockTransferController` | `DepartmentInventoryService` | out+in | ❌ **none** |
| 12 | Dept consumption | (dept) | `DepartmentConsumptionService` | out | ❌ **none** |
| 13 | Manufacturing consumption | `Manufacturing/*` | `ConsumptionPostingService` → `postOutFefo` | out (WIP) | ❌ **none** |
| 14 | Manufacturing finished good | `Manufacturing/FinishedGoodReceiptController` | `FinishedGoodPostingService` → `postIn` | in | ❌ **none** |
| 15 | Opening stock | seeder / provisioner | `InventoryService::postIn` | in | ❌ **none** (setup-time; low risk) |
| 16 | Edge operational stock | `EdgeLocalPosService` | `EdgeOperationalStockService` | provisional (Edge-side) | ✅ Edge-scoped (separate authority) |

**14 of 16 official Cloud stock paths are unfenced.** The two guarded ones guard at the controller,
so even they are bypassable by a direct `SaleOperationalSettlementService`/`SalesReturnService` call.

## Design implication for the implementation sprint (NOT built yet)

1. Add one reusable authority — `assertStockMutationAllowed(Branch)` (generalize the existing
   sale fence) — and call it **inside `InventoryService::postIn/postOutFefo/transfer`**, keyed on
   the movement's `branch_id`. Choke-point placement covers controllers *and* direct service calls
   in one stroke; `transfer()` must check **both** branches.
2. Contract to preserve exactly:
   - Cloud + branch **not** Local Mode → unchanged (zero behavior change for every normal branch,
     which is all of production today).
   - Cloud + branch in Local Mode (`local_edge` active/closing/suspended) → **fail closed**
     (`BranchLocalEdgeException`).
   - Branch Server instance → only its hard-bound branch (existing `assertBranchServerBoundToBranch`).
   - Other Cloud branches → unaffected; finance/report **reads** stay available.
3. Test with real MySQL integration + direct-service-call tests (prove the bypass is closed), plus
   a per-mutator matrix test asserting each of #3–#15 fails closed for a Local-Mode branch.
4. **No sync, no config refresh, no Local Mode activation in that sprint.** Production stays Cloud;
   the fence is dormant for every branch until a real Local Mode activation, which is later still.

## Verified boundary

`EdgeOperationalStockService` (#16) is the Edge-side provisional stock and keeps its own authority —
it must NOT be folded into the Cloud fence. The Cloud fence is about blocking the **Cloud** from
mutating a branch that has handed authority to its Branch Server.
