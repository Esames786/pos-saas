# Edge W-G1 — remaining backend contract gaps (held settle printing, recipe preview, quick-report 403, device version page)

Date: 3 Oct 2026. Branch `feat/edge-config-refresh-v1`, base HEAD **`68fd04b`** (Phase 2 checkpoint). Team G1. Source + isolated
automated tests only; nothing committed; LAB / production / other worktrees untouched; isolated `_t7` MySQL databases only.

Online behaviour is the specification throughout; no Edge-only behaviour was invented; nothing is faked (no card, no email).

```
TASK1_HELD_SETTLE_PRINTING=DONE   — Edge POST held-sales/{sale}/settle accepts kot_print_intent / receipt_print_intent (in:print,skip),
                                    hashes them (Online hashes them), stores Online's DirectPayPrintOrchestrator initial state on the paid
                                    row inside the settle txn, orchestrates through EdgeLocalPrintDirectPayService AFTER the commit (and on
                                    a replay), answers Online's `printing` block + `idempotent_replay` + `print_intents`. Exactly-once proven.
TASK2_RECIPE_PREVIEW=DONE         — app/Support/Pos/RecipeAvailability.php = the ONE implementation (Online's body moved verbatim); Online
                                    POSController uses it (zero behaviour change); Edge computes it from the local recipe config (bootstrap
                                    Section K IS synced) over the ACCEPTED operational baseline. is_recipe=false is gone from the shared view.
TASK3_QUICK_REPORT_403=DONE       — every JSON quick-report action answers the shared {message, permission} 403 via denyUnlessCan (trait
                                    ResolvesEdgePosContext); the thermal page keeps an HTML 403 unless the request asks for JSON.
TASK4_DEVICE_VERSION_PAGE=DONE    — tenant/offline-edge/index.blade.php device cell shows app version, commit, bootstrap schema, applied edge
                                    schema, capabilities, reported-at (read-only; "Version not reported yet" otherwise). No new assets.
TASK5_ROUTE_KEY_CENSUS=NOTED      — 12 ROUTE_KEYS are referenced by no Blade view (list in §5). Contract left untouched.
GATE=see §6
```

## 1. Held-order settle prints like Online

**Online specification (mirrored):** `app/Http/Controllers/Tenant/SalesOrderController.php`
- `:111-118` — on `tenant.pos.store` both intents are required (page always sends both); `initialState()` built from them;
- `:833-834` — `kot_print_intent` / `receipt_print_intent` validated `in:print,skip`;
- `app/Services/Sales/SaleIdempotencyService.php:57-58` — both intents are part of the hashed canonical payload (changed intent under the
  same client_uuid = 409);
- `:392` — the initial state is stored on the finalized (paid) row, for a held sale too;
- `:616` — orchestrated AFTER the commit; `:629-644` — an idempotent replay re-orchestrates (`existing->direct_pay_print_state`), so stored
  jobs are reused; `:651-660` — `saleResponse` carries `idempotent_replay` + `printing`.
- `app/Services/Printing/DirectPayPrintOrchestrator.php` — ensure-once receipt (`queueReceipt(ensureOnce: true)`), existing-KOT reuse
  (`existingKotJobs`), KOT = the un-sent delta (`PrintJobService::queueKot`), Reminder planning, retryable state that never unwinds the sale.
- The shared page: `resources/views/tenant/pos/index.blade.php` `resolveDirectPayKotIntent` (~4535), `processDirectPayPrinting` (~4567),
  `submitPaidSale` (~4695 — posts to `saleHeldSettle` when a held_sale_id is present and consumes `result.data.printing`).

**Edge changes**
- `app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php` `settleHeldSale()` — validates the two intent keys (same rule as
  `EdgeLocalPosController::storeSale`), reads the replay fact BEFORE the settle (`SaleIdempotencyService::findFinalized`), and after the
  committed settle calls `EdgeLocalPrintDirectPayService::afterPaidSale()` exactly as the Edge Direct Pay endpoint does (same try/report/
  null fallback → the page falls back to `print_intents` → `POST /sales/{sale}/printing/retry`). Response adds `idempotent_replay`,
  `printing` (Online's block with the Edge document URLs; `id` alias on each job as the Edge print endpoints already do) and `print_intents`.
- `app/Services/Edge/EdgeLocalPosService.php` `settleHeldSale()` — `directPayPrintState($data)` (the existing helper the Direct Pay path
  uses: both intents valid → `DirectPayPrintOrchestrator::initialState`, else null); the two intents join the idempotency hash ONLY when
  present (an old-page settle without intents hashes byte-for-byte as before — every pre-existing retry key still matches); the state is
  written on the paid row inside the settle transaction (Online :392).
- No change to the print-job service, orchestrator or the page.

**Exactly-once / retry semantics:** same uuid + same payload → the service replays the finalized sale, the controller re-orchestrates →
the orchestrator reuses `receipt_job_id` / `kot_job_ids` (0 new jobs, 0 new KOT batch, 1 outbox row, 1 payment row); same uuid + a
different intent → 409 (`SaleIdempotencyConflictException`), nothing queued. A KOT already sent for the check before settle → the delta is
empty → `kot_status = not_required`, no second kitchen ticket (Online semantics).

**Test:** `tests/MySql/EdgeHeldSettlePrintingMySqlTest.php` — 4 tests / 111 assertions GREEN (`_t7`):
1. print/print on a never-KOT'd held check → Online `printing` keys (configured/stable/sale_paid/state/retry_available/kot_jobs/receipt/
   reminder{revision,auto_jobs,ask_printers,confirmation_token,warning}), ONE receipt job (Print Here fallback, local document URL, never a
   Cloud `/printing/documents/` URL), the KOT delta job(s) + exactly one KOT batch, durable state on the row (`queued`/`queued`,
   `direct_pay_print_orchestrated_at`), `idempotent_replay=false`, `print_intents`;
2. retry = replay (`idempotent_replay=true`, identical job ids, no new jobs/batch/outbox/payment), changed intent → 409;
3. skip/skip → configured, nothing queued, state `skipped`/`skipped`; intents absent → `printing=null`, no state, no jobs (old contract
   byte-identical); invalid intent → 422 on `kot_print_intent`, check still `held`;
4. KOT sent before settle → no duplicate kitchen ticket at settle (`not_required`), receipt still once.

## 2. Recipe "makeable" preview on Edge tiles

**Is the recipe config on the appliance?** YES. `app/Services/Edge/EdgeBootstrapService.php:661-692` ships `recipes`, `recipe_ingredients`
(+ raw-material products / variants as bare config rows) and the tenant-global `unit_conversions` (Section K);
`app/Services/Edge/EdgeLocalBootstrapImporter.php:87-89` imports them; the Edge local DB runs the full tenant migrations
(`EdgeLocalDbInitCommand:99`, `EdgeLocalSchemaUpgrader:53`) so the tables exist. The Edge sale already consumes recipe ingredients from
the ACCEPTED operational baseline (`app/Services/Edge/EdgeOperationalStockService.php:184-224`, same quantities + real
`UnitConversionService`) — so a preview over `edge_operational_stock_balances` is exactly "the balance the sale refuses on", never a guess.

**Online specification (moved, not rewritten):** the former private `POSController::recipeAvailability()` (was `:557-620`, called at
`:296`; `[branch][product][variant] => qty` lookup built at `:234-238` from grouped `stock_balances`).

**Changes**
- NEW `app/Support/Pos/RecipeAvailability.php` — `forProduct(Product, iterable $branches, array $stockLookup)` = the Online body verbatim
  (recipe gate, tracked-ingredient filter, yield `?: 1`, ingredient-unit → product-unit conversion with the swallowed no-path exception,
  min over ingredients, `floor(max(0, …))`, limiting name), `blank()`, and `stockLookup($rows, $qtyColumn)` (the inline loop both
  controllers had).
- `app/Http/Controllers/Tenant/POSController.php` — uses `RecipeAvailability::stockLookup($stockRows, 'qty')` + `forProduct(...)`;
  the private method and the `UnitConversionService` import are removed. Zero behaviour change on Online (same relations, same inputs,
  same outputs; `tests/Feature/Pos` green).
- `app/Http/Controllers/Edge/EdgeLocalPosController.php` `sharedMenu()` — eager-loads the same recipe relations Online loads
  (`activeRecipe.ingredients.product.unit`, `.unit`, `.variant`), builds the lookup from the accepted baseline (`recipeStockLookup()`;
  empty when no baseline → honest 0, as the sale refuses without a baseline) and computes `is_recipe` / `makeable_by_branch` /
  `limiting_ingredient_by_branch` through the shared class. The hard-coded `is_recipe=false` is gone from the shared view. The old
  fallback page (`menuPayload` / `screen`) is untouched.

**Tests**
- `tests/Feature/Pos/RecipeAvailabilityTest.php` — 5 tests / 33 assertions GREEN (SQLite, in-memory models): Online arithmetic incl.
  limiting name per branch and the no-stock branch, unit conversion applied / no-path fallback / variant balance, blanks (non-recipe, no
  active recipe, untracked-only, yield 0 → 1), the lookup builder, and a source guard that both controllers call the shared class and no
  private copy / `UnitConversionService` remains on Online.
- `tests/MySql/EdgeSharedPosRecipeAvailabilityMySqlTest.php` — 2 tests GREEN (`_t7`, real HTTP `GET /edge/local/pos/shared`): Naan
  recipe (yield 10: 1 kg flour + 200 g ghee with the synced g→kg conversion + untracked salt) over a 2.5 kg / 1.5 kg baseline →
  `makeable 25`, limiting `Flour`; a stock item stays `is_recipe=false` with its on-hand; an untracked-only recipe is blank (plain
  service); raw materials never become tiles; without a baseline → `0`; after a real Edge sale of 10 naan the baseline loses
  1 kg flour + 0.2 kg ghee and the preview follows (`15`, limiting `Ghee`) — the preview reads the number the sale consumed.

## 3. Quick-report 403 shape

**Specification:** W-B §3.2 — every Edge POS JSON endpoint refuses with `{message, permission}` through the shared
`ResolvesEdgePosContext::denyUnlessCan()` (`app/Http/Controllers/Edge/Concerns/ResolvesEdgePosContext.php:26-33`); the Online page
loads keep an HTML 403 (`EnsureRoutePermission` → `abort(403, 'Permission denied.')`). The shared page fetches every quick-report JSON
endpoint with `Accept: application/json` (`index.blade.php:1793-1829`) and opens the thermal view in a window.

**Change:** `app/Http/Controllers/Edge/EdgeQuickReportController.php` — `use ResolvesEdgePosContext`; `guard()` returns the shared JSON
403 for `options`, `settings`, `saveSettings`, `network`, `email` (and for `view` when the request expects JSON) and keeps
`abort_unless(..., 403, 'Permission denied.')` for the HTML thermal page; the branch-scope refusal in `context()` is a JSON `{message}`
403 for JSON callers (a scope, not a permission — same precedent as `denyUnlessMayOperateTerminal`) and `abort(403)` for the HTML page.
The permission constant is unchanged; `app/Support/Pos/PosPermissionCatalog.php` entry text updated. `tests/Feature/Edge/
EdgePermissionCatalogTest` passes (no new check site).

**Test:** `tests/MySql/EdgeCashierQuickReportHttpMySqlTest.php` — NEW method
`test_quick_report_403_is_the_shared_json_shape_for_fetches_and_html_for_the_page` (the existing 3 methods untouched): every JSON
endpoint + a JSON fetch of the view URL → 403 `application/json` `{message, permission: tenant.pos.quick-report-send}`; the HTML page
→ 403 `text/html` without a JSON body; nothing written by a refused call; permission back → the same endpoints answer. Class GREEN.

## 4. Cloud "Offline Edge" device page shows the reported version

**Source of truth:** `app/Services/Edge/EdgeAuthorityLeaseService.php:148-194` `recordBuildReport()` writes `app_version`,
`schema_version`, the canonical build block into `compatibility_manifest` (+ the `*_version` aliases of the compatibility-report
vocabulary, `reported_via: heartbeat`), `build_reported_hash` / `build_reported_at` (migration `2026_09_27_000001_widen_edge_devices_
version_columns.php`).

**Changes (read-only display, existing style, no new assets, no layout change elsewhere)**
- `app/Models/Master/EdgeDevice.php` — `build_reported_at` datetime cast.
- `app/Http/Controllers/Tenant/OfflineEdgeController.php` — `branchState()` adds `build` per row from `buildFacts(EdgeDevice)`: app
  version (`app_version` → manifest `edge_app_version` / `artifact_version`), `git_commit`, bootstrap schema (`bootstrap_schema` →
  `bootstrap_schema_version` → `schema_version`), applied edge schema (`applied_edge_schema_version` → `edge_schema_version`),
  `capabilities`, reported at (`build_reported_at` → `compatibility_reported_at`). Null when nothing was ever reported.
- `resources/views/tenant/offline-edge/index.blade.php` — inside the existing "Paired device" cell: a `small text-muted` block
  (`.edge-device-build[data-device]`) with App version · Commit / Bootstrap schema · Applied edge schema / Capabilities / Reported
  (humanised + exact); unreported facts render `—`; a device that never reported renders "Version not reported yet". Same block on the
  setup page and the security page (one Blade).

**Test:** `tests/MySql/EdgeHeartbeatBuildDevicePageHttpMySqlTest.php` — 2 tests GREEN (`_t7`, REAL tenant HTTP stack
IdentifyTenant → auth:tenant → route.permission on `/settings/offline-edge/security`): the build recorded by the real
`recordBuildReport()` renders every field for that device only (commit appears exactly once), the never-reported device says so,
no new controls (only the existing Revoke form); fallback to the pairing-era columns + compatibility-report aliases with dashes for
unreported facts.

## 5. PosRuntime::ROUTE_KEYS referenced by no view (contract left alone)

Census over every `.blade.php` under `resources/views` (`POS.route('k')`, `POS.api('k', …)`, `$posRuntime->route('k')`,
`routes['k']`, `'k' =>`), including `layouts/pos`, `tenant/pos/**`, the chrome partials and the W-B secondary screens:

- **Unused by any view (12):** `quickReportOptions`, `tableMerge`, `shiftOpen`, `shiftClose`, `heldShow`, `printJobsRecent`,
  `shiftSummary`, `voidReasons`, `printPreferences`, `printMarkPrinted`, `printDismiss`, `heldKot`.
- Used only by the Edge chrome partial (`tenant/pos/partials/pos-chrome-edge.blade.php`): `syncSummary`, `terminals`, `terminalSelect`.
- `customerQuickStore` / `customerAddressStore` ARE used (`POS.api(...)` at `index.blade.php:7068` / `:6930`).
- There is no `heldIndexPage` key in the contract; the held-list key the view uses is `heldList` (`:5032`), the recall page is `posIndex`.
- `voidReasons` the view reads is a PHP variable (`@php $voidReasons = VoidReason::…` `:4003`), not the route key.
  Nothing was changed in `PosRuntime`, either factory or either view.

## 6. Gates (all on the isolated `_t7` databases)

```
FEATURE_POS=GREEN   — vendor/bin/phpunit tests/Feature/Pos: 24 tests / 423 assertions (19 before + 5 new RecipeAvailabilityTest)
FEATURE_EDGE=GREEN  — vendor/bin/phpunit tests/Feature/Edge: 162 tests / 36,118 assertions (EdgePermissionCatalogTest incl. — no new check site)
MYSQL_GATE=GREEN    — phpunit.mysql.xml --filter "EdgeHeldSettlePrinting|EdgeSharedPos|EdgeDiscountFlow|EdgeCashierQuickReport|EdgeCashierPrinting|
                      EdgeHeartbeat|EdgeCashierOrderLifecycle|EdgeLocalPos" on pos_test_{master,tenant,edge_local}_edgewt_t7:
                      99 tests / 2,065 assertions, 0 failures, 0 errors, 0 skipped (3 min 43 s)
```

New test counts inside that filter: EdgeHeldSettlePrintingMySqlTest 4 / 111 · EdgeSharedPosRecipeAvailabilityMySqlTest 2 / 34 ·
EdgeHeartbeatBuildDevicePageHttpMySqlTest 2 · EdgeCashierQuickReportHttpMySqlTest +1 method (4 total).

Files touched (source): app/Support/Pos/RecipeAvailability.php (new), app/Http/Controllers/Tenant/POSController.php,
app/Http/Controllers/Edge/EdgeLocalPosController.php, app/Http/Controllers/Edge/EdgeLocalHeldSalesController.php,
app/Services/Edge/EdgeLocalPosService.php, app/Http/Controllers/Edge/EdgeQuickReportController.php, app/Support/Pos/PosPermissionCatalog.php,
app/Models/Master/EdgeDevice.php, app/Http/Controllers/Tenant/OfflineEdgeController.php, resources/views/tenant/offline-edge/index.blade.php.
Tests: tests/MySql/EdgeHeldSettlePrintingMySqlTest.php (new), tests/MySql/EdgeSharedPosRecipeAvailabilityMySqlTest.php (new),
tests/MySql/EdgeHeartbeatBuildDevicePageHttpMySqlTest.php (new), tests/Feature/Pos/RecipeAvailabilityTest.php (new),
tests/MySql/EdgeCashierQuickReportHttpMySqlTest.php (+1 method). No migration, no route, no asset, no view other than the device cell.

Not G1: `tools/edge-browser-proof/README.md` and `tools/edge-browser-proof/shared-pos-workflows.mjs` are uncommitted in this worktree
from a concurrent Team G2 session (browser workflow proof) — untouched by G1; the coordinator should commit them separately.

## 7. Not done / mismatches noticed but not fixed (Online vs Edge)

- **Intents optional on the Edge settle** — Online's `tenant.pos.store` refuses a sale (held or not) without BOTH intents (`:111-118`,
  422 `printing`). The Edge settle keeps them optional (nullable `in:print,skip`), because the old fallback page and every existing settle
  test post without them; the shared page always sends both, so behaviour is identical for it. Making them required is a one-line change
  once the old page is retired.
- **`idempotent_replay` on the Edge Direct Pay endpoint** — `EdgeLocalPosController::storeSale` still omits Online's `idempotent_replay`
  (the page only uses it for the toast wording). Added on the settle only (Team 2 owns storeSale).
- **Replay flag on a concurrent first request** — the Edge settle reads the replay fact before the service call; a request that loses a
  simultaneous race for the same uuid answers `idempotent_replay=false` while still returning the winner's sale and printing (Online's
  race path says `true`). Harmless (same body otherwise), noted for exactness.
- **Edge `printing.*.preview_url` is absolute** (`url('/edge/local/pos/print-jobs/…')`, as the existing Direct Pay path answers) while the
  held-controller `jobView()` and the print-job endpoints answer path-only URLs (W-B). Online is absolute too; left as the Direct Pay path
  does it.
- **Recipe preview — order-type filter**: Online's preview ignores `recipe_ingredients.applicable_order_types` while consumption
  (`RecipeConsumptionService` / `EdgeOperationalStockService::consumeRecipe:200`) honours it — the preview can under-promise for an
  order type that skips an ingredient. Identical on both sides (moved verbatim), not fixed (Online is the spec).
- **Quick-report scope refusal has no `permission` key** — it is a branch-scope refusal, not a permission; `{message}` only, as the
  terminal-assignment refusal already does.
- **Device page**: the setup page (`/settings/offline-edge`) renders the same cell; it was proven through the permission-only security
  page because the module entitlement (`tenant.subscription.access`) is not seeded in the MySQL harness — one Blade, same `$branchRows`.
