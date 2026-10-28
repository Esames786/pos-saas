# Release manifest — unified W2, units & packs, W3/W4 (24 Oct 2026)

**Status:** the production-merge candidate is built and verified. Nothing is merged into the production branch, pushed,
deployed or switched on. Production is `ed9a8431` (VOID-PURGE-3). **Deployment needs approval.** The owner's rollout
decision is also open (section 11).

## Read before approving — limitations and rollback restrictions

- **Shared code reaches every tenant on deploy, whatever its switches say** (section 3, warning).
- **One live tenant already has the new product screen switch on.** kashiffood has `product_screen_v2` and
  `purchasing_v2` on today. It gets the new product list, form tabs and English-only purchasing pieces on deploy
  (section 5a). Its switches are kept as they are, not turned off.
- **One schema change is not additive.** `product_pack_units.factor` changes from a whole number to a decimal; the
  table is copied (section 4a). Rollback works only while every factor is whole.
- **After first use, roll forward only.** New documents (workspace postings, decimal packs, net invoices, credit
  moves) are real records. A code rollback does not remove them (section 8).
- **Known gaps** (section 10):
  - 3 default-suite failures that predate this work;
  - English label fixes in purchasing not done;
  - the remaining units & packs items;
  - RESET-BALANCES-1.
- **New permissions go to the Owner role only** (deploy.sh). No other role gets them until the owner names the roles
  (section 6).

## 1. The candidate

- **Release branch `release/unified-w3w4-20261024`, tested revision `7b4719c6`.** This revision is production
  `ed9a8431` merged into the feature branch head `0f733e4c`; code is unchanged since `2740ac9f`.
- **A docs-only commit follows `7b4719c6`.** It contains:
  - this manifest;
  - three corrected lines in the W3/W4 design record;
  - the read-only production check scripts in `docs/status/release-2026-10-24-checks/`.

  No code, test or migration changed.
- **Feature branch `feat/unified-w3w4-20261023`.** Code was frozen at `43598ccb` for the first checkpoint.
  `43598ccb → 2740ac9f` holds only two files, checked with `git diff --stat`:
  - `docs/plans/w3-w4-product-purchase-design-2026-10-23.md`: +32 lines (the checkpoint record);
  - `tests/MySql/ProductWorkspaceMySqlTest.php`: +4 lines (the untouched-tab case).

  The added case is `test_a_recipe_edited_in_the_product_form_keeps_what_the_tab_does_not_show`. Run alone, the
  runner gave:
  - `OK (1 test, 17 assertions)` as committed;
  - `Tests: 1, Assertions: 7, Failures: 1` with the guard it proves removed.

  It is part of the final full run on `7b4719c6` (section 9).
- **Commit count.** The feature branch forked from production at `59787ff6` and carries **58 commits** in three layers
  (section 3). Production's 9 commits since then (VOID-PURGE-1/2/3) are now in the candidate through the merge.

## 2. Release step 1 — merge production into the candidate (done)

`7b4719c6` merged production `ed9a8431` into the candidate. Three files conflicted:

| File | Resolution |
|---|---|
| `app/Support/TenantFeatures.php` | both sides kept: production's `void_purge` and this branch's `whole_counted_stock`, `counter_packs` and `purchase_workspace` (10 switches) |
| `docs/system-map/index.html`, `system-map.json` | rebuilt with `tools/system-map/build.php` from test DBs migrated with the merged migrations (`pos_test_tenant_relc`, `pos_test_master_relc`): 229 tables, 736 screens |

Production features kept, unchanged:
- the `void_purge` switch;
- the `purged_entries` / `purged_numbers` tables;
- the Cash Book delete popup;
- the VOID-PURGE-3 fix in `PreventDuplicateSubmission::failed()`.

`VoidPurgeMySqlTest` passed 10/10 on the merge; the final run and the delete journey (section 9) repeat it.

**At deploy time** (not done):
1. In the production checkout, merge with `git merge --no-ff release/unified-w3w4-20261024` and the message
   `Merge UNIFIED-W3W4: …`.
2. The release branch already contains `ed9a8431`, so the merged tree must be the tested tree plus docs.
   `git diff 7b4719c6 HEAD --stat` must list only `docs/` files.
3. **If production has moved past `ed9a8431`, this candidate is stale.** Merge again, rebuild the map and verify
   again.

## 3. What the 58 commits contain, and whom they reach on deploy

| Layer | Commits (first → last) | What | Switch | Reaches on deploy |
|---|---|---|---|---|
| A. Unified W2 + supplier invoice | `76af4d73` → `edf958d9` (1–28) | Supplier net invoice: a return before the bill is credited once; credit moves need an authorized yes. Reset wipes the two supplier-invoice tables. kg ↔ g by rule; one stock for no-variant / default-variant rows. Server quantity rules on POS sale, hold, split and return; on purchasing and stock (W2-C rules 1, 3–5). | none, except W2-C rule 2 → `whole_counted_stock` | **every tenant**, except rule 2 |
| B. Units & packs | `306029df` → `23d80320` (29–39) | F1 decimal packs (Bag of 2.5 kg); F2 gram entry; F3 packs at the normal counter; F4 prints and reports state the unit, and a mixed kg + pieces total is never one number. | F1, F2 → `packs`; F3 → `counter_packs`; F4 units: none | F4 unit labels: **every tenant**; the rest only where the switch is on |
| C. W3/W4 | `dc1425fb` → `2740ac9f` (40–58) | S0 purchasing safety fixes; S1 product list; S2 the form keeps what it does not show; S3 recipe / BOM / event costing tabs, with writers shared with their own screens; S4 purchase workspace; close-out: area forms, English-only, S5 navigation. | S1–S3 and areas → `product_screen_v2`; S4, S5 → `purchase_workspace` | see the warning below, and section 5a for who has `product_screen_v2` on today |

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
- **S0 purchasing safety fixes.**
  - A receipt is posted once per form key.
  - A voided receipt is never invoiced.
  - Receipt and payment numbers are retried on a clash.
  - A partly received order moves on.
  - A payment must be against its own supplier's invoice.
- **S2, old form.** A switched-off category or purchase unit is no longer emptied by an unchanged save.
- **S3b writers.** The Recipes, Bill of materials, Catering profile and Cost blocks screens save through the shared
  writers. A recipe or BOM save is now one transaction. A BOM line without a unit saves instead of failing.
  Refusals keep their words.
- **S4 receipts.** The Goods Receipt screen checks and posts through `GoodsReceiptService` (code moved unchanged). The
  shared entry area has optional hooks that no other screen sets.
- **English-only purchasing pieces.** The purchasing tabs and the supplier box lose their Urdu labels wherever
  `purchasing_v2` is on.

The switch-OFF journey (20 / 20, run twice: on `43598ccb` and on `7b4719c6`) and the full suite exercised these
changes with every switch off. That is evidence. It is **not** a claim that existing tenants are unaffected.

## 4. Migrations, in the order deploy.sh runs them (tenant DBs)

| # | Migration | Change | Locking | Rollback limit |
|---|---|---|---|---|
| 1 | `2026_10_21_000003_create_purchase_bill_return_nettings` | new tables `purchase_bill_return_nettings`, `supplier_credit_reallocations` | create only | the tables stay; once a net invoice exists, old code no longer explains that balance |
| 2 | `2026_10_23_000001_decimal_pack_sizes` | `pack_size DECIMAL(15,4) NULL` on 8 line tables; **`product_pack_units.factor` → `DECIMAL(15,4)`** | columns `ALGORITHM=INSTANT`; the factor change **copies** `product_pack_units` (section 4a) | `down()` reverts the factor only while every factor is whole. After a 2.5 kg Bag exists, roll forward only |
| 3 | `2026_10_23_000002_sale_line_entered_unit` | `entered_quantity`, `entered_unit_code` on `sales_order_lines` | `ALGORITHM=INSTANT` (no copy) | additive; old code ignores the columns |
| 4 | `2026_10_24_000001_add_submission_key_to_goods_receipts` | `submission_key`, `submission_hash` + unique index | columns `INSTANT`; index `INPLACE, LOCK=NONE` | additive; NULL on every existing receipt |

Production's VOID-PURGE migrations are already applied there; the merge adds no new ones of theirs.

Code that writes the new columns first checks that they exist. A tenant whose migration has not run yet keeps working
(schema-first rule).

**INSTANT limits, from the production census (24 Oct).**
- The largest `sales_order_lines` is about 66,000 rows (39 MB); INSTANT does not copy it.
- The highest INSTANT row-version count on any affected table is 5. MySQL's limit is 64.

### 4a. The `product_pack_units.factor` conversion — reviewed against production

**This differs from the earlier additive-only plan.** The units & packs plan said: additive changes only, so old code
keeps working. This change alters a column type, because a 2.5 kg Bag cannot be stored in a whole-number column.

**What production holds** (read-only census, 24 Oct, MySQL 8.0.46):

| | |
|---|---|
| Tenant DBs | 13; `factor` is `int unsigned` on all 13 |
| Rows | 0 on 12 DBs, every live tenant included; **78 on pharmacydemo** (demo) |
| Values | pharmacydemo: whole numbers 1 to 300 |
| Size | 32 KB empty; 48 KB on pharmacydemo |

**The values convert exactly:** 1 → 1.0000 … 300 → 300.0000. The unique key `(product_id, factor)` is kept.

**What runs:** `ALTER TABLE product_pack_units MODIFY factor DECIMAL(15,4) NOT NULL`. It runs only while the column
is still an integer type, so a re-run skips it.

**Locking:**
- A type change uses `ALGORITHM=COPY`. MySQL rebuilds the table. Writes to `product_pack_units` wait during the
  copy; reads go on. Copying 78 rows or none takes well under a second.
- The ALTER also needs a short exclusive metadata lock. If another session has an open transaction on the table,
  the ALTER waits, and new queries on that table queue behind it.
- The migration sets `lock_wait_timeout = 10`, so that wait is at most 10 seconds. Then the ALTER fails, and the
  table stays exactly as it was (never half-converted).
- deploy.sh prints `SKIP: <tenant>` for that tenant and carries on with the rest. Re-run deploy.sh at a quieter
  moment; finished parts are skipped.

**Before deploy:**
- Re-run the census and compare the type, row count and min / max with the table above.
- Check for long transactions: `SELECT trx_id, trx_started FROM information_schema.INNODB_TRX WHERE trx_started <
  NOW() - INTERVAL 10 SECOND` should return nothing.

**After deploy:**
- `factor` is `decimal(15,4)` on every tenant DB.
- pharmacydemo still has 78 rows, min 1.0000, max 300.0000.

**Rollback:**
- Old code was written for whole-number factors. While the column holds only whole values, they mean the same to
  it, and `down()` turns the column back into a whole number.
- Once a fractional factor exists, old code cannot read it correctly. This is possible only where `packs` is on:
  pharmacydemo today. `down()` then leaves the column a decimal. **Roll forward only.**

## 5. Feature switches — exact keys (Master → tenant), all OFF by default

| Key | Needs | Turns on |
|---|---|---|
| `product_screen_v2` | — | new product list and form, Recipe / BOM / Event costing tabs; Manufacturing Products and Catering Materials Add / Edit on the one form |
| `packs` | `product_screen_v2` | packs on the product form; decimal packs (F1); gram entry (F2); pack words in reports |
| `counter_packs` (new) | `packs` | packs at the normal counter (F3) |
| `whole_counted_stock` (new) | — | W2-C rule 2: counted items whole in purchasing and stock |
| `purchasing_v2` | — | new PO / GRN / Return screens and the purchasing tabs |
| `purchase_workspace` (new) | `purchasing_v2` | `/purchases/new`, the "New purchase" tab and the single "Purchases" sidebar item |
| `void_purge` | — | production's; kept by the merge, unchanged |

Other switches in `TenantFeatures` (`medical_counter`, `return_no_bill`, `report_delivery_v2`) are untouched by this
release.

### 5a. Production switches today — preserved as they are

**Correction.** An earlier draft and the W3/W4 design record said `product_screen_v2` was on for demo tenants only.
The census shows it is also on for **kashiffood**, a live tenant. The design record is corrected in the same commit.

Production `tenants.features`, read-only census, 24 Oct:

| Tenant | Kind | Switches on today | What this release changes there, beyond the shared code in section 3 |
|---|---|---|---|
| kashiffood | **live** | `purchasing_v2`, `product_screen_v2`, `report_delivery_v2` | **the new product list (S1), the form that keeps what it does not show (S2), and the Recipe / BOM / Event costing tabs (S3c)**. Each tab appears only where the tenant has that module and the user's role has that screen's permission. Catering Materials / Manufacturing Products open on the one form where those modules exist. The purchasing tabs and the supplier box become English-only. **Not:** the purchase workspace, packs. |
| kashifkitchen | live | `void_purge` | shared code only |
| khatribiryani | live | `void_purge` | shared code only |
| tawakalkashif | live | none | shared code only |
| mohsin | live | none | shared code only |
| demo | demo | none | shared code only |
| pharmacydemo | demo | `packs`, `purchasing_v2`, `return_no_bill`, `medical_counter`, `product_screen_v2` | S1–S3, area forms, decimal packs (F1), gram entry (F2), English-only purchasing. **Not:** `counter_packs`, `whole_counted_stock`, the workspace. |
| enterprisedemo, financedemo, inventorydemo, restaurantdemo, restaurantprodemo, retaildemo | demo | `product_screen_v2` | S1–S3 and the area forms |

**Rules for this release:**
- **Deploy changes no switch.** Every value above stays as it is, including kashiffood's.
- **No switch is turned off because its default is off.** If the owner wants kashiffood to stay on today's product
  screen, that is a change to a live value and needs his explicit decision.
- **New keys stay off until each one is approved, per tenant.** `counter_packs`, `whole_counted_stock` and
  `purchase_workspace` are absent on every tenant, which means off.
- **Proof.** Run `release-2026-10-24-checks/feature_snapshot.php` with `FEAT_SNAP=before` just before deploy, and with
  `FEAT_SNAP=after` once it finishes. The second run must print `SWITCHES OK: every tenant exactly as before`.

## 6. Permissions

- **New:** `tenant.purchases.workspace`, `tenant.purchases.workspace.store` and `tenant.purchases.workspace.receipt`
  (module: purchasing, via `PermissionSyncService::MODULE_KEY_OVERRIDES`).
- **deploy.sh grants new permissions to the Owner role on every tenant** (standing behaviour). The screen still
  answers 404 until `purchase_workspace` and `purchasing_v2` are on.
- **No other role gets them** until the owner names the roles.
  - Each step of a Post still needs that step's own permission (`goods-receipts.store`, `purchase-bills.store`,
    `supplier-payments.store`).
  - Grants are additive only (`givePermissionTo`, never `syncPermissions`), then
    `system:clear-tenant-permission-cache`.
- **Existing permissions are preserved.** Take a snapshot of every role and user before deploy and compare after.
  The expected difference is the Owner's 3 grants per tenant and nothing else.

## 7. Backup and reconciliation — discovered at deploy time

The count of tenants is read on the day, not assumed. An earlier draft listed five live tenants. The 24 Oct census
found 13 tenants (all active, all migrated) and 13 `pos_tenant_*` schemas, with no schema that master does not list.
The shared code reaches every one of them.

The scripts in `docs/status/release-2026-10-24-checks/` only read. To run one on the server:
1. Copy it to `/tmp`.
2. In `/var/www/html/pos-saas`, run `sudo -u www-data env HOME=/tmp php artisan tinker --execute='require "/tmp/<script>.php";'`
   (add `FEAT_SNAP=…` or `BACKUP_DIR=…` after `HOME=/tmp` where needed).

**Before deploy, in this order:**
1. **Census** (`prod_census.php`). It lists every tenant in master (any status) with its database, and any
   `pos_tenant_*` schema that master does not list. Compare with section 4a.
2. **Switch snapshot**: `FEAT_SNAP=before` `feature_snapshot.php`.
3. **Permission snapshot**: every role and user.
4. **Smoke baseline per tenant**: `tb_diff`, `neg_on_disallowed`, `department_negative`.
5. **Row counts per tenant**:
   - `goods_receipts`, `purchase_bills`, `supplier_payments`;
   - `recipe_ingredients`, `manufacturing_bom_lines`, `catering_product_cost_blocks`;
   - `product_pack_units`, plus the factor values.
6. **Backup**: `sudo -u www-data env HOME=/tmp php artisan tenants:backup --no-storage`, with **no `--tenant`**.
   - It dumps master and every tenant master lists at that moment, whatever its status.
   - It uses `mysqldump --single-transaction --skip-lock-tables`, so it takes no table locks.
   - Note the folder it prints (`storage/app/private/backups/<stamp>`).
7. **Coverage**: `BACKUP_DIR=<that folder>` `backup_coverage.php`.
   - It compares the backup's manifest with every tenant in master and every `pos_tenant_*` schema on the server
     at that moment, and checks that `master.sql` is there and not empty.
   - It must print `COVERAGE OK`. **Any `GAP` means do not deploy.**
   - It has been shown to catch a gap: on 24 Oct, run against an earlier partial backup folder, it printed
     `backed up OK=1 — 25 GAP(S): DO NOT DEPLOY`.
8. Long-transaction check (section 4a).

**After deploy:**
- deploy.sh reports `tenants ok=<the census count> skipped=0`. On a `SKIP`, read its line; for a lock wait, re-run
  deploy.sh.
- `FEAT_SNAP=after` prints `SWITCHES OK`.
- The same smoke: `tb_diff = 0`, no new negatives.
- Row counts unchanged. Deploy writes no business rows; only live sales may add rows during the window.
- `product_pack_units`: `decimal(15,4)` everywhere; pharmacydemo 78 rows, 1.0000 to 300.0000.
- Permission diff: Owner +3 per tenant, nothing else.
- Read-only page loads, using tenants that have the switches off:
  - Recipes edit, BOM edit, Cost blocks, Catering profiles;
  - Goods Receipt create, the product form;
  - the Cash Book (with its delete button where `void_purge` is on).

  `/purchases/new` answers 404.

## 8. Rollback limits

- **Code.** Revert the release merge commit and deploy; deploy.sh pulls origin. Safe only **before** any tenant uses
  the new features.
- **Real documents stay.** Purchase-workspace documents, decimal packs, net invoices and credit reallocations are
  real records. A code rollback does not remove them, and old code would misread some of them. After first use,
  roll forward only. Undo a document through its void or return, never by code.
- **Schema stays.** Everything is additive except the `factor` type (section 4a); nothing is dropped on rollback.
  `factor` goes back to a whole number only while every value is whole.
- **Production's permanent delete is not reversible by rollback either.** A purged entry stays purged; its copy is in
  `purged_entries`.
- **Backups.** The step 6 backup is the restore point for a data problem. Restoring a tenant from it loses that
  tenant's sales since the backup; that is the owner's call, per tenant.

## 9. Test evidence — the runners' own summaries

**Final run, on the merged revision `7b4719c6`.**

Setup: frozen worktree `pos-saas-verify-upv`, DB set `_relc`. The freeze file reads
`BEFORE 2026-10-24T10:26:04Z HEAD=7b4719c6… dirty=0` and `AFTER 2026-10-24T11:55:51Z HEAD=7b4719c6… dirty=0`.

| Suite | Runner summary | Result |
|---|---|---|
| MySQL | `OK, but there were issues!` `Tests: 2462, Assertions: 15650, PHPUnit Deprecations: 1, Skipped: 1.` | **2,461 passed, 1 skipped, 2,462 run** |
| default | `FAILURES!` `Tests: 230, Assertions: 35451, Failures: 3, Skipped: 4.` | **223 passed, 3 failed (the three below), 4 skipped** |

The journey stores were rebuilt on `7b4719c6` by `ProductWorkspaceMySqlTest` and `PurchaseWorkspaceMySqlTest` on DB
set `_relz`: `OK, but there were issues!` `Tests: 20, Assertions: 238, PHPUnit Deprecations: 1.`

**Browser journeys on `7b4719c6`.**

Setup:
- local servers from the frozen worktree (HEAD `7b4719c6`, clean tree);
- local test stores only (master `pos_test_master_relz`); never production;
- desktop, plus 390 px where noted.

| Journey | Checks | Result |
|---|---|---|
| S3 product workspace: Recipe, BOM and Event costing tabs; unchanged save writes nothing; 390 px | 25 | **25 / 25** |
| Every switch off: old Recipes, BOM, Cost blocks, Catering profile, old product form, old and new Goods Receipt; no workspace; 390 px | 20 | **20 / 20** |
| S4 purchase workspace with S5: receive + invoice + payment; receive now, invoice later; invoice an earlier receipt; overpay refused; 390 px | 20 | **20 / 20** |
| Production's permanent delete, the client's 24 Oct case (details below) | 8 | **8 / 8** |

The permanent-delete case: a journal dated 5 days back and voided today. On the Cash Book, the screen asks "Voided
on another day — Delete anyway?". After "Yes", the journal and its reversal are deleted, and a copy is in Deleted
entries.

**Two first attempts stopped, both from my setup, not the app:**
- **S3:** I started the local servers from the wrong folder, so no login page loaded and nothing ran. The re-run
  used the same prepared items.
- **Permanent delete:** the walk's first set-up request bypassed the browser's host mapping and could not reach the
  test store, so nothing was created. The walk now sends that request from inside the page. The re-run gave 8 / 8.

**The three default failures predate this work.** They fail the same on production `c5c39cf9` (re-run 24 Oct):
- `Tests\Unit\CateringClientFeedbackUiRegressionTest::test_the_event_screen_starts_with_the_sidebar_collapsed`
- `Tests\Unit\Printing\EscPosReportPayloadTest::test_it_renders_the_requested_sections_with_two_decimal_money`
- `Tests\Unit\Tenant\DeliveryRiderReassignmentRegressionTest::test_reassignment_is_routed_guarded_audited_and_visible`

**Earlier checkpoint on the feature branch, frozen `43598ccb`** (DB set `_w34z`; same HEAD and a clean tree before
and after):

| Suite | Runner summary | Result |
|---|---|---|
| MySQL | `OK, but there were issues!` `Tests: 2452, Assertions: 15518, PHPUnit Deprecations: 1, Skipped: 1.` | **2,451 passed, 1 skipped, 2,452 run** |
| default | `FAILURES!` `Tests: 230, Assertions: 35424, Failures: 3, Skipped: 4.` | **223 passed, 3 failed, 4 skipped** |

**Targeted sabotage:** 11 cases plus a control. 10 went red the first time. The UNTOUCHED case stayed green until
`03e42bf7` added a test that catches it. The control stayed green.

**Not clean, recorded as such:**
- The S4 purchasing set of 205 had 65 errors and 1 failure. After the fixes, only the 10 affected classes were
  re-run (78 / 78).
- The close-out focused run had 148 / 149: one old-rule test, deliberately updated. Then 43 / 43.
- The first switch-off journey was 18 / 20, from a walk-script fault with a Select2 box. The app was correct.

## 10. Open items carried into the release

- **The three pre-existing default-suite failures** (section 9).
- **English label fixes in purchasing** ("Create GRN", "Create Bill", "Against Bill" …): named in the design, not done.
- **Units & packs remaining:** KOT-cancellation and partner-share quantities, and a WhatsApp mixed-units test (see the
  units-packs record).
- **RESET-BALANCES-1:** `tenant:reset-transactions` keeps stored balances. It is its own defect and decision.
- **kashiffood's switches** (section 5a): kept as they are unless the owner decides otherwise.
- **Rollout:** the owner's decision (below).

## 11. Rollout

**Deployment:** not approved yet. Deploy turns on no switch.

**Reviewer's recommendation (24 Oct, after the first manifest):**
1. Deploy with every new screen OFF, keeping every current switch value (section 5a).
2. Verify the existing workflows (section 7).
3. Activate one approved pilot tenant.
4. After acceptance, enable the applicable new design for **both new and existing tenants**, in stages, not all at
   once.
5. Preserve each tenant's modules, permissions and history. Do not turn on medical or manufacturing features where
   they do not belong.

**Owner decision needed:** "Do you approve that eventual rollout to both new and existing tenants, with staged
activation rather than switching everyone at once?"

Whatever the answer, the shared-code changes in section 3 reach existing tenants on deploy. Holding them back would
need a switch or a separate release.
