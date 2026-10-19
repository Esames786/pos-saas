# Pharmacy packs (box · strip · tablet) — step 1 of 4

Owner's go: 19 Oct 2026, ~09:45 PKT ("theek hai … ab kaam start karo"). Design source: the Claude Doc
"Bingoo for Pharmacies — Readiness and Plan" (rev 32) and the tentative canvas boards. Order agreed with him:

1. **Packs on the product screen** (this step) — name, size, price, barcode per pack.
2. GRN / distributor bill in packs, with batch, expiry and bonus quantity.
3. The medical-store counter on `/pos` — list, pack boxes per line, batch + expiry on the line, expired never sold.
4. Bill print and sales return in packs (stock goes back to the batch it was sold from).

Every step goes live on `pharmacydemo` first; the live tenants (khatribiryani, kashiffood, tawakalkashif,
kashifkitchen) never see it until the owner flips the Master switch.

## What step 1 adds

- Master switch **Packs** (`tenants.features.packs`), per tenant. It needs the new product screen
  (`product_screen_v2`), and Master refuses one without the other.
- On the new product screen, one more question: **"Does it come in packs?"** Yes opens a small builder:
  the smallest unit (the "Sold and counted in" unit, e.g. Tablet) and up to three packs, each one
  "1 Strip = 10 Tablet", "1 Box = 10 Strip". Each pack: price (empty = size × the tablet price), a barcode
  (scan / type / Make), Sell and Buy ticks, Print labels. Live text: "1 Box = 10 Strip = 100 Tablet".
- Stock stays in the smallest unit. Nothing moves stock; existing stock is shown in packs on the edit screen.
- The product page shows the packs (read-only).
- `pharmacydemo`: every tablet/capsule medicine gets its strip and box from the seeded "Box 200 = 20 strips x 10"
  text, and the nightly reset leaves Counter 1 with an **open shift** (the POS was blocked every morning).

## Impact (system map, 19 Oct)

| Area | Change | Who is affected |
| --- | --- | --- |
| New table `product_pack_units` (FK → products, cascade) | created | nobody until the switch is on |
| `sales_order_lines`, `sales_return_lines`, `goods_receipt_lines`, `purchase_order_lines`, `purchase_bill_lines`, `purchase_return_lines`, `stock_adjustment_lines`, `stock_transfer_lines` | 3 nullable columns each (`pack_unit_id`, `pack_factor`, `base_quantity`), `ALGORITHM=INSTANT`, no FK, no backfill | none — schema for steps 2–4 ("schema first, code later" from the risk review) |
| `products` / `product_variants` / `product_barcodes` | unchanged | — |
| Product screen v2 (`tenant.products.create/store/edit/update`) | packs section when the switch is on | tenants with the switch on |
| Barcode uniqueness (`ProductController::guardScreenV2`, `ProductBarcodeController::store`) | also checks pack barcodes | everyone (refuses a clash that would otherwise confuse a scan) |
| Master hub "Business & switches" | one more switch | Master only |
| `DemoProvisioner`, `PharmacyDemoSeeder` | packs on for the pharmacy demo; packs seeded; open shift | demos only |
| `TenantResetTransactionsCommand` | `product_pack_units` kept as master data | — |

**Why pack barcodes live on `product_pack_units.barcode`, not `product_barcodes` (a change from the doc):**
seven existing readers load `product_barcodes` (POS payload, held sales, sales orders, product search, catalog
lookup, Edge bootstrap, recipe cost). Until step 3 teaches the POS packs, a box barcode there would add ONE
tablet at the tablet price. On the pack row, no existing screen sees it.

## Rules the server enforces (never trusts the page)

- Only with the switch on; otherwise posted packs are ignored and saved packs stay.
- Whole numbers, each pack at least 2 of the one below it; at most 3 packs; size ≤ 1,000,000.
- Pack names unique per product, ≤ 20 characters (receipt width).
- Not on a kg / litre / metre item ("packs are counted in pieces").
- A barcode used anywhere else (another product, variant or pack) is refused.
- A pack already on a sale / GRN / return line can't be removed or resized (steps 2–4 write those columns).

## Not in step 1

GRN, POS, bill, return (steps 2–4); stock shown in packs on lists and reports; distributor bonus and trade
price; the DRAP 2D code; retail-plan shift fix.
