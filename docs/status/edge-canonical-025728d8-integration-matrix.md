# Canonical 025728d8 → Edge — INTEGRATION MATRIX (before merge mutation), 22 Oct 2026

Owner decision "FINAL CANONICAL FREEZE + INTEGRATION": `CANONICAL_RELEASE_FREEZE=025728d8`. Produced from the read-only delta report
(`edge-canonical-025728d8-delta.md`) BEFORE any merge mutation. Edge head before: `ecefd7dd` (code `b3648abd`). Merge base `ccc67fa1`.

```
CANONICAL_COMMITS=30 (11 first-parent merges)   CONFLICTING_FILES=7   UNCLASSIFIED_TENANT_MIGRATIONS=3 → classified below (→ 0)
CHUNKS (D8 order, actual head re-checked before each): cc00ac9a → 91e8fcde → 142be366 → e8d0c544 → 025728d8
```

## 1. The 7 conflicting files

```
FILE=app/Services/Sales/SalesReturnService.php (3 hunks)
CANONICAL_INTENT=REFUND-SHIFT-1 (processReturn gains ?int $refundShiftId; refundShift() resolution; sales_returns.shift_id/terminal_id), RETURN-RESTOCK-1 (per-line restock flag; stock-in + COGS reversal only for restocked lines), RETURN-NO-BILL-1 (processNoBillReturn(); unit_cost / inventory_batch_id on lines; noBillCost()), MEDICAL pack return fields.
EDGE_INTENT=Phase 3/4: computeReturn() extracted as THE arithmetic (BUG-011 cap, discount/tax proration, "returnable" guard) so EdgeLocalReturnService reuses it offline; processReturn calls computeReturn; returnCompletesOrder removed/relocated.
CONFLICT_TYPE=BOTH (textual at the computeReturn call + the line-insert array; semantic: which shift receives an Edge-ingested refund, and restock/no-bill on the appliance).
RESOLUTION_PLAN=Keep computeReturn() as the single arithmetic; inside processReturn keep `$computed = computeReturn(...)` AND canonical's refundShift() resolution; line insert carries both sides' fields (edge amounts from $computed + canonical restocked/pack_unit_id/unit_cost); processNoBillReturn() lands verbatim (Cloud-only path). EdgeInboundReturnIngestionService keeps calling processReturn with refundShiftId = null (the sale's own open shift else none) — the till's refund-shift mapping onto a Cloud shift is NOT invented here (owner item, recorded as handover). EdgeLocalReturnService keeps writing restocked = DB default 1 (today's behaviour) and never a no-bill return.
CANONICAL_BEHAVIOUR_TO_PRESERVE=refund shift + day booking for Online returns; restock choice with COGS only for restocked lines; no-bill returns at today's price; pack returns.
EDGE_BEHAVIOUR_TO_PRESERVE=computeReturn arithmetic identical on both runtimes (parity register); Edge-ingested returns still apply through processReturn exactly once; cash-only refund policy on the appliance.
TESTS_REQUIRED=tests/MySql/RefundShiftMySqlTest (canonical, updated by 025728d8), SalesReturn* canonical suites, EdgeReturnSyncHttpMySqlTest, EdgeCashierReturnParity*, EdgeLocalReturn* suites, Recent Orders parity.
```

```
FILE=app/Http/Controllers/Tenant/Finance/ManualJournalController.php (2 hunks)
CANONICAL_INTENT=VOID-ON-ORIGINAL-DATE-1: reverse() validates void_date_mode, passes VoidDate::resolve(mode, entry_date) to JournalService::reverse and to reverseCashBankLines (transaction_date = original or today); status message names the date.
EDGE_INTENT=D1: the controller is a THIN caller of ManualJournalService::reverse() (lock, posted-only, already-reversed refusal, GL + cash/bank + supplier mirror in ONE service shared with the Cloud ingestion); reverseCashBankLines/guardNarration moved INTO the service.
CONFLICT_TYPE=BOTH (textual: canonical edits code D1 deleted; semantic: the date mode must live in the service, not the controller).
RESOLUTION_PLAN=ManualJournalService::reverse(JournalEntry, string $reason, ?int $userId = null, string $dateMode = VoidDate::TODAY): passes VoidDate::resolve($dateMode, $entry->entry_date) to JournalService::reverse and dates the cash/bank counter-movements the same way; controller validates void_date_mode and passes $mode — stays thin; the status message from canonical kept. The Edge supplier-finance ingestion keeps the default (TODAY) — unchanged behaviour.
CANONICAL_BEHAVIOUR_TO_PRESERVE=a wrong entry wiped on its own date; a right one reversed today (default); message text.
EDGE_BEHAVIOUR_TO_PRESERVE=one enforcement point (R1–R3, R5, reverse lock, already-reversed → redirect to the existing reversal), JOURNAL_RULE_REFUSED terminal on ingestion.
TESTS_REQUIRED=tests/MySql/ManualJournal* (canonical VOID-ON-ORIGINAL-DATE cases), EdgeD1 shared-rules tests (tests/MySql/EdgeManualJournalRules*), EdgeSupplierFinanceSyncHttpMySqlTest.
```

```
FILE=resources/views/tenant/sales-returns/create.blade.php (1 hunk + semantic)
CANONICAL_INTENT=RETURN-NO-BILL-1 tabs (With bill / Without bill, feature-switched), terminal_id hidden field (REFUND-SHIFT-1), submission_key (UB-06), pack-aware quantities, restock "back on shelf" choice, "keep how the page was opened" URL params on bill pick.
EDGE_INTENT=Phase 2 W-B: SHARED screen on both runtimes — layouts.pos on Edge, every URL from $posRuntime->route(...) (salesReturnIndexPage/Store/Search/CreatePage, managerVerify), non-cash refund options capability-disabled, manager prompt via POS.managerCredentialFieldsHtml.
CONFLICT_TYPE=BOTH.
RESOLUTION_PLAN=bill pick: `$posRuntime->route('salesReturnCreatePage') + '?' + keep.toString()` (runtime route AND the kept params); `$tabUrl` built on the runtime route; the no-bill tab + partial rendered only when the Cloud feature is on AND the runtime capability `returnWithoutBill` is ON (new PosRuntime CAPABILITY_KEY: Cloud ON, Edge OFF, server-only class — the appliance has no processNoBillReturn twin); terminal_id / submission_key hidden fields kept on both runtimes (the Edge twin ignores submission_key? — verify the Edge store route tolerates unknown fields); restock checkbox rendered on both runtimes (the Edge twin ignores `restock` for now = restocked default 1; documented).
CANONICAL_BEHAVIOUR_TO_PRESERVE=tabs, no-bill flow, terminal_id to the refund shift, idempotent submission, pack quantities, restock choice.
EDGE_BEHAVIOUR_TO_PRESERVE=one shared screen, runtime routes, Edge layout, cash-only refund hint, shared manager prompt.
TESTS_REQUIRED=shared-view regression gate (render + static), EdgeReturnScreen* / PosScreenRenders (Edge + Cloud), browser W09 on both runtimes, canonical sales-return UI tests.
```

```
FILE=resources/views/tenant/sales-returns/index.blade.php (1 hunk)
CANONICAL_INTENT=RETURN-NO-BILL-1: a return without an order shows a "No bill" badge instead of a sale link.
EDGE_INTENT=sale link through $posRuntime->route('salesOrderShow') (null on Edge → plain code).
CONFLICT_TYPE=TEXTUAL.
RESOLUTION_PLAN=`@if(! $return->order)` badge (canonical) `@elseif(runtime route + Gate)` link (edge) `@else` code.
CANONICAL_BEHAVIOUR_TO_PRESERVE=No-bill badge.  EDGE_BEHAVIOUR_TO_PRESERVE=runtime route, no Cloud URL on Edge.
TESTS_REQUIRED=shared-view static gate, PosScreenRenders return list.
```

```
FILE=resources/views/tenant/sales-returns/show.blade.php (1 hunk)
CANONICAL_INTENT=same as index (badge + "returned without a bill, at today's price at most").
EDGE_INTENT=same as index.
CONFLICT_TYPE=TEXTUAL.  RESOLUTION_PLAN=same three-way @if as index.
TESTS_REQUIRED=as index.
```

```
FILE=docs/system-map/index.html + docs/system-map/system-map.json (2 files)
CANONICAL_INTENT=rebuilt by canonical for its new tables/screens.  EDGE_INTENT=rebuilt by Edge after the D8 merge.
CONFLICT_TYPE=TEXTUAL (generated).
RESOLUTION_PLAN=take either side during the chunk, then REBUILD from freshly migrated test DBs at the end (CLAUDE.md standing rule) and commit the rebuilt files with the merge report.
TESTS_REQUIRED=none (generated artefact).
```

Non-conflicting but semantically critical files handled in the same chunks: `resources/views/tenant/pos/index.blade.php` (medical blocks off on Edge; the
Return URL gains `terminal_id` on both runtimes — the Edge return screen must accept it), `JournalService` / `JournalPostingService` (`$dateMode`;
COGS only for restocked lines), `PermissionCatalogService` (module filing), `UserDataScope::applyToReturns`, `SalesReportEngine` (pack quantities),
`InventoryService` (restock / reverseReceiptEntry), `Branch` (+`ask_prescription`), `TenantFeatures` (+RETURN_NO_BILL), `SalesReturnController` (+216).

## 2. The 3 new canonical tenant migrations — classification (UNCLASSIFIED → 0)

```
MIGRATION=2026_10_20_000005_add_restocked_to_sales_return_lines
TABLES_CHANGED=sales_return_lines
DATA_REWRITE=false (ADD COLUMN … DEFAULT 1, ALGORITHM=INSTANT; no UPDATE)
DESTRUCTIVE=false
LOCK_RISK=low (INSTANT; tiny table on the appliance)
EDGE_CODE_REFERENCES=SalesReturnService (restocked per line, read by JournalPostingService COGS reversal), EdgeLocalReturnService (writes sales_return_lines rows — column default 1 = today's behaviour), EdgeReturnEnvelopeBuilder / Cloud ingestion (processReturn)
CLOUD_ONLY_DEPENDENCIES=none
CLASSIFICATION=EDGE_REQUIRED (operational table the appliance writes; the shared service reads the column on both runtimes)
PACKAGE_DECISION=SHIP_AND_RUN
UPGRADE_TEST=Tests\MySql\EdgeTenantMigrationUpgradeMySqlTest::test_restocked_on_sales_return_lines_upgrades_an_older_appliance
```

```
MIGRATION=2026_10_20_000006_add_ask_prescription_to_branches
TABLES_CHANGED=branches
DATA_REWRITE=false (ADD COLUMN … DEFAULT 1, INSTANT)
DESTRUCTIVE=false
LOCK_RISK=low
EDGE_CODE_REFERENCES=Branch model; `branches` is the bootstrap/refresh `branch` section (WHOLE-ROW upsert → the column must exist before the next refresh); the shared POS reads it only when medicalCounter is on (Edge: false)
CLOUD_ONLY_DEPENDENCIES=none
CLASSIFICATION=EDGE_REQUIRED (bootstrap section column)
PACKAGE_DECISION=SHIP_AND_RUN
UPGRADE_TEST=Tests\MySql\EdgeTenantMigrationUpgradeMySqlTest::test_ask_prescription_on_branches_upgrades_an_older_appliance
```

```
MIGRATION=2026_10_20_000007_return_without_bill_schema
TABLES_CHANGED=sales_returns (sales_order_id → NULLable), sales_return_lines (sales_order_line_id → NULLable; + unit_cost DECIMAL(15,4) NULL, + inventory_batch_id BIGINT NULL)
DATA_REWRITE=false (no row is touched; today's code never writes NULL there)
DESTRUCTIVE=false in data terms, NON-ADDITIVE in schema terms (MODIFY column nullability → ADDITIVE_ONLY=false → OWNER_REVIEW_REQUIRED by rule 6; FKs kept; ALGORITHM=INPLACE, LOCK=NONE, lock_wait_timeout 10)
LOCK_RISK=low (INPLACE with concurrent DML; both tables small; run while STANDBY like every migration — the update script refuses LOCAL_ACTIVE)
EDGE_CODE_REFERENCES=SalesReturnService::processReturn / computeReturn (shared), EdgeLocalReturnService (writes sales_return_lines; always with a sale line), EdgeInboundReturnIngestionService, SalesReturn / SalesReturnLine models, JournalPostingService (unit_cost fallback)
CLOUD_ONLY_DEPENDENCIES=none (processNoBillReturn is Cloud-only CODE behind TenantFeatures::RETURN_NO_BILL + the `returnWithoutBill` capability, but the migration references no Cloud-only class)
CLASSIFICATION=EDGE_REQUIRED (operational tables the appliance writes; the shared models/services assume the columns exist) — OWNER_REVIEW_REQUIRED=true (D7-6, see below)
PACKAGE_DECISION=SHIP_AND_RUN
UPGRADE_TEST=Tests\MySql\EdgeTenantMigrationUpgradeMySqlTest::test_return_without_bill_schema_upgrades_an_older_appliance (older appliance: NOT NULL columns + missing unit_cost/inventory_batch_id → after: nullable + columns present; existing return rows byte-identical; FK names unchanged)
```

**D7-6 (owner review item created by this classification):** `2026_10_20_000007_return_without_bill_schema` is non-additive (nullability change on two
operational return tables). Evidence: no data rewrite, FKs kept, INPLACE/LOCK=NONE, tiny tables; the appliance never writes a NULL there (no
no-bill twin). Recommended: approve as EDGE_REQUIRED / SHIP_AND_RUN; it is in the RUN set of this release's plan hash.

Manifest after classification: **215 entries — EDGE_REQUIRED 124 / EDGE_SCHEMA_SAFE 67 / CLOUD_ONLY 24 / UNCLASSIFIED 0**; `meta.merged_canonical = 025728d8`.
