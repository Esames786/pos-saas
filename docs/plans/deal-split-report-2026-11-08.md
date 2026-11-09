# DEAL-SPLIT-REPORT-1 — a deal's money shown under the items inside it (8 Nov 2026)

Owner, 8 Nov, with the new Signature Platters menu: platters become deals, and the report must say how much
BBQ and how much rice was sold — "900 Khas = 400 biryani + 500 BBQ; 1800 = 800 + 1000; 3600 = 1600 + 2000;
Singaporean Khas 2850 = 950 + 1900". Then: "deals mai item k rate b add kartain … option ho all item one rate /
all split rates … night reporting, sales report, quick report tamam reports pe masla to nhi aye ga";
"teekh hai banado, make sure other tenants ka kam kharab na ho".

## What exists today (checked 8 Nov)
- A deal (`combos` + `combo_components`) sells as a `combo_header` line carrying the WHOLE price and one
  `component` line per item carrying 0.00 (`sales_order_lines.parent_sales_order_line_id` → the header).
- Every report reads lines through `SalesReportEngine`: components are left out (REPORT-DEAL-COMPONENTS-1), the
  deal is filed under `COALESCE(combos.category_id, products.category_id)` (DEAL-CATEGORY-1). So a deal is one
  line under one head; nothing splits it. Partner Share (PARTNER-SHARE-1) is a payer/receiver settlement, not this.
- Khatri today: 0 deals, 0 BBQ items, no modifiers, no departments.

## The change
1. **Deal form (Products → Deals → Add / Edit, no new screen):** "How should this deal show in reports?"
   (•) One rate — as today (default) · ( ) Each item's rate — a Rs box per item, the boxes must add up to the deal's
   price. Shown only when the Master switch is on.
2. **At the moment of sale** the parts are copied onto the bill's lines (`deal_share`: header = total of parts,
   component = its part) by ONE hook, `SalesOrderLine::creating` → `DealSplit::stamp()`. Every write path (counter,
   held bill, table, split bill, website) creates lines through the model. Editing the deal later never moves an
   old report. Written only when it has a value → a switch-off tenant never names the column.
3. **Reports (only when `DealSplit::active()`):** a split deal's money goes to its items in proportion to the
   parts — `header.line_total × part / Σ parts`, rounded, the remainder on the biggest part — so a discounted
   deal splits the same way and the total is exact. Items with part 0 never appear (no "Paratha 10 @ 0").
   The deal's own head row goes away. Returns of a split deal come off the same items the same way.
   **Unchanged:** Deals section (deal × count × money), Net Sales / overview, payments, order types, waiters,
   Partner Share, Detailed, receipt, KOT, website, finance/GL (sale revenue is not posted per category).
4. **Master switch** `deal_split` ("Deal money by item in reports"), OFF everywhere; ON for khatribiryani only.
   Switch off → the engine runs its OLD code path, byte for byte.

## Impact (system map)
- Tables: `combos.report_by_items` (bool, default 0), `combo_components.report_amount` (decimal, null),
  `sales_order_lines.deal_share` (decimal, null) — migration `2026_11_08_000001`, additive.
- Write: `ComboController` store/update (+ `tenant/combos/form.blade.php`); `SalesOrderLine` creating hook (all
  sale-line writers: SalesOrderController, HeldSaleController, SplitBillController, WebsiteOrderService,
  EdgeLocalPosService — Edge: switch never on there, so nothing written).
- Read: `SalesReportEngine` (byCategory, byItem, byCategoryItems, dealsNet / SalesBridge "Plus Deals",
  orderTypeCombos, category/product-narrowed overview & dimension reports, returns) → Report Center screen / print /
  CSV / email / network slip (`EscPosPayloadService`), POS Quick Report (+ network), nightly PDF / WhatsApp
  (`SalesReportDocumentService`), dashboard "Sales by Category Today" card.
- Screens (map `used_by`): tenant.combos.*, tenant.reports.center.*, tenant.pos.quick-report.*, tenant.dashboard,
  tenant.pos.store / held-sales.store / split-bill.store / website orders (hook).
- Not touched: `byDeal`, `partnerShare`, `detailedQuery`, `SalesReportService` top products, `DepartmentReportService`,
  `WebBestSellers`, GL posting, receipt/KOT printing.

## Risks and how they are closed
| Risk | Guard |
|---|---|
| Another tenant's report changes | switch off = old code path; golden test: every section, every tenant DB available locally (kashiffood with 76 deals, tawakalkashif, khatribiryani) rendered by prod code and new code → identical |
| "Plus Deals" bridge counts a split deal twice | dealsNet counts only unsplit deals; test: Items + Plus Deals = Net Sales with split + unsplit deals |
| Deal returned | return line on the header expands to its parts; test full + partial return |
| Discount on a deal | proportional split, remainder on the biggest part; test sums exact |
| Deploy window (code before migration) | stamp writes only with a value; engine reads the column only when active() (switch + column exists) |
| Old sales | no deal_share → reported exactly as before |

## Work split (8 Nov)
- Contract (this commit): migration, models, `DealSplit`, switch.
- Agent A — deal form + controller + tests (worktree `pos-saas-dealsplit-form`).
- Agent B — report engine + bridge + returns + tests (worktree `pos-saas-dealsplit-engine`).
- Agent C — golden "nothing else moved" harness on the local tenant DBs (prod code vs new code).
- Merge, full MySQL suite (frozen worktree), system map, deploy on the owner's word, switch ON for Khatri.

## Not in this work
- Partner Share stays as it is. Website shows the deal as before. A deal edited after sale keeps the parts copied
  at the sale. Branch Server (Edge) reports a deal as one line.

## Also in this branch: DEAL-WHOLE-SPLIT-1 — Split Bill moves a deal whole (9 Nov, owner: "split bill mai to pori deal chalni chahye")
Returns already refuse a deal's item on its own (SalesReturnService). Split Bill did not: its page listed the deal's price
line and each item as rows, so the price could move to a new bill and the food stay behind, unlinked. Prod census, 90 days:
one store, 6 split bills, 1 with a deal — exactly that (header moved alone). Now (all tenants — it only stops a wrong move):
the page shows a deal as one row with its items under it; moving N deals moves the same share of every item, linked to the new
deal line; an item cannot move alone (server refuses too); a deal moves in whole deals; moved lines keep the parts they were sold
with (`DealSplit::copying`). `SplitBillController::moveLine()` is the one mover. Tests `SplitBillDealWholeMySqlTest` 8;
sabotages (refusal off / items not moved / parts not carried / re-stamping on move) each turn their test red.

## How it was built and proved (8–9 Nov)
- Contract `7762ab39`; form `b41f0553` (8 tests: switch off renders/saves exactly as before and never names a new column;
  sums refused with the gap; real POS sale + held bill stamp the parts; deploy-window test with the columns rolled back);
  engine `b79ef1eb` + `c3ab5ef3` (one rule in `SalesReportEngine`: a derived `l` / `rl` when active; 8 tests through the Report
  Center, network slip, Quick Report, nightly document and dashboard; 18 existing report files green; 5 sabotages red);
  Split Bill `8c8ee79e` + `6fb854b1`. Merge `723fe348`.
- Golden "nothing else moved": every Report Center section, CSV, A4/thermal print HTML, network slip bytes (80/58 mm), Quick
  Report, nightly document + WhatsApp parameters and the dashboard card, for 3–5 date ranges and several filters, rendered by
  production's code (19813266) and by the merge, on copies of the local kashiffood / tawakalkashif / khatribiryani DBs plus the
  real Khatri data of the 6 Nov laptop backup (30,871 bills), switch off and on, columns absent and present: **every value
  identical**. The only differences are row ORDER where production's own SQL has none (cash & bank movements, payment lines,
  items with equal totals) — production's code run twice on the same copy shows the same kind of swaps.
- Menu rehearsal (below) on a copy of Khatri's real data with the merged code and the switch on.

## Khatri menu go-live (KHATRI-MENU-NOV-1, data only, after the deploy)
Owner's answers 9 Nov: Paratha 100 / Chapati 30; Nalli one item 250; BBQ part spread over the platter's BBQ items by menu
price; BBQ / Rolls / Paratha-Chapati kitchen slips → new printer 192.168.100.218; platter rice ½ kg (Khas, 1) / 1 kg (Classic 01,
2) / 2 × 1 kg (Classic 02, 4) — everything doubles and the rice part is 80% of the counter price; Singaporean Khas = Singaporean
Rice Large; each BBQ item 1; grilled vegetables + chutney on the slip as Rs 0 items; new items + platters on the website.
- Prices: Singaporean Family Pack S 2,500→2,750, L 3,500→3,850; Haleem ½ kg 400→500, 1 kg 800→1,000 (no branch prices,
  no website prices — website follows).
- 4 categories (BBQ, Rolls, Paratha / Chapati, Signature Platters), 49 products (21 BBQ + BBQ Platter + 2 Rs 0 platter-only, 18
  rolls, Paratha, Chapati, Nalli, Changezi Matka 1 / 1.5 kg (not on the website, like the biryani matkas), Pakola 500 ml, Big Apple),
  7 platters with "Each item's rate", printer "BBQ KOT" + 12 KOT mappings (3 categories × 4 counters).
- Rehearsal on a copy of the real data: one bill (Khatri Khas + Khatri Classic 02 + Chicken Tikka Leg = 5,050) → Categories Beef
  Khatri Biryani 2,000 + BBQ 3,050 = 5,050 = Net Sales; Deals section both platters at full price; Plus Deals 0; kitchen slips:
  biryani → the counter's printer, BBQ items + vegetables + chutney → BBQ KOT, paratha → BBQ KOT; website shows the 4 new
  sections, Nalli under Extras, Pakola 500 / Big Apple under Beverages, new prices.
- Steps: deploy → laptop backup of the tenant → menu script dry run → apply → switch `deal_split` ON (khatribiryani only) →
  render checks → owner prints a test slip on the BBQ printer.
