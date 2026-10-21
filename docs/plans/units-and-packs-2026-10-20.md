# Units & packs — one way for every business (plan, awaiting the owner)

Date: 2026-10-20 · Branch: `plan/units-packs-20261020` (doc only) · Design canvas: https://claude.ai/artifact/1cTDykqKdBst1pY5TWiXpT
Owner: "system ne pehle pora unit conversion kiya tha … pora end to end plan … purchase mein maal kaise aata hai,
sale mein kaise jaata hai, ye kaise decide hoga … medical store ke liye box/packet/unit … super mart ke liye bhi …
pehle UI banalo, phir mashwara."

Nothing is built. This is the plan to discuss.

> **Superseded in part (21 Oct).** The unified-business roadmap (`pos-saas-catering/docs/plans/claude-unified-business-
> implementation-roadmap-2026-10-20.md`) is the plan being executed. Its rule wins over this file: old entries, unit
> labels and quantities are never relabelled, converted or backfilled. So the "relabel / convert" unit change and the
> Khatri "→ kg" fix below are OUT of this delivery — GRN and bill lines read the product's unit (they store none), so
> a relabel would rewrite how every old line reads. Khatri's 7 items stay as they are; the read-only readiness check
> (UB-10, Master → tenant → "Items ready for the new screens?") names them and they keep the old screens. Any fix to
> them is a separate, owner-approved change. The 21 Oct status lives in `docs/status/unified-business-baseline-2026-10-21.md`.

## What already exists (correction)

On 20 Oct I said "no code converts a quantity when a unit changes". That was wrong — I had grepped one column.
Five pieces exist; they do not know each other:

| Piece | Where it works today | Gap |
|---|---|---|
| `units` (`unit_type` quantity/weight/volume/length, `base_factor`, `is_base`) — Catalog → Units | `unit_type` decides decimals on POS, lookup, GRN v2 (`PackLines` `$part`) | `base_factor` is saved, never read by any calculation |
| `unit_conversions` + `Kitchen\UnitConversionService` — Kitchen → Unit Conversions | recipe consumption on sale (BUG-009: missing path blocks the sale), POS recipe availability, catering costing + requirement, modifier stock, Edge | per-tenant manual list, hidden under Kitchen; 0 rows on every live tenant |
| `products.purchase_unit_id` + `purchase_pack_size` ("bought in … holding") | recipe cost report; GRN/PO v2 buying pack (PURCHASING-V2-2) | a second way to say what a pack says |
| `product_pack_units` (PHARMACY-PACKS-1, switch `packs`) + line columns `pack_unit_id/pack_factor/base_quantity` | GRN/PO/returns v2 (`PackLines`, `ReturnLines`), medical counter (`CounterPacks`), PACK-QTY-REPORTS-1 | pharmacy demo only; whole-number size; refused on kg/litre items; normal POS can't read pack barcodes |
| Stock unit itself = CTN / SACK / CRATE | inventorydemo (25 items), some Kashif Kitchen items | a single piece can't be sold |

## The model

Every item has three things; everything else is derived.

1. **Stock unit** (ginti ki unit) — the smallest thing ever sold or used. All stock and cost are kept in it.
   Its `unit_type` decides decimals: quantity = whole numbers, weight/volume/length = 3 decimals.
2. **Packs** — per item, "1 Box = 10 Strip", "1 Carton = 72 Piece", "1 Sack = 50 kg"; each with can-buy,
   can-sell, own price (NULL = size × unit price), own barcode. `product_pack_units` becomes the one place;
   "bought in … holding" folds into it.
3. **Usually bought in / usually sold in** — the default pack on GRN/PO and on the counter.

Standard units convert by `base_factor` within one `unit_type` (kg↔g, L↔ml, dozen↔piece) with no list to fill;
`unit_conversions` stays for cross-type cases (1 cup = 120 g) and wins when a row exists.

Pack ≠ variant: more of the same thing = pack; a different thing inside (500 ml vs 1.5 L) = item/variant.

**In (purchase):** pick item → pack (default "usually bought in") → qty, free, price per pack → stock in =
(qty + free) × size, cost per stock unit = money ÷ stock in → batch/expiry when the item needs it.
**Out (sale):** the pack is decided by — the medical-counter chip; the scanned barcode (each pack has its own);
the scale label (21 = weight, 22 = price, 5-digit item code, 5-digit value, EAN-13 check); the recipe (each
ingredient in its own unit, converted by the standard math); a plain tap = "usually sold in". Returns go back in
the pack the line was sold/bought in, at that day's size. Every line keeps pack, size that day, base quantity.

## Every tenant (prod, read-only census 20 Oct; purchases = last 90 days)

| Tenant | Units | Purchases | Found | To do |
|---|---|---|---|---|
| khatribiryani | only EA; 6 items no unit | 45 GRN, 297 lines, 28 items, last 15 Oct | 21 lines on 7 count items with part qty (SINGAPORIAN RICE 7, KHATRI BIRYANI BEEF 5, CREAM COCKTAIL 3, MANGO DELIGHT 2, BEEF CHUNGAIZI PULAOW 2, BIG APPLE (CRT) 1, CHICKEN 1); 5 stock rows with part qty | standard units; BEEF, KHATRI BIRYANI BEEF, BEEF CHUNGAIZI PULAOW, CHICKEN, SINGAPORIAN RICE, CREAM COCKTAIL, MANGO DELIGHT → kg by relabel (all purchase-only, never sold, in no recipe/combo/PO/return/wastage/adjustment/catering line); BIG APPLE 4.001 typo; then `purchasing_v2` |
| kashifkitchen | KG 982 · NOS 421 · PCS 370 · PH 47 · PKT 42 · LTR 20 · +4; none `is_base` | 18 GRN, 3 items, all kg, last 8 Oct | no part-qty problems; NOS and PCS both mean piece | mark base per type; NOS/PCS merge = owner, later |
| kashiffood | only EA | 0 (`purchasing_v2` ON, never used) | 0 stock-tracked items | nothing; check before the first purchase |
| tawakalkashif | only EA | 0 | — | nothing |
| pharmacydemo | TAB, CAP, BTL, SACH, TUBE, PCS, PACK, TIN | 5 GRN, 53 lines | 78 packs on 28 items; 2 counter pack sales | the reference |
| retaildemo / inventorydemo | inventorydemo stock in CTN/SACK/BOX/CRT | 2 GRN | piece can't be sold | demo seeder: Piece + Carton pack |

Recipe lines whose unit has no conversion (would block a sale today): 0 on every tenant.

## Steps (each its own worktree, tests + sabotage, system map rebuilt, deploy only on the owner's word)

1. **Foundation** — `App\Support\StandardUnits` (built 21 Oct): kg/g, litre/ml, metre/cm recognised by code AND
   type; a saved `base_factor` is not trusted (roadmap). Not yet wired into `UnitConversionService` — doing so changes
   recipe consumption on live tenants (only where today the sale is refused, 0 such lines anywhere), so it waits for
   its own decision. No standard units are added to tenants in this delivery (that would be a data write).
2. **Units check** — READ-ONLY (roadmap UB-10, built 21 Oct as the Master readiness check): items with no unit,
   part qty on count items, stock unit = carton, recipe line with no path. ~~One-click fixes; relabel / convert~~ —
   removed: no old-data change in this delivery.
3. **Item card "Count & packs"** (product screen v2, every business) — stock unit by picture, pack ladder,
   buy/sell, price, barcode, usually-in. "Bought in … holding" copied once into a buy-only pack. Packs on
   kg/litre items with a decimal size (schema first: widen `product_pack_units.factor`, ship before the code).
   Lifts the PHARMACY-PACKS-1 rule "not on a kg/litre/metre item".
4. **Purchase for all** — GRN/PO v2 default pack, decimal kg packs, "Stock in: 100.000 kg" on the line; Master
   "Purchase ready?" pre-check before `purchasing_v2` (red = switch refused, lists the items). Khatri only after
   step 2 fixes and the owner's yes.
5. **Mart counter** — pack barcodes on the normal POS (teach the seven `product_barcodes` readers named in the
   packs plan), pack price, scale-label settings; the weight popup for kg items already exists. New Master switch.
6. **Count & reports** — stock count in packs; stock/valuation reports in both forms.

Coordination: the pharmacy session owns `PackUnits`/`PackLines`/`ReturnLines`/`CounterPacks` (latest RETURN-NO-BILL-1
`88f7c9f5`). This plan builds on them; it must not fork them.

## Owner decisions

1. Khatri's 7 items → kg? CREAM COCKTAIL / MANGO DELIGHT kg or litre? BIG APPLE (CRT) 4.001 → 4?
2. Decimal pack sizes for kg/litre items (2.5 kg bag)? Recommended: yes.
3. Units check + unit change Owner-only? Recommended: yes.
4. Which scale do the mart clients use; does the label carry weight or price? (a photo of one label)
5. Stock in reports: "304 Tablet", "3 Box 4 Tablet", or both? Recommended: both.
6. Move Unit Conversions from Kitchen into Units as a tab? Recommended: yes (no new screen).
7. Kashif Kitchen NOS vs PCS merge — later.

## Impact (system map built 2026-10-20)

Tables: `units` (32 tables link to it, 141 routes), `products` (341), `product_pack_units` (57), `product_variants`
(97), `product_barcodes` (31), `sales_order_lines` (74), `goods_receipt_lines` (32), `purchase_order_lines`,
`purchase_return_lines`, `sales_return_lines`, `unit_conversions` (9), `stock_count_lines` (9), `recipe_ingredients` (7).
`stock_balances`/stock ledger keep counting in the stock unit — unchanged.
Screens: Units, Unit Conversions, product v2, GRN/PO v1+v2, purchase return, POS (restaurant + medical counter),
held sales, sales orders, stock count, recipes, catering costing/requirement, Edge bootstrap.
Tests: PackUnits/PackLines/CounterPacks/ReturnLines MySQL tests, recipe consumption, catering requirement, barcode
lookup, pack reports. Not touched: GL posting, supplier ledger, FEFO, the average-cost formula (only its input unit).
