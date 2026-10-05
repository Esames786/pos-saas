# Phase 3 Stage B — old Edge POS tree deleted; gaps A / B closed; Edge entry points; Direct Pay intents required

Date: 6 Oct 2026. Branch `feat/edge-config-refresh-v1` (HEAD `40ce17a` + this uncommitted team work). Team B2.
Owner directive (Phase 3, Stage B): *"prove no runtime/include/reference points at the old Edge POS; delete the obsolete Edge POS
Blade/JS/CSS tree; remove old-page-only controller/view-model code that has zero remaining callers. Keep: Edge backend
controllers/services; Edge local auth; health/status page; finance screens still intentionally separate; local runtime/API services;
authority/sync/printing backend."* Census: *"Only after the replacement gate is green may the old EdgeCashierControlCensusHttpMySqlTest
and old fixture be deleted"* — it was green at `7c40923`. *"Do not preserve a bug merely because both sides currently show it."*

Safety: source + isolated automated tests only (`_edgewt_t13` databases). No commit, no LAB, no production, no dev-server database.
The dev Edge instances on 127.0.0.1:8095 / :8096 serve this worktree live and kept answering throughout (`/edge/local/health` 200;
`/edge/local/pos` and `/edge/local/pos/shared` 302 → `/edge/local/login` unauthenticated). `tools/edge-browser-proof/shared-pos-workflows.mjs`,
its README and `docs/status/edge-w-g2-workflow-proof-report.md` were not touched (another stream owns them).

```
OLD_EDGE_POS_FILES_DELETED=25 — resources/views/edge/pos/** (22: index + partials/{banners,cart,grid,header,shell,styles} + js/{actions,boot,cart,
                               catalog,commercial,context,core,held,payment,printing,reports,returns,shift,sync,tables}), tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php,
                               tests/Fixtures/edge/online-pos-control-census.json, tools/edge-browser-proof/edge-pos-proof.mjs (read the deleted fixture)
OLD_EDGE_POS_REFERENCES=0 — no view()/@include/@extends of edge.pos.* under app/ or routes/ (static gate), no edge.local.* route action renders one (render gate),
                           no Blade under resources/views names 'edge.pos.' (static gate), EdgeLocalPosController::screen() gone (method_exists asserted false);
                           the remaining mentions are comments + the gate assertions themselves (grep evidence §1.3)
CONTROL_CENSUS_REPLACEMENT=frozen id fixture — tests/Fixtures/edge/shared-pos-required-ids.json: the 276 ids by state (present 221 / equivalent 38 /
                           online_required 17), a plain id list, NOT a sha1 pin of any view (asserted: no online_view_sha1 key); read by the render gate's
                           test_b (every id on BOTH renders; ≥17 online_required rows) — the assertion is kept verbatim
SHARED_ALIAS=301 — GET /edge/local/pos/shared → 301 /edge/local/pos, query string preserved (EdgeLocalPosController::sharedAlias); the name
                   edge.local.pos.shared stays on the allowlist + URI census as a redirect; /edge/local/pos/shared/{shifts,sales-returns}… untouched
ARTIFACT_MANIFEST=EdgeArtifactTest::test_real_repo_plan_is_secret_free now requires tenant/pos/index.blade.php, layouts/pos.blade.php, every
                  tenant/pos/partials/* + tenant/pos/js/* (globbed, ≥6), tenant/shifts/{open,close,index,show}, tenant/sales-returns/{create,index,show},
                  tenant/sales-orders/split-bill, edge/auth/login, edge/health — and asserts the old page is NOT in the plan; the build command's
                  include/exclude lists (config/edge.php 'artifact') already shipped all of them (`resources` is included whole; no exclude hits
                  tenant/pos, tenant/shifts, tenant/sales-returns, sales-orders/split-bill) — no config change needed
STRICT_SWITCH=only mode — both gate halves' strictCutover() return true; EDGE_POS_CUTOVER_STRICT=1 / =0 give identical results (3 tests / 818 assertions)
FEATURE_GATES=GREEN — Feature/Edge 168 tests / 36,906 assertions; Feature/Pos 26 / 456 (EDGE_NODE_BIN set)
MYSQL_FILTER=GREEN — 248 tests / 5,314 assertions, 0 skipped, 0 failures on _edgewt_t13 (9 min 31 s; the filter above + EdgeSupplierFinance|EdgePurchaseReturn|EdgeW6ContractEnvelope
             because this stage edits them); first run surfaced 5 failures (old 2-char floor still asserted in EdgeCashierPaymentHttpMySqlTest; the D-08 null-pair
             assertion on a sale that now carries its state; a one-character query matching random customer codes; Symfony re-sorting the alias's query string)
             — all fixed (raw QUERY_STRING in sharedAlias; tests follow Online) and green on re-run
```

## 1. Task 1 — Stage B deletion

### 1.1 Deleted (25 files)

| Path | Why it could go |
|---|---|
| `resources/views/edge/pos/index.blade.php` + `partials/` (6) + `js/` (15) | 0 routes / 0 `view()` since Stage A (`edge.local.pos.screen` → `sharedScreen`); the only `view('edge.pos.…')` was the Stage A delegate, itself removed below |
| `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php` | skipped since Stage A; replaced by the render + static gate (`docs/status/edge-phase3-census-replacement.md` §2), green at `7c40923` |
| `tests/Fixtures/edge/online-pos-control-census.json` | its only remaining reader (render gate category b) now reads the frozen id fixture below |
| `tools/edge-browser-proof/edge-pos-proof.mjs` | the W7 DOM-census tool of the OLD Edge page: it parsed the deleted census fixture (`census.groups`) and drove the old page's selectors; superseded (architecture doc §"what goes away": *"the harness becomes the paired-screenshot runner"* — `pos-reference-shots.mjs`, `geometry-compare.mjs`, `shared-pos-workflows.mjs`). Its `package.json` script entry is dropped too. **Left for the owning stream:** `tools/edge-browser-proof/README.md:12` still shows its usage line. |

### 1.2 Source changes

| File | Change |
|---|---|
| `app/Http/Controllers/Edge/EdgeLocalPosController.php` | `screen()` (the Stage A delegate, 0 callers — grep: no `EdgeLocalPosController::class, 'screen'` in routes, no `@screen` action, no `->screen(`) **removed**. New `sharedAlias()` = 301 to `EdgePosRuntimeFactory::SHARED_PAGE` + the request's query string. `sharedScreen()` docblock updated. `menuPayload()` docblock notes it is NOT old-page-only (`sharedMenu()` builds the Online tile payload from it); `boardFloors()` / `recipeStockLookup()` / `safeCollection()` / `previewBill()` kept (shared page / restaurant controller / preview route callers — §4 of the Stage A report). |
| `routes/edge_runtime.php` | `GET /shared` → `sharedAlias` (name `shared` kept); comments. |
| `config/edge.php`, `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` | allowlist / URI census rows unchanged in content (the alias stays, as a redirect); comments. |
| `tests/Fixtures/edge/shared-pos-required-ids.json` (NEW) | `{_doc, online_view, ids: {present[], equivalent[], online_required[]}}` — 276 ids extracted from the census by state; no hash. |
| `tests/MySql/EdgeSharedPosRegressionGateMySqlTest.php` | `CENSUS_FIXTURE` → the new fixture; `censusRows()` reads `ids[state][]` and asserts no `online_view_sha1`; `renderEdge()` GETs `/edge/local/pos`; `test_d_…` renamed `test_d_no_old_edge_page_renders_anywhere_and_the_alias_redirects_to_the_canonical_page`: folder absent, no route action renders `edge.pos.*`, `edge.local.pos.screen` → `@sharedScreen`, `/edge/local/pos` = `tenant.pos.index`, `/edge/local/pos/shared` 301 (+ query preserved); `strictCutover()` = `true` (env no-op); `OLD_EDGE_ONLY_IDS` docblock (frozen before deletion — the only reference now). Seed: a combo FILED to a product-less "Deals" category (gap A — the skeleton equality of test_a now covers the pill set on the same dataset, see §2). |
| `tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php` | strict is the only mode (`strictCutover()` = `true`); Phase 2 branch and the "re-derive OLD_EDGE_ONLY_IDS while the folder exists" branch removed; `test_no_old_edge_page_blade_exists_or_is_rendered_anywhere`: folder absent, 0 refs under app/+routes/, no Blade under resources/views names `'edge.pos.`, `edge.local.pos.screen` → `@sharedScreen`, `method_exists(EdgeLocalPosController, 'screen')` false. |
| `tests/Feature/Edge/EdgeBladeCompileGateTest.php` | the cashier-page entry + its `@include('edge.pos.js.*')` resolver dropped (renamed `test_the_edge_operator_page_scripts_parse_as_javascript`, asserts the old page file does not exist); the `views/edge/**` glob keeps the finance pages + login + health; the shared view's scripts are checked by `SharedPosViewRenderTest`. |
| `tests/Feature/Edge/EdgeArtifactTest.php` | manifest: see `ARTIFACT_MANIFEST` above. |
| `tests/MySql/EdgeSharedPosViewMySqlTest.php`, `EdgeSharedPosGapFixesMySqlTest.php`, `EdgeSharedPosRecipeAvailabilityMySqlTest.php` | every `GET /edge/local/pos/shared[?…]` → `/edge/local/pos[?…]` (the `/shared/shifts`, `/shared/sales-returns` screens untouched); new `test_the_phase_2_alias_is_a_permanent_redirect_to_the_canonical_page`. |
| `tools/edge-browser-proof/geometry-compare.mjs`, `pos-reference-shots.mjs` | default Edge path `/edge/local/pos` (a browser follows the 301 anyway); comments. `package.json`: the `proof` script (deleted tool) removed. |

### 1.3 Grep evidence (after the change)

- `grep -rn "edge\.pos\.\|views/edge/pos\|edge/pos/" app routes config resources tests tools` → only comments naming the deletion and the two gate
  assertions' own message strings; `grep -rn "EdgeLocalPosController::class, 'screen'\|@screen\|->screen("` → only the static gate's
  `method_exists(…, 'screen')` false-assertion. `grep -rn "EdgeCashierControlCensus\|online-pos-control-census\|edge-pos-proof"` → the render
  gate's docblock (historical) and `tools/edge-browser-proof/README.md:12` (not ours).
- `ls resources/views/edge` → `auth/`, `finance/`, `health.blade.php` (kept, per the directive).

## 2. Task 2 — Gap A: bootstrap `combos` carries `category_id`

`app/Services/Edge/EdgeBootstrapService.php` `buildSections()` combos column list: `['id','branch_id','category_id','code','name','price',
'sort_order','status','description']`. One list serves the bootstrap AND the config refresh (`refreshPackage()` → `buildSections()`); the
watermark (`$add('combos','branch_id')` = `max(updated_at)|count`) already moves when a combo is re-filed. Importer (`insertRows`, whole row)
and applier (`upsertSection` → `mutable($row)`, whole row) pass the column through unchanged; `categories` exports every active row of the
shared/branch tree, so the FK is coherent. Bootstrap schema stays `edge-bootstrap-v8` (unreleased cycle; the applier accepted the rows).

Tests: `EdgeBootstrapV7MySqlTest` seeds a deal filed to a product-less "Deals" category — export carries `[id, category_id]` and the category
row; import persists `combos.category_id` on the appliance. `EdgeConfigRefreshMySqlTest::packageV2` re-files the Burger Meal → the applied
refresh carries it. Pill parity: `EdgeSharedPosViewMySqlTest::test_a_categorised_combo_yields_the_online_pill_set_and_no_legacy_deals_pill`
(before: legacy `__deals__` pill, no Deals pill; after: `hasUncategorizedCombos` false, `pillCategoryIds` = [Karahi, Deals] = the Online rule
re-derived verbatim from the same page data, the empty shelf never a pill, the rendered strip asserted), and the regression gate's seed now
carries a categorised combo so test_a's skeleton equality compares the pill set of both runtimes on the same dataset.

## 3. Task 3 — Gap B: customer lookup lists on an empty query

`EdgeLocalPosController::customers()`: the `mb_strlen($q) < 2 → []` floor is gone; `q=''` (or no `q`) lists the first 20 ACTIVE customers ordered by
name, a one-character query searches, `?id=` still pins one; limit 20 + name order kept (Online `Ajax\CustomerLookupController`). Test:
`EdgeSharedPosContractMySqlTest::test_403_shape_…_and_customer_search` (21 active + 1 inactive seeded: 20 rows, name order, inactive never offered,
`q=Z` → Mr Zafar, `?id=`).

## 4. Task 4 — Gap C (owner call; behaviour unchanged)

Facts: on a STANDBY appliance `GET /edge/local/pos/recent-sales` (`EdgeLocalOrderLifecycleService::recentSales`, local `sales_orders`, `status != held`,
newest 50) lists the `cloud_mirror` rows written by `EdgeReturnableSaleCacheService::insertShadowSale()`. The Cloud projection
(`EdgeReturnableSaleProjectionService`) sends only `RETURNABLE_STATUSES = ['paid','partially_returned']` and `restaurant_waiter_id`, not
`restaurant_table_id`, so the mirrored list has no cancelled sales and no "Table G6 ·" label, while Online's `POSController::recentSales` shows
every non-held sale with `restaurantTable.table_no`. The mirror exists for RETURNS (what may be refunded on the till), not as an order history.

Options:

1. **Mirror the table and Online's status rule** — add `restaurant_table_id` (tables are bootstrapped, so the local row exists; guard like the
   waiter: NULL when the id is unknown locally) and widen the projection to Online's recent-list rule (every non-held sale) **while keeping
   `RETURNABLE_STATUSES` as the rule for what the return flow may touch** (two concerns: list vs returnable). Cost: a projection + cache
   schema touch (envelope keys, cache upsert), more mirrored rows (cancelled sales), a config/sync contract note; the pixel pair
   `06-recent-orders` then matches on the same dataset. Risk: a cancelled mirrored row must never be offered to the return flow — needs a
   status filter in `EdgeLocalReturnService` + a test.
2. **Mirror `restaurant_table_id` only** — the table label appears (the cheap half); cancelled Online sales stay absent on the standby
   (a standby shows "what this branch can act on", not the Cloud's audit history). Smallest change; the recent-orders list still differs
   by the cancelled rows.
3. **Leave as is** — document that a STANDBY appliance's Recent Orders = the returnable mirror (no cancelled sales, no table label) and that
   LOCAL-mode history (sales taken on the appliance) is complete. No code; the 06 pixel pair stays a known, explained DATA difference.

Recommendation for the owner: option 1 if "Recent Orders" on a standby is meant to read like Online's; option 3 if the standby is a return-only
window into Cloud history. Not changed here.

## 5. Task 5 — Edge entry points (Stage A gap §5.1)

A runtime-driven **Branch Server menu** behind the EXISTING `#pos-sidebar-toggle` (the control Online uses to open its sidebar), rendered by
`resources/views/tenant/pos/partials/pos-chrome-edge.blade.php` as a Bootstrap 5 offcanvas (`#pos-edge-menu`, `position:fixed`, hidden until
opened → zero geometry in the flow; opened it overlays with a backdrop, never reflows the POS). Entries: **Health / status** (`routes.status`),
**Supplier finance** (`routes.supplierFinancePage` — `tenant.suppliers.ledger` OR `tenant.supplier-payments.store`, the screen's `requireAny`),
**General journal** (`routes.financeJournalPage` — `tenant.finance.manual-journals.store`), **Purchase returns** (`routes.purchaseReturnsPage` —
`tenant.purchase-returns.store` OR `.post`, the screen's `requireView`), **Log out** (a submit button for the existing CSRF form
`#pos-edge-logout-form`). The data island `#pos-edge-chrome-data` carries the same routes + a `menu[]` list.

- `app/Support/Pos/PosRuntime.php` `ROUTE_KEYS` += `supplierFinancePage`, `financeJournalPage`, `purchaseReturnsPage`;
  `CloudPosRuntimeFactory` defines them `null` (the Cloud sidebar carries its own menu); `EdgePosRuntimeFactory::routes()` sets each to its
  Edge path only when `auth('tenant')->user()` holds the permission the Edge route enforces — otherwise `null`, so no entry renders (the
  screen still refuses 403 server-side; the menu only stops offering an entry that would 403).
- The toggle button is **not touched** (no `data-bs-toggle`; a `DOMContentLoaded` listener in the partial opens the offcanvas; it also re-adds
  `body.nosidebar` and restores the button's title/icon after the shared handler toggled them — there is no sidebar on a Branch Server, so
  the page never drifts from Online). The skeleton gate compares the button's attributes on both runtimes — identical. The menu lives
  outside `#main-content`, which the gate's skeleton / id-set / component-set comparisons are scoped to (confirmed in `skeleton()`,
  `elementIds()`, `componentSets()`); its hrefs are allowlisted `edge.local.*` routes (test_e scans the whole render).
- Online rendering is untouched (the partial is Edge-only; `SharedPosViewRenderTest` renders the Cloud runtime; Feature/Pos green).
- Tests: `EdgeChromePartialTest::test_the_edge_chrome_carries_a_runtime_driven_offcanvas_menu_behind_the_shared_sidebar_toggle` (null routes
  → no entry; three finance routes → three entries; island; no `.sidebar`/`<ul`; no `data-bs-toggle`); `EdgeSharedPosViewMySqlTest::
  test_the_edge_menu_offers_health_logout_and_only_the_permitted_finance_screens` (cashier template: Health + Logout only, runtime routes null,
  no finance path in the page; grant ledger → suppliers entry; grant journal + purchase-return store → the other two; every href allowlisted;
  toggle byte-identical to Online; theme hook the only `.sidebar`); `EdgeSupplierFinanceHttpMySqlTest` / `EdgePurchaseReturnHttpMySqlTest`
  flipped from "the POS page carries no finance path" to "the permitted operator gets the menu entry, the cashier does not, the store-only
  user does".

## 6. Task 6 — Edge Direct Pay intents required (Stage A gap §5.2)

`EdgeLocalPosController::storeSale`: after `validate()` (the `in:print,skip` rules still answer `errors.kot_print_intent` for an INVALID value,
like Online `validateSale`), a Direct Pay missing either intent throws `ValidationException::withMessages(['printing' => 'Choose the Direct Pay
KOT and Receipt intent before completing the sale.'])` → 422 `{message, errors.printing}`, byte-identical to `SalesOrderController::store` on
`tenant.pos.store` and to the held settle (Stage A), BEFORE terminal/authority work. Tests: 22 MySQL classes post `/edge/local/pos/sales` —
every post now sends both intents (`skip/skip`, the shared page's Edge-local default; `print/print` in the D-08 printing-parity case, whose
service calls became the exactly-once replay); `EdgeLocalPosHttpMySqlTest` gains the refusal cases (`[null,·]`, `[·,null]`, `[null,null]`
→ `message` + `errors.printing.0`; `maybe` → `errors.kot_print_intent`; no sale row, no stock move); `EdgeCashierPrintingParityHttpMySqlTest`'s
"a sale without intents cannot be retried" (no longer a reachable state over HTTP) became "intent-less post = Online's 422; retrying a
skip/skip sale resumes the SKIPPED state and queues nothing".

## 7. Not done / for the coordinator

- `tools/edge-browser-proof/README.md:12` still documents the deleted `edge-pos-proof.mjs` (file owned by another stream — one line to drop).
- Gap C: owner decision (§4), no behaviour change.
- `EdgeCleanMachineInstallMySqlTest` (nginx/PHP-CGI harness) not run here, as in Stage A.
- Docs that narrate the Phase 2 strict switch (`edge-phase3-census-replacement.md` §4, `edge-phase3-stage-a-route-swap.md` §4) describe history;
  the gates themselves now say strict is the only mode.

## 8. Commands

```
export PATH=/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe php vendor/bin/phpunit tests/Feature/Edge     # 168 / 36,906
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe php vendor/bin/phpunit tests/Feature/Pos      # 26 / 456
EDGE_POS_CUTOVER_STRICT=1 php vendor/bin/phpunit tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php     # identical to =0 (no-op)
MSYS_NO_PATHCONV=1 DB_DATABASE=pos_test_master_edgewt_t13 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t13 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t13 \
  php vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeSharedPos|EdgeCashier|EdgeBootstrap|EdgeConfigRefresh|EdgeLocalPos|EdgeHeld|EdgeDiscountFlow|EdgeArtifact|EdgeAppliance|EdgeLocalRestaurant|EdgeSupplierFinance|EdgePurchaseReturn|EdgeW6ContractEnvelope'
```
