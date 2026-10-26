# Product-setup navigation cleanup (PRODUCT-NAV-1) — 26 Oct 2026

Branch `feat/product-nav-cleanup-20261026` (worktree `pos-saas-prodnav`), from production `50d8a8f7`.
Asked by the reviewer after ROLLOUT-1. Navigation only. **Submitted for review; not deployed.**

## What was found first

**Already in place** since W3/W4 S1–S3 and the close-out:
- **Products** is the one main entry: the list with Structure, Role and Behaviour filters, Add, and Edit.
- **Catering Materials and Manufacturing Products** are `ProductController` itself, with a context filter, their own URL
  and their own permission. With `product_screen_v2` on, their Add / Edit already open the one form, with their
  values.
- **The product form** has the Recipe, Bill of materials and Event costing tabs.

**Not fully replaced.** The tabs edit the core of each item; the specialized screens still own these fields:

| Screen | Only there today |
|---|---|
| Recipes | per-line order types, cost overrides, report numbers, overhead, notes |
| Bill of materials | BOM number, version, status, notes |
| Catering Products (profile) | on / off, pricing mode, production labels, instructions, Urdu name |
| Cost blocks | each block's role and rate source |

So their links stay, as secondary links. Hiding them would first need those fields in the tabs: a separate decision.

## Keep / Hide / Redirect

Everything below applies only with `product_screen_v2` ON. With it OFF the menu and the lists are exactly as before.

**KEEP — main entry**
- Catalog → **Products** (`/products`).

**KEEP — reference setup**
- Catalog → Modifiers, Categories, Units of Measure.
- Kitchen Inventory → Unit Conversions.

**KEEP — specialized lists, as secondary links**
- Catering → Materials (`/catering/materials`).
- Manufacturing → Products (`/manufacturing/products`).
- Kitchen Inventory → Recipes / BOM (`/recipes`).
- Manufacturing → Bill of Materials (`/manufacturing/bom`).
- Catering → Catering Products (`/catering/profiles`) and its Cost blocks screen (`/catering/profiles/{id}/blocks`).

The last four gain **"Edit in product form"**, which opens `/products/{id}/edit#recipe` / `#bom` / `#catering`. The
tabs gain links back to the full screen for the fields they do not edit. All of these open in a new tab, so the list,
or the typed form, stays where it was. Each is shown only to a user who may open its target
(`App\Support\ProductFormLink`, `ScreenAccess::allows`: permission and plan module).

**KEEP — operational, never hidden**
- Catering: events, customer credits, store issue, every rate screen, kitchen instructions, printers, settings.
- Manufacturing: production orders, requisitions, WIP, finished goods, scrap, rejections, consumption, reports.
- Purchasing; inventory movements, adjustments, transfers, counts; sales and supplier returns.
- Kitchen productions and wastage.

**HIDE** (the link only; the screen and its address stay)
- Catering → the second **Store Issue** link. The menu lists it twice; the one beside Materials stays.
- Manufacturing → **Product Categories** and **Units of Measure**. They are the same screens as Catalog → Categories /
  Units (`?context=manufacturing` only adds a banner). They need the same permission, which also shows the Catalog
  links, so nobody loses a way in.

**REDIRECT — none.** No route is removed or replaced. Every old address keeps opening at its own URL, behind its own
permission. That covers the Catering Materials / Manufacturing Products Add / Edit addresses, which already serve the
one form.

## Impact (system map)

| | |
|---|---|
| Tables | none; nothing is written |
| Screens | the sidebar (every tenant page), `tenant.recipes.index`, `tenant.manufacturing.bom.index`, `tenant.catering.profiles.index`, `tenant.catering.cost-blocks.edit`, `tenant.products.edit` (the three tabs' help lines) |
| Permissions | none added or changed |
| Tests | new `ProductNavCleanupMySqlTest`; regression on the sidebar, catering, product-screen, recipe / BOM and entitlement classes |

## Not changed

- Product roles, recipes, BOMs, rates, stock, balances, tenant permissions.
- Models, services, tables, history.
- The UI stays English-only.

## Seen on the way (not changed)

The Manufacturing menu sits inside the "ERP Extensions" block, so it shows only on the enterprise, standard and
finance_erp plans. Production has it that way today. A tenant with the manufacturing module on another plan reaches
manufacturing screens by address but not from the menu.

## Verification — commit `c9b18179`

| Check | Result |
|---|---|
| `ProductNavCleanupMySqlTest` (DB set `_pnv`) | `OK, but there were issues!` `Tests: 5, Assertions: 256, PHPUnit Deprecations: 1.` — 5 / 5 |
| Sabotage (each guard broken, then restored; tree checked clean) | 3 / 3 caught: the link ignoring the product permission; the sidebar hiding with the switch off; the Event costing tab's links ignoring their permission |
| Browser walk (local store `pnavtest`, server from this worktree), desktop 1366 + phone 390 | 19 / 19, no page errors (17 checks in the script, one of them run on 3 pages; this line first said "21 / 21" — a miscount, corrected 26 Oct) |
| Related MySQL, 24 classes on the frozen commit (sidebar, catering setup and entitlement, product screens, recipes / BOM, purchasing, Master) | `FAILURES!` `Tests: 263, Assertions: 2612, Failures: 1` — 262 passed; the one failure is below |
| `tests/Unit` | `Tests: 129, Assertions: 748, Failures: 3, Skipped: 4` — the three failures that predate this work |

**What the tests cover:**
- **Switch ON:** Store Issue once; no Categories / Units copies under Manufacturing; every kept and operational link
  present.
- **Switch OFF:** both old duplicates still there, and no new link anywhere.
- **"Edit in product form"** on all four lists for the Owner. A setup clerk with the specialized permissions but no
  product permission gets no such link, keeps all four screens, and gets 403 on product edit.
- **Tab links back** for the Owner. A product clerk who may change a recipe or a profile from the form, but not open
  those screens, gets none.
- **Old addresses:** all 16 open (200, never a redirect), switch on and off, for the Owner and the setup clerk.
  Catering Materials edit opens the one form when on, the old form when off, with the material's values.

**The browser walk:**
- **Switch ON:**
  - desktop and phone menus with Store Issue once and the Manufacturing copies gone;
  - Recipes → "Edit in product form" opens the product form on its Recipe tab in a new tab, with the list still open
    behind it;
  - the old Catering Materials address opens the one form;
  - no sideways scroll at 390 px on the dashboard, Recipes and Catering Products.
- **Switch OFF:** the menu as before (Store Issue twice, the copies there); no link on Recipes; the old form.

**The one related failure:** `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards` failed at
"catering top button = POS" (the header's top button, which this patch does not touch). It depends on test order:
- alone on this commit it passes (`Tests: 1, Assertions: 46`);
- together with `ProductNavCleanupMySqlTest` it passes (`Tests: 30, Assertions: 587`).

**It predates this patch.** The same 23 classes, without the new one, on the unchanged production base `50d8a8f7`
(DB set `_pnvb`, frozen worktree, clean before and after) fail the same way, at the same assertion:
`FAILURES!` `Tests: 258, Assertions: 2356, Failures: 1` — `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards`, "catering top
button = POS". So it is an existing test-order dependency on production, and this patch neither causes nor fixes it.
It is recorded for the backlog, not changed here.

## Release integration — 26 Oct 2026 (reviewer: "suitable for release integration")

**What this release is:** duplicate links removed, product-form shortcuts added, advanced setup screens retained.
- Three duplicate sidebar links are shown once with `product_screen_v2` ON:
  - Store Issue (twice under Catering);
  - Categories and Units (copied under Manufacturing).
- Recipes, BOM, Catering Products and Cost Blocks get "Edit in product form" (by permission); the product form's tabs
  link back.
- **Recipe, BOM and catering setup screens stay.** Some of their fields are still edited only there, so hiding them
  would remove functionality.
- Full consolidation of those remaining fields is a separate backlog item, not this release.

**Merge:**
- Isolated release worktree `pos-saas-navrel`, branch `release/product-nav-20261026`, from production `041ae000`
  (live code `6c2c6379` + its deploy record, with SUPPLIER-CREDIT-CLARITY-1).
- `git merge --no-ff origin/feat/product-nav-cleanup-20261026` (`4a99577c`) → **`f9d0174b`**. No conflicts: the two
  sides share no file.
- Proof that the merge changed nothing on either side:
  - `f9d0174b` differs from production only in the 12 PRODUCT-NAV-1 files;
  - those 12 files are byte-identical to the reviewed `4a99577c`;
  - no supplier-credit file changed.

**Checks on the merged revision `f9d0174b`** (frozen: clean tree before and after; DB set `_pnv`; real vendor):

| Check | Result |
|---|---|
| Related MySQL: the same 24 classes as above + `SupplierCreditClarityMySqlTest` (the sidebar renders on the supplier pages) | `Tests: 280, Assertions: 2768, Failures: 1` — 279 passed. The one failure is the documented baseline `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards` ("catering top button = POS"), not addressed here |
| `ProductNavCleanupMySqlTest` (inside the run above) | 5 / 5. Switch ON / OFF; Owner and the specialized setup clerk (keeps all four screens, no product link, 403 on product edit); a product clerk without the setup screens gets no back links; all 16 old addresses open (200, never a redirect) for both, switch on and off; operational links present |
| `tests/Unit` | `Tests: 129, Failures: 3` — the three that predate this work |
| Browser walk, desktop 1366 + phone 390, server from the release worktree | 19 / 19, no page errors |

No full-suite cycle: integration added no executable change beyond the reviewed 12 files. No permission, switch or
tenant-data change. Not deployed — waiting for final deployment approval.

## Deployed (26 Oct 2026) — together with COMMERCIAL-RATE-PICKER-1

- **Approval:** reviewer GO (combined release) and the owner's "deploy karo".
- **Combined revision `fd144412`** (production `041ae000` + this + the picker); the system map was rebuilt once.
- **Checks on `fd144412`, frozen:** focused tests 74/74, this walk 19/19, picker walk 14/14.
- **Deploy:** backup `20261026_175806` COVERAGE OK (13/13); pulled 18:02:06 UTC; `tenants ok=13`, `DEPLOY COMPLETE`.

**After the deploy** (read-only, GET only):
- **Books and data:**
  - smoke clean ×13;
  - `PERMISSIONS OK` (nothing gained, nothing lost — no new route);
  - no migration;
  - GL, stock and supplier sums the same on every tenant. The fingerprint's 4 lines are live POS order edits on
    kashiffood / tawakalkashif (`sales_order_lines` −5 / −8 and their kitchen-ticket lines), the same pattern as the
    earlier deploys.
- **Duplicate links removed where they existed** (new product screen ON on all 13):
  - kashifkitchen Store Issue 2 → 1;
  - demo / enterprisedemo / financedemo Manufacturing Categories + Units copies 1 + 1 → 0;
  - every other menu unchanged.
- **Specialized users and old bookmarks:** the Owner and every role holding a setup screen opened the 16 old addresses
  on every tenant, before and after. Every status is identical (39 pages on kashifkitchen across its 3 users, 96 on
  enterprisedemo, …).
- **Supplier screens:** the same as after their own deploy; restaurantdemo / retaildemo / tawakalkashif are still 403
  by their plans, as before.

**Limitations:**
- Recipe, BOM and catering Cost Blocks screens are kept and still open from their old addresses (by design). Moving
  their remaining fields into the product form is the separate backlog item.
- The baseline test failure `ProductScreenV2MySqlTest::test_each_business_sees_only_its_own_cards` remains, not
  addressed.
