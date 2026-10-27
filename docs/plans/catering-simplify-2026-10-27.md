# CATERING-SIMPLIFY-1 — one place to price and edit a catering dish (27 Oct 2026)

Branch `feat/catering-simplify-20261027` (worktree `pos-saas-catedit`), from production `d27d31ec`.
Asked by the owner on 27 Oct, after a client changed a dish's **Selling price** and the event screen did not change.

## Why

1. **The client changed the wrong box.** A catering dish priced by cost blocks takes its event price from its blocks
   (the sum of what each block adds to one unit: the "order rate"). The product form's **Selling price** is the
   counter (POS) price. No catering code reads it. The form still let the client type there, so they thought they had
   changed the event price.
2. **Three buttons for one dish.** The Catering Products list shows "Edit in product form", "Cost Blocks" and "Edit"
   (a pop-up) on every row. The pop-up and the product form's **Event costing** tab edit overlapping fields.
3. **The unit is not filled in.** On both rate pop-ups (Commercial Charge Rates, Material Cost Rates), choosing a
   material leaves **Unit** on "Choose a unit…", even when the answer is obvious.
4. **The cost book drifts away from purchases.** A Material Cost Rate is the business's own entry for what a material
   costs. In practice it is the purchase price, but nothing shows the purchase price beside it, so it goes stale.

## The four changes

### 1 — Lock the selling price of a cost-block dish (catering business)

- **When it applies.** All of these must be true:
  - the tenant's primary business is catering (`Business::of() === Business::CATERING`; never derived from modules);
  - the product already exists and is a sold item (`product_kind = sale_item`);
  - the dish is priced by cost blocks. On a save, the posted Event costing choice decides when the user may edit
    that tab; otherwise the saved profile's `costing_mode` decides.
- **What the form shows.**
  - The Selling price box is disabled. It shows the dish's cost-block price
    (`CateringCostBlockService::rateFor()`, lump sums left out, the same figure as the event screen's order rate).
  - Under the box: "This dish's price comes from its cost blocks."
  - A **Change in Cost blocks** button opens the Event costing tab of the same form (no new page). Without that tab
    (no permission), the line says where the price comes from and has no button.
- **The live switch.** If the user changes "Priced by" to Recipe on the Event costing tab, the box opens again with the
  saved counter price. Changing it back locks the box again.
- **On save.** For a locked dish the server ignores any posted `default_selling_price` and keeps the stored one, so a
  crafted post cannot change it either.
- **Not changed.** The stored counter price is not written or copied from the blocks. The local copy of the catering
  tenant has 0 counter orders, and copying would be a write nobody asked for.
  - If the stored counter price differs from the block price, a small line says so: "Counter (POS) price saved:
    Rs X — events do not use it."
  - Open question for the owner: should a save also copy the block price into the counter price? Not done.
- **Other businesses.** A restaurant or other business with a dish that also has a cost-block catering profile keeps
  the box open. A note says events price it from its cost blocks (Rs X).

### 2 — One Edit button on Catering Products

- **The rule.** When `ProductFormLink::to($productId, 'catering')` gives a link, the row shows one **Edit** button.
  That means the new product screen is on and the user may open product edit.
  - **Edit** opens `/products/{id}/edit#catering` in a new tab, as PRODUCT-NAV-1 does, so the list stays where it was.
  - "Edit in product form", "Cost Blocks" and the pop-up Edit are not shown on that row.
- **Otherwise everything stays as it is today:** the pop-up Edit and the Cost Blocks button. This covers the new
  screen being off, and a setup clerk without product edit.
- **"Add Catering Product"** stays as it is.
- **The pop-up's fields move into the Event costing tab.** These are added to what the tab already has (priced by,
  catering rate, quoted in, smallest order, kitchen station, cost blocks):

  | Field | Label on the tab |
  |---|---|
  | `catering_enabled` | Use at events? Yes / No |
  | `allow_party_supply` | Customer may bring own meat? (گاہک کا سامان) |
  | `is_complimentary` | Free item (اعزازی)? |
  | `name_ur` (product translation) | Urdu name (kitchen sheet, quotation) |
  | `production_label`, `production_label_ur` | Kitchen label (English / Urdu) |
  | `instructions` | Default instructions |

  - `pricing_mode` stays hidden and kept, as on the pop-up (CAT-PRICE-001: no calculation reads it).
  - The tab's link to "Catering Products" for these fields goes, since they are now on the tab.
  - The link to the Cost blocks screen stays, for each block's role and rate source (advanced, rarely used).
- **Saving.** These fields go through the same writer as the pop-up (`CateringProfileWriter`), with the same rules.
  - The same "_was" fingerprint now includes them, so an untouched tab writes nothing.
  - A profile or Urdu name changed elsewhere after the page opened is refused, not overwritten (as today).
  - A new dish's profile starts with the same defaults as today.

### 3 — Unit filled in on both rate pop-ups

- Choosing a material fills **Unit** with the first of these that is an active unit:
  1. the unit of that material's latest rate in **this** book (commercial or cost);
  2. the unit most active cost blocks of that material use (a tie goes to the most recent block);
  3. the material's own unit (`products.unit_id`).
- It stays a normal dropdown: the user can change it.
- The map (material id to unit id) travels with the page, like the rate history already does. No new route and no
  new permission.
- Both pop-ups share the history panel script (`partials/rate-history-panel`), which does the filling. One script,
  two books.

### 4 — Last purchase price on Material Cost Rates

- **Source.** The latest goods receipt line for that material, by `receipt_date` then line id:
  - from any supplier;
  - only receipts with `status = posted` (voided receipts are `voided`, drafts are not posted);
  - only lines with `unit_cost > 0`;
  - the price is `unit_cost`, per the material's base unit, the same figure the purchase screen shows as the last
    price (`ProductLookupController::lastCost`).
  - Purchase bills are made from receipts (`createFromReceipt`), so receipts are the one source.
- **The table.** A **Last purchase** column on Material Cost Rates: "Rs 760.00 / KG · 22 Oct 2026 · supplier", or
  "—" when the material has never been bought.
- **The pop-up.** Under Rate, choosing a material shows "Last bought at Rs 760.00 per KG on 22 Oct 2026 (supplier)",
  with a **Use this price** button. The button fills Rate and Unit only; the date is not touched, the same as the
  history panel's "Use this". Nothing is saved until **Save Rate**.
- **Not automatic.** A purchase never writes a cost rate by itself. The screen's "what this manages" line now says
  the last purchase price is shown as a guide.
- **The read.** One batched query (`ROW_NUMBER() OVER (PARTITION BY product_id …)`, MySQL 8, as on production).
  Only materials that were bought come back.

## Impact (from the system map, `docs/system-map/system-map.json`, built 27 Oct)

- **Screens (routes):**
  - `tenant.products.edit` and `tenant.products.update`: the lock, plus the new Event costing fields;
  - `tenant.catering.profiles.index`: one Edit;
  - `tenant.catering.commercial-rates.index`: unit default;
  - `tenant.catering.material-rates.index`: unit default and last purchase.
  - `tenant.catering.profiles.update` (the pop-up) is unchanged and still used where the link is not shown.
- **Tables read (new reads):** `goods_receipt_lines`, `goods_receipts`, `suppliers` (last purchase);
  `catering_product_cost_blocks`, `catering_material_rates`, `catering_material_commercial_rates`, `units`, `products`
  (unit default); `product_translations` (Urdu name on the tab).
- **Tables written:**
  - `catering_product_profiles` and `product_translations`: the same writer, only from the product form's save,
    only for the fields that changed;
  - `products.default_selling_price`: now never written for a locked dish.
- **Not changed:** no migration, no new route, no new permission, no switch. Quotations, estimate lines, line block
  snapshots, the rate books and the impact screen are not touched.
- **Who sees it:**
  - The lock is for catering-business tenants only.
  - One Edit needs the new product screen on (per-tenant switch `product_screen_v2`).
  - Unit default and last purchase are for anyone who already opens those two rate screens.

## Tests

**New: `tests/MySql/CateringSimplifyMySqlTest.php`.** It goes through the real routes, renders the real pages and
posts what the page shows:

1. Catering business, cost-block dish:
   - the box is disabled, shows the block price and has the Change in Cost blocks button;
   - a crafted `default_selling_price` leaves the stored price unchanged.
   - A recipe dish saves the typed price.
   - Switching to Recipe in the same save saves the typed price.
   - A restaurant business keeps the box open, with the note.
2. Catering Products with the switch on (owner): one Edit per row to `#catering`, and none of the three old buttons.
   With the switch off, or a clerk without product edit: as before.
3. The Event costing tab:
   - shows the moved fields;
   - an untouched save writes nothing (the same `updated_at`);
   - changed fields are saved, the Urdu name included;
   - an Urdu name changed elsewhere meanwhile is refused.
4. Unit default: last rate unit beats block unit beats material unit; an inactive unit is skipped; both books.
5. Last purchase: the latest posted receipt from any supplier wins; voided, draft and zero-cost lines are ignored;
   column and pop-up data.

**Each guard is proven to bite.** Remove the guard, watch its test fail, restore it (md5 checked).

**Updated:**
- `ProductNavCleanupMySqlTest`: the Catering Products link is gone from the tab; the Cost blocks link stays.
- `ProductWorkspaceMySqlTest`: the "keeps what the tab does not show" test, now that those fields are shown.

**Related run (frozen, detached worktree, one DB set):**
- the new test file, plus `ProductWorkspace`, `ProductNavCleanup`, `ProductScreenV2` and `ProductFormArea`;
- `CommercialRatePicker`, `CateringRateBookParity`, `CateringMaterialRateBasis` and `CateringRateApplyOnSave`;
- `CateringCostingSwitchHttp`, `CateringCostBlockHttp`, `CateringCostBlockSourceHttp` and `CateringViewRender`;
- `CateringEntitlement`, `CateringDisabledPlatformNonRegression`, `UnitsAndPacksJourney` and
  `ProductArchetypeContract`.

**Also:** Blade compile and lint of every changed view, and a browser walk of the four screens (desktop and phone
width).

## Release

- Not deployed until the owner says so.
- When it is: the usual steps.
  - Before: backup with coverage, permission and reconciliation snapshots, smoke.
  - Release: merge, push, check origin == HEAD, then `deploy.sh`.
  - After: smoke, permissions unchanged, reconciliation.
  - A read-only probe that each tenant's four screens open.
- No data change, so nothing to undo beyond a code revert.

## Results (27 Oct)

- **Built:** `df80ff05`; system map rebuilt `956e67cf`. Not pushed, not deployed.
- **New tests:** `CateringSimplifyMySqlTest` has 6 tests. With `ProductWorkspace` and `ProductNavCleanup`: 23 / 23 pass.
- **Each guard bites:** 7 / 7. Each was broken on purpose, its test failed, and the file was restored (md5 checked).
  1. the stored counter price is kept;
  2. a posted "Priced by" counts only from a user who may edit the Event costing tab;
  3. a first block keeps the Urdu name;
  4. voided receipts are not purchases;
  5. the pop-up data survives an apostrophe;
  6. the book's last rate decides the unit first;
  7. one Edit where the product form opens.
- **Browser walk:** local test store, desktop 1366 px and phone 390 px, 25 checks pass, no script errors. Save was never
  pressed.
- **Frozen related run** at `df80ff05` (detached `pos-saas-verify-upv`, DB set `_relz`, HEAD unchanged, tree clean
  before and after): 211 tests, 3 fail. All 3 fail the same way on the production base `d27d31ec`:
  - `GrnVoidMySqlTest` ×2. A billed receipt is still voidable in the test. The void guard is in `voidGrn`; the test
    makes its bill by calling the controller directly and does not check the answer.
  - `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards` ("catering top button = POS"). It now also
    fails alone, on the base.
- **Unit:** 129 tests, the same 3 known failures as earlier release runs (`CateringClientFeedbackUiRegression`,
  `EscPosReportPayload`, `DeliveryRiderReassignmentRegression`).
- **Note for the release:** an edit page opened before the deploy and saved after it with an Event costing change
  is refused once ("changed on the Catering screen"). The tab's saved state now includes more fields. Opening the page
  again fixes it.
