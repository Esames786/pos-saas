# Canonical 025728d8 → Edge — MERGE REPORT (owner CANONICAL_RELEASE_FREEZE=025728d8), 22 Oct 2026

```
EDGE_HEAD_BEFORE=ecefd7dd (code b3648abd)
CANONICAL_RELEASE_FREEZE=025728d8
MERGED_HEAD=087278e9 (5 chunks: be41130a cc00ac9a · d988e6ae 91e8fcde · 3d3078d9 142be366 · e273d246 e8d0c544 · 087278e9 025728d8; matrix ec336631)
MERGE_BASE_NOW=025728d8 (Edge was NOT merged into canonical)
CONFLICTS_RESOLVED=7 files / 9 hunks (SalesReturnService ×3, ManualJournalController ×2, sales-returns create/index/show ×1 each, system map ×2 generated) — each recorded in the chunk commit message and in the integration matrix; no wholesale ours/theirs
UNCLASSIFIED_MIGRATIONS=0   EDGE_REQUIRED=124   EDGE_SCHEMA_SAFE=67   CLOUD_ONLY=24   (215 entries, manifest 2026-10-22.1, merged_canonical 025728d8)
OWNER_REVIEW_NEW=D7-6: 2026_10_20_000007_return_without_bill_schema (non-additive nullability change on sales_returns / sales_return_lines; no data rewrite; recommended EDGE_REQUIRED / SHIP_AND_RUN)
```

## 1. What canonical 025728d8 brought and how each critical path was preserved

| Canonical work | Preserved canonical behaviour | Preserved Edge behaviour | Where |
|---|---|---|---|
| REFUND-SHIFT-1 | the refund goes into the paying counter's open shift and day; `processReturn(…, ?int $refundShiftId)`; the POS Return button passes `terminal_id` | `computeReturn()` stays the one arithmetic for both runtimes; the Edge till keeps its local shift; Edge-ingested returns pass `null` (sale's own open shift, else none) — **handover H1**: which Cloud shift should receive a till-paid refund (owner) | SalesReturnService, POS view, create view |
| RETURN-RESTOCK-1 | per-line "back on shelf / thrown away"; stock-in and COGS reversal only for restocked lines | the restock flag rides the computed item (default true); the Edge till sends none = today's behaviour; `sales_return_lines.restocked` EDGE_REQUIRED | SalesReturnService, JournalPostingService, manifest |
| RETURN-NO-BILL-1 | `processNoBillReturn()`, tabs on the return screen, No-bill badges, `UserDataScope::applyToReturns`, 4120 revenue code for a no-bill return | Cloud-only by capability `returnWithoutBill` (Cloud ON / Edge OFF, server-only class; no Edge route or twin); tab URLs through the runtime route; the nullable links migration classified with owner review | create/index/show views, PosRuntime, EdgePosRuntimeFactory, gate |
| VOID-ON-ORIGINAL-DATE-1 | a manual-journal reversal dated on the entry's own date or today, GL + cash/bank; message names the date | D1 one rule set: the date mode lives in `ManualJournalService::reverse($entry, $reason, $userId, $dateMode)`; controller thin; the Edge supplier-finance ingestion keeps TODAY | ManualJournalService / Controller |
| RX-BRANCH-SETTING-1, MEDICAL-COUNTER-RX-POPUP-1 / UX-1 / KEYS, PACK-QTY-REPORTS-1 | all canonical | `branches.ask_prescription` EDGE_REQUIRED (bootstrap `branch` section); medical blocks off on every appliance (`medicalCounter=false`); report engine shared (Quick Report parity re-proven in the gate) | manifest, POS view, SalesReportEngine |
| STARTER-PLAN-COUNTER-1, GRN-VOID-OWN-BATCH-1, MASTER-NEW-SCREENS-1 | all canonical | permission-catalogue filing change covered by the D3 audit tests; purchasing/Master are Cloud-only (artifact-excluded) | PermissionCatalogService, InventoryService |

Deleted old Edge POS (`resources/views/edge/pos/**`) did NOT return (static gate). Bootstrap stays v8, envelope v3, no version bump.

## 2. Gates on 087278e9

| Gate | Result |
|---|---|
| Full Edge MySQL gate (`test-mysql.sh --filter Edge`, shared _edgewt DBs) | **721 tests / 9,754 assertions, 1 skipped (guarded dev seed)**; 1 failure = `EdgeConnectionPartitionTest` wall-clock pacing guard (17.76 s vs < 17 s budget) while three suites shared the box; **re-run alone: 1 / 103 green** → GREEN. Log `C:\Users\Dell\BingooEdgeLab\evidence\phase5\edge-mysql-gate-087278e9.log` (65 min) |
| Feature/Edge | 190 / 41,683 green |
| Feature/Pos | 31 / 490 green |
| Unit | 119: 116 green, 3 = the documented canonical pre-existing static reds (CateringClientFeedbackUi sidebar hook, EscPosReportPayload two-decimal money, DeliveryRiderReassignment rider key) |
| Canonical MySQL suites for the merged areas (RefundShift, ReturnRestock, GrnVoid ×2, ManualJournal ×2, CashBookVoid, StarterPlanCounter, MedicalCounter, PosScreenRenders, QuickReport, SalesReturn*, PackQty/SalesReport, BillBarcode, CancelFreesTable, TableClose, CustomerSearch, PrintAgent, ReminderPause) on isolated `_t25` | **189 / 1,346 green** (11 min) |
| Fast contract suites (manifest, PosRuntime, SharedSecondaryScreens, shared-view static gate, compatibility) | 34 / 4,931 green |
| Migration manifest validator + upgrade tests (incl. the 3 new) | inside the gates above (EdgeTenantMigrationManifestTest 215/24; EdgeTenantMigrationUpgradeMySqlTest 19 incl. restocked / ask_prescription / return_without_bill_schema) |
| O1 regression | the freshness / cutover / handback files are untouched by the merge; the regression test ran inside the full gate |
| Browser (Team FB2) | see `docs/status/edge-087278e9-browser-and-baseline.md` |

## 3. Handovers created by this integration (owner items)

- **H1 — REFUND-SHIFT-1 on Edge-ingested returns:** today a return the till pays is ingested with `refundShiftId = null` (the sale's own Cloud shift while open, else no shift), so the Cloud day closing does not show a till-paid refund under the paying counter. Options: map the envelope's local `shift_uuid` to the Cloud shift of that terminal (needs the Cloud shift twin of an Edge shift), or accept until the Edge shift mirror lands.
- **D7-6** — approve `2026_10_20_000007_return_without_bill_schema` (non-additive nullability; no rows touched).
- The no-bill and restock choices are not on the appliance (capability OFF / default restocked); documented in `edge-capability-differences.md`.

## 4. Final freeze check (read-only, once) — canonical HEAD after the freeze point

```
CANONICAL_HEAD_AT_CHECK=59787ff6 (21 commits after 025728d8; 63 files; 4 new tenant migrations)
```

| Canonical commit(s) after 025728d8 | What | Classification | Why |
|---|---|---|---|
| 35bdb5bd / 59787ff6 CATEGORY-PARENT-GUARD-1 | a category never goes inside itself; `categories.updated_by` (migration `2026_10_21_000020`) | **CRITICAL_FOR_THIS_RELEASE (schema rule)** | `categories` is a bootstrap/refresh section: the Cloud exports WHOLE rows (`EdgeBootstrapService` `$rows(table('categories')…)`) and `EdgeLocalConfigRefreshApplier::upsertCategories` inserts/updates the whole row — once a Cloud at 59787ff6+ serves this appliance, the next categories refresh fails on the unknown column and the standby goes stale. The guard logic itself is a Cloud screen. Fix is one additive column (EDGE_REQUIRED + upgrade test) — OR the durable fix: make the importer/applier filter Cloud rows to the appliance's own columns (forward-tolerant to additive section columns), which removes this whole class of blockers; owner decision |
| cb039389 / f17e01eb / 582f4b70 / c0df44cb / 5c49790c UNIFIED-BUSINESS W1/W2 (UB-04/05/06/08/10) | a return is posted once (`sales_returns.submission_key` + unique index, `SalesReturnService::processReturn(…, ?string $submissionKey, ?string $submissionHash)`, `AlreadySubmitted`); a no-bill return states its refund; supplier-return matrix; standard units; `StockUnitGuard` on `Product::updating`; read-only readiness in Master | **DEFER_TO_NEXT_RELEASE** (owner may designate UB-06 as a data-integrity fix) | the Edge return path is exactly-once by the outbox (sale_uuid + content_hash) and the ingestion passes no submission key, so the guard is inert for Edge; the Online form's double-submit guard is a Cloud change; `StockUnitGuard` is a model event and the applier writes with the query builder (no events) — the refresh is unaffected; `purchase_returns.submission_key` is Cloud-side; its two additive migrations would classify EDGE_SCHEMA_SAFE/REQUIRED next release |
| e973e2a4 TB-SECTION-HEADS-1, a6722dae KHATRI-STOCK-TO-PNL-1, f860c0cf KHATRI-STOCK-ORPHAN, `2026_10_21_000010` stock_ledgers ENUM (+expensed_to_cost) | Trial Balance by heads (display), bought-and-used stock to the P&L by command | DEFER_TO_NEXT_RELEASE | Cloud reports / commands; `stock_ledgers` is Cloud-side (the appliance uses operational stock movements) |
| docs / system-map rebuilds / Master screenshots | — | DEFER (docs) | — |

Per the owner's FINAL FREEZE RULE: **one critical item surfaced → STOP and report before the build** (see the chat report). No further canonical commits are chased.
