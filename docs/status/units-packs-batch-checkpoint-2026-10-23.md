# Units & packs batch: checkpoint, 23 Oct 2026

Branch `feat/units-packs-batch-20261023` (worktree `pos-saas-unitspacks`). The tested revision is **`62e16346`**.
Plan and as-built notes: `docs/plans/units-packs-batch-2026-10-23.md`. Nothing has been pushed, merged, deployed or
switched on for any tenant.

## Completed features

| | What a user gets | Commit | Switch |
|---|---|---|---|
| F1 decimal packs | A kg / litre / metre item can have a Bag of 2.5 kg. Buy 4 Bag and 10 kg comes in; sell 1 Bag and 2.5 kg goes out. | `7308db7e`, tests `b9b6a8fd` | `packs` |
| F2 gram entry | The weight popup offers g / kg (ml / L, cm / m). 250 g sells 0.250 kg at the kg price. | `9ec6b22f`, gate `bd684f07` | `packs` |
| F3 packs at the normal counter | Scanning a carton barcode on the ordinary POS sells 1 Carton at the server's price, and 24 pieces leave stock. | `7e85f640`, fix `62e16346` | `counter_packs` (new, off by default) |
| F4 prints and reports | Receipt and sale screen: "2 Bag = 5 Kilogram" / "250 g = 0.25 Kilogram". Item report and CSV: a Unit column. Stock list and valuation: the unit, and the packs in words. Deal returns count base quantity. | `bd684f07` | unit names for everyone; pack words with `packs` |

## Evidence

**Focused tests per feature, each sabotage turning a test red, files restored.** The sabotage runs were in the dev
worktree only. Each sabotage names the guard it removes:
- F2 (4 tests):
  - GATELQ — the server ignores a posted gram entry when the switch is off;
  - GATEPOS — the weight popup offers no g / kg when the switch is off.
- F3 (5 tests):
  - PRICE, HELDPRICE — the server prices a pack line on sale and on hold;
  - PACKSON — packs work at the normal counter;
  - EXPIRY — an expired batch is refused at the normal counter;
  - PAYLOAD — the POS page carries each item's packs.
- F4 (4 tests):
  - WORDS, ENTERED — the words under a pack line and under a gram line;
  - UNIT, CSVUNIT — the unit in the item report and in its CSV;
  - DEALRET — deal returns counted in base quantity;
  - INWORDS, VALSWITCH — the pack words, and that they show only with the switch on.

**Browser journey** (local test store, headless Chrome, port 8151): 30 / 30.

Steps:
- buy 4 Bag of rice and 2 Carton of cola on the GRN screen;
- at the normal POS, scan the carton and step it with + and −;
- type 3 cartons with 48 pieces in stock: refused at once;
- sell rice as 250 g, then pay;
- check the sale screen and the receipt;
- return the carton;
- check the item report, the stock list and the valuation;
- turn the switches off: the POS is today's.

The journey found two F3 faults that the server tests cannot see. Both are fixed in `62e16346`, and with each fix
undone the journey fails:
- a scanned carton said "out of stock", because stock was looked up without the item's variant;
- a typed carton count was compared with pieces.

**Frozen full regression** on `pos-saas-verify-upv`, detached at `62e16346`, DB set `_upc` (new):

| Check | Result |
|---|---|
| HEAD, before and after (and around the re-run) | `62e16346`, 0 dirty files |
| MySQL suite | 2413 tests: 2392 passed first time, 20 red, 1 skipped (VisitorCountry GeoIP file, as in the RC) |
| The 20 reds | 3 classes that run 4th, 12th and 13th: BillingMonthlyInvoice (8 errors), CategoryBranchScope and CategoryParentGuard (12 failures). They depend on master rows that a brand-new master DB does not have yet. BillingMonthlyInvoice takes `Tenant::first()`, and there is no tenant row. The category tests find a route in `route_catalogs` that an earlier class filled, but no matching row in `modules`. |
| Baseline for the 20 | Not dismissed on a retry. The suite's first 13 classes, in the suite's order, were run on a brand-new DB set at two revisions: the batch base `edf958d9` (DB set `_upf`) and `62e16346` (DB set `_upg`). Both runs: 95 tests, 8 errors, 12 failures. The failing tests are the same 20, name for name, as in the full run. So they come before this batch and depend only on the order of a cold master. On the warm master the same 20 pass. They passed in the RC run, whose master DB was not new. |
| Default suite | 230 tests, 3 failures, the same 3 as the RC and as the production base |
| Batch-related classes in the full run | all green: CounterPacks, DecimalPacks, GramEntry, UnitsPacksPrintsReports, PackUnits, MedicalCounter, UnitsAndPacksJourney, PurchasingV2, QuantityRules, W2Combined, PurchaseStockQuantity, SalesReportEngine, ItemsByCategory, ThermalItemLayout, PharmacyDemo |

A first full run, on `bd684f07`, was stopped when the journey changed the code. It is void.


## Review corrections (23 Oct, later)

The reviewer's reading stands: the full run on `62e16346` did **not** pass outright. 20 tests failed on the first run
and passed only on a warm database. That result stays recorded above. These corrections close the gaps the review
named.

### 1. The 20 cold-database failures: the tests now make their own master fixtures (`21dd2218`, test-only)

- `SeedsMasterCatalog::ensureMasterCatalog(routes)`: when the routes or their modules are missing, the test itself
  runs MasterSeeder and `system:routes-sync`. Both are idempotent, and deploy.sh runs them on every deploy.
- BillingMonthlyInvoice now uses its own tenant and plan. Before, it borrowed `Tenant::first()`, which is none on a
  fresh master, and on a warm master it rewrote another test's subscription.
- PosHeldListDeliveryMeta had the same pattern and got the same fix.

Verified on `pos-saas-verify-upv`, detached at `21dd2218`, with no reliance on earlier tests. The commit and a clean
tree were checked before and after.

| Run | DB set | Result |
|---|---|---|
| BillingMonthlyInvoice alone | `_uh1` (new) | 8 / 8 |
| CategoryBranchScope alone | `_uh2` (new) | 7 / 7 |
| CategoryParentGuard alone | `_uh3` (new) | 5 / 5 (101 assertions: the module check now really runs) |
| PosHeldListDeliveryMeta alone | `_uh4` (new) | 5 / 5 |
| The suite's first 13 classes, in order | `_uh5` (new) | 95 tests: the 20 pass; 1 error elsewhere (below) |

**The 1 error is a separate, older defect.** `CashBookVoidMySqlTest` hit a duplicate payment number
`PAY-20261023142746-513`. Payment numbers are `PAY-<YmdHis>-<3 random digits>` with no retry, so two payments made in
the same second can collide. This is a real defect that pre-dates this batch, not a fixture problem. It is kept as its
own ticket, and the W4 plan's slice S0 fixes it: receipt and payment numbers retry on a clash, as invoice numbers
already do. The hour-long suite was not repeated for this test-only correction.

### 2. Mixed units are never one number

- **Rule (`QtyMix`).**
  - kg / g, litre / ml and metre / cm each add up in one unit, so 500 g + 1 kg = 1.5 kg.
  - Every counted unit adds up as pieces.
  - A total with one kind of unit shows a number: a count as before, a measured total with its unit ("12.5 kg").
  - Two or more kinds show their parts ("5.25 kg · 26 pcs"). The thermal print shows "mixed" in the narrow column and
    puts the parts on their own line.
  - A row built without a mix (old callers, fixtures) shows today's number.
- **Covered.**
  - Report Centre screen: overview, categories, items by category, order types and waiters, order-type combinations,
    and every total row.
  - CSV: a cell stays a number unless the total is mixed.
  - A4 and thermal print views.
  - The ESC/POS report sent to a network printer.
  - The dashboard's category card.
  - The WhatsApp detailed report.
- **Units on prints.** Wherever quantities appear, a kg / litre / metre quantity carries its unit ("0.25 kg"). A count
  stays a plain number, read as pieces, so a restaurant's prints look exactly as before.
- **Money totals.** Unchanged. The old numeric quantity fields stay for sorting and for older callers.
- **Tests.**
  - `UnitsPacksPrintsReportsMySqlTest::test_a_total_of_kg_and_pieces_is_never_one_number_and_the_prints_say_the_unit`
    covers the screen, CSV, A4, thermal, ESC/POS (no line wider than the roll) and a counted-only shelf left
    unchanged.
  - `DashboardCategoryTodayMySqlTest::test_a_category_of_kg_and_pieces_shows_its_parts_never_one_number`.
- **Sabotages** (dev worktree, each guard removed in turn, files restored, no marks left):
  - MIXLABEL: the label always a number.
  - CATMIX: no split on categories.
  - CSVCELL: the CSV cell always a number.
  - ESCTAG: the ESC/POS report ignores the split.
  - OVCSV: the overview sheet writes the split as a row.
  - DASH: the dashboard card prints a plain number.

  Each turned its test red. THERMAL (the thermal Sold column ignores the split) stayed green the first time: the test
  looked for "mixed" anywhere, and the Net column still printed it. The assertion now pins the whole Qty row, column
  by column, and THERMAL then turned it red (F4 class 5 / 5 green before it).
- **Related suites.**
  - 176 MySQL tests across the report, print, dashboard and delivery classes, plus 86 unit tests (Printing, Tenant).
  - The unit failures were 2 of the 3 known default-suite reds; nothing new.
  - The MySQL run found a real fault in this change: `ReportDeliveryV2MySqlTest` failed 3 tests. The cause was the
    e-mailed CSV's overview sheet, which wrote every overview key, the new split arrays included ("Array to string
    conversion"). It is fixed, the overview sheet is now asserted, and the re-run of the affected classes is 52 / 52.

### 3. What F4 covers, and what it does not yet

F4 is complete for the outputs listed above. It is **not** yet complete for:
- the KOT-cancellations section of the prints, whose cancelled quantity still adds all items;
- the partner-share section, which counts line quantity, with rates per menu item.

A WhatsApp detailed message with a mixed total shows its parts. That goes through the same `QtyMix::label` as the
screen, but has no test of its own.

## Remaining

- Closed after the review:
  - mixed-unit totals;
  - units on the Report Centre prints.

  See "Review corrections" above.
- Still open: the cancellations and partner-share quantities, and a WhatsApp test of its own (section 3 above).
- Separate ticket: payment and goods-receipt numbers that can collide in one second (W3/W4 slice S0).
- Next phase: the unified product form and the simple purchase workspace, with the product-list Type / Role /
  Behaviour requirements.

## Blockers

None for the code. Push, release merge, deploy, and each switch per tenant are separate approvals.

## Next demo

On a store with `packs` on (and `counter_packs` for a mart):
1. Buy 4 Bag of rice on the GRN screen; the stock shows 10 kg.
2. At the POS, sell 250 g of rice and scan a cola carton.
3. Show the receipt ("1 Carton = 24 Piece", "250 g = 0.25 Kilogram").
4. Return the carton.
5. Show the item report, the stock list ("3 Bag 2.25 Kilogram") and the valuation.
