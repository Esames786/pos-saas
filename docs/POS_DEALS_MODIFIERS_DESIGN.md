# POS Deals & Modifiers — Design & Build Backlog

> Status: **Prompt MOD-1 in progress.** Branch `feat/14d-2-plan-upgrade-requests`.
> This doc is the durable reference for adding product **modifiers/add-ons** and
> **deals/combos** to the POS without disturbing the order → inventory → COGS → GL
> pipeline.

## The governing constraint (why the design is shaped this way)

Every downstream system keys off **`sales_order_lines.product_id`**:

- **Inventory + COGS** — `SalesService::finalizePaidSale()` loops each line and reads
  `product.inventory_consumption_method` (`recipe` → consume ingredients, `stock_item`
  → FEFO stock-out, `none` → skip), stamping `unit_cost` / `cost_total`.
- **GL / journals** — `JournalPostingService::postPaidSale()` posts COGS/inventory from
  those line costs.
- **KOT routing** — KOT goes to the printer mapped to **each line's product category**
  (`CategoryPrinterMapping`); KOT deltas track `kot_sent_quantity`.
- **Held ↔ Complete** — a held sale is the *same* `SalesOrder` rows (`status='held'`);
  `HeldSaleController::store` deletes+rebuilds lines on each save; checkout flips status
  and runs `finalizePaidSale`. Returns/voids FK back to `sales_order_line_id` + `product_id`.

**Rule:** anything that must hit inventory/COGS/kitchen-routing/returns has to be a real
product line. So **modifiers** are annotations + price on a line (no new product lines),
and **combos** explode into real component product lines. This keeps the whole pipeline
untouched.

## Five render surfaces a line touches (must all show modifiers/combo children)

1. ESC/POS KOT — `EscPosPayloadService::kot()`
2. Browser KOT — `resources/views/tenant/printing/documents/kot.blade.php`
3. Browser receipt — `resources/views/tenant/printing/documents/receipt.blade.php`
4. KDS board — `KitchenDisplayController`
5. Table bill preview — `resources/views/tenant/restaurant/table-sessions/bill-preview.blade.php`

## Build order (decided)

**Modifiers → Discount Deals → Combos → Phase-2 polish.** Modifiers ship first: highest
kitchen value, lowest risk (no pipeline change), and they prove the rendering combos reuse.

---

### Prompt MOD-1 — Modifier catalog (config only) — *in progress*
Tables `modifier_groups`, `modifiers`, `product_modifier_group`; models; CRUD UI under
Catalog; attach groups on the product page. Branch-aware groups (`branch_id` null = all).
No POS / printing / pipeline impact yet.

- `modifier_groups`: branch_id (null=all), name, min_select, max_select (null=unlimited),
  is_required, sort_order, status.
- `modifiers`: modifier_group_id, name, price_delta, linked_product_id (null; future
  inventory hook — MOD/Phase-2), is_default, sort_order, status.
- `product_modifier_group`: product_id × modifier_group_id (+ sort_order).

**Acceptance:** define "Crust (required, pick 1)" + "Toppings (0–5)" and attach to a Pizza.

### Prompt MOD-2 — Modifiers in the POS cart
Add `modifiers` JSON to `sales_order_lines`. Modifier modal on add (enforce min/max/
required); `price_delta`s fold into `unit_price`/`line_total`; persisted via `buildInputs()`
through both store paths; survives hold + recall.

### Prompt MOD-3 — Modifiers on every render surface
Render chosen modifiers indented under the item on all five surfaces; respect
`kot_sent_quantity` delta logic.

### Prompt DEAL-1 — Discount deals (extend existing engine)
Extend the branch-aware `promotions` engine: add `buy_x_get_y`; wire the existing
`promotion_targets` (product/category) into `PromotionService`. Central CRUD.

### Prompt COMBO-1 — Combo / meal bundles (structural)
`combos` (branch-aware availability + price via the existing `ProductBranchPrice` pattern),
`combo_components`; add `parent_sales_order_line_id` (self-FK) + `line_kind`
(`standard`/`combo_header`/`component`/`modifier`, default `standard`) to `sales_order_lines`.
POS expands a combo into header + component product lines so inventory/COGS/GL/KOT-routing/
returns all keep working unchanged; renderers indent components under the header.

### Phase-2 backlog
Modifier inventory (`linked_product_id` → ingredient/stock), 86 / out-of-stock toggle,
coursing / seat numbers.

## Guardrails
- No change to `finalizePaidSale` inventory/COGS/journal logic.
- `line_kind` defaults to `standard` → all current sales behave identically.
- Every migration guarded + reversible.
- Permissions follow the route-name convention (`tenant.<module>.<action>`); after adding
  routes run `system:routes-sync` and grant Owner (see `deploy.sh` step 5).
<!-- Current local implementation status:
MOD-1 implemented: catalog tables, models, CRUD, sidebar, product attach UI.
MOD-2 implemented: sales_order_lines.modifiers, POS modifier modal, price deltas folded into cart unit_price, modifier-aware cart keys, hidden input serialization, held-sale save/recall payloads, and paid sale persistence.
MOD-3 next: render modifiers on KOT, receipt, KDS, and bill preview.
-->
