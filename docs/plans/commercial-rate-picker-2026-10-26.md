# Commercial rate material picker (COMMERCIAL-RATE-PICKER-1) — 26 Oct 2026

Branch `feat/commercial-rate-picker-20261026` (worktree `pos-saas-ratepick`), from production `041ae000`.
**Not deployed. Submitted for review.**

## What the owner asked (kashifkitchen, Catering → Commercial Charge Rates → "Set a rate")

> "yahan sirf wo material dikhao jo kahin use ho rahe hon, aur aage bracket me product attach count bhi aa raha ho" —
> "search bhi lagao ke item search type kar sakte"

Since the purchase items came in on 14 Oct, the Material list holds 1,000+ materials (A/C FILTER, ACCOUNTS REGISTER, …).
Only a handful are in any dish: on 28 Sep it was 4.

**Owner's decision (26 Oct):** hide the unused ones, with a "Show all materials" tick, so a new material can still be
priced before a dish uses it.

## The change (same screen, same modal — no new screen)

- **Each material shows its dish count:** "Chicken (Regular) (OLD-CHICKEN) — 111 dishes".
  - The count is the same number the "Save karte hi kin dishes par lag jaye?" box below shows (`blockSourceCounts`:
    dishes whose cost uses the material, following the house rate or set by hand). The two numbers never disagree.
  - A material with a house rate but no dish yet says "— no dish yet" and stays listed, so its rate can still be
    changed.
- **The list opens on the materials in use:** used by a dish, or already given a house rate.
- **"Show all materials (also ones no dish uses yet)"** brings back the rest. Unticking it while an unused material is
  chosen clears the choice; the choice is never left hidden.
- **Type to search** (select2, the same as Material Cost Rates). It searches the materials already in the box, never
  the whole catalogue (CATERING-RATE-PARITY-1). With nothing in use matching, it says to tick "Show all".
- **The dish counts and their ticks still follow the choice.** select2 announces a choice through jQuery, which the
  modal's plain listeners do not hear, so each choice is passed on to them. Without this, the counts would silently
  stay at "0 dishes" (the walk's sabotage below proves it).
- **Without JavaScript** the plain list (every material) still works.

## Impact (system map)

- **Code:** `CateringCommercialRateController::index` (+ `ratedIds`) and `tenant/catering/commercial-rates/index.blade.php`.
- **Unchanged:**
  - tables, routes, permissions, switches;
  - what a save records or reprices — the store action is not touched.
- **Screens:** Catering → Commercial Charge Rates only.

## Results (26 Oct, local)

- **Tests:**
  - New `CommercialRatePickerMySqlTest` (3), rendered through the controller: every material marked in use or not,
    with its count; the count equals the apply box's; Show all, search, the hand-off, inline scripts parse.
  - With `CateringRateApplyOnSaveMySqlTest`, `CateringCostBlockSourceHttpMySqlTest` and
    `CateringDocumentTruthMySqlTest`: **49/49**.
- **Browser walk** (local isolated store `pnavtest`, server from this worktree, desktop 1366 + phone 390; the modal is
  never saved): **14/14.**
  - Opens on the materials in use with their counts; a rated one stays; an unused one is hidden.
  - Typing searches.
  - Show all finds the unused one.
  - Choosing chicken sets "2 dishes" and both ticks.
  - An unused choice gives 0 and the ticks go off; unticking Show all clears it.
  - Phone: no sideways scroll.
  - No script errors.
- **Sabotage:** the "in use" filter and the hand-off were both removed. The walk failed 4 checks: the unused material
  was listed; search found it without Show all; after choosing chicken the count stayed at "0 dishes" and the ticks
  stayed off. Restored, checksum verified; walk 14/14 again.

## Deployed (26 Oct 2026) — together with PRODUCT-NAV-1, revision `fd144412`

- **Same release checks** as recorded in `product-nav-cleanup-2026-10-26.md` → "Deployed": backup `20261026_175806`
  COVERAGE OK; smoke clean ×13; permissions unchanged; no migration.
- **kashifkitchen, live, read-only** (the page rendered as the Owner; no rate saved, nothing repriced):
  - the picker is there, with "Show all materials" and search;
  - **987 materials, 6 of them in use** (in a dish or with a house rate);
  - each material's count equals the dish count the page's "kin dishes par lag jaye" box uses (0 mismatches).
- **Limitation:** choosing a material and the count following it is browser behaviour. It was walked on the exact
  revision locally (14/14), not clicked on production. Production shows the same script and the same count data.
