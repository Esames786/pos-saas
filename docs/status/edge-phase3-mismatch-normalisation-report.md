# EDGE PHASE 3 — Online-vs-Edge mismatch normalisation (owner §6 items B–F) + device version reporting proof (§8) — 4 Oct 2026

Team M. Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, base `6cac0e8`. Source + isolated automated
tests only; nothing committed; the LAB, the other worktrees, production and the running dev servers' databases were not touched.
Online behaviour is the specification; per the owner, a bug both sides showed was NOT preserved (item E). The concurrently edited
manager-approval files (EdgeLocalAuthService, the approval controllers/services, bootstrap users export) and the new shared-view
regression gate / `tools/edge-browser-proof` were not edited.

Input: `docs/status/edge-phase2-step7-report.md` §3 (the leftovers).

## Summary

| Item | Decision | Edge change | Online reference |
|---|---|---|---|
| B totals/quote modifier validation | **User-visible → made lenient like Online** (store/preview stay strict) | `EdgeLocalPosService.php:567-574, 1819, 1851, 1943-1975`; `EdgeLocalPosController.php:551-568`; `index.blade.php:3629-3634` | `POSController::quoteTotals` `app/Http/Controllers/Tenant/POSController.php:734-787` |
| C print retry refusal wording | **Online's text on Edge's rule** | `EdgeLocalPrintDeliveryService.php:245-248` | `PrintJobService::requeueFailed` `app/Services/Printing/PrintJobService.php:614` |
| D reservation refusal JSON shape | **Normalised to `{ok:false, message, errors}`** (Online field names in the bag) | `EdgeLocalRestaurantController.php:314, 342, 349-369` | `RestaurantTableController::reserve` `:33` (`{ok:false,message}`) and `:36-42` (`$request->validate` → Laravel `{message, errors}`) |
| E `#ctx-terminal-name` "No terminal" | **Bug on both runtimes — fixed once in the shared view** (text only, same element) | `index.blade.php:7157-7163` | n/a (shared view; root cause below) |
| F Direct Pay `idempotent_replay` | **Added with Online's semantics** | `EdgeLocalPosController.php:1048-1054, 1088` | `SalesOrderController::saleResponse` `app/Http/Controllers/Tenant/SalesOrderController.php:651-660` (`:658`) |
| 8 device version reporting proof | **Proven over real HTTP** (A → B on the same paired device, no re-pair, authority untouched, replay/stale intact) | test only | `OfflineEdgeController::buildFacts :150-182`, `EdgeAuthorityLeaseService::recordBuildReport :148-193`, `tenant/offline-edge/index.blade.php :82-91` |

## B — Edge `POST /edge/local/pos/totals/quote` validated required modifier groups; Online's quote does not

**Is the 422 user-visible?** Not as a toast: `refreshServerTotals` (`index.blade.php:3638-3686`) does `res.json()` then `if (!data.ok) return;`
and swallows errors (`.catch(function () { /* keep POS usable */ })`). **But it changes displayed totals.** The quote is the ONLY source of
`_serviceChargeAmount` and of the promotion's live `promotion_discount_amount` (`:3661-3675`), and the shared view's `collectQuoteLines`
(`:3620-3631`) never sent `modifiers` — so on Edge every quote for a product carrying a required group was refused (not only "while the
modal is open": always), the page kept a stale service charge / promo amount, and the payment modal's total differed from Online for the
same cart. Two further pricing differences hid behind the same quote: Online prices the client's option-inclusive `unit_price`, the Edge
quote re-priced on the server WITHOUT the (unsent) option deltas, and a deal (combo) row — sent without `combo_id` / `line_kind` — was
priced on Edge as the header product plus every component at full price.

**Change (Edge quote as lenient as Online; the sale store / hold / Preview Bill keep enforcing required modifiers):**
- `app/Services/Edge/EdgeLocalPosService.php:567-574` — `previewBill(..., bool $quoteOnly = false)`; `:1819` `resolveLines(..., bool $quoteOnly = false)`;
  `:1851` passes `enforceSelection: ! $quoteOnly`; `:1943-1975` `resolveModifiers(..., bool $enforceSelection = true)`: in quote mode the
  options the page named are priced from the synced book, an option not on this product's menu is left out (`:1969`), and the min/max
  loop is skipped (`:1975`). Every mutating path keeps the strict default (`completePaidSale`, holds, `billPreviewDocument`, the
  `preview-bill` endpoint).
- `app/Http/Controllers/Edge/EdgeLocalPosController.php:551-568` — `quotePreview` (used by BOTH quote twins, totals + promotions) calls
  `previewBill(..., quoteOnly: true)`.
- `resources/views/tenant/pos/index.blade.php:3629-3634` — `collectQuoteLines` additionally sends `product_variant_id`, `combo_id`,
  `line_kind`, `modifiers` (normalised). Online's `quoteTotals` validates only its own keys and ignores these (Laravel `validate()`
  returns validated keys only; `:756-766` maps `product_id/category_id/quantity/unit_price/discount_amount/tax_amount`), so the Online
  request is unchanged in effect; on Edge the server-priced quote now equals Online's client-priced one (option deltas folded, deals
  expanded from the combo book, component rows dropped by `quoteInput :528`).

**Tests:** `tests/MySql/EdgeSharedPosContractMySqlTest.php:223-245` (inside `test_totals_and_promotion_quote_twins_answer_in_online_flat_keys`):
a REQUIRED single-choice "Spice Level" group on the karahi → quote with no modifiers = 200 / subtotal 200 (was 422 "Select at least 1
option…"), quote naming Hot = subtotal 240 (book delta +20, the client's 999 ignored), unknown option skipped (100), max-1 violation
tolerated (120); the paid sale AND `preview-bill` with no modifiers still 422 "Select at least 1 option for Spice Level on Chicken Karahi.",
zero sales written. `EdgeCashierMenuHttpMySqlTest` (the strict store rules, `:277-289`) unchanged and green.

## C — Retry refusal text

`app/Services/Edge/EdgeLocalPrintDeliveryService.php:245-248` — `retryTerminalFailed` now throws Online's sentence
`Only failed or cancelled jobs can be retried.` (`PrintJobService::requeueFailed :614`). The Edge RULE is unchanged (a terminally-failed local
delivery or a dismissed job; never a queued / leased / printed one); `EdgeLocalPrintJobController::retryPrintJob :238-243` still answers
`422 {message}` and the shared view toasts `data.message` (`index.blade.php:5654`). No technical reason forbade the text.
Tests: `tests/MySql/EdgeCashierPrintingParityHttpMySqlTest.php:523-526` (HTTP 422 + exact message), `tests/MySql/EdgeLocalPrintDeliveryMySqlTest.php:343-344`
(service message updated from the old `terminally-failed` substring).

## D — Reservation refusal JSON shape

What the view reads: `index.blade.php` reserve-save handler (`:6322-6328`) shows `res.d.message || 'Could not reserve the table.'` on a non-2xx;
`cancelReservation` (`:6263-6268`) ignores the body. Online: a Laravel validation failure (`RestaurantTableController::reserve :36-42`) is
`422 {message, errors}`; its open-session refusal (`:33`) is `422 {ok:false, message}`. Edge answered its service refusals as `{ok:false, message}`
(a service `ValidationException` collapsed to `message: "The given data was invalid."` with no bag) and cancel as a bare `{message}`.

`app/Http/Controllers/Edge/EdgeLocalRestaurantController.php:349-369` — new `reservationRefusal()` used by `reserveTable :314` and
`cancelReservation :342`: always `422 {ok:false, message, errors}`; a service `ValidationException` keeps its bag re-keyed to ONLINE's field
names (`customer_id → reserved_customer_id`, `customer_name → reserved_name`, `customer_phone → reserved_phone`, `note → reservation_note`)
with `message` = the first error (Laravel's rule); a business `RuntimeException` is filed under `errors.table`. `ok:false` kept (Online's
`:33` shape; harmless, additive). Request validation (`$request->validate :304-310`) was already Laravel's shape on both sides.
Tests: `tests/MySql/EdgeCashierReservationHttpMySqlTest.php:201-235` `test_phase3_d_reservation_refusals_carry_online_message_plus_errors_bag`
(duplicate reservation → exact `[ok, message, errors]` keys + `errors.table.0`; unknown book customer → `errors.reserved_customer_id.0`
and `message`; `reserved_for` not a date → Laravel bag; cancel-nothing → the same shape).

## E — `#ctx-terminal-name` shows "No terminal" while terminal 1 is selected (both runtimes)

**Root cause (shared view JS, not page data):** the page has two script blocks. Block 1 (`index.blade.php:2174`) is a `DOMContentLoaded`
callback; inside it `autoSelectTerminal()` (`:2476-2512`, called at `:2525`) picks the cashier's default/remembered/first terminal by
assigning `terminalEl.value = candidate` — a programmatic assignment that fires NO `change` event. Block 2 (`:6798`, an IIFE) runs
synchronously while the document is still parsing — i.e. BEFORE DOMContentLoaded — and its `updateContextSummary()` (`:7142-7156`) reads
`terminal_id` while it is still `""` → writes "No terminal"; its only refresh path was the `change` listener, which never fires for the
programmatic selection. The `<select>` renders no `selected` option on purpose (Online's `:665-670`), so the page data is correct on both
runtimes (the Edge page data is NOT involved — `EdgeSharedPosViewMySqlTest` was therefore left untouched).

**Fix (once, in the shared view, text only):** `index.blade.php:7157-7163` — the summary is re-read on `DOMContentLoaded`
(`if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', updateContextSummary)`). Block 1's callback was
registered first, so the hook runs after the auto-selection and shows the selected terminal's name (text before the " — branch" suffix).
Same element, same markup (`<span id="ctx-terminal-name" class="text-muted"></span>`), zero geometry change; nothing else listens.
`#print-terminal-label` (`:4446`) was never affected (it is computed when the payment modal opens).

Tests: `tests/Feature/Pos/SharedPosViewRenderTest.php:318-370` `test_the_context_bar_reads_the_auto_selected_terminal_after_dom_content_loaded`
— exactly one `#ctx-terminal-name`, markup unchanged; ordering proof on the rendered page (block-1 `DOMContentLoaded` registration <
`autoSelectTerminal();` < the summary hook); and the extracted `updateContextSummary` run under Node against a stub DOM: `''` → "No terminal",
then a programmatic `value = '3'` with NO change event → "Till 1" (branch "Main"). The existing Node syntax gate
(`test_every_inline_script_of_the_rendered_page_parses_as_javascript`) passes on the changed page.
Census: `tests/Fixtures/edge/online-pos-control-census.json` `online_view_sha1` re-pinned `07b24460… → a5fec968…` (no id added/removed/renamed;
`EdgeCashierControlCensusHttpMySqlTest` green).

## F — Edge Direct Pay `idempotent_replay`

`app/Http/Controllers/Edge/EdgeLocalPosController.php:1048-1054` — before `completePaidSale`, `SaleIdempotencyService::normalizeClientUuid` +
`findFinalized` decide whether a sale already finalized under this `client_uuid` (the same pre-read the held settle uses since W-G1,
`EdgeLocalHeldSalesController :296-302`); `:1088` adds `'idempotent_replay' => $replay` to the 201 body. Semantics as Online
(`SalesOrderController::saleResponse :651-660`): `false` on the request that posted the sale, `true` on a same-payload replay (a changed
payload is still the 409 conflict). The shared view then toasts "Sale … already completed - printing re-checked." (`index.blade.php:4727-4735`).
Tests: `tests/MySql/EdgeCashierPaymentHttpMySqlTest.php:163-171` (first post `false`; replay `true`, same sale_id / sale_no, one row under the uuid;
changed intent → 409 as before).

## 8 — Device version reporting proof (owner §8)

`tests/MySql/EdgeHeartbeatBuildDevicePageHttpMySqlTest.php:196-287`
`test_a_newer_build_on_the_same_paired_device_updates_the_page_without_re_pairing_or_moving_authority`, over the REAL device-authenticated
`POST /api/edge/authority/heartbeat` (central domain, `X-Edge-Device-ID` + bearer secret) and the REAL tenant page
`GET /settings/offline-edge/security`:
1. beat seq 1 with build A (`0.7.0-edge` / `aaa7000`) → 200 holder cloud; the device cell shows A (+ "02 Oct 2026, 09:00"); `edge_devices.app_version` = A;
2. beat seq 2 with build B (`0.8.0-edge` / `bbb8000`) on the SAME device → the cell shows B, A/`aaa7000` nowhere on the page, the other paired
   device still "Version not reported yet", `build_reported_at` = the beat's instant;
3. NO re-pairing: `edge_devices` row identity (`id, public_uuid, tenant_id, branch_id, installation_uuid, device_name, device_secret_hash, status,
   active_slot, paired_at`) byte-identical, device count for the tenant unchanged;
4. authority untouched by the build block: the same `edge_branch_authority_leases` row id, every column equal except `heartbeat_seq`,
   `last_heartbeat_at`, `expires_at`, `updated_at`; holder `cloud`, state `standby` before and after;
5. replay rule intact: seq 2 re-sent with B → 200 seq 2, device row AND lease row byte-identical; stale rule intact: seq 1 with `9.9.9-evil` →
   409 `STALE_HEARTBEAT`, nothing recorded, the page still shows B.
(One process plays both machines, so the test releases the page request's tenant binding before each beat exactly as
`TenancyManager::deactivate` does at the end of a real request — otherwise the central-only heartbeat route sees a bound tenant and 404s.)
`EdgeHeartbeatBuildReportMySqlTest` (write-on-change hash, old appliances, invalid blocks, the appliance's own tick end to end) unchanged and green.

## Gate results (all on the isolated `_t10` databases)

- `tests/Feature/Pos` — OK (26 tests, 456 assertions), incl. the new E test and the Node syntax gate.
- `tests/Feature/Edge` — OK (164 tests, 36,139 assertions).
- MySQL filter `EdgeCashierPayment|EdgeCashierPrinting|EdgeCashierReservation|EdgeLocalRestaurant|EdgeSharedPos|EdgeHeartbeat|EdgeCashierControlCensus|EdgeCashierMenu|EdgeHeldSettle|EdgeLocalPrintDelivery`
  — 89 tests, 2,408 assertions; every class named in the required filter GREEN (EdgeCashierPayment*, EdgeCashierPrinting*, EdgeCashierReservation, EdgeLocalRestaurant*, EdgeSharedPosContract / GapFixes / RecipeAvailability / View, EdgeHeartbeatBuildDevicePage / BuildReport, EdgeCashierControlCensus, EdgeCashierMenu, EdgeHeldSettle*, EdgeLocalPrintDelivery). The filter also caught the OTHER team's new, UNTRACKED `tests/MySql/EdgeSharedPosRegressionGateMySqlTest.php` (matched by `EdgeSharedPos`): 2 failures there — (1) `:240` expects ≥ 20 modals, the shared view has 19 at HEAD `6cac0e8` and 19 now (my diff adds 13 lines of JS, no markup); (2) `:293` ids on Cloud only = `btnFullscreen, mobile_btn, sidebar, sidebar-menu, toggle_btn`, which come from `partials/header.blade.php` / `partials/sidebar.blade.php` (the Cloud chromeView), not from the shared view (0 occurrences at HEAD and now; the view's id set is 290 in both). Neither is caused by this work; the file is that team's in-progress gate and was not edited per the brief.

## Not done / notes for the owner

- B leaves `preview-bill` (the bill facsimile / print document) strict on purpose: it renders the lines the kitchen and receipt will carry.
- D: Online's `exists:customers,id` message differs in wording from the Edge book message ("The selected customer is not in the customer
  book on this Branch Server."); the SHAPE and field name now match, the business sentence stays Edge's (it names the real cause).
- E: a `change` event could have been dispatched from `autoSelectTerminal` instead; not done because the terminal `change` listener also
  writes `localStorage` and refetches the shift status — behaviour, not text. The DOMContentLoaded re-read is text-only.
- Files touched: `app/Services/Edge/EdgeLocalPosService.php`, `app/Http/Controllers/Edge/EdgeLocalPosController.php`,
  `app/Services/Edge/EdgeLocalPrintDeliveryService.php`, `app/Http/Controllers/Edge/EdgeLocalRestaurantController.php`,
  `resources/views/tenant/pos/index.blade.php`, `tests/Fixtures/edge/online-pos-control-census.json`, and the tests named above
  (`EdgeSharedPosContractMySqlTest`, `EdgeCashierPrintingParityHttpMySqlTest`, `EdgeLocalPrintDeliveryMySqlTest`,
  `EdgeCashierReservationHttpMySqlTest`, `EdgeCashierPaymentHttpMySqlTest`, `SharedPosViewRenderTest`, `EdgeHeartbeatBuildDevicePageHttpMySqlTest`).
  Other teams' concurrent working-tree changes (EdgeLocalAuthService, bootstrap, approvals, `config/edge.php`, the new edge migration) are theirs.
