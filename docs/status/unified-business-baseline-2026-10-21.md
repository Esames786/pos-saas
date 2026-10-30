# Unified business — W0 baseline register (21 Oct 2026)

Roadmap: `pos-saas-catering/docs/plans/claude-unified-business-implementation-roadmap-2026-10-20.md` (+ screen map).
Implementation branch: `feat/unified-business-20261021` in worktree `pos-saas-qrwaiter`, from `025728d8`.

## Revisions (checked 21 Oct)

| What | Revision | Note |
|---|---|---|
| Canonical `pos-saas` (`feat/14d-2-plan-upgrade-requests`) | `025728d8` | = `origin/feat/14d-2-plan-upgrade-requests` |
| Production `/var/www/html/pos-saas` | `025728d8` | read-only `git log`; only `.env.bak-*` untracked |
| `pos-saas-packs` (returns/pharmacy session) | `a0dc6790`, 0 changed files | fully merged; the session handed off |
| System map | built at `00d15053` | behind by RETURN-NO-BILL-1; rebuilt with this work |

## Delta against the roadmap's starting point (`88f7c9f5`)

- RETURN-NO-BILL-1 merged and deployed (`025728d8`); switch "Return without a bill" ON for pharmacydemo only.
- Also live: GRN void takes back only its own batch; starter plans open shifts; tablet totals in reports;
  prescription popup; Master screen pictures.
- The W0 blocker ("active no-bill-return work") is gone: nothing half-done in another worktree.

## Ownership (owner, 21 Oct: "continue all")

This branch owns W1–W7 until the owner says otherwise. Files both sessions touch — no edits from another
session without a handoff: `SalesReturnService`, `SalesReturnController` + views, `JournalPostingService` (returns),
`SalesReportEngine`, `SalesReportService`, `UserDataScope`, `InventoryService`, `CounterPacks`, `PackLines`,
`ReturnLines`, the POS counter view, module access / role editor services, `TenantFeatures`, `DemoProvisioner`.

## Authorities (reuse, never duplicate)

Stock: `InventoryService` (postIn / postOutFefo, balance row lock). GL: `JournalPostingService`.
Supplier money: `SupplierPayableService` + running account. Returns: `SalesReturnService`, `ReturnLines`
(supplier). Packs: `PackUnits` (setup), `PackLines` (GRN/PO), `CounterPacks` (counter). Units: `Kitchen\UnitConversionService`.

## Quantity meaning per table (input to W2)

| Table | `quantity` holds | Beside it |
|---|---|---|
| `goods_receipt_lines`, `purchase_order_lines`, `purchase_bill_lines` (v2 entry) | BASE units (`quantity_received` = tablets) | `pack_unit_id`, `pack_factor` (whole or NULL), `base_quantity` = same number, `entry_json` = what was typed |
| `purchase_return_lines` (v2) | BASE units | pack, factor, `base_quantity` |
| `sales_order_lines` | PACKS for a pack line ("2" Strip), base for a plain line | `pack_factor`, `base_quantity` (NULL on a plain line), `unit_code` snapshot, `returned_quantity` in the line's own unit |
| `sales_return_lines` | same as the sale line it returns | `pack_factor`, `base_quantity`, `unit_cost`, `inventory_batch_id` |
| `stock_ledgers`, `stock_balances` | BASE units | — |
| `recipe_ingredients` | the ingredient line's own unit | converted at consumption by `UnitConversionService` |
| GRN/bill lines (all) | no unit of their own — they read `products.unit_id` | so a product unit change relabels old lines (why the roadmap forbids it) |

Rule from this: never apply one formula to both; a reader uses `base_quantity` when present, else `quantity`
(reports already do).

## Live data (prod, read-only, 20–21 Oct)

| Tenant | Returns 90 d | Purchases 90 d | Items with expiry / dated batches |
|---|---|---|---|
| khatribiryani | 294 (all with a bill) | 45 GRN, 297 lines | 0 / 0 |
| kashiffood | 180 | 0 | 0 / 0 |
| tawakalkashif | 2 | 0 | 0 / 0 |
| kashifkitchen | 0 | 18 GRN | 0 / 0 |
| pharmacydemo | 2 | 5 GRN | 41 / 47 |

Units census (20 Oct): live tenants use only "Each" (Khatri, Kashif Food, The Kashif Foods) or KG/NOS/PCS (Kashif
Kitchen); Khatri has 21 GRN lines with part quantities on 7 "Each"/no-unit items. Per the roadmap these stay
as they are (no relabel, no conversion); the readiness check will mark them.

## Owner decisions open (not blocking W1/W2 code; blocking activation where named)

1. Return without a bill — valuation: refund at most today's price; stock back at the batch's cost, else the item's
   average, else its buying price; booked against counter sales (4120). Needs a yes before any live tenant.
2. Branch tax: the pharmacy session's three questions (report page shared with the owner). The tax-number line can
   never print on the bill — print bug for W6.
3. khatribiryani item #60 is 2 units low (GRN void bug, fixed for new voids by GRN-VOID-OWN-BATCH-1).
4. Master password.
5. Scale label format (photo of one label) before any scale decoding — manual weight first.
6. First pilot tenant for each journey.

## Demo note for W7

pharmacydemo is rebuilt from scratch at 04:00 UTC: every switch a demo needs must be in `DemoProvisioner`, or it
vanishes overnight.

## Excluded

Offline Edge: no change, no claim.
