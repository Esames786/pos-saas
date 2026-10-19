# Work priority — from 19 Oct 2026

Owner, 19 Oct (afternoon): *"filhal medical store GRN, PO — ye saare kaam complete karo, priority banao, kyun ke
mujhe medical store wale ko demo dena hai. Us ke baad extra product screens ko bhi ek jagah le aayenge."*

Every step goes live on `pharmacydemo` first, behind a Master switch; live customers see nothing until the owner
switches it on. Tentative boards for P1: https://claude.ai/artifact/LBfacrEPnNJLCHki3K8chw

## P1 — Medical-store demo (now, in this order)

| # | Work | What the owner can show on pharmacydemo | Board |
| --- | --- | --- | --- |
| 1a | **GRN + Purchase Order in the entry-area pattern** (the catering event screen's way): scan or search on top → the item's own boxes appear (Box \| Strip \| Tab, batch, expiry MM/YY, bonus, price per box, disc %) → Enter moves on → Ctrl+Enter puts the line in the grid → a grid line opens back for editing. GRN takes lines from its PO ("Receive"). 2D DRAP code fills item + batch + expiry. Expired batches refused, short expiry warned. | "5 Box + 1 free @ 1,000" received; stock "6 Box 16 Strip 2 Tab" up by 1,200 tablets in batch PN2410-07, exp 09/28; cost per tablet shown with bonus | 1, 2 |
| 1b | **Purchase return by batch**: Bingoo lists expired / short-expiry batches; send back from that batch only | Expired Panadol batch back to the distributor; supplier balance down | 3 |
| 1c | **Medical-store counter** on the same `/pos`: list, brand **or salt** search, Box \| Strip \| Tab per line, batch + expiry on the line, expired never sold, box barcode scan, keys F2 / ↑↓ / Enter / F10 | Sell "1 Strip + 3 Tab" by typing "paracetamol"; scan a box | 5 |
| 1d | **Sales return into its batch** + the bill printed in packs | Return 3 tablets → back into PN2410-07; bill says "1 Strip + 3 Tab · B:PN2410-07 EXP:09/28" | 6 |
| 1e | **Demo data through the new screens** — the nightly reset seeds pharmacydemo with GRNs in boxes and counter sales in strips/tablets | A demo that looks like a real store's week | — |

**Status, 19 Oct evening — 1a built (PURCHASING-V2-1)**, Master switch "New purchase screens" (`purchasing_v2`):

- **How a line is saved.** The page sends what was typed: packs, price per buying pack, disc %, free, batch,
  MM/YY. `App\Support\PackLines` turns it into today's line:
  - quantity in tablets;
  - net cost per tablet;
  - the bonus as its own zero-cost line in the same batch.

  Stock, bill, void and reports read it as before.
- **Proof.**
  - `PurchasingV2MySqlTest`:
    - 5 Box 3 Strip + 1 free @ 892 −5% = 1,030 @ 4.237 + 200 free, so stock rises by 1,230;
    - the bill is 4,364.11 and the supplier owes the same;
    - expired / unbatched / 1.5 Box are refused;
    - the old post is unchanged;
    - a PO in boxes; a free box never counts as ordered;
    - the lookup gives packs only to the new screen.
  - The 11 existing purchasing / search test files are green.
  - A browser drive on a local test shop covered: search → Enter through the boxes → Ctrl+Enter → edit →
    post; PO → GRN "Receive".
- **The GRN and PO pages** show what was typed ("5 Box 3 Strip @ 892 / Box, −5%", "Bonus (free): 1 Box").
- **What a draft PO does.** It stays draft after a GRN, as before; only an approved PO moves to
  partly / fully received.

Rules for P1 (from the risk review, rev 32):
- The stock engine stays as it is. Packs become tablets just before it; quantity and cost on a line stay in the
  pack the user typed. The line's `base_quantity` is set only when it carries a pack.
- A purchase or sale line without packs is saved exactly as today — Khatri's and Kashif Food's GRNs must not
  change by one rupee or one gram.
- Bonus (free) quantity goes into stock at zero cost, which lowers the cost per tablet. The supplier's bill is
  still the paid packs × price.
- Expired stock is never sold. This is a sale-only opt-in inside the stock engine, on for the medical-store
  counter only; wastage, transfers, counts and kitchen issues are unchanged.
- New screens are switched on per tenant in Master ("New purchase screens", "Medical-store counter").

## P2 — One product screen everywhere (after the demo)

Bring every separate product screen into the one product screen (tabs). Then **remove the old screens
permanently**: their links open the product screen, and the menu entries go.

| Screen today | Goes into | Notes |
| --- | --- | --- |
| Catering Products — the profile popup: Urdu name, how it is priced, costing source, default catering rate, default quote unit, production station, minimum qty, enabled, allow party meat ("customer apna saman"), complimentary, production labels EN/UR, default instructions | product screen → **Catering** tab (catering business only) | Kashif Kitchen has **912** profiles |
| Cost Blocks — parts of a dish (material / charge, rate, rate is per, charge source, basis, material per KG, unit), the tiles (order rate, making, material, additional) and the preview | **Catering** tab → "Parts of this dish", entry-area pattern | **1,130** blocks live. Keep every rule: a block holds its own rate; the commercial-rate apply refuses manual blocks; editing never reprices a sent quotation |
| Catering Materials (create / edit) | the product screen with the catering cards (Ingredient, Disposables) | already the same controller |
| Manufacturing Products (create / edit) | the product screen with the industry cards | already the same controller |
| Manufacturing BOM | product screen → **"How it is made (BOM)"** tab on finished / half-made items; the BOM list stays | versions and effective dates stay |

What the code says (field-level map, 19 Oct):

- **The profile.** Table `catering_product_profiles`, one profile per product (unique `product_id`). Saved by
  `CateringProductProfileController` with `updateOrCreate`.
  - Columns: `costing_mode` (recipe / blocks), `default_catering_rate`, `default_quote_unit_id`,
    `production_station`, `minimum_qty`, `catering_enabled`, `allow_party_supply`, `is_complimentary`,
    `production_label` / `production_label_ur`, `instructions`.
  - The Urdu name is a `product_translations` row.
  - `refuseUnreadySwitch()` blocks a costing-source switch the dish is not ready for. This guard lives in the
    controller and must move to a service before the merge.
- **The cost blocks.** Table `catering_product_cost_blocks`, keyed by **`product_id`**, not by the profile, so
  the product screen can save them directly.
  - The save rules live in `CateringCostBlockController`:
    - a material block must name a material;
    - only one Making charge;
    - house-rate linking is refused for charge blocks, per-dish rates, materials with no house rate and
      unit mismatches;
    - a removed block becomes inactive.
  - These rules move to a service too.
  - Readers: `CateringCostBlockService`, `CateringEstimateCostingService` (Recalculate),
    `CateringLineCostBlockService` (copies blocks into a quotation), `CateringCommercialRateImpactService`,
    `CateringMakingAdjustmentService`, `CateringPunchedRateAdoptionService`, the event screen, and
    `CateringProductionReleaseService` (labels / station on the kitchen sheet).
- **Catering Materials / Manufacturing Products.** Both already run on `ProductController` (a route context);
  `usesScreenV2()` is the only thing keeping them on the old form.
- **BOM.** Tables `manufacturing_boms` + `manufacturing_bom_lines` (version, status, effective date, output
  qty; lines are component, unit, qty, wastage %).
  - Saving an active BOM deactivates the product's other active BOMs.
  - Its only reader is the material requisition prefill.
  - There are **no BOM tests yet**; write them before moving it.
- **Tests that guard the move.** `CateringCostBlockHttpMySqlTest`, `CateringCostBlockMySqlTest`,
  `CateringCostBlockSourceHttpMySqlTest`, `CateringCostingModeMySqlTest`, `CateringCostingSwitchHttpMySqlTest`,
  `CateringMaterialRateBasisMySqlTest`, `CateringViewRenderMySqlTest`, `ProductFormAreaMySqlTest`,
  `ProductListEveryKindMySqlTest`.

Rules for P2:
- Same tables and services; only the screens move. Estimates, Recalculate Cost, the commercial rate,
  kitchen sheets and production read the same data.
- Permission names stay. A tab shows only to a role that holds today's permission for that screen
  (`tenant.catering.profiles.update`, `tenant.catering.cost-blocks.update`, `tenant.manufacturing.bom.*`).
  All 3 Kashif Kitchen roles hold both product and catering permissions today (checked on production).
- "Owner (No Amounts)" roles must not see rates or money in the Catering tab — the same as today.
- A screen is removed only after its tab is live and the owner has used it.

## P3 — Fixes for every customer (small, can slot between P1 steps)

- GRN void takes stock only from its own batch (today every batch of the product).
- PO reaches "received" after a partial GRN; the GRN prefill ignores voided receipts.
- Shifts open on Retail Starter / Inventory Store / Restaurant Starter plans.
- Kashif Kitchen catering emails (`jobs` table missing).
- Product variant SKU clash gives a plain error, not a 500.
- The 5 missing module pictures in Master.

## P4 — Waiting for the owner

- "Internet connected" purchasing: the supplier confirms the PO by WhatsApp link (board 4) — or another meaning?
- When live customers get the new screens (Master switches).
- Distributor terms (trade price, bonus 10+1) in the first release; FBR POS integration; pilot store.
