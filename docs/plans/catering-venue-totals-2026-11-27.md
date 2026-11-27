# VENUE-LIMIT-200-1 + CATERING-TOTALS-ORDER-1 — longer venue, Total before Discount (27 Nov 2026)

Owner, 27 Nov, two asks for Kashif Kitchen:

1. "event add edit mai venue ki limit abhe kum hai 200 words kardo"
2. On a quotation (screenshot of bulk quotation 779): "pehle total ae phr discount thora highlighted ae phr net ae …
   net total ki jaga total agae under the lines, us k neche thora highlighted discount, or k nechay net wagera advance".

## What changes

**1. Venue limit 30 → 200 characters (huroof).**
- VENUE-NAME-ONLY-1 (5 Nov) set 30 so the venue held only a hall name. The owner now finds that too short.
- The limit counts characters, not words, as before. The form's `maxlength` and the server check both read
  `CateringEvent::VENUE_MAX_LENGTH`, so only that number changes.
- 200 characters is about 30–35 words. Literal "200 words" would need about 1,200 characters, a database change
  (`varchar(255)` → `text`), and would print a paragraph on the kitchen sheet footer. Not done; the owner can ask.
- The rest of VENUE-NAME-ONLY-1 stays: an old booking's longer venue, sent back unchanged, still does not block the rest
  of the edit.

**2. Totals block on customer documents.** When a document has a discount:

```
Subtotal
Service Charges        (if any)
Other charge / Fare    (if any)
══ Total ══            = Subtotal + charges   (bold, between the two lines Net Total used to have)
[ Discount ]           highlighted: bold, amber box with dashed lines (prints in colour and in black and white)
Tax                    (if any)
Net Total              bold — the stored grand_total, unchanged
Advance / Refund / Balance (unchanged)
```

- With no discount, the document is exactly as before: Net Total sits between the lines.
- Total − Discount + Tax = Net Total. That is the stored formula (`CateringEstimateService::totals()`), so the printed
  figures always add up.

## Impact (system map, built 26 Nov `040e91f4`)

- **Tables:** none change. `catering_events.venue` is `varchar(255)`. The release, invoice and history copies of the venue are
  JSON. No migration.
- **Screens:**
  - `tenant.catering.events.create`, `.store`, `.edit`, `.update` (venue box + check).
  - `tenant.catering.documents.estimate` (single quotation / booking confirmation, also `?format=pdf`).
  - `.bulk-quotations`.
  - `.proforma-invoice` and `.final-invoice` (both render `final-invoice.blade.php`, also PDF).
  - The network print queue renders the same views.
  - Not changed: thermal `*-print` routes (they print one TOTAL line), kitchen sheet, address sheet.
- **Files:**
  - `app/Models/Tenant/CateringEvent.php` (constant).
  - `resources/views/tenant/catering/documents/partials/estimate-body.blade.php` and `estimate-style.blade.php`.
  - `resources/views/tenant/catering/documents/final-invoice.blade.php`.
- **Tests:**
  - `CateringVenueNameOnlyMySqlTest` moved to 200: 200 accepted, 201 refused, the old 48-character address now accepted;
    old over-limit venues still do not block an edit.
  - New `CateringDocumentTotalsOrderMySqlTest` renders through the real controller: quotation with discount, without
    discount, with tax, in Urdu; proforma; final invoice. It reads the totals rows in order and checks that they add up.

## Results

- Lint clean on the changed PHP and on the compiled Blade of the three views.
- 73 tests across 9 files: totals order, venue, A4 fit, meta, PDF, print, document truth, live balance, course order.
  - All green, with one exception. `test_print_routes_are_denied_without_the_catering_module` needs the master route
    catalog filled, which a fresh test master DB (`_ks`, recreated after the 4 Nov cleanup) did not have.
  - After `syncRouteCatalog()` on that test DB only, the file is 12/12 green. Not related to this change.
- Rendered locally (KK dev DB, read only) and screenshotted in Chrome:
  - Quotation with discount, English and Urdu: Total 23,320 between the lines, then Discount −220 highlighted, then Net
    Total 23,100.
  - Quotation without discount: unchanged.
