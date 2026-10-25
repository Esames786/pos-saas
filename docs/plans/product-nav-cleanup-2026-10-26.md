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
