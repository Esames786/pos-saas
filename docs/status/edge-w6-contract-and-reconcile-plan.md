# Offline Edge — W6 (Team 6): Cloud/Edge contract design, canonical reconcile assessment, 0.7.0-edge release plan

Wave 1 = DESIGN + ASSESSMENT ONLY (24 Sep 2026). No code changed, no merge run, no worktree created, no git write. Read-only git
used: `fetch`, `log`, `diff`, `merge-tree`, `show`, `ls-tree`, `rev-parse`, `status`. No secret was opened or printed
(`C:\Users\Dell\BingooEdgeLab\secrets` and `C:\Users\Dell\.bingoo-edge-release` were not read; only PUBLIC custody facts from
`edge-p5b-release-operations.md` are quoted).

```
EDGE_HEAD            feat/edge-config-refresh-v1 @ d82612f (docs-only above 63b88d8); worktree carries Teams 1-5 UNCOMMITTED edits
                     (git status 24 Sep: EdgeLocalPosService, EdgeLocalRestaurant/HeldSales/Shift controllers, EdgeLocalPrintDeliveryService,
                     EdgeTableReservationService, config/edge.php, routes/edge_runtime.php, EdgeBranchServerRegistrationTest + 7 new files)
                     -> every file:line below is cited at the COMMITTED HEAD d82612f unless marked "(worktree)".
CANONICAL            origin/feat/14d-2-plan-upgrade-requests @ b529c95 (fetched 24 Sep; audit baseline was 243e01d)
LAST_RECONCILE_BASE  5dc13d3 (= git merge-base HEAD origin/feat/14d-2-plan-upgrade-requests)
CANONICAL_DELTA      59 commits 5dc13d3..b529c95 (79 files, +9151/-266 overall; 43 files under app routes resources config database)
INSTALLED_LAB        0.6.0-edge built from 623f887 (edge-p5c-home-lab.md:56); 623f887..HEAD adds NO migration (git diff 623f887..HEAD -- database/migrations = empty)
PRODUCTION_CLOUD     acf33a8 (last verified by the canonical session, audit §3 D); no Edge device is paired to production
```

---

## PART A — Canonical reconcile assessment

### A1. Shipped-file inventory: `git diff --stat 5dc13d3..origin/feat/14d-2-plan-upgrade-requests -- app routes resources config database`

Artifact rules used for "Ships?": `config/edge.php:328-452` — include allowlist (`app`, `config`, `database/migrations`, `resources`,
`routes`, …) then exclude globs, matched by `EdgeArtifactBuilder::isExcluded` (`app/Services/Edge/EdgeArtifactBuilder.php:221-247`:
exact path, directory prefix, and `Catering*`-style basename globs for application source). "Executes?" = reachable from the Branch
Server runtime: on `APP_ROLE=branch_server` `routes/web.php:9-10` loads ONLY `routes/edge_runtime.php` (`routes/tenant.php` is required
only on the Cloud branch, `routes/web.php:15`).

| # | File (canonical change) | Ships? | Executes on the appliance? (the Edge code path) |
|---|---|---|---|
| 1 | `app/Services/Sales/KotCancellationService.php` (+94/−) MANAGER-APPROVAL-COMBO-VOID-1 | yes | **YES** — `EdgeLocalPosService` injects it (`EdgeLocalPosService.php:77`) and calls `recordLineCancellations` on Add-Round line reductions (`:909`) and `cancelHeldOrder` (`:1241`). Edge today runs the pre-fix count rule (`KotCancellationService.php:158` `if (count($resolved) === 1)` → demands `void_kot_item`); canonical decides by the approval's own `action_type` (canonical `KotCancellationService.php:159-245`, `:201` `if ($suppliedType === 'void_kot_items')`). |
| 2 | `resources/views/tenant/printing/documents/receipt.blade.php` (+45) TABLE-BILL-PREVIEW-PARITY-1 | yes | **YES for every receipt** — Print Here renders it through `EdgeLocalPrintJobController::printDocument` → `Tenant\PrintDocumentController::preview` (`EdgeLocalPrintJobController.php:94-102`; view at `PrintDocumentController.php:91`). The new block is `@isset($tableBill)` (canonical `receipt.blade.php:303`); no Edge caller passes `tableBill` → output unchanged. Edge's own change to the same file is `$edgeMarkPrintedUrl ??` (`receipt.blade.php:327`) — a different hunk. |
| 3 | `app/Http/Controllers/Tenant/RestaurantTableSessionController.php` (+110) TABLE-BILL-PREVIEW-PARITY-1 / BILL-PREVIEW-WRONG-PRINT-1 | yes (Tenant controllers are not excluded) | no — not referenced by `routes/edge_runtime.php`; Edge only mirrors its *semantics* (`EdgeLocalPosService.php:600` comment; `EdgeLocalRestaurantController.php:142` (worktree)). New private `renderTableBillReceipt()` (canonical `:366-424`) and `held_sale_ids` in the JSON (`:318-330`) define the NEW Online spec for R14. |
| 4 | `resources/views/tenant/pos/index.blade.php` (+98) BILL-PREVIEW-UNHIDE-1 / TABLE-WORKSPACE-WIDTH-1 / BILL-PREVIEW-WRONG-PRINT-1 | yes | no — Edge serves `resources/views/edge/pos/index.blade.php`; the Online page is the REFERENCE the census reads (`tests/Fixtures/edge/online-pos-control-census.json`) |
| 5 | `resources/views/tenant/pos/partials/table-board.blade.php` (+15) | yes | no (same reason) |
| 6 | `app/Http/Controllers/Tenant/DashboardController.php` (+63) | yes | no (Cloud dashboard route in `routes/tenant.php`) |
| 7 | `resources/views/tenant/dashboard.blade.php`, `tenant/partials/catering-calendar.blade.php`, `tenant/partials/catering-kpis.blade.php` | yes (not under an excluded dir; basenames lower-case) | no |
| 8 | `app/Services/Reports/SalesReportService.php` (+13, optional `?string $orderType = null`) | yes (Reports services are KEPT, `config/edge.php:425-429`) | no — no Edge file references it (callers: DashboardController, Reports\SalesReportController, JournalPostingService, RestaurantReportService, SalesReportEngine); the parameter defaults to previous behaviour (P5B §1 assessed the same hunk, `edge-p5b-release-operations.md:19`) |
| 9 | `app/Http/Controllers/Tenant/Reports/SalesAnalyticsController.php` (new) + `resources/views/tenant/reports/analytics.blade.php` (new) | controller NO (`app/Http/Controllers/Tenant/Reports` excluded, `config/edge.php:379`); view yes | no |
| 10 | `routes/tenant.php` (+7, `/reports/analytics`) | yes | no (`routes/web.php:9-15`) |
| 11 | `app/Http/Controllers/Tenant/CustomerController.php` (+31) | yes | no — not in `routes/edge_runtime.php`; Edge customer search lives in `EdgeLocalPosController` |
| 12 | `app/Services/Tenant/CustomerDirectory.php` (new; phone-normalised find/findOrCreate) | **yes** (nothing excludes `app/Services/Tenant`) | no — only CustomerController + Catering reference it (`git grep` on b529c95) |
| 13 | `app/Jobs/Catering/SendCateringCustomerMailJob.php` (new) | **yes — NOT excluded**: basename `SendCatering…` does not match `Catering*`, and `app/Jobs/Catering` is not an exclude prefix | no — but it `use`s excluded classes (`CateringEstimate`, `CateringEvent`, `CateringEmailLog`, `CateringCustomerMail`); inert dead code in the artifact (see risk R-A3) |
| 14 | `app/Support/Catering/CourseOrder.php` (new) | **yes — NOT excluded** (basename `CourseOrder.php`; `app/Support/Catering` not a prefix) | no (inert) |
| 15 | `app/Console/Commands/CateringFixCourseCategoriesCommand.php`, `app/Models/Tenant/CateringSetting.php`, `app/Services/Catering/*` (2), `app/Http/Controllers/Tenant/Catering/*` (5), `resources/views/tenant/catering/**` (17 views) | NO — `Catering*` basename glob, `app/Services/Catering`, `app/Http/Controllers/Tenant/Catering`, `resources/views/tenant/catering` (`config/edge.php:372,430,434-435`) | no |
| 16 | `database/migrations/tenant/2026_09_17_000001_add_customer_email_switch_to_catering_settings.php` (new) | **yes** (`database/migrations` is included whole) | **YES at update time** — the appliance schema upgrader applies `database/migrations/tenant` then `database/migrations/edge` (`EdgeLocalSchemaUpgrader.php:50-53`). Additive, idempotent (`hasColumn` guard), boolean `send_customer_emails` default true on `catering_settings`; references no seeder/model (so the `keep` list rule at `config/edge.php:352-360` is not triggered) |

Totals: 43 files; **2 executed at runtime** (KotCancellationService, receipt.blade.php), **1 executed once at update** (the tenant
migration), **3 ship but are unreferenced** and not covered by any exclude rule (CustomerDirectory, SendCateringCustomerMailJob,
CourseOrder), the rest ship inert or are excluded. Since the audit baseline (243e01d → b529c95, 25 app/resources/database files)
**no additional executed file changed**: the delta is Catering + CustomerController/CustomerDirectory + DashboardController + dashboard
partials (`git diff --stat 243e01d..origin/feat/14d-2-plan-upgrade-requests`).

### A2. Conflict assessment

```
git merge-tree --write-tree --name-only HEAD origin/feat/14d-2-plan-upgrade-requests  -> exit 0, tree 6c1b4fec4751…, NO conflicted paths
files changed on BOTH sides since 5dc13d3                                                -> 1: resources/views/tenant/printing/documents/receipt.blade.php
                                                                                            (canonical hunk @@ -292 +292,51 / Edge hunk @@ -324 +324 — disjoint)
```

`merge-tree` evaluates COMMITS only. Teams 1–5 are editing the worktree concurrently; none of their owned files (charter §1) is in the
canonical delta. Collision matrix (canonical file → Edge files that could meet it):

| Canonical change | Edge file(s) that could collide | Owner | Collision type |
|---|---|---|---|
| `KotCancellationService.php` (behaviour) | `EdgeLocalPosService.php` callers (`:909`, `:1241`); Team 3 void-reason / combo grouped void UI (`js/actions`, `js/held`) | Team 2 (file), Team 3 (flow) | **semantic, not textual**: before the reconcile a one-line combo void carrying a `void_kot_items` approval is refused on Edge; after it, accepted. Team 3 must not edit the service (charter §1). |
| `receipt.blade.php` (`@isset($tableBill)`) | Team 5 (`EdgeLocalPrintDocumentService.php`, worktree, untracked) and Team 3 (R14 bill preview, `EdgeLocalTableOperationsService.php`, worktree) if either edits the shared document | Team 5 / Team 3 | textual only if a team edits `receipt.blade.php` itself (not in their ownership); passing `tableBill` from an Edge renderer is safe only AFTER the reconcile (before it the block does not exist in Edge's copy) |
| `RestaurantTableSessionController.php` (+110) | any extraction of `renderTableBillReceipt()` into a shared service | Team 3 | textual — do NOT refactor the canonical controller from Edge; compose the same view + the same `tableBill` shape (`session`, `rounds` = held, `paid`) in an Edge-owned service |
| `tenant/pos/index.blade.php` | census fixture / `EdgeCashierControlCensusHttpMySqlTest` | coordinator | the census reads the Online page — new/moved Online controls (bill-preview unhide, `held_sale_ids`) may add rows → census test fails on purpose → coordinator re-baselines after the merge |
| new tenant migration | `EdgeLocalSchemaUpgradeMySqlTest`, `EdgeCleanMachineInstallMySqlTest`, any team adding `database/migrations/edge/*` | coordinator / Teams 2-4 | ordering only (tenant path runs before edge path) |

### A3. Reconcile procedure (for the coordinator; nothing here was executed)

0. **Precondition** — worktree clean (commit or park team work first; never bare `git stash` — shared stack). `git fetch origin`;
   re-run `git merge-tree --write-tree --name-only HEAD origin/feat/14d-2-plan-upgrade-requests` and require exit 0. If canonical moved
   past b529c95, repeat A1 for the new commits.
1. **Merge** — `git merge --no-ff origin/feat/14d-2-plan-upgrade-requests -m "EDGE W6: reconcile canonical <sha> (MANAGER-APPROVAL-COMBO-VOID-1, TABLE-BILL-PREVIEW-PARITY-1, …)"`.
   Expected: 79 files from the canonical side (43 app/routes/resources/database + 9 docs/plans|reference|status + 27 tests), auto-merged
   `receipt.blade.php`, no Edge-owned file touched.
2. **Artifact exclude follow-up (same change set, W6-owned lines of `config/edge.php` 'exclude')** — add `app/Jobs/Catering` and
   `app/Support/Catering` (and decide on `app/Services/Tenant/CustomerDirectory.php`: unreferenced by Edge; excluding it is optional
   because it is framework-safe code). Team 4 owns only the `capabilities` block of this file — coordinate the edit. Note: the
   worktree copy of `config/edge.php` already carries uncommitted W3 and W5 `route_allowlist` blocks (diff vs HEAD at `:268`), so
   several teams are editing this file; all `config/edge.php` line numbers in this document are at HEAD.
3. **Targeted tests per shipped/executed file** (per-team DB env of charter §3, e.g. `_edgewt_t6`):

   | Shipped file | Tests |
   |---|---|
   | `KotCancellationService.php` | canonical `tests/MySql/ManagerApprovalComboVoidMySqlTest.php` (arrives with the merge), `CancelFreesTableMySqlTest`, `tests/Unit/Tenant/CancellationPolicyRegressionTest.php`; Edge `EdgeLocalRestaurantHttpMySqlTest` (void approvals `:270-333`), `EdgeCashierDineInHttpMySqlTest`, `EdgeLocalPosMySqlTest`, `EdgeIdentityFlowMySqlTest`; **NEW (W6 wave 2):** `EdgeComboVoidReconcileHttpMySqlTest` — one-line combo void with a `void_kot_items` approval over `/edge/local/pos/*` is accepted; a single `void_kot_item` approval over two lines is refused; payload/expiry/single-use unchanged |
   | `receipt.blade.php` | `EdgeCashierPrintingHttpMySqlTest`, `EdgeLocalPrintDeliveryMySqlTest`, `EdgeNoDuplicatePrintAfterSyncMySqlTest`, `ReceiptProformaVsFinalMySqlTest`, canonical `BillPreviewPrintTargetMySqlTest`, `tests/Unit/Tenant/TableBillDecimalRegressionTest.php`; byte-equality of an Edge receipt before/after (no `tableBill`) |
   | tenant migration 2026_09_17 | `EdgeLocalSchemaUpgradeMySqlTest` (forward-only, data-loss audit), `EdgeCleanMachineInstallMySqlTest` (fresh install + A→B update) |
   | inert shipped files | `tests/Feature/Edge/EdgeApplianceDependencyClosureTest.php` (the closure must not reach `App\Services\Catering\…`, `:73-75`), `EdgeArtifactTest` (`test_built_artifact_source_scan_reports_zero_cloud_module_files`, `:331-362`), `EdgePackageBuilderTest` |
4. **Full-suite gate** — `vendor/bin/phpunit --testsuite Feature,Unit` (SQLite, incl. `tests/Feature/Edge`), then the full MySQL suite
   (`phpunit -c phpunit.mysql.xml`, ≈1432+ tests, 35–45 min, detached with a log; per-worktree DB names) — both green, failures
   attributed, never skipped.
5. **Artifact boundary gate** — `EdgeArtifactTest`, `EdgePackageBuilderTest`, `EdgeApplianceDependencyClosureTest`,
   `EdgeRecoveryAuthorityBoundaryTest`, `EdgeBranchServerRegistrationTest` (route census), `EdgeBladeCompileGateTest`, `EdgeLogHygieneTest`;
   then a DEV `edge:build-package --allow-dirty --no-sign` + `edge:audit-package` and confirm `cloud_only_present=[]`,
   `forbidden_hits=[]`, and that `app/Jobs/Catering` / `app/Support/Catering` are absent once step 2 lands.
6. **Census re-baseline** — run `EdgeCashierControlCensusHttpMySqlTest`; any new Online control row from the merged Online page is
   added deliberately by the coordinator (not by a team).
7. Push; record in the board: `RECONCILED_TO=<sha>`, records flipped (A4).

### A4. Parity records the reconcile alone closes or changes

| Record | Before | After the merge (source) | Still needed |
|---|---|---|---|
| **R26 / D-25 (combo void)** | `CANONICAL_DRIFT_NOT_RECONCILED` — shared service on Edge refuses a one-line combo void with `void_kot_items` | drift closed in SOURCE: Edge executes the canonical shape-based rule | Team 3's grouped-void UI must mint `void_kot_items` for deal lines (Online `requestComboQuantity` behaviour) — the Edge page mints no void approval today (only `manual_discount` / `sales_return`, `js/commercial.blade.php:60,75`, `js/returns.blade.php:66`); + the NEW Edge test above; + 0.7.0 release to reach the LAB → status `PRESENT_IN_SOURCE_NOT_INSTALLED` until then |
| **R14 / A30 / D-10 (per-table bill preview)** | Online spec = receipt document with rounds + previously-paid + print target = held ids | the `@isset($tableBill)` block becomes available in Edge's copy of the shared document; spec for Edge is now fixed: same receipt view, unsaved aggregate SalesOrder of HELD rounds, `tableBill = [session, rounds(held), paid]`, print target = held sale ids (canonical `RestaurantTableSessionController.php:318-330,366-424`) | Team 3 builds the Edge renderer + print path (Team 5); nothing is closed by the merge alone — the record changes from `CANONICAL_DRIFT_NOT_RECONCILED` to an ordinary W3 gap |
| D-25 receipt drift | Edge receipt lacks the dormant block | byte-identical shared document | — (closed by the merge) |
| Online UX drift (bill-preview unhide, workspace width) | Online page moved | census fixture must be re-read | coordinator census re-baseline |

No other audit record changes: DashboardController / SalesAnalytics / SalesReportService / Catering / CustomerController are not executed.

### A5. Reconcile risks

- **R-A1 KotCancellationService on the appliance (intended behaviour change).** After the merge a one-line combo void with a
  `void_kot_items` approval is accepted; a multi-line cancel with a single `void_kot_item` approval stays refused (canonical
  `:220-227`). The approval guarantees (single-use, 10 min, same cashier, payload match) are unchanged per the canonical comment.
  Edge-specific: Edge approvals are minted by `verifyManagerApproval` with `void_kot_items` mapped to `tenant.pos.void-kot-item`
  (`EdgeLocalPosService.php:1035-1042`) — no mapping change needed. Risk = LOW; proof = the new Edge test.
- **R-A2 `receipt.blade.php` `@isset($tableBill)` on the appliance.** Dormant unless a caller passes `tableBill`. When Team 3 passes it,
  the block uses `$money` and `$round->sale_no` / `grand_total` — Edge sale numbers are `SO-<branch>-<terminal>-<ULID>`
  (`EdgeLocalPosService.php` held-sale creation) and will print long; verify on 80 mm paper. Risk = LOW.
- **R-A3 Inert Cloud code shipped.** `SendCateringCustomerMailJob` (references excluded Catering models) and `CourseOrder` would ship
  because no exclude rule matches them; the dependency-closure gate does not flag unreachable files. Not a runtime risk (never
  instantiated on a Branch Server) but it breaks the "Catering physically excluded" claim → fix with step A3.2. Risk = LOW, cheap fix.
- **R-A4 `CustomerDirectory` / `CustomerController` on the appliance.** Ship, not routed; Edge never creates customers (accepted
  ONLINE_REQUIRED "new customer at the till", audit §G). If a later Edge customer feature is built, reuse `CustomerDirectory::findByPhone`
  for phone normalisation instead of forking. Risk = NONE today.
- **R-A5 New tenant migration runs on the appliance DB at update.** Additive column on `catering_settings`; the forward-only upgrader
  applies it (`EdgeLocalSchemaUpgrader.php:82-120`), the data-loss audit cannot trip (no shrink). It also must run on the LAB Cloud
  tenant DB (`pos_lab_tenant_edge`) — owner-gated (A5 is the only LAB-DB mutation the reconcile implies).
- **R-A6 LAB Cloud serves this worktree** (`edge-online-vs-edge-screen-audit-2026-09-20.md:28`; `edge-p5c-home-lab.md:149`). The merge
  changes the running LAB Cloud code the moment it lands (and the teams' uncommitted edits already do). The reconcile adds no Cloud
  ingestion/bootstrap change, so the installed 0.6.0 appliance is unaffected; but see B0.4 for bootstrap-schema bumps, which WOULD break
  the running LAB appliance's config refresh.
- **R-A7 Ancestry drags Cloud-only features** (59 commits). P5B chose "assessed, not merged" to avoid that (`edge-p5b-release-operations.md:21`);
  now a shared executed service changed, so a merge is justified. Alternative: cherry-pick 8d11bfb only (KotCancellationService + its
  test) — smaller, but leaves `receipt.blade.php` drifted and makes the next merge replay the same hunk. Recommendation: full merge.

**Reconcile risk level: LOW** (0 textual conflicts; 1 overlapping file with disjoint hunks; 2 executed files, both covered by existing +
one new test; 1 additive migration; 2 exclude-list additions).

---

## PART B — Contract design for the fields Teams 2–4 hit at the boundary

### B0. Cross-cutting decisions

**B0.1 What exists today (HEAD d82612f).**
- Envelope `edge-sale-envelope-v1` (`EdgeSaleEnvelopeBuilder.php:34`), built INSIDE the paid-sale transaction and stored immutable in the
  outbox (`EdgeLocalPosService.php:289,1206` → `EdgeSyncOutboxService.php:34-55`). Keys: binding (`:64-73`), identity (`:76-85`),
  principals (`:88-93`), `shift` snapshot (`:96-102`), `table_session` snapshot (`:103-109`), `kot_events` (`:110-113`), `customer`
  (`:116`), `totals` incl. `discount_*`, `tip_amount` (`:119-133`), `delivery` (`:135-139`), `lines[]` incl. `discount_amount` and
  `modifiers` (`:141-154`), `payments[]` (`:156-165`), `operational_stock` (`:169-172`), `local_state` (`:175-180`), `content_hash` (`:185`).
  **No `notes`, no `kitchen_note`, no print intent.**
- Fail-closed guards: tip refused (`:245-247`); a discount with `discount_type='none'` and no promotion refused (`:242-244`); cash only
  (`:38,272-277`).
- Cloud `EdgeInboundSaleIngestionService` accepts ONLY `edge-sale-envelope-v1` (`:49,84-86`); refusal `SCHEMA_UNSUPPORTED`. It projects
  header totals incl. `tip_amount` (`:299`) and line `discount_amount` / `modifiers` JSON (`:340,343`); posts product FEFO / recipe
  (`:349-384`); posts GL + cash-bank (`:138-139`) and verifies them (`:145`). It does **not** call modifier stock consumption, does **not**
  project `notes`, `shift_id`, `restaurant_table_session_id`, `restaurant_table_id` or the dine-in waiter (`projectSale`, `:272-310`).
- Sender routes by schema to one URL per event family (`EdgeSyncSender.php:56-62`); `SCHEMA_UNSUPPORTED` is a TERMINAL verdict
  (`EdgeIngestionVerdicts.php:19`) → the row becomes `failed_permanent` (`EdgeSyncSender.php:137-142`). A refused (never applied) Cloud
  registry row may be re-attempted with the SAME content (`EdgeInboundSaleIngestionService.php:104-107`).
- `config('edge.sync_protocol')` = `'edge-sync-v0'` placeholder (`config/edge.php:31`), reported but not negotiated
  (`EdgeBuildInfoService.php:60-63`).
- The Edge local DB applies the same tenant migrations as the Cloud (`EdgeLocalSchemaUpgrader.php:50-53`), so every Cloud column
  below (`sales_orders.notes`, `sales_order_lines.kitchen_note`, `modifiers`, `tip_amount`, `direct_pay_print_state`, `shifts.closing_notes`,
  `cash_count_lines`, `pos_quick_report_settings`) already exists on the appliance — **no Edge migration is needed for any of them.**

**B0.2 Versioning rule (recommended).**
1. **Additive optional keys stay in v1** when an old Cloud that ignores them produces the SAME money and stock: an old ingester hashes
   the whole envelope incl. unknown keys (`:525-534`), so new optional keys are hash-safe and silently ignored.
2. **Bump to `edge-sale-envelope-v2`** only when an old Cloud ignoring a key would post WRONG official state. Today that is exactly one
   case: a line whose modifier has `consume_stock=1` (old Cloud would skip official modifier stock/COGS). The builder emits v2 only for
   such sales ("minimal version emission"); every other sale stays byte-shape v1 — existing contract tests and an old Cloud keep working.
3. Cloud accepts `['edge-sale-envelope-v1','edge-sale-envelope-v2']` (turn `SUPPORTED_ENVELOPE_SCHEMA` into a list; registry column
   `envelope_schema_version` is `string(64)`, no migration).
4. **Appliance sender: treat `SCHEMA_UNSUPPORTED` as retryable-with-alert, not terminal** (appliance-side override in `EdgeSyncSender`, the
   shared Cloud list unchanged) so a new appliance talking to a not-yet-upgraded Cloud parks rows `pending` instead of `failed_permanent`;
   they apply once the Cloud is upgraded (the refused registry row is re-attemptable, `:104-107`). This touches the outbox engine →
   coordinator approval required (charter §0.4).
5. **Deployment order rule:** Cloud first, appliance second. The LAB Cloud serves this worktree, so it is always ≥ the appliance;
   production Cloud (acf33a8) has no paired device and must be upgraded to the Edge release commit before any production pairing.
6. `sync_protocol` → `'edge-sync-v1'` with 0.7.0 (informational; nothing negotiates it today).

**B0.3 Exactly-once invariants that every change must keep.** The envelope is built once inside the sale transaction (no re-build on
replay); Cloud idempotency is the `sale_uuid` registry + `content_hash` (`:92-108`, unique index + collision convergence `:161-168`);
lines/payments keep their canonical `line_uuid` / `payment_uuid` (`:345,402`). Any new Cloud posting (modifier FEFO, shift mirror,
voucher) must run INSIDE the same ingestion transaction so a replay (`already_applied`, `:99`) causes zero further effects.
Local idempotency: `effectiveIntent()` (`EdgeLocalPosService.php:366-397`) must include every new client-controlled field (tip, line
discounts, notes, kitchen notes, print intents) — otherwise a retry with a different value replays the first sale silently. The Cloud
canonicalizer already hashes `tip_amount`, `kot_print_intent`, `receipt_print_intent`, line `discount_amount`
(`SaleIdempotencyService.php:56-58,67`).

**B0.4 Bootstrap contract is also in scope (found during this design).** Several W2/W4 features need config the appliance does not
receive today; adding a section follows the v5→v6 precedent (`EdgeBootstrapService.php:35`, "a v5 export … must be refused") and the
appliance importer requires an EXACT schema match (`EdgeLocalBootstrapImporter.php:171-173`).
- `product_modifier_group` (which groups apply to which product) is **not** in the bootstrap (`EdgeBootstrapService.php:579-590` section
  list) — Team 2's modifier resolver joins it (`EdgeLocalPosService.php:1708`, worktree) → on a real appliance it would resolve no groups.
- `modifier_groups` ships only `where('branch_id', $b)` (`:715-716`) — Online also offers global groups (`branch_id NULL`), which Team 2's
  resolver accepts (worktree `:1711`) but the appliance never receives.
- `modifiers` ship only when `linked_product_id` is NULL or a POS-visible product (`:718-720`); products ship only if sellable+POS-visible
  (`:626-627`) → a `consume_stock` modifier linked to a raw ingredient is dropped, and its linked product is absent for Edge operational
  stock (`EdgeOperationalStockService.php:229-275` throws on a missing linked product).
- `currencies` / `currency_denominations` are not in the bootstrap → the denomination count (B6) cannot render offline.
- `pos_quick_report_settings` is not in the bootstrap (B9).
→ One bump **`edge-bootstrap-v7`** carrying all of the above. **LAB coupling:** the LAB Cloud serves the worktree, so the moment v7 lands
the running 0.6.0 appliance's config refresh is refused (`SCHEMA_UNSUPPORTED`) and its CONFIG_COMPATIBLE readiness gate can drop out of
STANDBY_READY; the Cloud also classifies it `software_update_required` (`EdgeCompatibilityService.php:71-74`). The v7 commit must land
immediately before the owner-approved LAB update (or the LAB Cloud must be pinned to an export of the release commit — owner choice, C6).

### B1. Line modifiers (ids, names, price deltas, linked-product stock consumption) — Team 2

| Aspect | Fact / design |
|---|---|
| Edge local schema today | `sales_order_lines.modifiers` JSON (tenant migration `2026_06_25_000002`); written raw from the request (`resolveLines` `EdgeLocalPosService.php:1482`, `createSaleLines` `:981`) — no normalisation, no price delta (price is catalog-only, `:1473` H6), no min/max check at HEAD. Edge OPERATIONAL modifier stock already mirrors Cloud (`EdgeOperationalStockService.php:85,229-275`). Team 2's worktree adds `resolveModifiers` in Online's normalized shape (worktree `:1694-1745`). |
| Envelope today | `lines[].modifiers` passes the stored JSON (`EdgeSaleEnvelopeBuilder.php:153`); `unit_price` is the stored price. |
| Cloud ingestion today | stores the JSON (`:343`); **no** `consumeLineModifiers` (Cloud POS does it in `SalesService.php:108-110,180-262`, a PRIVATE method). |
| Additive change | (a) Edge prices `unit_price = catalog price + Σ price_delta` from the synced book (Online sums deltas client-side, `tenant/pos/index.blade.php:2611`; Cloud trusts the submitted price, `SalePricingService.php:25-27`) — Edge must compute it server-side; (b) envelope `lines[].modifiers[]` = `{modifier_group_id, modifier_group_name, modifier_id, name, price_delta}` (Online `normalizeLineModifiers`, `SalesOrderController.php:970-993`) + audit-only snapshot `consume_stock`, `linked_product_id`, `linked_quantity`, `linked_unit_id`; (c) **v2** when any selected modifier has `consume_stock=1` (B0.2); (d) Cloud: extract `SalesService::consumeLineModifiers` into a shared public method (additive refactor, Cloud POS behaviour identical) and call it in `projectLinesWithOfficialStock` after the product FEFO, adding modifier cost to the line COGS as Cloud does; `INSUFFICIENT_STOCK` stays the retryable verdict; (e) bootstrap v7 (B0.4). Stock semantics = Cloud's CURRENT modifier book at ingest (same precedent as recipes, `:354-357`); the snapshot is evidence only. |
| Migrations | none (Cloud master/tenant/Edge). |
| Exactly-once | modifier FEFO inside the ingestion transaction; replay → `already_applied`, zero movements; two-worker race → one set of `modifier_consumption` ledgers. |
| Backward compat | old appliance (v1, raw modifiers, no deltas) → new Cloud: v1 path unchanged (JSON stored; decide whether v1 also consumes — recommend NO, to keep v1 semantics frozen). New appliance → old Cloud: price-only modifiers go as v1 (correct); consume-stock sales go v2 → old Cloud refuses `SCHEMA_UNSUPPORTED` → rows wait (B0.2.4). |
| Tests | Edge: `EdgeSyncEnvelopeContractMySqlTest` + new `EdgeModifierEnvelopeMySqlTest` (shape, delta-priced unit_price, v1 vs v2 selection, hash stable, secret scan); Cloud: `EdgeInboundSaleIngestionMySqlTest` + new cases (v2 posts `modifier_consumption` FEFO with unit conversion; misconfigured modifier → refusal and full rollback; v1 unchanged); replay + concurrent-workers cases (`:284,505` pattern); F1: return of a modifier line (see B-F). |
| Owner-dependent | no. |

### B2. Per-line kitchen notes — Team 2 (+ Team 5 printing)

| Aspect | Fact / design |
|---|---|
| Online reference | **no canonical writer exists**: `SalesOrderController::validateSale` has no `lines.*.kitchen_note` rule (`:846-862`), HeldSaleController only READS it (`:122,238`), and the Online page never sets it (`git grep kitchen_note` on b529c95: only readers — printing `EscPosPayloadService.php:346,851,1126`, `PrintJobService.php:857,902`, KDS, split). The audit's A10 "notes" claim must be re-verified by Team 2 against the live Online page before building. |
| Edge local | column exists (`0001_01_01_000013_create_printing_tables.php:142-143`); Edge copies it only on split (`EdgeLocalPosService.php:1357`). |
| Envelope / Cloud | not carried / not written. |
| Additive change (if the owner confirms an Online writer) | `lines[].kitchen_note` (string ≤ 255, nullable) in **v1** (non-financial; an old Cloud dropping it loses text only); Cloud maps it in `projectLinesWithOfficialStock`. KOT printing is local (Team 5) — the note never triggers a Cloud print. |
| Tests | envelope shape + Cloud projection + replay; KOT byte proof on the FakePrinter (Team 5). |
| Owner-dependent | no — but **blocked on an Online reference** (rule 0.1: Online defines the feature). |

### B3. Line-level discounts — Team 2

| Aspect | Fact / design |
|---|---|
| Edge local | refused at HEAD: `EdgeLocalPosService.php:355-356`; per-line `discount_amount` column exists. |
| Envelope | carries `lines[].discount_amount` (`:150`) and header `discount_amount` (`:121`) — but the guard `:242-244` refuses a sale whose header discount comes only from line discounts (`discount_type='none'`, no promotion) → the paid sale would ROLL BACK at the till (builder throws inside the transaction). |
| Cloud | maps both (`:291,340`); SalesTotalsService folds line discounts into `manual_discount_amount` (`SalesTotalsService.php:45-65`); GL revenue is grossed up by header `discount_amount` (`JournalPostingService.php:293`). Online consumes ONE `manual_discount` approval for the whole manual amount incl. line discounts (`SalesOrderController.php:266-291`). |
| Additive change | Edge-side only, **v1**: relax guard `:242` to "discount explained by discount_type ≠ none OR a promotion OR Σ lines.discount_amount > 0"; add audit key `totals.line_discount_total`; Edge `consumeManualDiscountApproval` must bind the same `manual_discount_amount` payload as Online; `effectiveIntent` must hash per-line discount. No Cloud change; old Cloud posts the same GL. |
| Tests | Edge: line discount with/without manager approval (branch mode), envelope guard, hash; Cloud: ingestion GL balance with line-only discount (`EdgeFinancePostingVerifier` passes); replay. |
| Owner-dependent | no. |

### B4. Tips — Team 2

| Aspect | Fact / design |
|---|---|
| Edge local | forced 0 (`EdgeLocalPosService.php:245` sale, `:780` held); `SaleOperationalSettlementService.php:104-110` already books a `tip` sub-ledger entry when > 0. |
| Envelope | carries `totals.tip_amount` (`:129`) but the guard refuses > 0 (`:245-247`). |
| Cloud | projects `tip_amount` (`:299`); GL credits account 4140 Tips (`JournalPostingService.php:258,305-306`). |
| Additive change | Edge-side only, **v1**: remove guard `:245-247`; pass `tipAmount` to `SalesTotalsService` on Direct Pay / settle (held stays 0 — "caller enforces 0 for held sales", `SalesTotalsService.php:102`); hash it. Cash-only tender unchanged. |
| Tests | Edge sale with tip → envelope; Cloud ingestion → 4140 credit, cash-bank = paid, verifier green; replay; **F1:** return of a tipped sale — current F1 refund math must be proven unchanged (tip not refunded unless Online refunds it; read `SalesReturnService` before building). |
| Owner-dependent | no (cash); a tip on a non-cash tender is part of the owner-dependent tender item. |

### B5. Held-sale notes (accepted, never persisted) — Team 3 via Team 2's service

| Aspect | Fact / design |
|---|---|
| Edge local | validated `notes` max 1000 (`EdgeLocalHeldSalesController.php:41`) but `holdOrReviseSale` never writes it (sale attributes `EdgeLocalPosService.php:757-800`; only the TABLE SESSION note is stored, `:655`). `sales_orders.notes` exists (`0001_01_01_000008_create_sales_tables.php:97`). |
| Online | persists on hold/revise (`HeldSaleController.php:286,686,727`) and on Direct Pay (`SalesOrderController.php:391,848`). |
| Envelope / Cloud | no `notes` key; not projected. |
| Additive change | Edge writes `notes` on hold/revise/Direct Pay (Team 2 file); envelope top-level `notes` (nullable text) in **v1**; Cloud maps `notes` in `projectSale`. Held sales stay local until settled (no new event). |
| Tests | held note survives revise + settle → envelope → Cloud `sales_orders.notes`; replay; hash. |
| Owner-dependent | no. |

### B6. Shift close: denomination count, closing notes, CASH-SHORTAGE draft voucher — Team 4

| Aspect | Fact / design |
|---|---|
| Edge local | `closeShift` accepts `counted_cash` + `closing_notes` (`EdgeLocalShiftController.php:151-179`) → shared `ShiftService::closeShift` persists both (`shifts.closing_notes`, `0001_01_01_000004…:81`). No denominations; no voucher. `cash_count_lines` table exists locally (`:112-116`). |
| Online | denominations → `calculateCashCount` + `cash_count_lines` (`ShiftController.php:276-278,584-610`); short drawer → `CashShortageExpenseService::recordShortage` DRAFT voucher, idempotent by `voucher_no = EXP-SHORT-<date>-S<shift id>` (`ShiftController.php:308-323`; `CashShortageExpenseService.php:33-76`, draft only — no GL until finance posts it, `:16-18`). |
| Cloud today | **no shift reaches the Cloud**: the sale envelope carries a shift SNAPSHOT (`EdgeSaleEnvelopeBuilder.php:96-102`) but ingestion sets no `shift_id` (`:272-310`); the design's "mirror shift by shift_uuid" (`docs/design/OFFLINE_SYNC_ENGINE_V1.md:309-316`) is not implemented → audit R1.11 ("Edge shift totals in Cloud shift reports") stays unverified/absent. |
| Design | Local part (not a contract): denomination capture into local `cash_count_lines` via the SAME `calculateCashCount` rule (extract from the controller into a shared service — additive) + bootstrap v7 `currencies`/`currency_denominations`. Contract part: NEW event **`edge-shift-close-envelope-v1`** `{event_uuid, shift_uuid, terminal_id, opened_at, closed_at, opened_by, closed_by, opening_float, expected_cash, counted_cash, cash_variance, denominations[{currency_denomination_id, value, quantity, amount}], closing_notes, activation_epoch, config_revision, content_hash}`; outbox `createForFinanceEvent`-style insert inside the close transaction; sender route + config key `edge.sync.shifts_url` / `EDGE_SYNC_SHIFTS_URL` (new `appliance.env` entry → provisioning step on the LAB, C5); Cloud `EdgeInboundShiftCloseIngestionService` + registry table `edge_inbound_shift_ingestions` (tenant migration, Cloud only) keyed by `event_uuid`; Cloud upserts the mirror shift by `shift_uuid` (column exists, migration `2026_08_07_000004`), writes `cash_count_lines`, and — **only with owner approval** — calls `recordShortage` with the CLOUD mirror shift id (the Edge local shift id must never be the voucher key: ids collide across systems). Ordering: shift-close may arrive before its sales (order-independent by `shift_uuid`, design §10). |
| Exactly-once | registry unique `event_uuid`; voucher already idempotent by `voucher_no`; mirror upsert by `shift_uuid`. |
| Backward compat | old Cloud has no route → HTTP 404 (not an ACK) → sender `reject`/retry; old appliance never emits it. |
| Tests | Edge: close with denominations persists `cash_count_lines` + outbox row in the same transaction; Cloud: ingestion creates mirror shift + count lines; shortage → one draft voucher; replay → no second voucher; conflict hash → terminal; shift-close-before-sale. |
| Owner-dependent | **YES for the voucher and for Edge shifts appearing in Cloud shift reports** (a new finance document from offline data; sits next to "Close Branch / Daily Closing posting"). Denomination count + closing notes locally: not owner-dependent. Close Branch / Daily Closing: owner-dependent, design not started. |

### B7. Request bill / move / merge / reattach table — Team 3

| Aspect | Fact / design |
|---|---|
| Edge local | sessions `open`/`bill_requested` already exist in the lock queries (`EdgeLocalPosService.php` open/hold paths); Team 3 is adding `EdgeLocalTableOperationsService` (worktree) mirroring `RestaurantTableSessionController::billRequested/move/merge`. |
| Envelope | only the FINAL state at settlement: `table_session {session_uuid, session_no, restaurant_table_id, restaurant_waiter_id, opened_at}` (`:103-109`). |
| Cloud | does not project table/session/waiter for Edge dine-in sales (`projectSale` `:272-310`). |
| Design | **Local-only** (held sales are local until settled — board rule; charter §4). No new event. Move/merge history stays on the appliance. Optional, additive, **no envelope change**: Cloud `projectSale` maps `restaurant_table_id` and the dine-in waiter from the existing `table_session` snapshot so Cloud table/waiter reports see Edge dine-in sales (a Cloud session mirror by `session_uuid` is a larger item — not proposed). Reattach (canonical `HeldSaleController.php:1012`, tests `HeldSaleReattachTableMySqlTest` / `HeldSaleDeadSessionMySqlTest` arrive with the merge) is a local re-pointing before settlement → same rule. |
| Tests | envelope after move/merge names the target session/table; Cloud projection of table/waiter (if adopted); replay. |
| Owner-dependent | no. |

### B8. Reservation with a book customer id — Team 3

| Aspect | Fact / design |
|---|---|
| Edge local | already supported in the service: `reserve()` accepts `customer_id`, resolves `customer_uuid` + snapshots (`EdgeTableReservationService.php:44-69,146-157`; table columns `customer_id`, `customer_uuid`, name/phone, `edge/2026_08_29_000001…:36-39`); seating carries the customer onto the session and the first held sale. Gap = UI only (Online modal `reserve-customer-search`, `tenant/pos/index.blade.php:1941-1946`). |
| Contract | none new: a settled sale carries `customer.customer_uuid` (`EdgeSaleEnvelopeBuilder.php:199-219`), ingestion resolves by uuid or fails closed (`:246-263`); reservation handback projects by `customer_uuid` and fails closed on an unknown one (`EdgeReservationHandbackService.php` header). |
| Tests | reserve with book customer → seat → hold → settle → envelope `customer_uuid`; handback of an attached-customer reservation. |
| Owner-dependent | no. |

### B9. Quick-report saved settings (per user) — Team 4

| Aspect | Fact / design |
|---|---|
| Online | `pos_quick_report_settings` one row per user, JSON payload (`tenant/2026_08_27_000002…`; `PosQuickReportController.php:246,264`). |
| Edge | table exists locally, not in the bootstrap, no Edge endpoint (`EdgeQuickReportController.php:91-174`: options/view/network/email). |
| Design | **Local, not synced upstream.** Seed each branch user's Online row via bootstrap v7 (read-only baseline), let Edge save locally (same controller rules); no outbox event (not business data). A config refresh overwrites local edits made during an outage — acceptable and must be stated in the UI help, or keep a local "edited_at" and skip overwrite (Team 4 choice). |
| Tests | bootstrap import of the section; save/load per user on Edge; refresh behaviour. |
| Owner-dependent | no. |

### B10. `kot_print_intent` / `receipt_print_intent` on a Direct Pay sale — Teams 2 + 5

| Aspect | Fact / design |
|---|---|
| Online | REQUIRED on `tenant.pos.store` (`SalesOrderController.php:111-116`), validated `print|skip` (`:833-834`), stored as `sales_orders.direct_pay_print_state` (`:117-119,392`; migration `2026_08_03_000004`), part of the idempotency hash (`SaleIdempotencyService.php:57-58`). |
| Edge | not accepted by `completePaidSale` (`EdgeLocalPosService.php:108-295`); Team 5 adds `EdgeLocalPrintDirectPayService` (worktree). |
| Contract | **Local-only; never in the envelope.** Cloud ingestion creates no print job (`EdgeNoDuplicatePrintAfterSyncMySqlTest`; P5B `DUPLICATE_PRINT_FROM_CLOUD_SYNC=0`, `edge-p5b-release-operations.md:162`). Edge stores the state in its local `direct_pay_print_state` and must add both intents to `effectiveIntent` (mirror of Online's hash). |
| Tests | envelope does NOT contain print fields (assert absent); retry with a different intent → conflict, not a second sale; Cloud ingestion still creates zero print jobs. |
| Owner-dependent | no. |

### B-F. F1/F2/F3 semantics to preserve

F1 `edge-return-envelope-v1` (`EdgeReturnEnvelopeBuilder.php:19`), F2 `edge-supplier-payment-envelope-v1` / `edge-supplier-ap-journal-envelope-v1`
(`EdgeSupplierFinanceEnvelopeBuilder.php:22-23`), F3 `edge-purchase-return-envelope-v1` (`EdgePurchaseReturnEnvelopeBuilder.php:19`) are
**not changed** by any item above. Two proofs are required because sale-shape changes feed F1: (1) a return of a line whose sale
consumed modifier stock (does Cloud restock modifier components on a return? — UNKNOWN; read `SalesReturnService` before B1 ships);
(2) a return of a tipped sale (refund amount unchanged vs Online). If either differs from Online, stop and report — no F1 envelope change
without owner review.

### B-summary — verdicts

| Field | Envelope / contract verdict | Cloud change | Migration | Owner-dependent |
|---|---|---|---|---|
| Modifiers — ids/names/deltas | ADDITIVE (v1, normalized shape) | none | none | no |
| Modifiers — linked-product stock | **NON-ADDITIVE → v2** (only sales with consume_stock modifiers) | ingestion posts modifier FEFO (shared extracted method) | none | no |
| Modifier config on the appliance | bootstrap **v7** (`product_modifier_group`, global groups, ingredient-linked modifiers/products) | bootstrap export | none | no |
| Kitchen note | ADDITIVE (v1) — blocked: no Online writer found | projection | none | no |
| Line discounts | ADDITIVE (Edge guard relax; envelope already carries) | none | none | no |
| Tips | ADDITIVE (Edge guard removal; envelope + Cloud already carry) | none | none | no (cash) |
| Held-sale notes | ADDITIVE (v1 top-level `notes`) | projection | none | no |
| Shift denominations + closing notes | local (bootstrap v7 denominations) | — | none | no |
| Shift close → Cloud mirror + shortage voucher | **NEW EVENT** `edge-shift-close-envelope-v1` + new URL key | new ingestion + registry | Cloud tenant `edge_inbound_shift_ingestions` | **YES** (voucher / Cloud shift reports) |
| Request bill / move / merge / reattach | local-only; optional Cloud table/waiter projection from existing keys | optional projection | none | no |
| Reservation with book customer | none (already by `customer_uuid`) | none | none | no |
| Quick-report saved settings | local; seeded by bootstrap v7 | bootstrap export | none | no |
| Print intents | local-only, never in the envelope | none | none | no |
| Offline bank/cheque/other tenders & refunds; Close Branch / Daily Closing posting; journal reverse; PR drafts | design only, not started | — | — | **YES** |

---

## PART C — Release plan for 0.7.0-edge (for owner approval BEFORE the LAB is touched)

### C0. Wave-2 status (25 Sep 2026) — what landed, what the release is cut from

```
RECONCILED_TO        b529c95 (merge 1baa34c, 0 conflicts)
W6_CODE_COMMIT       06bb53b  (contract code + tests; see docs/status/edge-w6-team6-report.md)
RELEASE_CANDIDATE    the W6 docs commit on top of 06bb53b (the branch tip handed back)  = tip of feat/edge-config-refresh-v1 after the W6 docs commit (app/config/database/resources/routes
                     byte-identical to 06bb53b; docs are excluded from the artifact). NOT pushed yet — the coordinator pushes, then the
                     C1 build runs from a `git archive` export of exactly this commit. Any later commit re-opens gates C10.2–C10.5.
ENVELOPES            edge-sale-envelope-v1 (unchanged shape for plain sales; + optional `notes`; tips / line-only discounts now accepted)
                     edge-sale-envelope-v2 (ONLY sales with a stock-consuming modifier; Cloud posts modifier FEFO via SalesService::consumeLineModifiers)
BOOTSTRAP            edge-bootstrap-v7 (config schema stays edge-config-v1; sync_protocol placeholder unchanged 'edge-sync-v0')
OUTBOX               SCHEMA_UNSUPPORTED = retryable, bounded backoff 60 s·2^(n−1) capped 900 s (edge.sync.schema_retry_*)
```

### C5a. Migration list for 0.7.0-edge (`git diff --name-only 623f887..the W6 docs commit on top of 06bb53b (the branch tip handed back) -- database/migrations`)

| Path | Where it runs | Origin | Nature |
|---|---|---|---|
| `database/migrations/tenant/2026_09_17_000001_add_customer_email_switch_to_catering_settings.php` | appliance DB at update (tenant path) **and** LAB Cloud tenant DB `pos_lab_tenant_edge` (`tenants:migrate`, owner-gated) | canonical reconcile | additive boolean, `hasColumn` guard |
| `database/migrations/edge/2026_09_25_000001_add_tenant_business_name_to_edge_local_meta.php` | appliance DB only (edge path, after the tenant path) | W6 bootstrap v7 (Team 4 C-4) | additive nullable `edge_local_meta.tenant_business_name`, `hasColumn` guard |

Cloud master: **none**. Cloud tenant (besides the canonical catering column): **none** (the v2 ingestion reuses `edge_inbound_sale_ingestions`;
`envelope_schema_version` is string(64)). No down-migration is ever run (forward-only upgrader).

### C6a. LAB pre-update steps (add to C6; all BEFORE the update, owner-approved, nothing done by W6)

The LAB Cloud serves THIS worktree and has been DOWN since the 22 Sep reboot — W6 did not start it. When the owner restarts it, it runs whatever
commit the worktree is at; from `06bb53b` on that is **bootstrap v7**.

1. **Bootstrap v7 sequencing warning.** A 0.6.0 appliance cannot import a v7 export (`SCHEMA_UNSUPPORTED`) and the Cloud classifies it
   `software_update_required`. The moment the LAB Cloud is started on a v7 commit, the installed 0.6.0 appliance's config refresh is refused and its
   CONFIG_COMPATIBLE readiness can leave STANDBY_READY. Therefore EITHER start the LAB Cloud on v7 only immediately before the approved update window
   (C8) OR serve the LAB Cloud from an export of the last v6 commit (`30105df`) until the update, then switch to the release commit (owner choice,
   C6.6). After the update the first refresh is a NEW config revision (the watermark carries `bootstrap_schema=edge-bootstrap-v7`) — applied as a
   normal revision, not a re-bootstrap. Verify after update: `product_modifier_group`, global modifier groups, currencies/denominations, non-cash
   (display-only) payment methods and `edge_local_meta.tenant_business_name` imported.
2. **LAB cashier permissions (NEW, from the integration board).** Every POS action on 0.7.0 is gated by the Online route permission, incl.
   `tenant.pos.index` for the page. The LAB Cloud seed grants LAB2C5D only `tenant.pos.store / view / hold / recall`. On the LAB Cloud tenant, grant the
   LAB cashier (and the LAB manager where applicable) the Online cashier set — exactly `onlinePosParityPermissions()` in
   `tests/MySql/Support/EdgeLocalRuntimeFixture.php` = `tenant.pos.index`, `tenant.pos.store`, `tenant.held-sales.store`, `tenant.held-sales.cancel`,
   `tenant.sales-orders.split-bill.store`, `tenant.restaurant.table-sessions.open`, `tenant.restaurant.table-sessions.close`, `tenant.shifts.store`,
   `tenant.shifts.close`, `tenant.api.manager-approvals.verify` (+ `tenant.pos.void-kot-item` for anyone approving/voiding sent food) — BEFORE the update, then let a config refresh carry it (it rides the `users[].permissions` export). Check
   the same for every real cashier role before any production pairing.
3. **Cloud first, appliance second.** Upgrade/start the LAB Cloud on the release commit BEFORE the appliance update. If an updated appliance ever meets
   an older Cloud, v2 rows are deferred (backoff, not failed_permanent) and apply once the Cloud is upgraded — but do not rely on it for the LAB run.
4. LAB Cloud tenant DB: run the canonical catering migration (`tenants:migrate`) on `pos_lab_tenant_edge` — owner-gated DB mutation.
5. Unchanged from C6: STANDBY_READY + authority standby, outbox 0/0/0 (a deferred row would show as `leased`), no open shift / held check / open table,
   fresh verified backup, record version/manifest/pointer/Cloud head, snapshot LAB Cloud DBs into the evidence folder.

### C10a. Gate status at `06bb53b` (wave 2)

```
Feature (SQLite)  vendor/bin/phpunit tests/Feature/Edge                               OK 137 tests / 32,883 assertions
                  (route census, artifact boundary incl. Catering job/support absent, dependency closure, Blade gate,
                   compatibility v6/v7, build info v7, log hygiene)
Unit              CancellationPolicyRegressionTest + TableBillDecimalRegressionTest  OK 3 / 12
MySQL targeted    EdgeW6ContractEnvelopeHttp (5) + EdgeW6ContractIngestion (6) + EdgeBootstrapV7 (3) + EdgeComboVoidReconcileHttp (2)
                  + Menu/Payment/TablesWorkspace/ControlCensus (EDGE_NODE_BIN set; node --check of the composed script)  all OK
MySQL regression  --filter 'Edge|ManagerApprovalComboVoid|CancelFreesTable|ReceiptProformaVsFinal|BillPreviewPrintTarget|
                  ComboModifierKotIntegrity|SteakSideModifier|RecipeConsumptionReference|CloudManagerApproval'
                  589 tests / 6,246 assertions, 1 skipped, 1 error = EdgeBackupRecoveryAuthorityMySqlTest::test_a_dead_appliance_is_replaced…
                  -> PRE-EXISTING test-order isolation defect, NOT W6: green alone (4/4); fails only when CloudManagerApprovalMySqlTest or
                  DeliveryChargeMySqlTest ran before it in the same process (both files, EdgeRestoreService and the harness are
                  unchanged since 30105df). Side effect found: that test leaves a master tenant_databases row ('edgerecov') for the
                  shared test tenant DB, which makes the canonical BillPreviewPrintTargetMySqlTest fail with a 1062 if it runs later
                  against the same DB (row removed by hand on _edgewt_t6). Coordinator: harness fix, not a product defect.
Dry artifact      php artisan edge:build-package <scratch> --no-sign --allow-dirty --vendor-junction=vendor  (dev, UNSIGNED, git_commit
                  06bb53b, source_dirty false): boundary_audit ok, forbidden_hits [], cloud_only_present [], edge_runtime_missing [],
                  marker branch_server; edge:audit-package -> PACKAGE OK (2,068 files); app/Jobs/Catering, app/Support/Catering,
                  app/Services/Catering, EdgeInboundSaleIngestionService physically absent; the new edge migration is present.
                  Scratch package deleted (junction unlinked first). No release build, no signing, custody keystore untouched.
Not run here      full `phpunit --testsuite Feature,Unit` and the full MySQL suite (C10.2 — coordinator's release gate), release-mode
                  EdgeCleanMachineInstallMySqlTest with EDGE_PROOF_VENDOR_FROM (needs the no-dev closure of the release commit).
```


### Original wave-1 release plan (C1–C10 still apply; C0/C5a/C6a/C10a above supersede where they differ)

### C1. Release commit
From the coordinator's integration commit on `feat/edge-config-refresh-v1` AFTER: the reconcile (A3), Teams 1–5 integrated, W6 wave-2
contract code (Cloud + Edge halves together), census re-baseline, and BOTH suites green (Feature/Unit SQLite + full MySQL). The commit
must be clean and pushed; nothing built from a dirty tree (`EdgeBuildPackageCommand.php:43-45` refuses a dirty RELEASE build).

### C2. Build + sign (P5B procedure, `edge-p5b-release-operations.md:104-128`)
1. `git archive <release-commit>` export (not the worktree — P5B found CRLF worktree copies, lesson (d)).
2. `composer install --no-dev` in the export → vendor closure; build with `--vendor-from=<closure>` (lock must equal).
3. `EDGE_APP_VERSION=0.7.0-edge php artisan edge:build-package <dest> --php-runtime=<php-8.3.16> --gateway=<nginx-1.22.0>
   --signing-keystore=<custody keystore> --signing-passphrase-file=<custody passphrase file>` from the clean export (version source:
   `config/edge.php:26`; artifact_version = version + commit, `EdgeBuildPackageCommand.php:132`). Custody (public facts only):
   key id `a2eaf8a3d3e66a48`, Ed25519 public key registered on appliances as `EDGE_UPDATE_PUBLIC_KEY`; keystore + passphrase live under the
   release custody directory with a user-only ACL (`edge-p5b-release-operations.md:38-51`). The passphrase is read from a FILE, never argv,
   never printed; a release refuses a plaintext key file (`:31-32`).
4. `php artisan edge:audit-package <dest>` → `PACKAGE OK`, `boundary_audit ok`, `forbidden_hits []`, `cloud_only_present []`,
   `edge_runtime_missing []`, marker `branch_server` (`:124`).
5. Byte-level source proof of `app/` against the export (0 mismatches).
6. Record `RELEASE_COMMIT`, `RELEASE_HASH` (package_hash), `manifest_hash`, file count, vendor closure lock sha, signing key id.

### C3. Restricted-artifact audit (extra checks for this release)
- `app/Jobs/Catering`, `app/Support/Catering` absent (A3.2); `app/Services/Edge/EdgeInbound*`, `EdgeFinancePostingVerifier`, the new
  `EdgeInboundShiftCloseIngestionService` (if built) and its model/controller are on the exclude list (same pattern as `config/edge.php:383-420`)
  and physically absent.
- `EdgeApplianceDependencyClosureTest` green; no keystore / passphrase / `.pem` / `.key` / `.env` / tests / `.git` / dev vendor.

### C4. Signed-update verification (before the LAB)
On the dev Edge instance / clean-machine proof: signature verifies with the custody public key; a foreign key refuses
(`UPDATE_SIGNATURE_INVALID`); a tampered payload refuses (`UPDATE_ARTIFACT_TAMPERED`); `UPDATE_DOWNGRADE_REFUSED` for 0.6.0 over 0.7.0
(`EdgeUpdateVerifier.php:29-92`). `EdgeUpdaterMySqlTest`, `EdgeCleanMachineInstallMySqlTest` in release mode
(`EDGE_PROOF_VENDOR_FROM=<closure>`) green with an A(0.6.0-shaped)→B(0.7.0) update.

### C5. Migrations / schema on the appliance DB
- Tenant path: `2026_09_17_000001_add_customer_email_switch_to_catering_settings` (from the reconcile). Edge path: `2026_09_25_000001_add_tenant_business_name_to_edge_local_meta` (W6 wave 2, see C5a);
  any `database/migrations/edge/*` a team adds must be listed here by the coordinator at release time
  (`git diff --name-only 623f887..<release> -- database/migrations`) — at d82612f the list is exactly the one tenant migration above.
- Bootstrap `edge-bootstrap-v7` (B0.4) is NOT a DB migration but a config-contract jump: after the update the appliance must pull a fresh v7
  snapshot/refresh (verify the importer path for "same device, newer schema" — UNKNOWN whether refresh or a re-bootstrap is required;
  dry-run on the dev instance first).
- New `appliance.env` key `EDGE_SYNC_SHIFTS_URL` only if B6 is approved and built (the file is the appliance secrets file → written by an
  operator step, never by printing its contents).
- Cloud (LAB) side: `tenants:migrate` for the Cloud-only registry table (if B6) and the catering_settings column on `pos_lab_tenant_edge`.
- Rollback semantics (`EdgeUpdateInstaller.php:11-26,96-107`): failure before the pointer switch → previous runtime stays; failure at the
  forward schema upgrade → pointer reverted (`reverted_runtime`) or `restore_required` from the verified pre-update backup — never a
  down-migration. After a SUCCESSFUL update there is no rollback command in `scripts/edge/` — a manual rollback = switch
  `runtime\current` back to `0.6.0-edge` (the versions dir is kept) and, if 0.6.0 refuses the newer schema/bootstrap, restore the
  pre-update backup. Whether 0.6.0 accepts a DB carrying the additive column + a v7 config is UNKNOWN → dry-run on the dev instance before
  approval.

### C6. LAB state checks immediately before the update (read-only)
1. `edge:local:health --json` → `STANDBY_READY`, authority `standby` (not `local_active` — `Update-EdgeAppliance.ps1:47-50` refuses it).
2. `edge:local:sync-status --json` → outbox pending 0 / leased 0 / failed_permanent 0 (last observed 0/0/0, `edge-p5c-home-lab.md:242`).
3. No open shift, held check or open table (the handback gate lists them, `EdgeHandbackOrchestrator.php:118-127`).
4. `edge:local:backup --json` → a fresh verified encrypted backup (the installer takes another pre-update backup itself, `EdgeUpdateInstaller.php:67-74`).
5. Record installed version (`0.6.0-edge`), manifest hash, runtime pointer, and the LAB Cloud head.
6. **Owner decision — LAB Cloud code:** keep serving the live worktree (current practice; it will run the release commit only if the worktree
   is at it) or serve it from an export of the release commit for the acceptance window.
7. Snapshot the LAB Cloud DBs (`pos_lab_master_edge`, `pos_lab_tenant_edge`) into the LAB evidence folder (not the repo).

### C7. Administrator-needing steps
Expected: **none**. The LAB install is user-owned under `C:\Users\Dell\BingooEdgeLab` (`edge-p5c-home-lab.md:59`), workers are hidden user
processes, not Scheduled Tasks (`:63`), and the update stages only the `app` payload (`Update-EdgeAppliance.ps1:64`) — the InstallRoot
`gateway\nginx.exe` path (which the auto-created firewall rules reference, `edge-p5c-home-lab.md:160-169`) is unchanged. If the dry-run
shows otherwise, STOP and ask.

### C8. Exact update procedure (LAB, after owner approval)
1. C6 checks pass; copy the verified package to the LAB (package dir under the release custody tree).
2. Stop the LAB workers (the P5C hidden-process set: gateway, web 1–2, print, sync sender, authority, backup) — `Start-EdgeLabWorkers.ps1`
   counterpart; ask before stopping.
3. `.\Update-EdgeAppliance.ps1 -InstallRoot C:\Users\Dell\BingooEdgeLab\install\BingooEdge -PackageRoot <pkg> -NoServices`
   (verifies hashes → authority guard → `edge:local:update` = signature/tamper/version/schema/target → pre-update backup → stage → switch →
   forward schema upgrade → service-plan re-render → health; `Update-EdgeAppliance.ps1:1-94`).
4. Start the LAB workers from the re-rendered plan; `edge:local:health` → `STANDBY_READY`, `edge_app_version 0.7.0-edge`, commit = release.
5. Config: pull/refresh to `edge-bootstrap-v7`; verify sections (product_modifier_group, denominations, quick-report settings) imported.
6. Heartbeat acks resume; outbox still 0/0/0; backup worker produces a new backup.
**Rollback** (if 4–6 fail): stop workers → switch `runtime\current` to `0.6.0-edge` → start → health; if 0.6.0 refuses the DB/config,
`Restore-EdgeAppliance.ps1` from the pre-update backup (Cloud-escrowed recovery key; `edge-p5b-release-operations.md:76-77`).

### C9. Second-laptop acceptance checklist (after the update; no live tenant; no P6; no Local Mode)
- [ ] Cashier laptop (LAB CA trusted, `edge-p5c-home-lab.md:218`) opens `https://DESKTOP-0024EPM.local:8443/edge/local/login`, logs in, POS renders.
- [ ] Paired screenshots Online (LAB Cloud tenant `edgehomelab`) vs Edge at the SAME viewport (1366×768 + tablet) for every in-scope screen/dialog.
- [ ] `node tools/edge-browser-proof/edge-pos-proof.mjs --base-url https://DESKTOP-0024EPM.local:8443 --allow-mutations` on LAB data only
      (coordinator runs it; evidence under `C:\Users\Dell\BingooEdgeLab\evidence\`).
- [ ] Warm-standby workflows only (Cloud serving; LAB branch data): sale with a modifier (price delta + consume-stock) → envelope v2 → LAB
      Cloud ingestion applied, modifier ledger present; line discount + tip sale → Cloud GL (4140) + cash-bank verified; held note → Cloud
      `sales_orders.notes`; one-line combo void with manager approval accepted (R26); table bill preview shows rounds/previously-paid and
      prints the held ids (R14); FakePrinter receives KOT/receipt, zero duplicate prints after sync.
- [ ] Shift close with denominations (+ voucher only if the owner approved B6).
- [ ] Outbox drains to 0/0/0; no `failed_permanent`; replay of an acknowledged row returns `already_applied` with zero effects.
- [ ] Health `STANDBY_READY` throughout; heartbeat sequence continuous; no restart beyond the planned worker stop/start.

### C10. Release gate list
1. Reconcile merged; `merge-tree` clean; census re-baselined.
2. Feature/Unit (SQLite) + full MySQL suite green on the release commit.
3. Artifact boundary gates + dependency closure green; `edge:audit-package` PACKAGE OK; Catering job/support absent.
4. Keystore-signed package; foreign-key / tamper / downgrade refusals proven; release-mode clean-machine proof green.
5. Contract tests: envelope v1 unchanged for plain sales; v2 only for consume-stock modifiers; Cloud accepts v1+v2; replay + race; F1 return
   proofs for modifier and tipped sales.
6. Bootstrap v7 landed together with (not before) the LAB update; dev-instance dry-run of update AND manual rollback.
7. LAB pre-checks C6 recorded; no Administrator step; owner approval recorded for: the update itself, the LAB Cloud code choice (C6.6),
   LAB Cloud DB migrations, and every OWNER-DEPENDENT item that is in the release (B6 voucher).
8. Second-laptop acceptance C9 with evidence.

---

## Stop state

```
CODE_CHANGED=no   GIT_WRITES=none   WORKTREE_CREATED=no   MERGE_RUN=no   LAB_TOUCHED=no   SECRETS_READ=no
RECONCILE_RISK=LOW (0 conflicts; 1 disjoint overlap; 2 executed files + 1 additive migration; 2 exclude-list additions)
CONTRACT: additive v1 = modifiers shape, kitchen note (blocked: no Online writer), line discounts, tips, notes; v2 = consume-stock modifiers;
          bootstrap v7 = product_modifier_group, global groups, ingredient-linked modifiers, denominations, quick-report settings;
          new event = edge-shift-close-envelope-v1 (owner-dependent voucher); local-only = table ops, print intents, quick-report edits
OWNER_DEPENDENT: shortage voucher / Edge shifts in Cloud reports; non-cash tenders & refunds; Close Branch / Daily Closing; journal reverse; PR drafts
```
