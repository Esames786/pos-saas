# EDGE W-G3 — shared cashier view: the G2 proof's gap list closed (3 Oct 2026)

Team G3. Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, base `cabe940`. Source + isolated
automated tests only; nothing committed; the LAB, the other worktrees, production and the running dev servers were not touched
(the dev Edge DB `bingoo_edge_devtest_local` was re-seeded through `tools/edge-dev-instance/seed.sh`, as allowed, to prove G3(a)
on the live dev instance). Online behaviour is the specification throughout; every change mirrors a named Online line.

Input: `docs/status/edge-w-g2-workflow-proof-report.md` §2 (G1–G4, E3) and §LAYOUT (X1), §PERMISSION/DATA P7 (X2).

## 1. Per gap — what changed, the Online behaviour mirrored, how it is pinned

### G1 — `lines[i][modifiers]` as a JSON string (blocked EVERY sale / hold from the shared view)
Online: `SalesOrderController::validateSale` `'lines.*.modifiers' => ['nullable','string']` (:863) + `normalizeLineModifiers` (:970);
`HeldSaleController::store` `'nullable|string'` (:298) + `normalizeLineModifiers` (:1081). The view posts multipart
(`index.blade.php` `buildInputs()` :3734-3760: `modifiers: JSON.stringify(...)`, plus a deal as `combo_header` + `component` rows).

Changed:
- `app/Http/Controllers/Edge/Concerns/ResolvesEdgePosContext.php:49-92` — new `normalizeSharedLines()` + `decodeLineModifiers()`:
  a JSON string decodes to its array (undecodable → `[]`, exactly Online), an array stays (the old Edge page), and a deal's posted
  `component` rows are dropped because the Branch Server expands the deal from the SYNCED combo book (the same rule the quote twin
  `quoteInput` already applied at :528 — without it a deal posted from the shared view would have been expanded once per posted row).
- `EdgeLocalPosController::storeSale` :1014-1020 (rule `['nullable']` + Online's `client_line_key` / `parent_client_line_key` /
  `line_kind in:standard,combo_header,component,modifier`) and :1034-1035 (normaliser applied before the service).
- `EdgeLocalHeldSalesController::storeHeldSale` :166-174 (same rules) and :183-184 (normaliser).
- Options are still priced and named from the synced modifier book (`EdgeLocalPosService::resolveModifiers`) — the client's
  names/deltas are ignored as before; only the request SHAPE changed. `previewBill` / `billPreviewDocument` already receive arrays.

Tests: `tests/MySql/EdgeSharedPosGapFixesMySqlTest.php` — `test_g1_a_multipart_sale_with_json_string_modifiers_is_accepted_and_priced_from_the_synced_book`
(form post, JSON-string modifiers → 201, unit price 300 = 250 + synced +50, stored snapshot names the synced option; the array form
still 201; `modifiers="not-json"` → 201 with no options), `test_g1_a_multipart_deal_posted_as_header_plus_component_rows_sells_once_from_the_synced_combo_book`
(2 × deal = 800, rows `combo_header, component, component`), `test_g1_a_multipart_hold_with_json_string_modifiers_is_accepted`.
Settle (`/held-sales/{sale}/settle`) takes no lines — nothing to change.

### G2 — hold response `lines[]` without `client_line_key` (blocked the second KOT round and the in-cart void)
Online: `HeldSaleController::store` `$savedLinePayload[] = ['id','client_line_key','kot_sent','kot_sent_quantity']` (:798-803),
returned as `lines` (:823); the view matches `savedLine.client_line_key` → `item._dbLineId` (`index.blade.php` :4817-4824) so the next
Hold posts `sales_order_line_id`.

Changed:
- `app/Services/Edge/EdgeLocalPosService.php` :1811-1826 (`resolveLines` carries `_client_line_key` on the NAMED row — a deal's
  header gets the deal's key, its server-expanded components none), :1250-1252 (`createSaleLines` records created line id → key),
  :1255-1268 (`lastSavedLineClientKeys()`), :908 (reset per `holdOrReviseSale`).
- `EdgeLocalHeldSalesController::storeHeldSale` :208-230 — `lines[]` now `{id, client_line_key, kot_sent, kot_sent_quantity}` (Online
  keys) + the Edge extras the old page reads (`line_uuid, product_id, quantity, unit_price`), additive. Validation accepts
  `client_line_key` (it was previously stripped by `validate()`, so it could never have been echoed).

Test: `test_g2_hold_response_carries_client_line_key_so_round_two_continues_the_sent_line_and_kot_sends_only_the_delta` — open table →
multipart hold (keys present, `client_line_key` echoed) → KOT 1 → hold round 2 with the response ids (200, the continued line
`kot_sent=true`, the new line not) → KOT 2 = exactly the new item, zero cancellations → round 3 adds a deal (header keyed, components
unkeyed). It also pins WHY the key is needed: a revision rewrites the rows (Online's own delete+recreate churn), so the ids move.

### G3 — customer sale refused: seeded customers had `customer_uuid` NULL
The Edge envelope rule (`EdgeSaleEnvelopeBuilder::customerIdentity` :232, the owner's canonical-identity requirement) is KEPT.
(a) Dev seed: `tests/MySql/EdgeDevInstanceSeedMySqlTest.php` :198-200 inserts every customer with a ULID (the model trait
    `HasCanonicalIdentity` mints one on create; a raw insert must too) + an assertion that no seeded customer is NULL (:236).
    All MySQL fixtures that insert customers raw already carried a ULID (checked: 12 files) — no other fixture change needed.
    The dev instance was re-seeded (`seed.sh`, guarded to `bingoo_edge_devtest_local`): see §3.
(b) Cloud-side safety net: `app/Services/Edge/EdgeBootstrapService.php` :636-657 `ensureCustomerIdentities()` — the migration
    `2026_08_08_000010` backfill rule (memory-bounded, fresh ULID per NULL row, a populated value NEVER changed, the UPDATE re-checks
    `IS NULL` so a concurrent writer wins). Called BEFORE the claim watermark in `createOrReuse` (:143-146) and `refreshPackage`
    (:500-503) — a repaired row moves `customers.updated_at`, so doing it before the claim keeps claim == txn == live (never a
    `SOURCE_CHANGED` on the build that repaired the book) and mints the config revision that carries the identity to an
    already-bootstrapped appliance — and again at the top of `buildSections` (:665, a no-op in the normal flow, covers direct callers).
Test: `tests/MySql/EdgeBootstrapV7MySqlTest.php` `test_the_export_generates_a_canonical_customer_uuid_once_for_any_null_row_and_never_changes_a_populated_one`
(NULL row ships with a valid ULID that IS the persisted value; populated row unchanged; inactive NULL row repaired too; watermark
moves once; second export changes nothing).

### G4 — reservation demanded `branches.sales_operating_mode = local_edge` (stricter than a sale / table open)
Online reserves on a cloud branch (`RestaurantTableController::reserve`); on Edge every other mutation goes through
`EdgeLocalPosService::assertAuthorizedPrincipal` (:639: principal + `EdgeUserAuthz` + P0 `assertLocalMutationAllowed`) — table
open via `EdgeLocalTableOperationsService::guardMutation` (:501) uses the same two pieces.

Changed: `app/Services/Edge/EdgeTableReservationService.php` :124-147 — `authorize()` now calls THAT gate (the
`branchHandedToBranchServer` demand removed; `ValidationException` surfaced as the gate's own business message so the controllers'
422 `{message}` reads identically to a refused table open). Class doc :20-25 updated; unused imports dropped.

Test: `tests/MySql/EdgeCashierReservationHttpMySqlTest.php` `test_g4_reservation_uses_the_same_authority_gate_as_a_table_open` —
the proof's appliance state (manual Local Mode, branch row `cloud`): open 201, reserve 201, unreserve 200; lease mode + STANDBY:
open 422 and reserve 422 with the SAME message (`Local Mode is not active — …`), unreserve 422; LOCAL_ACTIVE again: both succeed.
`EdgeTableReservationMySqlTest` (service level) unchanged and green.

### E3 — customer quick-add / add-address must be disabled-with-hint on Edge (owner A5/A6)
Changed (shared view, zero geometry: disabled state + `title` only, no new element):
- `resources/views/tenant/pos/index.blade.php` :2021-2022 (`#qa-save` `@disabled(! can('customerCreate'))` + `title=capabilityHint`)
  and :2043-2044 (`#new-addr-save`, capability `customerAddressCreate`) — the exact mechanism of `#qr-email` / `#qr-network` (:1687-1692).
- `resources/views/tenant/pos/js/pos-runtime.blade.php` :66-75 — the capability-off rejection is now recognisable: `capabilityOff: true`,
  `status 0`, and a body shaped like a refused JSON response `{ok:false, capabilityOff:true, route, message}`; :210-214 `POS.hintText()`
  (the raw label for textContent / toasts; `POS.hint()` stays HTML-escaped). Never produced on Online (every Cloud route is non-null).
- `index.blade.php` :7071-7083 `quickSave()` — the rejection handler resolves `e.status || e.capabilityOff`; a `capabilityOff` body shows
  `POS.hintText('customerCreate')` (the same text as the title) instead of "Could not save the customer."; :6933-6937 address save —
  same for `customerAddressCreate` (toast). The Enter-key paths reach these handlers even though the buttons are disabled.
Tests: `EdgeSharedPosGapFixesMySqlTest::test_e3_customer_quick_add_and_add_address_render_disabled_with_the_capability_hint_on_edge`
(real Edge render: both buttons disabled with the `EdgePosRuntimeFactory::LABELS` text; one element each);
`tests/Feature/Pos/SharedPosViewRenderTest.php::test_customer_quick_add_and_add_address_follow_the_capability_mechanism_on_edge_and_are_untouched_on_cloud`
(Cloud render: no `disabled`, no `title`, identical element set; Edge render with the two capabilities off: both disabled with the labels).

### X1 — split-bill page errors inside the POS modal; "Sidebar element not found" on every Edge page
- `resources/views/tenant/sales-orders/split-bill.blade.php` :154-194 — the script referenced `#tendered_amount`, which the page renders
  on NEITHER layout (the split is not paid here) → `null.dataset` / `null.addEventListener`. Guarded (`if (tendered …)`). Online's
  rendering is unchanged (the totals were always updated before the throw; the errors were latent on Online too).
- `resources/views/tenant/pos/partials/pos-chrome-edge.blade.php` :32-38 — `theme-script.js` (:501-507, loaded by `layouts.pos` on both
  runtimes inside DOMContentLoaded) looks for `.sidebar`; without it it logs the error and SKIPS writing `data-theme / data-sidebar /
  data-color / data-layout / data-topbar / data-width` on `<html>` — attributes the Online page does get from its Cloud chrome. A hidden,
  zero-geometry `<nav id="pos-edge-theme-hook" class="sidebar" hidden style="display:none">` inside the display:none chrome lets Edge run
  the same code path (also `body.pos-workspace.nosidebar .sidebar {display:none}`). It is not the Cloud sidebar — `EdgeSharedPosViewMySqlTest`'s
  "no `<div class="sidebar"`" guard still holds.
Test: `tests/Feature/Edge/EdgeChromePartialTest.php` (2 tests: the hook renders inside the hidden chrome, no Cloud sidebar markup, the
selector the vendored script uses is still `.sidebar`; the split-bill script guards every reference to the missing field).

### X2 — DEVCASH1 "pinned to Counter 1" yet offered Counter 2 (P7)
Finding: NOT a gap. The seed grants DEVCASH1 the full cashier template incl. `tenant.pos.change-terminal` (:96-98); Online's rule
(`PosController::index` :430-433) pins the list to `default_terminal_id` ONLY for an operator WITHOUT that permission, so Online offers
both counters to this user too (`EdgeCashierScreenRendersHttpMySqlTest::test_pinned_operator_is_offered_only_his_assigned_terminal`
models the pinned case by revoking it). One real difference was found and aligned: Online's list is first scoped by
`UserDataScope::terminalsForPos` (the operator's ASSIGNED terminals, `terminal_user`); the two Edge page paths applied only the pin.
Changed: `EdgeLocalPosController` :150-159 (shared page data) and :593-600 (old page) — assignment scoping, then the pin. The server
already re-checked assignments on every use (`denyUnlessMayOperateTerminal`), so this is the page offer matching the server rule.
Test: `EdgeSharedPosGapFixesMySqlTest::test_x2_terminal_offer_follows_online_assignment_then_pin_rule_on_the_shared_page`
(change-terminal + no assignment → both counters; `terminal_user` row → only the assigned one; pinned → only his own).

## 2. Gates (isolated `_t8` databases; nothing else)
- `tests/Feature/Pos`: OK (25 tests, 445 assertions) — was 24; +1 E3 render test.
- `tests/Feature/Edge` (incl. `EdgePermissionCatalogTest`, the Blade compile gate, the new `EdgeChromePartialTest`): OK (164 tests, 36,131 assertions) — was 162.
- MySQL filter `EdgeSharedPos|EdgeDiscountFlow|EdgeCashier|EdgeLocalPos|EdgeHeld|EdgeLocalRestaurant|EdgeReturn|EdgeShift|EdgePrint|EdgeManagerApproval|EdgeHeldSettle|EdgeDevInstanceSeed|EdgeBootstrap|EdgeConfigRefresh|EdgeTableReservation`
  on `_t8` (detached `--debug` run, 03:12–03:21 PST): 211 tests — 209 passed, 1 skipped (the dev seed, guarded to its own DB — expected),
  1 failed on the first pass: `EdgeCashierControlCensusHttpMySqlTest::test_every_online_pos_control_is_registered_and_the_reference_is_pinned`
  — the census pins `sha1(index.blade.php)` and the E3 edit moved it (no `id=` added or removed; `qa-save` / `new-addr-save` stay
  classified `online_required` A13 — still true). Re-pinned `online_view_sha1` in `tests/Fixtures/edge/online-pos-control-census.json`
  as the test instructs (the same step previous waves took); the class re-run on `_t8` is green (4 tests, 12 assertions; its Node
  parse test skips only when `EDGE_NODE_BIN` is unset). No other test in the filter changed outcome.
- New / extended tests: `EdgeSharedPosGapFixesMySqlTest` (6), `EdgeCashierReservationHttpMySqlTest` (+1), `EdgeBootstrapV7MySqlTest` (+1),
  `SharedPosViewRenderTest` (+1), `EdgeChromePartialTest` (2, new), `EdgeDevInstanceSeedMySqlTest` (+1 assertion).

## 3. Dev instance re-seed (G3a on the live dev Edge)
`bash tools/edge-dev-instance/seed.sh` (guarded to `bingoo_edge_devtest_local`; the dev instance on :8095 kept running): OK (1 test,
4 assertions) — `DEV EDGE SEEDED db=bingoo_edge_devtest_local branch=1 terminals=1/2 products=14 variants=4 modifier_groups=2 combos=2
tables=10 cashier=DEVCASH1 manager=DEVMGR1`. Verified on the dev DB: `customers` = 3 rows, 0 NULL `customer_uuid`, every value 26 chars
(ULID). The W04 workflow (sale with a book customer) can now be re-proven on the dev appliance without data repair. The dev DB's
sales/returns from the G2 run were reset by the re-seed (they were disposable proof data).

## 4. Not done / notes
- No permission check site was added (no `PosPermissionCatalog` change); `EdgePermissionCatalogTest` untouched and green.
- The browser proof itself (`tools/edge-browser-proof/shared-pos-workflows.mjs`) was not re-run here (Team G2's tool; the dev DB was
  re-seeded so a re-run starts clean — run WITHOUT `--rewrite-modifiers` to prove G1 end-to-end).
- G3(b) repairs identities at export time only; a tenant that never bootstraps/refreshes an appliance keeps its NULL rows (the Online POS
  does not need them) — the model trait covers every new customer.

## 5. Further Online-vs-Edge mismatches noticed (not in the brief; not changed)
1. **Deal rows from the shared view (now handled, worth knowing):** before this change the shared view's deal post (header + component
   rows, each with `combo_id`) would have been expanded once per posted row by `EdgeLocalPosService::resolveLines` (G1 covered it, the
   G2 proof never sold a deal). Online writes the client's rows as posted; Edge expands from the synced book — same stored shape, but
   Edge ignores client-supplied component quantities (by design: "never a client-supplied header/component price").
2. **Hold revision churn:** both runtimes delete+recreate the lines on a revision, so `sales_order_line_id`s move on every Hold; the view
   relies on the response ids each time. Parity, but any tool/report that caches held line ids across holds is wrong on BOTH.
3. **Reservation refusal shape:** Online `RestaurantTableController::reserve` validates `reserved_customer_id` `exists:customers` (a 422
   `errors` bag); Edge answers `{ok:false, message}` 422 for every refusal (`reserveTable` catches `\Throwable`). The shared view reads
   `message`, so it is cosmetic.
4. **Hold validation surface:** Online `HeldSaleController::store` validates `lines.*.unit_price`, `line_name`, `discount_type` etc. that
   the view posts; Edge ignores `unit_price`/`line_name` (server is the price authority) — intentional (H6), but a payload containing an
   invalid `product_variant_id` is 422 `exists` on Online and a service-level refusal on Edge.
5. **Theme attributes:** until X1 the Edge page never received the `data-theme/...` attributes the Online page gets from `theme-script.js`
   (the proof found no geometric difference, so the theme CSS keyed on them is evidently neutral for the POS workspace, but the Edge render
   was running a different JS path). Now identical.
