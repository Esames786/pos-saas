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

## Remaining

- The open points in the plan:
  - category and order-type rows still add quantities across units, as today;
  - the report-centre print and thermal views have no Unit column.
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
