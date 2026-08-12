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

---

## Census verification — EDGE-SPLITBRAIN-STOCK-1 implementation (`014a6f2`, 2026-08-13)

Re-ran the census as an independent proof (Phase 1.1), **not** trusting the "InventoryService is
universal" phrasing. Method: grepped every runtime write to `stock_balances` / `stock_ledgers` /
`inventory_batches` — raw table (`DB::table`, insert/update/increment/decrement/delete, raw SQL) and
every Eloquent model write (`::create`/`::insert`/`updateOrCreate`/`firstOrCreate`/`new …`/`->save`/
`->update`/relationship writes) across `app/` and `tests/`.

**Result — the sole runtime writer to the three official tables is `InventoryService`:**

| Table | Only writer | Line |
|---|---|---|
| `inventory_batches` | `InventoryService::findOrCreateBatch` (`InventoryBatch::firstOrCreate`) | 264 |
| `stock_balances` | `InventoryService::postMovement` (`StockBalance::create` + `->update`) | 322 / 352 |
| `stock_ledgers` | `InventoryService::postMovement` (`StockLedger::create`) | 357 |

Non-runtime writers (out of scope, correctly): `TenantResetTransactionsCommand` (admin truncate),
`EdgeLocalBootstrapImporter` (Edge-side fresh-DB seed). `EdgeOperationalStockService` (#16) writes
its **own** provisional tables, never the official three.

**Choke proven.** `findOrCreateBatch` has **no external callers** (only `postIn`/`transfer` reach it).
Every external mutation enters via `postIn` / `postOutFefo` / `transfer`. Fencing those three closes
controllers *and* direct-service calls. Matrix rows #1–#15 all route here.

### Two corrections to the matrix above

1. **Rows #11/#12 (department transfer/consumption) do NOT route through `InventoryService`.** They
   use `DepartmentInventoryService`, a **separate** custody sub-ledger writing `department_stock_balances`
   / `department_stock_ledgers` (single private sink `postMovement`, L250, keyed on `branch_id`). It
   provably never writes the official three tables and is bounded by official on-hand, so it **cannot
   corrupt official valuation/FEFO** — but it is a second per-branch authority with **no operating-mode
   guard in either direction** today. Treated as a **secondary** fence (defense-in-depth), not the
   primary official-stock split-brain risk.
2. **The Branch-Server direction is already partially fenced.** `InventoryService::postIn/postOutFefo/
   transfer` each already `throw new RuntimeException` when `EdgeRuntime::isBranchServer()`. This sprint
   **replaces** those three bare throws with the structured `assertOfficialStockMutationAllowed(Branch)`
   so both directions (Branch-Server AND Cloud-on-a-Local-Mode-branch) render one friendly 409, not a 500.

### Fence design (implemented this sprint)

`BranchOperatingModeService::assertOfficialStockMutationAllowed(Branch)` — **stricter** than the sale
fence (`assertSaleMutationAllowed`): a Branch Server may settle *sales* on its bound branch, but it must
**never** post official stock (even its own branch) — official FEFO/costing/valuation is Cloud authority,
applied later by Cloud-side sync ingestion; the Branch Server tracks only provisional quantity.

- **Branch Server instance** → always throw (`CODE_BRANCH_SERVER_OFFICIAL_STOCK`), regardless of binding.
- **Cloud + branch handed to its server** (`local_edge` && status ∈ active/closing/suspended) → throw
  (`CODE_ACTIVE`).
- **Cloud + normal branch** (cloud/inactive/pending) → pass. **Zero behavior change for all of production
  today.**

Placement: `InventoryService::{postIn, postOutFefo, transfer}` (transfer asserts **both** branches);
`DepartmentInventoryService::postMovement` keyed on its `$branchId` (secondary). No sync, no config
refresh, no Local Mode activation in this sprint; the fence is dormant for every branch until a real
Local Mode activation (`activation_ready=false` remains).
