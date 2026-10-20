# Canonical head recheck before the release build — READ-ONLY delta ccc67fa1 → 025728d8 (21 Oct 2026)

Owner directive "FINAL PRE-RELEASE EXECUTION", section FINAL CANONICAL HEAD RECHECK: "If new canonical commits touch critical shared
Edge paths … STOP and report the delta before integrating." They do. **The release build is STOPPED; nothing was merged or built.**

```
EDGE_HEAD=0e6b3974 (code head 4d025f7b = §4-3 classifier; 0e6b3974 = decision records)
CANONICAL_HEAD=025728d8 (feat/14d-2-plan-upgrade-requests, local = origin)
MERGE_BASE=ccc67fa1
CANONICAL_COMMITS_SINCE_ccc67fa1=30 (11 first-parent merges), 97 files, +3,724 / −517
MERGE_TREE_DRY_RUN=7 conflicting files (ManualJournalController, SalesReturnService, sales-returns create/index/show views, system-map html + json)
```

## 1. First-parent merge points (oldest first) — the chunk ladder a D8-style integration would follow

| # | Merge | Topic | Touches a critical Edge path? |
|---|---|---|---|
| 1 | `cc00ac9a` REFUND-SHIFT-1 | a refund goes into the PAYING counter's open shift and day | YES — `SalesReturnService::processReturn` new optional `?int $refundShiftId`; `sales_returns.shift_id/terminal_id` written; the shared POS view's Return button now appends `&terminal_id=` to the return URL; `SalesReturnController` resolves the refund shift from `terminal_id` |
| 2 | `e99cdcda` RETURN-RESTOCK-1 schema | `sales_return_lines.restocked` (default 1) | YES — new tenant migration `2026_10_20_000005` on an OPERATIONAL table the appliance writes |
| 3 | `1465902e` RX-BRANCH-SETTING-1 schema | `branches.ask_prescription` (default 1) | YES — new tenant migration `2026_10_20_000006` on the bootstrap `branch` section (whole-row upsert → the column must exist before the next refresh) |
| 4 | `91e8fcde` RETURN-RESTOCK-1 + RX-BRANCH-SETTING-1 | returns ask "back on shelf / thrown away"; Rx question is a branch setting | YES — `SalesReturnService` (restock per line; stock in and COGS reversal only for restocked lines), `JournalPostingService` (COGS only for restocked lines), `InventoryService` (+60), `Branch` model/controller/form, `TenantFeatures` |
| 5 | `142be366` VOID-ON-ORIGINAL-DATE-1 | every money void asks which day to undo on | YES — `ManualJournalController` (conflicts with D1's thin caller), `JournalService::reverse` + `JournalPostingService::reverseForSource` gain a `$dateMode` (new `App\Support\VoidDate`), finance screens (Cloud) |
| 6 | `a0079107` MEDICAL-COUNTER-RX-POPUP-1 | prescription popup, numeric qty boxes | shared POS view (+185/−66) — inside `@if($medicalCounter)` blocks (Edge `false`) except the REFUND-SHIFT line above |
| 7 | `6e9ae284` PACK-QTY-REPORTS-1 | reports count tablets | `SalesReportEngine` / `SalesReportService` (+50) — the canonical report engine the offline Quick Report reuses |
| 8 | `00d15053` MASTER-NEW-SCREENS-1 | Master screenshots | Cloud-only (central views, jpgs, `TenantFeatures` shots) |
| 9 | `e8d0c544` GRN-VOID-OWN-BATCH-1 + STARTER-PLAN-COUNTER-1 | GRN void takes back its own batch; POS-only plans can make a counter / open a shift | `PermissionCatalogService` (files a route under an entitled module via `TenantSubscriptionAccessService::anyOfModulesFor`), `InventoryService::reverseReceiptEntry`, `PurchasingService`, `ModuleCatalogService`, `DemoProvisioner` |
| 10 | `88f7c9f5` RETURN-NO-BILL-1 schema + medClick rename | `sales_returns.sales_order_id` and `sales_return_lines.sales_order_line_id` become NULLable; `unit_cost`, `inventory_batch_id` on return lines | YES — new tenant migration `2026_10_20_000007`: **non-additive** (MODIFY column, INPLACE LOCK=NONE) on operational tables → OWNER_REVIEW_REQUIRED by rule 6 |
| 11 | `025728d8` RETURN-NO-BILL-1 | return without a bill (Master switch, pharmacydemo only) | YES — `SalesReturnService::processNoBillReturn`, `SalesReturnController` (+216), `UserDataScope::applyToReturns` (returns without a sale scoped by branch/terminal), `SalesReturn`/`SalesReturnLine` models, `JournalPostingService` revenue code for a no-bill return, sales-returns views (conflict with the shared-screen runtime routes), `TenantFeatures::RETURN_NO_BILL` |

## 2. Critical-path impact, file by file

| File | Canonical change | Edge consequence |
|---|---|---|
| `resources/views/tenant/pos/index.blade.php` | medical-counter UX inside `@if($medicalCounter)`; REFUND-SHIFT-1 appends `&terminal_id=<#terminal_id>` to the Return button URL | The ONE shared cashier view: the medical blocks stay off on both runtimes for a restaurant tenant (pixel baseline unaffected in principle, to be re-proven); the `terminal_id` parameter now reaches the Edge return screen (`salesReturnCreatePage` runtime route) — the Edge return twin must accept/ignore it and decide its own refund-shift rule |
| `app/Services/Sales/SalesReturnService.php` (+304, 3 conflict hunks with Phase 3/4 edits) | `processReturn(…, ?int $refundShiftId = null)`; `refundShift()` resolution; per-line `restock` (stock-in + COGS only when restocked); `processNoBillReturn()`; `unit_cost` on lines | `EdgeLocalReturnService` uses `computeReturn` (arithmetic) and writes its own local rows → will not set `restocked` (DB default 1 = today's behaviour) unless the twin is extended; `EdgeInboundReturnIngestionService::processReturn(6 args)` compiles (7th optional) but passes NO refund shift → an Edge-ingested return books to "the sale's own shift while open, else no shift" — the Cloud's day closing then does NOT show the refund the till paid (REFUND-SHIFT-1 semantic gap; the envelope carries the local shift, the Cloud needs a rule) |
| `app/Http/Controllers/Tenant/SalesReturnController.php` (+216) + `resources/views/tenant/sales-returns/{create,index,show}.blade.php` (3 conflicts) | no-bill flow, refund-shift from `terminal_id`, "keep how the page was opened" URL params | The return screens are SHARED screens rendered on the appliance through `PosRuntime` route keys (Phase 2 W-B); canonical re-introduced literal `url('/sales-returns/create')` in `create.blade.php` where the shared view uses `$posRuntime->route('salesReturnCreatePage')` → resolution = runtime route + keep params; the no-bill partial must be capability-gated (`TenantFeatures::RETURN_NO_BILL` is a Cloud switch; the appliance has no `processNoBillReturn` twin) |
| `database/migrations/tenant/2026_10_20_000005/6/7` | restocked; ask_prescription; return-without-bill schema | **3 UNCLASSIFIED tenant migrations = release blockers by D7 until classified**: 000005 → EDGE_REQUIRED (operational `sales_return_lines`, additive INSTANT, default 1); 000006 → EDGE_REQUIRED (bootstrap `branch` section); 000007 → EDGE_REQUIRED + OWNER_REVIEW_REQUIRED (column MODIFY on `sales_returns` / `sales_return_lines`, non-additive; LOCK_RISK low — tiny tables) — each needs its upgrade test; manifest 212 → 215 |
| `app/Http/Controllers/Tenant/Finance/ManualJournalController.php` (2 conflict hunks) + `JournalService` / `JournalPostingService` / `VoidDate` | VOID-ON-ORIGINAL-DATE-1 `$dateMode` on reversals | D1 made the controller a thin caller of `ManualJournalService::reverse`; canonical added the date choice in the controller → the date mode must move INTO the shared service (the Edge supplier-finance ingestion posts through the same rules); `JournalPostingService` is in the artifact |
| `app/Services/Finance/JournalPostingService.php` (+24) | COGS reversal only for restocked lines; 4120 for a no-bill return; `reverseForSource($dateMode)` | Used by Cloud ingestion of Edge returns/supplier finance — behaviour change for returns (restocked default 1 keeps today's figures) |
| `app/Services/Permissions/PermissionCatalogService.php` | STARTER-PLAN-COUNTER-1 module filing | The catalogue the D3 audit / Cashier (Counter) template builds on; `TenantSubscriptionAccessService` is NOT artifact-excluded (only `EnsureTenantSubscriptionAccess*` and `Subscription*` basenames are) — dependency closure expected to hold, to be proven |
| `app/Services/Security/UserDataScope.php` (+21) | `applyToReturns` for returns without a sale | Shared scope used by Recent Orders / returns lists on both runtimes — additive |
| `app/Services/Reports/SalesReportEngine.php` / `SalesReportService.php` | PACK-QTY-REPORTS-1 | The offline Quick Report reuses the engine — quick-report parity tests must re-run |
| `app/Services/Inventory/InventoryService.php` (+60) | restock on return; `reverseReceiptEntry` | Cloud stock authority; the appliance's operational stock baseline is unaffected, the Cloud side of F3/returns is |
| `app/Models/Tenant/Branch.php`, `BranchController`, branches form | `ask_prescription` | bootstrap `branch` section whole row → migration 000006 required before the next refresh |
| `app/Support/TenantFeatures.php` | `RETURN_NO_BILL` switch, Master shots | Edge never reads Master switches directly; the shared return screen must not expose "Without bill" on the appliance |
| Catering / central / finance screens / `DemoProvisioner` / jpgs | Cloud-only | excluded from the artifact or Cloud views — no appliance effect |
| `docs/system-map/*` | rebuilt by canonical | regenerate after any merge |

## 3. What an integration would need (estimate, not started)

1. D8 procedure again: 4–5 chunks at the merge points above (cc00ac9a → 91e8fcde → 142be366 → e8d0c544 → 025728d8), actual head re-checked
   before each chunk, per-conflict record for the 7 files, no wholesale ours/theirs.
2. Classify the 3 migrations in the manifest (one OWNER_REVIEW_REQUIRED → a D7-6 owner decision), write 3 upgrade tests, manifest 215 / 24.
3. Decide the Edge side of REFUND-SHIFT-1 for returns the till pays (which Cloud shift receives an Edge-ingested refund: the envelope's local
   shift mapped by `shift_uuid`, or none as today) — an owner decision; and keep RETURN-NO-BILL / RESTOCK-choice Cloud-only (capability OFF on
   the appliance, `restocked` default 1 = today's behaviour).
4. Move VOID-ON-ORIGINAL-DATE's date choice into the shared manual-journal service (D1 rule: one rule set, controller thin).
5. Re-run every source gate, the browser suites on both runtimes, and — because the shared view changed — re-capture the Online pixel
   baseline (D5) and re-prove zero unintentional drift; rebuild the system map.
6. Then the build. Realistic effort: one to two working days of tree work plus the ~2 h gate wall-clock, assuming canonical does not move again.

## 4. Owner question raised by this recheck

Canonical is moving daily (pharmacy / returns work: 30 commits in ~24 h). Without a declared **canonical freeze point** for this Edge release the
recheck → integrate → re-gate loop has no end. Proposed: the owner names the canonical commit this release is reconciled to (025728d8 if the
integration above is approved, or ccc67fa1 if the owner prefers to build the already-proven tree); later canonical commits go to the NEXT Edge
release and are recorded as a known delta in the release report.
