# Rollout completion — counted items in parts, and one defaults policy (25 Oct 2026)

Branch `feat/rollout-completion-20261025` (worktree `pos-saas-rollout`), from production `4b745532`.

**Asked by:** the reviewer, after the UNIFIED-W3W4 deploy (`docs/status/release-deploy-2026-10-25.md`): a small patch
that completes the rollout. It is not a rebuild. **No deploy and no activation until reviewed.**

## 1. Counted items in parts — one rule for the page and the server

**Today:**
- The new purchasing entry area (PO, GRN, supplier return, the purchase workspace) refuses a part of a counted item
  (piece, bottle, box):
  - on the page: `allow_decimal` comes from the unit type alone;
  - on the server: `PackLines`, `ReturnLines` and the free quantity use the same unit-type test.
- Neither asks `whole_counted_stock`. The old screens, the stock screens and the base-quantity check ask it
  (`LineQuantity::forStock`).
- So a business that buys counted items in parts (khatribiryani: 33 receipt lines and 33 invoice lines) cannot use
  the new screens, whatever its switch says.
- The quantity words round a counted item ("2.5" shows as "3 Piece").

**The rule** (`LineQuantity::packTakesParts(unit, factor)`; the page reads the same switch):

| Item | Parts allowed? |
|---|---|
| sold by weight, volume or length | yes, up to 3 decimals, always (as today) |
| counted item, in its own unit (a pack of 1) | only while `whole_counted_stock` is OFF |
| counted item, in a bigger pack (Box of 10) | no — always typed whole (as today) |
| counted item, total in its own unit, with the switch ON | whole (`forStock`, as today; the page now says so before Post) |

**Changes:**
- `PackLines` (packs and free) and `ReturnLines` ask `packTakesParts`.
- The entry area:
  - gets the switch;
  - uses the rule for the box type, the "whole number" check, the free check and the base check;
  - shows quantities exactly (`num()`, never `Math.round`);
  - when it splits a due quantity into packs (receive from an order; the expired-batch sweep), puts what is left
    into the smallest pack when that pack takes parts. Today 2.5 due comes in as 2, for kg items as well.

**Not changed:**
- the POS (sales keep their own rule);
- units, records, balances;
- the old screens.

## 2. One defaults policy for new tenants

**Today:** self-signup and Master create set no switches. `DemoProvisioner` hard-codes `product_screen_v2`, plus
packs, `purchasing_v2`, the medical counter and return-without-bill for the pharmacy demo.

**The policy** is `App\Support\TenantFeatureDefaults::for(business, plan modules, demo)`. It returns only the switches
that start ON; their needs are always on too.

| Switch | Starts ON when |
|---|---|
| `product_screen_v2` | the plan has `catalog` |
| `purchasing_v2`, `purchase_workspace` | the plan has `purchasing` |
| `whole_counted_stock` | **business policy:** pharmacy or mart, with `inventory` or `purchasing`. Restaurants, caterers and factories buy counted items in parts, so it stays off for them. Never inferred from the purchasing module alone |
| `packs` | pharmacy or mart, with `inventory` |
| `counter_packs` | mart, with packs and `pos` |
| `medical_counter` | pharmacy, with packs and `pos` |
| `return_no_bill` | **demos only** (the pharmacy demo shows it). A live pharmacy opts in: switching on the medical counter does not authorise refunds without a bill |
| `void_purge`, `report_delivery_v2` | never (unchanged) |

**Where it is used:**
- **Self-signup** (`SelfSignupService::createPendingTrial`) and **Master create** (`TenantController@store`, when a
  plan is given). The business is not known at creation, so only the module rows apply.
- **Demo provisioning** (`DemoProvisioner::provision`). The nightly reset calls it with `fresh`, so the reset keeps
  the defaults.
- **The business is set for the first time** (`TenantBusinessService::changeBusiness`, old value empty). The
  business rows (`whole_counted_stock`, `packs`, `counter_packs`, `medical_counter`) fill **only switches the tenant
  has never set**. A switch already on, or explicitly off, is kept. Each change is recorded in
  `tenant_setting_changes`.
- **Never on an existing tenant otherwise.** No backfill and no migration. A later business or plan change leaves
  switches alone.

## 3. Impact (system map, built 24 Oct)

**Tables:**
- master `tenants.features`: written at creation and when the business is first set;
- master `tenant_setting_changes`: rows for the first business set;
- tenant `goods_receipt_lines`, `purchase_order_lines`, `purchase_return_lines`: may hold a part of a counted item
  from the new screens while the switch is off. The old screens could already write that.

**Screens:**
- `tenant.purchase-orders.create`, `tenant.goods-receipts.create`, `tenant.purchase-returns.create` (v2),
  `tenant.purchases.workspace`;
- `central.tenants.store`, `central.tenants.business.update`;
- `public.signup` (trial);
- `central.tenants.reset-demos` and `demo:reset-all`.

**Tests:**
- `PurchaseStockQuantityMySqlTest` (extended);
- new `RolloutDefaultsMySqlTest` (signup, Master create, demo provision + reset, first business set, explicit choices
  kept, other tenants untouched);
- new `TenantFeatureDefaultsTest` (default suite: the policy table);
- a local browser walk of the entry area (exact words; parts accepted with the switch off, refused with it on).
