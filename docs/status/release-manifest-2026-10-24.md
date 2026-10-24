# Release manifest — unified W2, units & packs, W3/W4 (24 Oct 2026)

**Status:** development closed. Nothing is merged into production, pushed, deployed or switched on. Production is
`ed9a8431` (VOID-PURGE-3). The owner's rollout decision is open (see the last section).

## 1. The candidate

- Branch `feat/unified-w3w4-20261023`, head **`2740ac9f`**. Code frozen at **`43598ccb`**, which the full checkpoint ran on.
- `43598ccb → 2740ac9f` holds only two files, checked with `git diff --stat`:
  - `docs/plans/w3-w4-product-purchase-design-2026-10-23.md`: +32 lines (the checkpoint record);
  - `tests/MySql/ProductWorkspaceMySqlTest.php`: +4 lines (the untouched-tab case).
  - The added case is `test_a_recipe_edited_in_the_product_form_keeps_what_the_tab_does_not_show`. The runner gave
    `OK (1 test, 17 assertions)` as committed, and `Tests: 1, Assertions: 7, Failures: 1` with the guard it proves
    removed. It was run alone; it was not part of the frozen full run.
- The branch forked from production at `59787ff6`. It carries **58 commits** that production does not have, in three
  layers (section 3). Production has 9 commits the branch does not: VOID-PURGE-1/2/3.

## 2. Release step 1 — merge production into the candidate (not done)

A dry run (`git merge-tree`) of `ed9a8431` into `2740ac9f` conflicts in three files:

| File | Resolution |
|---|---|
| `app/Support/TenantFeatures.php` | keep both sides: production's `void_purge` and this branch's `counter_packs`, `whole_counted_stock` and `purchase_workspace` |
| `docs/system-map/index.html`, `system-map.json` | regenerate with `tools/system-map/build.php` after the merge |

This merge is a code change. The merged candidate needs its own verification before deploy: the VoidPurge and
switch-page tests, then — because money, stock and GL paths are in scope — one full run on the merged commit.

## 3. What the 58 commits contain, and whom they reach on deploy

| Layer | Commits (first → last) | What | Switch | Reaches on deploy |
|---|---|---|---|---|
| A. Unified W2 + supplier invoice | `76af4d73` → `edf958d9` (1–28) | Supplier net invoice: a return before the bill is credited once; credit moves need an authorized yes. Reset wipes the two supplier-invoice tables. kg ↔ g by rule; one stock for no-variant / default-variant rows. Server quantity rules on POS sale, hold, split and return; on purchasing and stock (W2-C rules 1, 3–5). | none, except W2-C rule 2 → `whole_counted_stock` | **every tenant**, except rule 2 |
| B. Units & packs | `306029df` → `23d80320` (29–39) | F1 decimal packs (Bag of 2.5 kg); F2 gram entry; F3 packs at the normal counter; F4 prints and reports state the unit, and a mixed kg + pieces total is never one number. | F1, F2 → `packs`; F3 → `counter_packs`; F4 units: none | F4 unit labels: **every tenant**; the rest only where the switch is on |
| C. W3/W4 | `dc1425fb` → `2740ac9f` (40–58) | S0 purchasing safety fixes; S1 product list; S2 the form keeps what it does not show; S3 recipe / BOM / event costing tabs, with writers shared with their own screens; S4 purchase workspace; close-out: area forms, English-only, S5 navigation. | S1–S3 and areas → `product_screen_v2`; S4, S5 → `purchase_workspace` | see the warning below |

Detailed records:
- `docs/status/integration-unified-w2-2026-10-22.md`
- `docs/plans/w2c-purchase-stock-quantities-2026-10-23.md`
- `docs/status/units-packs-batch-checkpoint-2026-10-23.md`
- `docs/plans/w3-w4-product-purchase-design-2026-10-23.md`

### Warning — shared code reaches existing tenants whatever their switches say

The switches protect only the new screens. These changes run for every tenant on deploy:

- **Quantity rules (W2-B / W2-C rules 1, 3–5).** The POS, holds, splits, returns, purchasing and stock lines are made
  exact to 3 decimals, and a return says what is left.
- **Supplier net invoice and credit rules.** The Full / Net question appears where goods went back before the
  invoice. A credit already paying another invoice is never moved without an authorized yes.
- **Unit labels on prints and reports (F4).** Mixed totals are shown as parts, never one number.
- **S0 purchasing safety fixes.** A receipt is posted once per form key. A voided receipt is never invoiced. Receipt
  and payment numbers are retried on a clash. A partly received order moves on. A payment must be against its own
  supplier's invoice.
- **S2, old form.** A switched-off category or purchase unit is no longer emptied by an unchanged save.
- **S3b writers.** The Recipes, Bill of materials, Catering profile and Cost blocks screens save through the shared
  writers. A recipe or BOM save is now one transaction. A BOM line without a unit saves instead of failing.
  Refusals keep their words.
- **S4 receipts.** The Goods Receipt screen checks and posts through `GoodsReceiptService` (code moved unchanged). The
  shared entry area has optional hooks that no other screen sets.
- **English-only purchasing pieces.** The purchasing tabs and the supplier box lose their Urdu labels wherever
  `purchasing_v2` is on.

These were exercised with every switch off by the checkpoint's switch-OFF journey (20 / 20) and by the full suite.
That is evidence. It is **not** a claim that existing tenants are unaffected.

## 4. Migrations, in the order deploy.sh runs them (tenant DBs)

| # | Migration | Change | Locking | Rollback limit |
|---|---|---|---|---|
| 1 | `2026_10_21_000003_create_purchase_bill_return_nettings` | new tables `purchase_bill_return_nettings`, `supplier_credit_reallocations` | create only | the tables stay; once a net invoice exists, old code no longer explains that balance |
| 2 | `2026_10_23_000001_decimal_pack_sizes` | `pack_size DECIMAL(15,4) NULL` on the line tables; **`product_pack_units.factor` → `DECIMAL(15,4)`** | columns `ALGORITHM=INSTANT`; the factor change rebuilds `product_pack_units` (small; packs tenants only) | `down()` reverts the factor only while every factor is whole. After a 2.5 kg Bag exists, roll forward only |
| 3 | `2026_10_23_000002_sale_line_entered_unit` | `entered_quantity`, `entered_unit_code` on `sales_order_lines` | `ALGORITHM=INSTANT` (no copy of 62k+ rows) | additive; old code ignores the columns |
| 4 | `2026_10_24_000001_add_submission_key_to_goods_receipts` | `submission_key`, `submission_hash` + unique index | columns `INSTANT`; index `INPLACE, LOCK=NONE` | additive; NULL on every existing receipt |

Code that writes the new columns first checks that they exist, so a tenant whose migration has not run yet keeps
working (schema-first rule).

## 5. Feature switches — exact keys (Master → tenant), all OFF by default

| Key | Needs | Turns on |
|---|---|---|
| `product_screen_v2` | — | new product list and form, Recipe / BOM / Event costing tabs; Manufacturing Products and Catering Materials Add / Edit on the one form |
| `packs` | `product_screen_v2` | packs on the product form; decimal packs (F1); gram entry (F2); pack words in reports |
| `counter_packs` (new) | `packs` | packs at the normal counter (F3) |
| `whole_counted_stock` (new) | — | W2-C rule 2: counted items whole in purchasing and stock |
| `purchasing_v2` | — | new PO / GRN / Return screens and the purchasing tabs |
| `purchase_workspace` (new) | `purchasing_v2` | `/purchases/new`, the "New purchase" tab and the single "Purchases" sidebar item |
| `void_purge` | — | production's; comes back with the release step 1 merge, unchanged |

## 6. Permissions

- **New:** `tenant.purchases.workspace`, `tenant.purchases.workspace.store` and `tenant.purchases.workspace.receipt`
  (module: purchasing, via `PermissionSyncService::MODULE_KEY_OVERRIDES`).
- **deploy.sh grants new permissions to the Owner role on every tenant** (standing behaviour). The screen still
  answers 404 until `purchase_workspace` and `purchasing_v2` are on.
- **No other role gets them** until the owner names the roles. Each step of a Post still needs that step's own
  permission (`goods-receipts.store`, `purchase-bills.store`, `supplier-payments.store`). Grants are additive only
  (`givePermissionTo`, never `syncPermissions`), then `system:clear-tenant-permission-cache`.
- **Existing permissions are preserved.** Take a snapshot of every role and user before deploy and compare after;
  the expected difference is the Owner's 3 grants and nothing else.

## 7. Backup and reconciliation checks

**Before deploy:**
- `tenants:backup` for every live tenant: kashiffood, kashifkitchen, khatribiryani, tawakalkashif, mohsin. The
  no-switch changes reach all of them.
- The permission snapshot.
- The smoke baseline per tenant: `tb_diff`, `neg_on_disallowed`, `department_negative`.
- Row counts per tenant: `goods_receipts`, `purchase_bills`, `supplier_payments`, `recipe_ingredients`,
  `manufacturing_bom_lines`, `catering_product_cost_blocks`, `product_pack_units` (+ the factor values).

**After deploy:**
- Every tenant migrated; deploy.sh reports `skipped=0`.
- The same smoke: `tb_diff = 0`, no new negatives.
- Row counts unchanged. Deploy writes no business rows; only live sales may add rows during the window.
- The permission diff: Owner +3 only.
- Read-only page loads, switches off: Recipes edit, BOM edit, Cost blocks, Catering profiles, Goods Receipt create,
  the product form. `/purchases/new` answers 404.

## 8. Rollback limits

- **Code.** Revert the release merge commit and deploy; deploy.sh pulls origin. Safe only **before** any tenant uses
  the new features.
- **Real documents stay.** Purchase-workspace documents, decimal packs, net invoices and credit reallocations are
  real records. A code rollback does not remove them, and old code would mis-read some of them, so after first use:
  roll forward only. Undo a document through its void or return, never by code.
- **Schema stays.** All of it is additive except the `factor` type (section 4); none of it is dropped on rollback.

## 9. Test evidence — the runners' own summaries

- **Checkpoint** on frozen `43598ccb` (`pos-saas-verify-upv`, fresh DB set `_w34z`; the freeze file shows the same
  HEAD and a clean tree before and after):
  - MySQL: `OK, but there were issues!` `Tests: 2452, Assertions: 15518, PHPUnit Deprecations: 1, Skipped: 1.` →
    **2,451 passed, 1 skipped, 2,452 run**;
  - default: `FAILURES!` `Tests: 230, Assertions: 35424, Failures: 3, Skipped: 4.` → **223 passed, 3 failed,
    4 skipped**.
- **The three default failures predate this work.** They fail the same on production `c5c39cf9` (re-run 24 Oct):
  - `Tests\Unit\CateringClientFeedbackUiRegressionTest::test_the_event_screen_starts_with_the_sidebar_collapsed`
  - `Tests\Unit\Printing\EscPosReportPayloadTest::test_it_renders_the_requested_sections_with_two_decimal_money`
  - `Tests\Unit\Tenant\DeliveryRiderReassignmentRegressionTest::test_reassignment_is_routed_guarded_audited_and_visible`
- **Targeted sabotage.** 11 cases plus a control: 10 red the first time; UNTOUCHED fixed by `03e42bf7` (above);
  CONTROL green.
- **Journeys** (desktop and 390 px):
  - S3: 25 / 25;
  - S4 with S5: 20 / 20;
  - every switch off: 20 / 20. The first attempt was 18 / 20: a walk-script fault with a Select2 box; the app was
    correct.
- **Not clean, recorded as such:**
  - the S4 purchasing set of 205 had 65 errors + 1 failure; after the fixes only the 10 affected classes were re-run
    (78 / 78);
  - the close-out focused run had 148 / 149 (one old-rule test, deliberately updated); then 43 / 43.

## 10. Open items carried into the release

- **The three pre-existing default-suite failures** (section 9).
- **English label fixes in purchasing** ("Create GRN", "Create Bill", "Against Bill" …): named in the design, not done.
- **Units & packs remaining:** KOT-cancellation and partner-share quantities, and a WhatsApp mixed-units test (see the
  units-packs record).
- **RESET-BALANCES-1:** `tenant:reset-transactions` keeps stored balances. It is its own defect and decision.
- **Rollout:** the owner's decision (below).

## 11. Rollout

**Reviewer's recommendation (24 Oct):**
1. Deploy with every new screen OFF.
2. Verify the existing workflows (section 7, plus the switch-OFF journey on a production-like tenant).
3. Activate one approved demo / pilot tenant.
4. After acceptance, make the switches the default for newly created tenants.
5. Roll out existing tenants in controlled groups.

**Owner decision needed:** is the new design ultimately for **new tenants only**, or for **existing tenants as well**?
Either way, the shared-code changes in section 3 reach existing tenants on deploy. If the owner wants those held
back, they need a switch or a separate release.
