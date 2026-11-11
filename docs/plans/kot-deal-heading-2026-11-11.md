# KOT-DEAL-HEADING-1 + DEAL-BILL-NO-VARIANT-1 — 11 Nov 2026

Owner, 11 Nov (a client asked for it):

1. **Bill.** Under a deal, the bill printed one of the deal's items in brackets (`Changezi Classic 01` / `(Changezi Pulao Rice)`).
   The bill should name the deal and nothing else.
2. **KOT.** Add a layout switch so a deal prints its name ONCE with its items under it, instead of every item on its own
   boxed row with `(Deal)` under each one. The deal name is only slightly bigger than a plain item. An item that belongs to
   another category, such as the Paratha, keeps going to its own slip, under the deal name. Category routing does not change.
   The owner asked for a preview.

## Why the bill showed the rice

The POS sends a deal line with the product of the deal's FIRST item (`POSController::posCatalog` → `header_product_id`) and
no variant. `SalesOrderController` then resolves the variant with `resolveVariant($product, null)`, which is the product's
**default** variant, and saves it on the deal line. Khatri's platters start with the hidden rice items (`Khatri Rice`,
`Changezi Pulao Rice`), whose default variant carries the same name. The bill prints any variant that differs from the line's
name, so the rice appeared under the deal.

**Fix (display only):** a bill never prints a variant under a `combo_header` line. This covers the thermal bill
(`EscPosPayloadService::receipt`), the browser bill and the POS Bill / Preview (`receipt.blade.php`), the table bill preview
(`pos/partials/table-bill-preview`) and the table-session bill preview. The saved data is NOT changed. Stock, reports and
held-order recall read that variant, and changing what is saved would cascade into them for no gain. Old bills reprint
correctly too.

## KOT switch

- Column `receipt_layout_settings.show_deal_as_heading`, boolean, default **false**. Migration
  `2026_11_11_000001_add_deal_heading_toggle`; a boolean with a default is an instant change on MySQL 8.
- Switch label: "Deal name on top, its items under it". It is in TOGGLE_FIELDS, the save validation, the form (default OFF)
  and the KOT document's switch list.
- `App\Support\KotDealGroups::build()` sorts one ticket's lines into plain items and deal groups, in print order. Both the
  thermal KOT and the browser KOT use it, so the preview and the paper stay the same.
  - The header is found by the parent id.
  - For a cancel or reprint snapshot whose line ids are gone, the header is found by the combo, and the deal is counted from
    the same item under the same deal.
- Sizes:
  - Deal name: one step taller than the Item Row Font, never wider.
  - Deal items: one step shorter, at normal width, indented 4 characters, no rule between them, one rule after the deal.
  - Plain items: `kotItemRow()`, exactly the old code.
  - With Khatri's settings (item 17 px, which prints 1×2), the deal name prints 1×3 and its items 1×1.
- Deal count:
  - The first item's quantity on the ticket divided by its per-deal quantity, so `2 x` for two platters and `(R) 1` for an
    addition.
  - If the count does not come out a whole number, it is left blank. A wrong count is never printed.
- Edit Layout preview:
  - With the switch ON, it borrows the latest paid sale that has a deal, the same way Delivery Details borrows a delivery.
  - It now also passes the deal names, so the OFF preview shows `(Deal)` as the paper does. Before this it never named a deal.

## Impact (system map, build 10 Nov)

- Table: `receipt_layout_settings` gets one column. Model `ReceiptLayoutSetting`.
- Screens: Printing → Layouts (index, store, preview), the print-job document preview, POS KOT printing on every counter, and
  the bill previews. Routing (`PrintRoutingService`, `category_printer_mappings`) is not touched. Reminder is not touched.
- Other tenants: the switch is OFF, so their KOT bytes are identical. The bill change applies to everyone and only removes a
  variant printed under a deal line.

## Tests

- New `KotDealHeadingMySqlTest` covers:
  - OFF behaves as before.
  - ON gives one heading with its items under it, and the expected sizes in bytes.
  - Paratha and rice go on their own slips.
  - Two platters, then an addition.
  - The cancel snapshot.
  - A reprint after the bill was saved again.
  - The browser KOT and the Edit Layout preview.
  - The switch saves.
  - The bill shows no variant under a deal.
- Related files re-run: `ComboKotDealName`, `KotOrderNote`, `LayoutRowDivider`, `ThermalItemLayout(Blade)`, `KotReprintBlank`,
  `KotSplitAndReprintWalkthrough`, `KotTicketTime`, `BillBarcode`, `BillPreviewPrintTarget`, `ReceiptProformaVsFinal`,
  `LifecyclePrintWalkthrough`, `SteakSideModifier`, `MedicalCounter`, `VehicleNumber`, `DeliveryCharge`, `ChangeAmount`,
  `UnitsPacksPrintsReports`, `PosScreenRendersHttp`; also Unit/Printing, the toggle-integrity unit test and Feature/Printing.

## Preview

The real Khatri orders HS-20261109152203-285 and HS-20261110130228-286 were rendered on prod with the new code, read-only. The
classes were loaded under a separate namespace and the switch was forced in memory. Nothing was saved and nothing was printed.
The page was shared with the owner.

## Deploy notes

- Column and code ship in one deploy. Live code only READS the column: a missing column reads OFF. The only writer is the
  Edit Layout save, by an operator. A save landing in the 1–2 s between pull and migrate is the only exposure; it is
  acceptable and would show a visible error, not lose data.
- After the deploy, turn ON for khatribiryani only: its KOT layout row (branch 1, `document_type = 'kot'`)
  `show_deal_as_heading = 1`.

## Done on prod — 11 Nov 2026

- **Deployed** `6f92144b` (merge of `7230a7b3`) at about 09:06 UTC on the owner's "deploy kardo". `git diff 7230a7b3 HEAD` was
  empty, and origin matched HEAD before `deploy.sh`.
- Before and after checks:
  - Permissions: no role lost anything.
  - Chart of accounts: identical.
  - Smoke: 13/13 tenants with `tb_diff=0`, `neg_on_disallowed=0`, `department_negative=0`.
  - Column `show_deal_as_heading` exists on all 13 tenants, and was ON nowhere before the switch below.
  - 0 `production.ERROR` after 09:00 UTC. The 6 earlier ones are from 00:00 UTC: kashifkitchen's missing `jobs` table, an
    old issue.
- **Switch ON for khatribiryani only** (owner: "khatri pe ye switch on kardena"): KOT layout row #1 (branch 1),
  `show_deal_as_heading = 1`. Every other tenant stays OFF.
- Proof, re-rendered through the live code (nothing printed):
  - BBQ slip of HS-20261109152203-285: `1 CHANGEZI CLASSIC 01` with its 5 items under it and one rule after them.
  - Its bill shows no `(Changezi Pulao Rice)`.
  - The Edit Layout preview shows 1 deal heading and 6 items.
- Tests:
  - `KotDealHeadingMySqlTest` 9/9.
  - 19 related MySQL files green.
  - Unit/Printing: 1 failure, `EscPosReportPayloadTest` (Sales Report money format). It fails the same way on production
    code `beff20a9`, so it was already broken; it is not this change.
- Sabotage: removing the bill guard, or removing the recipe fallback, turns its test red.
