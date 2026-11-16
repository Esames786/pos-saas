# W2-B — quantity rules on the server (22 Oct 2026)

Branch `feat/w2b-quantity-rules-20261022` off `5441ead2`. Separate from the frozen W2 candidate `df524466` and from the
supplier-bill reset fix. Status words: implemented / tested / merged / deployed / activated. Requested by the reviewer
(22 Oct): fix quantity correctness before any more screens.

## The rules (new postings only; old lines are read as stored)

1. **Precision, exact once.** A quantity is stored with 3 decimals. It is made exact before price, stock, KOT or a
   return reads it, so `quantity × price = line total` and stock moves by the same number. Browser float noise
   (`0.1 + 0.2 = 0.30000000000000004`) is cleaned. A real fourth decimal (`1.2345`) is refused: "a quantity can have at
   most 3 decimal places". It is never rounded silently.
2. **Counted items are whole numbers** on sale, hold and split. Counted means piece, bottle, plate, or no unit at all.
   Weight, volume and length items take parts, by the same test every screen uses (`StandardUnits::allowsParts`).
   Packs were already whole (`CounterPacks`). Combo components and modifiers follow their parent and are not checked.
3. **Returns:**
   - Asking for more than is left is refused, with what is left in the line's own unit: "only 1 Strip can still be
     returned, not 2". It used to be cut down silently to the remainder.
   - Nothing left: "nothing is left to return — all 3 Strip already came back".
   - A counted or pack line comes back whole, **or as everything still left**, so a line split in the past can still
     be closed.

## Historical return policy (production census, read-only, 22 Oct)

- Sale lines with a part quantity on a counted item: **0 on every live tenant**. The demo tenant has 2 (0.999 / 1.001,
  Orange Juice).
- Return lines with a part quantity on a counted item: **4, all khatribiryani, 19 Aug 2026.** Two lines of 1 each had
  0.001 returned, then 0.999. Both lines are fully returned today.
- The "everything still left" exception keeps any such line closable. A test reproduces the 0.001 / 0.999 case.

## Changed behaviour to note

- `SalesReturnPostedOnceMySqlTest` (W1, UB-06) pinned "a second post for 2 with 1 left is cut down to 1, exactly as
  before". It is now refused with the quantity left, as the reviewer asked; the test is updated and says why. A posted
  old browser tab (no form key) that over-asks is told what is left instead of silently returning less.
- The concurrent-return race test now expects one return posted and the other refused ("only 1 can still be returned,
  not 2"), instead of two returns where the second was cut down. Together they still never exceed what was sold.
- A bill with nothing left is not offered for a return at all (the screen answers 404, as before).

## Impact (system map)

- **Tables:** none. No migration.
- **Code:**
  - new `app/Support/LineQuantity.php`;
  - `SalesOrderController::validateSale`;
  - `HeldSaleController::store`;
  - `SplitBillController::store`;
  - `SalesReturnService::processReturn`.
  - The no-bill return already rounds to 3 decimals and requires whole counted items; it is unchanged.
- **Screens:** POS pay, hold, split bill, sales return. Website orders, KOT cancellations and stock screens are not
  part of this step.
- **Tests:**
  - new `QuantityRulesMySqlTest` (4 tests, through the real routes): whole numbers on sale, hold and split; the
    3-decimal rule and float noise; returns in the line's unit; a line split in the past;
  - updated `SalesReturnPostedOnceMySqlTest` (2 tests).

## Evidence

- `QuantityRulesMySqlTest` + `SalesReturnPostedOnceMySqlTest`: 16 / 16.
- 8 sabotages, each red:
  - no whole-number rule;
  - a fourth decimal rounded silently;
  - the sale skips the rules;
  - the hold skips the rules;
  - an over-return cut down silently;
  - half a strip comes back;
  - no "everything left" exception;
  - the split skips the rule.
- Related MySQL suites (every file that posts a sale, hold, split or return; 26 files): 179 / 179.
- Default suite (Unit + Feature): 230 tests, 3 failures. These are the same 3 known on production: catering sidebar,
  ESC/POS report payload, rider reassignment.
