# Agent B — CANONICAL INTEGRATION REHEARSAL (owner Decision C, 30 Oct 2026): isolated merge rehearsal only

Scope and safety: owner directive 30 Oct 2026, Decision C — APPROVED FOR ISOLATED MERGE REHEARSAL ONLY. The production branch
`feat/14d-2-plan-upgrade-requests` (checkout `D:/laragon2/www/pos-saas`) was read with `git log/diff/show` only; the Edge branch
`feat/edge-config-refresh-v1` (worktree `D:/laragon2/www/pos-saas-edge`, head a6113772 = release code 0.8.2) was not modified
except for the copy of this report in `docs/status/`. Everything below happened in ONE disposable worktree on a disposable branch.
Nothing was pushed, deployed, installed or merged into production. No database named pos_lab_* / bingoo_edge_lab_* / pos_devonline_* /
production was touched; no server was started; the MySQL suite was NOT run (directive: pending).

```
CANONICAL_BASE_COMMIT=59787ff6 (directive pin) — NOT an ancestor of a6113772: only its migration (categories.updated_by) was
                      cherry-picked as the post-freeze exception ac5b168d. The real git merge base of a6113772 and the canonical
                      head is 025728d8 (CANONICAL_RELEASE_FREEZE). The rehearsal therefore integrates 025728d8..073238de, which
                      INCLUDES the 21 commits between 025728d8 and 59787ff6 that the 22 Oct report classified DEFER (UB W1/W2,
                      Khatri TB/stock-to-P&L, the category guard logic) — they had never been integrated either.
CANONICAL_CURRENT_HEAD=073238dee14e04ad5ae7b91fae9dbae93a526240 (feat/14d-2-plan-upgrade-requests, pinned 2026-10-30 08:19:54 UTC;
                      "Merge MASTER-HISAB-EXCEL-1", authored 2026-10-30 13:18 +0500; later movement NOT chased)
EDGE_INTEGRATION_SOURCE=a6113772 (feat/edge-config-refresh-v1, 0.8.2 release code)
CANONICAL_DELTA_COUNT=157 commits (123 non-merge + 34 merges; 34 first-parent merge points; 332 files, +35,230 / −2,498)
INTEGRATION_WORKTREE=D:/laragon2/www/pos-saas-integ-082  BRANCH=integ/canonical-rehearsal-082  HEAD=see §6 (eight REHEARSAL chunk commits + 1 fixture commit on top of a6113772)
CONFLICT_MATRIX=9 files / 15 code hunks (+2 generated files) — preserve-list hit in 7 of the 7 code files; 0 wholesale ours/theirs
MIGRATIONS_NEW=13 (11 tenant + 2 master) → tenant classified: EDGE_REQUIRED 4 · EDGE_SCHEMA_SAFE 2 · CLOUD_ONLY 4 · already classified 1 · UNCLASSIFIED 0; master 2 = outside the manifest (never run on an appliance)
MANIFEST_AFTER=227 entries — EDGE_REQUIRED 129 / EDGE_SCHEMA_SAFE 70 / CLOUD_ONLY 28 (was 217 = 125/68/24) · OWNER_REVIEW_REQUIRED new: 1 (R-1 decimal_pack_sizes)
CHUNKS_APPLIED=8 of 8 (ancestry order; canonical 073238de and Edge a6113772 are both ancestors of the rehearsal head)  CHUNKS_BLOCKED=0
TESTS_SQLITE=see §7 (run after 09:35 UTC, one suite at a time)   TESTS_MYSQL=PENDING (not run in this directive)
INTEGRATION_READINESS=NOT_READY — see §9 for the blockers
PRODUCTION_BRANCH_MUTATED=NO   EDGE_BRANCH_MUTATED=NO (only docs/status/edge-agent-b-canonical-rehearsal.md copied there)
composer.lock: canonical unchanged since 025728d8; worktree lock byte-identical to the Edge worktree's; vendor copied (robocopy), no package installed
```

## 1. Delta inventory — canonical 025728d8 → 073238de by domain (157 commits)

| Domain | Merge points (first-parent, oldest first) | Commits (approx.) | Edge relevance |
|---|---|---|---|
| Returns / refunds (shared) | 5c49790c UNIFIED-BUSINESS W1/W2 (UB-04 no-bill refund stated + restock at a known cost, UB-06 posted once, UB-05 supplier-return matrix); 4b745532 W2-B quantity rules (0831cd41, f72a38b1) | ~14 | HIGH — SalesReturnService / SalesReturnController / SalesReturn model (conflicts), computeReturn semantics, sales_returns.submission_key |
| POS / shared cashier view | 4b745532 units & packs F2 gram entry (9ec6b22f), F3 packs at the normal counter (7e85f640, 62e16346), UB-W2 plain-variant stock (df524466), UB-08 recipe conversion (582f4b70); bc9c973c MOVE-TABLE-CART-1 (a4eec6f8) | ~12 | HIGH — resources/views/tenant/pos/index.blade.php (+177/−?), POSController (4 conflict hunks), PosPageData key, HeldSaleController, SalesOrderLine |
| Printing (shared ESC/POS) | bc9c973c REPORT-NETWORK-BRIDGE-1; 46088129 REPORT-NETWORK-GAPS-1 + ITEMS-DEALS-1; F4 prints carry units (bd684f07, 23d80320) | ~8 | MEDIUM — EscPosPayloadService (+148/−?) is the Edge network-print plumbing; Unit/Printing tests added |
| Reports (shared engine) | F4 mixed-unit reports (QtyMix in SalesReportEngine/Exporter); e973e2a4 TB-SECTION-HEADS-1; f311cb38 PNL-DETAIL-1 + FINANCE-PDF-DOWNLOADS-1 | ~9 | MEDIUM — SalesReportEngine (+94) feeds the offline Quick Report; TB/P&L/PDF are Cloud Reports controllers (artifact-excluded) |
| Finance (Cloud) | 9550e045 / c5c39cf9 / ed9a8431 VOID-PURGE-1/2/3 (purge, popup, clear yes); 18d552a0 KHATRI-STOCK-TO-PNL-1 (+ 958c36ad orphan clean-up) | ~15 | LOW — ManualJournalController conflict (D1 thin controller); PurgedNumbers in the shared number generators; purged_* tables; stock_ledgers ENUM |
| Purchasing / AP (Cloud) | 5c49790c SUPPLIER-NET-BILL-1 (76af4d73, 8c7a47eb); 4b745532 W2-C purchasing quantity rules, W3/W4 S0 safety (6e6ea024) + S4 purchase workspace; 3a3f2596/1e0e30b6 SUPPLIER-CREDIT-CLARITY-1 | ~25 | NONE on the appliance (Purchase*/Supplier*/GoodsReceipt* excluded) — 4 migrations classified CLOUD_ONLY/EDGE_SCHEMA_SAFE; F3 twin unchanged |
| Products / catalogue (Cloud screens) | 4b745532 W3/W4 S1–S3 (product list/form, RecipeWriter), UB-10 StockUnitGuard, F1 decimal packs (7308db7e); f9d0174b PRODUCT-NAV-1; 54bb81f9 ROLLOUT-1 (TenantFeatureDefaults) | ~24 | LOW–MEDIUM — Product::updating guard (model event; the Edge refresh applier writes with the query builder → unaffected); product_pack_units DECIMAL factor (R-1) |
| Restaurant / tables | a4eec6f8 MOVE-TABLE-CART-1 | 2 | MEDIUM — shared view handler (conflict) + Cloud HeldSaleController guard (no Edge hold-twin guard: C-3) |
| Customers | — | 0 | NONE — customer_uuid / phone_normalized / aliases untouched |
| Catering (Cloud) | fd144412 COMMERCIAL-RATE-PICKER-1; a06a5b57 CATERING-SIMPLIFY-1 | ~8 | NONE (artifact-excluded) |
| Website / online ordering (Cloud) | 106fe6cb WEB-CART-PHONE-1; 5e36d094 WEB-PICKUP-SWITCH-1 | 2 | NONE (D4 CLOUD_ONLY) — web_settings.pickup_enabled CLOUD_ONLY |
| Master / central (Cloud) | 43fb7a5b + 30e3c3a8 CONTACT-FORM-1; ca85d2f1 MASTER-HISAB-1 + MASTER-TABLE-COLOURS-1; 073238de MASTER-HISAB-EXCEL-1; UB-10 readiness in Master; 59787ff6 CATEGORY-PARENT-GUARD-1 (guard logic) | ~14 | NONE — 2 MASTER migrations (outside the manifest); Central controllers/services excluded |
| Migrations | 13 new files (11 tenant, 2 master) | 13 | §4 |
| Tests | 60 tests/MySql files (new suites: QuantityRules, CounterPacks, DecimalPacks, GramEntry, UnitsAndPacksJourney, UnitsPacksPrintsReports, SalesReturnPostedOnce, PurchaseReturnPostedOnce, MoveTableCart, ReportNetworkBridge, VoidPurge, W2Combined, PlainStockVariants, …); 4 tests/Unit (EscPosReportBridge/Gaps, StandardUnits, TenantFeatureDefaults) | ~60 files | MySQL suites = the canonical gate for the merged areas (pending) |
| Docs / system map | 24 docs/plans, 10 docs/status, system-map rebuilt 10× | ~20 | generated map taken from canonical during the chunks; REBUILD PENDING |

Shared-path files canonical changed (diff 025728d8..073238de restricted to the preserve-list and shared runtime paths, 39 files): `resources/views/tenant/pos/index.blade.php`,
`app/Http/Controllers/Tenant/POSController.php`, `app/Services/Sales/SalesReturnService.php`, `app/Models/Tenant/SalesReturn.php`, `SalesOrderLine`, `Product`, `ProductPackUnit`,
`app/Services/Printing/EscPosPayloadService.php`, `app/Services/Reports/SalesReportEngine.php` + `SalesReportExporter`, `app/Services/Inventory/InventoryService.php`,
`app/Services/Kitchen/RecipeWriter.php` (new) + `UnitConversionService`, `app/Http/Middleware/PreventDuplicateSubmission.php` (4xx releases the token), `app/Providers/AppServiceProvider.php`
(contact-form rate limiter), `app/Services/Permissions/PermissionSyncService.php`, `app/Services/Tenancy/TenantProvisioner.php`, `app/Support/TenantFeatures.php` (+4 switches),
`resources/views/layouts/app.blade.php`, `resources/views/tenant/sales-returns/create.blade.php` + `partials/no-bill.blade.php`, `routes/tenant.php`, `.env.example`, `config/saas.php`.
**Untouched by canonical (diff = 0):** `app/Services/Edge/**`, `app/Http/Controllers/Edge/**`, `app/Support/Pos/PosRuntime.php` + `CloudPosRuntimeFactory`, `app/Support/Edge/**`,
`config/edge.php`, `routes/edge_runtime.php`, `database/migrations/edge/**`, `app/Models/Tenant/Customer.php`, `app/Services/Sales/ManagerApprovalService.php`, the POS JS asset
files, `EdgeUpdate*`, `EdgeIngestionAuthority`, lease/authority services, the artifact-boundary and dependency-closure tests.

## 2. CONFLICT / DEPENDENCY MATRIX (merge-tree dry run a6113772 × 073238de = 9 files; the same 9 surfaced across the chunks)

| # | File | Canonical change | Edge change | Preserve-list item touched? | Resolution applied | Chunk |
|---|---|---|---|---|---|---|
| 1 | `app/Services/Sales/SalesReturnService.php` (4 hunks) | UB-06 `processReturn(…, ?string $submissionKey, ?string $submissionHash)`, `postedOnce()` / `refuseIfPosted()` / `createReturn()` (number retry) / `submissionColumns()`; W2-B quantity rules written INLINE in processReturn (LineQuantity normalize, "only N can still be returned" refusal, counted lines whole); CounterPacks::sizeOf (decimal pack size) + wholeOrNull/sizeColumns on the line insert; UB-04 no-bill cost known/expired-restock refusals; `noBillCost(): ?float` | REFUND-SHIFT-H1 parameters (`processingShiftUuid`, `saleShiftFallback`, `processingShiftFacts`, `legacyCashWithoutShift`) + `refundShift()` / `assertCashRefundHasAPayingShift()` / `CASH_REFUND_NEEDS_SHIFT`; `computeReturn()` = THE arithmetic (parity register) iterated as `$computed['lines']` | YES — shared Online/Edge return arithmetic (parity register), REFUND-SHIFT-1/H1, exactly-once ingestion | signature = H1 params + UB-06 params APPENDED (optional; the Edge ingestion and the till pass none); transaction wrapped in `postedOnce()` with the H1 closure vars; W2-B rules moved INTO `computeReturn()` so the Online form and the Edge till (EdgeLocalReturnService → ValidationException) refuse alike; line insert = Edge `$item` fields + canonical decimal-size columns; UB-04/no-bill/createReturn landed verbatim (Cloud-only paths) | 1, 3 |
| 2 | `app/Http/Controllers/Tenant/SalesReturnController.php` (3 hunks) | UB-06: `submissionHash()`, `alreadyPosted()`, `answerAlreadySubmitted()`, `submission_key` rule, `refund_amount` required on no-bill; `refundShiftId:` resolved inline in the call | H1: `$refundShiftId` resolved BEFORE the manager approval is consumed; `saleShiftFallback: false`; cash refund refused without a paying shift (both paths) | YES — REFUND-SHIFT-H1 / CASH_REFUND_NEEDS_SHIFT | H1 kept on both paths; UB-06 hash/alreadyPosted/AlreadySubmitted added beside it; the service call passes `refundShiftId: $refundShiftId, saleShiftFallback: false, submissionKey, submissionHash` | 1 |
| 3 | `app/Models/Tenant/SalesReturn.php` (1 hunk) | fillable `submission_key`, `submission_hash` | fillable `processing_shift_uuid` (H1) | YES — H1 identity column | both | 1 |
| 4 | `app/Http/Controllers/Tenant/Finance/ManualJournalController.php` (1 hunk, 240 lines) | re-edits the private helpers (guardNarration, guardCashLines, normalizeLines, syncCashBankLines, reverseCashBankLines, nextManualJournalId + VOID-PURGE-1 PurgedNumbers) | D1: helpers moved INTO `ManualJournalService` (one rule set shared with the Edge supplier-finance ingestion); controller thin | YES — D1 one enforcement point (manual-journal rules) | Edge side kept (helpers stay deleted); the ONE new rule (a purged manual-journal number is never reused) applied to `ManualJournalService::nextManualJournalId()`; both files remain artifact-excluded | 2 |
| 5 | `app/Http/Controllers/Tenant/POSController.php` (4 hunks) | UB-W2 default-variant stock merge inline; `$unitConversion`/`$counterPacks`/`$gramEntry` in the tile closure; `'counterPacks'` page key; private `recipeAvailability()` with UB-08 | W-G1: `RecipeAvailability` shared computation (`stockLookup`, `forProduct`); page built through `PosPageData::fromArray([...])->toViewData($posRuntime)` (closed key contract); private recipeAvailability deleted | YES — runtime capability adapter / PosPageData contract / W-G1 shared computation | UB-W2 → `RecipeAvailability::mergePlainVariants()` (new shared static) and UB-08 → `RecipeAvailability::forProduct()`; controller calls the helpers; closure = Edge vars + `$counterPacks, $gramEntry`; `counterPacks` added to `PosPageData::KEYS`; `EdgeLocalPosController` sets `counterPacks => false` and applies the same plain-variant merge to its operational-stock lookup | 3 |
| 6 | `app/Console/Commands/TenantResetTransactionsCommand.php` (1 hunk) | keep-list gains the Branch Server local tables (edge_auth_audit, …, edge_operational_stock_*), purged_entries; wipe-list gains purged_numbers, nettings, credit moves; reset fails closed on an unclassified table | keep-list has `edge_branch_authority_leases` (P0 lease = authority state) | YES (list only) — branch-authority lease table | both lists kept | 3 |
| 7 | `resources/views/tenant/pos/index.blade.php` (1 hunk; +177 lines auto-merged) | MOVE-TABLE-CART-1: literal `url('/restaurant/table-sessions')/{id}/move` + `enterMovedTableSession()`; UNITS-PACKS F2/F3 JS (entry units in the qty modal, pack lines, `counterPacks` const) | ONE shared cashier view: every URL via `POS.route(...)` (`tableMove`) | YES — shared Online/Edge POS view (A5: no Edge-styled copy, runtime routes) | runtime route kept + canonical callback taken; F2/F3 JS lands as-is gated by `counterPacks` (false on Edge) and `product.entry_units || []` (absent on the Edge tile payload); no new `\App\` reference in the view | 7 |
| 8–9 | `docs/system-map/index.html`, `system-map.json` | rebuilt by canonical | rebuilt by Edge | no (generated) | canonical side taken in chunks 1–2, auto-merged later; REBUILD from freshly migrated test DBs PENDING (CLAUDE.md standing rule; MySQL not run here) | 1, 2 |

Non-conflicting but semantically critical files handled in the same chunks: `EscPosPayloadService` (network report slip = Edge print plumbing), `SalesReportEngine` (Quick Report engine: `sold_mix`/`returned_mix`/`net_mix` keys added to rows — additive for the Edge quick-report view), `HeldSaleController` (W2-B `LineQuantity::prepareSaleLines` on held lines; MOVE-TABLE-CART-1 server guard; pack_size/entered_* on recall), `SalesOrderLine` fillable (`pack_size`, `entered_quantity`, `entered_unit_code`), `PreventDuplicateSubmission` (shared middleware: any 4xx/5xx releases the page token), `TenantFeatures` (+`COUNTER_PACKS`, `WHOLE_COUNTED_STOCK`, `PURCHASE_WORKSPACE`, `VOID_PURGE`), `resources/views/tenant/sales-returns/create.blade.php` (shared return screen: `submission_key` hidden field via `\App\Support\SubmissionKey::fresh()` — app/Support ships; the Edge store route validates with `$request->validate` and ignores the unknown field), `JournalService::nextEntryNo` / `ExpenseVoucherController` (PurgedNumbers, table-guarded), `Product::booted` StockUnitGuard (model event; the refresh applier writes with the query builder).

## 3. Chunks (ancestry order, `git merge --no-ff <first-parent merge point>`; each commit message records its conflicts and resolutions)

| Chunk | Merge point | Canonical range | Commits | Conflicts | Result |
|---|---|---|---|---|---|
| 1 | 59787ff6 CATEGORY-PARENT-GUARD-1 | 025728d8..59787ff6 — UNIFIED-BUSINESS W1/W2 (UB-04/05/06/08/10), SUPPLIER-NET-BILL-1, KHATRI TB-SECTION-HEADS-1 + STOCK-TO-PNL-1 + STOCK-ORPHAN, category guard (full) | 22 (7 merges) | 3 files / 6 hunks + 2 generated | e14ba696 — lint 55 files green; manifest +4 (→221) |
| 2 | ed9a8431 VOID-PURGE-3 | 59787ff6..ed9a8431 — VOID-PURGE-1/2/3 | 9 (3) | 1 file / 1 hunk | aa29539a — lint 26 green; manifest +1 (→222); VOID-PURGE numbering rule into ManualJournalService |
| 3 | 4b745532 UNIFIED-W3W4 | ed9a8431..4b745532 — W2 slices A/B/C, units & packs F1–F4, W3/W4 S0–S5, reset fail-closed | 62 (8) | 3 files / 7 hunks | b431fc4a — lint 156 green; manifest +3 (→225); RecipeAvailability helpers; PosPageData `counterPacks`; computeReturn W2-B |
| 4 | 50d8a8f7 map after CONTACT-FORM-1 | 4b745532..50d8a8f7 — ROLLOUT-1, CONTACT-FORM-1 (1/2 schema, 2/2 form) | 11 (3) | none | f6767bfe — lint 33 green; 1 MASTER migration (outside manifest) |
| 5 | 567a7752 deployment record | 50d8a8f7..567a7752 — SUPPLIER-CREDIT-CLARITY-1, PRODUCT-NAV-1, COMMERCIAL-RATE-PICKER-1 | 17 (4) | none | ced10f55 — lint 37 green; manifest +1 (→226) |
| 6 | f1a64bb8 deployment record | 567a7752..f1a64bb8 — PNL-DETAIL-1 + FINANCE-PDF-DOWNLOADS-1, CATERING-SIMPLIFY-1 | 12 (2) | none | 61769ab4 — lint 26 green; no migrations |
| 7 | 46088129 REPORT-NETWORK-GAPS/ITEMS-DEALS | f1a64bb8..46088129 — MOVE-TABLE-CART-1 + REPORT-NETWORK-BRIDGE-1, -GAPS-1, -ITEMS-DEALS-1 | 11 (4) | 1 file / 1 hunk (shared POS view) | 75c8fb03 — lint 11 green |
| 8 | 073238de MASTER-HISAB-EXCEL-1 (= pinned head) | 46088129..073238de — WEB-CART-PHONE-1, WEB-PICKUP-SWITCH-1, MASTER-HISAB-1/-EXCEL-1, MASTER-TABLE-COLOURS-1 | 13 (3) | none | ca502cd9 — lint 31 green; manifest +1 (→227); 1 MASTER migration |
| fixtures | — | SharedPosViewRenderTest carries `counterPacks => false` | — | — | 70ab81f7 |

Proof of completeness: `git merge-base --is-ancestor 073238de <rehearsal head>` and `… a6113772 <rehearsal head>` both true. Total 157 commits = 22+9+62+11+17+12+11+13.
No chunk was blocked. `git merge --abort` was never needed.

## 4. New canonical migrations — classification against `database/migrations/edge-tenant-manifest.php` (method of the 22 Oct integration matrix)

| Migration | Tables / change | Appliance need | Cloud section export affected? | Class · package · owner review | Upgrade test |
|---|---|---|---|---|---|
| `tenant/2026_10_21_000001_add_submission_key_to_sales_returns` | sales_returns + submission_key VARCHAR(64) NULL, submission_hash CHAR(64) NULL (INSTANT) + UNIQUE (INPLACE, LOCK=NONE) | yes — OPERATIONAL table the till writes; the shared SalesReturnService/SalesReturn assume the columns when a key is passed (the appliance and the ingestion pass none; `SubmissionKey::usable` checks the column first) | no (sales_returns is not a section) | **EDGE_REQUIRED** · SHIP_AND_RUN · no | `test_submission_key_on_sales_returns_upgrades_an_older_appliance` (added; MySQL pending) |
| `tenant/2026_10_21_000002_add_submission_key_to_purchase_returns` | purchase_returns, same two columns + unique | no — baseline Cloud-module table carried empty (the F3 twin writes its own purchase_returns_grn flow) | no | **EDGE_SCHEMA_SAFE** · SHIP_AND_RUN · no | `test_submission_key_on_purchase_returns_upgrades_an_older_appliance` (added) |
| `tenant/2026_10_21_000003_create_purchase_bill_return_nettings` | NEW purchase_bill_return_nettings (FK purchase_bills, purchase_returns) + supplier_credit_reallocations | no — purchasing/AP (excluded) | no | **CLOUD_ONLY** · EXCLUDE · no (adds both to the "absent on the appliance" watch list) | n/a |
| `tenant/2026_10_21_000010_add_expensed_to_cost_movement_type` | stock_ledgers.movement_type ENUM + 'expensed_to_cost' (appended; MySQL metadata-only; MODIFY statement, raw SHOW COLUMNS — same shape as the baseline MFG-FIN-E / MODIFIER-INVENTORY-1 appends, classified ADDITIVE/medium there) | parity — operational table the shared InventoryService writes; only the Cloud command writes the value | no | **EDGE_REQUIRED** · SHIP_AND_RUN · no (ADDITIVE_ONLY=true per precedent) | `test_expensed_to_cost_movement_type_upgrades_an_older_appliance` (added) |
| `tenant/2026_10_21_000020_add_updated_by_to_categories` | categories.updated_by | — | no (explicit column list) | already classified EDGE_SCHEMA_SAFE (post-freeze exception ac5b168d); file identical, no conflict | existing |
| `tenant/2026_10_22_000001_create_purged_entries_tables` | NEW purged_entries + purged_numbers | not for writing; the SHIPPED number generators (JournalService, PurgedNumbers helper) read them table-guarded | no | **EDGE_SCHEMA_SAFE** · SHIP_AND_RUN · no — **owner may flip to CLOUD_ONLY (R-2)** | `test_purged_entries_tables_upgrade_an_older_appliance` (added) |
| `tenant/2026_10_23_000001_decimal_pack_sizes` | product_pack_units.factor INT → DECIMAL(15,4) (**MODIFY = non-additive**); pack_size DECIMAL(15,4) NULL INSTANT on sales_order_lines, sales_return_lines, goods_receipt_lines, purchase_order_lines, purchase_bill_lines, purchase_return_lines, stock_adjustment_lines, stock_transfer_lines; no row rewritten | yes — sales_order_lines / sales_return_lines OPERATIONAL; shared computeReturn/processReturn read `CounterPacks::sizeOf`; SalesOrderLine fillable | no (product_pack_units is NOT a bootstrap section; products/categories exports are explicit column lists) | **EDGE_REQUIRED** · SHIP_AND_RUN · **OWNER_REVIEW_REQUIRED = true (R-1)** — ADDITIVE_ONLY=false by the column-type change; tiny table; lock_wait_timeout 10 | `test_decimal_pack_sizes_upgrade_an_older_appliance` (added) |
| `tenant/2026_10_23_000002_sale_line_entered_unit` | sales_order_lines + entered_quantity DECIMAL(15,3) NULL, entered_unit_code VARCHAR(20) NULL (INSTANT) | yes — operational table the sale twin writes (NULL there); SalesOrderLine fillable; HeldSaleController recall | no | **EDGE_REQUIRED** · SHIP_AND_RUN · no | `test_sale_line_entered_unit_upgrades_an_older_appliance` (added) |
| `tenant/2026_10_24_000001_add_submission_key_to_goods_receipts` | goods_receipts submission key + unique | no — purchasing (GoodsReceipt* excluded; table already on the diverged watch list) | no | **CLOUD_ONLY** · EXCLUDE · no (same class as 2026_10_03_000002 voided_to_goods_receipts) | n/a |
| `tenant/2026_10_26_000001_create_supplier_credit_moves` | NEW supplier_credit_moves | no — AP (excluded) | no | **CLOUD_ONLY** · EXCLUDE · no | n/a |
| `tenant/2026_10_29_000001_add_pickup_enabled_to_web_settings` | web_settings.pickup_enabled BOOLEAN DEFAULT 1 (Blueprint `after`) | no — online ordering (D4 CLOUD_ONLY; web_settings empty on the appliance) | no | **CLOUD_ONLY** · EXCLUDE · no (same class as add_branding_to_web_settings) | n/a |
| `2026_10_25_020000_create_contact_enquiries_table` (MASTER) | contact_enquiries | master DB only | — | outside the manifest (master migrations never run on an appliance) | — |
| `2026_10_29_000001_create_company_hisab_tables` (MASTER) | company_partners/accounts/entries/entry_lines/entry_logs | master DB only | — | outside the manifest | — |

UNCLASSIFIED = 0 (blockers from classification: none). `EdgeTenantMigrationManifestTest::test_the_counts_per_class_match_the_report` updated 217 → 227 (CLOUD_ONLY 24 → 28) with one comment line per chunk;
the canonical RUN list in `EdgeTenantMigrationUpgradeMySqlTest` gained the 6 shipped post-freeze migrations. Bootstrap stays v8, the sale envelope v3, the return envelope v2 — no section column list changed.
`meta.merged_canonical` was left at `025728d8` on purpose (the rehearsal is not an integration); the promotion sets it to the integrated canonical head.

## 5. Shared POS / runtime compatibility risks (C-n) and owner items (R-n)

| Id | Area | Finding | Status / what proves it |
|---|---|---|---|
| C-1 | ONE shared cashier view | UNITS-PACKS F2/F3 JS landed in `resources/views/tenant/pos/index.blade.php` (qty-modal unit buttons + error slot (hidden), cart-line "entered" label, pack lines, `const counterPacks`); gated by the page key `counterPacks` (Edge: false) and `product.entry_units || []` (absent on the Edge tile payload); MOVE-TABLE-CART-1 handler via the runtime route | the view CHANGED on both runtimes → D5 Online pixel baseline RECAPTURE + Edge same-dataset diff + geometry compare required before any build (not run); static gate `EdgeSharedPosRegressionStaticGateTest` + `SharedPosViewRenderTest` on SQLite (§7) |
| C-2 | Return arithmetic parity | W2-B rules now live in `SalesReturnService::computeReturn()` — the Edge till REFUSES an over-return ("only N can still be returned") and a fractional counted line, where 0.8.2 silently capped (BUG-011) — a deliberate behaviour change that keeps "one arithmetic" (the alternative — rules only in the Cloud processReturn — would make the two runtimes disagree); `LineQuantity::normalize` throws ValidationException (passes through the Edge twin's RuntimeException catch as a validation answer) | owner acceptance **R-3**; parity register update; `EdgeCashierReturnParity*`, `EdgeLocalReturn*`, `EdgeReturnSyncHttpMySqlTest`, canonical `QuantityRulesMySqlTest` / `SalesReturnPostedOnceMySqlTest` (MySQL, pending) |
| C-3 | Restaurant hold twin | MOVE-TABLE-CART-1's SERVER guard (a held bill whose session is not the posted table's is refused) exists only in the Cloud `HeldSaleController`; the Edge hold path (`EdgeLocalPosService`) has no twin of it (the JS fix applies to both runtimes) | follow-up **R-4** (not invented in the rehearsal) |
| C-4 | Shared print / report engines | `EscPosPayloadService` (network report slip: bridge lines, BY ORDER TYPE, grand total, deals once) and `SalesReportEngine` (+ `QtyMix` mixed-unit figures) changed for both runtimes; `SalesService` now calls `CounterPacks::refusesExpired()` (Cloud path only) | Unit/Printing `EscPosReportBridgeTest`/`EscPosReportGapsTest` (new canonical, §7 Unit not in scope); Quick Report parity suites + `ReportNetworkBridgeMySqlTest` (MySQL pending) |
| C-5 | W-G1 recipe availability | UB-W2 and UB-08 moved into the shared `RecipeAvailability`; the Edge twin now merges plain + default-variant stock the same way (appliance preview changes for products with a default variant — parity with Online, which is the point) | `PosRuntimeTest` / shared-menu tests on SQLite; `EdgeSharedPosViewMySqlTest` (pending) |
| C-6 | Ingestion signature | `processReturn()` gained 2 optional params AFTER the H1 ones; `EdgeInboundReturnIngestionService` (unchanged, artifact-excluded Cloud class) compiles — named/positional calls unaffected | `php -l` green; `EdgeReturnSyncHttpMySqlTest` pending |
| C-7 | Artifact boundary | New Cloud classes that are NOT caught by the exclusion globs: `app/Services/Finance/VoidPurgeService.php` (+ `VoidPurgeNeedsConfirmation`), `app/Http/Controllers/Tenant/Finance/VoidPurgeController.php`, `resources/views/tenant/finance/voided-entries/*` — VoidPurgeService imports `CateringAdvance`/`CateringEvent`/`SupplierPayment` (excluded basenames). Not an Edge entry point and not referenced by the shared POS views, so the constructor-walk closure should not reach it | `EdgeApplianceArtifactBoundaryTest` + `EdgeApplianceDependencyClosureTest` on SQLite (§7); if either objects → add the four paths to `config/edge.php` artifact exclusions (recommended regardless: Cloud finance purge, like ManualJournalController) |
| C-8 | Capability keys | `PosRuntime::CAPABILITY_KEYS`, `EdgePosRuntimeFactory::CAPABILITIES/LABELS`, `CloudPosRuntimeFactory` — diff 0 (reminderPause, websiteOrders, partnerShare, returnWithoutBill, customerCreate, … unchanged); no new capability needed (`counterPacks` is page DATA like `medicalCounter`, a Master switch, not a runtime capability) | `PosRuntimeTest`, static gate |
| C-9 | Identity / ingestion / authority / updater | canonical touched NONE of `app/Services/Edge`, `app/Http/Controllers/Edge`, `database/migrations/edge`, `Customer`, `customer_uuid_aliases`, `EdgeIngestionAuthority`, `edge_inbound_*`, lease/authority, `EdgeUpdate*`, `config/edge.php`, `routes/edge_runtime.php` | diff 0 |
| C-10 | Feature switches on the appliance | new `TenantFeatures` keys (counter_packs, whole_counted_stock, purchase_workspace, void_purge) are read from the synced tenant row (`TenantFeatures::on`), default off; `PackUnits::enabled()`/`CounterPacks::packsOn()` false on Edge | no code path on the appliance toggles behaviour |
| C-11 | Shared middleware | `PreventDuplicateSubmission`: a 4xx/5xx answer releases the page token (VOID-PURGE-3) — affects every route using `prevent.duplicate.submit` on both runtimes (a refused post may be re-sent, which is the intended semantics) | Feature/Edge route gates |
| C-12 | Config refresh / bootstrap | no bootstrap section column list changed; product_pack_units DECIMAL factor is not exported; `StockUnitGuard` is a model event — `EdgeLocalConfigRefreshApplier` upserts with the query builder (no events) | `EdgeConfigRefreshMySqlTest` (pending) |
| R-1 | Owner review | `2026_10_23_000001_decimal_pack_sizes` is NON-ADDITIVE (column type change on product_pack_units) — recommended EDGE_REQUIRED / SHIP_AND_RUN (tiny table, no data rewrite, INSTANT on the line tables) | owner decision |
| R-2 | Owner review | `purged_entries` / `purged_numbers`: EDGE_SCHEMA_SAFE (carried empty) vs CLOUD_ONLY (PurgedNumbers::ready() keeps the generators safe either way) | owner decision |
| R-5 | Standing rule | system map not rebuilt (needs freshly migrated MySQL test DBs; MySQL not run here) — generated files currently = canonical's rebuild | promotion step |

## 6. Rehearsal branch

```
INTEGRATION_WORKTREE=D:/laragon2/www/pos-saas-integ-082
BRANCH=integ/canonical-rehearsal-082 (from a6113772; never pushed; disposable — delete with `git worktree remove` + `git branch -D` when the owner is done with it)
HEAD=70ab81f7 (fixtures) ← ca502cd9 (chunk 8) ← 75c8fb03 (7) ← 61769ab4 (6) ← ced10f55 (5) ← f6767bfe (4) ← b431fc4a (3) ← aa29539a (2) ← e14ba696 (1) ← a6113772
Edge-side files changed beyond the merge itself (vs a6113772): app/Http/Controllers/Edge/EdgeLocalPosController.php (+10), app/Support/Pos/PosPageData.php (+4),
  app/Support/Pos/RecipeAvailability.php (+36), app/Services/Finance/ManualJournalService.php (PurgedNumbers rule), database/migrations/edge-tenant-manifest.php (+10 entries),
  tests/MySql/EdgeTenantMigrationUpgradeMySqlTest.php (+6 tests), tests/Feature/Edge/EdgeTenantMigrationManifestTest.php (counts), tests/Feature/Pos/SharedPosViewRenderTest.php (fixture)
```

## 7. Tests (SQLite only — the host's MySQL gate was running until ~09:30 UTC; the MySQL suite was NOT run per the directive)

NOT RUN — the run was handed back before the 09:35 UTC window opened (host shared with the MySQL gate). Command to run in the worktree, one at a time, after 09:35 UTC:
`vendor/bin/phpunit tests/Feature/Edge` then `vendor/bin/phpunit tests/Feature/Pos` (SQLite :memory:, phpunit.xml). Expected baselines on a6113772: Feature/Edge 202 tests, Feature/Pos 31. Known fixture change: SharedPosViewRenderTest carries counterPacks=false; the manifest count test expects 227 (CLOUD_ONLY 28). Any red is to be classified product vs environmental before promotion.

## 8. MySQL (pending) — what the promotion must run before any build

Full Edge MySQL gate (`test-mysql.sh --filter Edge`, isolated `_tNN` DBs) incl. the 6 new upgrade tests and the manifest validator; canonical suites for the merged
areas on isolated DBs: QuantityRules, SalesReturnPostedOnce, PurchaseReturnPostedOnce, CounterPacks, DecimalPacks, GramEntry, UnitsAndPacksJourney, UnitsPacksPrintsReports,
W2Combined, PlainStockVariants, MoveTableCart, ReportNetworkBridge, VoidPurge, SupplierNetBill, SupplierCreditClarity, TenantResetFailClosed, CategoryParentGuard, RefundShift,
SalesReturn*, PosQuickReport, MedicalCounter, PackUnits; Unit (incl. the 3 documented pre-existing canonical static reds + the new EscPosReport* and StandardUnits tests).

## 9. INTEGRATION_READINESS = NOT_READY

Blockers (none of them a merge conflict — the tree merges and lints clean):
1. MySQL gates not run (directive) — full Edge gate, canonical merged-area suites, the 6 new upgrade tests, config-refresh/ingestion/parity suites.
2. The ONE shared cashier view changed (C-1) → D5 Online pixel baseline recapture, Edge same-dataset diff, geometry compare, browser W01–W18 on both runtimes — not run.
3. Owner decisions: R-1 (non-additive decimal_pack_sizes), R-2 (purged tables class), R-3 (W2-B refusal semantics reach the Edge till), R-4 (Edge hold-twin guard for MOVE-TABLE-CART-1).
4. System map not rebuilt/republished (R-5).
5. SQLite results §7 (any product red there is a blocker; environmental reds are listed with their classification).
6. Canonical keeps moving: the pin is 073238de at 08:19:54 UTC; a promotion must re-scan `073238de..<head>` with merge-tree first.

## 10. Recommended promotion procedure (NOT executed — needs separate owner approval)

1. Owner reviews this report, decides R-1…R-4, and names the canonical commit the integration is reconciled to (073238de, or a later head after a fresh merge-tree scan).
2. In a NEW worktree on `feat/edge-config-refresh-v1` (its head may have moved past a6113772 — 0.8.2 release records): `git merge --no-ff integ/canonical-rehearsal-082`
   (the rehearsal branch is pure merge history on top of a6113772 carrying the recorded resolutions; a replay chunk by chunk with the same resolutions is the equivalent
   long form if the owner prefers the eight merge points re-done against the moved head). Set `meta.merged_canonical` in the manifest to the integrated canonical head;
   add the C-7 exclusions to `config/edge.php` if the SQLite boundary gates asked for them.
3. Gates on the merged head (nothing shared running): Feature/Edge, Feature/Pos, Unit, then the full Edge MySQL gate + canonical merged-area suites on isolated DBs;
   rebuild the system map from those test DBs (`php tools/system-map/build.php --tenant-db=… --master-db=…`), commit, republish the artifact link.
4. Browser programme on the disposable pair only (Online 17/17 + W18, Edge, W15 e2e, D5 pixel recapture, geometry NONE) — per `edge-merged-tree-reconciliation-report.md` §3.
5. Only then the release path (`edge-next-lab-update-checklist.md`): build, package audit, dry-run plan hash on a copy of the LAB DB, owner approval, LAB install.
6. Never merge the Edge branch into canonical; never push the rehearsal branch; delete the rehearsal worktree/branch after promotion.

## 7a. SQLite suites on the rehearsal head 8afa8d0e (run by the coordinating session, 30 Oct 09:27–09:40 UTC, one at a time)

| suite | result | classification |
|---|---|---|
| tests/Feature/Edge | first pass 204/206 (42,244 assertions): `EdgeBuildInfoTest::test_health_and_ready_endpoints` and `EdgeRuntimeBoundaryTest::test_branch_server_boot_problems_detect_missing_config` reported "EDGE_LOCAL_APP_KEY is not set" — ENVIRONMENTAL: the fresh worktree had no gitignored `.env` (the Edge worktree's `.env` carries that key for the test boot). After copying the Edge worktree's `.env` into the rehearsal worktree: **both PASS** (re-run of the two classes: 65 tests, 163 assertions) → **206/206** | environmental, fixed by the env file |
| tests/Feature/Pos | **31/31** (490 assertions) | — |
| tests/Unit | 139 tests (canonical added EscPosReportBridgeTest, EscPosReportGapsTest, StandardUnitsTest, TenantFeatureDefaultsTest): 3 failures = the three known canonical reds (CateringClientFeedbackUiRegressionTest, EscPosReportPayloadTest, DeliveryRiderReassignmentRegressionTest), 1 error `TenantActivationPermissionCacheTest` = `MissingAppKeyException` → ENVIRONMENTAL (same missing `.env`); re-run after the env copy: **PASS** (1 test, 2 assertions) → **136/139** with only the three known reds | environmental + known reds |

No product red was caused by the eight rehearsal chunks on SQLite. Still unrun (deferred by the directive): the full Edge MySQL gate, the canonical merged-area
MySQL suites, the six new upgrade tests in `EdgeTenantMigrationUpgradeMySqlTest`, and the browser/pixel/geometry gates for the changed shared view.
INTEGRATION_READINESS stays **NOT_READY** on those plus owner items R-1…R-4.
