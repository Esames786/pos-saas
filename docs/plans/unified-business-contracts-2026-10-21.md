# Unified business — contracts (UB-03), 21 Oct 2026

The three decision tables the roadmap asks for before money or stock code changes: quantity, purchasing state, and
returns. Each row says what the code does TODAY (with the test that pins it) and what is still the owner's decision.
Branch: `feat/unified-business-20261021`.

## 1. Quantity contract

| Fact | Where it lives today | Rule kept |
|---|---|---|
| Stock unit | `products.unit_id`; stock and cost always in it (`stock_ledgers`, `stock_balances`) | never changed by a switch or a product edit; old lines have no unit of their own and read it from the product, so changing it would relabel history — not done |
| Part quantities | `units.unit_type` weight / volume / length; everything else whole numbers | the same test everywhere (`StandardUnits::allowsParts`, `PackLines`, POS, lookup) |
| Standard conversions | `App\Support\StandardUnits`: kg/g, litre/ml, metre/cm, recognised by code AND type | a typed `base_factor` is never trusted; counted things never convert globally |
| Cross-type / kitchen conversions | `unit_conversions` + `Kitchen\UnitConversionService` | unchanged; a missing path still refuses the sale (`StandardUnits` not wired in yet — separate decision) |
| Packs | `product_pack_units` (whole-number `factor`, own price, own barcode, can buy / can sell) | per product; refused on kg/litre/metre items today |
| Purchase lines (GRN / PO / bill / supplier return, new screens) | `quantity` = BASE units; `pack_unit_id`, `pack_factor` (whole or NULL), `base_quantity` = same number; `entry_json` = what was typed | never multiply a purchase line by its factor again |
| Sale and customer-return lines (pack counter) | `quantity` = PACKS; `base_quantity` = units; NULL on a plain line; `returned_quantity` in the line's own unit | readers use `base_quantity` when present, else `quantity` (reports do) |
| A return's size | the line's own `pack_factor` at the time of sale | a later pack edit never changes an old return |

Open (owner): decimal pack sizes for kg/litre items ("Bag = 2.5 kg"). Needs new nullable decimal columns beside the
whole-number ones (a type change on the busy line tables would copy them — not allowed), every reader taught, and the
packs rule lifted. Recommended: yes, as its own step.

## 2. Purchasing — what each action does today

Pinned by `SupplierReturnMatrixMySqlTest` (running account ON, as on every live tenant):

| Action | Stock | Supplier / GL | Cash |
|---|---|---|---|
| GRN (receipt) | in, once | nothing — GL waits for the bill | none |
| Bill for the receipt | none | supplier ledger debit; Dr 1400 Inventory / Cr 2100 Payable; takes any advance first | none |
| Payment | none | settles its bill, then the oldest; the rest is an advance | the payment's own movement |
| Return after the bill, unpaid | out, once | ledger credit; Dr 2100 / Cr 1400; settles its own bill first — the bill owes less | none |
| Return after the bill, fully paid | out, once | the supplier owes us (negative balance) | none until he actually pays back |
| Return BEFORE the bill | out, once | books ahead of the bill (1400 below zero meanwhile); a later bill for the FULL receipt makes stock, 1400 and 2100 agree | none |
| Free (bonus) goods returned | out, once | no money — no credit for goods never paid for | none |
| Return posted twice at once / same receipt line by two people | — | refused past what the line brought in (`PurchaseReturnPostedOnceMySqlTest`) | — |

**Owner decision before W4 (direct purchase) posts money:** a supplier bill entered NET of a return already made
credits the supplier twice today (the test pins 600 where 800 is right). Choices: (a) a bill for a receipt with
returns before it defaults to the receipt minus those returns, and the return then does not credit again; or (b) the
bill always carries the full receipt and the screen says so. Recommended: (b) — nothing posted changes, and the screen
can show "this receipt had 2 returned — bill the full 1,000; the 200 is already credited".

Not built and not offered until designed: one bill for several receipts / part of a receipt (today one receipt = one
bill, guarded).

## 3. Returns

| Case | Today (after W1) | Test |
|---|---|---|
| Customer return, with a bill | capped at what is left on each line; sale and its lines locked; posted once per form | `SalesReturnPostedOnceMySqlTest` |
| Customer return, no bill | reason required, price at most today's, refund into the paying counter's shift; posted once per form | same + `MedicalCounterMySqlTest` |
| Medicine (item with an expiry) back with no bill | "Thrown away" unless the cashier says shelf | `SalesReturnPostedOnceMySqlTest` |
| Expired batch | never restocked, with or without a bill | same |
| Supplier return | never more than the receipt line brought in, even two at once; the new screen's "Post return" posted once | `PurchaseReturnPostedOnceMySqlTest` |

**Owner decision:** valuation of a return without a bill — refund at most today's price; stock back at the batch's
cost, else the item's average, else its buying price; revenue reversed on counter sales (4120). On pharmacydemo only
until confirmed.
