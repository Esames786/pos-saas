# W2 — Team 2 report: the real menu and sale workflows (Offline Edge cashier parity)

Worktree `D:\laragon2\www\pos-saas-edge`, branch `feat/edge-config-refresh-v1`, base HEAD d82612f. No git operation, no Cloud call,
no envelope/ingestion/outbox/authority edit, no LAB/production touch, non-cash tenders NOT enabled. Test DBs `pos_test_*_edgewt_t2`.

## Files changed (all Team 2-owned)

| File | Change |
|---|---|
| `app/Services/Edge/EdgeLocalPosService.php` | dine_in in Direct Pay types with the Online table rule; tip input + contract gate (`TIPS_SYNC_CONTRACT_READY=false`); Direct Pay print intents persisted (`DirectPayPrintOrchestrator::initialState`); direct-sale `notes`; line discounts accepted (Online min:0) + envelope-boundary guard (Direct Pay + settle); server-side variant validation (`resolveLineVariant`); modifier resolution from the synced book (`resolveModifiers`, min/max, deltas folded into unit_price); carried-line option/note keeping on Add Round; `variant_name` / `unit_code` / `kitchen_note` line snapshots; held `notes` persisted (new + revision); own-delivery rider required / aggregator rider dropped (Online); previewBill: tip quoted, promo feedback block, JSON-safe `lines` view |
| `app/Http/Controllers/Edge/EdgeLocalPosController.php` | `screen()` Online tile payload (`menuPayload()`): sku, image (only if the file is on the appliance), unit/measurable flags, tax, sale-resolved price (SalePricingService), product + variant barcodes, variants (price/stock/barcodes), modifier groups, operational stock, stock_kind; deal components; child categories (active, branch-scoped); `pillCategoryIds` / `contentCategoryIds` / `hasUncategorizedCombos`; `allowNegativeStock`; `tenderMethods` (all active, cash first, with the truthful hint); `tipsSyncable`. Validators: order_type `in:` (Online), `tip_amount`, `kot_print_intent`/`receipt_print_intent` `in:print,skip`, `notes`, `promo_code max:50`, `lines.*.{product_variant_id, modifiers, discount_amount min:0, kitchen_note max:500}`, `payments.*.transaction_ref max:190`. Response `print_intents`. |
| `resources/views/edge/pos/partials/grid.blade.php` | Online menu pane: `#vehicle-wrap/#vehicle_number`, `#qs-waiter-wrap/#qs-waiter-select`, `#delivery-panel` (`#delivery_channel_id`, `#delivery-rider-wrap/#delivery_rider_id`, hidden `#delivery_address`, `#delivery_charge_amount`), `#pos_search`, `#parent-category-strip`, `#child-category-wrap/#child-category-strip`, `#product-grid`; hidden `#search` kept only for js/boot compatibility; W2 styles |
| `resources/views/edge/pos/partials/cart.blade.php` | cart rows container, main-pane totals rows (`#t-subtotal`, `#t-discount`, `#t-tax`, `#t-service`, `#t-tip`, `#t-delivery`, `#t-grand`, `#t-quote-note`); free-text customer box removed (`#customer-name` now a hidden carrier, like Online's hidden `#customer_name`); `#customer-chip` opens the customer modal |
| `resources/views/edge/pos/js/catalog.blade.php` | pills + child strip (Online rules, "Deals" dedupe), Online tiles, deal availability, search by name/SKU/barcode, scan exact barcode (input) / barcode+SKU (Enter), inline quick-sale + delivery panel sync (`onOrderTypeChanged`), `requireQuickSaleFields` |
| `resources/views/edge/pos/js/cart.blade.php` | Online addToCart flow (stock block / backorder toast → variant picker → qty entry → options), `openQtyEntry`, `openModifierEntry`, `openVariantPicker`, cart rows (variant · price / unit, options, stored note shown, −/input/+, Options edit, Remove or Cancel-kitchen-item → `voidSentLine` hook), live server-quoted totals (debounced POST /preview-bill), `cartLines` with variant/options, `commercial` with tip, Preview Bill with lines + `printBillPreview` entry points |
| `resources/views/edge/pos/js/commercial.blade.php` | promo row (Apply / ✕ / feedback), manual discount panel (approval AT APPLY, Remove, feedback, short-tender Discount balance), tip buttons + contract hint, customer modal (`openCustomerModal`), approval prompts |
| `resources/views/edge/pos/js/payment.blade.php` | Online paymentModal: pre-checks, `#payment_method_id` (non-cash listed disabled with hint), `#tendered_amount` + `#quick-cash-buttons`, `#transaction_ref`, `printPrefsHtml()`/`readPrintPrefs()` hooks, Online totals column (`#subtotal-view` … `#change-view`), live change + short tender, `#complete-sale-btn`, `#payment-bill-preview-btn`, receipt only when receipt intent ≠ skip, tile stock follows a completed sale |
| `tests/MySql/EdgeCashierMenuHttpMySqlTest.php` | NEW — 7 tests, production-shaped menu |
| `tests/MySql/EdgeCashierPaymentHttpMySqlTest.php` | NEW — 4 tests |

No new route (everything rides existing `/edge/local/pos/*` endpoints: `/preview-bill`, `/sales`, `/held-sales`, `/customers`), so the
W2 blocks in `routes/edge_runtime.php` and `EdgeBranchServerRegistrationTest` stay empty.

## Records

### A3 — child-category strip
- ONLINE_BEHAVIOUR: O:698-722, JS O:6433-6495; POSController:388-417 (pillCategoryIds, contentCategoryIds, hasUncategorizedCombos).
- EDGE_IMPLEMENTATION: controller `screen()` (children active + branch-scoped, pill/content ids); js/catalog `renderTabs/renderChildStrip/visibleItems` (a parent matches its own AND its children's items; child strip only for content children; empty categories no pill).
- PERMISSION_AND_VALIDATION: display only (CATEGORY-BRANCH-SCOPE-1 respected).
- EXECUTABLE_TEST: `EdgeCashierMenuHttpMySqlTest::test_the_page_ships_the_online_tile_payload_and_the_online_pill_rules`.
- BROWSER_ACCEPTANCE_STEP: click "Grills" in `#parent-category-strip` → `#child-category-wrap` shows "All / Chicken / Beef"; Chicken Tikka visible under Grills; click "Chicken".
- CENSUS_FLIPS: `parent-category-strip` → present `#parent-category-strip` (was equivalent `#category-tabs`, id removed); `child-category-wrap` planned → present `#child-category-wrap`; `child-category-strip` planned → present `#child-category-strip`.
- REMAINING_DIFFERENCE: dark pill look (Team 1 theme).
- STATUS: MATCHED (functional) — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A4 — product tiles
- ONLINE_BEHAVIOUR: renderProducts O:2711-2835; CSS O:165-260; POSController:170-346.
- EDGE_IMPLEMENTATION: `menuPayload()`; js/catalog `renderTiles` — avatar (image only when the file exists on the appliance, else initials), name, SKU, price (the SalePricingService price the sale charges), badge Stock N / Low (≤5) / Out / Backorder (branch allow_negative_stock) / Service / Recipe, Tax %, "N options", "Customizable"; add blocked on Out (toast) or backorder toast. Stock = Edge operational balance of the accepted baseline (EdgeOperationalStockService's truth); the page decrements its copy after a sale and subtracts what the cart holds.
- EXECUTABLE_TEST: `…Menu…::test_the_page_ships_the_online_tile_payload_and_the_online_pill_rules`.
- BROWSER_ACCEPTANCE_STEP: `#product-grid` tiles show SKU + "Stock 50"; set a product's balance to 0 → "Out" and click → toast.
- CENSUS_FLIPS: `product-grid` partial → present `#product-grid` (old `#tiles` removed). Team 1's js/boot anchors `#products_heading` before `#tiles` → must anchor `#product-grid` (request below).
- REMAINING_DIFFERENCE: recipe products show "Recipe" not Online's "Makes N" (Online recipeAvailability not ported — would need the recipe ingredient stock on the page); product images are not synced to the appliance (bootstrap ships `image_path` only) so real menus show initials; per-product price resolution is N+1 queries (shared rule reused on purpose).
- STATUS: PARTIALLY_IMPLEMENTED (recipe makeable).

### A5 — search + barcode / SKU
- ONLINE_BEHAVIOUR: O:691-696, handlePosBarcodeScan O:6304-6357.
- EDGE_IMPLEMENTATION: `#pos_search` filters name / SKU / barcode (product + variant); exact barcode on input or Enter adds (variant barcode → that exact variant; Online's quirk of taking `variants[0]` for a variant barcode is NOT copied); exact product/variant SKU on Enter adds; box clears + refocuses; no match on Enter → warning toast.
- SYNC CHECK: `product_barcodes` and `product_variants` (incl. `barcode` column) ARE exported by `EdgeBootstrapService::buildSections` (:708-711) and imported by `EdgeLocalBootstrapImporter::PLAN` (:58-59), refreshed by `EdgeLocalConfigRefreshApplier` (:70-71) — so barcodes reach the appliance.
- EXECUTABLE_TEST: tile payload assertions (barcodes per product / variant) in the page test; the scan itself is client-side (browser step).
- BROWSER_ACCEPTANCE_STEP: type `8901002` in `#pos_search` → "Chicken Karahi — Full" added at 1200; type `SKU-DRINK` + Enter → Cold Drink added.
- CENSUS_FLIPS: `pos_search` equivalent `#search` → present `#pos_search` (a hidden `#search` remains for js/boot; Team 1 should wire `#pos_search`).
- REMAINING_DIFFERENCE: none functional; Ctrl+F already targets `#pos_search` in Team 1's latest boot.
- STATUS: MATCHED — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A6 — qtyEntryModal
- ONLINE_BEHAVIOUR: O:2028-2062, O:2836-2879, isMeasurableProduct O:2528-2545.
- EDGE_IMPLEMENTATION: `openQtyEntry(item[, variant, modifiers])` → `#qtyEntryModal` (`#qtyEntryModalLabel`, `#qty-modal-product-name`, `#qty-modal-price-hint`, `#qty-modal-input` step .001, `#qty-modal-unit`, `#qty-modal-amount-input` amount ÷ price, `#qty-modal-confirm`, Enter confirms); cart qty input/± step 0.001 for measurable lines.
- PERMISSION_AND_VALIDATION: server `lines.*.quantity numeric gt:0` (decimal accepted), price/tax server-resolved.
- EXECUTABLE_TEST: `…Menu…::test_a_weighted_item_sells_a_decimal_quantity_and_a_kitchen_note_persists` (1.25 kg → 3000, stock 25 → 23.75, `unit_code` kg).
- BROWSER_ACCEPTANCE_STEP: click "Mutton Karahi (per kg)" → `#qtyEntryModal`; enter 1200 in `#qty-modal-amount-input` → qty 0.500 → Add.
- CENSUS_FLIPS: group `qty-entry` planned → present (all 8 ids render in the page script).
- STATUS: MATCHED — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A7 — modifierEntryModal
- ONLINE_BEHAVIOUR: O:2065-2088, O:2881-2981 (groups, radio when max=1, defaults, min/max, deltas folded into the unit price, edit on the cart line); SOC normalizeLineModifiers :970-993.
- EDGE_IMPLEMENTATION: `openModifierEntry(item[, variant, qty, preselectedIds, editKey])` → `#modifierEntryModal` (`#modifierEntryModalLabel`, `#modifier-modal-product-name`, `#modifier-modal-price-hint`, `#modifier-modal-groups`, `#modifier-modal-confirm`); cart "Options" button (unsent, non-deal lines) re-opens it preselected and re-keys the line. SERVER (stricter than Online, which trusts the client): `resolveModifiers()` — every option must be active, in an active group attached to the product (`product_modifier_group`), group branch NULL or the bound branch; min/max per group enforced; names + deltas from the book; unit_price = SalePricingService price + Σ deltas; tax on that price; stored in Online's normalized shape. Carried lines on Add Round keep their stored options (omitted = kept; different options = refused "submit as a new line"). Linked-option stock is consumed locally by the existing `EdgeOperationalStockService::consumeModifiers`. KOT/receipt: the shared documents print `line.modifiers` (kot.blade.php:201, receipt.blade.php:216) — now populated.
- EXECUTABLE_TEST: `…Menu…::test_options_are_priced_and_named_from_the_synced_book_with_min_max_enforced_and_linked_stock_consumed` (340 unit price, book names, naan 50→48, envelope carries options, min / max / foreign / inactive refusals, zero writes on refusal); `…::test_held_check_keeps_its_note_and_its_carried_options`.
- BROWSER_ACCEPTANCE_STEP: click Chicken Tikka → `#modifierEntryModal` with "Spice Level" radios (Mild preselected) and "Extras" checkboxes; pick Extra Cheese → line "Chicken Tikka · 300.00", "+ Extra Cheese (+50.00)"; click "Options" on the line.
- CENSUS_FLIPS: group `modifier-entry` planned → present (6 ids).
- CONTRACT: envelope v1 carries `lines[].modifiers` and the delta-inclusive `unit_price` (Team 6: additive, OK). Linked-product stock at Cloud ingestion → CONTRACT_REQUIREMENTS §1.
- REMAINING_DIFFERENCE: on a REAL appliance no option appears today — the bootstrap does not export `product_modifier_group`, and exports only branch-bound modifier groups (global `branch_id NULL` groups, the common case and the dev seed shape, are skipped: `EdgeBootstrapService::buildSections` :624/:715). Works on the dev instance / tests where the rows exist locally. → Team 6 bootstrap v7.
- STATUS: MATCHED on the till + server; BLOCKED for real appliances by the bootstrap export (Team 6).

### A8 — variants
- ONLINE_BEHAVIOUR: tile uses the first variant O:2787; barcode picks the variant O:6321-6329; cart shows variant name O:3339; SOC `exists:product_variants`.
- EDGE_IMPLEMENTATION: tile priced by the default variant; a product with >1 variant opens `openVariantPicker(item)` (`#variantPickerModal`, each variant's price + stock badge); one variant → used directly; variant barcode → that variant. Server `resolveLineVariant()` — variant must belong to the product (business 422, no model-not-found text) and be active for a new line; none → shared `resolveVariant` default; `variant_name` + `unit_code` stored for KOT/receipt.
- EXECUTABLE_TEST: `…Menu…::test_a_variant_sells_at_its_own_price_and_stock_and_a_foreign_variant_is_refused`.
- BROWSER_ACCEPTANCE_STEP: click Chicken Karahi → picker Half 650 / Full 1200 → Full → line "Full · 1200.00".
- REMAINING_DIFFERENCE: the picker is an Edge addition (Online sells `variants[0]` on a tile click and needs the barcode for others) — requested by the coordinator's interface contract; flagged for the owner. The envelope carries `product_variant_id` but not `variant_name` (Cloud line has no variant snapshot) → CONTRACT_REQUIREMENTS §5.
- STATUS: MATCHED (superset) — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A9 — deals presentation (+ duplicate "Deals" pill)
- ONLINE_BEHAVIOUR: deal tile O:2757-2784, comboAvailability, addComboToCart availability checks; flat Deals pill only while uncategorised deals exist.
- EDGE_IMPLEMENTATION: tile with code, price, "Combo" / Unavailable / Backorder badge, "N items · makes M" / limiting component; add blocked when a component is out (unless the branch allows negative). A category literally named "Deals" is THE deals entry: the flat pill is not added beside it and uncategorised deals show under it too (fixes the duplicate pill).
- EXECUTABLE_TEST: page test (deal code + components, no flat pill flag); existing `EdgeCashierDealsDiscountsHttpMySqlTest` (sale path unchanged).
- CENSUS_FLIPS: none (no Online id).
- STATUS: MATCHED.

### A10 — cart line editing, line discounts, kitchen notes
- ONLINE_BEHAVIOUR: renderCart O:3299-3457; SOC `lines.*.discount_amount min:0` :861.
- EDGE_IMPLEMENTATION: direct qty input (`data-qty-input`, step by unit), −/+, Remove (✕) or "Cancel" for a sent line; reducing below the kitchen-sent quantity calls `voidSentLine(line, nextQty)` when Team 3 defines it, else the previous toast; Options edit; the line shows variant · price / unit, options, a stored note, "kitchen has N". Line discounts: accepted server-side (min:0), SalesTotalsService folds them into `manual_discount_amount` (same branch approval gate as Online); a line-ONLY discount on a paid sale is refused up front with a business message because the envelope refuses it (CONTRACT §3). No line-discount UI — the Online POS has none either (only the server field).
- KITCHEN NOTES (re-checked per Team 6): the Online POS view and SalesOrderController do NOT capture a per-line note (no input, `kitchen_note` not validated/written; only KDS/KOT/receipt/reminder documents RENDER it). So NO till capture was built (the Note button drafted earlier was removed). The server accepts `lines.*.kitchen_note` (max 500) and persists it to `sales_order_lines.kitchen_note` as a dormant pass-through (tested) — an owner decision whether the till should capture it; the envelope does not carry it (CONTRACT §4).
- EXECUTABLE_TEST: `…Menu…::test_line_discounts_ride_the_shared_totals_and_stop_at_the_contract_boundary`; `…::test_a_weighted_item_sells_a_decimal_quantity_and_a_kitchen_note_persists`.
- BROWSER_ACCEPTANCE_STEP: type 3 in a line's qty box; ✕ removes; on a recalled check with KOT sent, "Cancel" → Team 3 void flow.
- CENSUS_FLIPS: `cart-items` partial (`#cart-lines`) → Team 1/coordinator may move to equivalent (direct qty + options edit landed; void flow = Team 3).
- DEFERRAL: "Line discounts are not yet available" string REMOVED from EdgeLocalPosService — the census deferral row (A10) no longer matches anything.
- STATUS: MATCHED (cart editing); line discounts PARTIALLY_IMPLEMENTED (contract boundary); kitchen notes NOT_IN_ONLINE (owner).

### A12 — customer chip + customerModal
- ONLINE_BEHAVIOUR: O:1966-2026, JS O:6697-7058.
- EDGE_IMPLEMENTATION: `openCustomerModal()` → `#customerModal` (`#customerModalLabel`, `#cust-search-input`, `#cust-search-results`, `#cust-selected-panel`, `#sel-cust-name`, `#sel-cust-phone`, `#cust-attach-btn`, `#cust-address-list` radios, default preselected); non-delivery click attaches; Enter attaches a single match; delivery → address chooser → Attach to Order (address onto the order + chip); "Walk-in (remove customer)"; the cart `#customer-chip` opens it (Team 1 wires `#pos-customer-btn` and renders `#pos-customer-chip`). Free-text customer name box removed (no Online counterpart).
- ONLINE_REQUIRED shown truthfully: no exact match → "Adding a new customer needs the Online POS."; new address → "Saving another address for this customer needs the Online POS" (A13, accepted).
- EXECUTABLE_TEST: `EdgeCashierPaymentHttpMySqlTest::test_customer_lookup_answers_from_the_synced_book_with_saved_addresses` (first HTTP proof of GET /customers).
- CENSUS_FLIPS: `cust-search-input` equivalent `#cm-cust-q` → present; `cust-search-results` → present; `cust-selected-panel`, `sel-cust-name`, `sel-cust-phone` → present; `cust-attach-btn` equivalent `js:data-cid` → present `#cust-attach-btn`; `cust-address-list` partial `#cm-addr-pick` → present; `customerModal` → present; `customerModalLabel` → present; group `customer-modal` edge `#cm-cust-q` must change to `#cust-search-input`. `customer_name` (#customer-name) stays equivalent (hidden). `chip-cust-clear` (was `#cm-cust-clear`, gone) → Team 1's `#chip-cust-clear`.
- STATUS: MATCHED (search/attach/addresses); quick-create ACCEPTED_ONLINE_REQUIRED.

### A14 — delivery panel on the main pane
- ONLINE_BEHAVIOUR: O:661-690, updateDeliveryPanel O:2329-2392, requireDeliveryCustomer O:4461-4493; SOC/HSC validateDeliveryAttribution.
- EDGE_IMPLEMENTATION: `#delivery-panel` under the menu for delivery orders: channel (aggregator labelled), rider shown only for own channels, charge (disabled when branch-locked), address via the customer modal (hidden `#delivery_address`); cleared when the type changes; a recalled delivery check shows its values read-only. Pre-check before Review & Pay: channel, rider for own, customer for own (opens the modal). SERVER now mirrors Online: own delivery REQUIRES a rider ("Select a rider for own-delivery orders."), an aggregator's rider is discarded.
- EXECUTABLE_TEST: `…Menu…::test_order_type_rules_follow_online_dine_in_direct_and_delivery_rider`; `EdgeCashierDealsDiscountsHttpMySqlTest` delivery test (regression).
- CENSUS_FLIPS: group `delivery` (W1): `delivery-panel` partial `#cm-channel` → present `#delivery-panel`; `delivery_channel_id` → present; `delivery-rider-wrap` → present; `delivery_rider_id` → present; `delivery_address` → present (hidden, as Online); `delivery_charge_amount` → present. Old `#cm-channel/#cm-rider/#cm-address/#cm-charge` removed.
- REMAINING_DIFFERENCE: editing delivery fields on a recalled check is not offered (EdgeLocalPosService::reviseHeldSale keeps the stored attribution).
- STATUS: MATCHED — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A15 / R2.2 — manual discount panel + short-tender "Discount balance"
- ONLINE_BEHAVIOUR: O:896-929, O:3883-3982, showManagerPinModal O:4037-4084.
- EDGE_IMPLEMENTATION: `#manual-discount-panel` (Fixed amount / Percentage, `#manual-discount-prefix/#manual-discount-suffix`, `#manual-discount-value`, `#apply-discount-btn`, `#remove-discount-btn`, `#manual-discount-feedback`); approval is asked AT APPLY (manager's own Edge credential, payload bound to the proposed amount on the server-quoted subtotal, sales_order_id / client_uuid as the server consumes them) unless the branch auto-approves; cancelled approval = nothing applied; `#short-tender-row` / `#short-tender-message` / `#discount-shortfall-btn` when tendered < total. The old "approve after refusal" retry is kept as a fallback.
- PERMISSION_AND_VALIDATION: unchanged server gate (branch approval mode, single-use payload-bound consume).
- EXECUTABLE_TEST: existing `EdgeCashierDealsDiscountsHttpMySqlTest` discount tests (server contract); UI flow = browser step.
- BROWSER_ACCEPTANCE_STEP: Review & Pay → Fixed 50 → Apply Discount → manager DEVMGR1 / MgrPass1 → feedback "Discount applied: 50.00"; tender less → "Short by …" → Discount balance.
- CENSUS_FLIPS: `manual-discount-panel`, `manual-discount-type`, `manual-discount-prefix`, `manual-discount-value`, `manual-discount-suffix`, `apply-discount-btn` equivalent → present; `remove-discount-btn` partial → present; `manual-discount-feedback`, `short-tender-row`, `short-tender-message`, `discount-shortfall-btn` planned → present.
- REMAINING_DIFFERENCE: Edge Hold keeps the discount (Online strips it on Hold) — Team 3's holdSale sends commercial(); flagged.
- STATUS: MATCHED — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A16 — promo
- ONLINE_BEHAVIOUR: O:887-895, O:3800-3850; PromotionController@quote messages.
- EDGE_IMPLEMENTATION: `#promo-row` (`#promo-code-input`, `#apply-promo-btn`, `#remove-promo-btn`), `#promo-feedback`; validation through POST /preview-bill whose new `promo` block answers like promotions/quote (valid + promotion_name + discount, or "Promo code is invalid, expired, or does not apply to this order."); promo row in totals; `promo_code max:50` (Online).
- EXECUTABLE_TEST: `…Payment…::test_preview_answers_the_promo_like_online_and_quotes_a_tip_that_a_paid_sale_cannot_carry_yet`.
- CENSUS_FLIPS: `promo-row`, `promo-code-input`, `apply-promo-btn` equivalent → present; `remove-promo-btn` partial → present; `promo-feedback` planned → present; `promo-discount-row/label/view` → present.
- STATUS: MATCHED.

### A17 — tips (contract boundary)
- ONLINE_BEHAVIOUR: O:930-939, O:3854-3872; SOC `tip_amount min:0`.
- EDGE_IMPLEMENTATION: tip buttons No Tip / 5% / 10% / Custom (`#tip-buttons .tip-btn`), `#tip-row/#tip-view`, main-pane `#t-tip`; tip quoted by POST /preview-bill on the shared SalesTotalsService; `tip_amount` validated + hashed; the persistence path is wired but gated by `EdgeLocalPosService::TIPS_SYNC_CONTRACT_READY = false` — a paid sale with a tip is refused BEFORE any write ("A tip cannot be recorded on a Branch Server sale until the Cloud sync contract carries tips — remove the tip to complete this sale."), and Complete Sale is disabled with `#tip-hint` while a tip is selected. Held settle carries no tip (settle endpoint is Team 3's; Online holds carry tip 0).
- EXECUTABLE_TEST: `…Payment…::test_preview_answers_the_promo_like_online_and_quotes_a_tip_that_a_paid_sale_cannot_carry_yet` (quote 525, refusal, zero sale/outbox).
- CENSUS_FLIPS: `tip-row`, `tip-view` planned → present; `pos-tip-amount` planned → equivalent `js:tip_amount`.
- STATUS: PARTIALLY_IMPLEMENTED — BLOCKED at the contract (CONTRACT §2).

### A18 — totals rows incl. tip / service charge
- ONLINE_BEHAVIOUR: O:763-771, O:969-988, updateTotals/refreshServerTotals O:3526-3656.
- EDGE_IMPLEMENTATION: the cart pane shows Items, Subtotal, Discount (promo code), Tax, Service charge, Tip, Delivery charge, Total from a debounced server quote (POST /preview-bill — same service as the sale, zero mutation), client estimate + note when no terminal / quote refused; payment modal column with the Online ids.
- EXECUTABLE_TEST: preview totals assertions in both new classes; `EdgePreviewBillMySqlTest` (not in my filter) unchanged contract.
- CENSUS_FLIPS: `subtotal-view`, `discount-view`, `tax-view`, `service-charge-row/view`, `delivery-charge-row/view`, `grand-total-view` equivalent (text:) → present ids; `pos-charge-total` stays equivalent `#t-grand`.
- STATUS: MATCHED — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A19 / A20 — Review & Pay entry + paymentModal
- ONLINE_BEHAVIOUR: O:857-1013; updateQuickCash O:3496-3524; updateShortTenderState O:3889-3900; submitPaidSale O:4593-4732; buildInputs O:3745-3755 (print intents).
- EDGE_IMPLEMENTATION: pre-checks before the modal (empty cart, terminal, delivery customer/rider, quick-sale vehicle + waiter); `#paymentModal/#paymentModalLabel/#payment_heading`; `#payment_method_id` lists `tenderMethods` (cash selected; card "Card / provider payments run on the Online POS (accepted Cloud-only)." disabled; bank transfer / cheque / other "Awaiting owner decision — …" disabled); `#tendered_amount` + `#quick-cash-buttons` (Online rounding set), `#transaction_ref` (stored on the payment + envelope), live `#change-view`, short tender; `printPrefsHtml()` included and `readPrintPrefs()` merged (default kot skip / receipt print = current Edge behaviour) → `kot_print_intent` / `receipt_print_intent` validated `in:print,skip` and persisted as `DirectPayPrintOrchestrator::initialState` + hashed (Online); response `print_intents`; receipt auto-queued only when the receipt intent is not skip; optional `afterDirectPaySale(sale, prefs)` hook for Team 5's Direct-Pay KOT. `#complete-sale-btn` ("Close & Pay Table Bill" on a table), `#payment-bill-preview-btn`.
- PERMISSION_AND_VALIDATION: Complete Sale gate unchanged (tenant.pos.store, hint for operators without it); non-cash still refused server-side (`assertPaymentsOffline`).
- EXECUTABLE_TEST: `…Payment…::test_the_payment_method_list_follows_online_and_only_cash_is_taken`, `…::test_direct_pay_print_intents_are_validated_and_persisted_like_online` (replay + 409 conflict on a changed intent).
- CENSUS_FLIPS: `payment_method_id` partial `#rp-tendered` → present `#payment_method_id` (non-cash rows owner-dependent); `tendered_amount` equivalent `#rp-tendered` → present; `quick-cash-buttons` planned → present; `transaction_ref` planned → present (cash reference; non-cash still owner); `change-view` partial → present; `complete-sale-btn` present `#rp-complete` → present `#complete-sale-btn` (old id removed — js/boot's Ctrl+P / Ctrl+Enter still reference `#rp-tendered` / `#rp-complete` → Team 1 request); `payment-bill-preview-btn` equivalent → present; `paymentModal`, `paymentModalLabel`, `payment_heading` → present.
- STATUS: MATCHED for cash — FUNCTIONAL_BUT_NOT_BROWSER_PROVEN.

### A21 — non-cash methods
- Presented exactly as Online lists them, disabled with the truthful hint; NOT enabled (owner decision / accepted exclusion). STATUS: card ACCEPTED_ONLINE_REQUIRED; bank/cheque/other OWNER_DECISION (isolated). Note: the bootstrap exports only `cash` payment methods (`EdgeBootstrapService::PHASE1_PAYMENT_TYPES`), so a real appliance lists Cash only until Team 6 exports the others as display rows.

### A39 — quick-sale vehicle / waiter
- ONLINE_BEHAVIOUR: O:585-603, requireQuickSaleFields O:4495-4511.
- EDGE_IMPLEMENTATION: inline `#vehicle-wrap/#vehicle_number`, `#qs-waiter-wrap/#qs-waiter-select` with "Select waiter…" placeholder (no silent first-waiter attribution), shown for quick sale only and cleared on other types; Review & Pay requires both; `quickSaleAttribution()` exposed for Team 3's Hold (their modal still preselects the first waiter — request).
- EXECUTABLE_TEST: existing `EdgeLocalPosHttpMySqlTest` quick-sale sale (server rule unchanged); UI = browser step.
- CENSUS_FLIPS: group `quick-sale` (W1): `vehicle-wrap`, `vehicle_number`, `qs-waiter-wrap`, `qs-waiter-select` equivalent `#qs-vehicle/#qs-waiter` → present (Online ids).
- STATUS: MATCHED on Direct Pay; Hold path pending Team 3.

### Browser-found defect — "Order type [dine_in] is not yet available" on direct pay
- ONLINE: SalesOrderController::store → "Select an open table before completing a dine-in order." when no session.
- EDGE: dine_in added to OFFLINE_ORDER_TYPES; direct dine-in answers the Online message (restaurant_table_session_id); order_type validated `in:` the four types. TEST: `…Menu…::test_order_type_rules_follow_online_dine_in_direct_and_delivery_rider`; `EdgeLocalPosHttpMySqlTest` dine_in 422 still green. STATUS: MATCHED. (The "Order type [" deferral text remains only for the held path's defence-in-depth branch.)

### Held-sale `notes`
- Accepted and now persisted on a new held check and replaced on a revision that submits `notes` (kept when omitted); direct sale `notes` persisted too. TEST: `…Menu…::test_held_check_keeps_its_note_and_its_carried_options`. Not in the envelope (CONTRACT §4). STATUS: MATCHED locally.

### A13 / A21 — not built (by rule): quick-create customer / address (ACCEPTED_ONLINE_REQUIRED, hint shown); non-cash tenders (owner).

## CONTRACT_REQUIREMENTS for Team 6 (I stopped at the boundary; nothing in the envelope/ingestion was edited)

1. **Modifier linked-product stock at Cloud ingestion (envelope v2).** Local: options are stored per line (`modifier_group_id, modifier_group_name, modifier_id, name, price_delta`), unit_price already includes the deltas, the envelope carries `lines[].modifiers`, and the appliance consumes linked stock (EdgeOperationalStockService::consumeModifiers). Missing: `EdgeInboundSaleIngestionService::projectLinesWithOfficialStock` does not run Cloud `SalesService::consumeLineModifiers` (consume_stock options → linked product FEFO + cost). Needed: ingestion deducts each `consume_stock` option's linked product (`linked_quantity × line qty`, unit-converted) exactly once per line_uuid, or the envelope carries the resolved linked consumption.
2. **Tips.** Local: `tip_amount` validated (min:0), quoted by the shared totals, hashed, persistence wired; refused on a paid sale because `EdgeSaleEnvelopeBuilder::assertSupported` (~:245) throws "tips are not supported offline". Needed: remove that guard (envelope already emits `totals.tip_amount`; ingestion already projects it), then flip `EdgeLocalPosService::TIPS_SYNC_CONTRACT_READY` to true in the same change; add a held-settle `tip_amount` if Team 3 wants tips on table bills.
3. **Line-only discounts.** Local: `lines.*.discount_amount` accepted, folded into manual_discount by SalesTotalsService (approval gate), stored per line, carried as `lines[].discount_amount`; refused on a paid sale only when there is NO order discount type and no promotion, because `assertSupported` (~:242) refuses discount money without a type/promotion. Needed: accept `totals.discount_amount` explained by Σ `lines[].discount_amount` (+ order/promo), then drop `assertEnvelopeCanCarryLineDiscounts` in EdgeLocalPosService.
4. **Kitchen note and order notes.** Local: `sales_order_lines.kitchen_note` (server pass-through) and `sales_orders.notes` (held + direct) persisted. Needed (if the owner wants them in the Cloud record): envelope `lines[].kitchen_note` and top-level `notes`, projected by ingestion. (Online captures no kitchen note at the till — owner decision first.)
5. **Variant / unit snapshots.** Local lines now store `variant_name`, `unit_code`. Envelope carries `product_variant_id` only; Cloud projected lines have `variant_name`/`unit_code` NULL (reports/receipts reprinted from Cloud lose "Full"/"kg"). Additive v1 fields `lines[].variant_name`, `lines[].unit_code` recommended.
6. **Direct Pay print state.** `direct_pay_print_state` is local only (printing is local) — no contract change needed; noted for completeness.
7. **Bootstrap v7 (menu sync).** (a) export `product_modifier_group` for the sellable set; (b) export global modifier groups (`branch_id IS NULL`) as well as the branch's; (c) optionally export non-cash `payment_methods` as display-only rows (the till lists them disabled); (d) product images are not synced (only `image_path`) — tiles fall back to initials.

## Requests to other teams

- **Team 1 (shell/boot):** wire the live search `#pos_search` (boot still `$('search').addEventListener`; a hidden `#search` is kept so boot does not throw); anchor `#products_heading` before `#product-grid` (not `#tiles`, removed); Ctrl+P / Ctrl+Enter target `#tendered_amount` / `#complete-sale-btn` (old `#rp-tendered` / `#rp-complete` removed); do NOT add `#delivery-panel` / `#vehicle-wrap` / `#qs-waiter-*` (they live in partials/grid); on a mode switch call `onOrderTypeChanged()` if you bypass `#order-type`'s change event (js/catalog also listens to `[data-mode-tab]` clicks); `resetOrderForModeSwitch` should also reset `tip_amount`/`tip_mode` (defaults tolerated).
- **Team 3 (held/tables):** (a) `EdgeLocalHeldSalesController::storeHeldSale` validation must add `lines.*.kitchen_note` (nullable string max:500) and `lines.*.discount_amount` (nullable numeric min:0) — otherwise they are stripped on Hold; (b) `heldSaleView` lines should include `modifiers`, `variant_name`, `unit_code`, `kitchen_note` (js/cart hydrates carried lines from `state.held.lines` when present), and `notes` on the sale; (c) `loadHeld` may copy `product_variant_id/modifiers` directly (renderCart backfills otherwise); (d) implement `voidSentLine(line, nextQty)` (called from js/cart); (e) Hold of a quick sale should use `quickSaleAttribution()` (inline fields) instead of a modal preselecting the first waiter; (f) settle may accept `payments.*.transaction_ref` and the print intents.
- **Team 5 (printing):** implement `printPrefsHtml()`, `readPrintPrefs()` → `{kot_print_intent, receipt_print_intent}` (validated/persisted by POST /sales; response `print_intents`), `printBillPreview(payload)` (payload = `{target:'here'|'network', held_sale_id, totals, lines, …preview payload}`) and optionally `afterDirectPaySale(sale, prefs)` for the Direct-Pay KOT; tell me the service call to hand the intents to (today: persisted as the shared DirectPayPrintOrchestrator initial state).
- **Coordinator:** apply the CENSUS_FLIPS above; the deferral rows "Line discounts are not yet available" (A10) and "later milestone" (A7 — the controller comment was rewritten) no longer match any source line; "Order type [" still matches (held path).

## Tests

```
export PATH="/d/laragon2/bin/php/php-8.3.16-Win32-vs16-x64:$PATH"
export DB_DATABASE=pos_test_master_edgewt_t2 EDGE_TEST_TENANT_DB=pos_test_tenant_edgewt_t2 EDGE_TEST_LOCAL_DB=pos_test_edge_local_edgewt_t2
vendor/bin/phpunit -c phpunit.mysql.xml --filter 'EdgeCashierMenu|EdgeCashierPayment|EdgeCashierDealsDiscountsHttpMySqlTest|EdgeCashierPermissionHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeLocalPosHttpMySqlTest|EdgeLocalPosMySqlTest|EdgeCashierScreenRendersHttpMySqlTest'
EDGE_NODE_BIN=D:/laragon2/bin/nodejs/node-v20.20.1-win-x64/node.exe vendor/bin/phpunit -c phpunit.mysql.xml --filter EdgeCashierControlCensusHttpMySqlTest
vendor/bin/phpunit tests/Feature/Edge/EdgeBranchServerRegistrationTest.php tests/Feature/Edge/EdgeArtifactTest.php
```

## Requests RECEIVED from other teams (handled in this pass)

| From | Request | Outcome |
|---|---|---|
| Team 3 (1) | persist held-sale `notes` | DONE (new + revision; kept when omitted) |
| Team 3 (2) | guest_count required on openTableSession like Online | DONE in `EdgeLocalPosService::openTableSession` ("Enter the number of guests."); every existing caller already sends it. Test: `EdgeCashierMenuHttpMySqlTest::test_team3_requests_change_order_details_moves_a_held_check_and_guest_count_is_required` |
| Team 3 (3) | R21 Change Order Details — move a held check to another table session / change order type on revise | DONE behind an explicit `change_order_details: true` flag on the held-sale revision: locks shift → BOTH sessions (current + target, ascending id) → sale; refuses a target session that already has an open check (Edge one-check-per-session rule); re-resolves delivery attribution / quick-sale waiter + vehicle / session waiter for the new type; same sale row (durable sale_uuid), shift + business_date frozen. Without the flag the old lock-order guard still refuses a session mismatch. **Team 3 must add `change_order_details` (boolean) to `EdgeLocalHeldSalesController::storeHeldSale` validation**, else it is stripped. Same test as above (takeaway → dine-in on G1 → back to takeaway). Declined part: "only unsent-or-all lines" — Online's revision imposes no such rule, so none was invented. |
| Team 3 (4) | reusable principal/authority helper | DONE: `EdgeLocalPosService::assertAuthorizedPrincipal(User $user): int` (bound branch id; principal + local authority) |
| Team 3 (5) | view-model table flags | DONE in `screen()` vm: canOpenTable, canCloseTable, canRequestBill, canMoveTable, canMergeTables, canViewSession, canReattachTable, canCancelHeld, canSplitBill (Online route permission names as given) |
| Team 5 T2-1 | printPrefsHtml / readPrintPrefs / afterSalePrinting / server afterPaidSale | DONE: panel rendered + `refreshPrintPanel()`; prefs merged into POST /sales (validated `in:print,skip`, persisted as the orchestrator state, never read by the envelope builder — it reads explicit fields only); `storeSale` calls `EdgeLocalPrintDirectPayService::afterPaidSale` after commit (also on idempotent replay; any exception is reported and never unwinds the sale) and returns `printing`; the page calls `afterSalePrinting(sale)` (fallback: autoReceipt unless receipt=skip). Settle (Team 3 controller) is not wired — their call. |
| Team 5 T2-2 | operator terminal on cancellation KOT + KOT | DONE: `reviseHeldSale` → `recordLineCancellations(..., (string) $terminal->id)`; `queueKotEvents` → `queueKot($sale, null, [], (string) $terminalId)` |
| Team 1 (1) | `GET /customers?id=N` | DONE (exact active customer with addresses; test extended) |
| Team 1 (2) | 44px category pills | DONE (parent + child strips) |
| Team 1 (3) | listen to `edge:order-type-changed` | DONE (js/catalog re-syncs the delivery panel / quick-sale row and re-quotes) |

## TEST_RESULTS (25 Sep 2026, team DBs `pos_test_*_edgewt_t2`)

- W2 + regression filter (`EdgeCashierMenu|EdgeCashierPayment|EdgeCashierDealsDiscountsHttpMySqlTest|EdgeCashierPermissionHttpMySqlTest|EdgeCashierRouteGatesHttpMySqlTest|EdgeLocalPosHttpMySqlTest|EdgeLocalPosMySqlTest|EdgeCashierScreenRendersHttpMySqlTest|EdgeCashierDineInHttpMySqlTest|EdgePreviewBillMySqlTest|EdgeCashierReservationHttpMySqlTest|EdgeCashierTables|EdgeCashierOrderLifecycle`): 86 tests / 1154 assertions — 1 failure, which was my own print-intent assertion made stale by the Team 5 `afterPaidSale` adoption (receipt_status advances in the same request); fixed and re-run: `EdgeCashierPayment|EdgeCashierMenu|EdgeCashierDineInHttpMySqlTest` → **OK (17 tests, 363 assertions)**. `EdgeLocalPosMySqlTest::test_effective_intent_ignores_spoofed_price_and_branch` (spoofed `kot_print_intent: true`) is green: only Online values print|skip are hashed/persisted.
- New classes: `EdgeCashierMenuHttpMySqlTest` 8/8, `EdgeCashierPaymentHttpMySqlTest` 4/4.
- Census (`EDGE_NODE_BIN=… EdgeCashierControlCensusHttpMySqlTest`): 3/4 — registration + pinned reference OK, deferral grep OK, **node --check of the composed page script OK**; the row gate fails on exactly the 23 W2 selector changes listed under CENSUS_FLIPS (old `#cm-*` / `#category-tabs` selectors → the Online ids) — for the coordinator to flip. (Planned rows that now exist have no selectors, so they do not fail; they are listed per record above.)
- Feature: `tests/Feature/Edge/EdgeBranchServerRegistrationTest.php` + `EdgeArtifactTest.php` → **OK (17 tests, 31236 assertions)** (no route added by W2; no `route_allowlist` entry needed).
- Composed script also checked locally with node 20 after every JS edit.
- Browser: no dev-instance proof run — the dev instance at :8095 serves the committed tree, not this uncommitted work, so it would not show W2; every BROWSER_ACCEPTANCE_STEP above is for the coordinator's post-integration read-only proof.
