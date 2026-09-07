# Catering V1 Product Completeness and UI/UX Audit

Date: 2026-08-21  
Audit branch: `audit/catering-product-completeness-v1`  
Audit base: `5266997b6474c605012d7cfac5791cad6b3c86ed` (`origin/feat/catering-product-ux-v1`)  
Current canonical reference: `52b5c85ce669d35b00cf1f247f95774012d7e92d` (`origin/feat/14d-2-plan-upgrade-requests`)  
Method: source, Blade, route, controller, service, model, permission, document, reset, and UAT-seed inspection only. No database tests were run.

## Executive verdict

Catering V1 has a substantial and coherent booking, quotation, finance, document, and configuration surface. The event page is unusually explicit about side effects, immutable states, customer credit, refunds, and closure. Cost Blocks can express the requested food and non-food commercial shapes.

It is not yet a complete safe V1 for live use. The largest problem is not cosmetic: the commercial Cost Block decisions shown on the quotation do not feed the production requirement authority. A production release still explodes recipes, so event-specific Cost Block quantities and Customer Supplied decisions can be omitted or contradicted when stock is issued. The standalone Store Issue screen also has no aggregate required/issued/remaining authority. There are additional material risks in fixed-price behavior, block-cost estimate costing, draft customer documents, and the discoverability/terminology of the two rate books.

Estimated product completeness: **72%**. The core is broad and much of it is usable, but six release-blocking product gaps cross quotation, stock, or customer-document boundaries.

`CAT-RATE-011` remains a separate `TECHNICAL_RELEASE_BLOCKER_OWNED_BY_CLAUDE`. This audit does not attempt to fix or re-certify it.

## A. Current product map

The audit reviewed 62 named `tenant.catering.*` routes, the dashboard calendar route, and the shared customer/product lookup dependencies (65 route surfaces in total).

| Area | Route/screen | Main action | Expected next action | Terminology and states |
|---|---|---|---|---|
| Dashboard | `/dashboard` plus `/dashboard/catering-calendar` | Inspect booking calendar and overdue open events | Open booking | Draft, Quoted, Confirmed, Done, Cancelled, overdue |
| Customers | Shared `/customers` and `/ajax/customers`; selected in event form | Link an existing customer or store a walk-in snapshot | Create event | No Catering customer workspace; balances are per booking |
| Events/bookings | `/catering/events` | Filter Today/Tomorrow/7 Days/Unconfirmed; open or create | Build estimate | Event lifecycle and estimate lifecycle are separate |
| Event create/edit | `/catering/events/create`, `/{event}/edit` | Capture customer, date, time, venue, PAX, branch | Add quotation lines | Helpful clash warning and quick dates; creating posts nothing |
| Event workspace | `/catering/events/{event}` | Run quotation, finance, production, invoice, closure | State-dependent | Strong impact explanations; next action is distributed across cards |
| Estimate builder | `PUT /catering/estimates/{estimate}` | Add sellable products/free-text lines and charges | Save, review cost, send/lock | Qty, Unit, Rate, Amount; Fixed/Per PAX profile mode is not applied |
| Estimate revisions | `send`, `accept`, `revise` routes | Lock, record acceptance, clone a revision | Confirm/release | Sent and superseded history is retained and locked |
| Products | Shared Catalog product screens | Create finished/service/sellable product | Add Catering profile | Generic product/POS vocabulary remains visible in places |
| Catering Products | `/catering/profiles` | Enable product, quote unit, pricing method, costing source | Configure recipe or Cost Blocks | Recipe vs Cost Blocks is explained; Default Catering Rate remains ambiguous in block mode |
| Recipes | Shared `/recipes` surface | Define ingredient/yield authority | Select Recipe as costing source | Requires kitchen-inventory permission/module |
| Cost Blocks | `/catering/profiles/{profile}/blocks` | Define material and charge components | Switch product to Cost Blocks, then quote | Material/Charge, per dish/per material unit, per-unit/lump-sum, manual/house rate |
| Materials | `/catering/materials` and form | Create ingredient or packaging stock master | Add cost and commercial rates | Screen says never sold directly and still displays generic Sell Price in the list |
| Material Cost Rate Book | `/catering/material-rates` | Record dated expected cost | Recalculate/inspect cost impact | Screen subtitle incorrectly calls these “Commercial quote rates” |
| Commercial Material Rate Book | `/catering/commercial-rates` | Record dated house customer charge | Review impact and selectively apply | Clear once reached, but missing from the Catering sidebar |
| Cost Rate Impact | `/catering/rate-impact` | Recompute internal cost of drafts; inspect locked events | Apply selected/all drafts or revise | Cost/margin impact only |
| Commercial Rate Impact | `/catering/commercial-rates/{material}/impact` | Apply house charge to products/drafts or create revision | Review audit trail | Good distinction between calculated and agreed quoted rate |
| Customer Supplied | Event > Estimate > Cost Details | Toggle an entire material block for this event | Save/send and carry to operations | Quote snapshot is correct; production requirements do not consume this decision |
| Advances | Event > Receipts & Refunds | Record receipt | Continue work or settle invoice | Posts GL/cash-bank; capped by live balance |
| Customer credit/refund | Event > Receipts & Refunds | Pay refundable credit out | Reach zero and close | Correct direction labels and separate refund permission/document |
| Cancellation | Event header/modal | Record irreversible cancellation reason | Refund customer credit if any | Cancel modal is accidentally nested under Advance permission |
| Production release | Event > Production; `/catering/production-releases/{release}` | Freeze dish list, print kitchen sheet | Issue materials | Immutable and clearly says no stock moved yet |
| Store Issue | `/catering/store-issues` | Record actual quantities for zero/many bookings | Review recent issues | Real FEFO movement, but no required/issued/remaining comparison |
| Inventory integration | Release issue and direct Store Issue | One `InventoryService::postOutFefo` movement per line | Review immutable issue | Release path is idempotent; direct path permits legitimate repeats but cannot identify accidental repeats |
| Purchasing dependency | Existing purchasing module | Replenish stock manually | Return to release/store issue | Shortfall has no direct purchase/requisition transition |
| Final invoice | Event > Billing & Closure | Issue immutable invoice and post GL | Record final receipt/refund, then close | Clear booking-level financial position |
| Settlement/close | Event > Billing & Closure | Reach zero in either direction, close | Historical record | Close is disabled and server-refused while unsettled |
| Email/reminders | Send, advance, confirm, invoice, scheduled reminders | Send/log customer/internal email | Retry externally if failed | SMTP limitation is disclosed; no operator email-log/retry screen |
| A4 documents | Estimate, final invoice, kitchen sheet | Browser print in EN/UR/both | Hand/email document | Customer documents exclude internal cost; kitchen sheet excludes price |
| Thermal/network print | Document print jobs and kitchen routing | Queue EN customer docs; route kitchen tickets | Agent prints | Urdu is honestly refused on thermal |
| Permissions | Route-name permissions and Permission Center groups | Delegate sales, rates, finance, production, store | Operate by role | Granular model exists; sidebar/dashboard/modal wiring has role gaps |
| Tenant/module gate | `tenant.catering.*` module mapping | Fail closed unless plan entitled | Grant permissions | Correct plan gate; dashboard widget is entitlement-only, not permission-aware |
| Reset | `tenant:reset-transactions` | Remove Catering documents and keep configuration | Optional UAT seed | Catering transaction/config classification is explicit |
| UAT seed | `catering:seed-uat {tenant_code}` | Seed guarded Cost-Block-first fixtures | Manual UAT | Refuses branch server, live tenant, unlisted tenant, missing typed confirmation, and non-empty Catering documents |

### Empty, error, and locked-state summary

- Most primary screens have a real empty state and show the first validation error.
- Event and release screens explain irreversible actions and use confirmation prompts.
- Sent/superseded estimates, invoices, releases, stock issues, and cancellation history are visibly immutable.
- Several setup empty states describe the problem without giving the complete next route: Commercial Rates is not in navigation; a new Cost Block profile requires a save-configure-return loop; printer mappings tells a Catering-only tenant to copy POS mappings even when that action is hidden.
- Showing only `$errors->first()` is concise but hides additional setup errors, especially on dense product and Cost Block forms.

## B. End-to-end operator journey

### Setup

1. Create ingredients/packaging under **Catering > Materials**.
2. Define stock/purchase units and conversions in shared inventory/kitchen screens.
3. Record expected cost under the **Material Cost Rate Book**.
4. Record customer charge under **Commercial Material Rates**.
5. Create or select a sellable/service product and add a Catering Product profile.
6. Configure a recipe or first save the profile, configure Cost Blocks, return to the profile, and switch Costing Source.
7. Configure branch/station printer mappings, reminder recipient, language, and service charge.

Transition findings:

- The two rate books are not safely discoverable as a pair. Commercial Rates has no sidebar entry, while the Material Cost screen calls itself “Commercial quote rates” at the top.
- Cost Blocks explains the math well once opened, but first-time setup is circular: a new profile cannot switch to Blocks until blocks exist, and blocks cannot exist until the profile has first been saved.
- Catering Materials intentionally supports ingredients/packaging only. A directly quoted raw material needs a separate sellable wrapper product/profile, but the UI never teaches that pattern.
- There is no “setup readiness” landing page listing missing materials, units, cost rates, commercial rates, products, blocks/recipes, branch, payment mappings, or printers.

### Sales

Customer -> Event -> items -> Cost Details -> quotation -> Send/Lock -> Customer Accepted -> Confirm Booking.

- Event creation is usable: customer search, walk-in snapshot, clash hints, PAX, date/time, venue, and branch are in one screen.
- The estimate builder is fast, supports free-text lines, decimal quantities, units, Urdu labels, instructions, totals, discounts, tax, and other charges.
- Cost Details clearly distinguishes Customer charge, Kitchen uses, Costs us, Calculated rate, and Quoted rate.
- The event-level **Confirm Booking** action is available while the current estimate is still Draft and the service permits it. This creates a recoverable but confusing `confirmed event + draft estimate` combination.
- A4 and network customer documents are available while the estimate is Draft; the document carries no Draft watermark and printing does not lock it.

### Finance

Advance -> live booking position -> customer credit/refund -> final invoice -> settlement receipt/refund -> close.

- The booking screen correctly distinguishes gross received, refunds, net received, balance due, and credit owed to customer.
- Refund is separate, reasoned, permissioned, and posts money out.
- Final invoice and closure are guarded by live financial position.
- The A4 quotation independently recomputes balance from gross advances and ignores refunds.
- There is no customer-level Catering balance across bookings, and no printable/email Booking Statement or settlement receipt.

### Operations

Upcoming events -> production release -> requirements -> kitchen print -> Store Issue/Issue Materials -> execution.

- Dashboard calendar and event filters expose date, status, venue, PAX, and quotation amount.
- The production release freezes dish quantities and instructions and prints no customer price.
- The production requirement service ignores Cost Block line snapshots and uses active recipes only.
- The per-release screen shows required/on-hand/shortfall/issued/remaining, but only for that recipe-derived snapshot and only for the single release issue.
- Standalone Store Issue is operationally independent and does not show aggregate requirements for selected bookings or subtract earlier direct issues.

### Closure

Final Invoice -> record final receipt or refund excess -> Close Event -> immutable history.

- The close rule is understandable and fail-closed in both directions.
- A final invoice is an issue-time snapshot. Later settlement is visible on the event screen, but there is no updated customer-facing statement/receipt, so the printed invoice may continue to show its issue-time balance after the event is fully settled.

## C. Screen-by-screen findings

| Screen | What works | Missing/confusing/risky | Classification |
|---|---|---|---|
| Dashboard calendar | Fast calendar, clash context, overdue links | Entitlement-only display reveals customers and values without `events.index`; no finance/production/store next action | NEXT ITERATION |
| Events list | Today/Tomorrow/7-day/unconfirmed buckets | No financial, production, store, or next-action column; status alone hides work due | NEXT ITERATION |
| Event form | Compact real booking capture and clash warning | Existing customer fields silently copy into editable snapshots; no explicit “update customer master vs this booking only” explanation | POLISH |
| Event workspace | Strong end-to-end cards and side-effect language | Too many competing header actions; Confirm can precede Send/Accept; cancel modal permission defect | NEXT ITERATION |
| Estimate builder | Decimal qty/unit, Urdu, free text, charges | Fixed/Per PAX setting is not applied; block rate preview can show Default Rate before server recalculates | RELEASE BLOCKER |
| Cost Details | Best explanation of calculated/quoted/cost/material quantities | Overall Recalculate Cost/readiness uses master blocks, not these event snapshots | RELEASE BLOCKER |
| Catering Products | Search, profile summary, recipe/block switch warning | Default Catering Rate shown in block mode; new Block setup requires a three-screen loop | NEXT ITERATION |
| Cost Blocks | Expressive grammar, live preview, unit mismatch warnings | “Dish” and production language is awkward for waiter/fan/delivery/decoration; no reusable non-food examples | NEXT ITERATION |
| Materials list/form | Catering-only material access and safe enforced role | List still shows Sell Price and generic product link; says raw materials are never directly sold | NEXT ITERATION |
| Material Cost Rates | Dated history, unit, cost impact link | Header says “Commercial quote rates only,” directly contradicting the Commercial Charge book | RELEASE BLOCKER |
| Commercial Rates | Clear cost-vs-charge teaching and append-only history | No sidebar entry and excluded from Catering menu `@canany`; hard to discover | RELEASE BLOCKER |
| Cost Rate Impact | Draft-only cost/margin comparison | Name “Rate Impact” does not say Cost; separate from similarly named commercial impact | POLISH |
| Commercial Rate Impact | Strong explicit selective apply and revision path | Very dense wide tables on small screens | POLISH |
| Store Issue | Searchable materials, multi-booking references, immutable FEFO issue | No required/issued/remaining authority; no aggregate by date/selected bookings; accidental repeat is indistinguishable | RELEASE BLOCKER |
| Production Release | Good lock/print/issue separation and per-release remaining columns | Requirements come from recipes, not Cost Block/event decisions | RELEASE BLOCKER |
| Billing/closure | Correct live two-way settlement logic | No customer-facing post-settlement receipt/statement | NEXT ITERATION |
| A4 estimate | Bilingual, customer-safe commercial content | Draft prints look final; balance ignores refunds | RELEASE BLOCKER |
| A4 final invoice | Immutable snapshot, no internal cost | Advance history excludes refund history; later settlement is not represented | NEXT ITERATION |
| A4 kitchen sheet | No pricing; dish/instruction and planning sections | Requirements can contradict Cost Block quote/customer-supplied decisions | RELEASE BLOCKER |
| Printer routing | Separate from POS and station/category routing | Empty state recommends hidden POS-copy action; impact block is nested inside header flex markup | POLISH |
| Settings/Guide | Useful bilingual guide, honest SMTP/thermal limitations | Guide describes three posting actions but refunds also post; no setup checklist or email delivery history | NEXT ITERATION |

## D. Finance journey

### What is clear

- Quotation total is visible on event, event list, calendar, email, and A4 estimate.
- Advance received and refunds are shown as separate directions.
- Customer credit is not hidden as a negative balance.
- Final invoice issuance, advance application, live balance, settlement, and close rules are explicit.
- Refund requires a reason and a mapped account; receipt and refund permissions are separate.

### Gaps

1. **Quotation print balance authority is wrong after refund.** `CateringDocumentController::estimate()` passes gross advance sum, while the live booking position subtracts refunds. A reprinted customer quotation can understate the amount due.
2. **Customer account is booking-only.** The shared Customer screen has no Catering event/advance/refund/invoice roll-up. Staff cannot answer the customer’s total outstanding/credit across multiple bookings from one place.
3. **No customer-facing settlement document.** Booking Statement is screen-only; final invoice remains an issue-time snapshot after later payments.
4. **Final invoice advance history is incomplete after pre-invoice refunds.** The net advance total can disagree with the gross-only advance history printed underneath it.
5. **Cancel UI incorrectly depends on Advance permission.** A sales role with Cancel Booking but without Record Advance sees the button but receives no modal.

## E. Store/inventory journey

### Questions the current UI can answer

- What is required for one recipe-derived production release?
- What was on hand and short at release time?
- Was that release’s one-click issue posted?
- What did that issue move and what was its FEFO cost?
- What direct Store Issue documents were recently posted and against which booking references?

### Questions it cannot safely answer

- What do all events today require in aggregate?
- What do selected bookings require in aggregate?
- Which requirements came from Cost Blocks rather than recipes?
- Which quantity was overridden for this event?
- What is Customer Supplied and what must our store supply?
- How much was already issued through direct Store Issue across one or several bookings?
- What remains after partial/multiple actual issues?
- Is a second direct issue a legitimate top-up or an accidental duplicate?

### Authority mismatch

`CateringProductionReleaseService` calls `CateringRequirementService::consolidatedForEstimate()`. That service loads `product.activeRecipe.ingredients` and never reads `CateringEstimateLineCostBlock`. Therefore:

- a Cost-Block-only non-food or food item contributes no operational material requirement;
- a block-costed product with a dormant recipe contributes the dormant recipe instead of its active Cost Blocks;
- event material quantity overrides are ignored;
- Customer Supplied is ignored;
- the one-click release issue can move the wrong inventory while still being internally idempotent.

The actual mutation remains one real `InventoryService::postOutFefo` movement. The problem is the quantity authority supplied to it, not a split inventory writer.

### Aggregate requirements status

**Partially complete.** A single production release shows recipe-derived required, on-hand-at-release, shortfall, its one release issue, and remaining. Aggregate required quantities beside actual standalone Store Issue are not implemented. Multi-booking/date aggregation and reconciliation of direct issues are still a real functional gap.

## F. Non-food item readiness

### Model verdict

`NON_FOOD_ITEM_MODEL_READY=yes`. The Cost Block grammar can represent all requested shapes without a new pricing system:

| Example | Current representation | Model result | UI friction |
|---|---|---|---|
| Waiter, per unit | Sellable service profile + per-unit Charge block; quote qty = waiter count | Works | “dish,” PAX, kitchen station, and production labels are food-biased |
| Decoration, lump sum | Sellable service profile + lump-sum Charge block | Works | Pricing Method Fixed is not operational; operator must understand lump sum is the real authority |
| Tissue box, 1:1 stock | Sellable wrapper profile + Material block ratio 1 in PC/BOX | Works | Materials screen says never sold; direct raw/packaging products are excluded from estimate picker |
| Fan, per-unit rental-like | Sellable service profile + per-unit Charge block | Works | No rental/return semantics, which is acceptable for V1 charge-only use but should be stated |
| Mutton/KG sold directly | Sellable wrapper profile + Material block linked to stock mutton at ratio 1 | Works | Requires duplicate/wrapper product because Catering Materials are forced non-sellable and estimate lookup filters `sellable=1` |

The largest semantic gap is that `pricing_mode` is presentation-only. `Fixed` and `Per PAX` are stored and displayed but do not change estimate quantity or amount logic. Cost Blocks can still produce correct prices, but the visible setting is unsafe and misleading.

## G. Catalogue importer requirements

Status: **not built; intentional large-catalogue backlog; design requirements recorded here only.**

A safe importer for the approximately 1,050-row source must use a staging workflow:

1. Upload and preserve the original file plus exact `Code #`, `Description`, `Sequence`, row number, and raw text.
2. Parse into a staging table; do not write products/profiles/blocks during upload.
3. Normalize only into separate suggested fields. Never overwrite the original value.
4. Suggest a classification with confidence and reason: food dish, raw material, packaging/stock, service-per-unit, service-lump-sum, rental-like charge, delivery, or reject/expense-like.
5. Treat Sequence as a hint, not authority. Flag duplicate/missing sequence numbers and rows inconsistent with their neighboring group.
6. Flag duplicate codes, duplicate normalized descriptions, likely spelling variants, existing SKU/name conflicts, and exact prior imports.
7. Require operator choices for unit, sellable wrapper vs material, stock tracked, purchasable, product kind, quote unit, Pricing Method, Costing Source, and category.
8. For Cost Blocks, require an explicit shape: charge per unit, charge lump sum, 1:1 material, per-material ratio, or deferred configuration.
9. Never create a Material Cost Rate, Commercial Charge Rate, Default Catering Rate, purchase price, or selling price when the source supplies none.
10. Put expense-like rows such as `KASHIF FOOD DAILY EXPENCES` into Reject/Review by default, not Product.
11. Show a preview summary and row-level diff before import: create/update/skip/conflict/reject.
12. Allow bulk accept only within a reviewed classification group; preserve individual overrides.
13. Provide a dry run using the same validation as final import.
14. Final import must be atomic per accepted batch, idempotent by import/batch identity, and produce a downloadable result/error register.
15. Keep an audit link from imported records to source batch and row.

## H. Release blockers

### CAT-PROD-001 — Production requirements do not use the quoted Cost Block snapshot

**Impact:** wrong/omitted stock requirement and possible wrong issue. Customer Supplied and event overrides do not reach the kitchen/store authority.  
**Required outcome:** production release must consolidate the immutable estimate-line authority selected for each line, preserving “kitchen needs” separately from “our store issues.”

### CAT-STORE-001 — Actual Store Issue has no aggregate requirement/issued/remaining authority

**Impact:** store operator cannot determine what today’s selected events need or whether an issue is a duplicate/top-up; materially wrong stock is plausible.  
**Required outcome:** show aggregate needs by date/selected bookings, customer-supplied exclusion, prior actual issue, and remaining, while keeping one real InventoryService movement.

### CAT-COST-001 — Block-mode estimate costing ignores event snapshots

**Impact:** overall Estimated Material Cost/Margin and send readiness use master blocks, not event quantity overrides or Customer Supplied flags. It can show a wrong margin or refuse a valid customer-supplied quote for a missing business cost rate.  
**Required outcome:** internal cost/readiness must read the same frozen line snapshot the operator sees in Cost Details.

### CAT-PRICE-001 — Fixed/Per PAX Pricing Method is not executed

**Impact:** the UI promises a pricing method that no builder/service calculation applies. Fixed recipe/service items can default to event PAX and multiply unexpectedly.  
**Required outcome:** either make the mode determine safe defaults/calculation or remove/rename it so Cost Blocks/quantity are the sole visible authority.

### CAT-DOC-001 — Draft customer documents look final, and quotation balance ignores refunds

**Impact:** a mutable draft can be printed/queued and signed without a Draft marker; reprinted balance can be materially wrong after refund.  
**Required outcome:** customer distribution must lock or unmistakably watermark a draft, and all live balance displays must use the shared financial-position authority.

### CAT-RATE-UX-001 — Cost and commercial rate books are not safely discoverable/distinguishable

**Impact:** the Cost Rate screen calls itself “Commercial quote rates,” while the actual Commercial Charge Rate screen is absent from the sidebar and Catering menu grant list. An owner can put a cost in the wrong conceptual book and quote the wrong customer charge.  
**Required outcome:** expose both books side by side in navigation and use one consistent vocabulary everywhere.

### External technical blocker

`TECHNICAL_RELEASE_BLOCKER_OWNED_BY_CLAUDE=CAT-RATE-011` — internal costing snapshot can race Send and write estimated internal cost fields against a quotation that has just become Sent.

## I. Next iteration

1. Add a setup/readiness landing page with direct links and missing-data counts.
2. Add event-list operational columns or a single “Next action” indicator for finance, production, and store state.
3. Add customer-level Catering balance/history across bookings.
4. Add printable/email Booking Statement or settlement receipt; show refunds in customer document history.
5. Add a shortfall-to-Purchase Requisition/Purchase Order transition when purchasing is entitled.
6. Fix role navigation: include Store Issue, Materials, Commercial Rates, and Guide in the parent `@canany`; permission-gate the dashboard calendar; remove Cancel modal’s Advance dependency.
7. Provide non-food Cost Block examples/templates and neutral “item/output” wording where food vocabulary is not required.
8. Teach the sellable-wrapper pattern for directly charged stock materials, or provide a safe UI shortcut that creates it without inventing a rate.
9. Remove generic Sell Price and inaccessible generic Product links from the Catering Materials context.
10. Surface email delivery/log status and a permissioned retry action.
11. Make lifecycle order explicit: Send/Lock -> Customer Accepted -> Confirm Booking, or explicitly document permitted alternatives.
12. Correct printer empty states and move impact panels outside page-header flex containers.

## J. Intentional backlog

These remain intentional backlog and were not reclassified as surprise defects:

- Partial Customer Supplied
- Making Adjustment / Making Rate Impact
- Full print cost breakdown
- Global Search
- Bulk print
- Instructions enhancements
- Mark-Sent wording/polish
- Large production catalogue importer

The aggregate-requirements issue is **not** merely rediscovered backlog: the single-release table is partial, but reconciliation beside the actual Store Issue workflow remains a real functional gap and is release-blocking in combination with the Cost Block authority mismatch.

## K. Recommended order to finish Catering

1. Unify the production/requirement authority with estimate line snapshots, including Customer Supplied and event overrides.
2. Build aggregate required/issued/remaining visibility into Store Issue without introducing a second inventory mutation path.
3. Make block-mode internal costing/readiness use the same line snapshots.
4. Resolve Pricing Method behavior and validate the five non-food examples through the ordinary UI.
5. Close the customer-document boundary: draft state, refund-aware balances, and post-settlement statement/receipt.
6. Put both rate books in navigation with consistent names and a first-run setup checklist.
7. Repair role navigation/dashboard/modal permission wiring.
8. Add customer/event action summaries, purchasing transition, and email delivery visibility.
9. Run focused UI/UAT acceptance on the guarded UAT dataset after Claude completes technical/MySQL certification.
10. Only then plan the staged catalogue importer.

## Final status register

```text
BASE_CATERING_HEAD=5266997b6474c605012d7cfac5791cad6b3c86ed
CURRENT_CANONICAL=52b5c85ce669d35b00cf1f247f95774012d7e92d
CODEX_BRANCH=audit/catering-product-completeness-v1

SCREENS_REVIEWED=21
ROUTES_REVIEWED=65
WORKFLOWS_MAPPED=23

CUSTOMER_EVENT_FLOW=partial
QUOTATION_FLOW=partial
COST_BLOCK_FLOW=partial
CUSTOMER_SUPPLIED_FLOW=partial
COMMERCIAL_RATE_FLOW=partial
ADVANCE_FLOW=complete
REFUND_FLOW=complete
FINAL_INVOICE_FLOW=partial
STORE_ISSUE_FLOW=partial
PRINT_DOCUMENT_FLOW=partial

NON_FOOD_ITEM_MODEL_READY=yes
NON_FOOD_UI_FRICTION=food-biased terminology; Pricing Method is not executed; directly quoted raw/packaging material needs an unexplained sellable wrapper; no non-food templates

AGGREGATE_REQUIREMENTS_STATUS=partial: per-release recipe-derived required/on-hand/shortfall/issued/remaining exists; multi-booking/date aggregate beside actual Store Issue and Cost Block/customer-supplied reconciliation do not
CATALOGUE_IMPORT_STATUS=not-built; intentional backlog; staged preview/review/dry-run design specified

RELEASE_BLOCKERS=6 product blockers plus CAT-RATE-011 owned by Claude
NEXT_ITERATION_ITEMS=12
POLISH_ITEMS=5
INTENTIONAL_BACKLOG=8

TECHNICAL_RELEASE_BLOCKER_OWNED_BY_CLAUDE=CAT-RATE-011

YOUR_ESTIMATED_CATERING_V1_COMPLETENESS_PERCENT=72
WHY=booking, versioned quotation, finance, documents, rate books, Cost Blocks, printing, permissions, reset, and UAT foundations are broad; however the quoted Cost Block/customer-supplied authority does not reach production/store, actual issue lacks aggregate reconciliation, and customer price/document semantics still permit materially wrong outcomes

TOP_5_ITEMS_TO_FINISH=1) line-snapshot production requirements; 2) aggregate Store Issue reconciliation; 3) snapshot-based internal costing/readiness; 4) execute or remove Fixed/Per-PAX behavior; 5) lock/watermark and make customer documents finance-authoritative

PRODUCTION_MUTATED=no
KHATRI_MUTATED=no
KASHIF_MUTATED=no
DATABASE_TESTS_RUN=no
APPLICATION_CODE_CHANGED=no

FINAL_PRODUCT_VERDICT=NOT READY FOR CATERING V1 RELEASE. The breadth is strong, but quotation-to-production/store authority and customer-facing pricing/document boundaries must be closed first.
```
