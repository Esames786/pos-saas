# W3 / W4 — one product screen and one purchase workspace (design, 23 Oct 2026)

**Status: design only.** Nothing here is built. Prototype (desktop and phone, clickable):
https://claude.ai/artifact/7Two4Ssuyt8ETPpCJnPGJP (private until shared).
Branch `feat/unified-w3w4-20261023` (worktree `pos-saas-w3w4`), on top of the units-packs batch `7321428e`.
Nothing here changes a live tenant: every new screen is behind a per-tenant Master switch, OFF for live tenants.

## What users gain

1. **A product list they can read.** Three separate things, each in plain words:
   - **Structure** — Simple, With variants, Combo;
   - **Role** — Sale item, Raw material, Packaging, Finished good, Semi-finished, Service;
   - **Behaviour** — Bought, Sold, Stock counted, On the counter, Recipe-based.
   Common filters stay on one row. Advanced filters open on demand and ask each behaviour as Any / Yes / No, so two
   filters can never contradict.
2. **One product form for every business, where Add = Edit.** It opens in the shop's own business (Bingoo Master).
   Packs, batch & expiry, recipe, bill of materials and catering cost blocks are all reached from it.
   A setting the screen does not show is never cleared.
3. **One purchase workspace.** Goods received + the supplier's actual invoice + an optional payment are posted together,
   or not at all. A Purchase Order is optional, and the six purchasing places sit under one "Purchases" menu as tabs.

## The screens (see the prototype)

### Product list (`/products`, plain mode when `product_screen_v2` is on)

- **Columns:** Product (name + SKU), Structure, Role badge, Behaviour badges, Packs ("Box = 10 Strip · Strip = 10
  Tablet", "Bag = 2.5 kg"), Category, Sell price, Status, Edit.
- **Common filters:** search (name / SKU / barcode), category, role, status.
- **More filters:** structure (Any / Simple / With variants / Combo) and each behaviour as Any / Yes / No.
- **Removed:**
  - "old name:" labels and "What it is" wording;
  - the `product_type` Type filter (recipe becomes the Recipe-based behaviour);
  - opposite flag pairs such as "POS Visible + Hidden from POS";
  - BOM columns on the catering list.
- **Data:** nothing changes. Presentation only; roles and history are untouched.

### One product form (`/products/create`, `/products/{id}/edit`; v2)

- **"What are you adding?"** offers picture cards per business:
  - Pharmacy: Medicine, Store item.
  - Mart: Packed item, Loose by weight.
  - Restaurant: Menu item, Kitchen ingredient, Packing material, Resale item.
  - Catering: Dish, Ingredient, Disposable, Service.
  - Industry: Raw material, Finished good, Semi-finished, Packing.
- **"None of these fits?"** shows an explicit Role choice.
- **Editing an item no card fits** (e.g. a Packaging item in a pharmacy): a banner says its role is kept, and the role
  changes only if the user picks another.
- **Sections:** Details · Selling & stock · Packs · Batch & expiry · Recipe · Bill of materials · Event costing · Code &
  barcode · **"Not shown — kept as saved"**. That last panel lists every hidden setting with its saved value.
- **Recipe, BOM and Cost blocks** show a summary on the form ("4 ingredients · Rs 182 a plate"). Their Edit button opens
  the existing screen with this product already chosen, then returns here. No new editor is built in v1 of this slice.
- **Catering Materials and Manufacturing Products Add/Edit** open this same form, with the area preset. Their lists stay.

### One purchase workspace (`/purchases/new`, new route, `purchasing` module)

- **Header:** supplier (with "you owe Rs X · Supplier Credit Rs Y waiting"), branch, date.
- **Start from:** "New delivery" or "Goods already received". The second picks a Goods Receipt waiting for its invoice;
  its stock is not added again.
- **Purchase Order (optional):** picks an open order. Its lines show "4 of 6 Bag due", and items not on the order are
  allowed and marked.
- **Goods received:** today's entry area (scan / search, packs, batch / expiry, free goods, line and bill discount,
  extra charges).
- **Supplier Invoice:** supplier's invoice no., invoice date, due date, tax.
  - The Full / Net question appears only when goods from that receipt went back before the invoice.
  - The invoice total comes from the goods. If the paper differs, a line is fixed, not the total.
- **Payment (optional):** "Pay now" Yes / No; amount, paid from (cash / bank), reference.
  - The amount defaults to the balance due **after** Supplier Credit.
  - Paying more than is owed is refused here (advances stay under Payments).
- **Review dialog:** stock (+ per item, in base units), supplier account (invoice − credit − paid = left), cash (− paid).
  It also says: "One Post records all three together; if any part fails nothing is saved, the screen says which part,
  and everything you typed stays."
- **After Post:** Goods Receipt, Supplier Invoice and Payment numbers, each with a link; "New purchase".
- **Navigation:**
  - the sidebar's purchasing submenu becomes one "Purchases" item;
  - the existing purchasing tabs row (`purchasing/partials/tabs.blade.php`) gains a "New purchase" tab first.
- **Help:** the existing `how-to-use` partial, closed by default.

## Design decisions that close the risks found in research

**Product form**

1. **A hidden question keeps the saved value.** `ProductCards::resolve()` (Cards:137-141) treats a question the card does
   not ask as "No". On edit it will return the saved value instead. This covers `has_expiry`, `requires_batch`, the
   recipe / hybrid method and the product type. On add, the card default still applies.
2. **Kind is never coerced silently.**
   - `Cards:155` with the `detect()` fallback (:122) re-kinds any product whose kind no card allows (pharmacy
     packaging, industry service, `combo_virtual`, `semi_finished` outside industry).
   - Instead: no card is selected, the misfit banner is shown, and the saved kind stays. A changed kind must come from
     the explicit Role choice and is shown in Review.
   - A saved service with stock history is no longer unsaveable (Cards:156-160 vs `guardScreenV2`).
3. **Inactive category and purchase unit are kept.** Today they are loaded `where is_active` only (PC:140/228/684;
   `unitsFor`). The saved one is added back, labelled "(switched off — kept as saved)", the same way UB-10 did for
   `unit_id`.
4. **Absent means "not on this screen", not "false".** The controller's `!empty($data[…])` / `boolean()` pattern
   (PC:415-474) is replaced, for the v2 path, by a server-side rule. The SERVER works out which fields the chosen card
   and business may edit, from `ProductCards` and the tenant's switches. It writes only those and keeps every other
   saved value. Nothing the browser says about which sections it showed is trusted (reviewer, 23 Oct).
5. **Packs "No" deletes only when the server put the Packs step on that screen** (the packs switch on, and the card
   offers packs), and `_packs=0` came with it.
   The base row's `can_buy` is no longer rewritten on every save (PackUnits:272-276).
6. **Recipe / BOM / catering are prefilled from the product:**
   - `recipes/create?product_id=`, `manufacturing/bom/create?finished_product_id=`,
     `catering/profiles?product_id=`, each with a `return_to` back to the form;
   - the recipe link is shown only when the plan has `kitchen_inventory` and the user can open it (today it 403s on
     `restaurant_starter`).
7. **One form writes the product.** Catering Materials and Manufacturing Products Add/Edit open v2 with the area
   preset, so the old form's `@unless($isCateringMaterials)` resets (old:549-572) and the narrowed kind select
   (old:522-524) can no longer re-kind rows. Behind the switch only; with it off, today's forms stay.

**Purchase workspace**

8. **Idempotency key.** A new nullable `goods_receipts.submission_key` (unique; additive, `ALGORITHM=INSTANT`, schema
   shipped first) is used, the same pattern as purchase returns.
   - A repeat of the same Post returns the first result ("already posted — nothing was posted twice"), never a second
     receipt.
   - The old GRN screen starts sending and checking it too (it already sends one that is ignored).
9. **One outer transaction.** The workspace runs, in this order:
   1. receipt (the logic moved from `GoodsReceiptController::store` into a `GoodsReceiptService`, so both screens share
      it);
   2. `PurchaseBillService::createFromReceipt`;
   3. `SupplierPayableService::recordPayment`.
   Any exception rolls all three back. The JSON answer names the step ("receipt" / "invoice" / "payment") and the field
   to fix, and the screen keeps every typed value. A null from `JournalPostingService` is turned into an exception at
   each step, as the invoice and payment services already do.
10. **Discount and tax are not counted twice.** The bill discount stays in the item costs (entry area). The workspace
    sends invoice discount = 0 to `createFromReceipt`, and tax is its own field. The open question below decides
    whether tax may also go into cost.
11. **Payment = balance due after credit.** The default is computed after `applyCreditToBill`. The server refuses an
    amount above the balance due on this invoice. Advances go through Payments, as today.
12. **Races and stale state.**
    - `createFromReceipt` refuses a receipt that is not `posted` (voided).
    - `voidGrn` locks the receipt and re-checks for an invoice inside its transaction.
    - Receipt and payment numbers retry on a clash, as invoice numbers already do.
    - A partly received PO moves to `received` when nothing is left due.
    - The payment's invoice must belong to its supplier.
13. **Business terms exactly:** Purchase Order, Goods Receipt, Supplier Invoice, Supplier Return, Supplier Credit,
    Payment. English UI only; the off-standard labels the research listed are renamed ("Create GRN", "Create Bill",
    "Against Bill", …).

## Switches

- **Product list and form:** behind `product_screen_v2` (today on for demo tenants only). Catering Materials /
  Manufacturing Products opening v2 is also behind it.
- **Purchase workspace:** a **new** switch `purchase_workspace`, which needs `purchasing_v2`. Off by default and off for
  every live tenant. The sidebar's single "Purchases" item follows the same switch; with it off, the sidebar is today's.
- **Bug fixes** that help every tenant (receipt idempotency, voided-receipt check, the void lock, number retry,
  PO status, invoice-supplier check) are not behind a switch. They go in their own commit with their own tests, so
  they can ship first.

## Impact map (system map, 23 Oct)

- **Tables written:**
  - products, product_variants, product_pack_units, product_barcodes;
  - recipes, recipe_ingredients (via the existing screen only);
  - manufacturing_boms, manufacturing_bom_lines, catering_product_profiles, catering_product_cost_blocks (existing
    screens);
  - goods_receipts (+ `submission_key`), goods_receipt_lines;
  - purchase_bills, purchase_bill_lines, purchase_bill_return_nettings, supplier_credit_reallocations,
    supplier_credit_allocations;
  - supplier_payments, supplier_ledgers;
  - stock_ledgers, stock_balances, inventory_batches;
  - journal_entries, journal_lines, cash_bank_account_transactions;
  - purchase_orders, purchase_order_lines (status only).
- **Read:** categories, units, combos / combo_components (list only, if the owner wants Combo rows).
- **Screens and routes:** 76 routes are in scope.
  - products.* (index / create / edit / store / update), catering.materials.*, manufacturing.products.*;
  - recipes.create, manufacturing.bom.create, catering.profiles (prefill parameters only);
  - goods-receipts.*, purchase-bills.*, supplier-payments.*, purchase-orders.*, purchase-returns.*, suppliers.*;
  - **new:** `tenant.purchases.workspace` (GET) and `tenant.purchases.workspace.store` (POST), both under the
    `purchasing` module.
  - The permission is the route name. deploy.sh grants it to Owner only, so non-owner purchasing roles need an
    additive grant (standing rule).
- **Sidebar and layout:** `partials/sidebar.blade.php` (purchasing submenu), `purchasing/partials/tabs.blade.php`,
  `layouts/app.blade.php`.
- **Tests that will need deliberate updates** (each named in the commit, never silently):
  - `ProductRoleFilterMySqlTest`: roles any-of and flags all-of become tri-state behaviour; the opposite-flag cases go.
  - `ProductScreenV2MySqlTest:262-282`: the "old name: Service Item" wording goes. `:161-183` gains the kept-as-saved
    cases.
  - `PurchasingV2MySqlTest:299-357`: the receipt JSON gains `next_key` / `already_posted`; the sidebar assertions
    follow the switch; the help panel extends to the workspace.
  - `CateringViewRenderMySqlTest:275 / :372`: the materials list loses the BOM columns.
  - `ProductFormAreaMySqlTest`, `ProductArchetypeContractMySqlTest`: still pin the old form with the switch off.
- **New tests:**
  - `ProductFormKeepsSavedMySqlTest`: every hidden question, kind misfit, inactive category / purchase unit, packs not
    posted.
  - `ProductListTriStateMySqlTest`.
  - `PurchaseWorkspaceMySqlTest`: the happy path (stock, invoice, payment, GL, cash, all once); each step failing in
    turn leaves nothing; the same key twice = one of each; payment above balance refused; voided receipt refused;
    Full / Net on an existing receipt ends at the same balance.
  - `GoodsReceiptIdempotencyMySqlTest`: the old screen with the same key twice.
  - Browser journey: buy (workspace) → sell → return → reports, desktop and phone width.

## Slices, in order (focused tests per slice; one frozen full run + journeys at the end)

1. **S0 — purchasing safety fixes** (no switch): receipt idempotency key (schema first), voided-receipt check, void
   lock, number retry, PO status, invoice-supplier check. Tests: `GoodsReceiptIdempotencyMySqlTest` + the related
   existing suites.
2. **S1 — product list presentation** (v2 plain mode). Tests: `ProductListTriStateMySqlTest`, updated
   `ProductRoleFilterMySqlTest` and `ProductScreenV2MySqlTest:262`.
3. **S2 — form keeps what it does not show** (resolve keeps saved; no silent re-kinding; inactive kept; a server-decided
   editable-field rule; packs guard). Tests: `ProductFormKeepsSavedMySqlTest` + sabotage of each guard.
4. **S3 — one form for materials and manufacturing**, plus recipe / BOM / catering prefill and return. Tests: render as
   each business; catering vocabulary; a materials-form edit no longer re-kinds.
5. **S4 — purchase workspace**: `GoodsReceiptService` extraction (old screen unchanged), workspace route / screen, one
   transaction, review, switch. Tests: `PurchaseWorkspaceMySqlTest` + sabotages (drop the outer transaction, skip the
   key, pay before credit).
6. **S5 — compact navigation** (single "Purchases" item behind the switch, English label fixes). Tests: the sidebar
   render with the switch on and off, plain English.

## Decisions (reviewer, 23 Oct): these replace the open questions

1. **Deals in Products.** They show as **Structure: Combo**, with a filter. Each row opens the existing deal record and
   editor; a deal is never copied into an ordinary product.
2. **Tax on a Supplier Invoice.** The existing configured treatment stays as it is. A screen redesign changes no
   accounting policy, and nothing is inferred from filer / non-filer. S4 records what the code does today; any new
   tax-treatment choice needs its own approval.
3. **Goods already received.** This is in the workspace. Picking an existing receipt creates only its invoice and an
   optional payment, and **never receives stock again**. Today's limits stay and are stated on screen: one receipt =
   one invoice; no partial or consolidated billing.
4. **Overpayments.** Blocked in the workspace. The screen explains the remaining balance and links to the separate
   supplier-advance workflow (Payments). An overpayment is never turned into an advance silently.
5. **Recipe / BOM / catering editing.** The final product experience edits these inside the product workspace, reusing
   the existing components and services.
   - A prefilled link to the existing screen is allowed only as an interim step. It is labelled **"integration
     pending"** and never reported as "unified form complete".
   - Unsaved product changes are kept when an editor opens and when it returns.
6. **Language.** English-only interface and usage guides; the Urdu helper lines are removed. Urdu product names,
   entered data and explicitly selected Urdu / bilingual prints are kept.

## Tightened (reviewer, 23 Oct)

- **Receive now, invoice later** is an explicit workspace choice. The invoice is never compulsory. One Post is atomic
  over whichever steps the user chose: receipt only; receipt + invoice; receipt + invoice + payment; or, for goods
  already received, invoice with or without a payment.
- **Invoice differences.** Users are never told to rewrite a posted receipt to match a supplier's invoice. A difference
  the workspace cannot record is explained there, and routed to an authorized adjustment (a Supplier Return or a
  separate approved process), never to an edit of the receipt.
- **Permissions.** The workspace route permission opens the screen and grants nothing else. On every Post the server
  checks each requested step against its own permission: `tenant.goods-receipts.store`,
  `tenant.purchase-bills.store` and `tenant.supplier-payments.store`. A step the user may not do is refused with its
  name, and nothing is saved.
- **Hidden settings (S2).** A browser-supplied list of "rendered sections" is not authorization and is not used. The
  server decides which fields the chosen card and business may edit, from `ProductCards` and the tenant's switches.
  It writes only those and keeps every other saved value. Decision 1 of "Product form" above is read this way.

## Closeout boundaries carried forward

- The KOT-cancellation quantities, the partner-share quantities and a WhatsApp-specific mixed-units test stay
  **pending**. Not every report channel is described as verified.
- The original cold-database failures stay in the units-packs record. The payment / receipt number collision is a
  ticket, and S0 fixes it.
- Port 8125 (the 19 Oct pharmacy session's server) is left running unless its owner or the user says otherwise. The
  `.env` comparison is recorded in the units-packs record. The pharmacy worktree is not touched again.
- Design acceptance needs every prototype screen and important state inspected at desktop and phone widths, with
  screenshots. The link alone is not acceptance.
