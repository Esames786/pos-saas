# W2 slice A — one rule for kg/g, L/ml, m/cm + the unit journeys proven end to end (22 Oct 2026)

Part of the unified-business roadmap, W2 (UB-08 resolver, UB-10 compatibility). Branch `feat/unified-business-20261021`.
Status words: implemented / tested / merged / deployed / activated.

## What the code map showed (22 Oct, read-only)

| Journey | Today | Proven by a test? |
|---|---|---|
| Medicine 1 Box = 10 Strip = 100 Tablet: buy 2 Box, sell 3 Strip, return 1 Strip (200 → 170 → 180) | works: GRN v2 → `PackLines` → `postIn(200)`; counter → `CounterPacks` → `postOutFefo(30)`; return reads the sale line's own `pack_factor` → `postIn(10)` | each step alone; the whole journey no |
| Mart 1 Carton = 24 Bottle: receive 2, sell 3 bottles, return 1 (48 → 45 → 46); a carton sold at the carton price | works on the pack counter (medical-counter switch) | no |
| Loose rice in kg: receive 25, sell 0.250, return 0.100 (25 → 24.750 → 24.850) | works on the normal POS (weight items take decimals) | the 12.5 kg GRN only |
| Recipe in grams, stock in kg, no conversion row | **the sale is refused** ("Cannot process sale…"), but the POS shows a wrong count (it reads 250 g as 250 kg) and Master readiness calls the item fine | no |

`StandardUnits` (kg/g, L/ml, m/cm by code and type) existed but nothing used it to convert.

## This slice

1. **`Kitchen\UnitConversionService`** — after the tenant's own conversion rows (they still win), a standard pair converts
   by `StandardUnits`: 250 G → 0.25 KG with no row. Anything else (a cup, a packet, a pound) still has no path and still
   refuses, exactly as today. Callers that gain it: recipe consumption on sale, POS availability, catering costing and
   requirement, modifier stock, Edge (production-disabled).
2. **POS availability** — when an ingredient's unit cannot be converted, the dish now shows 0 to make (the sale is refused
   anyway), instead of reading the number in the wrong unit.
3. **One stock for "no variant" and "the default variant"** (found while proving the gram recipe, 22 Oct).
   `InventoryService::postOutFefo` matched the variant exactly, but the screens disagree:
   - the new GRN screen and the POS write the product's **default variant**;
   - the old GRN screen, opening stock and recipe lines write **none**.

   So a recipe could not find rice bought on the new GRN screen, and a POS sale could not find stock bought on the old
   one ("Insufficient stock", or a negative row where the branch allows it). Now a stock-out with no variant, or with
   the default variant, takes from both rows. Each stock-book line is written against the row it actually left, so
   the row it reads is the row it decrements. A real second variant (Large / Small) still takes only its own rows.
   Nothing already written changes.

   The POS "can make" count reads both rows the same way.
4. **Tests** for every journey in the table above, through the real routes (GRN, POS, sales return, POS page), on an
   isolated tenant.

Not in this slice (next W2 steps, each its own step): pack sizes with decimals ("Bag = 2.5 kg"; new nullable decimal
columns beside the whole-number ones, every reader taught); typing grams at the counter for a kg item (W5 counter);
a server rule that a counted item never sells in parts (needs a read-only census of live sale lines first); supplier
return re-using the GRN line's pack (today it uses the current pack, which cannot be resized once used, so stock is right).

## Impact (system map + read-only production census, 22 Oct)

- **Tables:** none changed. No migration.
- **Code:**
  - `app/Services/Kitchen/UnitConversionService.php`
  - `app/Http/Controllers/Tenant/POSController.php` (`recipeAvailability`, stock lookup)
  - `app/Services/Inventory/InventoryService.php` (`postOutFefo` only; postIn, reversal, transfer-in unchanged).

  This is the stock choke point: money/stock gates apply — full suite, owner's go, backup, before/after reconciliation.
- **Stock rows by variant, production census (`variant_census.php`, read-only, 22 Oct):**

  | Tenant | Stock rows: no variant / default variant | Recipe lines: no variant | Note |
  |---|---|---|---|
  | khatribiryani | 2 / 6 (purchases 50 / 226: both screens used) | 0 | no recipes |
  | kashifkitchen | 3 / 0 | 40 | consistent today; the first GRN on the new screen would have stranded them |
  | demo | 0 / 78 | 11 | **unreachable today** (demo only) |
  | others | one kind only, or no stock | — | — |

  No product on any tenant has stock split across both rows. So no live sale changes; the fix removes a trap that
  closes the moment a recipe tenant uses the new GRN screen (W4).
- **Screens whose numbers could move:** POS (dish availability, recipe stock on sale), catering costing / requirement /
  kitchen sheet, modifier stock. **Production census (`unit_pair_census.php`, every active tenant, read-only):** no
  recipe ingredient, catering quote line, cost block, rate unit or modifier on any live tenant uses two different
  units. Kashif Kitchen's 1,313 estimate blocks and 238 product blocks, and the demo recipes, all use the stock unit
  itself. So nothing on a live tenant changes when this ships. The probe proved it can see rows: it counted those
  same-unit lines.
- **Tests:** new `tests/MySql/UnitsAndPacksJourneyMySqlTest.php`. `CateringCostingMySqlTest` "send blocked by a missing
  conversion" moves its broken pair from GM→KG (now a standard pair) to GM→PKT (no rule at all), so it still proves
  that a missing conversion refuses the send. Re-run: catering costing / lifecycle / material issue, recipe consumption,
  Edge operational stock, medical counter, purchasing v2, master readiness.

## Stock-path evidence (22 Oct, reviewer's list) — branch `feat/unified-w2-slice-a-evidence-20261022`, tests only

`tests/MySql/PlainStockVariantsMySqlTest.php` exercises the real `InventoryService` on the candidate's code, which is
unchanged. The worker gains a `stock_out` mode for the race. It proves:

- **Real second variant isolated.** A 5 kg bag variant is never taken for plain stock, and plain stock is never taken
  for it.
- **Branch isolated.** Another branch's stock is never read for this branch's sale, even when it holds the nearest
  expiry. The stock key alone would also stop it leaving.
- **FEFO and expiry across both kinds of row.**
  - The nearest expiry goes first.
  - An expired batch is never sold, and the refusal names what is expired.
  - The batch the cashier picked goes first, whichever row it sits on.
- **Cost.** Every leg is written against the row it left, at that row's own cost and balance. Each row equals its own
  stock-book lines, and no row goes below zero.
- **Reversal.** A GRN void still takes back only from the row it filled, and refuses once that row has been sold from.
- **Concurrency.** Two sales of 6 against 5 + 5 at the same moment (separate OS processes, queued on the row lock):
  one posts, the other is refused, 4 left, 6 out in the stock book.

Sabotages, each red:
- no variant filter;
- no branch filter in the FEFO read (red only after adding the nearest-expiry-in-another-branch case);
- each leg written against the asked row instead of the row read;
- no row lock;
- no expiry filter.
