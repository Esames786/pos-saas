# Units & packs batch — decimal packs, gram entry, packs on the normal POS, reports (plan, 23 Oct 2026)

Branch `feat/units-packs-batch-20261023`, off W2-C `edf958d9`; worktree `pos-saas-unitspacks`, test DB set `_up`.
The reviewer's next batch, in four features. Each feature gets its focused tests as it is built. At the end the
integrated revision is frozen for the full suite plus browser journeys.

**Demonstration at the end:** buy a pack, sell it in smaller units, return it, and see the right stock and value in
the reports.

## What users gain

1. **Decimal packs.** A kg, litre or metre item can have packs: Bag = 2.5 kg, Bottle = 1.5 L. Buy 4 Bags and 10 kg
   goes in; sell 1 Bag and 2.5 kg goes out. Counted items keep whole-number packs, as today.
2. **Gram entry at the counter.** A kg item can be sold as "250 g". Stock and price use 0.250 kg; the receipt says
   250 g. Litre/ml and metre/cm work the same way.
3. **Packs on the normal POS** (mart). Scan a carton barcode and the line is 1 Carton at the carton's price, with 24
   pieces leaving stock. This is a new Master switch, so the medical counter is not needed for it.
4. **Reports and prints say what the number is.**
   - The receipt and the sale screen show "2 Bag" / "250 g".
   - Stock and sales reports show the unit beside every quantity, with the pack wording where there are packs.
   - The deal-returns count reads base units.

## Rules

- **Old records stay as they are.** No unit is relabelled or converted, and no old line is rewritten. Khatri's units
  stay.
- **Stock is always counted in the item's own unit, 3 decimals** (W2-B/W2-C). A pack size times a whole number of
  packs must come to at most 3 decimals of the base.
- **The server never trusts the screen's arithmetic.** It works out the base quantity and the pack price itself, from
  the pack row.
- **Schema first.** New columns are additive (nullable, `ALGORITHM=INSTANT` on the line tables). Code writes them only
  when the column exists, so the code can ship after the schema.

## Production census (read-only, 23 Oct)

| | Count |
|---|---|
| Tenants with pack rows | 1: pharmacydemo, 78 rows, packs + medical counter on, nightly reset |
| Pack rows on every other tenant | 0 |
| Sale, return, GRN, PO, bill, adjustment or transfer lines carrying a pack (`pack_factor` set), any tenant | **0** |

So widening `product_pack_units.factor` touches only the demo's 78 rows. The line tables get a new column instead of
a change to `pack_factor`: kashiffood alone has 62,277 sale lines, and a type change would copy the table.

## Feature 1 — decimal packs

**Schema**
- `product_pack_units.factor`: INT UNSIGNED → DECIMAL(15,4). Small table; the unique (product_id, factor) key stays.
- New nullable `pack_size DECIMAL(15,4)` on the 8 line tables, beside `pack_factor` (INT, still written when whole).
  Readers use `pack_size ?? pack_factor`.

**Code**
- `ProductPackUnit` casts `factor` as float; `isBase()` compares within 1e-9.
- `PackUnits` (the product screen):
  - packs are allowed on weight, volume and length items;
  - "contains" takes up to 3 decimals there (counted items: whole, ≥ 2, as today);
  - `forScreen` and `describe` work with decimals ("2 Bag 1 kg").
- Every `(int)` read of a factor learns the decimal:
  - `CounterPacks`, `PackLines::wholeOrNull` (plus `pack_size`), `SalesReturnService` (4 places), `HeldSaleController`,
    `SplitBillController`, `SalesReturnController`, the sales-return screen;
  - the `pack_factor integer|min:1` rules on GRN and PO;
  - the product-screen JS (`parseInt`, `inputmode=numeric`);
  - POS `medSet`.

**Tests**
- Bag = 2.5 kg on rice through the product screen;
- GRN 4 Bags → 10 kg; supplier return of 1 Bag → 7.5 kg;
- counter sale of 1 Bag → 2.5 kg out at the Bag's price, return → back in;
- 1.5 L Bottle the same;
- a factor that would make the base exceed 3 decimals is refused;
- counted packs are unchanged.

## Feature 2 — gram entry against kg stock

- **Schema:** new nullable `entered_quantity DECIMAL(15,3)` and `entered_unit_code VARCHAR(20)` on
  `sales_order_lines`, with `ALGORITHM=INSTANT`.
- **POS weight popup:** a unit picker with the item's own family only (`StandardUnits`: g / kg; ml / L; cm / m).
- **Server:** the base quantity is worked out from the entered quantity and unit, with `StandardUnits::factor`, then
  `LineQuantity` exactness on the base. 1 g = 0.001 kg is fine; 0.5 g is refused with its message. The price is
  base × price per kg.
- **Held, split and recalled lines** keep the entered unit.
- **Returns** are in the base unit (the receipt line names both).
- **Tests:** 250 g of rice sells 0.250 kg at the kg price; 1.5 L as 1500 ml; 0.5 g is refused; a unit from another
  family is refused; a hold round trip keeps the entry.

## Feature 3 — packs on the normal POS

- **New Master switch** "Packs at the normal counter". It needs Packs and is OFF by default.
- **POS payload:** each item's sellable packs (name, size, barcode, price). Batches stay on the medical counter only.
- **Scan:** `handlePosBarcodeScan` and search match pack barcodes, and the line is 1 pack.
- **Server:** the pack is resolved as on the medical counter (`CounterPacks`, generalised to either switch). The base
  quantity and the pack price are worked out on the server. A submitted price that differs is treated like any other
  price change at the counter, under today's rule.
- **Hold, split, return** follow the existing pack paths.
- **Tests:** a carton scan sells 24 at the carton's price; a loose piece scan still sells 1; with the switch off a
  pack is ignored (today's test); hold, recall and return of a carton.

## Feature 4 — reports and prints

| Where | Today | After |
|---|---|---|
| ESC/POS receipt item row | the number only | number + unit ("2 Bag", "250 g") |
| Sale detail screen | `2.000` | "2 Bag (5 kg)" |
| Item-level sales report, CSV | base totals, no unit | a unit column in base units |
| Stock report, valuation | base quantity, no unit | a unit column + pack wording where there are packs |
| Deal returns | `SUM(rl.quantity)` | the base quantity (a bug) |

Mixed units are never added into one total without saying so.

## As built (23 Oct)

| Feature | Commit | Focused test | Sabotages that turned it red |
|---|---|---|---|
| F1 decimal packs | `7308db7e`, tests `b9b6a8fd` | DecimalPacksMySqlTest, PackUnitsMySqlTest | see the F1 report |
| F2 gram entry | `9ec6b22f`, gate in the F4 commit | GramEntryMySqlTest (4) | GATELQ, GATEPOS |
| F3 packs at the normal counter | `7e85f640` | CounterPacksMySqlTest (5) | PRICE, HELDPRICE, PACKSON, EXPIRY, PAYLOAD |
| F4 prints and reports | this commit | UnitsPacksPrintsReportsMySqlTest (4) | WORDS, ENTERED, UNIT, CSVUNIT, DEALRET, INWORDS, VALSWITCH |

Decisions taken while building, each against the rules above:

- **Gram entry comes with the packs switch.** The plan did not name a switch. As first built, the weight popup offered
  g / kg on every kg item of every tenant, so a live shop's POS would have changed on deploy. Now the popup offers
  g / kg only with `packs` on (today only pharmacydemo). With it off, the server ignores a posted entry and the
  quantity sent stands, as before.
- **The words go under the line, not into the Qty column.** The receipt's Qty column is a few characters wide, and
  the restaurant thermal layouts are tested column by column. So "2 Bag = 5 Kilogram" and "250 g = 0.25 Kilogram" print as the sub-row the medical
  counter already uses: thermal, browser receipt and the sale screen, from one helper (`CounterPacks::lineWords`).
  A plain line prints exactly as before.
- **The unit name shows for every tenant.** In the item report it is a Unit column, on screen and in the CSV (after
  Category). In the stock list and the valuation it sits beside the quantity, and the valuation CSV gets Unit and In
  Packs columns. Pack words ("2 Bag 2.25 Kilogram") show only with packs on, and only for an item that has packs.
- **Deal returns read the base quantity**, like deal sales. No live number moves: `base_quantity` is written only on
  pack lines, and a deal header is never a pack line.

Left as they are (open points):

- Category and order-type rows still add quantities across units: kg and pieces in one Sold Qty, as today. The plan's
  "never without saying so" is not met there yet. The owner should choose: a "mixed units" note, or no quantity on
  those rows.
- Partner share counts line quantity (its rates are per menu item, with no packs).
- The report-centre print and thermal views have no Unit column.

## Impact (system map, 23 Oct)

- **Tables:**
  - `product_pack_units` (factor widened);
  - the 8 line tables (+ `pack_size`);
  - `sales_order_lines` (+ `entered_quantity`, `entered_unit_code`);
  - `tenants.features` (one new key).
- **Screens:**
  - product form, packs step;
  - GRN/PO v2 entry area;
  - supplier return v2;
  - POS (normal and medical counter) and its weight popup;
  - sales return create;
  - receipt and bill preview, ESC/POS;
  - sale detail;
  - sales report centre and CSV;
  - stock and valuation reports.
- **Services:**
  - PackUnits, CounterPacks, PackLines, ReturnLines;
  - SalesService (base quantity, already `CounterPacks::baseOf`);
  - SalesReturnService;
  - SalesOrderController, HeldSaleController, SplitBillController;
  - SalesReportEngine, InventoryReportService, EscPosPayloadService.
- **Tests re-run:**
  - per feature: PackUnits, MedicalCounter, UnitsAndPacksJourney, PurchasingV2, W2Combined, QuantityRules, the new
    test per feature;
  - at the end: the full suite, frozen, plus browser journeys.

## Out of this batch

- Scale or price-embedded barcode labels: needs the clients' label (owner decision 4 of the 20 Oct plan).
- Stock count in packs.
- The product list's Type / Role / Behaviour presentation: product-screen phase, per the reviewer.

## Gates

Push, release merge, deploy, and each switch per tenant: each is its own approval. No reset and no historical-data
change.
