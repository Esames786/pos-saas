# Phase 3 — the W0 control census → the shared-view regression gate

Date: 4 Oct 2026. Branch `feat/edge-config-refresh-v1` (HEAD `6cac0e8` + uncommitted team work). Team R.
Owner directive: *"I do NOT approve simply retiring the control census because 'the shared view is the census'. Convert its
value into a shared-view regression gate first … Only after the replacement gate is green may the old test and fixture be
deleted. Document exactly what replaces every assertion category."*

Status: **replacement gate GREEN** (render half on the isolated Edge MySQL databases, static half in the Feature suite).
The old test `tests/MySql/EdgeCashierControlCensusHttpMySqlTest.php` and its fixture
`tests/Fixtures/edge/online-pos-control-census.json` are **NOT deleted** (that is a later stage, after the owner's go);
the fixture is still READ by the new gate (category b) and stays until the gate no longer needs it.

```
REPLACEMENT_GATE=tests/MySql/EdgeSharedPosRegressionGateMySqlTest.php (render half, 5 tests)
               + tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php (static half, 3 tests)
GATE_RESULT=GREEN — render half 23 / 1,155 (_t11), static half in Feature/Edge 167 / 36,536 (normal + strict)
EDGE_VS_ONLINE_GEOMETRY=NONE — same dataset: 998 boxes, 0 geometry differences, max 0 px (run 1 on different datasets: data-driven only)
STRICT_NO_OLD_EDGE_PAGE=auto (engages when the fallback route or the old view file is gone) | forced with EDGE_POS_CUTOVER_STRICT=1
OLD_CENSUS_DELETED=NO (later stage)
```

## 1. What the new gate renders

One test run, BOTH runtimes, the SAME tenant test database:

1. the process boots as a **Branch Server** (`APP_ROLE=branch_server`, the Edge fixture: real edge migrations, `edge_local_meta`
   binding, enrolled cashier credential with the Online cashier permission set + every `@can` the view gates a control on);
   `GET /edge/local/pos/shared` is rendered (plus the dead-session recall state `?held_sale_id=` built through the Edge APIs);
2. the application is **re-booted as Cloud** in the same test (`refreshApplication()`, `APP_ROLE` unset), the master rows a
   tenant needs are seeded (tenant, tenant_databases → the same test DB, domain, subscription), and `GET /pos` is rendered
   over the REAL tenant HTTP stack (IdentifyTenant by host → subscription access → route permission → `POSController@index`)
   for the same page states;
3. every assertion below compares the two responses. Nothing pins a hash of the view (Team M edits `index.blade.php`
   concurrently) — everything is derived from the two live renders.

`EDGE_GATE_DUMP_DIR=<dir>` writes both renders and both skeletons (`edge-render-main.html`, `cloud-skeleton-main.txt`, …) as evidence.

## 2. Old census assertion category → replacement

| # | Old census (EdgeCashierControlCensusHttpMySqlTest + fixture) | Replacement test method(s) | What it proves now |
|---|---|---|---|
| 1 | **Inventory completeness** — every `id="…"` in the Online view is registered in the fixture; no stale rows (`test_every_online_pos_control_is_registered_and_the_reference_is_pinned`, first half) | `MySql…Gate::test_b_every_census_control_exists_in_both_renders_including_the_online_required_rows` — all 276 fixture rows must exist on BOTH renders (main + dead-session states; DOM id, or a quoted id in the shared page script for the controls the shared JS creates); the DOM id SET of the page content is identical on both runtimes (`ids on Cloud only` / `ids on Edge only` = []). | Every control the census ever registered is served by ONE Blade in both runtimes; a control added to the view appears on both (set equality), a control removed from the view is caught by the fixture rows (until the fixture is retired, then by the skeleton equality). |
| 2 | **Pinned Online view hash** (`online_view_sha1`) — the reconcile gate | Deliberately **not** replaced by a hash. Replaced by `test_a…` skeleton equality: the normalised DOM skeleton (tag, id, classes, element order, every attribute except the runtime allowlist) of `#main-content` must be IDENTICAL on both runtimes, for the main AND the dead-session page state. | A change to the view reaches both runtimes by construction; what the pin guarded (a drift between the two pages) can no longer exist — any Edge-only or Cloud-only element fails with the exact element lines. |
| 3a | **present / equivalent / partial rows** — the Edge counterpart selector is on the rendered Edge page (`test_every_census_row_matches_the_rendered_edge_page`) | `test_b…` (every row's ONLINE id on the Edge render — the counterpart IS the Online control now) + `test_a…` skeleton equality (same element, same place, same classes) + `componentSets` equality (f). | "Equivalent under another id" no longer exists: the Edge page carries the Online id itself. |
| 3b | **planned rows** must NOT be on Edge | No planned rows remain (0 since 25 Sep 2026); the state cannot recur — a control present on Cloud and absent on Edge fails `test_a…` ("Elements on the CLOUD render that the EDGE render lacks"). | — |
| 3c | **online_required rows** (17: Reports Centre window, floors/tables management pane, customer quick-add / add-address) need a decision | `test_b…` — the 17 rows are on the Edge render too (same place); `test_c_capability_gated_controls_have_the_expected_state_per_runtime` — the control each is gated on is enabled on Cloud and **disabled + capability hint** on Edge (`reports`, `manageFloorsTables`, `customerCreate`, `customerAddressCreate`), and the capability-off routes are `null` in `POS_RUNTIME` (§7). | Owner decision A5: identical geometry, the Cloud-only control is disabled in place with the hint; the decision is encoded in `EdgePosRuntimeFactory::CAPABILITIES` + `LABELS`, not in a fixture note. |
| 3d | (implicit) capability matrix | `test_c…` — for EVERY `PosRuntime::CAPABILITY_KEYS`: Cloud flag on; Edge flag = `EdgePosRuntimeFactory::CAPABILITIES`; the view's gated control(s) (`CAPABILITY_CONTROLS` map, read from the `@disabled(! $posRuntime->can(…))` sites: report / return / quick-report buttons, branch select, non-cash tender options, tip buttons, manage floors/tables, qr-network / qr-email, qa-save, new-addr-save; JS-gated `changeRider`) have the expected state; a capability that is OFF on Edge with no gate in the view fails. Static half `test_the_capability_control_map_is_complete_against_the_view_source`: the map equals the set of `$posRuntime->can()` / `POS.can()` sites in the view (nothing unmapped, nothing stale), every `@disabled` site is a capability gate, every OFF capability has a hint label. | — |
| 4 | **Deferral strings** ("not yet available / later milestone / needs the Online POS") must be registered | Not carried over: the deferral strings lived in the old Edge page / Edge services as free text. On the shared view the only "needs the Online POS" texts are the capability hints, which are `EdgePosRuntimeFactory::LABELS` and asserted verbatim by `test_c…` (title attribute = the label). | A deferral can no longer hide in a comment: a disabled control must carry the runtime label, and a label must belong to an OFF capability (static half). |
| 5 | **Composed page script parses** (`node --check`) | Already covered for the shared view by `tests/Feature/Pos/SharedPosViewRenderTest::test_every_inline_script_of_the_rendered_page_parses_as_javascript` (every inline script of the rendered page) and `EdgeBladeCompileGateTest` (compile + `php -l` of `resources/views/tenant/pos/**` and `layouts/pos`). | Unchanged coverage, one page. |
| 6 | (fixture `_doc`) "the register can no longer drift from the screen" | `test_a…` + `test_b…` + static `test_the_shared_view_carries_no_runtime_branch_and_none_of_the_old_edge_page_ids`: the shared view has no `isEdge()` / `app.role` / `mode === 'edge'` branch; none of the OLD Edge page's own ids (frozen `OLD_EDGE_ONLY_IDS`, re-derived from `resources/views/edge/pos/**` while it exists) appears in the shared sources or in either render. | The screen IS the register; there is nothing to drift. |
| — | **New (owner, Phase 3): no Cloud-only endpoint leaks** | `test_e_every_url_of_the_edge_render_resolves_to_an_allowlisted_edge_route_or_a_local_asset` — every `href` / `action` / `src` / `data-*-url` of the Edge render and every non-null `POS_RUNTIME.routes` template is a registered **allowlisted** `edge.local.*` route (`config/edge.php route_allowlist`) or `/edge/local/assets|storage`; plus the leak regexes of `EdgeSharedPosViewMySqlTest` (no `/pos`, `/api/…`, `/printing`, `/restaurant`, … path; no Internet asset; no Cloud header/sidebar). | — |
| — | **New: same modal ids / classes / components** | `test_a…` (f): identical sets on both renders of `.modal[id]` (18 on the main state, + the recovery modal on the dead-session state), `[data-bs-toggle]` (toggle → target pairs), `[data-bs-target]`, `form[id]`, `button[id]`, named controls (`input/select/textarea[name]`), and each modal's dialog/header/body/footer structure. | — |
| — | **New: no old Edge page under strict cutover** | `test_d_the_old_edge_page_is_the_only_fallback_now_and_disappears_under_strict_cutover` (render half: scans every `edge.local.*` route action's source for `view('edge.pos.…')`; GETs `/edge/local/pos`) + static `test_no_old_edge_page_blade_is_rendered_outside_the_phase_2_fallback_and_none_under_strict_cutover` (scans `app/` + `routes/` for `view(`/`@include(`/`@extends(` of `edge.pos.*`). Phase 2: exactly `EdgeLocalPosController::screen` on `edge.local.pos.screen` renders `edge.pos.index` and `/edge/local/pos` returns it; STRICT: nothing renders it and `/edge/local/pos` returns `tenant.pos.index`. | See §4 for the switch. |

Summary counts (with denominators, never a percentage) are written to
`storage/framework/testing/edge-shared-pos-gate-summary.json` by `test_b…` (replaces `edge-pos-census-summary.json`).

### 2.1 The skeleton: what "identical" means

Both renders are parsed (inline `<script>` blocks removed first — libxml would otherwise end a script at the first `</` of a JS
template string) and the `#main-content` subtree is flattened to one line per element: depth, tag, `#id`, every attribute
sorted (classes normalised). Not part of the skeleton: text nodes, comments, `<script>/<style>/<template>/<noscript>`,
`input[type=hidden]` (compared separately: Edge may add only the fixed `branch_id` carrier of `branchSelect=off`). The
**runtime-attribute allowlist** (values may differ, nothing else may): `disabled`, `title`, `href`, `action`, `src`, `value`,
`content` (csrf meta), `data-runtime-mode`, `data-*-url` (report / return / management / board / sync). The class list is
ignored only on `#pos-runtime-state` and `#pos-runtime-pending` (status-slot tone / pending badge). The layout's chrome slot
(Cloud: hidden Online header + sidebar; Edge: hidden data island + theme hook) is outside `#main-content` and differs BY DESIGN
(W-A / W-B: zero geometry, proven by `EdgeChromePartialTest` / `SharedPosViewRenderTest`). Head/foot asset lists must be
identical modulo the asset base (`/assets/…` vs `/edge/local/assets/…`).

## 3. Results

- Render half `EdgeSharedPosRegressionGateMySqlTest`: **23 tests / 1,155 assertions green** on the isolated `_t11` databases (coordinator
  re-run, 6 Oct 2026, both runtimes rendered in one run); green again inside the Stage A team filter on `_t12` (237 tests).
- Static half `EdgeSharedPosRegressionStaticGateTest`: green in `tests/Feature/Edge` (167 tests / 36,536 assertions) in normal mode AND
  with `EDGE_POS_CUTOVER_STRICT=1` on the Stage A tree (route swap landed: `edge.local.pos.screen` → `sharedScreen`, old page unreachable).
- The old `EdgeCashierControlCensusHttpMySqlTest` is skipped since Stage A (points here) and is deleted with its fixture in Stage B.

## 4. The strict "no old Edge page" switch

Both halves share one rule (`strictCutover()`):

- **forced**: `EDGE_POS_CUTOVER_STRICT=1` in the environment of the test run → strict now (fails until the cutover lands;
  the coordinator can run it to see exactly what still renders the old page);
- **automatic**: strict as soon as the fallback is gone — either `routes/edge_runtime.php` no longer routes
  `GET /edge/local/pos` (`edge.local.pos.screen`) to `EdgeLocalPosController::screen`, or
  `resources/views/edge/pos/index.blade.php` no longer exists. So the cutover commit (Team M / coordinator: point
  `edge.local.pos.screen` at `sharedScreen`, then delete `resources/views/edge/pos/**`) flips the gate without touching the tests.

Under strict: no `edge.local.*` route action, no file under `app/`, `routes/` may render / include / extend a Blade under
`resources/views/edge/pos/**`; `GET /edge/local/pos` must render `tenant.pos.index`.

Note for the deletion stage: `tests/Feature/Edge/EdgeBladeCompileGateTest::test_the_cashier_page_script_parses_as_javascript`
still lists `views/edge/pos/index.blade.php` — it must drop that entry (and the `views/edge/**` glob keeps the finance pages)
when the old folder is removed. The W0 census test and fixture are deleted in the same stage.

## 5. Edge-vs-Online paired geometry comparison (tools/edge-browser-proof/geometry-compare.mjs)

Tool: `tools/edge-browser-proof/geometry-compare.mjs` — Playwright (msedge, loopback only, password via `POS_SHOT_PASS`), 13 states ×
2 viewports (1366×768, 1024×768), 34–50 boxes per state (title row, status slot, mode tabs, search, category pills, tile grid, cart
panel, Review & Pay, Hold/Draft/Bill/Recent/Cancel block, the open modal's dialog/header/body/footer and primary buttons), compared at
1 px and classified GEOMETRY / DATA (dims that depend on list content) / PRESENCE.

- Run 1 (dev-seeded Edge DB vs the LAB-dataset clone, `evidence\phase3\geometry\compare-run1-dialog-origin.json`): 998 boxes; every
  difference traced to the DATASET (category pills wrapping at 1024 — more categories in the dev seed; customer-modal height — list length).
- Run 2, SAME dataset (Team Q's paired instance 8096 bootstrapped from the clone, `evidence\phase3\geometry-samedata\compare.json`):
  **998 boxes, 0 geometry differences, max deviation 0 px, 0 presence differences**; 27 DATA-bucket differences, all explained by the two
  product gaps A/B of `docs/status/edge-phase3-samedata-comparison.md` (combo `category_id` lost in the bootstrap; Edge customer lookup
  empty on an empty query). **EDGE_VS_ONLINE_GEOMETRY=NONE.**

## 6. Commands

```
# render half (isolated Edge MySQL databases; ~3–4 min; both runtimes in one run)
MSYS_NO_PATHCONV=1 DB_DATABASE=pos_test_master_edgewt_t11 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t11 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t11 \
  /d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64/php.exe vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeSharedPosRegressionGateMySqlTest
#   + EDGE_GATE_DUMP_DIR=<dir>      writes both renders + skeletons as evidence
#   + EDGE_POS_CUTOVER_STRICT=1     forces the strict "no old Edge page" mode

# static half (fast Feature suite)
/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64/php.exe vendor/bin/phpunit tests/Feature/Edge/EdgeSharedPosRegressionStaticGateTest.php

# geometry: measure both runtimes (password ONLY via POS_SHOT_PASS), then compare
cd tools/edge-browser-proof
MSYS_NO_PATHCONV=1 POS_SHOT_PASS=<edge cashier credential> node geometry-compare.mjs --mode edge  --base-url http://127.0.0.1:8095 --user DEVCASH1 --path /edge/local/pos/shared --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry/edge --settle 6000
MSYS_NO_PATHCONV=1 POS_SHOT_PASS="$(tr -d '\r\n' < C:/Users/Dell/BingooEdgeLab/secrets/cashier.pass)" node geometry-compare.mjs --mode cloud --base-url http://edgehomelab.localhost:9704 --user lab.cashier@edgehomelab.test --path /pos --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry/online --settle 6000
node geometry-compare.mjs --compare --a C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry/edge/report.json --b C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry/online/report.json --out C:/Users/Dell/BingooEdgeLab/evidence/phase3/geometry/compare.json --tolerance 1
```
