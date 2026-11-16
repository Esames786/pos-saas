# RECEIPT-CUSTOMER-SIZE-1 — Customer / Phone / Deliver to can print bigger (14 Nov 2026)

## Ask

A client wrote: "Delivery Printer Ma Adress or phone num ka size b bara kr dy". The owner asked whether this can
come from the layout setting, since it looked hard-coded, and wanted a before/after look first. The mock-up
(artifact `5ZwJdSynyokAviWdj1sbs6`) was approved with "teekh hai". The yellow marks are for the mock-up only.

## What was there

- **Thermal bill** (`EscPosPayloadService::receipt()`): `Customer:`, `Phone:` and `Deliver to:` are printed BOLD at a
  fixed normal size. Only the item rows (`item_font_size`) and the totals (`font_size`) follow the layout.
- **Screen preview** (`tenant/printing/documents/receipt.blade.php`): the same three lines are plain `<div class="bold">`
  at the document font. This one template serves all of these:
  - POS Preview Bill (`POSController@billPreview`);
  - the table bill (`RestaurantTableSessionController`);
  - the Printing documents screen;
  - the Edit Layout live preview.
- **KOT**: phone and address are not printed on the KOT at all, so there is nothing to change there.
- **The shop's receipt layout on prod (read-only)**: font 15, item 14, customer + delivery details ON. Over 7 days
  there were 180 delivery orders. Address length: median 52 characters, 90th percentile 82, longest 118.

## What changed (no Master switch, owner's 14 Nov rule)

1. **Database:** a new nullable `receipt_layout_settings.customer_font_size` column (tenant migration
   `2026_11_14_000001`). NULL means today's bill.
2. **Edit Layout (Receipt only):** a new "Customer & Delivery Font (px)" box, with English help text, the size
   bands, and an Urdu line underneath.
   - It is hidden on KOT and Reminder layouts through `data-layout-doc`. It is not in the TOGGLE `supported`
     lists, because it is a size box, not a toggle.
   - The live preview always sends the box, even when blank, so clearing it previews today's bill.
3. **Paper** uses the same bands as every other size box:
   - 15–17: tall (`GS ! 0x01`), full 42 columns;
   - 18–20: tall and wide (`GS ! 0x11`), wrapped to 21 characters;
   - 21 and above: triple.
   - The Rider, Channel and Cashier lines are untouched.
4. **Screen:** the same three `<div>`s get `style="font-size: 1.45em"` (or `1.9em` above 20). When the box is NULL
   there is no attribute, so the markup is unchanged.
5. **Schema first:** a blank box is written only when it clears a saved size.
   - Every KOT or Reminder save posts the box blank. Without this guard, any layout save in deploy's
     pull → migrate second would name a column that does not exist yet.
   - The thermal path reads the value with `?->… ?? null`, so a model without the column prints exactly as before.

## Impact (system map, `receipt_layout_settings`)

- **Table:** `receipt_layout_settings` gets one nullable column. It has no foreign keys, and the inferred link
  `branch_id → branches` is unchanged.
- **Screens:**
  - Printing → Layouts (index / store / preview);
  - every receipt print through `PrintJobService::queueReceipt`: POS pay, reprint, the agent's pending jobs;
  - POS Preview Bill (`tenant.api.pos.bill-preview`);
  - table bill preview;
  - `tenant.printing.documents.receipt`.
  - The KOT, the Reminder, the reports sent to network and the quick-report all use `EscPosPayloadService` but
    never these lines, so they are unchanged.
- **New tenant provisioning (`TenantProvisioner`):** it does not set size columns. The new column defaults to NULL.
- **Tests:**
  - new `tests/MySql/ReceiptCustomerSizeMySqlTest.php`;
  - re-run: the print and layout MySQL files below, `ReceiptLayoutToggleIntegrityTest`, `DirectPayPrintOrchestratorTest`.

## Results (code commit `c8e333d9`)

Every run used the `_rcs` database set (`pos_test_master_rcs` / `pos_test_tenant_rcs` / `pos_test_edge_local_rcs`).

- **New test:** `ReceiptCustomerSizeMySqlTest` passed 7 tests with 44 assertions.
- **Related MySQL tests:** 14 files, 101 tests, 626 assertions, exit 0. The only warning was 1 PHPUnit deprecation,
  which comes from the PHPUnit config and existed before this change. The 14 files are:
  - ReceiptCustomerSize
  - BillBarcode
  - BillPreviewPrintTarget
  - KotDealHeading
  - KotOrderNote
  - KotTicketTime
  - LayoutRowDivider
  - ReceiptProformaVsFinal
  - MedicalCounter
  - SteakSideModifier
  - LifecyclePrintWalkthrough
  - KotSplitAndReprintWalkthrough
  - EdgeLocalPrintDelivery
  - EdgeLocalImport
- **Fast tests:** `DirectPayPrintOrchestratorTest` + `ReceiptLayoutToggleIntegrityTest` passed 7 tests with 110 assertions.
- **Blade:** every view was compiled, and `php -l` on the three changed compiled views was clean. They were also
  rendered: the preview through `ReceiptLayoutController@preview`, the bill template, and the form partial.

### Sabotage proofs (each one red, then the code was restored with `git checkout --`)

| # | Sabotage | Result |
|---|----------|--------|
| 1 | Paper ignores the size (`$customerLine` always prints the old bytes) | RED: tests 17 and 19 (2 failures) |
| 2 | Schema guard removed in `store()` | RED: the save test and the before-column test (2 errors: `Incorrect integer value ''`, `Unknown column`) |
| 3 | Preview always adds a style attribute | RED: the live-preview test and the no-size bill-preview test (2 failures) |

The system map was rebuilt from the `_rcs` databases: 239 tables, 441 foreign keys, 230 inferred links,
749 screens; `customer_font_size` is present.

## Done on prod (14 Nov 2026, ~12:50 UTC; owner: "jub test pas hogae to deploy kardena for khatri")

**Code**
- Merged as `055a4ccb` (merge of `3022d795`). `git diff` between the tested commit and the merge is empty.
- Pushed, and origin equals HEAD.
- `bash deploy.sh`: DEPLOY_EXIT=0, all 13 tenants ok, prod `2db86a82` → `055a4ccb`.

**Checks before vs after the deploy**
- **Permissions:** OK. No role lost anything and no user lost a role (all 14 connections unchanged).
- **Accounts:** OK. Every tenant's chart of accounts is exactly as before.
- **Smoke:** all 13 tenants show tb_diff=0.00, neg_on_disallowed=0, department_negative=0, before and after.
- **Log errors today:** 21 before and 21 after. The 21 were already there before the deploy: KK's catering
  reminder `SendCateringCustomerMailJob` dispatches into a tenant `jobs` table that does not exist.
  - This is unrelated to this change.
  - It has been in the log every day since at least 10 Nov.
  - It is recorded in the open items.
- **New column:** `customer_font_size` exists on all 13 tenants. It was NULL everywhere before the one write below.

**The one data change**
- Khatri receipt layout #2 (branch 1): `customer_font_size` NULL → 17. This is the only row set.

**Live proof** (bill built IN MEMORY from an unsaved PrintJob; nothing was queued: 0 queued, last job id 131856)
- Khatri's latest paid delivery sale with phone and address:
  - on paper, Customer, Phone and Deliver to all print at `GS ! 0x01` (tall); the address takes 2 lines, the
    widest 42 characters;
  - on screen, all three lines carry `font-size: 1.45em`.
- Control, another tenant with the box left NULL: on paper, no size bytes on all three lines (exactly as before);
  on screen, no style attribute.
