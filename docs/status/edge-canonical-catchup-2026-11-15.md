# Online → Offline Edge catch-up check (15 Nov 2026)

Owner directive: inspect the latest canonical Online POS branch against the Offline Edge branch, reusing the rehearsal
worktree `integ/canonical-rehearsal-082` and its R-1…R-4 findings; report the actual missing Online changes, a catch-up
order, and whether anything blocks the first real Offline demo. Read-only: no merge, no LAB, no production, no build.

## 1. Pins (real git state, 15 Nov 2026)

| what | commit | note |
|---|---|---|
| Canonical production branch `feat/14d-2-plan-upgrade-requests` | `5c74107f` (15 Nov 01:48) | checkout `D:/laragon2/www/pos-saas` |
| Edge branch `feat/edge-config-refresh-v1` | `ffed2ef3` | LAB runs 0.8.2-edge = `a6113772`; LAB Cloud export `ac5b168d` is an ancestor of this branch |
| Edge ↔ canonical merge-base | `025728d8` (20 Oct, RETURN-NO-BILL-1) | = the last reconcile point of the D8 merge |
| Rehearsal `integ/canonical-rehearsal-082` | `bfc52574` | based on `a6113772`; canonical integrated up to the pinned head `073238de` (28 Oct) in 8 chunks; R-1…R-4 resolved; **READY_FOR_OWNER_DECISION**, never promoted |
| Canonical commits not in Edge | 291 (321 since ccc67fa1) | 157 already rehearsed (`025728d8..073238de`) + **134 new** (`073238de..5c74107f`, 29 Oct → 15 Nov, 103 non-merge) |
| `git cherry` Edge × canonical from `073238de` | 103 × `+` | **nothing** of the new range is in Edge, not even by patch-id |
| Canonical files under `app/Services/Edge`, `app/Http/Controllers/Edge`, `app/Support/Pos`, `routes/edge*`, `database/migrations/edge` touched in the new range | **0** | the sync envelope, authority and ingestion contracts are untouched by Online |

Merge dry runs (`git merge-tree --write-tree`):
- Edge `ffed2ef3` × rehearsal `bfc52574`: 1 real conflict — `resources/views/tenant/pos/index.blade.php` (F-1 gate edits vs the rehearsal's shared-view chunks) + the two generated system-map files (regenerate).
- Rehearsal `bfc52574` × canonical `5c74107f`: 4 real conflicts — `POSController.php` (RECALL-HIDDEN-ITEM-1 vs the W3/W4 product payload), `ShiftController.php` (SHIFT-HANDOVER-1 / SHIFT-LIST-COUNTER-1 vs REFUND-SHIFT-H1), `sales-returns/create.blade.php` (MANAGER-PIN-ENTER-1 vs W2-B retained input), `tests/MySql/PosScreenRendersHttpMySqlTest.php` + the system-map files. All four are small, same-area edits.

## 2. The 157 rehearsed commits — not repeated (report `docs/status/edge-agent-b-canonical-rehearsal.md` in the integ worktree)

Still outstanding before promotion, unchanged since 6 Nov: owner sign-off of R-1 (non-additive `decimal_pack_sizes`, proven
lossless on a disposable copy), C-2 refuse-not-cap return arithmetic (R-3), the REFUND-SHIFT-H1 effect on the Online form,
the `TenantResetTransactionsCommand` KNOWN_KEPT additions, the CRLF enrol hardening (shipped separately as ab6bfd3b), and the
canonical re-scan `073238de..<head>` — which is this document.

## 3. The 134 new canonical commits (29 Oct → 15 Nov) against Edge

Legend — In Edge: YES / NO / PARTIAL. Priority: MUST_CATCH_UP_NOW (needed for the first real Offline demo) /
BEFORE_PRODUCTION (needed before 0.8.x runs against the real Cloud) / DEFER. "Cherry-pick" = shared Blade/JS or an additive
file Edge can take as-is; "integration" = needs an Edge twin, a manifest row, a bootstrap column list or the page-data contract.

### 3.1 Cashier UI, sales, payments, holds, split bills, tables, KOT, printing

| # | Change (canonical commit) | In Edge | Offline impact | Priority | How | Tests |
|---|---|---|---|---|---|---|
| 1 | **RECALL-HIDDEN-ITEM-1** `69536d3a` — recall of a saved bill finds POS-hidden items through a recall-only list (`recallProductsPayload`), never opens half a bill | NO | Shared JS (`productForHeldLine`, `heldBillMissingItems`) lands on Edge with the view; the Edge page must add `recallProductsPayload` to the closed `PosPageData::KEYS` and build it from the local products table, or the contract test refuses the page. Same silent half-bill risk exists on Edge today for a product hidden after it was held | BEFORE_PRODUCTION | integration (page-data key + Edge builder) | `PosPageData`/shared-view render test, `EdgeCashierMenuHttpMySqlTest`, new Edge recall test, `RecallHiddenItemMySqlTest` |
| 2 | **RECALL-HIDDEN-ITEM-2** `216c7d28` — a hidden item already on the bill may be paid | PARTIAL (equivalent) | `EdgeLocalPosService::resolveLines` already exempts carried lines (HIDDEN-PRODUCT-HELD-BILL-1 parity, canonical cba7e09); keyed by existing line id like Online's `sales_order_line_id` | DEFER | parity test only | add a case to `EdgeCashierMenuHttpMySqlTest` |
| 3 | **MANAGER-PIN-ENTER-1** `830f04ec`, **POS-PIN-FOCUS-1** `f1b98728`, **WEB-ORDERS-FOCUS-1** `848734e4` — manager code box takes Enter, keeps focus, shows the server's words; SweetAlert box opened from a Bootstrap modal is typable | NO | Shared `index.blade.php` + sales-returns views → Edge's `manager.verify` flow has the same focus defect today: a code box opened from inside the pay window cannot be typed in 0.8.2. The web-orders part is inert on Edge (Cloud-only capability) | BEFORE_PRODUCTION | cherry-pick (Blade/JS only; one trivial conflict in `sales-returns/create.blade.php`) | `EdgeChromePartialTest`, `PosScreenRendersHttpMySqlTest`, headless-Edge harness run |
| 4 | **KOT-ORDER-NOTE-1** `114413b9` + `c51b2fe7` — `receipt_layout_settings.show_order_note` (2 migrations), `SalesOrder::kitchenNote()`, KOT Blade/ESC-POS; **WEB-ORDERS-ALERT-1** ringing alert | NO | Edge prints the canonical KOT/receipt Blades from its replicated `receipt_layout_settings`; the bootstrap section is an **explicit column list** (no `show_order_note`) → after the merge the KOT would hide the note on Edge while Cloud shows it (parity gap, no crash). Alert = Cloud-only, inert | BEFORE_PRODUCTION | integration (manifest rows + bootstrap/importer column lists) | `EdgeTenantMigrationManifestTest`, `EdgeBootstrapSnapshotContractTest`, Edge print parity tests, `KotOrderNoteMySqlTest` |
| 5 | **KOT-DEAL-HEADING-1 + DEAL-BILL-NO-VARIANT-1** `7230a7b3` — `show_deal_as_heading`, `KotDealGroups`, kot/receipt/bill-preview Blades | NO | Same class as #4 (column list); the bill-preview partials are shared with Edge's restaurant board; DEAL-BILL-NO-VARIANT is Blade-only and applies on Edge once merged | BEFORE_PRODUCTION | integration (same work as #4) | as #4 + `KotDealHeadingMySqlTest` |
| 6 | **RECEIPT-CUSTOMER-SIZE-1** `c8e333d9` — `customer_font_size` on receipt layouts | NO | Same class as #4 (Edge renders `printing.documents.receipt`) | BEFORE_PRODUCTION | integration (same work as #4) | as #4 + `ReceiptCustomerSizeMySqlTest` |
| 7 | **DEAL-WHOLE-SPLIT-1** `8c8ee79e` — Split Bill moves a deal whole, items linked to the new deal line | NO | Edge has its own twin `EdgeLocalPosService::splitHeldSale` (parity with the older `SplitBillController::store`) → a held bill with a deal splits differently offline than online | BEFORE_PRODUCTION | integration (twin update) | `SplitBillDealWholeMySqlTest` twin on Edge routes, existing Edge split tests |
| 8 | **DEAL-SPLIT-REPORT-1** `7762ab39` `b41f0553` `b79ef1eb` `c3ab5ef3` — `combos.report_by_items`, `combo_components.report_amount`, `sales_order_lines.deal_share` stamped in `SalesOrderLine::creating` when the Master switch is on; report engine | NO | Stamping goes through the model on **every** write path, and Cloud ingestion creates Edge lines with `new SalesOrderLine(...)->save()` → an Edge-synced deal sale is stamped on the Cloud at ingestion with the Cloud's parts — **no envelope change needed**. On the appliance the switch is always OFF (the bootstrap tenant section carries no `features`) and `DealSplit::columnsReady()` guards the columns | BEFORE_PRODUCTION (manifest + proof) | integration: manifest row (`EDGE_SCHEMA_SAFE` proposed); deal form/report engine are Cloud screens | `EdgeTenantMigrationManifestTest`; new ingestion test with the switch ON; `DealSplitReportMySqlTest` |
| 9 | **SHIFT-HANDOVER-1** `2814f44d` — close a shift with open orders and open the next cashier's on the same counter (Master switch) | NO | `tenant/shifts/close.blade.php` now reads `$handover` **unguarded** and the Edge twin `EdgeLocalShiftController` renders that shared view → after the merge the Edge close page throws until the twin passes `handover => null` (`handovers` for close-branch). The handover itself needs an Edge twin in `ShiftService` terms if the owner wants it offline; switch is OFF on Edge by construction | BEFORE_PRODUCTION (view variable); DEFER (feature twin) | integration | Edge shift HTTP tests, `ShiftHandoverMySqlTest` |
| 10 | **SHIFT-LIST-COUNTER-1** `4769655e` — Shifts list / shift page / close screen show only the cashier's own counter | PARTIAL | Edge's twin already scopes open/close by `canOperateTerminal`, but `index`/`show` (lines 213–264) filter by branch only → in Local Mode a Takeaway cashier can read the Quick Sale counter's shift and cash | BEFORE_PRODUCTION | integration (≈30-line twin, same `UserDataScope` calls) | `ShiftListCounterScopeMySqlTest` twin on Edge routes |

### 3.2 Products, deals, pricing (Cloud screens)

| # | Change | In Edge | Offline impact | Priority | How |
|---|---|---|---|---|---|
| 11 | Product advanced settings: recipe BOM + cost-block in the unified form `64a50d77` `b78d8dba`; **COST-BLOCK-CLARITY-1** `0f6e2b9a`; product parity records | NO | None at the counter — products, recipes and ingredients reach Edge through explicit bootstrap/refresh column lists that these screens do not change | DEFER (Cloud-only screens) | n/a |
| 12 | Deal form "Each item's rate" `b41f0553` | NO | Cloud screen; see #8 for the data contract | DEFER | part of #8 |

### 3.3 Customers, returns, refunds, shifts, permissions — covered by #3, #9, #10. No new permission keys in the range.

### 3.4 Inventory, stock, configuration refresh, purchasing, finance

| # | Change | In Edge | Offline impact | Priority | How |
|---|---|---|---|---|---|
| 13 | **PURCHASE-EDIT-1** `f0019787` (schema) `27db1bc5` (code), **PURCHASE-EDIT-RECOST-1** `53d82a83`, **SUPPLIER-LEDGER-DAILY-1** `0d07db90`, **SUPPLIER-LEDGER-RANGE-1** `ef076e5d`, **BILL-DATE-FILTER-1** `f8dc13e5`, **PNL-DETAIL-RECOST-1 / -STOCK-EXPENSE-1**, SUPPLIER-COST-5001 records | NO | Goods receipts / purchase bills / P&L are Cloud screens; Edge's supplier finance twin renders its own `edge.finance.*` views, so the new From/To ledger filters do not touch it. Cloud ingestion of Edge supplier payments goes through the shared `SupplierPayableService` → `entry_date` is written by the shared code automatically. Schema: `supplier_ledgers.entry_date` + index, `goods_receipt_revisions` + `goods_receipts.revision_no`, `purchase_bills` status ENUM + `'voided'` (an ENUM MODIFY — same owner-review class as R-1, values preserved) + `voided_*` columns | BEFORE_PRODUCTION (manifest rows only) | integration: manifest classification; code stays Cloud-only |
| 14 | **PLACE-LEARNING-1** `e8f40726` `77fe736a` (`delivery_place_sightings`, hourly `online-ordering:learn-places`), **PLACE-NAME-NEAR-1** `fa375ecd` | NO | Website delivery areas = Cloud-only; `routes/console.php` already schedules nothing on a `branch_server` | DEFER | manifest row `CLOUD_ONLY` |
| 15 | **PRINT-PREPARE-1 + AGENT-UPGRADE-1** `2b054814` (schema) `0e0465bf` `c29634b8` (agent 2.7.0) — `print_prepares` table, `print_agents.agent_version`, 2 print-agent API routes, Master switch OFF everywhere | NO | Edge prints through its own print worker and network transport, not the Cloud print-agent API; `print_agents` is not a bootstrap section | DEFER (owner keeps printer certification an independent track) | manifest rows `CLOUD_ONLY` |
| 16 | FINANCE-RESTART-1, CHART-SEEDER-KEEP-NAMES-1, BILLING-DATE-KEEP-1, WEBSITE-HIDE-PRICES-1, CATERING-* (restore in place, venue name, kitchen sheet, send language), MASTER records, Khatri/Kashif data records | NO | Master / public site / catering / finance commands — all outside the Edge package (catering and VOID-PURGE-class finance already artifact-excluded by the rehearsal, C-7) | DEFER | none; `TenantResetTransactionsCommand` edits touch the rehearsal's KNOWN_KEPT list → resolve at merge |

### 3.5 New migrations in the range — proposed manifest classification (all UNCLASSIFIED today = RELEASE BLOCKER for any build)

| migration | proposal | why |
|---|---|---|
| `2026_11_02_000001_add_order_note_toggle` | EDGE_REQUIRED | Edge renders the KOT from replicated `receipt_layout_settings`; add to the bootstrap/importer column lists |
| `2026_11_02_000002_order_note_toggle_default_off` | EDGE_REQUIRED | changes the default + rewrites the rows (tiny table) |
| `2026_11_02_000003_create_delivery_place_sightings_table` | CLOUD_ONLY | website orders |
| `2026_11_03_000001_add_entry_date_to_supplier_ledgers` | EDGE_SCHEMA_SAFE | additive; Edge keeps the ledger table but writes through the shared service on the Cloud |
| `2026_11_03_000002_create_goods_receipt_revisions` | EDGE_SCHEMA_SAFE (or CLOUD_ONLY) | goods receipts are Cloud screens |
| `2026_11_03_000003_let_a_purchase_bill_be_voided` | OWNER_REVIEW (ENUM MODIFY, R-1 class) → EDGE_SCHEMA_SAFE | raw `SHOW COLUMNS` + `ALTER … MODIFY ENUM`; values preserved; `down()` empty |
| `2026_11_08_000001_add_deal_split_report_columns` | EDGE_SCHEMA_SAFE | additive; stamping happens on the Cloud at ingestion; switch OFF on the appliance |
| `2026_11_11_000001_add_deal_heading_toggle` | EDGE_REQUIRED | as the order-note toggle |
| `2026_11_12_000001_add_print_prepares_and_agent_version` | CLOUD_ONLY | Cloud print-agent API |
| `2026_11_14_000001_add_customer_font_size_to_receipt_layouts` | EDGE_REQUIRED | Edge renders the receipt |

New routes: `tenant.suppliers.ledger-all`, `tenant.goods-receipts.revise` (Cloud screens, switch-gated), two print-agent API
routes (Cloud). No Edge runtime route, envelope schema version or ingestion contract changed.

## 4. Is anything a genuine blocker for the first real Offline demo? **NO.**

- The LAB Cloud (export `ac5b168d`) and the LAB appliance (0.8.2-edge `a6113772`) are both on the Edge branch lineage: same
  schema, same page-data contract, same sync envelope on both sides of the demo.
- The demo scope (supervised takeover → local shift → cash sale → FakePrinter receipt → reconnect → exactly-once sync →
  reconciliation → controlled handback) touches none of the changed areas; none of the 134 commits changes authority,
  sync, ingestion or the cash-sale path, and none is a correctness fix on that path.
- One caveat to brief the demo operator on, not a blocker: 0.8.2 carries the pre-fix manager-code box (#3). If a demo step
  ever opens the manager code box from inside the pay window, open it from the cart instead (not in the 11 steps).
- The real gate is production, not the LAB: against the real Cloud at `5c74107f` the appliance needs the rehearsal promoted
  **and** the ten migrations above classified before any 0.8.x build is allowed to pair.

## 5. Recommended catch-up order (no merge started; each step its own owner go)

0. **Promote the rehearsal** (`integ/canonical-rehearsal-082`, READY_FOR_OWNER_DECISION) onto Edge after the owner signs
   R-1 / C-2 / H1 / KNOWN_KEPT; 1 real conflict (`index.blade.php` vs F-1) + regenerate the system map. Everything below
   builds on it.
1. **Classify the 10 new migrations** in `edge-tenant-manifest.php` (table above) — release blocker otherwise.
2. **Shared-view / contract chunk** (small, mechanical): #3 PIN focus/Enter, #1 RECALL-HIDDEN-ITEM-1 with the
   `recallProductsPayload` page-data key and the Edge recall-only list, #9 `handover => null` in the Edge shift twin,
   #10 counter scoping in the Edge shift list/detail.
3. **Print parity chunk**: #4/#5/#6 — three `receipt_layout_settings` columns into the bootstrap/importer lists,
   `KotDealGroups`, DEAL-BILL-NO-VARIANT; print parity tests on the isolated set.
4. **Deals**: #8 manifest carry + the Cloud-ingestion stamping proof; #7 DEAL-WHOLE-SPLIT-1 twin in `splitHeldSale`.
5. **Purchasing / finance schema rows** (#13) — classification only; code stays Cloud.
6. **Cloud-only exclusions** (#14, #15, #16) — manifest rows and nothing else.

Independent tracks untouched: canonical integration (this list), Windows reboot reliability (service needs elevated
approval), physical-printer certification (PRINT-PREPARE / agent 2.7.0 noted, not adopted).
