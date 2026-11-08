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
