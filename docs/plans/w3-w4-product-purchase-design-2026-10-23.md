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

## As built

### S0 — purchasing safety fixes (`6e6ea024`, no switch)

| Fix | Guarded by (`PurchasingSafetyMySqlTest`) | Sabotage → red |
|---|---|---|
| A goods receipt is posted once per form key. The same form → the first receipt; the same key with other lines → refused with a new key; no key → as before. The old screen sends a key too. | `..._one_form_key_is_one_receipt...` | KEYSKIP |
| The unique index alone still returns the first receipt (the race path) | same test | KEYRACE, a control: **stays green**, as it must |
| A voided receipt cannot be invoiced (under the receipt lock) | `test_a_voided_receipt_cannot_be_invoiced` | VOIDBILL |
| A GRN- / PAY- number already taken draws another | `..._number_already_taken_draws_another` | GRNRETRY, PAYRETRY |
| A partly received PO becomes "received" when the rest arrives | `..._partly_received_order...` | PODONE |
| A payment against another supplier's invoice is refused; that invoice stays unpaid | `..._another_suppliers_invoice...` | PAYSUP |

- `voidGrn` re-checks the status and the invoice under the receipt lock. There is no race test for this; it closes
  the gap the research found.
- Schema: `goods_receipts.submission_key` + `submission_hash`, nullable, INSTANT, with an in-place unique index. It is
  written only when the column exists.
- Related suites: 217 / 217 in 24 classes, on `6e6ea024` with a clean tree before and after (DB set `_w34`). The
  classes: PurchasingV2, SupplierNetBill, SupplierReturnMatrix, PurchaseReturnPostedOnce / Gl, every Grn*,
  SupplierFinanceDirect, SupplierRunningAccount, CashBookVoid / Report, PurchaseStockQuantity, W2Combined,
  UnitsAndPacksJourney, DecimalPacks, PharmacyDemo, InventoryExpensePurchaseStock, PurchaseItemsImport,
  SupplierLedgerDrCr, SupplierOpeningBalanceGl, PayablesFinanceGate.

### S1 — product list (`bf1d8874`, tests `36a987ad` + the role-column pin; behind `product_screen_v2`)

- With the switch off the list is today's. With it on, it is a new partial:
  - columns Product, Structure, Role (recognisable names), Behaviour badges, Packs (where packs are on), Category,
    Price, Status;
  - search, category, role and status in one row; "More filters" holds Structure and each behaviour as Any / Yes / No;
  - no Type filter, no tick-box pairs, no "old name", no "What it is";
  - Structure "Combo" lists the deals themselves, each opening the existing deal editor; a deal is never copied into
    a product.
- Tests: `ProductScreenV2MySqlTest` 19 / 19. The 19 Oct wording test was changed on purpose, and three tests are new:
  the Any / Yes / No split, deals, and packs in words. The classes that pin the switched-off list (ProductRoleFilter,
  ProductListEveryKind, CateringViewRender) are unchanged and green.
- Sabotages:
  - red: BEHSKIP, COUNTERNO, COMBO, ROLENAMES, PACKS.
  - ROLEROW (the Role column reads the shape) stayed **green** the first time. The test item's product_type was also
    "service", so reading the wrong field gave the right word. The column is now pinned on a packaging item whose
    type is "simple", and ROLEROW then turned it red.

### S2 — the product form keeps what it does not show (`94a7e792`)

- The server decides what a save may change; nothing the browser says about which sections it showed is used.
  - On an edit with the card the server picks for the item, a question that card does not ask keeps its saved
    answer: batch and expiry on a menu item; recipe on a kitchen item.
  - An item no card fits keeps its role and every saved answer, and the screen says so.
  - A saved service keeps its stock and buy answers, so one with stock history can be saved.
  - A switched-off category and purchase unit stay listed, on both screens.
  - The review step lists "Not shown for this item — kept as saved".
- Tests: `ProductScreenV2MySqlTest` 25 / 25, with 6 new open-and-save cases on the rendered page.
- Sabotages:
  - red: HIDDEN, MISFIT, SERVICE, CATEGORY, PUNIT.
  - MFGFLAGS stayed **green**. The path cannot be reached today: a misfit falls back to the business's first card,
    and no first card is a factory card. The guard is defensive; no test can turn it red.


### S3 — recipe, BOM and event costing inside the product form (`a3ab8d2b`, `f46da6d7`, `77c0613f`)

- **S3a, pins first.** `RecipeBomSaveCharacterizationMySqlTest` pins today's recipe and BOM saves, quirks included,
  before any code moved.
- **S3b, one writer per document**, shared by its own screen and the product form:

  | Writer | Moved from | What changes for that screen |
  |---|---|---|
  | `Kitchen\RecipeWriter` | `RecipeController` store / update | one transaction around header and lines |
  | `Manufacturing\BomWriter` | `BomController` store / update | one transaction; a line without a unit box saves "no unit" instead of a 500 |
  | `Catering\CateringCostBlockWriter` | `CateringCostBlockController::update` | refusals thrown (same words, same redirect back) |
  | `Catering\CateringProfileWriter` | `CateringProductProfileController` | refusal thrown; the Urdu name written only when posted (the screen always posts it) |

  Each writer also answers `unchanged()`. Sabotages on the recipe and BOM writers (13): 12 red; CONTROL, a comment
  only, green.
- **S3c, the tabs** (`App\Services\Products\ProductWorkspace`, behind `product_screen_v2`): Recipe, Bill of materials
  and Event costing, saved with the product in its own transaction.
  - **Server-decided.** A tab is offered when a card of the business needs it, the plan has the module and the user
    holds that screen's permission (`App\Support\ScreenAccess`: the two checks its route makes). The same checks run
    on every save; a crafted section without them is ignored.
  - **Untouched = unwritten.** Each tab posts a fingerprint of what it showed. An untouched tab writes nothing, so
    line and block ids stay. A tab changed on its own screen after the page opened is refused, not overwritten.
  - **Hidden settings kept:**
    - recipe numbers, notes and overhead; each line's cost override and order types;
    - the BOM's number, name, version, status and notes; each line's notes;
    - the profile's switches, pricing, labels and the Urdu name;
    - each block's rate basis, rate source and role. A block that follows the house rate book keeps its rate.
  - **All or nothing.** A refusal in any tab rolls the whole save back, the product too.
- **Tests:**
  - `ProductWorkspaceMySqlTest` 11 / 11, through the real edit page as a browser posts it.
  - The focused run with the catering, product-screen and recipe / BOM files: 134 / 134.
  - One deliberate update: `CateringMaterialRateBasisMySqlTest` now reads its source check from the writer.
- **Browser demo** (frozen verify worktree at `77c0613f`, isolated test store, 25 / 25 checks, desktop and 390 px):
  - an existing restaurant dish: Recipe tab edited (a quantity changed, an ingredient added; the line's cost override
    and order type and the report numbers kept; the price kept; a re-save left the line ids as they were);
  - a factory chair: BOM tab edited (number, version, status, notes and a line note kept);
  - a catering dish: Event costing edited (Making rate changed, a Gas block added, the station set; the book-linked
    chicken rate, the switches, label and Urdu name kept).
- **Not done in S3** (carried, not hidden):
  - Catering Materials and Manufacturing Products still open the old form;
  - the new form still shows its Urdu helper lines (decision 6: English-only);
  - no sabotage run on the S3c tabs. The run was limited to the focused tests and the demo; the tab sabotages belong
    to the batch checkpoint.

### S4 — the purchase workspace (`6d98263b`, behind `purchase_workspace`, which needs `purchasing_v2`)

- **One screen, one Post** (`/purchases/new`, `App\Services\Purchasing\PurchaseWorkspaceService`):
  - **goods arriving now:** a Goods Receipt; its Supplier Invoice now **or later** ("No — later" posts the receipt
    only); a Payment if chosen;
  - **goods already received:** a posted receipt that waits for its invoice → the invoice (and a payment if
    chosen). Its stock is never received again. One receipt = one invoice, stated on screen.
- **All or nothing.** The chosen steps post in ONE transaction, through the screens' own services
  (`GoodsReceiptService`, `PurchaseBillService::createFromReceipt`, `SupplierPayableService::recordPayment`). Any refusal
  or failure rolls every step back; the answer names the step and the screen keeps what was typed.
- **Payment against the server's balance.** The amount is checked against the invoice's own `balance_due` after
  Supplier Credit, re-read under a row lock inside the Post's transaction — never the screen's figure. Paying more is
  refused, with a link to Payments for an advance. A payment always pays this purchase's invoice: no invoice, no payment.
- **Per-step permission, server-side.** The route permission opens the screen only. On every Post each chosen step
  needs its own screen's permission and the plan's module (`ScreenAccess::allows`): `tenant.goods-receipts.store`,
  `tenant.purchase-bills.store`, `tenant.supplier-payments.store`. A missing one is refused by name before anything is
  written; the screen shows only the steps the user may do.
- **Duplicate submit.** A new delivery's receipt carries the form key (S0's unique `goods_receipts.submission_key`): the
  same Post again returns the first Post's documents; the same key with other lines is refused with a new key. For goods
  already received, the receipt's one-invoice rule (locked) refuses a second invoice by number.
- **The Goods Receipt screen** now checks and posts through `GoodsReceiptService` — the code moved, unchanged.
- **New routes** (module: purchasing, via `PermissionSyncService::MODULE_KEY_OVERRIDES`): `tenant.purchases.workspace`,
  `.store`, `.receipt`. deploy.sh grants new permissions to Owner only; other purchasing roles need an additive grant.
- **Tests:** `PurchaseWorkspaceMySqlTest` 7 / 7 (receive-only; receive + invoice + pay with the supplier, cash and GL
  once; goods already received without stock again, and a second invoice refused; overpay after Supplier Credit
  refused with nothing kept; the same Post twice; per-step permissions; no switch, no screen). Purchasing focused set:
  205 tests; the first run had 65 errors (a `store()` signature change, fixed by constructor injection) and 1 wrong
  expectation in the new test (a receipt posts no GL entry; fixed); the 10 affected classes re-run 78 / 78.
- **Browser demo** (frozen verify worktree at `6d98263b`, isolated test store, 20 / 20 checks, desktop and 390 px):
  a complete purchase (2 items, invoice, Rs 1,000 paid of Rs 1,600 → owed 600); receive now / invoice later (receipt
  only); that receipt invoiced and paid later (stock not received again); an overpayment refused naming the Payment and
  "Rs 240.00 after Supplier Credit", with nothing saved.
- **Not done in S4:** the "New purchase" tab and the single sidebar item (S5); a sabotage run on the workspace (batch
  checkpoint).

### Close-out — one form for every area, English-only, S5 navigation (`5523c42e`)

- **Areas.** Catering Materials and Manufacturing Products Add / Edit open the one product form where
  `product_screen_v2` is on (their lists unchanged). Each posts to its own routes, so its own permissions apply.
  - Manufacturing Products shows a factory's cards; a new Catering Material shows the material cards, and a dish card
    posted there is refused.
  - An item opened in an area that no offered card fits (a service in Manufacturing Products, a factory good in
    Catering Materials) keeps its role and every column on an unchanged save — tested on the rendered page.
  - Switch off: both areas open their old forms.
- **English-only** (decision 6). The Urdu helper lines are gone from the new product form, its packs step, and the
  purchasing tabs and supplier box, with the Urdu tails of this form's own messages. Urdu product names, entered data
  and Urdu / bilingual prints are untouched.
- **S5.** With the purchase workspace on, the sidebar's purchasing menu is one "Purchases" item (it opens the first
  purchasing page the role may open), and the tabs row starts with "New purchase" for a role that may open it — on the
  workspace page too. Off: today's menu. The English label fixes named in the design were not part of this close-out.
- **Tests, as they happened:**
  - focused run of 149: 148 passed, 1 failed — `ProductScreenV2MySqlTest` pinned the old rule ("the manufacturing
    context keeps its own form tonight"); deliberately updated to the new rule. This run also lost about 56 minutes
    while the laptop was in Modern Standby (10:41–11:37 local, Windows Kernel-Power log), not to the code.
  - re-run of the 3 affected classes: 43 / 43, with the new area and unchanged-role cases. (The navigation test ran in
    the 149 run, not in the re-run; the commit message says otherwise.)
- **S4 record, restated exactly:** the S4 purchasing set of 205 was NOT a clean run — 65 errors and 1 failure; after the
  fixes only the 10 affected classes were re-run (78 / 78).

### Batch checkpoint (24 Oct) — frozen revision `43598ccb`

- **Full regression** on a detached checkout of `43598ccb` in `pos-saas-verify-upv`, fresh DB set `_w34z`; the freeze
  file shows HEAD `43598ccb` and a clean tree before and after (07:12–08:49 UTC):
  - MySQL suite: **2,452 tests, 0 failures, 0 errors** (1 skipped);
  - default suite: **230 tests, 3 failures** — `CateringClientFeedbackUiRegressionTest`, `EscPosReportPayloadTest`,
    `DeliveryRiderReassignmentRegressionTest`. The same three fail identically on the production head `c5c39cf9`
    (re-run on it, 24 Oct); they predate this batch (listed since 8 Oct).
- **Targeted sabotage** (dev worktree, separate from the verification checkout; sources restored and compared, no
  marks left). Each removed one guard:
  - red as required: PRODACCESS and PWSTEP (unauthorized saves); STALE (stale edits); KEEPOVERRIDE, KEEPBOMNOTES and
    KEEPBOOKRATE (hidden settings); PWKEY (duplicate submission); PWTX and PRODTX (rollback); PAYDUE (the server's
    balance check);
  - UNTOUCHED stayed **green**: the writer's own `unchanged()` also refused the re-post, so the test could not tell the
    tab's check apart. `03e42bf7` (test only, after the freeze) adds a recipe whose saved sort orders have gaps — only
    the tab's check keeps it: sabotaged red, restored green;
  - CONTROL (a comment only) green.
- **Browser journeys** on the isolated test stores, desktop 1366 px and phone 390 px. Served from the dev worktree at
  `43598ccb` (clean, after the sabotages were restored) because the verify checkout was running the full suite:
  - S3 product workspace: 25 / 25;
  - S4 purchase workspace with the S5 navigation: 20 / 20;
  - **every new switch OFF**: 20 / 20 — Recipes, Bill of materials, Cost blocks and Catering profile screens each
    saved through their own page (through the shared writers); the old product form; the old Goods Receipt screen
    (`purchasing_v2` off) and the new one (`purchasing_v2` on, workspace off), each posting stock; today's sidebar;
    `/purchases/new` 404. The first attempt was 18 / 20: the walk set the old receipt screen's Select2 item box without
    searching, so the line was blank and the screen correctly refused it; driven as a person does, it posted.
- **Release boundary.** The switches protect the new screens only. The shared writers (Recipes, Bill of materials,
  Catering profile and cost blocks), the Goods Receipt screen on `GoodsReceiptService`, the shared entry area's hooks
  and the English-only purchasing tabs / supplier box reach existing tenants on deploy whatever their switches say.
  They were exercised with the switches off by the journey above and by the full suite; that is evidence, not a claim
  that existing tenants are unaffected.

## Release manifest (draft; nothing pushed, merged, deployed or switched on)

The table says who a part reaches on the day it is deployed. Parts without a switch reach **every tenant at once**, so
they are listed apart and need their own approval.

| Part | Commits | Reaches on deploy | Switch |
|---|---|---|---|
| **S0 purchasing safety fixes** | `6e6ea024` + migration `2026_10_24_000001_add_submission_key_to_goods_receipts` | **every tenant** | none |
| **S2 old-form part**: an edit lists the item's own category and purchase unit even when switched off | in `94a7e792` (`categoriesFor`, `unitsFor`) | **every tenant** (the old product form) | none |
| S1 product list | `bf1d8874`, `36a987ad`, `c382d488` | tenants with `product_screen_v2` on (today: demo tenants) | `product_screen_v2` |
| S2 new-form part: hidden answers kept, misfit roles kept, a saved service saveable, "kept as saved" panel | `94a7e792` | tenants with `product_screen_v2` on | `product_screen_v2` |
| **S3b screen writers**: Recipes, Bill of materials, Catering Profiles and Cost blocks save through the writers | `f46da6d7`, `77c0613f` | **every tenant** (those four screens) | none |
| S3c product-form tabs (Recipe, Bill of materials, Event costing) | `77c0613f` | tenants with `product_screen_v2` on | `product_screen_v2` |
| **S4 Goods Receipt screen** on `GoodsReceiptService` (moved, unchanged) | `6d98263b` | **every tenant** (the receipt screen) | none |
| S4 purchase workspace (`/purchases/new`) | `6d98263b` | tenants with `purchase_workspace` (+ `purchasing_v2`) on — nobody today | `purchase_workspace` |
| Close-out: areas on the one form, English-only, S5 navigation | `5523c42e` | tenants with `product_screen_v2` / `purchase_workspace` on (the purchasing tabs and supplier box lose their Urdu labels wherever `purchasing_v2` is on) | `product_screen_v2`, `purchase_workspace`, `purchasing_v2` |

What every tenant sees from the no-switch parts:

- **S0, goods receipts.**
  - A second post of the same receipt form returns the first receipt.
  - A voided receipt cannot be invoiced.
  - A taken receipt or payment number draws another.
  - A partly received order becomes "received" when the rest arrives.
  - A payment against another supplier's invoice is refused.
- **S0, schema.** The migration adds two nullable columns and a unique index to `goods_receipts`: INSTANT columns and
  an in-place index. Schema first: the code writes the key only when the column exists.
- **S2 old form.** A switched-off category or purchase unit is no longer emptied by an unchanged save.
- **S3b, the four screens.** The same saves and the same refusal words. A recipe or BOM save is now all or nothing.
  A BOM line without a unit box saves instead of giving an error page.

**Owner decision needed before any deploy.** The rollout is either new tenants only (switches on for new sign-ups) or
existing tenants too. The no-switch parts above reach existing tenants either way. If the owner wants them held back,
they need a switch, or a separate release.

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
