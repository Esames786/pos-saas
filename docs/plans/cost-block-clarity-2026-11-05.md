# COST-BLOCK-CLARITY-1 — a clearer cost-block area on the dish form (5 Nov 2026)

Branch `feat/cost-block-clarity-20261105`, worktree `pos-saas-costblock`, from production `199ea23c`.
Owner asked 5 Nov while entering a dish's cost blocks for a client; design shown as a before/after sketch, owner: "teekh ye
behtar design hai ese implement kardo", and "har input pe chota question mark ho ta k user ko samaj ae".

## The problem (as the owner hit it)

On Products → a dish → Event costing → Cost blocks:

1. **Kind (Material / Charge) was the second column**, but the first column's "Pricing settings" changed with it — you filled
   things in before saying what the row is. In the owner's own entry a "Making" block ended up as a Material.
2. **"Pricing settings" was collapsed and technical** ("Rate is per", "Rate source", "Linking adopts the current house rate…")
   with no explanation; the Making role a client's guide asks for (step 4) was hidden inside it.
3. **No way to see what the event screen will charge** while typing the blocks.
4. **Bug:** switching a block to Charge set "Rate is per" to the dish; switching it back to Material left it there.

## What is built

Same screen, same fields, same save. Files: `resources/views/tenant/products/partials/ws-block-row.blade.php`,
`ws-catering.blade.php`, `ws-script.blade.php`, styles in `form-v2.blade.php`.

- **What it is comes first** — two picture buttons, 🥩 Material / 👨‍🍳 Charge, with the block's Name under them; a new
  block's focus lands there. The rest of the row follows: Material and how much of it goes into 1 KG (unit) of the dish ·
  Rate and, for a material, what the rate is the price of (a short box, "per KG of material" / "per KG of dish"), with a
  line under the rate that says it in full words ("Rs 200.00 for 1 KG of Chicken", "Rs 500.00 for every 1 KG of the
  dish", "Rs 50.00 once for the whole order") · Charged (every KG / once per order) · **1 KG adds** (what the block adds to
  one unit of the dish) · a small bin to remove it.
- **Fits the screen** — six columns that fit a 1366 laptop without sideways scrolling or cut-off boxes; where the cost-block
  area is narrower than 1000px (smaller laptops, tablets, phones) each block becomes a card with a small heading over each
  box. Measured in the browser check at 1920, 1366, 1280 and 390 wide.
- **One setting line under each block**, only what its kind has: a material's *Rate from* (own rate / house rate book); a
  charge's *Is this the Making charge?* Yes / No. "Pricing settings" is gone.
- **A "?" next to every box** (and on the "1 KG adds" heading and the sample). It opens one small box with what that box
  means in plain English, with an example; Esc, ×, the same "?" or a click elsewhere closes it. English-only, as the whole
  product form since 23 Oct (W3/W4 decision 6) — the Urdu helper lines stay off.
- **Live sample under the blocks** — "How the event screen will charge it — booking of [10] KG": one line per block with its
  working (0.5 × 10 = 5 KG × Rs 200 = Rs 1,000), then *Rate for 1 KG*, *Total for 10 KG*, and once-per-order charges apart.
  Same arithmetic as `CateringCostBlockService::priceLine` / `CateringProductCostBlock::amountFor` (lump sum once; per unit of
  the material = rate × ratio × quantity to 4 places; else rate × quantity; 2-place amounts; the rate leaves lump sums out).
  Warnings: a rate of 0, a material not chosen, a material with no "how much", more than one Making. The quantity box has no
  name (never posted) and does not count as an unsaved change.
- **Bug fixed:** back to Material = the basis it was saved with, else per unit of the material.
- Choosing a material with no unit yet fills the material's own unit.

## What does not change

Field names and values posted are the ones the form always posted (`cost_blocks[lines][k][…]`), so a block left alone posts
exactly what was saved and the stale-edit fingerprint is untouched. `CateringCostBlockWriter`, `ProductWorkspace`, the event
screen, quotations, Rate Impact, Making Adjustment and the standalone Cost blocks screen are unchanged. No route, table,
migration, permission, module or switch. Saved prices do not move.

## Impact (system map)

- Table: `catering_product_cost_blocks` — written only through the existing product save (`tenant.products.update` /
  `store` → `ProductWorkspace` → `CateringCostBlockWriter`), unchanged.
- Screens: `tenant.products.edit` / `create` (Event costing tab), where `product_screen_v2` is on and the user may edit cost
  blocks. Read-only view (no Cost blocks permission) unchanged.
- Tests: `ProductWorkspaceMySqlTest` (+1: what it is first, a "?" on every box with words, Making Yes / No, sample quantity
  never posted, English-only, saved unchanged = same rows, the sample's numbers = `priceLine`); the existing event-costing /
  unlink-relink / stale / duplicate-Making tests read the new page the way a browser posts it.

## Release

Presentation only, so no Master switch: every catering dish form shows it on deploy (today that is Kashif Kitchen).
Deploy = the usual `deploy.sh`; nothing to run afterwards.
